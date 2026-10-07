<?php

namespace Tests\Feature;

use App\Crud\ProviderCrud;
use App\Models\Branch;
use App\Models\Procurement;
use App\Models\Provider;
use App\Services\CrudEntry;
use App\Services\ProcurementWorkbook;
use App\Services\ProviderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class SupplierBillingTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private Branch $branch;

    private Provider $provider;

    private Procurement $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs( $this->attemptGetAnyUserFromRole(), 'web' );
        $this->branch = Branch::create( ['code' => 'BILL-TEST', 'name' => 'สาขาทดสอบวางบิล', 'is_active' => true, 'author_id' => auth()->id()] );
        $this->provider = Provider::create( ['first_name' => 'บริษัททดสอบภาษาไทย', 'code' => '0000123', 'author_id' => auth()->id()] );
        $this->document = Procurement::withoutEvents( function () {
            $d = new Procurement;
            foreach ( ['name' => '=1+1', 'provider_id' => $this->provider->id, 'branch_id' => $this->branch->id, 'author_id' => auth()->id(), 'cost' => 107, 'tax_value' => 7, 'delivery_status' => 'stocked', 'payment_status' => 'unpaid', 'due_date' => '2026-09-15', 'invoice_date' => '2026-09-01', 'invoice_reference' => '0000456'] as $k => $v ) {
                $d->$k = $v;
            }
            $d->save();

            return $d;
        } );
    }

    private function url( array $overrides = [] ): string
    {
        return '/dashboard/supplier-billing?' . http_build_query( array_merge( ['provider_id' => $this->provider->id, 'branch_id' => $this->branch->id, 'from' => '2026-09-01', 'to' => '2026-09-30'], $overrides ) );
    }

    public function test_report_filters_branch_dates_status_and_archived_documents(): void
    {
        foreach ( [['branch_id' => null], ['due_date' => '2026-10-01'], ['trial_archived_at' => now()], ['delivery_status' => 'pending'], ['payment_status' => 'paid'], ['due_date' => null], ['provider_id' => 0]] as $changes ) {
            Procurement::withoutEvents( function () use ( $changes ) {
                $d = $this->document->replicate();
                foreach ( $changes as $k => $v ) {
                    $d->$k = $v;
                }$d->save();
            } );
        }
        $response = $this->get( $this->url() )->assertOk();
        $this->assertCount( 1, $response->viewData( 'documents' ) );
        $this->assertEquals( 107, $response->viewData( 'documents' )->sum( 'cost' ) );
        $this->get( $this->url( ['format' => 'print'] ) )->assertOk()->assertSee( '0000456' )->assertSee( '107.00' );
        $this->getJson( $this->url( ['from' => '2026-10-01', 'to' => '2026-09-01'] ) )->assertUnprocessable();
    }

    public function test_workbook_roundtrip_preserves_thai_codes_money_and_literal_formula_text(): void
    {
        $book = app( ProcurementWorkbook::class )->create( [$this->document->load( 'provider' )], 'ภาษาไทย', 'ทดสอบ' );
        $path = tempnam( sys_get_temp_dir(), 'billing' );
        try {
            ( new Xlsx( $book ) )->save( $path );
            $sheet = IOFactory::load( $path )->getActiveSheet();
            $this->assertSame( '=1+1', $sheet->getCell( 'A5' )->getValue() );
            $this->assertSame( 's', $sheet->getCell( 'A5' )->getDataType() );
            $this->assertSame( '0000123', $sheet->getCell( 'B5' )->getValue() );
            $this->assertSame( '0000456', $sheet->getCell( 'G5' )->getValue() );
            $this->assertSame( 'บริษัททดสอบภาษาไทย', $sheet->getCell( 'C5' )->getValue() );
            $this->assertEquals( 107, $sheet->getCell( 'O6' )->getCalculatedValue() );
            $this->assertSame( 'n', $sheet->getCell( 'O5' )->getDataType() );
        } finally {
            unlink( $path );
        }
        $response = $this->get( $this->url( ['format' => 'xlsx'] ) )->assertOk();
        $this->assertStringStartsWith( 'PK', $response->streamedContent() );
    }

    public function test_staff_cannot_export_other_branch_or_legacy_unassigned_documents(): void
    {
        $staff = $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' );
        $staff->branch_id = $this->branch->id;
        $staff->save();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs( $staff, 'web' );
        Gate::define( 'nexopos.read.procurements', fn() => true );
        $this->get( $this->url() )->assertOk();
        $this->getJson( $this->url( ['branch_id' => 0] ) )->assertForbidden();
        $this->getJson( $this->url( ['branch_id' => 999999] ) )->assertForbidden();
    }

    public function test_provider_totals_use_purchase_cost_and_scope_receipts(): void
    {
        DB::table( 'nexopos_procurements' )->where( 'id', $this->document->id )->update( ['value' => 21050, 'cost' => 18450] );
        foreach ( [['payment_status' => 'paid', 'cost' => 100, 'branch_id' => $this->branch->id], ['payment_status' => 'unpaid', 'cost' => 300, 'branch_id' => null], ['trial_archived_at' => now(), 'cost' => 900], ['delivery_status' => 'pending', 'cost' => 700]] as $changes ) {
            Procurement::withoutEvents( function () use ( $changes ): void {
                $d = $this->document->fresh()->replicate();
                foreach ( $changes as $key => $value ) {
                    $d->$key = $value;
                }$d->save();
            } );
        }
        $service = app( ProviderService::class );
        $this->assertSame( ['amount_due' => 18450.0, 'amount_paid' => 100.0], $service->receiptSummary( $this->provider->id, $this->branch->id ) );
        $service->computeSummary( $this->provider );
        $this->assertEquals( 18750, $this->provider->fresh()->amount_due );
        $this->assertEquals( 100, $this->provider->fresh()->amount_paid );
        session( ['selected_branch_id' => $this->branch->id] );
        $entry = ( new ProviderCrud )->setActions( new CrudEntry( $this->provider->fresh()->toArray() ) );
        $this->assertSame( ns()->currency->define( 18450 )->format(), $entry->amount_due );
        $this->assertEquals( 18750, $this->provider->fresh()->amount_due );
        $this->post( '/dashboard/purchase-trials/' . $this->document->id, ['action' => 'archive', 'acknowledge' => '1'] )->assertRedirect();
        $this->assertEquals( 300, $this->provider->fresh()->amount_due );
        $this->post( '/dashboard/purchase-trials/' . $this->document->id, ['action' => 'restore', 'acknowledge' => '1'] )->assertRedirect();
        $this->assertEquals( 18750, $this->provider->fresh()->amount_due );
    }

    public function test_existing_download_button_exports_selected_receipt_as_xlsx(): void
    {
        $response = $this->postJson( '/api/crud/ns.procurements/export', ['entries' => [$this->document->id]] )->assertOk();
        Auth::shouldUse( 'web' );
        $download = $this->get( $response->json( 'url' ) )->assertOk();
        $this->assertStringContainsString( '.xlsx', $download->headers->get( 'Content-Disposition' ) );
        $path = tempnam( sys_get_temp_dir(), 'download-test' );
        file_put_contents( $path, $download->streamedContent() );
        $sheet = IOFactory::load( $path )->getActiveSheet();
        $this->assertSame( '0000456', $sheet->getCell( 'G5' )->getValue() );
        $this->assertSame( 'รวมเงิน', $sheet->getCell( 'L6' )->getValue() );
        unlink( $path );
    }
}

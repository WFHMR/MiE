<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Services\MemberImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class MemberImportTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private Branch $branch;

    private MemberImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs( $this->attemptGetAnyUserFromRole() );
        $this->branch = Branch::create( ['code' => 'MEMBER-TEST', 'name' => 'สาขานำเข้าทดสอบ', 'is_active' => true, 'author_id' => auth()->id()] );
        $this->service = app( MemberImportService::class );
    }

    public function test_excel_and_csv_preserve_phone_and_member_code(): void
    {
        foreach ( ['Xlsx' => 'xlsx', 'Csv' => 'csv'] as $writer => $extension ) {
            $book = new Spreadsheet;
            $book->getActiveSheet()->fromArray( [['ชื่อ', 'เบอร์โทร', 'รหัสสมาชิก', 'คะแนน'], ['ทดสอบไฟล์', '0991234567', '00123', '500']] );
            $book->getActiveSheet()->setCellValueExplicit( 'B2', '0991234567', DataType::TYPE_STRING );
            $book->getActiveSheet()->setCellValueExplicit( 'C2', '00123', DataType::TYPE_STRING );
            $path = tempnam( sys_get_temp_dir(), 'member-test' );
            try {
                IOFactory::createWriter( $book, $writer )->save( $path );
                $rows = $this->service->normalize( $this->service->read( $path, $extension ) );
                $this->assertSame( '0991234567', $rows[0]['phone'] );
                $this->assertSame( '00123', $rows[0]['code'] );
                $this->assertSame( [], $rows[0]['errors'] );
            } finally {
                unlink( $path );
                $book->disconnectWorksheets();
            }
        }
    }

    public function test_corrupt_excel_returns_validation_error(): void
    {
        $path = tempnam( sys_get_temp_dir(), 'member-test' );
        file_put_contents( $path, 'not an Excel workbook' );
        try {
            $this->expectException( ValidationException::class );
            $this->service->read( $path, 'xlsx' );
        } finally {
            unlink( $path );
        }
    }

    private function preview( array $row = ['ลูกค้าทดสอบ', '0991234567', '00123', '1200'] ): string
    {
        return $this->service->preview( [['ชื่อ', 'เบอร์โทร', 'รหัสสมาชิก', 'คะแนน'], $row], $this->branch->id, CustomerGroup::firstOrFail()->id );
    }

    public function test_preview_does_not_import_and_confirm_is_idempotent(): void
    {
        $id = $this->preview();
        $this->assertFalse( Customer::where( 'phone', '0991234567' )->exists() );
        $this->get( '/dashboard/customers/import-members?batch=' . $id )->assertOk()->assertSee( '00123' );
        $this->assertSame( 1, $this->service->confirm( $id ) );
        $this->assertSame( 1, $this->service->confirm( $id ) );
        $this->assertSame( 1, Customer::where( 'phone', '0991234567' )->count() );
        $account = DB::table( 'nexopos_loyalty_accounts' )->where( 'branch_id', $this->branch->id )->first();
        $this->assertSame( '00123', $account->member_code );
        $this->assertEquals( 1200, $account->points );
        $this->assertSame( 1, DB::table( 'nexopos_loyalty_entries' )->where( 'account_id', $account->id )->count() );
    }

    public function test_duplicate_reupload_cannot_add_points_again(): void
    {
        $this->service->confirm( $this->preview() );
        $id = $this->preview();
        $this->expectException( ValidationException::class );
        $this->service->confirm( $id );
    }

    public function test_invalid_phone_with_missing_zero_is_rejected(): void
    {
        $id = $this->preview( ['ทดสอบ', '991234567', '', '0'] );
        try {
            $this->service->confirm( $id );
            $this->fail( 'Invalid phone was imported' );
        } catch ( ValidationException $e ) {
            $this->assertFalse( Customer::where( 'phone', '991234567' )->exists() );
        }
    }

    public function test_duplicate_rows_block_entire_batch(): void
    {
        $rows = [['ชื่อ', 'เบอร์โทร'], ['ทดสอบ', '0991234567'], ['ทดสอบ', '0991234567']];
        $id = $this->service->preview( $rows, $this->branch->id, CustomerGroup::firstOrFail()->id );
        $this->expectException( ValidationException::class );
        $this->service->confirm( $id );
    }

    public function test_employee_cannot_import_into_another_branch(): void
    {
        $staff = $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' );
        $this->actingAs( $staff );
        $this->expectException( HttpException::class );
        $this->preview();
    }

    public function test_second_branch_cannot_import_opening_points_for_existing_shared_member(): void
    {
        $this->service->confirm( $this->preview() );
        $this->branch = Branch::create( ['code' => 'MEMBER-TEST-OTHER', 'name' => 'สาขาสอง', 'is_active' => true, 'author_id' => auth()->id()] );
        try {
            $this->service->confirm( $this->preview( ['ลูกค้าทดสอบ', '0991234567', '00123', '15'] ) );
            $this->fail( 'Duplicated opening points' );
        } catch ( ValidationException $e ) {
            $this->assertEquals( 1200, DB::table( 'nexopos_loyalty_accounts' )->where( 'member_code', '00123' )->sum( 'points' ) );
        }
    }
}

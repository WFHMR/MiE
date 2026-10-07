<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class ProductListSearchTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private array $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs( $this->attemptGetAnyUserFromRole() );
        $north = Provider::create( ['first_name' => 'ซัพทดสอบเหนือ', 'last_name' => 'เทรดดิ้ง', 'code' => 'SP50N', 'author_id' => auth()->id()] );
        $south = Provider::create( ['first_name' => 'ซัพทดสอบใต้', 'code' => 'SP50S', 'author_id' => auth()->id()] );
        $this->products = [];
        foreach ( [['QA50ถ้วย พลาสติก 150 มล.', $north->id], ['150 มล. QA50ถ้วย กระดาษ', $south->id], ['QA50ถ้วย พลาสติก 250 มล.', $north->id]] as [$name, $provider] ) {
            $this->products[] = Product::factory()->create( ['name' => $name, 'provider_id' => $provider, 'stock_management' => 'disabled'] )->id;
        }
    }

    private function ids( array $filters ): array
    {
        $response = $this->getJson( '/api/crud/ns.products?' . http_build_query( $filters ) )->assertOk();

        return array_column( $response->json( 'data' ), 'id' );
    }

    public function test_all_terms_match_in_any_order_and_support_space_star_or_plus(): void
    {
        foreach ( ['QA50ถ้วย 150', '150 QA50ถ้วย', 'QA50ถ้วย*150', 'QA50ถ้วย+150', '  QA50ถ้วย   150  '] as $search ) {
            $this->assertEqualsCanonicalizing( array_slice( $this->products, 0, 2 ), $this->ids( compact( 'search' ) ) );
        }
        $this->assertSame( [], $this->ids( ['search' => 'QA50ถ้วย 999'] ) );
    }

    public function test_supplier_name_or_code_combines_with_product_terms(): void
    {
        $this->assertSame( [$this->products[0]], $this->ids( ['search' => 'QA50ถ้วย 150', 'supplier' => 'ซัพทดสอบเหนือ เทรดดิ้ง'] ) );
        $this->assertEqualsCanonicalizing( [$this->products[0], $this->products[2]], $this->ids( ['supplier' => 'SP50N'] ) );
        $this->assertSame( [], $this->ids( ['search' => 'QA50ถ้วย', 'supplier' => 'ไม่มีซัพนี้QA50'] ) );
    }

    public function test_filters_work_when_sorting_and_reject_array_input(): void
    {
        $this->assertSame( [$this->products[0]], $this->ids( ['search' => 'QA50ถ้วย 150', 'supplier' => 'SP50N', 'active' => 'name', 'direction' => 'asc'] ) );
        $this->getJson( '/api/crud/ns.products?search[]=invalid' )->assertStatus( 422 );
    }

    public function test_unit_barcode_with_dot_finds_product_once_and_combines_with_other_filters(): void
    {
        foreach ( [1, 2] as $copy ) {
            $row = new ProductUnitQuantity;
            $row->product_id = $this->products[0];
            $row->unit_id = Unit::firstOrFail()->id;
            $row->branch_id = null;
            $row->barcode = 'QA54M002.10';
            $row->quantity = 10;
            $row->save();
        }
        $this->assertSame( [$this->products[0]], $this->ids( ['search' => 'QA54M002.10'] ) );
        $this->assertSame( [$this->products[0]], $this->ids( ['search' => 'QA50ถ้วย QA54M002.10', 'supplier' => 'SP50N'] ) );
        $this->assertSame( [], $this->ids( ['search' => 'QA54M002.10', 'supplier' => 'SP50S'] ) );
    }

    public function test_foreign_branch_unit_barcode_is_not_searchable_in_current_branch(): void
    {
        $branches = Branch::where( 'is_active', true )->take( 2 )->get();
        $this->assertCount( 2, $branches );
        session( ['selected_branch_id' => $branches[0]->id] );
        $row = new ProductUnitQuantity;
        $row->product_id = $this->products[0];
        $row->unit_id = Unit::firstOrFail()->id;
        $row->branch_id = $branches[1]->id;
        $row->barcode = 'QA54-FOREIGN.10';
        $row->quantity = 10;
        $row->save();
        $this->assertSame( [], $this->ids( ['search' => 'QA54-FOREIGN.10'] ) );
        $row->branch_id = $branches[0]->id;
        $row->save();
        $this->assertSame( [$this->products[0]], $this->ids( ['search' => 'QA54-FOREIGN.10'] ) );
    }
}

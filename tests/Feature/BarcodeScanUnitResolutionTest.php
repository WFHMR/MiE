<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Register;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * A scan has to add the unit that was scanned, and nothing else.
 *
 * Two complaints from the counter, one cause. Scanning a bottle opened the unit chooser listing bottle,
 * pack and carton, so every single item needed a mouse click — impossible with a queue and a scanner in
 * the other hand. Scanning a pack or a carton reported the goods as missing altogether.
 *
 * Both came from the branch a scan was resolved against. The sale already followed the till
 * (OrdersService::resolveOrderBranch: "the till outranks the branch picker") but the scan followed the
 * header picker, so an administrator ringing up on the Tha Chang till with the picker left on the main
 * branch had the order stamped Tha Chang while every scan on the way to it was resolved against the
 * main branch. 1,118 products on this shop are stocked at Tha Chang and nowhere else:
 *
 *  - a code that also sits on the product row fell through to the product lookup, which carries no
 *    branch filter, so the unit was unknown and the chooser opened;
 *  - a code that sits only on a unit row matched nothing and answered "no product found".
 *
 * The chooser is still wanted when a product is picked from the F1 search, where there is no barcode to
 * say which unit was meant.
 */
class BarcodeScanUnitResolutionTest extends TestCase
{
    use WithAuthentication;

    /** @var list<array{0: string, 1: int}> */
    private array $createdRows = [];

    private ?User $actor = null;

    private ?int $branchBefore = null;

    private ?int $registerId = null;

    protected function tearDown(): void
    {
        $this->registerId = null;

        if ( $this->actor instanceof User ) {
            DB::table( 'nexopos_users' )->where( 'id', $this->actor->id )->update( [ 'branch_id' => $this->branchBefore ] );
        }

        foreach ( array_reverse( $this->createdRows ) as [ $table, $id ] ) {
            DB::table( $table )->where( 'id', $id )->delete();
        }

        $this->createdRows = [];

        session()->forget( 'selected_branch_id' );

        parent::tearDown();
    }

    /**
     * The headline complaint: the bottle barcode is also the product's own barcode, and scanning it
     * must put the bottle in the cart without asking.
     */
    public function test_scanning_the_retail_barcode_resolves_the_retail_unit_without_asking(): void
    {
        [ $product, $bottle, $pack, $branchId ] = $this->productSoldByBottleAndPack();

        $this->standAtTillOfBranch( $branchId, pickerBranch: $branchId + 1000 );

        $response = $this->scan( $bottle->barcode );

        $response->assertOk();

        $this->assertNotNull(
            $response->json( 'unit' ),
            'The scan came back without a unit, which is what makes the till open the unit chooser and'
                . ' forces a mouse click on every item.'
        );

        $this->assertSame( $bottle->id, $response->json( 'unitQuantity.id' ) );
        $this->assertEqualsWithDelta( 16.0, (float) $response->json( 'unitQuantity.sale_price' ), 0.001 );
    }

    /**
     * The other half: a pack barcode exists only on the unit row, so when the branch was wrong there
     * was no fallback at all and the goods were reported missing.
     */
    public function test_scanning_the_pack_barcode_resolves_the_pack(): void
    {
        [ $product, $bottle, $pack, $branchId ] = $this->productSoldByBottleAndPack();

        $this->standAtTillOfBranch( $branchId, pickerBranch: $branchId + 1000 );

        $response = $this->scan( $pack->barcode );

        $response->assertOk();

        $this->assertSame( $pack->id, $response->json( 'unitQuantity.id' ) );
        $this->assertEqualsWithDelta( 180.0, (float) $response->json( 'unitQuantity.sale_price' ), 0.001 );
    }

    /**
     * The rule the sale already followed, now followed by the scan: the register is bolted to one
     * branch, the picker is a screen control anyone can touch.
     */
    public function test_the_till_outranks_the_branch_picker(): void
    {
        [ $product, $bottle, $pack, $branchId ] = $this->productSoldByBottleAndPack();

        $otherBranch = $this->makeBranch();

        /**
         * Exactly the live situation: an administrator with no branch of their own, ringing up on this
         * branch's till, with the picker left on another branch.
         */
        $this->standAtTillOfBranch( $branchId, pickerBranch: $otherBranch );

        $this->scan( $pack->barcode )
            ->assertOk()
            ->assertJsonPath( 'unitQuantity.id', $pack->id );
    }

    /**
     * The chooser must never be able to offer another branch's price.
     */
    public function test_the_units_offered_belong_to_this_branch_only(): void
    {
        [ $product, $bottle, $pack, $branchId ] = $this->productSoldByBottleAndPack();

        $otherBranch = $this->makeBranch();
        $unit = Unit::findOrFail( $bottle->unit_id );

        $this->unitQuantity( $product->id, $unit->id, $otherBranch, 'OTHER' . random_int( 100000, 999999 ), 999, 5 );

        $this->standAtTillOfBranch( $branchId );

        $rows = $this->scan( $bottle->barcode )->json( 'product.unit_quantities' );

        $this->assertNotEmpty( $rows );

        foreach ( $rows as $row ) {
            $this->assertNotEquals(
                999.0,
                (float) $row[ 'sale_price' ],
                'Another branch\'s row was handed to the till, so the cashier can sell at a price this'
                    . ' branch never set and deduct from a shelf it does not own.'
            );
        }
    }

    /**
     * Goods this branch does not stock have to be refused with something the cashier can act on, not
     * answered with the units of a branch they are not standing in.
     */
    public function test_goods_this_branch_does_not_stock_are_refused_plainly(): void
    {
        [ $product, $bottle, $pack, $branchId ] = $this->productSoldByBottleAndPack();

        $emptyBranch = $this->makeBranch();

        $this->standAtTillOfBranch( $emptyBranch );

        $response = $this->scan( $product->barcode );

        $response->assertStatus( 404 );

        $this->assertStringContainsString(
            'ไม่มีในคลังของสาขานี้',
            (string) $response->json( 'message' ),
            'The refusal does not say why, so the cashier cannot tell a missing product from one that'
                . ' belongs to another branch.'
        );
    }

    /**
     * A shop that has only ever had one branch carries no branch on any row. Nothing here may exclude
     * those rows, or every scan on such an install would report the goods as missing.
     */
    public function test_a_branchless_catalogue_still_scans(): void
    {
        $group = $this->unitGroup();
        $unit = $this->unit( $group->id, 'ขวด', 1, true );
        $product = $this->product( $group->id, 'BRANCHLESS' . random_int( 100000, 999999 ) );

        $row = $this->unitQuantity( $product->id, $unit->id, null, $product->barcode, 16, 12 );

        $this->standAtTillOfBranch( null, pickerBranch: 4242 );

        $this->scan( $product->barcode )
            ->assertOk()
            ->assertJsonPath( 'unitQuantity.id', $row->id );
    }

    /**
     * When the code really is ambiguous — a product barcode that belongs to no single unit, with
     * several units on the shelf — asking is correct, and that is the behaviour the shop asked to keep
     * for the F1 search.
     */
    public function test_a_genuinely_ambiguous_code_still_asks(): void
    {
        [ $product, $bottle, $pack, $branchId ] = $this->productSoldByBottleAndPack();

        /**
         * The product's own barcode no longer matches any unit row, so nothing identifies which unit
         * was meant.
         */
        DB::table( 'nexopos_products' )
            ->where( 'id', $product->id )
            ->update( [ 'barcode' => 'AMBIG' . random_int( 100000, 999999 ) ] );

        $product->refresh();

        $this->standAtTillOfBranch( $branchId );

        $response = $this->scan( $product->barcode );

        $response->assertOk();

        $this->assertNull(
            $response->json( 'unit' ),
            'A code that cannot identify a unit resolved to one anyway, so the cashier is never asked'
                . ' and whichever unit came first is sold.'
        );

        $this->assertCount( 2, $response->json( 'product.unit_quantities' ) );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The till is named on the request, which is what the POS does. A cashier who left a till open
     * elsewhere holds more than one, and nothing outside the screen can tell which one they are
     * standing at — so the screen says.
     */
    private function scan( string $barcode )
    {
        $query = $this->registerId !== null ? '?register_id=' . $this->registerId : '';

        return $this->actingAs( $this->actor(), 'web' )
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/products/search/using-barcode/' . rawurlencode( $barcode ) . $query );
    }

    /**
     * @return array{0: Product, 1: ProductUnitQuantity, 2: ProductUnitQuantity, 3: int}
     */
    private function productSoldByBottleAndPack(): array
    {
        $branchId = $this->makeBranch();

        $group = $this->unitGroup();
        $bottleUnit = $this->unit( $group->id, 'ขวด', 1, true );
        $packUnit = $this->unit( $group->id, 'แพ็ค', 12, false );

        $bottleBarcode = '885' . random_int( 1000000, 9999999 );
        $packBarcode = '886' . random_int( 1000000, 9999999 );

        /**
         * The product's own barcode is the bottle's, which is what the importer writes and what makes
         * the retail scan fall through to the product lookup.
         */
        $product = $this->product( $group->id, $bottleBarcode );

        $bottle = $this->unitQuantity( $product->id, $bottleUnit->id, $branchId, $bottleBarcode, 16, 12 );
        $pack = $this->unitQuantity( $product->id, $packUnit->id, $branchId, $packBarcode, 180, 0 );

        return [ $product, $bottle, $pack, $branchId ];
    }

    /**
     * Puts the acting user behind an open till of the given branch, with the header picker deliberately
     * pointing somewhere else, which is the live situation this whole change is about.
     */
    private function standAtTillOfBranch( ?int $branchId, ?int $pickerBranch = null ): void
    {
        $actor = $this->actor();

        DB::table( 'nexopos_users' )->where( 'id', $actor->id )->update( [ 'branch_id' => null ] );
        $actor->refresh();

        $this->registerId = null;

        if ( $branchId !== null ) {
            $registerId = (int) DB::table( 'nexopos_registers' )->insertGetId( [
                'name' => 'จุดคิดเงินทดสอบยิงบาร์โค้ด ' . random_int( 100, 999 ),
                'branch_id' => $branchId,
                'status' => Register::STATUS_OPENED,
                'used_by' => $actor->id,
                'author_id' => $actor->id,
                'balance' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ] );

            $this->createdRows[] = [ 'nexopos_registers', $registerId ];
            $this->registerId = $registerId;
        }

        if ( $pickerBranch !== null ) {
            session( [ 'selected_branch_id' => $pickerBranch ] );
        } else {
            session()->forget( 'selected_branch_id' );
        }
    }

    private function makeBranch(): int
    {
        $id = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'SCAN' . random_int( 100000, 999999 ),
            'name' => 'สาขาทดสอบยิงบาร์โค้ด ' . random_int( 100, 999 ),
            'is_main' => false,
            'is_active' => true,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_branches', $id ];

        return $id;
    }

    private function unitGroup(): UnitGroup
    {
        $group = new UnitGroup;
        $group->name = 'scan-' . random_int( 100000, 999999 );
        $group->author_id = 1;
        $group->save();

        $this->createdRows[] = [ 'nexopos_units_groups', $group->id ];

        return $group;
    }

    private function unit( int $groupId, string $name, float $value, bool $isBase ): Unit
    {
        $unit = new Unit;
        $unit->name = $name;
        $unit->identifier = 'scan-' . random_int( 100000, 999999 );
        $unit->group_id = $groupId;
        $unit->value = $value;
        $unit->base_unit = $isBase;
        $unit->author_id = 1;
        $unit->save();

        $this->createdRows[] = [ 'nexopos_units', $unit->id ];

        return $unit;
    }

    private function product( int $unitGroupId, string $barcode ): Product
    {
        $product = new Product;
        $product->stock_on_base_unit = true;
        $product->name = 'สินค้าทดสอบยิงบาร์โค้ด ' . random_int( 100000, 999999 );
        $product->barcode = $barcode;
        $product->sku = 'scan-' . random_int( 100000, 999999 );
        $product->barcode_type = 'code128';
        $product->unit_group = $unitGroupId;
        $product->author_id = 1;
        $product->type = Product::TYPE_MATERIALIZED;
        $product->product_type = 'product';
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->tax_type = 'inclusive';
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->searchable = true;
        $product->accurate_tracking = false;
        $product->save();

        $this->createdRows[] = [ 'nexopos_products', $product->id ];

        return $product;
    }

    private function unitQuantity( int $productId, int $unitId, ?int $branchId, string $barcode, float $price, float $quantity ): ProductUnitQuantity
    {
        $row = new ProductUnitQuantity;
        $row->product_id = $productId;
        $row->unit_id = $unitId;
        $row->branch_id = $branchId;
        $row->barcode = $barcode;
        $row->quantity = $quantity;
        $row->sale_price = $price;
        $row->sale_price_edit = $price;
        $row->wholesale_price = $price;
        $row->cogs = $price;
        $row->type = 'product';
        $row->visible = true;
        $row->stock_alert_enabled = false;
        $row->low_quantity = 0;
        $row->save();

        $this->createdRows[] = [ 'nexopos_products_unit_quantities', $row->id ];

        return $row;
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
            $this->branchBefore = $this->actor->branch_id;
        }

        return $this->actor;
    }
}

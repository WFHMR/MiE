<?php

namespace Tests\Feature;

use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Receiving goods that hold their stock on a base unit.
 *
 * A sale is converted before anything is written: selling a carton of goods stocked in pieces takes a
 * carton's worth of pieces off the shelf. Receiving did not do the same. It added the purchased
 * figure to the row of whichever unit was bought, so buying five kilos of loose sugar put five onto
 * the kilo row — a row that exists only as a price label and is deliberately held at zero — while the
 * row the till actually reads stayed empty.
 *
 * The shop then had goods it could not sell, and a label row carrying a figure that would have let
 * the same goods be sold a second time. Nothing on screen said so; the products list showed stock.
 *
 * These tests pin the conversion in both directions and prove ordinary goods are untouched by it.
 */
class ProcurementUnitConversionTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * @param  array<string, float>                                $units unit name to its value
     * @return array{product: Product, units: array<string, Unit>}
     */
    private function makeProduct( string $name, array $units, bool $onBaseUnit ): array
    {
        $group = new UnitGroup;
        $group->name = 'กลุ่มรับของ ' . $name . ' ' . random_int( 1000, 9999 );
        $group->author_id = 1;
        $group->save();

        $smallest = min( $units );
        $resolved = [];

        foreach ( $units as $unitName => $value ) {
            $unit = new Unit;
            $unit->name = $unitName;
            $unit->identifier = 'prc-' . $group->id . '-' . substr( md5( $unitName ), 0, 10 );
            $unit->group_id = $group->id;
            $unit->value = $value;
            $unit->base_unit = abs( $value - $smallest ) < 0.00001;
            $unit->author_id = 1;
            $unit->save();

            $resolved[ $unitName ] = $unit;
        }

        $product = new Product;
        $product->name = $name;
        $product->sku = 'PRC-' . random_int( 100000, 999999 );
        $product->barcode = (string) random_int( 8600000000000, 8699999999999 );
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
        $product->product_type = 'product';
        $product->type = Product::TYPE_MATERIALIZED;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->accurate_tracking = false;
        $product->auto_cogs = true;
        $product->searchable = true;
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_on_base_unit = $onBaseUnit;
        $product->author_id = 1;
        $product->save();

        foreach ( $resolved as $unit ) {
            $row = new ProductUnitQuantity;
            $row->product_id = $product->id;
            $row->unit_id = $unit->id;
            $row->type = 'product';
            $row->visible = true;
            $row->is_weighable = false;
            $row->low_quantity = 0;
            $row->stock_alert_enabled = false;
            $row->quantity = 0;
            $row->sale_price = 10 * (float) $unit->value;
            $row->sale_price_edit = 10 * (float) $unit->value;
            $row->sale_price_gross = 10 * (float) $unit->value;
            $row->sale_price_net = 10 * (float) $unit->value;
            $row->sale_price_tax = 0;
            $row->wholesale_price = 10 * (float) $unit->value;
            $row->wholesale_price_edit = 10 * (float) $unit->value;
            $row->wholesale_price_gross = 10 * (float) $unit->value;
            $row->wholesale_price_net = 10 * (float) $unit->value;
            $row->wholesale_price_tax = 0;
            $row->cogs = 5;
            $row->save();
        }

        return [ 'product' => $product, 'units' => $resolved ];
    }

    /**
     * Receives goods the way the purchase screen does, through the same endpoint, so what is proved
     * here is what happens when a storeman saves a delivery rather than what happens when a test
     * assembles rows by hand.
     */
    private function receive( Product $product, Unit $unit, float $quantity ): void
    {
        $provider = Provider::first();

        if ( ! $provider instanceof Provider ) {
            $provider = new Provider;
            $provider->first_name = 'ผู้จำหน่ายทดสอบรับของ';
            $provider->email = 'provider' . random_int( 1000, 9999 ) . '@test.invalid';
            $provider->author_id = 1;
            $provider->save();
        }

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/procurements', [
                'name' => 'ใบรับของทดสอบ ' . random_int( 1000, 9999 ),
                'general' => [
                    'provider_id' => $provider->id,
                    'payment_status' => Procurement::PAYMENT_PAID,
                    'delivery_status' => Procurement::DELIVERED,
                    'author_id' => 1,
                    'automatic_approval' => 1,
                    'created_at' => ns()->date->now()->toDateTimeString(),
                ],
                'products' => [
                    [
                        'product_id' => $product->id,
                        'unit_id' => $unit->id,
                        'quantity' => $quantity,
                        'gross_purchase_price' => 5,
                        'net_purchase_price' => 5,
                        'purchase_price' => 5,
                        'total_purchase_price' => 5 * $quantity,
                        'tax_group_id' => 0,
                        'tax_type' => 'inclusive',
                        'tax_value' => 0,
                    ],
                ],
            ] );

        $response->assertOk();
    }

    private function quantity( Product $product, Unit $unit ): float
    {
        return (float) app( ProductService::class )->getQuantity( $product->id, $unit->id );
    }

    /**
     * The fault. Five kilos of loose sugar received against a shelf counted in hundred gram bags is
     * fifty bags, and it has to land on the row the till reads.
     */
    public function test_receiving_in_a_larger_unit_lands_on_the_unit_that_bears_the_stock(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            name: 'น้ำตาลทรายขาว รับเป็นกิโล',
            units: [ '100 กรัม' => 1.0, '1 กก.' => 10.0 ],
            onBaseUnit: true
        );

        $this->receive( $product, $units[ '1 กก.' ], 5 );

        $this->assertSame(
            50.0,
            $this->quantity( $product, $units[ '100 กรัม' ] ),
            'Five kilos against a hundred gram bag is fifty bags.'
        );

        $this->assertSame(
            0.0,
            $this->quantity( $product, $units[ '1 กก.' ] ),
            'The kilo row is a price label and must never carry a figure of its own.'
        );
    }

    /**
     * Receiving in the base unit itself needs no conversion and must not be scaled by anything.
     */
    public function test_receiving_in_the_base_unit_is_added_as_stated(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            name: 'ถั่วเขียว รับเป็นถุงเล็ก',
            units: [ '100 กรัม' => 1.0, '1 กก.' => 10.0 ],
            onBaseUnit: true
        );

        $this->receive( $product, $units[ '100 กรัม' ], 37 );

        $this->assertSame( 37.0, $this->quantity( $product, $units[ '100 กรัม' ] ) );
        $this->assertSame( 0.0, $this->quantity( $product, $units[ '1 กก.' ] ) );
    }

    /**
     * Two deliveries add up rather than replace one another.
     */
    public function test_two_deliveries_in_different_units_add_up_on_the_same_row(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            name: 'แป้งสาลี รับสองครั้ง',
            units: [ '100 กรัม' => 1.0, 'ครึ่งโล' => 5.0, '1 กก.' => 10.0 ],
            onBaseUnit: true
        );

        $this->receive( $product, $units[ '1 กก.' ], 2 );
        $this->receive( $product, $units[ 'ครึ่งโล' ], 3 );

        $this->assertSame(
            35.0,
            $this->quantity( $product, $units[ '100 กรัม' ] ),
            'Two kilos and three half kilos is three and a half kilos in hundred gram bags.'
        );
    }

    /**
     * Ordinary goods keep their own figure per unit, which is the behaviour every other product in
     * the catalogue relies on. The conversion must not reach them.
     */
    public function test_goods_without_the_flag_are_untouched_by_the_conversion(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            name: 'สินค้าธรรมดา หน่วยแยกกัน',
            units: [ 'ชิ้น' => 1.0, 'แพ็ค' => 6.0 ],
            onBaseUnit: false
        );

        $this->receive( $product, $units[ 'แพ็ค' ], 4 );

        $this->assertSame(
            4.0,
            $this->quantity( $product, $units[ 'แพ็ค' ] ),
            'Without the flag a purchased pack is four packs, not twenty four pieces.'
        );

        $this->assertSame( 0.0, $this->quantity( $product, $units[ 'ชิ้น' ] ) );
    }

    /**
     * A one unit product is the commonest case in the catalogue and has to behave exactly as before.
     */
    public function test_a_one_unit_product_is_received_as_stated(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            name: 'สินค้าหน่วยเดียว',
            units: [ 'ชิ้น' => 1.0 ],
            onBaseUnit: false
        );

        $this->receive( $product, $units[ 'ชิ้น' ], 19 );

        $this->assertSame( 19.0, $this->quantity( $product, $units[ 'ชิ้น' ] ) );
    }

    /**
     * Received goods have to be sellable. This joins the two halves: a delivery in kilos, then a sale
     * in half kilos off the same shelf.
     */
    public function test_goods_received_in_kilos_can_be_sold_in_half_kilos(): void
    {
        $this->attemptAuthenticate();

        [ 'product' => $product, 'units' => $units ] = $this->makeProduct(
            name: 'ข้าวเหนียว รับกิโลขายครึ่งโล',
            units: [ '100 กรัม' => 1.0, 'ครึ่งโล' => 5.0, '1 กก.' => 10.0 ],
            onBaseUnit: true
        );

        $this->receive( $product, $units[ '1 กก.' ], 4 );

        $this->assertSame( 40.0, $this->quantity( $product, $units[ '100 กรัม' ] ) );

        app( ProductService::class )->stockAdjustment( ProductHistory::ACTION_LOST, [
            'product_id' => $product->id,
            'unit_id' => $units[ 'ครึ่งโล' ]->id,
            'quantity' => 3,
            'unit_price' => 0,
            'author' => 1,
        ] );

        $this->assertSame(
            25.0,
            $this->quantity( $product, $units[ '100 กรัม' ] ),
            'Four kilos less three half kilos is two and a half kilos.'
        );
    }
}

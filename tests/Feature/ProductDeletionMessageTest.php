<?php

namespace Tests\Feature;

use App\Classes\Model;
use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A product that appears on a saved bill must not be deletable, and the refusal has to
 * name the bill.
 *
 * The message used to read "Unable to delete X as it's a dependency for Non-existent Item"
 * because of two separate faults that hid each other:
 *
 *   1. Model::related() writes the label column under "local_name", but NsDependable read
 *      only "foreign_name", so the label column was always the literal "name" no matter
 *      what the model declared. An order has no name column, only a code.
 *   2. Product declared foreign_index: 'product_id' on the related order, which made the
 *      lookup ask for the order whose id equals the product id.
 *
 * Fixing either one alone still produces a useless message, so both are asserted here.
 */
class ProductDeletionMessageTest extends TestCase
{
    use WithFaker;

    /**
     * Every row this class creates, children before parents, as [ table, id ] pairs.
     *
     * This class used to leave all of it behind. On a freshly built database it therefore created
     * unit group id 1, and every fixture product made afterwards attached itself to that group.
     * That group's unit carries no stock, so the whole order and stock cluster in the later suites
     * failed with "not enough stock" on a unit that exists only for this test. These tests passed
     * throughout, which is why it went unnoticed: the damage landed on other suites.
     *
     * DatabaseTransactions is the usual answer and was tried first, but it holds a write
     * transaction open for the length of each test, and saving an Order inside that transaction
     * makes SQLite report "database is locked" for the rest of the suite. Cleaning up explicitly
     * isolates this class without holding a transaction at all.
     *
     * @var list<array{0: string, 1: int}>
     */
    private array $createdRows = [];

    /**
     * Rows are removed with the query builder rather than through the models, so the dependency
     * guard this class exists to test cannot refuse the cleanup.
     */
    protected function tearDown(): void
    {
        foreach ( $this->createdRows as [ $table, $id ] ) {
            DB::table( $table )->where( 'id', $id )->delete();
        }

        $this->createdRows = [];

        parent::tearDown();
    }

    private function remember( string $table, int $id ): void
    {
        // Prepended so teardown walks children before the parents they point at.
        array_unshift( $this->createdRows, [ $table, $id ] );
    }

    public function test_a_product_on_a_bill_cannot_be_deleted(): void
    {
        [ $product ] = $this->productSoldOnABill();

        $this->expectException( NotAllowedException::class );

        $product->delete();
    }

    public function test_the_refusal_names_the_bill_by_its_code(): void
    {
        [ $product, $order ] = $this->productSoldOnABill();

        try {
            $product->delete();
            $this->fail( 'The product was deleted even though it appears on a bill.' );
        } catch ( NotAllowedException $exception ) {
            $message = $exception->getMessage();
        }

        $this->assertStringContainsString(
            $product->name,
            $message,
            'The refusal has to say which product was refused.'
        );

        $this->assertStringContainsString(
            $order->code,
            $message,
            'The refusal has to name the bill, which is the only identifier the user can act on.'
        );

        foreach ( [ 'Non-existent Item', 'ไม่มีรายการนี้', 'Undefined Item', 'ไม่ระบุรายการ' ] as $placeholder ) {
            $this->assertStringNotContainsString(
                $placeholder,
                $message,
                sprintf( 'The bill could not be resolved and fell back to "%s".', $placeholder )
            );
        }
    }

    /**
     * When more than one row depends on the item, the message gains a tail built with
     * trans_choice. That key had no Thai entry, so a Thai refusal ended in "and 33 more
     * items." — the sentence changed language halfway through, in the one message the shop
     * reads when it cannot delete something.
     *
     * Asserted on the translation file rather than by provoking a multi dependency refusal,
     * because the tail only appears above a threshold and building that is a lot of fixture
     * for what is a missing translation.
     */
    public function test_the_extra_dependency_count_is_translated(): void
    {
        $key = '{1} and :count more item.|[2,*] and :count more items.';

        $entries = json_decode( file_get_contents( lang_path( 'th.json' ) ), true );

        $this->assertIsArray( $entries, 'th.json did not parse.' );

        $this->assertArrayHasKey(
            $key,
            $entries,
            'The count tail has no Thai entry, so a Thai refusal ends in English.'
        );

        $this->assertStringNotContainsString(
            'more item',
            $entries[ $key ],
            'The Thai entry still carries the English wording.'
        );

        /**
         * trans_choice needs both branches and the placeholder in each, or the tail renders
         * as the raw key.
         */
        $this->assertStringContainsString( '{1}', $entries[ $key ] );
        $this->assertStringContainsString( '[2,*]', $entries[ $key ] );
        $this->assertSame(
            2,
            substr_count( $entries[ $key ], ':count' ),
            'Both plural branches need the :count placeholder.'
        );
    }

    /**
     * The related lookup has to walk order_id, not product_id.
     *
     * Asserted on the declaration itself as well as through the message, because a wrong
     * index here silently degrades to a fallback string rather than failing outright.
     */
    public function test_the_related_order_is_resolved_through_order_id(): void
    {
        $related = ( new Product )->setDependencies()[ OrderProduct::class ][ 'related' ];

        $this->assertSame( Order::class, $related[ 'model' ] );
        $this->assertSame( 'order_id', $related[ 'foreign_index' ] );
        $this->assertSame( 'id', $related[ 'local_index' ] );
        $this->assertSame( 'code', $related[ 'local_name' ] );
    }

    /**
     * A declared label column must actually be used.
     *
     * Guards the NsDependable side of the fix on its own, so the behaviour cannot regress
     * back to always reading a column called "name".
     */
    public function test_a_declared_label_column_is_honoured(): void
    {
        $related = Model::related(
            model: Order::class,
            local_index: 'id',
            foreign_index: 'order_id',
            local_name: 'code'
        );

        $this->assertArrayHasKey( 'local_name', $related );
        $this->assertSame( 'code', $related[ 'local_name' ] );
        $this->assertArrayNotHasKey(
            'foreign_name',
            $related,
            'Model::related() does not write foreign_name, which is why reading only that key failed.'
        );
    }

    /**
     * @return array{0: Product, 1: Order}
     */
    private function productSoldOnABill(): array
    {
        $author = User::first();

        /**
         * Named uniquely rather than looked up by a fixed name. firstOrCreate on a fixed name
         * meant a single shared group, and if isolation is ever lost again that group is the one
         * every other fixture product will attach to. A unique name keeps the blast radius to
         * this test even in that case.
         */
        $suffix = strtoupper( substr( uniqid(), -6 ) );

        $group = new UnitGroup;
        $group->name = 'กลุ่มหน่วยทดสอบการลบ ' . $suffix;
        $group->author_id = $author->id;
        $group->save();
        $this->remember( 'nexopos_units_groups', $group->id );

        $unit = new Unit;
        $unit->name = 'ชิ้นทดสอบการลบ ' . $suffix;
        $unit->identifier = 'test-delete-piece-' . strtolower( $suffix );
        $unit->group_id = $group->id;
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->author_id = $author->id;
        $unit->save();
        $this->remember( 'nexopos_units', $unit->id );

        /**
         * Built column by column rather than through a factory, matching the shape
         * BranchScopedStockDeductionTest already uses. Several columns on this table are
         * NOT NULL without a default.
         */
        $product = new Product;
        $product->name = 'สินค้าทดสอบการลบ ' . uniqid();
        $product->barcode = 'DEL' . uniqid();
        $product->sku = $product->barcode;
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
        $product->author_id = $author->id;
        $product->type = Product::TYPE_MATERIALIZED;
        $product->product_type = 'product';
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->tax_type = 'inclusive';
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->searchable = true;
        $product->save();
        $this->remember( 'nexopos_products', $product->id );

        $order = new Order;
        $order->code = 'DELTEST-' . strtoupper( substr( uniqid(), -6 ) );
        $order->title = 'บิลทดสอบการลบสินค้า';
        $order->type = 'takeaway';
        $order->payment_status = Order::PAYMENT_PAID;
        $order->customer_id = 0;
        $order->subtotal = 100;
        $order->total = 100;
        $order->discount = 0;
        $order->tax_value = 0;
        $order->author_id = $author->id;
        $order->created_at = ns()->date->getNow()->toDateTimeString();
        $order->save();
        $this->remember( 'nexopos_orders', $order->id );

        $line = new OrderProduct;
        $line->order_id = $order->id;
        $line->product_id = $product->id;
        $line->unit_id = $unit->id;
        $line->unit_quantity_id = 0;
        $line->product_category_id = 0;
        $line->name = $product->name;
        $line->unit_name = $unit->name;
        $line->quantity = 1;
        $line->unit_price = 100;
        $line->discount = 0;
        $line->total_price = 100;
        $line->save();
        $this->remember( 'nexopos_orders_products', $line->id );

        return [ $product, $order ];
    }
}

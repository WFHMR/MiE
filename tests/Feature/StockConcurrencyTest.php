<?php

namespace Tests\Feature;

use App\Events\ProductAfterStockAdjustmentEvent;
use App\Events\ProductStockUpdatedEvent;
use App\Exceptions\NotAllowedException;
use App\Exceptions\NotFoundException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithCustomerTest;

/**
 * Covers stock correctness when several tills sell from the same catalogue.
 *
 * The shop runs multiple checkout points per branch. The deduction used to read
 * the quantity into PHP, subtract there, and write the result back as an absolute
 * value. Two tills selling the last unit both read 1, both computed 0, and both
 * wrote 0 — the second write discarded the first sale's deduction and both sales
 * went through. Nothing in the codebase locked, and order creation was not
 * transactional, so the check and the deduction could be minutes apart.
 *
 * These tests pin the three properties that make that impossible: the deduction
 * ignores whatever the caller read, it refuses to go below zero, and the whole
 * order rolls back as one unit.
 */
class StockConcurrencyTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication, WithCustomerTest;

    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = app()->make( ProductService::class );
    }

    public function test_reducing_stock_ignores_the_stale_quantity_the_caller_passed(): void
    {
        $unitQuantity = $this->givenStock( 5 );

        /**
         * The caller passes a quantity it read earlier. This is the exact shape of the
         * lost update: with the old implementation the new value was computed from
         * this argument, so a stale 100 produced 99 and the real stock of 5 was
         * overwritten. The result must depend only on what is in the database.
         */
        $result = $this->productService->reduceUnitQuantities(
            product_id: $unitQuantity->product_id,
            unit_id: $unitQuantity->unit_id,
            quantity: 1,
            oldQuantity: 100
        );

        $this->assertSame( 4.0, (float) $unitQuantity->refresh()->quantity );
        $this->assertSame( 4.0, (float) $result[ 'data' ][ 'newQuantity' ] );
        $this->assertSame( 5.0, (float) $result[ 'data' ][ 'oldQuantity' ] );
    }

    public function test_increasing_stock_ignores_the_stale_quantity_the_caller_passed(): void
    {
        $unitQuantity = $this->givenStock( 5 );

        $result = $this->productService->increaseUnitQuantities(
            product_id: $unitQuantity->product_id,
            unit_id: $unitQuantity->unit_id,
            quantity: 2,
            oldQuantity: 100
        );

        $this->assertSame( 7.0, (float) $unitQuantity->refresh()->quantity );
        $this->assertSame( 7.0, (float) $result[ 'data' ][ 'newQuantity' ] );
        $this->assertSame( 5.0, (float) $result[ 'data' ][ 'oldQuantity' ] );
    }

    public function test_reducing_exactly_the_available_quantity_succeeds(): void
    {
        $unitQuantity = $this->givenStock( 3 );

        $this->productService->reduceUnitQuantities(
            product_id: $unitQuantity->product_id,
            unit_id: $unitQuantity->unit_id,
            quantity: 3,
            oldQuantity: 3
        );

        $this->assertSame( 0.0, (float) $unitQuantity->refresh()->quantity );
    }

    public function test_reducing_more_than_available_is_refused_and_leaves_the_stock_untouched(): void
    {
        $unitQuantity = $this->givenStock( 2 );

        try {
            $this->productService->reduceUnitQuantities(
                product_id: $unitQuantity->product_id,
                unit_id: $unitQuantity->unit_id,
                quantity: 3,
                oldQuantity: 2
            );

            $this->fail( 'Deducting more than the shop holds must be refused.' );
        } catch ( NotAllowedException $exception ) {
            $this->assertStringContainsString( 'stock', strtolower( $exception->getMessage() ) );
        }

        /**
         * A refused deduction must not partially apply. This is what stops a second
         * till from driving the quantity negative.
         */
        $this->assertSame( 2.0, (float) $unitQuantity->refresh()->quantity );
    }

    /**
     * The second half of the race: the same last unit deducted twice in a row. In
     * production the two calls come from two requests; the guard that rejects the
     * second one is the same either way, because it lives in the UPDATE statement
     * rather than in PHP.
     */
    public function test_the_last_unit_cannot_be_deducted_twice(): void
    {
        $unitQuantity = $this->givenStock( 1 );

        $this->productService->reduceUnitQuantities(
            product_id: $unitQuantity->product_id,
            unit_id: $unitQuantity->unit_id,
            quantity: 1,
            oldQuantity: 1
        );

        $this->expectException( NotAllowedException::class );

        /**
         * Both calls are handed the same "there is 1 in stock" reading, exactly as two
         * concurrent requests would be.
         */
        $this->productService->reduceUnitQuantities(
            product_id: $unitQuantity->product_id,
            unit_id: $unitQuantity->unit_id,
            quantity: 1,
            oldQuantity: 1
        );
    }

    public function test_deducting_from_a_unit_with_no_stock_row_is_reported_as_missing(): void
    {
        $product = $this->givenStock( 5 )->product;

        $this->expectException( NotFoundException::class );

        $this->productService->reduceUnitQuantities(
            product_id: $product->id,
            unit_id: $this->givenUnitWithoutStockRow( $product )->id,
            quantity: 1,
            oldQuantity: 0
        );
    }

    /**
     * Procuring in a unit the product has never held is normal — a purchase unit
     * that is not a selling unit. The row has to be created rather than the
     * procurement refused.
     */
    public function test_increasing_stock_creates_the_row_when_the_unit_has_none(): void
    {
        $product = $this->givenStock( 5 )->product;
        $unusedUnit = $this->givenUnitWithoutStockRow( $product );

        $this->productService->increaseUnitQuantities(
            product_id: $product->id,
            unit_id: $unusedUnit->id,
            quantity: 12,
            oldQuantity: 0
        );

        $created = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unusedUnit->id )
            ->firstOrFail();

        $this->assertSame( 12.0, (float) $created->quantity );
    }

    public function test_a_stock_change_is_announced_to_the_other_tills(): void
    {
        $unitQuantity = $this->givenStock( 9 );

        Event::fake( [ ProductStockUpdatedEvent::class ] );

        $this->productService->reduceUnitQuantities(
            product_id: $unitQuantity->product_id,
            unit_id: $unitQuantity->unit_id,
            quantity: 4,
            oldQuantity: 9
        );

        Event::assertDispatched(
            ProductStockUpdatedEvent::class,
            function ( ProductStockUpdatedEvent $event ) use ( $unitQuantity ) {
                return $event->unit_quantity_id === (int) $unitQuantity->id
                    && $event->product_id === (int) $unitQuantity->product_id
                    && $event->quantity === 5.0;
            }
        );
    }

    public function test_the_stock_event_carries_only_what_a_till_needs(): void
    {
        $unitQuantity = $this->givenStock( 6 );
        $event = ProductStockUpdatedEvent::fromUnitQuantity( $unitQuantity );

        $this->assertSame( 'stock.updated', $event->broadcastAs() );

        $channels = $event->broadcastOn();

        $this->assertInstanceOf( Channel::class, $channels[ 0 ] );
        $this->assertSame( 'stock', $channels[ 0 ]->name );

        /**
         * Cost prices and supplier references must not reach a till screen, so the
         * payload is asserted to be exactly these four keys.
         */
        $this->assertSame(
            [ 'product_id', 'unit_quantity_id', 'unit_id', 'quantity' ],
            array_keys( $event->broadcastWith() )
        );

        $this->assertSame( 6.0, $event->broadcastWith()[ 'quantity' ] );
    }

    /**
     * The Reverb server is a separate process and will sometimes be down. When it is,
     * dispatching a broadcast throws — and because the announcement happens at commit
     * time, an unguarded throw surfaces after the order was already written. The till
     * would report a failure for a sale that succeeded and the cashier would ring it
     * up twice.
     */
    public function test_a_sale_still_completes_when_the_tills_cannot_be_told(): void
    {
        $unitQuantity = $this->givenStock( 8 );

        Event::listen( ProductStockUpdatedEvent::class, function () {
            throw new RuntimeException( 'Reverb is unreachable.' );
        } );

        $this->productService->reduceUnitQuantities(
            product_id: $unitQuantity->product_id,
            unit_id: $unitQuantity->unit_id,
            quantity: 3,
            oldQuantity: 8
        );

        $this->assertSame(
            5.0,
            (float) $unitQuantity->refresh()->quantity,
            'A failed announcement must not undo or block the deduction.'
        );
    }

    public function test_a_failure_after_the_stock_was_deducted_rolls_the_whole_order_back(): void
    {
        $this->attemptAuthenticate();

        $unitQuantity = $this->givenStock( 20 );
        $ordersBefore = Order::count();

        /**
         * ProductAfterStockAdjustmentEvent is dispatched synchronously right after the
         * deduction and after the order rows are written, so throwing here reproduces
         * a failure late in order creation — the case that used to leave a persisted
         * order whose stock had never been taken, or the reverse.
         */
        Event::listen( ProductAfterStockAdjustmentEvent::class, function () {
            throw new RuntimeException( 'Forced failure after the stock was deducted.' );
        } );

        $this->postOrder( $unitQuantity, quantity: 2 );

        $this->assertSame(
            $ordersBefore,
            Order::count(),
            'A failure during order creation must not leave an order behind.'
        );

        $this->assertSame(
            20.0,
            (float) $unitQuantity->refresh()->quantity,
            'A failure during order creation must not leave the stock deducted.'
        );
    }

    public function test_selling_the_last_unit_twice_is_refused_end_to_end(): void
    {
        $this->attemptAuthenticate();

        $unitQuantity = $this->givenStock( 1 );

        $this->postOrder( $unitQuantity, quantity: 1 )->assertStatus( 200 );

        $this->assertSame( 0.0, (float) $unitQuantity->refresh()->quantity );

        $second = $this->postOrder( $unitQuantity, quantity: 1 );

        $this->assertNotSame(
            200,
            $second->status(),
            'The shop has nothing left, so the second sale of the last unit must be refused.'
        );

        $this->assertSame(
            0.0,
            (float) $unitQuantity->refresh()->quantity,
            'A refused sale must not drive the stock negative.'
        );
    }

    /**
     * A unit the product holds no stock row for.
     *
     * Created rather than looked up: the fixture gives each product a unit group with
     * a single unit, so there is no guarantee a spare one exists, and skipping the
     * test would hide the procurement regression it guards against.
     */
    private function givenUnitWithoutStockRow( Product $product ): Unit
    {
        return Unit::factory()->create( [
            'group_id' => $product->unit_group,
            'base_unit' => 0,
        ] );
    }

    /**
     * A product with stock management on, its stock set to a known quantity.
     */
    private function givenStock( float $quantity ): ProductUnitQuantity
    {
        $product = Product::withStockEnabled()
            ->notGrouped()
            ->notInGroup()
            ->whereHas( 'unit_quantities' )
            ->with( 'unit_quantities' )
            ->first();

        $this->assertNotNull( $product, 'A stock managed product is required to run this test.' );

        $unitQuantity = $product->unit_quantities->first();
        $unitQuantity->quantity = $quantity;
        $unitQuantity->save();

        return $unitQuantity->fresh( 'product' );
    }

    private function postOrder( ProductUnitQuantity $unitQuantity, float $quantity )
    {
        $customer = Customer::first() ?: $this->attemptCreateCustomer();
        $total = $unitQuantity->sale_price * $quantity;

        return $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/orders', [
                'customer_id' => $customer->id,
                'type' => [ 'identifier' => 'takeaway' ],
                'addresses' => [],
                'author_id' => User::first()->id,
                'shipping' => 0,
                'subtotal' => $total,
                'products' => [
                    [
                        'product_id' => $unitQuantity->product_id,
                        'name' => $unitQuantity->product->name,
                        'quantity' => $quantity,
                        'unit_price' => $unitQuantity->sale_price,
                        'unit_quantity_id' => $unitQuantity->id,
                        'unit_id' => $unitQuantity->unit_id,
                        'tax_type' => 'disabled',
                        'tax_group_id' => 0,
                    ],
                ],
                'payments' => [
                    [
                        'identifier' => 'cash-payment',
                        'value' => $total,
                    ],
                ],
            ] );
    }
}

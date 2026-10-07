<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Moving stock from one branch to another.
 *
 * The list page renders fine while the table is empty, which is how the shop has seen it, and breaks the
 * moment a transfer is waiting to be approved: the row builder calls $entry->addAction(), and CrudEntry
 * has no such method and no __call to absorb it. So the feature works right up until it is used.
 *
 * Approving and cancelling were also reachable by GET. A GET that moves stock between branches is
 * triggered by anything that follows a link — a prefetch, a bookmark, a URL pasted into a chat — and the
 * confirm dialog in the row action guards the mouse, not the request.
 */
class StockTransferPageTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->attemptGetAnyUserFromRole( 'admin' );
    }

    /**
     * Dashboard pages are requested with the session guard rather than through Sanctum. The web group
     * runs AuthenticateSession, which asks the guard whether the user arrived via a remember cookie, and
     * Sanctum's guard has no such method — so authenticating the way the api tests do turns every page
     * request into a 500 that has nothing to do with the page.
     */
    public function test_the_list_page_opens_when_a_transfer_is_waiting_to_be_approved(): void
    {
        $this->givenPendingTransfer();

        $response = $this->actingAs( $this->admin )->get( '/dashboard/stock-transfers' );

        $response->assertStatus( 200 );
        $response->assertSee( 'dashboard-body', false );
    }

    public function test_the_edit_page_opens(): void
    {
        $transfer = $this->givenPendingTransfer();

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/stock-transfers/edit/' . $transfer->id );

        $response->assertStatus( 200 );
    }

    /**
     * Stock is not moved by following a link.
     */
    public function test_approving_is_not_reachable_by_get(): void
    {
        $transfer = $this->givenPendingTransfer();

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/stock-transfers/approve/' . $transfer->id );

        $this->assertContains(
            $response->status(),
            [ 404, 405 ],
            'A GET request reached the approve action. Approving moves goods between branches, so it must '
                . 'not be reachable by following a link.'
        );

        $this->assertSame(
            'pending',
            $transfer->fresh()->status,
            'The transfer was approved by a GET request.'
        );
    }

    public function test_cancelling_is_not_reachable_by_get(): void
    {
        $transfer = $this->givenPendingTransfer();

        $response = $this->actingAs( $this->admin )
            ->get( '/dashboard/stock-transfers/cancel/' . $transfer->id );

        $this->assertContains( $response->status(), [ 404, 405 ] );

        $this->assertSame( 'pending', $transfer->fresh()->status );
    }

    public function test_cancelling_marks_the_transfer_and_moves_no_stock(): void
    {
        $this->attemptAuthenticate( $this->admin );

        $transfer = $this->givenPendingTransfer( quantity: 3.0 );

        $source = $this->stockRow( $transfer->product_id, $transfer->unit_id, $transfer->from_branch_id );
        $sourceBefore = (float) $source->quantity;

        $this->postJson( '/api/stock-transfers/' . $transfer->id . '/cancel' )
            ->assertStatus( 200 )
            ->assertJson( [ 'status' => 'success' ] );

        $this->assertSame( 'cancelled', $transfer->fresh()->status );

        $this->assertEqualsWithDelta(
            $sourceBefore,
            (float) $source->fresh()->quantity,
            0.001,
            'Cancelling a transfer moved stock.'
        );
    }

    public function test_approving_moves_the_stock_and_only_once(): void
    {
        $this->attemptAuthenticate( $this->admin );

        $transfer = $this->givenPendingTransfer( quantity: 4.0 );

        $source = $this->stockRow( $transfer->product_id, $transfer->unit_id, $transfer->from_branch_id );
        $sourceBefore = (float) $source->quantity;

        $response = $this->postJson( '/api/stock-transfers/' . $transfer->id . '/approve' );

        $this->assertSame(
            200,
            $response->status(),
            'Approving answered ' . $response->status() . ': ' . $response->getContent()
        );

        $response->assertJson( [ 'status' => 'success' ] );

        $this->assertSame( 'completed', $transfer->fresh()->status );

        $this->assertEqualsWithDelta(
            $sourceBefore - 4.0,
            (float) $source->fresh()->quantity,
            0.001,
            'The source branch was not debited by the quantity transferred.'
        );

        $destination = $this->stockRow( $transfer->product_id, $transfer->unit_id, $transfer->to_branch_id );

        $this->assertEqualsWithDelta( 4.0, (float) $destination->quantity, 0.001 );

        /**
         * A second approval must not move the goods again. Without the status guard a refreshed page
         * would transfer twice.
         */
        $again = $this->postJson( '/api/stock-transfers/' . $transfer->id . '/approve' );

        $again->assertStatus( 400 );

        $this->assertEqualsWithDelta( 4.0, (float) $destination->fresh()->quantity, 0.001 );
    }

    /**
     * The ledger has to say which shelf moved and in which direction, in the words the rest of the
     * system uses. The controller wrote 'transfer_out', while the model's value is 'outgoing-transfer'
     * and the display cast answers "Unknown Action" for anything else, so every transfer would have been
     * labelled that way on the product history page and counted as neither a reduction nor an increase.
     */
    public function test_approving_records_the_movement_under_the_names_the_system_knows(): void
    {
        $this->attemptAuthenticate( $this->admin );

        $transfer = $this->givenPendingTransfer( quantity: 6.0 );

        $this->postJson( '/api/stock-transfers/' . $transfer->id . '/approve' )
            ->assertStatus( 200 );

        $out = ProductHistory::where( 'product_id', $transfer->product_id )
            ->where( 'branch_id', $transfer->from_branch_id )
            ->where( 'operation_type', ProductHistory::ACTION_TRANSFER_OUT )
            ->latest( 'id' )
            ->first();

        $in = ProductHistory::where( 'product_id', $transfer->product_id )
            ->where( 'branch_id', $transfer->to_branch_id )
            ->where( 'operation_type', ProductHistory::ACTION_TRANSFER_IN )
            ->latest( 'id' )
            ->first();

        $this->assertNotNull( $out, 'No outgoing movement was recorded against the source branch.' );
        $this->assertNotNull( $in, 'No incoming movement was recorded against the destination branch.' );

        $this->assertContains( ProductHistory::ACTION_TRANSFER_OUT, ProductHistory::STOCK_REDUCE );
        $this->assertContains( ProductHistory::ACTION_TRANSFER_IN, ProductHistory::STOCK_INCREASE );

        /** Stored unsigned, with the direction carried by the operation type, as everywhere else. */
        $this->assertEqualsWithDelta( 6.0, (float) $out->quantity, 0.001 );
        $this->assertEqualsWithDelta( 6.0, (float) $in->quantity, 0.001 );

        $this->assertEqualsWithDelta(
            (float) $out->before_quantity - 6.0,
            (float) $out->after_quantity,
            0.001,
            'The outgoing row does not show the source shelf falling by the quantity moved.'
        );
    }

    private function givenPendingTransfer( float $quantity = 2.0 ): StockTransfer
    {
        /**
         * Created directly rather than through a factory: there is no BranchFactory, and code is both
         * required and unique, so it has to be distinct per run.
         */
        $from = Branch::create( [
            'code' => 'XFR-FROM-' . uniqid(),
            'name' => 'สาขาต้นทางสำหรับทดสอบการโอน',
            'is_active' => true,
            'is_main' => false,
            'author_id' => auth()->id() ?? 1,
        ] );

        $to = Branch::create( [
            'code' => 'XFR-TO-' . uniqid(),
            'name' => 'สาขาปลายทางสำหรับทดสอบการโอน',
            'is_active' => true,
            'is_main' => false,
            'author_id' => auth()->id() ?? 1,
        ] );

        $unitQuantity = ProductUnitQuantity::whereHas( 'product' )->firstOrFail();

        $product = Product::findOrFail( $unitQuantity->product_id );
        $unit = Unit::findOrFail( $unitQuantity->unit_id );

        $source = $this->stockRow( $product->id, $unit->id, $from->id );
        $source->quantity = 50;
        $source->save();

        return StockTransfer::create( [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'quantity' => $quantity,
            'status' => 'pending',
            'requested_by' => User::value( 'id' ),
            'notes' => 'สร้างโดยชุดทดสอบ',
        ] );
    }

    /**
     * Built by assignment, not firstOrCreate. ProductUnitQuantity declares no $fillable, so it is
     * totally guarded and mass assignment throws — the same fault the approve path carried.
     */
    private function stockRow( int $productId, int $unitId, int $branchId ): ProductUnitQuantity
    {
        $row = ProductUnitQuantity::where( 'product_id', $productId )
            ->where( 'unit_id', $unitId )
            ->where( 'branch_id', $branchId )
            ->first();

        if ( $row instanceof ProductUnitQuantity ) {
            return $row;
        }

        $row = new ProductUnitQuantity;
        $row->product_id = $productId;
        $row->unit_id = $unitId;
        $row->branch_id = $branchId;
        $row->quantity = 0;
        $row->save();

        return $row;
    }
}

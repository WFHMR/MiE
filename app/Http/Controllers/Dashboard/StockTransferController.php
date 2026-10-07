<?php

namespace App\Http\Controllers\Dashboard;

use App\Crud\StockTransferCrud;
use App\Http\Controllers\DashboardController;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\StockTransfer;
use App\Services\DateService;
use App\Services\ProductService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockTransferController extends DashboardController
{
    public function __construct(
        protected DateService $dateService,
        protected ProductService $productService
    ) {
        parent::__construct( $dateService );
    }

    /**
     * Display list of stock transfers
     */
    public function list()
    {
        return StockTransferCrud::table();
    }

    /**
     * Create a new stock transfer
     */
    public function create()
    {
        return StockTransferCrud::form();
    }

    /**
     * Edit a stock transfer
     */
    public function edit( $id )
    {
        return StockTransferCrud::form( StockTransfer::findOrFail( $id ) );
    }

    /**
     * Approve a pending stock transfer
     */
    public function approve( $id )
    {
        DB::beginTransaction();

        try {
            $transfer = StockTransfer::with( ['product', 'unit', 'fromBranch', 'toBranch'] )
                ->lockForUpdate()
                ->findOrFail( $id );

            if ( $transfer->status !== 'pending' ) {
                throw new Exception( __( 'Only pending transfers can be approved.' ) );
            }

            // Check if source branch has enough stock
            $sourceStock = ProductUnitQuantity::where( 'product_id', $transfer->product_id )
                ->where( 'unit_id', $transfer->unit_id )
                ->where( 'branch_id', $transfer->from_branch_id )
                ->lockForUpdate()
                ->first();

            if ( ! $sourceStock || $sourceStock->quantity < $transfer->quantity ) {
                throw new Exception( __( 'Insufficient stock in source branch.' ) );
            }

            /**
             * Built by assignment rather than firstOrCreate. ProductUnitQuantity declares no $fillable,
             * so Laravel treats it as totally guarded and mass assignment throws — meaning a transfer to
             * a branch that did not already stock this product failed with the framework's "Add
             * [product_id] to fillable property" text shown to whoever pressed Approve. Every other
             * place in this application that creates one of these rows sets the properties one by one.
             */
            $destStock = ProductUnitQuantity::where( 'product_id', $transfer->product_id )
                ->where( 'unit_id', $transfer->unit_id )
                ->where( 'branch_id', $transfer->to_branch_id )
                ->lockForUpdate()
                ->first();

            if ( ! $destStock instanceof ProductUnitQuantity ) {
                $destStock = new ProductUnitQuantity;
                $destStock->product_id = $transfer->product_id;
                $destStock->unit_id = $transfer->unit_id;
                $destStock->branch_id = $transfer->to_branch_id;
                $destStock->quantity = 0;
                $destStock->save();
            }

            // Record history BEFORE updating stock (for accurate before_quantity)
            $this->createProductHistory(
                $transfer->product_id,
                $transfer->unit_id,
                $transfer->from_branch_id,
                ProductHistory::ACTION_TRANSFER_OUT,
                $transfer->quantity,
                $sourceStock->quantity,
                __( 'Transfer to :branch', [ 'branch' => $transfer->toBranch->name ] )
            );

            $this->createProductHistory(
                $transfer->product_id,
                $transfer->unit_id,
                $transfer->to_branch_id,
                ProductHistory::ACTION_TRANSFER_IN,
                $transfer->quantity,
                $destStock->quantity,
                __( 'Transfer from :branch', [ 'branch' => $transfer->fromBranch->name ] )
            );

            // Deduct from source branch
            $sourceStock->quantity -= $transfer->quantity;
            $sourceStock->save();

            // Add to destination branch
            $destStock->quantity += $transfer->quantity;
            $destStock->save();

            // Update transfer status
            $transfer->status = 'completed';
            $transfer->approved_by = auth()->id();
            $transfer->approved_at = now();
            $transfer->save();

            DB::commit();

            /**
             * JSON, not a redirect. This is called by the table row, which reads a message off the
             * response and reloads itself; a redirect handed it a page of HTML and it reported success
             * with no message at all. The failure path already answered JSON, so the two disagreed.
             */
            return response()->json( [
                'status' => 'success',
                'message' => __( 'Stock transfer approved successfully.' ),
            ] );
        } catch ( \Throwable $e ) {
            DB::rollBack();

            if ( $e instanceof ModelNotFoundException ) {
                throw $e;
            }

            return response()->json( [
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400 );
        }
    }

    /**
     * Cancel a pending stock transfer
     */
    public function cancel( $id )
    {
        return DB::transaction( function () use ( $id ) {
            $transfer = StockTransfer::lockForUpdate()->findOrFail( $id );

            if ( $transfer->status !== 'pending' ) {
                return response()->json( [
                    'status' => 'error',
                    'message' => __( 'Only pending transfers can be cancelled.' ),
                ], 400 );
            }

            $transfer->status = 'cancelled';
            $transfer->approved_by = auth()->id();
            $transfer->approved_at = now();
            $transfer->save();

            return response()->json( [
                'status' => 'success',
                'message' => __( 'Stock transfer cancelled successfully.' ),
            ] );
        } );
    }

    /**
     * Create product history record
     */
    /**
     * Three things were wrong here, and together they meant approving a transfer had never once
     * succeeded on this installation.
     *
     * ProductHistory::create() mass assigns, and the model declares no $fillable, so Laravel treats it
     * as totally guarded and throws. The caller catches Exception and answers 400, so the operator saw
     * "Add [product_id] to fillable property to allow mass assignment" and the goods stayed put. Every
     * other place in this application builds one of these rows by assignment.
     *
     * The operation type was written as the literal 'transfer_out'. The model's own value is
     * 'outgoing-transfer', and the display cast falls back to "Unknown Action" for anything it does not
     * recognise, so the product history page would have labelled every transfer that way. The two
     * constants are also what ProductHistory::STOCK_REDUCE and STOCK_INCREASE list, which is how the
     * rest of the system tells a movement's direction.
     *
     * The quantity was stored signed. recordStockHistory stores abs() and lets the operation type carry
     * the direction, and the history page prints the column as it is, so a negative number there is a
     * second minus sign on a row already labelled outgoing.
     */
    protected function createProductHistory(
        int $productId,
        int $unitId,
        int $branchId,
        string $operationType,
        float $quantity,
        float $beforeQuantity,
        string $description
    ): void {
        $outgoing = $operationType === ProductHistory::ACTION_TRANSFER_OUT;

        $history = new ProductHistory;
        $history->product_id = $productId;
        $history->unit_id = $unitId;
        $history->branch_id = $branchId;
        $history->operation_type = $operationType;
        $history->unit_price = 0;
        $history->total_price = 0;
        $history->before_quantity = $beforeQuantity;
        $history->quantity = abs( $quantity );
        $history->after_quantity = $beforeQuantity + ( $outgoing ? -$quantity : $quantity );
        $history->description = $description;
        $history->order_id = null;
        $history->procurement_id = null;
        $history->procurement_product_id = null;
        $history->author_id = Auth::id();
        $history->save();
    }
}

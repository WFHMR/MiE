<?php

/**
 * RGBF Controller
 *
 * @since  1.0
 **/

namespace App\Http\Controllers\Dashboard;

use App\Classes\Hook;
use App\Crud\ProductAdjustmentCrud;
use App\Crud\ProductCrud;
use App\Crud\ProductHistoryCrud;
use App\Crud\ProductUnitQuantitiesCrud;
use App\Crud\ScaleRangeCrud;
use App\Crud\UnitCrud;
use App\Exceptions\NotAllowedException;
use App\Exceptions\NotFoundException;
use App\Http\Controllers\DashboardController;
use App\Http\Requests\ProductRequest;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\ProductAdjustment;
use App\Models\ProductAdjustmentItem;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\ScaleRange;
use App\Models\Unit;
use App\Services\BarcodeService;
use App\Services\DateService;
use App\Services\Helper;
use App\Services\LabelPresetService;
use App\Services\ProductService;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;

class ProductsController extends DashboardController
{
    public function __construct(
        protected ProductService $productService,
        protected DateService $dateService
    ) {
        // ...
    }

    public function saveProduct( Request $request )
    {
        $primary = collect( $request->input( 'variations' ) )
            ->filter( fn( $variation ) => isset( $variation[ '$primary' ] ) )
            ->first();

        $source = $primary;
        $units = $primary[ 'units' ];

        /**
         * this is made to ensure the array
         * provided aren't flatten
         */
        unset( $primary[ 'units' ] );
        unset( $primary[ 'images' ] );
        unset( $primary[ 'groups' ] );

        $primary[ 'identification' ][ 'name' ] = $request->input( 'name' );
        $primary = Helper::flatArrayWithKeys( $primary )->toArray();
        $primary[ 'product_type' ] = 'product';

        /**
         * let's restore the fields before
         * storing that.
         */
        $primary[ 'images' ] = $source[ 'images' ];
        $primary[ 'units' ] = $source[ 'units' ];
        $primary[ 'groups' ] = $source[ 'groups' ] ?? [];

        unset( $primary[ '$primary' ] );

        /**
         * As foreign fields aren't handled with
         * they are complex (array), this methods allow
         * external script to reinject those complex fields.
         */
        $primary = Hook::filter( 'ns-create-products-inputs', $primary, $source );

        /**
         * the method "create" is capable of
         * creating either a product or a variable product
         */
        return $this->productService->create( $primary );
    }

    public function convertUnits( Request $request, Product $product )
    {
        ns()->restrict( [ 'nexopos.convert.products-units' ] );

        return $this->productService->convertUnitQuantities(
            product: $product,
            from: Unit::findOrFail( $request->input( 'from' ) ),
            to: Unit::findOrFail( $request->input( 'to' ) ),
            quantity: $request->input( 'quantity' )
        );
    }

    /**
     * returns a list of available
     * product
     *
     * @return array
     */
    public function getProduts()
    {
        return $this->productService->getProducts();
    }

    /**
     * Update a product using
     * a provided id
     *
     * @param Request
     * @param int product id
     * @return array
     */
    public function updateProduct( ProductRequest $request, Product $product )
    {
        $productCrud = new ProductCrud;
        $form = $productCrud->getFlatForm( $request->post(), $product );

        /**
         * the method "create" is capable of
         * creating either a product or a variable product
         */
        return $this->productService->update( $product, $form );
    }

    public function searchProduct( Request $request )
    {
        return $this->productService->searchProduct(
            search: $request->input( 'search' ),
            arguments: (array) $request->input( 'arguments' )
        );
    }

    public function refreshPrices( $id )
    {
        $product = $this->productService->get( $id );
        $this->productService->refreshPrices( $product );

        return [
            'status' => 'success',
            'message' => __( 'The product price has been refreshed.' ),
            'data' => compact( 'product' ),
        ];
    }

    public function reset( $identifier )
    {
        $product = $this->productService->getProductUsingArgument(
            request()->query( 'as' ) ?? 'id',
            $identifier
        );

        return $this->productService->resetProduct( $product );
    }

    /**
     * return the full history of a product
     *
     * @param int product id
     * @return array
     */
    public function history( $identifier )
    {
        $product = $this->productService->getProductUsingArgument(
            request()->query( 'as' ) ?? 'id',
            $identifier
        );

        return $this->productService->getProductHistory(
            $product->id
        );
    }

    public function units( $identifier )
    {
        $product = $this->productService->getProductUsingArgument(
            request()->query( 'as' ) ?? 'id',
            $identifier
        );

        return $this->productService->getUnitQuantities(
            $product->id
        );
    }

    public function getUnitQuantities( Product $product )
    {
        return $this->productService->getProductUnitQuantities( $product );
    }

    /**
     * delete a product
     *
     * @param int product_id
     * @return array reponse
     */
    public function deleteProduct( $identifier )
    {
        $product = $this->productService->getProductUsingArgument(
            request()->query( 'as' ) ?? 'id',
            $identifier
        );

        return $this->productService->deleteProduct( $product );
    }

    /**
     * Return a single product ig that exists
     * with his variations
     *
     * @param string|int filter
     * @return array found product
     */
    public function singleProduct( $identifier )
    {
        return $this->productService->getProductUsingArgument(
            request()->query( 'as' ) ?? 'id',
            $identifier
        );
    }

    /**
     * return all available variations
     *
     * @return array
     */
    public function getAllVariations()
    {
        return $this->productService->getProductVariations();
    }

    public function deleteAllProducts()
    {
        return $this->productService->deleteAllProducts();
    }

    public function getProductVariations( $identifier )
    {
        $product = $this->productService->getProductUsingArgument(
            request()->query( 'as' ) ?? 'id',
            $identifier
        );

        return $product->variations;
    }

    /**
     * delete a single variation product
     *
     * @param int product id
     * @param int variation id
     * @return array status of the operation
     */
    public function deleteSingleVariation( $product_id, int $variation_id )
    {
        /**
         * @todo consider registering an event for
         * catching when a single is about to be delete
         */

        /** @var Product */
        $product = $this->singleProduct( $product_id );

        $results = $product->variations->map( function ( $variation ) use ( $variation_id ) {
            if ( $variation->id === $variation_id ) {
                $variation->delete();

                return 1;
            }

            return 0;
        } );

        $opResult = $results->reduce( function ( $before, $after ) {
            return $before + $after;
        } );

        return floatval( $opResult ) > 0 ? [
            'status' => 'success',
            'message' => __( 'The single variation has been deleted.' ),
        ] : [
            'status' => 'error',
            'message' => sprintf( __( 'The the variation hasn\'t been deleted because it might not exist or is not assigned to the parent product "%s".' ), $product->name ),
        ];
    }

    /**
     * Create a single product
     * variation
     *
     * @param int product id (parent)
     * @param Request data
     * @return array
     */
    public function createSingleVariation( $product_id, Request $request )
    {
        $product = $this->productService->get( $product_id );

        return $this->productService->createProductVariation(
            $product,
            $request->all()
        );
    }

    public function editSingleVariation( $parent_id, $variation_id, Request $request )
    {
        $parent = $this->productService->get( $parent_id );

        return $this->productService->updateProductVariation( $parent, $variation_id, $request->all() );
    }

    public function listProducts()
    {
        return ProductCrud::table();
    }

    public function editProduct( Product $product )
    {
        ns()->restrict( [ 'nexopos.update.products' ] );

        return View::make( 'pages.dashboard.products.create', [
            'title' => __( 'Edit a product' ),
            'description' => __( 'Makes modifications to a product' ),
            'submitUrl' => ns()->url( '/api/products/' . $product->id ),
            'unitSrc' => ns()->url( '/api/crud/ns.units/form-config' ),
            'unitSubmitUrl' => ns()->url( '/api/crud/ns.units' ),
            'returnUrl' => ns()->url( '/dashboard/products' ),
            'unitsUrl' => ns()->url( '/api/units-groups/{id}/units' ),
            'optionAttributes' => json_encode( UnitCrud::getFormConfig()[ 'optionAttributes' ] ),
            'submitMethod' => 'PUT',
            'src' => ns()->url( '/api/crud/ns.products/form-config/' . $product->id ),
        ] );
    }

    public function createProduct()
    {
        ns()->restrict( [ 'nexopos.create.products' ] );

        return View::make( 'pages.dashboard.products.create', [
            'title' => __( 'Create a product' ),
            'description' => __( 'Add a new product on the system' ),
            'submitUrl' => ns()->url( '/api/products' ),
            'unitSrc' => ns()->url( '/api/crud/ns.units/form-config' ),
            'unitSubmitUrl' => ns()->url( '/api/crud/ns.units' ),
            'returnUrl' => ns()->url( '/dashboard/products' ),
            'unitsUrl' => ns()->url( '/api/units-groups/{id}/units' ),
            'optionAttributes' => json_encode( UnitCrud::getFormConfig()[ 'optionAttributes' ] ),
            'src' => ns()->url( '/api/crud/ns.products/form-config' ),
        ] );
    }

    /**
     * Renders the crud table for the product
     * units
     *
     * @return View
     */
    public function productUnits( Product $product )
    {
        return ProductUnitQuantitiesCrud::table( [
            'queryParams' => [
                'product_id' => $product->id,
            ],
        ] );
    }

    /**
     * render the crud table for the product
     * history
     *
     * @return View
     */
    public function productHistory( $identifier )
    {
        $product = Product::find( $identifier );

        return ProductHistoryCrud::table( [
            'title' => sprintf( __( 'Stock History For %s' ), $product->name ),
            'queryParams' => [
                'product_id' => $identifier,
            ],
        ] );
    }

    public function showStockAdjustment( Request $request )
    {
        $adjustment = null;

        if ( $request->query( 'adjustment_id' ) ) {
            $adjustment = ProductAdjustment::with( 'items' )
                ->where( 'id', $request->query( 'adjustment_id' ) )
                ->where( 'status', ProductAdjustment::STATUS_DRAFT )
                ->first();
        }

        return View::make( 'pages.dashboard.products.stock-adjustment', [
            'title' => __( 'Stock Adjustment' ),
            'description' => __( 'Adjust stock of existing products.' ),
            'adjustment' => $adjustment,
            'actions' => Helper::kvToJsOptions( [
                ProductHistory::ACTION_ADDED => __( 'Add' ),
                ProductHistory::ACTION_DELETED => __( 'Delete' ),
                ProductHistory::ACTION_DEFECTIVE => __( 'Defective' ),
                ProductHistory::ACTION_LOST => __( 'Lost' ),
                ProductHistory::ACTION_SET => __( 'Set' ),
            ] ),
        ] );
    }

    public function showStockAdjustmentEdit( ProductAdjustment $history )
    {
        if ( $history->status !== ProductAdjustment::STATUS_DRAFT ) {
            return redirect()->route( ns()->routeName( 'ns.dashboard.products.adjustment-history' ) );
        }

        return View::make( 'pages.dashboard.products.stock-adjustment', [
            'title' => __( 'Edit Stock Adjustment' ),
            'description' => __( 'Update this draft stock adjustment.' ),
            'adjustment' => $history->load( 'items' ),
            'actions' => Helper::kvToJsOptions( [
                ProductHistory::ACTION_ADDED => __( 'Add' ),
                ProductHistory::ACTION_DELETED => __( 'Delete' ),
                ProductHistory::ACTION_DEFECTIVE => __( 'Defective' ),
                ProductHistory::ACTION_LOST => __( 'Lost' ),
                ProductHistory::ACTION_SET => __( 'Set' ),
            ] ),
        ] );
    }

    public function getUnitQuantity( Product $product, Unit $unit )
    {
        $quantity = $this->productService->getUnitQuantity( $product->id, $unit->id );

        if ( $quantity instanceof ProductUnitQuantity ) {
            return $quantity;
        }

        throw new Exception( __( 'No stock is provided for the requested product.' ) );
    }

    public function deleteUnitQuantity( ProductUnitQuantity $unitQuantity )
    {
        ns()->restrict( [ 'nexopos.delete.products-units', 'nexopos.make.products-adjustments' ] );

        /**
         * This endpoint removes one branch's stock row, addressed by nothing but its id, and the id
         * carries no hint of whose shelf it is. A member of staff who belongs to a branch has no
         * legitimate reason to remove another branch's row, and doing so would take goods off a shelf
         * they cannot see and write the adjustment against the wrong branch's history.
         *
         * Only enforced for users who have a branch of their own. The owner and the administrator
         * have none — they work across branches by design, and the procurement pages they use reach
         * rows in whichever branch the procurement belongs to.
         */
        $userBranchId = Auth::user()?->branch_id;

        if (
            $userBranchId !== null
            && $unitQuantity->branch_id !== null
            && (int) $unitQuantity->branch_id !== (int) $userBranchId
        ) {
            throw new NotAllowedException( __( 'รายการสต็อกนี้เป็นของสาขาอื่น จึงลบจากสาขาของคุณไม่ได้' ) );
        }

        if ( $unitQuantity->quantity > 0 ) {
            $this->productService->stockAdjustment( ProductHistory::ACTION_DELETED, [
                'unit_price' => $unitQuantity->sale_price,
                'unit_id' => $unitQuantity->unit_id,
                'product_id' => $unitQuantity->product_id,
                'quantity' => $unitQuantity->quantity,
            ] );
        }

        $unitQuantity->delete();

        return [
            'status' => 'success',
            'message' => __( 'The product unit quantity has been deleted.' ),
        ];
    }

    public function createAdjustment( Request $request )
    {
        ns()->restrict( [ 'nexopos.make.products-adjustments' ] );

        $validator = Validator::make( $request->all(), [
            'products' => 'required',
        ] );

        if ( $validator->fails() ) {
            throw new Exception( __( 'Unable to proceed as the request is not valid.' ) );
        }

        $results = [];

        /**
         * We need to make sure the action
         * made are actually supported.
         */
        foreach ( $request->input( 'products' ) as $unit ) {
            /**
             * if the action is set, then we need to make sure
             * the quantity is set
             */
            if ( ! isset( $unit[ 'adjust_unit' ][ 'unit_id' ] ) ) {
                throw new Exception( sprintf( __( 'The unit is not set for the product "%s".' ), $unit[ 'name' ] ) );
            }

            /**
             * let's check if the action is supported
             */
            if (
                ! in_array( $unit[ 'adjust_action' ], $this->productService->getIncreaseActions() ) &&
                ! in_array( $unit[ 'adjust_action' ], $this->productService->getReduceActions() ) &&
                ! in_array( $unit[ 'adjust_action' ], [
                    ProductHistory::ACTION_SET,
                ] )
            ) {
                throw new Exception( sprintf( __( 'Unsupported action for the product %s.' ), $unit[ 'name' ] ) );
            }

            /**
             * let's check for every operation if there is enough inventory
             */
            $productUnitQuantity = ProductUnitQuantity::where( 'product_id', $unit[ 'id' ] )
                ->where( 'unit_id', $unit[ 'adjust_unit' ][ 'unit_id' ] )
                ->first();

            if ( $productUnitQuantity instanceof ProductUnitQuantity && in_array( $unit[ 'adjust_action' ], $this->productService->getReduceActions() ) ) {
                $remaining = $productUnitQuantity->quantity - (float) $unit[ 'adjust_quantity' ];

                if ( $remaining < 0 ) {
                    throw new NotAllowedException(
                        sprintf(
                            __( 'The operation will cause a negative stock for the product "%s" (%s).' ),
                            $productUnitQuantity->product->name,
                            $remaining
                        )
                    );
                }
            }

            if ( $unit[ 'adjust_quantity' ] < 0 ) {
                throw new NotAllowedException(
                    sprintf(
                        __( 'The adjustment quantity can\'t be negative for the product "%s" (%s)' ),
                        $unit[ 'name' ],
                        $unit[ 'adjust_quantity' ]
                    )
                );
            }
        }

        /**
         * now we can adjust the stock of the items
         */
        foreach ( $request->input( 'products' ) as $product ) {
            $results[] = $this->productService->stockAdjustment( $product[ 'adjust_action' ], [
                'unit_price' => $product[ 'adjust_unit' ][ 'sale_price' ],
                'unit_id' => $product[ 'adjust_unit' ][ 'unit_id' ],
                'procurement_product_id' => $product[ 'procurement_product_id' ] ?? null,
                'product_id' => $product[ 'id' ],
                'quantity' => $product[ 'adjust_quantity' ],
                'description' => $product[ 'adjust_reason' ] ?? '',
            ] );
        }

        /**
         * Record the batch so it appears in the adjustment history.
         */
        $adjustment = ProductAdjustment::create( [
            'author_id' => auth()->id(),
            'title' => $request->input( 'title', '' ),
            'status' => ProductAdjustment::STATUS_PERFORMED,
            'description' => $request->input( 'description', '' ),
        ] );

        foreach ( $this->productService->buildAdjustmentItems( $request->input( 'products' ) ) as $item ) {
            ProductAdjustmentItem::create( array_merge( $item, [
                'adjustment_id' => $adjustment->id,
            ] ) );
        }

        return [
            'status' => 'success',
            'message' => __( 'The stock has been adjustment successfully.' ),
            'data' => $results,
        ];
    }

    public function searchUsingArgument( string $reference )
    {
        // Check if this is a scale barcode
        $scaleData = null;

        if ( $this->productService->isScaleBarcode( $reference ) ) {
            try {
                $scaleData = $this->productService->parseScaleBarcode( $reference );
                // The product_code from scale barcode is actually the PLU
                $plu = $scaleData['product_code'];

                // Find the product unit quantity by PLU
                $unitQuantity = ProductUnitQuantity::where( 'scale_plu', $plu )
                    ->with( 'product', 'unit' )
                    ->first();

                if ( $unitQuantity && $unitQuantity->product ) {
                    $product = Product::find( $unitQuantity->product_id );
                    $product->load( 'unit_quantities.unit', 'tax_group.taxes' );

                    return [
                        'type' => 'product',
                        'unit' => $unitQuantity->unit,
                        'unitQuantity' => $unitQuantity,
                        'product' => $product->toArray(),
                        'scale' => $scaleData,
                    ];
                }

                // If PLU not found, throw an error
                throw new NotFoundException(
                    sprintf(
                        __( 'No product found with PLU code: %s' ),
                        $plu
                    )
                );
            } catch ( Exception $e ) {
                // If parsing fails or product not found, throw the exception
                throw $e;
            }
        }

        $procurementProduct = ProcurementProduct::barcode( $reference )->first();

        /**
         * The unit row has to answer for a product that is actually on sale, the same restriction the
         * product lookup below applies. Without it a withheld product was still reachable through the
         * barcode on one of its units, and a product is withheld precisely when it carries no price,
         * so scanning that barcode added the goods to the cart for nothing.
         */
        /**
         * And it has to answer for the branch doing the scanning. The same barcode is carried by one
         * row per branch — 840 of them on this catalogue — and taking whichever row came first meant
         * a till showed another branch's price and put another branch's row id into the cart, which
         * the sale then checked and deducted against. Branchless rows are still accepted, because a
         * single store install has no branch on any of them.
         */
        /**
         * Resolved from the till, not the branch picker. The picker is a screen control; the register
         * is bolted to one branch. Only the sale followed that rule, so an administrator ringing up on
         * the Tha Chang till with the picker on the main branch had the order stamped Tha Chang while
         * every scan leading to it was resolved against the main branch.
         */
        $branchId = $this->productService->branchIdForCurrentTill();

        $productUnitQuantity = ProductUnitQuantity::barcode( $reference )
            ->whereHas( 'product', fn ( $query ) => $query->onSale() )
            ->when(
                $branchId !== null,
                fn ( $query ) => $query->where(
                    fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
                )
            )
            ->with( 'unit' )
            ->first();

        $product = Product::barcode( $reference )
            ->onSale()
            ->first();

        if ( $procurementProduct instanceof ProcurementProduct ) {
            $product = $procurementProduct->product;
            $product->load( 'tax_group.taxes' );

            /**
             * check if the product has expired
             * and the sales are disallowed.
             */
            if (
                $procurementProduct->expiration_date !== null &&
                $this->dateService->copy()->greaterThan( $procurementProduct->expiration_date ) &&
                $product->expires &&
                $product->on_expiration === Product::EXPIRES_PREVENT_SALES ) {
                throw new NotAllowedException( __( 'Unable to add the product to the cart as it has expired.' ) );
            }

            /**
             * We need to add  a reference of the procurement product
             * in order to deplete the available quantity accordingly.
             * Will also be helpful to track how products are sold.
             */
            $product->procurement_product_id = $procurementProduct->id;
            $product->selectedUnitQuantity = ProductUnitQuantity::where( 'product_id', $procurementProduct->product_id )
                ->where( 'unit_id', $procurementProduct->unit_id )
                ->first();

        } elseif ( $productUnitQuantity instanceof ProductUnitQuantity ) {
            /**
             * if a product unit quantity is loaded. Then we make sure to return the parent
             * product with the selected unit quantity.
             */
            $productUnitQuantity->load( 'unit' );

            $product = Product::find( $productUnitQuantity->product_id );
            $product->load( 'tax_group.taxes' );
            $product->setRelation( 'unit_quantities', $this->branchUnitQuantities( $product, $branchId ) );
            $product->selectedUnitQuantity = $this->productService->forStockDisplay( $productUnitQuantity );
        } elseif ( $product instanceof Product ) {
            $product->load( 'tax_group.taxes' );

            if ( $product->accurate_tracking && ! $scaleData ) {
                throw new NotAllowedException( __( 'Unable to add a product that has accurate tracking enabled, using an ordinary barcode.' ) );
            }

            /**
             * Only the product's own barcode matched, so no unit is known yet. The rows offered from
             * here are this branch's alone: handing over every branch's rows is what let a till show
             * another branch's price and put another branch's row id into the cart.
             */
            $rows = $this->branchUnitQuantities( $product, $branchId );

            if ( $rows->isEmpty() ) {
                /**
                 * The catalogue is company-wide but the shelf is not. Reported as plainly as that,
                 * because the alternative — offering the units of a branch that is not this one — is
                 * how a cashier ends up selling goods this till has never stocked.
                 */
                throw new NotFoundException( sprintf(
                    __( 'สินค้า "%s" ไม่มีในคลังของสาขานี้ จึงขายจากจุดขายนี้ไม่ได้' ),
                    $product->name
                ) );
            }

            $product->setRelation( 'unit_quantities', $rows );

            /**
             * A product's own barcode is the barcode of one of its units — in this catalogue that
             * holds for every product with a barcode. Resolving it here is what stops the unit chooser
             * from opening on a scan that was never ambiguous: the cashier scanned the bottle, so the
             * bottle goes in the cart. A single remaining unit is equally unambiguous.
             *
             * Anything else is genuinely ambiguous and still asks, which is the behaviour wanted when
             * a product is picked from the F1 search rather than scanned.
             */
            $scanned = $rows->firstWhere( 'barcode', $reference );

            if ( $scanned === null && $rows->count() === 1 ) {
                $scanned = $rows->first();
            }

            if ( $scanned instanceof ProductUnitQuantity ) {
                $product->selectedUnitQuantity = $scanned;
            }
        }

        if ( $product instanceof Product ) {
            return [
                'type' => 'product',
                'unit' => $product?->selectedUnitQuantity?->unit,
                'unitQuantity' => $product?->selectedUnitQuantity,
                'product' => $product,
            ];
        }

        throw new NotFoundException( __( 'There is no products matching the current request.' ) );
    }

    /**
     * The unit rows of a product that belong to the till doing the asking.
     *
     * Branchless rows are always included: a single store install carries no branch on any row, and
     * excluding them would report every product as unsellable.
     *
     * @return Collection<int, ProductUnitQuantity>
     */
    private function branchUnitQuantities( Product $product, ?int $branchId )
    {
        return $product->unit_quantities()
            ->with( 'unit' )
            ->when(
                $branchId !== null,
                fn ( $query ) => $query->where(
                    fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
                )
            )
            ->get()->map( fn ( ProductUnitQuantity $row ) => $this->productService->forStockDisplay( $row ) );
    }

    public function printLabels()
    {
        return View::make( 'pages.dashboard.products.print-labels', [
            'title' => __( 'Print Labels' ),
            'description' => __( 'Customize and print products labels.' ),
        ] );
    }

    /**
     * The barcode image a label prints, drawn the first time it is asked for.
     *
     * The label page read these files straight off the public disk, and they are only written when a
     * product is saved through the product form or received through a procurement. This catalogue was
     * loaded by CSV import, which writes none — 2,922 of 2,928 barcodes had no image, so every label
     * printed a broken picture. Drawing here removes the need to backfill thousands of files and keeps
     * the next import from reintroducing the gap.
     *
     * The result is written to the same path the existing files live at, so an image is drawn once and
     * served as a file from then on, and the images that already exist are reused untouched.
     */
    public function showBarcodeImage( string $barcode )
    {
        ns()->restrict( 'nexopos.read.products' );

        /**
         * The page asks for "<barcode>.png", and a barcode in this catalogue can itself contain dots,
         * so only a trailing extension is removed.
         */
        $barcode = (string) preg_replace( '/\.png$/i', '', $barcode );

        if ( $barcode === '' ) {
            throw new NotFoundException( __( 'ไม่ได้ระบุบาร์โค้ด' ) );
        }

        /**
         * Restricted to barcodes the catalogue actually uses, so this cannot be used to render
         * arbitrary text as an image. The type comes from the product that owns the barcode, because
         * the same digits drawn as EAN-13 and as CODE-128 are two different pictures.
         */
        $unitQuantity = ProductUnitQuantity::with( 'product' )
            ->where( 'barcode', $barcode )
            ->first();

        $type = $unitQuantity?->product?->barcode_type;

        if ( $type === null ) {
            $product = Product::where( 'barcode', $barcode )->first();

            if ( ! $product instanceof Product ) {
                throw new NotFoundException( __( 'ไม่พบบาร์โค้ดนี้ในทะเบียนสินค้า' ) );
            }

            $type = $product->barcode_type;
        }

        $barcodeService = app()->make( BarcodeService::class );
        $path = $barcodeService->imagePathFor( $barcode );
        $disk = Storage::disk( 'public' );

        /**
         * Drawing, the CODE-128 fallback and the "never redraw what exists" rule all live in the
         * service, so rgbf:barcodes-generate produces byte-identical images to the ones drawn here on
         * demand. A label printed from a warmed catalogue has to match one printed cold.
         */
        if ( ! $barcodeService->ensureImage( $barcode, $type ) ) {
            throw new NotFoundException( sprintf(
                __( 'สร้างบาร์โค้ด "%s" ไม่ได้ ค่านี้อาจมีอักขระที่พิมพ์เป็นบาร์โค้ดไม่ได้' ),
                $barcode
            ) );
        }

        return response()->file( $disk->path( $path ), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ] );
    }

    /**
     * The sticker sizes available, and the one this branch prints on.
     */
    public function getLabelPresets()
    {
        $presets = app()->make( LabelPresetService::class );

        return [
            'presets' => $presets->all(),
            'selected' => $presets->selectedFor( $this->currentBranchId() ),
            'defaults' => $presets->defaults(),
        ];
    }

    public function saveLabelPreset( Request $request )
    {
        $presets = app()->make( LabelPresetService::class );

        $saved = $presets->save(
            (string) $request->input( 'name' ),
            (array) $request->input( 'settings', [] ),
            $this->currentBranchId()
        );

        return [
            'status' => 'success',
            'message' => sprintf( __( 'บันทึกขนาดฉลาก "%s" แล้ว' ), $saved[ 'name' ] ),
            'data' => [
                'presets' => $presets->all(),
                'selected' => $saved,
            ],
        ];
    }

    public function selectLabelPreset( Request $request )
    {
        $presets = app()->make( LabelPresetService::class );

        $selected = $presets->select( (string) $request->input( 'name' ), $this->currentBranchId() );

        return [
            'status' => 'success',
            'message' => sprintf( __( 'ใช้ขนาดฉลาก "%s" สำหรับสาขานี้แล้ว' ), $selected[ 'name' ] ),
            'data' => [ 'selected' => $selected ],
        ];
    }

    public function deleteLabelPreset( string $name )
    {
        $presets = app()->make( LabelPresetService::class );

        $presets->delete( $name, $this->currentBranchId() );

        return [
            'status' => 'success',
            'message' => sprintf( __( 'ลบขนาดฉลาก "%s" แล้ว' ), $name ),
            'data' => [
                'presets' => $presets->all(),
                'selected' => $presets->selectedFor( $this->currentBranchId() ),
            ],
        ];
    }

    /**
     * The branch whose sticker size is being read or written, resolved the same way every other
     * branch-aware page resolves it, so the label page and the till agree on which branch the person
     * at the keyboard is standing in.
     */
    private function currentBranchId(): ?int
    {
        return $this->productService->branchIdForCurrentUser();
    }

    public function getProcuredProducts( Product $product )
    {
        return $product->procurementHistory->map( function ( $procurementProduct ) {
            $procurementProduct->procurement = $procurementProduct->procurement()->select( 'name' )->first();

            return $procurementProduct;
        } );
    }

    public function getScaleRange()
    {
        return ScaleRangeCrud::table();
    }

    public function createScaleRange()
    {
        return ScaleRangeCrud::form();
    }

    public function editScaleRange( ScaleRange $scaleRange )
    {
        return ScaleRangeCrud::form( $scaleRange );
    }

    /**
     * Reorder products
     *
     * @return array
     */
    public function reorderProducts( Request $request )
    {
        $items = $request->input( 'items', [] );

        foreach ( $items as $index => $item ) {
            $product = Product::find( $item['id'] );
            if ( $product instanceof Product ) {
                $product->position = $index;
                $product->save();
            }
        }

        return [
            'status' => 'success',
            'message' => __( 'Products have been successfully reordered.' ),
        ];
    }

    public function listAdjustmentHistory()
    {
        return ProductAdjustmentCrud::table();
    }

    public function saveDraftAdjustment( Request $request )
    {
        ns()->restrict( [ 'nexopos.make.products-adjustments' ] );

        $validator = Validator::make( $request->all(), [
            'products' => 'required|array',
        ] );

        if ( $validator->fails() ) {
            throw new Exception( __( 'Unable to proceed as the request is not valid.' ) );
        }

        $adjustment = $this->productService->createAdjustmentDraft(
            $request->input( 'products' ),
            $request->input( 'title', '' ),
            $request->input( 'description', '' )
        );

        return [
            'status' => 'success',
            'message' => __( 'The draft has been saved successfully.' ),
            'data' => [ 'adjustment' => $adjustment ],
        ];
    }

    public function getAdjustment( ProductAdjustment $adjustment )
    {
        ns()->restrict( [ 'nexopos.make.products-adjustments' ] );

        return $adjustment->load( 'items' );
    }

    public function updateDraftAdjustment( ProductAdjustment $adjustment, Request $request )
    {
        ns()->restrict( [ 'nexopos.make.products-adjustments' ] );

        if ( $adjustment->status !== ProductAdjustment::STATUS_DRAFT ) {
            throw new NotAllowedException( __( 'Only draft adjustments can be updated.' ) );
        }

        $validator = Validator::make( $request->all(), [
            'products' => 'required|array',
        ] );

        if ( $validator->fails() ) {
            throw new Exception( __( 'Unable to proceed as the request is not valid.' ) );
        }

        $adjustment = $this->productService->updateAdjustmentDraft(
            $adjustment,
            $request->input( 'products' ),
            $request->input( 'title', $adjustment->title ?? '' ),
            $request->input( 'description', $adjustment->description ?? '' )
        );

        return [
            'status' => 'success',
            'message' => __( 'The draft has been updated successfully.' ),
            'data' => [ 'adjustment' => $adjustment ],
        ];
    }

    public function executeAdjustment( ProductAdjustment $adjustment )
    {
        ns()->restrict( [ 'nexopos.make.products-adjustments' ] );

        $results = $this->productService->executeAdjustmentDraft( $adjustment );

        return [
            'status' => 'success',
            'message' => __( 'The stock adjustment has been performed successfully.' ),
            'data' => $results,
        ];
    }

    public function deleteDraftAdjustment( ProductAdjustment $adjustment )
    {
        ns()->restrict( [ 'nexopos.make.products-adjustments' ] );

        if ( $adjustment->status !== ProductAdjustment::STATUS_DRAFT ) {
            throw new NotAllowedException( __( 'Only draft adjustments can be deleted.' ) );
        }

        $adjustment->items()->delete();
        $adjustment->delete();

        return [
            'status' => 'success',
            'message' => __( 'The draft adjustment has been deleted.' ),
        ];
    }
}

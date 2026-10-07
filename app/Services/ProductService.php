<?php

namespace App\Services;

use App\Classes\Hook;
use App\Events\ProductAfterCreatedEvent;
use App\Events\ProductAfterStockAdjustmentEvent;
use App\Events\ProductAfterUpdatedEvent;
use App\Events\ProductBeforeCreatedEvent;
use App\Events\ProductBeforeUpdatedEvent;
use App\Events\ProductResetEvent;
use App\Events\ProductStockUpdatedEvent;
use App\Exceptions\NotAllowedException;
use App\Exceptions\NotFoundException;
use App\Models\OrderProduct;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\ProductAdjustment;
use App\Models\ProductAdjustmentItem;
use App\Models\ProductCategory;
use App\Models\ProductGallery;
use App\Models\ProductHistory;
use App\Models\ProductSubItem;
use App\Models\ProductUnitQuantity;
use App\Models\Register;
use App\Models\Unit;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(
        protected ProductCategoryService $categoryService,
        protected TaxService $taxService,
        protected CurrencyService $currency,
        protected UnitService $unitService,
        protected BarcodeService $barcodeService
    ) {
        // ...
    }

    /**
     * The branch currently selected, or null when the install is single store.
     *
     * SetDefaultBranch puts a branch in the session for every authenticated user
     * whether or not multi store is switched on, so the session alone cannot say
     * whether stock should be read per branch. On a single store install the stock
     * rows carry no branch at all, so filtering by one matched nothing and
     * getQuantity() reported every product as zero on hand. The sale passed the
     * check in OrdersService, which reads the unit quantity row directly, and was
     * then refused during the stock adjustment as negative stock.
     */
    protected function getCurrentBranchId(): ?int
    {
        if ( ns()->option->get( 'ns_store_enabled', 'no' ) !== 'yes' ) {
            return null;
        }

        return session( 'selected_branch_id' );
    }

    /**
     * The branch a stock movement belongs to, taken from the data that already knows it.
     *
     * The branch used to be inferred from the session behind the ns_store_enabled switch, which is
     * off on an install that nonetheless runs two branches, so every filter it guarded was inert
     * and a deduction was applied to whichever row came first. Asking the movement's own data
     * instead needs no switch and cannot disagree with the sale: the row the till sold from
     * carries its branch, and so does the order it belongs to.
     *
     * The session and the cashier are consulted last, for the operations that have no row to point
     * at — a procurement, a manual count — and both are performed by a signed in user standing in
     * one branch.
     *
     * @param array<string, mixed> $data
     */
    protected function resolveStockBranchId( array $data ): ?int
    {
        if ( ! empty( $data[ 'branch_id' ] ) ) {
            return (int) $data[ 'branch_id' ];
        }

        /**
         * The row the till sold from. This is the most precise answer available, because it is
         * the row the cashier's screen was showing when the barcode was scanned.
         */
        foreach ( [ $data[ 'unit_quantity_id' ] ?? null, $data[ 'orderProduct' ]->unit_quantity_id ?? null ] as $unitQuantityId ) {
            if ( empty( $unitQuantityId ) ) {
                continue;
            }

            $branchId = ProductUnitQuantity::where( 'id', $unitQuantityId )->value( 'branch_id' );

            if ( ! empty( $branchId ) ) {
                return (int) $branchId;
            }
        }

        $orderBranch = $data[ 'orderProduct' ]->order->branch_id ?? null;

        if ( ! empty( $orderBranch ) ) {
            return (int) $orderBranch;
        }

        return $this->branchIdForCurrentUser();
    }

    /**
     * The branch the person at the keyboard is standing in.
     *
     * SetDefaultBranch fills the session on every authenticated request, from the user's own branch
     * or from the main one, so the session is the answer for anything that has no row to point at:
     * a barcode scan, a catalogue search, a procurement, a manual count.
     */
    public function branchIdForCurrentUser(): ?int
    {
        $selected = session( 'selected_branch_id' );

        if ( ! empty( $selected ) ) {
            return (int) $selected;
        }

        $userBranch = Auth::user()?->branch_id;

        if ( ! empty( $userBranch ) ) {
            return (int) $userBranch;
        }

        return null;
    }

    /**
     * The branch a till-side lookup belongs to: a barcode scan, the catalogue search behind F1, the
     * list of units a product can be sold as.
     *
     * The till outranks the branch picker, which is the rule OrdersService::resolveOrderBranch already
     * applies to the sale itself. Until now only the sale followed it, so an administrator ringing up
     * on the Tha Chang till with the picker left on the main branch had the order stamped Tha Chang —
     * correctly — while every scan on the way to that order was resolved against the main branch. The
     * two disagreed about the same sale.
     *
     * What that produced at the counter: 1,118 products are stocked at Tha Chang and nowhere else, so
     * their unit rows were invisible to the scan. A code that also sits on the product itself fell
     * through to the unscoped product lookup and opened the unit chooser, which is why every scan
     * needed a mouse click. A code that sits only on a unit row — a pack or a carton — matched nothing
     * at all and reported the goods as missing.
     *
     * A register belongs to one branch and does not travel. The picker is a screen control anybody
     * signed in can touch.
     */
    public function branchIdForCurrentTill(): ?int
    {
        $userId = Auth::id();

        if ( $userId === null ) {
            return $this->branchIdForCurrentUser();
        }

        /**
         * The till the screen actually has open, named by the screen itself.
         *
         * Guessing from "whichever till this user holds open" is not enough: a user who opened a till
         * and never closed it holds two, and picking the newest would answer with a branch they are
         * not standing in. The register id is accepted only after checking the till is open and held by
         * this user, so it identifies rather than authorises.
         */
        $claimed = request()->input( 'register_id' );

        if ( ! empty( $claimed ) ) {
            $claimedBranch = Register::where( 'id', (int) $claimed )
                ->where( 'status', Register::STATUS_OPENED )
                ->where( 'used_by', $userId )
                ->value( 'branch_id' );

            if ( ! empty( $claimedBranch ) ) {
                return (int) $claimedBranch;
            }
        }

        /**
         * Nothing was claimed — an older screen, or a request that is not from the till. A single open
         * till held by this user is unambiguous and still answers. More than one and there is no
         * telling which they are standing at, so the question falls through to the picker rather than
         * being answered wrongly.
         */
        $held = Register::where( 'used_by', $userId )
            ->where( 'status', Register::STATUS_OPENED )
            ->whereNotNull( 'branch_id' )
            ->pluck( 'branch_id' )
            ->unique();

        if ( $held->count() === 1 ) {
            return (int) $held->first();
        }

        return $this->branchIdForCurrentUser();
    }

    /**
     * Whether this product holds separate stock per branch for this unit.
     *
     * A single store install leaves branch_id null on every row, and the resolver above will still
     * offer a branch from the session. Scoping to it would match nothing and report every product
     * as empty, which is the failure that made every sale on such an install impossible. So a
     * branch is only enforced once the rows are genuinely split by one.
     */
    protected function unitQuantitiesAreSplitByBranch( int $product_id, int $unit_id ): bool
    {
        return ProductUnitQuantity::where( 'product_id', $product_id )
            ->where( 'unit_id', $unit_id )
            ->whereNotNull( 'branch_id' )
            ->exists();
    }

    /**
     * The one row a stock movement has to be applied to.
     *
     * Returning a single row rather than a query is the point: the deduction was previously a mass
     * decrement over product and unit, so a product stocked in two branches had both rows reduced
     * by one sale. There is never a case where a movement belongs to more than one row.
     *
     * Null means this branch holds no stock of these goods, which a deduction must refuse rather
     * than satisfy from somewhere else.
     */
    protected function unitQuantityRowFor( int $product_id, int $unit_id, ?int $branch_id ): ?ProductUnitQuantity
    {
        $rows = ProductUnitQuantity::where( 'product_id', $product_id )
            ->where( 'unit_id', $unit_id )
            ->orderBy( 'id' );

        if ( $branch_id === null ) {
            return $rows->first();
        }

        $scoped = ( clone $rows )->where( 'branch_id', $branch_id )->first();

        if ( $scoped instanceof ProductUnitQuantity ) {
            return $scoped;
        }

        /**
         * Split by branch and nothing here: the branch is genuinely empty. Falling back to another
         * branch's row is what allowed one shop to sell another shop's shelf.
         */
        if ( $this->unitQuantitiesAreSplitByBranch( $product_id, $unit_id ) ) {
            return null;
        }

        return $rows->first();
    }

    /**
     * Get produt using
     * the provided id
     *
     * @param int $id Product id
     */
    public function get( int $id ): Product
    {
        $product = Product::find( $id );

        if ( ! $product instanceof Product ) {
            throw new Exception( __( 'Unable to find the product using the provided id.' ) );
        }

        return $product;
    }

    /**
     * Get Product using barcode
     */
    public function getProductUsingBarcode( string $barcode ): Product|false
    {
        /**
         * checks if a similar product already
         * exists and throw an error if it's the case
         */
        $product = Product::findUsingBarcode( $barcode )
            ->first();

        if ( $product instanceof Product ) {
            return $product;
        }

        return false;
    }

    /**
     * Get Product using barcode
     */
    public function getProductUsingBarcodeOrFail( string $barcode ): Product|false
    {
        /**
         * checks if a similar product already
         * exists and throw an error if it's the case
         */
        $product = Product::findUsingBarcode( $barcode )
            ->first();

        if ( $product instanceof Product ) {
            return $product;
        }

        return false;
    }

    /**
     * Get Product using sku
     */
    public function getProductUsingSKU( string $sku ): Product|false
    {
        /**
         * checks if a similar product already
         * exists and throw an error if it's the case
         */
        $product = Product::findUsingSKU( $sku )
            ->first();

        if ( $product instanceof Product ) {
            return $product;
        }

        return false;
    }

    /**
     * retrive a product using a SKU or fail
     * if the product is not found
     */
    public function getProductUsingSKUOrFail( string $sku ): Product
    {
        $product = $this->getProductUsingSKU( $sku );

        if ( ! $product instanceof Product ) {
            throw new Exception( __( 'Unable to find the requested product using the provided SKU.' ) );
        }

        return $product;
    }

    /**
     * Create a product either it's a "product"
     * or a "variable" product
     *
     * @param  array $data Data to handle
     * @return array response
     */
    public function create( array $data ): array
    {
        /**
         * check if the provided category
         * exists or throw an error
         */
        if ( ! $this->categoryService->get( $data[ 'category_id' ] ) ) {
            throw new Exception( __( 'The category to which the product is attached doesn\'t exists or has been deleted' ) );
        }

        /**
         * We'll check if the product is flagged as pinned. If it's the case we'll make sure
         * we don't exceed the maximum allowed pinned products.
         */
        $this->checkPinnedProducts( $data );

        /**
         * We need to check the product
         * before proceed and avoiding adding grouped
         * product within grouped product.
         */
        if ( $data[ 'type' ] === Product::TYPE_GROUPED ) {
            $this->checkGroupProduct( $data[ 'groups' ] );
        }

        /**
         * We want to check here if the unit quantities
         * attached to the product uses the same unit_id
         */
        if ( $data[ 'units' ] ) {
            $unitIds = collect( $data[ 'units' ][ 'selling_group' ] )->map( fn( $group ) => $group[ 'unit_id' ] );
            $unitIds->each( function ( $unitId ) use ( $unitIds ) {
                if ( $unitIds->filter( fn( $id ) => $id === $unitId )->count() > 1 ) {
                    throw new NotAllowedException( __( 'You cannot assign the same unit to more than one selling unit.' ) );
                }
            } );
        }

        /**
         * check if it's a simple product or not
         */
        if ( $data[ 'product_type' ] === 'product' ) {
            return $this->createSimpleProduct( $data );
        } elseif ( $data[ 'product_type' ] === 'variable' ) {
            return $this->createVariableProduct( $data );
        } else {
            throw new NotAllowedException( sprintf( __( 'Unable to create a product with an unknown type : %s' ), $data[ 'product_type' ] ) );
        }
    }

    /**
     * create a variable product
     *
     * @param  array $data Data to handle
     * @return array response
     */
    public function createVariableProduct( array $data ): array
    {
        /**
         * let's try to check if the product required
         * fields are valid. We should do that before saving anything to
         * the database
         */
        collect( $data[ 'variations' ] )->each( function ( $variation ) {
            if ( $this->getProductUsingBarcode( $variation[ 'barcode' ] ) ) {
                throw new Exception( sprintf( __( 'A variation within the product has a barcode which is already in use : %s.' ), $variation[ 'barcode' ] ) );
            }

            /**
             * search a product using the provided SKU
             * and throw an error if it's the case
             */
            if ( $this->getProductUsingSKU( $variation[ 'sku' ] ) ) {
                throw new Exception( sprintf( __( 'A variation within the product has a SKU which is already in use : %s' ), $variation[ 'sku' ] ) );
            }
        } );

        /**
         * save the simple product
         * as a variable product
         */
        $result = $this->createSimpleProduct( $data );
        $parent = $result[ 'data' ][ 'product' ];
        $parent->product_type = 'variable';
        $parent->save();

        /**
         * loop variations to
         * see if they aren't using already in use SKU, Barcode
         */
        foreach ( $data[ 'variations' ] as $variation ) {
            $this->createProductVariation( $parent, $variation );
        }

        return [
            'status' => 'success',
            'message' => __( 'The variable product has been created.' ),
            'data' => compact( 'parent' ),
        ];
    }

    /**
     * Create a simple product
     *
     * @param  array $data Data to handle
     * @return array response
     */
    public function createSimpleProduct( array $data ): array
    {
        if ( ! empty( $data[ 'barcode' ] ) && $this->getProductUsingBarcode( $data[ 'barcode' ] ) instanceof Product ) {
            throw new Exception( sprintf(
                __( 'The provided barcode "%s" is already in use.' ),
                $data[ 'barcode' ]
            ) );
        }

        if ( empty( $data[ 'barcode_type' ] ) ) {
            $data[ 'barcode_type' ] = ns()->option->get( 'ns_pos_default_barcode_type', 'code128' );
        }

        if ( empty( $data[ 'barcode' ] ) ) {
            $data[ 'barcode' ] = $this->barcodeService->generateRandomBarcode( $data[ 'barcode_type' ] );
        }

        /**
         * We'll generate an SKU automatically
         * if it's not provided by the form.
         */
        if ( empty( $data[ 'sku' ] ) ) {
            $category = ProductCategory::find( $data[ 'category_id' ] );
            $data[ 'sku' ] = Str::slug( $category->name ) . '--' . Str::slug( $data[ 'name' ] ) . '--' . Str::random( 5 );
        }

        /**
         * search a product using the provided SKU
         * and throw an error if it's the case
         */
        if ( $this->getProductUsingSKU( $data[ 'sku' ] ) && ! empty( $data[ 'barcode' ] ) ) {
            throw new Exception( sprintf(
                __( 'The provided SKU "%s" is already in use.' ),
                $data[ 'sku' ]
            ) );
        }

        $product = new Product;
        $mode = 'create';

        event( new ProductBeforeCreatedEvent( $product ) );

        foreach ( $data as $field => $value ) {
            if ( ! in_array( $field, [ 'variations' ] ) ) {
                $fields = $data;
                $this->__fillProductFields( $product, compact( 'field', 'value', 'mode', 'fields' ) );
            }
        }

        $product->author_id = $fields[ 'author_id' ] ?? Auth::id();
        $product->save();

        /**
         * this will calculate the unit quantities
         * for the created product. This also comute taxes
         */
        $this->__computeUnitQuantities( $fields, $product );

        /**
         * We'll reload the unit quantity
         * that is helpful to test if the tax is well computed
         */
        $product->load( 'unit_quantities' );

        /**
         * save product images
         */
        $this->saveGallery( $product, $fields[ 'images' ] ?? [] );

        /**
         * We'll now save all attached sub items
         */
        if ( $product->type === Product::TYPE_GROUPED ) {
            $this->saveSubItems( $product, $fields[ 'groups' ] ?? [] );
        }

        $editUrl = ns()->route( 'ns.dashboard.products.edit', [ 'product' => $product->id ] );

        event( new ProductAfterCreatedEvent( $product ) );

        return [
            'status' => 'success',
            'message' => __( 'The product has been saved.' ),
            'data' => compact( 'product', 'editUrl' ),
        ];
    }

    public function checkPinnedProducts( array $data, ?Product $product = null ): void
    {
        if ( $product instanceof Product ) {
            $countPinned = Product::where( 'pinned', 1 )
                ->where( 'id', '!=', $product->id )
                ->count();

        } else {
            $countPinned = Product::where( 'pinned', 1 )->count();
        }

        if ( isset( $data['pinned'] ) && $data['pinned'] == 1 ) {
            $maxPinned = ns()->option->get( 'ns_pos_max_pinned_products', 5 );

            if ( $countPinned >= $maxPinned ) {
                throw new NotAllowedException( sprintf( __( 'You cannot pin more than %s products.' ), $maxPinned ) );
            }
        }
    }

    /**
     * Update a product either is a "product" or a "variable"
     *
     * @param  Product $product Product instance
     * @param  array   $data    Fields
     * @return array   response
     */
    public function update( Product $product, array $data ): array
    {
        /**
         * check if the provided category
         * exists or throw an error
         */
        if ( ! $this->categoryService->get( $data[ 'category_id' ] ) ) {
            throw new Exception( __( 'The category to which the product is attached doesn\'t exists or has been deleted' ) );
        }

        /**
         * We'll check if the product is flagged as pinned. If it's the case we'll make sure
         * we don't exceed the maximum allowed pinned products.
         */
        $this->checkPinnedProducts( $data, $product );

        /**
         * We need to check the product
         * before proceed and avoiding adding grouped
         * product within grouped product.
         */
        if ( $data[ 'type' ] === Product::TYPE_GROUPED ) {
            $this->checkGroupProduct( $data[ 'groups' ] );
        }

        /**
         * We want to check here if the unit quantities
         * attached to the product uses the same unit_id
         */
        if ( $data[ 'units' ] ) {
            $unitIds = collect( $data[ 'units' ][ 'selling_group' ] )->map( fn( $group ) => $group[ 'unit_id' ] );
            $unitIds->each( function ( $unitId ) use ( $unitIds ) {
                if ( $unitIds->filter( fn( $id ) => $id === $unitId )->count() > 1 ) {
                    throw new NotAllowedException( __( 'You cannot assign the same unit to more than one selling unit.' ) );
                }
            } );
        }

        switch ( $data[ 'product_type' ] ) {
            case 'product':
                return $this->updateSimpleProduct( $product, $data );
                break;
            case 'variable':
                return $this->updateVariableProduct( $product, $data );
                break;
            default:
                throw new Exception( sprintf( __( 'Unable to edit a product with an unknown type : %s' ), $data[ 'product_type' ] ) );
                break;
        }
    }

    /**
     * Will release the product taxes
     * before a new modification is made to it
     */
    public function releaseProductTaxes( Product $product ): void
    {
        $product->product_taxes()->delete();
    }

    /**
     * Performs a verification to see if the subitems only
     * consist of valid items (not gruoped items).
     */
    public function checkGroupProduct( array $fields ): void
    {
        if ( ! isset( $fields[ 'product_subitems' ] ) ) {
            throw new NotAllowedException( __( 'A grouped product cannot be saved without any sub items.' ) );
        }

        foreach ( $fields[ 'product_subitems' ] as $item ) {
            $product = Product::find( $item[ 'product_id' ] );

            if ( $product->type === Product::TYPE_GROUPED ) {
                throw new NotAllowedException( __( 'A grouped product cannot contain grouped product.' ) );
            }
        }
    }

    /**
     * Update a simple product. This doesn't delete
     * the variable within a product, if this latest has
     * the type "product" before
     *
     * @param  Product|int $id     Product id or instance
     * @param  array       $fields Fields
     * @return array       response
     */
    public function updateSimpleProduct( Product|int $id, array $fields ): array
    {
        /**
         * will get a product if
         * the provided value is an integer
         * and not an instance of Product
         */
        $product = $this->getProductUsingArgument( 'id', $id );

        $mode = 'update';

        event( new ProductBeforeUpdatedEvent( $product ) );

        $this->releaseProductTaxes( $product );

        if ( empty( $fields[ 'barcode' ] ) ) {
            $fields[ 'barcode' ] = $this->barcodeService->generateRandomBarcode( $fields[ 'barcode_type' ] );
        }

        if ( $existingProduct = $this->getProductUsingBarcode( $fields[ 'barcode' ] ) ) {
            if ( $existingProduct->id !== $product->id ) {
                throw new Exception( __( 'The provided barcode is already in use.' ) );
            }
        }

        if ( empty( $fields[ 'sku' ] ) ) {
            $category = ProductCategory::find( $fields[ 'category_id' ] );
            $fields[ 'sku' ] = Str::slug( $category->name ) . '--' . Str::slug( $fields[ 'name' ] ) . '--' . strtolower( Str::random( 5 ) );
        }

        /**
         * search a product using the provided SKU
         * and throw an error if it's the case
         */
        if ( $existingProduct = $this->getProductUsingSKU( $fields[ 'sku' ] ) ) {
            if ( $existingProduct->id !== $product->id ) {
                throw new Exception( __( 'The provided SKU is already in use.' ) );
            }
        }

        foreach ( $fields as $field => $value ) {
            $this->__fillProductFields( $product, compact( 'field', 'value', 'mode', 'fields' ) );
        }

        $product->author_id = $fields[ 'author_id' ] ?? Auth::id();
        $product->save();

        /**
         * this will calculate the unit quantities
         * for the created product.
         */
        $this->__computeUnitQuantities( $fields, $product );

        /**
         * save product images
         */
        $this->saveGallery( $product, $fields[ 'images' ] ?? [] );

        /**
         * We'll now save all attached sub items. That is only applicable
         * if the product is set to be a grouped product.
         */
        if ( $product->type === Product::TYPE_GROUPED ) {
            $this->saveSubItems( $product, $fields[ 'groups' ] ?? [] );
        }

        $editUrl = ns()->route( 'ns.dashboard.products.edit', [ 'product' => $product->id ] );

        event( new ProductAfterUpdatedEvent( $product ) );

        return [
            'status' => 'success',
            'message' => __( 'The product has been updated' ),
            'data' => compact( 'product', 'editUrl' ),
        ];
    }

    /**
     * Saves the sub items by binding that to a product
     *
     * @return array response
     */
    public function saveSubItems( Product $product, array $subItems ): array
    {
        $savedItems = collect( [] );

        foreach ( $subItems[ 'product_subitems' ] as $item ) {
            if ( ! isset( $item[ 'id' ] ) ) {
                $subitem = new ProductSubItem;
                $subitem->parent_id = $product->id;
                $subitem->product_id = $item[ 'product_id' ];
                $subitem->unit_id = $item[ 'unit_id' ];
                $subitem->unit_quantity_id = $item[ 'unit_quantity_id' ];
                $subitem->sale_price = $item[ 'sale_price' ];
                $subitem->quantity = $item[ 'quantity' ];
                $subitem->total_price = $item[ 'total_price' ] ?? (float) $item[ 'sale_price' ] * (float) $item[ 'quantity' ];
                $subitem->author_id = $product->author_id;
                $subitem->save();
            } else {
                $subitem = ProductSubItem::find( $item[ 'id' ] );

                if ( ! $subitem instanceof ProductSubItem ) {
                    throw new NotFoundException( __( 'The requested sub item doesn\'t exists.' ) );
                }

                $subitem->parent_id = $product->id;
                $subitem->product_id = $item[ 'product_id' ];
                $subitem->unit_id = $item[ 'unit_id' ];
                $subitem->unit_quantity_id = $item[ 'unit_quantity_id' ];
                $subitem->sale_price = $item[ 'sale_price' ];
                $subitem->quantity = $item[ 'quantity' ];
                $subitem->total_price = $item[ 'total_price' ] ?? (float) $item[ 'sale_price' ] * (float) $item[ 'quantity' ];
                $subitem->author_id = $product->author_id;
                $subitem->save();
            }

            $savedItems->push( $subitem->id );
        }

        /**
         * We'll delete all products
         * that aren't submitted
         */
        ProductSubItem::where( 'parent_id', $product->id )
            ->whereNotIn( 'id', $savedItems->toArray() )
            ->delete();

        return [
            'status' => 'success',
            'message' => __( 'The subitem has been saved.' ),
        ];
    }

    public function saveGallery( Product $product, array|Collection $groups ): void
    {
        $product->galleries()
            ->get()
            ->each( function ( $image ) {
                $image->delete();
            } );

        /**
         * if there are many featured images
         * let's choose one for the user.
         *
         * @todo should be tested
         */
        $manyPrimary = collect( $groups )->map( function ( $fields ) {
            return isset( $fields[ 'featured' ] ) && (int) $fields[ 'featured' ] === 1;
        } )
            ->filter( fn( $result ) => $result === true )
            ->count() > 1;

        if ( $manyPrimary ) {
            $groups = collect( $groups )->map( function ( $fields, $index ) {
                return collect( $fields )->map( function ( $field, $fieldName ) use ( $index ) {
                    if ( $fieldName === 'featured' ) {
                        if ( $index === 0 ) {
                            $field = 1;
                        } else {
                            $field = 0;
                        }
                    }

                    return $field;
                } );
            } );
        }

        foreach ( $groups as $group ) {
            $image = new ProductGallery;
            $image->featured = $group[ 'featured' ] ?? 0;
            $image->url = $group[ 'url' ];
            $image->author_id = $product->author_id;
            $image->product_id = $product->id;
            $image->save();
        }
    }

    /**
     * Update a variable product
     *
     * @param  array $data Fields to save
     * @return array response of the process
     */
    public function updateVariableProduct( Product $product, array $data ): array
    {
        /**
         * let's try to check if the product variations
         * doesn't use any barcode already in use excluding
         * their id
         *
         * @var Collection $valid
         */
        $valid = collect( $data[ 'variations' ] )->filter( function ( $product ) {
            return ! empty( $product[ 'id' ] );
        } );

        /**
         * if the product variation doesn\'t include
         * any identifier
         */
        if ( $valid->empty() ) {
            throw new Exception(
                __( 'One of the provided product variation doesn\'t include an identifier.' )
            );
        }

        $valid->each( function ( $variation ) {
            if ( $foundProduct = $this->getProductUsingBarcode( $variation[ 'barcode' ] ) ) {
                if ( $foundProduct->id !== $variation[ 'id' ] ) {
                    throw new Exception( sprintf( __( 'A variation within the product has a barcode which is already in use : %s.' ), $variation[ 'barcode' ] ) );
                }
            }

            /**
             * search a product using the provided SKU
             * and throw an error if it's the case
             */
            if ( $foundProduct = $this->getProductUsingSKU( $variation[ 'sku' ] ) ) {
                if ( $foundProduct->id !== $variation[ 'id' ] ) {
                    throw new Exception( sprintf( __( 'A variation within the product has a SKU which is already in use : %s' ), $variation[ 'sku' ] ) );
                }
            }
        } );

        /**
         * let's update the product and recover the
         * parent product, which id will be reused.
         *
         * @var array{status: string, message: string, data: array{product: Product}} $result
         */
        $result = $this->updateSimpleProduct( $product, $data );
        $parent = $result[ 'data' ][ 'product' ];
        $parent->product_type = 'variable';

        /**
         * loop variations to see if they aren't
         * using already used SKU or Barcode
         */
        foreach ( $data[ 'variations' ] as $variation ) {
            $this->updateProductVariation( $parent, $variation[ 'id' ], $variation );
        }

        $parent->save();

        event( new ProductAfterUpdatedEvent( $parent ) );

        return [
            'status' => 'success',
            'message' => __( 'The variable product has been updated.' ),
            'data' => compact( 'parent' ),
        ];
    }

    /**
     * Compute the tax and update the
     * product according to the tax assigned
     * to that product
     */
    private function __fillProductFields( Product $product, array $data ): void
    {
        /**
         * @param string $field
         * @param mixed  $value
         * @param string $mode
         * @param array  $fields
         */
        extract( $data );

        if ( ! in_array( $field, [ 'units', 'images', 'groups' ] ) && ! is_array( $value ) ) {
            $product->$field = $value;
        } elseif ( $field === 'units' ) {
            $product->unit_group = $fields[ 'units' ][ 'unit_group' ];
            $product->accurate_tracking = $fields[ 'units' ][ 'accurate_tracking' ] ?? false;
            $product->auto_cogs = $fields[ 'units' ][ 'auto_cogs' ] ?? false;
        }
    }

    private function __computeUnitQuantities( array $fields, Product $product ): void
    {
        if ( $fields[ 'units' ] ) {
            foreach ( $fields[ 'units' ][ 'selling_group' ] as $group ) {
                $unitQuantity = $this->getUnitQuantity(
                    $product->id,
                    $group[ 'unit_id' ]
                );

                if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
                    $unitQuantity = new ProductUnitQuantity;
                    $unitQuantity->unit_id = $group[ 'unit_id' ];
                    $unitQuantity->product_id = $product->id;
                    $unitQuantity->quantity = 0;

                    /**
                     * The row has to say which branch it belongs to. Without it the stock, the price
                     * and the cost were saved against no branch at all, which every per-branch read
                     * then treats as "belongs to all of them". 21 rows on this shop were written that
                     * way, 13 of them with no cost, and they are the reason a figure typed in one
                     * branch turned up in the other.
                     */
                    $unitQuantity->branch_id = $this->branchIdForCurrentUser();
                }

                /**
                 * We don't need to save all the information
                 * available on the group variable, that's why we define
                 * explicitly how everything is saved here.
                 */
                $unitQuantity->sale_price = $this->currency->define( $group[ 'sale_price_edit' ] )->toFloat();
                $unitQuantity->sale_price_edit = $this->currency->define( $group[ 'sale_price_edit' ] )->toFloat();
                $unitQuantity->wholesale_price_edit = $this->currency->define( $group[ 'wholesale_price_edit' ] )->toFloat();
                $unitQuantity->preview_url = $group[ 'preview_url' ] ?? '';
                $unitQuantity->low_quantity = $group[ 'low_quantity' ] ?? 0;
                $unitQuantity->stock_alert_enabled = $group[ 'stock_alert_enabled' ] ?? false;
                $unitQuantity->convert_unit_id = $group[ 'convert_unit_id' ] ?? null;
                $unitQuantity->cogs = $group[ 'cogs' ] ?? 0;
                $unitQuantity->visible = $group[ 'visible' ] ?? true;

                /**
                 * Let's compute the tax only
                 * when the tax group is provided.
                 */
                $this->taxService->computeTax(
                    $unitQuantity,
                    $fields[ 'tax_group_id' ] ?? null,
                    $fields[ 'tax_type' ] ?? null
                );

                /**
                 * if some other fields should be retrieved from the $group variable
                 * we can defined that on the array below
                 */
                foreach ( Hook::filter( 'ns-products-units-quantities-fields-names', [] ) as $field ) {
                    $unitQuantity->$field = $group[ $field ] ?? null;
                }

                /**
                 * Handle scale PLU and is_weighable fields
                 */
                $unitQuantity->is_weighable = $group[ 'is_weighable' ] ?? false;
                $unitQuantity->scale_plu = $group[ 'scale_plu' ] ?? null;

                /**
                 * save custom barcode for the created unit quantity
                 */
                $unitQuantity->barcode = $group[ 'barcode' ] ?? $product->barcode . '-' . $unitQuantity->id;
                $unitQuantity->save();

                /**
                 * Auto-generate PLU for weighable products without a PLU
                 */
                if ( $unitQuantity->is_weighable && empty( $unitQuantity->scale_plu ) ) {
                    try {
                        $plu = $this->generateScalePLU( $product->id, $unitQuantity->id );
                        $unitQuantity->scale_plu = $plu;
                        $unitQuantity->save();
                    } catch ( Exception $e ) {
                        // PLU generation failed - product category may not have a range assigned
                        // This is not a critical error, so we'll just skip it
                        ns()->notification->create(
                            title: __( 'PLU Range: Failure' ),
                            identifier: 'product-scale-plu-error-' . $unitQuantity->id,
                            url: route( 'ns.dashboard.products-categories', [
                                'id' => $product->category_id,
                            ] ),
                            description: sprintf( __( 'Unable to generate a scale PLU for %s: %s' ), $product->name, $e->getMessage() ),
                        )->dispatchForPermissions( [
                            'nexopos.create.products',
                            'nexopos.update.products',
                        ] );
                    }
                }
            }
        }
    }

    /**
     * We'll get the Cost Of Good Sold from
     * the whole product history.
     */
    public function computeCogsIfNecessary( ProductHistory $productHistory ): void
    {
        $productHistory->load( 'product', 'unit' );

        /**
         * if the value is explicitly defined
         * then we'll skip the automatic detection
         */
        if ( $productHistory->product instanceof Product && $productHistory->product->auto_cogs ) {
            /**
             * The movement carries the branch it happened in, which is the only thing that can say
             * whose cost is being recomputed. Leaving it out is what let a delivery received at one
             * branch rewrite the other branch's cost.
             */
            $this->computeCogs( $productHistory->product, $productHistory->unit, branchId: $productHistory->branch_id );
        }
    }

    /**
     * Compute cogs for the provided product and unit, for one branch.
     *
     * The branch is not a refinement here, it is the whole answer. Stock, prices and cost are held
     * per branch in nexopos_products_unit_quantities, and a delivery is received into one branch at
     * a price that branch paid. This used to average the stock history of every branch together and
     * write the result onto whichever unit row came first out of the database, so on this shop every
     * delivery taken in at Tha Chang recomputed the main warehouse's cost and left Tha Chang's own
     * row at zero — 167 of its rows were still carrying no cost at all, which is why its sales
     * reported a loss.
     *
     * A null branch means nothing tagged the movement, which is a single store install: the history
     * is then averaged whole and written to the only row there is, exactly as before.
     */
    public function computeCogs( ?Product $product = null, ?Unit $unit = null, ?ProductUnitQuantity $productUnitQuantity = null, ?int $branchId = null ): ?ProductUnitQuantity
    {
        $unit_id = $productUnitQuantity->unit_id ?? $unit->id;
        $product_id = $productUnitQuantity->product_id ?? $product->id;
        $branchId = $productUnitQuantity->branch_id ?? $branchId;

        $productHistories = ProductHistory::where( 'unit_id', $unit_id )->where( 'product_id', $product_id )
            ->when(
                $branchId !== null,
                fn ( $query ) => $query->where( 'branch_id', $branchId )
            )
            ->whereIn( 'operation_type', [
                ProductHistory::ACTION_CONVERT_IN,
                ProductHistory::ACTION_STOCKED,
                // we might need to consider futher conversion option.
            ] )
            ->get();

        $totalQuantities = $productHistories->map( fn( $productHistory ) => $productHistory->quantity )->sum();
        $sums = $productHistories->map( fn( $productHistory ) => $productHistory->total_price )->sum();

        if ( $sums > 0 && $totalQuantities > 0 ) {
            $cogs = ns()->currency->define( $sums )->divideBy( $totalQuantities )->toFloat();

            $productUnitQuantity = $productUnitQuantity ?: $this->resolveUnitQuantityForBranch( $product_id, $unit_id, $branchId );

            if ( $productUnitQuantity instanceof ProductUnitQuantity ) {
                $productUnitQuantity->cogs = $cogs;
                $productUnitQuantity->save();

                $this->spreadCogsAcrossUnits( $productUnitQuantity );

                return $productUnitQuantity;
            }
        }

        return null;
    }

    /**
     * Derives the cost of the product's other selling units from the one that was just costed.
     *
     * A cost belongs to a quantity of goods, so it only means anything alongside the unit it was
     * measured in. Buying a pack of 24 for 213.08 means a bottle costs 8.88, and the two figures are the
     * same fact stated twice. Until now nothing said so: computeCogs() filters stock history by unit_id,
     * so it costed whichever unit the goods were received in and left every sibling unit untouched. The
     * importers then compounded it by writing the workbook's cost column onto every unit row verbatim.
     *
     * The visible result was a bottle selling for 10 baht recorded as costing 213.08, and a sales report
     * showing a loss of 203 baht on a 10 baht sale. The stock was right, the price was right, and the
     * profit column was nonsense.
     *
     * A unit that carries priced stock history of its own is left alone: that is a measurement, and a
     * measurement outranks something derived by arithmetic from another unit.
     *
     * @param array<int, int> $skipUnitIds Units whose cost was just set by hand in the same
     *                                     operation, and must not be overwritten by arithmetic
     *                                     derived from a sibling. Somebody correcting the cost of
     *                                     both the carton and the bottle of one product in a single
     *                                     save states two facts; spreading from the carton would
     *                                     silently replace the bottle they had just typed, and which
     *                                     of the two survived would depend on the order the rows came
     *                                     back from the database.
     * @return int                         How many sibling rows were written, so a caller acting on
     *                                     a batch can report what it changed beyond what was typed.
     */
    public function spreadCogsAcrossUnits( ProductUnitQuantity $source, array $skipUnitIds = [] ): int
    {
        $sourceUnit = Unit::find( $source->unit_id );

        if ( ! $sourceUnit instanceof Unit || (float) $sourceUnit->value <= 0 ) {
            return 0;
        }

        /**
         * Confined to the branch the source row belongs to.
         *
         * Stock and prices are held per branch on this table — a product sold at two branches has a row
         * per unit per branch — so spreading across every row would let one branch's delivery cost rewrite
         * another's. Without this filter the first version of this method fixed whichever branch's row it
         * happened to reach and left the other carrying the pack cost against a single item: branch 1 of
         * product 20883 read 49.08 per box while branch 2 still read 589.
         */
        $siblings = ProductUnitQuantity::where( 'product_id', $source->product_id )
            ->where( 'id', '!=', $source->id )
            ->where( function ( $query ) use ( $source ) {
                $source->branch_id === null
                    ? $query->whereNull( 'branch_id' )
                    : $query->where( 'branch_id', $source->branch_id );
            } )
            ->get();

        $written = 0;

        foreach ( $siblings as $sibling ) {
            /**
             * Stated by hand a moment ago. An explicit figure outranks one derived from it.
             */
            if ( in_array( (int) $sibling->unit_id, array_map( 'intval', $skipUnitIds ), true ) ) {
                continue;
            }

            $unit = Unit::find( $sibling->unit_id );

            /**
             * Units from another group describe a different way of counting the same product and have no
             * ratio to this one. Multiplying across them would invent a number.
             */
            if ( ! $unit instanceof Unit || (float) $unit->value <= 0 || $unit->group_id !== $sourceUnit->group_id ) {
                continue;
            }

            $hasOwnCost = ProductHistory::where( 'product_id', $source->product_id )
                ->where( 'unit_id', $unit->id )
                ->when(
                    $source->branch_id !== null,
                    fn ( $query ) => $query->where( 'branch_id', $source->branch_id )
                )
                ->whereIn( 'operation_type', [
                    ProductHistory::ACTION_CONVERT_IN,
                    ProductHistory::ACTION_STOCKED,
                ] )
                ->where( 'total_price', '>', 0 )
                ->exists();

            if ( $hasOwnCost ) {
                continue;
            }

            /**
             * getPurchasePriceFromUnit() multiplies before it divides, which keeps the rounding of a
             * cost-per-pack converted down to a cost-per-bottle stable. Written the other way round the
             * ratio is rounded first and the error is multiplied by it.
             */
            $sibling->cogs = $this->unitService->getPurchasePriceFromUnit( $source->cogs, $sourceUnit, $unit );
            $sibling->save();

            $written++;
        }

        return $written;
    }

    /**
     * refresh the price for a specific product
     *
     * @param  ProductUnitQuantity $product Instance of the product
     * @return array               response of the operation
     *
     * @deprecated
     */
    public function refreshPrices( ProductUnitQuantity $product )
    {
        return $this->taxService->computeTax( $product, $product->tax_group_id ?? null );
    }

    /**
     * get product quantity according
     * to a specific unit id
     */
    public function getQuantity( int $product_id, int $unit_id, ?int $branch_id = null )
    {
        /**
         * An explicit branch is the caller telling us which shelf is being counted, and it has to
         * win over anything the session happens to hold. Zero when that branch carries none of
         * these goods, so the negative stock guard refuses the sale instead of clearing it against
         * a different branch's figure.
         */
        if ( $branch_id !== null ) {
            $row = $this->unitQuantityRowFor( $product_id, $unit_id, $branch_id );

            return $row instanceof ProductUnitQuantity
                ? $this->currency->define( $row->quantity )->toFloat()
                : 0;
        }

        $query = Product::with( [
            'unit_quantities' => function ( $query ) use ( $unit_id ) {
                $query->where( 'unit_id', $unit_id );

                // Filter by current branch
                $branchId = $this->getCurrentBranchId();
                if ( $branchId ) {
                    $query->where( 'branch_id', $branchId );
                }
            },
        ] );

        $product = $query->find( $product_id );

        if ( $product->unit_quantities->count() > 0 ) {
            return $this->currency->define( $product->unit_quantities->first()->quantity )->toFloat();
        }

        return 0;
    }

    /**
     * save product history
     *
     * @param  string $operationType Operation type
     * @param  array  $data          History to save
     * @return array
     */
    public function saveHistory( $operationType, array $data )
    {
        switch ( $operationType ) {
            case ProductHistory::ACTION_STOCKED:
                $this->__saveProcurementHistory( $data );
                break;
        }
    }

    /**
     * Record a procurement history for
     * a specific set of product informations
     *
     * @param array $data Product information to handle
     */
    private function __saveProcurementHistory( array $data ): void
    {
        /**
         * @var int   $unit_id
         * @var int   $product_id
         * @var float $unit_price
         * @var float $total_price
         * @var int   $procurement_product_id
         * @var int   $procurement_id
         * @var float $quantity
         */
        extract( $data );

        $currentQuantity = $this->getQuantity( $product_id, $unit_id );
        $newQuantity = $this->currency
            ->define( $currentQuantity )
            ->additionateBy( $quantity )
            ->get();

        $history = new ProductHistory;
        $history->product_id = $product_id;
        $history->procurement_id = $procurement_id;
        $history->procurement_product_id = $procurement_product_id;
        $history->unit_id = $unit_id;
        $history->operation_type = ProductHistory::ACTION_STOCKED;
        $history->unit_price = $unit_price;
        $history->total_price = $total_price;
        $history->before_quantity = $currentQuantity;
        $history->quantity = $quantity;
        $history->after_quantity = $newQuantity;
        $history->author_id = Auth::id() ?: Procurement::find( $procurement_id )->author_id;
        $history->save();
    }

    /**
     * Overwrites the quantity of a product for a given unit with an absolute value.
     *
     * Use this only when the caller genuinely means "the stock is now exactly this"
     * — a physical count, a reset, or test setup. It is not safe for deducting or
     * adding stock: writing an absolute value discards any change another request
     * made in the meantime. For deltas use increaseUnitQuantities() or
     * reduceUnitQuantities(), which perform the arithmetic in a single statement.
     *
     * @param  int   $product_id Product id
     * @param  int   $unit_id    Unit id
     * @param  float $quantity   Quantity
     * @return array response
     */
    public function setQuantity( int $product_id, int $unit_id, float|int $quantity ): array
    {
        $query = ProductUnitQuantity::where( 'product_id', $product_id )
            ->where( 'unit_id', $unit_id );

        $unitQuantity = $query->first();

        if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
            $unitQuantity = new ProductUnitQuantity;
        }

        $unitQuantity->product_id = $product_id;
        $unitQuantity->unit_id = $unit_id;
        $unitQuantity->quantity = $quantity;
        $unitQuantity->save();

        /**
         * A physical count or a reset changes what the tills may sell just as much as
         * a sale does, so it is announced on the same channel.
         */
        $this->announceStockChange( $unitQuantity, (float) $unitQuantity->quantity );

        return [
            'status' => 'success',
            'message' => __( 'The product\'s unit quantity has been updated.' ),
            'data' => compact( 'unitQuantity' ),
        ];
    }

    /**
     * Reset a product quantity
     * this will delete all quantity
     *
     * @param  int|Product $product_id Product id or instance
     * @return array       response
     */
    public function resetProduct( Product|int $product_id ): array
    {
        /**
         * to avoid multiple call to the DB
         */
        if ( $product_id instanceof Product ) {
            $product = $product_id;
            $product_id = $product->id;
        } else {
            $product = $this->get( $product_id );
        }

        /**
         * let's check if the product is a variable
         * product
         */
        if ( $product->product_type === 'variable' ) {
            $result = $product->variations->map( function ( Product $product ) {
                return $this->__resetProductRelatives( $product );
            } )->toArray();

            if ( count( $result ) === 0 ) {
                return [
                    'status' => 'info',
                    'message' => sprintf( __( 'Unable to reset this variable product "%s", since it doens\'t seems to have any variations' ), $product->name ),
                ];
            }

            return [
                'status' => 'success',
                'message' => __( 'The product variations has been reset' ),
                'data' => compact( 'result' ),
            ];
        } else {
            return $this->__resetProductRelatives( $product );
        }
    }

    private function __resetProductRelatives( Product $product ): array
    {
        abort_if(ProductUnitQuantity::where('product_id',$product->id)->where('reserved_quantity','>',0)->exists(),422,'สินค้านี้มีการจอง กรุณาปลดจองก่อนรีเซ็ต');
        ProductHistory::where( 'product_id', $product->id )->delete();
        ProductUnitQuantity::where( 'product_id', $product->id )->delete();

        /**
         * dispatch an event to let everyone knows
         * a product has been reset
         */
        event( new ProductResetEvent( $product ) );

        return [
            'status' => 'success',
            'message' => __( 'The product has been reset.' ),
            'data' => compact( 'product' ),
        ];
    }

    /**
     * delete a product using the
     * provided identifier
     *
     * @return array operation status
     */
    public function deleteUsingID( int $product_id ): array
    {
        $product = $this->get( $product_id );

        return $this->deleteProduct( $product );
    }

    /**
     * delete an instance of a product
     *
     * @param  Product $product Instance to delete
     * @return array   operation status
     */
    public function deleteProduct( Product $product ): array
    {
        abort_if(ProductUnitQuantity::where('product_id',$product->id)->where('reserved_quantity','>',0)->exists(),422,'สินค้านี้มีการจอง กรุณาปลดจองก่อนลบ');
        $name = $product->name;

        $product->delete();

        return [
            'status' => 'success',
            'message' => sprintf( __( 'The product "%s" has been successfully deleted' ), $name ),
        ];
    }

    /**
     * get product variation
     *
     * @param int|Product
     * @return Collection<Product> variation
     */
    public function getProductVariations( $product = null )
    {
        if ( $product !== null ) {
            if ( is_numeric( $product ) ) {
                $product = $this->get( $product );
            }

            return $product->variations;
        } else {
            return Product::onlyVariations()->get();
        }
    }

    /**
     * get variations
     *
     * @param  int                 $product_id Product id
     * @return Collection<Product>
     */
    public function getVariations( int $product_id ): Collection
    {
        return Product::where( 'id', $product_id )->onlyVariations()->get();
    }

    /**
     * get speciifc variation
     *
     * @param int $id Variation id
     */
    public function getVariation( int $id ): Product
    {
        $variation = Product::where( 'product_type', 'variation' )
            ->where( 'id', $id )
            ->first();

        if ( ! $variation instanceof Product ) {
            throw new Exception( __( 'Unable to find the requested variation using the provided ID.' ) );
        }

        return $variation;
    }

    /**
     * get unit quantity for a specific product
     *
     * @return Collection<ProductUnitQuantity>
     */
    public function getUnitQuantities( int $product_id ): Collection
    {
        $query = ProductUnitQuantity::withProduct( $product_id );

        // Filter by current branch
        $branchId = $this->getCurrentBranchId();
        if ( $branchId ) {
            $query->withBranch( $branchId );
        }

        return $query->get()
            ->map( function ( $productQuantity ) {
                $productQuantity->unit;

                return $productQuantity;
            } );
    }

    /**
     * The unit row a product is priced and costed by, for the branch the person is standing in.
     *
     * The branch used to be read through getCurrentBranchId(), which answers null unless
     * ns_store_enabled is switched on. That option is not set on this install even though it runs
     * two branches, so the filter was inert and this returned whichever row came first out of the
     * database. Everything built on it then addressed the wrong shelf: saving a product from the
     * Tha Chang branch wrote the sale price, the wholesale price and the cost onto the main
     * warehouse's row, and the procurement screen quoted that row's price back.
     *
     * branchIdForCurrentUser() is the resolver the till, the product list and the stock reports
     * already share, and it needs no option to be switched on.
     *
     * A row carrying no branch belongs to every branch, so it answers for a single store install
     * and for the rows that predate the split. Null means this branch holds no row for that unit
     * yet, which is what lets a save create one for it rather than overwrite another branch's.
     */
    public function getUnitQuantity( int $product_id, int $unit_id ): ?ProductUnitQuantity
    {
        return $this->resolveUnitQuantityForBranch( $product_id, $unit_id, $this->branchIdForCurrentUser() );
    }

    /**
     * The row holding a product's stock, prices and cost for one named branch.
     *
     * A row carrying no branch belongs to every branch, so it answers for a single store install and
     * for the rows that predate the split. Null means the branch holds no row for that unit yet,
     * which is what lets a save create one for it rather than overwrite another branch's.
     */
    private function resolveUnitQuantityForBranch( int $product_id, int $unit_id, ?int $branchId ): ?ProductUnitQuantity
    {
        $rows = ProductUnitQuantity::withProduct( $product_id )
            ->withUnit( $unit_id )
            ->get();

        if ( $rows->isEmpty() ) {
            return null;
        }

        if ( $branchId === null ) {
            return $rows->first();
        }

        return $rows->first( fn ( ProductUnitQuantity $row ): bool => $row->branch_id !== null && (int) $row->branch_id === $branchId )
            ?: $rows->first( fn ( ProductUnitQuantity $row ): bool => $row->branch_id === null );
    }

    /**
     * get specific product quantity using the provided id
     *
     * @param  int                        $product_id Product id
     * @return Collection<ProductHistory>
     */
    public function getProductHistory( int $product_id ): Collection
    {
        return ProductHistory::withProduct( $product_id )->orderBy( 'id' )->get()->map( function ( $product ) {
            $product->unit;

            return $product;
        } );
    }

    /**
     * @param ProcurementProduct $oldProduct Updating procurement product
     * @param array              $fields     Fields [ quantity, unit_id, purchase_price ]
     */
    public function procurementStockOuting( ProcurementProduct $oldProduct, array $fields ): array
    {
        $history = $this->stockAdjustment( ProductHistory::ACTION_REMOVED, [
            'unit_id' => $oldProduct->unit_id,
            'product_id' => $oldProduct->product_id,
            'unit_price' => $oldProduct->purchase_price,
            'total_price' => $oldProduct->total_price,
            'procurement_id' => $oldProduct->procurement_id,
            'procurementProduct' => $oldProduct,
            'procurement_product_id' => $oldProduct->id,
            'quantity' => $fields[ 'quantity' ],
        ] );

        return [
            'status' => 'success',
            'message' => __( 'The product stock has been updated.' ),
            'compac' => compact( 'history' ),
        ];
    }

    /**
     * Make a unit adjustment for a specific product.
     *
     * @param string                                                                                                                                                                                                                                                                                                                                       $action Operation: deducted, sold, procured, deleted, adjusted, damaged
     * @param array{unit_id?: int, unit?: Unit, product_id?: int, sku?: string, unit_price: float|int, total_price?: float|int, procurement_id?: int|null, procurement_product_id?: int|null, sale_id?: int|null, quantity: float|int, orderProduct?: OrderProduct|null, procurementProduct?: ProcurementProduct|null, description?: string, author?: int} $data   Data to manage
     */
    public function stockAdjustment( string $action, array $data ): ProductHistory|EloquentCollection|bool
    {
        extract( $data, EXTR_REFS );
        /**
         * @var int|null                $product_id
         * @var float|int               $unit_price
         * @var int|null                $unit_id
         * @var Unit|null               $unit
         * @var float|int|null          $total_price
         * @var int|null                $procurement_product_id
         * @var OrderProduct|null       $orderProduct
         * @var ProcurementProduct|null $procurementProduct
         * @var string|null             $description
         * @var float|int               $quantity
         * @var string|null             $sku
         * @var string|null             $unit_identifier
         * @var int|null                $author
         */
        $product = isset( $product_id ) ? Product::find( $product_id ) : Product::usingSKU( $sku )->first();

        if ( ! $product instanceof Product ) {
            throw new NotFoundException( __( 'The product doesn\'t exists.' ) );
        }

        $product_id = $product->id;
        $unit_id = isset( $unit_id ) ? $unit_id : $unit->id;
        $unit = Unit::find( $unit_id );

        if ( ! $unit instanceof Unit ) {
            throw new NotFoundException( __( 'The unit doesn\'t exists.' ) );
        }

        /**
         * let's check the different
         * actions which are allowed on the current request
         */
        if ( ! in_array( $action, [
            ...$this->getIncreaseActions(),
            ...$this->getReduceActions(),
            ProductHistory::ACTION_SET,
        ] ) ) {
            throw new NotAllowedException( sprintf( __( 'The "%s" action is not an allowed operation.' ), $action ) );
        }

        /**
         * if the total_price is not provided
         * then we'll compute it
         */
        $total_price = empty( $data[ 'total_price' ] ) ? $this->currency
            ->define( $data[ 'unit_price' ] )
            ->multipliedBy( $data[ 'quantity' ] )
            ->get() : $data[ 'total_price' ];

        /**
         * the change on the stock is only performed
         * if the Product has the stock management enabled.
         */
        /**
         * Resolved once, here, from whatever the movement already knows about itself, and then
         * carried through every read and write below. Working it out further down would let the
         * availability check and the deduction disagree about which shelf they are talking about,
         * which is how one branch came to be cleared to sell against another branch's stock.
         */
        $branch_id = $this->resolveStockBranchId( $data );

        if ( $product->stock_management === Product::STOCK_MANAGEMENT_ENABLED ) {
            if ( $product->type === Product::TYPE_GROUPED ) {
                return $this->handleStockAdjustmentsForGroupedProducts(
                    action: $action,
                    orderProductQuantity: $quantity,
                    product: $product,
                    orderProduct: isset( $orderProduct ) ? $orderProduct : null,
                    parentUnit: $unit,
                    author: $author ?? 0,
                    branch_id: $branch_id,
                );
            } else {
                return $this->handleStockAdjustmentRegularProducts(
                    action: $action,
                    quantity: $quantity,
                    product_id: $product_id,
                    unit_id: $unit_id,
                    total_price: $total_price,
                    unit_price: $unit_price,
                    orderProduct: isset( $orderProduct ) ? $orderProduct : null,
                    procurementProduct: isset( $procurementProduct ) ? $procurementProduct : null,
                    author: $author ?? 0,
                    branch_id: $branch_id,
                );
            }
        }

        return false;
    }

    /**
     * Handle stock transaction for grouped products.
     */
    private function handleStockAdjustmentsForGroupedProducts(
        string $action,
        float|int $orderProductQuantity,
        Product $product,
        Unit $parentUnit,
        int $author = 0,
        ?OrderProduct $orderProduct = null,
        ?int $branch_id = null ): EloquentCollection
    {
        $product->load( 'sub_items' );

        if ( ! $orderProduct instanceof OrderProduct ) {
            throw new Exception( __( 'Adjusting grouped product inventory must result of a create, update, delete sale operation.' ) );
        }

        $products = $product->sub_items->map( function ( ProductSubItem $subItem ) use ( $author, $action, $orderProductQuantity, $parentUnit, $orderProduct, $branch_id ) {
            $finalQuantity = $this->computeSubItemQuantity(
                subItemQuantity: $subItem->quantity,
                parentUnit: $parentUnit,
                parentQuantity: $orderProductQuantity
            );

            /**
             * Let's retrieve the old item quantity.
             */
            $oldQuantity = $this->getQuantity( $subItem->product_id, $subItem->unit_id, $branch_id );

            if ( in_array( $action, $this->getReduceActions() ) ) {
                $this->preventNegativity(
                    oldQuantity: $oldQuantity,
                    quantity: $finalQuantity
                );

                /**
                 * @var array{status: string, message: string, data: array{oldQuantity: float|int, newQuantity: float|int, quantity: float|int}} $result
                 */
                $result = $this->reduceUnitQuantities(
                    product_id: $subItem->product_id,
                    unit_id: $subItem->unit_id,
                    quantity: $finalQuantity,
                    oldQuantity: $oldQuantity,
                    branch_id: $branch_id
                );
            } elseif ( in_array( $action, $this->getIncreaseActions() ) ) {
                /**
                 * @var array{status: string, message: string, data: array{oldQuantity: float|int, newQuantity: float|int, quantity: float|int}} $result
                 */
                $result = $this->increaseUnitQuantities(
                    product_id: $subItem->product_id,
                    unit_id: $subItem->unit_id,
                    quantity: $finalQuantity,
                    oldQuantity: $oldQuantity,
                    branch_id: $branch_id
                );
            }

            /**
             * We would like to record for every sub product
             * included an history of the stock transaction.
             */
            return $this->recordStockHistory(
                product_id: $subItem->product_id,
                action: $action,
                author: $author,
                unit_id: $subItem->unit_id,
                unit_price: $subItem->sale_price,
                quantity: $finalQuantity,
                order_id: $orderProduct->order_id ?? null,
                order_product_id: $orderProduct->id ?? null,
                total_price: $finalQuantity * $subItem->sale_price,
                old_quantity: $result[ 'data' ][ 'oldQuantity' ],
                new_quantity: $result[ 'data' ][ 'newQuantity' ],
                branch_id: $branch_id
            );
        } );

        /**
         * This should record the transaction for
         * the grouped product
         */
        $this->recordStockHistory(
            product_id: $product->id,
            action: $action,
            author: $author,
            unit_id: $orderProduct->unit_id,
            unit_price: $orderProduct->unit_price,
            quantity: $orderProductQuantity,
            order_id: $orderProduct->order_id,
            order_product_id: $orderProduct->id,
            total_price: $orderProductQuantity * $orderProduct->unit_price,
            old_quantity: 0,
            new_quantity: 0,
            branch_id: $branch_id
        );

        return $products;
    }

    /**
     * Will prevent negativity to occurs
     *
     * @param float $oldQuantity
     * @param float $quantity
     */
    private function preventNegativity( float|int $oldQuantity, float|int $quantity ): void
    {
        $diffQuantity = $this->currency
            ->define( $oldQuantity )
            ->subtractBy( $quantity )
            ->get();

        /**
         * this should prevent negative
         * stock on the current item
         */
        if ( $diffQuantity < 0 ) {
            throw new NotAllowedException( sprintf( __( 'Unable to proceed, this action will cause negative stock (%s). Old Quantity : (%s),  Quantity : (%s).' ), $diffQuantity, $oldQuantity, $quantity ) );
        }
    }

    /**
     * Works out which unit actually carries the stock for a product, and by how much a
     * quantity expressed in the sold unit has to be multiplied to be expressed in it.
     *
     * A shop that receives cartons and sells singles needs one honest stock figure, not one
     * per unit: counting the same goods on both the carton row and the piece row lets the
     * same item be sold twice. The figure is therefore held on the base unit of the group,
     * and every other unit is a label carrying its own barcode and its own price.
     *
     * Only products flagged with stock_on_base_unit take part. Everything else is returned
     * unchanged with a factor of 1, which is what keeps an ordinary product on its
     * existing behaviour: a product stocked as ten pieces and nine thousand dozens can still
     * sell a dozen from the dozen figure without touching the pieces.
     *
     * @return array{unit_id: int, factor: float}
     */
    public function resolveStockBearingUnit( int $product_id, int $unit_id, ?int $branch_id = null ): array
    {
        $unchanged = [ 'unit_id' => $unit_id, 'factor' => 1.0 ];

        $product = Product::find( $product_id );

        if ( ! $product instanceof Product || (bool) $product->stock_on_base_unit !== true ) {
            return $unchanged;
        }

        $unit = Unit::find( $unit_id );

        if ( ! $unit instanceof Unit ) {
            return $unchanged;
        }

        /**
         * The unit that holds the stock is the base of its group. When the sold unit is
         * already that unit there is nothing to convert.
         */
        if ( (bool) $unit->base_unit === true ) {
            return $unchanged;
        }

        $baseUnit = Unit::where( 'group_id', $unit->group_id )
            ->where( 'base_unit', true )
            ->first();

        if ( ! $baseUnit instanceof Unit ) {
            return $unchanged;
        }

        /**
         * A product only participates when it actually stores its stock against that base
         * unit. Without this a product whose unit merely happens to sit in a group with a
         * base elsewhere would have its deduction sent to a row that does not exist.
         */
        $baseRow = $this->unitQuantityRowFor( $product_id, (int) $baseUnit->id, $branch_id );

        if ( ! $baseRow instanceof ProductUnitQuantity ) {
            return $unchanged;
        }

        $baseValue = (float) $baseUnit->value;
        $unitValue = (float) $unit->value;

        if ( $baseValue <= 0 || $unitValue <= 0 ) {
            return $unchanged;
        }

        return [
            'unit_id' => (int) $baseUnit->id,
            'factor' => $unitValue / $baseValue,
        ];
    }

    /**
     * Handle stock adjustment for regular products.
     */
    private function handleStockAdjustmentRegularProducts( string $action, float|int $quantity, int $product_id, int $unit_id, int $author = 0, ?OrderProduct $orderProduct = null, float|int $unit_price = 0, float|int $total_price = 0, ?ProcurementProduct $procurementProduct = null, ?int $branch_id = null ): ProductHistory
    {
        /**
         * Selling a carton of a product stocked in pieces has to leave the piece count
         * short by a carton's worth. The conversion happens here, before anything is read
         * or written, so the check, the mutation and the ledger entry all speak in the
         * unit that holds the stock and stay consistent with each other.
         *
         * The branch goes in with it: the piece row the carton converts into is the one at the
         * branch that sold the carton, not whichever branch happens to hold a piece row.
         */
        $stockBearing = $this->resolveStockBearingUnit( $product_id, $unit_id, $branch_id );

        if ( $stockBearing[ 'unit_id' ] !== $unit_id ) {
            $quantity = $quantity * $stockBearing[ 'factor' ];
            $unit_id = $stockBearing[ 'unit_id' ];
        }

        /**
         * we would like to verify if
         * by editing a procurement product
         * the remaining quantity will be greather than 0
         */
        $oldQuantity = $this->getQuantity( $product_id, $unit_id, $branch_id );

        if ( in_array( $action, $this->getReduceActions() ) || in_array( $action, $this->getIncreaseActions() ) ) {
            if ( in_array( $action, $this->getReduceActions() ) ) {
                $this->preventNegativity(
                    oldQuantity: $oldQuantity,
                    quantity: $quantity
                );

                /**
                 * @var array{status: string, message: string, data: array{oldQuantity: float|int, newQuantity: float|int, quantity: float|int}} $result
                 */
                $result = $this->reduceUnitQuantities( $product_id, $unit_id, abs( $quantity ), $oldQuantity, $branch_id );

                /**
                 * We should reduce the quantity if
                 * we're dealing with a product that has
                 * accurate stock tracking
                 */
                if ( $procurementProduct instanceof ProcurementProduct ) {
                    $this->updateProcurementProductQuantity( $procurementProduct, $quantity, ProcurementProduct::STOCK_REDUCE );
                }
            } elseif ( in_array( $action, $this->getIncreaseActions() ) ) {
                /**
                 * @var array{status: string, message: string, data: array{oldQuantity: float|int, newQuantity: float|int, quantity: float|int}} $result
                 */
                $result = $this->increaseUnitQuantities( $product_id, $unit_id, abs( $quantity ), $oldQuantity, $branch_id );

                /**
                 * We should reduce the quantity if
                 * we're dealing with a product that has
                 * accurate stock tracking
                 */
                if ( $procurementProduct instanceof ProcurementProduct ) {
                    $this->updateProcurementProductQuantity( $procurementProduct, $quantity, ProcurementProduct::STOCK_INCREASE );
                }
            }

            return $this->recordStockHistory(
                product_id: $product_id,
                author: $author,
                action: $action,
                unit_id: $unit_id,
                unit_price: $unit_price,
                quantity: $quantity,
                total_price: $total_price,
                procurement_product_id: $procurementProduct?->id ?: null,
                procurement_id: $procurementProduct->procurement_id ?? null,
                order_id: isset( $orderProduct ) ? $orderProduct->order_id : null,
                order_product_id: isset( $orderProduct ) ? $orderProduct->id : null,
                old_quantity: $result[ 'data' ][ 'oldQuantity' ],
                new_quantity: $result[ 'data' ][ 'newQuantity' ],
                branch_id: $branch_id
            );
        } elseif (
            in_array( $action, [ ProductHistory::ACTION_SET ] )
        ) {
            /**
             * "Set the stock to exactly this" is the one operation where reading the
             * current value and then changing it is the intent, so the read and the
             * write have to be serialised. Without the lock a sale landing between
             * them would be counted twice: once by the till, once again by the
             * difference this branch computes.
             */
            [ $adjustQuantity, $action, $currentQuantity ] = $this->withUnitQuantityLock(
                product_id: $product_id,
                unit_id: $unit_id,
                callback: function () use ( $product_id, $unit_id, $quantity, $action, $branch_id ) {
                    $currentQuantity = $this->getQuantity(
                        product_id: $product_id,
                        unit_id: $unit_id,
                        branch_id: $branch_id
                    );

                    $adjustQuantity = 0;

                    if ( $currentQuantity < $quantity ) {
                        $action = ProductHistory::ACTION_ADDED;
                        $adjustQuantity = $quantity - $currentQuantity;

                        $this->increaseUnitQuantities(
                            product_id: $product_id,
                            unit_id: $unit_id,
                            quantity: $adjustQuantity,
                            oldQuantity: $currentQuantity,
                            branch_id: $branch_id
                        );
                    } elseif ( $currentQuantity > $quantity ) {
                        $action = ProductHistory::ACTION_REMOVED;
                        $adjustQuantity = $currentQuantity - $quantity;

                        $this->reduceUnitQuantities(
                            product_id: $product_id,
                            unit_id: $unit_id,
                            quantity: $adjustQuantity,
                            oldQuantity: $currentQuantity,
                            branch_id: $branch_id
                        );
                    }

                    return [ $adjustQuantity, $action, $currentQuantity ];
                },
                branch_id: $branch_id
            );

            return $this->recordStockHistory(
                product_id: $product_id,
                action: $action,
                author: $author,
                unit_id: $unit_id,
                unit_price: $unit_price,
                quantity: $adjustQuantity,
                total_price: $total_price,
                procurement_product_id: $procurementProduct?->id ?: null,
                procurement_id: $procurementProduct->procurement_id ?? null,
                order_id: isset( $orderProduct ) ? $orderProduct->order_id : null,
                order_product_id: isset( $orderProduct ) ? $orderProduct->id : null,
                old_quantity: $currentQuantity,
                new_quantity: $quantity,
                branch_id: $branch_id
            );
        }

        throw new NotAllowedException(
            sprintf(
                __( 'Unsupported stock action "%s"' ),
                $action
            )
        );
    }

    /**
     * Records stock transaction for the provided product.
     *
     * @param float $unit_price
     * @param float $quantity
     * @param float $total_price
     * @param float $old_quantity
     * @param float $new_quantity
     */
    public function recordStockHistory(
        int $product_id,
        string $action,
        int $unit_id,
        float|int $unit_price,
        float|int $quantity,
        float|int $total_price,
        int $author = 0,
        $order_id = null,
        $order_product_id = null,
        $procurement_product_id = null,
        $procurement_id = null,
        float|int $old_quantity = 0,
        float|int $new_quantity = 0,
        ?int $branch_id = null ): ProductHistory
    {
        $history = new ProductHistory;
        $history->product_id = $product_id;

        /**
         * Without this the ledger cannot say which shelf moved, so a stock report per branch is
         * impossible and a wrong movement cannot be traced back to the till that caused it.
         */
        $history->branch_id = $branch_id;
        $history->procurement_id = $procurement_id ?? null;
        $history->procurement_product_id = $procurement_product_id ?? null;
        $history->unit_id = $unit_id;
        $history->order_id = $order_id ?? null;
        $history->order_product_id = $order_product_id ?? null;
        $history->operation_type = $action;
        $history->unit_price = $unit_price;
        $history->total_price = $total_price;
        $history->description = $description ?? ''; // a description might be provided to describe the operation
        $history->before_quantity = $old_quantity; // if the stock management is 0, it shouldn't change
        $history->quantity = abs( $quantity );
        $history->after_quantity = $new_quantity; // if the stock management is 0, it shouldn't change
        $history->author_id = $author ?: Auth::id();
        $history->save();

        event( new ProductAfterStockAdjustmentEvent( $history ) );

        return $history;
    }

    /**
     * Return a base unit from a unit.
     */
    public function getBaseUnit( Unit $unit )
    {
        if ( $unit->base_unit ) {
            return $unit;
        }

        $unit->load( 'group.units' );

        return $unit->group->units->filter( fn( $unit ) => $unit->base_unit )->first();
    }

    public function computeSubItemQuantity(
        float $subItemQuantity,
        Unit $parentUnit,
        float $parentQuantity )
    {
        return ( $subItemQuantity * $parentUnit->value ) * $parentQuantity;
    }

    /**
     * Update procurement product quantity
     */
    public function updateProcurementProductQuantity( ProcurementProduct $procurementProduct, float|int $quantity, string $action ): void
    {
        if ( $action === ProcurementProduct::STOCK_INCREASE ) {
            $procurementProduct->available_quantity += $quantity;
        } elseif ( $action === ProcurementProduct::STOCK_REDUCE ) {
            $procurementProduct->available_quantity -= $quantity;
        }

        $procurementProduct->save();
    }

    /**
     * reduce Product unit quantities and update
     * the available quantity for the unit provided
     *
     * @param int       $product_id  Product id
     * @param int       $unit_id     Unit id
     * @param float|int $quantity    Quantity
     * @param float|int $oldQuantity Old quantity
     */
    public function reduceUnitQuantities( int $product_id, int $unit_id, float|int $quantity, float|int $oldQuantity, ?int $branch_id = null ): array
    {
        /**
         * $oldQuantity is accepted for backward compatibility but deliberately not
         * used to compute the new value. It was read before this call and is already
         * stale by the time we get here — see applyQuantityDelta().
         */
        return $this->applyQuantityDelta(
            product_id: $product_id,
            unit_id: $unit_id,
            delta: -abs( $quantity ),
            branch_id: $branch_id
        );
    }

    /**
     * Increase Product unit quantities and update
     * the available quantity for the unit provided
     *
     * @param int       $product_id  Product id
     * @param int       $unit_id     Unit id
     * @param float|int $quantity    Quantity
     * @param float|int $oldQuantity Old quantity
     */
    public function increaseUnitQuantities( int $product_id, int $unit_id, float|int $quantity, float|int $oldQuantity, ?int $branch_id = null ): array
    {
        /**
         * $oldQuantity is accepted for backward compatibility but deliberately not
         * used to compute the new value. See applyQuantityDelta().
         */
        return $this->applyQuantityDelta(
            product_id: $product_id,
            unit_id: $unit_id,
            delta: abs( $quantity ),
            branch_id: $branch_id
        );
    }

    /**
     * Tells the other tills that a unit quantity changed.
     *
     * Two things are deliberate here.
     *
     * Timing: the announcement waits for the surrounding transaction to commit, so a
     * sale that later fails does not leave every terminal showing a deduction that was
     * rolled back. Laravel's own $afterCommit on the event cannot be used, because it
     * defers the queue push and the sync queue driver then performs the broadcast
     * during DB::commit() — outside any guard placed around the dispatch.
     *
     * Failure handling: a broadcast error is logged and dropped. If the Reverb server
     * is not running, dispatching throws a BroadcastException, and without this the
     * exception would surface after the order was already committed — the customer
     * pays, the till shows an error, and the cashier rings the sale up a second time.
     * A till that misses a stock message keeps working: the server still refuses to
     * oversell, because the deduction itself is atomic.
     */
    protected function announceStockChange( ProductUnitQuantity $unitQuantity, float $quantity ): void
    {
        $announce = function () use ( $unitQuantity, $quantity ) {
            try {
                ProductStockUpdatedEvent::dispatch(
                    (int) $unitQuantity->product_id,
                    (int) $unitQuantity->id,
                    (int) $unitQuantity->unit_id,
                    $quantity,
                );
            } catch ( \Throwable $exception ) {
                Log::warning( 'Unable to announce a stock change to the tills.', [
                    'product_id' => $unitQuantity->product_id,
                    'unit_quantity_id' => $unitQuantity->id,
                    'quantity' => $quantity,
                    'reason' => $exception->getMessage(),
                ] );
            }
        };

        if ( DB::transactionLevel() > 0 ) {
            DB::afterCommit( $announce );

            return;
        }

        $announce();
    }

    /**
     * Runs a callback with the unit quantity row held under a write lock.
     *
     * Only needed for operations that must read the current stock and then change
     * it based on what they read. Plain deductions and additions do not need this —
     * applyQuantityDelta() is already atomic on its own and a lock would only add
     * contention between tills.
     *
     * SQLite has no row level locking, and the test suite runs on SQLite with a
     * single connection, so lockForUpdate() is a no-op there. The transaction still
     * gives atomicity, which is what the tests assert.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn $callback
     * @return TReturn
     */
    protected function withUnitQuantityLock( int $product_id, int $unit_id, callable $callback, ?int $branch_id = null ): mixed
    {
        return DB::transaction( function () use ( $product_id, $unit_id, $callback, $branch_id ) {
            $query = ProductUnitQuantity::where( 'product_id', $product_id )
                ->where( 'unit_id', $unit_id );

            /**
             * Locking every branch's row would make a count at one branch block a sale at the
             * other, so only the row being counted is held.
             */
            if ( $branch_id !== null && $this->unitQuantitiesAreSplitByBranch( $product_id, $unit_id ) ) {
                $query->where( 'branch_id', $branch_id );
            }

            $query->lockForUpdate()->first();

            return $callback();
        } );
    }

    /**
     * Applies a signed change to a unit quantity in a single atomic statement.
     *
     * This replaces a read-modify-write that could not survive two tills selling
     * at the same time. The previous implementation read the quantity into PHP,
     * subtracted there, and wrote the result back as an absolute value
     * ("SET quantity = 4"). Two terminals selling the last unit both read 1, both
     * computed 0, and both wrote 0 — the second write silently discarded the
     * first sale's deduction and both sales completed. A shop with several
     * checkout points loses stock accuracy on every concurrent sale.
     *
     * The fix is to let the database do the arithmetic and the guard together:
     *
     *   UPDATE ... SET quantity = quantity - ? WHERE ... AND quantity >= ?
     *
     * A row is only updated when the stock is genuinely there, so the check and
     * the deduction cannot be separated by another request. Zero affected rows
     * means the stock went away between the cart check and here, which is exactly
     * the race we need to reject rather than absorb.
     *
     * @param  float                                                                                                            $delta negative to deduct, positive to add
     * @return array{ status: string, message: string, data: array{ oldQuantity: float, newQuantity: float, quantity: float } }
     *
     * @throws NotFoundException
     * @throws NotAllowedException
     */
    protected function applyQuantityDelta( int $product_id, int $unit_id, float $delta, ?int $branch_id = null ): array
    {
        /**
         * The column is decimal(18,5). Rounding the amount to the same scale stops
         * float noise from a computed quantity (a unit conversion, a grouped item
         * ratio) from making the "quantity >= ?" guard reject a sale that is in
         * fact affordable by a fraction too small for the column to hold.
         */
        $amount = round( abs( $delta ), 5 );

        /**
         * One row, resolved once, and every statement below keyed on its primary key.
         *
         * This used to be a query over product and unit, which on a two branch shop matched two
         * rows, and the deduction was a mass decrement over that query: selling one item at one
         * branch took one item off both. Keying on the id keeps the guard and the arithmetic in the
         * same single statement — which is what makes concurrent tills safe — while making it
         * impossible for a movement to reach a shelf it does not belong to.
         */
        $target = $this->unitQuantityRowFor( $product_id, $unit_id, $branch_id );

        if ( $delta < 0 ) {
            /**
             * Nothing to deduct from. Distinguished from "not enough" because the
             * causes are different: a missing row is a configuration problem, an
             * insufficient one is a race or an overselling attempt.
             */
            if ( ! $target instanceof ProductUnitQuantity ) {
                throw new NotFoundException(
                    sprintf(
                        __( 'Unable to adjust the stock: the product #%s has no quantity recorded for the unit #%s.' ),
                        $product_id,
                        $unit_id
                    )
                );
            }

            $affected = ProductUnitQuantity::where( 'id', $target->id )
                ->whereRaw('quantity - reserved_quantity >= CAST(? AS DECIMAL(18,5))', [$amount])
                ->decrement( 'quantity', $amount );

            /**
             * The row exists, so a miss can only mean the stock is no longer
             * sufficient. Another terminal took it while this sale was being
             * assembled.
             */
            if ( $affected === 0 ) {
                throw new NotAllowedException(
                    sprintf(
                        __( 'Unable to proceed, the stock of "%s" changed while the sale was being recorded. Requested: %s, available: %s.' ),
                        Product::find( $product_id )?->name ?? '#' . $product_id,
                        $amount,
                        $this->getQuantity( $product_id, $unit_id, $branch_id )
                    )
                );
            }
        } elseif ( $target instanceof ProductUnitQuantity ) {
            ProductUnitQuantity::where( 'id', $target->id )->increment( 'quantity', $amount );
        } else {
            /**
             * Receiving stock in a unit the product has never held before is normal —
             * a purchase unit that is not a selling unit, for instance. The previous
             * implementation created the row through setQuantity(), so it is created
             * here too rather than refusing the procurement.
             *
             * The branch is stamped on it, so goods received at one branch do not become
             * a branchless row that every branch then reads as its own.
             */
            $created = new ProductUnitQuantity;
            $created->product_id = $product_id;
            $created->unit_id = $unit_id;
            $created->branch_id = $branch_id;
            $created->quantity = $amount;
            $created->save();

            $target = $created;
        }

        /**
         * Re-read rather than trust the computed value: this is the row every other
         * till has to be told about, and it is also what the history will record.
         */
        $unitQuantity = ProductUnitQuantity::find( $target->id );
        $newQuantity = (float) $unitQuantity->quantity;

        $this->announceStockChange( $unitQuantity, $newQuantity );

        /**
         * Derived from the value the database settled on rather than from the
         * caller's stale read, so the recorded history matches what actually
         * happened even when several sales interleave.
         */
        $oldQuantity = round( $newQuantity - ( $delta < 0 ? -$amount : $amount ), 5 );
        $quantity = $amount;

        return [
            'status' => 'success',
            'message' => __( 'The product quantity has been updated.' ),
            'data' => compact( 'newQuantity', 'oldQuantity', 'quantity' ),
        ];
    }

    /**
     * add a stock entry to a product
     * history using the provided informations
     *
     * @param array{quantity: float|int, unit_id?: int, purchase_price?: float|int, product_id?: int} $fields
     */
    public function procurementStockEntry( ProcurementProduct $product, array $fields ): array
    {
        $history = $this->stockAdjustment( ProductHistory::ACTION_ADDED, [
            'unit_id' => $product->unit_id,
            'product_id' => $product->product_id,
            'unit_price' => $product->purchase_price,
            'total_price' => $product->total_price,
            'procurement_id' => $product->procurement_id,
            'procurementProduct' => $product,
            'procurement_product_id' => $product->id,
            'quantity' => $fields[ 'quantity' ],
        ] );

        return [
            'status' => 'success',
            'message' => __( 'The product stock has been updated.' ),
            'data' => compact( 'history' ),
        ];
    }

    /**
     * returns only variable & product
     *
     * @return Collection
     */
    public function getProducts()
    {
        return Product::excludeVariations()->get();
    }

    /**
     * Before delete a specific variations
     *
     * @return array operation result
     */
    public function deleteVariations( $id = null )
    {
        $variations = $this->getVariations( $id );
        $count = $variations->count();

        $variations->map( function ( $variation ) {
            $variation->delete();
        } );

        if ( $count === 0 ) {
            return [
                'status' => 'info',
                'message' => __( 'There is no variations to delete.' ),
            ];
        }

        return [
            'status' => 'success',
            'message' => sprintf( __( '%s product(s) has been deleted.' ), $count ),
        ];
    }

    /**
     * Delete all the available products
     *
     * @return array result of the operation
     */
    public function deleteAllProducts()
    {
        $result = $this->getProducts()->map( function ( $product ) {
            return $this->deleteProduct( $product );
        } )->toArray();

        if ( ! $result ) {
            return [
                'status' => 'info',
                'message' => __( 'There is no products to delete.' ),
            ];
        }

        return [
            'status' => 'success',
            'message' => sprintf( __( '%s products(s) has been deleted.' ), count( $result ) ),
            'data' => compact( 'result' ),
        ];
    }

    public function getCogs( Product $product, Unit $unit )
    {
        $productUnitQuantity = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unit->id )
            ->first();

        if ( ! $productUnitQuantity instanceof ProductUnitQuantity ) {
            return 0;
        }

        return $productUnitQuantity->cogs ?: 0;
    }

    /**
     * Will return the last purchase price
     * defined for the provided product
     */
    public function getLastPurchasePrice( ?Product $product, Unit $unit, ?string $before = null ): float|int
    {
        if ( $product instanceof Product ) {
            $productHistory = ProductHistory::where( 'product_id', $product->id )
                ->where( 'unit_id', $unit->id )
                ->whereIn( 'operation_type', [
                    ProductHistory::ACTION_STOCKED,
                    ProductHistory::ACTION_CONVERT_IN,
                ] )
                ->when( $before, fn( $query ) => $query->where( 'created_at', '<', $before ) )
                ->latest()
                ->first();

            if ( $productHistory instanceof ProductHistory ) {
                return $productHistory->unit_price;
            }
        }

        return 0;
    }

    /**
     * Get a specific product using the
     * provided argument & identifier
     *
     * @param  string          $argument   Argument
     * @param  string|int|null $identifier Identifier
     * @return Product
     */
    public function getProductUsingArgument( $argument = 'id', $identifier = null )
    {
        if ( $identifier instanceof Product ) {
            return $identifier;
        }

        try {
            switch ( $argument ) {
                case 'id':
                    return $this->get( $identifier );
                case 'sku':
                    return $this->getProductUsingSKUOrFail( $identifier );
                case 'barcode':
                    return $this->getProductUsingBarcodeOrFail( $identifier );
            }
        } catch ( Exception $exception ) {
            throw new Exception( sprintf( __( 'Unable to find the product, as the argument "%s" which value is "%s", doesn\'t have any match.' ), $argument, $identifier ) );
        }
    }

    /**
     * Create a variation for a
     * specified parent product
     *
     * @param Product $parent Parent product
     * @param array   $fields Fields
     */
    public function createProductVariation( Product $parent, array $fields ): array
    {
        $product = new Product;

        event( new ProductBeforeCreatedEvent( $product ) );

        $mode = 'create';

        foreach ( $fields as $field => $value ) {
            $this->__fillProductFields( $product, compact( 'field', 'value', 'mode', 'fields' ) );
        }

        $product->author_id = $parent->author_id;
        $product->parent_id = $parent->id;
        $product->type = $parent->type;
        $product->category_id = $parent->category_id;
        $product->product_type = 'variation';
        $product->save();

        event( new ProductAfterCreatedEvent( $product ) );

        return [
            'status' => 'success',
            'message' => __( 'The product variation has been successfully created.' ),
            'data' => compact( 'product' ),
        ];
    }

    /**
     * Update product variation
     *
     * @param  Product $parent
     * @param  int     $id
     * @param  array   $fields
     * @return array
     */
    public function updateProductVariation( $parent, $id, $fields )
    {
        $product = Product::find( $id );
        $mode = 'update';

        event( new ProductBeforeUpdatedEvent( $product ) );

        foreach ( $fields as $field => $value ) {
            /**
             * we'll update the data
             * since the variation don't need to
             * access the parent data informations.
             */
            $this->__fillProductFields( $product, compact( 'field', 'value', 'mode', 'fields' ) );
        }

        $product->author_id = $parent->author_id;
        $product->parent_id = $parent->id;
        $product->type = $parent->type;
        $product->product_type = 'variation';
        $product->save();

        /**
         * compute product tax
         * for the meantime we assume the tax applies on the
         * main product
         */
        // $this->taxService->computeTax( $product, $fields[ 'tax_group_id' ] ?? null );

        event( new ProductAfterUpdatedEvent( $product ) );

        return [
            'status' => 'success',
            'message' => __( 'The product variation has been updated.' ),
            'data' => compact( 'product' ),
        ];
    }

    /**
     * Will return the Product Unit Quantities
     * for the provided product
     *
     * @return array
     */
    /**
     * What this product can be sold as, at this till.
     *
     * Scoped to the till's branch. Unscoped, the unit chooser listed one tile per branch per unit —
     * the same unit twice at two different prices, with nothing to say which shelf either belonged to —
     * and a cashier picking the wrong tile sold at another branch's price and deducted from another
     * branch's row. Branchless rows are always included, because a single store install leaves the
     * branch null on every row and excluding them would report every product as unsellable.
     */
    public function forStockDisplay( ProductUnitQuantity $row ): ProductUnitQuantity
    {
        $display = clone $row;
        $branchId = $row->branch_id !== null ? (int) $row->branch_id : null;
        $bearing = $this->resolveStockBearingUnit( (int) $row->product_id, (int) $row->unit_id, $branchId );
        if ( $bearing['unit_id'] !== (int) $row->unit_id ) {
            $base = $this->unitQuantityRowFor( (int) $row->product_id, $bearing['unit_id'], $branchId );
            $display->quantity = (float) $base->quantity / $bearing['factor'];
        }

        return app(ScheduledPriceService::class)->display($display);
    }

    public function getProductUnitQuantities( Product $product )
    {
        $branchId = $this->branchIdForCurrentTill();

        return $product->unit_quantities()
            ->with( [ 'unit' ] )
            ->visible()
            ->when(
                $branchId !== null,
                fn ( $query ) => $query->where(
                    fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
                )
            )
            ->get()->map( fn ( ProductUnitQuantity $row ) => $this->forStockDisplay( $row ) );
    }

    /**
     * Generate product barcode using product
     * configurations.
     *
     * @return void
     */
    public function generateProductBarcode( Product $product )
    {
        $this->barcodeService->generateBarcode(
            $product->barcode,
            $product->barcode_type
        );

        $product->unit_quantities->each( function ( $unitQuantity ) use ( $product ) {
            $this->barcodeService->generateBarcode(
                $unitQuantity->barcode,
                $product->barcode_type
            );
        } );
    }

    /**
     * Convert quantity from a source unit ($from) to a destination unit ($to)
     * using the provided quantity and product.
     */
    public function convertUnitQuantities( Product $product, Unit $from, float $quantity, Unit $to, ?ProcurementProduct $procurementProduct = null ): array
    {
        if ( $product->stock_management !== Product::STOCK_MANAGEMENT_ENABLED ) {
            throw new NotAllowedException( __( 'You cannot convert unit on a product having stock management disabled.' ) );
        }

        if ( $quantity == 0 ) {
            throw new NotAllowedException( __( 'The quantity to convert can\'t be zero.' ) );
        }

        $unitQuantityFrom = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $from->id )
            ->first();

        $unitQuantityTo = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $to->id )
            ->first();

        if ( $unitQuantityFrom->quantity < $quantity ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'The source unit "(%s)" for the product "%s", does not have enough quantity "%s". "%s" is required for the conversion.' ),
                    $from->name,
                    $product->name,
                    $unitQuantityFrom->quantity,
                    $quantity
                )
            );
        }

        if ( $from->id === $to->id ) {
            throw new NotAllowedException(
                __( 'The source and the destination unit can\'t be the same. What are you trying to do ?' )
            );
        }

        if ( ! $unitQuantityFrom instanceof ProductUnitQuantity ) {
            throw new NotFoundException(
                sprintf(
                    __( 'There is no source unit quantity having the name "%s" for the item %s' ),
                    $from->name,
                    $product->name
                )
            );
        }

        /**
         * if the unitQuantityTo is missing, we might then
         * create a new unit with quantity set to 0.
         */
        if ( ! $unitQuantityTo instanceof ProductUnitQuantity ) {
            $unitQuantityTo = new ProductUnitQuantity;
            $unitQuantityTo->product_id = $product->id;
            $unitQuantityTo->unit_id = $to->id;
            $unitQuantityTo->quantity = 0;
            $unitQuantityTo->visible = false;
            $unitQuantityTo->save();
        }

        if ( ! $this->unitService->isFromSameGroup( $from, $to ) ) {
            throw new NotAllowedException( __( 'The source unit and the destination unit doens\'t belong to the same unit group.' ) );
        }

        /**
         * We can't proceed with no base unit defined.
         */
        $from->load( [
            'group.units' => function ( $query ) {
                $query->where( 'base_unit', true );
            },
        ] );

        $baseUnit = $from->group->units->first();

        if ( ! $baseUnit instanceof Unit ) {
            throw new NotFoundException(
                sprintf(
                    __( 'The group %s has no base unit defined' ),
                    $from->group->name
                )
            );
        }

        /**
         * avoid converting unnecessary quantity, in order
         * to avoid having a quantity with a lot of decimal.
         *
         * @todo this should be skippable if the user has enabled decimal quantity
         */
        if ( $from->value < $to->value ) {
            $totalPossibleSlots = floor( ( $from->value * $quantity ) / $to->value );
            $quantity = $totalPossibleSlots * $to->value;
        }

        /**
         * This quantity will be added removed
         * from the source ProductUnitQuantity
         */
        $finalDestinationQuantity = $this->unitService->getConvertedQuantity(
            from: $from,
            to: $to,
            quantity: $quantity
        );

        /**
         * unless if decimal values are allowed
         * we should prevent a convertion that cause float numbers
         */
        if ( $finalDestinationQuantity < 1 ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'The conversion from "%s" will cause a decimal value less than one count of the destination unit "%s".' ),
                    $from->name,
                    $finalDestinationQuantity,
                    $to->name
                )
            );
        }

        $lastFromPurchasePrice = $this->getLastPurchasePrice(
            product: $product,
            unit: $from
        );

        /**
         * This quantity will be initially removed
         * from the source ProductUnitQuantity
         */
        /**
         * A conversion moves goods between two units of one branch's shelf. It calls the handler
         * directly rather than going through stockAdjustment(), so the branch has to be supplied
         * here or both legs would land on whichever branch happened to come first.
         */
        $conversionBranchId = $this->branchIdForCurrentUser();

        $this->handleStockAdjustmentRegularProducts(
            action: ProductHistory::ACTION_CONVERT_OUT,
            product_id: $product->id,
            unit_id: $from->id,
            unit_price: $lastFromPurchasePrice,
            quantity: $quantity,
            procurementProduct: $procurementProduct,
            total_price: ns()->currency->define( $lastFromPurchasePrice )->multipliedBy( $quantity )->toFloat(),
            branch_id: $conversionBranchId,
        );

        /**
         * The last purchase price of the destination unit
         * should be based on the last purchase price of the source unit
         * divided by the finalDestionaQuantity
         */
        $lastToPurchasePrice = ns()->currency->define( $lastFromPurchasePrice )
            ->divideBy( $finalDestinationQuantity )
            ->toFloat();

        $this->handleStockAdjustmentRegularProducts(
            action: ProductHistory::ACTION_CONVERT_IN,
            product_id: $product->id,
            unit_id: $to->id,
            unit_price: $lastToPurchasePrice,
            quantity: $finalDestinationQuantity,
            procurementProduct: $procurementProduct,
            total_price: ns()->currency->define( $lastFromPurchasePrice )->multipliedBy( $quantity )->toFloat(),
            branch_id: $conversionBranchId,
        );

        return [
            'status' => 'success',
            'message' => sprintf(
                __( 'The conversion of %s(%s) to %s(%s) was successful' ),
                $quantity,
                $from->name,
                $finalDestinationQuantity,
                $to->name
            ),
        ];
    }

    /**
     * Get the product using the provided SKU
     * or throw an exception if the product
     * doesn't exist.
     *
     * @return Collection<Product>
     *
     * @throws NotFoundException
     */
    public function searchProduct( ?string $search, int $limit = 5, array $arguments = [] )
    {
        /**
         * @var Builder $query
         */
        /**
         * Products are stored without a branch, so a branch cashier would otherwise be
         * offered every product in the company, including ones only another branch stocks
         * and dead rows left by earlier imports. Stock is what is branch owned, so a
         * product only belongs in the result when the searcher's branch holds a quantity
         * row for it.
         *
         * The branch was read from the user record alone, which is null for the administrator, and
         * the fallback below it was gated behind ns_store_enabled and therefore never ran. The
         * administrator standing at the main till was consequently handed both branches' rows for
         * the same product, and the till took the first — another branch's price, and another
         * branch's row id into the cart for the sale to be checked and deducted against. The
         * session is filled on every request by SetDefaultBranch, so it answers for whoever the
         * user record does not.
         *
         * Rows carrying no branch are always included: a single store install has no branch on any
         * of them, and excluding them would empty the catalogue.
         */
        /**
         * Resolved from the till first, so the catalogue behind F1 lists the shelf the cashier is
         * standing at rather than whichever branch the header picker happens to show.
         */
        $branchId = $this->branchIdForCurrentTill();

        $branchScope = fn ( $query ) => $query->where(
            fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
        );

        $query = Product::query()
            ->where( function ( $query ) use ( $search ) {
                $query
                    ->orWhere( 'name', 'LIKE', "%{$search}%" )
                    ->orWhere( 'sku', 'LIKE', "%{$search}%" )
                    ->orWhere( 'barcode', 'LIKE', "%{$search}%" );
            } )
            ->when( $branchId !== null, function ( $query ) use ( $branchScope ) {
                $query->whereHas( 'unit_quantities', $branchScope );
            } )
            ->with( [
                'unit_quantities' => function ( $query ) use ( $branchId, $branchScope ) {
                    if ( $branchId !== null ) {
                        $branchScope( $query );
                    }
                },
                'unit_quantities.unit',
                'category',
                'tax_group.taxes',
            ] )
            ->limit( $limit );

        /**
         * if custom arguments are provided
         * we'll parse it and convert it into
         * eloquent arguments
         */
        if ( ! empty( $arguments ) ) {
            $eloquenize = new EloquenizeArrayService;
            $eloquenize->parse( $query, $arguments );
        }

        return $query->get()
            ->map( function ( $product ) {
                $units = json_decode( $product->purchase_unit_ids );
                $product->setRelation('unit_quantities', $product->unit_quantities->map(fn (ProductUnitQuantity $row) => app(ScheduledPriceService::class)->display($row)));

                if ( $units ) {
                    $product->purchase_units = collect();
                    collect( $units )->each( function ( $unitID ) use ( &$product ) {
                        $product->purchase_units->push( Unit::find( $unitID ) );
                    } );
                }

                return $product;
            } );
    }

    /**
     * Get the product history for the provided.
     */
    public function sumProductHistory( int $product_id, int $unit_id, string $startOfDay, string $endOfDay, array $action ): float|int
    {
        return ProductHistory::where( 'product_id', $product_id )
            ->where( 'unit_id', $unit_id )
            ->whereIn( 'action', $action )
            ->whereBetween( 'created_at', [$startOfDay, $endOfDay] )
            ->sum( 'quantity' );
    }

    /**
     * Delete relations linked to a product
     */
    public function deleteProductRelations( Product $product )
    {
        $product->sub_items()->delete();
        $product->galleries()->delete();
        $product->variations()->delete();
        $product->product_taxes()->delete();
        $product->unit_quantities()->delete();
        $product->history()->delete();

        return [
            'status' => 'success',
            'message' => __( 'The product has been deleted.' ),
        ];
    }

    public function getReduceActions()
    {
        return Hook::filter( 'ns-products-decrease-actions', ProductHistory::STOCK_REDUCE );
    }

    public function getIncreaseActions()
    {
        return Hook::filter( 'ns-products-increase-actions', ProductHistory::STOCK_INCREASE );
    }

    /**
     * Scale Barcode Methods
     * These methods handle scale barcodes that encode product code and weight/price
     */

    /**
     * Check if scale barcode feature is enabled
     */
    public function isScaleBarcodeEnabled(): bool
    {
        return ns()->option->get( 'ns_scale_barcode_enabled', 'no' ) === 'yes';
    }

    /**
     * Check if a barcode matches the scale barcode format
     */
    public function isScaleBarcode( string $barcode ): bool
    {
        if ( ! $this->isScaleBarcodeEnabled() ) {
            return false;
        }

        $prefix = ns()->option->get( 'ns_scale_barcode_prefix', '2' );

        // Check if barcode starts with the configured prefix
        if ( ! str_starts_with( $barcode, $prefix ) ) {
            return false;
        }

        // Check if barcode has the expected length
        $prefixLength = strlen( $prefix );
        $productCodeLength = (int) ns()->option->get( 'ns_scale_barcode_product_length', 5 );
        $valueLength = (int) ns()->option->get( 'ns_scale_barcode_value_length', 5 );
        $expectedLength = $prefixLength + $productCodeLength + $valueLength + 1; // +1 for check digit

        if ( strlen( $barcode ) !== $expectedLength ) {
            return false;
        }

        // Check if the barcode contains only digits
        if ( ! ctype_digit( $barcode ) ) {
            return false;
        }

        return true;
    }

    /**
     * Parse a scale barcode and extract product code and weight/price
     *
     * @return array{
     *   product_code: string,
     *   value: float,
     *   type: string,
     *   original_barcode: string
     * }
     *
     * @throws Exception
     */
    public function parseScaleBarcode( string $barcode ): array
    {
        if ( ! $this->isScaleBarcode( $barcode ) ) {
            throw new Exception( __( 'Invalid scale barcode format' ) );
        }

        $prefix = ns()->option->get( 'ns_scale_barcode_prefix', '2' );
        $prefixLength = strlen( $prefix );
        $productCodeLength = (int) ns()->option->get( 'ns_scale_barcode_product_length', 5 );
        $valueLength = (int) ns()->option->get( 'ns_scale_barcode_value_length', 5 );
        $type = ns()->option->get( 'ns_scale_barcode_type', 'weight' );

        // Extract product code
        $productCode = substr( $barcode, $prefixLength, $productCodeLength );

        // Extract value
        $startPosition = $prefixLength + $productCodeLength;
        $rawValue = substr( $barcode, $startPosition, $valueLength );

        // Convert to float based on type
        if ( $type === 'weight' ) {
            // Weight is typically stored in grams, convert to kg
            $value = (float) $rawValue / 1000;
        } else {
            // Price is typically stored in cents, convert to currency
            $value = (float) $rawValue / 100;
        }

        return [
            'product_code' => $productCode,
            'value' => $value,
            'type' => $type,
            'original_barcode' => $barcode,
        ];
    }

    /**
     * Generate a PLU code for a product unit quantity
     *
     * @return string The generated PLU code
     *
     * @throws Exception
     */
    public function generateScalePLU( int $productId, int $unitQuantityId ): string
    {
        $product = Product::with( 'category.scaleRange' )->find( $productId );

        if ( ! $product ) {
            throw new Exception( __( 'Product not found.' ) );
        }

        if ( ! $product->category ) {
            throw new Exception( __( 'Product must have a category to generate PLU code.' ) );
        }

        if ( ! $product->category->scaleRange ) {
            throw new Exception(
                sprintf(
                    __( 'Category "%s" does not have a PLU range assigned.' ),
                    $product->category->name
                )
            );
        }

        $scaleRange = $product->category->scaleRange;

        // Get the next PLU from the range
        $plu = $scaleRange->getNextPLU();

        // Validate PLU is unique
        $existingPlu = ProductUnitQuantity::where( 'scale_plu', $plu )
            ->where( 'id', '!=', $unitQuantityId )
            ->first();

        if ( $existingPlu ) {
            // Try next PLU
            $scaleRange->incrementNextPLU();

            return $this->generateScalePLU( $productId, $unitQuantityId );
        }

        // Update the scale range
        $scaleRange->incrementNextPLU();

        return $plu;
    }

    /**
     * Validate and format a PLU code
     *
     * @param  int|null $productLength Override product code length
     * @return string   Formatted PLU code
     *
     * @throws Exception
     */
    public function validateAndFormatPLU( string $plu, ?int $productLength = null ): string
    {
        // Get the configured product code length
        if ( $productLength === null ) {
            $productLength = (int) ns()->option->get( 'ns_scale_barcode_product_length', 5 );
        }

        // Remove any non-numeric characters
        $plu = preg_replace( '/[^0-9]/', '', $plu );

        if ( empty( $plu ) ) {
            throw new Exception( __( 'PLU code cannot be empty.' ) );
        }

        // Check if PLU is numeric
        if ( ! ctype_digit( $plu ) ) {
            throw new Exception( __( 'PLU code must contain only digits.' ) );
        }

        // Pad with zeros to match the configured length
        $plu = str_pad( $plu, $productLength, '0', STR_PAD_LEFT );

        // Check if PLU length matches configured length
        if ( strlen( $plu ) > $productLength ) {
            throw new Exception(
                sprintf(
                    __( 'PLU code is too long. Maximum length is %d digits.' ),
                    $productLength
                )
            );
        }

        return $plu;
    }

    /**
     * Check if a PLU code is unique
     */
    public function isPLUUnique( string $plu, ?int $excludeUnitQuantityId = null ): bool
    {
        $query = ProductUnitQuantity::where( 'scale_plu', $plu );

        if ( $excludeUnitQuantityId ) {
            $query->where( 'id', '!=', $excludeUnitQuantityId );
        }

        return ! $query->exists();
    }

    /**
     * Map the frontend products payload into a normalized
     * array ready to be stored as ProductAdjustmentItem rows.
     */
    public function buildAdjustmentItems( array $products ): array
    {
        return collect( $products )->map( function ( $rawProduct ) {
            $product = Product::find( $rawProduct[ 'id' ] );

            return [
                'product_id' => $rawProduct[ 'id' ],
                'product_name' => $product->name,
                'unit_id' => $rawProduct[ 'adjust_unit' ][ 'unit_id' ],
                'unit_name' => $rawProduct[ 'adjust_unit' ][ 'unit' ][ 'name' ] ?? '',
                'unit_price' => $rawProduct[ 'adjust_unit' ][ 'sale_price' ] ?? 0,
                'quantity' => $rawProduct[ 'adjust_quantity' ],
                'adjust_action' => $rawProduct[ 'adjust_action' ],
                'description' => $rawProduct[ 'adjust_reason' ] ?? '',
                'procurement_product_id' => $rawProduct[ 'procurement_product_id' ] ?? null,
            ];
        } )->toArray();
    }

    /**
     * Create a draft stock adjustment batch with its line items.
     * No stock quantities are modified at this stage.
     */
    public function createAdjustmentDraft( array $products, ?string $title = '', string $description = '' ): ProductAdjustment
    {
        $adjustment = ProductAdjustment::create( [
            'author_id' => Auth::id(),
            'title' => $title ?: $this->generateAdjustmentTitle(),
            'status' => ProductAdjustment::STATUS_DRAFT,
            'description' => $description,
        ] );

        $items = $this->buildAdjustmentItems( $products );

        foreach ( $items as $item ) {
            ProductAdjustmentItem::create( array_merge( $item, [
                'adjustment_id' => $adjustment->id,
            ] ) );
        }

        return $adjustment->load( 'items' );
    }

    public function generateAdjustmentTitle( ?ProductAdjustment $adjustment = null ): string
    {
        if ( $adjustment instanceof ProductAdjustment && $adjustment->title ) {
            return sprintf( __( 'Stock Adjustment #%s' ), $adjustment->id );
        } else {
            $latestAdjustment = ProductAdjustment::latest()->first();
            $nextNumber = $latestAdjustment ? $latestAdjustment->id + 1 : 1;

            return sprintf( __( 'Stock Adjustment #%s' ), $nextNumber );
        }
    }

    /**
     * Replace all items of an existing draft adjustment.
     */
    public function updateAdjustmentDraft( ProductAdjustment $adjustment, array $products, ?string $title = '', string $description = '' ): ProductAdjustment
    {
        $adjustment->update( [
            'title' => $title ?: $this->generateAdjustmentTitle( $adjustment ),
            'description' => $description,
        ] );

        $adjustment->items()->delete();

        $items = $this->buildAdjustmentItems( $products );

        foreach ( $items as $item ) {
            ProductAdjustmentItem::create( array_merge( $item, [
                'adjustment_id' => $adjustment->id,
            ] ) );
        }

        return $adjustment->load( 'items' );
    }

    /**
     * Execute a draft adjustment: apply all stock changes and
     * mark the batch as performed.
     */
    public function executeAdjustmentDraft( ProductAdjustment $adjustment ): array
    {
        if ( $adjustment->status !== ProductAdjustment::STATUS_DRAFT ) {
            throw new NotAllowedException( __( 'Only draft adjustments can be executed.' ) );
        }

        $results = [];

        foreach ( $adjustment->items as $item ) {
            $results[] = $this->stockAdjustment( $item->adjust_action, [
                'unit_price' => $item->unit_price,
                'unit_id' => $item->unit_id,
                'procurement_product_id' => $item->procurement_product_id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'description' => $item->description ?? '',
            ] );
        }

        $adjustment->update( [ 'status' => ProductAdjustment::STATUS_PERFORMED ] );

        return $results;
    }

    public function getAdjustmentLabel( string $label ): string
    {
        return match ( $label ) {
            ProductAdjustment::STATUS_DRAFT => __( 'Draft' ),
            ProductAdjustment::STATUS_PERFORMED => __( 'Performed' ),
            default => __( 'Unknown' ),
        };
    }
}

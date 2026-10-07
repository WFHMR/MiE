<?php

namespace App\Crud;

use App\BulkEditor\ProductCrudBulkConfig;
use App\Casts\ProductTypeCast;
use App\Classes\CrudForm;
use App\Classes\CrudTable;
use App\Classes\FormInput;
use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use App\Services\ProductService;
use App\Services\TaxService;
use App\Services\ThaiVatService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\BulkEditor\Classes\BulkEditor;
use TorMorten\Eventy\Facades\Events as Hook;

#[ BulkEditor( config: ProductCrudBulkConfig::class ) ]
class ProductCrud extends CrudService
{
    /**
     * Define the autoload status
     */
    const AUTOLOAD = true;

    /**
     * Define the identifier
     */
    const IDENTIFIER = 'ns.products';

    /**
     * define the base table
     */
    protected $table = 'nexopos_products';

    /**
     * base route name
     */
    protected $mainRoute = 'ns.products';

    /**
     * Define namespace
     *
     * @param  string
     */
    protected $namespace = 'ns.products';

    /**
     * Model Used
     */
    protected $model = Product::class;

    /**
     * Will prepend the system options.
     */
    protected $prependOptions = true;

    /**
     * Adding relation
     */
    /**
     * Every join here is a left join on purpose.
     *
     * The author used to be an inner join, which silently dropped any product whose
     * author_id no longer matches a row in nexopos_users — products imported by a
     * since-removed account, or carried over from an older install where the seeded
     * administrator held id 1. On the live database that left 9 of 1,785 products
     * visible in the list. A missing author is a reason to show "N/A" in one cell,
     * never a reason to hide the product itself.
     */
    public $relations = [
        'leftJoin' => [
            [ User::class, 'user' ],
            [ 'nexopos_products_categories as category', 'nexopos_products.category_id', '=', 'category.id' ],
            [ 'nexopos_products as parent', 'nexopos_products.parent_id', '=', 'parent.id' ],
            [ 'nexopos_taxes_groups as taxes_groups', 'nexopos_products.tax_group_id', '=', 'taxes_groups.id' ],
        ],
    ];

    protected $pick = [
        'parent' => [ 'name' ],
        'user' => [ 'username' ],
        'category' => [ 'name' ],
    ];

    /**
     * Define permissions
     *
     * @param  array
     */
    protected $permissions = [
        'create' => 'nexopos.create.products',
        'read' => 'nexopos.read.products',
        'update' => 'nexopos.update.products',
        'delete' => 'nexopos.delete.products',
    ];

    /**
     * Define where statement
     *
     * @var array
     **/
    protected $listWhere = [];

    /**
     * Define where in statement
     *
     * @var array
     */
    protected $whereIn = [];

    /**
     * Fields which will be filled during post/put
     */
    public $fillable = [];

    /**
     * protected tax service
     *
     * @param TaxService
     */
    protected $taxService;

    public $casts = [
        'type' => ProductTypeCast::class,
    ];

    /**
     * Define Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->taxService = app()->make( TaxService::class );
    }

    /**
     * Return the label used for the crud
     * instance
     *
     * @return array
     **/
    public function getLabels()
    {
        return [
            'list_title' => __( 'Products List' ),
            'list_description' => __( 'Display all products.' ),
            'no_entry' => __( 'No products has been registered' ),
            'create_new' => __( 'Add a new product' ),
            'create_title' => __( 'Create a new product' ),
            'create_description' => __( 'Register a new product and save it.' ),
            'edit_title' => __( 'Edit product' ),
            'edit_description' => __( 'Modify  Product.' ),
            'back_to_list' => __( 'Return to Products' ),
        ];
    }

    /**
     * Check whether a feature is enabled
     *
     **/
    public function isEnabled( $feature ): bool
    {
        return false; // by default
    }

    /**
     * The unit rows the edit form is allowed to show: the ones held by the branch the person is
     * standing in.
     *
     * A product is one company-wide row, but its prices, cost and barcodes are held per branch in
     * nexopos_products_unit_quantities.branch_id. The form listed every row of the product, so a
     * product stocked in two branches showed each of its units twice, and the save was then refused
     * by ProductService with "You cannot assign the same unit to more than one selling unit." —
     * 2,093 of the shop's products are stocked in both branches, and none of them could be edited
     * at all. It also meant the prices and the cost on screen were whichever branch's row came
     * first out of the database, not the ones belonging to the branch being looked at.
     *
     * A row carrying no branch belongs to every branch — a single store install, and the rows that
     * predate the split — so those are shown when the branch holds none of its own. A product
     * stocked only in another branch falls back to that branch's figures, which is the starting
     * point for stocking it here; saving writes new rows stamped with this branch and leaves the
     * other branch's rows untouched.
     *
     * The unit_id is unique in the returned set whatever the data looks like, which is the one thing
     * the form cannot render and cannot save.
     *
     * @return Collection<int, ProductUnitQuantity>
     */
    /**
     * The name of the branch whose figures the form is showing, or null on a single store install.
     */
    private function currentBranchName(): ?string
    {
        $branchId = app( ProductService::class )->branchIdForCurrentUser();

        if ( $branchId === null ) {
            return null;
        }

        return Branch::find( $branchId )?->name;
    }

    private function unitQuantitiesForCurrentBranch( Product $product ): Collection
    {
        $rows = ProductUnitQuantity::withProduct( $product->id )->get();

        /**
         * Resolved the same way the till, the product list and the stock reports resolve it, so they
         * all agree on which shelf is being looked at.
         */
        $branchId = app( ProductService::class )->branchIdForCurrentUser();

        if ( $branchId !== null ) {
            $preferred = $rows->filter( fn ( ProductUnitQuantity $row ): bool => $row->branch_id !== null && (int) $row->branch_id === $branchId );

            if ( $preferred->isEmpty() ) {
                $preferred = $rows->filter( fn ( ProductUnitQuantity $row ): bool => $row->branch_id === null );
            }

            if ( $preferred->isNotEmpty() ) {
                $rows = $preferred;
            }
        }

        return $rows->unique( 'unit_id' )->values();
    }

    /**
     * Fields
     *
     * @param  object/null
     * @return array of field
     */
    public function getForm( $entry = null )
    {
        $groups = UnitGroup::get();
        $branchName = $this->currentBranchName();

        if ( $entry instanceof Product ) {
            $unitGroup = UnitGroup::where( 'id', $entry->unit_group )->with( 'units' )->first() ?? [];
            $units = UnitGroup::find( $entry->unit_group )->units;
        } else {
            $unitGroup = $groups->first();
            $units = collect( [] );

            if ( $unitGroup instanceof UnitGroup ) {
                $units = UnitGroup::find( $unitGroup->id )->units;
            }
        }

        $fields = Hook::filter( 'ns-products-units-quantities-fields', CrudForm::fields(
            FormInput::searchSelect(
                errors: [],
                label: __( 'Assigned Unit' ),
                name: 'unit_id',
                component: 'nsCrudForm',
                props: UnitCrud::getFormConfig(),
                options: Helper::toJsOptions( $units, [ 'id', 'name' ] ),
                description: __( 'The assigned unit for sale' ),
                validation: 'required',
                value: ! $units->isEmpty() ? $units->first()->id : '',
            ),
            FormInput::searchSelect(
                errors: [],
                label: __( 'Convert Unit' ),
                name: 'convert_unit_id',
                validation: 'different:variations.*.units.selling_group.*.unit_id',
                options: Helper::toJsOptions( $units, [ 'id', 'name' ] ),
                value: '',
                description: __( 'The unit that is selected for convertion by default.' ),
            ),
            FormInput::number(
                errors: [],
                label: __( 'Sale Price' ),
                name: 'sale_price_edit',
                value: 0,
                description: __( 'Define the regular selling price.' ),
                validation: 'required',
            ),
            FormInput::text(
                errors: [],
                label: __( 'Barcode' ),
                name: 'barcode',
                value: 0,
                description: __( 'Define the unit barcode value.' ),
                validation: '',
            ),
            FormInput::number(
                errors: [],
                label: __( 'Wholesale Price' ),
                name: 'wholesale_price_edit',
                value: 0,
                description: __( 'Define the wholesale price.' ),
                validation: 'required',
            ),
            FormInput::number(
                errors: [],
                label: __( 'COGS' ),
                name: 'cogs',
                value: '',
                description: __( 'Used to define the Cost of Goods Sold.' ),
            ),
            FormInput::switch(
                errors: [],
                label: __( 'Weighable Product' ),
                name: 'is_weighable',
                options: Helper::kvToJsOptions( [ __( 'No' ), __( 'Yes' ) ] ),
                description: __( 'Define whether this unit is sold by weight using scale barcodes.' ),
                disabled: ! ns()->option->get( 'ns_scale_barcode_product_length' ),
                value: 0,
            ),
            FormInput::text(
                errors: [],
                label: __( 'PLU Code' ),
                name: 'scale_plu',
                value: '',
                description: __( 'Price Lookup code for scale barcodes. Leave empty to auto-generate based on category PLU range.' ),
                disabled: ! ns()->option->get( 'ns_scale_barcode_product_length' ),
            ),
            FormInput::switch(
                errors: [],
                label: __( 'Stock Alert' ),
                name: 'stock_alert_enabled',
                options: Helper::kvToJsOptions( [ __( 'No' ), __( 'Yes' ) ] ),
                description: __( 'Define whether the stock alert should be enabled for this unit.' ),
            ),
            FormInput::number(
                errors: [],
                label: __( 'Low Quantity' ),
                name: 'low_quantity',
                description: __( 'Which quantity should be assumed low.' ),
            ),
            FormInput::switch(
                errors: [],
                label: __( 'Visible' ),
                name: 'visible',
                value: 1, // by default
                options: Helper::kvToJsOptions( [ __( 'No' ), __( 'Yes' ) ] ),
                description: __( 'Define whether the unit is available for sale.' ),
            ),
            FormInput::media(
                errors: [],
                label: __( 'Preview Url' ),
                name: 'preview_url',
                description: __( 'Provide the preview of the current unit.' ),
            ),
            FormInput::hidden(
                errors: [],
                label: '',
                name: 'id',
            ),
            FormInput::hidden(
                errors: [],
                label: '',
                name: 'quantity',
            ),
        ) );

        return Hook::filter( 'ns-products-crud-form', [
            'main' => [
                'label' => __( 'Name' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'validation' => 'required',
                'description' => __( 'Provide a name to the resource.' ),
            ],
            'variations' => [
                [
                    '$primary' => true,
                    'id' => $entry->id ?? '',
                    'tabs' => [
                        'identification' => [
                            'label' => __( 'Identification' ),
                            'fields' => [
                                [
                                    'type' => 'search-select',
                                    'component' => 'nsCrudForm',
                                    'props' => ProductCategoryCrud::getFormConfig(),
                                    'description' => __( 'Select to which category the item is assigned.' ),
                                    'options' => Helper::toJsOptions( ProductCategory::get(), [ 'id', 'name' ] ),
                                    'name' => 'category_id',
                                    'label' => __( 'Category' ),
                                    'validation' => 'required',
                                    'value' => $entry->category_id ?? '',
                                ], [
                                    /**
                                     * Searchable rather than a plain dropdown: the shop has 735
                                     * suppliers, and scrolling that list to find one is not a thing
                                     * anybody would do twice.
                                     */
                                    'type' => 'search-select',
                                    'description' => __( 'ซัพพลายเออร์ที่ร้านสั่งสินค้าตัวนี้เป็นปกติ ใช้เป็นค่าตั้งต้นในรายงานเมื่อรายการขายนั้นไม่มีใบสั่งซื้อผูกอยู่ ถ้ามีใบสั่งซื้อ รายงานจะยึดซัพพลายเออร์บนใบสั่งซื้อแทน' ),
                                    'options' => Helper::toJsOptions(
                                        Provider::orderBy( 'first_name' )->get(),
                                        fn ( Provider $provider ) => [
                                            'label' => trim( $provider->first_name . ' ' . ( $provider->last_name ?? '' ) ),
                                            'value' => $provider->id,
                                        ]
                                    ),
                                    'name' => 'provider_id',
                                    'label' => __( 'ซัพพลายเออร์ประจำ' ),

                                    /**
                                     * Left optional deliberately. 2,645 products already exist and the
                                     * shop is trading; making this required would block every edit to a
                                     * product whose supplier nobody has filled in yet.
                                     */
                                    'validation' => '',
                                    'value' => $entry->provider_id ?? '',
                                ], [
                                    'type' => 'text',
                                    'name' => 'barcode',
                                    'description' => __( 'Define the barcode value. Focus the cursor here before scanning the product.' ),
                                    'label' => __( 'Barcode' ),
                                    'validation' => '',
                                    'value' => $entry->barcode ?? '',
                                ], [
                                    'type' => 'text',
                                    'name' => 'sku',
                                    'description' => __( 'Define a unique SKU value for the product.' ),
                                    'label' => __( 'SKU' ),
                                    'validation' => '',
                                    'value' => $entry->sku ?? '',
                                ], [
                                    'type' => 'select',
                                    'description' => __( 'Define the barcode type scanned.' ),
                                    'options' => Helper::kvToJsOptions( [
                                        'ean8' => __( 'EAN 8' ),
                                        'ean13' => __( 'EAN 13' ),
                                        'codabar' => __( 'Codabar' ),
                                        'code128' => __( 'Code 128' ),
                                        'code39' => __( 'Code 39' ),
                                        'code11' => __( 'Code 11' ),
                                        'upca' => __( 'UPC A' ),
                                        'upce' => __( 'UPC E' ),
                                    ] ),
                                    'name' => 'barcode_type',
                                    'label' => __( 'Barcode Type' ),
                                    'validation' => 'required',
                                    'value' => $entry->barcode_type ?? ns()->option->get( 'ns_pos_default_barcode_type', 'code128' ),
                                ], [
                                    'type' => 'select',
                                    'options' => Helper::kvToJsOptions( Hook::filter( 'ns-products-type', [
                                        'materialized' => __( 'Materialized Product' ),
                                        'dematerialized' => __( 'Dematerialized Product' ),
                                        'grouped' => __( 'Grouped Product' ),
                                    ] ) ),
                                    'description' => __( 'Define the product type. Applies to all variations.' ),
                                    'name' => 'type',
                                    'validation' => 'required',
                                    'label' => __( 'Product Type' ),
                                    'value' => $entry->type ?? 'materialized',
                                ], [
                                    'type' => 'select',
                                    'options' => Helper::kvToJsOptions( [
                                        'available' => __( 'On Sale' ),
                                        'unavailable' => __( 'Hidden' ),
                                    ] ),
                                    'description' => __( 'Define whether the product is available for sale.' ),
                                    'name' => 'status',
                                    'validation' => 'required',
                                    'label' => __( 'Status' ),
                                    'value' => $entry->status ?? 'available',
                                ], [
                                    'type' => 'switch',
                                    'options' => Helper::kvToJsOptions( [
                                        'enabled' => __( 'Yes' ),
                                        'disabled' => __( 'No' ),
                                    ] ),
                                    'description' => __( 'Enable the stock management on the product. Will not work for service or uncountable products.' ),
                                    'name' => 'stock_management',
                                    'label' => __( 'Stock Management Enabled' ),
                                    'validation' => 'required',
                                    'value' => $entry->stock_management ?? 'enabled',
                                ], [
                                    'type' => 'switch',
                                    'options' => Helper::kvToJsOptions( [
                                        __( 'No' ),
                                        __( 'Yes' ),
                                    ] ),
                                    'description' => __( 'Pin this product to display at the top of the POS grid.' ),
                                    'name' => 'pinned',
                                    'label' => __( 'Pin Product' ),
                                    'value' => $entry->pinned ?? false,
                                ], [
                                    'type' => 'ckeditor',
                                    'name' => 'description',
                                    'label' => __( 'Description' ),
                                    'value' => $entry->description ?? '',
                                ],
                            ],
                        ],
                        'groups' => [
                            'label' => __( 'Groups' ),
                            'fields' => [
                                [
                                    'type' => 'hidden',
                                    'name' => 'product_subitems',
                                    'value' => $entry !== null ? $entry->sub_items()->get()->map( function ( $subItem ) {
                                        $subItem->load( 'product.unit_quantities.unit' );

                                        return [
                                            '_quantity_toggled' => false,
                                            '_price_toggled' => false,
                                            '_unit_toggled' => false,
                                            'id' => $subItem->id,
                                            'name' => $subItem->product->name,
                                            'unit_quantity_id' => $subItem->unit_quantity_id,
                                            'unit_quantity' => $subItem->unit_quantity,
                                            'product_id' => $subItem->product_id,
                                            'parent_id' => $subItem->parent_id,
                                            'unit_id' => $subItem->unit_id,
                                            'unit' => $subItem->unit,
                                            'quantity' => $subItem->quantity,
                                            'unit_quantities' => $subItem->product->unit_quantities,
                                            'sale_price' => $subItem->sale_price,
                                        ];
                                    } ) : [],
                                ],
                            ],
                            'component' => 'nsProductGroup',
                        ],
                        'units' => [
                            'label' => __( 'Units' ),
                            'fields' => [
                                [
                                    'type' => 'search-select',
                                    'options' => Helper::toJsOptions( $groups, [ 'id', 'name' ] ),
                                    'name' => 'unit_group',
                                    'description' => __( 'What unit group applies to the actual item. This group will apply during the procurement.' ),
                                    'label' => __( 'Unit Group' ),
                                    'validation' => 'required',
                                    'props' => UnitGroupCrud::getFormConfig(),
                                    'component' => 'nsCrudForm',
                                    'value' => $entry->unit_group ?? ( ! $groups->isEmpty() ? $groups->first()->id : '' ),
                                ], [
                                    'type' => 'switch',
                                    'description' => __( 'The product won\'t be visible on the grid and fetched only using the barcode reader or associated barcode.' ),
                                    'options' => Helper::boolToOptions(
                                        true: __( 'Yes' ),
                                        false: __( 'No' ),
                                    ),
                                    'name' => 'accurate_tracking',
                                    'label' => __( 'Accurate Tracking' ),
                                    'value' => $entry->accurate_tracking ?? false,
                                ], [
                                    'type' => 'switch',
                                    'description' => __( 'The Cost Of Goods Sold will be automatically be computed based on procurement and conversion.' ),
                                    'options' => Helper::boolToOptions(
                                        true: __( 'Yes' ),
                                        false: __( 'No' ),
                                    ),
                                    'name' => 'auto_cogs',
                                    'label' => __( 'Auto COGS' ),
                                    'value' => $entry->auto_cogs ?? true,
                                ], [
                                    'type' => 'group',
                                    'name' => 'selling_group',

                                    /**
                                     * Naming the branch is not decoration: the sale price, the
                                     * wholesale price and the cost below are held per branch, and the
                                     * same product carries different figures in each. Without it the
                                     * screen looks company-wide and a cost typed here reads as if it
                                     * applied to the whole shop.
                                     */
                                    'description' => $branchName !== null
                                        ? sprintf(
                                            __( 'Determine the unit for sale. The prices and the cost below belong to the branch "%s" only — every other branch keeps its own, and is edited after switching branch at the top of the screen.' ),
                                            $branchName
                                        )
                                        : __( 'Determine the unit for sale.' ),
                                    'label' => __( 'Selling Unit' ),
                                    'fields' => $fields,

                                    /**
                                     * We make sure to popular the unit quantity
                                     * with the entry values using the fields array.
                                     */
                                    'groups' => ( $entry instanceof Product ? $this->unitQuantitiesForCurrentBranch( $entry )
                                        ->map( function ( $productUnitQuantity ) use ( $fields ) {
                                            // get label of field having as name "unit_group"
                                            $field = collect( $fields )->filter( fn( $field ) => $field[ 'name' ] === 'unit_id' )->map( function ( $field ) use ( $productUnitQuantity ) {
                                                $field[ 'value' ] = $productUnitQuantity->{ $field[ 'name' ] };

                                                return $field;
                                            } );

                                            $optionLabel = __( 'Unnamed Section' );

                                            if ( $field->isNotEmpty() ) {
                                                $option = collect( $field[0][ 'options' ] )->filter( fn( $option ) => $option[ 'value' ] == $field[0][ 'value' ] );
                                                $optionLabel = $option->first() ? $option->first()[ 'label' ] : __( 'N/A' );
                                            }

                                            return [
                                                'fields' => collect( $fields )->map( function ( $field ) use ( $productUnitQuantity ) {

                                                    /**
                                                     * When the unit is assigned, a UnitQuantity model is created
                                                     * for the product. It shouldn't be possible to change the unit,
                                                     * to prevent loosing a reference to the created UnitQuantity.
                                                     */
                                                    if ( $field[ 'name' ] === 'unit_id' ) {
                                                        $field[ 'disabled' ] = true;
                                                    }

                                                    $field[ 'value' ] = $productUnitQuantity->{ $field[ 'name' ] };

                                                    return $field;
                                                } ),
                                                'closable' => true,
                                                'label' => $optionLabel,
                                            ];
                                        } ) : [] ),
                                    'options' => $entry instanceof Product ? UnitGroup::find( $entry->unit_group )->units : [],
                                ],
                            ],
                        ],
                        'expiracy' => [
                            'label' => __( 'Expiry' ),
                            'fields' => [
                                [
                                    'type' => 'switch',
                                    'name' => 'expires',
                                    'validation' => 'required',
                                    'label' => __( 'Product Expires' ),
                                    'options' => Helper::boolToOptions(
                                        true: __( 'Yes' ),
                                        false: __( 'No' ),
                                    ),
                                    'description' => __( 'Set to "No" expiration time will be ignored.' ),
                                    'value' => $entry->expires ?? false,
                                ], [
                                    'type' => 'select',
                                    'options' => Helper::kvToJsOptions( [
                                        'prevent_sales' => __( 'Prevent Sales' ),
                                        'allow_sales' => __( 'Allow Sales' ),
                                    ] ),
                                    'description' => __( 'Determine the action taken while a product has expired.' ),
                                    'name' => 'on_expiration',
                                    'label' => __( 'On Expiration' ),
                                    'value' => $entry->on_expiration ?? 'prevent-sales',
                                ],
                            ],
                        ],
                        'taxes' => [
                            'label' => __( 'Taxes' ),
                            'fields' => [
                                [
                                    'type' => 'select',
                                    'options' => Helper::kvToJsOptions( app()->make( ThaiVatService::class )->statuses() ),
                                    'description' => __( 'ยกเว้น VAT ใช้กับพืชผลเกษตรและเนื้อสัตว์ที่ยังไม่แปรรูปตามมาตรา 81 — ต่างจากอัตรา 0% ที่ยังนับเป็นยอดขายที่เสียภาษี' ),
                                    'name' => 'vat_status',
                                    'label' => __( 'สถานะภาษีมูลค่าเพิ่ม' ),
                                    'value' => $entry->vat_status ?? ns()->option->get( 'ns_thai_vat_default_status', ThaiVatService::STATUS_STANDARD ),
                                ], [
                                    'type' => 'select',
                                    'options' => Helper::toJsOptions( TaxGroup::get(), [ 'id', 'name' ] ),
                                    'description' => __( 'กลุ่มภาษีที่ใช้กับสินค้านี้ จำเป็นต้องเลือก เพราะสินค้าที่ไม่มีกลุ่มภาษีจะถูกขายโดยไม่มีภาษีผูกกับบรรทัดนั้น แม้ใบเสร็จจะเก็บ VAT รวมไปแล้ว' ),
                                    'name' => 'tax_group_id',
                                    'label' => __( 'Tax Group' ),

                                    /**
                                     * Required, and pre-filled from the shop setting.
                                     *
                                     * This field carried no validation and defaulted to blank, so a
                                     * product created by hand through this form was saved with
                                     * tax_group_id NULL while tax_type stayed 'inclusive' — a
                                     * contradiction: the price claims to contain VAT and no VAT is
                                     * computed. Thirteen products were created that way between the
                                     * 15th and the 21st, five of them sold, and their order lines went
                                     * out carrying zero tax while the bill charged VAT on the total.
                                     */
                                    'validation' => 'required',
                                    'value' => $entry->tax_group_id ?? ns()->option->get( 'ns_pos_tax_group' ) ?: '',
                                ], [
                                    'type' => 'select',
                                    'options' => Helper::kvToJsOptions( [
                                        'inclusive' => __( 'Inclusive' ),
                                        'exclusive' => __( 'Exclusive' ),
                                    ] ),
                                    'description' => __( 'Define what is the type of the tax.' ),
                                    'name' => 'tax_type',
                                    'label' => __( 'Tax Type' ),
                                    'value' => $entry->tax_type ?? 'inclusive',
                                ],
                            ],
                        ],
                        'images' => [
                            'label' => __( 'Images' ),
                            'fields' => [
                                [
                                    'type' => 'media',
                                    'name' => 'url',
                                    'label' => __( 'Image' ),
                                    'description' => __( 'Choose an image to add on the product gallery' ),
                                ], [
                                    'type' => 'switch',
                                    'name' => 'featured',
                                    'options' => Helper::kvToJsOptions( [ __( 'No' ), __( 'Yes' ) ] ),
                                    'label' => __( 'Is Primary' ),
                                    'description' => __( 'Define whether the image should be primary. If there are more than one primary image, one will be chosen for you.' ),
                                ],
                            ],
                            'groups' => $entry ? $entry->galleries->map( function ( $gallery ) {
                                return [
                                    [
                                        'type' => 'media',
                                        'name' => 'url',
                                        'label' => __( 'Image' ),
                                        'value' => $gallery->url,
                                        'description' => __( 'Choose an image to add on the product gallery' ),
                                    ], [
                                        'type' => 'switch',
                                        'name' => 'featured',
                                        'options' => Helper::boolToOptions(
                                            true: __( 'Yes' ),
                                            false: __( 'No' ),
                                        ),
                                        'label' => __( 'Is Primary' ),
                                        'value' => $gallery->featured ?? false,
                                        'description' => __( 'Define whether the image should be primary. If there are more than one primary image, one will be chosen for you.' ),
                                    ],
                                ];
                            } ) : [],
                        ],
                    ],
                ],
            ],
        ], $entry );
    }

    /**
     * Filter POST input fields
     *
     * @param  array of fields
     * @return array of fields
     */
    public function filterPostInputs( $inputs )
    {
        return $inputs;
    }

    /**
     * Filter PUT input fields
     *
     * @param  array of fields
     * @return array of fields
     */
    public function filterPutInputs( $inputs, Product $entry )
    {
        return $inputs;
    }

    /**
     * Before saving a record
     *
     * @param  Request $request
     * @return void
     */
    public function beforePost( $request )
    {
        return $request;
    }

    /**
     * After saving a record
     *
     * @param  Request $request
     * @return void
     */
    public function afterPost( $request, Product $entry )
    {
        return $request;
    }

    /**
     * get
     *
     * @param  string
     * @return mixed
     */
    public function get( $param )
    {
        switch ( $param ) {
            case 'model': return $this->model;
                break;
        }
    }

    /**
     * Before updating a record
     *
     * @param Request $request
     * @param  object entry
     * @return void
     */
    public function beforePut( $request, $entry )
    {
        return $request;
    }

    /**
     * After updating a record
     *
     * @param Request $request
     * @param  object entry
     * @return void
     */
    public function afterPut( $request, Product $product )
    {
        /**
         * delete all assigned taxes as it
         * be newly assigned
         */
        if ( $product instanceof Product ) {
            $product->taxes()->delete();
        }

        return $request;
    }

    /**
     * Before Delete
     *
     * Returning a non-empty value stops the model from being deleted and hands the value back as
     * the response — see CrudController::crudDelete(). That is what makes a per-branch removal
     * possible here without touching the generic delete path every other resource uses.
     *
     * @return array|null
     */
    public function beforeDelete( $namespace, $id, $model )
    {
        return $this->removeFromCurrentBranchInstead( $model );
    }

    /**
     * Deleting a product in one branch used to empty every other branch's shelf.
     *
     * A product is a single company-wide row; only its stock, prices and barcodes are held per
     * branch, in nexopos_products_unit_quantities.branch_id. Deleting the product therefore fired
     * ProductBeforeDeleteEventListener → ProductService::deleteProductRelations(), whose
     * unit_quantities()->delete() carries no branch condition, so the administrator removing an item
     * from the main warehouse silently destroyed the other branches' quantities, prices and barcodes
     * for it. On this shop that reached 935 products stocked in both branches at once, with no
     * warning and nothing to undo it with.
     *
     * The stock row is the thing that makes a product exist for a branch: the till's own search
     * (ProductService::searchProduct) only offers a product when the searcher's branch holds a
     * quantity row for it. So removing this branch's rows is exactly "this branch stops stocking
     * it", and it is enough — the catalogue entry stays for the branches that still sell it.
     *
     * Deleting the product outright is still possible, and still means what it says: it happens once
     * no other branch holds a row, which is reached by removing it branch by branch.
     *
     * @return array|null null to let the ordinary delete proceed
     */
    private function removeFromCurrentBranchInstead( Product $product ): ?array
    {
        /**
         * Resolved the same way the till, the product list and the stock reports resolve it, so all
         * four agree on which shelf is being looked at. Null is a single store install, where no row
         * carries a branch and there is nothing to scope — the original behaviour is correct there.
         */
        $branchId = app( ProductService::class )->branchIdForCurrentUser();

        if ( $branchId === null ) {
            return null;
        }

        $rows = ProductUnitQuantity::where( 'product_id', $product->id )->get( [ 'id', 'branch_id' ] );

        $mine = $rows->filter( fn ( ProductUnitQuantity $row ): bool => $row->branch_id !== null && (int) $row->branch_id === $branchId );
        $others = $rows->filter( fn ( ProductUnitQuantity $row ): bool => $row->branch_id !== null && (int) $row->branch_id !== $branchId );
        $shared = $rows->filter( fn ( ProductUnitQuantity $row ): bool => $row->branch_id === null );

        /**
         * Nobody else stocks it, so deleting the product is what was meant. Handed back to the
         * ordinary path, which still runs the dependency guard that refuses to delete goods that
         * appear on a bill.
         */
        if ( $others->isEmpty() ) {
            return null;
        }

        $branchName = Branch::find( $branchId )?->name ?: '#' . $branchId;

        /**
         * A row with no branch belongs to every branch at once. Deleting it is the very thing this
         * method exists to prevent, and there is no per-branch row to delete instead, so the request
         * is refused rather than guessed at.
         */
        if ( $shared->isNotEmpty() ) {
            throw new NotAllowedException( sprintf(
                __( 'สินค้า "%s" เก็บสต็อกแบบรวมทุกสาขา (ไม่ได้แยกสาขา) จึงลบออกจากสาขา %s เพียงสาขาเดียวไม่ได้ ต้องแยกสต็อกรายสาขาก่อน' ),
                $product->name,
                $branchName
            ) );
        }

        /**
         * The list is company-wide on purpose — a branch has to be able to see an item before it can
         * start stocking it — so a product another branch stocks is visible from here. Naming the
         * branches that do hold it turns a refusal into a direction.
         */
        if ( $mine->isEmpty() ) {
            $holders = Branch::whereIn( 'id', $others->pluck( 'branch_id' )->unique() )
                ->orderBy( 'name' )
                ->pluck( 'name' )
                ->implode( ', ' );

            throw new NotAllowedException( sprintf(
                __( 'สินค้า "%s" ไม่ได้อยู่ในคลังของสาขา %s จึงลบจากสาขานี้ไม่ได้ สินค้านี้อยู่ที่ %s — สลับสาขาที่หัวหน้าจอก่อนแล้วลบจากสาขานั้น' ),
                $product->name,
                $branchName,
                $holders
            ) );
        }

        /**
         * Refund and return handling reads back the unit quantity row a line was sold against, by
         * id on the order line and by branch on the order. Removing the row this branch sold from
         * would leave its own past bills unrefundable, so the branch that has sold the item keeps
         * it and sets the quantity to zero instead.
         */
        $soldHere = OrderProduct::where( 'product_id', $product->id )
            ->where( function ( $query ) use ( $branchId, $mine ): void {
                $query
                    ->whereIn( 'unit_quantity_id', $mine->pluck( 'id' ) )
                    ->orWhereHas( 'order', fn ( $order ) => $order->where( 'branch_id', $branchId ) );
            } )
            ->exists();

        if ( $soldHere ) {
            throw new NotAllowedException( sprintf(
                __( 'สินค้า "%s" มีการขายไปแล้วที่สาขา %s จึงเอาออกจากคลังสาขานี้ไม่ได้ เพราะบิลเก่าจะคืนสินค้าไม่ได้ ให้ปรับจำนวนคงเหลือเป็น 0 แทน' ),
                $product->name,
                $branchName
            ) );
        }

        ProductUnitQuantity::whereIn( 'id', $mine->pluck( 'id' ) )->delete();

        /**
         * Movement history is deliberately left alone. It records what happened on that shelf, and
         * an audit trail that disappears when the item does is no audit trail.
         */
        return [
            'status' => 'success',
            'message' => sprintf(
                __( 'เอาสินค้า "%s" ออกจากคลังของสาขา %s แล้ว อีก %d สาขายังมีสินค้านี้อยู่ ทะเบียนสินค้าจึงยังไม่ถูกลบ' ),
                $product->name,
                $branchName,
                $others->pluck( 'branch_id' )->unique()->count()
            ),
        ];
    }

    /**
     * Define Columns
     */
    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column(
                label: __( 'Name' ),
                identifier: 'name',
                width: '300px',
            ),
            CrudTable::column(
                label: __( 'Type' ),
                identifier: 'type',
                width: '150px',
            ),
            CrudTable::column(
                label: __( 'Sku' ),
                identifier: 'sku',
            ),
            /**
             * The three columns below are built in setActions() from the unit rows, so
             * there is no database column behind them and sorting has to stay off.
             */
            CrudTable::column(
                label: __( 'Units & Prices' ),
                identifier: 'units_prices',
                sort: false,
                width: '240px',
            ),
            CrudTable::column(
                label: __( 'Remaining Stock' ),
                identifier: 'remaining_stock',
                sort: false,
                width: '170px',
            ),
            CrudTable::column(
                label: __( 'Barcodes' ),
                identifier: 'barcodes',
                sort: false,
                width: '170px',
            ),
            CrudTable::column(
                label: __( 'Category' ),
                identifier: 'category_name',
                width: '150px',
            ),
            CrudTable::column(
                label: __( 'Status' ),
                identifier: 'status',
            ),
            CrudTable::column(
                label: __( 'Author' ),
                identifier: 'user_username',
            ),
            CrudTable::column(
                label: __( 'Date' ),
                identifier: 'created_at',
                width: '150px',
            ),
        );
    }

    /**
     * Define actions
     */
    public function setActions( CrudEntry $entry ): CrudEntry
    {
        $class = match ( $entry->type ) {
            'grouped' => 'text-success-tertiary',
            default => 'text-info-tertiary'
        };

        $entry->rawType = $entry->getOriginalValue( 'type' );
        $entry->rawStockManagement = $entry->getOriginalValue( 'stock_management' );

        $entry->stock_management = $entry->stock_management === 'enabled' ? __( 'Enabled' ) : __( 'Disabled' );
        $entry->status = $entry->status === 'available' ? __( 'Available' ) : __( 'Hidden' );
        $entry->category_name = $entry->category_name ?: __( 'Unassigned' );

        /**
         * The author is a left join, so a product whose author_id points at a row that
         * is no longer there arrives here with a null username instead of being dropped
         * from the result.
         */
        $entry->user_username = $entry->user_username ?: __( 'N/A' );

        $units = $this->describeUnits( $entry );

        $entry->units_prices = $this->renderUnitPrices( $units );
        $entry->remaining_stock = $this->renderRemainingStock( $units );
        $entry->barcodes = $this->renderBarcodes( $units );

        // you can make changes here
        $entry->action(
            identifier: 'edit',
            label: '<i class="mr-2 las la-edit"></i> ' . __( 'Edit' ),
            type: 'GOTO',
            url: ns()->url( '/dashboard/' . 'products' . '/edit/' . $entry->id ),
        );

        $entry->action(
            identifier: 'ns.quantities',
            label: '<i class="mr-2 las la-eye"></i> ' . __( 'Preview' ),
            type: 'POPUP',
            url: ns()->url( '/dashboard/' . 'products' . '/edit/' . $entry->id ),
        );

        $entry->action(
            identifier: 'units',
            label: '<i class="mr-2 las la-balance-scale-left"></i> ' . __( 'See Quantities' ),
            type: 'GOTO',
            url: ns()->url( '/dashboard/' . 'products/' . $entry->id . '/units' ),
        );

        $entry->action(
            identifier: 'history',
            label: '<i class="mr-2 las la-history"></i> ' . __( 'See History' ),
            type: 'GOTO',
            url: ns()->url( '/dashboard/' . 'products/' . $entry->id . '/history' ),
        );

        $entry->action(
            identifier: 'delete',
            label: '<i class="mr-2 las la-trash"></i> ' . __( 'Delete' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/ns.products/' . $entry->id ),
            confirm: [
                'message' => __( 'Would you like to delete this ?' ),
            ],
        );

        return $entry;
    }

    /**
     * The branch whose unit rows should be displayed, or null when the install is
     * single store and the rows carry no branch.
     *
     * This deliberately repeats the rule used by ProductService, which is what the POS
     * reads when it prices a scan and when it deducts stock. If the list applied a
     * different rule it would show a price or an on-hand figure the till does not agree
     * with, which is worse than showing nothing.
     */
    private function resolveBranchId(): ?int
    {
        /**
         * This used to sit behind the ns_store_enabled option, which does not exist in this
         * database and therefore reads as off, so the branch was always null and the list showed
         * every branch's rows stacked together — two prices and two on-hand figures for the same
         * unit of the same product, with nothing to say which shelf either belonged to. A branch
         * cashier was reading another branch's numbers on the page they check stock on.
         *
         * The branch now comes from the same resolver the POS uses, so the page and the till cannot
         * disagree. A cashier gets their own branch. The owner, who has no branch of their own, gets
         * whichever branch the header picker is set to — which is what the picker is for, and is the
         * only unambiguous answer available on a page with one row per unit.
         */
        return app( ProductService::class )->branchIdForCurrentUser();
    }

    /**
     * One entry per product, already resolved, keyed by product id.
     *
     * getEntries() calls setActions() once per row, so without this the same product
     * would be read again by each of the three columns.
     *
     * @var array<int, array<int, array{name: string, price: string, stock: float, ratio: float, converted: bool, barcode: ?string}>>
     */
    private array $describedUnits = [];

    /**
     * Every unit, keyed by id, read once per request.
     *
     * @var Collection<int, Unit>|null
     */
    private ?Collection $units = null;

    /**
     * @return Collection<int, Unit>
     */
    private function getUnits(): Collection
    {
        if ( $this->units === null ) {
            $this->units = Unit::query()
                ->get( [ 'id', 'name', 'value', 'base_unit', 'group_id' ] )
                ->keyBy( 'id' );
        }

        return $this->units;
    }

    /**
     * Describes what a product can be sold as, at what price, and how much of each is
     * left, in the order the units get larger.
     *
     * The stock figure is the interesting part. A product flagged stock_on_base_unit
     * keeps a single honest count against the base unit of its group, and its other
     * units are price labels that convert into it — that is the arrangement
     * ProductService::resolveStockBearingUnit implements and the till obeys. Reading the
     * quantity column of those label rows reports zero for a carton the till will sell
     * without complaint, so the figure shown here is derived the same way the sale is:
     * the base count divided by the unit's factor, floored, because half a carton is not
     * a carton anyone can carry out.
     *
     * Products that are not flagged keep the ordinary behaviour, where each unit
     * row carries its own stock and is reported as it stands.
     *
     * @return array<int, array{name: string, price: string, stock: float, ratio: float, converted: bool, barcode: ?string}>
     */
    private function describeUnits( CrudEntry $entry ): array
    {
        $productId = (int) $entry->id;

        if ( isset( $this->describedUnits[ $productId ] ) ) {
            return $this->describedUnits[ $productId ];
        }

        $branchId = $this->resolveBranchId();

        /**
         * Rows carrying no branch are kept alongside the branch's own. A single store install leaves
         * branch_id null on every row, and excluding them would empty the column on every product.
         */
        $rows = ProductUnitQuantity::withProduct( $productId )
            ->when(
                $branchId !== null,
                fn ( $query ) => $query->where(
                    fn ( $scoped ) => $scoped->where( 'branch_id', $branchId )->orWhereNull( 'branch_id' )
                )
            )
            ->orderBy( 'sale_price' )
            ->get( [ 'id', 'unit_id', 'barcode', 'quantity', 'sale_price' ] );

        $units = $this->getUnits();
        $onBaseUnit = (bool) $entry->stock_on_base_unit;

        $baseUnitByGroup = $units
            ->filter( fn ( Unit $unit ): bool => (bool) $unit->base_unit === true )
            ->keyBy( 'group_id' );

        $rowsByUnit = $rows->keyBy( 'unit_id' );

        $described = $rows->map( function ( ProductUnitQuantity $row ) use ( $units, $baseUnitByGroup, $rowsByUnit, $onBaseUnit ): array {
            $unit = $units[ $row->unit_id ] ?? null;

            $line = [
                'name' => $unit->name ?? __( 'Unknown Unit' ),
                'price' => ns()->currency->define( $row->sale_price )->format(),
                'stock' => (float) $row->quantity,
                'ratio' => 1.0,
                'converted' => false,
                'barcode' => $row->barcode,
            ];

            if ( ! $onBaseUnit || $unit === null || (bool) $unit->base_unit === true ) {
                return $line;
            }

            $baseUnit = $baseUnitByGroup[ $unit->group_id ] ?? null;

            if ( $baseUnit === null ) {
                return $line;
            }

            /**
             * The base row has to exist for the conversion to mean anything: without it
             * there is no count to divide, which is the same condition
             * resolveStockBearingUnit checks before it agrees to convert a deduction.
             */
            $baseRow = $rowsByUnit[ $baseUnit->id ] ?? null;

            if ( $baseRow === null ) {
                return $line;
            }

            $baseValue = (float) $baseUnit->value;
            $unitValue = (float) $unit->value;

            if ( $baseValue <= 0 || $unitValue <= 0 ) {
                return $line;
            }

            $factor = $unitValue / $baseValue;

            $line[ 'ratio' ] = $factor;
            $line[ 'converted' ] = true;
            $line[ 'stock' ] = floor( ( (float) $baseRow->quantity ) / $factor );

            return $line;
        } )->values()->all();

        return $this->describedUnits[ $productId ] = $described;
    }

    /**
     * Drops the decimals a quantity does not need, so 48.00 reads as 48 while 1.50
     * still reads as 1.5.
     */
    private function formatQuantity( float $quantity ): string
    {
        $formatted = number_format( $quantity, 2, '.', ',' );

        if ( str_contains( $formatted, '.' ) ) {
            $formatted = rtrim( rtrim( $formatted, '0' ), '.' );
        }

        return $formatted;
    }

    /**
     * Every renderer below returns HTML because the table cell is bound with v-html and
     * passed through DOMPurify on the front end. Anything coming from the database is
     * escaped here regardless, since a unit name or a barcode is user supplied.
     *
     * @param array<int, array{name: string, price: string, stock: float, ratio: float, converted: bool, barcode: ?string}> $units
     */
    private function renderUnitPrices( array $units ): string
    {
        if ( $units === [] ) {
            return __( 'N/A' );
        }

        return collect( $units )->map( function ( array $unit ): string {
            /**
             * The ratio is what turns "หีบ ฿1,070" from a bare number into something the
             * owner can check against the piece price.
             */
            $ratio = $unit[ 'converted' ]
                ? sprintf( ' <span class="text-xs opacity-60">&times;%s</span>', e( $this->formatQuantity( $unit[ 'ratio' ] ) ) )
                : '';

            return sprintf(
                '<div class="flex justify-between gap-2"><span class="truncate">%s%s</span><span class="font-semibold whitespace-nowrap">%s</span></div>',
                e( $unit[ 'name' ] ),
                $ratio,
                e( $unit[ 'price' ] )
            );
        } )->implode( '' );
    }

    /**
     * @param array<int, array{name: string, price: string, stock: float, ratio: float, converted: bool, barcode: ?string}> $units
     */
    private function renderRemainingStock( array $units ): string
    {
        if ( $units === [] ) {
            return __( 'N/A' );
        }

        return collect( $units )->map( function ( array $unit ): string {
            /**
             * A converted line is the same goods counted a second way, not stock held on
             * top of the base figure. The equals sign is there so nobody adds the two up.
             */
            $prefix = $unit[ 'converted' ] ? '= ' : '';

            return sprintf(
                '<div class="%s">%s%s <span class="text-xs">%s</span></div>',
                $unit[ 'stock' ] > 0 ? '' : 'text-error-tertiary',
                $prefix,
                e( $this->formatQuantity( $unit[ 'stock' ] ) ),
                e( $unit[ 'name' ] )
            );
        } )->implode( '' );
    }

    /**
     * @param array<int, array{name: string, price: string, stock: float, ratio: float, converted: bool, barcode: ?string}> $units
     */
    private function renderBarcodes( array $units ): string
    {
        $barcodes = collect( $units )
            ->pluck( 'barcode' )
            ->filter( fn ( ?string $barcode ): bool => ! empty( $barcode ) )
            ->unique();

        if ( $barcodes->isEmpty() ) {
            return __( 'N/A' );
        }

        return $barcodes->map( fn ( string $barcode ): string => sprintf(
            '<div class="font-mono text-xs">%s</div>',
            e( $barcode )
        ) )->implode( '' );
    }

    public function hook( $query ): void
    {
        $query->orderBy( 'updated_at', 'desc' );
    }

    public function applySearch( $query, Request $request ): void
    {
        $request->validate( ['search' => 'nullable|string|max:250', 'supplier' => 'nullable|string|max:250'] );
        $terms = preg_split( '/[\s+*]+/u', trim( (string) $request->query( 'search', '' ) ), -1, PREG_SPLIT_NO_EMPTY );
        $branchId = $this->resolveBranchId();
        foreach ( array_unique( $terms ) as $term ) {
            $query->where( function ( $match ) use ( $term, $branchId ) {
                foreach ( ['name', 'sku', 'barcode'] as $column ) {
                    $match->orWhere( 'nexopos_products.' . $column, 'like', '%' . $term . '%' );
                }
                $match->orWhereExists( function ( $units ) use ( $term, $branchId ) {
                    $units->selectRaw( '1' )->from( 'nexopos_products_unit_quantities' )
                        ->whereColumn( 'nexopos_products_unit_quantities.product_id', 'nexopos_products.id' )
                        ->where( 'nexopos_products_unit_quantities.barcode', 'like', '%' . $term . '%' );
                    if ( $branchId !== null ) {
                        $units->where( fn ( $scoped ) => $scoped->where( 'nexopos_products_unit_quantities.branch_id', $branchId )
                            ->orWhereNull( 'nexopos_products_unit_quantities.branch_id' ) );
                    }
                } );
            } );
        }
        $supplierTerms = preg_split( '/\s+/u', trim( (string) $request->query( 'supplier', '' ) ), -1, PREG_SPLIT_NO_EMPTY );
        if ( $supplierTerms ) {
            $query->whereExists( function ( $providers ) use ( $supplierTerms ) {
                $providers->selectRaw( '1' )->from( 'nexopos_providers' )
                    ->whereColumn( 'nexopos_providers.id', 'nexopos_products.provider_id' );
                foreach ( array_unique( $supplierTerms ) as $term ) {
                    $providers->where( function ( $match ) use ( $term ) {
                        foreach ( ['first_name', 'last_name', 'code'] as $column ) {
                            $match->orWhere( 'nexopos_providers.' . $column, 'like', '%' . $term . '%' );
                        }
                    } );
                }
            } );
        }
    }

    /**
     * Bulk Delete Action
     *
     * @param    object Request with object
     * @return  false/array
     */
    public function bulkAction( Request $request )
    {
        if ( $request->input( 'action' ) == 'delete_selected' ) {
            /**
             * Will control if the user has the permissoin to do that.
             */
            if ( $this->permissions[ 'delete' ] !== false ) {
                ns()->restrict( $this->permissions[ 'delete' ] );
            } else {
                throw new NotAllowedException;
            }

            $status = [
                'success' => 0,
                'error' => 0,
            ];

            foreach ( $request->input( 'entries' ) as $id ) {
                $entity = $this->model::find( $id );

                if ( ! $entity instanceof Product ) {
                    $status[ 'error' ]++;

                    continue;
                }

                /**
                 * The same branch rule as the single delete. Selecting rows and pressing delete has
                 * to mean what pressing delete on one row means, or the bulk action becomes the way
                 * round it — and the way to wipe every branch's stock in one click.
                 *
                 * A refusal is counted rather than thrown so one protected item does not abandon the
                 * rest of the selection halfway through. NotAllowedException is the only exception
                 * caught: it is the deliberate refusal, both from the branch rule here and from the
                 * dependency guard that protects goods sitting on a bill.
                 */
                try {
                    if ( $this->removeFromCurrentBranchInstead( $entity ) === null ) {
                        $entity->delete();
                    }

                    $status[ 'success' ]++;
                } catch ( NotAllowedException ) {
                    $status[ 'error' ]++;
                }
            }

            return $status;
        }

        return Hook::filter( $this->namespace . '-catch-action', false, $request );
    }

    /**
     * get Links
     *
     * @return array of links
     */
    public function getLinks(): array
    {
        return [
            'list' => ns()->url( 'dashboard/' . 'products' ),
            'create' => ns()->url( 'dashboard/' . 'products/create' ),
            'edit' => ns()->url( 'dashboard/' . 'products/edit/' ),
        ];
    }

    /**
     * Get Bulk actions
     *
     * @return array of actions
     **/
    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [
            [
                'label' => __( 'Delete Selected Groups' ),
                'identifier' => 'delete_selected',
                'confirm' => __( 'Would you like to delete selected entries ?' ),
                'url' => ns()->route( 'ns.api.crud-bulk-actions', [
                    'namespace' => $this->namespace,
                ] ),
            ],
        ] );
    }

    /**
     * get exports
     *
     * @return array of export formats
     **/
    public function getExports()
    {
        return [];
    }

    public function getExtractedProductForm( $product = null )
    {
        $rawForm = $this->getForm( $product );

        return array_merge(
            $this->extractForm( $rawForm ),
            [
                'variations' => collect( $rawForm[ 'variations' ] )->map( function ( $variation, $index ) {
                    $data = $this->extractForm( $variation );
                    if ( $index === 0 ) {
                        $data[ '$primary' ] = true;
                    }

                    $data[ 'images' ] = collect( $variation[ 'tabs' ][ 'images' ][ 'groups' ] )->map( function ( $fields ) {
                        return $this->extractFields( $fields );
                    } )->toArray();

                    $groups = [];

                    collect( $variation[ 'tabs' ][ 'units' ][ 'fields' ] )->filter( function ( $field ) {
                        return $field[ 'type' ] === 'group';
                    } )->each( function ( $field ) use ( &$groups ) {
                        $groups[ $field[ 'name' ] ] = collect( $field[ 'groups' ] )
                            ->map( fn( $group ) => $this->extractFields( $group[ 'fields' ] ) )
                            ->toArray();
                    } );

                    $data[ 'units' ] = [
                        ...$data[ 'units' ],
                        ...$groups,
                    ];

                    return $data;
                } )->toArray(),
            ]
        );
    }

    /**
     * Returns a key-value array format
     * of the crud post submitted.
     */
    public function getFlatForm( array $inputs, $model = null ): array
    {
        $primary = collect( $inputs[ 'variations' ] )
            ->filter( fn( $variation ) => isset( $variation[ '$primary' ] ) )
            ->first();

        $source = $primary;

        /**
         * this is made to ensure the array
         * provided isn't flattened
         */
        unset( $primary[ 'images' ] );
        unset( $primary[ 'units' ] );
        unset( $primary[ 'groups' ] );

        $primary[ 'identification' ][ 'name' ] = $inputs[ 'name' ];
        $primary = Helper::flatArrayWithKeys( $primary )->toArray();
        $primary[ 'product_type' ] = 'product';

        /**
         * let's restore the fields before
         * storing that.
         */
        $primary[ 'images' ] = $source[ 'images' ];
        $primary[ 'units' ] = $source[ 'units' ];

        /**
         * Only a grouped product carries sub items, so an ordinary product has no groups key to
         * restore. Reading it unguarded turned every update of an ordinary product into a 500 the
         * moment the caller left the key out — which the create path in ProductsController::saveProduct
         * already guards against with the same expression. The two had drifted apart: one was fixed and
         * its sibling was not, and nothing ran the update path to notice.
         */
        $primary[ 'groups' ] = $source[ 'groups' ] ?? [];

        unset( $primary[ '$primary' ] );

        /**
         * As foreign fields aren't handled with
         * they are complex (array), this methods allow
         * external script to reinject those complex fields.
         */
        return Hook::filter( 'ns-update-products-inputs', $primary, $source, $model );
    }
}

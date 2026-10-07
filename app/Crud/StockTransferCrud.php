<?php

namespace App\Crud;

use App\Classes\CrudForm;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Services\CrudEntry;
use App\Services\CrudService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use TorMorten\Eventy\Facades\Events as Hook;

class StockTransferCrud extends CrudService
{
    /**
     * Define namespace for the resource
     */
    const IDENTIFIER = 'ns.stock-transfers';

    /**
     * Define if the crud should be auto-discovered
     */
    const AUTOLOAD = true;

    /**
     * Define the main resource table name
     */
    protected $table = 'nexopos_stock_transfers';

    /**
     * Define the model class
     */
    protected $model = StockTransfer::class;

    /**
     * Define permissions
     */
    protected $permissions = [
        'create' => 'nexopos.create.stock-transfers',
        'read' => 'nexopos.read.stock-transfers',
        'update' => 'nexopos.update.stock-transfers',
        'delete' => 'nexopos.delete.stock-transfers',
    ];

    /**
     * Fields which will be filled during post/put
     */
    public $fillable = [
        'product_id',
        'unit_id',
        'from_branch_id',
        'to_branch_id',
        'quantity',
        'notes',
        'status',
        'requested_by',
    ];

    protected $casts = [
        'quantity' => 'float',
    ];

    /**
     * Define Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Return the label used for the crud instance
     */
    public function getLabels(): array
    {
        return [
            'list_title' => __( 'Stock Transfers List' ),
            'list_description' => __( 'Display all stock transfers between branches.' ),
            'no_entry' => __( 'No stock transfers has been registered' ),
            'create_new' => __( 'Add a new stock transfer' ),
            'create_title' => __( 'Create a new stock transfer' ),
            'create_description' => __( 'Register a new stock transfer and save it.' ),
            'edit_title' => __( 'Edit stock transfer' ),
            'edit_description' => __( 'Modify Stock Transfer.' ),
        ];
    }

    /**
     * Check whether a feature is enabled
     */
    public function isEnabled( $feature ): bool
    {
        return false; // Make sure this is false
    }

    public function getLinks(): array
    {
        return [
            'list' => route( 'ns.dashboard.stock-transfers' ),
            'create' => route( 'ns.dashboard.stock-transfers.create' ),
            'edit' => ns()->url( '/dashboard/stock-transfers/edit/' ),
            'post' => ns()->url( '/api/crud/ns.stock-transfers' ),
            'put' => ns()->url( '/api/crud/ns.stock-transfers/{id}' ),
        ];
    }

    /**
     * Filter POST input fields
     */
    public function filterPostInputs( array $inputs ): array
    {
        $inputs = $this->validateTransferInputs( $inputs );
        $inputs['status'] = 'pending';
        $inputs['requested_by'] = auth()->id();

        return $inputs;
    }

    private function validateTransferInputs( array $inputs ): array
    {
        $inputs = Validator::make( $inputs, [
            'product_id' => 'required|integer|exists:' . Product::class . ',id',
            'unit_id' => 'required|integer|exists:' . Unit::class . ',id',
            'from_branch_id' => 'required|integer|exists:' . Branch::class . ',id',
            'to_branch_id' => 'required|integer|exists:' . Branch::class . ',id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ] )->validate();

        // Validate that from_branch_id and to_branch_id are different
        if ( (int) $inputs['from_branch_id'] === (int) $inputs['to_branch_id'] ) {
            throw ValidationException::withMessages( ['to_branch_id' => __( 'Cannot transfer to the same branch.' )] );
        }

        // Get branches
        $fromBranch = Branch::find( $inputs['from_branch_id'] );
        $toBranch = Branch::find( $inputs['to_branch_id'] );

        // Validate branch-to-branch transfers (must go through main warehouse)
        if ( $fromBranch && $toBranch ) {
            $fromIsWarehouse = $fromBranch->is_main_warehouse;
            $toIsWarehouse = $toBranch->is_main_warehouse;

            // If both are branches (not warehouse), deny direct transfer
            if ( ! $fromIsWarehouse && ! $toIsWarehouse ) {
                throw ValidationException::withMessages( ['to_branch_id' => __( 'Cannot transfer directly between branches. Transfers must go through the main warehouse.' )] );
            }
        }

        return $inputs;
    }

    /**
     * Filter PUT input fields
     */
    public function filterPutInputs( array $inputs, StockTransfer $entry ): array
    {
        return $this->validateTransferInputs( $inputs );
    }

    /**
     * Before saving a new entry
     */
    public function beforePost( array $inputs ): array
    {
        $this->allowedTo( 'create' );

        return $inputs;
    }

    /**
     * After saving a new entry
     */
    public function afterPost( $inputs, StockTransfer $entry )
    {
        return $inputs;
    }

    /**
     * Before updating an entry
     */
    public function beforePut( array $inputs, $entry ): array
    {
        $this->allowedTo( 'update' );

        return $inputs;
    }

    /**
     * After updating an entry
     */
    public function afterPut( $inputs, $entry )
    {
        return $inputs;
    }

    /**
     * Before deleting an entry
     */
    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace == 'ns.stock-transfers' ) {
            $this->allowedTo( 'delete' );
        }
    }

    /**
     * Define columns
     */
    public function getColumns(): array
    {
        return [
            'id' => [
                'label' => __( 'ID' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'product' => [
                'label' => __( 'Product' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'unit' => [
                'label' => __( 'Unit' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'from_branch' => [
                'label' => __( 'From Branch' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'to_branch' => [
                'label' => __( 'To Branch' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'quantity' => [
                'label' => __( 'Quantity' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'status' => [
                'label' => __( 'Status' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'requested_by_name' => [
                'label' => __( 'Requested By' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'approved_by_name' => [
                'label' => __( 'Approved By' ),
                'type' => 'text',
                '$direction' => '',
                '$sort' => false,
            ],
            'created_at' => [
                'label' => __( 'Created At' ),
                'type' => 'datetime',
                '$direction' => '',
                '$sort' => false,
            ],
        ];
    }

    /**
     * Define actions
     */
    public function setActions( CrudEntry $entry ): CrudEntry
    {
        $entry->id = $entry->id;
        $entry->product = $entry->product->name ?? __( 'N/A' );
        $entry->unit = $entry->unit->name ?? __( 'N/A' );
        $entry->from_branch = $entry->fromBranch->name ?? __( 'N/A' );
        $entry->to_branch = $entry->toBranch->name ?? __( 'N/A' );
        $entry->quantity = $entry->quantity;
        $entry->status = $entry->status;
        $entry->requested_by_name = $entry->requestedBy->username ?? __( 'N/A' );
        $entry->approved_by_name = $entry->approvedBy->username ?? __( 'N/A' );
        $entry->created_at = $entry->created_at;

        /**
         * These four were written as $entry->addAction( $identifier, $array ). CrudEntry has no such
         * method and no __call to absorb the difference, so building any row at all threw and the whole
         * page answered 500. It only looked healthy because the table was empty — the crash arrives with
         * the first transfer somebody records.
         *
         * The method is action(), and it takes the fields as arguments rather than an array.
         */
        if ( $entry->status === 'pending' ) {
            /**
             * Approving moves goods from one branch's shelf to another's. It used to be a GOTO, meaning
             * the browser followed a link to a GET route that performed the move, so anything that
             * followed that link — a prefetch, a bookmark, a url pasted into a chat — transferred stock.
             * It is a POST to the api group now, which is where every other row action that changes
             * something already posts.
             */
            $entry->action(
                identifier: 'approve',
                label: __( 'Approve' ),
                type: 'POST',
                url: ns()->url( '/api/stock-transfers/' . $entry->id . '/approve' ),
                confirm: [
                    'message' => __( 'Would you like to approve this transfer?' ),
                ]
            );

            $entry->action(
                identifier: 'cancel',
                label: __( 'Cancel' ),
                type: 'POST',
                url: ns()->url( '/api/stock-transfers/' . $entry->id . '/cancel' ),
                confirm: [
                    'message' => __( 'Would you like to cancel this transfer?' ),
                ]
            );
        }

        if ( $entry->status !== 'pending' ) {
            return $entry;
        }

        $entry->action(
            identifier: 'edit',
            label: __( 'Edit' ),
            type: 'GOTO',
            url: ns()->url( '/dashboard/stock-transfers/edit/' . $entry->id )
        );

        $entry->action(
            identifier: 'delete',
            label: __( 'Delete' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/ns.stock-transfers/' . $entry->id ),
            confirm: [
                'message' => __( 'Would you like to delete this?' ),
            ]
        );

        return $entry;
    }

    /**
     * Bulk Delete Action
     */
    public function bulkAction( Request $request ): array
    {
        if ( $request->input( 'action' ) == 'delete_selected' ) {
            $this->allowedTo( 'delete' );

            DB::transaction( function () use ( $request ) {
                $entries = StockTransfer::whereIn( 'id', $request->input( 'entries' ) )
                    ->orderBy( 'id' )->lockForUpdate()->get();
                $entries->each( function ( $entry ) {
                    $entry->delete();
                } );
            } );

            return [
                'status' => 'success',
                'message' => __( 'The selected stock transfers has been deleted.' ),
            ];
        }

        return Hook::filter( $this->namespace . '-catch-action', false, $request );
    }

    /**
     * Get entries
     */
    public function getEntries( $config = [] ): array
    {
        $this->allowedTo( 'read' );
        $query = StockTransfer::with( [
            'product',
            'unit',
            'fromBranch',
            'toBranch',
            'requestedBy',
            'approvedBy',
        ] )->orderByDesc( 'created_at' )->orderByDesc( 'id' );

        if ( isset( $config['pick'] ) ) {
            $query->whereIn( 'id', $config['pick'] );
        }

        $perPage = $config['per_page'] ?? request()->query( 'per_page', 10 );
        if ( $perPage === false ) {
            $models = $query->get();
            $result = [];
        } else {
            $paginator = $query->paginate( max( 1, min( 200, (int) $perPage ) ) );
            $models = $paginator->getCollection();
            $result = $paginator->toArray();
        }

        $result['data'] = $models->map( function ( StockTransfer $transfer ) {
            $entry = new CrudEntry( array_merge( $transfer->getAttributes(), $transfer->getRelations() ) );
            $this->setActions( $entry );
            foreach ( ['fromBranch', 'toBranch', 'requestedBy', 'approvedBy'] as $relation ) {
                unset( $entry->$relation );
            }

            return $entry->toArray();
        } )->all();

        return $result;
    }

    /**
     * Get form for creating a new entry
     */
    public function getForm( $entry = null ): array
    {
        $products = Product::get()->map( function ( $product ) {
            return [
                'label' => $product->name,
                'value' => $product->id,
            ];
        } );

        $units = Unit::get()->map( function ( $unit ) {
            return [
                'label' => $unit->name,
                'value' => $unit->id,
            ];
        } );

        // All locations (warehouse + branches)
        $allLocations = Branch::get()->map( function ( $branch ) {
            $prefix = $branch->is_main_warehouse ? '🏭 ' : '🏪 ';

            return [
                'label' => $prefix . $branch->name,
                'value' => $branch->id,
            ];
        } );

        return CrudForm::form(
            tabs: CrudForm::tabs(
                CrudForm::tab(
                    identifier: 'general',
                    label: __( 'รายละเอียดการโอนสินค้า' ),
                    fields: [
                        [
                            'type' => 'search-select',
                            'name' => 'product_id',
                            'label' => __( 'สินค้า' ),
                            'validation' => 'required',
                            'options' => $products,
                            'value' => $entry->product_id ?? null,
                            'description' => __( 'ค้นหาและเลือกสินค้าที่ต้องการโอน บันทึกแล้วจะรออนุมัติ สต๊อกจะย้ายเมื่อกดอนุมัติในรายการโอนเท่านั้น' ),
                        ],
                        [
                            'type' => 'select',
                            'name' => 'unit_id',
                            'label' => __( 'หน่วยสินค้า' ),
                            'validation' => 'required',
                            'options' => $units,
                            'value' => $entry->unit_id ?? null,
                            'description' => __( 'เลือกหน่วยที่สินค้านี้มีสต๊อกในสาขาต้นทาง จำนวนที่จะโอนนับตามหน่วยนี้ เช่น ชิ้นหรือกล่อง' ),
                        ],
                        [
                            'type' => 'select',
                            'name' => 'from_branch_id',
                            'label' => __( 'สาขาต้นทาง' ),
                            'validation' => 'required',
                            'options' => $allLocations,
                            'value' => $entry->from_branch_id ?? null,
                            'description' => __( 'สถานที่ที่นำสินค้าออก 🏭 = คลังหลัก 🏪 = สาขา' ),
                        ],
                        [
                            'type' => 'select',
                            'name' => 'to_branch_id',
                            'label' => __( 'สาขาปลายทาง' ),
                            'validation' => 'required',
                            'options' => $allLocations,
                            'value' => $entry->to_branch_id ?? null,
                            'description' => __( 'สถานที่รับสินค้า ต้องต่างจากต้นทาง ระบบกำหนดให้โอนผ่านคลังหลัก ไม่โอนตรงระหว่างสองสาขา' ),
                        ],
                        [
                            'type' => 'number',
                            'name' => 'quantity',
                            'label' => __( 'จำนวนที่โอน' ),
                            'validation' => 'required|numeric|min:0.01',
                            'value' => $entry->quantity ?? '',
                            'description' => __( 'ระบุจำนวนตามหน่วยที่เลือก ระบบตรวจสต๊อกต้นทางอีกครั้งตอนอนุมัติ' ),
                        ],
                        [
                            'type' => 'textarea',
                            'name' => 'notes',
                            'label' => __( 'หมายเหตุ' ),
                            'validation' => 'nullable',
                            'value' => $entry->notes ?? '',
                            'description' => __( 'ระบุเหตุผลหรือรายละเอียดเพิ่มเติม (ไม่บังคับ)' ),
                        ],
                    ],
                ),
            ),
        );
    }

    /**
     * Hook for returning the form
     */
    public function hook( $query ): void
    {
        $query->with( [
            'product',
            'unit',
            'fromBranch',
            'toBranch',
            'requestedBy',
            'approvedBy',
        ] );
    }
}

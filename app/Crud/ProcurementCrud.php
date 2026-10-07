<?php

namespace App\Crud;

use App\Exceptions\NotAllowedException;
use App\Models\Procurement;
use App\Models\User;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\ProviderService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use TorMorten\Eventy\Facades\Events as Hook;

class ProcurementCrud extends CrudService
{
    /**
     * Define the autoload status
     */
    const AUTOLOAD = true;

    /**
     * Define the identifier
     */
    const IDENTIFIER = 'ns.procurements';

    /**
     * define the base table
     */
    protected $table = 'nexopos_procurements';

    /**
     * Define namespace
     *
     * @param  string
     */
    protected $namespace = 'ns.procurements';

    /**
     * Model Used
     */
    protected $model = Procurement::class;

    /**
     * Adding relation
     */
    /**
     * Both joins are left joins so that a purchase order is never dropped from the list.
     *
     * They were inner joins, which meant a procurement whose provider_id resolved to no row — the
     * bulk stock importer stores a zero — vanished from the screen entirely while its stock lines and
     * its totals stayed in the accounts. The same applied to the author: removing a member of staff
     * took every purchase order they had recorded off the list with them. A list that quietly omits
     * records is worse than one that shows them with a blank supplier cell, because nothing tells the
     * shop that anything is missing.
     */
    public $relations = [
        'leftJoin' => [
            [User::class, 'user'],
            ['nexopos_providers as providers', 'nexopos_procurements.provider_id', '=', 'providers.id'],
        ],
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
     * define permission
     */
    public $permissions = [
        'create' => 'nexopos.create.procurements',
        'read' => 'nexopos.read.procurements',
        'update' => false,
        'delete' => 'nexopos.delete.procurements',
    ];

    /**
     * @var ProviderService
     */
    protected $providerService;

    /**
     * Define Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->providerService = app()->make( ProviderService::class );
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
            'list_title' => __( 'Procurements List' ),
            'list_description' => __( 'Display all procurements.' ),
            'no_entry' => __( 'No procurements has been registered' ),
            'create_new' => __( 'Add a new procurement' ),
            'create_title' => __( 'Create a new procurement' ),
            'create_description' => __( 'Register a new procurement and save it.' ),
            'edit_title' => __( 'Edit procurement' ),
            'edit_description' => __( 'Modify  Procurement.' ),
            'back_to_list' => __( 'Return to Procurements' ),
        ];
    }

    public function hook( $query ): void
    {
        $query->whereNull( 'nexopos_procurements.trial_archived_at' );
        if ( auth()->check() && ! ns()->hasRole( 'admin' ) ) {
            $query->where( function ( $q ): void {
                $q->where(fn ($legacy) => $legacy->whereNull('nexopos_procurements.purchase_order_id')->whereNull('nexopos_procurements.receipt_source'))->orWhere( 'nexopos_procurements.branch_id', auth()->user()->branch_id ?: -1 );
            } );
        }
        /**
         * should not block default
         * crud sorting
         */
        if ( ! request()->query( 'active' ) && ! request()->query( 'direction' ) ) {
            $query->orderBy( 'created_at', 'desc' );
        }
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
     * Fields
     *
     * @param  object/null
     * @return array of field
     */
    public function getForm( $entry = null )
    {
        return [
            'main' => [
                'label' => __( 'Name' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'description' => __( 'Provide a name to the resource.' ),
            ],
            'tabs' => [
                'general' => [
                    'label' => __( 'General' ),
                    'fields' => [
                        [
                            'type' => 'text',
                            'name' => 'author_id',
                            'label' => __( 'Author' ),
                            'value' => $entry->author_id ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'created_at',
                            'label' => __( 'Created At' ),
                            'value' => $entry->created_at ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'description',
                            'label' => __( 'Description' ),
                            'value' => $entry->description ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'id',
                            'label' => __( 'Id' ),
                            'value' => $entry->id ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'name',
                            'label' => __( 'Name' ),
                            'value' => $entry->name ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'provider_id',
                            'label' => __( 'Provider Id' ),
                            'value' => $entry->provider_id ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'status',
                            'label' => __( 'Status' ),
                            'value' => $entry->status ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'total_items',
                            'label' => __( 'Total Items' ),
                            'value' => $entry->total_items ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'updated_at',
                            'label' => __( 'Updated At' ),
                            'value' => $entry->updated_at ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'uuid',
                            'label' => __( 'Uuid' ),
                            'value' => $entry->uuid ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'value',
                            'label' => __( 'Value' ),
                            'value' => $entry->value ?? '',
                        ],                     ],
                ],
            ],
        ];
    }

    /**
     * Filter POST input fields
     *
     * @param  array of fields
     * @return array of fields
     */
    public function filterPostInputs( $inputs )
    {
        if ( empty( $inputs['invoice_date'] ) ) {
            $inputs['invoice_date'] = ns()->date->toDateTimeString();
        }

        return $inputs;
    }

    /**
     * Filter PUT input fields
     *
     * @param  array of fields
     * @return array of fields
     */
    public function filterPutInputs( $inputs, Procurement $entry )
    {
        if ( empty( $inputs['invoice_date'] ) ) {
            $inputs['invoice_date'] = ns()->date->toDateTimeString();
        }

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
    public function afterPost( $request, Procurement $entry )
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
    public function afterPut( $request, $entry )
    {
        return $request;
    }

    /**
     * Before Delete
     *
     * @return void
     */
    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace == 'ns.procurements' ) {
            //
        }
    }

    /**
     * Define Columns
     */
    public function getColumns(): array
    {
        /**
         * Ordered to match the way the shop reads the paper register: the documents first, then who
         * they came from, then the payment terms, then the money.
         *
         * `providers_code` comes free from the existing left join on nexopos_providers — the crud
         * service flattens a joined column as {alias}_{column}. The staff quote the supplier code, not
         * the registered name, and several of those names read almost alike.
         *
         * `net_goods_value` has no column behind it. It is set in setActions() from cost minus tax,
         * because cost is the tax-inclusive total: ns-procurement.vue sets each line's purchase_price
         * to the gross price under both tax types, so the sum of total_purchase_price already carries
         * the tax. Sorting is therefore off on it, as it is on every column here.
         */
        return [
            'name' => [
                'label' => __( 'รายการ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'billing_note_number' => [
                'label' => __( 'ใบวางบิล' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'billing_note_date' => [
                'label' => __( 'วันวางบิล' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'delivery_note_number' => [
                'label' => __( 'ใบส่งของ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'delivery_note_date' => [
                'label' => __( 'วันส่งของ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'purchase_order_number' => [
                'label' => __( 'ใบสั่งซื้อ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'providers_code' => [
                'label' => __( 'รหัสผู้ขาย' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'providers_first_name' => [
                'label' => __( 'ชื่อบริษัท' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'contact_person' => [
                'label' => __( 'ผู้ติดต่อ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'credit_days' => [
                'label' => __( 'เครดิต' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'due_date' => [
                'label' => __( 'ครบกำหนด' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'net_goods_value' => [
                'label' => __( 'มูลค่าสุทธิ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'tax_value' => [
                'label' => __( 'ภาษี' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'cost' => [
                'label' => __( 'รวมเงิน' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'delivery_status' => [
                'label' => __( 'รับของ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'payment_status' => [
                'label' => __( 'ชำระเงิน' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'invoice_date' => [
                'label' => __( 'วันใบกำกับ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'user_username' => [
                'label' => __( 'ผู้บันทึก' ),
                '$direction' => '',
                '$sort' => false,
            ],
        ];
    }

    /**
     * Keeps a short value on one line, optionally against the right edge.
     *
     * Every column here is its own column again, because pairing two values into one cell read as
     * though half the register had gone missing. What made eighteen columns unreadable was not the
     * count but the wrapping: the headings and the values were both allowed to break anywhere, and
     * Thai has no spaces for a browser to break on, so it broke mid-word — `เลขที่ใบวางบิล` came out
     * as four stacked fragments.
     *
     * Values that are meant to be read as one token — a date, a document number, an amount — are
     * therefore pinned to a single line here, and the headings are pinned in ns-crud.vue. Only the
     * two genuinely long free-text columns, the record name and the company name, are still allowed
     * to wrap, because those are sentences rather than tokens.
     */
    private function oneLine( ?string $value, bool $alignRight = false ): string
    {
        $value = trim( (string) $value );

        if ( $value === '' ) {
            return '';
        }

        return '<div class="whitespace-nowrap' . ( $alignRight ? ' text-right' : '' ) . '">' . e( $value ) . '</div>';
    }

    /**
     * A date as the shop writes it, and blank when there is no date.
     *
     * Carbon::parse would raise on a malformed value and take the whole list down with it, so a value
     * it cannot read is shown as it was stored instead. A list that renders one odd cell is worth
     * more than a list that will not render.
     */
    private function shortDate( ?string $date ): string
    {
        if ( empty( $date ) ) {
            return '';
        }

        try {
            return Carbon::parse( $date )->format( 'd/m/Y' );
        } catch ( Exception ) {
            return (string) $date;
        }
    }

    /**
     * Define actions
     */
    public function setActions( CrudEntry $entry ): CrudEntry
    {
        /**
         * Kept before the labels are swapped in. The Set Paid action below compares against
         * Procurement::PAYMENT_PAID, and the comparison used to run after payment_status had already
         * been replaced by its Thai label — so it never matched and "Set Paid" was offered on
         * purchases that were already settled.
         */
        $rawPaymentStatus = $entry->payment_status;
        $isStocked = $entry->delivery_status === Procurement::STOCKED;

        /**
         * Read before cost is replaced by its formatted string below. cost is the tax-inclusive
         * total, so the net value of the goods is what is left once the tax is taken off.
         */
        $netGoodsValue = (float) $entry->cost - (float) $entry->tax_value;

        $deliveryLabel = $this->providerService->getDeliveryStatusLabel( $entry->delivery_status );
        $paymentLabel = $this->providerService->getPaymentStatusLabel( $rawPaymentStatus );

        $entry->value = ns()
            ->currency
            ->define( $entry->value )
            ->format();

        /**
         * A document that has not arrived yet prints blank, not as a dash or a zero: the purchase is
         * raised before its delivery note and its billing note exist, and a dash reads as "there
         * isn't one" while a blank reads as "not yet".
         *
         * Zero credit days means cash on delivery, written as an empty cell rather than as a nought,
         * the same way the register this is modelled on does.
         */
        $creditDays = (int) $entry->credit_days > 0 ? (int) $entry->credit_days : null;

        /**
         * The record name and the company name are left as plain text so they may wrap: those two are
         * the only free-text columns here and pinning a supplier's registered name to one line would
         * push the table wider than any screen on its own.
         */
        foreach ( ['billing_note_number', 'delivery_note_number', 'purchase_order_number', 'contact_person'] as $field ) {
            $entry->$field = $this->oneLine( $entry->$field );
        }

        foreach ( ['billing_note_date', 'delivery_note_date', 'due_date', 'invoice_date'] as $field ) {
            $entry->$field = $this->oneLine( $this->shortDate( $entry->$field ) );
        }

        $entry->providers_code = $this->oneLine( $entry->providers_code ?: __( 'ไม่มีรหัส' ) );
        $entry->credit_days = $this->oneLine( $creditDays === null ? '' : (string) $creditDays );
        $entry->user_username = $this->oneLine( $entry->user_username );

        $entry->net_goods_value = $this->oneLine( ns()->currency->define( max( 0, $netGoodsValue ) )->format(), true );
        $entry->tax_value = $this->oneLine( ns()->currency->define( $entry->tax_value )->format(), true );
        $entry->cost = $this->oneLine( ns()->currency->define( $entry->cost )->format(), true );

        $entry->delivery_status = $this->oneLine( $deliveryLabel );
        $entry->payment_status = $this->oneLine( $paymentLabel );

        $entry->action(
            identifier: 'edit',
            label: __( 'Edit' ),
            type: 'GOTO',
            url: $isStocked ? route( 'ns.purchase-paperwork', $entry->id ) : ns()->url( '/dashboard/' . 'procurements' . '/edit/' . $entry->id )
        );

        $entry->action( identifier: 'paperwork', label: 'แก้ไขเลขที่ / วันที่เอกสาร', type: 'GOTO', url: route( 'ns.purchase-paperwork', $entry->id ) );

        $entry->action(
            identifier: 'invoice',
            label: __( 'ดูใบรับของ' ),
            type: 'GOTO',
            url: ns()->url( '/dashboard/' . 'procurements' . '/edit/' . $entry->id . '/invoice' )
        );

        /**
         * Opens in a tab and prints itself, the same way the billing note does. The receiving desk
         * wants paper in its hand to walk to the shelves with; making them open the document and then
         * find a Print button is a step that gets skipped.
         */
        $entry->action(
            identifier: 'print',
            label: __( 'พิมพ์ใบรับของ' ),
            type: 'TAB',
            url: ns()->url( '/dashboard/procurements/edit/' . $entry->id . '/invoice?dash-visibility=disabled&autoprint=true' )
        );

        /**
         * if the procurement payment status
         * is not paid, we can display new option for making a payment
         */
        if ( $rawPaymentStatus !== Procurement::PAYMENT_PAID ) {
            /**
             * POST, not GET. Both of these actions change the purchase — one settles it, the other rewrites
             * its totals — and a GET is reachable by anything that follows a link.
             */
            $entry->action(
                identifier: 'set_paid',
                label: 'สร้างใบสำคัญจ่าย / ยืนยันชำระ',
                type: 'GOTO',
                url: ns()->route( 'ns.payment-vouchers', ['provider_id' => $entry->provider_id, 'branch_id' => $entry->branch_id ?: session( 'selected_branch_id' )] )
            );
        }

        $entry->action(
            identifier: 'refresh',
            label: __( 'Refresh' ),
            type: 'POST',
            url: ns()->url( '/api/procurements/' . $entry->id . '/refresh' ),
            confirm: [
                'message' => __( 'Would you like to refresh this ?' ),
            ]
        );

        $entry->action(
            identifier: 'delete',
            label: __( 'Delete' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/ns.procurements/' . $entry->id ),
            confirm: [
                'message' => __( 'Would you like to delete this ?' ),
            ]
        );

        return $entry;
    }

    /**
     * Bulk Delete Action
     *
     * @param    object Request with object
     * @return false/array
     */
    public function bulkAction( Request $request )
    {
        if ( $request->input( 'action' ) == 'delete_selected' ) {
            /**
             * Will control if the user has the permissoin to do that.
             */
            if ( $this->permissions['delete'] !== false ) {
                ns()->restrict( $this->permissions['delete'] );
            } else {
                throw new NotAllowedException;
            }

            $status = [
                'success' => 0,
                'error' => 0,
            ];

            foreach ( $request->input( 'entries' ) as $id ) {
                $entity = $this->model::find( $id );
                if ( $entity instanceof Procurement ) {
                    $entity->delete();
                    $status['success']++;
                } else {
                    $status['error']++;
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
            'list' => 'procurements',
            'create' => 'procurements/create',
            'edit' => 'procurements/edit',
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
}

<?php

namespace App\Crud;

use App\Casts\CurrencyCast;
use App\Casts\DateCast;
use App\Classes\CrudTable;
use App\Classes\Hook;
use App\Exceptions\NotAllowedException;
use App\Models\BillingNote;
use App\Models\User;
use App\Services\CrudEntry;
use App\Services\CrudService;
use Illuminate\Http\Request;

/**
 * The register of provisional billing notes (ใบแจ้งหนี้/วางบิลค่าสินค้า ชั่วคราว).
 *
 * Read only by design. A note is raised from the issuing page, where the clerk picks the
 * customer and ticks the deliveries that have fallen due — there is no sensible way to
 * assemble one through a generic CRUD form, and no way to edit one afterwards: the customer
 * is holding a signed copy and the figures on it are what the payment gets reconciled
 * against. So this table exists to find a note, reprint it, and withdraw it.
 */
class BillingNoteCrud extends CrudService
{
    /**
     * Define the autoload status
     */
    const AUTOLOAD = true;

    /**
     * Define the identifier
     */
    const IDENTIFIER = 'ns.billing-notes';

    /**
     * define the base table
     */
    protected $table = 'nexopos_billing_notes';

    /**
     * base route name
     */
    protected $mainRoute = 'ns.billing-notes';

    /**
     * Define namespace
     */
    protected $namespace = 'ns.billing-notes';

    /**
     * Model Used
     */
    protected $model = BillingNote::class;

    protected $slug = 'orders/billing-notes';

    /**
     * Adding relation
     */
    public $relations = [
        'join' => [
            [ User::class, 'user' ],
        ],
    ];

    public $pick = [
        'user' => [ 'username' ],
    ];

    /**
     * Creating is pointed at the issuing page rather than a CRUD form, and updating and
     * deleting are refused outright: a note that has been left at a customer's accounts desk
     * cannot be quietly restated, and its number must stay consumed so a payment can always
     * be traced back to the sheet it was matched against.
     */
    protected $permissions = [
        'create' => 'nexopos.create.billing-notes',
        'read' => 'nexopos.read.billing-notes',
        'update' => false,
        'delete' => false,
    ];

    protected $listWhere = [];

    protected $whereIn = [];

    public $fillable = [];

    /**
     * Dates are cast so the register reads in the shop's configured format rather than as a
     * raw timestamp. DateCast passes null straight through, which is what leaves an unset
     * appointment date free to be labelled in setActions.
     */
    protected $casts = [
        'issued_at' => DateCast::class,
        'appointment_date' => DateCast::class,
        'total_billed' => CurrencyCast::class,
        'total_deducted' => CurrencyCast::class,
        'total_net' => CurrencyCast::class,
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function getLabels()
    {
        return [
            'list_title' => __( 'ทะเบียนใบวางบิล' ),
            'list_description' => __( 'ใบแจ้งหนี้/วางบิลค่าสินค้า (ชั่วคราว) ที่ออกไปแล้ว สำหรับค้นหา พิมพ์ซ้ำ และยกเลิก' ),
            'no_entry' => __( 'ยังไม่มีการออกใบวางบิล' ),
            'create_new' => __( 'ออกใบวางบิล' ),
            'create_title' => __( 'ออกใบวางบิล' ),
            'create_description' => __( 'เลือกลูกค้าและบิลที่ต้องการวางบิล' ),
            'edit_title' => __( 'ใบวางบิล' ),
            'edit_description' => __( 'แก้ไขใบวางบิลที่ออกแล้วไม่ได้' ),
            'back_to_list' => __( 'กลับไปทะเบียนใบวางบิล' ),
        ];
    }

    public function isEnabled( $feature ): bool
    {
        return false;
    }

    public function get( $param )
    {
        switch ( $param ) {
            case 'model': return $this->model;
                break;
        }
    }

    public function hook( $query ): void
    {
        $query->orderBy( 'id', 'desc' );
    }

    /**
     * A note handed to a customer, whose number is consumed and whose deliveries are marked
     * as billed, is not deletable. Cancelling is the reversal, and it keeps the number and
     * releases the deliveries.
     */
    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace === self::IDENTIFIER ) {
            throw new NotAllowedException(
                __( 'ลบใบวางบิลไม่ได้ ให้ใช้คำสั่ง "ยกเลิก" แทน เพื่อให้เลขที่เอกสารไม่หายไปจากชุด และบิลในใบนั้นกลับมาวางบิลได้อีก' )
            );
        }
    }

    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column( __( 'เลขที่' ), 'number' ),
            CrudTable::column( __( 'วันที่' ), 'issued_at' ),
            CrudTable::column( __( 'ลูกค้า' ), 'customer_name' ),
            CrudTable::column( __( 'รหัส' ), 'customer_code' ),
            /**
             * Not a column on the table — it is counted out of the frozen snapshot in
             * setActions. Sorting is switched off because the CRUD would build an ORDER BY
             * against a column that does not exist.
             */
            CrudTable::column( __( 'จำนวนบิล' ), 'documents_count', sort: false ),
            CrudTable::column( __( 'ที่วางบิลรวม' ), 'total_billed' ),
            CrudTable::column( __( 'หักตามหมายเหตุ' ), 'total_deducted' ),
            CrudTable::column( __( 'รับวางบิลสุทธิ' ), 'total_net' ),
            CrudTable::column( __( 'นัดชำระวันที่' ), 'appointment_date' ),
            CrudTable::column( __( 'สถานะ' ), 'status' ),
            CrudTable::column( __( 'ผู้ออก' ), 'user_username' ),
        );
    }

    public function setActions( CrudEntry $entry ): CrudEntry
    {
        /**
         * Read through getRawValue rather than __raw: the CRUD only mirrors a column into
         * __raw when that column has a cast declared, and neither of these has one. Reading
         * the original also means the status check is unaffected by the label written over
         * $entry->status a few lines down.
         */
        $isCancelled = $entry->getRawValue( 'status' ) === BillingNote::STATUS_CANCELLED;

        /**
         * The number of deliveries on the sheet, counted out of the frozen snapshot rather
         * than against the orders table, so a note still reports what it printed even after
         * one of those orders has been deleted.
         */
        $lines = json_decode( (string) $entry->getRawValue( 'lines' ), true );
        $entry->documents_count = is_array( $lines ) ? count( $lines ) : 0;

        $entry->status = $isCancelled ? __( 'ยกเลิกแล้ว' ) : __( 'ใช้งาน' );
        $entry->appointment_date = $entry->appointment_date ?: __( 'ยังไม่ได้นัด' );

        $entry->{'$cssClass'} = $isCancelled ? 'error border text-sm' : '';

        $entry->action(
            identifier: 'print',
            label: '<i class="mr-2 las la-print"></i> ' . __( 'พิมพ์' ),
            type: 'TAB',
            url: ns()->url( '/dashboard/orders/billing-note/' . $entry->id . '?dash-visibility=disabled&autoprint=true' ),
            permissions: [ 'nexopos.read.billing-notes' ],
        );

        if ( ! $isCancelled ) {
            /**
             * A popup rather than a confirm dialog, because cancelling requires a written
             * reason: the service refuses a blank one, and a plain confirm has nowhere to
             * type it.
             */
            $entry->action(
                identifier: 'ns.billing-note-cancel',
                label: '<i class="mr-2 las la-ban"></i> ' . __( 'ยกเลิก' ),
                type: 'POPUP',
                url: ns()->url( '/api/billing-notes/' . $entry->id . '/cancel' ),
                permissions: [ 'nexopos.cancel.billing-notes' ],
            );
        }

        return $entry;
    }

    /**
     * No bulk action. Cancelling releases deliveries back into the billable pool, and doing
     * that to a checkbox selection is how a customer ends up billed twice.
     */
    public function bulkAction( Request $request )
    {
        return Hook::filter( $this->namespace . '-catch-action', false, $request );
    }

    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [] );
    }

    public function getLinks(): array
    {
        /**
         * edit, post and put are deliberately empty rather than pointing anywhere: there is
         * no form behind this register. CrudTable::links() types them as strings, so they are
         * blanked instead of nulled.
         */
        return CrudTable::links(
            list: ns()->url( 'dashboard/orders/billing-notes' ),

            /**
             * The "create" button leads to the issuing page. A billing note is assembled from
             * a customer's outstanding deliveries, which is a selection rather than a set of
             * fields.
             */
            create: ns()->url( 'dashboard/orders/billing-notes/create' ),
            edit: '',
            post: '',
            put: '',
        );
    }

    public function getExports()
    {
        return [];
    }
}

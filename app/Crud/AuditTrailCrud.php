<?php

namespace App\Crud;

use App\Exceptions\NotAllowedException;
use App\Models\AuditTrail;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use Illuminate\Http\Request;
use TorMorten\Eventy\Facades\Events as Hook;

/**
 * ตรวจสอบการใช้งานย้อนหลัง — /dashboard/audit-trail.
 *
 * Read only in the strongest sense available here: every write permission is false, which makes
 * CrudService refuse a create, an update and a delete, and no row action or bulk action offers any
 * of them. An audit trail that the people being audited can edit is not an audit trail, and the
 * shop owner has to be able to point at a row and say it has not been touched.
 *
 * Pruning old rows is therefore deliberately not a button on this screen. If the table ever needs
 * trimming it is done from the command line, by somebody with server access, and that is the right
 * bar for destroying an audit record.
 *
 * No joins. The user's name and the branch's name are stored on the row itself, so a staff member
 * who has since been deleted still reads as a name instead of as a dangling id.
 */
class AuditTrailCrud extends CrudService
{
    const AUTOLOAD = true;

    const IDENTIFIER = 'ns.audit-trail';

    protected $table = 'nexopos_audit_trails';

    protected $slug = '/audit-trail';

    protected $mainRoute = 'ns.audit-trail';

    protected $namespace = 'ns.audit-trail';

    protected $model = AuditTrail::class;

    protected $permissions = [
        'create' => false,
        'read' => 'nexopos.read.audit-trail',
        'update' => false,
        'delete' => false,
    ];

    /**
     * Nothing can be done to a row, so the options column and the tick boxes would only offer
     * actions that are refused.
     */
    protected $showOptions = false;

    protected $showCheckboxes = false;

    public $relations = [];

    public $pick = [];

    protected $listWhere = [];

    protected $whereIn = [];

    public $fillable = [];

    public $skippable = [];

    protected $prependOptions = false;

    public function __construct()
    {
        parent::__construct();

        /**
         * The three questions this screen gets asked: what happened on a given day, what did one
         * person do, and what happened to one part of the system.
         */
        $this->queryFilters = [
            [
                'type' => 'daterangepicker',
                'name' => 'nexopos_audit_trails.created_at',
                'description' => __( 'จำกัดช่วงวันเวลาที่เกิดเหตุการณ์' ),
                'label' => __( 'ช่วงวันที่' ),
            ], [
                'type' => 'select',
                'name' => 'action',
                'description' => __( 'จำกัดเฉพาะประเภทการกระทำที่เลือก' ),
                'label' => __( 'การกระทำ' ),
                'options' => Helper::kvToJsOptions( AuditTrail::actionOptions() ),
            ],
        ];
    }

    public function getLabels()
    {
        return [
            'list_title' => __( 'ตรวจสอบการใช้งานย้อนหลัง' ),
            'list_description' => __( 'บันทึกว่าใครทำอะไรในระบบ เมื่อไหร่ และจากเครื่องไหน ใช้ตรวจย้อนหลังเมื่อข้อมูลเปลี่ยนไปโดยไม่ทราบสาเหตุ บันทึกนี้แก้ไขและลบจากหน้าจอไม่ได้' ),
            'no_entry' => __( 'ยังไม่มีบันทึกการใช้งาน' ),
            'create_new' => __( 'ไม่สามารถเพิ่มบันทึกได้' ),
            'create_title' => __( 'ไม่สามารถเพิ่มบันทึกได้' ),
            'create_description' => __( 'บันทึกการใช้งานถูกสร้างโดยระบบเท่านั้น' ),
            'edit_title' => __( 'ไม่สามารถแก้ไขบันทึกได้' ),
            'edit_description' => __( 'บันทึกการใช้งานแก้ไขไม่ได้' ),
            'back_to_list' => __( 'กลับไปหน้าบันทึกการใช้งาน' ),
        ];
    }

    public function isEnabled( $feature ): bool
    {
        return false;
    }

    public function getForm( $entry = null )
    {
        return [
            // nothing is editable here, on purpose
        ];
    }

    public function filterPostInputs( $inputs )
    {
        throw new NotAllowedException( __( 'บันทึกการใช้งานถูกสร้างโดยระบบเท่านั้น เพิ่มด้วยมือไม่ได้' ) );
    }

    public function filterPutInputs( $inputs, AuditTrail $entry )
    {
        throw new NotAllowedException( __( 'บันทึกการใช้งานแก้ไขไม่ได้' ) );
    }

    public function beforePost( $request )
    {
        throw new NotAllowedException( __( 'บันทึกการใช้งานถูกสร้างโดยระบบเท่านั้น เพิ่มด้วยมือไม่ได้' ) );
    }

    public function beforePut( $request, $entry )
    {
        throw new NotAllowedException( __( 'บันทึกการใช้งานแก้ไขไม่ได้' ) );
    }

    public function beforeDelete( $namespace, $id, $model )
    {
        throw new NotAllowedException( __( 'บันทึกการใช้งานลบไม่ได้ เพื่อให้ตรวจย้อนหลังได้จริง' ) );
    }

    public function get( $param )
    {
        switch ( $param ) {
            case 'model': return $this->model;
                break;
        }
    }

    public function getColumns(): array
    {
        return [
            'created_at' => [
                'label' => __( 'เมื่อไหร่' ),
                '$direction' => '',
                'width' => '160px',
                '$sort' => false,
            ],
            'user_name' => [
                'label' => __( 'ใคร' ),
                '$direction' => '',
                'width' => '200px',
                '$sort' => false,
            ],
            'action' => [
                'label' => __( 'ทำอะไร' ),
                '$direction' => '',
                'width' => '120px',
                '$sort' => false,
            ],
            'resource_label' => [
                'label' => __( 'เรื่อง' ),
                '$direction' => '',
                'width' => '200px',
                '$sort' => false,
            ],
            'record_label' => [
                'label' => __( 'รายการ' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'branch_name' => [
                'label' => __( 'สาขา' ),
                '$direction' => '',
                'width' => '160px',
                '$sort' => false,
            ],
            'ip_address' => [
                'label' => __( 'เครื่อง (IP)' ),
                '$direction' => '',
                'width' => '140px',
                '$sort' => false,
            ],
        ];
    }

    public function setActions( CrudEntry $entry ): CrudEntry
    {
        $entry->action = AuditTrail::actionLabel( $entry->action );

        /**
         * A row whose resource label was never resolved still has to say something, so the
         * identifier stands in rather than leaving an empty cell that reads as missing data.
         */
        $entry->resource_label = $entry->resource_label ?: $entry->resource;
        $entry->record_label = $entry->record_label ?: ( $entry->record_id ? '#' . $entry->record_id : __( 'ไม่ระบุ' ) );
        $entry->branch_name = $entry->branch_name ?: __( 'ทุกสาขา / ไม่ระบุ' );
        $entry->user_name = $entry->user_name ?: __( 'ระบบ' );
        $entry->ip_address = $entry->ip_address ?: __( 'ไม่ระบุ' );

        return $entry;
    }

    /**
     * Newest first. An audit trail is read from the most recent event backwards, which is the
     * order somebody investigating "what just happened" needs.
     */
    public function hook( $query ): void
    {
        $query->orderBy( 'nexopos_audit_trails.id', 'desc' );
    }

    public function bulkAction( Request $request )
    {
        throw new NotAllowedException( __( 'บันทึกการใช้งานลบไม่ได้ เพื่อให้ตรวจย้อนหลังได้จริง' ) );
    }

    public function getLinks(): array
    {
        return [
            'list' => ns()->url( 'dashboard/audit-trail' ),
            'create' => false,
            'edit' => false,
            'post' => false,
            'put' => false,
        ];
    }

    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [] );
    }

    public function getExports()
    {
        return [];
    }
}

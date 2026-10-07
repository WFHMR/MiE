<?php

namespace App\Crud;

use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\Register;
use App\Models\User;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use TorMorten\Eventy\Facades\Events as Hook;

class BranchCrud extends CrudService
{
    /**
     * Define the autoload status
     */
    const AUTOLOAD = true;

    /**
     * Define the identifier
     */
    const IDENTIFIER = 'ns.branches';

    /**
     * define the base table
     */
    protected $table = 'nexopos_branches';

    /**
     * default slug
     */
    protected $slug = 'branches';

    /**
     * Define namespace
     */
    protected $namespace = 'ns.branches';

    /**
     * Model Used
     */
    protected $model = Branch::class;

    /**
     * Define permissions
     */
    protected $permissions = [
        'create' => 'nexopos.create.branches',
        'read' => 'nexopos.read.branches',
        'update' => 'nexopos.update.branches',
        'delete' => 'nexopos.delete.branches',
    ];

    /**
     * Adding relation
     */
    public $relations = [
        'leftJoin' => [
            [User::class, 'author'],
        ],
    ];

    /**
     * Pick
     */
    public $pick = [
        'author' => ['username'],
    ];

    /**
     * Define where statement
     */
    protected $listWhere = [];

    /**
     * Define where in statement
     */
    protected $whereIn = [];

    /**
     * Fields which will be filled during post/put
     */
    public $fillable = [];

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
    public function getLabels()
    {
        return [
            'list_title' => __( 'Branches List' ),
            'list_description' => __( 'Display all branches.' ),
            'no_entry' => __( 'No branches has been registered' ),
            'create_new' => __( 'Add a new branch' ),
            'create_title' => __( 'Create a new branch' ),
            'create_description' => __( 'Register a new branch and save it.' ),
            'edit_title' => __( 'Edit branch' ),
            'edit_description' => __( 'Modify Branch.' ),
            'back_to_list' => __( 'Return to Branches' ),
        ];
    }

    /**
     * Check whether a feature is enabled
     */
    public function isEnabled( $feature ): bool
    {
        return false; // by default
    }

    /**
     * Fields
     */
    public function getForm( $entry = null )
    {
        return [
            'main' => [
                'label' => __( 'Branch Name' ),
                'name' => 'name',
                'value' => $entry->name ?? '',
                'description' => __( 'Provide the branch name.' ),
                'validation' => 'required|string|max:255',
            ],
            'tabs' => [
                'general' => [
                    'label' => __( 'General' ),
                    'fields' => [
                        [
                            'type' => 'text',
                            'name' => 'code',
                            'label' => __( 'Branch Code' ),
                            'value' => $entry->code ?? '',
                            'description' => __( 'Unique code for this branch (e.g., BKK01, CNX01).' ),
                            'validation' => 'required|string|max:255|unique:' . Branch::class . ',code' . ( $entry ? ',' . $entry->id : '' ),
                        ],
                        [
                            /**
                             * Not the same thing as the code above, which is the shop's own label.
                             * This is the establishment code the Revenue Department issued, and a tax
                             * invoice has to show the code of the establishment that issued it. Left
                             * empty, the invoice falls back to the install wide setting, which is how
                             * a single establishment shop behaves.
                             */
                            'type' => 'text',
                            'name' => 'tax_branch_code',
                            'label' => __( 'รหัสสาขาสำหรับใบกำกับภาษี' ),
                            'value' => $entry->tax_branch_code ?? '',
                            'validation' => 'nullable|digits:5',
                            'description' => __( 'รหัส 5 หลักตามที่จดทะเบียนภาษีมูลค่าเพิ่ม สำนักงานใหญ่ = 00000 สาขาแรก = 00001 เว้นว่างไว้จะใช้ค่าที่ตั้งไว้ในระบบ' ),
                        ],
                        [
                            'type' => 'textarea',
                            'name' => 'description',
                            'label' => __( 'Description' ),
                            'value' => $entry->description ?? '',
                            'description' => __( 'Provide more details about this branch.' ),
                        ],
                        [
                            'type' => 'textarea',
                            'name' => 'address',
                            'label' => __( 'Address' ),
                            'value' => $entry->address ?? '',
                            'description' => __( 'Branch physical address.' ),
                        ],
                        [
                            'type' => 'text',
                            'name' => 'phone',
                            'label' => __( 'Phone' ),
                            'value' => $entry->phone ?? '',
                            'description' => __( 'Branch contact phone number.' ),
                        ],
                        [
                            'type' => 'text',
                            'name' => 'email',
                            'label' => __( 'Email' ),
                            'value' => $entry->email ?? '',
                            'description' => __( 'Branch contact email address.' ),
                        ],
                        [
                            'type' => 'switch',
                            'name' => 'is_active',
                            'label' => __( 'Active' ),
                            'value' => $entry->is_active ?? true,
                            'description' => __( 'Define whether this branch is active.' ),
                            'options' => Helper::kvToJsOptions( [
                                false => __( 'No' ),
                                true => __( 'Yes' ),
                            ] ),
                        ],
                        [
                            'type' => 'switch',
                            'name' => 'is_main',
                            'label' => __( 'Main Branch' ),
                            'value' => $entry->is_main ?? false,
                            'description' => __( 'Define whether this is the main branch.' ),
                            'options' => Helper::kvToJsOptions( [
                                false => __( 'No' ),
                                true => __( 'Yes' ),
                            ] ),
                        ],
                        [
                            'type' => 'switch',
                            'name' => 'is_main_warehouse',
                            'label' => __( 'Main Warehouse' ),
                            'value' => $entry->is_main_warehouse ?? false,
                            'description' => __( 'Define whether this branch serves as the main warehouse. Stock can only be transferred from warehouse to branches.' ),
                            'options' => Helper::kvToJsOptions( [
                                false => __( 'No' ),
                                true => __( 'Yes' ),
                            ] ),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Filter POST input fields
     */
    public function filterPostInputs( $inputs )
    {
        $inputs['author_id'] = auth()->id();
        $inputs['uuid'] = (string) Str::uuid();

        return $inputs;
    }

    /**
     * Filter PUT input fields
     */
    public function filterPutInputs( $inputs, Branch $entry )
    {
        return $inputs;
    }

    /**
     * Before saving a record
     */
    public function beforePost( $request )
    {
        $this->allowedTo( 'create' );

        return $request;
    }

    /**
     * After saving a record
     */
    /**
     * A branch with no till cannot sell, and creating one used to leave the shop with a row in a
     * table and nothing else — the two artisan commands that finish the job have no screen behind
     * them, so opening a branch meant shell access. One till is created here so the branch is
     * usable the moment it is saved; more can be added from the registers page, which now asks
     * which branch each one belongs to.
     */
    public function afterPost( $request, Branch $entry )
    {
        if ( ! $entry->registers()->exists() ) {
            $register = new Register;
            $register->name = sprintf( __( 'จุดคิดเงิน %s 1' ), $entry->name );
            $register->branch_id = $entry->id;
            $register->status = Register::STATUS_CLOSED;
            $register->description = __( 'สร้างอัตโนมัติพร้อมสาขา แก้ชื่อหรือเพิ่มลิ้นชักได้ที่หน้าจุดคิดเงิน' );
            $register->author_id = auth()->id() ?: $entry->author_id;
            $register->save();
        }

        return $request;
    }

    /**
     * get
     */
    public function get( $param )
    {
        switch ( $param ) {
            case 'model':
                return $this->model;
                break;
        }
    }

    /**
     * Before updating a record
     */
    public function beforePut( $request, $entry )
    {
        $this->allowedTo( 'update' );

        return $request;
    }

    /**
     * After updating a record
     */
    public function afterPut( $request, $entry )
    {
        return $request;
    }

    /**
     * Before Delete
     */
    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace == self::IDENTIFIER ) {
            $this->allowedTo( 'delete' );

            // ป้องกันการลบสาขาหลัก
            if ( $model->is_main ) {
                throw new NotAllowedException( __( 'Unable to delete the main branch.' ) );
            }

            // ตรวจสอบว่ามีเครื่องคิดเงินในสาขานี้หรือไม่
            if ( $model->registers()->count() > 0 ) {
                throw new NotAllowedException( __( 'Unable to delete a branch that has cash registers assigned to it.' ) );
            }
        }
    }

    /**
     * Define Columns
     */
    public function getColumns(): array
    {
        return [
            'code' => [
                'label' => __( 'Code' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'name' => [
                'label' => __( 'Name' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'phone' => [
                'label' => __( 'Phone' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'is_active' => [
                'label' => __( 'Status' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'is_main' => [
                'label' => __( 'Main' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'is_main_warehouse' => [
                'label' => __( 'Warehouse' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'author_username' => [
                'label' => __( 'Author' ),
                '$direction' => '',
                '$sort' => false,
            ],
            'created_at' => [
                'label' => __( 'Created At' ),
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
        $entry->is_active = $entry->is_active ? __( 'Active' ) : __( 'Inactive' );
        $entry->is_main = $entry->is_main ? __( 'Yes' ) : __( 'No' );
        $entry->is_main_warehouse = $entry->is_main_warehouse ? '🏭 ' . __( 'Yes' ) : __( 'No' );

        $entry->action(
            identifier: 'edit',
            label: __( 'Edit' ),
            type: 'GOTO',
            url: ns()->url( '/dashboard/branches/edit/' . $entry->id )
        );

        $entry->action(
            identifier: 'delete',
            label: __( 'Delete' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/' . self::IDENTIFIER . '/' . $entry->id ),
            confirm: [
                'message' => __( 'Would you like to delete this branch?' ),
            ]
        );

        return $entry;
    }

    /**
     * Bulk Delete Action
     */
    public function bulkAction( Request $request )
    {
        if ( $request->input( 'action' ) == 'delete_selected' ) {
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
                if ( $entity instanceof Branch ) {
                    if ( ! $entity->is_main && $entity->registers()->count() == 0 ) {
                        $entity->delete();
                        $status['success']++;
                    } else {
                        $status['error']++;
                    }
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
     */
    public function getLinks(): array
    {
        return [
            'list' => ns()->url( 'dashboard/branches' ),
            'create' => ns()->url( 'dashboard/branches/create' ),
            'edit' => ns()->url( 'dashboard/branches/edit/{id}' ),
            'post' => ns()->url( 'api/crud/' . self::IDENTIFIER ),
            'put' => ns()->url( 'api/crud/' . self::IDENTIFIER . '/{id}' ),
        ];
    }

    /**
     * Get Bulk actions
     */
    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [
            [
                'label' => __( 'Delete Selected Branches' ),
                'identifier' => 'delete_selected',
                'url' => ns()->route( 'ns.api.crud-bulk-actions', [
                    'namespace' => $this->namespace,
                ] ),
            ],
        ] );
    }

    /**
     * get exports
     */
    public function getExports()
    {
        return [];
    }
}

<?php

namespace App\Crud;

use App\Casts\GenderCast;
use App\Casts\NotDefinedCast;
use App\Casts\YesNoBoolCast;
use App\Classes\CrudTable;
use App\Classes\JsonResponse;
use App\Events\UserAfterActivationSuccessfulEvent;
use App\Exceptions\NotAllowedException;
use App\Models\Branch;
use App\Models\Register;
use App\Models\Role;
use App\Models\User;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use App\Services\PasswordPolicyService;
use App\Services\UsersService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use TorMorten\Eventy\Facades\Events as Hook;

class UserCrud extends CrudService
{
    /**
     * Define the autoload status
     */
    const AUTOLOAD = true;

    /**
     * Define the identifier
     */
    const IDENTIFIER = 'ns.users';

    /**
     * define the base table
     */
    protected $table = 'nexopos_users';

    /**
     * base route name
     */
    protected $mainRoute = 'ns.users';

    /**
     * Define namespace
     *
     * @param  string
     */
    protected $namespace = 'ns.users';

    /**
     * Model Used
     */
    protected $model = User::class;

    /**
     * Determine if the options column should display
     * before the crud columns
     */
    protected $prependOptions = true;

    /**
     * Adding relation
     */
    /**
     * The join onto the customer groups table is gone with the group field it fed. A staff list has no
     * business reading the customer loyalty register.
     */
    public $relations = [
        'leftJoin' => [
            [ 'nexopos_users as author', 'nexopos_users.author_id', '=', 'author.id' ],
            [ 'nexopos_branches as branch', 'nexopos_users.branch_id', '=', 'branch.id' ],
        ],
    ];

    public $pick = [
        'author' => [ 'username' ],
        'role' => [ 'name' ],
        'branch' => [ 'name' ],
    ];

    protected $permissions = [
        'create' => 'create.users',
        'read' => 'read.users',
        'update' => 'update.users',
        'delete' => 'delete.users',
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
    /**
     * Staff details only.
     *
     * group_id, credit_limit_amount and pobox used to be here. They belong to the customer register —
     * a shop's loyalty group, its credit ceiling and its postal box are not employment details — and
     * having them on this screen invited someone to record a member of staff as though they were a
     * customer, in the same table, with no way to tell afterwards which they were meant to be.
     */
    public $fillable = [
        'username',
        'email',
        'password',
        'active',
        'first_name',
        'last_name',
        'phone',
        'gender',
        'branch_id',
        'assigned_register_id',
    ];

    protected $casts = [
        'first_name' => NotDefinedCast::class,
        'last_name' => NotDefinedCast::class,
        'phone' => NotDefinedCast::class,
        'gender' => GenderCast::class,
        'active' => YesNoBoolCast::class,
    ];

    private UsersService $userService;

    /**
     * Define Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->userService = app()->make( UsersService::class );
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
            'list_title' => __( 'Users List' ),
            'list_description' => __( 'Display all users.' ),
            'no_entry' => __( 'No users has been registered' ),
            'create_new' => __( 'Add a new user' ),
            'create_title' => __( 'Create a new user' ),
            'create_description' => __( 'Register a new user and save it.' ),
            'edit_title' => __( 'Edit user' ),
            'edit_description' => __( 'Modify  User.' ),
            'back_to_list' => __( 'Return to Users' ),
        ];
    }

    /**
     * Keeps the customer register out of the staff list.
     *
     * Customers and staff share the nexopos_users table, and CustomerCrud narrows its list to accounts
     * holding the store customer role. This list had no equivalent filter, so every walk in customer
     * appeared among the shop's employees — on this installation two of the eight rows were customers.
     *
     * The test is "holds nothing but the customer role" rather than "holds the customer role", because
     * the shop owner's own account carries it alongside Administrator, and excluding anyone who happens
     * to hold it would hide the owner from the staff list.
     *
     * Accounts with no role at all stay visible: they are half finished staff records that someone has
     * to be able to find and fix.
     */
    public function hook( $query ): void
    {
        $customerRole = Role::where( 'namespace', Role::STORECUSTOMER )->first();

        if ( $customerRole instanceof Role ) {
            $query->whereNotExists( function ( $sub ) use ( $customerRole ) {
                $sub->selectRaw( '1' )
                    ->from( 'nexopos_users_roles_relations as customer_only' )
                    ->whereColumn( 'customer_only.user_id', 'nexopos_users.id' )
                    ->where( 'customer_only.role_id', $customerRole->id )

                    /**
                     * Only excludes the account when the customer role is the only one it has.
                     */
                    ->whereNotExists( function ( $other ) use ( $customerRole ) {
                        $other->selectRaw( '1' )
                            ->from( 'nexopos_users_roles_relations as other_roles' )
                            ->whereColumn( 'other_roles.user_id', 'nexopos_users.id' )
                            ->where( 'other_roles.role_id', '!=', $customerRole->id );
                    } );
            } );
        }

        $query->orderBy( 'nexopos_users.id', 'desc' );
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
                'label' => __( 'Username' ),
                'name' => 'username',
                'value' => $entry->username ?? '',
                'validation' => $entry === null ? 'required|unique:nexopos_users,username' : [
                    'required',
                    Rule::unique( 'nexopos_users', 'username' )->ignore( $entry->id ),
                ],
                'description' => __( 'Provide a name to the resource.' ),
            ],
            'tabs' => [
                'general' => [
                    'label' => __( 'General' ),
                    'fields' => [
                        [
                            'type' => 'text',
                            'name' => 'email',
                            'label' => __( 'Email' ),
                            'validation' => $entry === null ? 'required|email|unique:nexopos_users,email' : [
                                'required',
                                'email',
                                Rule::unique( 'nexopos_users', 'email' )->ignore( $entry->id ),
                            ],
                            'description' => __( 'Will be used for various purposes such as email recovery.' ),
                            'value' => $entry->email ?? '',
                        ], [
                            'type' => 'text',
                            'name' => 'first_name',
                            'value' => $entry?->first_name,
                            'label' => __( 'First Name' ),
                            'description' => __( 'Provide the user first name.' ),
                        ], [
                            'type' => 'text',
                            'name' => 'last_name',
                            'value' => $entry?->last_name,
                            'label' => __( 'Last Name' ),
                            'description' => __( 'Provide the user last name.' ),
                        ], [
                            'type' => 'password',
                            'name' => 'password',
                            'label' => __( 'Password' ),

                            /**
                             * "sometimes" rather than "required": leaving the box empty when
                             * editing a member of staff means "keep the password they have".
                             */
                            'validation' => app( PasswordPolicyService::class )->validationString( optional: true ),
                            'description' => app( PasswordPolicyService::class )->describe(),
                        ], [
                            'type' => 'password',
                            'name' => 'password_confirm',
                            'validation' => 'sometimes|same:general.password',
                            'label' => __( 'Confirm Password' ),
                            'description' => __( 'Should be the same as the password.' ),
                        ], [
                            'type' => 'switch',
                            'options' => Helper::kvToJsOptions( [ __( 'No' ), __( 'Yes' ) ] ),
                            'name' => 'active',
                            'label' => __( 'Active' ),
                            'description' => __( 'Define whether the user can use the application.' ),

                            /**
                             * A new member of staff starts able to sign in.
                             *
                             * This defaulted to No, and an account created that way is a dead end:
                             * the sign in check logs the user straight back out, and the only way
                             * to activate is for an administrator to return to this form, because
                             * the activation mail cannot be delivered on a shop's own server.
                             * So creating a cashier produced someone who could never log in, with
                             * nothing on screen to explain why.
                             *
                             * Whoever opens this form is already an administrator creating an
                             * account on purpose, and the switch is right here if they want to
                             * withhold access. Editing an existing user still shows that user's
                             * real state, so this changes the new-user default and nothing else.
                             */
                            'value' => $entry !== null ? (int) $entry->active : 1,
                        ], [
                            'type' => 'multiselect',
                            'options' => Helper::toJsOptions( Role::get(), [ 'id', 'name' ] ),
                            'description' => __( 'Define what roles applies to the user' ),
                            'name' => 'roles',
                            'label' => __( 'Roles' ),
                            'value' => $entry !== null ? ( $entry->roles()->get()->map( fn( $role ) => $role->id )->toArray() ?? '' ) : [],
                        ], [
                            /**
                             * The branch a member of staff works in. Without this field a cashier
                             * could only be given a branch from the command line, so opening a new
                             * branch meant shell access. It also decides what stock they read and
                             * which tills they can open, so leaving it empty seats them at head
                             * office by default — right for the owner, wrong for a branch cashier.
                             */
                            'type' => 'select',
                            'label' => __( 'สาขา' ),
                            'name' => 'branch_id',
                            'value' => $entry->branch_id ?? '',
                            'options' => Helper::kvToJsOptions(
                                [ '' => __( 'ทุกสาขา (เจ้าของร้าน / ผู้ดูแลระบบ)' ) ] +
                                Branch::active()->orderByDesc( 'is_main' )->orderBy( 'name' )->get()
                                    ->mapWithKeys( fn ( Branch $branch ): array => [ $branch->id => $branch->name ] )
                                    ->toArray()
                            ),
                            'description' => __( 'พนักงานจะเห็นสต๊อก ราคา และลิ้นชักของสาขานี้เท่านั้น เว้นว่างไว้ = เห็นทุกสาขา' ),
                        ], [
                            'type' => 'select',
                            'label' => __( 'ลิ้นชักที่ผูกไว้' ),
                            'name' => 'assigned_register_id',
                            'value' => $entry->assigned_register_id ?? '',
                            'options' => Helper::kvToJsOptions(
                                [ '' => __( 'ไม่ผูก (เลือกลิ้นชักได้เองตอนเปิดขาย)' ) ] +
                                Register::orderBy( 'branch_id' )->orderBy( 'name' )->get()
                                    ->mapWithKeys( fn ( Register $register ): array => [
                                        $register->id => $register->name . ( $register->branch?->name ? ' — ' . $register->branch->name : '' ),
                                    ] )
                                    ->toArray()
                            ),
                            'description' => __( 'ถ้าผูกไว้ พนักงานจะเปิดได้แค่ลิ้นชักนี้ ต้องเป็นลิ้นชักของสาขาเดียวกัน' ),
                        ], [
                            'type' => 'select',
                            'name' => 'gender',
                            'value' => $entry?->gender,
                            'label' => __( 'Gender' ),
                            'options' => Helper::kvToJsOptions( [
                                '' => __( 'Not Defined' ),
                                'male' => __( 'Male' ),
                                'female' => __( 'Female' ),
                            ] ),
                            'description' => __( 'Set the user gender.' ),
                        ], [
                            'type' => 'text',
                            'name' => 'phone',
                            'value' => $entry?->phone,
                            'label' => __( 'Phone' ),

                            /**
                             * No uniqueness rule. It used to be governed by ns_customers_force_unique_phone,
                             * a customer setting — which meant a rule meant for the customer register
                             * decided whether two members of staff could share the shop's landline.
                             */
                            'description' => __( 'เบอร์ติดต่อของพนักงาน' ),
                        ],
                    ],
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
        unset( $inputs[ 'password_confirm' ] );

        /**
         * if the password is not changed, no
         * need to hash it
         */
        $inputs = collect( $inputs )->filter( fn( $input ) => ! empty( $input ) || $input === 0 )->toArray();

        if ( ! empty( $inputs[ 'password' ] ) ) {
            $inputs[ 'password' ] = Hash::make( $inputs[ 'password' ] );
        }

        return $inputs;
    }

    /**
     * Filter PUT input fields
     *
     * @param  array of fields
     * @return array of fields
     */
    public function filterPutInputs( $inputs, User $entry )
    {
        unset( $inputs[ 'password_confirm' ] );

        /**
         * if the password is not changed, no
         * need to hash it
         */
        $inputs = collect( $inputs )->filter( fn( $input ) => ! empty( $input ) || $input === 0 )->toArray();

        if ( ! empty( $inputs[ 'password' ] ) ) {
            $inputs[ 'password' ] = Hash::make( $inputs[ 'password' ] );
        }

        return $inputs;
    }

    /**
     * Before saving a new record
     *
     * @param  Request $request
     * @return void
     */
    public function beforePost( $request )
    {
        $this->refuseRegisterFromAnotherBranch( $request );

        return $request;
    }

    /**
     * A till belongs to one branch, and so does the member of staff using it. Where the two disagree
     * the till wins on the screen — the picker shows the assigned register whatever branch it is in
     * — while the sale is filed against the branch. That mismatch writes a sale into one branch and
     * takes the stock off another, so it is refused at the point it would be created rather than
     * left to be discovered in a stock count.
     *
     * @param array<string, mixed> $inputs
     */
    private function refuseRegisterFromAnotherBranch( mixed $payload, ?User $entry = null ): void
    {
        $registerId = $this->valueFromCrudPayload( $payload, 'assigned_register_id' ) ?? $entry?->assigned_register_id;

        if ( empty( $registerId ) ) {
            return;
        }

        $register = Register::find( $registerId );

        if ( ! $register instanceof Register ) {
            return;
        }

        $branchId = $this->valueFromCrudPayload( $payload, 'branch_id' ) ?? $entry?->branch_id;

        if ( empty( $branchId ) ) {
            throw new NotAllowedException(
                __( 'ผูกลิ้นชักไว้แล้วต้องระบุสาขาของพนักงานด้วย เพราะลิ้นชักอยู่สาขาเดียว' )
            );
        }

        if ( (int) $register->branch_id !== (int) $branchId ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'ลิ้นชัก "%s" อยู่สาขา "%s" ไม่ตรงกับสาขาของพนักงาน เลือกลิ้นชักของสาขาเดียวกัน' ),
                    $register->name,
                    $register->branch?->name ?? '-'
                )
            );
        }
    }

    /**
     * Pulls one field out of a CRUD payload wherever it happens to sit.
     *
     * The hooks are handed a plain array on one path and a Request on another, and the fields
     * themselves arrive nested under the tab that declared them rather than at the top level. A
     * guard that only read the top level of a Request looked correct and never once fired.
     */
    private function valueFromCrudPayload( mixed $payload, string $field ): mixed
    {
        $inputs = is_array( $payload )
            ? $payload
            : ( is_object( $payload ) && method_exists( $payload, 'all' ) ? $payload->all() : [] );

        if ( array_key_exists( $field, $inputs ) ) {
            return $inputs[ $field ];
        }

        foreach ( $inputs as $value ) {
            if ( is_array( $value ) && array_key_exists( $field, $value ) ) {
                return $value[ $field ];
            }
        }

        return null;
    }

    /**
     * After saving a record
     *
     * @param  Request $request
     * @return void
     */
    public function afterPost( $request, User $entry )
    {
        if ( isset( $request[ 'roles'] ) ) {
            $this->userService
                ->setUserRole(
                    $entry,
                    $request[ 'roles' ]
                );

            $this->userService->createAttribute( $entry );

            /**
             * While creating the user, if we set that user as active
             * we'll dispatch the activation successful event.
             */
            if ( $entry->active ) {
                UserAfterActivationSuccessfulEvent::dispatch( $entry );
            }
        }

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
        /**
         * Checked on edit as well as create: moving a cashier to another branch while their old
         * till stays assigned produces exactly the mismatch described above. The stored record fills
         * in whichever of the two the form did not send.
         */
        $this->refuseRegisterFromAnotherBranch( $request, $entry instanceof User ? $entry : null );

        return $request;
    }

    /**
     * After updating a record
     *
     * @param Request $request
     * @param  object entry
     * @return void
     */
    public function afterPut( $request, User $entry )
    {
        if ( isset( $request[ 'roles'] ) ) {
            $this->userService
                ->setUserRole(
                    $entry,
                    $request[ 'roles' ]
                );

            $this->userService->createAttribute( $entry );

            /**
             * While creating the user, if we set that user as active
             * we'll dispatch the activation successful event.
             */
            if ( $entry->active ) {
                UserAfterActivationSuccessfulEvent::dispatch( $entry );
            }
        }

        return $request;
    }

    /**
     * Before Delete
     *
     * @return void
     */
    public function beforeDelete( $namespace, int $id, $model )
    {
        if ( $namespace == 'ns.users' ) {
            if ( $id === Auth::id() ) {
                throw new NotAllowedException( __( 'You cannot delete your own account.' ) );
            }
        }
    }

    /**
     * Define Columns
     */
    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column(
                identifier: 'username',
                label: __( 'Username' ),
                attributes: CrudTable::attributes(
                    CrudTable::attribute(
                        column: 'active',
                        label: __( 'Active' )
                    ),
                    CrudTable::attribute(
                        column: 'email',
                        label: __( 'Email' )
                    )
                )
            ),
            CrudTable::column(
                /**
                 * The wallet balance, the amount owed and the lifetime purchases used to sit here. They
                 * are customer figures; against a cashier they only ever read zero, which invited the
                 * question of why a member of staff had a wallet at all. The branch they work in is the
                 * thing a shop actually needs to see next to their name.
                 */
                label: __( 'สาขา' ),
                identifier: 'branch_name',
                sort: false
            ),
            CrudTable::column(
                label: __( 'Roles' ),
                identifier: 'rolesNames',
                sort: false
            ),
            CrudTable::column(
                label: __( 'Created At' ),
                identifier: 'created_at'
            )
        );
    }

    /**
     * Define actions
     */
    public function setActions( CrudEntry $entry ): CrudEntry
    {
        /**
         * The orders, rewards, coupons and wallet history entries used to be listed here. All four are
         * customer records, and they were pointed at dashboard/users/{id}/... paths that answer nothing
         * — every one of them was a dead link sitting on the staff list.
         */
        $entry->action(
            identifier: 'edit',
            label: __( 'Edit' ),
            permissions: [ 'update.users' ],
            url: ns()->url( 'dashboard/users/edit/' . $entry->id ),
        );

        $entry->action(
            identifier: 'delete',
            label: __( 'Delete' ),
            type: 'DELETE',
            url: ns()->url( '/api/crud/ns.users/' . $entry->id ),
            confirm: [
                'message' => __( 'Would you like to delete this ?' ),
                'title' => __( 'Delete a user' ),
            ],
        );

        $user = User::find( $entry->id );

        $roles = $user ? $user->roles()->get() : collect();
        $entry->rolesNames = $roles->map( fn( $role ) => $role->name )->join( ', ' ) ?: __( 'Not Assigned' );

        /**
         * An empty branch means the account is not tied to one, which is how the owner and any
         * administrator are set up. Saying so beats leaving the cell blank and letting it read as
         * missing data.
         */
        $entry->branch_name = $user?->branch_id
            ? ( $entry->branch_name ?: __( 'สาขาที่ถูกลบแล้ว' ) )
            : __( 'ทุกสาขา' );

        return $entry;
    }

    /**
     * Bulk Delete Action
     *
     * @param    object Request with object
     * @return  false/array
     */
    public function bulkAction( Request $request )
    {
        /**
         * Deleting licence is only allowed for admin
         * and supervisor.
         */
        $user = app()->make( UsersService::class );
        if ( ! $user->is( [ 'admin', 'supervisor' ] ) ) {
            return JsonResponse::error(
                message: __( 'You\'re not allowed to do this operation' )
            );
        }

        if ( $request->input( 'action' ) == 'delete_selected' ) {

            $this->allowedTo( 'delete' );

            $status = [
                'success' => 0,
                'error' => 0,
            ];

            foreach ( $request->input( 'entries' ) as $id ) {
                $entity = $this->model::find( $id );
                if ( $entity instanceof User ) {
                    $entity->delete();
                    $status[ 'success' ]++;
                } else {
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
            'list' => ns()->url( 'dashboard/' . 'users' ),
            'create' => ns()->url( 'dashboard/' . 'users/create' ),
            'edit' => ns()->url( 'dashboard/' . 'users/edit/' ),
            'post' => ns()->url( 'api/crud/' . 'ns.users' ),
            'put' => ns()->url( 'api/crud/' . 'ns.users/{id}' . '' ),
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
                'label' => __( 'Delete Selected Users' ),
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

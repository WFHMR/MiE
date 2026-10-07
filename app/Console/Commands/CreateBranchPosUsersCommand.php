<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Register;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAttribute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Creates one login per checkout station and binds each to its own till.
 *
 * NexoPOS ties an open till to a single user through registers.used_by, so a branch with
 * three counters serving customers at the same time needs three logins. Sharing one login
 * across three machines does not work: the second machine finds the till already held and
 * is refused.
 */
class CreateBranchPosUsersCommand extends Command
{
    protected $signature = 'rgbf:create-pos-users
        {--branch= : Branch the stations belong to}
        {--prefix= : Username prefix, defaults to pos-<branch id>}
        {--role=nexopos.store.cashier : Role namespace granted to each station}
        {--password= : Shared password, one is generated per user when omitted}
        {--author=1 : User id recorded as author}
        {--grant-pos-permissions : Add the permissions a till operator needs to the role}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Create one POS login per till in a branch and bind each login to its till.';

    /**
     * A till operator cannot work without these. The stock Store Cashier role ships with
     * fifteen permissions and none of them allow reading products or using a till, so the
     * station cannot scan a barcode or open its drawer.
     */
    private const REQUIRED_POS_PERMISSIONS = [
        'nexopos.read.products',
        'nexopos.read.products-units',
        'nexopos.read.categories',
        'nexopos.read.customers',
        'nexopos.create.customers',
        'nexopos.read.registers',
        'nexopos.use.registers',
        'nexopos.read.registers-history',
        'nexopos.read.orders',
        'nexopos.create.orders',
        'nexopos.make-payment.orders',

        /**
         * The cart toolbar exposes these, and the POS calls the users endpoint while
         * building the screen, so without them the till loads and then refuses to work.
         */
        'nexopos.cart.products',
        'nexopos.cart.taxes',
        'nexopos.cart.coupons',
        'nexopos.convert.products-units',

        /**
         * The till reads the tax group attached to each product to show VAT on the line and
         * in the footer, so without this it reports: permission ("nexopos.read.taxes")
         * required, and the footer stays at zero.
         */
        'nexopos.read.taxes',
        'nexopos.read.tax-invoices',

        /**
         * Stored without the nexopos prefix, which is why it was missed the first time and
         * the till reported: permission ("read.users") required.
         */
        'read.users',
        'read.dashboard',
        'manage.profile',
    ];

    public function handle(): int
    {
        if ( ! Schema::hasColumn( 'nexopos_users', 'assigned_register_id' ) ) {
            $this->error( 'ตาราง nexopos_users ยังไม่มีคอลัมน์ assigned_register_id — รัน migrate ก่อน' );

            return self::FAILURE;
        }

        $branchId = $this->option( 'branch' );

        if ( $branchId === null || $branchId === '' ) {
            $this->error( 'ต้องระบุ --branch=<id>' );

            return self::FAILURE;
        }

        $branchId = (int) $branchId;
        $branch   = Branch::find( $branchId );

        if ( $branch === null ) {
            $this->error( "ไม่พบสาขา id={$branchId}" );

            return self::FAILURE;
        }

        $roleNamespace = (string) $this->option( 'role' );
        $role          = Role::where( 'namespace', $roleNamespace )->first();

        if ( $role === null ) {
            $this->error( "ไม่พบ role: {$roleNamespace}" );

            return self::FAILURE;
        }

        $registers = Register::where( 'branch_id', $branchId )
            ->orderBy( 'id' )
            ->get();

        if ( $registers->isEmpty() ) {
            $this->error( "สาขานี้ยังไม่มีลิ้นชัก — รัน rgbf:setup-branch-registers --branch={$branchId} ก่อน" );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option( 'dry-run' );
        $prefix = (string) ( $this->option( 'prefix' ) ?: 'pos-' . $branchId );

        $this->info( 'สาขา     : ' . $branch->name . " (id={$branchId})" );
        $this->info( 'ลิ้นชัก   : ' . $registers->count() . ' จุด' );
        $this->info( 'Role     : ' . $role->name . " ({$roleNamespace})" );
        $this->info( 'โหมด     : ' . ( $dryRun ? 'ทดลอง' : 'เขียนข้อมูลจริง' ) );
        $this->newLine();

        if ( (bool) $this->option( 'grant-pos-permissions' ) ) {
            $this->grantPosPermissions( $role, $dryRun );
        }

        $credentials = [];
        $index       = 0;

        foreach ( $registers as $register ) {
            $index++;
            $username = $prefix . '-' . $index;

            $existing = User::where( 'username', $username )->first();

            if ( $existing instanceof User ) {
                $this->line( "  มีอยู่แล้ว: {$username} — จะอัปเดตการผูกลิ้นชักและสาขา" );
            }

            $password = (string) ( $this->option( 'password' ) ?: $this->generatePassword() );

            if ( $dryRun ) {
                $this->line( sprintf(
                    '  จะสร้าง: %-14s -> ลิ้นชัก "%s" (id=%d)',
                    $username,
                    $register->name,
                    $register->id
                ) );

                continue;
            }

            $user = $existing instanceof User ? $existing : new User;

            $user->username = $username;
            $user->email    = $username . '@' . $this->emailDomain();
            $user->active   = true;

            /**
             * The password is only written for a new station. Rewriting it for an existing
             * one would lock out a counter that is already trading.
             */
            if ( ! $existing instanceof User ) {
                $user->password = Hash::make( $password );
            }

            $user->branch_id            = $branchId;
            $user->assigned_register_id = $register->id;
            $user->author_id            = (int) $this->option( 'author' );
            $user->first_name           = $register->name;
            $user->save();

            DB::table( 'nexopos_users_roles_relations' )->updateOrInsert(
                [ 'user_id' => $user->id, 'role_id' => $role->id ],
                [ 'user_id' => $user->id, 'role_id' => $role->id ]
            );

            $this->ensureAttribute( $user );

            $credentials[] = [
                $register->name,
                $username,
                $existing instanceof User ? '(รหัสเดิม)' : $password,
                $register->id,
                $user->id,
            ];

            $this->info( sprintf(
                '  พร้อม: %-14s -> %s (register id=%d)',
                $username,
                $register->name,
                $register->id
            ) );
        }

        if ( $dryRun ) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info( 'บัญชีสำหรับแต่ละเครื่อง' );
        $this->table(
            [ 'จุดคิดเงิน', 'ชื่อผู้ใช้', 'รหัสผ่าน', 'register id', 'user id' ],
            $credentials
        );

        $this->warn( 'บันทึกรหัสผ่านไว้ให้ดี ระบบเก็บเป็น hash อ่านย้อนหลังไม่ได้' );
        $this->newLine();
        $this->renderState( $branchId );

        return self::SUCCESS;
    }

    /**
     * Creates the per user settings row.
     *
     * The dashboard layout reads Auth::user()->attribute->theme without checking for null,
     * so a user created without this row cannot load a single dashboard page: the request
     * dies with "Attempt to read property theme on null". The layouts are guarded now, but
     * the row is still what carries the interface language, so it is created here rather
     * than left for ns:doctor to repair afterwards.
     */
    private function ensureAttribute( User $user ): void
    {
        if ( $user->attribute instanceof UserAttribute ) {
            return;
        }

        $attribute           = new UserAttribute;
        $attribute->user_id  = $user->id;
        $attribute->language = (string) ns()->option->get( 'ns_store_language', 'th' );

        /**
         * Left unset on purpose so the station follows whatever the shop's default theme
         * is, the same way the existing accounts do. Writing a value here is what made the
         * new tills render dark while every other screen in the shop was light.
         */
        $attribute->theme = null;

        $attribute->save();
    }

    private function grantPosPermissions( Role $role, bool $dryRun ): void
    {
        $existing = DB::table( 'nexopos_role_permission as rp' )
            ->join( 'nexopos_permissions as p', 'p.id', '=', 'rp.permission_id' )
            ->where( 'rp.role_id', $role->id )
            ->pluck( 'p.namespace' )
            ->all();

        $missing = array_values( array_diff( self::REQUIRED_POS_PERMISSIONS, $existing ) );

        if ( $missing === [] ) {
            $this->info( 'Role มีสิทธิ์ที่จำเป็นครบแล้ว' );
            $this->newLine();

            return;
        }

        $this->warn( 'Role ขาดสิทธิ์ที่จำเป็นต่อการใช้ POS ' . count( $missing ) . ' รายการ:' );

        foreach ( $missing as $namespace ) {
            $this->line( '    ' . $namespace );
        }

        if ( $dryRun ) {
            $this->newLine();

            return;
        }

        $granted = 0;
        $unknown = [];

        foreach ( $missing as $namespace ) {
            $permission = Permission::where( 'namespace', $namespace )->first();

            if ( $permission === null ) {
                $unknown[] = $namespace;

                continue;
            }

            DB::table( 'nexopos_role_permission' )->updateOrInsert(
                [ 'role_id' => $role->id, 'permission_id' => $permission->id ],
                [ 'role_id' => $role->id, 'permission_id' => $permission->id ]
            );

            $granted++;
        }

        $this->info( "เพิ่มสิทธิ์แล้ว {$granted} รายการ" );

        if ( $unknown !== [] ) {
            $this->error( 'ไม่พบสิทธิ์เหล่านี้ในระบบ: ' . implode( ', ', $unknown ) );
        }

        $this->newLine();
    }

    /**
     * Readable rather than maximal: it is typed by a cashier at the start of every shift,
     * so ambiguous glyphs are excluded and the shape stays easy to transcribe.
     */
    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits   = '23456789';

        $letters = '';

        for ( $i = 0; $i < 4; $i++ ) {
            $letters .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
        }

        $numbers = '';

        for ( $i = 0; $i < 4; $i++ ) {
            $numbers .= $digits[ random_int( 0, strlen( $digits ) - 1 ) ];
        }

        return $letters . $numbers;
    }

    private function emailDomain(): string
    {
        $host = parse_url( (string) config( 'app.url' ), PHP_URL_HOST );

        return is_string( $host ) && $host !== '' ? $host : 'store.local';
    }

    private function renderState( int $branchId ): void
    {
        $rows = User::where( 'branch_id', $branchId )
            ->orderBy( 'id' )
            ->get()
            ->map( static function ( User $u ): array {
                $register = $u->assigned_register_id !== null
                    ? Register::find( $u->assigned_register_id )
                    : null;

                return [
                    $u->id,
                    $u->username,
                    $u->branch_id,
                    $register === null ? 'ไม่ผูก (เห็นทุกจุดในสาขา)' : $register->name,
                    $u->active ? 'ใช้งาน' : 'ปิด',
                ];
            } )
            ->all();

        $this->table( [ 'user id', 'ชื่อผู้ใช้', 'สาขา', 'ลิ้นชักที่ผูก', 'สถานะ' ], $rows );
    }
}

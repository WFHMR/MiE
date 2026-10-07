<?php

/**
 * Data Migration
 * Rewrites the descriptions of the built-in roles in Thai, and removes the upstream product name
 * from the one that carried it.
 *
 * The role description is not an internal label. RolesCrud puts it in a textarea on the edit form,
 * so it is read by whoever opens ผู้ใช้งาน → สร้างกลุ่มผู้ใช้งาน and edits a role. One of the three
 * shop roles described itself as belonging to the project this system was built from, which is a
 * supplier's product name on a screen the shop's own staff open. The other two were in English on a
 * system whose every other label is Thai.
 *
 * The descriptions in database/permissions/*.php were corrected at the same time, which covers a
 * fresh installation. This migration exists because those files only run once, at install, and this
 * shop is already installed — the wrong text is sitting in its database now.
 *
 * Matched on namespace rather than id: ids differ between installations, and two extra roles were
 * created by hand on this one.
 *
 * Placed in this directory rather than migrations/update, for the reason recorded on
 * 2026_09_12_220000_add_tax_branch_code_to_branches_table.php: SetupService marks everything under
 * update as executed without running it. Filed there, this migration reported "Nothing to migrate"
 * and the English text stayed in the database — which is exactly what happened on the first attempt.
 *
 * Safe to run on a fresh install as well as an existing one. It only updates rows that are already
 * there, and on a fresh install the permission files have written the same Thai text a moment
 * earlier, so the statement matches and changes nothing.
 **/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'nexopos.store.administrator' => 'ดูแลร้านได้ทั้งหมด ตั้งแต่สินค้า คลังสินค้า การขาย ลูกค้า จัดซื้อ รายงาน และภาษี',
        'nexopos.store.cashier' => 'ขายหน้าร้าน เปิดและปิดกะจุดคิดเงิน รับชำระเงิน และดูรายการขาย',
        'nexopos.store.customer' => 'ซื้อสินค้าและแก้ไขข้อมูลส่วนตัวของตัวเองได้',
        'admin' => 'สิทธิ์สูงสุดของระบบ ตั้งค่าได้ทุกอย่าง สร้างผู้ใช้งาน และจัดการส่วนขยาย',
        'user' => 'ผู้ใช้งานทั่วไป ยังไม่ได้รับสิทธิ์เฉพาะด้าน',
    ];

    private const TABLE = 'nexopos_roles';

    public function up(): void
    {
        /**
         * The table may not exist yet.
         *
         * This directory is run by a plain `php artisan migrate`, in filename order, and also during
         * the full reset that HardResetTest performs — where the schema is dropped and rebuilt from
         * nothing. On that path this file is reached before the roles table has been created, and
         * without this guard the UPDATE raised "no such table: nexopos_roles" and took the entire
         * reset down with it. Every suite that depends on the reset then failed too: 594 tests, none
         * of them related to role descriptions.
         *
         * Skipping is the correct behaviour rather than a workaround. The permission definition files
         * write these same Thai descriptions when the roles are created, so an installation that has
         * no table yet has nothing to correct — this migration exists only for the installations that
         * already hold the old English text.
         */
        if ( ! Schema::hasTable( self::TABLE ) ) {
            return;
        }

        foreach ( self::DESCRIPTIONS as $namespace => $description ) {
            DB::table( self::TABLE )
                ->where( 'namespace', $namespace )
                ->update( [ 'description' => $description ] );
        }
    }

    /**
     * Deliberately empty. The previous text was English, and one line of it named another product;
     * restoring it would be reintroducing the fault this migration was written to remove.
     */
    public function down(): void
    {
        // Nothing to reverse.
    }
};

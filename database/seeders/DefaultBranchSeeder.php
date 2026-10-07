<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DefaultBranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // สร้างสาขาหลัก (Main Branch)
        $admin = User::where( 'username', 'admin' )->first();

        Branch::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Branch',
                'description' => 'Default main branch',
                'address' => '',
                'phone' => '',
                'email' => '',
                'is_active' => true,
                'is_main' => true,
                'author_id' => $admin ? $admin->id : 1,
                'uuid' => Str::uuid(),
            ]
        );

        // อัปเดต existing records ให้ใช้ main branch
        $mainBranch = Branch::where( 'code', 'MAIN' )->first();

        /**
         * The backfill below claims every unassigned row for head office. That is right exactly once,
         * on an install being converted from one branch to many. Run it again after a second branch
         * exists and it stamps that branch's newly created tills, stock rows and staff onto head
         * office instead — silently, and across five tables. So it only runs while there is nothing
         * else to confuse it with.
         */
        if ( Branch::count() > 1 ) {
            $this->command?->warn( 'มีมากกว่า 1 สาขาแล้ว ข้ามการโยกข้อมูลที่ยังไม่ระบุสาขาไปสาขาหลัก เพื่อไม่ให้ข้อมูลของสาขาอื่นถูกดึงมา' );

            return;
        }

        if ( $mainBranch ) {
            // อัปเดต registers ที่ยังไม่มี branch_id
            \DB::table( 'nexopos_registers' )
                ->whereNull( 'branch_id' )
                ->update( ['branch_id' => $mainBranch->id] );

            // อัปเดต products_unit_quantities
            \DB::table( 'nexopos_products_unit_quantities' )
                ->whereNull( 'branch_id' )
                ->update( ['branch_id' => $mainBranch->id] );

            // อัปเดต products_histories
            \DB::table( 'nexopos_products_histories' )
                ->whereNull( 'branch_id' )
                ->update( ['branch_id' => $mainBranch->id] );

            // อัปเดต orders
            \DB::table( 'nexopos_orders' )
                ->whereNull( 'branch_id' )
                ->update( ['branch_id' => $mainBranch->id] );

            // อัปเดต users (admin จะอยู่ main branch)
            \DB::table( 'nexopos_users' )
                ->whereNull( 'branch_id' )
                ->update( ['branch_id' => $mainBranch->id] );
        }
    }
}

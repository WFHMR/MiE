<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Services\Options;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmergencySetupSeeder extends Seeder
{
    public function run(): void
    {
        echo "🚀 Emergency Setup - Starting...\n\n";

        // 1. Create Admin Role
        echo "1️⃣ Creating Admin Role...\n";
        $adminRole = Role::firstOrCreate(
            ['namespace' => 'admin'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'locked' => true,
            ]
        );
        echo "   ✅ Admin Role: {$adminRole->name}\n\n";

        // 2. Create Admin User
        echo "2️⃣ Creating Admin User...\n";
        $initialPassword = Str::password( 32 );
        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'email' => 'admin@nexopos.com',
                'password' => Hash::make( $initialPassword ),
                'active' => true,
                'author' => 0,
            ]
        );
        DB::table( 'nexopos_users_roles' )->updateOrInsert(
            ['user_id' => $admin->id, 'role_id' => $adminRole->id],
            ['user_id' => $admin->id, 'role_id' => $adminRole->id]
        );
        echo "   ✅ Admin User: {$admin->username}\n\n";
        if ( $admin->wasRecentlyCreated ) {
            echo "Initial password: {$initialPassword}\n";
        }

        // 3. Create Main Branch (Warehouse)
        echo "3️⃣ Creating Main Warehouse Branch...\n";
        $mainBranch = Branch::firstOrCreate(
            ['name' => 'สาขาหลัก / คลังสินค้าหลัก'],
            [
                'is_main' => true,
                'is_main_warehouse' => true,
                'author' => $admin->id,
            ]
        );
        echo "   ✅ Main Branch: {$mainBranch->name} (ID: {$mainBranch->id})\n\n";

        // 4. Create Unit Groups and Units
        echo "4️⃣ Creating Units...\n";
        $countableGroup = UnitGroup::firstOrCreate(
            ['name' => 'Countable'],
            ['description' => 'Countable units', 'author' => $admin->id]
        );

        $units = [
            ['name' => 'ชิ้น', 'identifier' => 'piece', 'value' => 1],
            ['name' => 'กล่อง', 'identifier' => 'box', 'value' => 1],
            ['name' => 'แพ็ค', 'identifier' => 'pack', 'value' => 1],
            ['name' => 'ห่อ', 'identifier' => 'bundle', 'value' => 1],
        ];

        foreach ( $units as $unitData ) {
            Unit::firstOrCreate(
                ['identifier' => $unitData['identifier']],
                [
                    'name' => $unitData['name'],
                    'value' => $unitData['value'],
                    'base_unit' => true,
                    'group_id' => $countableGroup->id,
                    'author' => $admin->id,
                ]
            );
        }
        echo "   ✅ Created 4 units\n\n";

        // 5. Create Product Categories
        echo "5️⃣ Creating Product Categories...\n";
        $categories = [
            'สินค้าทั่วไป',
            'อาหาร',
            'เครื่องดื่ม',
            'ของใช้',
            'เครื่องครัว',
        ];

        foreach ( $categories as $catName ) {
            ProductCategory::firstOrCreate(
                ['name' => $catName],
                ['author' => $admin->id]
            );
        }
        echo '   ✅ Created ' . count( $categories ) . " categories\n\n";

        // 6. Create Default Tax Group
        echo "6️⃣ Creating Tax Group...\n";
        TaxGroup::firstOrCreate(
            ['name' => 'ไม่มีภาษี'],
            ['description' => 'No tax applied', 'author' => $admin->id]
        );
        echo "   ✅ Tax group created\n\n";

        // 7. Set System Options (Thai Language)
        echo "7️⃣ Setting System Options (Thai Language)...\n";
        $options = app( Options::class );

        $settings = [
            'ns_store_language' => 'th',
            'ns_store_name' => 'RGBF',
            'ns_currency_symbol' => '฿',
            'ns_currency_iso' => 'THB',
            'ns_currency_position' => 'before',
            'ns_currency_prefered' => 'iso',
            'ns_date_format' => 'd/m/Y',
            'ns_datetime_format' => 'd/m/Y H:i',
        ];

        foreach ( $settings as $key => $value ) {
            $options->set( $key, $value );
        }
        echo "   ✅ System set to Thai language\n\n";

        echo "✅ Emergency Setup Complete!\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "Admin: {$admin->username} (existing passwords are unchanged)\n";
        echo "Main Branch ID: {$mainBranch->id}\n";
        echo "Language: Thai (th)\n";
    }
}

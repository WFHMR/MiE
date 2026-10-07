<?php

namespace App\Filters;

class MenusFilter
{
    public static function injectRegisterMenus( $menus )
    {
        if ( ns()->option->get( 'ns_pos_registers_enabled' ) === 'yes' ) {
            /**
             * Labels are written in Thai directly, the same way MenuService builds every
             * other menu in the sidebar. This filter replaces the single "ขายสินค้า" link
             * that MenuService declares, so going through __() here would have left the
             * sidebar with one branch translated by the language file and the rest not.
             *
             * "Register" is a counter with its own cash drawer, not a registration form —
             * the wording says so, because "ลงทะเบียน" would read as signing something up.
             */
            $menus = array_insert_after( $menus, 'pos', [
                'registers' => [
                    'label' => 'ขายสินค้า',
                    'icon' => 'la-cash-register',
                    'permissions' => [ 'nexopos.create.orders' ],
                    'childrens' => [
                        'pos' => [
                            'label' => 'เปิดหน้าจอขาย (POS)',
                            'href' => ns()->route( 'ns.dashboard.pos' ),
                        ],
                        'create' => [
                            'label' => 'สร้างจุดคิดเงิน',
                            'permissions' => [ 'nexopos.create.registers' ],
                            'href' => ns()->route( 'ns.dashboard.registers-create' ),
                        ],
                        'list' => [
                            'label' => 'รายการจุดคิดเงิน (เปิด/ปิดกะ)',
                            'permissions' => [ 'nexopos.create.registers' ],
                            'href' => ns()->route( 'ns.dashboard.registers-list' ),
                        ],
                    ],
                ],
            ] );

            unset( $menus[ 'pos' ] );
        }

        if ( ns()->option->get( 'ns_orders_allow_unpaid' ) === 'yes' && isset( $menus[ 'orders' ] ) ) {
            $menus[ 'orders' ][ 'childrens' ][ 'instalments' ] = [
                'label' => __( 'Instalments' ),
                'permissions' => [ 'nexopos.read.orders-instalments' ],
                'href' => ns()->route( 'ns.dashboard.orders-instalments' ),
            ];
        }

        /**
         * Inject Branches menu into sidebar
         */
        $menus = array_insert_after( $menus, 'registers', [
            'branches' => [
                'label' => 'สาขา',
                'icon' => 'la-building',
                'permissions' => [ 'nexopos.read.branches' ],
                'childrens' => [
                    /**
                     * These used to read /dashboard/crud/ns.branches. There is no generic
                     * dashboard/crud/{namespace} route in this application, so both entries
                     * answered 404. The paths below are the ones BranchCrud::getLinks()
                     * already declares.
                     */
                    'list' => [
                        'label' => 'รายการสาขา',
                        'permissions' => [ 'nexopos.read.branches' ],
                        'href' => ns()->url( '/dashboard/branches' ),
                    ],
                    'create' => [
                        'label' => 'สร้างสาขาใหม่',
                        'permissions' => [ 'nexopos.create.branches' ],
                        'href' => ns()->url( '/dashboard/branches/create' ),
                    ],
                ],
            ],
        ] );

        return $menus;
    }
}

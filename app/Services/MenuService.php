<?php

namespace App\Services;

use App\Classes\AsideMenu;
use App\Classes\Menu;
use Illuminate\Support\Facades\Gate;
use TorMorten\Eventy\Facades\Eventy as Hook;

class MenuService
{
    protected $menus;

    protected $accountMenus = [];

    public function buildMenus()
    {
        $this->menus = AsideMenu::wrapper(
            // ==========================================
            // แดชบอร์ด
            // ==========================================
            AsideMenu::menu(
                label: 'แดชบอร์ด',
                icon: 'la-home',
                identifier: 'dashboard',
                permissions: ['read.dashboard'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'หน้าหลัก',
                        identifier: 'index',
                        permissions: ['read.dashboard'],
                        href: ns()->url('/dashboard')
                    ),
                    AsideMenu::subMenu(label: 'แดชบอร์ดยอดขาย', identifier: 'business-sales', permissions: ['nexopos.reports.sales'], href: route('ns.business.sales')),
                    AsideMenu::subMenu(label: 'แดชบอร์ดจัดซื้อ', identifier: 'business-purchase', permissions: ['nexopos.read.procurements'], href: route('ns.business.purchase')),
                    AsideMenu::subMenu(label: 'แดชบอร์ดคลังสินค้า', identifier: 'business-inventory', permissions: ['nexopos.read.products'], href: route('ns.business.inventory')),
                    AsideMenu::subMenu(label: 'แดชบอร์ดการเงิน', identifier: 'business-financial', permissions: ['nexopos.reports.transactions'], href: route('ns.business.financial')),
                    AsideMenu::subMenu(label: 'แดชบอร์ด KPI', identifier: 'business-kpi', permissions: ['nexopos.reports.sales'], href: route('ns.business.kpi'))
                ),
            ),

            AsideMenu::menu(label:'ราคาพิเศษตามช่วงเวลา',icon:'la-tags',identifier:'scheduled-prices',permissions:['nexopos.read.scheduled-prices'],href:route('ns.scheduled-prices.index')),
            AsideMenu::menu(label:'บริหารผลงาน',icon:'la-chart-line',identifier:'performance',permissions:['nexopos.read.performance'],childrens:AsideMenu::childrens(
                AsideMenu::subMenu(label:'ตัวชี้วัด KPI',identifier:'performance-metrics',permissions:['nexopos.read.performance'],href:route('ns.performance.index',['page'=>'metrics'])),
                AsideMenu::subMenu(label:'เป้าหมายพนักงาน',identifier:'performance-goals',permissions:['nexopos.read.performance'],href:route('ns.performance.index',['page'=>'goals'])),
                AsideMenu::subMenu(label:'ประเมินผลงาน',identifier:'performance-evaluations',permissions:['nexopos.read.performance'],href:route('ns.performance.index',['page'=>'evaluations'])),
                AsideMenu::subMenu(label:'คะแนนและสรุปผล',identifier:'performance-scores',permissions:['nexopos.read.performance'],href:route('ns.performance.index',['page'=>'scores'])),
                AsideMenu::subMenu(label:'ประเมินประจำปี',identifier:'performance-annual',permissions:['nexopos.read.performance'],href:route('ns.performance.index',['page'=>'annual']))
            )),
            // ==========================================
            // ขายสินค้า (POS)
            // ==========================================
            AsideMenu::menu(
                label: 'ขายสินค้า',
                icon: 'la-cash-register',
                identifier: 'pos',
                permissions: ['nexopos.create.orders'],
                href: ns()->url('/dashboard/pos'),
            ),

            // ==========================================
            // เอกสารการขาย
            // ==========================================
            /**
             * The identifier has to be "orders": MenusFilter::injectRegisterMenus() hangs the
             * instalments entry off $menus[ 'orders' ][ 'childrens' ], and until this section
             * existed that injection silently did nothing.
             *
             * รายการขาย is not just a history view — it is the only way to reach a sale's row
             * actions, which is where a tax invoice is issued from. Without this entry the
             * document cannot be issued at all.
             */
            AsideMenu::menu(
                label: 'เอกสารการขาย',
                icon: 'la-file-invoice',
                identifier: 'orders',
                permissions: [
                    'nexopos.read.orders',
                    'nexopos.create.tax-invoices',
                    'nexopos.read.billing-notes',
                    'nexopos.create.billing-notes',
                ],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(label: 'ใบเสนอราคา', identifier: 'sales-quotations', permissions: ['nexopos.read.orders'], href: route('ns.sales.quotations')),
                    AsideMenu::subMenu(label: 'จองสต๊อก / ประวัติการจอง', identifier: 'stock-reservations', permissions: ['nexopos.read.orders'], href: route('ns.sales.reservations')),
                    AsideMenu::subMenu(label: 'ใบสั่งขาย', identifier: 'sales-orders', permissions: ['nexopos.read.orders'], href: route('ns.sales.orders')),
                    AsideMenu::subMenu(label: 'จัดส่งสินค้า', identifier: 'sales-deliveries', permissions: ['nexopos.read.orders'], href: route('ns.sales.deliveries')),
                    AsideMenu::subMenu(
                        label: 'รายการขาย / ออกใบกำกับภาษี',
                        identifier: 'orders-list',
                        permissions: ['nexopos.read.orders'],
                        href: ns()->url('/dashboard/orders')
                    ),
                    AsideMenu::subMenu(
                        label: 'ออกใบวางบิล',
                        identifier: 'billing-notes-create',
                        permissions: ['nexopos.create.billing-notes'],
                        href: ns()->url('/dashboard/orders/billing-notes/create')
                    ),
                    AsideMenu::subMenu(
                        label: 'ทะเบียนใบวางบิล',
                        identifier: 'billing-notes-list',
                        permissions: ['nexopos.read.billing-notes'],
                        href: ns()->url('/dashboard/orders/billing-notes')
                    ),
                ),
            ),

            // ==========================================
            // รายงานการขายสินค้า
            // ==========================================
            AsideMenu::menu(
                label: 'รายงานการขาย',
                icon: 'la-chart-bar',
                identifier: 'sales-reports',
                permissions: ['nexopos.reports.sales', 'nexopos.reports.payment-types'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(label: 'กระทบยอดบิลรายวัน', identifier: 'daily-reconciliation', permissions: ['nexopos.reports.sales'], href: ns()->url('/dashboard/reports/daily-reconciliation')),
                    AsideMenu::subMenu(
                        label: 'รายงานการขายตามวัน',
                        identifier: 'daily-sales',
                        permissions: ['nexopos.reports.sales'],
                        href: ns()->url('/dashboard/reports/sales')
                    ),
                    AsideMenu::subMenu(
                        label: 'ตามกลุ่มสินค้า',
                        identifier: 'sales-by-category',
                        permissions: ['nexopos.reports.sales'],
                        href: ns()->url('/dashboard/reports/sales-progress')
                    ),
                    AsideMenu::subMenu(
                        label: 'ตามซัพพลายเออร์',
                        identifier: 'sales-by-provider',
                        permissions: ['nexopos.reports.sales'],
                        href: ns()->url('/dashboard/reports/sold-stock')
                    ),
                    AsideMenu::subMenu(
                        label: 'ช่องทางการขาย',
                        identifier: 'sales-channels',
                        permissions: ['nexopos.reports.sales'],
                        href: ns()->url('/dashboard/reports/profit')
                    ),
                    AsideMenu::subMenu(
                        label: 'แยกช่องทางการชำระเงิน',
                        identifier: 'payment-types-report',
                        permissions: ['nexopos.reports.payment-types'],
                        href: ns()->url('/dashboard/reports/payment-types')
                    ),
                ),
            ),

            // ==========================================
            // ลูกค้า
            // ==========================================
            AsideMenu::menu(
                label: 'ลูกค้า',
                icon: 'la-user-friends',
                identifier: 'customers',
                permissions: [
                    'nexopos.read.customers',
                    'nexopos.create.customers',
                    'nexopos.read.customers-groups',
                    'nexopos.read.rewards',
                    'nexopos.read.coupons',
                ],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'หน้าสมาชิก',
                        identifier: 'customers-list',
                        permissions: ['nexopos.read.customers'],
                        href: ns()->url('/dashboard/customers')
                    ),
                    AsideMenu::subMenu(
                        label: 'เพิ่มสมาชิก',
                        identifier: 'create-customer',
                        permissions: ['nexopos.create.customers'],
                        href: ns()->url('/dashboard/customers/create')
                    ),
                    AsideMenu::subMenu(
                        label: 'นำเข้าสมาชิก Excel / CSV',
                        identifier: 'import-members',
                        permissions: ['nexopos.create.customers'],
                        href: ns()->url('/dashboard/customers/import-members')
                    ),
                    AsideMenu::subMenu(
                        label: 'คะแนนสมาชิกทุกสาขา',
                        identifier: 'branch-points',
                        permissions: ['nexopos.read.customers'],
                        href: ns()->url('/dashboard/customers/branch-points')
                    ),
                    AsideMenu::subMenu(
                        label: 'สร้างกลุ่ม (Tier)',
                        identifier: 'customers-groups',
                        permissions: ['nexopos.read.customers-groups'],
                        href: ns()->url('/dashboard/customers/groups')
                    ),
                    AsideMenu::subMenu(
                        label: 'แบ่งประเภทลูกค้า',
                        identifier: 'create-customers-group',
                        permissions: ['nexopos.create.customers-groups'],
                        href: ns()->url('/dashboard/customers/groups/create')
                    ),
                    AsideMenu::subMenu(
                        label: 'ระบบรางวัล/ตรวจสอบคะแนน',
                        identifier: 'reward-system',
                        permissions: ['nexopos.read.rewards'],
                        href: ns()->url('/dashboard/customers/rewards-system')
                    ),
                    AsideMenu::subMenu(
                        label: 'สร้างคูปองส่วนลด',
                        identifier: 'create-coupon',
                        permissions: ['nexopos.create.coupons'],
                        href: ns()->url('/dashboard/customers/coupons/create')
                    ),
                    AsideMenu::subMenu(
                        label: 'สรุปคูปองส่วนลด',
                        identifier: 'list-coupons',
                        permissions: ['nexopos.read.coupons'],
                        href: ns()->url('/dashboard/customers/coupons')
                    ),
                ),
            ),

            // ==========================================
            // ซัพพลายเออร์
            // ==========================================
            AsideMenu::menu(
                label: 'ซัพพลายเออร์',
                icon: 'la-user-tie',
                identifier: 'providers',
                permissions: ['nexopos.read.providers', 'nexopos.create.providers'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'รายชื่อซัพพลายเออร์',
                        identifier: 'providers-list',
                        permissions: ['nexopos.read.providers'],
                        href: ns()->url('/dashboard/providers')
                    ),
                    AsideMenu::subMenu(
                        label: 'สร้างทะเบียนซัพพลายเออร์',
                        identifier: 'create-provider',
                        permissions: ['nexopos.create.providers'],
                        href: ns()->url('/dashboard/providers/create')
                    ),
                ),
            ),

            // ==========================================
            // คลังสินค้า
            // ==========================================
            AsideMenu::menu(
                label: 'คลังสินค้า',
                icon: 'la-boxes',
                identifier: 'inventory',
                permissions: [
                    'nexopos.read.products',
                    'nexopos.create.products',
                    'nexopos.read.categories',
                    'nexopos.create.categories',
                    'nexopos.read.products-units',
                    'nexopos.make.products-adjustments',
                    'nexopos.stock-counts.work',
                ],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'รายการสินค้า (ทะเบียนสินค้า)',
                        identifier: 'products',
                        permissions: ['nexopos.read.products'],
                        href: ns()->url('/dashboard/products')
                    ),
                    AsideMenu::subMenu(
                        label: 'สร้างสินค้า',
                        identifier: 'create-products',
                        permissions: ['nexopos.create.products'],
                        href: ns()->url('/dashboard/products/create')
                    ),

                    /**
                     * Listed next to the product list because that is where somebody goes looking
                     * for a price or a cost. Held to the same permission as editing a product,
                     * since it changes the figure every margin is computed from.
                     */
                    AsideMenu::subMenu(
                        label: 'แก้ต้นทุนหลายรายการ',
                        identifier: 'products-cost-editor',
                        permissions: ['nexopos.update.products'],
                        href: ns()->route('ns.products-cost-editor')
                    ),
                    AsideMenu::subMenu(
                        label: 'พิมพ์ฉลาก ป้ายราคา/ลาเบล',
                        identifier: 'labels-printing',
                        permissions: ['nexopos.create.products-labels'],
                        href: ns()->url('/dashboard/products/print-labels')
                    ),
                    AsideMenu::subMenu(
                        label: 'สร้างหมวดหมู่/แก้ไข',
                        identifier: 'categories',
                        permissions: ['nexopos.read.categories'],
                        href: ns()->url('/dashboard/products/categories')
                    ),
                    AsideMenu::subMenu(
                        label: 'เชื่อมหน่วยสินค้า / เพิ่มหน่วยสินค้า',
                        identifier: 'units',
                        permissions: ['nexopos.read.products-units'],
                        href: ns()->url('/dashboard/units')
                    ),
                    AsideMenu::subMenu(
                        label: 'ปรับปรุงสต็อก',
                        identifier: 'stock-adjustment',
                        permissions: ['nexopos.make.products-adjustments'],
                        href: ns()->url('/dashboard/products/stock-adjustment')
                    ),
                    AsideMenu::subMenu(
                        label: 'ประวัติการปรับปรุง',
                        identifier: 'adjustment-history',
                        permissions: ['nexopos.make.products-adjustments'],
                        href: ns()->url('/dashboard/products/adjustment-history')
                    ),
                    AsideMenu::subMenu(
                        label: 'โอนสินค้าระหว่างสาขา',
                        identifier: 'stock-transfers',
                        permissions: ['nexopos.read.stock-transfers'],
                        href: ns()->url('/dashboard/stock-transfers')
                    ),
                    AsideMenu::subMenu(
                        label: 'สร้างรายการโอนสินค้า',
                        identifier: 'create-stock-transfer',
                        permissions: ['nexopos.create.stock-transfers'],
                        href: ns()->url('/dashboard/stock-transfers/create')
                    ),
                    AsideMenu::subMenu(
                        label: 'ตรวจนับสต๊อก',
                        identifier: 'stock-counts',
                        permissions: ['nexopos.stock-counts.work'],
                        href: ns()->route('ns.dashboard.inventory.counts')
                    ),
                    AsideMenu::subMenu(
                        label: 'อัพโหลดสินค้า',
                        identifier: 'upload-products',
                        permissions: ['nexopos.create.products'],
                        href: ns()->url('/dashboard/inventory/upload')
                    ),
                ),
            ),

            // ==========================================
            // ภาษี (รอสรุปฟังก์ชัน)
            // ==========================================
            AsideMenu::menu(
                label: 'ภาษี',
                icon: 'la-balance-scale-left',
                identifier: 'taxes',
                permissions: [
                    'nexopos.read.taxes',
                    'nexopos.create.taxes',
                    'nexopos.reports.vat-return',
                    'nexopos.read.withholding-tax',
                    'nexopos.reports.withholding-tax',
                ],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'กลุ่มภาษี',
                        identifier: 'taxes-groups',
                        permissions: ['nexopos.read.taxes'],
                        href: ns()->url('/dashboard/taxes/groups')
                    ),
                    AsideMenu::subMenu(
                        label: 'รายการภาษี',
                        identifier: 'taxes-list',
                        permissions: ['nexopos.read.taxes'],
                        href: ns()->url('/dashboard/taxes')
                    ),
                    AsideMenu::subMenu(
                        label: 'รายงานภาษีขาย (ภ.พ.30)',
                        identifier: 'vat-return-report',
                        permissions: ['nexopos.reports.vat-return'],
                        href: ns()->url('/dashboard/reports/vat-return')
                    ),
                    AsideMenu::subMenu(
                        label: 'ภาษีหัก ณ ที่จ่าย',
                        identifier: 'withholding-tax-list',
                        permissions: ['nexopos.read.withholding-tax'],
                        href: ns()->url('/dashboard/taxes/withholding')
                    ),
                    AsideMenu::subMenu(
                        label: 'บันทึกการหักภาษี ณ ที่จ่าย',
                        identifier: 'withholding-tax-create',
                        permissions: ['nexopos.create.withholding-tax'],
                        href: ns()->url('/dashboard/taxes/withholding/create')
                    ),
                    AsideMenu::subMenu(
                        label: 'รายงานภาษีหัก ณ ที่จ่าย (ภ.ง.ด.3/53)',
                        identifier: 'withholding-tax-report',
                        permissions: ['nexopos.reports.withholding-tax'],
                        href: ns()->url('/dashboard/reports/withholding-tax')
                    ),
                ),
            ),

            // ==========================================
            // ผู้ใช้งาน
            // ==========================================
            AsideMenu::menu(
                label: 'ผู้ใช้งาน',
                icon: 'la-users',
                identifier: 'users',
                permissions: ['read.users', 'create.users', 'read.roles', 'create.roles'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'รายชื่อผู้ใช้งาน',
                        identifier: 'users-list',
                        permissions: ['read.users'],
                        href: ns()->url('/dashboard/users')
                    ),
                    AsideMenu::subMenu(
                        label: 'เพิ่ม/แก้ไขสิทธิ',
                        identifier: 'permissions-manager',
                        permissions: ['update.roles'],
                        href: ns()->url('/dashboard/users/roles/permissions-manager')
                    ),
                    AsideMenu::subMenu(
                        label: 'สร้างสิทธิผู้ใช้งาน กำหนดสิทธิ',
                        identifier: 'create-role',
                        permissions: ['create.roles'],
                        href: ns()->url('/dashboard/users/roles/create')
                    ),
                    AsideMenu::subMenu(
                        label: 'สร้างกลุ่มผู้ใช้งาน',
                        identifier: 'roles-list',
                        permissions: ['read.roles'],
                        href: ns()->url('/dashboard/users/roles')
                    ),
                ),
            ),

            // ==========================================
            // ความปลอดภัย
            // ==========================================

            /**
             * The three things an owner reaches for when the question is "who can do what, and who
             * did this". The roles screen is listed here as well as under ผู้ใช้งาน on purpose:
             * it is the same page, and somebody looking for permissions looks under security.
             */
            AsideMenu::menu(
                label: 'ความปลอดภัย',
                icon: 'la-shield-alt',
                identifier: 'security',
                permissions: ['read.roles', 'manage.options', 'nexopos.read.audit-trail'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'กำหนดสิทธิ์การใช้งานระบบ',
                        identifier: 'security-roles',
                        permissions: ['read.roles'],
                        href: ns()->url('/dashboard/users/roles')
                    ),
                    AsideMenu::subMenu(
                        label: 'กำหนดนโยบายรหัสผ่าน',
                        identifier: 'security-password-policy',
                        permissions: ['manage.options'],
                        href: ns()->url('/dashboard/settings/password')
                    ),
                    AsideMenu::subMenu(
                        label: 'ตรวจสอบการใช้งานย้อนหลัง',
                        identifier: 'security-audit-trail',
                        permissions: ['nexopos.read.audit-trail'],
                        href: ns()->url('/dashboard/audit-trail')
                    ),
                ),
            ),

            /**
             * "จัดการพื้นที่สำนักงาน" used to sit here. It belongs to staff attendance — an office
             * location is a pin on a map with a radius, used to decide whether an employee standing
             * outside is close enough for their clock-in to count. Nothing in a till, a stock count or
             * a tax invoice reads it. It is now where it belongs, in the personnel system on its own
             * host, alongside the shifts and leave records that give it meaning.
             *
             * The table and its four permission rows are left untouched in the database, so restoring
             * the removed files would bring the screen back exactly as it was. Nothing had been entered
             * into it — the table held no rows on the day it was taken out.
             */

            // ==========================================
            // จัดซื้อ
            // ==========================================
            AsideMenu::menu(
                label: 'จัดซื้อ',
                icon: 'la-truck-loading',
                identifier: 'procurements',
                permissions: ['nexopos.read.procurements', 'nexopos.create.procurements'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(label: 'รับเข้าสินค้า / ประวัติใบรับ', identifier: 'goods-receipts', permissions: ['nexopos.read.procurements'], href: route('ns.goods-receipts.index')),
                    AsideMenu::subMenu(
                        label: 'สร้างใบสั่งซื้อ',
                        identifier: 'create-procurement',
                        permissions: ['nexopos.create.procurements'],
                        href: ns()->url('/dashboard/procurements/create')
                    ),
                    AsideMenu::subMenu(
                        label: 'ใบสั่งซื้อ / รับสินค้า',
                        identifier: 'purchase-orders',
                        permissions: ['nexopos.read.procurements'],
                        href: ns()->route('ns.purchase-orders')
                    ),
                    AsideMenu::subMenu(label: 'ใบขอซื้อ / เทียบราคาผู้ขาย', identifier: 'purchase-requests', permissions: ['nexopos.read.procurements'], href: ns()->route('ns.purchasing.requests')),
                    AsideMenu::subMenu(label: 'คืนสินค้าให้ซัพพลายเออร์', identifier: 'purchase-returns', permissions: ['nexopos.read.procurements'], href: ns()->route('ns.purchasing.returns')),
                    AsideMenu::subMenu(label: 'วางแผนสั่งซื้อ (W/W · 1/3/6 เดือน)', identifier: 'replenishment-report', permissions: ['nexopos.read.procurements'], href: ns()->route('ns.reports.replenishment')),
                    AsideMenu::subMenu(label: 'รายงานจัดซื้อ', identifier: 'purchase-reports', permissions: ['nexopos.read.procurements'], href: ns()->route('ns.purchasing.report')),
                    AsideMenu::subMenu(label: 'ตรวจรับ / ยืนยันวางบิล', identifier: 'billing-approval', permissions: ['nexopos.read.procurements'], href: ns()->route('ns.billing-approval')),
                    AsideMenu::subMenu(label: 'สรุปวางบิลซัพพลายเออร์', identifier: 'supplier-billing', permissions: ['nexopos.read.procurements'], href: ns()->route('ns.supplier-billing')),
                    AsideMenu::subMenu(label: 'ใบสำคัญจ่าย', identifier: 'payment-vouchers', permissions: ['nexopos.read.procurements'], href: ns()->route('ns.payment-vouchers')),
                    ns()->hasRole('admin') ? AsideMenu::subMenu(label: 'รายการทดลอง / เอกสารเก่า', identifier: 'purchase-trials', permissions: ['nexopos.read.procurements'], href: ns()->route('ns.purchase-trials')) : [],
                    AsideMenu::subMenu(
                        label: 'เอกสารรับเข้า / เอกสารเดิม',
                        identifier: 'procurements-list',
                        permissions: ['nexopos.read.procurements'],
                        href: ns()->url('/dashboard/procurements')
                    ),
                ),
            ),

            // ==========================================
            // รายงาน
            // ==========================================
            AsideMenu::menu(
                label: 'รายงาน',
                icon: 'la-chart-pie',
                identifier: 'reports',
                permissions: [
                    'nexopos.reports.sales',
                    'nexopos.reports.inventory',
                    'nexopos.reports.stock-history',
                    'nexopos.reports.receivables',
                    'nexopos.reports.delivery-notes',
                    'nexopos.read.procurements',
                ],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'ดูยอดขายรายวัน / ตามกำหนด',
                        identifier: 'sales-report',
                        permissions: ['nexopos.reports.sales'],
                        href: ns()->url('/dashboard/reports/sales')
                    ),
                    /**
                     * The purchasing report already existed, under จัดซื้อ. It is listed here too
                     * because the shop's own list of reports asks for "รายงานการจัดซื้อ" and
                     * nobody looking for a report looks under จัดซื้อ for it. Same route, same
                     * permission — one page, reachable from both places it is expected to be.
                     */
                    AsideMenu::subMenu(
                        label: 'รายงานการจัดซื้อ',
                        identifier: 'purchase-report',
                        permissions: ['nexopos.read.procurements'],
                        href: ns()->route('ns.purchasing.report')
                    ),
                    AsideMenu::subMenu(
                        label: 'ลูกหนี้การค้า (อายุหนี้)',
                        identifier: 'receivables-report',
                        permissions: ['nexopos.reports.receivables'],
                        href: ns()->url('/dashboard/reports/receivables')
                    ),
                    AsideMenu::subMenu(
                        label: 'ทะเบียนใบส่งสินค้า/บิลเงินสด',
                        identifier: 'delivery-notes-cash-report',
                        permissions: ['nexopos.reports.delivery-notes'],
                        href: ns()->url('/dashboard/reports/delivery-notes/cash')
                    ),
                    AsideMenu::subMenu(
                        label: 'ทะเบียนใบส่งสินค้า/ใบฝากสินค้าชั่วคราว',
                        identifier: 'delivery-notes-consignment-report',
                        permissions: ['nexopos.reports.delivery-notes'],
                        href: ns()->url('/dashboard/reports/delivery-notes/consignment')
                    ),
                    AsideMenu::subMenu(
                        label: 'ยอดขายสินค้าแยกหมวด/แยกกลุ่ม',
                        identifier: 'sales-by-product',
                        permissions: ['nexopos.reports.sales'],
                        href: ns()->url('/dashboard/reports/sales-progress')
                    ),
                    AsideMenu::subMenu(
                        label: 'ยอดขายตามซัพพลายเออร์',
                        identifier: 'sales-by-supplier',
                        permissions: ['nexopos.reports.sales'],
                        href: ns()->url('/dashboard/reports/sold-stock')
                    ),
                    AsideMenu::subMenu(
                        label: 'รายงานสต็อก',
                        identifier: 'stock-report',
                        permissions: ['nexopos.reports.low-stock'],
                        href: ns()->url('/dashboard/reports/low-stock')
                    ),
                    AsideMenu::subMenu(
                        label: 'รายงานการเคลื่อนไหวสินค้า',
                        identifier: 'stock-movement',
                        permissions: ['nexopos.reports.stock-history'],
                        href: ns()->url('/dashboard/reports/stock-history')
                    ),
                    AsideMenu::subMenu(
                        label: 'รายงานประจำปี',
                        identifier: 'annual-report',
                        permissions: ['nexopos.reports.yearly'],
                        href: ns()->url('/dashboard/reports/annual-report')
                    ),
                ),
            ),

            // ==========================================
            // ตั้งค่า
            // ==========================================
            AsideMenu::menu(
                label: 'ตั้งค่า',
                icon: 'la-cogs',
                identifier: 'settings',
                permissions: ['manage.options'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: 'ทั่วไป',
                        identifier: 'general',
                        href: ns()->url('/dashboard/settings/general')
                    ),
                    AsideMenu::subMenu(
                        label: 'ขายสินค้า (POS)',
                        identifier: 'pos-settings',
                        href: ns()->url('/dashboard/settings/pos')
                    ),
                    AsideMenu::subMenu(
                        label: 'ภาษีไทย',
                        identifier: 'thai-tax-settings',
                        href: ns()->url('/dashboard/settings/thai-tax')
                    ),
                    AsideMenu::subMenu(
                        label: 'ลูกค้า',
                        identifier: 'customers-settings',
                        href: ns()->url('/dashboard/settings/customers')
                    ),
                    AsideMenu::subMenu(
                        label: 'คำสั่งซื้อ',
                        identifier: 'orders-settings',
                        href: ns()->url('/dashboard/settings/orders')
                    ),
                    AsideMenu::subMenu(
                        label: 'บัญชี',
                        identifier: 'accounting-settings',
                        href: ns()->url('/dashboard/settings/accounting')
                    ),
                    AsideMenu::subMenu(
                        label: 'ใบแจ้งหนี้',
                        identifier: 'invoices-settings',
                        href: ns()->url('/dashboard/settings/invoices')
                    ),
                    AsideMenu::subMenu(
                        label: 'ตั้งค่าใบเสร็จ',
                        identifier: 'receipt-builder',
                        href: ns()->url('/dashboard/settings/receipt-builder')
                    ),
                    AsideMenu::subMenu(
                        label: 'รายงาน',
                        identifier: 'reports-settings',
                        href: ns()->url('/dashboard/settings/reports')
                    ),
                ),
            ),
        );
    }

    /**
     * returns the list of available menus
     */
    public function getMenus()
    {
        $this->buildMenus();
        $this->menus = Hook::filter('ns-dashboard-menus', $this->menus);
        $this->toggleActive();

        return collect($this->menus)->filter(function ($menu) {
            return (! isset($menu['permissions']) || Gate::any($menu['permissions'])) && (! isset($menu['show']) || $menu['show'] === true);
        })->map(function ($menu) {
            $menu['childrens'] = collect($menu['childrens'] ?? [])->filter(function ($submenu) {
                return ! isset($submenu['permissions']) || Gate::any($submenu['permissions']);
            })->toArray();

            return $menu;
        });
    }

    /**
     * Will make sure active menu is toggled
     */
    public function toggleActive()
    {
        foreach ($this->menus as $identifier => &$menu) {
            if (isset($menu['href']) && $menu['href'] === url()->current()) {
                $menu['toggled'] = true;
            }

            if (isset($menu['childrens'])) {
                foreach ($menu['childrens'] as $subidentifier => &$submenu) {
                    if ($submenu['href'] === url()->current()) {
                        $menu['toggled'] = true;
                        $submenu['active'] = true;
                    }
                }
            }
        }
    }

    /**
     * Sets an account menu item
     */
    public function setAccountMenu($identifier, $label, $icon, $href)
    {
        $this->accountMenus[$identifier] = AsideMenu::menu(
            label: $label,
            icon: $icon,
            identifier: $identifier,
            href: $href,
        );
    }

    /**
     * Returns the account menus
     */
    public function getAccountMenus(): array
    {
        $this->accountMenus = Hook::filter('ns-account-menus', Menu::wrapper(
            Menu::item(
                label: 'โปรไฟล์',
                identifier: 'profile',
                icon: 'la-user-tie',
                href: ns()->route('ns.dashboard.users.profile'),
                permissions: ['manage.profile'],
            ),
            Menu::item(
                label: 'ออกจากระบบ',
                identifier: 'logout',
                icon: 'la-sign-out-alt',
                href: ns()->route('ns.logout'),
            ),
        ));

        return collect($this->accountMenus)->filter(function ($menu) {
            return (! isset($menu['permissions']) || Gate::any($menu['permissions'])) && (! isset($menu['show']) || $menu['show'] === true);
        })->toArray();
    }

    /**
     * Remove an account menu item
     */
    public function removeAccountMenu($identifier)
    {
        unset($this->accountMenus[$identifier]);
    }
}

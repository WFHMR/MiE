<?php

namespace Modules\NexoHR;

use App\Classes\AsideMenu;
use App\Classes\Hook;
use App\Services\Module;

class NexoHRModule extends Module
{
    public function __construct()
    {
        parent::__construct(__FILE__);
    }

    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');

        // Load views
        $this->loadViewsFrom(__DIR__.'/Resources/Views', 'NexoHR');

        // Register menu
        Hook::addFilter('ns-dashboard-menus', function ($menus) {
            $hrMenu = AsideMenu::menu(
                label: 'ระบบบริหารจัดการบุคคล',
                identifier: 'nexohr',
                icon: 'la-users-cog',
                permissions: ['nexohr.access'],
                childrens: AsideMenu::childrens(
                    AsideMenu::subMenu(
                        label: __('HR Dashboard'),
                        identifier: 'nexohr-dashboard',
                        href: route('nexohr.admin.dashboard'),
                        permissions: ['nexohr.admin']
                    ),
                    AsideMenu::subMenu(
                        label: __('Attendance'),
                        identifier: 'nexohr-attendance',
                        href: route('nexohr.admin.attendances.index'),
                        permissions: ['nexohr.attendance']
                    ),
                    AsideMenu::subMenu(
                        label: __('Overtime'),
                        identifier: 'nexohr-overtime',
                        href: route('nexohr.admin.overtimes.index'),
                        permissions: ['nexohr.overtime']
                    ),
                    AsideMenu::subMenu(
                        label: __('Leave'),
                        identifier: 'nexohr-leave',
                        href: route('nexohr.admin.leaves.index'),
                        permissions: ['nexohr.leave']
                    ),
                    AsideMenu::subMenu(
                        label: __('Payroll'),
                        identifier: 'nexohr-payroll',
                        href: route('nexohr.admin.payrolls.index'),
                        permissions: ['nexohr.payroll']
                    ),
                    AsideMenu::subMenu(
                        label: __('QR Code'),
                        identifier: 'nexohr-qrcode',
                        href: route('nexohr.admin.qr-code.index'),
                        permissions: ['nexohr.admin']
                    ),
                    AsideMenu::subMenu(
                        label: __('Settings'),
                        identifier: 'nexohr-settings',
                        href: route('nexohr.admin.settings.index'),
                        permissions: ['nexohr.settings']
                    )
                )
            );

            // Insert after users menu
            return array_insert_after($menus, 'users', $hrMenu);
        });

        // Register permissions
        $this->registerPermissions();
    }

    private function registerPermissions(): void
    {
        Hook::addFilter('ns-permissions', function ($permissions) {
            $permissions['nexohr'] = [
                'label' => __('HR Management'),
                'permissions' => [
                    'nexohr.access' => __('Access HR System'),
                    'nexohr.admin' => __('HR Administrator'),
                    'nexohr.attendance' => __('Manage Attendance'),
                    'nexohr.overtime' => __('Manage Overtime'),
                    'nexohr.leave' => __('Manage Leave'),
                    'nexohr.payroll' => __('Manage Payroll'),
                    'nexohr.settings' => __('Manage HR Settings'),
                ],
            ];

            return $permissions;
        });
    }
}

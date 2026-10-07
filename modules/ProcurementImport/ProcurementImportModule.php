<?php

/**
 * ProcurementImport Module
 * 
 * Handles CSV import for Products, Stock, and Procurement data
 * with complete Branch Isolation support
 * 
 * @package Modules\ProcurementImport
 * @version 1.0.0
 */

namespace Modules\ProcurementImport;

use App\Services\ModulesService;

class ProcurementImportModule
{
    const VERSION = '1.0.0';
    const IDENTIFIER = 'procurement-import';

    public static function permissions(): array
    {
        return [
            'procurement.import.view' => __('View CSV Import'),
            'procurement.import.create' => __('Upload & Import CSV'),
            'procurement.import.manage' => __('Manage Import History'),
        ];
    }

    public static function menus(): array
    {
        return [
            'dashboard' => [
                'label' => __('CSV Import'),
                'icon' => 'la-file-upload',
                'childrens' => [
                    [
                        'label' => __('Import Products'),
                        'route' => 'procurement-import.index',
                        'permission' => 'procurement.import.view',
                    ],
                    [
                        'label' => __('Import History'),
                        'route' => 'procurement-import.history',
                        'permission' => 'procurement.import.manage',
                    ],
                ],
            ],
        ];
    }
}

<?php

use App\Http\Controllers\Dashboard\TaxesController;
use Illuminate\Support\Facades\Route;

Route::get( '/taxes', [ TaxesController::class, 'listTaxes' ] )->name( ns()->routeName( 'ns.dashboard.taxes' ) );
Route::get( '/taxes/create', [ TaxesController::class, 'createTax' ] )->name( ns()->routeName( 'ns.dashboard.taxes.create' ) );
Route::get( '/taxes/edit/{tax}', [ TaxesController::class, 'editTax' ] )->name( ns()->routeName( 'ns.dashboard.taxes.edit' ) );
Route::get( '/taxes/groups', [ TaxesController::class, 'taxesGroups' ] )->name( ns()->routeName( 'ns.dashboard.taxes.groups' ) );
Route::get( '/taxes/groups/create', [ TaxesController::class, 'createTaxGroups' ] )->name( ns()->routeName( 'ns.dashboard.taxes.groups.create' ) );
Route::get( '/taxes/groups/edit/{group}', [ TaxesController::class, 'editTaxGroup' ] )->name( ns()->routeName( 'ns.dashboard.taxes.groups.edit' ) );

/**
 * Thai withholding tax (ภาษีหัก ณ ที่จ่าย). The certificate route is deliberately a web
 * route rather than an API one: it renders a printable document, and the CRUD action links
 * to it directly.
 */
Route::get( '/taxes/withholding', [ TaxesController::class, 'listWithholdingTaxes' ] )->name( ns()->routeName( 'ns.dashboard.taxes.withholding' ) );
Route::get( '/taxes/withholding/create', [ TaxesController::class, 'createWithholdingTax' ] )->name( ns()->routeName( 'ns.dashboard.taxes.withholding.create' ) );
Route::get( '/taxes/withholding/edit/{withholdingTax}', [ TaxesController::class, 'editWithholdingTax' ] )->name( ns()->routeName( 'ns.dashboard.taxes.withholding.edit' ) );
Route::get( '/taxes/withholding/certificate/{withholdingTax}', [ TaxesController::class, 'showWithholdingCertificate' ] )->name( ns()->routeName( 'ns.dashboard.taxes.withholding.certificate' ) );

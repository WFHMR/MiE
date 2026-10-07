<?php

use App\Http\Controllers\BranchLoyaltyController;
use App\Http\Controllers\Dashboard\CustomersController;
use App\Http\Controllers\Dashboard\CustomersGroupsController;
use App\Http\Controllers\Dashboard\RewardsSystemController;
use App\Http\Controllers\MemberImportController;
use Illuminate\Support\Facades\Route;

Route::get( '/customers/branch-points', [BranchLoyaltyController::class, 'index'] )->name( 'ns.loyalty' );
Route::post( '/customers/branch-points', [BranchLoyaltyController::class, 'save'] )->name( 'ns.loyalty.save' );
Route::post( '/customers/branch-points/enroll', [BranchLoyaltyController::class, 'enroll'] )->name( 'ns.loyalty.enroll' );
Route::get( '/customers/branch-points/context', [BranchLoyaltyController::class, 'context'] )->name( 'ns.loyalty.context' );

Route::get( '/customers/import-members', [MemberImportController::class, 'index'] )->name( 'ns.members.import' );
Route::get( '/customers/import-members/template', [MemberImportController::class, 'template'] )->name( 'ns.members.template' );
Route::post( '/customers/import-members/preview', [MemberImportController::class, 'preview'] )->name( 'ns.members.preview' );
Route::post( '/customers/import-members/{id}/confirm', [MemberImportController::class, 'confirm'] )->name( 'ns.members.confirm' );

Route::get( '/customers', [ CustomersController::class, 'listCustomers' ] )->name( ns()->routeName( 'ns.dashboard.customers-list' ) ); // @todo update
Route::get( '/customers/create', [ CustomersController::class, 'createCustomer' ] )->name( ns()->routeName( 'ns.dashboard.customers-create' ) );
Route::get( '/customers/edit/{customer}', [ CustomersController::class, 'editCustomer' ] )->name( ns()->routeName( 'ns.dashboard.customers-edit' ) );
Route::get( '/customers/{customer}/rewards', [ CustomersController::class, 'getCustomersRewards' ] )->name( ns()->routeName( 'ns.dashboard.customers-rewards-list' ) ); // @todo update
Route::get( '/customers/{customer}/rewards/edit/{reward}', [ CustomersController::class, 'editCustomerReward' ] )->name( ns()->routeName( 'ns.dashboard.customers-rewards-edit' ) );
Route::get( '/customers/{customer}/orders', [ CustomersController::class, 'getCustomersOrders' ] )->name( ns()->routeName( 'ns.dashboard.customers-orders-list' ) ); // @todo update
Route::get( '/customers/{customer}/coupons', [ CustomersController::class, 'getCustomersCoupons' ] )->name( ns()->routeName( 'ns.dashboard.customers-coupons-list' ) );
Route::get( '/customers/{customer}/coupons/{customerCoupon}/history', [ CustomersController::class, 'listCustomerCouponHistory' ] )->name( ns()->routeName( 'ns.dashboard.customers-coupons-history-list' ) );
Route::get( '/customers/{customer}/account-history', [ CustomersController::class, 'getCustomerAccountHistory' ] )->name( ns()->routeName( 'ns.dashboard.customers-account-history-list' ) ); // @todo update
Route::get( '/customers/{customer}/account-history/create', [ CustomersController::class, 'createCustomerAccountHistory' ] )->name( ns()->routeName( 'ns.dashboard.customers-account-history-create' ) );
/**
 * There is deliberately no edit page for a customer account history entry.
 *
 * The route used to be declared here pointing at CustomersController::editCustomerAccountHistory, a
 * method that does not exist, so the Edit button on the account history list answered 500 — and it is the
 * only one of these broken routes a user could reach by clicking rather than by typing a url.
 *
 * It is removed rather than implemented. A row here is a ledger entry: it carries the balance before and
 * after itself, and CustomerService::saveTransaction builds that chain while updateCustomerAccount moves
 * the customer's account_amount. The generic crud update would write a new amount and do neither —
 * beforePut and afterPut on CustomerAccountCrud are empty, and CustomerAccountHistoryAfterUpdatedEvent
 * has no listener — so the history would stop reconciling with the balance, silently. Correcting an entry
 * is done by recording the opposite movement, which is what the create page is for.
 */
Route::get( '/customers/groups', [ CustomersGroupsController::class, 'listCustomersGroups' ] )->name( ns()->routeName( 'ns.dashboard.customersgroups-list' ) ); // @todo update
Route::get( '/customers/groups/create', [ CustomersGroupsController::class, 'createCustomerGroup' ] )->name( ns()->routeName( 'ns.dashboard.customersgroups-create' ) );
Route::get( '/customers/groups/edit/{group}', [ CustomersGroupsController::class, 'editCustomerGroup' ] )->name( ns()->routeName( 'ns.dashboard.customersgroups-edit' ) );
Route::get( '/customers/rewards-system', [ RewardsSystemController::class, 'list' ] )->name( ns()->routeName( 'ns.dashboard.rewards-list' ) ); // @todo update
Route::get( '/customers/rewards-system/create', [ RewardsSystemController::class, 'create' ] )->name( ns()->routeName( 'ns.dashboard.rewards-create' ) );
Route::get( '/customers/rewards-system/edit/{reward}', [ RewardsSystemController::class, 'edit' ] )->name( ns()->routeName( 'ns.dashboard.rewards-edit' ) );
Route::get( '/customers/coupons', [ CustomersController::class, 'listCoupons' ] )->name( ns()->routeName( 'ns.dashboard.all-customers-coupons-list' ) ); // @todo update
Route::get( '/customers/coupons/create', [ CustomersController::class, 'createCoupon' ] )->name( ns()->routeName( 'ns.dashboard.customers-coupons-create' ) );
Route::get( '/customers/coupons/edit/{coupon}', [ CustomersController::class, 'editCoupon' ] )->name( ns()->routeName( 'ns.dashboard.customers-coupons-edit' ) );
Route::get( '/customers/coupons/history/{coupon}', [ CustomersController::class, 'couponHistory' ] )->name( ns()->routeName( 'ns.dashboard.customers-coupons-history' ) );
Route::get( '/customers/coupons-generated', [ CustomersController::class, 'listGeneratedCoupons' ] )->name( ns()->routeName( 'ns.dashboard.customers-coupons-generated-list' ) ); // @todo update
Route::get( '/customers/coupons-generated/edit/{coupon}', [ CustomersController::class, 'editGeneratedCoupon' ] )->name( ns()->routeName( 'ns.dashboard.customers-coupons-generated-edit' ) );

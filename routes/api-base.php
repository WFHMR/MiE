<?php

use App\Http\Controllers\SetupController;
use App\Http\Middleware\ClearRequestCacheMiddleware;
use App\Http\Middleware\InstalledStateMiddleware;
use App\Http\Middleware\NotInstalledStateMiddleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::middleware( [
    InstalledStateMiddleware::class,
    SubstituteBindings::class,
    ClearRequestCacheMiddleware::class,
] )->group( function () {
    include dirname( __FILE__ ) . '/api/fields.php';

    Route::middleware( [
        'auth:sanctum',
    ] )->group( function () {
        include dirname( __FILE__ ) . '/api/dashboard.php';
        include dirname( __FILE__ ) . '/api/categories.php';
        include dirname( __FILE__ ) . '/api/customers.php';
        include dirname( __FILE__ ) . '/api/transactions.php';
        include dirname( __FILE__ ) . '/api/modules.php';
        include dirname( __FILE__ ) . '/api/medias.php';
        include dirname( __FILE__ ) . '/api/notifications.php';
        include dirname( __FILE__ ) . '/api/orders.php';
        include dirname( __FILE__ ) . '/api/procurements.php';
        include dirname( __FILE__ ) . '/api/products.php';
        include dirname( __FILE__ ) . '/api/providers.php';
        include dirname( __FILE__ ) . '/api/registers.php';
        include dirname( __FILE__ ) . '/api/reset.php';
        include dirname( __FILE__ ) . '/api/reports.php';
        include dirname( __FILE__ ) . '/api/settings.php';

        /**
         * api/rewards.php is gone. Its five routes were upstream leftovers: reward-system/{id}/rules,
         * .../coupons, PUT and DELETE reward-system/{id} all named methods RewardsSystemController does
         * not have, so any user who held the permission got a 500, and POST reward-system pointed at the
         * method that renders the create form. Rewards are managed through api/crud/ns.rewards-system and
         * the pages in routes/web/customers.php, both of which are unaffected.
         */
        include dirname( __FILE__ ) . '/api/taxes.php';
        include dirname( __FILE__ ) . '/api/crud.php';
        include dirname( __FILE__ ) . '/api/forms.php';
        include dirname( __FILE__ ) . '/api/units.php';
        include dirname( __FILE__ ) . '/api/users.php';
        include dirname( __FILE__ ) . '/api/permissions.php';
        include dirname( __FILE__ ) . '/api/system.php';
        include dirname( __FILE__ ) . '/api/scan-utility.php';
        include dirname( __FILE__ ) . '/api/marketplace.php';
        include dirname( __FILE__ ) . '/api/branches.php';
        include dirname( __FILE__ ) . '/api/stock-transfers.php';
    } );
} );

include dirname( __FILE__ ) . '/api/update.php';

Route::prefix( 'setup' )
    ->middleware( [
        ClearRequestCacheMiddleware::class,
        NotInstalledStateMiddleware::class,
    ] )
    ->group( function () {
        Route::get( 'check-database', [ SetupController::class, 'checkExistingCredentials' ] );
        Route::post( 'database', [ SetupController::class, 'checkDatabase' ] );
        Route::get( 'database', [ SetupController::class, 'checkDbConfigDefined' ] );
        Route::post( 'configuration', [ SetupController::class, 'saveConfiguration' ] );
    } );

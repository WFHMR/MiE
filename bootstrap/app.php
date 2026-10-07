<?php

use App\Classes\ModuleRouting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure( basePath: dirname( __DIR__ ) )
    ->withRouting(
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        using: function () {
            Route::middleware( 'api' )
                ->prefix( 'api' )
                ->group( base_path( 'routes/api.php' ) );

            Route::middleware( 'web' )
                ->group( base_path( 'routes/web.php' ) );

            ModuleRouting::register();
        },
    )
    /**
     * These callbacks run on every application boot, so the included files must
     * run every time as well. "include_once" only executes on the first boot of
     * a PHP process, which left every application booted afterwards without the
     * middleware aliases, middleware groups and exception handlers below. A
     * single HTTP request boots once and was unaffected, but the test suite
     * boots an application per test, so every test after the first ran against
     * Laravel defaults instead of this application's configuration.
     */
    ->withSchedule( function ( Schedule $schedule ) {
        include __DIR__ . '/modules-schedule.php';
    } )
    ->withMiddleware( function ( Middleware $middleware ) {
        include __DIR__ . '/middleware.php';
    } )
    ->withExceptions( function ( Exceptions $exceptions ) {
        include __DIR__ . '/exceptions.php';
    } )->create();

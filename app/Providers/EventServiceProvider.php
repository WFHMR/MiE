<?php

namespace App\Providers;

use App\Classes\Hook;
use App\Events\AfterSuccessfulLoginEvent;
use App\Events\CrudAfterDeleteEvent;
use App\Events\CrudAfterPostEvent;
use App\Events\CrudAfterPutEvent;
use App\Events\SettingsSavedEvent;
use App\Filters\MenusFilter;
use App\Listeners\AuditTrailListener;
use App\Services\ModulesService;
use App\Services\OrdersService;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    protected $subscribe = [];

    /**
     * Get the listener directories that should be used to discover events.
     *
     * @return array
     */
    protected function discoverEventsWithin()
    {
        /**
         * @var ModulesService
         */
        $modulesServices = app()->make( ModulesService::class );

        $paths = $modulesServices->getEnabledAndAutoloadedModules()->map( function ( $module ) {
            return base_path( 'modules' . DIRECTORY_SEPARATOR . $module[ 'namespace' ] . DIRECTORY_SEPARATOR . 'Listeners' );
        } )
            ->values()
            ->toArray();

        return $paths;
    }

    public function shouldDiscoverEvents()
    {
        return true;
    }

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        Hook::addFilter( 'ns-dashboard-menus', [ MenusFilter::class, 'injectRegisterMenus' ] );
        Hook::addFilter( 'ns-common-routes', [ app()->make( OrdersService::class ), 'handlePOSRoute' ], 10, 3 );

        $this->registerAuditTrail();
    }

    /**
     * Wires the audit trail to the events the CRUD layer already fires.
     *
     * Registered by hand rather than by discovery: shouldDiscoverEvents() is true but
     * discoverEventsWithin() above returns the modules' Listeners folders only, so nothing in
     * app/Listeners is ever scanned. Listing them here also makes the wiring visible to whoever
     * reads this provider next.
     */
    private function registerAuditTrail(): void
    {
        Event::listen( CrudAfterPostEvent::class, [ AuditTrailListener::class, 'handleCreated' ] );
        Event::listen( CrudAfterPutEvent::class, [ AuditTrailListener::class, 'handleUpdated' ] );
        Event::listen( CrudAfterDeleteEvent::class, [ AuditTrailListener::class, 'handleDeleted' ] );
        Event::listen( AfterSuccessfulLoginEvent::class, [ AuditTrailListener::class, 'handleSignedIn' ] );
        Event::listen( SettingsSavedEvent::class, [ AuditTrailListener::class, 'handleSettingsSaved' ] );
    }
}

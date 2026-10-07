<?php

namespace Tests\Feature;

use App\Services\ModulesService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Loading modules must not depend on two calls to "is this installed?" agreeing.
 *
 * ModulesService held the options store in a typed property with no default. It was assigned in the
 * constructor only when Helper::installed() returned true, and read later behind a second call to
 * the same check. That check caches its answer for sixty seconds and treats any database error as
 * "not installed", so within a single request the two calls could disagree — a brief connection
 * failure, or the cache being cleared by a deploy, was enough. When they disagreed the read reached
 * an uninitialised property and the request died with
 *
 *   Typed property App\Services\ModulesService::$options must not be accessed before initialization
 *
 * which is what the shop's log recorded. The store is now resolved on first use, so the constructor's
 * answer no longer has to match the one given later.
 */
class ModulesServiceOptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget( 'ns-core-installed' );

        parent::tearDown();
    }

    /**
     * Reproduces the disagreement directly: built while the check says no, used while it says yes.
     */
    public function test_loading_modules_survives_the_installed_check_changing_mid_request(): void
    {
        Cache::set( 'ns-core-installed', false, 60 );

        $service = new ModulesService;

        Cache::set( 'ns-core-installed', true, 60 );

        $service->load();

        $this->assertTrue(
            true,
            'Loading modules threw because the options store was read before it was set.'
        );
    }

    public function test_the_module_list_is_readable_after_the_same_change(): void
    {
        Cache::set( 'ns-core-installed', false, 60 );

        $service = new ModulesService;

        Cache::set( 'ns-core-installed', true, 60 );

        $service->load();

        $modules = $service->get();

        $this->assertIsArray( $modules, 'The module list could not be read.' );
    }

    /**
     * The ordinary path has to keep working: built and used while the check says yes throughout.
     */
    public function test_loading_modules_works_when_the_check_is_steady(): void
    {
        Cache::set( 'ns-core-installed', true, 60 );

        $service = new ModulesService;
        $service->load();

        $this->assertIsArray( $service->get() );
    }

    /**
     * The installed modules are still reported as enabled, which is the value the property was read
     * for in the first place. NexoPOS is present on every install of this system.
     */
    public function test_an_installed_module_still_reports_its_enabled_state(): void
    {
        Cache::set( 'ns-core-installed', true, 60 );

        $service = new ModulesService;
        $service->load();

        $modules = $service->get();

        if ( $modules === [] ) {
            $this->markTestSkipped( 'No module is present on this installation.' );
        }

        $first = reset( $modules );

        $this->assertArrayHasKey(
            'enabled',
            $first,
            'The enabled flag is missing, so the options store was never consulted.'
        );
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AppServiceProvider falls back to Europe/London when ns_datetime_timezone has
 * never been set, so a fresh install stamps orders, reports and receipts with UK
 * time. On a Thai till that is six hours out during British Summer Time and seven
 * outside it, which puts evening sales on the wrong calendar day and makes both the
 * daily takings and the VAT period for that day wrong.
 *
 * Because the offset moves with UK daylight saving, the resulting data cannot be
 * corrected by applying a fixed shift afterwards. Setting this correctly before
 * trading starts is the only cheap fix, so the command that does it has to reject
 * anything the application will not accept.
 */
class SetTimezoneCommandTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $originalTimezone = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = ns()->option->get( 'ns_datetime_timezone' );
    }

    /**
     * The suite shares one fixture and this option drives how every date in the
     * application is rendered, so leaving it changed would move the timestamps a
     * later test asserts on.
     */
    protected function tearDown(): void
    {
        if ( $this->originalTimezone === null ) {
            ns()->option->delete( 'ns_datetime_timezone' );
        } else {
            ns()->option->set( 'ns_datetime_timezone', $this->originalTimezone );
        }

        parent::tearDown();
    }

    public function test_it_stores_a_supported_timezone(): void
    {
        $this->artisan( 'pos:set-timezone', [ 'timezone' => 'Asia/Bangkok' ] )
            ->assertSuccessful();

        $this->assertSame( 'Asia/Bangkok', ns()->option->get( 'ns_datetime_timezone' ) );
    }

    public function test_it_rejects_an_identifier_the_application_does_not_support(): void
    {
        ns()->option->set( 'ns_datetime_timezone', 'Asia/Bangkok' );

        $this->artisan( 'pos:set-timezone', [ 'timezone' => 'Mars/Olympus_Mons' ] )
            ->assertFailed();

        $this->assertSame(
            'Asia/Bangkok',
            ns()->option->get( 'ns_datetime_timezone' ),
            'A rejected value must leave the existing setting untouched.'
        );
    }

    public function test_a_typo_in_a_real_identifier_is_rejected_rather_than_stored(): void
    {
        /**
         * The wrong case, or a trailing space, would pass a naive check and then be
         * quietly ignored by PHP, leaving the site on the London fallback while the
         * setting reads as if it had been changed.
         */
        $before = ns()->option->get( 'ns_datetime_timezone' );

        $this->artisan( 'pos:set-timezone', [ 'timezone' => 'asia/bangkok' ] )
            ->assertFailed();

        $this->assertSame(
            $before,
            ns()->option->get( 'ns_datetime_timezone' ),
            'A rejected identifier must leave the stored value exactly as it was.'
        );
    }

    public function test_it_reports_the_current_setting_without_changing_anything(): void
    {
        ns()->option->set( 'ns_datetime_timezone', 'Asia/Bangkok' );

        $this->artisan( 'pos:set-timezone' )
            ->expectsOutputToContain( 'Asia/Bangkok' )
            ->assertSuccessful();

        $this->assertSame( 'Asia/Bangkok', ns()->option->get( 'ns_datetime_timezone' ) );
    }

    public function test_search_finds_the_identifier_by_city(): void
    {
        $this->artisan( 'pos:set-timezone', [ '--search' => 'Bangkok' ] )
            ->expectsOutputToContain( 'Asia/Bangkok' )
            ->assertSuccessful();
    }
}

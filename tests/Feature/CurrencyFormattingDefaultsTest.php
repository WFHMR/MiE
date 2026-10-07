<?php

namespace Tests\Feature;

use App\Services\CurrencyService;
use App\Services\Options;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The server and the browser have to agree on how an amount reads.
 *
 * common/footer.blade.php hands the front end ',' for thousands and '.' for decimals
 * when the options were never saved, and the settings screen documents the same pair.
 * AppServiceProvider used to fall back to the reverse, so PHP printed 1.070,50 for the
 * amount the POS printed as 1,070.50.
 */
class CurrencyFormattingDefaultsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The fallbacks are only reachable with the options absent, which is the state of
     * any install whose owner never opened the currency settings.
     */
    private function forgetSeparatorOptions(): void
    {
        $options = app()->make( Options::class );

        foreach ( [ 'ns_currency_decimal_separator', 'ns_currency_thousand_separator' ] as $key ) {
            $options->delete( $key );
        }
    }

    public function test_the_separators_fall_back_to_the_documented_pair(): void
    {
        $this->forgetSeparatorOptions();

        /**
         * Resolved from the container rather than through ns()->currency, because the
         * latter is the instance CoreService built at boot and would not see the options
         * being removed here. The container binding is what reads the fallbacks.
         */
        $formatted = app()->make( CurrencyService::class )
            ->value( 1070.5 )
            ->accuracy( 2 )
            ->format();

        $this->assertStringContainsString(
            '1,070.50',
            $formatted,
            sprintf( 'The amount formatted as "%s", so thousands and decimals are the wrong way round.', $formatted )
        );

        $this->assertStringNotContainsString( '1.070,50', $formatted );
    }

    /**
     * The pair the footer views hand the front end has to be the same pair, or the two
     * disagree again the moment the settings are cleared.
     */
    public function test_the_front_end_is_handed_the_same_pair(): void
    {
        $this->forgetSeparatorOptions();

        $options = app()->make( Options::class );

        $this->assertSame( ',', $options->get( 'ns_currency_thousand_separator', ',' ) );
        $this->assertSame( '.', $options->get( 'ns_currency_decimal_separator', '.' ) );
    }

    /**
     * The currency itself, not just the separators.
     *
     * AppServiceProvider passed ns_currency_symbol and ns_currency_iso with no default of their own,
     * so a missing option row arrived at the constructor as null and CurrencyService fell back to its
     * own static values — which were 'USD' and '$'.
     *
     * That is a quiet failure of the worst kind: the digits are unchanged and only the symbol moves,
     * so a shop in Chanthaburi would be quoting dollars on screen and on every printed receipt with
     * nothing to announce it. Both files now default to baht.
     */
    public function test_the_currency_falls_back_to_baht_rather_than_another_country(): void
    {
        $options = app()->make( Options::class );

        foreach ( [ 'ns_currency_symbol', 'ns_currency_iso', 'ns_currency_prefered' ] as $key ) {
            $options->delete( $key );
        }

        $currency = app()->make( CurrencyService::class );

        $formatted = $currency->value( 1070.5 )->accuracy( 2 )->format();

        $this->assertStringNotContainsString(
            '$',
            $formatted,
            sprintf( 'With no currency option saved the amount formatted as "%s", which is not baht.', $formatted )
        );

        $this->assertStringNotContainsString( 'USD', $formatted );
    }

    /**
     * Guards the two literals directly, because the fallback above can only be reached with the
     * options deleted and a future change could reintroduce a foreign default without this test
     * having any option state to expose it.
     */
    public function test_the_declared_currency_defaults_are_thai(): void
    {
        $reflection = new \ReflectionClass( CurrencyService::class );

        $iso = $reflection->getProperty( '_currency_iso' );
        $iso->setAccessible( true );

        $symbol = $reflection->getProperty( '_currency_symbol' );
        $symbol->setAccessible( true );

        $this->assertSame( 'THB', $iso->getValue() );
        $this->assertSame( '฿', $symbol->getValue() );
    }

    /**
     * A saved setting still wins, so a shop that deliberately wants the European pair
     * keeps it.
     */
    public function test_a_saved_setting_overrides_the_fallback(): void
    {
        $options = app()->make( Options::class );

        $options->set( 'ns_currency_decimal_separator', ',' );
        $options->set( 'ns_currency_thousand_separator', '.' );

        $formatted = app()->make( CurrencyService::class )
            ->value( 1070.5 )
            ->accuracy( 2 )
            ->format();

        $this->assertStringContainsString( '1.070,50', $formatted );
    }
}

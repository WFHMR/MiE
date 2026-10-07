<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaveSettingsTest extends TestCase
{
    use WithFaker;

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_save_settings()
    {
        Sanctum::actingAs(
            Role::namespace( 'admin' )->users->first(),
            ['*']
        );

        collect( Storage::disk( 'ns' )->files( 'app/Settings' ) )
            ->map( function ( $fileName ) {
                $fileName = collect( explode( '/', $fileName ) );
                $file = pathinfo( $fileName->last() );

                return 'App\\Settings\\' . $file[ 'filename' ];
            } )
            ->filter( function ( $class ) {
                $object = new $class;

                return array_key_exists( 'tabs', $object->getForm() );
            } )
            ->each( function ( $class ) {
                $object = new $class;
                $form = $object->getForm();

                $form = collect( $object->getForm()[ 'tabs' ] )->mapWithKeys( function ( $value, $key ) {
                    return [
                        $key => collect( $value[ 'fields' ] )
                            ->mapWithKeys( function ( $field ) {

                                if ( $field[ 'type' ] === 'select' && ! empty( $field[ 'options' ] ) ) {
                                    $rawField = collect( $field[ 'options' ] )->random();
                                }

                                return [
                                    $field[ 'name' ] => match ( $field[ 'name' ] ) {
                                        'ns_store_language' => 'en',
                                        'ns_currency_symbol' => '$',
                                        'ns_currency_iso' => 'USD',
                                        'ns_currency_thousand_separator' => '.',
                                        'ns_currency_decimal_separator' => ',',
                                        'ns_date_format' => 'Y-m-d',
                                        'ns_datetime_timezone' => 'Europe/London',
                                        'ns_datetime_format' => 'Y-m-d H:i',

                                        /**
                                         * The Thai tax page validates its inputs, so the
                                         * generated values this test uses elsewhere would
                                         * be rejected. A random string is not a taxpayer
                                         * identification number, and a five digit branch
                                         * code is not free text.
                                         *
                                         * The rate period is pinned to the one seeded by
                                         * the migration so saving this page does not open a
                                         * new period and leave the shared fixture with a
                                         * different rate for later tests.
                                         */
                                        'ns_thai_tax_id' => '0105536001107',
                                        'ns_thai_tax_branch_code' => '00000',
                                        'ns_thai_vat_rate' => 7,
                                        'ns_thai_vat_effective_from' => '2000-01-01',
                                        'ns_thai_vat_effective_to' => '',
                                        'ns_thai_vat_default_status' => 'standard',

                                        /**
                                         * The invoice prefix feeds the document numbering
                                         * series, so a random string here would leave the
                                         * shared fixture with a nonsense series for every
                                         * test that issues a tax invoice afterwards.
                                         */
                                        'ns_thai_tax_invoice_prefix' => 'TIV',
                                        'ns_thai_tax_invoice_start_number' => 0,

                                        /**
                                         * Same reasoning for the withholding tax
                                         * certificate series.
                                         */
                                        'ns_thai_wht_prefix' => 'WHT',
                                        'ns_thai_wht_start_number' => 0,
                                        default => (
                                            match ( $field[ 'type' ] ) {
                                                'text', 'textarea' => strstr( $field[ 'name' ], 'email' ) ? $this->faker->email() : $this->faker->text( 20 ),
                                                'select' => ! empty( $field[ 'options' ] ) ? ( $rawField[ 'value' ] ?? '' ) : '',
                                                default => $field[ 'value' ] ?? ''
                                            }
                                        )
                                    },
                                ];
                            } ),
                    ];
                } )->toArray();

                if ( ! empty( $object->getIdentifier() ) ) {
                    $response = $this
                        ->withSession( $this->app[ 'session' ]->all() )
                        ->json( 'POST', '/api/settings/' . $object->getIdentifier(), $form );

                    $response->assertJsonPath( 'status', 'success' );

                    foreach ( $form as $tab => $fields ) {
                        foreach ( $fields as $name => $value ) {
                            $value = ns()->option->get( $name );

                            if ( ! is_array( $value ) ) {
                                $this->assertTrue(
                                    ns()->option->get( $name ) == $value,
                                    sprintf(
                                        'Failed to assert that "%s" option has as value %s. Current value: %s',
                                        $name,
                                        $value,
                                        ns()->option->get( $name )
                                    )
                                );
                            }
                        }
                    }
                }
            } );
    }
}

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VatRate;
use App\Services\ThaiVatService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the Thai tax settings page.
 *
 * The page is the only place the shop declares its VAT registration and its rate,
 * so it has to reject a wrong taxpayer identification number at entry rather than
 * at print time, and it has to record a rate change as a dated period instead of
 * overwriting the previous one.
 */
class ThaiTaxSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private const VALID_TAX_ID = '0105536001107';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Role::namespace( 'admin' )->users->first();
    }

    public function test_the_settings_page_is_reachable(): void
    {
        /**
         * Plain actingAs, not Sanctum::actingAs: the latter makes Auth::guard()
         * return a RequestGuard for the rest of the test, and the dashboard layout
         * calls viaRemember(), which a RequestGuard does not implement. The two auth
         * modes cannot be mixed inside one test method.
         */
        $this->actingAs( $this->admin )
            ->json( 'GET', '/dashboard/settings/thai-tax' )
            ->assertStatus( 200 );
    }

    public function test_the_form_exposes_the_identity_and_vat_tabs(): void
    {
        $this->actAsApi();

        $response = $this->json( 'GET', '/api/settings/thai-tax' );

        $response->assertStatus( 200 );
        $response->assertJsonPath( 'tabs.identity.identifier', 'identity' );
        $response->assertJsonPath( 'tabs.vat.identifier', 'vat' );

        $fields = collect( $response->json( 'tabs.identity.fields' ) )->pluck( 'name' );

        $this->assertContains( 'ns_thai_tax_id', $fields );
        $this->assertContains( 'ns_thai_tax_branch_code', $fields );
        $this->assertContains( 'ns_thai_vat_registered', $fields );
    }

    public function test_it_rejects_a_taxpayer_id_that_fails_the_checksum(): void
    {
        $response = $this->postSettings( [
            'identity' => [ 'ns_thai_tax_id' => '0105536001108' ],
        ] );

        $response->assertStatus( 422 );
        $response->assertJsonValidationErrors( [ 'identity.ns_thai_tax_id' ] );
    }

    public function test_it_rejects_a_branch_code_that_is_not_five_digits(): void
    {
        $response = $this->postSettings( [
            'identity' => [ 'ns_thai_tax_branch_code' => 'HQ' ],
        ] );

        $response->assertStatus( 422 );
        $response->assertJsonValidationErrors( [ 'identity.ns_thai_tax_branch_code' ] );
    }

    public function test_a_shop_cannot_be_flagged_registered_without_a_valid_taxpayer_id(): void
    {
        $response = $this->postSettings( [
            'identity' => [
                'ns_thai_vat_registered' => 'yes',
                'ns_thai_tax_id' => '',
            ],
        ] );

        $response->assertStatus( 422 );
        $response->assertJsonValidationErrors( [ 'identity.ns_thai_vat_registered' ] );
    }

    public function test_saving_persists_the_registration_details(): void
    {
        $this->postSettings( [
            'identity' => [
                'ns_thai_vat_registered' => 'yes',
                'ns_thai_tax_id' => self::VALID_TAX_ID,
                'ns_thai_tax_registered_name' => 'บริษัท ทดสอบภาษี จำกัด',
                'ns_thai_tax_registered_address' => '99/1 ถนนทดสอบ แขวงทดสอบ เขตทดสอบ กรุงเทพมหานคร 10110',
                'ns_thai_tax_branch_code' => '00002',
            ],
        ] )->assertJsonPath( 'status', 'success' );

        $profile = app()->make( ThaiVatService::class )->taxpayerProfile();

        $this->assertSame( self::VALID_TAX_ID, $profile[ 'tax_id' ] );
        $this->assertSame( '00002', $profile[ 'branch_code' ] );
        $this->assertTrue( $profile[ 'registered' ] );
    }

    public function test_declaring_a_new_rate_opens_a_dated_period_and_closes_the_previous_one(): void
    {
        $this->givenOnlyRate( 7, '2020-01-01' );

        $this->postSettings( [
            'vat' => [
                'ns_thai_vat_rate' => 10,
                'ns_thai_vat_effective_from' => '2027-10-01',
                'ns_thai_vat_effective_to' => '',
                'ns_thai_vat_legal_reference' => 'มาตรา 80 แห่งประมวลรัษฎากร',
            ],
        ] )->assertJsonPath( 'status', 'success' );

        $vat = app()->make( ThaiVatService::class );

        $this->assertSame( 2, VatRate::count() );

        /**
         * The point of the dated period: a sale made before the change keeps the rate
         * it was taxed with, so a reprinted tax invoice still reconciles with the
         * PP30 return it was reported on.
         */
        $this->assertSame( 7.0, $vat->rateOn( '2027-09-30' ) );
        $this->assertSame( 10.0, $vat->rateOn( '2027-10-01' ) );

        $this->assertSame(
            '2027-09-30',
            VatRate::whereDate( 'effective_from', '2020-01-01' )->firstOrFail()->effective_to->toDateString()
        );
    }

    public function test_saving_without_changing_the_rate_does_not_pile_up_periods(): void
    {
        $this->givenOnlyRate( 7, '2020-01-01' );

        $payload = [
            'vat' => [
                'ns_thai_vat_rate' => 7,
                'ns_thai_vat_effective_from' => '2020-01-01',
                'ns_thai_vat_effective_to' => '',
            ],
        ];

        $this->postSettings( $payload )->assertJsonPath( 'status', 'success' );
        $this->postSettings( $payload )->assertJsonPath( 'status', 'success' );

        $this->assertSame( 1, VatRate::count() );
    }

    public function test_an_emptied_end_date_reopens_the_period(): void
    {
        VatRate::query()->delete();

        VatRate::factory()->create( [
            'label' => 'VAT 7%',
            'rate' => 7,
            'effective_from' => '2020-01-01',
            'effective_to' => '2027-09-30',
        ] );

        ns()->option->set( 'ns_thai_vat_effective_to', '2027-09-30' );

        $this->postSettings( [
            'vat' => [
                'ns_thai_vat_rate' => 7,
                'ns_thai_vat_effective_from' => '2020-01-01',
                'ns_thai_vat_effective_to' => '',
            ],
        ] )->assertJsonPath( 'status', 'success' );

        /**
         * SettingsPage drops empty values when flattening the posted form, so without
         * the explicit write in syncVatRatePeriod() the option would keep the old end
         * date while the rate table has none — and the two would disagree.
         */
        $this->assertSame( '', (string) ns()->option->get( 'ns_thai_vat_effective_to' ) );
        $this->assertSame( 1, VatRate::count() );
        $this->assertNull( VatRate::whereDate( 'effective_from', '2020-01-01' )->firstOrFail()->effective_to );
        $this->assertSame( 7.0, app()->make( ThaiVatService::class )->rateOn( '2030-01-01' ) );
    }

    public function test_it_rejects_an_end_date_that_is_not_a_date(): void
    {
        $response = $this->postSettings( [
            'vat' => [
                'ns_thai_vat_rate' => 7,
                'ns_thai_vat_effective_from' => '2027-10-01',
                'ns_thai_vat_effective_to' => 'not-a-date',
            ],
        ] );

        $response->assertStatus( 422 );
        $response->assertJsonValidationErrors( [ 'vat.ns_thai_vat_effective_to' ] );
    }

    /**
     * Posts a complete, valid payload with the provided tabs merged over the
     * defaults, authenticating with a Sanctum token as the API expects.
     */
    private function postSettings( array $overrides = [] )
    {
        $this->actAsApi();

        $payload = [
            'identity' => [
                'ns_thai_vat_registered' => 'no',
                'ns_thai_tax_id' => self::VALID_TAX_ID,
                'ns_thai_tax_registered_name' => 'ร้านทดสอบ',
                'ns_thai_tax_registered_address' => 'ที่อยู่ทดสอบ',
                'ns_thai_tax_branch_code' => '00000',
            ],
            'vat' => [
                'ns_thai_vat_rate' => 7,
                'ns_thai_vat_effective_from' => '2020-01-01',
                'ns_thai_vat_effective_to' => '',
                'ns_thai_vat_legal_reference' => '',
                'ns_thai_vat_default_status' => ThaiVatService::STATUS_STANDARD,
            ],

            /**
             * The settings page validates every tab it declares, whether or not the caller
             * sent it, so a payload that omits the invoice tab is rejected for a missing
             * required field rather than saved. The real form always posts all tabs.
             */
            'invoice' => [
                'ns_thai_tax_invoice_auto_abbreviated' => 'no',
                'ns_thai_tax_invoice_prefix' => 'TIV',
                'ns_thai_tax_invoice_start_number' => 0,
                'ns_thai_tax_invoice_footer' => '',
            ],
            'withholding' => [
                'ns_thai_wht_enabled' => 'no',
                'ns_thai_wht_default_rate' => '3',
                'ns_thai_wht_default_income_type' => '40(8)',
                'ns_thai_wht_prefix' => 'WHT',
                'ns_thai_wht_start_number' => 0,
                'ns_thai_wht_footer' => '',
            ],
        ];

        foreach ( $overrides as $tab => $fields ) {
            $payload[ $tab ] = array_merge( $payload[ $tab ] ?? [], $fields );
        }

        return $this->json( 'POST', '/api/settings/thai-tax', $payload );
    }

    private function actAsApi(): void
    {
        Sanctum::actingAs( $this->admin, [ '*' ] );
    }

    /**
     * Leaves exactly one open ended rate period in place.
     */
    private function givenOnlyRate( float $rate, string $from ): void
    {
        VatRate::query()->delete();

        VatRate::factory()->create( [
            'label' => 'VAT ' . $rate . '%',
            'rate' => $rate,
            'effective_from' => $from,
            'effective_to' => null,
        ] );
    }
}

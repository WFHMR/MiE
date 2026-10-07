<?php

namespace Tests\Feature;

use App\Models\OrderPayment;
use App\Models\PaymentType;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HardResetTest extends TestCase
{
    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_hard_reset_system()
    {
        Artisan::call( 'ns:reset --mode=hard' );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/setup/configuration', [
                'ns_store_name' => env( 'NS_RESET_APPNAME', 'NexoPOS' ),
                'admin_email' => env( 'NS_RESET_MAIL', 'contact@nexopos.com' ),
                'admin_username' => env( 'NS_RESET_USERNAME', 'admin' ),
                'password' => env( 'NS_RESET_PASSWORD', 123456 ),
                'confirm_password' => env( 'NS_RESET_PASSWORD', 123456 ),
            ] );

        $response->assertStatus( 200 );

        /**
         * The four channels a Thai shop takes at the till, plus the customer account
         * channel which is created inactive — the refund path settles a refund to store
         * credit through that identifier, so the row has to exist even though it is not
         * offered at the POS. See SetupService::createDefaultPayment.
         */
        $this->assertEquals( 5, PaymentType::count() );
        $this->assertEquals( 4, PaymentType::active()->count() );

        $this->assertFalse(
            (bool) PaymentType::identifier( OrderPayment::PAYMENT_ACCOUNT )->firstOrFail()->active,
            'The customer account channel must be installed switched off.'
        );

        return $this->assertTrue( true );
    }
}

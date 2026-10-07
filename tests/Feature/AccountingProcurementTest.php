<?php

namespace Tests\Feature;

use App\Models\Procurement;
use PHPUnit\Framework\Attributes\Depends;
use Tests\TestCase;
use Tests\Traits\WithAccountingTest;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithProcurementTest;
use Tests\Traits\WithProviderTest;

/**
 * The accounting side of receiving goods: an unpaid purchase, a paid one, and one paid after the fact.
 *
 * This file extended Modules\NsGastro\Tests\TestCase, and that module is not installed here, so the
 * file could not even be loaded — which is also why it was in no phpunit suite. Every trait it needs
 * lives in tests/Traits, so the only thing standing between it and running was the parent class.
 * Until now nothing at all checked that receiving goods writes the ledger entries it claims to.
 */
class AccountingProcurementTest extends TestCase
{
    use WithAccountingTest, WithAuthentication, WithProcurementTest, WithProviderTest;

    public function test_create_procurement()
    {
        $this->attemptAuthenticate();
        $response = $this->attemptCreateAnUnpaidProcurement();
        $procurement = Procurement::findOrFail( $response[ 'data' ][ 'procurement' ][ 'id' ] );
        $this->attemptTestAccountingForProcurement( $procurement );

        return $procurement;
    }

    public function test_create_paid_procurement()
    {
        $this->attemptAuthenticate();
        $response = $this->attemptPaidProcurement();

        $this->attemptTestAccountingForProcurement( Procurement::findOrFail( $response[ 'data' ][ 'procurement' ][ 'id' ] ) );
    }

    #[Depends( 'test_create_procurement' )]
    public function test_create_procurement_and_pay( $procurement )
    {
        $this->attemptAuthenticate();
        $this->attemptPayUnpaidProcurement( $procurement->id );
        $this->attemptTestAccountingForPreviouslyUnpaidProcurement( $procurement );
    }
}

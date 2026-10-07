<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Procurement;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Covers the purchase order list at dashboard/procurements.
 *
 * The list is built from an inner join onto the providers table, so a procurement whose provider
 * cannot be resolved is dropped from the result entirely. The record still exists, its stock lines
 * still exist, and the totals still count — but the shop cannot see it, edit it, or reconcile it.
 * A screen that silently omits records is worse than one that shows them incomplete, because nothing
 * signals that anything is missing.
 *
 * provider_id is a plain NOT NULL integer with no foreign key, so a zero is perfectly storable, and
 * the bulk stock importer writes exactly that.
 */
class ProcurementListVisibilityTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * Creates a procurement, filling every column the table insists on.
     *
     * Written out rather than taken from a factory because no procurement factory exists, and the
     * point of these tests is what happens with deliberately unresolvable references.
     */
    /**
     * A supplier to hang a purchase order off.
     *
     * Built by hand rather than from a factory: the registered ProvidersFactory does not fill
     * first_name, which the table requires, so creating through it fails before the test begins.
     */
    private function givenProvider(): Provider
    {
        $provider = new Provider;

        $provider->first_name = 'ซัพพลายเออร์ทดสอบ ' . uniqid();
        $provider->author_id = User::first()->id;
        $provider->save();

        return $provider;
    }

    private function givenProcurement( int $providerId, ?int $authorId = null ): Procurement
    {
        $procurement = new Procurement;

        $procurement->name = 'ทดสอบใบสั่งซื้อ ' . uniqid();
        $procurement->provider_id = $providerId;
        $procurement->value = 0;
        $procurement->cost = 0;
        $procurement->tax_value = 0;
        $procurement->payment_status = 'unpaid';
        $procurement->delivery_status = 'pending';
        $procurement->total_items = 0;
        $procurement->author_id = $authorId ?? User::first()->id;
        $procurement->save();

        return $procurement;
    }

    /**
     * Reads the purchase order list the way the screen does and reports whether a row is present.
     */
    private function listContains( int $procurementId ): bool
    {
        $response = $this->json( 'GET', 'api/crud/ns.procurements?per_page=200' );

        $response->assertStatus( 200 );

        foreach ( $response->json( 'data' ) ?? [] as $entry ) {
            if ( (int) ( $entry[ 'id' ] ?? 0 ) === $procurementId ) {
                return true;
            }
        }

        return false;
    }

    public function test_a_procurement_with_a_real_provider_is_listed(): void
    {
        $this->attemptAuthenticate();

        $procurement = $this->givenProcurement( $this->givenProvider()->id );

        $this->assertTrue(
            $this->listContains( $procurement->id ),
            'A procurement with a resolvable provider has to appear on the list.'
        );
    }

    public function test_a_procurement_without_a_provider_is_still_listed(): void
    {
        $this->attemptAuthenticate();

        /**
         * Zero is what the stock importer stores when no supplier was named. No provider carries that
         * id, so the inner join used to discard the row.
         */
        $procurement = $this->givenProcurement( 0 );

        $this->assertTrue(
            $this->listContains( $procurement->id ),
            'A procurement whose provider cannot be resolved must still be visible, otherwise received stock exists in the accounts with nothing on screen to account for it.'
        );
    }

    public function test_a_supplier_holding_purchase_orders_cannot_be_deleted(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        $this->givenProcurement( $provider->id );

        /**
         * Recorded because it is the reason the orphan case is rare rather than routine: the
         * application refuses to remove a supplier that still has purchase orders against it. Worth
         * pinning, since the list fix below would otherwise look like the only thing standing between
         * the shop and missing records.
         */
        $this->expectException( NotAllowedException::class );

        $provider->delete();
    }

    public function test_a_procurement_pointing_at_a_supplier_row_that_is_gone_is_still_listed(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider();
        $procurement = $this->givenProcurement( $provider->id );

        /**
         * Removed underneath the guard on purpose. Rows like this exist on installations that predate
         * the dependency check or were loaded in bulk, and the list has to cope with them rather than
         * pretend the purchase order was never recorded.
         */
        DB::table( 'nexopos_providers' )->where( 'id', $provider->id )->delete();

        $this->assertTrue(
            $this->listContains( $procurement->id ),
            'A purchase order whose supplier row is gone must still be visible.'
        );
    }

    public function test_a_procurement_whose_author_no_longer_exists_is_still_listed(): void
    {
        $this->attemptAuthenticate();

        /**
         * An id far outside the table stands in for an author who was removed. The author join has the
         * same shape as the provider one, so it fails the same way.
         */
        $procurement = $this->givenProcurement( $this->givenProvider()->id, 99999999 );

        $this->assertTrue(
            $this->listContains( $procurement->id ),
            'A purchase order must survive the removal of the staff member who recorded it.'
        );
    }

    public function test_the_list_reports_a_total_that_matches_the_stored_rows(): void
    {
        $this->attemptAuthenticate();

        $before = $this->json( 'GET', 'api/crud/ns.procurements?per_page=1' )->json( 'total' );

        $this->givenProcurement( 0 );

        $after = $this->json( 'GET', 'api/crud/ns.procurements?per_page=1' )->json( 'total' );

        $this->assertSame(
            (int) $before + 1,
            (int) $after,
            'The counter above the list has to move with the stored rows, or a shop reconciling purchases against it will be misled.'
        );
    }
}

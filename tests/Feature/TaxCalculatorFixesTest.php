<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderTax;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\User;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression cover for three defects in the tax layer that were silent.
 *
 * None of them raised an error. getTaxGroupVatValue() returned 0.00 for every
 * input, Tax::group() resolved to null, and OrderTax::order() queried a column
 * that does not exist. Anything built on top of them — a VAT report, a tax
 * invoice — would have looked plausible and been wrong.
 */
class TaxCalculatorFixesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tax_group_vat_value_matches_the_single_rate_calculation(): void
    {
        /**
         * @var TaxService
         */
        $taxService = app()->make( TaxService::class );

        $group = $this->givenTaxGroupWithRate( 7.0 );

        /**
         * Previously both branches returned 0.00: the inclusive branch called the
         * "with tax" helper, which only computes for exclusive amounts and otherwise
         * hands the value straight back, so the subtraction cancelled out. The
         * exclusive branch had the mirror image of the same mistake.
         */
        $inclusive = $taxService->getTaxGroupVatValue( 'inclusive', $group, 107.0 );
        $exclusive = $taxService->getTaxGroupVatValue( 'exclusive', $group, 100.0 );

        $this->assertNotEquals( 0.0, $inclusive, 'The inclusive VAT of 107.00 at 7% cannot be zero.' );
        $this->assertNotEquals( 0.0, $exclusive, 'The exclusive VAT of 100.00 at 7% cannot be zero.' );

        $this->assertEqualsWithDelta( 7.0, $inclusive, 0.01 );
        $this->assertEqualsWithDelta( 7.0, $exclusive, 0.01 );

        /**
         * The group variant has to agree with the single rate variant, which was
         * already correct. That is the invariant the fix restores.
         */
        $this->assertEqualsWithDelta(
            $taxService->getVatValue( 'inclusive', 7.0, 107.0 ),
            $inclusive,
            0.01
        );

        $this->assertEqualsWithDelta(
            $taxService->getVatValue( 'exclusive', 7.0, 100.0 ),
            $exclusive,
            0.01
        );
    }

    public function test_tax_group_vat_value_sums_the_rates_of_a_multi_rate_group(): void
    {
        /**
         * @var TaxService
         */
        $taxService = app()->make( TaxService::class );

        $group = $this->givenTaxGroupWithRate( 8.0 );

        $second = new Tax;
        $second->name = 'Second rate';
        $second->description = 'Second rate of the group';
        $second->rate = 2.0;
        $second->tax_group_id = $group->id;
        $second->author_id = $group->author_id;
        $second->save();

        $group->load( 'taxes' );

        $this->assertEqualsWithDelta(
            $taxService->getVatValue( 'exclusive', 10.0, 100.0 ),
            $taxService->getTaxGroupVatValue( 'exclusive', $group, 100.0 ),
            0.01
        );
    }

    public function test_an_unknown_tax_type_yields_no_tax(): void
    {
        /**
         * @var TaxService
         */
        $taxService = app()->make( TaxService::class );

        $group = $this->givenTaxGroupWithRate( 7.0 );

        /**
         * The method is typed float, so the literal 0 in the fallback branch is
         * coerced on the way out.
         */
        $this->assertSame( 0.0, $taxService->getTaxGroupVatValue( 'disabled', $group, 107.0 ) );
        $this->assertSame( 0.0, $taxService->getTaxGroupVatValue( null, $group, 107.0 ) );
    }

    public function test_a_tax_resolves_back_to_its_group(): void
    {
        $group = $this->givenTaxGroupWithRate( 7.0 );
        $tax = $group->taxes()->firstOrFail();

        /**
         * The relation used to point at App\Models\Group, which does not exist, and
         * discarded the relation object instead of returning it. Both defects hid
         * each other: the missing return meant the missing class was never autoloaded.
         */
        $this->assertInstanceOf( TaxGroup::class, $tax->group );
        $this->assertSame( $group->id, $tax->group->id );
    }

    public function test_an_order_tax_resolves_back_to_its_order(): void
    {
        $order = Order::first();

        if ( ! $order instanceof Order ) {
            $this->markTestSkipped( 'No order available in the fixture to attach a tax line to.' );
        }

        $orderTax = new OrderTax;
        $orderTax->order_id = $order->id;
        $orderTax->tax_name = 'VAT 7%';
        $orderTax->tax_value = 7.0;
        $orderTax->rate = 7.0;
        $orderTax->save();

        /**
         * The foreign key and owner key were inverted, so this joined
         * nexopos_orders.order_id — a column that does not exist.
         */
        $this->assertInstanceOf( Order::class, $orderTax->order );
        $this->assertSame( $order->id, $orderTax->order->id );
    }

    private function givenTaxGroupWithRate( float $rate ): TaxGroup
    {
        $authorId = User::first()?->id;

        $group = new TaxGroup;
        $group->name = 'Regression group ' . uniqid();
        $group->description = 'Created by TaxCalculatorFixesTest';
        $group->author_id = $authorId;
        $group->save();

        $tax = new Tax;
        $tax->name = 'Rate ' . $rate;
        $tax->description = 'Created by TaxCalculatorFixesTest';
        $tax->rate = $rate;
        $tax->tax_group_id = $group->id;
        $tax->author_id = $authorId;
        $tax->save();

        return $group->fresh( 'taxes' );
    }
}

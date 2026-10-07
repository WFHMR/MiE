<?php

namespace Tests\Feature;

use App\Crud\ProductCrud;
use App\Models\Order;
use App\Models\Product;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * A product that declares its price includes VAT, but carries no tax group, is a contradiction the till
 * accepts in silence.
 *
 * Thirteen products were created by hand through the dashboard between the 15th and the 21st with
 * tax_group_id NULL and tax_type 'inclusive'. Five were sold. Their order lines recorded tax_value 0.00
 * while the bill header charged VAT on the whole total, so the sales report showed a tax column that did
 * not add up to the tax on the receipt — 25.97 baht adrift across the month.
 *
 * The VAT return itself was unharmed: getVatReturnReport() sums total_price by vat_status and derives the
 * tax from gross, so it never reads the broken per-line figure. That is worth stating, because the fix
 * here is about the reports agreeing with each other, not about a filing shortfall.
 */
class ProductTaxGroupIsRequiredTest extends TestCase
{
    use WithAuthentication;

    public function test_the_product_form_will_not_accept_a_product_with_no_tax_group(): void
    {
        $this->attemptAuthenticate();

        $form = ( new ProductCrud )->getForm();
        $field = $this->findField( $form, 'tax_group_id' );

        $this->assertNotNull( $field, 'The product form no longer offers a tax group at all.' );

        $this->assertSame(
            'required',
            $field[ 'validation' ] ?? '',
            'A product can be saved with no tax group again. Sold, its line records no tax while the bill'
                . ' charges VAT, and the two reports stop agreeing.'
        );
    }

    /**
     * Blank was the old default and is what produced the thirteen. The shop setting is a sane starting
     * point, and leaves the cashier free to change it.
     */
    public function test_the_tax_group_defaults_to_the_shop_setting(): void
    {
        $this->attemptAuthenticate();

        $expected = ns()->option->get( 'ns_pos_tax_group' );

        if ( empty( $expected ) ) {
            $this->markTestSkipped( 'The shop has no default tax group configured.' );
        }

        $form = ( new ProductCrud )->getForm();
        $field = $this->findField( $form, 'tax_group_id' );

        $this->assertSame(
            $expected,
            $field[ 'value' ],
            'The tax group no longer starts from the shop setting, so it is blank again by default.'
        );
    }

    /**
     * The condition that caused it, asserted against the data rather than the form: nothing sellable
     * should declare a tax type while carrying no group.
     */
    public function test_no_available_product_declares_a_tax_type_without_a_group(): void
    {
        $offenders = Product::whereNull( 'tax_group_id' )
            ->orWhere( 'tax_group_id', 0 )
            ->get()
            ->filter( fn ( Product $product ) => in_array( $product->tax_type, [ 'inclusive', 'exclusive' ], true ) );

        $this->assertCount(
            0,
            $offenders,
            'These products claim a tax type with no tax group, so every line they are sold on will record'
                . ' zero tax: ' . $offenders->pluck( 'name' )->take( 5 )->implode( ', ' )
        );
    }

    /**
     * There is deliberately no assertion here that every paid line records tax.
     *
     * Two attempts at one failed, and both failures were correct. The test fixture runs with ns_pos_vat
     * disabled, so seeded sales legitimately carry no tax at all — even for products that do have a tax
     * group. Asserting otherwise was testing the seeder, not the code, and the only way to make it pass
     * would have been to weaken it until it asserted nothing.
     *
     * The invariant above — no sellable product declaring a tax type without a group — is the condition
     * that caused the defect and it is checkable here. Whether the live order lines agree with their
     * bills is a question about production data, and it is verified against production directly.
     */
    public function test_the_form_field_is_reachable_where_the_cashier_works(): void
    {
        $this->attemptAuthenticate();

        $form = ( new ProductCrud )->getForm();

        $this->assertNotNull(
            $this->findField( $form, 'tax_type' ),
            'The tax type field vanished, which would leave the tax group with nothing to apply.'
        );

        $this->assertNotNull(
            $this->findField( $form, 'vat_status' ),
            'The Thai VAT status field vanished. The PP30 return is built from it, so losing it would move'
                . ' sales into the wrong bucket on the filing.'
        );
    }

    /**
     * @param  array<string, mixed>      $form
     * @return array<string, mixed>|null
     */
    private function findField( array $form, string $name ): ?array
    {
        /**
         * The product form nests its tabs under `variations`, one entry per variation, rather than at the
         * top level the way most CRUD forms do. Looking only at the top level found nothing and made this
         * report that the field had been removed.
         */
        $tabGroups = [ $form[ 'tabs' ] ?? [] ];

        foreach ( $form[ 'variations' ] ?? [] as $variation ) {
            $tabGroups[] = $variation[ 'tabs' ] ?? [];
        }

        foreach ( $tabGroups as $tabs ) {
            foreach ( $tabs as $tab ) {
                foreach ( $tab[ 'fields' ] ?? [] as $field ) {
                    if ( ( $field[ 'name' ] ?? '' ) === $name ) {
                        return $field;
                    }
                }
            }
        }

        return null;
    }
}

<?php

namespace App\Forms;

use App\Classes\Hook;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Services\SettingsPage;
use Illuminate\Support\Collection;

class ProcurementForm extends SettingsPage
{
    const IDENTIFIER = 'ns.procurement';

    public $form;

    public function __construct()
    {
        if ( ! empty( request()->route( 'identifier' ) ) ) {
            $procurement = Procurement::with( 'products' )
                ->with( 'provider' )
                ->find( request()->route( 'identifier' ) );
        }

        $this->form = Hook::filter( 'ns-procurement-form', [
            'main' => [
                'name' => 'name',
                'type' => 'text',
                'value' => $procurement->name ?? '',
                'label' => __( 'Procurement Name' ),
                'description' => __( 'Provide a name that will help to identify the procurement.' ),
            ],
            'columns' => Hook::filter( 'ns-procurement-columns', [
                'name' => [
                    'label' => __( 'Name' ),
                    'type' => 'name',
                ],
                'purchase_price_edit' => [
                    'label' => __( 'Unit Price' ),
                    'type' => 'text',
                ],
                'tax_value' => [
                    'label' => __( 'Tax Value' ),
                    'type' => 'currency',
                ],
                'quantity' => [
                    'label' => __( 'Quantity' ),
                    'type' => 'text',
                ],
                'total_purchase_price' => [
                    'label' => __( 'Total Price' ),
                    'type' => 'currency',
                ],
            ] ),
            'products' => isset( $procurement ) ? $this->buildProductLines( $procurement ) : [],
            'tabs' => [
                'general' => include ( dirname( __FILE__ ) . '/procurement/general.php' ),
            ],
        ] );
    }

    /**
     * Builds the editable product lines of a purchase.
     *
     * Two faults were fixed here, both of which the shop hit on its only purchase record.
     *
     * The lines were resolved with `Product::findOrFail()`, one query per line, so a single line
     * whose product had been deleted took the whole edit page down with a 404 model exception.
     * That is not an edge case in this shop: the catalogue has been deleted and re-imported, so
     * every product now sits in a new id range and every line of the earlier purchase points at
     * an id that no longer exists. A purchase that cannot be opened cannot be corrected either.
     *
     * The line after it read `$product->load( 'unit_quantities.unit' )->get()`. `load()` returns
     * the model, and `get()` on a model instance is forwarded to a fresh query builder — so this
     * fetched the **entire product catalogue** once per line and discarded the result. On a
     * purchase of 15,462 lines that is 15,462 full-table reads for nothing.
     *
     * A line whose product is gone is kept and marked invalid rather than dropped. The Vue page
     * already paints an invalid line with a red border and already guards for missing unit
     * quantities, so the person editing sees exactly which lines need attention. Dropping them
     * would be worse than the crash: the page would look healthy and silently rewrite the
     * document the moment it was saved.
     *
     * @return Collection<int, ProcurementProduct>
     */
    private function buildProductLines( Procurement $procurement )
    {
        $lines = $procurement->products;

        $products = Product::with( 'unit_quantities.unit' )
            ->whereIn( 'id', $lines->pluck( 'product_id' )->filter()->unique()->values()->all() )
            ->get()
            ->keyBy( 'id' );

        return $lines->map( function ( $line ) use ( $products ) {
            $product = $products->get( $line->product_id );

            $line->procurement = array_merge( $line->toArray(), [
                '$invalid' => $product === null,
                'purchase_price_edit' => $line->purchase_price,
            ] );

            $line->unit_quantities = $product?->unit_quantities ?? [];

            return $line;
        } );
    }
}

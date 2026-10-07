<?php
use App\Models\TaxInvoice;

/**
 * Picks the geometry the document is meant to be printed on.
 *
 * The two Thai tax invoice types are not the same document at different sizes. An
 * abbreviated invoice is handed over at the counter on the 58mm thermal roll and shows
 * VAT inclusive line amounts. A full form invoice is filed by a buyer who will claim the
 * VAT as input tax, so it goes on A4 stationery, carries the buyer's registration
 * details and shows the tax as its own amount.
 *
 * Kept as a two line dispatcher rather than one template with size branches throughout,
 * because that is how the previous version drifted: every block carried an $isFull test
 * and the two layouts could not be changed independently.
 */
?>
@include( $invoice->type === TaxInvoice::TYPE_FULL
    ? 'pages.dashboard.orders.templates._tax_invoice_a4'
    : 'pages.dashboard.orders.templates._tax_invoice_abbreviated' )

@includeWhen( request()->query( 'autoprint' ) === 'true', '/pages/dashboard/orders/templates/_autoprint' )

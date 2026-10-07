@extends( 'layout.base' )
@section( 'layout.base.body' )
    @include( Hook::filter( 'ns-web-tax-invoice-template', 'pages.dashboard.orders.templates._tax_invoice' ) )
@endsection

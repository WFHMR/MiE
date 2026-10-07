@extends( 'layout.base' )
@section( 'layout.base.body' )
    @include( Hook::filter( 'ns-web-billing-note-template', 'pages.dashboard.orders.templates._billing_note' ) )
@endsection

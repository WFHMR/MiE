@extends( 'layout.base' )
@section( 'layout.base.body' )
    @include( Hook::filter( 'ns-web-withholding-certificate-template', 'pages.dashboard.taxes.withholding._certificate' ) )
@endsection

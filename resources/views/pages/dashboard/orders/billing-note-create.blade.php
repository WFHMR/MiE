@extends( 'layout.dashboard' )

@section( 'layout.dashboard.with-title' )
    {{-- The print base is passed down so the component never has to know the application's
         base path when reprinting an existing note. --}}
    <ns-billing-note-issue
        search-url="{{ ns()->route( 'ns-api.customers.search' ) }}"
        billing-note-url="{{ ns()->url( '/dashboard/orders/billing-note/' ) }}"
        v-cloak>
    </ns-billing-note-issue>
@endsection

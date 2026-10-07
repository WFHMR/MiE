@extends( 'layout.dashboard' )

@section( 'layout.dashboard.with-title' )
    <ns-customers-statement-report
        storeName="{{ ns()->option->get( 'ns_store_name' ) }}" 
        storeLogo="{{ ns_logo( 'rectangle' ) }}"
        search-url="{{ ns()->route( 'ns-api.customers.search' ) }}"
        ></ns-customers-statement-report>
@endsection

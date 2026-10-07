@extends( 'layout.dashboard' )
@section( 'layout.dashboard.body' )
    <div>
        @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
        <div id="dashboard-content" class="px-4">
            <div class="page-inner-header mb-4">
                <h3 class="text-3xl text-fontcolor font-bold">{!! sprintf( '%s — %s', $invoice->document_title, $invoice->number ) !!}</h3>
                <p class="text-fontcolor-soft">{{ sprintf( __( 'ออกจากบิลเลขที่ %s' ), $order->code ) }}</p>
            </div>

            @if ( $invoice->isCancelled() )
            <div class="my-2">
                <ns-notice color="danger">
                    <template v-slot:title>{{ __( 'เอกสารนี้ถูกยกเลิกแล้ว' ) }}</template>
                    <template v-slot:description>{{ $invoice->cancellation_reason }}</template>
                </ns-notice>
            </div>
            @endif

            <div class="my-2 w-full mx-auto">
                <ns-link type="info" href="{{ ns()->url( '/dashboard/orders/tax-invoice/' . $invoice->id . '?dash-visibility=disabled' ) }}">{{ __( 'Hide Dashboard' ) }}</ns-link>
                @include( Hook::filter( 'ns-web-tax-invoice-template', 'pages.dashboard.orders.templates._tax_invoice' ) )
            </div>
        </div>
    </div>
@endsection

@extends( 'layout.dashboard' )
@section( 'layout.dashboard.body' )
    <div>
        @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
        <div id="dashboard-content" class="px-4">
            <div class="page-inner-header mb-4">
                <h3 class="text-3xl text-fontcolor font-bold">{{ sprintf( '%s — %s', __( 'หนังสือรับรองการหักภาษี ณ ที่จ่าย' ), $record->number ) }}</h3>
                <p class="text-fontcolor-soft">{{ sprintf( __( 'จ่ายให้ %s เมื่อ %s — ยื่นใน %s' ), $record->payee_name, $record->paid_on->toDateString(), $record->form_label ) }}</p>
            </div>

            @if ( $record->isCancelled() )
            <div class="my-2">
                <ns-notice color="danger">
                    <template v-slot:title>{{ __( 'เอกสารนี้ถูกยกเลิกแล้ว' ) }}</template>
                    <template v-slot:description>{{ $record->cancellation_reason }}</template>
                </ns-notice>
            </div>
            @endif

            <div class="my-2 w-full mx-auto">
                <ns-link type="info" href="{{ ns()->url( '/dashboard/taxes/withholding/certificate/' . $record->id . '?dash-visibility=disabled' ) }}">{{ __( 'Hide Dashboard' ) }}</ns-link>
                @include( Hook::filter( 'ns-web-withholding-certificate-template', 'pages.dashboard.taxes.withholding._certificate' ) )
            </div>
        </div>
    </div>
@endsection

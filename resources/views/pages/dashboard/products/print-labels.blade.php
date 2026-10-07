@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div class="h-full flex-auto flex flex-col">
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    <div class="flex-auto flex flex-col overflow-hidden" id="dashboard-content">
        {{--
            Points at the route that draws the barcode rather than at the public disk. The files on
            that disk only exist for products saved through the product form or received through a
            procurement, so a catalogue loaded by CSV import had almost none â€” 2,922 of 2,928 barcodes
            resolved to a 404 and every label printed a broken image.
        --}}
        <ns-print-label
            barcodeurl="{{ ns()->url( 'dashboard/products/barcode' ) }}"
            storename="{{ ns()->option->get( 'ns_store_name' ) }}">
        </ns-print-label>
    </div>
</div>
@endsection

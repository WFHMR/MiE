@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div class="flex-auto flex flex-col">
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    <div class="flex-auto flex flex-col" id="dashboard-content">
        <div class="px-4">
            @include( '../common/dashboard/title' )
        </div>
        {{-- registerType decides which set of orders the report asks for. Passed from the
             route rather than picked in the browser, because the two registers are two
             separate sheets in the shop's filing and each has its own bookmark. --}}
        <div class="px-4 my-3" style="color:#173c40">
            <p>หน้านี้เป็นทะเบียนรวมบิล หากต้องการเอกสารพร้อมรายการสินค้าและช่องลงชื่อ ให้เลือกพิมพ์รายบิล</p>
            <a class="border rounded p-2 inline-block" href="{{ route('ns.orders.temporary-delivery') }}">พิมพ์ใบส่งสินค้า / ใบฝากสินค้าชั่วคราวรายบิล</a>
        </div>
        <ns-delivery-notes-report
            registerType="{{ $registerType }}"
            storeName="{{ ns()->option->get( 'ns_thai_tax_registered_name' ) ?: ns()->option->get( 'ns_store_name' ) }}"
            v-cloak>
        </ns-delivery-notes-report>
    </div>
</div>
@endsection

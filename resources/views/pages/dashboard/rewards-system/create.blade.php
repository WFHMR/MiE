@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div class="flex-auto flex flex-col">
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    <div class="px-4 flex-auto flex flex-col" id="dashboard-content">
        @include( 'common.dashboard.title' )
        <div class="border p-4 my-4 text-fontcolor">หน้านี้เป็นระบบรางวัลเดิมที่ออกคูปองอัตโนมัติเมื่อคะแนนถึงเป้า หากต้องการสะสมคะแนนร่วมทุกสาขาและให้พนักงานกดแลกส่วนลดเอง ให้ใช้ <a class="underline" href="{{ route('ns.loyalty') }}">คะแนนสมาชิกทุกสาขา</a> ซึ่งไม่ต้องเลือกคูปอง</div>
        <ns-rewards-system class="mt-4"
            return-url="{{ $returnUrl }}"
            submit-method="{{ $submitMethod ?? 'POST' }}"
            submit-url="{{ $submitUrl }}"
            disable-tabs="true"
            src="{{ $src }}"
            :rules="{{ $reward->rules ?? '[]' }}"
            >
            <template v-slot:title>{{ $mainFieldLabel ?? __( 'Reward System Name' ) }}</template>
            <template v-slot:save>{{ $saveButton ?? __( 'Save' ) }}</template>
            <template v-slot:error-required>{{ $fieldRequired ?? __( 'This field is required' ) }}</template>
            <template v-slot:error-invalid-form>{{ $formNotValid ?? __( 'The form is not valid. Please check it and try again' ) }}</template>
        </ns-rewards-system>
    </div>
</div>
@endsection

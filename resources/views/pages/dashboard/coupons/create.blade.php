@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div class="h-full flex flex-col flex-auto">
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    <div class="px-4 flex-auto flex flex-col" id="dashboard-content">
        @include( 'common.dashboard.title' )
        <details class="ns-box border rounded p-3 my-3" open>
            <summary class="font-bold cursor-pointer">วิธีสร้างคูปองและโปรโมชั่น</summary>
            <ol class="list-decimal pl-6 text-sm space-y-2 mt-2">
                <li>ตั้งชื่อและรหัสโปรที่ไม่ซ้ำ เลือก “ลดทั้งบิลเป็นเปอร์เซ็นต์” หรือ “ลดทั้งบิลเป็นจำนวนบาท” หากใช้ทั้งร้านไม่ต้องเลือกสินค้า หมวด หรือกลุ่มลูกค้า</li>
                <li>“ชุดสินค้าราคาพิเศษ” ให้ค้นหาสินค้าจริง เลือกหน่วยขายและจำนวนต่อชุด แล้วระบุราคาทั้งชุด เช่น A 2 ขวด + B 1 ถุง ราคา 100 บาท ครบ 2 ชุดคิด 200 บาท ส่วนที่เกินคิดราคาปกติ หน่วยขายต้องตรงกับที่ตั้งไว้</li>
                <li>หากเลือกสินค้า/หมวดในโปรลดทั้งบิล ต้องมีสินค้าที่ตรงเงื่อนไขอย่างน้อย 1 รายการ ส่วนลดคิดทั้งบิล ไม่ใช่เฉพาะสินค้าที่เลือก เลือกกลุ่มลูกค้าเมื่อต้องการจำกัดสิทธิ์</li>
                <li>วันหมดอายุและช่วงเวลาไม่บังคับ ยอดขั้นต่ำ/สูงสุดและจำนวนครั้งต่อสมาชิกใส่ 0 = ไม่จำกัด กติกาใช้ร่วมทุกสาขา โดยราคาปกติและสต๊อกเป็นของสาขาที่ขาย ราคาโปรใช้ตามการตั้งค่าภาษีของร้าน: รวมภาษีในราคาหรือบวกภาษีหลังหักส่วนลด</li>
                <li>ตอนขาย เพิ่มสินค้าลงบิล → กดคูปอง → กรอกรหัส → โหลด → ใช้ ตรวจยอดก่อนชำระ โปรไม่ติดให้อัตโนมัติ ใช้ได้ 1 โปรต่อบิล และไม่ใช้ร่วมกับส่วนลดท้ายบิลหรือแลกแต้ม</li>
                <li>จำนวนไม่ครบชุดหรือราคาปกติถูกกว่าราคาโปรจะไม่ลดเพิ่ม เมื่อแก้สินค้าในบิลให้ตรวจยอดโปรอีกครั้ง การคืนสินค้าใช้ยอดจ่ายจริงหลังส่วนลดและไม่เกินวงเงินคืนของบิล</li>
            </ol>
        </details>
        <ns-create-coupons
            return-url="{{ $returnUrl }}"
            submit-method="{{ $submitMethod ?? 'POST' }}"
            submit-url="{{ $submitUrl }}"
            src="{{ $src }}"
        >
            <template v-slot:title>{{ $mainFieldLabel ?? __( 'Coupon Name' ) }}</template>
            <template v-slot:save>{{ $saveButton ?? __( 'Save Coupon' ) }}</template>
            <template v-slot:error-required>{{ $fieldRequired ?? __( 'This field is required' ) }}</template>
            <template v-slot:error-invalid-form>{{ $formNotValid ?? __( 'The form is not valid. Please check it and try again' ) }}</template>
        </ns-create-coupons>
    </div>
</div>
@endsection

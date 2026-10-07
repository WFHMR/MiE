@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div class="flex-auto flex flex-col">
    @include( '../common/dashboard-header' )
    <div class="px-4 flex flex-col" id="dashboard-content">
        <div class="flex-auto flex flex-col">
            <div class="page-inner-header mb-4">
                <h3 class="text-3xl text-fontcolor font-bold">ตั้งค่าใบเสร็จ</h3>
                <p class="text-fontcolor-soft">กำหนดรูปแบบใบเสร็จ/บิลสำหรับร้านค้า RGBF</p>
            </div>
        </div>

        <div id="receipt-builder-app" class="flex flex-col lg:flex-row gap-6 pb-8">
            {{-- Form Section --}}
            <div class="w-full lg:w-1/2">
                <div class="ns-box rounded-lg shadow">
                    <div class="p-3 border-b rounded-t-lg" style="background: #54a2a2;">
                        <h4 class="text-white font-semibold text-lg">ข้อมูลร้านค้า</h4>
                    </div>
                    <div class="ns-box-body p-4 space-y-4">
                        <div>
                            <label class="block text-fontcolor font-medium mb-1">ชื่อร้าน</label>
                            <input type="text" id="rb-store-name" oninput="updatePreview()"
                                class="w-full border border-input-edge rounded px-3 py-2 text-sm bg-input-button text-fontcolor"
                                placeholder="เช่น RGBF Supermarket"
                                value="{{ ns()->option->get('ns_store_name', '') }}">
                        </div>
                        <div>
                            <label class="block text-fontcolor font-medium mb-1">ที่อยู่ร้าน</label>
                            <textarea id="rb-store-address" oninput="updatePreview()" rows="3"
                                class="w-full border border-input-edge rounded px-3 py-2 text-sm bg-input-button text-fontcolor"
                                placeholder="เช่น 123 ถ.สุขุมวิท แขวงคลองเตย กรุงเทพฯ 10110">{{ ns()->option->get('ns_invoice_receipt_column_a', '') }}</textarea>
                        </div>
                        <div>
                            <label class="block text-fontcolor font-medium mb-1">เบอร์โทร</label>
                            <input type="text" id="rb-store-phone" oninput="updatePreview()"
                                class="w-full border border-input-edge rounded px-3 py-2 text-sm bg-input-button text-fontcolor"
                                placeholder="เช่น 02-123-4567"
                                value="{{ ns()->option->get('ns_store_phone', '') }}">
                        </div>
                        <div>
                            <label class="block text-fontcolor font-medium mb-1">เลขผู้เสียภาษี / Tax ID</label>
                            <input type="text" id="rb-tax-id" oninput="updatePreview()"
                                class="w-full border border-input-edge rounded px-3 py-2 text-sm bg-input-button text-fontcolor"
                                placeholder="เช่น 0-1234-56789-01-2"
                                {{-- Padded back to thirteen digits, or the form shows the number a
                                     digit short and invites the owner to retype it, only to lose the
                                     leading zero again on the next read. --}}
                                value="{{ app( \App\Services\ThaiVatService::class )->formatTaxpayerId( ns()->option->get('ns_store_tax_id', '') ) }}">
                        </div>
                        <div>
                            <label class="block text-fontcolor font-medium mb-1">ข้อความท้ายบิล</label>
                            <textarea id="rb-footer" oninput="updatePreview()" rows="2"
                                class="w-full border border-input-edge rounded px-3 py-2 text-sm bg-input-button text-fontcolor"
                                placeholder="เช่น ขอบคุณที่ใช้บริการ">{{ ns()->option->get('ns_invoice_receipt_footer', 'ขอบคุณที่ใช้บริการ') }}</textarea>
                        </div>
                        <div>
                            <label class="block text-fontcolor font-medium mb-1">โลโก้ (URL รูปภาพ)</label>
                            <input type="text" id="rb-logo" oninput="updatePreview()"
                                class="w-full border border-input-edge rounded px-3 py-2 text-sm bg-input-button text-fontcolor"
                                placeholder="เช่น /images/store-logo.jpg"
                                value="{{ ns()->option->get('ns_invoice_receipt_logo', '') }}">
                            <p class="text-xs text-gray-400 mt-1">ใส่ URL ของรูปโลโก้ หรือเว้นว่างไว้เพื่อแสดงชื่อร้านแทน</p>
                        </div>
                        <div class="pt-2">
                            <button onclick="saveReceiptSettings()" id="rb-save-btn"
                                class="w-full text-white font-semibold py-3 px-4 rounded transition hover:opacity-90"
                                style="background: #54a2a2;">
                                บันทึกการตั้งค่า
                            </button>
                        </div>
                        <div id="rb-message" class="hidden mt-2 p-3 rounded text-sm font-medium"></div>
                    </div>
                </div>
            </div>

            {{-- Receipt Preview --}}
            <div class="w-full lg:w-1/2">
                <div class="ns-box rounded-lg shadow">
                    <div class="p-3 border-b rounded-t-lg" style="background: #54a2a2;">
                        <h4 class="text-white font-semibold text-lg">ตัวอย่างใบเสร็จ (Live Preview)</h4>
                    </div>
                    <div class="ns-box-body p-4 flex justify-center" style="background: #f0f0f0;">
                        @include( 'pages.dashboard.orders.templates._thermal-receipt-style' )
                        <div class="rgbf-thermal-receipt" id="receipt-preview" style="width: 302px; background: #fff; border: 1px solid #ccc; padding: 24px 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                            {{-- Content filled by JS --}}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(updatePreview, 500);
});

function updatePreview() {
    var name = document.getElementById('rb-store-name').value || 'ชื่อร้าน';
    var address = document.getElementById('rb-store-address').value || 'ที่อยู่ร้าน';
    var phone = document.getElementById('rb-store-phone').value;
    var taxId = document.getElementById('rb-tax-id').value;
    var footer = document.getElementById('rb-footer').value || 'ขอบคุณที่ใช้บริการ';
    var logo = document.getElementById('rb-logo').value;
    
    var now = new Date();
    var dateStr = now.toLocaleDateString('th-TH');
    var timeStr = now.toLocaleTimeString('th-TH', {hour:'2-digit', minute:'2-digit'});

    var logoHtml = logo 
        ? '<img src="' + logo + '" style="max-height:50px;margin:0 auto;display:block;" alt="Logo">'
        : '<div style="font-size:22px;font-weight:bold;color:#54a2a2;letter-spacing:2px;">RGBF</div>';

    var phoneHtml = phone ? '<div>โทร: ' + phone + '</div>' : '';
    var taxHtml = taxId ? '<div>เลขผู้เสียภาษี: ' + taxId + '</div>' : '';

    document.getElementById('receipt-preview').innerHTML = 
        '<div style="text-align:center;margin-bottom:8px;">' + logoHtml + '</div>' +
        '<div style="text-align:center;font-size:13px;color:#000;line-height:1.6;">' +
            '<div style="font-size:16px;font-weight:bold;">' + name + '</div>' +
            '<div style="white-space:pre-line;font-size:13px;margin-top:4px;">' + address + '</div>' +
            phoneHtml + taxHtml +
        '</div>' +
        '<div style="border-top:1px dashed #000;margin:10px 0;"></div>' +
        '<div class="receipt-meta" style="font-size:12px;color:#000;display:flex;justify-content:space-between;">' +
            '<span>เลขที่: INV-20250001</span><span>' + dateStr + '</span>' +
        '</div>' +
        '<div style="font-size:12px;color:#000;margin-bottom:4px;">พนักงาน: Admin | ' + timeStr + '</div>' +
        '<div style="border-top:1px dashed #000;margin:8px 0;"></div>' +
        '<div class="receipt-columns" style="font-size:12px;font-weight:bold;display:flex;justify-content:space-between;padding-bottom:3px;border-bottom:1px solid #000;">' +
            '<span style="flex:2;">รายการ</span><span style="flex:0.8;text-align:center;">จำนวน</span><span style="flex:1;text-align:right;">ราคา</span>' +
        '</div>' +
        '<div class="receipt-columns" style="font-size:13px;padding:3px 0;display:flex;justify-content:space-between;"><span class="receipt-product-name" style="flex:2;">น้ำดื่ม 600ml</span><span style="flex:0.8;text-align:center;">2</span><span style="flex:1;text-align:right;">14.00</span></div>' +
        '<div class="receipt-columns" style="font-size:13px;padding:3px 0;display:flex;justify-content:space-between;"><span class="receipt-product-name" style="flex:2;">ข้าวกล่อง</span><span style="flex:0.8;text-align:center;">1</span><span style="flex:1;text-align:right;">45.00</span></div>' +
        '<div class="receipt-columns" style="font-size:13px;padding:3px 0;display:flex;justify-content:space-between;"><span class="receipt-product-name" style="flex:2;">ขนมปัง</span><span style="flex:0.8;text-align:center;">1</span><span style="flex:1;text-align:right;">35.00</span></div>' +
        '<div style="border-top:1px dashed #000;margin:8px 0;"></div>' +
        '<div style="font-size:13px;">' +
            /*
                ตัวอย่างต้องเป็นแบบภาษีรวมในราคา ให้ตรงกับที่ร้านตั้งไว้จริง
                เดิมตัวอย่างเป็นแบบภาษีนอก คือเอายอดก่อนภาษีบวกภาษีแล้วได้ยอดรวมที่สูงกว่าราคาป้าย
                ทำให้คนอ่านเข้าใจว่าใบเสร็จของตัวเองลืมบวกภาษี
                ของจริงภาษีฝังอยู่ในราคาป้ายแล้ว ยอดรวมจึงเท่ากับราคาป้าย
            */
            '<div class="receipt-summary-row" style="display:flex;justify-content:space-between;padding:2px 0;"><span>มูลค่าสินค้าก่อนภาษี:</span><span class="receipt-amount">83.18</span></div>' +
            '<div class="receipt-summary-row" style="display:flex;justify-content:space-between;padding:2px 0;"><span>ภาษีมูลค่าเพิ่ม 7%:</span><span class="receipt-amount">5.82</span></div><div style="font-size:12px;">(รวมในราคาแล้ว)</div>' +
            '<div class="receipt-summary-row" style="display:flex;justify-content:space-between;padding:4px 0;font-weight:bold;font-size:15px;border-top:1px solid #000;border-bottom:1px solid #000;margin:4px 0;"><span>รวมทั้งสิ้น:</span><span class="receipt-amount">89.00 ฿</span></div>' +
        '</div>' +
        '<div style="font-size:13px;margin-top:6px;">' +
            '<div class="receipt-summary-row" style="display:flex;justify-content:space-between;padding:2px 0;"><span>ชำระโดย: เงินสด</span><span class="receipt-amount">100.00</span></div>' +
            '<div class="receipt-summary-row" style="display:flex;justify-content:space-between;padding:2px 0;"><span>เงินทอน:</span><span class="receipt-amount">11.00</span></div>' +
        '</div>' +
        '<div style="border-top:1px dashed #000;margin:10px 0;"></div>' +
        '<div style="text-align:center;font-size:13px;font-weight:bold;color:#000;">' + footer + '</div>' +
        '<div style="text-align:center;font-size:12px;color:#000;margin-top:6px;">' + dateStr + ' ' + timeStr + '</div>';
}

function saveReceiptSettings() {
    var btn = document.getElementById('rb-save-btn');
    var msg = document.getElementById('rb-message');
    btn.disabled = true;
    btn.textContent = 'กำลังบันทึก...';

    var data = {
        store_name: document.getElementById('rb-store-name').value,
        store_address: document.getElementById('rb-store-address').value,
        store_phone: document.getElementById('rb-store-phone').value,
        tax_id: document.getElementById('rb-tax-id').value,
        footer_message: document.getElementById('rb-footer').value,
        logo_url: document.getElementById('rb-logo').value,
    };

    nsHttpClient.post('/api/settings/receipt-builder', data).subscribe({
        next: function(response) {
            msg.className = 'mt-2 p-3 rounded text-sm font-medium bg-green-100 text-green-700 border border-green-300';
            msg.textContent = '✅ บันทึกสำเร็จ!';
            msg.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = '💾 บันทึกการตั้งค่า';
            setTimeout(function() { msg.classList.add('hidden'); }, 3000);
        },
        error: function(err) {
            msg.className = 'mt-2 p-3 rounded text-sm font-medium bg-red-100 text-red-700 border border-red-300';
            msg.textContent = '❌ เกิดข้อผิดพลาด';
            msg.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = '💾 บันทึกการตั้งค่า';
        }
    });
}
</script>
@endsection


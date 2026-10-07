@php
    $charts = [];
    $makeChart = static fn ($title, $unit, $note, $rows) => ['chartTitle' => $title, 'chartUnit' => $unit, 'chartNote' => $note, 'chartRows' => $rows];
    if ($kind === 'sales') {
        $charts[] = $makeChart('แนวโน้มยอดขายรายวัน', 'บาท', 'เรียงตามวันที่ที่มีบิลขายในช่วงที่เลือก', $data['days']->map(fn ($r) => ['label' => $r['day'], 'value' => $r['total']]));
        $charts[] = $makeChart('ยอดขายตามพนักงาน', 'บาท', 'ผู้สร้างบิล เรียงยอดขายจากมากไปน้อย', $data['team']->map(fn ($r) => ['label' => $r['name'], 'value' => $r['total']]));
    } elseif ($kind === 'purchase') {
        $charts[] = $makeChart('ยอดรับเข้าตามซัพพลายเออร์', 'บาท', 'เฉพาะเอกสารที่เข้าสต๊อกแล้วในช่วงที่เลือก', $data['vendors']->map(fn ($r) => ['label' => $r['name'] ?: 'ไม่ระบุชื่อ', 'value' => $r['total']]));
        $statusLabels = ['draft'=>'ฉบับร่าง','approved'=>'อนุมัติ','ordered'=>'สั่งแล้ว','partial'=>'รับบางส่วน','received'=>'รับครบ','cancelled'=>'ยกเลิก'];
        $charts[] = $makeChart('สถานะใบสั่งซื้อ', 'ใบ', 'ใบสั่งซื้อที่สร้างในช่วงที่เลือก แสดงสถานะปัจจุบัน', $data['statuses']->map(fn ($v, $k) => ['label' => $statusLabels[$k] ?? $k, 'value' => $v]));
    } elseif ($kind === 'inventory') {
        $charts[] = $makeChart('ภาพรวมหน่วยเก็บสต๊อก', 'รายการ', 'ภาพปัจจุบัน แยกกลุ่มไม่ซ้ำ ไม่บวกหน่วยลังซ้ำกับฐาน', $data['count'] ? [['label'=>'คงเหลือมากกว่า 0','value'=>$data['count']-$data['out']],['label'=>'คงเหลือเท่ากับ 0','value'=>$data['out']-$data['negative']],['label'=>'คงเหลือติดลบ','value'=>$data['negative']]] : []);
        $charts[] = $makeChart('จุดที่ควรตรวจสอบ', 'รายการ', 'รายการเดียวอาจอยู่หลายเงื่อนไข จึงห้ามบวกเป็นจำนวนสินค้ารวม', $data['count'] ? [['label'=>'ถึงจุดเตือนที่ตั้งไว้','value'=>$data['low']],['label'=>'สต๊อกติดลบ','value'=>$data['negative']],['label'=>'ต้นทุนไม่มี / ไม่เป็นบวก','value'=>$data['missing_cost']]] : []);
    } elseif ($kind === 'financial') {
        $channelLabels = ['cash-payment'=>'เงินสด','bank-payment'=>'โอนธนาคาร','promptpay-payment'=>'พร้อมเพย์','welfare-card-payment'=>'บัตรสวัสดิการแห่งรัฐ','half-half-payment'=>'คนละครึ่ง'];
        $charts[] = $makeChart('ยอดรับชำระแยกช่องทาง', 'บาท', 'หักเงินทอน ไม่รวมเครดิตบัญชีลูกค้า ตามวันรับชำระ', collect($data['channels'])->map(fn ($v, $k) => ['label'=>$channelLabels[$k] ?? $k, 'value'=>$v]));
        $charts[] = $makeChart('เงินรับและจ่ายที่บันทึก', 'บาท', 'ค่าลบคือเงินจ่ายออก ไม่ใช่ยอดเงินสดหรือธนาคารคงเหลือ', [['label'=>'รับชำระบิล','value'=>$data['collections']],['label'=>'รับคืนจากซัพพลายเออร์','value'=>$data['supplierRefunds']],['label'=>'คืนเงินลูกค้า','value'=>-$data['refunds']],['label'=>'จ่ายซัพพลายเออร์','value'=>-$data['vouchers']],['label'=>'สุทธิจากรายการที่บันทึก','value'=>$data['recorded_flow']]]);
    }
@endphp
@if($kind === 'kpi')
    <h2>ผลจริงเทียบเป้าหมาย</h2>
    <p class="muted">แต่ละกราฟใช้สเกลของตัวเองตามหน่วย · สีเขียว = ผลจริง · สีน้ำเงิน = เป้าหมาย · สีแดง = ค่าติดลบ · ดูสถานะถึงเป้าหมายจากเงื่อนไขอย่างน้อย/ไม่เกิน</p>
    <div class="chart-grid">
    @foreach($metrics as $key => $metric)
        @php
            $actual = $data['values'][$key];
            $target = $data['targets'][$key] ?? null;
            $rows = [];
            if ($actual !== null) { $rows[] = ['label'=>'ผลจริง', 'value'=>$actual]; }
            if ($target !== null) { $rows[] = ['label'=>'เป้าหมาย', 'value'=>$target, 'target'=>true]; }
            $state = $actual === null ? 'ยังไม่มีฐานข้อมูลสำหรับคำนวณผลจริง' : ($target === null ? 'ยังไม่ตั้งเป้าหมาย' : (($metric['direction'] === 'down' ? $actual <= $target : $actual >= $target) ? 'ถึงเป้าหมาย' : 'ยังไม่ถึงเป้าหมาย'));
        @endphp
        @include('pages.dashboard.business.bar-chart', $makeChart($metric['label'], $metric['unit'], ($key === 'stockout' ? 'สต๊อกปัจจุบัน · ' : 'เดือน '.$month.' · ').($metric['direction'] === 'down' ? 'ไม่เกินเป้าหมาย' : 'อย่างน้อยเท่าเป้าหมาย').' · '.$state, $rows))
    @endforeach
    </div>
@else
    <div class="chart-grid">
    @foreach($charts as $chart)
        @include('pages.dashboard.business.bar-chart', $chart)
    @endforeach
    </div>
@endif

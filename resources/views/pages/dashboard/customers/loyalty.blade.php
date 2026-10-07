@extends('layout.dashboard')
@section('layout.dashboard.header')

<style>
.loyalty-page{max-width:1280px;width:100%;min-width:0;margin:0 auto;padding:24px;box-sizing:border-box;color:var(--color-fontcolor);overflow-wrap:anywhere}
.loyalty-page *{box-sizing:border-box}
.loyalty-page .lp-heading,.loyalty-page .lp-card-head,.loyalty-page .lp-actions{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.loyalty-page .lp-heading{margin-bottom:22px;align-items:flex-start}
.loyalty-page h1{font-size:26px;line-height:1.35;font-weight:700;margin:0 0 7px}
.loyalty-page h2{font-size:18px;font-weight:700;margin:0}
.loyalty-page p{line-height:1.65;margin:0}
.loyalty-page .lp-muted{font-size:13px;color:var(--color-fontcolor-soft);line-height:1.6}
.loyalty-page .lp-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border:1px solid var(--color-input-edge,#96c8c8);border-radius:999px;background:var(--color-surface,#dcf0f0);font-size:13px;font-weight:600;white-space:nowrap}
.loyalty-page .lp-card{background:var(--color-popup-surface,#faffff);border:1px solid var(--color-input-edge,#96c8c8);border-radius:14px;margin-bottom:18px;min-width:0;overflow:hidden}
.loyalty-page .lp-card-head{padding:18px 22px;border-bottom:1px solid var(--color-input-edge,#96c8c8)}
.loyalty-page .lp-card-head p{margin-top:4px}
.loyalty-page .lp-branch{padding:18px 22px;display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,460px);gap:20px;align-items:center}
.loyalty-page .lp-branch-form,.loyalty-page .lp-search{display:flex;gap:10px;align-items:flex-end;min-width:0}
.loyalty-page .lp-field{display:flex;flex-direction:column;gap:8px;font-size:14px;min-width:0;flex:1;font-weight:600}
.loyalty-page .lp-control{display:block;width:100%;min-width:0;min-height:44px;padding:10px 12px;border:1px solid var(--color-input-edge,#96c8c8);border-radius:8px;background:var(--color-input-background,var(--color-surface,#dcf0f0));color:inherit;font:inherit;font-weight:400;outline-offset:3px}
.loyalty-page .lp-control:focus-visible,.loyalty-page button:focus-visible,.loyalty-page summary:focus-visible,.loyalty-page a:focus-visible{outline:2px solid var(--color-primary);outline-offset:3px}
.loyalty-page .lp-button{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:44px;padding:10px 17px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;flex-shrink:0;line-height:1.5;text-decoration:none}
.loyalty-page .lp-primary{background:var(--color-primary);color:white;border:1px solid var(--color-primary)}
.loyalty-page .lp-secondary{background:transparent;border:1px solid var(--color-input-edge,#96c8c8);color:inherit}
.loyalty-page .lp-button:hover{filter:brightness(.94)}
.loyalty-page .lp-settings-form{padding:22px}
.loyalty-page .lp-fields{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;align-items:start}
.loyalty-page .lp-actions{margin-top:22px;padding-top:18px;border-top:1px solid var(--color-input-edge,#96c8c8)}
.loyalty-page .lp-guide{padding:16px 22px}
.loyalty-page .lp-guide summary{cursor:pointer;font-size:14px;font-weight:600;line-height:1.6}
.loyalty-page .lp-guide[open] summary{margin-bottom:14px}
.loyalty-page .lp-guide ol{line-height:1.85;font-size:14px}
.loyalty-page .lp-guide li{padding-bottom:8px}
.loyalty-page .lp-guide p{font-size:14px;margin-top:12px}
.loyalty-page .lp-search{padding:18px 22px;max-width:720px}
.loyalty-page .lp-table-scroll{max-width:100%;overflow-x:auto}
.loyalty-page .lp-table{width:100%;border-collapse:collapse;min-width:570px;font-size:14px}
.loyalty-page .lp-table th{padding:12px 22px;text-align:left;background:var(--color-surface,#dcf0f0);font-weight:600;white-space:nowrap}
.loyalty-page .lp-table td{padding:15px 22px;border-top:1px solid var(--color-input-edge,#96c8c8);vertical-align:middle}
.loyalty-page .lp-table th:last-child,.loyalty-page .lp-table td:last-child{text-align:right}
.loyalty-page .lp-table a{font-weight:600;text-underline-offset:4px}
.loyalty-page .lp-table .lp-empty{text-align:center;padding:30px;color:var(--color-fontcolor-soft)}
.loyalty-page .lp-pagination{padding:16px 22px}
.loyalty-page .lp-pagination:empty{display:none}
.loyalty-page .lp-alert{padding:14px 18px;border:1px solid var(--color-primary);border-radius:10px;margin-bottom:18px;background:var(--color-surface)}
.loyalty-page .lp-enroll{padding:16px 0;border-top:1px solid var(--color-input-edge,#96c8c8);display:flex;flex-direction:column;gap:12px;align-items:flex-start;margin-top:16px}
.loyalty-page .lp-enroll label{font-size:14px;line-height:1.8}
@media(max-width:1100px){.loyalty-page .lp-branch{grid-template-columns:1fr}.loyalty-page .lp-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.loyalty-page{padding:16px 12px}.loyalty-page h1{font-size:22px}.loyalty-page .lp-card-head,.loyalty-page .lp-settings-form,.loyalty-page .lp-branch,.loyalty-page .lp-guide,.loyalty-page .lp-search{padding:16px}.loyalty-page .lp-fields{grid-template-columns:1fr;gap:16px}.loyalty-page .lp-branch-form{flex-direction:column;align-items:stretch}.loyalty-page .lp-search{flex-wrap:wrap}.loyalty-page .lp-search .lp-field{flex-basis:100%}.loyalty-page .lp-search .lp-button,.loyalty-page .lp-actions .lp-button{width:100%}.loyalty-page .lp-actions{align-items:stretch}.loyalty-page .lp-table th,.loyalty-page .lp-table td{padding:12px 16px}}
</style>
@endsection
@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file', '../common/dashboard-header'))
<main class="loyalty-page">
    <header class="lp-heading">
        <div><h1>คะแนนสมาชิกทุกสาขา</h1><p class="lp-muted">ตั้งกติกาสะสมและแลกส่วนลด พร้อมตรวจสอบคะแนนสมาชิก</p></div>
        <span class="lp-badge"><i class="las la-store" aria-hidden="true"></i>ใช้คะแนนร่วมกันทุกสาขา</span>
    </header>
    @if($errors->any())<div class="lp-alert" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if(session('success'))<p class="lp-alert" role="status">{{ session('success') }}</p>@endif
    <section class="lp-card lp-branch" aria-label="สาขาที่ทำรายการ">
        <div><h2>สาขาที่ทำรายการ</h2><p class="lp-muted">ใช้ระบุสถานที่ทำรายการเท่านั้น คะแนนของสมาชิกเป็นยอดรวมทุกสาขา</p></div>
        <form method="get" class="lp-branch-form">
            <label class="lp-field">เลือกสาขา<select class="lp-control" name="branch_id">@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
            <button class="lp-button lp-secondary">เลือกสาขาที่ทำรายการ</button>
        </form>
    </section>
    @can('nexopos.update.rewards')
    <section class="lp-card" aria-labelledby="lp-settings-title">
        <div class="lp-card-head"><div><h2 id="lp-settings-title">กติกาสะสมและแลกคะแนน</h2><p class="lp-muted">กติกาเดียวกันทุกสาขา รวมสาขาที่สร้างใหม่</p></div></div>
<form method="post" action="{{ route('ns.loyalty.save') }}" class="lp-settings-form">@csrf<input type="hidden" name="branch_id" value="{{ $branchId }}">
<div class="lp-fields"><label class="lp-field">สถานะ <select name="enabled" class="lp-control"><option value="0">ปิดใช้งาน</option><option value="1" @selected($settings?->enabled)>เปิดใช้งาน</option></select></label>
<label class="lp-field">ซื้อครบกี่บาทได้ 1 คะแนน <input class="lp-control" name="earn_baht" type="number" min="0.01" max="1000000" step="0.01" required value="{{ old('earn_baht', $settings ? $settings->earn_satang / 100 : '') }}"></label>
<label class="lp-field">ใช้กี่คะแนน <input class="lp-control" name="redeem_points" type="number" min="1" max="1000000" required value="{{ old('redeem_points', $settings?->redeem_points) }}"></label>
<label class="lp-field">แลกส่วนลดกี่บาท <input class="lp-control" name="redeem_baht" type="number" min="0.01" max="1000000" step="0.01" required value="{{ old('redeem_baht', $settings ? $settings->redeem_satang / 100 : '') }}"></label></div><div class="lp-actions"><p class="lp-muted">การบันทึกมีผลกับกติกาทุกสาขา สำหรับบิลใหม่</p><button class="lp-button lp-primary">บันทึกกติกาทุกสาขา</button></div></form>

    </section>
    @endcan
    <details class="lp-card lp-guide"><summary>วิธีใช้งานคะแนนและส่วนลด</summary>
<ol style="list-style:decimal;padding-left:24px"><li>ผู้จัดการหรือผู้ดูแลที่ได้รับสิทธิ์แก้ไขระบบรางวัล ตั้งยอดซื้อที่ได้ 1 คะแนน และจำนวนคะแนนที่แลกเป็นส่วนลด แล้วเปิดใช้งาน กติกานี้ใช้ร่วมกันทุกสาขา รวมสาขาที่สร้างใหม่ การแก้ไขมีผลทั้งร้าน</li>
<li>นำเข้าสมาชิกพร้อมคะแนนตั้งต้นที่ใช้ร่วมทุกสาขาได้จาก <a class="underline" href="{{ route('ns.members.import') }}">นำเข้าสมาชิก</a> กรณีไม่มีคะแนนเดิมให้ใส่ 0</li>
<li>เพิ่มสมาชิกครั้งเดียวที่เมนู “เพิ่มสมาชิก” พร้อมเบอร์โทร หรือใช้สมาชิกเดิมได้เลย ไม่ต้องลงทะเบียนคะแนนซ้ำ เลือกสมาชิกก่อนขาย คะแนนเพิ่มเมื่อชำระครบ คำนวณจากยอดสุทธิหลังส่วนลด ไม่รวมค่าจัดส่ง เศษยอดที่ไม่ครบอัตราปัดลง ไม่ยกไปรวมบิลถัดไป</li>
<li>ที่ POS พนักงานผู้มีสิทธิ์ส่วนลดท้ายบิลกด “แลกคะแนน” ระบุจำนวนที่ลูกค้าต้องการใช้ คะแนนถูกหักพร้อมบันทึกบิลสำเร็จ หากไม่สำเร็จคะแนนยังอยู่</li>
<li>คะแนนเป็นยอดเดียวต่อสมาชิก สะสมจากสาขาใดก็แลกส่วนลดได้ทุกสาขา ใช้สมาชิกคนเดิมโดยไม่ต้องลงทะเบียนหรือนำเข้าแต้มซ้ำ ไม่มีการออกคูปองอัตโนมัติ การแลกไม่ใช้ร่วมกับคูปองหรือส่วนลดท้ายบิลอื่น และต้องชำระครบ ไม่รองรับการพักบิลพร้อมคะแนน</li></ol>
<p class="mt-2">ยังไม่ตั้งค่า = ยังไม่เริ่มสะสมหรือแลกคะแนน การแก้กติกามีผลกับบิลใหม่ ไม่เปลี่ยนคะแนนเดิม สมาชิกเดิมเริ่มใช้ได้โดยไม่ลงทะเบียนซ้ำ และไม่คำนวณคะแนนย้อนหลังให้บิลเก่า</p>
<p class="mt-2">เมื่อคืนสินค้า ระบบหักคะแนนที่เคยได้รับตามยอดสุทธิที่เหลือ คืนเงินได้ไม่เกินยอดจ่ายจริงหลังส่วนลด และคืนคะแนนที่ใช้แลกเมื่อคืนเงินค่าสินค้าครบหรือยกเลิกบิล หากใช้คะแนนที่ได้รับไปแล้ว ยอดคะแนนอาจติดลบและต้องสะสมเพิ่มก่อนแลกครั้งใหม่</p>
    </details>
    <section class="lp-card" aria-labelledby="lp-members-title">
        <div class="lp-card-head"><div><h2 id="lp-members-title">คะแนนคงเหลือรวมทุกสาขา</h2><p class="lp-muted">ใช้รายชื่อสมาชิกเดิมทุกสาขา ไม่ต้องลงทะเบียนคะแนนซ้ำ สมาชิกที่ยังไม่มีรายการคะแนนเริ่มที่ 0</p></div></div>
        <form method="get" class="lp-search"><input type="hidden" name="branch_id" value="{{ $branchId }}"><label class="lp-field">ค้นหาสมาชิก<input name="q" class="lp-control" placeholder="ชื่อ / เบอร์โทร / รหัสสมาชิก" value="{{ $query }}"></label><button class="lp-button lp-primary">ค้นหา</button></form>
<div class="lp-table-scroll" tabindex="0" aria-label="ตารางคะแนนสมาชิก เลื่อนซ้ายขวาได้"><table class="lp-table"><thead><tr><th scope="col">รหัสสมาชิก</th><th scope="col">ชื่อ</th><th scope="col">เบอร์โทร</th><th scope="col">คะแนนคงเหลือ</th></tr></thead><tbody>@forelse($accounts as $account)<tr class="border-b"><td class="p-2">{{ $account->member_code ?: '#'.$account->customer_id }}</td><td>{{ $account->first_name }} {{ $account->last_name }}</td><td>{{ $account->phone }}</td><td>@if($account->id)<a class="underline" href="{{ route('ns.loyalty', ['branch_id' => $branchId, 'account' => $account->id]) }}">{{ number_format($account->points) }} · ประวัติ</a>@else 0 · {{ trim((string)$account->phone) !== '' ? 'พร้อมสะสมเมื่อขาย ไม่ต้องลงทะเบียนซ้ำ' : 'กรุณาเพิ่มเบอร์โทรในข้อมูลสมาชิก' }}@endif</td></tr>@empty<tr><td colspan="4" class="lp-empty">ไม่พบสมาชิกที่ตรงกับการค้นหา</td></tr>@endforelse</tbody></table></div><div class="lp-pagination">{{ $accounts->links() }}</div>

    </section>
@if($history->isNotEmpty())
<section class="lp-card"><div class="lp-card-head"><h2>ประวัติคะแนน 100 รายการล่าสุด</h2></div>
<div class="lp-table-scroll" tabindex="0" aria-label="ตารางประวัติคะแนน เลื่อนซ้ายขวาได้"><table class="lp-table"><thead><tr><th scope="col">วันที่</th><th scope="col">สาขาที่ทำรายการ</th><th scope="col">รายการ</th><th scope="col">เพิ่ม / ลด</th><th scope="col">คงเหลือหลังรายการ</th></tr></thead><tbody>@foreach($history as $entry)<tr class="border-b"><td class="p-2">{{ $entry->created_at }}</td><td>{{ $entry->branch_name ?? 'ไม่ระบุ' }}</td><td>{{ $entry->description }}</td><td>{{ number_format($entry->points) }}</td><td>{{ number_format($entry->shared_balance ?? $entry->balance) }}{{ $entry->shared_balance === null ? ' (ยอดสาขาเดิม ก่อนใช้ร่วมกัน)' : ' (รวมทุกสาขา)' }}</td></tr>@endforeach</tbody></table></div>
</section>
@endif


</main>
@endsection

{{--
    แก้ต้นทุนสินค้าหลายรายการ.

    Plain Blade with its own stylesheet, following the purchasing screens, because there is no node
    toolchain on the server: a new Vue component could not be built there, and shipping a rebuilt
    bundle to add one page would replace every asset the shop is running on. A form post needs none
    of that and keeps working with no javascript at all.
--}}
@extends('layout.dashboard')

@section('layout.dashboard.header')
<style>
.cost-editor{max-width:1240px;margin:auto;padding:24px;color:inherit;min-width:0}
.cost-editor *{box-sizing:border-box}
.cost-editor h1{font-size:26px;font-weight:700}
.cost-editor h2{font-size:20px;font-weight:650;margin-bottom:14px}
.cost-editor p{margin:10px 0;line-height:1.7}
.cost-editor .card{background:var(--color-popup-surface);border:1px solid var(--color-input-edge);border-radius:12px;padding:20px;margin:18px 0;min-width:0}
.cost-editor .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.cost-editor .grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.cost-editor label{display:block;font-size:14px}
.cost-editor input,.cost-editor select{display:block;width:100%;min-height:44px;background:var(--color-input-background);color:inherit;border:1px solid var(--color-input-edge);border-radius:6px;padding:10px;margin-top:6px}
.cost-editor .check{display:flex;align-items:center;gap:8px;margin-top:22px}
.cost-editor .check input[type=checkbox]{width:20px;min-height:20px;margin:0;flex:none}
.cost-editor button,.cost-editor .button{display:inline-block;border:1px solid var(--color-input-edge);border-radius:7px;min-height:44px;padding:10px 16px;cursor:pointer;text-decoration:none;background:var(--color-input-background);color:inherit}
.cost-editor .primary{background:#257d79;color:#fff;border-color:#257d79}
.cost-editor a{text-decoration:underline}
.cost-editor .actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:14px 0}
.cost-editor .table-wrap{overflow:auto;max-width:100%}
.cost-editor table{width:100%;border-collapse:collapse;min-width:900px}
.cost-editor th,.cost-editor td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--color-input-edge);vertical-align:middle}
.cost-editor th{font-size:13px;position:sticky;top:0;background:var(--color-popup-surface);z-index:1}
.cost-editor td.num,.cost-editor th.num{text-align:right}
.cost-editor td input{margin:0;min-height:40px;max-width:150px}
.cost-editor .alert{border-left:4px solid #bb6b0c;padding:12px 16px;background:var(--color-popup-surface);margin:14px 0}
.cost-editor .ok{border-left-color:#257d79}
.cost-editor .muted{font-size:13px;opacity:.85}
.cost-editor .metric{font-size:26px;font-weight:700}
.cost-editor .badge{display:inline-block;border:1px solid var(--color-input-edge);border-radius:16px;padding:3px 10px;font-size:12px;white-space:nowrap}
.cost-editor .warn{border-color:#bb6b0c;color:#bb6b0c}
.cost-editor details summary{cursor:pointer;font-weight:650}
.cost-editor .sticky-save{position:sticky;bottom:0;background:var(--color-popup-surface);border-top:1px solid var(--color-input-edge);padding:14px 0;margin-top:0;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
@media(max-width:900px){.cost-editor{padding:14px}.cost-editor .grid,.cost-editor .grid-3{grid-template-columns:1fr}.cost-editor .check{margin-top:4px}}
</style>
@endsection

@section('layout.dashboard.body')
@include(Hook::filter('ns-dashboard-header-file','../common/dashboard-header'))

<main class="cost-editor text-fontcolor">
    <h1>{{ $title }}</h1>
    <p>{{ $description }}</p>

    @if($branchName)
        <p>
            <span class="badge">{{ __('สาขาที่กำลังแก้') }}: {{ $branchName }}</span>
            <span class="muted">
                {{ __('ต้นทุนในหน้านี้เป็นของสาขานี้เท่านั้น สาขาอื่นมีต้นทุนของตัวเอง หากต้องการแก้สาขาอื่นให้สลับสาขาที่ด้านบนของหน้าจอก่อน') }}
            </span>
        </p>
    @else
        <p class="muted">{{ __('ระบบนี้ยังไม่ได้แยกสาขา ต้นทุนที่แก้จะใช้กับทั้งระบบ') }}</p>
    @endif

    @if(session('success'))
        <p role="status" class="alert ok">{{ session('success') }}</p>
    @endif

    @if($errors->any())
        <div role="alert" class="alert">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    @if(session('cost_editor_refusals'))
        <div role="alert" class="alert">
            @foreach(session('cost_editor_refusals') as $refusal)<p>{{ $refusal }}</p>@endforeach
        </div>
    @endif

    <div class="grid-3">
        <section class="card">
            <p>{{ __('ยังไม่มีต้นทุน และมีของอยู่') }}</p>
            <div class="metric">{{ number_format($summary['missing_with_stock']) }}</div>
            <p class="muted">{{ __('ทุกครั้งที่ขายรายการเหล่านี้ รายงานกำไรจะอ่านผิด') }}</p>
        </section>
        <section class="card">
            <p>{{ __('ยังไม่มีต้นทุนทั้งหมด') }}</p>
            <div class="metric">{{ number_format($summary['missing']) }}</div>
            <p class="muted">{{ sprintf(__('จากหน่วยสินค้าของสาขานี้ %s รายการ'), number_format($summary['rows'])) }}</p>
        </section>
        <section class="card">
            <p>{{ __('ไม่ได้ระบุสาขา') }}</p>
            <div class="metric">{{ number_format($summary['unassigned_branch']) }}</div>
            <p class="muted">{{ __('แถวเหล่านี้ใช้กับทุกสาขา แก้แล้วมีผลทั้งหมด') }}</p>
        </section>
    </div>

    <details class="card">
        <summary>{{ __('วิธีใช้และข้อควรรู้') }}</summary>
        <p>{{ __('กรอกเฉพาะช่องที่ต้องการเปลี่ยน ช่องที่เว้นว่างจะไม่ถูกแก้ ใส่จำนวนเงินต่อหนึ่งหน่วยตามคอลัมน์ "หน่วย" เช่นถ้าหน่วยเป็นหีบ ให้ใส่ราคาทุนต่อหีบ') }}</p>
        <p>{{ __('ถ้าเปิด "คำนวณหน่วยอื่นให้ด้วย" ระบบจะคิดต้นทุนของหน่วยอื่นในสินค้าเดียวกันตามอัตราส่วน เช่นกรอกหีบละ 589 บาท หีบบรรจุ 12 ถุง ถุงจะได้ 49.08 บาท หน่วยที่คุณกรอกเองในครั้งนี้ และหน่วยที่มีประวัติการรับเข้าพร้อมราคาของตัวเองอยู่แล้ว จะไม่ถูกคำนวณทับ') }}</p>
        <p>{{ __('สินค้าในระบบนี้เปิดการคำนวณต้นทุนอัตโนมัติไว้ หมายความว่าเมื่อมีการรับสินค้าเข้าพร้อมราคาซื้อจริงในอนาคต ต้นทุนจะถูกคำนวณใหม่จากราคาที่รับเข้า ค่าที่กรอกที่นี่คือค่าตั้งต้นสำหรับรายการที่ยังไม่มีประวัติราคา') }}</p>
    </details>

    <form method="get" class="card">
        <input type="hidden" name="filtered" value="1">
        <div class="grid">
            <label>{{ __('ค้นหาชื่อ / รหัส / บาร์โค้ด') }}
                <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('เช่น น้ำตาล') }}">
            </label>
            <label>{{ __('หมวดสินค้า') }}
                <select name="category_id">
                    <option value="">{{ __('ทุกหมวด') }}</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters['category_id'] === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('จำนวนต่อหน้า') }}
                <select name="per_page">
                    @foreach([25, 50, 100, 200] as $size)
                        <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </label>
            <div>
                <div class="check">
                    <input type="checkbox" id="only_missing" name="only_missing" value="1" @checked($filters['only_missing'])>
                    <label for="only_missing">{{ __('เฉพาะที่ยังไม่มีต้นทุน') }}</label>
                </div>
                <div class="check">
                    <input type="checkbox" id="only_in_stock" name="only_in_stock" value="1" @checked($filters['only_in_stock'])>
                    <label for="only_in_stock">{{ __('เฉพาะที่มีของอยู่') }}</label>
                </div>
            </div>
        </div>
        <div class="actions">
            <button class="primary">{{ __('ค้นหา') }}</button>
            <a class="button" href="{{ route('ns.products-cost-editor') }}">{{ __('ล้างตัวกรอง') }}</a>
        </div>
    </form>

    <form method="post" action="{{ route('ns.products-cost-editor.save') }}">
        @csrf
        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">

        <section class="card">
            <h2>{{ sprintf(__('รายการที่ตรงตัวกรอง %s รายการ'), number_format($rows->total())) }}</h2>

            @if($rows->isEmpty())
                <p>{{ __('ไม่พบรายการตามตัวกรองนี้ ลองล้างตัวกรองหรือค้นหาด้วยคำอื่น') }}</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('สินค้า') }}</th>
                                <th>{{ __('หน่วย') }}</th>
                                <th>{{ __('สาขา') }}</th>
                                <th class="num">{{ __('คงเหลือ') }}</th>
                                <th class="num">{{ __('ราคาขาย') }}</th>
                                <th class="num">{{ __('ต้นทุนปัจจุบัน') }}</th>
                                <th>{{ __('ต้นทุนใหม่') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td>
                                        <a href="{{ ns()->url('dashboard/products/edit/' . $row->product_id) }}" target="_blank" rel="noopener">{{ $row->product?->name ?? __('ไม่พบสินค้า') }}</a>
                                        @if($row->product?->sku)<div class="muted">{{ $row->product->sku }}</div>@endif
                                    </td>
                                    <td>{{ $row->unit?->name ?? __('ไม่ระบุ') }}</td>
                                    <td>
                                        @if($row->branch_id === null)
                                            <span class="badge warn">{{ __('ทุกสาขา') }}</span>
                                        @else
                                            {{ $row->branch?->name ?? $row->branch_id }}
                                        @endif
                                    </td>
                                    <td class="num">{{ number_format((float) $row->quantity, 2) }}</td>
                                    <td class="num">{{ number_format((float) $row->sale_price, 2) }}</td>
                                    <td class="num">
                                        @if((float) $row->cogs <= 0)
                                            <span class="badge warn">{{ __('ยังไม่มี') }}</span>
                                        @else
                                            {{ number_format((float) $row->cogs, 2) }}
                                        @endif
                                    </td>
                                    <td>
                                        <label class="muted" for="cost-{{ $row->id }}">{{ sprintf(__('ต้นทุนต่อ %s'), $row->unit?->name ?? __('หน่วย')) }}</label>
                                        <input
                                            id="cost-{{ $row->id }}"
                                            type="text"
                                            inputmode="decimal"
                                            name="costs[{{ $row->id }}]"
                                            value=""
                                            placeholder="{{ __('เว้นว่าง = ไม่แก้') }}"
                                            aria-label="{{ sprintf(__('ต้นทุนใหม่ของ %s หน่วย %s'), $row->product?->name ?? '', $row->unit?->name ?? '') }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="sticky-save">
                    <div class="check">
                        <input type="checkbox" id="spread_to_other_units" name="spread_to_other_units" value="1" checked>
                        <label for="spread_to_other_units">{{ __('คำนวณหน่วยอื่นของสินค้าเดียวกันให้ด้วยตามอัตราส่วน') }}</label>
                    </div>
                    <button class="primary">{{ __('บันทึกต้นทุนที่กรอก') }}</button>
                    <span class="muted">{{ __('ช่องที่เว้นว่างจะไม่ถูกแก้') }}</span>
                </div>
            @endif
        </section>
    </form>

    @if($rows->hasPages())
        <div class="card">{{ $rows->links() }}</div>
    @endif
</main>
@endsection

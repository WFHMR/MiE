@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div class="flex-auto flex flex-col">
    @include( Hook::filter( 'ns-dashboard-header-file', '../common/dashboard-header' ) )
    <div class="px-4 flex flex-col" id="dashboard-content">
        @include( 'common.dashboard.title' )
        <div>
            <div class="flex justify-between items-center mb-4">
                <div class="ns-button">
                    <a href="{{ ns()->route( 'ns.dashboard.products' ) }}" class="rounded-lg text-fontcolor shadow px-3 py-1">
                        <i class="las la-angle-left"></i> {{ __( 'กลับไปรายการสินค้า' ) }}
                    </a>
                </div>
            </div>

            @if (session('success'))
                <div class="ns-notice success mb-4">
                    <p>{{ session('success') }}</p>
                </div>
            @endif

            @if (session('warning'))
                <div class="ns-notice warning mb-4">
                    <p>{{ session('warning') }}</p>
                </div>
            @endif

            @if (session('error'))
                <div class="ns-notice error mb-4">
                    <p>{{ session('error') }}</p>
                </div>
            @endif

            {{--
                Counting or receiving by scanning the shelf, above the file upload because it is the
                quicker of the two for the job it fits: a delivery of a few lines, or a shelf recount.
                The file stays below for a whole branch at once.

                Mounted rather than written in Blade because a barcode reader needs a live list — the
                figure has to be adjustable before anything is written, and a page that posts on every
                scan cannot be corrected.
            --}}
            <ns-inventory-scan
                class="mb-4 rounded shadow"
                :branches='@json( $branches->map( fn ( $branch ) => [ "id" => $branch->id, "name" => $branch->name ] )->values() )'
                :current-branch-id='@json( $currentBranchId )'></ns-inventory-scan>

            <form action="{{ ns()->route( 'ns.dashboard.inventory.upload.post' ) }}" method="post" enctype="multipart/form-data">
                @csrf
                <div class="module-section my-4 flex flex-wrap -mx-4">
                    <div class="px-4 w-full md:w-2/3">
                        <div class="ns-box rounded shadow">
                            <div class="ns-box-header border-b p-3">
                                <h3 class="font-semibold">อัพโหลดสินค้าจากไฟล์ CSV</h3>
                            </div>
                            <div class="{{ $errors->any() ? 'form-input-invalid' : 'form-input' }} ns-box-body p-4">
                                <label for="branch_id" class="font-medium">นำเข้าเข้าสาขา</label>
                                <select name="branch_id" id="branch_id" class="my-2 block w-full text-sm border rounded p-2" required>
                                    @foreach ( $branches as $branch )
                                        <option value="{{ $branch->id }}" @selected( $branch->id === $currentBranchId )>
                                            {{ $branch->name }}@if ( $branch->code ) ({{ $branch->code }})@endif
                                        </option>
                                    @endforeach
                                </select>
                                <p class="text-sm text-secondary mb-4">ราคาและสต๊อกจะถูกบันทึกเข้าสาขาที่เลือกนี้เท่านั้น</p>

                                <label class="font-medium">ไฟล์นี้คืออะไร <span class="text-error-primary">*</span></label>
                                <p class="text-sm text-secondary mb-2">ต้องเลือกก่อนทุกครั้ง เพราะสองแบบนี้ให้ผลกับสต๊อกต่างกัน</p>

                                <label class="flex items-start gap-3 border rounded p-3 mb-2 cursor-pointer hover:bg-box-elevation-background">
                                    <input type="radio" name="stock_mode" value="receive" class="mt-1" @checked( old( 'stock_mode' ) === 'receive' ) required>
                                    <span>
                                        <strong>1. รับสินค้าเข้า</strong> — ของที่รับเข้ามาใหม่รอบนี้ ระบบจะ <strong>บวกเพิ่ม</strong> จากยอดที่มีอยู่<br>
                                        <span class="text-sm text-secondary">ตัวอย่าง ในระบบมี 68 ไฟล์ใส่ 37 &rarr; ผลลัพธ์ <strong>105</strong></span>
                                    </span>
                                </label>

                                <label class="flex items-start gap-3 border rounded p-3 mb-3 cursor-pointer hover:bg-box-elevation-background">
                                    <input type="radio" name="stock_mode" value="set" class="mt-1" @checked( old( 'stock_mode' ) === 'set' )>
                                    <span>
                                        <strong>2. อัปเดตสต๊อก</strong> — นับสต๊อกจริงแล้วต้องการตั้งยอดใหม่ หรือแก้ยอดที่ผิด ระบบจะ <strong>ทับยอดเดิม</strong><br>
                                        <span class="text-sm text-secondary">ตัวอย่าง ในระบบมี 68 ไฟล์ใส่ 37 &rarr; ผลลัพธ์ <strong>37</strong></span>
                                    </span>
                                </label>

                                @error( 'stock_mode' )
                                    <p class="text-sm text-error-primary mb-3">{{ $message }}</p>
                                @enderror

                                <label for="csv_file" class="font-medium">เลือกไฟล์ CSV</label>
                                <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" class="my-2 block w-full text-sm border rounded p-2" required>
                                <p class="text-sm text-secondary mt-2">
                                    {{ $errors->any() ? __( $errors->first( 'csv_file' ) ) : __( 'เลือกไฟล์ CSV ที่ต้องการอัพโหลด (UTF-8 encoding)' ) }}
                                </p>
                            </div>
                            <div class="ns-box-footer border-t p-3 flex justify-end">
                                <div class="ns-button info">
                                    <button class="rounded-lg px-4 py-2 shadow" type="submit">
                                        <i class="las la-upload"></i> {{ __( 'อัพโหลดและนำเข้า' ) }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="px-4 w-full md:w-1/3">
                        <div class="ns-box rounded shadow">
                            <div class="ns-box-header border-b p-3">
                                <h3 class="font-semibold">รูปแบบไฟล์ CSV (ใช้ได้ 3 แบบ)</h3>
                            </div>
                            <div class="ns-box-body p-4 text-sm">
                                <p class="text-xs mb-3">ระบบอ่านหัวตารางแล้วรู้เองว่าเป็นแบบไหน <strong>ไม่ต้องเลือก</strong> และไม่ต้องแก้หัวตาราง มีบรรทัดชื่อรายงานอยู่ข้างบนกี่บรรทัดก็ได้</p>

                                {{-- Three formats is two more than anyone wants to hold in their head, so the
                                     choice is reduced to one question about the goods rather than a
                                     comparison of the files. --}}
                                <div class="mb-4 p-3 border-l-4 border-info-primary bg-box-elevation-background text-xs rounded">
                                    <p class="font-bold text-sm mb-2">ใช้แม่แบบไหน — ถามข้อเดียว</p>
                                    <p class="mb-1"><strong>ของมาเป็นห่อปิดผนึกอยู่แล้ว</strong> ขายเป็นชิ้น เป็นแพ็ค เป็นหีบ (น้ำอัดลม บะหมี่ นม) &rarr; <strong>แม่แบบมาตรฐาน</strong></p>
                                    <p class="mb-1"><strong>ร้านเปิดกระสอบแบ่งขายเอง</strong> ชั่งเป็นกรัมเป็นโล (แป้ง น้ำตาล ถั่ว ข้าว น้ำมัน) &rarr; <strong>แม่แบบสินค้าแบ่งบรรจุ</strong></p>
                                    <p><strong>ได้ไฟล์ส่งออกจากสาขามาแล้ว</strong> ไม่อยากกรอกใหม่ &rarr; <strong>แบบสำรอง</strong> อัพได้เลย</p>
                                </div>

                                {{-- The standard the shop hands to its staff is put first and named as such.
                                     Two formats presented as equals is itself a source of error: someone
                                     picks the wrong one and the stock figures come out wrong. --}}
                                {{--
                                    Colours come from the tertiary and fontcolor variables rather than
                                    -primary plus text-white.

                                    In the light theme --color-info-primary is rgb(200 235 235) and
                                    --color-success-primary is green-200: both very pale. White text on
                                    them was invisible, and a 2px border in them disappeared, so the boxes
                                    stopped reading as boxes. --color-info-tertiary and
                                    --color-success-tertiary hold the same dark value in all three themes,
                                    and --color-info-fontcolor / --color-success-fontcolor are defined per
                                    theme precisely to sit on those surfaces — light text on the dark
                                    themes, dark text on the pale one. That is the pairing the tables and
                                    forms in this codebase already use.
                                --}}
                                <div class="mb-4 border-2 border-info-tertiary rounded p-3 bg-box-elevation-background">
                                    <p class="font-bold mb-1"><i class="las la-star text-info-tertiary"></i> แม่แบบมาตรฐาน — ใช้อันนี้เป็นหลัก</p>
                                    <p class="text-xs mb-2">ส่งไฟล์นี้ให้พนักงานทุกคนใช้เหมือนกัน หนึ่งบรรทัด = หนึ่งสินค้า กรอกชั้นหน่วย <strong>ชิ้น / แพ็ค / หีบ</strong> ครบในบรรทัดเดียว ใช้ได้ทุกสาขา</p>

                                    <p class="text-xs mb-2">
                                        <a class="inline-block px-3 py-2 rounded border border-info-tertiary bg-info-primary text-info-fontcolor font-bold" href="{{ asset( 'samples/standard-product-template.csv' ) }}" download="แม่แบบมาตรฐาน-อัพโหลดสินค้า-SWP.csv">
                                            <i class="las la-download"></i> ดาวน์โหลดแม่แบบเปล่า (ให้พนักงานกรอก)
                                        </a>
                                    </p>

                                    <p class="text-xs mb-2">
                                        <a class="underline font-semibold text-info-tertiary" href="{{ asset( 'samples/standard-product-example.csv' ) }}" download="ตัวอย่างกรอกแล้ว-อัพโหลดสินค้า-SWP.csv">
                                            <i class="las la-download"></i> ดาวน์โหลดตัวอย่างที่กรอกแล้ว (ใช้ดูเทียบ 8 กรณี)
                                        </a>
                                    </p>

                                    <div class="mt-3 p-2 border-l-4 border-error-primary rounded">
                                        <p class="font-bold mb-1">ตอนบันทึกไฟล์จาก Excel</p>
                                        <p>เลือก <strong>CSV UTF-8 (Comma delimited)</strong> ถ้าเลือกแบบธรรมดา ชื่อสินค้าไทยอาจเพี้ยน <span class="text-secondary">(ระบบพยายามแปลงให้อยู่แล้ว แต่เลือกให้ถูกตั้งแต่ต้นชัวร์กว่า)</span></p>
                                    </div>
                                </div>

                                {{-- Goods the shop breaks open itself. The ladder above offers three fixed
                                     rungs and asks for each as a multiple of the one below it, which for
                                     weights means working out that half a kilo is two and a half two
                                     hundreds. This states the weight a scale shows instead. --}}
                                <div class="mb-4 border-2 border-success-tertiary rounded p-3 bg-box-elevation-background">
                                    <p class="font-bold mb-1"><i class="las la-balance-scale text-success-tertiary"></i> แม่แบบสินค้าแบ่งบรรจุ — ซื้อมาเป็นกระสอบ แบ่งขายหลายขนาด</p>
                                    <p class="text-xs mb-2">ใช้กับของที่ร้าน<strong>แบ่งบรรจุเอง</strong> เช่น แป้ง น้ำตาล ถั่ว ธัญพืช ข้าวสาร น้ำมัน ไข่ <span class="text-secondary">กระสอบ 10 โล แบ่งขาย 100 กรัม / 200 กรัม / ครึ่งโล / 1 โล ได้ในสินค้าตัวเดียว ตัดสต๊อกจากกองเดียวกัน</span></p>

                                    <p class="text-xs mb-2">
                                        <a class="inline-block px-3 py-2 rounded border border-success-tertiary bg-success-primary text-success-fontcolor font-bold" href="{{ asset( 'samples/divided-goods-template.csv' ) }}" download="แม่แบบสินค้าแบ่งบรรจุ-SWP.csv">
                                            <i class="las la-download"></i> ดาวน์โหลดแม่แบบเปล่า (แบ่งบรรจุ)
                                        </a>
                                    </p>

                                    <p class="text-xs mb-2">
                                        <a class="underline font-semibold text-success-tertiary" href="{{ asset( 'samples/divided-goods-example.csv' ) }}" download="ตัวอย่างกรอกแล้ว-สินค้าแบ่งบรรจุ-SWP.csv">
                                            <i class="las la-download"></i> ดาวน์โหลดตัวอย่างที่กรอกแล้ว (8 กรณี รวมน้ำตาล ถั่ว แป้ง ข้าว ไข่ น้ำมัน)
                                        </a>
                                    </p>

                                    <p class="text-xs mb-1"><strong>ต่างจากแม่แบบมาตรฐานอย่างไร:</strong></p>
                                    <ul class="list-disc list-inside space-y-1 text-xs mb-2">
                                        <li>แม่แบบมาตรฐานมี <strong>3 ระดับ</strong> (ชิ้น/แพ็ค/หีบ) — อันนี้มี <strong>6 ระดับ</strong> และเพิ่มคอลัมน์เองได้ไม่จำกัด</li>
                                        <li>แม่แบบมาตรฐานกรอก<strong>อัตราเทียบระดับก่อนหน้า</strong> (1 หีบ = 4 แพ็ค) — อันนี้กรอก<strong>ขนาดจริง</strong>ตามที่ตาชั่งบอก (100, 200, 500, 1000)</li>
                                    </ul>

                                    <p class="text-xs mb-1"><strong>หัวตาราง:</strong></p>
                                    <p class="text-xs mb-2 font-mono break-words bg-box-elevation-background p-2 rounded">รหัสสต็อก, ชื่อสินค้า, กลุ่มหลัก, ย่อย, ราคาทุน, หน่วยนับ, บาร์โค้ด1, ชื่อขนาด1, ขนาด1, ราคา1, … ถึง ชุดที่ 6 …, จำนวนรับเข้า, หน่วยที่นับ</p>

                                    <p class="text-xs mb-1"><strong>กฎการกรอก:</strong></p>
                                    <ul class="list-disc list-inside space-y-1 text-xs">
                                        <li><strong>หน่วยนับ</strong> = หน่วยที่ใช้วัดขนาด เช่น <span class="font-mono">กรัม</span> <span class="font-mono">มล.</span> <span class="font-mono">ฟอง</span></li>
                                        <li><strong>ขนาด1..6</strong> = ตัวเลขจริงในหน่วยนั้น <strong>ไม่ใช่</strong>อัตราเทียบขนาดก่อนหน้า กรอกสลับลำดับได้ ระบบเรียงให้เอง</li>
                                        <li><strong>ขนาดเล็กสุดที่กรอกบาร์โค้ด</strong> คือหน่วยที่เก็บสต๊อก ขนาดอื่นเป็นป้ายราคา ขาย 1 โลแล้วระบบตัด 10 ถุงร้อยกรัมให้เอง</li>
                                        <li><strong>ทุกขนาดต้องเป็นจำนวนเท่าของขนาดเล็กสุด</strong> — 100/200/500/1000 ใช้ได้ แต่ 100 กับ 150 ไม่ได้ (ได้ 1.5 ถุง ซึ่งขายไม่ได้นับไม่ได้) ระบบจะข้ามบรรทัดนั้นแล้วบอกเหตุผล</li>
                                        <li><strong>ไม่ใส่บาร์โค้ดแต่ใส่ขนาด</strong> = ขนาดนั้นไม่ขายแยก แต่ใช้เป็น<strong>หน่วยรับเข้า</strong>ได้ เช่น กระสอบที่เปิดแบ่งขาย ไม่มีบาร์โค้ดให้ยิง</li>
                                        <li><strong>ไม่ใส่ราคา = ไม่เปิดขายขนาดนั้น</strong> กันการยิงบาร์โค้ดแล้วได้ของฟรี</li>
                                        <li><strong>ชื่อขนาด</strong> เว้นว่างได้ ระบบตั้งให้เป็น ขนาด + หน่วยนับ เช่น <span class="font-mono">250 กรัม</span></li>
                                        <li><strong>หน่วยที่นับ</strong> ใส่ได้ทั้งชื่อขนาดที่กรอกไว้ (<span class="font-mono">กระสอบ 10 กก.</span>) หรือชื่อหน่วยนับเอง (<span class="font-mono">กรัม</span>) เช่น รับมา 20000 / กรัม &rarr; ระบบบวก 200 ถุงร้อยกรัม</li>
                                    </ul>

                                    <div class="mt-3 p-2 border-l-4 border-info-primary rounded">
                                        <p class="font-bold mb-1">ตัวอย่างน้ำตาลทราย กระสอบ 10 โล</p>
                                        <p class="text-xs">หน่วยนับ <span class="font-mono">กรัม</span> — ขนาด <span class="font-mono">100 / 200 / 500 / 1000</span> ใส่บาร์โค้ดกับราคาทั้งสี่ช่อง แล้วเพิ่มอีกชุดเป็น <span class="font-mono">กระสอบ 10 กก.</span> ขนาด <span class="font-mono">10000</span> <strong>ไม่ใส่บาร์โค้ด ไม่ใส่ราคา</strong> จากนั้น จำนวนรับเข้า <span class="font-mono">1</span> หน่วยที่นับ <span class="font-mono">กระสอบ 10 กก.</span></p>
                                        <p class="text-xs mt-1">ระบบจะเก็บสต๊อกเป็น <strong>100 ถุงร้อยกรัม</strong> ขายขนาดไหนก็ตัดจากกองนี้ ขายครึ่งโลตัด 5 ขายหนึ่งโลตัด 10</p>
                                    </div>
                                </div>

                                <div class="mb-4 border rounded p-3">
                                    <p class="font-bold mb-1">แบบสำรอง — ไฟล์ส่งออกจากสาขา (หนึ่งบรรทัด = หนึ่งหน่วย)</p>
                                    <p class="text-xs mb-2">ใช้เมื่อได้ไฟล์รายการสินค้าที่สาขาส่งออกมาโดยตรง <strong>ใช้ได้เลยไม่ต้องแก้</strong> สินค้าที่ขายหลายหน่วยจะแยกเป็นหลายบรรทัด <span class="text-secondary">ถ้าจะให้พนักงานกรอกใหม่ ให้ใช้แม่แบบมาตรฐานด้านบนแทน</span></p>

                                    <p class="text-xs mb-1"><strong>คอลัมน์ที่ใช้:</strong></p>
                                    <ul class="list-disc list-inside space-y-1 text-xs">
                                        <li><strong>รหัสบาร์โค๊ดสินค้า</strong> → บาร์โค้ด (จำเป็น)</li>
                                        <li><strong>ชื่อ/รายละเอียดสินค้า</strong> → ชื่อสินค้า (จำเป็น)</li>
                                        <li><strong>ขาย (VAT)</strong> → ราคาขาย รวม VAT แล้ว</li>
                                        <li><strong>จำนวน</strong> → สต๊อก</li>
                                        <li><strong>หน่วยขาย</strong> → หน่วยของสินค้าใหม่</li>
                                        <li><strong>ราคาทุน</strong> → ต้นทุน</li>
                                        <li><strong>รหัสสต็อก</strong> → SKU</li>
                                        <li><strong>กลุ่มหลัก + ย่อย</strong> → หมวดหมู่</li>
                                    </ul>

                                    <p class="text-xs mt-2">
                                        <a class="underline font-semibold text-info-tertiary" href="{{ asset( 'samples/sample-branch-export.csv' ) }}" download="ตัวอย่างอัพโหลด-แบบ1-หนึ่งบรรทัดหนึ่งหน่วย.csv">
                                            <i class="las la-download"></i> ดาวน์โหลดไฟล์ตัวอย่าง แบบ 1
                                        </a>
                                    </p>
                                </div>

                                <div class="mb-4 border rounded p-3">
                                    <p class="font-bold mb-1">รายละเอียดกฎการกรอกแม่แบบมาตรฐาน</p>
                                    <p class="text-xs mb-2">ใช้กับสินค้าที่ขายเป็น <strong>ชิ้น / แพ็ค / หีบ (ลัง กระสอบ โหล)</strong> ในตัวเดียวกัน กรอกครบในบรรทัดเดียว</p>

                                    <p class="text-xs mb-1"><strong>หัวตาราง:</strong></p>
                                    <p class="text-xs mb-2 font-mono break-words bg-box-elevation-background p-2 rounded">รหัสสต็อก, ชื่อสินค้า, กลุ่มหลัก, ย่อย, ราคาทุน, บาร์โค้ดชิ้น, ราคาชิ้น, บาร์โค้ดแพ็ค, ชิ้นต่อแพ็ค, ราคาแพ็ค, บาร์โค้ดหีบ, แพ็คต่อหีบ, ราคาหีบ, จำนวนรับเข้า, หน่วยที่นับ</p>
                                    <p class="text-sm mb-2"><strong>ต้นทุนและซัพพลายเออร์:</strong> ราคาทุนคิดต่อ “หน่วยที่นับ” เช่น หีบละ 1,336.45 บาท มี 20 แถว ระบบจะตั้งทุนแถวละ 66.8225 บาท แม้จำนวนรับเข้าเป็น 0 หากทุนเป็นคนละหน่วยกับที่นับ ให้เพิ่มคอลัมน์ “หน่วยต้นทุน” และระบุชื่อหน่วยให้ตรง หากไม่ระบุทั้งสองช่อง จะถือเป็นทุนต่อหน่วยเล็กสุด</p>
                                    <p class="text-sm mb-2">เพิ่มคอลัมน์ “รหัสซัพพลายเออร์” เช่น SP680007 เพื่อผูกกับซัพพลายเออร์ที่มีอยู่ หากไม่มีรหัส ใช้ “ชื่อ/บริษัท” ที่ตรงกับชื่อซัพพลายเออร์เพียงรายเดียว ระบบจะไม่สร้างซัพพลายเออร์ใหม่และไม่เดาว่าหมายถึงบริษัทใด ช่องว่างจะคงซัพพลายเออร์เดิมไว้</p>

                                    <p class="text-xs mb-1"><strong>กฎการกรอก:</strong></p>
                                    <ul class="list-disc list-inside space-y-1 text-xs">
                                        <li><strong>ช่องบาร์โค้ดว่าง = ไม่ขายแยกหน่วยนั้น</strong> ระบบจะไม่สร้างหน่วยนั้นให้</li>
                                        <li><strong>ชิ้นต่อแพ็ค / แพ็คต่อหีบ</strong> เทียบกับหน่วยที่เล็กกว่า <strong>ที่กรอกไว้จริง</strong> เหมือนที่เขียนข้างลัง เช่น 1 แพ็ค = 12 ชิ้น และ 1 หีบ = 4 แพ็ค &rarr; ระบบคิดให้เองว่า 1 หีบ = 48 ชิ้น</li>
                                        <li>ถ้า <strong>ไม่ขายแพ็ค</strong> (เว้นบาร์โค้ดแพ็ค) ให้ใส่ <strong>แพ็คต่อหีบ</strong> เป็นจำนวน<strong>ชิ้น</strong>ในหีบ</li>
                                        <li>หน่วย<strong>เล็กสุดที่กรอกบาร์โค้ด</strong> คือหน่วยที่เก็บสต๊อก หน่วยใหญ่เป็นแค่ป้ายราคา ขายยกหีบแล้วระบบตัดสต๊อกเป็นชิ้นให้เอง</li>
                                        <li><strong>หน่วยไหนไม่ใส่ราคา = ไม่เปิดขายหน่วยนั้น</strong> กันการยิงบาร์โค้ดแล้วได้ของฟรี</li>
                                        <li><strong>จำนวนรับเข้า + หน่วยที่นับ</strong> = รับมาเท่าไหร่ นับเป็นหน่วยไหน เช่น 5 / หีบ &rarr; ระบบบวก 240 ชิ้น <span class="text-secondary">(เว้นหน่วยที่นับ = นับเป็นหน่วยเล็กสุด)</span></li>
                                        <li>นับเป็นหน่วยที่<strong>ไม่ได้ขาย</strong>ก็ได้ เช่น รับเป็นหีบแต่ขายเฉพาะแพ็ค ขอแค่กรอกบาร์โค้ดหีบกับอัตราไว้</li>
                                    </ul>

                                    <p class="text-xs mt-2 mb-1"><strong>เปลี่ยนชื่อหน่วยได้:</strong> เพิ่มคอลัมน์ <span class="font-mono">ชื่อหน่วยเล็ก</span> / <span class="font-mono">ชื่อหน่วยกลาง</span> / <span class="font-mono">ชื่อหน่วยใหญ่</span> เช่น กก. / — / กระสอบ ไม่ใส่จะเป็น ชิ้น / แพ็ค / หีบ</p>

                                    <p class="text-xs">อัพซ้ำได้ ระบบจับคู่สินค้าเดิมจากบาร์โค้ดใดก็ได้ในบรรทัด ไม่สร้างสินค้าซ้ำ</p>
                                    <p class="text-xs mt-1">คอลัมน์ที่ระบบไม่รู้จักจะถูกข้าม จึงเพิ่มคอลัมน์หมายเหตุของร้านเองได้</p>
                                </div>

                                <div class="mt-4 p-3 border-l-4 border-error-tertiary bg-box-elevation-background text-xs rounded">
                                    <p class="font-bold text-sm mb-1">⚠️ อ่านก่อนอัพโหลด</p>
                                    <p class="mb-2"><strong>เลือกผิดแบบ ตัวเลขสต๊อกจะผิด</strong> ให้ถามตัวเองว่า ตัวเลขในช่อง <strong>จำนวน</strong> (แบบ 2 คือ <strong>จำนวนรับเข้า</strong>) ของไฟล์นี้คืออะไร</p>
                                    <p class="mb-1">• ถ้าเป็น <strong>จำนวนที่รับเข้ามารอบนี้</strong> (ใบรับของ / ของเข้าใหม่) &rarr; เลือก <strong>รับสินค้าเข้า</strong></p>
                                    <p class="mb-2">• ถ้าเป็น <strong>ยอดคงเหลือจริงทั้งหมด</strong> (นับสต๊อกแล้ว / แก้ยอดที่ผิด) &rarr; เลือก <strong>อัปเดตสต๊อก</strong></p>
                                    <p class="mb-1"><strong>ห้ามใช้ อัปเดตสต๊อก กับไฟล์ใบรับของ</strong> เพราะของที่มีอยู่เดิมจะหายไป</p>
                                    <p><strong>ห้ามใช้ รับสินค้าเข้า กับไฟล์ยอดคงเหลือ</strong> เพราะของจะถูกนับซ้ำสองเท่า</p>
                                </div>

                                <div class="mt-3 p-2 bg-box-elevation-background text-xs rounded">
                                    <p class="font-medium">🧾 ตรวจย้อนหลังได้</p>
                                    <p>ทุกครั้งที่อัพโหลด ระบบบันทึกลง <strong>ประวัติการปรับปรุง</strong> ว่าสินค้าใดเพิ่มหรือลดเท่าไหร่ จากยอดเท่าไหร่เป็นเท่าไหร่ โดยใคร ถ้าอัพผิดสามารถตามแก้ได้</p>
                                </div>

                                <div class="mt-3 p-2 bg-info-primary text-info-fontcolor text-xs rounded">
                                    <p class="font-medium">📌 การทำงาน</p>
                                    <p>- <strong>บาร์โค้ดที่มีอยู่แล้ว</strong> จะอัปเดตราคาและสต๊อกทับ สินค้าที่มีหลายหน่วย (ชิ้น/แพ็ค/หีบ) <strong>ชั้นหน่วยไม่หาย</strong> ถ้าไฟล์นับมาเป็นหีบ ระบบจะแปลงเป็นหน่วยฐานให้เอง</p>
                                    <p>- <strong>บาร์โค้ดใหม่</strong> แบบ 1 สร้างเป็นสินค้าหน่วยเดียวตาม หน่วยขาย ในไฟล์ / แบบ 2 สร้างครบทุกหน่วยที่กรอกบาร์โค้ดไว้</p>
                                    <p>- <strong>ราคา 0</strong> นำเข้าแต่ยังไม่เปิดขาย กันการยิงแล้วได้ของฟรี</p>
                                    <p>- <strong>บรรทัดที่กรอกผิด</strong> เช่น มีบาร์โค้ดแพ็คแต่ไม่บอกชิ้นต่อแพ็ค ระบบจะ<strong>ข้ามเฉพาะบรรทัดนั้น</strong> แล้วแจ้งเลขบรรทัดให้ บรรทัดอื่นเข้าปกติ</p>
                                    <p>- ไม่มีการลบและไม่มีการซ่อนสินค้าที่ขายอยู่</p>
                                    <p>- VAT 7% แยก net/tax ให้อัตโนมัติ ใบเสร็จกับรายงานจะตรงกัน</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

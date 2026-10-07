<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ProductRegisterWorkbook
{
    public function create(array $ids, int $branch): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('ทะเบียนสินค้า');
        $detail = $book->createSheet()->setTitle('ทุกหน่วยสินค้า');
        $headers = ['รหัสสินค้า (SKU)', 'ชื่อสินค้า', 'รหัสหมวดหมู่', 'หมวดหมู่', 'ต้นทุนหน่วยเล็ก', 'บาร์โค้ดชิ้น', 'ราคาชิ้น', 'ชื่อหน่วยเล็ก', 'บาร์โค้ดแพ็ก', 'ชิ้นต่อแพ็ก', 'ราคาแพ็ก', 'ชื่อหน่วยกลาง', 'บาร์โค้ดหีบ', 'แพ็กต่อหีบ', 'ราคาหีบ', 'ชื่อหน่วยใหญ่', 'สต๊อกหน่วยเล็ก', 'หน่วยสต๊อก', 'รหัสซัพพลายเออร์', 'ชื่อซัพพลายเออร์', 'สาขา', 'จำนวนหน่วยสินค้า', 'รหัสสินค้าในระบบ'];
        $this->row($sheet, 1, $headers);
        $this->row($detail, 1, ['รหัสสินค้า (SKU)', 'ชื่อสินค้า', 'บาร์โค้ด', 'หน่วย', 'อัตราต่อหน่วยฐาน', 'ราคาขาย', 'ต้นทุนหน่วยนี้', 'สต๊อกตามหน่วย', 'สต๊อกที่จอง (หน่วยที่เก็บ)', 'วิธีเก็บสต๊อก', 'สาขา', 'รหัสหน่วย']);
        $branchName = Branch::find($branch)?->name ?? (string) $branch;
        $r = 2;
        $d = 2;
        foreach (array_chunk(array_unique($ids), 400) as $chunk) {
            $products = Product::with(['category', 'provider', 'unit_quantities' => fn ($q) => $q->where(fn ($s) => $s->where('branch_id', $branch)->orWhereNull('branch_id'))->with('unit')])->whereIn('id', $chunk)->orderBy('id')->get();
            foreach ($products as $p) {
                // Prefer this branch's unit over a legacy global row for the same unit.
                $rows = $p->unit_quantities->sortBy(fn ($u) => $u->branch_id === null ? 1 : 0)->unique('unit_id')->sortBy(fn ($u) => (float) ($u->unit?->value ?? 0))->values();
                $small = $rows->first();
                $middle = $rows->count() >= 3 ? $rows[1] : null;
                $large = $rows->count() >= 2 ? $rows->last() : null;
                $base = $rows->first(fn ($u) => $u->unit?->base_unit) ?? $small;
                $ratio = fn ($a, $b) => $a && $b && (float) $b->unit?->value > 0 ? (float) $a->unit?->value / (float) $b->unit->value : null;
                $stock = function ($u) use ($p, $base, $ratio) {
                    if (! $u) {
                        return null;
                    }
                    $factor = $ratio($u, $base);

                    return $p->stock_on_base_unit && $base && $factor > 0 && $u->id !== $base->id ? floor((float) $base->quantity / $factor) : (float) $u->quantity;
                };
                $this->row($sheet, $r++, [(string) $p->sku, $p->name, (string) $p->category_id, $p->category?->name, $small?->cogs !== null ? (float) $small->cogs : null, $small?->barcode, $small ? (float) $small->sale_price : null, $small?->unit?->name, $middle?->barcode, $ratio($middle, $small), $middle ? (float) $middle->sale_price : null, $middle?->unit?->name, $large?->barcode, $ratio($large, $middle ?? $small), $large ? (float) $large->sale_price : null, $large?->unit?->name, $stock($small), $small?->unit?->name, (string) $p->provider?->code, trim(($p->provider?->first_name ?? '').' '.($p->provider?->last_name ?? '')), $branchName, $rows->count(), (string) $p->id]);
                foreach ($rows as $u) {
                    $this->row($detail, $d++, [(string) $p->sku, $p->name, $u->barcode, $u->unit?->name, $ratio($u, $base), (float) $u->sale_price, $u->cogs !== null ? (float) $u->cogs : null, $stock($u), (float) ($u->reserved_quantity ?? 0), $p->stock_on_base_unit ? 'แปลงจากหน่วยฐาน (ห้ามรวมซ้ำ)' : 'แยกสต๊อกแต่ละหน่วย', $branchName, (string) $u->unit_id]);
                }
            }
        }
        foreach ([$sheet, $detail] as $tab) {
            $last = $tab->getHighestColumn();
            $end = $tab->getHighestRow();
            $tab->freezePane('C2');
            $tab->setAutoFilter('A1:'.$last.$end);
            $tab->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $tab->getStyle('A1:'.$last.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF267C79');
            $tab->getStyle('A1:'.$last.'1')->getAlignment()->setWrapText(true);
            $tab->getRowDimension(1)->setRowHeight(34);
            for ($i = 1; $i <= Coordinate::columnIndexFromString($last); $i++) {
                $tab->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth($i === 2 ? 55 : 22);
            }
            $tab->getStyle('A2:'.$last.$end)->getNumberFormat()->setFormatCode('#,##0.#####');
        }
        $guide = $book->createSheet()->setTitle('คำอธิบาย');
        $notes = [
            ['สาขา', $branchName], ['วันที่ส่งออก', now()->toDateTimeString()], ['จำนวนสินค้า', $r - 2],
            ['ข้อมูล', 'ข้อมูลทะเบียนปัจจุบันของ RGBF ตามสิทธิ์และตัวกรองที่เลือก'],
            ['หน่วย', 'เรียงตามอัตราบรรจุ: หน่วยเล็ก = อัตราต่ำสุด; กลาง = ลำดับที่สองเมื่อมี 3 หน่วยขึ้นไป; ใหญ่ = อัตราสูงสุด'],
            ['กรณีมี 2 หน่วย', 'ช่องหน่วยกลางว่าง อัตราแพ็กต่อหีบหมายถึงหน่วยเล็กต่อหน่วยใหญ่ ให้ดูชื่อหน่วยประกอบ'],
            ['หน่วยมากกว่า 3', 'ดูทุกหน่วยครบในชีตทุกหน่วยสินค้า ไม่ตัดทิ้ง'],
            ['ต้นทุน', 'ต้นทุน (COGS) ปัจจุบันของแต่ละหน่วย ไม่ใช่ราคาซื้อครั้งล่าสุด'],
            ['สต๊อก', 'เป็นยอดคงเหลือปัจจุบัน ไม่ใช่จำนวนรับเข้าย้อนหลัง; หน่วยที่แปลงจากฐานเป็นสินค้ากองเดียวกัน ห้ามรวมซ้ำ'],
            ['ระบบเดิม', 'รหัสหมวดหมู่เป็นรหัสใน RGBF ไม่ยืนยันว่าเท่ากับกลุ่มหลัก/ย่อยระบบเดิม ช่องที่ไม่มีข้อมูลเว้นว่าง ไม่สร้างรหัสแทน'],
            ['รหัสและบาร์โค้ด', 'เก็บเป็นข้อความเพื่อรักษาเลขศูนย์นำหน้าและป้องกันเลขยาวเปลี่ยนรูปแบบ'],
        ];
        foreach ($notes as $i => $values) {
            $this->row($guide, $i + 1, $values);
        }
        $guide->getColumnDimension('A')->setWidth(25);
        $guide->getColumnDimension('B')->setWidth(100);
        $guide->getStyle('B1:B11')->getAlignment()->setWrapText(true);
        foreach (range(1, 11) as $i) {
            $guide->getRowDimension($i)->setRowHeight(42);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function row($sheet, int $row, array $values): void
    {
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$row, $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
        }
    }
}

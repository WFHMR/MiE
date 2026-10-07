<?php

namespace App\Services;

use App\Models\Procurement;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ProcurementWorkbook
{
    public function create(iterable $documents, string $title, string $scope): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('รายการจัดซื้อ');
        $book->getDefaultStyle()->getFont()->setName('Tahoma')->setSize(11);
        $sheet->mergeCells('A1:T1')->setCellValueExplicit('A1', $title, DataType::TYPE_STRING);
        $sheet->mergeCells('A2:T2')->setCellValueExplicit('A2', $scope, DataType::TYPE_STRING);
        $headers = ['รายการ', 'รหัสซัพพลายเออร์', 'ซัพพลายเออร์', 'ใบสั่งซื้อ', 'ใบส่งสินค้า', 'วันที่ส่งสินค้า', 'ใบกำกับภาษี', 'วันที่ใบกำกับ', 'ใบวางบิล', 'วันที่วางบิล', 'เครดิต (วัน)', 'ครบกำหนดชำระ', 'มูลค่าก่อนภาษี (บาท)', 'ภาษี (บาท)', 'รวมเงิน (บาท)', 'สถานะรับเข้า', 'สถานะชำระเงิน', 'สาขา', 'ลดหนี้สะสม', 'ยอดหลังลดหนี้'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 4], $header);
        }
        $row = 5;
        foreach ($documents as $d) {
            $values = [$d->name, $d->provider?->code, trim(($d->provider?->first_name ?? '').' '.($d->provider?->last_name ?? '')), $d->purchase_order_number, $d->delivery_note_number, substr((string) $d->delivery_note_date, 0, 10), $d->invoice_reference, substr((string) $d->invoice_date, 0, 10), $d->billing_note_number, substr((string) $d->billing_note_date, 0, 10), $d->credit_days, substr((string) $d->due_date, 0, 10), (float) $d->cost - (float) $d->tax_value, (float) $d->tax_value, (float) $d->cost, ['stocked' => 'รับเข้าสต๊อกแล้ว', 'pending' => 'รอรับสินค้า', 'draft' => 'ฉบับร่าง', 'delivered' => 'ส่งถึงแล้ว'][$d->delivery_status] ?? $d->delivery_status, $d->payment_status === Procurement::PAYMENT_PAID ? 'ชำระแล้ว' : 'ยังไม่ชำระ', $d->branch_id ? ($d->report_branch_name ?? (string) $d->branch_id) : 'เอกสารเก่าไม่ระบุสาขา', (float) $d->purchase_return_credit, $d->payable_total];
            foreach ($values as $i => $value) {
                $numeric = in_array($i, [10, 12, 13, 14, 18, 19], true);
                $sheet->setCellValueExplicit([$i + 1, $row], $numeric ? (float) $value : (string) $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            $sheet->getRowDimension($row)->setRowHeight(36);
            $row++;
        }
        $sheet->setCellValue('L'.$row, 'รวมเงิน');
        foreach (['M', 'N', 'O', 'S', 'T'] as $col) {
            $sheet->setCellValue($col.$row, $row > 5 ? '=SUM('.$col.'5:'.$col.($row - 1).')' : 0);
        }
        $sheet->getStyle('M5:O'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A4:T4')->getFont()->setBold(true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A4:T'.$row)->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->getRowDimension(4)->setRowHeight(40);
        for ($i = 1; $i <= 20; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(in_array($i, [1, 3], true) ? 32 : 19);
        }
        $sheet->freezePane('D5');
        if ($row > 5) {
            $sheet->setAutoFilter('A4:T'.($row - 1));
        }
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 4)->setPrintArea('A1:T'.$row);

        return $book;
    }
}

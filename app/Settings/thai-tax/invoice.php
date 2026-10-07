<?php

use App\Classes\FormInput;
use App\Classes\SettingForm;
use App\Models\TaxInvoiceSequence;
use App\Services\Helper;
use App\Services\ThaiTaxInvoiceService;
use App\Services\ThaiVatService;

/**
 * @var ThaiVatService
 */
$vatService = app()->make( ThaiVatService::class );

/**
 * @var ThaiTaxInvoiceService
 */
$invoiceService = app()->make( ThaiTaxInvoiceService::class );

$notices = [];

if ( ! $vatService->isRegistered() ) {
    $notices[] = [
        'color' => 'warning',
        'title' => __( 'ยังออกใบกำกับภาษีไม่ได้' ),
        'description' => __( 'ต้องตั้งสถานะเป็น "จดทะเบียนแล้ว" และกรอกเลขประจำตัวผู้เสียภาษีที่ถูกต้องในแท็บ "ข้อมูลผู้เสียภาษี" ก่อน จนกว่าจะทำ ระบบจะออกได้เพียงใบเสร็จรับเงิน' ),
    ];
}

$scope = $invoiceService->currentScope();
$sequence = TaxInvoiceSequence::where( 'scope', $scope )->first();

$notices[] = [
    'color' => 'info',
    'title' => sprintf( __( 'ชุดเลขที่ปัจจุบัน: %s' ), $scope ),
    'description' => $sequence
        ? sprintf(
            __( 'ออกไปแล้ว %d ฉบับ เลขล่าสุดคือ %s — เลขถัดไปจะเป็น %s เลขที่ออกแล้วจะไม่ถูกใช้ซ้ำและไม่ถูกลบ การยกเลิกจะบันทึกเป็นสถานะ ไม่ใช่การลบเลข' ),
            $sequence->last_number,
            $invoiceService->formatNumber( $scope, $sequence->last_number ),
            $invoiceService->formatNumber( $scope, $sequence->last_number + 1 )
        )
        : sprintf(
            __( 'ยังไม่มีการออกเอกสารในชุดนี้ ฉบับแรกจะเป็นเลขที่ %s' ),
            $invoiceService->formatNumber( $scope, (int) ns()->option->get( 'ns_thai_tax_invoice_start_number', 0 ) + 1 )
        ),
];

return SettingForm::tab(
    identifier: 'invoice',
    label: __( 'ใบกำกับภาษี' ),
    notices: $notices,
    fields: SettingForm::fields(
        FormInput::select(
            label: __( 'ออกใบกำกับภาษีอย่างย่อทุกบิล' ),
            name: 'ns_thai_tax_invoice_auto_abbreviated',
            options: Helper::kvToJsOptions( [
                'yes' => __( 'ออกอัตโนมัติเมื่อชำระเงิน' ),
                'no' => __( 'ออกเมื่อกดสั่งเท่านั้น' ),
            ] ),
            value: ns()->option->get( 'ns_thai_tax_invoice_auto_abbreviated', 'no' ),
            validation: 'required',
            description: __( 'ร้านค้าปลีกที่จดทะเบียน VAT ต้องออกใบกำกับภาษีอย่างย่อให้ลูกค้าทุกครั้งที่ขาย การเปิดตัวเลือกนี้ทำให้ระบบออกให้เองตอนชำระเงินเสร็จ' )
        ),
        FormInput::text(
            label: __( 'อักษรนำหน้าเลขที่เอกสาร' ),
            name: 'ns_thai_tax_invoice_prefix',
            value: ns()->option->get( 'ns_thai_tax_invoice_prefix', 'TIV' ),
            validation: 'nullable',
            description: __( 'เลขที่จะอยู่ในรูปแบบ อักษรนำหน้า-ปี-เลขลำดับ 6 หลัก เช่น TIV-2026-000001 และเริ่มนับใหม่ทุกปี' )
        ),
        FormInput::number(
            label: __( 'เริ่มนับจากเลข' ),
            name: 'ns_thai_tax_invoice_start_number',
            value: ns()->option->get( 'ns_thai_tax_invoice_start_number', 0 ),
            validation: 'nullable|integer|min:0',
            description: __( 'ใช้เมื่อย้ายมาจากระบบเดิมและต้องการให้เลขต่อจากของเดิม มีผลกับชุดเลขที่ยังไม่เคยออกเอกสารเท่านั้น' )
        ),
        FormInput::textarea(
            label: __( 'ข้อความท้ายใบกำกับภาษี' ),
            name: 'ns_thai_tax_invoice_footer',
            value: ns()->option->get( 'ns_thai_tax_invoice_footer', '' ),
            description: __( 'พิมพ์ต่อท้ายเอกสาร เช่น เงื่อนไขการคืนสินค้า หรือข้อความที่ฝ่ายบัญชีต้องการ' )
        ),
    )
);

<?php

use App\Classes\FormInput;
use App\Classes\SettingForm;
use App\Models\WithholdingTaxSequence;
use App\Services\Helper;
use App\Services\ThaiVatService;
use App\Services\ThaiWithholdingTaxService;

/**
 * @var ThaiVatService
 */
$vatService = app()->make( ThaiVatService::class );

/**
 * @var ThaiWithholdingTaxService
 */
$whtService = app()->make( ThaiWithholdingTaxService::class );

$notices = [];

if ( ! $vatService->isValidTaxpayerId( $vatService->taxpayerId() ) ) {
    $notices[] = [
        'color' => 'warning',
        'title' => __( 'ยังออกหนังสือรับรองไม่ได้' ),
        'description' => __( 'ต้องกรอกเลขประจำตัวผู้เสียภาษีของร้านให้ถูกต้องในแท็บ "ข้อมูลผู้เสียภาษี" ก่อน เพราะเลขนี้ต้องพิมพ์อยู่บนหนังสือรับรองในฐานะผู้จ่ายเงิน' ),
    ];
}

$scope = $whtService->currentScope();
$sequence = WithholdingTaxSequence::where( 'scope', $scope )->first();

$notices[] = [
    'color' => 'info',
    'title' => sprintf( __( 'ชุดเลขที่ปัจจุบัน: %s' ), $scope ),
    'description' => $sequence
        ? sprintf(
            __( 'ออกไปแล้ว %d ฉบับ เลขถัดไปจะเป็น %s เลขที่ออกแล้วจะไม่ถูกใช้ซ้ำ การยกเลิกบันทึกเป็นสถานะ ไม่ใช่การลบเลข' ),
            $sequence->last_number,
            $whtService->formatNumber( $scope, $sequence->last_number + 1 )
        )
        : sprintf(
            __( 'ยังไม่มีการออกหนังสือรับรองในชุดนี้ ฉบับแรกจะเป็นเลขที่ %s' ),
            $whtService->formatNumber( $scope, (int) ns()->option->get( 'ns_thai_wht_start_number', 0 ) + 1 )
        ),
];

$notices[] = [
    'color' => 'info',
    'title' => __( 'ฐานที่ใช้คำนวณคือยอดก่อน VAT' ),
    'description' => __( 'ภาษีหัก ณ ที่จ่ายคิดจากยอดก่อนภาษีมูลค่าเพิ่มเสมอ ถ้าคิดจากยอดรวม VAT จะหักเกินและผู้รับเงินจะได้เงินขาด เช่น ค่าบริการ 107 บาท (รวม VAT 7%) ฐานคือ 100 บาท หัก 3% = 3 บาท ไม่ใช่ 3.21 บาท' ),
];

return SettingForm::tab(
    identifier: 'withholding',
    label: __( 'ภาษีหัก ณ ที่จ่าย' ),
    notices: $notices,
    fields: SettingForm::fields(
        FormInput::select(
            label: __( 'ร้านเป็นผู้มีหน้าที่หักภาษี ณ ที่จ่าย' ),
            name: 'ns_thai_wht_enabled',
            options: Helper::kvToJsOptions( [
                'no' => __( 'ไม่ใช่' ),
                'yes' => __( 'ใช่ — หักและออกหนังสือรับรองได้' ),
            ] ),
            value: ns()->option->get( 'ns_thai_wht_enabled', 'no' ),
            validation: 'required',
            description: __( 'บริษัทและห้างหุ้นส่วนนิติบุคคลมีหน้าที่หักภาษี ณ ที่จ่ายเมื่อจ่ายค่าบริการ ค่าเช่า ค่าขนส่ง ค่าโฆษณา ถ้าร้านเป็นบุคคลธรรมดาโดยทั่วไปไม่มีหน้าที่นี้ ให้เลือก "ไม่ใช่"' )
        ),
        FormInput::select(
            label: __( 'อัตราที่ใช้บ่อย' ),
            name: 'ns_thai_wht_default_rate',
            options: Helper::kvToJsOptions( $whtService->rates() ),
            value: (string) ns()->option->get( 'ns_thai_wht_default_rate', '3' ),
            validation: 'required',
            description: __( 'ใช้เป็นค่าเริ่มต้นในฟอร์มบันทึกการหักภาษี เปลี่ยนเป็นอัตราอื่นได้ทุกครั้งที่บันทึก' )
        ),
        FormInput::select(
            label: __( 'ประเภทเงินได้ที่ใช้บ่อย' ),
            name: 'ns_thai_wht_default_income_type',
            options: Helper::kvToJsOptions( $whtService->incomeTypes() ),
            value: (string) ns()->option->get( 'ns_thai_wht_default_income_type', '40(8)' ),
            validation: 'required',
            description: __( 'ประเภทเงินได้ตามมาตรา 40 ที่จะเลือกไว้ให้ล่วงหน้า ค่าบริการทั่วไปอยู่ในกลุ่ม 40(8)' )
        ),
        FormInput::text(
            label: __( 'อักษรนำหน้าเลขที่หนังสือรับรอง' ),
            name: 'ns_thai_wht_prefix',
            value: ns()->option->get( 'ns_thai_wht_prefix', 'WHT' ),
            validation: 'nullable',
            description: __( 'เลขที่จะอยู่ในรูปแบบ อักษรนำหน้า-ปี-เลขลำดับ 6 หลัก เช่น WHT-2026-000001 และเริ่มนับใหม่ทุกปี' )
        ),
        FormInput::number(
            label: __( 'เริ่มนับจากเลข' ),
            name: 'ns_thai_wht_start_number',
            value: ns()->option->get( 'ns_thai_wht_start_number', 0 ),
            validation: 'nullable|integer|min:0',
            description: __( 'ใช้เมื่อย้ายมาจากระบบเดิมและต้องการให้เลขต่อจากของเดิม มีผลกับชุดเลขที่ยังไม่เคยออกหนังสือรับรองเท่านั้น' )
        ),
        FormInput::textarea(
            label: __( 'ข้อความท้ายหนังสือรับรอง' ),
            name: 'ns_thai_wht_footer',
            value: ns()->option->get( 'ns_thai_wht_footer', '' ),
            description: __( 'พิมพ์ต่อท้ายเอกสาร เช่น ชื่อผู้มีอำนาจลงนาม หรือข้อความที่ฝ่ายบัญชีต้องการ' )
        ),
    )
);

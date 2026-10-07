<?php

use App\Classes\FormInput;
use App\Classes\SettingForm;
use App\Services\Helper;
use App\Services\ThaiVatService;

/**
 * @var ThaiVatService
 */
$vatService = app()->make( ThaiVatService::class );

/**
 * Read through the service, not straight from the options: the option layer casts
 * digit-only values to integers and drops the leading zeros that every Thai
 * taxpayer number and branch code carries.
 */
$taxId = $vatService->taxpayerId();
$registered = ns()->option->get( 'ns_thai_vat_registered', 'no' ) === 'yes';

$notices = [];

/**
 * A shop that claims to be registered but has not entered a valid taxpayer
 * number cannot issue a tax invoice, and the till would silently fall back to a
 * plain receipt. Saying so here is cheaper than discovering it at the counter.
 */
if ( $registered && ! $vatService->isValidTaxpayerId( $taxId ) ) {
    $notices[] = [
        'color' => 'warning',
        'title' => __( 'ยังออกใบกำกับภาษีไม่ได้' ),
        'description' => __( 'ร้านถูกตั้งเป็น "จดทะเบียน VAT แล้ว" แต่เลขประจำตัวผู้เสียภาษียังไม่ถูกต้อง ระบบจะออกได้เพียงใบเสร็จรับเงินจนกว่าจะกรอกเลข 13 หลักที่ถูกต้อง' ),
    ];
}

if ( ! $registered ) {
    $notices[] = [
        'color' => 'info',
        'title' => __( 'ยังไม่ได้จดทะเบียน VAT' ),
        'description' => __( 'ผู้ประกอบการที่มีรายได้ไม่ถึง 1.8 ล้านบาทต่อปีไม่ต้องจดทะเบียน และออกใบกำกับภาษีไม่ได้ ระบบจะออกใบเสร็จรับเงินให้แทน เมื่อจดทะเบียนแล้วให้เปิดสวิตช์นี้และกรอกข้อมูลตามที่จด' ),
    ];
}

return SettingForm::tab(
    identifier: 'identity',
    label: __( 'ข้อมูลผู้เสียภาษี' ),
    notices: $notices,
    fields: SettingForm::fields(
        FormInput::select(
            label: __( 'จดทะเบียน VAT แล้ว' ),
            name: 'ns_thai_vat_registered',
            options: Helper::kvToJsOptions( [
                'no' => __( 'ยังไม่จดทะเบียน' ),
                'yes' => __( 'จดทะเบียนแล้ว' ),
            ] ),
            value: ns()->option->get( 'ns_thai_vat_registered', 'no' ),
            validation: 'required',
            description: __( 'ต้องจดทะเบียนก่อนจึงจะออกใบกำกับภาษีได้ หน้าที่จดทะเบียนเริ่มเมื่อรายได้เกิน 1.8 ล้านบาทต่อปี' )
        ),
        FormInput::text(
            label: __( 'เลขประจำตัวผู้เสียภาษี (13 หลัก)' ),
            name: 'ns_thai_tax_id',
            value: $taxId,
            description: __( 'ระบบตรวจเลขตรวจสอบหลักที่ 13 ให้ เลขที่ผิดจะทำให้ผู้ซื้อนำใบกำกับภาษีไปใช้เป็นภาษีซื้อไม่ได้' )
        ),
        FormInput::text(
            label: __( 'ชื่อผู้ประกอบการตามที่จดทะเบียน' ),
            name: 'ns_thai_tax_registered_name',
            value: ns()->option->get( 'ns_thai_tax_registered_name', '' ),
            description: __( 'ต้องตรงกับ ภ.พ.20 ไม่ใช่ชื่อร้านที่ใช้ทางการค้า' )
        ),
        FormInput::textarea(
            label: __( 'ที่อยู่ตามที่จดทะเบียน' ),
            name: 'ns_thai_tax_registered_address',
            value: ns()->option->get( 'ns_thai_tax_registered_address', '' ),
            description: __( 'ที่อยู่สถานประกอบการตามที่จดทะเบียนภาษีมูลค่าเพิ่ม จะถูกพิมพ์บนใบกำกับภาษี' )
        ),
        FormInput::text(
            label: __( 'เลขที่สาขา' ),
            name: 'ns_thai_tax_branch_code',
            value: $vatService->branchCode(),
            description: __( '5 หลัก — 00000 คือสำนักงานใหญ่ สาขาแรกคือ 00001 ต้องระบุบนใบกำกับภาษีทุกใบ' )
        ),
    )
);

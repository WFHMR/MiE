<?php

use App\Classes\FormInput;
use App\Classes\SettingForm;
use App\Models\VatRate;
use App\Services\Helper;
use App\Services\ThaiVatService;

/**
 * @var ThaiVatService
 */
$vatService = app()->make( ThaiVatService::class );

$current = $vatService->rateRecordOn();

$notices = [];

if ( $current instanceof VatRate ) {
    $notices[] = [
        'color' => 'info',
        'title' => sprintf( __( 'อัตราที่ใช้อยู่ตอนนี้: %s%%' ), rtrim( rtrim( number_format( (float) $current->rate, 2, '.', '' ), '0' ), '.' ) ),
        'description' => sprintf(
            __( 'มีผลตั้งแต่ %s %s — การขายแต่ละรายการจะถูกคำนวณด้วยอัตราที่มีผล ณ วันขาย ดังนั้นการแก้อัตราที่นี่ไม่กระทบใบกำกับภาษีที่ออกไปแล้ว' ),
            $current->effective_from?->format( 'd/m/Y' ),
            $current->effective_to ? sprintf( __( 'ถึง %s' ), $current->effective_to->format( 'd/m/Y' ) ) : __( '(ยังไม่กำหนดวันสิ้นสุด)' )
        ),
    ];
} else {
    $notices[] = [
        'color' => 'warning',
        'title' => __( 'ยังไม่มีอัตราภาษีที่ใช้ได้' ),
        'description' => __( 'บันทึกหน้านี้หนึ่งครั้งเพื่อสร้างช่วงอัตราแรก จนกว่าจะบันทึก ระบบจะใช้ค่าเริ่มต้น 7% ' ),
    ];
}

/**
 * The reduced rate is granted by a Royal Decree with an expiry date, renewed each
 * year. If the shop has entered an end date and it is close, the till will start
 * taxing at whatever the next period says — or at nothing, if no next period was
 * entered. That is worth a warning before it happens rather than after.
 */
$daysBeforeExpiry = $current instanceof VatRate && $current->effective_to !== null
    ? now()->startOfDay()->diffInDays( $current->effective_to->copy()->startOfDay(), false )
    : null;

if ( $daysBeforeExpiry !== null && $daysBeforeExpiry >= 0 && $daysBeforeExpiry <= 60 ) {
    $notices[] = [
        'color' => 'warning',
        'title' => __( 'อัตราภาษีกำลังจะสิ้นสุด' ),
        'description' => sprintf(
            __( 'ช่วงอัตราปัจจุบันสิ้นสุดวันที่ %s หากพระราชกฤษฎีกาต่ออายุแล้ว ให้แก้วันสิ้นสุดหรือเปิดช่วงใหม่ มิฉะนั้นการขายหลังวันนั้นจะคำนวณภาษีผิด' ),
            $current->effective_to->format( 'd/m/Y' )
        ),
    ];
}

/**
 * Closed periods, most recent first. Read only — a period is closed by opening the
 * next one, never by editing history.
 */
$history = VatRate::orderBy( 'effective_from', 'desc' )->limit( 6 )->get();

if ( $history->count() > 1 ) {
    $notices[] = [
        'color' => 'info',
        'title' => __( 'ประวัติอัตราภาษี' ),
        'description' => $history->map( fn( VatRate $rate ) => sprintf(
            '%s%% : %s → %s',
            rtrim( rtrim( number_format( (float) $rate->rate, 2, '.', '' ), '0' ), '.' ),
            $rate->effective_from?->format( 'd/m/Y' ),
            $rate->effective_to?->format( 'd/m/Y' ) ?? __( 'ปัจจุบัน' )
        ) )->implode( '  |  ' ),
    ];
}

return SettingForm::tab(
    identifier: 'vat',
    label: __( 'อัตราภาษีมูลค่าเพิ่ม' ),
    notices: $notices,
    fields: SettingForm::fields(
        FormInput::number(
            label: __( 'อัตรา VAT (%)' ),
            name: 'ns_thai_vat_rate',
            value: $current instanceof VatRate ? (float) $current->rate : ns()->option->get( 'ns_thai_vat_rate', ThaiVatService::DEFAULT_RATE ),
            validation: 'required|numeric|min:0|max:100',
            description: __( 'อัตราตามกฎหมายในมาตรา 80 คือ 10% แต่ลดเหลือ 7% โดยพระราชกฤษฎีกาที่ต่ออายุเป็นรายปี' )
        ),
        FormInput::date(
            label: __( 'เริ่มมีผลวันที่' ),
            name: 'ns_thai_vat_effective_from',
            value: $current instanceof VatRate
                ? $current->effective_from?->toDateString()
                : ns()->option->get( 'ns_thai_vat_effective_from', now()->startOfYear()->toDateString() ),
            validation: 'required|date',
            description: __( 'เมื่อบันทึกด้วยวันเริ่มใหม่ ระบบจะปิดช่วงอัตราเดิมในวันก่อนหน้าและเปิดช่วงใหม่ให้อัตโนมัติ' )
        ),
        FormInput::date(
            label: __( 'สิ้นสุดวันที่' ),
            name: 'ns_thai_vat_effective_to',
            value: $current instanceof VatRate ? $current->effective_to?->toDateString() : ns()->option->get( 'ns_thai_vat_effective_to', '' ),
            description: __( 'เว้นว่างไว้ถ้ายังไม่มีกำหนดสิ้นสุด กรอกเมื่อรู้วันสิ้นอายุของพระราชกฤษฎีกา' )
        ),
        FormInput::text(
            label: __( 'อ้างอิงกฎหมาย' ),
            name: 'ns_thai_vat_legal_reference',
            value: ns()->option->get( 'ns_thai_vat_legal_reference', '' ),
            description: __( 'เช่น เลขที่พระราชกฤษฎีกาที่กำหนดอัตรานี้ ใช้ตอบผู้สอบบัญชีว่าทำไมเป็นอัตรานี้' )
        ),
        FormInput::select(
            label: __( 'สถานะภาษีเริ่มต้นของสินค้าใหม่' ),
            name: 'ns_thai_vat_default_status',
            options: Helper::kvToJsOptions( $vatService->statuses() ),
            value: ns()->option->get( 'ns_thai_vat_default_status', ThaiVatService::STATUS_STANDARD ),
            validation: 'required',
            description: __( 'สินค้าแปรรูปเกือบทั้งหมดเสีย VAT ปกติ จึงควรตั้งค่าเริ่มต้นเป็น "เสีย VAT" แล้วไปยกเว้นเป็นรายตัวที่หน้าสินค้า' )
        ),
    )
);

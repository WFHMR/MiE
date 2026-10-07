<?php

namespace App\Settings;

use App\Classes\FormInput;
use App\Classes\SettingForm;
use App\Services\Helper;
use App\Services\PasswordPolicyService;
use App\Services\SettingsPage;

/**
 * ตั้งค่า → นโยบายรหัสผ่าน.
 *
 * Reachable at /dashboard/settings/password. There is no route or registry to add: the settings
 * pages are discovered from this folder by SettingsPageProvider, which matches on IDENTIFIER.
 *
 * Every default is what the application did before this page existed — a six character minimum and
 * no other requirement — so an installation that never opens this screen behaves exactly as it did.
 * PasswordPolicyService reads these values and is the single thing every password box is validated
 * against.
 */
class PasswordSettings extends SettingsPage
{
    const IDENTIFIER = 'password';

    const AUTOLOAD = true;

    public function __construct()
    {
        $policy = app()->make( PasswordPolicyService::class );

        $this->form = SettingForm::form(
            title: __( 'นโยบายรหัสผ่าน' ),
            description: sprintf(
                __( 'กำหนดความแข็งแรงของรหัสผ่านที่ทุกคนในร้านต้องใช้ มีผลทันทีกับการสร้างผู้ใช้ใหม่ การแก้ไขผู้ใช้ การเปลี่ยนรหัสผ่านในหน้าโปรไฟล์ การสมัครสมาชิก และการกู้คืนรหัสผ่าน — นโยบายที่ใช้อยู่ตอนนี้: %s' ),
                $policy->describe()
            ),
            tabs: SettingForm::tabs(
                SettingForm::tab(
                    label: __( 'ข้อกำหนด' ),
                    identifier: 'requirements',
                    fields: SettingForm::fields(
                        FormInput::number(
                            name: 'ns_password_min_length',
                            value: ns()->option->get( 'ns_password_min_length', PasswordPolicyService::DEFAULT_MIN_LENGTH ),
                            label: __( 'ความยาวขั้นต่ำ' ),
                            description: sprintf(
                                __( 'จำนวนตัวอักษรที่น้อยที่สุดที่ยอมรับ ใส่ได้ตั้งแต่ %s ถึง %s ค่าเริ่มต้นคือ %s' ),
                                PasswordPolicyService::MIN_ALLOWED_LENGTH,
                                PasswordPolicyService::MAX_ALLOWED_LENGTH,
                                PasswordPolicyService::DEFAULT_MIN_LENGTH
                            ),
                            validation: 'required|integer|min:' . PasswordPolicyService::MIN_ALLOWED_LENGTH . '|max:' . PasswordPolicyService::MAX_ALLOWED_LENGTH,
                        ),
                        FormInput::switch(
                            name: 'ns_password_require_uppercase',
                            value: ns()->option->get( 'ns_password_require_uppercase', 'no' ),
                            options: Helper::kvToJsOptions( [
                                'no' => __( 'ไม่บังคับ' ),
                                'yes' => __( 'บังคับ' ),
                            ] ),
                            label: __( 'ต้องมีตัวพิมพ์ใหญ่' ),
                            description: __( 'บังคับให้มีตัวอักษรภาษาอังกฤษตัวพิมพ์ใหญ่ (A-Z) อย่างน้อยหนึ่งตัว' ),
                        ),
                        FormInput::switch(
                            name: 'ns_password_require_lowercase',
                            value: ns()->option->get( 'ns_password_require_lowercase', 'no' ),
                            options: Helper::kvToJsOptions( [
                                'no' => __( 'ไม่บังคับ' ),
                                'yes' => __( 'บังคับ' ),
                            ] ),
                            label: __( 'ต้องมีตัวพิมพ์เล็ก' ),
                            description: __( 'บังคับให้มีตัวอักษรภาษาอังกฤษตัวพิมพ์เล็ก (a-z) อย่างน้อยหนึ่งตัว' ),
                        ),
                        FormInput::switch(
                            name: 'ns_password_require_number',
                            value: ns()->option->get( 'ns_password_require_number', 'no' ),
                            options: Helper::kvToJsOptions( [
                                'no' => __( 'ไม่บังคับ' ),
                                'yes' => __( 'บังคับ' ),
                            ] ),
                            label: __( 'ต้องมีตัวเลข' ),
                            description: __( 'บังคับให้มีตัวเลข (0-9) อย่างน้อยหนึ่งตัว' ),
                        ),
                        FormInput::switch(
                            name: 'ns_password_require_symbol',
                            value: ns()->option->get( 'ns_password_require_symbol', 'no' ),
                            options: Helper::kvToJsOptions( [
                                'no' => __( 'ไม่บังคับ' ),
                                'yes' => __( 'บังคับ' ),
                            ] ),
                            label: __( 'ต้องมีอักขระพิเศษ' ),
                            description: __( 'บังคับให้มีอักขระที่ไม่ใช่ตัวอักษรและไม่ใช่ตัวเลข อย่างน้อยหนึ่งตัว เช่น ! @ # $ %' ),
                        ),
                        FormInput::switch(
                            name: 'ns_password_block_common',
                            value: ns()->option->get( 'ns_password_block_common', 'no' ),
                            options: Helper::kvToJsOptions( [
                                'no' => __( 'ไม่บังคับ' ),
                                'yes' => __( 'บังคับ' ),
                            ] ),
                            label: __( 'ห้ามรหัสผ่านที่เดาง่าย' ),
                            description: __( 'ปฏิเสธรหัสผ่านยอดนิยมที่คนเดาเป็นอันดับแรก เช่น 123456, password, qwerty' ),
                        ),
                    )
                ),
            )
        );
    }
}

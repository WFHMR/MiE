<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * @property int         $id
 * @property int|null    $user_id
 * @property string|null $user_name
 * @property int|null    $branch_id
 * @property string|null $branch_name
 * @property string      $action
 * @property string      $resource
 * @property string|null $resource_label
 * @property int|null    $record_id
 * @property string|null $record_label
 * @property string|null $ip_address
 * @property string|null $changes
 * @property Carbon|null $created_at
 */
class AuditTrail extends NsRootModel
{
    protected $table = 'nexopos_audit_trails';

    /**
     * An audit row is written once and never touched again, so there is nothing for updated_at to
     * say. created_at is set explicitly by the service.
     */
    public $timestamps = false;

    const ACTION_CREATED = 'created';

    const ACTION_UPDATED = 'updated';

    const ACTION_DELETED = 'deleted';

    const ACTION_SIGNED_IN = 'signed_in';

    const ACTION_SETTINGS_SAVED = 'settings_saved';

    /**
     * Wording aimed at the shop owner reading the screen, not at a developer.
     */
    public static function actionLabel( ?string $action ): string
    {
        return match ( $action ) {
            self::ACTION_CREATED => __( 'เพิ่มรายการ' ),
            self::ACTION_UPDATED => __( 'แก้ไข' ),
            self::ACTION_DELETED => __( 'ลบ' ),
            self::ACTION_SIGNED_IN => __( 'เข้าสู่ระบบ' ),
            self::ACTION_SETTINGS_SAVED => __( 'บันทึกการตั้งค่า' ),
            default => (string) $action,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        return [
            self::ACTION_CREATED => self::actionLabel( self::ACTION_CREATED ),
            self::ACTION_UPDATED => self::actionLabel( self::ACTION_UPDATED ),
            self::ACTION_DELETED => self::actionLabel( self::ACTION_DELETED ),
            self::ACTION_SIGNED_IN => self::actionLabel( self::ACTION_SIGNED_IN ),
            self::ACTION_SETTINGS_SAVED => self::actionLabel( self::ACTION_SETTINGS_SAVED ),
        ];
    }

    public function scopeWithAction( $query, string $action )
    {
        return $query->where( 'action', $action );
    }

    public function scopeWithResource( $query, string $resource )
    {
        return $query->where( 'resource', $resource );
    }
}

<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the audit trail.
 *
 * Two rules govern everything here.
 *
 * The first is that recording must never be able to break the thing it is recording. A till that
 * cannot take a sale because the audit table is full, or because a column was renamed, is a worse
 * outcome than an audit gap. So every write is wrapped: a failure is logged and swallowed, and the
 * sale, the product save or the deletion carries on.
 *
 * The second is that a secret must not be copied into a table that the shop reads on screen. The
 * submitted values are useful — "the price was changed to 80" is the whole point — but those same
 * submissions carry passwords, tokens and API keys. Anything whose field name looks like a secret
 * is replaced before the row is written, so the audit trail cannot become the easiest place in the
 * installation to read a password from.
 */
class AuditTrailService
{
    /**
     * Field names whose values are never stored. Matched as substrings and case-insensitively, so
     * "password", "password_confirm", "api_key" and "stripe_secret" are all caught.
     *
     * @var array<int, string>
     */
    const REDACTED_KEYS = [
        'password', 'token', 'secret', 'api_key', 'apikey', 'private_key', 'credential', 'otp',
    ];

    const REDACTED_PLACEHOLDER = '[ซ่อนไว้]';

    /**
     * The whole payload is capped. An audit row documents an action, it is not a backup of the
     * record, and a product form with fifty unit rows would otherwise write tens of kilobytes on
     * every save.
     */
    const MAX_CHANGES_LENGTH = 4000;

    /**
     * Resolved once per request. Without this, a bulk action saving two hundred rows would ask the
     * database for the same branch name two hundred times.
     *
     * @var array<int, string|null>
     */
    private array $branchNames = [];

    /**
     * @param array<string, mixed>|null $changes
     */
    public function record(
        string $action,
        string $resource,
        ?string $resourceLabel = null,
        ?int $recordId = null,
        ?string $recordLabel = null,
        ?array $changes = null,
    ): ?AuditTrail {
        try {
            $user = Auth::user();
            $branchId = $this->resolveBranchId();

            $entry = new AuditTrail;
            $entry->user_id = $user?->id;
            $entry->user_name = $this->describeUser();
            $entry->branch_id = $branchId;
            $entry->branch_name = $branchId === null ? null : $this->branchName( $branchId );
            $entry->action = $action;
            $entry->resource = $resource;
            $entry->resource_label = $this->trim( $resourceLabel );
            $entry->record_id = $recordId;
            $entry->record_label = $this->trim( $recordLabel );
            $entry->ip_address = request()?->ip();
            $entry->changes = $changes === null ? null : $this->encodeChanges( $changes );
            $entry->created_at = now();
            $entry->save();

            return $entry;
        } catch ( Throwable $exception ) {
            /**
             * Deliberately swallowed. See the note on this class: the audit trail is a witness,
             * not a gatekeeper.
             */
            Log::error( 'Unable to write the audit trail: ' . $exception->getMessage(), [
                'action' => $action,
                'resource' => $resource,
            ] );

            return null;
        }
    }

    /**
     * Turns a submitted payload into something safe and bounded to store.
     *
     * @param array<string, mixed> $changes
     */
    public function encodeChanges( array $changes ): string
    {
        $encoded = json_encode( $this->redact( $changes ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        if ( $encoded === false ) {
            return '';
        }

        if ( mb_strlen( $encoded ) <= self::MAX_CHANGES_LENGTH ) {
            return $encoded;
        }

        /**
         * Cut rather than dropped. A truncated payload still answers "what was touched", and the
         * marker says plainly that there was more, so nobody reads a short row as a complete one.
         */
        return mb_substr( $encoded, 0, self::MAX_CHANGES_LENGTH ) . '…[ตัดข้อความ]';
    }

    /**
     * Replaces the value of anything whose name reads like a secret, at any depth.
     *
     * @param  array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public function redact( array $values ): array
    {
        $redacted = [];

        foreach ( $values as $key => $value ) {
            if ( is_string( $key ) && $this->keyIsSecret( $key ) ) {
                $redacted[ $key ] = self::REDACTED_PLACEHOLDER;

                continue;
            }

            $redacted[ $key ] = is_array( $value ) ? $this->redact( $value ) : $value;
        }

        return $redacted;
    }

    /**
     * The best name available for the record an action was taken on, so the list reads
     * "ลบ · สินค้า · น้ำตาลทราย 1 กก." rather than "ลบ · สินค้า · #21284".
     */
    public function describeRecord( mixed $record ): ?string
    {
        if ( ! is_object( $record ) && ! is_array( $record ) ) {
            return null;
        }

        foreach ( [ 'name', 'username', 'code', 'title', 'reference', 'email' ] as $key ) {
            /**
             * Read through property access rather than by casting the object to an array. Casting
             * an Eloquent model yields its protected properties under mangled keys — the
             * attributes are not reachable that way — so every row created through a CRUD screen
             * was recorded without a name, and the audit list read "#412" instead of naming what
             * had been touched.
             */
            $value = is_array( $record )
                ? ( $record[ $key ] ?? null )
                : ( $record->{$key} ?? null );

            if ( is_string( $value ) && $value !== '' ) {
                return $value;
            }
        }

        return null;
    }

    private function keyIsSecret( string $key ): bool
    {
        $key = strtolower( $key );

        foreach ( self::REDACTED_KEYS as $needle ) {
            if ( str_contains( $key, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * "username (ชื่อ นามสกุล)" when both are known, so the row names the person the shop knows
     * rather than only the login they were issued.
     */
    private function describeUser(): ?string
    {
        $user = Auth::user();

        if ( $user === null ) {
            return __( 'ระบบ' );
        }

        $fullName = trim( ( $user->first_name ?? '' ) . ' ' . ( $user->last_name ?? '' ) );

        return $fullName === ''
            ? (string) $user->username
            : $user->username . ' (' . $fullName . ')';
    }

    private function resolveBranchId(): ?int
    {
        try {
            return app( ProductService::class )->branchIdForCurrentUser();
        } catch ( Throwable ) {
            return null;
        }
    }

    private function branchName( int $branchId ): ?string
    {
        if ( ! array_key_exists( $branchId, $this->branchNames ) ) {
            $this->branchNames[ $branchId ] = Branch::find( $branchId )?->name;
        }

        return $this->branchNames[ $branchId ];
    }

    private function trim( ?string $value ): ?string
    {
        if ( $value === null ) {
            return null;
        }

        return mb_substr( $value, 0, 190 );
    }
}

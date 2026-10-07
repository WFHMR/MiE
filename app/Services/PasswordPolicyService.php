<?php

namespace App\Services;

/**
 * The shop's password rules, in one place.
 *
 * Before this, every screen that set a password carried its own hardcoded rule and they did not
 * agree: eleven places said "min:6", the sign-up request said only "required" — so an account could
 * be opened from the registration form with a one character password — and the artisan command that
 * creates the first administrator asked for no length at all. There was no way for the shop to ask
 * for anything stronger, and no way to find out what the rule was without reading the source.
 *
 * Every default here is today's behaviour: a six character minimum and nothing else. Switching the
 * installation to this service therefore changes nothing until somebody turns a requirement on at
 * ตั้งค่า → นโยบายรหัสผ่าน, which is the point — a rule the shop chooses, not one the code imposes.
 *
 * The rules are enforced on the server, by the "password_policy" validation rule registered in
 * PasswordPolicyServiceProvider. The forms also carry "min:N" so the browser can object immediately,
 * but that figure comes from here as well, so the two cannot drift apart.
 */
class PasswordPolicyService
{
    /**
     * Below four a password is not a secret, and above sixty-four it is a passphrase that no
     * shop keyboard is going to receive correctly. The stored option is clamped rather than
     * trusted, so a value typed into the database by hand cannot lock everybody out or let
     * everybody in.
     */
    const MIN_ALLOWED_LENGTH = 4;

    const MAX_ALLOWED_LENGTH = 64;

    const DEFAULT_MIN_LENGTH = 6;

    /**
     * Passwords that are tried first by anybody guessing, so they are worth refusing by name
     * whatever else the policy says. Kept short and obvious on purpose: a long dictionary
     * belongs in a file, and a shop's till is not defended by one.
     *
     * @var array<int, string>
     */
    const COMMON_PASSWORDS = [
        '123456', '1234567', '12345678', '123456789', '1234567890', '12345',
        'password', 'password1', 'passw0rd', 'p@ssw0rd', 'qwerty', 'qwerty123',
        'abc123', '111111', '000000', 'admin', 'admin123', 'letmein', 'welcome',
        'iloveyou', 'monkey', 'dragon', '1q2w3e4r', '123123', 'aaaaaa', 'test123',
    ];

    public function minLength(): int
    {
        $value = (int) ns()->option->get( 'ns_password_min_length', self::DEFAULT_MIN_LENGTH );

        if ( $value < self::MIN_ALLOWED_LENGTH ) {
            return self::MIN_ALLOWED_LENGTH;
        }

        if ( $value > self::MAX_ALLOWED_LENGTH ) {
            return self::MAX_ALLOWED_LENGTH;
        }

        return $value;
    }

    public function requiresUppercase(): bool
    {
        return $this->switchIsOn( 'ns_password_require_uppercase' );
    }

    public function requiresLowercase(): bool
    {
        return $this->switchIsOn( 'ns_password_require_lowercase' );
    }

    public function requiresNumber(): bool
    {
        return $this->switchIsOn( 'ns_password_require_number' );
    }

    public function requiresSymbol(): bool
    {
        return $this->switchIsOn( 'ns_password_require_symbol' );
    }

    public function blocksCommonPasswords(): bool
    {
        return $this->switchIsOn( 'ns_password_block_common' );
    }

    /**
     * Whether a password satisfies every requirement currently switched on.
     */
    public function passes( ?string $password ): bool
    {
        return $this->failures( $password ) === [];
    }

    /**
     * Every requirement the given password misses, worded for the person typing it.
     *
     * Returned as a list rather than as the first failure, so somebody who typed something far
     * too weak is told everything that is wrong with it in one go instead of discovering the
     * requirements one submission at a time.
     *
     * @return array<int, string>
     */
    public function failures( ?string $password ): array
    {
        $password = (string) $password;
        $failures = [];

        /**
         * Counted in characters and not in bytes. A Thai password is perfectly legitimate and
         * strlen() would report each of its characters as three, so a four character Thai
         * password would pass a twelve character rule.
         */
        if ( mb_strlen( $password ) < $this->minLength() ) {
            $failures[] = sprintf( __( 'ต้องยาวอย่างน้อย %s ตัวอักษร' ), $this->minLength() );
        }

        if ( $this->requiresUppercase() && ! preg_match( '/[A-Z]/', $password ) ) {
            $failures[] = __( 'ต้องมีตัวพิมพ์ใหญ่ (A-Z) อย่างน้อยหนึ่งตัว' );
        }

        if ( $this->requiresLowercase() && ! preg_match( '/[a-z]/', $password ) ) {
            $failures[] = __( 'ต้องมีตัวพิมพ์เล็ก (a-z) อย่างน้อยหนึ่งตัว' );
        }

        if ( $this->requiresNumber() && ! preg_match( '/[0-9]/', $password ) ) {
            $failures[] = __( 'ต้องมีตัวเลข (0-9) อย่างน้อยหนึ่งตัว' );
        }

        /**
         * Anything that is not a letter, a digit or a space counts. Writing out a list of
         * permitted symbols would refuse a perfectly strong password for containing a character
         * nobody thought to list.
         */
        if ( $this->requiresSymbol() && ! preg_match( '/[^\p{L}\p{N}\s]/u', $password ) ) {
            $failures[] = __( 'ต้องมีอักขระพิเศษ (เช่น ! @ # $ %) อย่างน้อยหนึ่งตัว' );
        }

        if ( $this->blocksCommonPasswords() && in_array( mb_strtolower( $password ), self::COMMON_PASSWORDS, true ) ) {
            $failures[] = __( 'ห้ามใช้รหัสผ่านที่เดาง่าย เช่น 123456 หรือ password' );
        }

        return $failures;
    }

    /**
     * The policy stated as one sentence, used as the error message and as the help text under
     * every password box. The person being refused is told the rule, not just that they failed it.
     */
    public function describe(): string
    {
        $requirements = [ sprintf( __( 'ยาวอย่างน้อย %s ตัวอักษร' ), $this->minLength() ) ];

        if ( $this->requiresUppercase() ) {
            $requirements[] = __( 'มีตัวพิมพ์ใหญ่' );
        }

        if ( $this->requiresLowercase() ) {
            $requirements[] = __( 'มีตัวพิมพ์เล็ก' );
        }

        if ( $this->requiresNumber() ) {
            $requirements[] = __( 'มีตัวเลข' );
        }

        if ( $this->requiresSymbol() ) {
            $requirements[] = __( 'มีอักขระพิเศษ' );
        }

        if ( $this->blocksCommonPasswords() ) {
            $requirements[] = __( 'ไม่ใช่รหัสผ่านที่เดาง่าย' );
        }

        return sprintf( __( 'รหัสผ่านต้อง %s' ), implode( __( ', ' ), $requirements ) );
    }

    /**
     * The rule string the forms and the requests use.
     *
     * "min" is included alongside the policy rule so the browser can refuse a short password
     * without a round trip — the client side validator understands "min" and ignores rules it
     * does not know. The server still applies the whole policy through "password_policy".
     *
     * @param bool $optional true where a blank field means "leave the password alone", which is
     *                       what editing an existing user or a profile means.
     */
    public function validationString( bool $optional = false ): string
    {
        return implode( '|', [
            $optional ? 'sometimes' : 'required',
            'min:' . $this->minLength(),
            'password_policy',
        ] );
    }

    private function switchIsOn( string $option ): bool
    {
        return in_array( ns()->option->get( $option, 'no' ), [ 'yes', true, 1, '1' ], true );
    }
}

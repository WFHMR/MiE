<?php

namespace Tests\Feature;

use App\Fields\AuthLoginFields;
use App\Models\Role;
use App\Services\PasswordPolicyService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The shop's password rules.
 *
 * Before this there were none to speak of. Eleven screens each carried their own hardcoded
 * "min:6", the sign-up request carried only "required" — so the registration form accepted a one
 * character password while the form field told the browser six — and the artisan command that
 * creates an administrator accepted four. Nothing let the shop ask for anything stronger.
 *
 * What is pinned here: the defaults are the old behaviour, so switching to the policy changed
 * nothing on its own; a configured policy is actually refused at the door rather than only
 * described; the figure is clamped so a value typed into the options table cannot lock the shop
 * out; and the sign-in box is deliberately NOT held to the policy, because that would lock out
 * every member of staff whose existing password is shorter than a newly raised minimum.
 */
class PasswordPolicyTest extends TestCase
{
    use WithAuthentication;

    /**
     * @var array<int, string>
     */
    private array $options = [
        'ns_password_min_length',
        'ns_password_require_uppercase',
        'ns_password_require_lowercase',
        'ns_password_require_number',
        'ns_password_require_symbol',
        'ns_password_block_common',
    ];

    protected function tearDown(): void
    {
        foreach ( $this->options as $option ) {
            ns()->option->delete( $option );
        }

        parent::tearDown();
    }

    private function policy(): PasswordPolicyService
    {
        return app()->make( PasswordPolicyService::class );
    }

    public function test_the_default_policy_is_the_behaviour_the_shop_already_had(): void
    {
        $policy = $this->policy();

        $this->assertSame( 6, $policy->minLength(), 'The default minimum is no longer six, so deploying this changed the rule under the shop.' );
        $this->assertFalse( $policy->requiresUppercase() );
        $this->assertFalse( $policy->requiresLowercase() );
        $this->assertFalse( $policy->requiresNumber() );
        $this->assertFalse( $policy->requiresSymbol() );
        $this->assertFalse( $policy->blocksCommonPasswords() );

        $this->assertTrue( $policy->passes( 'abcdef' ), 'A six character password is refused by the default policy, which is stricter than what the shop had.' );
        $this->assertFalse( $policy->passes( 'abc' ) );
    }

    public function test_every_requirement_is_applied_once_it_is_switched_on(): void
    {
        ns()->option->set( 'ns_password_min_length', 10 );
        ns()->option->set( 'ns_password_require_uppercase', 'yes' );
        ns()->option->set( 'ns_password_require_number', 'yes' );
        ns()->option->set( 'ns_password_require_symbol', 'yes' );

        $policy = $this->policy();

        $this->assertFalse( $policy->passes( 'abcdefghij' ), 'no uppercase, no digit and no symbol, yet accepted' );
        $this->assertFalse( $policy->passes( 'Abc1!' ), 'too short, yet accepted' );
        $this->assertTrue( $policy->passes( 'Abcdefgh1!' ) );

        $this->assertCount(
            3,
            $policy->failures( 'abcdefghij' ),
            'Every unmet requirement should be reported at once, so somebody is not told them one submission at a time.'
        );
    }

    public function test_a_guessable_password_is_refused_when_the_shop_asks_for_that(): void
    {
        ns()->option->set( 'ns_password_block_common', 'yes' );

        $this->assertFalse( $this->policy()->passes( 'password' ) );
        $this->assertFalse( $this->policy()->passes( 'PASSWORD' ), 'the list should be matched regardless of case' );
        $this->assertTrue( $this->policy()->passes( 'rgbf-till-2569' ) );
    }

    public function test_the_minimum_length_is_clamped_to_something_sane(): void
    {
        ns()->option->set( 'ns_password_min_length', 1 );
        $this->assertSame(
            PasswordPolicyService::MIN_ALLOWED_LENGTH,
            $this->policy()->minLength(),
            'A minimum of one would let a single character password through every screen in the shop.'
        );

        ns()->option->set( 'ns_password_min_length', 5000 );
        $this->assertSame(
            PasswordPolicyService::MAX_ALLOWED_LENGTH,
            $this->policy()->minLength(),
            'An unreachable minimum locks everybody out of setting a password at all.'
        );
    }

    /**
     * A Thai password is legitimate and strlen() counts each of its characters as three, so a four
     * character Thai password would satisfy a twelve character rule.
     */
    public function test_the_length_is_counted_in_characters_and_not_in_bytes(): void
    {
        ns()->option->set( 'ns_password_min_length', 10 );

        $this->assertFalse( $this->policy()->passes( 'รหัสผ่าน' ) );
        $this->assertTrue( $this->policy()->passes( 'รหัสผ่านของร้านค้า' ) );
    }

    /**
     * The rule has to be registered with the validator, not merely implemented — a rule name the
     * validator does not know is silently ignored and everything passes.
     */
    public function test_the_rule_is_registered_with_the_validator(): void
    {
        ns()->option->set( 'ns_password_min_length', 8 );
        ns()->option->set( 'ns_password_require_number', 'yes' );

        $validator = Validator::make(
            [ 'password' => 'abcdefghij' ],
            [ 'password' => 'required|password_policy' ]
        );

        $this->assertTrue( $validator->fails(), 'The "password_policy" rule is not registered, so every form using it validates nothing.' );
        $this->assertStringContainsString(
            'ตัวเลข',
            $validator->errors()->first( 'password' ),
            'The message should state the rule the person is being held to, not just that they failed it.'
        );

        $this->assertFalse(
            Validator::make( [ 'password' => 'abcdefgh1' ], [ 'password' => 'required|password_policy' ] )->fails()
        );
    }

    /**
     * An empty value is left to "required" and "sometimes" to judge: clearing the password box on
     * the edit-user form means "keep the current password" and must not be refused for being short.
     */
    public function test_an_empty_value_is_left_to_the_required_rule(): void
    {
        ns()->option->set( 'ns_password_min_length', 12 );

        $this->assertFalse(
            Validator::make( [ 'password' => '' ], [ 'password' => 'sometimes|password_policy' ] )->fails()
        );

        $this->assertTrue(
            Validator::make( [ 'password' => '' ], [ 'password' => 'required|password_policy' ] )->fails()
        );
    }

    /**
     * The door that matters: creating a member of staff.
     */
    public function test_a_password_below_the_policy_is_refused_when_creating_a_user(): void
    {
        $this->attemptAuthenticate();

        ns()->option->set( 'ns_password_min_length', 12 );
        ns()->option->set( 'ns_password_require_number', 'yes' );
        ns()->option->set( 'ns_password_require_symbol', 'yes' );

        $weak = 'abcdefgh';

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.users', [
                'username' => 'weak' . Str::random( 6 ),
                'general' => [
                    'email' => Str::random( 8 ) . '@rgbf.test',
                    'password' => $weak,
                    'password_confirm' => $weak,
                    'roles' => [ Role::where( 'namespace', Role::STORECASHIER )->first()->id ],
                    'active' => 1,
                ],
            ] );

        $response->assertStatus( 422 );
    }

    public function test_a_password_meeting_the_policy_is_accepted_when_creating_a_user(): void
    {
        $this->attemptAuthenticate();

        ns()->option->set( 'ns_password_min_length', 12 );
        ns()->option->set( 'ns_password_require_number', 'yes' );
        ns()->option->set( 'ns_password_require_symbol', 'yes' );
        ns()->option->set( 'ns_customers_default_group', '' );

        $strong = 'Rgbf-till-2569!';

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.users', [
                'username' => 'strong' . Str::random( 6 ),
                'general' => [
                    'email' => Str::random( 8 ) . '@rgbf.test',
                    'password' => $strong,
                    'password_confirm' => $strong,
                    'roles' => [ Role::where( 'namespace', Role::STORECASHIER )->first()->id ],
                    'active' => 1,
                ],
            ] );

        $response->assertStatus( 200 );
        $response->assertJsonPath( 'status', 'success' );
    }

    /**
     * The sign-in box holds an existing password, so a length rule there refuses the attempt before
     * it is even checked. The day the shop raises its minimum, every member of staff whose password
     * is shorter than the new figure would be unable to sign in — including to go and change it.
     */
    public function test_the_sign_in_field_is_not_held_to_the_policy(): void
    {
        $fields = collect( ( new AuthLoginFields )->get() );

        $password = $fields->first( fn ( $field ): bool => ( $field[ 'name' ] ?? null ) === 'password' );

        $this->assertNotNull( $password, 'the sign-in form no longer has a password field' );

        $this->assertStringNotContainsString(
            'password_policy',
            (string) $password[ 'validation' ],
            'The sign-in box is held to the password policy, which locks out everybody whose existing password is below a newly raised minimum.'
        );

        $this->assertStringNotContainsString(
            'min:',
            (string) $password[ 'validation' ],
            'The sign-in box carries a minimum length, which refuses an existing shorter password before it is checked.'
        );
    }

    public function test_the_settings_page_can_be_opened_and_saved(): void
    {
        $this->attemptAuthenticate();

        $form = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', 'api/settings/password' );

        $form->assertStatus( 200 );

        $names = collect( $form->json( 'tabs.requirements.fields' ) )->pluck( 'name' );

        foreach ( $this->options as $option ) {
            $this->assertTrue(
                $names->contains( $option ),
                'The settings page does not expose ' . $option . ', so the shop cannot configure it.'
            );
        }

        $save = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/settings/password', [
                /**
                 * Nested under the tab identifier, which is how the settings screens submit.
                 */
                'requirements' => [
                    'ns_password_min_length' => 9,
                    'ns_password_require_uppercase' => 'yes',
                    'ns_password_require_lowercase' => 'no',
                    'ns_password_require_number' => 'yes',
                    'ns_password_require_symbol' => 'no',
                    'ns_password_block_common' => 'yes',
                ],
            ] );

        $this->assertSame(
            200,
            $save->getStatusCode(),
            'The settings page could not be saved: ' . $save->getContent()
        );

        $this->assertSame( 9, $this->policy()->minLength(), 'the saved minimum is not what the policy reads back' );
        $this->assertTrue( $this->policy()->requiresUppercase() );
        $this->assertFalse( $this->policy()->requiresLowercase() );
        $this->assertTrue( $this->policy()->blocksCommonPasswords() );
    }

    /**
     * The page itself, requested through the web guard the way a browser does. Done in its own test
     * because switching the acting guard affects every request that follows it.
     */
    public function test_the_settings_page_is_reachable_in_a_browser(): void
    {
        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/settings/password' )
            ->assertStatus( 200 )
            ->assertSee( 'นโยบายรหัสผ่าน', false );
    }
}

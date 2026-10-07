<?php

namespace Tests\Feature;

use App\Crud\UserCrud;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Covers the separation of the staff register from the customer register.
 *
 * Both live in nexopos_users, and the customer screens narrow themselves to accounts holding the store
 * customer role. The staff screen had no matching filter and no separate form, so it listed customers
 * among the employees and asked, when creating a member of staff, for their loyalty group, birth date,
 * credit ceiling, postal box, billing address and shipping address.
 *
 * Worse than untidy: the form's group field defaulted through ns_customers_default_group, and on a shop
 * that never set one, saving threw "The assigned default customer group doesn't exist or is not
 * defined" — so the staff form could not create a member of staff at all.
 */
class StaffAndCustomerSeparationTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication, WithFaker;

    /**
     * The fields a staff form has no business carrying.
     *
     * @return array<int, string>
     */
    private function customerOnlyFields(): array
    {
        return [ 'group_id', 'birth_date', 'credit_limit_amount', 'pobox' ];
    }

    /**
     * Every field name the staff form declares, across all of its tabs.
     *
     * @return array<int, string>
     */
    private function staffFormFieldNames(): array
    {
        $form = ( new UserCrud )->getForm();
        $names = [];

        foreach ( $form[ 'tabs' ] ?? [] as $tab ) {
            foreach ( $tab[ 'fields' ] ?? [] as $field ) {
                $names[] = $field[ 'name' ] ?? '';
            }
        }

        return array_values( array_filter( $names ) );
    }

    public function test_the_staff_form_does_not_ask_for_customer_details(): void
    {
        $this->attemptAuthenticate();

        $names = $this->staffFormFieldNames();

        $found = array_values( array_intersect( $this->customerOnlyFields(), $names ) );

        $this->assertSame(
            [],
            $found,
            "The staff form still asks for customer details:\n" . implode( "\n", $found )
        );
    }

    public function test_the_staff_form_has_no_customer_address_tabs(): void
    {
        $this->attemptAuthenticate();

        $tabs = array_keys( ( new UserCrud )->getForm()[ 'tabs' ] ?? [] );

        $this->assertNotContains( 'billing', $tabs, 'A billing address belongs to a customer, not to a member of staff.' );
        $this->assertNotContains( 'shipping', $tabs, 'A shipping address belongs to a customer, not to a member of staff.' );
        $this->assertContains( 'general', $tabs, 'The staff form still needs its general tab.' );
    }

    public function test_the_staff_form_still_asks_for_what_a_staff_record_needs(): void
    {
        $this->attemptAuthenticate();

        $names = $this->staffFormFieldNames();

        foreach ( [ 'email', 'first_name', 'last_name', 'password', 'active', 'roles', 'branch_id', 'assigned_register_id' ] as $expected ) {
            $this->assertContains(
                $expected,
                $names,
                sprintf( 'The staff form has to keep asking for %s.', $expected )
            );
        }
    }

    public function test_a_member_of_staff_can_be_created_without_a_default_customer_group(): void
    {
        $this->attemptAuthenticate();

        /**
         * The state that used to break it: no default customer group configured anywhere.
         */
        ns()->option->set( 'ns_customers_default_group', '' );

        $password = Str::random( 12 );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.users', [
                'username' => 'staff' . Str::random( 6 ),
                'general' => [
                    'email' => $this->faker->unique()->safeEmail(),
                    'password' => $password,
                    'password_confirm' => $password,
                    'roles' => [ Role::where( 'namespace', Role::STORECASHIER )->first()->id ],
                    'active' => 1,
                ],
            ] );

        $response->assertStatus( 200 );
        $response->assertJsonPath( 'status', 'success' );
    }

    /**
     * The branch choices are read from the branches table rather than written into the form, so a branch
     * opened later has to appear on its own. Pinned because the whole point of the staff form carrying a
     * branch is that the shop can staff a new branch without anyone touching the code.
     */
    public function test_a_branch_opened_later_appears_in_the_staff_form(): void
    {
        $this->attemptAuthenticate();

        $branch = new Branch;
        $branch->name = 'สาขาทดสอบ ' . Str::random( 5 );
        $branch->code = 'T' . Str::upper( Str::random( 6 ) );
        $branch->is_active = true;
        $branch->is_main = false;
        $branch->author_id = User::first()->id;
        $branch->save();

        $options = $this->branchOptions();

        $this->assertContains(
            (string) $branch->id,
            array_map( 'strval', $options ),
            'A branch created after the form was written still has to be offered.'
        );

        /**
         * Every active branch, not merely the new one: a form offering some of them would leave staff
         * unassignable to the rest.
         */
        foreach ( Branch::active()->pluck( 'id' ) as $activeId ) {
            $this->assertContains(
                (string) $activeId,
                array_map( 'strval', $options ),
                sprintf( 'Branch %s is active but cannot be chosen on the staff form.', $activeId )
            );
        }

        $this->assertContains( '', array_map( 'strval', $options ), 'The form has to keep offering "every branch" for the owner and administrators.' );
    }

    public function test_a_member_of_staff_can_be_assigned_to_a_branch_opened_later(): void
    {
        $this->attemptAuthenticate();

        $branch = new Branch;
        $branch->name = 'สาขาทดสอบ ' . Str::random( 5 );
        $branch->code = 'T' . Str::upper( Str::random( 6 ) );
        $branch->is_active = true;
        $branch->is_main = false;
        $branch->author_id = User::first()->id;
        $branch->save();

        $password = Str::random( 12 );
        $username = 'branchstaff' . Str::random( 6 );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.users', [
                'username' => $username,
                'general' => [
                    'email' => $this->faker->unique()->safeEmail(),
                    'password' => $password,
                    'password_confirm' => $password,
                    'roles' => [ Role::where( 'namespace', Role::STORECASHIER )->first()->id ],
                    'active' => 1,
                    'branch_id' => $branch->id,
                ],
            ] );

        $response->assertStatus( 200 );

        $saved = User::where( 'username', $username )->first();

        $this->assertInstanceOf( User::class, $saved );
        $this->assertSame(
            (int) $branch->id,
            (int) $saved->branch_id,
            'A cashier has to be assignable to a branch that did not exist when the form was written.'
        );

        $this->assertContains(
            $saved->id,
            $this->staffListIds(),
            'Staff of a newly opened branch have to appear on the staff list like anyone else.'
        );
    }

    /**
     * The values the branch field offers on the staff form.
     *
     * @return array<int, mixed>
     */
    private function branchOptions(): array
    {
        foreach ( ( new UserCrud )->getForm()[ 'tabs' ][ 'general' ][ 'fields' ] ?? [] as $field ) {
            if ( ( $field[ 'name' ] ?? '' ) === 'branch_id' ) {
                return collect( $field[ 'options' ] ?? [] )->pluck( 'value' )->all();
            }
        }

        return [];
    }

    public function test_the_staff_list_leaves_out_accounts_that_are_only_customers(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->givenAccountWithRoles( [ Role::STORECUSTOMER ] );
        $cashier = $this->givenAccountWithRoles( [ Role::STORECASHIER ] );

        $listed = $this->staffListIds();

        $this->assertNotContains(
            $customer->id,
            $listed,
            'A walk in customer must not appear on the staff list.'
        );

        $this->assertContains(
            $cashier->id,
            $listed,
            'A cashier has to appear on the staff list.'
        );
    }

    public function test_someone_who_is_both_staff_and_a_customer_stays_on_the_staff_list(): void
    {
        $this->attemptAuthenticate();

        /**
         * This is the shop owner's own arrangement: an administrator who also carries the customer role.
         * A filter written as "hide anyone holding the customer role" would hide the owner.
         */
        $owner = $this->givenAccountWithRoles( [ Role::ADMIN, Role::STORECUSTOMER ] );

        $this->assertContains(
            $owner->id,
            $this->staffListIds(),
            'An account holding a staff role as well as the customer role has to remain on the staff list.'
        );
    }

    public function test_an_account_with_no_role_at_all_remains_visible(): void
    {
        $this->attemptAuthenticate();

        $orphan = $this->givenAccountWithRoles( [] );

        $this->assertContains(
            $orphan->id,
            $this->staffListIds(),
            'A record with no role is an unfinished staff account and has to stay findable.'
        );
    }

    public function test_the_staff_list_offers_no_customer_actions(): void
    {
        $this->attemptAuthenticate();

        $response = $this->json( 'GET', 'api/crud/ns.users?per_page=5' );

        $response->assertStatus( 200 );

        $entries = $response->json( 'data' ) ?? [];

        $this->assertNotEmpty( $entries, 'The staff list returned nothing to inspect.' );

        foreach ( $entries as $entry ) {
            $identifiers = array_keys( $entry[ '$actions' ] ?? [] );

            foreach ( [ 'customers_orders', 'customers_rewards', 'customers_coupons', 'customers_history' ] as $customerAction ) {
                $this->assertNotContains(
                    $customerAction,
                    $identifiers,
                    sprintf( 'The staff list still offers the customer action "%s", which points at a route that answers nothing.', $customerAction )
                );
            }
        }
    }

    /**
     * The ids the staff list would show.
     *
     * @return array<int, int>
     */
    private function staffListIds(): array
    {
        $response = $this->json( 'GET', 'api/crud/ns.users?per_page=500' );

        $response->assertStatus( 200 );

        return collect( $response->json( 'data' ) ?? [] )
            ->map( fn( $entry ) => (int) ( $entry[ 'id' ] ?? 0 ) )
            ->all();
    }

    /**
     * Creates an account carrying exactly the roles named.
     *
     * @param array<int, string> $namespaces
     */
    private function givenAccountWithRoles( array $namespaces ): User
    {
        $user = new User;
        $user->username = 'sep' . Str::random( 8 );
        $user->email = $this->faker->unique()->safeEmail();
        $user->password = Hash::make( Str::random( 12 ) );
        $user->active = true;
        $user->author_id = User::first()->id;
        $user->save();

        /**
         * Written straight into the pivot: User::roles() is a has-many-through, so it has no attach().
         */
        foreach ( $namespaces as $namespace ) {
            $role = Role::where( 'namespace', $namespace )->first();

            if ( $role instanceof Role ) {
                DB::table( 'nexopos_users_roles_relations' )->insert( [
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                ] );
            }
        }

        return $user;
    }
}

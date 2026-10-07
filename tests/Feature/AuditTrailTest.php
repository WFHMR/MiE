<?php

namespace Tests\Feature;

use App\Crud\CustomerGroupCrud;
use App\Events\AfterSuccessfulLoginEvent;
use App\Events\CrudAfterDeleteEvent;
use App\Events\CrudAfterPostEvent;
use App\Events\CrudAfterPutEvent;
use App\Listeners\AuditTrailListener;
use App\Models\AuditTrail;
use App\Models\CustomerGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * ตรวจสอบการใช้งานย้อนหลัง — the record of who did what.
 *
 * Nothing kept this before. author_id says who last touched a row but not what they changed it
 * from, and says nothing at all about a row that was deleted — which is the one case somebody
 * always needs to look into. The only audit in the installation covered orders alone.
 *
 * What is pinned here: creating, updating and deleting through any CRUD screen writes a row, which
 * is what proves the listener is actually wired — the events were already being fired and nothing
 * was listening to them; a password submitted on a form never reaches the audit table; and the page
 * refuses every attempt to write to it, because an audit trail the audited can edit is not one.
 */
class AuditTrailTest extends TestCase
{
    use WithAuthentication;

    /**
     * @var array<int, int>
     */
    private array $createdGroupIds = [];

    protected function tearDown(): void
    {
        foreach ( $this->createdGroupIds as $id ) {
            CustomerGroup::where( 'id', $id )->delete();
        }

        $this->createdGroupIds = [];

        parent::tearDown();
    }

    public function test_creating_a_record_through_a_crud_screen_is_recorded(): void
    {
        $this->attemptAuthenticate();

        $name = 'กลุ่มทดสอบบันทึก ' . Str::random( 6 );

        $id = $this->createCustomerGroup( $name );

        $entry = AuditTrail::where( 'resource', 'ns.customers-groups' )
            ->where( 'action', AuditTrail::ACTION_CREATED )
            ->where( 'record_id', $id )
            ->first();

        $this->assertNotNull(
            $entry,
            'Nothing was recorded for a create. CrudAfterPostEvent is fired by CrudService for every'
                . ' resource, so a missing row means the listener is not wired at all and the whole'
                . ' audit trail is empty.'
        );

        $this->assertSame( $name, $entry->record_label, 'the row does not name the record, so the list reads as an id' );
        $this->assertNotEmpty( $entry->user_name, 'the row does not name who did it' );
        $this->assertNotNull( $entry->created_at );
    }

    /**
     * One action, one line.
     *
     * This listener is registered twice on a running installation — once explicitly in
     * EventServiceProvider and once by Laravel's automatic discovery, which picks up any public
     * method whose first argument is an event class. Both registrations fire, and the first live
     * check showed two identical rows for a single create. A log that doubles every line cannot be
     * read: there is no way to tell a repeated action from a repeated record.
     *
     * Dispatched directly rather than through the endpoint so the count is exactly one event.
     */
    public function test_one_action_is_recorded_once_even_though_the_listener_is_registered_twice(): void
    {
        $this->attemptAuthenticate();

        /**
         * The second registration is added by hand because that is what the running installation
         * has and the test environment does not: on the server the dispatcher holds both
         * "AuditTrailListener::handleCreated" (declared in EventServiceProvider) and
         * "AuditTrailListener@handleCreated" (found by automatic discovery). Without reproducing
         * that here, this test would pass on a dispatcher that only ever calls the listener once
         * and would say nothing about the case that actually went wrong.
         */
        Event::listen( CrudAfterPostEvent::class, [ AuditTrailListener::class, 'handleCreated' ] );

        $name = 'กลุ่มทดสอบนับแถว ' . Str::random( 6 );
        $group = new CustomerGroup;
        $group->name = $name;
        $group->author_id = User::first()->id;
        $group->save();

        $this->createdGroupIds[] = $group->id;

        CrudAfterPostEvent::dispatch( new CustomerGroupCrud, [ 'name' => $name ], $group );

        $this->assertSame(
            1,
            AuditTrail::where( 'resource', 'ns.customers-groups' )
                ->where( 'action', AuditTrail::ACTION_CREATED )
                ->where( 'record_id', $group->id )
                ->count(),
            'One action was written twice. Every line of the log doubles, and a reader cannot tell'
                . ' a repeated action from a repeated record.'
        );

        /**
         * A genuinely separate action still has to be recorded — the guard must suppress a
         * duplicate delivery of one event, not a second event.
         */
        CrudAfterPostEvent::dispatch( new CustomerGroupCrud, [ 'name' => $name ], $group );

        $this->assertSame(
            2,
            AuditTrail::where( 'resource', 'ns.customers-groups' )
                ->where( 'action', AuditTrail::ACTION_CREATED )
                ->where( 'record_id', $group->id )
                ->count(),
            'A second, separate action was swallowed, so the guard is hiding real work.'
        );
    }

    /**
     * Every action in one request, not merely the first.
     *
     * The guard above suppresses a second delivery of one event. It must not suppress a second
     * event, and the shape that proves it is the one a bulk action has: CrudController dispatches
     * a fresh CrudAfterDeleteEvent per row inside a loop, and each one is released before the next
     * is built. Clearing twenty products has to leave twenty lines; a trail that records the first
     * deletion of a batch and silently drops the rest is worse than none, because it reads as a
     * complete account of a single deletion.
     *
     * This is what the first live check actually showed: a create was recorded and the update,
     * delete and sign-in that followed it in the same process were not.
     */
    public function test_every_action_in_one_request_is_recorded_not_just_the_first(): void
    {
        $this->attemptAuthenticate();

        $marker = Str::random( 8 );
        $batch = 5;

        foreach ( range( 1, $batch ) as $index ) {
            /**
             * Built and dispatched exactly as CrudController::deleteEntry does it, one per
             * iteration, with no reference kept to the previous event.
             */
            CrudAfterDeleteEvent::dispatch( new CustomerGroupCrud, (object) [
                'id' => 900000 + $index,
                'name' => 'กลุ่มลบเป็นชุด ' . $marker . ' ' . $index,
            ] );
        }

        $recorded = AuditTrail::where( 'resource', 'ns.customers-groups' )
            ->where( 'action', AuditTrail::ACTION_DELETED )
            ->where( 'record_label', 'like', '%' . $marker . '%' )
            ->count();

        $this->assertSame(
            $batch,
            $recorded,
            'A batch of ' . $batch . ' deletions left ' . $recorded . ' lines. Each action is a'
                . ' separate event, so each has to be recorded; dropping the rest of a batch hides'
                . ' exactly the work an audit trail exists to show.'
        );

        AuditTrail::where( 'record_label', 'like', '%' . $marker . '%' )->delete();
    }

    /**
     * The same failure with four different kinds of action rather than one repeated, because that
     * is how it appeared on the running installation.
     */
    public function test_different_kinds_of_action_in_one_process_are_all_recorded(): void
    {
        $this->attemptAuthenticate();

        $name = 'กลุ่มทดสอบหลายเหตุการณ์ ' . Str::random( 6 );
        $group = new CustomerGroup;
        $group->name = $name;
        $group->author_id = User::first()->id;
        $group->save();

        $this->createdGroupIds[] = $group->id;

        $user = User::first();
        $before = AuditTrail::count();

        CrudAfterPostEvent::dispatch( new CustomerGroupCrud, [ 'name' => $name ], $group );
        CrudAfterPutEvent::dispatch( new CustomerGroupCrud, [ 'name' => $name ], $group );
        CrudAfterDeleteEvent::dispatch( new CustomerGroupCrud, (object) $group->toArray() );
        AfterSuccessfulLoginEvent::dispatch( $user );

        $written = AuditTrail::count() - $before;

        $this->assertSame(
            4,
            $written,
            'Four distinct actions produced ' . $written . ' lines.'
        );

        foreach ( [ AuditTrail::ACTION_CREATED, AuditTrail::ACTION_UPDATED, AuditTrail::ACTION_DELETED, AuditTrail::ACTION_SIGNED_IN ] as $action ) {
            $this->assertGreaterThan(
                0,
                AuditTrail::where( 'action', $action )->where( 'id', '>', $before )->count(),
                'nothing was recorded for ' . $action
            );
        }
    }

    public function test_updating_and_deleting_are_recorded_and_the_record_name_survives_the_delete(): void
    {
        $this->attemptAuthenticate();

        $name = 'กลุ่มทดสอบแก้ไข ' . Str::random( 6 );
        $id = $this->createCustomerGroup( $name );

        $renamed = $name . ' แก้แล้ว';

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'PUT', 'api/crud/ns.customers-groups/' . $id, [
                'name' => $renamed,
                'general' => [
                    'reward_system_id' => '',
                    'minimal_credit_payment' => 0,
                    'description' => 'updated by the test suite',
                ],
            ] )
            ->assertStatus( 200 );

        $this->assertNotNull(
            AuditTrail::where( 'resource', 'ns.customers-groups' )
                ->where( 'action', AuditTrail::ACTION_UPDATED )
                ->where( 'record_id', $id )
                ->first(),
            'An update was not recorded.'
        );

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'DELETE', 'api/crud/ns.customers-groups/' . $id )
            ->assertStatus( 200 );

        $deleted = AuditTrail::where( 'resource', 'ns.customers-groups' )
            ->where( 'action', AuditTrail::ACTION_DELETED )
            ->where( 'record_id', $id )
            ->first();

        $this->assertNotNull(
            $deleted,
            'A deletion was not recorded. This is the case nothing in the installation could answer'
                . ' before: once the row is gone, author_id is gone with it.'
        );

        $this->assertSame(
            $renamed,
            $deleted->record_label,
            'The deleted record is not named, so the audit says something was deleted without saying what.'
        );
    }

    /**
     * The audit table is read on screen by whoever holds the permission. A password copied into it
     * would make it the easiest place in the installation to read one from.
     */
    public function test_a_submitted_password_is_never_stored(): void
    {
        $this->attemptAuthenticate();

        ns()->option->set( 'ns_customers_default_group', '' );

        $password = Str::password( 32 );

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.users', [
                'username' => 'audit' . Str::random( 6 ),
                'general' => [
                    'email' => Str::random( 8 ) . '@rgbf.test',
                    'password' => $password,
                    'password_confirm' => $password,
                    'roles' => [ Role::where( 'namespace', Role::STORECASHIER )->first()->id ],
                    'active' => 1,
                ],
            ] )
            ->assertStatus( 200 );

        $entry = AuditTrail::where( 'resource', 'ns.users' )
            ->where( 'action', AuditTrail::ACTION_CREATED )
            ->orderBy( 'id', 'desc' )
            ->first();

        $this->assertNotNull( $entry, 'creating a user was not recorded' );

        $this->assertStringNotContainsString(
            $password,
            (string) $entry->changes,
            'The submitted password was written into the audit trail in clear text.'
        );

        $this->assertStringContainsString(
            AuditTrailService::REDACTED_PLACEHOLDER,
            (string) $entry->changes,
            'The password field should be present but masked, so the row still shows that a password was set.'
        );
    }

    public function test_the_redaction_covers_every_field_that_reads_like_a_secret(): void
    {
        $redacted = app()->make( AuditTrailService::class )->redact( [
            'name' => 'keep me',
            'password' => 'secret-value',
            'password_confirm' => 'secret-value',
            'api_key' => 'secret-value',
            'stripe_secret' => 'secret-value',
            'nested' => [
                'access_token' => 'secret-value',
                'quantity' => 4,
            ],
        ] );

        $this->assertSame( 'keep me', $redacted[ 'name' ] );
        $this->assertSame( 4, $redacted[ 'nested' ][ 'quantity' ] );

        foreach ( [ 'password', 'password_confirm', 'api_key', 'stripe_secret' ] as $key ) {
            $this->assertSame( AuditTrailService::REDACTED_PLACEHOLDER, $redacted[ $key ], $key . ' was not masked' );
        }

        $this->assertSame( AuditTrailService::REDACTED_PLACEHOLDER, $redacted[ 'nested' ][ 'access_token' ], 'a nested secret was not masked' );
    }

    public function test_a_long_payload_is_capped(): void
    {
        $encoded = app()->make( AuditTrailService::class )->encodeChanges( [
            'note' => Str::random( AuditTrailService::MAX_CHANGES_LENGTH * 2 ),
        ] );

        $this->assertLessThanOrEqual(
            AuditTrailService::MAX_CHANGES_LENGTH + 20,
            mb_strlen( $encoded ),
            'A single save could write an unbounded amount into the audit table.'
        );
    }

    public function test_the_page_can_be_opened(): void
    {
        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/audit-trail' )
            ->assertStatus( 200 );
    }

    /**
     * Read only has to mean refused, not merely hidden. The buttons are not rendered, but the API
     * is reachable by anybody who can read the page.
     */
    public function test_the_audit_trail_cannot_be_written_to_or_deleted(): void
    {
        $this->attemptAuthenticate();

        $entry = app()->make( AuditTrailService::class )->record(
            action: AuditTrail::ACTION_CREATED,
            resource: 'ns.test-only',
            resourceLabel: 'ทดสอบ',
            recordLabel: 'ทดสอบ',
        );

        $this->assertNotNull( $entry, 'the service could not write a row to test against' );

        $this->assertNotEquals(
            200,
            $this->withSession( $this->app[ 'session' ]->all() )
                ->json( 'POST', 'api/crud/ns.audit-trail', [ 'action' => 'created' ] )
                ->getStatusCode(),
            'A row can be added to the audit trail by hand, so the trail can be fabricated.'
        );

        $this->assertNotEquals(
            200,
            $this->withSession( $this->app[ 'session' ]->all() )
                ->json( 'DELETE', 'api/crud/ns.audit-trail/' . $entry->id )
                ->getStatusCode(),
            'A row can be deleted from the audit trail, so somebody can erase what they did.'
        );

        $this->assertNotNull(
            AuditTrail::find( $entry->id ),
            'The row was deleted despite the request being refused.'
        );

        AuditTrail::where( 'resource', 'ns.test-only' )->delete();
    }

    private function createCustomerGroup( string $name ): int
    {
        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.customers-groups', [
                'name' => $name,
                'general' => [
                    'reward_system_id' => '',
                    'minimal_credit_payment' => 0,
                    'description' => 'created by the test suite',
                ],
            ] );

        $response->assertStatus( 200 );

        $id = (int) $response->json( 'data.entry.id' );

        $this->assertGreaterThan( 0, $id, 'the customer group was not created, so there is nothing to audit' );

        $this->createdGroupIds[] = $id;

        return $id;
    }
}

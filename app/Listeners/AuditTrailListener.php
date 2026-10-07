<?php

namespace App\Listeners;

use App\Events\AfterSuccessfulLoginEvent;
use App\Events\CrudAfterDeleteEvent;
use App\Events\CrudAfterPostEvent;
use App\Events\CrudAfterPutEvent;
use App\Events\SettingsSavedEvent;
use App\Models\AuditTrail;
use App\Services\AuditTrailService;
use Throwable;
use WeakMap;

/**
 * Turns the events the CRUD layer already fires into audit rows.
 *
 * Three events cover every create, update and delete across every screen in app/Crud — orders,
 * products, users, roles, suppliers, tills, taxes, settings pages built on CRUD — because they are
 * dispatched by CrudService and CrudController themselves rather than by each resource. Nothing was
 * listening to them before this.
 *
 * Registered explicitly in EventServiceProvider::boot(). Automatic listener discovery is switched
 * on in this application but discoverEventsWithin() is overridden to scan the modules' Listeners
 * folders only, so a class dropped in app/Listeners is never found. Two of the listeners already
 * here work because the events they handle are dispatched through model $dispatchesEvents, which
 * resolves the class name directly.
 */
class AuditTrailListener
{
    /**
     * The event objects already written.
     *
     * This listener ends up registered twice on a running installation: once explicitly in
     * EventServiceProvider, and once by Laravel's automatic discovery, which finds any public
     * method here whose first argument is an event class. The two registrations are visible side by
     * side in the dispatcher as "AuditTrailListener::handleCreated" and
     * "AuditTrailListener@handleCreated", and the result was two identical audit rows for every
     * create, update and delete — a log that doubles every line is worse than useless, because the
     * reader cannot tell a repeated action from a repeated record.
     *
     * Discovery is not the same in every environment: the test suite registers only the explicit
     * one, the server registers both. So neither registration is removed; the writing is made
     * idempotent instead, which holds whatever the dispatcher ends up containing.
     *
     * A WeakMap and not an array of spl_object_id. Those ids are unique only among objects that are
     * alive at the same time, and PHP hands a freed id straight back to the next object. An event
     * is released as soon as it has been dispatched, so the next event in the same request was
     * issued the previous one's id and read as already written. On the running installation that
     * recorded a create and silently dropped the update, the delete and the sign-in that followed
     * it; a bulk delete, which dispatches one event per row in a loop, left a single line for the
     * whole batch.
     *
     * A WeakMap is keyed by the object itself and drops its entry when that object is collected, so
     * a reused id cannot collide and nothing has to be pruned by hand.
     *
     * @var \WeakMap<object, true>|null
     */
    private static ?WeakMap $handled = null;

    public function __construct( private AuditTrailService $auditTrail ) {}

    public function handleCreated( CrudAfterPostEvent $event ): void
    {
        if ( $this->alreadyHandled( $event ) ) {
            return;
        }

        $this->auditTrail->record(
            action: AuditTrail::ACTION_CREATED,
            resource: $this->resourceOf( $event->resource ),
            resourceLabel: $this->labelOf( $event->resource ),
            recordId: $this->idOf( $event->entry ),
            recordLabel: $this->auditTrail->describeRecord( $event->entry ),
            changes: $event->inputs,
        );
    }

    public function handleUpdated( CrudAfterPutEvent $event ): void
    {
        if ( $this->alreadyHandled( $event ) ) {
            return;
        }

        $this->auditTrail->record(
            action: AuditTrail::ACTION_UPDATED,
            resource: $this->resourceOf( $event->resource ),
            resourceLabel: $this->labelOf( $event->resource ),
            recordId: $this->idOf( $event->entry ),
            recordLabel: $this->auditTrail->describeRecord( $event->entry ),
            changes: $event->inputs,
        );
    }

    public function handleDeleted( CrudAfterDeleteEvent $event ): void
    {
        /**
         * The deleted row's own attributes are what is recorded here, because after this there is
         * nothing left to look the record up in. This is the case author_id could never answer.
         */
        if ( $this->alreadyHandled( $event ) ) {
            return;
        }

        $attributes = (array) $event->model;

        $this->auditTrail->record(
            action: AuditTrail::ACTION_DELETED,
            resource: $this->resourceOf( $event->resource ),
            resourceLabel: $this->labelOf( $event->resource ),
            recordId: isset( $attributes[ 'id' ] ) ? (int) $attributes[ 'id' ] : null,
            recordLabel: $this->auditTrail->describeRecord( $attributes ),
            changes: $attributes,
        );
    }

    /**
     * Saving a settings page is not a CRUD write, so none of the three events above sees it — yet
     * a changed tax rate or a changed password policy is exactly the kind of change somebody later
     * needs to attribute.
     */
    public function handleSettingsSaved( SettingsSavedEvent $event ): void
    {
        if ( $this->alreadyHandled( $event ) ) {
            return;
        }

        $identifier = is_object( $event->settingsClass ) && method_exists( $event->settingsClass, 'getIdentifier' )
            ? (string) $event->settingsClass->getIdentifier()
            : class_basename( $event->settingsClass );

        $this->auditTrail->record(
            action: AuditTrail::ACTION_SETTINGS_SAVED,
            resource: 'settings',
            resourceLabel: __( 'ตั้งค่า' ),
            recordLabel: $identifier,
            changes: is_array( $event->data ) ? $event->data : [],
        );
    }

    public function handleSignedIn( AfterSuccessfulLoginEvent $event ): void
    {
        if ( $this->alreadyHandled( $event ) ) {
            return;
        }

        /**
         * No payload. Who signed in and from which address is the whole fact, and the event
         * carries the user object, whose attributes include the password hash.
         */
        $this->auditTrail->record(
            action: AuditTrail::ACTION_SIGNED_IN,
            resource: 'auth',
            resourceLabel: __( 'เข้าสู่ระบบ' ),
            recordId: $event->user->id,
            recordLabel: (string) $event->user->username,
        );
    }

    /**
     * The CRUD identifier, which is what makes a row filterable. Falls back to the class name so a
     * resource that somehow has no identifier still produces a readable row rather than none.
     */
    private function resourceOf( mixed $resource ): string
    {
        try {
            if ( is_object( $resource ) && method_exists( $resource, 'getIdentifier' ) ) {
                $identifier = $resource->getIdentifier();

                if ( ! empty( $identifier ) ) {
                    return (string) $identifier;
                }
            }
        } catch ( Throwable ) {
            // falls through to the class name below
        }

        return is_object( $resource ) ? class_basename( $resource ) : (string) $resource;
    }

    /**
     * The screen's own Thai title, taken from the resource's labels, so the audit list names the
     * screen the way the menu does instead of showing "ns.products".
     */
    private function labelOf( mixed $resource ): ?string
    {
        try {
            if ( is_object( $resource ) && method_exists( $resource, 'getLabels' ) ) {
                $labels = $resource->getLabels();

                if ( is_array( $labels ) && ! empty( $labels[ 'list_title' ] ) ) {
                    return (string) $labels[ 'list_title' ];
                }
            }
        } catch ( Throwable ) {
            // a resource whose labels cannot be built is not worth failing an audit row over
        }

        return null;
    }

    /**
     * True the second time the same event object reaches this listener.
     */
    private function alreadyHandled( object $event ): bool
    {
        self::$handled ??= new WeakMap;

        if ( isset( self::$handled[ $event ] ) ) {
            return true;
        }

        self::$handled[ $event ] = true;

        return false;
    }

    private function idOf( mixed $entry ): ?int
    {
        if ( is_object( $entry ) && isset( $entry->id ) ) {
            return (int) $entry->id;
        }

        return null;
    }
}

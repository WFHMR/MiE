<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserRoleRelation;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithCrud;

/**
 * Deleting a role used to crash: the resource called detach() on the permissions
 * relation, which is a "has many through" and has no such method. Nothing could be
 * deleted from the role list at all.
 */
class DeleteRoleTest extends TestCase
{
    use WithAuthentication, WithCrud;

    private function attemptCreateRole( string $namespace, int $permissions = 0 ): Role
    {
        /**
         * Keeps the test repeatable on a database that was not rebuilt.
         */
        Role::where( 'namespace', $namespace )->get()->each( function ( Role $role ) {
            UserRoleRelation::where( 'role_id', $role->id )->delete();
            RolePermission::where( 'role_id', $role->id )->delete();
            $role->delete();
        } );

        $role = new Role;
        $role->name = $namespace;
        $role->namespace = $namespace;
        $role->description = 'Created by the test suite.';
        $role->locked = false;
        $role->save();

        if ( $permissions > 0 ) {
            $role->addPermissions( Permission::take( $permissions )->get() );

            $this->assertEquals(
                $permissions,
                RolePermission::where( 'role_id', $role->id )->count(),
                'The permissions were not attached to the role.'
            );
        }

        return $role;
    }

    private function attemptAssignUser( Role $role ): UserRoleRelation
    {
        $relation = new UserRoleRelation;
        $relation->user_id = User::first()->id;
        $relation->role_id = $role->id;
        $relation->save();

        return $relation;
    }

    public function test_delete_role_removes_the_role_and_its_permissions()
    {
        $this->attemptAuthenticate();

        $role = $this->attemptCreateRole( 'test.deletable.role', 3 );

        $response = $this->submitRequest( 'ns.roles/' . $role->id, [], 'DELETE' );

        $response->assertStatus( 200 );

        $this->assertNull(
            Role::find( $role->id ),
            'The role has not been deleted.'
        );

        $this->assertEquals(
            0,
            RolePermission::where( 'role_id', $role->id )->count(),
            'The permissions of the deleted role are still stored.'
        );
    }

    public function test_delete_role_is_refused_while_users_are_assigned_to_it()
    {
        $this->attemptAuthenticate();

        $role = $this->attemptCreateRole( 'test.busy.role', 2 );
        $relation = $this->attemptAssignUser( $role );

        $response = $this->submitRequest( 'ns.roles/' . $role->id, [], 'DELETE' );

        /**
         * The deletion is refused, otherwise the users would keep pointing at a role
         * that no longer exists and would end up without any permission.
         */
        $response->assertStatus( 500 );

        $this->assertNotNull(
            Role::find( $role->id ),
            'A role holding users has been deleted.'
        );

        $this->assertEquals(
            2,
            RolePermission::where( 'role_id', $role->id )->count(),
            'The permissions were removed even though the deletion was refused.'
        );

        $relation->delete();
        RolePermission::where( 'role_id', $role->id )->delete();
        $role->delete();
    }

    public function test_bulk_delete_role_applies_the_same_rules_as_a_single_deletion()
    {
        $this->attemptAuthenticate();

        $deletable = $this->attemptCreateRole( 'test.bulk.deletable.role', 2 );
        $busy = $this->attemptCreateRole( 'test.bulk.busy.role', 2 );
        $relation = $this->attemptAssignUser( $busy );
        $locked = Role::where( 'locked', true )->first();

        $lockedPermissions = RolePermission::where( 'role_id', $locked->id )->count();

        $response = $this->submitRequest( 'ns.roles/bulk-actions', [
            'action' => 'delete_selected',
            'entries' => [ $deletable->id, $busy->id, $locked->id ],
        ] );

        $response->assertStatus( 200 );
        $response->assertJson( [
            'data' => [
                'success' => 1,
                'error' => 2,
            ],
        ] );

        $this->assertNull(
            Role::find( $deletable->id ),
            'The role that could be deleted is still stored.'
        );

        $this->assertEquals(
            0,
            RolePermission::where( 'role_id', $deletable->id )->count(),
            'The permissions of the role deleted in bulk are still stored.'
        );

        $this->assertNotNull(
            Role::find( $busy->id ),
            'A role holding users has been deleted in bulk.'
        );

        $this->assertNotNull(
            Role::find( $locked->id ),
            'A system role has been deleted in bulk.'
        );

        $this->assertEquals(
            $lockedPermissions,
            RolePermission::where( 'role_id', $locked->id )->count(),
            'The permissions of a system role were removed by a refused bulk deletion.'
        );

        $relation->delete();
        RolePermission::where( 'role_id', $busy->id )->delete();
        $busy->delete();
    }
}

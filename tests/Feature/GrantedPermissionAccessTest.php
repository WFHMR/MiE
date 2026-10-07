<?php

namespace Tests\Feature;

use App\Models\PermissionAccess;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * api/permissions/granted used to query App\Models\Permission while filtering on
 * "status" and "granter_id", two columns that only exist on
 * nexopos_permissions_access. MySQL rejected it with "Unknown column 'status'"
 * and the endpoint answered 500.
 *
 * SQLite never surfaced it: when a double quoted identifier matches no column it
 * is demoted to a string literal, so "status" = 'granted' silently evaluated to
 * false and the endpoint returned an empty page with a 200.
 *
 * Asserting on the returned rows rather than the status code is therefore the
 * only way to keep this covered on the sqlite fixture.
 */
class GrantedPermissionAccessTest extends TestCase
{
    use WithAuthentication;

    /**
     * @var array<int, int>
     */
    private array $createdIds = [];

    protected function tearDown(): void
    {
        if ( $this->createdIds !== [] ) {
            PermissionAccess::whereIn( 'id', $this->createdIds )->delete();
        }

        parent::tearDown();
    }

    private function createAccess( int $granterId, string $status, string $permission ): PermissionAccess
    {
        $access = new PermissionAccess;
        $access->requester_id = $granterId;
        $access->granter_id = $granterId;
        $access->status = $status;
        $access->permission = $permission;
        $access->save();

        $this->createdIds[] = $access->id;

        return $access;
    }

    public function test_it_returns_the_accesses_granted_by_the_logged_user(): void
    {
        $this->attemptAuthenticate();

        $granted = $this->createAccess( Auth::id(), PermissionAccess::GRANTED, 'nexopos.create.products' );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', 'api/permissions/granted' );

        $response->assertStatus( 200 );

        $ids = array_column( $response->json( 'data' ), 'id' );

        $this->assertContains(
            $granted->id,
            $ids,
            'The granted permission access was not returned, the endpoint is querying the wrong table.'
        );
    }

    public function test_it_ignores_accesses_that_are_not_granted(): void
    {
        $this->attemptAuthenticate();

        $pending = $this->createAccess( Auth::id(), PermissionAccess::PENDING, 'nexopos.update.products' );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', 'api/permissions/granted' );

        $response->assertStatus( 200 );

        $this->assertNotContains( $pending->id, array_column( $response->json( 'data' ), 'id' ) );
    }

    public function test_it_ignores_accesses_granted_by_somebody_else(): void
    {
        $this->attemptAuthenticate();

        $otherUser = User::where( 'id', '!=', Auth::id() )->firstOrFail();

        $foreign = $this->createAccess( $otherUser->id, PermissionAccess::GRANTED, 'nexopos.delete.products' );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', 'api/permissions/granted' );

        $response->assertStatus( 200 );

        $this->assertNotContains( $foreign->id, array_column( $response->json( 'data' ), 'id' ) );
    }
}

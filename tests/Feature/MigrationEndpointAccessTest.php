<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MigrationEndpointAccessTest extends TestCase
{
    public function test_guest_cannot_run_migrations(): void
    {
        $this->mock( UpdateService::class )->shouldNotReceive( 'executeMigrationFromFileName' );
        $this->postJson( '/api/update', ['file' => 'audit-fixture'] )->assertUnauthorized();
    }

    public function test_authenticated_user_without_update_permission_is_refused(): void
    {
        Sanctum::actingAs( User::firstOrFail() );
        Gate::define( 'update.core', fn () => false );
        $this->mock( UpdateService::class )->shouldNotReceive( 'executeMigrationFromFileName' );
        $this->postJson( '/api/update', ['file' => 'audit-fixture'] )->assertForbidden();
    }

    public function test_authorized_updater_reaches_migration_service(): void
    {
        Sanctum::actingAs( User::firstOrFail() );
        Gate::define( 'update.core', fn () => true );
        $this->mock( UpdateService::class )->shouldReceive( 'executeMigrationFromFileName' )->once()->with( 'audit-fixture' );
        $this->postJson( '/api/update', ['file' => 'audit-fixture'] )->assertOk();
    }
}

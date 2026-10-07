<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitApiValidationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_four_write_routes_validate_missing_fields(): void
    {
        Sanctum::actingAs( User::firstOrFail() );
        Gate::before( fn () => true );
        foreach ( ['/api/units', '/api/units-groups'] as $path ) {
            $this->postJson( $path, [] )->assertUnprocessable()->assertJsonValidationErrors( 'name' );
            $this->putJson( $path . '/1', [] )->assertUnprocessable()->assertJsonValidationErrors( 'name' );
        }
    }

    public function test_create_and_update_units_and_groups(): void
    {
        Sanctum::actingAs( User::firstOrFail() );
        Gate::before( fn () => true );
        $group = $this->postJson( '/api/units-groups', ['name' => 'API fixture'] )->assertOk()->json( 'data.group.id' );
        $this->putJson( '/api/units-groups/' . $group, ['name' => 'Updated fixture'] )->assertOk();
        $payload = ['name' => 'กล่อง', 'group_id' => $group, 'value' => 1, 'base_unit' => '1'];
        $unit = $this->postJson( '/api/units', $payload )->assertOk()->json( 'data.unit.id' );
        $saved = Unit::findOrFail( $unit );
        $this->assertNotEmpty( $saved->identifier );
        $this->assertTrue( $saved->base_unit );
        $this->putJson( '/api/units/' . $unit, array_merge( $payload, ['name' => 'ถุง', 'base_unit' => false] ) )->assertOk();
        $this->assertSame( $saved->identifier, $saved->fresh()->identifier );
        $this->assertFalse( $saved->fresh()->base_unit );
        $this->postJson( '/api/units', array_merge( $payload, ['identifier' => $saved->identifier] ) )->assertUnprocessable()->assertJsonValidationErrors( 'identifier' );
        $this->postJson( '/api/units', array_merge( $payload, ['group_id' => -1, 'value' => 0] ) )->assertUnprocessable()->assertJsonValidationErrors( ['group_id', 'value'] );
    }

    public function test_guest_and_unprivileged_users_cannot_write(): void
    {
        $this->postJson( '/api/units' )->assertUnauthorized();
        Sanctum::actingAs( User::firstOrFail() );
        Gate::before( fn () => false );
        $this->postJson( '/api/units' )->assertForbidden();
        $this->putJson( '/api/units/1' )->assertForbidden();
        $this->postJson( '/api/units-groups' )->assertForbidden();
        $this->putJson( '/api/units-groups/1' )->assertForbidden();
    }
}

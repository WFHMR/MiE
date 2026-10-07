<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitGroupDeletionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_empty_group_can_be_deleted_but_used_group_cannot(): void
    {
        Sanctum::actingAs( User::firstOrFail() );
        Gate::before( fn () => true );
        $empty = UnitGroup::factory()->create();
        $this->deleteJson( '/api/units-groups/' . $empty->id )->assertOk();
        $this->assertNull( $empty->fresh() );
        $group = UnitGroup::factory()->create();
        $unit = Unit::factory()->create( ['group_id' => $group->id] );
        $this->deleteJson( '/api/units-groups/' . $group->id )->assertForbidden();
        $this->assertNotNull( $group->fresh() );
        $this->assertNotNull( $unit->fresh() );
        $this->deleteJson( '/api/units-groups/999999999' )->assertNotFound();
    }

    public function test_delete_requires_permission(): void
    {
        $this->deleteJson( '/api/units-groups/1' )->assertUnauthorized();
        Sanctum::actingAs( User::firstOrFail() );
        Gate::before( fn () => false );
        $this->deleteJson( '/api/units-groups/1' )->assertForbidden();
    }
}

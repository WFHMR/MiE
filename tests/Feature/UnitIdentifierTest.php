<?php

namespace Tests\Feature;

use App\Crud\UnitCrud;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UnitIdentifierTest extends TestCase
{
    use DatabaseTransactions;

    private function saveUnit( array $fields ): Unit
    {
        return Unit::factory()->create( array_merge( ['group_id' => UnitGroup::factory()->create()->id], $fields ) );
    }

    public function test_thai_names_get_nonempty_distinct_identifiers(): void
    {
        $crud = app( UnitCrud::class );
        $a = $crud->filterPostInputs( ['name' => 'กล่อง', 'identifier' => ''] );
        $b = $crud->filterPostInputs( ['name' => 'ถุง', 'identifier' => ''] );
        $this->assertNotSame( '', $a['identifier'] );
        $this->assertNotSame( $a['identifier'], $b['identifier'] );
        $this->saveUnit( $a );
        $this->saveUnit( $b );
    }

    public function test_generated_identifier_does_not_reuse_an_existing_slug(): void
    {
        $this->saveUnit( ['identifier' => 'audit-box'] );
        $fields = app( UnitCrud::class )->filterPostInputs( ['name' => 'Audit Box', 'identifier' => ''] );
        $this->assertNotSame( 'audit-box', $fields['identifier'] );
        $this->saveUnit( $fields );
    }

    public function test_edit_keeps_existing_identifier_when_field_is_empty(): void
    {
        $unit = $this->saveUnit( ['identifier' => 'audit-stable'] );
        $crud = app( UnitCrud::class );
        $this->assertSame( 'audit-stable', $crud->filterPutInputs( ['name' => 'กล่องใหม่', 'identifier' => ''], $unit )['identifier'] );
        $this->assertSame( 'explicit-id', $crud->filterPostInputs( ['name' => 'กล่อง', 'identifier' => 'explicit-id'] )['identifier'] );
    }
}

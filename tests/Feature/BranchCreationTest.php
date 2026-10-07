<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Register;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class BranchCreationTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attemptAuthenticate( $this->attemptGetAnyUserFromRole( 'admin' ) );
    }

    private function payload(): array
    {
        return ['name' => 'QA สาขาใหม่', 'general' => [
            'code' => 'QA53-' . uniqid(), 'tax_branch_code' => '00001',
            'description' => '', 'address' => '', 'phone' => '', 'email' => '',
            'is_active' => 1, 'is_main' => 0, 'is_main_warehouse' => 0,
        ]];
    }

    public function test_create_and_edit_preserve_uuid_and_create_one_closed_register(): void
    {
        $payload = $this->payload();
        $this->postJson( '/api/crud/ns.branches', $payload )->assertOk();
        $branch = Branch::where( 'code', $payload['general']['code'] )->firstOrFail();
        $this->assertTrue( Str::isUuid( $branch->uuid ) );
        $this->assertSame( '00001', $branch->tax_branch_code );
        $this->assertSame( $payload['name'], $branch->name );
        $this->assertEquals( auth()->id(), $branch->author_id );
        $this->assertSame( 1, $branch->registers()->count() );
        $this->assertSame( Register::STATUS_CLOSED, $branch->registers()->first()->status );
        $uuid = $branch->uuid;
        $payload['name'] = 'QA เปลี่ยนชื่อสาขา';
        $this->putJson( '/api/crud/ns.branches/' . $branch->id, $payload )->assertOk();
        $this->assertSame( $uuid, $branch->fresh()->uuid );
        $this->assertSame( $payload['name'], $branch->fresh()->name );
        $this->assertSame( 1, $branch->registers()->count() );
    }

    public function test_duplicate_code_is_rejected_on_create_and_edit(): void
    {
        $payload = $this->payload();
        $this->postJson( '/api/crud/ns.branches', $payload )->assertOk();
        $this->postJson( '/api/crud/ns.branches', $payload )->assertUnprocessable()->assertJsonValidationErrors( ['general.code'] );
        $this->assertSame( 1, Branch::where( 'code', $payload['general']['code'] )->count() );
        $other = $this->payload();
        $this->postJson( '/api/crud/ns.branches', $other )->assertOk();
        $branch = Branch::where( 'code', $other['general']['code'] )->firstOrFail();
        $this->putJson( '/api/crud/ns.branches/' . $branch->id, $payload )->assertUnprocessable()->assertJsonValidationErrors( ['general.code'] );
        $this->assertSame( $other['general']['code'], $branch->fresh()->code );
    }

    public function test_invalid_tax_codes_are_rejected_before_saving(): void
    {
        foreach ( ['RGF001', '1234', '123456', '12A45'] as $code ) {
            $payload = $this->payload();
            $payload['general']['tax_branch_code'] = $code;
            $this->postJson( '/api/crud/ns.branches', $payload )->assertUnprocessable()->assertJsonValidationErrors( ['general.tax_branch_code'] );
            $this->assertFalse( Branch::where( 'code', $payload['general']['code'] )->exists() );
        }
    }

    public function test_empty_optional_tax_code_is_allowed(): void
    {
        $payload = $this->payload();
        $payload['general']['tax_branch_code'] = '';
        $this->postJson( '/api/crud/ns.branches', $payload )->assertOk();
        $this->assertNull( Branch::where( 'code', $payload['general']['code'] )->firstOrFail()->tax_branch_code );
    }
}

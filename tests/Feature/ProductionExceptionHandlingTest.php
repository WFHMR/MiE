<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionExceptionHandlingTest extends TestCase
{
    public function test_validation_preserves_field_errors(): void
    {
        Route::post( '/audit/validation', fn () => request()->validate( ['name' => 'required'] ) );
        $this->postJson( '/audit/validation' )->assertUnprocessable()->assertJsonValidationErrors( 'name' );
    }

    public function test_method_not_allowed_preserves_allow_header(): void
    {
        Route::post( '/audit/method', fn () => ['ok' => true] );
        $this->getJson( '/audit/method' )->assertStatus( 405 )->assertHeader( 'Allow', 'POST' );
    }

    public function test_forbidden_and_throttled_statuses_are_preserved(): void
    {
        Route::get( '/audit/forbidden', fn () => abort( 403 ) );
        Route::get( '/audit/throttled', fn () => abort( 429, '', ['Retry-After' => '60'] ) );
        $this->getJson( '/audit/forbidden' )->assertForbidden();
        $this->getJson( '/audit/throttled' )->assertStatus( 429 )->assertHeader( 'Retry-After', '60' );
    }

    public function test_unexpected_exception_details_are_hidden(): void
    {
        Route::get( '/audit/error', fn () => throw new \RuntimeException( 'private-database-detail' ) );
        $this->getJson( '/audit/error' )->assertStatus( 500 )->assertDontSee( 'private-database-detail' );
        $this->get( '/audit/error' )->assertStatus( 500 )->assertDontSee( 'private-database-detail' );
    }
}

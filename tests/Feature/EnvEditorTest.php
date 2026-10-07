<?php

namespace Tests\Feature;

use App\Services\EnvEditor;
use Tests\TestCase;

/**
 * EnvEditor writes the real .env, so a defect here damages the running install.
 *
 * It used to parse each line with explode( '=', $line ) and keep only index 1,
 * which truncated every value containing an equals sign. APP_KEY is base64 and
 * normally ends with "=" padding, so a single set() call silently mangled the
 * application key. It also dropped comments and blank lines, meaning writing one
 * key erased the documentation of every other key.
 */
class EnvEditorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path( 'framework/testing/env-editor-' . uniqid() . '.env' );

        if ( ! is_dir( dirname( $this->path ) ) ) {
            mkdir( dirname( $this->path ), 0777, true );
        }
    }

    protected function tearDown(): void
    {
        if ( is_file( $this->path ) ) {
            unlink( $this->path );
        }

        parent::tearDown();
    }

    private function given( string $contents ): EnvEditor
    {
        file_put_contents( $this->path, $contents );

        return new EnvEditor( $this->path );
    }

    private function currentContents(): string
    {
        return file_get_contents( $this->path );
    }

    public function test_it_keeps_base64_padding_intact(): void
    {
        $key = 'base64:hpMHiWKrJPPD8FSpDVCnB6EMWJcEs4wj0/Cv0KbGZlU=';

        $editor = $this->given( "APP_KEY={$key}\nAPP_ENV=local\n" );

        $this->assertSame( $key, $editor->get( 'APP_KEY' ) );

        $editor->set( 'APP_ENV', 'production' );

        $this->assertStringContainsString(
            'APP_KEY=' . $key,
            $this->currentContents(),
            'Writing an unrelated key must not rewrite APP_KEY.'
        );
    }

    public function test_it_preserves_comments_and_blank_lines(): void
    {
        $original = <<<'ENV'
        # Application
        APP_NAME="RGBF"
        APP_ENV=local

        # Database
        DB_CONNECTION=mysql
        ENV;

        $editor = $this->given( $original . "\n" );

        $editor->set( 'APP_ENV', 'production' );

        $contents = $this->currentContents();

        $this->assertStringContainsString( '# Application', $contents );
        $this->assertStringContainsString( '# Database', $contents );
        $this->assertStringContainsString( 'APP_ENV="production"', $contents );
        $this->assertStringContainsString( 'DB_CONNECTION=mysql', $contents );
    }

    public function test_it_replaces_a_key_in_place_without_duplicating_it(): void
    {
        $editor = $this->given( "A=1\nSANCTUM_STATEFUL_DOMAINS=old.example.com\nB=2\n" );

        $editor->set( 'SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1' );

        $contents = $this->currentContents();

        $this->assertSame( 1, substr_count( $contents, 'SANCTUM_STATEFUL_DOMAINS=' ) );
        $this->assertStringNotContainsString( 'old.example.com', $contents );
        $this->assertSame( 'localhost,127.0.0.1', $editor->get( 'SANCTUM_STATEFUL_DOMAINS' ) );

        /**
         * Surrounding keys must keep their position.
         */
        $this->assertStringContainsString( "A=1\n", $contents );
        $this->assertStringContainsString( "B=2\n", $contents );
    }

    public function test_it_appends_a_key_that_does_not_exist_yet(): void
    {
        $editor = $this->given( "A=1\n" );

        $editor->set( 'MARKETPLACE_DOMAIN', 'https://my.nexopos.com' );

        $this->assertSame( 'https://my.nexopos.com', $editor->get( 'MARKETPLACE_DOMAIN' ) );
        $this->assertStringContainsString( 'A=1', $this->currentContents() );
    }

    public function test_it_reads_a_quoted_value_followed_by_an_inline_comment(): void
    {
        $editor = $this->given( "REVERB_SERVER_HOST=\"127.0.0.1\" # the current server host\n" );

        $this->assertSame( '127.0.0.1', $editor->get( 'REVERB_SERVER_HOST' ) );
    }

    public function test_it_reads_an_unquoted_value_followed_by_an_inline_comment(): void
    {
        $editor = $this->given( "REVERB_PORT=8080 # local websocket port\n" );

        $this->assertSame( '8080', $editor->get( 'REVERB_PORT' ) );
    }

    public function test_it_ignores_commented_out_keys(): void
    {
        $editor = $this->given( "# MARKETPLACE_CLIENT_ID=should-not-be-read\nA=1\n" );

        $this->assertFalse( $editor->has( 'MARKETPLACE_CLIENT_ID' ) );
        $this->assertNull( $editor->get( 'MARKETPLACE_CLIENT_ID' ) );
    }

    public function test_it_escapes_quotes_rather_than_every_apostrophe(): void
    {
        $editor = $this->given( "A=1\n" );

        $editor->set( 'APP_NAME', "Chava's Store" );

        $this->assertSame( "Chava's Store", $editor->get( 'APP_NAME' ) );
        $this->assertStringNotContainsString( "Chava\\'s", $this->currentContents() );
    }

    public function test_it_refuses_to_split_a_value_across_lines(): void
    {
        $editor = $this->given( "A=1\n" );

        $editor->set( 'B', "one\ntwo" );

        $this->assertSame( 'onetwo', $editor->get( 'B' ) );
        $this->assertSame( 2, substr_count( trim( $this->currentContents() ), "\n" ) );
    }

    public function test_delete_removes_only_the_targeted_key(): void
    {
        $editor = $this->given( "# keep me\nA=1\nB=2\n" );

        $editor->delete( 'A' );

        $contents = $this->currentContents();

        $this->assertFalse( $editor->has( 'A' ) );
        $this->assertTrue( $editor->has( 'B' ) );
        $this->assertStringContainsString( '# keep me', $contents );
    }

    /**
     * The live .env must not already be carrying a truncated key.
     */
    public function test_the_application_key_in_use_decodes_to_a_full_length_key(): void
    {
        $key = config( 'app.key' );

        $this->assertNotEmpty( $key, 'APP_KEY is not set.' );

        $decoded = base64_decode( str_starts_with( $key, 'base64:' ) ? substr( $key, 7 ) : $key, true );

        $this->assertNotFalse( $decoded, 'APP_KEY is not valid base64.' );
        $this->assertSame(
            32,
            strlen( $decoded ),
            'APP_KEY must decode to 32 bytes for AES-256-CBC.'
        );
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The shop trades under its own name and presents this system as its own. The name of the project
 * it was built from must not appear on any screen its staff or its customers can open.
 *
 * This is not a cosmetic rule and it was not caught by reading the interface. The Description field
 * of the "Store Administrator" role read "Has a control over an entire store of NexoPOS." — stored
 * in the database at install time and shown in a textarea whenever that role is opened for editing
 * at ผู้ใช้งาน → สร้างกลุ่มผู้ใช้งาน. Nobody looking at the sidebar or the till would ever have
 * seen it.
 *
 * The distinction this test rests on is capitalisation, and it is a real one rather than a
 * convenience:
 *
 *   "nexopos"  lowercase  is an identifier — the table prefix nexopos_*, permission namespaces
 *              like nexopos.create.orders, route names, the composer package. None of it is
 *              rendered as prose and none of it can be renamed without rewriting the schema.
 *
 *   "NexoPOS"  as spelled here is the product name. Wherever that spelling appears in text a
 *              user can read, it is a supplier's name on the shop's screen.
 *
 * So the check is case-sensitive on the branded spelling, and only over the places that produce
 * text for a person to read.
 */
class NoUpstreamBrandingTest extends TestCase
{
    private const BRAND = 'NexoPOS';

    /**
     * Deliberately in database/migrations rather than database/migrations/update.
     *
     * SetupService marks everything under update as executed without running it, so a migration
     * filed there reports "Nothing to migrate" and never touches the database. This one was written
     * there first and did exactly that. Asserting on the path is therefore not pedantry — it is the
     * difference between the fix reaching the shop and appearing to.
     */
    private const MIGRATION = 'migrations/2026_09_13_220000_rewrite_role_descriptions_in_thai.php';

    /**
     * There is no longer an exception to this rule.
     *
     * One was allowed: the key of window.ns.langFiles in layout/_header-script.blade.php, excused on
     * the grounds that it was an internal dictionary key "displayed to nobody". That reasoning was
     * wrong. json_encode wrote it into the page source of every dashboard screen, where
     * {"NexoPOS":"https://…/lang/th.json"} sat one View Source away from any member of staff or any
     * customer handed the tablet.
     *
     * The key is now `core`, lowercase, which cannot collide with a module namespace because those
     * come from ucwords( Str::camel( … ) ) and always begin with a capital. Nothing is excused here
     * now, so this test reads the whole of resources with no filter.
     *
     * @return list<string>
     */
    private function sourceFilesIn( string $directory ): array
    {
        if ( ! is_dir( $directory ) ) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && in_array( strtolower( $file->getExtension() ), [ 'php', 'json' ], true ) ) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function frontendSourceFilesIn( string $directory ): array
    {
        if ( ! is_dir( $directory ) ) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && in_array( strtolower( $file->getExtension() ), [ 'ts', 'js', 'vue' ], true ) ) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Views are the last thing between the application and the person using it. A branded string
     * here reaches either the rendered page or, for anything inside a script tag, the page source.
     */
    public function test_no_view_carries_the_upstream_product_name(): void
    {
        $offenders = [];

        foreach ( $this->sourceFilesIn( base_path( 'resources/views' ) ) as $path ) {
            if ( str_contains( (string) file_get_contents( $path ), self::BRAND ) ) {
                $offenders[] = str_replace( base_path() . DIRECTORY_SEPARATOR, '', $path );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These views reach the browser and still name the upstream project: ' . implode( ', ', $offenders )
        );
    }

    /**
     * The compiled bundle is served to the browser as plain text, so a branded string in a .ts, .js
     * or .vue file is as readable as one in a view — it just takes one more click to find.
     *
     * This caught two. The lang namespace key, and a Vue runtime fallback in the vite plugin that
     * read globalThis.NexoPOSVue: the runtime is assigned as RGBFVue, so that fallback pointed at
     * nothing and could never have fired, while writing the name into every module asset built
     * through the plugin along with the error text beside it.
     *
     * Case-sensitive on the branded spelling, as above. Lowercase nexopos in a filename, an import
     * path or a permission string is an identifier and stays.
     */
    public function test_no_frontend_source_carries_the_upstream_product_name(): void
    {
        $offenders = [];

        foreach ( $this->frontendSourceFilesIn( base_path( 'resources' ) ) as $path ) {
            if ( str_contains( (string) file_get_contents( $path ), self::BRAND ) ) {
                $offenders[] = str_replace( base_path() . DIRECTORY_SEPARATOR, '', $path );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These files are compiled into assets the browser downloads and still name the upstream'
                . ' project: ' . implode( ', ', $offenders )
        );
    }

    /**
     * The role and permission definition files write name and description straight into the
     * database, and both are shown on the roles and permissions screens.
     */
    public function test_no_role_or_permission_definition_carries_the_upstream_product_name(): void
    {
        $offenders = [];

        foreach ( $this->sourceFilesIn( database_path( 'permissions' ) ) as $path ) {
            if ( str_contains( (string) file_get_contents( $path ), self::BRAND ) ) {
                $offenders[] = str_replace( base_path() . DIRECTORY_SEPARATOR, '', $path );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These definitions are written into the database and displayed on the roles screen: ' . implode( ', ', $offenders )
        );
    }

    /**
     * Every other label on this installation is Thai. A role description in English is not a leak,
     * but it is the seam where one was found, and an English sentence among Thai ones is exactly
     * the kind of text nobody rereads.
     */
    public function test_the_built_in_shop_roles_describe_themselves_in_thai(): void
    {
        $files = [
            'store-admin-role.php',
            'store-cashier-role.php',
            'store-customer-role.php',
        ];

        foreach ( $files as $file ) {
            $path = database_path( 'permissions/' . $file );

            $this->assertFileExists( $path );

            $contents = (string) file_get_contents( $path );

            $this->assertMatchesRegularExpression(
                '/->description\s*=\s*\'[^\']*\p{Thai}/u',
                $contents,
                sprintf( '%s still assigns a description with no Thai in it.', $file )
            );
        }
    }

    /**
     * The definition files only run at install. This shop is already installed, so the corrected
     * text reaches it through a migration; if that migration stops covering a namespace the wrong
     * text stays in the database and no other test would notice.
     */
    public function test_the_migration_rewrites_every_built_in_role(): void
    {
        $path = database_path( self::MIGRATION );

        $this->assertFileExists( $path );

        $contents = (string) file_get_contents( $path );

        foreach ( [ 'nexopos.store.administrator', 'nexopos.store.cashier', 'nexopos.store.customer', 'admin', 'user' ] as $namespace ) {
            $this->assertStringContainsString(
                "'" . $namespace . "'",
                $contents,
                sprintf( 'The migration does not rewrite the description of the %s role.', $namespace )
            );
        }

        $this->assertStringNotContainsString( self::BRAND, $contents );
    }

    /**
     * A migration under database/migrations/update is registered as executed by SetupService without
     * ever being run, so it silently does nothing. Nothing in the framework reports that: artisan
     * says "Nothing to migrate" and exits successfully, which is what it said the first time this
     * fix was deployed.
     */
    public function test_the_migration_is_not_filed_where_it_would_never_run(): void
    {
        $this->assertFileDoesNotExist(
            database_path( 'migrations/update/2026_09_13_220000_rewrite_role_descriptions_in_thai.php' ),
            'The migration is under migrations/update, where it is marked as done without running.'
        );

        $this->assertFileExists( database_path( self::MIGRATION ) );
    }

    /**
     * The migration has to survive being run before the roles table exists.
     *
     * It is reached during the full reset, where the schema is dropped and rebuilt, and on a fresh
     * install. Without a table guard the UPDATE raised "no such table: nexopos_roles", the reset
     * aborted, and 594 tests failed — none of them about role descriptions. A fresh install on the
     * shop's own server would have failed the same way.
     */
    public function test_the_migration_is_safe_to_run_before_the_table_exists(): void
    {
        $contents = (string) file_get_contents( database_path( self::MIGRATION ) );

        $this->assertMatchesRegularExpression(
            '/Schema::hasTable\(/',
            $contents,
            'The migration writes to nexopos_roles without checking that the table exists, so it breaks a fresh install and the test reset.'
        );

        Schema::dropIfExists( 'nexopos_roles_absent_probe' );

        $migration = require database_path( self::MIGRATION );

        /**
         * Driven against a connection where the table is genuinely missing, so the guard is proven
         * rather than merely present in the source.
         */
        Schema::rename( 'nexopos_roles', 'nexopos_roles_absent_probe' );

        try {
            $migration->up();
        } finally {
            Schema::rename( 'nexopos_roles_absent_probe', 'nexopos_roles' );
        }

        $this->assertTrue( Schema::hasTable( 'nexopos_roles' ) );
    }

    /**
     * Drives the migration rather than reading it, so that a namespace named in the map but
     * misspelt, or a column renamed underneath it, is caught.
     */
    public function test_running_the_migration_leaves_thai_descriptions_in_the_database(): void
    {
        DB::table( 'nexopos_roles' )->updateOrInsert(
            [ 'namespace' => 'nexopos.store.administrator' ],
            [
                'name' => 'Store Administrator',
                'description' => 'Has a control over an entire store of ' . self::BRAND . '.',
            ]
        );

        $migration = require database_path( self::MIGRATION );

        $migration->up();

        $description = (string) DB::table( 'nexopos_roles' )
            ->where( 'namespace', 'nexopos.store.administrator' )
            ->value( 'description' );

        $this->assertStringNotContainsString(
            self::BRAND,
            $description,
            'The migration ran but the upstream product name is still in the role description.'
        );

        $this->assertMatchesRegularExpression(
            '/\p{Thai}/u',
            $description,
            'The migration ran but the description it wrote is not Thai.'
        );
    }
}

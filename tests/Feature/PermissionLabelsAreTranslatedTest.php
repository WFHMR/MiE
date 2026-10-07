<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAttribute;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The permission manager must speak the shop's language.
 *
 * A permission's label is a database value, written when the permission was first registered, so it
 * is fixed in whatever language did the registering. The permission manager rendered it exactly as
 * stored, so the page listing every tick box showed rows like "Upload Medias" and "Create Customers"
 * in English — while lang/th.json had held Thai for all of them the whole time. Nothing put the
 * label through the translator.
 *
 * The namespace must survive untouched. Saving a role posts namespaces as the keys, and the till
 * decides what a cashier may do by matching on namespace, so translating that instead of the label
 * would quietly break both.
 */
class PermissionLabelsAreTranslatedTest extends TestCase
{
    use WithAuthentication;

    private ?User $actor = null;

    private ?string $languageBefore = null;

    private bool $createdAttribute = false;

    protected function tearDown(): void
    {
        if ( $this->actor instanceof User ) {
            if ( $this->createdAttribute ) {
                DB::table( 'nexopos_users_attributes' )->where( 'user_id', $this->actor->id )->delete();
            } else {
                DB::table( 'nexopos_users_attributes' )
                    ->where( 'user_id', $this->actor->id )
                    ->update( [ 'language' => $this->languageBefore ] );
            }
        }

        parent::tearDown();
    }

    public function test_permission_labels_arrive_translated_while_namespaces_do_not_move(): void
    {
        $this->useThai();

        $stored = DB::table( 'nexopos_permissions' )->pluck( 'name', 'namespace' );
        $thai = $this->thaiTranslations();

        $response = $this->actingAs( $this->actor(), 'web' )->json( 'GET', '/api/users/permissions' );

        $response->assertOk();

        $rows = $response->json();

        $this->assertNotEmpty( $rows, 'the permission manager received no permissions at all' );

        $translated = 0;

        foreach ( $rows as $row ) {
            $namespace = $row[ 'namespace' ] ?? null;

            $this->assertNotNull( $namespace, 'a permission came back without a namespace' );

            $this->assertArrayHasKey(
                $namespace,
                $stored->toArray(),
                sprintf(
                    'The permission manager received the namespace "%s", which is not what the'
                        . ' database holds. Roles are saved by namespace, so a changed namespace'
                        . ' silently grants or revokes the wrong permission.',
                    $namespace
                )
            );

            $storedName = $stored[ $namespace ];

            if ( ! isset( $thai[ $storedName ] ) ) {
                continue;
            }

            $this->assertSame(
                $thai[ $storedName ],
                $row[ 'name' ],
                sprintf(
                    'The permission "%s" was shown as "%s" although lang/th.json translates it to'
                        . ' "%s". The label is not being passed through the translator, so the'
                        . ' permission manager reads in English.',
                    $namespace,
                    $row[ 'name' ],
                    $thai[ $storedName ]
                )
            );

            $translated++;
        }

        /**
         * A floor rather than an exact figure: permissions come and go as modules are added. The
         * point is that a whole page of labels is being translated, so dropping the translator
         * cannot pass unnoticed.
         */
        $this->assertGreaterThan(
            50,
            $translated,
            'Almost nothing on the permission manager was translated, so the page still reads in English.'
        );
    }

    public function test_role_column_headings_arrive_translated_too(): void
    {
        $this->useThai();

        $stored = DB::table( 'nexopos_roles' )->pluck( 'name', 'namespace' );
        $thai = $this->thaiTranslations();

        $response = $this->actingAs( $this->actor(), 'web' )->json( 'GET', '/api/users/roles' );

        $response->assertOk();

        $translated = 0;

        foreach ( $response->json() as $row ) {
            $namespace = $row[ 'namespace' ] ?? null;
            $storedName = $stored[ $namespace ] ?? null;

            if ( $storedName === null || ! isset( $thai[ $storedName ] ) ) {
                continue;
            }

            $this->assertSame(
                $thai[ $storedName ],
                $row[ 'name' ],
                sprintf(
                    'The role "%s" was shown as "%s" although lang/th.json translates it to "%s",'
                        . ' so the permission manager column headings read in English.',
                    $namespace,
                    $row[ 'name' ],
                    $thai[ $storedName ]
                )
            );

            $translated++;
        }

        $this->assertGreaterThan(
            0,
            $translated,
            'No role heading was translated, so the columns on the permission manager stay in English.'
        );
    }

    /**
     * A role the shop named itself has no translation, and must be left exactly as typed rather
     * than blanked or altered.
     */
    public function test_a_role_the_shop_named_itself_is_left_as_typed(): void
    {
        $this->useThai();

        $namespace = 'rgbf.test.own-name-' . random_int( 10000, 99999 );
        $name = 'ชื่อที่ร้านตั้งเอง ' . random_int( 100, 999 );

        DB::table( 'nexopos_roles' )->insert( [
            'name' => $name,
            'namespace' => $namespace,
            'description' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        try {
            $response = $this->actingAs( $this->actor(), 'web' )->json( 'GET', '/api/users/roles' );

            $response->assertOk();

            $row = collect( $response->json() )->firstWhere( 'namespace', $namespace );

            $this->assertNotNull( $row, 'the role the shop created was not returned at all' );

            $this->assertSame(
                $name,
                $row[ 'name' ],
                'A role name the shop typed itself came back altered. Names without a translation'
                    . ' must pass through untouched.'
            );
        } finally {
            DB::table( 'nexopos_roles' )->where( 'namespace', $namespace )->delete();
        }
    }

    public function test_translating_the_label_does_not_rewrite_the_database(): void
    {
        $this->useThai();

        $before = DB::table( 'nexopos_permissions' )->orderBy( 'namespace' )->pluck( 'name', 'namespace' );

        $this->actingAs( $this->actor(), 'web' )->json( 'GET', '/api/users/permissions' )->assertOk();

        $after = DB::table( 'nexopos_permissions' )->orderBy( 'namespace' )->pluck( 'name', 'namespace' );

        $this->assertSame(
            $before->toArray(),
            $after->toArray(),
            'Reading the permission list rewrote the stored labels. Translating for display must not'
                . ' persist anything, or the stored English would be replaced and the page could'
                . ' never be shown in another language again.'
        );
    }

    /** @return array<string, string> */
    private function thaiTranslations(): array
    {
        $path = base_path( 'lang/th.json' );

        $this->assertFileExists( $path );

        return json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
    }

    /**
     * The locale is decided per request from the signed in user's own language, so it is set there
     * rather than with App::setLocale, which the middleware would overwrite.
     */
    private function useThai(): void
    {
        $actor = $this->actor();

        $attribute = UserAttribute::where( 'user_id', $actor->id )->first();

        if ( $attribute instanceof UserAttribute ) {
            $this->languageBefore = $attribute->language;

            DB::table( 'nexopos_users_attributes' )
                ->where( 'user_id', $actor->id )
                ->update( [ 'language' => 'th' ] );

            return;
        }

        $this->createdAttribute = true;

        DB::table( 'nexopos_users_attributes' )->insert( [
            'user_id' => $actor->id,
            'language' => 'th',
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
        }

        return $this->actor;
    }
}

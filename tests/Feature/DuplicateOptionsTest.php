<?php

namespace Tests\Feature;

use App\Models\Option;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A setting stored twice is a setting whose value is decided by the database's mood.
 *
 * This installation had the store name twice, once as the installer's placeholder and once as the
 * registered company name, and the read had no ordering, so which of the two applied was undefined.
 * That name prints on tax invoices, so it cannot be left to chance.
 */
class DuplicateOptionsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_most_recently_written_row_is_the_one_that_applies(): void
    {
        $this->storeRawOption( 'ns_test_store_name', 'ชื่อเก่า' );
        $this->storeRawOption( 'ns_test_store_name', 'ชื่อใหม่' );

        ns()->option->rebuild();

        $this->assertSame(
            'ชื่อใหม่',
            ns()->option->get( 'ns_test_store_name' ),
            'The later row is the one someone meant to save.'
        );
    }

    public function test_duplicates_are_cleared_keeping_the_later_value(): void
    {
        $this->storeRawOption( 'ns_test_dup', 'placeholder' );
        $this->storeRawOption( 'ns_test_dup', 'หจก.รุ่งเรืองแกรนด์' );

        ns()->option->rebuild();

        $removed = ns()->option->purgeDuplicates();

        $this->assertArrayHasKey( 'ns_test_dup', $removed );
        $this->assertSame( 1, $removed[ 'ns_test_dup' ] );

        $this->assertSame(
            1,
            Option::where( 'key', 'ns_test_dup' )->count(),
            'One setting must leave one row.'
        );

        $this->assertSame(
            'หจก.รุ่งเรืองแกรนด์',
            Option::where( 'key', 'ns_test_dup' )->value( 'value' ),
            'Clearing duplicates must not throw away the value that was meant to win.'
        );
    }

    /**
     * The previous fixer ignored who owned a setting, so a value saved against one user was taken
     * for a duplicate of the shop wide one and deleted, along with every other user's copy.
     */
    public function test_a_setting_saved_against_a_user_is_not_treated_as_a_duplicate(): void
    {
        $this->storeRawOption( 'ns_test_theme', 'light' );
        $this->storeRawOption( 'ns_test_theme', 'dark', userId: 1 );
        $this->storeRawOption( 'ns_test_theme', 'phosphor', userId: 2 );

        ns()->option->purgeDuplicates();

        $this->assertSame( 1, Option::where( 'key', 'ns_test_theme' )->whereNull( 'user_id' )->count() );
        $this->assertSame( 'dark', Option::where( 'key', 'ns_test_theme' )->where( 'user_id', 1 )->value( 'value' ) );
        $this->assertSame( 'phosphor', Option::where( 'key', 'ns_test_theme' )->where( 'user_id', 2 )->value( 'value' ) );
    }

    public function test_a_users_own_duplicates_are_cleared_within_that_user(): void
    {
        $this->storeRawOption( 'ns_test_lang', 'en', userId: 5 );
        $this->storeRawOption( 'ns_test_lang', 'th', userId: 5 );

        ns()->option->purgeDuplicates();

        $rows = Option::where( 'key', 'ns_test_lang' )->where( 'user_id', 5 )->get();

        $this->assertCount( 1, $rows );
        $this->assertSame( 'th', $rows->first()->value );
    }

    /**
     * Writing a setting under a differently cased name used to insert a second row, because the
     * check for an existing one compared the name as given while the insert stored it lowercased.
     */
    public function test_writing_a_setting_under_a_different_case_does_not_add_a_row(): void
    {
        ns()->option->set( 'ns_test_case', 'first' );
        ns()->option->set( 'NS_Test_Case', 'second' );

        $rows = Option::where( 'key', 'ns_test_case' )->get();

        $this->assertCount( 1, $rows, 'Case alone must not create a second row.' );
        $this->assertSame( 'second', $rows->first()->value );
    }

    /**
     * A setting written straight to the table by a migration is invisible to the copy the service
     * holds in memory, and writing it through the service used to add a second row rather than
     * update the one already there.
     */
    public function test_writing_over_a_row_created_outside_the_service_updates_it(): void
    {
        $this->storeRawOption( 'ns_test_seeded', 'from-a-migration' );

        ns()->option->set( 'ns_test_seeded', 'from-the-service' );

        $rows = Option::where( 'key', 'ns_test_seeded' )->get();

        $this->assertCount( 1, $rows );
        $this->assertSame( 'from-the-service', $rows->first()->value );
    }

    public function test_deleting_a_setting_removes_every_row_for_it(): void
    {
        $this->storeRawOption( 'ns_test_gone', 'one' );
        $this->storeRawOption( 'ns_test_gone', 'two' );

        ns()->option->rebuild();
        ns()->option->delete( 'ns_test_gone' );

        $this->assertSame(
            0,
            Option::where( 'key', 'ns_test_gone' )->count(),
            'A setting left behind reappears on the next request.'
        );
    }

    private function storeRawOption( string $key, string $value, ?int $userId = null ): void
    {
        DB::table( 'nexopos_options' )->insert( [
            'key' => $key,
            'value' => $value,
            'user_id' => $userId,
            'array' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }
}

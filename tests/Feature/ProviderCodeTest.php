<?php

namespace Tests\Feature;

use App\Crud\ProviderCrud;
use App\Models\Provider;
use App\Services\CrudEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The supplier code, end to end.
 *
 * The shop trades by a code of the form SP520099, but the table had nowhere to keep it and the
 * importer read the company name column and skipped the code column beside it. The result was a
 * supplier list of 514 long Thai names with no code on screen anywhere, several of them near
 * duplicates of each other.
 *
 * Pinned here: the column exists and refuses duplicates, the list shows it, the form can write it,
 * and the backfill command fills the blanks without touching a code already set.
 */
class ProviderCodeTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private function givenProvider( string $name, ?string $code = null ): Provider
    {
        $provider = new Provider;
        $provider->first_name = $name;
        $provider->code = $code;
        $provider->author_id = $this->attemptAuthenticate()->id ?? 1;
        $provider->save();

        return $provider;
    }

    public function test_the_providers_table_carries_a_code_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn( 'nexopos_providers', 'code' ),
            'The suppliers table has nowhere to keep the code the shop trades by.'
        );
    }

    public function test_the_list_shows_the_code_first(): void
    {
        $columns = array_keys( ( new ProviderCrud )->getColumns() );

        $this->assertContains( 'code', $columns, 'The supplier list does not show the code.' );
        $this->assertSame( 'code', $columns[0], 'The code is not the first cell, so the list still reads as names only.' );
    }

    public function test_the_form_can_write_the_code(): void
    {
        $this->attemptAuthenticate();

        $fields = ( new ProviderCrud )->getForm()[ 'tabs' ][ 'general' ][ 'fields' ];
        $names = array_column( $fields, 'name' );

        $this->assertContains( 'code', $names, 'The supplier form has no field for the code.' );
        $this->assertSame( 'code', $names[0], 'The code is not the first field on the tab.' );
    }

    public function test_the_code_is_optional(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider( 'ร้านที่ยังไม่มีรหัส' );

        $this->assertNull( $provider->fresh()->code, 'A supplier without a code could not be saved.' );
    }

    public function test_two_providers_cannot_share_a_code(): void
    {
        $this->attemptAuthenticate();

        $this->givenProvider( 'รายแรก', 'SP999001' );

        $this->expectException( \Illuminate\Database\QueryException::class );

        $this->givenProvider( 'รายที่สอง', 'SP999001' );
    }

    public function test_an_empty_cell_reads_as_absent_rather_than_blank(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider( 'ร้านไม่มีรหัสสำหรับตาราง' );

        /**
         * Read back from the database rather than using the instance just saved: the list renders
         * rows that carry the column defaults, and an unsaved instance has none of them.
         */
        $entry = ( new ProviderCrud )->setActions( new CrudEntry( $provider->fresh()->toArray() ) );

        $this->assertNotSame( '', (string) $entry->code, 'The cell would print empty, which reads as a fault.' );
        $this->assertNotSame( 'null', strtolower( (string) $entry->code ) );
    }

    /**
     * The backfill reads the catalogue, so the test writes a small one shaped the same way:
     * a title row, a header row, then rows whose column 11 holds the code and column 12 the name.
     */
    private function givenCatalogue( array $rows ): string
    {
        $path = storage_path( 'app/test-supplier-catalogue-' . uniqid() . '.csv' );

        $handle = fopen( $path, 'w' );
        fputcsv( $handle, [ 'รายงานสินค้า' ] );
        fputcsv( $handle, array_fill( 0, 15, 'หัวตาราง' ) );

        foreach ( $rows as $row ) {
            $line = array_fill( 0, 15, '' );
            $line[ 11 ] = $row[ 0 ];
            $line[ 12 ] = $row[ 1 ];
            fputcsv( $handle, $line );
        }

        fclose( $handle );

        return $path;
    }

    public function test_the_backfill_fills_a_blank_code_and_leaves_a_set_one_alone(): void
    {
        $this->attemptAuthenticate();

        $blank = $this->givenProvider( 'บริษัท ตัวอย่างหนึ่ง จำกัด' );
        $alreadySet = $this->givenProvider( 'บริษัท ตัวอย่างสอง จำกัด', 'SP888002' );

        $catalogue = $this->givenCatalogue( [
            [ 'SP888001', 'บริษัท ตัวอย่างหนึ่ง จำกัด' ],
            [ 'SP888009', 'บริษัท ตัวอย่างสอง จำกัด' ],
        ] );

        Artisan::call( 'providers:backfill-codes', [ 'file' => $catalogue ] );

        $this->assertSame( 'SP888001', $blank->fresh()->code, 'The blank code was not filled in.' );
        $this->assertSame( 'SP888002', $alreadySet->fresh()->code, 'A code that was already set got overwritten.' );

        unlink( $catalogue );
    }

    public function test_the_backfill_writes_nothing_when_asked_to_only_report(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider( 'บริษัท ทดลองเท่านั้น จำกัด' );

        $catalogue = $this->givenCatalogue( [
            [ 'SP888003', 'บริษัท ทดลองเท่านั้น จำกัด' ],
        ] );

        Artisan::call( 'providers:backfill-codes', [ 'file' => $catalogue, '--dry-run' => true ] );

        $this->assertNull( $provider->fresh()->code, 'A dry run wrote to the database.' );

        unlink( $catalogue );
    }

    /**
     * Matching on the name is only safe while the file identifies one supplier per code. If it ever
     * stops doing that, writing would move codes onto the wrong suppliers, so nothing is written.
     */
    public function test_the_backfill_refuses_a_file_where_one_name_carries_two_codes(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider( 'บริษัท ชื่อซ้ำ จำกัด' );

        $catalogue = $this->givenCatalogue( [
            [ 'SP888004', 'บริษัท ชื่อซ้ำ จำกัด' ],
            [ 'SP888005', 'บริษัท ชื่อซ้ำ จำกัด' ],
        ] );

        Artisan::call( 'providers:backfill-codes', [ 'file' => $catalogue ] );

        $this->assertNull( $provider->fresh()->code, 'A code was written from a file that cannot identify the supplier.' );

        unlink( $catalogue );
    }

    public function test_it_creates_only_the_suppliers_the_database_does_not_have(): void
    {
        $this->attemptAuthenticate();

        $byName = $this->givenProvider( 'บริษัท มีชื่อแล้ว จำกัด' );
        $byCode = $this->givenProvider( 'ชื่อที่ถูกแก้ไปแล้ว', 'SP777002' );

        $before = Provider::count();

        $catalogue = $this->givenCatalogue( [
            [ 'SP777001', 'บริษัท มีชื่อแล้ว จำกัด' ],
            [ 'SP777002', 'ชื่อเดิมในไฟล์ที่ไม่ตรงกับฐาน' ],
            [ 'SP777003', 'บริษัท ยังไม่มีในฐาน จำกัด' ],
        ] );

        Artisan::call( 'providers:backfill-codes', [
            'file' => $catalogue,
            '--create-missing' => true,
        ] );

        $this->assertSame( $before + 1, Provider::count(), 'Something other than the one missing supplier was created.' );

        $this->assertNotNull(
            Provider::where( 'code', 'SP777003' )->first(),
            'The supplier the database did not have was not created.'
        );

        $this->assertSame(
            'บริษัท มีชื่อแล้ว จำกัด',
            $byName->fresh()->first_name,
            'The supplier matched by name was duplicated or renamed.'
        );

        $this->assertSame(
            'ชื่อที่ถูกแก้ไปแล้ว',
            $byCode->fresh()->first_name,
            'The supplier matched by code had its edited name overwritten from the file.'
        );

        unlink( $catalogue );
    }

    public function test_it_creates_nothing_without_being_asked(): void
    {
        $this->attemptAuthenticate();

        $before = Provider::count();

        $catalogue = $this->givenCatalogue( [
            [ 'SP777004', 'บริษัท ไม่ควรถูกสร้าง จำกัด' ],
        ] );

        Artisan::call( 'providers:backfill-codes', [ 'file' => $catalogue ] );

        $this->assertSame( $before, Provider::count(), 'A supplier was created without --create-missing.' );

        unlink( $catalogue );
    }

    public function test_creating_respects_the_dry_run(): void
    {
        $this->attemptAuthenticate();

        $before = Provider::count();

        $catalogue = $this->givenCatalogue( [
            [ 'SP777005', 'บริษัท ทดลองสร้าง จำกัด' ],
        ] );

        Artisan::call( 'providers:backfill-codes', [
            'file' => $catalogue,
            '--create-missing' => true,
            '--dry-run' => true,
        ] );

        $this->assertSame( $before, Provider::count(), 'A dry run created a supplier.' );

        unlink( $catalogue );
    }

    /**
     * Running twice must not produce a second copy of anything, because the shop will re-run this
     * after each catalogue import.
     */
    public function test_running_the_creation_twice_adds_nothing_the_second_time(): void
    {
        $this->attemptAuthenticate();

        $catalogue = $this->givenCatalogue( [
            [ 'SP777006', 'บริษัท รันสองครั้ง จำกัด' ],
        ] );

        Artisan::call( 'providers:backfill-codes', [ 'file' => $catalogue, '--create-missing' => true ] );
        $afterFirst = Provider::count();

        Artisan::call( 'providers:backfill-codes', [ 'file' => $catalogue, '--create-missing' => true ] );

        $this->assertSame( $afterFirst, Provider::count(), 'The second run created a duplicate supplier.' );
        $this->assertSame( 1, Provider::where( 'code', 'SP777006' )->count() );

        unlink( $catalogue );
    }

    public function test_the_backfill_tolerates_double_spaces_in_the_spreadsheet(): void
    {
        $this->attemptAuthenticate();

        $provider = $this->givenProvider( 'วิริยะบราเธอร์ (นุชนารถทดสอบ)' );

        $catalogue = $this->givenCatalogue( [
            [ 'SP888006', 'วิริยะบราเธอร์  (นุชนารถทดสอบ)' ],
        ] );

        Artisan::call( 'providers:backfill-codes', [ 'file' => $catalogue ] );

        $this->assertSame(
            'SP888006',
            $provider->fresh()->code,
            'A name differing only by a double space was treated as a different supplier.'
        );

        unlink( $catalogue );
    }
}

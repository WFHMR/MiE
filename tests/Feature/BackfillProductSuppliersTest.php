<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Provider;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Attaching suppliers to a catalogue that is already in use, without disturbing anything else.
 *
 * The catalogue importer can also do this, and must not be used for it. Re-running it rewrites names,
 * categories, tax groups, statuses and prices from the spreadsheet, so any correction a member of staff
 * made in the till since the last import would be reverted without a word. A reverted price is money
 * lost or a customer overcharged, on a shop that is open.
 *
 * So the thing that matters here is not only that the supplier arrives, but that nothing else moves.
 */
class BackfillProductSuppliersTest extends TestCase
{
    use WithAuthentication;

    private string $workbook = '';

    protected function tearDown(): void
    {
        if ( $this->workbook !== '' && is_file( $this->workbook ) ) {
            unlink( $this->workbook );
        }

        parent::tearDown();
    }

    /**
     * @param array<int, array{0: string, 1: string}> $rows barcode, supplier name
     */
    private function workbookWith( array $rows ): string
    {
        $this->workbook = tempnam( sys_get_temp_dir(), 'suppliers' ) . '.csv';

        $handle = fopen( $this->workbook, 'w' );

        /**
         * The real header, in the layout the staff actually upload: a title line, then headings, with the
         * barcode in column 1 and the supplier in column 10.
         *
         * This fixture used placeholder headings and put the barcode in column 2 — matching the column
         * number the command had hard coded rather than the file it has to read. So the tests agreed with
         * the bug instead of catching it.
         */
        fputcsv( $handle, [ 'รายงานแสดงทะเบียนรายการสินค้า', '', '', '', '', '', '', '', '', '', 'พิมพ์วันที่ :', '10/09/2026', '' ] );
        fputcsv( $handle, [ 'รหัสสต็อก', 'รหัสบาร์โค๊ดสินค้า', 'ชื่อ/รายละเอียดสินค้า', 'ปริมาณ/ขนาด', 'หน่วย', 'บรรจุ', 'หน่วยขาย', 'กลุ่มหลัก', 'ย่่อย', 'ผู้จำหน่าย', 'ชื่อ/บริษัท', 'ราคาทุน', 'ขาย (VAT)' ] );

        foreach ( $rows as [ $barcode, $supplier ] ) {
            $line = array_fill( 0, 13, '' );
            $line[ 1 ] = $barcode;
            $line[ 2 ] = 'สินค้าทดสอบ ' . $barcode;
            $line[ 10 ] = $supplier;
            fputcsv( $handle, $line );
        }

        fclose( $handle );

        return $this->workbook;
    }

    public function test_it_attaches_the_supplier_named_in_the_workbook(): void
    {
        $this->attemptAuthenticate();

        /**
         * Any product will do, and a barcode is lent to it below if it has none.
         *
         * Asking the database for a product that already carries a barcode made four of these five tests
         * skip themselves on a clean install, because nothing seeded happens to have one. They reported
         * green while checking nothing.
         */
        $product = Product::orderBy( 'id' )->first();

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'No product in the catalogue.' );
        }

        $originalBarcode = (string) $product->barcode;

        $supplier = new Provider;
        $supplier->first_name = 'บริษัท ทดสอบเติมซัพพลายเออร์ ' . random_int( 100000, 999999 );
        $supplier->author_id = auth()->id() ?? 1;
        $supplier->save();

        $originalProvider = $product->provider_id;
        $barcode = ctype_digit( (string) $product->barcode ) ? (string) $product->barcode : '4900000000001';

        if ( $barcode !== (string) $product->barcode ) {
            $product->barcode = $barcode;
            $product->save();
        }

        $file = $this->workbookWith( [ [ $barcode, $supplier->first_name ] ] );

        try {
            $this->artisan( 'ns:backfill-product-suppliers', [ 'file' => $file ] )
                ->assertSuccessful();

            $this->assertSame(
                (int) $supplier->id,
                (int) Product::find( $product->id )->provider_id,
                'The workbook named a supplier for this barcode and it was not attached, so the product'
                    . ' still reports as unattributed on every sales report.'
            );
        } finally {
            Product::where( 'id', $product->id )->update( [
                'provider_id' => $originalProvider,
                'barcode' => $originalBarcode,
            ] );
            $supplier->delete();
        }
    }

    /**
     * The point of this command over re-running the import. Price, name and status must be exactly as
     * they were.
     */
    public function test_it_changes_nothing_but_the_supplier(): void
    {
        $this->attemptAuthenticate();

        /**
         * Any product will do, and a barcode is lent to it below if it has none.
         *
         * Asking the database for a product that already carries a barcode made four of these five tests
         * skip themselves on a clean install, because nothing seeded happens to have one. They reported
         * green while checking nothing.
         */
        $product = Product::orderBy( 'id' )->first();

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'No product in the catalogue.' );
        }

        $originalBarcode = (string) $product->barcode;

        $supplier = new Provider;
        $supplier->first_name = 'บริษัท ทดสอบไม่แตะอย่างอื่น ' . random_int( 100000, 999999 );
        $supplier->author_id = auth()->id() ?? 1;
        $supplier->save();

        $originalProvider = $product->provider_id;
        $barcode = ctype_digit( (string) $product->barcode ) ? (string) $product->barcode : '4900000000002';

        if ( $barcode !== (string) $product->barcode ) {
            $product->barcode = $barcode;
            $product->save();
        }

        $before = Product::find( $product->id )->toArray();

        $file = $this->workbookWith( [ [ $barcode, $supplier->first_name ] ] );

        try {
            $this->artisan( 'ns:backfill-product-suppliers', [ 'file' => $file ] )
                ->assertSuccessful();

            $after = Product::find( $product->id )->toArray();

            unset( $before[ 'provider_id' ], $after[ 'provider_id' ] );

            /**
             * updated_at is excluded on purpose: the command writes through the query builder precisely
             * so the model is not re-saved, but a timestamp moving would not be a data loss anyway. Every
             * other column is compared, prices included.
             */
            unset( $before[ 'updated_at' ], $after[ 'updated_at' ] );

            $this->assertSame(
                $before,
                $after,
                'The backfill altered something other than the supplier. That is the whole reason this'
                    . ' command exists instead of re-running the catalogue import.'
            );
        } finally {
            Product::where( 'id', $product->id )->update( [
                'provider_id' => $originalProvider,
                'barcode' => $originalBarcode,
            ] );
            $supplier->delete();
        }
    }

    /**
     * An unknown supplier name means the workbook and the database have diverged. Reporting it is useful;
     * inventing a supplier record for it is not, because the next report would then show a supplier the
     * shop has no purchase history with.
     */
    public function test_it_refuses_to_invent_a_supplier_it_does_not_know(): void
    {
        $this->attemptAuthenticate();

        /**
         * Any product will do, and a barcode is lent to it below if it has none.
         *
         * Asking the database for a product that already carries a barcode made four of these five tests
         * skip themselves on a clean install, because nothing seeded happens to have one. They reported
         * green while checking nothing.
         */
        $product = Product::orderBy( 'id' )->first();

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'No product in the catalogue.' );
        }

        $originalBarcode = (string) $product->barcode;

        $invented = 'บริษัท ที่ไม่มีอยู่จริง ' . random_int( 100000, 999999 );
        $providersBefore = Provider::count();
        $originalProvider = $product->provider_id;

        $barcode = ctype_digit( (string) $product->barcode ) ? (string) $product->barcode : '4900000000003';

        /**
         * The barcode is made to match a real product on purpose. If it did not, this test would pass
         * simply because nothing was found, and would say nothing at all about unknown suppliers.
         */
        if ( $barcode !== (string) $product->barcode ) {
            $product->barcode = $barcode;
            $product->save();
        }

        $file = $this->workbookWith( [ [ $barcode, $invented ] ] );

        try {
            $this->artisan( 'ns:backfill-product-suppliers', [ 'file' => $file ] )
                ->assertSuccessful();

            $this->assertSame(
                $providersBefore,
                Provider::count(),
                'The backfill created a supplier that was not already on file.'
            );

            $this->assertSame(
                $originalProvider,
                Product::find( $product->id )->provider_id,
                'An unmatched supplier name still changed the product.'
            );
        } finally {
            Product::where( 'id', $product->id )->update( [
                'provider_id' => $originalProvider,
                'barcode' => $originalBarcode,
            ] );
        }
    }

    /**
     * Safe to run twice, and safe to run on a shop where somebody has already set suppliers by hand —
     * a hand correction is better information than the spreadsheet and must not be trampled by default.
     */
    public function test_it_leaves_a_supplier_set_by_hand_alone_unless_told_otherwise(): void
    {
        $this->attemptAuthenticate();

        /**
         * Any product will do, and a barcode is lent to it below if it has none.
         *
         * Asking the database for a product that already carries a barcode made four of these five tests
         * skip themselves on a clean install, because nothing seeded happens to have one. They reported
         * green while checking nothing.
         */
        $product = Product::orderBy( 'id' )->first();

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'No product in the catalogue.' );
        }

        $originalBarcode = (string) $product->barcode;

        $chosenByHand = new Provider;
        $chosenByHand->first_name = 'บริษัท ที่แอดมินเลือกเอง ' . random_int( 100000, 999999 );
        $chosenByHand->author_id = auth()->id() ?? 1;
        $chosenByHand->save();

        $fromWorkbook = new Provider;
        $fromWorkbook->first_name = 'บริษัท ในไฟล์ ' . random_int( 100000, 999999 );
        $fromWorkbook->author_id = auth()->id() ?? 1;
        $fromWorkbook->save();

        $originalProvider = $product->provider_id;
        $barcode = ctype_digit( (string) $product->barcode ) ? (string) $product->barcode : '4900000000004';

        if ( $barcode !== (string) $product->barcode ) {
            $product->barcode = $barcode;
            $product->save();
        }

        Product::where( 'id', $product->id )->update( [ 'provider_id' => $chosenByHand->id ] );

        $file = $this->workbookWith( [ [ $barcode, $fromWorkbook->first_name ] ] );

        try {
            $this->artisan( 'ns:backfill-product-suppliers', [ 'file' => $file ] )
                ->assertSuccessful();

            $this->assertSame(
                (int) $chosenByHand->id,
                (int) Product::find( $product->id )->provider_id,
                'The backfill overwrote a supplier somebody had chosen by hand.'
            );

            $this->artisan( 'ns:backfill-product-suppliers', [ 'file' => $file, '--overwrite' => true ] )
                ->assertSuccessful();

            $this->assertSame(
                (int) $fromWorkbook->id,
                (int) Product::find( $product->id )->provider_id,
                '--overwrite was asked for and did not take effect.'
            );
        } finally {
            Product::where( 'id', $product->id )->update( [
                'provider_id' => $originalProvider,
                'barcode' => $originalBarcode,
            ] );
            $chosenByHand->delete();
            $fromWorkbook->delete();
        }
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->attemptAuthenticate();

        /**
         * Any product will do, and a barcode is lent to it below if it has none.
         *
         * Asking the database for a product that already carries a barcode made four of these five tests
         * skip themselves on a clean install, because nothing seeded happens to have one. They reported
         * green while checking nothing.
         */
        $product = Product::orderBy( 'id' )->first();

        if ( ! $product instanceof Product ) {
            $this->markTestSkipped( 'No product in the catalogue.' );
        }

        $originalBarcode = (string) $product->barcode;

        $supplier = new Provider;
        $supplier->first_name = 'บริษัท ทดลองไม่เขียน ' . random_int( 100000, 999999 );
        $supplier->author_id = auth()->id() ?? 1;
        $supplier->save();

        $originalProvider = $product->provider_id;
        $barcode = ctype_digit( (string) $product->barcode ) ? (string) $product->barcode : '4900000000005';

        if ( $barcode !== (string) $product->barcode ) {
            $product->barcode = $barcode;
            $product->save();
        }

        Product::where( 'id', $product->id )->update( [ 'provider_id' => null ] );

        $file = $this->workbookWith( [ [ $barcode, $supplier->first_name ] ] );

        try {
            $this->artisan( 'ns:backfill-product-suppliers', [ 'file' => $file, '--dry-run' => true ] )
                ->assertSuccessful();

            $this->assertNull(
                Product::find( $product->id )->provider_id,
                'A dry run wrote to the database, so there is no way to preview this against a live shop.'
            );
        } finally {
            Product::where( 'id', $product->id )->update( [
                'provider_id' => $originalProvider,
                'barcode' => $originalBarcode,
            ] );
            $supplier->delete();
        }
    }
}

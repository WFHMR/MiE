<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The label printing page, which could not print a usable label at all.
 *
 * Every label carries a barcode image, and the page read those images straight off the public disk.
 * They are only written there when a product is saved through the product form or received through a
 * procurement — this catalogue was loaded by CSV import, which writes none. On the live shop 2,922 of
 * 2,928 barcodes resolved to a 404: the page rendered, the settings all worked, and every label came
 * out with a broken picture where the barcode belongs.
 *
 * The page also showed English field labels on a Thai screen, because they are built in the component
 * as plain strings instead of being passed through __(), and the preview was an empty dark rectangle
 * with nothing to say that products had to be added first.
 */
class PrintLabelsPageTest extends TestCase
{
    use WithAuthentication;

    /** @var list<array{0: string, 1: int}> */
    private array $createdRows = [];

    /** @var list<string> */
    private array $createdFiles = [];

    private ?User $actor = null;

    protected function tearDown(): void
    {
        foreach ( $this->createdFiles as $path ) {
            if ( Storage::disk( 'public' )->exists( $path ) ) {
                Storage::disk( 'public' )->delete( $path );
            }
        }

        foreach ( array_reverse( $this->createdRows ) as [ $table, $id ] ) {
            DB::table( $table )->where( 'id', $id )->delete();
        }

        $this->createdFiles = [];
        $this->createdRows = [];

        parent::tearDown();
    }

    /**
     * The fault: a barcode the catalogue uses, with no image on disk.
     */
    public function test_a_barcode_with_no_image_on_disk_is_drawn_on_demand(): void
    {
        $barcode = '9771' . random_int( 100000, 999999 );

        $this->productWithBarcode( $barcode, 'code128' );

        $path = 'products/barcodes/' . $barcode . '.png';
        $this->createdFiles[] = $path;

        $this->assertFalse(
            Storage::disk( 'public' )->exists( $path ),
            'the fixture is meaningless if the image is already there'
        );

        $response = $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/products/barcode/' . $barcode . '.png' );

        $response->assertOk();

        $this->assertSame(
            'image/png',
            $response->headers->get( 'Content-Type' ),
            'The label page was served something that is not an image, so the barcode prints broken.'
        );

        $this->assertTrue(
            Storage::disk( 'public' )->exists( $path ),
            'The image was not kept, so every label print would redraw it from scratch.'
        );

        $this->assertGreaterThan(
            0,
            strlen( (string) Storage::disk( 'public' )->get( $path ) ),
            'an empty file was written'
        );
    }

    /**
     * An image that already exists is served as it stands. Thousands are already on disk from products
     * that were saved through the form, and redrawing them would change what has already been printed.
     */
    public function test_an_existing_image_is_served_untouched(): void
    {
        $barcode = '9772' . random_int( 100000, 999999 );

        $this->productWithBarcode( $barcode, 'code128' );

        $path = 'products/barcodes/' . $barcode . '.png';
        $this->createdFiles[] = $path;

        Storage::disk( 'public' )->put( $path, 'not-a-real-png-but-untouched' );

        $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/products/barcode/' . $barcode . '.png' )
            ->assertOk();

        $this->assertSame(
            'not-a-real-png-but-untouched',
            (string) Storage::disk( 'public' )->get( $path ),
            'An image that was already on disk got overwritten.'
        );
    }

    /**
     * This catalogue holds barcodes that cannot be encoded as the type their product declares — nine
     * digits on a product marked EAN-13, for one. Refusing would leave exactly the broken picture this
     * change exists to remove, so the label falls back to CODE-128, which encodes anything printable.
     */
    public function test_a_barcode_its_declared_type_cannot_encode_still_produces_a_label(): void
    {
        $barcode = 'RGBF' . random_int( 10000, 99999 );

        $this->productWithBarcode( $barcode, 'ean13' );

        $path = 'products/barcodes/' . $barcode . '.png';
        $this->createdFiles[] = $path;

        $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/products/barcode/' . $barcode . '.png' )
            ->assertOk();

        $this->assertTrue( Storage::disk( 'public' )->exists( $path ) );
    }

    /**
     * Restricted to what the catalogue actually holds, so the endpoint cannot be used to render
     * arbitrary text as an image.
     */
    public function test_a_barcode_that_belongs_to_no_product_is_refused(): void
    {
        $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/products/barcode/definitely-not-a-barcode-' . random_int( 1000, 9999 ) . '.png' )
            ->assertStatus( 404 );
    }

    /**
     * Barcodes in this catalogue contain dots. A route pattern that stops at one would answer 404 for
     * every such product while looking fine for the rest.
     */
    public function test_a_barcode_containing_a_dot_is_still_found(): void
    {
        $barcode = '13' . random_int( 100, 999 ) . '.2';

        $this->productWithBarcode( $barcode, 'code128' );

        $path = 'products/barcodes/' . $barcode . '.png';
        $this->createdFiles[] = $path;

        $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/products/barcode/' . $barcode . '.png' )
            ->assertOk();
    }

    /**
     * The page has to point at the route. Pointing at the public disk is what produced a broken image
     * on every label, and it looked correct in the markup.
     */
    public function test_the_page_points_the_labels_at_the_barcode_route(): void
    {
        $response = $this->actingAs( $this->actor(), 'web' )
            ->get( 'dashboard/products/print-labels' );

        $response->assertOk();

        $page = (string) $response->getContent();

        $this->assertStringContainsString(
            'dashboard/products/barcode',
            $page,
            'The label page still reads barcode images from a path that has no file for most of the'
                . ' catalogue.'
        );

        $this->assertStringNotContainsString(
            'storage/products/barcodes',
            $page,
            'The page still points at the public disk, where the images for an imported catalogue do'
                . ' not exist.'
        );
    }

    /**
     * The settings on the right of the page are built in the component as plain strings, which is why
     * they stayed English while every heading around them was translated.
     */
    public function test_the_label_settings_are_translated(): void
    {
        $source = File::get( resource_path( 'ts/pages/dashboard/products/ns-print-label.vue' ) );

        foreach ( [ 'ความกว้างสติกเกอร์ (มม.)', 'จำนวนดวงต่อแถว', 'ความสูงบาร์โค้ด (มม.)', 'แสดงชื่อร้าน', 'แสดงราคาขาย' ] as $label ) {
            $this->assertStringContainsString(
                $label,
                $source,
                sprintf( 'the label settings panel is still showing English where "%s" belongs', $label )
            );
        }

        foreach ( [ "label: 'Vertical Padding (pixels)'", "label: 'Show Store Name'", "label: 'Items Per Row'" ] as $english ) {
            $this->assertStringNotContainsString(
                $english,
                $source,
                'an untranslated field label is still built in the component'
            );
        }
    }

    /**
     * A sticker is a physical object. Pixels only become millimetres through a printer's own idea of
     * resolution, so a sheet that fitted on one printer came out cropped on the next — and the shop has
     * many sizes and no fixed one, so the size has to be described in the units printed on the box the
     * stickers came in.
     */
    public function test_the_label_is_measured_in_millimetres(): void
    {
        $source = File::get( resource_path( 'ts/pages/dashboard/products/ns-print-label.vue' ) );

        foreach ( [ "name: 'label_width'", "name: 'label_height'", "name: 'page_margin'", "name: 'font_size'" ] as $field ) {
            $this->assertStringContainsString( $field, $source, sprintf( '%s is missing', $field ) );
        }

        $this->assertStringNotContainsString(
            "name: 'document_size'",
            $source,
            'the paper is still described in pixels'
        );

        $this->assertStringContainsString(
            "+ 'mm'",
            $source,
            'the measurements never reach the page as millimetres'
        );

        /**
         * Without an @page rule the browser prints onto whatever it last used, and a 50mm sticker
         * lands in the corner of an A4 sheet.
         */
        $this->assertStringContainsString( '@page { size:', $source );

        /**
         * A roll printer holds one label per page. Treating it as a sheet prints the whole run onto the
         * first sticker.
         */
        $this->assertStringContainsString( 'page-break-after: always', $source );
    }

    /**
     * The sizes are saved on the server, not in the browser, so the branch is still set up for its own
     * printer tomorrow and on the till next to it.
     */
    public function test_the_page_saves_and_reloads_its_sizes_from_the_server(): void
    {
        $source = File::get( resource_path( 'ts/pages/dashboard/products/ns-print-label.vue' ) );

        $this->assertStringContainsString( "'/api/products/label-presets'", $source );
        $this->assertStringContainsString( "'/api/products/label-presets/select'", $source );
        $this->assertStringContainsString( 'loadPresets()', $source );
    }

    /**
     * Two things made the page look broken before anything was added to it: an empty dark rectangle
     * with no explanation, and a paper width of the string "px" whenever the size was left blank,
     * which is not a length and was dropped, leaving the sheet invisible.
     */
    public function test_the_preview_explains_itself_when_empty(): void
    {
        $source = File::get( resource_path( 'ts/pages/dashboard/products/ns-print-label.vue' ) );

        $this->assertStringContainsString( 'ยังไม่มีฉลากให้พิมพ์', $source );

        $this->assertStringNotContainsString(
            "( form.document_size + 'px' ) || 'auto'",
            $source,
            'an empty paper size still resolves to the invalid width "px"'
        );

        $this->assertStringContainsString(
            "window.addEventListener( 'load'",
            $source,
            'printing still fires while the document is parsing, so the sheet prints before its'
                . ' stylesheets and barcode images have loaded'
        );
    }

    /**
     * Drawing on demand covers correctness; this command exists so the first print of the day is not
     * the one waiting for thousands of images to be drawn one request at a time, and so the barcodes
     * that cannot be drawn at all are known before someone meets them at the printer.
     *
     * Scoped to its own branch, because the command otherwise walks the whole catalogue.
     */
    public function test_the_bulk_command_draws_what_is_missing_and_leaves_what_exists(): void
    {
        $branchId = $this->makeBranch();

        $missing = '9773' . random_int( 100000, 999999 );
        $present = '9774' . random_int( 100000, 999999 );

        $this->productWithBarcode( $missing, 'code128', $branchId );
        $this->productWithBarcode( $present, 'code128', $branchId );

        $missingPath = 'products/barcodes/' . $missing . '.png';
        $presentPath = 'products/barcodes/' . $present . '.png';

        $this->createdFiles[] = $missingPath;
        $this->createdFiles[] = $presentPath;

        Storage::disk( 'public' )->put( $presentPath, 'already-here' );

        $this->artisan( 'rgbf:barcodes-generate', [ '--branch' => $branchId ] )
            ->assertSuccessful();

        $this->assertTrue(
            Storage::disk( 'public' )->exists( $missingPath ),
            'the command did not draw the image that was missing'
        );

        $this->assertSame(
            'already-here',
            (string) Storage::disk( 'public' )->get( $presentPath ),
            'The command redrew an image that already existed. Labels already stuck on shelves have to'
                . ' keep matching their product.'
        );
    }

    public function test_the_bulk_command_writes_nothing_on_a_dry_run(): void
    {
        $branchId = $this->makeBranch();
        $barcode = '9775' . random_int( 100000, 999999 );

        $this->productWithBarcode( $barcode, 'code128', $branchId );

        $path = 'products/barcodes/' . $barcode . '.png';
        $this->createdFiles[] = $path;

        $this->artisan( 'rgbf:barcodes-generate', [ '--branch' => $branchId, '--dry-run' => true ] )
            ->assertSuccessful();

        $this->assertFalse(
            Storage::disk( 'public' )->exists( $path ),
            'a dry run wrote a file'
        );
    }

    // ------------------------------------------------------------------ helpers

    private function makeBranch(): int
    {
        $id = (int) DB::table( 'nexopos_branches' )->insertGetId( [
            'code' => 'LBL' . random_int( 100000, 999999 ),
            'name' => 'สาขาทดสอบฉลาก ' . random_int( 100, 999 ),
            'is_main' => false,
            'is_active' => true,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->createdRows[] = [ 'nexopos_branches', $id ];

        return $id;
    }

    private function productWithBarcode( string $barcode, string $barcodeType, ?int $branchId = null ): Product
    {
        $group = new UnitGroup;
        $group->name = 'label-' . random_int( 100000, 999999 );
        $group->author_id = 1;
        $group->save();

        $this->createdRows[] = [ 'nexopos_units_groups', $group->id ];

        $unit = new Unit;
        $unit->name = 'ชิ้นฉลาก ' . random_int( 100000, 999999 );
        $unit->identifier = 'label-' . random_int( 100000, 999999 );
        $unit->group_id = $group->id;
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->author_id = 1;
        $unit->save();

        $this->createdRows[] = [ 'nexopos_units', $unit->id ];

        $product = new Product;
        $product->stock_on_base_unit = false;
        $product->name = 'สินค้าทดสอบฉลาก ' . random_int( 100000, 999999 );
        $product->barcode = $barcode;
        $product->sku = 'label-' . random_int( 100000, 999999 );
        $product->barcode_type = $barcodeType;
        $product->unit_group = $group->id;
        $product->author_id = 1;
        $product->type = Product::TYPE_MATERIALIZED;
        $product->product_type = 'product';
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->tax_type = 'inclusive';
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->searchable = true;
        $product->save();

        $this->createdRows[] = [ 'nexopos_products', $product->id ];

        $row = new ProductUnitQuantity;
        $row->product_id = $product->id;
        $row->unit_id = $unit->id;
        $row->branch_id = $branchId;
        $row->barcode = $barcode;
        $row->quantity = 10;
        $row->sale_price = 25;
        $row->sale_price_edit = 25;
        $row->wholesale_price = 20;
        $row->cogs = 18;
        $row->type = 'product';
        $row->visible = true;
        $row->stock_alert_enabled = false;
        $row->low_quantity = 0;
        $row->save();

        $this->createdRows[] = [ 'nexopos_products_unit_quantities', $row->id ];

        return $product;
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
        }

        return $this->actor;
    }
}

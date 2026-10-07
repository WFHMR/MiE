<?php

namespace Tests\Feature;

use App\Services\BarcodeService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OptionalBarcodeImageTest extends TestCase
{
    public function test_missing_optional_barcodes_do_not_create_images_or_throw(): void
    {
        Storage::fake('public');
        foreach ([null, '', '  '] as $barcode) {
            app(BarcodeService::class)->generateBarcode($barcode, 'code128');
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_valid_barcode_still_creates_png(): void
    {
        Storage::fake('public');
        app(BarcodeService::class)->generateBarcode('8851234567890', 'code128');
        Storage::disk('public')->assertExists('products/barcodes/8851234567890.png');
        $this->assertStringStartsWith("\x89PNG", Storage::disk('public')->get('products/barcodes/8851234567890.png'));
    }

    public function test_invalid_nonempty_barcode_still_reports_validation_error(): void
    {
        Storage::fake('public');
        $this->expectException(\Exception::class);
        app(BarcodeService::class)->generateBarcode('INVALID', 'ean13');
    }
}

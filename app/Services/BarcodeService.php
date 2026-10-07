<?php

namespace App\Services;

use App\Classes\Hook;
use App\Models\Product;
use Exception;
use Faker\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Picqer\Barcode\BarcodeGeneratorPNG;

class BarcodeService
{
    const TYPE_EAN8 = 'ean8';

    const TYPE_EAN_8 = 'ean_8';

    const TYPE_EAN13 = 'ean13';

    const TYPE_EAN_13 = 'ean_13';

    const TYPE_CODABAR = 'codabar';

    const TYPE_CODE128 = 'code128';

    const TYPE_CODE_128 = 'code_128';

    const TYPE_CODE39 = 'code39';

    const TYPE_CODE_39 = 'code_39';

    const TYPE_CODE11 = 'code11';

    const TYPE_CODE_11 = 'code_11';

    const TYPE_UPCA = 'upca';

    const TYPE_UPC_A = 'upc_a';

    const TYPE_UPCE = 'upce';

    const TYPE_UPC_E = 'upc_e';

    /**
     * Will generate code
     * for provided barcode type
     */
    public function generate( string $type ): string
    {
        return $this->generateRandomBarcode( $type );
    }

    /**
     * Will generate a EAN8 code
     *
     * @return string generated code.
     */
    public function generateRandomBarcode( $code ): string
    {
        $factory = Factory::create();

        do {
            switch ( $code ) {
                case self::TYPE_EAN8:
                case self::TYPE_EAN_8:
                    $barcode = $factory->ean8();
                    break;
                case self::TYPE_EAN13:
                case self::TYPE_EAN_13:
                    $barcode = $factory->ean13();
                    break;
                case self::TYPE_CODABAR:
                case self::TYPE_CODE128:
                case self::TYPE_CODE_128:
                case self::TYPE_CODE39:
                case self::TYPE_CODE_39:
                    $barcode = Str::random( 10 );
                    break;
                case self::TYPE_CODE11:
                case self::TYPE_CODE_11:
                    $barcode = rand( 1000000000, 999999999 );
                    break;
                case self::TYPE_UPCA:
                case self::TYPE_UPC_A:
                    $barcode = rand( 10000000000, 99999999999 );
                    break;
                case self::TYPE_UPCE:
                case self::TYPE_UPC_E:
                    $barcode = rand( 1000000, 9999999 );
                    break;
                default:
                    $barcode = $factory->isbn10();
                    break;
            }

            $product = Product::where( 'barcode', $barcode )->first();
        } while ( $product instanceof Product );

        return $barcode;
    }

    public function getBarcodeInBase64( $barcode, $type, $height = 30 )
    {
        $generator = new BarcodeGeneratorPNG;

        switch ( $type ) {
            case self::TYPE_EAN8:
            case self::TYPE_EAN_8:
                $realType = $generator::TYPE_EAN_8;
                break;
            case self::TYPE_EAN13:
            case self::TYPE_EAN_13:  $realType = $generator::TYPE_EAN_13;
                break;
            case self::TYPE_CODABAR: $realType = $generator::TYPE_CODABAR;
                break;
            case self::TYPE_CODE128:
            case self::TYPE_CODE_128: $realType = $generator::TYPE_CODE_128;
                break;
            case self::TYPE_CODE39:
            case self::TYPE_CODE_39: $realType = $generator::TYPE_CODE_39;
                break;
            case self::TYPE_CODE11:
            case self::TYPE_CODE_11: $realType = $generator::TYPE_CODE_11;
                break;
            case self::TYPE_UPCA:
            case self::TYPE_UPC_A: $realType = $generator::TYPE_UPC_A;
                break;
            case self::TYPE_UPCE:
            case self::TYPE_UPC_E: $realType = $generator::TYPE_UPC_E;
                break;
            default: $realType = $generator::TYPE_CODE_128;
                break;
        }

        return base64_encode( $generator->getBarcode( $barcode, $realType, 3, $height ) );
    }

    /**
     * generate barcode using a code and a code type
     *
     * @param  string $barcode
     * @param  string $type
     * @return void
     */
    public function generateBarcode( $barcode, $type )
    {
        // A unit may intentionally have no barcode. There is no image to generate.
        if ( $barcode === null || trim( (string) $barcode ) === '' ) {
            return;
        }

        $generator = new BarcodeGeneratorPNG;

        switch ( $type ) {
            case self::TYPE_EAN8:
            case self::TYPE_EAN_8:
                $realType = $generator::TYPE_EAN_8;
                break;
            case self::TYPE_EAN13:
            case self::TYPE_EAN_13:  $realType = $generator::TYPE_EAN_13;
                break;
            case self::TYPE_CODABAR: $realType = $generator::TYPE_CODABAR;
                break;
            case self::TYPE_CODE128:
            case self::TYPE_CODE_128: $realType = $generator::TYPE_CODE_128;
                break;
            case self::TYPE_CODE39:
            case self::TYPE_CODE_39: $realType = $generator::TYPE_CODE_39;
                break;
            case self::TYPE_CODE11:
            case self::TYPE_CODE_11: $realType = $generator::TYPE_CODE_11;
                break;
            case self::TYPE_UPCA:
            case self::TYPE_UPC_A: $realType = $generator::TYPE_UPC_A;
                break;
            case self::TYPE_UPCE:
            case self::TYPE_UPC_E: $realType = $generator::TYPE_UPC_E;
                break;
            default: $realType = $generator::TYPE_CODE_128;
                break;
        }

        try {
            Storage::disk( 'public' )->put(
                Hook::filter( 'ns-media-path', 'products/barcodes/' . $barcode . '.png' ),
                $generator->getBarcode( $barcode, $realType, 3, 30 )
            );
        } catch ( Exception $exception ) {
            $insight = ( $exception->getMessage() ?: __( 'N/A' ) );

            throw new Exception(
                sprintf(
                    __( 'An error has occurred while creating a barcode "%s" using the type "%s" for the product. Make sure the barcode value is correct for the barcode type selected. Additional insight : %s' ),
                    $barcode,
                    $realType,
                    $insight
                )
            );
        }
    }

    /**
     * Where the image for a barcode is kept on the public disk.
     */
    public function imagePathFor( string $barcode ): string
    {
        return Hook::filter( 'ns-media-path', 'products/barcodes/' . $barcode . '.png' );
    }

    /**
     * Makes sure an image exists for this barcode, and reports whether one does.
     *
     * Shared by the label page, which draws an image the first time it is asked for, and by
     * rgbf:barcodes-generate, which draws them in bulk. Both need the same two things and getting
     * them to disagree would mean the labels printed from a warmed catalogue differ from the ones
     * printed cold.
     *
     * An existing image is never redrawn: thousands were written by the product form over months and
     * a label already stuck on a shelf has to keep matching its product.
     *
     * When the type a product declares cannot encode its barcode — an EAN-13 product carrying nine
     * digits, which this catalogue has — the code is drawn as CODE-128 instead, which encodes
     * anything printable. Refusing would leave the label with a broken image, which is worse than a
     * scannable code in a different symbology.
     */
    public function ensureImage( string $barcode, ?string $type = null ): bool
    {
        if ( $barcode === '' ) {
            return false;
        }

        $path = $this->imagePathFor( $barcode );
        $disk = Storage::disk( 'public' );

        if ( $disk->exists( $path ) ) {
            return true;
        }

        foreach ( [ $type, self::TYPE_CODE128 ] as $attempt ) {
            if ( $attempt === null ) {
                continue;
            }

            try {
                $this->generateBarcode( $barcode, $attempt );

                if ( $disk->exists( $path ) ) {
                    return true;
                }
            } catch ( \Throwable ) {
                continue;
            }
        }

        return false;
    }
}

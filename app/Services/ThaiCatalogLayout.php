<?php

namespace App\Services;

/**
 * Works out which column holds what in the shop's product workbook, by reading the header row.
 *
 * Both commands that read this workbook used hard coded column numbers, and both were wrong. The file
 * the staff actually upload has 13 columns with the barcode second; the numbers in the code described a
 * wider layout with the barcode third, the cost at 13 and the price at 14 — positions that do not exist
 * in the real file. The importer therefore read the name where it expected a barcode and read nothing
 * where it expected prices. That is why all 2,644 products carry an empty description, and it is why
 * running that importer again would have written empty or shifted values over live prices.
 *
 * Moving the numbers would fix today's file and break the next one. So nothing is assumed: the header is
 * read, each field is matched by the words in it, and a file whose header cannot be understood is
 * refused rather than guessed at. A refusal costs someone five minutes; a silent misread costs the shop
 * its price list.
 */
class ThaiCatalogLayout
{
    public const STOCK_CODE = 'stock_code';

    public const BARCODE = 'barcode';

    public const NAME = 'name';

    public const PACKING = 'packing';

    public const SALE_UNIT = 'sale_unit';

    public const CATEGORY_MAIN = 'category_main';

    public const CATEGORY_SUB = 'category_sub';

    public const SUPPLIER_CODE = 'supplier_code';

    public const SUPPLIER_NAME = 'supplier_name';

    public const COST = 'cost';

    public const PRICE = 'price';

    public const QUANTITY = 'quantity';

    /**
     * Every field that must be present for a catalogue import to be safe. The supplier backfill needs
     * far less, so it asks for its own shorter list.
     *
     * @var array<int, string>
     */
    public const REQUIRED_FOR_IMPORT = [
        self::BARCODE,
        self::NAME,
        self::SALE_UNIT,
        self::CATEGORY_MAIN,
        self::COST,
        self::PRICE,
    ];

    /**
     * @var array<int, string>
     */
    public const REQUIRED_FOR_SUPPLIERS = [
        self::BARCODE,
        self::NAME,
        self::SUPPLIER_NAME,
    ];

    /**
     * Each field and the words that identify its column.
     *
     * Order matters. `หน่วยขาย` would also answer to `หน่วย`, and `ขาย (VAT)` would also answer to
     * `ขาย`, so the more specific patterns are listed first and a column is only claimed once.
     *
     * @var array<string, array<int, string>>
     */
    private const PATTERNS = [
        self::BARCODE => [ 'บารโค' ],
        self::STOCK_CODE => [ 'รหสสตอก', 'รหสสนคา' ],
        self::NAME => [ 'ชอ/รายละเอยด', 'รายละเอยดสนคา' ],
        self::SALE_UNIT => [ 'หนวยขาย' ],
        self::PACKING => [ 'บรรจ' ],
        self::CATEGORY_MAIN => [ 'กลมหลก' ],
        self::CATEGORY_SUB => [ 'ยอย' ],
        self::SUPPLIER_CODE => [ 'ผจำหนาย' ],
        self::SUPPLIER_NAME => [ 'ชอ/บรษท', 'บรษท' ],
        self::COST => [ 'ราคาทน' ],
        self::PRICE => [ 'ขาย(vat)', 'ราคาขาย' ],
        self::QUANTITY => [ 'จำนวน', 'คงเหลอ' ],
    ];

    /**
     * @param array<string, int> $columns
     */
    private function __construct(
        private array $columns,
        private int $headerRowNumber
    ) {}

    /**
     * Finds the header among the first few rows, because the workbook opens with a title line before it.
     *
     * @param array<int, array<int, string|null>> $rows
     */
    public static function detect( array $rows ): self
    {
        $best = [];
        $bestRow = 0;

        foreach ( array_slice( $rows, 0, 10, true ) as $number => $row ) {
            $found = self::matchRow( (array) $row );

            if ( count( $found ) > count( $best ) ) {
                $best = $found;
                $bestRow = $number + 1;
            }
        }

        return new self( $best, $bestRow );
    }

    /**
     * @param  array<int, string|null> $row
     * @return array<string, int>
     */
    private static function matchRow( array $row ): array
    {
        $normalised = [];

        foreach ( $row as $index => $value ) {
            $normalised[ $index ] = self::normalise( (string) ( $value ?? '' ) );
        }

        $columns = [];
        $claimed = [];

        foreach ( self::PATTERNS as $field => $needles ) {
            foreach ( $needles as $needle ) {
                foreach ( $normalised as $index => $heading ) {
                    if ( $heading === '' || isset( $claimed[ $index ] ) ) {
                        continue;
                    }

                    if ( str_contains( $heading, $needle ) ) {
                        $columns[ $field ] = $index;
                        $claimed[ $index ] = true;

                        continue 3;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Thai tone marks and vowel signs are stripped, and spaces removed.
     *
     * The real file spells one heading `ย่่อย`, with the tone mark typed twice. Comparing the raw text
     * would miss it, and a missed sub category column is the kind of thing nobody notices until the
     * data is already wrong.
     */
    private static function normalise( string $value ): string
    {
        $value = mb_strtolower( trim( $value ) );

        /**
         * U+0E31, U+0E34–U+0E3A and U+0E47–U+0E4E: the marks that sit above and below the consonants.
         */
        $value = (string) preg_replace( '/[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]/u', '', $value );

        return (string) preg_replace( '/\s+/u', '', $value );
    }

    public function indexOf( string $field ): ?int
    {
        return $this->columns[ $field ] ?? null;
    }

    /**
     * @param array<int, string|null> $row
     */
    public function value( array $row, string $field ): string
    {
        $index = $this->indexOf( $field );

        if ( $index === null ) {
            return '';
        }

        return trim( (string) ( $row[ $index ] ?? '' ) );
    }

    /**
     * @param  array<int, string> $required
     * @return array<int, string> the fields that could not be located
     */
    public function missing( array $required ): array
    {
        return array_values( array_filter(
            $required,
            fn ( string $field ) => ! isset( $this->columns[ $field ] )
        ) );
    }

    public function headerRowNumber(): int
    {
        return $this->headerRowNumber;
    }

    /**
     * @return array<string, int>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Readable enough to paste into a message to whoever produced the file.
     */
    public function describe(): string
    {
        $parts = [];

        foreach ( $this->columns as $field => $index ) {
            $parts[] = $field . '=' . $index;
        }

        return implode( ', ', $parts );
    }
}

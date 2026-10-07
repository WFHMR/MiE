<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;

/**
 * The sticker sizes the label page prints on, kept where every till can reach them.
 *
 * The page used to describe a label in pixels, held only in the browser, and forgotten on reload. A
 * sticker is a physical object — 50 by 25 millimetres, three across a sheet — and pixels only become
 * millimetres through a printer's own idea of resolution, so a page that fitted yesterday would come
 * out cropped on the next printer. Everything here is in millimetres, which is what CSS puts on paper
 * unchanged.
 *
 * Sizes are shared and each branch remembers the one it uses. The shop has many sticker sizes and no
 * fixed one, so a single default is useless: the head office defines the sizes once and each branch
 * picks the one loaded in its own printer, which stays picked between sessions and between devices.
 */
class LabelPresetService
{
    const PRESETS_OPTION = 'ns_label_presets';

    const SELECTION_OPTION = 'ns_label_preset_selection';

    const MAX_PRESETS = 40;

    /**
     * Bounds, not opinions. These figures go straight into print CSS, so a negative width or a
     * thousand columns has to be refused rather than rendered.
     *
     * @var array<string, array{0: float, 1: float, 2: float}> field => [ min, max, default ]
     */
    const NUMERIC_FIELDS = [
        'label_width' => [ 10, 300, 50 ],
        'label_height' => [ 8, 300, 25 ],
        'columns' => [ 1, 12, 3 ],
        'gap_x' => [ 0, 50, 2 ],
        'gap_y' => [ 0, 50, 2 ],
        'page_margin' => [ 0, 50, 5 ],
        'barcode_height' => [ 4, 100, 12 ],
        'font_size' => [ 4, 24, 8 ],
        'paper_width' => [ 20, 1000, 210 ],
        'paper_height' => [ 20, 2000, 297 ],
    ];

    const PAPER_SIZES = [ 'a4', 'a5', 'letter', 'roll', 'custom' ];

    const VISIBILITY_FIELDS = [
        'show_store_name',
        'show_product_name',
        'show_barcode_text',
        'show_product_price',
    ];

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $settings = [];

        foreach ( self::NUMERIC_FIELDS as $field => [ , , $default ] ) {
            $settings[ $field ] = $default;
        }

        $settings[ 'paper' ] = 'a4';

        foreach ( self::VISIBILITY_FIELDS as $field ) {
            $settings[ $field ] = true;
        }

        return $settings;
    }

    /**
     * @return list<array{name: string, settings: array<string, mixed>}>
     */
    public function all(): array
    {
        $stored = ns()->option->get( self::PRESETS_OPTION );

        if ( is_string( $stored ) ) {
            $stored = json_decode( $stored, true );
        }

        if ( ! is_array( $stored ) ) {
            return [];
        }

        $presets = [];

        foreach ( $stored as $preset ) {
            if ( ! is_array( $preset ) || ! isset( $preset[ 'name' ] ) ) {
                continue;
            }

            $presets[] = [
                'name' => (string) $preset[ 'name' ],
                'settings' => $this->sanitize( is_array( $preset[ 'settings' ] ?? null ) ? $preset[ 'settings' ] : [] ),
            ];
        }

        return $presets;
    }

    /**
     * @return array{name: string, settings: array<string, mixed>}|null
     */
    public function find( string $name ): ?array
    {
        foreach ( $this->all() as $preset ) {
            if ( $preset[ 'name' ] === $name ) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Saves under a name and hands it to the branch that saved it.
     *
     * Saving over an existing name replaces it, which is how a size gets corrected once rather than
     * accumulating "50x25", "50x25 new", "50x25 real".
     *
     * @param  array<string, mixed> $settings
     * @return array{name: string, settings: array<string, mixed>}
     */
    public function save( string $name, array $settings, ?int $branchId = null ): array
    {
        $name = trim( $name );

        if ( $name === '' ) {
            throw new NotAllowedException( __( 'ต้องตั้งชื่อขนาดฉลากก่อนบันทึก' ) );
        }

        if ( mb_strlen( $name ) > 60 ) {
            throw new NotAllowedException( __( 'ชื่อขนาดฉลากยาวเกินไป' ) );
        }

        $presets = $this->all();
        $sanitized = $this->sanitize( $settings );
        $replaced = false;

        foreach ( $presets as $index => $preset ) {
            if ( $preset[ 'name' ] === $name ) {
                $presets[ $index ][ 'settings' ] = $sanitized;
                $replaced = true;

                break;
            }
        }

        if ( ! $replaced ) {
            if ( count( $presets ) >= self::MAX_PRESETS ) {
                throw new NotAllowedException( sprintf(
                    __( 'เก็บขนาดฉลากได้ไม่เกิน %d แบบ ลบแบบที่ไม่ใช้ก่อน' ),
                    self::MAX_PRESETS
                ) );
            }

            $presets[] = [ 'name' => $name, 'settings' => $sanitized ];
        }

        ns()->option->set( self::PRESETS_OPTION, json_encode( $presets, JSON_UNESCAPED_UNICODE ) );

        $this->select( $name, $branchId );

        return [ 'name' => $name, 'settings' => $sanitized ];
    }

    public function delete( string $name, ?int $branchId = null ): void
    {
        $presets = array_values( array_filter(
            $this->all(),
            fn ( array $preset ): bool => $preset[ 'name' ] !== $name
        ) );

        ns()->option->set( self::PRESETS_OPTION, json_encode( $presets, JSON_UNESCAPED_UNICODE ) );

        /**
         * Any branch pointing at the deleted size is released, or it would keep asking for a size that
         * no longer exists and silently fall back to the defaults with no explanation.
         */
        $selection = $this->selection();

        foreach ( $selection as $branch => $selected ) {
            if ( $selected === $name ) {
                unset( $selection[ $branch ] );
            }
        }

        ns()->option->set( self::SELECTION_OPTION, json_encode( $selection, JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * @return array{name: string, settings: array<string, mixed>}
     */
    public function select( string $name, ?int $branchId = null ): array
    {
        $preset = $this->find( $name );

        if ( $preset === null ) {
            throw new NotAllowedException( __( 'ไม่พบขนาดฉลากที่เลือก' ) );
        }

        $selection = $this->selection();
        $selection[ $this->branchKey( $branchId ) ] = $name;

        ns()->option->set( self::SELECTION_OPTION, json_encode( $selection, JSON_UNESCAPED_UNICODE ) );

        return $preset;
    }

    /**
     * The size this branch prints on, or the defaults when it has never chosen one.
     *
     * @return array{name: string|null, settings: array<string, mixed>}
     */
    public function selectedFor( ?int $branchId = null ): array
    {
        $selection = $this->selection();
        $name = $selection[ $this->branchKey( $branchId ) ] ?? null;

        if ( $name !== null ) {
            $preset = $this->find( (string) $name );

            if ( $preset !== null ) {
                return $preset;
            }
        }

        return [ 'name' => null, 'settings' => $this->defaults() ];
    }

    /**
     * Every figure clamped into range and every unknown key dropped, because these values are written
     * into print CSS and read back as lengths.
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function sanitize( array $settings ): array
    {
        $clean = $this->defaults();

        foreach ( self::NUMERIC_FIELDS as $field => [ $min, $max, $default ] ) {
            if ( ! array_key_exists( $field, $settings ) ) {
                continue;
            }

            $value = $settings[ $field ];

            if ( ! is_numeric( $value ) ) {
                continue;
            }

            $value = (float) $value;

            /**
             * Clamped rather than refused. A figure typed one digit too long should print on the
             * largest sheet the page can describe, not stop the operator with a validation error while
             * they are standing at a printer.
             */
            $clean[ $field ] = (float) min( $max, max( $min, $value ) );

            if ( $field === 'columns' ) {
                $clean[ $field ] = (int) round( $clean[ $field ] );
            }
        }

        $paper = is_string( $settings[ 'paper' ] ?? null ) ? strtolower( $settings[ 'paper' ] ) : null;
        $clean[ 'paper' ] = in_array( $paper, self::PAPER_SIZES, true ) ? $paper : 'a4';

        foreach ( self::VISIBILITY_FIELDS as $field ) {
            if ( array_key_exists( $field, $settings ) ) {
                $clean[ $field ] = filter_var( $settings[ $field ], FILTER_VALIDATE_BOOLEAN );
            }
        }

        return $clean;
    }

    /**
     * @return array<string, string>
     */
    private function selection(): array
    {
        $stored = ns()->option->get( self::SELECTION_OPTION );

        if ( is_string( $stored ) ) {
            $stored = json_decode( $stored, true );
        }

        if ( ! is_array( $stored ) ) {
            return [];
        }

        $selection = [];

        foreach ( $stored as $branch => $name ) {
            if ( is_string( $name ) || is_numeric( $name ) ) {
                $selection[ (string) $branch ] = (string) $name;
            }
        }

        return $selection;
    }

    /**
     * A branchless install, and the administrator who has no branch of their own, share one slot.
     */
    private function branchKey( ?int $branchId ): string
    {
        return (string) ( $branchId ?: 0 );
    }
}

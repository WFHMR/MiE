<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the Thai translation file against the mistakes that turn a translation into a broken
 * screen rather than a wrong word.
 *
 * A message like "Remaining %s" is passed through sprintf with the remaining quantity. Drop the
 * %s while translating and the number never appears; add a second one and sprintf raises
 * ArgumentCountError, which in the till means a blank screen in front of a queue. The same is
 * true of the :attribute placeholders Laravel's validator fills in.
 *
 * These checks are cheap and they run over the whole file, so a mistake in any one of several
 * thousand strings is caught before it reaches a counter.
 */
class ThaiTranslationIntegrityTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function translations(): array
    {
        $path = base_path( 'lang/th.json' );

        $this->assertFileExists( $path );

        $decoded = json_decode( file_get_contents( $path ), true );

        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            'lang/th.json must be valid JSON, otherwise every translated string falls back to English at once: ' . json_last_error_msg()
        );

        $this->assertIsArray( $decoded );

        return $decoded;
    }

    public function test_the_file_is_valid_json(): void
    {
        $this->assertNotEmpty( $this->translations() );
    }

    /**
     * sprintf placeholders have to survive translation exactly, in kind and in number.
     */
    public function test_printf_placeholders_are_preserved(): void
    {
        $offenders = [];

        foreach ( $this->translations() as $key => $value ) {
            if ( ! is_string( $value ) || $value === $key ) {
                continue;
            }

            $expected = $this->printfPlaceholders( $key );
            $actual = $this->printfPlaceholders( $value );

            if ( $expected !== $actual ) {
                $offenders[] = sprintf(
                    '%s => %s (expected %s, found %s)',
                    $key,
                    $value,
                    $expected === [] ? 'none' : implode( ',', $expected ),
                    $actual === [] ? 'none' : implode( ',', $actual )
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A translated string must carry the same sprintf placeholders as its source:\n" . implode( "\n", array_slice( $offenders, 0, 30 ) )
        );
    }

    /**
     * Validation messages are filled in by name, so :attribute has to stay spelled as it was.
     */
    public function test_named_placeholders_are_preserved(): void
    {
        $offenders = [];

        foreach ( $this->translations() as $key => $value ) {
            if ( ! is_string( $value ) || $value === $key ) {
                continue;
            }

            $expected = $this->namedPlaceholders( $key );
            $actual = $this->namedPlaceholders( $value );

            if ( $expected !== $actual ) {
                $offenders[] = sprintf( '%s => %s', $key, $value );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A translated string must keep the same named placeholders as its source:\n" . implode( "\n", array_slice( $offenders, 0, 30 ) )
        );
    }

    /**
     * The front end fills {amount}, {product} and friends by name. A translated brace that has
     * been renamed or dropped is printed to the screen literally, so the cashier reads
     * "ยอด {amount}" instead of a figure.
     */
    public function test_brace_placeholders_are_preserved(): void
    {
        $offenders = [];

        foreach ( $this->translations() as $key => $value ) {
            if ( ! is_string( $value ) || $value === $key ) {
                continue;
            }

            $expected = $this->bracePlaceholders( $key );
            $actual = $this->bracePlaceholders( $value );

            if ( $expected !== $actual ) {
                $offenders[] = sprintf(
                    '%s => %s (expected %s, found %s)',
                    $key,
                    $value,
                    $expected === [] ? 'none' : implode( ',', $expected ),
                    $actual === [] ? 'none' : implode( ',', $actual )
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A translated string must keep the same brace placeholders as its source:\n" . implode( "\n", array_slice( $offenders, 0, 30 ) )
        );
    }

    /**
     * A handful of strings are pipe separated lists read by position, such as the units of time
     * used by the relative date formatter. Losing a segment shifts every later one, so "minute"
     * starts being rendered where "hour" belongs.
     */
    public function test_pipe_separated_lists_keep_their_number_of_segments(): void
    {
        $offenders = [];

        foreach ( $this->translations() as $key => $value ) {
            if ( ! is_string( $value ) || $value === $key || ! str_contains( $key, '|' ) ) {
                continue;
            }

            $expected = count( explode( '|', $key ) );
            $actual = count( explode( '|', $value ) );

            if ( $expected !== $actual ) {
                $offenders[] = sprintf( '%s => %s (expected %d segments, found %d)', $key, $value, $expected, $actual );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A pipe separated list is read by position, so the count has to match:\n" . implode( "\n", $offenders )
        );
    }

    /**
     * An empty translation is worse than none: Laravel returns the empty string, so the label
     * vanishes from the screen instead of falling back to English.
     */
    public function test_no_translation_is_blank(): void
    {
        $blank = [];

        foreach ( $this->translations() as $key => $value ) {
            if ( ! is_string( $value ) || trim( $value ) === '' ) {
                $blank[] = $key;
            }
        }

        $this->assertSame(
            [],
            $blank,
            "A blank translation makes the label disappear:\n" . implode( "\n", array_slice( $blank, 0, 30 ) )
        );
    }

    /**
     * The same key written twice is accepted by the parser, which keeps the last one and throws
     * the other away without a word. Whichever translation was meant to apply is then a matter of
     * which line came last in the file, so the file itself has to be checked, not the parsed array.
     *
     * Keys differing only by case are fine: PHP array keys are case sensitive, so "Type" and
     * "type" are two separate strings and both survive.
     */
    public function test_no_key_appears_twice_in_the_file(): void
    {
        $lines = file( base_path( 'lang/th.json' ), FILE_IGNORE_NEW_LINES );

        $seen = [];
        $duplicates = [];

        foreach ( $lines as $number => $line ) {
            if ( preg_match( '/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/', $line, $match ) !== 1 ) {
                continue;
            }

            $key = $match[ 1 ];

            if ( isset( $seen[ $key ] ) ) {
                $duplicates[] = sprintf( 'line %d repeats the key first seen on line %d: %s', $number + 1, $seen[ $key ], $key );

                continue;
            }

            $seen[ $key ] = $number + 1;
        }

        $this->assertSame(
            [],
            $duplicates,
            "A repeated key means one of the two translations is silently discarded:\n" . implode( "\n", array_slice( $duplicates, 0, 30 ) )
        );
    }

    /**
     * Thai has no word spacing, so a translation that is still pure ASCII is either untranslated
     * or a copy of the source. Those are reported as a count rather than a failure, because the
     * file is being translated in passes and some strings are the same in both languages.
     */
    public function test_reports_how_much_is_still_english(): void
    {
        $translations = $this->translations();
        $untranslated = 0;

        foreach ( $translations as $key => $value ) {
            if ( is_string( $value ) && $value === $key ) {
                $untranslated++;
            }
        }

        $total = count( $translations );

        /**
         * A ratchet, not a target. It only has to be moved down as passes complete, and it fails
         * if a later change puts strings back into English.
         *
         * The 24 that remain are deliberately identical to their source: barcode symbologies
         * (EAN 8, EAN 13, Codabar, Code 11/39/128, UPC A, UPC E), tax abbreviations (GST, SGST,
         * CGST), the product name "My NexoPOS", the bare tokens "Uuid", "ISO", "Token", "Tokens",
         * "#", "%s", "%s — %s" and "{module}", and four keys whose source string is already Thai.
         */
        $this->assertLessThanOrEqual(
            24,
            $untranslated,
            'Strings reverted to English. Translated: ' . ( $total - $untranslated ) . " of {$total}."
        );
    }

    /**
     * @return list<string>
     */
    private function printfPlaceholders( string $text ): array
    {
        preg_match_all( '/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/', $text, $matches );

        $found = array_values( array_filter( $matches[ 0 ], static fn ( string $p ): bool => $p !== '%%' ) );

        sort( $found );

        return $found;
    }

    /**
     * @return list<string>
     */
    private function bracePlaceholders( string $text ): array
    {
        preg_match_all( '/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $text, $matches );

        $found = $matches[ 0 ];

        sort( $found );

        return $found;
    }

    /**
     * @return list<string>
     */
    private function namedPlaceholders( string $text ): array
    {
        preg_match_all( '/:[a-zA-Z_][a-zA-Z0-9_]*/', $text, $matches );

        $found = $matches[ 0 ];

        sort( $found );

        return $found;
    }

    /**
     * The frontend finds th.json under a namespace, and two files have to agree on its name.
     *
     * The blade layout writes `window.ns.langFiles = { "<name>": ".../lang/th.json" }` and the
     * frontend looks the entries up under CORE_LANG_NAMESPACE. If the two ever differ, __() asks for
     * a namespace that was never loaded, gets nothing, and returns the English key it was handed —
     * for every string on every screen, with no error raised anywhere. The screens simply come up in
     * English, which is the kind of fault that gets reported as "the system changed language by
     * itself".
     *
     * That risk is why the name was left as the upstream project's for so long. It is `core` now, so
     * this reads both files and holds them to each other rather than relying on whoever edits one
     * remembering the other.
     */
    public function test_the_frontend_and_the_layout_agree_on_the_core_translation_namespace(): void
    {
        $layout = base_path( 'resources/views/layout/_header-script.blade.php' );
        $library = base_path( 'resources/ts/libraries/lang.ts' );

        $this->assertFileExists( $layout );
        $this->assertFileExists( $library );

        $this->assertSame(
            1,
            preg_match(
                '/[\'"]([A-Za-z0-9_.-]+)[\'"]\s*=>\s*asset\(\s*[\'"]\/lang\//',
                (string) file_get_contents( $layout ),
                $registered
            ),
            'The layout no longer registers a core translation file through ns.langFiles, so the'
                . ' frontend has nothing to read Thai from.'
        );

        $this->assertSame(
            1,
            preg_match(
                '/CORE_LANG_NAMESPACE\s*=\s*[\'"]([A-Za-z0-9_.-]+)[\'"]/',
                (string) file_get_contents( $library ),
                $expected
            ),
            'CORE_LANG_NAMESPACE is gone from resources/ts/libraries/lang.ts.'
        );

        $this->assertSame(
            $expected[ 1 ],
            $registered[ 1 ],
            sprintf(
                'The layout registers the core translations under "%s" while the frontend reads them'
                    . ' under "%s". Nothing errors when these disagree — every screen just falls back'
                    . ' to English.',
                $registered[ 1 ],
                $expected[ 1 ]
            )
        );

        $this->assertStringNotContainsString(
            'NexoPOS',
            $registered[ 1 ],
            'The namespace written into the page source is the upstream product name again.'
        );
    }
}

<?php

namespace App\Services;

use Exception;

/**
 * Reads and writes individual keys of a .env file.
 *
 * The previous implementation parsed the file into a key/value map and rewrote
 * the whole file from that map on every set(). Two things went wrong with it:
 *
 * 1. Values were split with explode( '=', $line ) and only index 1 was kept, so
 *    anything containing an equals sign lost everything after the first one.
 *    APP_KEY is base64 and routinely ends with "=" padding, which meant a single
 *    set() call silently corrupted the application key.
 * 2. Comments and blank lines were dropped while reading, so rewriting one key
 *    erased every comment in the file.
 *
 * Editing the raw lines in place avoids both problems and keeps the file readable.
 */
class EnvEditor
{
    private string $env_file_path;

    /**
     * @var array<int, string>
     */
    private array $lines = [];

    public function __construct( string $env_file_path )
    {
        $this->env_file_path = $env_file_path;
        $this->lines = $this->readLines();
    }

    /**
     * @return array<int, string>
     */
    private function readLines(): array
    {
        if ( ! is_file( $this->env_file_path ) ) {
            return [];
        }

        $contents = file_get_contents( $this->env_file_path );

        if ( $contents === false || $contents === '' ) {
            return [];
        }

        return explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $contents ) );
    }

    /**
     * Returns the parsed key/value pairs of a .env file.
     *
     * Kept for backward compatibility with callers that used it directly.
     *
     * @return array<string, string|null>
     */
    public function read( $filePath )
    {
        $result = [];

        if ( ! is_file( $filePath ) ) {
            return $result;
        }

        $contents = file_get_contents( $filePath );

        if ( $contents === false ) {
            return $result;
        }

        foreach ( explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $contents ) ) as $line ) {
            $key = $this->keyOf( $line );

            if ( $key === null ) {
                continue;
            }

            $result[ $key ] = $this->valueOf( $line );
        }

        return $result;
    }

    /**
     * Returns the key a raw line assigns, or null when the line is a comment,
     * blank, or otherwise not an assignment.
     */
    private function keyOf( string $line ): ?string
    {
        $trimmed = trim( $line );

        if ( $trimmed === '' || str_starts_with( $trimmed, '#' ) ) {
            return null;
        }

        if ( ! str_contains( $trimmed, '=' ) ) {
            return null;
        }

        $key = trim( explode( '=', $trimmed, 2 )[ 0 ] );

        return preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key ) === 1 ? $key : null;
    }

    /**
     * Extracts the value a raw line assigns, unquoted and without any trailing
     * inline comment. Mirrors how Dotenv reads the same line.
     */
    private function valueOf( string $line ): string
    {
        $parts = explode( '=', trim( $line ), 2 );
        $raw = trim( $parts[ 1 ] ?? '' );

        if ( $raw === '' ) {
            return '';
        }

        $quote = $raw[ 0 ];

        if ( $quote === '"' || $quote === "'" ) {
            $closing = strpos( $raw, $quote, 1 );

            if ( $closing !== false ) {
                return stripcslashes( substr( $raw, 1, $closing - 1 ) );
            }

            return stripcslashes( substr( $raw, 1 ) );
        }

        /**
         * Unquoted values end at the first inline comment.
         */
        $hash = strpos( $raw, ' #' );

        return $hash === false ? $raw : rtrim( substr( $raw, 0, $hash ) );
    }

    public function get( $key, $default = null )
    {
        foreach ( $this->lines as $line ) {
            if ( $this->keyOf( $line ) === $key ) {
                return $this->valueOf( $line );
            }
        }

        return $default;
    }

    public function delete( $key )
    {
        $this->lines = array_values( array_filter(
            $this->lines,
            fn ( string $line ): bool => $this->keyOf( $line ) !== $key
        ) );

        $this->write();
    }

    public function set( $key, $value, $quoted = true )
    {
        if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*$/', (string) $key ) ) {
            throw new Exception( 'Invalid key format' );
        }

        $line = $key . '=' . $this->formatValue( $value, (bool) $quoted );
        $replaced = false;

        foreach ( $this->lines as $index => $existing ) {
            if ( $this->keyOf( $existing ) !== $key ) {
                continue;
            }

            $this->lines[ $index ] = $line;
            $replaced = true;
            break;
        }

        if ( ! $replaced ) {
            $this->lines[] = $line;
        }

        $this->write();
    }

    private function formatValue( mixed $value, bool $quoted ): string
    {
        if ( ! is_numeric( $value ) && ! is_string( $value ) ) {
            return '""';
        }

        /**
         * Newlines would break the file into two assignments.
         */
        $value = str_replace( [ "\r", "\n" ], '', (string) $value );

        if ( is_numeric( $value ) && ! $quoted ) {
            return $value;
        }

        if ( ! $quoted ) {
            return $value;
        }

        /**
         * Only the backslash and the double quote need escaping inside a double
         * quoted value. addslashes() also escaped single quotes, which then
         * appeared literally in the value once Dotenv read it back.
         */
        return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
    }

    public function has( $key )
    {
        foreach ( $this->lines as $line ) {
            if ( $this->keyOf( $line ) === $key ) {
                return true;
            }
        }

        return false;
    }

    private function write()
    {
        $contents = implode( "\n", $this->lines );

        if ( ! str_ends_with( $contents, "\n" ) ) {
            $contents .= "\n";
        }

        file_put_contents( $this->env_file_path, $contents );
    }
}

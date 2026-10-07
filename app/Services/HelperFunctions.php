<?php

use App\Services\CoreService;

if ( ! function_exists( 'array_insert' ) ) {
    /**
     * Insert an array into another array before/after a certain key
     *
     * Merge the elements of the $array array after, or before, the designated $key from the $input array.
     * It returns the resulting array.
     *
     * @param  array  $input  The input array.
     * @param  mixed  $insert The value to merge.
     * @param  mixed  $key    The key from the $input to merge $insert next to.
     * @param  string $pos    Wether to splice $insert before or after the $key.
     * @return array  Returns the resulting array.
     */
    function array_insert( array $input, $insert, $key, $pos = 'after' )
    {
        if ( ! is_string( $key ) && ! is_int( $key ) ) {
            trigger_error( 'array_insert(): The key should be a string or an integer', E_USER_ERROR );
        }
        $offset = array_search( $key, array_keys( $input ) );

        if ( $pos === 'after' ) {
            $offset++;
        } else {
            $offset--;
        }

        if ( $offset !== false ) {
            $result = array_slice( $input, 0, $offset );
            $result = array_merge( $result, (array) $insert, array_slice( $input, $offset ) );
        } else {
            $result = array_merge( $input, (array) $insert );
        }

        return $result;
    }
}

/**
 * Insert a value or key/value pair after a specific key in an array.  If key doesn't exist, value is appended
 * to the end of the array.
 *
 * @param  string $key
 * @return array
 */
function array_insert_after( array $array, $key, array $new )
{
    $keys = array_keys( $array );
    $index = array_search( $key, $keys );
    $pos = $index === false ? count( $array ) : $index + 1;

    return array_merge( array_slice( $array, 0, $pos ), $new, array_slice( $array, $pos ) );
}

/**
 * Insert Array Before
 *
 * @param array
 * @param string key
 * @param array new array
 * @return array
 **/
function array_insert_before( $array, $key, $new )
{
    return array_insert( $array, $new, $key, $pos = 'before' );
}

/**
 * Returns an instance of CoreService
 */
function ns(): CoreService
{
    return app()->make( CoreService::class );
}

/**
 * Returns a translated version for a string defined
 * under a module namespace.
 *
 * @param  string $key
 * @param  string $namespace
 * @return string $result
 */
function __m( $key, $namespace = 'default' )
{
    if ( app( 'translator' )->has( $namespace . '.' . $key ) ) {
        return app( 'translator' )->get( $namespace . '.' . $key );
    }

    return $key;
}

if ( ! function_exists( 'ns_logo' ) ) {
    /**
     * The shop logo, falling back to the copy shipped with the application.
     *
     * The logo was set through the media library, which stores it as a URL in an option. Saving
     * any settings screen that carries the media field submits it empty when the picker has not
     * been touched, so the option was cleared and the sidebar dropped back to plain text. The
     * shop then appeared unbranded until someone noticed and re-picked the file.
     *
     * Falling back to a file that lives in the repository means the branding survives an option
     * being cleared, a fresh deployment, a cache clear, and a broken storage symlink. A logo
     * chosen through the media library still wins, so nothing is taken away.
     *
     * @param string $shape square or rectangle, matching the two options the settings offer
     */
    function ns_logo( string $shape = 'square' ): string
    {
        $option = $shape === 'rectangle'
            ? ns()->option->get( 'ns_store_rectangle_logo' )
            : ns()->option->get( 'ns_store_square_logo' );

        if ( is_string( $option ) && trim( $option ) !== '' ) {
            return $option;
        }

        return asset( 'images/store-logo.jpg' );
    }
}

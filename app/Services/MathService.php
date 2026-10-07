<?php

namespace App\Services;

use Brick\Math\BigNumber;

class MathService
{
    /**
     * Hands a value to brick/math as a number it will still accept.
     *
     * brick/math 0.14 deprecated passing floats to BigNumber::of() and will refuse them in 0.15, and
     * every amount in this application arrives as a float because that is what the columns hold. The
     * cast below is the one brick/math does internally today, so the value produced is unchanged;
     * only the warning goes away. Mirrors CurrencyService::forMath().
     */
    public function set( $value )
    {
        if ( is_float( $value ) ) {
            $value = is_nan( $value ) ? 'NAN' : (string) $value;
        }

        return BigNumber::of( $value );
    }
}

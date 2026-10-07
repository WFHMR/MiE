<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class CurrencyService
{
    private $value;

    private $currency;

    private $currency_iso;

    private $prefered_currency;

    private $currency_symbol;

    private $currency_position;

    private $format;

    private $decimal_precision;

    private $thousand_separator;

    private $decimal_separator;

    /**
     * The fallback currency, used only when no option has been saved.
     *
     * These were 'USD' and '$'. AppServiceProvider builds this service from
     * $options->get( 'ns_currency_symbol' ) with no default of its own, so a missing or null option
     * row reaches the constructor as null, the ?? below takes over, and every price on the till and
     * on every printed receipt reads in dollars.
     *
     * That is not a hypothetical: options can be absent on a fresh install, after a reset, or if the
     * row is removed by hand. A shop in Chanthaburi would then be quoting dollars to its customers
     * with nothing on screen to say so — the digits stay the same, only the symbol changes, so it is
     * the kind of fault that gets noticed by a customer rather than by the shop.
     *
     * The saved options are still what applies in normal operation; this is the value that applies
     * when they are not there.
     */
    private static $_currency_iso = 'THB';

    private static $_currency_symbol = '฿';

    private static $_decimal_precision = 9;

    private static $_thousand_separator = ',';

    private static $_decimal_separator = '.';

    private static $_currency_position = 'before';

    private static $_prefered_currency = 'iso';

    /**
     * Hands a float to brick/math the way brick/math itself used to.
     *
     * Every amount in this application arrives as a float, because that is what the database columns
     * and the request payloads carry, and brick/math 0.14 deprecated taking floats directly: it warns
     * on BigNumber::of() and on the arithmetic methods, and says it will refuse them in 0.15. Closing
     * a till alone raised a dozen of those warnings.
     *
     * The conversion below is the one brick/math performs internally today — a plain string cast,
     * with NAN spelled out — so the numbers that come out are the same ones that came out before. It
     * is done here rather than at the hundreds of call sites because every one of them would
     * otherwise have to remember to do it, and the one that forgot would break when 0.15 lands.
     *
     * Not a rounding or precision change. `(string) $float` uses PHP's own precision setting, which
     * is exactly what brick/math was relying on.
     */
    private static function forMath( BigDecimal|float|int|string $number ): BigDecimal|int|string
    {
        if ( ! is_float( $number ) ) {
            return $number;
        }

        if ( is_nan( $number ) ) {
            return 'NAN';
        }

        return (string) $number;
    }

    public function __construct( $value, $config = [] )
    {
        extract( $config );

        $this->currency_iso = $currency_iso ?? self::$_currency_iso;
        $this->currency_symbol = $currency_symbol ?? self::$_currency_symbol;
        $this->currency_position = $currency_position ?? self::$_currency_position;
        $this->decimal_precision = $decimal_precision ?? self::$_decimal_precision;
        $this->decimal_separator = $decimal_separator ?? self::$_decimal_separator;
        $this->prefered_currency = $prefered_currency ?? self::$_prefered_currency;
        $this->thousand_separator = $thousand_separator ?? self::$_thousand_separator;

        $this->value = BigDecimal::of( self::forMath( $value ) );
    }

    /**
     * Will intanciate a new instance
     * using the default value
     *
     * @param  int|float       $value
     * @return CurrencyService
     */
    public function fresh( $value )
    {
        return new CurrencyService( $value, [
            'currency_iso' => $this->currency_iso,
            'currency_symbol' => $this->currency_symbol,
            'currency_position' => $this->currency_position,
            'decimal_precision' => $this->decimal_precision,
            'decimal_separator' => $this->decimal_separator,
            'prefered_currency' => $this->prefered_currency,
            'thousand_separator' => $this->thousand_separator,
        ] );
    }

    /**
     * Set a value for the current instance.
     */
    private static function __defineAmount( float|int|string $amount ): CurrencyService
    {
        /**
         * @var CurrencyService
         */
        $currencyService = app()->make( CurrencyService::class );

        return $currencyService->value( $amount );
    }

    /**
     * Define an amount to work on
     */
    public static function define( float|int|string $amount )
    {
        return self::__defineAmount( $amount );
    }

    public function value( float|int|string $amount ): self
    {
        $this->value = BigDecimal::of( self::forMath( $amount ) );

        return $this;
    }

    public function __toString()
    {
        return $this->format();
    }

    /**
     * Multiply two numbers
     * and return a currency object.
     */
    public static function multiply( int|float $first, int|float $second ): self
    {
        return self::__defineAmount(
            BigDecimal::of( trim( $first ) )
        )->multipliedBy( trim( $second ) );
    }

    /**
     * Divide two numbers
     * and return a currency object
     */
    public static function divide( int|float $first, int|float $second ): self
    {
        return self::__defineAmount(
            BigDecimal::of( self::forMath( $first ) )
        )->dividedBy( $second );
    }

    /**
     * Additionnate two operands.
     */
    public static function additionate( float|int $left_operand, float|int $right_operand ): self
    {
        return self::__defineAmount(
            BigDecimal::of( self::forMath( $left_operand ) )
        )->additionateBy( $right_operand );
    }

    /**
     * calculate a percentage of
     */
    public static function percent( int|float $amount, int|float $rate ): self
    {
        return self::__defineAmount( BigDecimal::of( self::forMath( $amount ) ) )
            ->multipliedBy( $rate )
            ->dividedBy( 100 );
    }

    /**
     * Define the currency in use
     * on the current process
     */
    public function currency( $currency ): string
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Get a currency formatted
     * amount of the current Currency Object
     */
    public function format(): string
    {
        $currency = $this->prefered_currency === 'iso' ? $this->currency_iso : $this->currency_symbol;
        $final = sprintf( '%s ' . number_format(
            floatval( (string) ( $this->value ) ),
            $this->decimal_precision,
            $this->decimal_separator,
            $this->thousand_separator
        ) . ' %s',
            $this->currency_position === 'before' ? $currency : '',
            $this->currency_position === 'after' ? $currency : ''
        );

        return $final;
    }

    /**
     * Get the current amount
     * of the Currency Object
     *
     * @return int|float
     */
    public function get()
    {
        return $this->define( $this->value )->toFloat();
    }

    /**
     * return a raw value for the provided number
     */
    public function getRaw( float|BigDecimal|null $value = null ): float
    {
        if ( $value === null ) {
            return $this->value->dividedBy( 1, $this->decimal_precision, RoundingMode::HALF_UP )->toFloat();
        } else {
            return BigDecimal::of( self::forMath( $value ) )->dividedBy( 1, $this->decimal_precision, RoundingMode::HALF_UP )->toFloat();
        }

        return 0;
    }

    /**
     * Define accuracy of the current
     * Currency object
     */
    public function accuracy( float|int $number ): self
    {
        $this->decimal_precision = intval( $number );

        return $this;
    }

    /**
     * Multiply the current Currency value
     * by the provided number.
     */
    public function multipliedBy( int|float $number ): self
    {
        $this->value = $this->value->multipliedBy( self::forMath( $number ) );

        return $this;
    }

    /**
     * Multiply the current Currency value
     * by the provided number
     */
    public function multiplyBy( int|float $number ): self
    {
        return $this->multipliedBy( $number );
    }

    /**
     * Divide the current Currency Value
     * by the provided number
     */
    public function dividedBy( float|int $number ): self
    {
        $this->value = $this->value->dividedBy(
            that: self::forMath( $number ),
            scale: self::$_decimal_precision,
            roundingMode: RoundingMode::HALF_UP
        );

        return $this;
    }

    /**
     * Divide the current Currency Value
     * by the provided number
     *
     * @param int number to divide by
     * @return CurrencyService
     */
    public function divideBy( $number )
    {
        return $this->dividedBy( $number );
    }

    /**
     * Subtract the current Currency Value
     * by the provided number
     */
    public function subtractBy( float|int $number ): self
    {
        $this->value = $this->value->minus( self::forMath( $number ) );

        return $this;
    }

    /**
     * Additionnate the current Currency Value
     * by the provided number
     */
    public function additionateBy( float|int $number ): self
    {
        $this->value = $this->value->plus( self::forMath( $number ) );

        return $this;
    }

    public function getPercentageValue( float|int|string $value, float $percentage, string $operation = 'additionate' ): BigDecimal|float|int|string
    {
        /**
         * `$share` is the amount the percentage works out to, and it has to be handed to brick/math
         * as a number.
         *
         * It used to be handed over as the CurrencyService object itself. PHP then reached for
         * __toString() to satisfy the parameter, which returns a formatted price — "THB 1.50" — and
         * brick/math threw a format error on the currency letters. Both branches below were
         * unreachable in practice because of it. Taking the float off the object first is what was
         * meant.
         */
        $share = CurrencyService::define( $value )
            ->multiplyBy( $percentage )
            ->dividedBy( 100 )
            ->toFloat();

        /**
         * `toFloat()` rather than a `(float)` cast. Casting an object to float in PHP does not read
         * its value — it warns and yields 1.0 — so the cast that was here would have answered 1 for
         * every amount even once the argument above was sound.
         */
        if ( $operation === 'additionate' ) {
            return BigDecimal::of( self::forMath( $value ) )->plus( self::forMath( $share ) )->toFloat();
        } elseif ( $operation === 'subtract' ) {
            return BigDecimal::of( self::forMath( $value ) )->minus( self::forMath( $share ) )->toFloat();
        }

        return $value;
    }

    /**
     * Returns a float value for
     * a defined number.
     * The scale is hardcoded to be 9 as this is the limit of each FLOAT column.
     */
    public function toFloat(): float
    {
        return $this->value->dividedBy( 1, self::$_decimal_precision, RoundingMode::HALF_UP )->toFloat();
    }
}

<?php

namespace App\Services;

use App\Models\Option;
use App\Models\PaymentType;
use Illuminate\Support\Collection;

class Options
{
    protected $rawOptions = [];

    public string $tableName;

    /**
     * the option class can be constructed with the user id.
     * If the user is not provided, the general options are loaded instead.
     */
    public function __construct()
    {
        $this->tableName = ( new Option )->getTable();
        $this->build();
    }

    /**
     * Will reset the default options
     *
     * @param array $options
     */
    public function setDefault( $options = [] ): void
    {
        Option::truncate();

        $defaultOptions = [
            'ns_registration_enabled' => 'no',
            'ns_store_name' => 'RGBF',
            'ns_pos_allow_decimal_quantities' => 'yes',
            'ns_pos_quick_product' => 'yes',
            'ns_pos_show_quantity' => 'yes',
            'ns_currency_precision' => 2,
            'ns_pos_hide_empty_categories' => 'yes',
            'ns_pos_unit_price_ediable' => 'yes',
            'ns_pos_order_types' => [ 'takeaway', 'delivery' ],
            'ns_pos_registers_default_change_payment_type' => PaymentType::where( 'identifier', 'cash-payment' )->first()?->id ?? 1,
        ];

        $options = array_merge( $defaultOptions, $options );

        foreach ( $options as $key => $value ) {
            $this->set( $key, $value );
        }
    }

    /**
     * return option service
     *
     * @return object
     */
    public function option()
    {
        return Option::where( 'user_id', null );
    }

    /**
     * Build
     * Build option array
     *
     * @return void
     **/
    public function build()
    {
        if ( Helper::installed() && empty( $this->rawOptions ) ) {
            $this->rawOptions = $this->readOptions();
        }
    }

    /**
     * Rebuild the options to ensure having
     * on the rawOptions the latest options.
     *
     * @return void
     **/
    public function rebuild()
    {
        if ( Helper::installed() ) {
            $this->rawOptions = $this->readOptions();
        }
    }

    /**
     * Reads the options, keyed by name.
     *
     * The ordering is what makes this reliable. Keying a collection by name means that when two
     * rows share a name the last one read wins, and the query used to have no ordering at all,
     * so which of them won was left to whatever order the database happened to return. The
     * store name existed twice, as the installer's default and as the registered company name,
     * which meant the name printed on a tax invoice could change without anyone touching a
     * setting. Ordering by id makes the most recently written row win, every time.
     *
     * @return Collection<string, Option>
     */
    protected function readOptions()
    {
        return $this->option()
            ->orderBy( 'id' )
            ->get()
            ->mapWithKeys( function ( $option ) {
                return [
                    $option->key => $option,
                ];
            } );
    }

    /**
     * Set Option
     *
     * @param string key
     * @param any value
     * @param bool force set
     * @return void
     **/
    public function set( $key, $value, $expiration = null )
    {
        /**
         * A new row was stored under the name lowercased and trimmed, while the check for an
         * existing one used the name exactly as given. Writing "NS_Store_Name" therefore never
         * matched the "ns_store_name" already there and inserted a second row instead, leaving
         * two rows to disagree about the same setting. Both paths now use the same name.
         */
        $key = trim( strtolower( $key ) );

        if ( isset( $this->rawOptions[ $key ] ) ) {
            $this->rawOptions[ $key ]->value = $value;
            $this->rawOptions[ $key ]->expire_on = $expiration;

            $this->encodeOptionValue( $this->rawOptions[ $key ], $value );

            $this->rawOptions[ $key ]->save();
        } else {
            /**
             * The in memory copy is not proof that no row exists: it is built once per request,
             * and anything written straight to the table since then, by a migration or a seeder,
             * is invisible to it. Checking the table as well is what stops a second row being
             * created for a setting that already has one.
             */
            $option = $this->option()->where( 'key', $key )->orderBy( 'id' )->first() ?? new Option;

            $option->key = $key;
            $option->array = false;
            $option->value = $value;
            $option->expire_on = $expiration;

            $this->encodeOptionValue( $option, $value );

            $option->save();
            $this->rawOptions[ $key ] = $option;
        }
    }

    /**
     * Encodes the value for the option before saving.
     */
    public function encodeOptionValue( Option $option, mixed $value ): void
    {
        if ( is_array( $value ) ) {
            $option->array = true;
            $option->value = json_encode( $value );
        } elseif ( empty( $value ) && ! (bool) preg_match( '/[0-9]{1,}/', $value ) ) {
            $option->value = '';
        } else {
            $option->value = $value;
        }

        $this->sanitizeValue( $option );
    }

    /**
     * Sanitizes values before storing on the database.
     */
    public function sanitizeValue( Option $option )
    {
        /**
         * sanitizing input to remove
         * all script tags
         */
        $option->value = strip_tags( $option->value );
    }

    /**
     * Get options
     **/
    public function get( string|array|null $key = null, mixed $default = null )
    {
        if ( $key === null ) {
            return $this->rawOptions;
        }

        $filtredOptions = collect( $this->rawOptions )->filter( function ( $option ) use ( $key ) {
            return is_array( $key ) ? in_array( $option->key, $key ) : $option->key === $key;
        } );

        $options = $filtredOptions->map( function ( $option ) {
            $this->decodeOptionValue( $option );

            return $option;
        } );

        return match ( $options->count() ) {
            0 => $default,
            1 => $options->first()->value,
            default => $options->map( fn( $option ) => $option->value )->toArray()
        };
    }

    public function decodeOptionValue( $option )
    {
        /**
         * We shouldn't run this everytime we
         * try to pull an option from the database or from the array
         */
        if ( ! empty( $option->value ) && $option->isClean() ) {
            if ( is_string( $option->value ) && $option->array ) {
                $json = json_decode( $option->value, true );

                if ( json_last_error() == JSON_ERROR_NONE ) {
                    $option->value = $json;
                } else {
                    $option->value = null;
                }
            } elseif ( ! $option->array ) {
                if ( preg_match( '/^[0-9]{1,}$/', $option->value ) ) {
                    $option->value = (int) $option->value;
                } elseif ( preg_match( '/^[0-9]{1,}\.[0-9]{1,}$/', $option->value ) ) {
                    $option->value = (float) $option->value;
                } else {
                    $option->value = $option->value;
                }
            }
        }
    }

    /**
     * Delete every option whose expiration date has passed.
     */
    public function deleteExpired(): int
    {
        $expiredOptions = Option::whereNotNull( 'expire_on' )
            ->where( 'expire_on', '<=', now() )
            ->get( [ 'id', 'key', 'user_id' ] );

        if ( $expiredOptions->isEmpty() ) {
            return 0;
        }

        Option::whereKey( $expiredOptions->pluck( 'id' ) )->delete();

        $expiredGeneralOptionKeys = $expiredOptions
            ->whereNull( 'user_id' )
            ->pluck( 'key' )
            ->all();

        if ( ! empty( $expiredGeneralOptionKeys ) ) {
            $this->rawOptions = collect( $this->rawOptions )
                ->reject( fn( Option $option ) => in_array( $option->key, $expiredGeneralOptionKeys ) );
        }

        return $expiredOptions->count();
    }

    /**
     * Delete an option using a specific key.
     **/
    public function delete( string $key ): void
    {
        /**
         * The in memory copy holds one entry per name, so deleting through it left any other row
         * sharing that name behind. The setting then appeared to come back on the next request.
         * The table is cleared by name so the setting is genuinely gone.
         */
        $this->option()->where( 'key', $key )->delete();

        $this->rawOptions = collect( $this->rawOptions )->reject(
            fn ( Option $option ): bool => $option->key === $key
        );
    }

    /**
     * Removes rows that repeat a setting, keeping the most recently written one.
     *
     * Two rows for one setting mean the value in force depends on the order the database returns
     * them. Ordering the read made that predictable, but the duplicates are still there to be
     * cleared, and until they are a settings screen showing one value can save over the other.
     *
     * The owner is part of the grouping. A setting stored against a user is a different setting
     * from the one stored for the whole shop even though they share a name, so a user's own
     * theme is not mistaken for a duplicate of the shop default and removed.
     *
     * @return array<string, int> setting name to the number of rows removed
     */
    public function purgeDuplicates(): array
    {
        $groups = Option::selectRaw( '`key`, user_id, COUNT(*) AS total, MAX(id) AS keep_id' )
            ->groupBy( 'key', 'user_id' )
            ->havingRaw( 'COUNT(*) > 1' )
            ->get();

        $removed = [];

        foreach ( $groups as $group ) {
            $query = Option::where( 'key', $group->key )
                ->where( 'id', '!=', $group->keep_id );

            $group->user_id === null
                ? $query->whereNull( 'user_id' )
                : $query->where( 'user_id', $group->user_id );

            $count = $query->delete();

            if ( $count > 0 ) {
                $label = $group->user_id === null
                    ? $group->key
                    : $group->key . ' (user ' . $group->user_id . ')';

                $removed[ $label ] = $count;
            }
        }

        if ( $removed !== [] ) {
            $this->rebuild();
        }

        return $removed;
    }
}

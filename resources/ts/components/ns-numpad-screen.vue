<template>
    <input
        ref="field"
        type="text"
        inputmode="decimal"
        autocomplete="off"
        data-role="numpad-screen"
        :value="formattedText"
        @input="onInput( $event )"
        @focus="onFocus"
        @blur="onBlur"
        @keydown.enter.prevent="$emit( 'next', text )"
        class="w-full bg-transparent outline-hidden border-none" />
</template>
<script lang="ts">
/**
 * The editable readout above a numpad.
 *
 * A numpad alone forces every amount to be assembled one key at a time, which is slow
 * on a full keyboard and easy to get wrong. Making the readout an input means a cashier
 * who has a keyboard simply types the amount and presses Enter, while a touch-only till
 * keeps using the keys. Both write to the same value.
 *
 * The typed text is kept as a string rather than a number so that half-finished input
 * survives: "10." and a leading "0" would both be destroyed by round-tripping through
 * parseFloat on every keystroke.
 */
export default {
    name: 'ns-numpad-screen',

    /**
     * @property value     the amount owned by the parent
     * @property decimals  whether a fractional part is accepted at all
     * @property precision how many fractional digits are kept, defaults to the currency
     */
    props: [ 'value', 'decimals', 'precision' ],

    data() {
        return {
            text: '',
            focused: false,
        };
    },

    computed: {
        allowedDecimals() {
            if ( this.decimals === false ) {
                return 0;
            }

            const precision = this.precision !== undefined
                ? parseInt( this.precision )
                : parseInt( ns.currency.ns_currency_precision );

            return isNaN( precision ) ? 2 : precision;
        },

        formattedText() {
            if ( this.focused || this.text === '' ) {
                return this.text;
            }

            // Format with thousand separator when not focused
            const number = parseFloat( this.text );
            if ( isNaN( number ) ) {
                return this.text;
            }

            const separator = ns.currency.ns_currency_thousand_separator || ',';
            const decimal = ns.currency.ns_currency_decimal_separator || '.';
            
            const parts = this.text.split( '.' );
            parts[0] = parts[0].replace( /\B(?=(\d{3})+(?!\d))/g, separator );
            
            return parts.join( decimal );
        }
    },

    watch: {
        value() {
            /**
             * A value arriving from the parent while the field has focus is the echo of
             * what is being typed. Writing it back would move the caret and fight the
             * user, so it is ignored until focus leaves.
             */
            if ( ! this.focused ) {
                this.text = this.fromValue( this.value );
            }
        }
    },

    mounted() {
        this.text = this.fromValue( this.value );
    },

    methods: {
        fromValue( value ) {
            if ( value === undefined || value === null || value === '' ) {
                return '';
            }

            return value.toString();
        },

        onFocus() {
            this.focused = true;

            // Typing over the whole amount is the common case.
            this.$refs.field.select();
        },

        onBlur() {
            this.focused = false;
            this.text = this.fromValue( this.value );
        },

        /**
         * Keeps only what can belong to an amount: digits, a single separator, and no
         * more fractional digits than the currency carries. Rejected characters are
         * dropped rather than refused, so a mistyped key does not stop the entry.
         */
        sanitize( raw ) {
            let cleaned = raw.replace( /,/g, '.' ).replace( /[^0-9.]/g, '' );

            if ( this.allowedDecimals === 0 ) {
                return cleaned.replace( /\./g, '' );
            }

            const parts = cleaned.split( '.' );

            if ( parts.length > 1 ) {
                cleaned = parts.shift() + '.' + parts.join( '' ).substring( 0, this.allowedDecimals );
            }

            return cleaned;
        },

        onInput( event ) {
            const cleaned = this.sanitize( event.target.value );

            /**
             * Vue will not re-render when the bound value is unchanged, so a rejected
             * character would stay on screen. The element is corrected directly.
             */
            if ( cleaned !== event.target.value ) {
                event.target.value = cleaned;
            }

            this.text = cleaned;

            this.$emit( 'changed', cleaned );
        }
    }
};
</script>

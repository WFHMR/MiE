<template>
    <div class="h-full w-full py-2">
        <div class="px-2 pb-2" v-if="order">
            <div class="grid grid-cols-2 gap-2">
                <div id="subtotal" class="h-16 flex justify-between items-center border elevation-surface text-xl md:text-3xl p-2">
                    <span>{{ __( 'Subtotal' ) }} : </span>
                    <span>{{ nsCurrency( netSubtotal ) }}</span>
                </div>
                <div id="tax" class="h-16 flex justify-between items-center border elevation-surface text-xl md:text-3xl p-2">
                    <span>{{ __( 'VAT 7%' ) }} : </span>
                    <span>{{ nsCurrency( order.tax_value || 0 ) }}</span>
                </div>
                <div id="details" class="h-16 flex justify-between items-center border elevation-surface info text-xl md:text-3xl p-2">
                    <span>{{ __( 'Total' ) }} : </span>
                    <span>{{ nsCurrency( order.total ) }}</span>
                </div>
                <div id="discount" @click="toggleDiscount()" class="cursor-pointer h-16 flex justify-between items-center border elevation-surface error text-xl md:text-3xl p-2">
                    <span>{{ __( 'Discount' ) }} : </span>
                    <span>{{ nsCurrency( order.discount ) }}</span>
                </div>
                <div id="paid" class="h-16 flex justify-between items-center border elevation-surface success text-xl md:text-3xl p-2">
                    <span>{{ __( 'Paid' ) }} : </span>
                    <span>{{ nsCurrency( order.tendered ) }}</span>
                </div>
                <div id="change" class="h-16 flex justify-between items-center border elevation-surface warning text-xl md:text-3xl p-2">
                    <span>{{ __( 'Change' ) }} : </span>
                    <span>{{ nsCurrency( order.change ) }}</span>
                </div>
                <!--
                    The amount is typed here as well as tapped on the pad. A cashier with
                    a keyboard reads the total and types it, which is both faster and
                    harder to get wrong than assembling it one key at a time.
                -->
                <div id="screen" class="col-span-2 h-16 flex justify-between items-center elevation-surface border text-xl md:text-3xl p-2">
                    <span class="flex-shrink-0">{{ __( 'Screen' ) }} : </span>
                    <ns-numpad-screen
                        class="flex-auto text-right px-2"
                        :value="backValue"
                        @changed="typedValue( $event )"
                        @next="confirmScreen()"></ns-numpad-screen>
                    <button
                        @click="inputValue({ identifier: 'next' })"
                        data-action="add-payment"
                        :title="__( 'Add the amount as a payment' )"
                        class="flex-shrink-0 ns-numpad-key border h-12 w-12 flex items-center justify-center cursor-pointer">
                        <i class="las la-share"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="px-2 pb-2">
            <div class="-mx-2 flex flex-wrap">
                <div class="pl-2 pr-1 flex-auto">
                    <div id="numpad" class="grid grid-flow-row grid-cols-3 gap-2 grid-rows-3" style="padding: 1px">
                        <div
                            @click="inputValue( key )"
                            :key="index"
                            v-for="(key,index) of keys"
                            :data-identifier="key.identifier"
                            style="margin:-1px;"
                            class="ns-numpad-key text-2xl border h-16 flex items-center justify-center cursor-pointer">
                            <span v-if="key.value !== undefined">{{ key.value }}</span>
                            <i v-if="key.icon" class="las" :class="key.icon"></i>
                        </div>
                        <div
                            @click="makeFullPayment()"
                            class="hover:bg-green-500 col-span-3 bg-success-secondary border-success-tertiary text-2xl text-white border h-16 flex items-center justify-center cursor-pointer">
                            {{ __( 'Full Payment' ) }}</div>
                    </div>
                </div>
                <div class="w-1/2 md:w-72 pr-2 pl-1">
                    <div class="grid grid-flow-row grid-rows-1 gap-2">
                        <div
                            v-for="(amount, index) of amountShortcuts" :key="index"
                            @click="increaseBy({ value : amount })"
                            class="ns-numpad-key text-2xl border h-16 flex items-center justify-center cursor-pointer">
                            <span>{{ nsCurrency( amount ) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { Popup } from '~/libraries/popup';
import nsPosDiscountPopupVue from '~/popups/ns-pos-discount-popup.vue';
import nsPosConfirmPopupVue from '~/popups/ns-pos-confirm-popup.vue';
import { __ } from '~/libraries/lang';
import { nsSnackBar } from '~/bootstrap';
import { nsCurrency } from '~/filters/currency';
import nsNumpadScreen from '~/components/ns-numpad-screen.vue';

export default {
    name: 'sample-payment',

    components: {
        nsNumpadScreen,
    },

    /**
     * "reference" is optional and passed down by reference-payment.vue for the channels
     * that settle after the sale (PromptPay, bank transfer, welfare card). Left undefined
     * it is simply absent from the payload, which is what cash wants.
     */
    props: [ 'label', 'identifier', 'reference' ],
    data() {
        return {
            /**
             * The amount as it is written. It used to be a count of the smallest unit, so
             * pressing "5" meant 0.05 and 1,504 had to be entered as 150400 — a keystroke
             * short of that produced a payment a hundred times too small.
             */
            backValue: '',
            order: null,
            settings: {},
            settingsSubscription: null,
            orderSubscription: null,
            allSelected: true,
            keys: [
                ...([7,8,9].map( key => ({ identifier: key, value: key }))),
                ...([4,5,6].map( key => ({ identifier: key, value: key }))),
                ...([1,2,3].map( key => ({ identifier: key, value: key }))),
                ...[{ identifier: '.', value: '.' },{ identifier: 0, value: 0 },{ identifier: 'backspace', icon : 'la-backspace' }],
            ]
        }
    },
    computed: {
        /**
         * With inclusive VAT the product prices already carry the tax, so order.subtotal
         * is a gross figure. Showing it next to the VAT line reads as if the two should
         * add up to the total, which they don't. The net figure is what makes the
         * "Subtotal + VAT = Total" breakdown on the screen true.
         */
        netSubtotal() {
            if ( this.order === null ) {
                return 0;
            }

            if ( this.order.tax_type === 'inclusive' ) {
                return this.order.subtotal - ( this.order.tax_value || 0 );
            }

            return this.order.subtotal;
        },
        amountShortcuts() {
            if ( nsShortcuts.ns_pos_amount_shortcut !== null ) {
                return nsShortcuts.ns_pos_amount_shortcut.split( '|' );
            }
            return [];
        }
    },
    mounted() {
        this.orderSubscription  =   POS.order.subscribe( order => {
            this.order  =   order;
        });

        this.settingsSubscription   =   POS.settings.subscribe( settings => {
            this.settings   =   settings;
        })

        /**
         * will bind keyboard event listening
         */
        const numbers   =   ( new Array(10) ).fill('').map( ( v,i ) => i );

        nsHotPress
            .create( 'numpad-keys' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( numbers, ( event, value ) => { this.inputValue({ value: value }); })

        nsHotPress
            .create( 'numpad-backspace' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( 'backspace', () => this.inputValue({ identifier: 'backspace' }))

        nsHotPress
            .create( 'numpad-dot' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( '.', () => this.inputValue({ identifier: '.' }))

        nsHotPress
            .create( 'numpad-save' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( 'enter', () => this.confirmScreen() )
    },
    beforeUnmount() {
        nsHotPress.destroy( 'numpad-keys' );
        nsHotPress.destroy( 'numpad-backspace' );
        nsHotPress.destroy( 'numpad-dot' );
        nsHotPress.destroy( 'numpad-save' );
    },
    unmounted() {
        this.orderSubscription.unsubscribe();

        /**
         * The settings subscription was created on mount but never released, so
         * every visit to the payment screen left another live subscriber behind.
         */
        this.settingsSubscription.unsubscribe();
    },
    methods: {
        __,
        nsCurrency,
        toggleDiscount() {
            if ( this.settings.cart_discount !== undefined && this.settings.cart_discount === true ) {
                Popup.show( nsPosDiscountPopupVue, {
                    reference : this.order,
                    type : 'cart',
                    onSubmit : ( response ) => {
                        POS.updateCart( this.order, response );
                    }
                });
            } else {
                return nsSnackBar.error( __( `You're not allowed to add a discount on the cart.` ) );
            }
        },
        makeFullPayment() {
            const order     =   POS.order.getValue();
            Popup.show( nsPosConfirmPopupVue, {
                title: __( 'Confirm Full Payment' ),
                message: __( 'A full payment will be made using {paymentType} for {total}' )
                    .replace( '{paymentType}', this.label )
                    .replace( '{total}', nsCurrency( this.order.total ) ),
                onAction: ( action ) => {
                    if ( action ) {
                        const order     =   POS.order.getValue();

                        /**
                         * to avoid inputing
                         * new amount when first payment fails.
                         */
                        if ( order.tendered < order.total ) {
                            POS.addPayment({
                                value: this.order.total - order.tendered,
                                identifier: this.identifier,
                                selected: false,
                                label: this.label,
                                readonly: false,
                                reference: this.reference,
                            });
                        }

                        this.$emit( 'submit' );
                        this.backValue     =   '';
                    }
                }
            })
        },
        /**
         * The shortcut tiles down the right-hand side add a round amount to what is on
         * the screen, the way a cashier counts out notes.
         */
        increaseBy( key ) {
            this.backValue      =   ( parseFloat( key.value ) + ( parseFloat( this.backValue ) || 0 ) ).toString();
            this.allSelected    =   false;
        },

        /**
         * The amount was typed into the screen rather than tapped. The keys append to
         * whatever is there, so the pad must not treat the value as still untouched.
         */
        typedValue( value ) {
            this.backValue      =   value;
            this.allSelected    =   false;
        },

        /**
         * Enter on an empty screen finishes the order instead of adding a payment. That
         * makes "full payment, then Enter twice" the whole cash sale, which is how the
         * till is driven when the customer pays the exact amount.
         */
        confirmScreen() {
            if ( this.backValue === '' ) {
                return this.$emit( 'submit' );
            }

            this.inputValue({ identifier: 'next' });
        },

        inputValue( key ) {
            if ( key.identifier === 'next' ) {
                const value     =   parseFloat( this.backValue );

                /**
                 * An empty screen is a mis-press, not a zero payment. Recording one would
                 * add a line worth nothing to the order and make the payment list lie
                 * about how many times the customer handed money over.
                 */
                if ( isNaN( value ) || value <= 0 ) {
                    return nsSnackBar.error( __( 'Please provide an amount before adding a payment.' ) );
                }

                POS.addPayment({
                    value,
                    identifier: this.identifier,
                    selected: false,
                    label: this.label,
                    readonly: false,
                    reference: this.reference,
                });

                this.backValue      =   '';
                this.allSelected    =   true;

                return;
            } else if ( key.identifier === 'backspace' ) {
                if ( this.allSelected ) {
                    this.backValue      =   '';
                    this.allSelected    =   false;
                } else {
                    this.backValue      =   this.backValue.slice(0, -1);
                }
            } else if ( key.identifier === '.' ) {
                if ( this.allSelected ) {
                    this.backValue      =   '0.';
                    this.allSelected    =   false;
                } else if ( ! this.backValue.includes( '.' ) ) {
                    this.backValue      +=  this.backValue.length === 0 ? '0.' : '.';
                }
            } else if ( key.value !== undefined && key.value.toString().match( /^\d+$/ ) ) {
                if ( this.allSelected ) {
                    this.backValue      =   key.value.toString();
                    this.allSelected    =   false;
                } else {
                    this.backValue      +=  key.value.toString();
                }
            }
        }
    }
}
</script>
<template>
    <div id="numpad" class="grid grid-flow-row divide-x-1 divide-y-1 border-t border-l border-numpad-edge grid-cols-3 grid-rows-3">
        <div 
            @click="inputValue( key )"
            :key="index"
            v-for="(key,index) of keys" 
            :data-identifier="key.identifier"
            :class="index === keys.length - 1 ? 'border-r border-b' : ''"
            class="select-none ns-numpad-key h-24 font-bold flex items-center justify-center cursor-pointer">
            <span v-if="key.value !== undefined">{{ key.value }}</span>
            <i v-if="key.icon" class="las" :class="key.icon"></i>
        </div>
        <!--
            A decimal amount needs a separator key, which pushes the confirmation onto a
            row of its own. Whole-number pads keep the compact three by four layout.
        -->
        <div
            v-if="floating"
            @click="inputValue({ identifier: 'next' })"
            data-identifier="next"
            class="select-none ns-numpad-key col-span-3 border-r border-b h-24 font-bold flex items-center justify-center cursor-pointer">
            <span>{{ __( 'Enter' ) }}</span>
        </div>
        <slot name="numpad-footer"></slot>
    </div>
    <!-- :class="index === 0 ? 'border-l border-t' : ''" -->
</template>
<script lang="ts">
import { __ } from '~/libraries/lang';

declare const ns, nsHotPress, nsState;

export default {
    name: 'ns-numpad',
    props: [ 'value', 'currency', 'floating', 'limit' ],
    data() {
        return {
            number: parseInt( 
                1 + ( new Array( parseInt( ns.currency.ns_currency_precision ) ) )
                .fill('')
                .map( _ => 0 )
                .join('') 
            ),
            screenValue: 0,
            order: null,
            popupSubscription: null,
            cursor: parseInt( ns.currency.ns_currency_precision ),
            orderSubscription: null,
            allSelected: true,
        }
    },
    computed: {
        keys() {
            const digits = [
                ...([7,8,9].map( key => ({ identifier: key, value: key }))),
                ...([4,5,6].map( key => ({ identifier: key, value: key }))),
                ...([1,2,3].map( key => ({ identifier: key, value: key }))),
            ];

            if ( this.floating ) {
                return [
                    ...digits,
                    { identifier: '.', value: '.' },
                    { identifier: 0, value: 0 },
                    { identifier: 'backspace', icon: 'la-backspace' },
                ];
            }

            return [
                ...digits,
                { identifier: 'backspace', icon: 'la-backspace' },
                { identifier: 0, value: 0 },
                { identifier: 'next', value: __( 'Enter' ) },
            ];
        }
    },
    unmounted() {
        if ( this.popupSubscription ) {
            this.popupSubscription.unsubscribe();
        }
    },
    mounted() {
        /**
         * The screen carries the amount as it is written, not as a count of the smallest
         * unit. Digits used to be shifted into the decimals, so pressing "5" produced
         * 0.05 and reaching 1,504 meant typing 150400. Cashiers read the total off the
         * screen and typed it, which is now what happens.
         */
        this.screenValue    =   this.value === undefined || this.value === null ? 0 : this.value;

        /**
         * will bind keyboard event listening
         */
        const numbers   =   ( new Array(10) ).fill('').map( ( v,i ) => i );

        // will trigger if any state changes occurs on nsState
        this.popupSubscription  =   nsState.subscribe( state => {
            setTimeout( () => {
                const isInPopup     =   this.$el.closest('.is-popup');
                this.numpadKeyboardWorking = ( isInPopup && isInPopup.getAttribute('focused') === 'true' );
            }, 100 );
        });


        /**
         * We'll no check if the popup is focused 
         * or if it's active
         */        
        nsHotPress
            .create( 'numpad-keys' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( numbers, ( event, value ) => {
                if ( this.numpadKeyboardWorking ) {
                    this.inputValue({ value });
                }
            })

        nsHotPress
            .create( 'numpad-backspace' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( 'backspace', () => {
                if ( this.numpadKeyboardWorking ) {
                    this.inputValue({ identifier: 'backspace' });
                }
            })

        nsHotPress
            .create( 'numpad-dot' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( '.', () => {
                if ( this.numpadKeyboardWorking ) {
                    this.inputValue({ identifier: '.' });
                }
            })

        nsHotPress
            .create( 'numpad-increase' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( '+', () => {
                if ( this.numpadKeyboardWorking ) {
                    this.increaseBy({ value: 1 });
                }
            })

        nsHotPress
            .create( 'numpad-reduce' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( '-', () => {
                if ( this.numpadKeyboardWorking ) {
                    this.increaseBy({ value: -1 });
                }
            })

        nsHotPress
            .create( 'numpad-save' )
            .whenVisible([ '.is-popup' ])
            .whenPressed( 'enter', () => {
                if ( this.numpadKeyboardWorking ) {
                    this.inputValue({ identifier: 'next' });
                }
            });
    },
    watch: {
        value() {
            this.screenValue    =   this.value === undefined || this.value === null || this.value.toString().length === 0
                ? ''
                : this.value;

            /**
             * The amount was changed from outside, most often typed straight into the
             * editable screen. Keeping "everything selected" would make the next key
             * press wipe what was typed instead of appending to it.
             */
            this.allSelected    =   false;
        }
    },
    beforeUnmount() {
        nsHotPress.destroy( 'numpad-keys' );
        nsHotPress.destroy( 'numpad-backspace' );
        nsHotPress.destroy( 'numpad-dot' );
        nsHotPress.destroy( 'numpad-increase' );
        nsHotPress.destroy( 'numpad-reduce' );
        nsHotPress.destroy( 'numpad-save' );
    },
    methods: {
        __,

        increaseBy( key ) {
            this.screenValue    =   ( parseFloat( key.value ) + ( parseFloat( this.screenValue ) || 0 ) ).toString();
            this.allSelected    =   false;

            this.$emit( 'changed', this.screenValue );
        },

        inputValue( key ) {
            // count a screenValue numbers if it's a string, if it's not, let's convert it to string first
            if ( typeof this.screenValue !== 'string' ) {
                this.screenValue    =   this.screenValue.toString();
            }

            if ( key.identifier === 'next' ) {
                this.$emit( 'next', this.screenValue );
                return;
            } else if ( key.identifier === 'backspace' ) {
                if ( this.allSelected ) {
                    this.screenValue    =   '0';
                    this.allSelected    =   false;
                } else {
                    this.screenValue    =   this.screenValue.substr( 0, this.screenValue.length - 1 );
                }
            } else if ( key.value.toString().match( /^\d+$/ ) ) {
                if ( this.limit > 0 && this.screenValue.length >= this.limit ) {
                    return;
                }
                
                if ( this.allSelected ) {
                    this.screenValue      =   key.value.toString();
                    this.allSelected    =   false;
                } else {
                    this.screenValue      +=  '' + key.value.toString();

                    if ( this.mode === 'percentage' ) {
                        this.screenValue = this.screenValue > 100 ? 100 : this.screenValue;
                    }
                }
            } else if ( key.identifier === '.' && this.floating ) {
                if ( this.allSelected ) {
                    this.screenValue    =   '0.';
                    this.allSelected    =   false;
                } else if ( ! this.screenValue.includes( '.' ) ) {
                    this.screenValue    +=  this.screenValue.length === 0 ? '0.' : '.';
                }
            }

            this.$emit( 'changed', this.screenValue );
        }
    }
}
</script>
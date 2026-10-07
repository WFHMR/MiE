<script>
import popupCloser from '~/libraries/popup-closer';
import nsPosCashRegistersActionPopupVue from './ns-pos-cash-registers-action-popup.vue';
import nsPosCashRegistersHistoryVue from './ns-pos-cash-registers-history-popup.vue';
import popupResolver from '~/libraries/popup-resolver';
import { __ } from '~/libraries/lang';
import { nsCurrency } from '~/filters/currency';
import { nsSnackBar } from '~/bootstrap';

export default {
    props: [ 'popup' ],
    mounted() {
        this.settingsSubscriber     =   POS.settings.subscribe( settings => {
            this.settings   =   settings;
        });

        this.popupCloser();

        this.loadRegisterSummary();
    },
    beforeUnmount() {
        this.settingsSubscriber.unsubscribe();
    },
    data() {
        return {
            settings: null,
            settingsSubscriber: null,
            register: {}
        }
    },
    methods: {
        __,
        nsCurrency,
        popupResolver,
        popupCloser,

        loadRegisterSummary() {
            if ( this.settings.register === undefined ) {
                setTimeout( () => {
                    this.popup.close();
                }, 500 );
                
                return nsSnackBar.error( __( 'The register is not yet loaded.' ) );
            }

            nsHttpClient.get( `/api/cash-registers/${this.settings.register.id}` )
                .subscribe( result => {
                    this.register   =   result;
                })
        },

        closePopup() {
            this.popupResolver({
                status: 'error',
                button: 'close_popup'
            });
        },

        async closeCashRegister( register ) {
            try {
                const response  =   await new Promise( ( resolve, reject ) => {
                    Popup.show( nsPosCashRegistersActionPopupVue, {
                        title: __( 'Close Register' ),
                        action: 'close',
                        identifier: 'ns.cash-registers-closing',
                        register,
                        resolve, 
                        reject
                    })
                });

                /**
                 * The shift closing slip, printed as soon as the drawer is closed.
                 *
                 * The employee hands this slip over together with the cash, so it has to appear as
                 * part of closing rather than being hunted for afterwards. Until now the only way to
                 * get it was a "Print Z-Report" button buried in the History popup, which nobody
                 * opens at the end of a shift — the access log recorded not one request for the
                 * document since the shop opened, while shifts were being closed every day.
                 *
                 * Printed before the register reference is dropped, because the id is what the
                 * document is fetched by. Wrapped because a printer that is not set up must not stop
                 * the till from closing: the shift is already recorded by the time we get here, and
                 * the slip can be reprinted from History.
                 */
                try {
                    if ( POS.print.process( register.id || this.settings.register.id, 'z-report' ) === false ) {
                        nsSnackBar.error( __( 'ปิดกะเรียบร้อยแล้ว แต่พิมพ์ใบปิดกะไม่สำเร็จ สั่งพิมพ์ซ้ำได้จากปุ่มประวัติ' ) );
                    }
                } catch ( printError ) {
                    nsSnackBar.error( __( 'ปิดกะเรียบร้อยแล้ว แต่พิมพ์ใบปิดกะไม่สำเร็จ สั่งพิมพ์ซ้ำได้จากปุ่มประวัติ' ) );
                }

                /**
                 * if the register has been successfully
                 * closed, we need to delete the registe reference
                 */
                POS.unset( 'register' );

                this.popupResolver({
                    button: 'close_register',
                    ...response
                });
            } catch( exception ) {
                throw exception;
            }
        },

        async cashIn( register ) {
            try {
                const response  =   await new Promise( ( resolve, reject ) => {
                    Popup.show( nsPosCashRegistersActionPopupVue, {
                        title: __( 'Cash In' ),
                        action: 'register-cash-in',
                        register,
                        identifier: 'ns.cash-registers-cashing',
                        resolve, 
                        reject
                    })
                });

                /**
                 * if the register has been successfully
                 * closed, we need to delete the registe reference
                 */
                this.popupResolver({
                    button: 'close_register',
                    ...response
                });
            } catch( exception ) {
                console.log({exception});
            }
        },

        async cashOut( register ) {
            try {
                const response  =   await new Promise( ( resolve, reject ) => {
                    Popup.show( nsPosCashRegistersActionPopupVue, {
                        title: __( 'Cash Out' ),
                        action: 'register-cash-out',
                        register,
                        identifier: 'ns.cash-registers-cashout',
                        resolve, 
                        reject
                    })
                });

                /**
                 * if the register has been successfully
                 * closed, we need to delete the registe reference
                 */
                this.popupResolver({
                    button: 'close_register',
                    ...response
                });
            } catch( exception ) {
                throw exception;
            }
        },

        async historyPopup( register ) {
            try {
                const response  =   await new Promise( ( resolve, reject ) => {
                    Popup.show( nsPosCashRegistersHistoryVue, { resolve, reject, register });
                });
            } catch( exception ) {
                throw exception;
            }
        }
    }
}
</script>
<template>
    <div class="shadow-lg w-95vw md:w-[60vw] lg:w-half ns-box">
        <div class="p-2 border-b ns-box-header flex items-center justify-between">
            <h3>{{ __( 'Register Options' ) }}</h3>
            <div>
                <ns-close-button @click="closePopup()"></ns-close-button>
            </div>
        </div>
        <div v-if="register.total_sale_amount !== undefined && register.balance !== undefined">
            <div class="h-16 text-3xl bg-info-primary info flex items-center justify-between px-3">
                <span class="">{{ __( 'Sales' ) }}</span>
                <span class="font-bold">{{ nsCurrency( register.total_sale_amount ) }}</span>
            </div>
            <div class="h-16 text-3xl bg-success-primary success flex items-center justify-between px-3">
                <span class="">{{ __( 'Balance' ) }}</span>
                <span class="font-bold">{{ nsCurrency( register.balance ) }}</span>
            </div>
        </div>
        <div class="h-32 ns-box-body border-b py-1 flex items-center justify-center" v-if="register.total_sale_amount === undefined && register.balance === undefined">
            <div>
                <ns-spinner border="4" size="16"></ns-spinner>
            </div>
        </div>
        <div class="grid grid-cols-2 text-font">
            <div @click="closeCashRegister( register )" class="border-r border-b py-4 ns-numpad-key info cursor-pointer px-2 flex items-center justify-center flex-col">
                <i class="las la-sign-out-alt text-6xl"></i>
                <h3 class="text-xl font-bold">{{ __( 'Close' ) }}</h3>
            </div>
            <div @click="cashIn( register )" class="ns-numpad-key success border-r border-b py-4 cursor-pointer px-2 flex items-center justify-center flex-col">
                <i class="las la-plus-circle text-6xl"></i>
                <h3 class="text-xl font-bold">{{ __( 'Cash In' ) }}</h3>
            </div>
            <div @click="cashOut( register )" class="ns-numpad-key error border-r border-b py-4 cursor-pointer px-2 flex items-center justify-center flex-col">
                <i class="las la-minus-circle text-6xl"></i>
                <h3 class="text-xl font-bold">{{ __( 'Cash Out' ) }}</h3>
            </div>
            <div @click="historyPopup( register )" class="ns-numpad-key info border-r border-b py-4 cursor-pointer px-2 flex items-center justify-center flex-col">
                <i class="las la-history text-6xl"></i>
                <h3 class="text-xl font-bold">{{ __( 'History' ) }}</h3>
            </div>
        </div>
    </div>
</template>
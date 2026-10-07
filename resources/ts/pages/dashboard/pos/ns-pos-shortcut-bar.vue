<template>
    <div class="ns-pos-shortcut-bar flex-shrink-0 overflow-x-auto">
        <div class="flex items-center justify-start gap-2 min-w-max">
            <button
                v-for="shortcut of shortcuts"
                :key="shortcut.key"
                type="button"
                :title="tooltipFor( shortcut )"
                @click="trigger( shortcut.key )">
                <b :class="shortcut.keyboardDisabled ? 'is-disabled' : ''">{{ shortcut.key.toUpperCase() }}</b>
                <span>{{ shortcut.label }}</span>
            </button>
        </div>
    </div>
</template>

<script lang="ts">
import nsPosSearchProductVue from '~/popups/ns-pos-search-product.vue';
import nsPosCustomerSelectPopupVue from '~/popups/ns-pos-customer-select-popup.vue';
import PosConfirmPopup from '~/popups/ns-pos-confirm-popup.vue';
import { __ } from '~/libraries/lang';

declare const POS;
declare const Popup;
declare const nsSnackBar;
declare const nsHotPress;
declare const nsHooks;
declare const nsShortcuts;

/**
 * Namespace used to register and release the hotkeys
 * and the hooks owned by this component.
 */
const NAMESPACE = 'ns-pos-shortcut-bar';

/**
 * Storage key holding the last submitted order, so the reprint
 * shortcut still works after the POS page is reloaded.
 */
const LAST_ORDER_STORAGE_KEY = 'ns-pos-last-order-id';

export default {
    name: 'ns-pos-shortcut-bar',
    data() {
        return {
            shortcuts: [
                { key: 'f1', label: __( 'ค้นหา/เช็คราคา' ), keyboardDisabled: false },
                { key: 'f2', label: __( 'ลบ/เลือกลบ' ), keyboardDisabled: false },
                { key: 'f3', label: __( 'สมาชิก/ค้นหา' ), keyboardDisabled: false },
                { key: 'f4', label: __( 'รับคืน' ), keyboardDisabled: false },
                { key: 'f5', label: __( 'คิดเงิน' ), keyboardDisabled: false },
                { key: 'f6', label: __( 'ส่วนลดรายตัว' ), keyboardDisabled: false },
                { key: 'f7', label: __( 'พักบิล' ), keyboardDisabled: false },
                { key: 'f8', label: __( 'ปริ้นบิลซ้ำ' ), keyboardDisabled: false },
                { key: 'f9', label: __( 'เปิด/ปิดกะ' ), keyboardDisabled: false },
                { key: 'f10', label: __( 'ยกเลิกบิล' ), keyboardDisabled: false },
            ],
            lastOrderId: null,
        };
    },
    mounted() {
        this.registerHotkeys();

        this.lastOrderId = this.readLastOrderId();

        /**
         * The reprint shortcut needs the identifier of the
         * order that was submitted last.
         */
        nsHooks.addAction( 'ns-order-submit-successful', NAMESPACE, ( result ) => {
            const order = result?.data?.order;

            if ( order !== undefined && order.id !== undefined ) {
                this.lastOrderId = order.id;
                this.storeLastOrderId( order.id );
            }
        });
    },
    unmounted() {
        this.shortcuts.forEach( shortcut => nsHotPress.destroy( `${NAMESPACE}-${shortcut.key}` ) );

        nsHooks.removeAction( 'ns-order-submit-successful', NAMESPACE );
    },

    methods: {
        __,

        /**
         * Reads the last submitted order from the browser storage.
         * Storage can throw when it is disabled, so failures are ignored.
         */
        readLastOrderId() {
            try {
                const stored = window.localStorage.getItem( LAST_ORDER_STORAGE_KEY );

                return stored === null ? null : Number( stored );
            } catch ( exception ) {
                return null;
            }
        },

        storeLastOrderId( id ) {
            try {
                window.localStorage.setItem( LAST_ORDER_STORAGE_KEY, String( id ) );
            } catch ( exception ) {
                // storage unavailable, the shortcut stays session-only.
            }
        },

        tooltipFor( shortcut ) {
            if ( shortcut.keyboardDisabled ) {
                return __( '{label} — คีย์ {key} ถูกกำหนดไว้ในตั้งค่า POS แล้ว กดปุ่มนี้แทนได้' )
                    .replace( '{label}', shortcut.label )
                    .replace( '{key}', shortcut.key.toUpperCase() );
            }

            return `${shortcut.label} (${shortcut.key.toUpperCase()})`;
        },

        /**
         * Every hotkey needs its own named NsHotPress entry. Calling
         * nsHotPress.create() without a name overwrites the previous
         * registration, which would leave a single working shortcut.
         */
        registerHotkeys() {
            const claimed = this.getKeysClaimedBySettings();

            this.shortcuts.forEach( shortcut => {
                /**
                 * A shortcut configured on the POS settings already has its own
                 * listener on the matching component. Registering the same key
                 * here would run both handlers on a single key press.
                 */
                if ( claimed.includes( shortcut.key ) ) {
                    shortcut.keyboardDisabled = true;
                    return;
                }

                nsHotPress
                    .create( `${NAMESPACE}-${shortcut.key}` )
                    .whenNotVisible([ '.is-popup' ])
                    .whenPressed( shortcut.key, ( event ) => {
                        event.preventDefault();
                        this.trigger( shortcut.key );
                    });
            });
        },

        /**
         * Single keys assigned through Settings > POS > Shortcuts. Combinations
         * such as ctrl+f5 are ignored, as NsHotPress requires an exact
         * modifier match and cannot collide with a bare F-key.
         *
         * @return string[]
         */
        getKeysClaimedBySettings() {
            if ( typeof nsShortcuts === 'undefined' || nsShortcuts === null ) {
                return [];
            }

            return Object.values( nsShortcuts )
                .filter( ( combination: any ) => Array.isArray( combination ) && combination.length === 1 )
                .map( ( combination: any ) => String( combination[ 0 ] ).toLowerCase() );
        },

        trigger( key ) {
            const handlers = {
                f1: this.openProductSearch,
                f2: this.removeProduct,
                f3: this.selectCustomer,
                f4: this.goToOrders,
                f5: this.runPayment,
                f6: this.discountProduct,
                f7: this.holdOrder,
                f8: this.reprintLastReceipt,
                f9: this.toggleRegister,
                f10: this.cancelOrder,
            };

            if ( handlers[ key ] !== undefined ) {
                handlers[ key ]();
            }
        },

        /**
         * F1 — opens the product search popup, the same popup
         * the magnifier button on the grid opens.
         */
        openProductSearch() {
            Popup.show( nsPosSearchProductVue );
        },

        /**
         * F2 — removes the selected cart line, or the last one when
         * nothing is selected. The cart owns both the rows and the
         * selection, so it performs the removal.
         */
        removeProduct() {
            nsHooks.doAction( 'ns-pos-shortcut-remove-product' );
        },

        /**
         * F3 — opens the customer selection popup.
         */
        selectCustomer() {
            Popup.show( nsPosCustomerSelectPopupVue );
        },

        /**
         * F4 — leaves the POS for the orders list, where
         * a refund can be started.
         */
        goToOrders() {
            const urls = POS.settings.getValue().urls || {};

            if ( urls.orders_url === undefined ) {
                return nsSnackBar.error( __( 'ไม่พบ URL สำหรับหน้ารับคืนสินค้า' ) );
            }

            window.location.href = urls.orders_url;
        },

        /**
         * F5 — runs the same checkout queue as the Pay button.
         */
        runPayment() {
            POS.runPaymentQueue();
        },

        /**
         * F6 — opens the discount popup for the selected cart line,
         * or the last one when nothing is selected.
         */
        discountProduct() {
            nsHooks.doAction( 'ns-pos-shortcut-discount-product' );
        },

        /**
         * F7 — the hold flow lives on the Hold button, which listens
         * to this action.
         */
        holdOrder() {
            nsHooks.doAction( 'ns-pos-shortcut-hold-order' );
        },

        /**
         * F8 — reopens the receipt of the last submitted order.
         */
        reprintLastReceipt() {
            if ( this.lastOrderId === null ) {
                return nsSnackBar.error( __( 'ไม่พบบิลล่าสุดที่จะปริ้น' ) );
            }

            const urls = POS.settings.getValue().urls || {};

            if ( urls.sale_printing_url === undefined ) {
                return nsSnackBar.error( __( 'ไม่พบ URL สำหรับปริ้นบิล' ) );
            }

            window.open( urls.sale_printing_url.replace( '{id}', this.lastOrderId ), '_blank' );
        },

        /**
         * F9 — the register flow lives on the Cash Register header
         * button, which listens to this action.
         */
        toggleRegister() {
            if ( POS.options.getValue().ns_pos_registers_enabled !== 'yes' ) {
                return nsSnackBar.error( __( 'ระบบเปิด/ปิดกะยังไม่ได้เปิดใช้งาน' ) );
            }

            nsHooks.doAction( 'ns-pos-shortcut-open-register' );
        },

        /**
         * F10 — voids a submitted order, or clears the cart when
         * the order has never been submitted.
         */
        cancelOrder() {
            const order = POS.order.getValue();

            if ( order.id !== undefined ) {
                return POS.voidOrder( order );
            }

            Popup.show( PosConfirmPopup, {
                title: __( 'Confirm Your Action' ),
                message: __( 'ต้องการยกเลิกบิลนี้และล้างตะกร้าหรือไม่ ?' ),
                onAction( action ) {
                    if ( action ) {
                        POS.reset();
                    }
                },
            });
        },
    },
};
</script>

<style scoped>
.ns-pos-shortcut-bar {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    background: #0f3232;
    border-bottom: 2px solid #54a2a2;
}

.ns-pos-shortcut-bar button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid #54a2a2;
    background: rgba(84, 162, 162, 0.2);
    color: #c8f0f0;
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.15s;
}

.ns-pos-shortcut-bar button:hover {
    background: #54a2a2;
    color: #fff;
    box-shadow: 0 2px 8px rgba(84, 162, 162, 0.4);
    transform: translateY(-1px);
}

.ns-pos-shortcut-bar button:active {
    transform: translateY(1px);
}

.ns-pos-shortcut-bar button b {
    background: #54a2a2;
    color: #fff;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
}

/**
 * The key is bound to another action through the POS settings,
 * so only the button itself triggers this shortcut.
 */
.ns-pos-shortcut-bar button b.is-disabled {
    background: transparent;
    border: 1px dashed #54a2a2;
    color: #54a2a2;
    padding: 1px 5px;
}
</style>

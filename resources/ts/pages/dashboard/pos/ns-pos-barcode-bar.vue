<template>
    <div id="pos-barcode-bar" class="w-full pb-2">
        <div class="border rounded flex overflow-hidden bg-input-background">
            <button :title="__( 'Search for products.' )" @click="openSearchPopup()" class="w-10 h-10 border-r outline-hidden">
                <i class="las la-search"></i>
            </button>
            <button :title="__( 'Toggle merging similar products.' )" @click="posToggleMerge()" :class="settings.ns_pos_items_merge ? 'pos-button-clicked' : ''" class="outline-hidden w-10 h-10 border-r">
                <i class="las la-compress-arrows-alt"></i>
            </button>
            <template v-if="options.ns_pos_barcode_reader_type === 'wireless'">
                <button v-if="! settings.marketplace_connected" :title="__( 'Connect/Disconnect wireless barcode reader.' )"
                    @click="inviteToMyRGBFConnexion()"
                    :class="wirelessBarcodeConnected ? 'text-blue-500' : 'text-red-500'"
                    class="outline-hidden border-r">
                    <div class="animate-pulse bg-red-500/10 px-2 h-10 flex items-center justify-center">
                        <i class="las la-exclamation-triangle text-lg animate-pulse"></i>
                    </div>
                </button>
                <template v-else>
                    <ns-pos-grid-wireless-barcode></ns-pos-grid-wireless-barcode>
                </template>
            </template>
            <template v-else>
                <button :title="__( 'Toggle auto focus.' )"
                    @click="options.ns_pos_force_autofocus = ! options.ns_pos_force_autofocus"
                    :class="options.ns_pos_force_autofocus ? 'pos-button-clicked' : ''"
                    class="outline-hidden w-10 h-10 border-r">
                    <i class="las la-barcode"></i>
                </button>
            </template>
            <input
                ref="search"
                v-model="barcode"
                @keydown="recordKey"
                @keyup.enter="submitSearch( barcode )"
                type="text"
                :placeholder="__( 'Scan or type a barcode...' )"
                class="flex-auto outline-hidden px-2">
        </div>
        <div style="height: 0px">
            <div v-if="isLoading" class="fade-in-entrance ns-loader">
                <div class="bar"></div>
            </div>
        </div>
    </div>
</template>
<script lang="ts">
import { nsHttpClient, nsSnackBar } from '../../../bootstrap';
import nsPosSearchProductVue from '~/popups/ns-pos-search-product.vue';
import { __ } from '~/libraries/lang';
import NsPosGridWirelessBarcode from './ns-pos-grid-wireless-barcode.vue';
import { ProductUnitPromise } from './queues/products/product-unit';
import { Popup } from '~/libraries/popup';

declare const nsNotice;
declare const nsShortcuts;
declare const nsHooks;
declare const nsHotPress;
declare const POS;

/**
 * Standalone barcode / product search bar.
 *
 * This was extracted out of `ns-pos-grid.vue` so the POS can run as a single
 * full-width cart column without the product grid. The grid used to own the
 * barcode input, the auto-focus loop and the wireless reader subscription, so
 * hiding the grid would have silently disabled the barcode scanner.
 *
 * A successful scan adds the product to the cart directly. The confirmation
 * popup is reserved for the two cases that cannot be resolved from the barcode
 * alone: a weighed item and a product with no price.
 */
export default {
    name: 'ns-pos-barcode-bar',
    components: {
        'ns-pos-grid-wireless-barcode': NsPosGridWirelessBarcode,
    },
    data() {
        return {
            barcode: '',
            settings: {},
            options: <{ [key: string]: any }>{},
            settingsSubscriber: null,
            optionsSubscriber: null,
            wirelessStateSubscriber: null,
            wirelessBarcodeConnected: false,
            searchTimeout: null,
            interval: null,
            isLoading: false,
            keyLog: <Array<{ key: string, code: string, at: number }>>[],
            keyLogStartedAt: <number | null>null,

            /**
             * Shorter than any barcode this catalogue uses. The shortest real one is 6 characters
             * (internal codes such as "C1-75" aside, which are typed by hand and submitted with
             * Enter), while the fragments the auto-search was looking up were one to four
             * characters long.
             */
            minimumAutoSearchLength: 6,
        };
    },
    watch: {
        /**
         * The options arrive after mount, and this watcher exists so a scan already sitting
         * in the field is picked up once auto focus becomes available. It must not fire on an
         * empty field: POS.options emits on load, which would otherwise start a lookup for
         * nothing every time the till is opened.
         */
        options: {
            handler() {
                this.scheduleSearch();
            },
            deep: true,
        },
        barcode() {
            this.scheduleSearch();
        },
    },
    mounted() {
        this.settingsSubscriber = POS.settings.subscribe( settings => {
            this.settings = settings;
        });

        this.optionsSubscriber = POS.options.subscribe( options => {
            this.options = options;
        });

        this.wirelessStateSubscriber = POS.wirelessBarcodeState.property( 'barcode' ).subscribe( state => {
            if ( typeof state === 'string' && state.length > 0 ) {
                this.submitSearch( state );
            }
        });

        this.interval = setInterval( () => this.checkFocus(), 500 );

        this.registerShortcuts();

        /**
         * Reset the stored wireless barcode after the cart changes, otherwise
         * scanning the same barcode twice in a row would not emit a new value.
         */
        nsHooks.addAction( 'ns-after-cart-changed', 'ns-pos-barcode-bar', () => {
            POS.wirelessBarcodeState.update({
                barcode: ''
            });
        });
    },
    unmounted() {
        clearInterval( this.interval );
        clearTimeout( this.searchTimeout );

        this.settingsSubscriber.unsubscribe();
        this.optionsSubscriber.unsubscribe();
        this.wirelessStateSubscriber.unsubscribe();
    },
    methods: {
        __,

        registerShortcuts() {
            if ( typeof nsShortcuts === 'undefined' || nsShortcuts === null ) {
                return;
            }

            for ( let shortcut in nsShortcuts ) {
                if ([ 'ns_pos_keyboard_quick_search' ].includes( shortcut ) ) {
                    nsHotPress
                        .create( 'search-popup' )
                        .whenNotVisible([ '.is-popup', '#product-search' ])
                        .whenPressed( nsShortcuts[ shortcut ] !== null ? nsShortcuts[ shortcut ].join( '+' ) : null, ( event ) => {
                            event.preventDefault();
                            this.openSearchPopup();
                        });
                }

                if ([ 'ns_pos_keyboard_toggle_merge' ].includes( shortcut ) ) {
                    nsHotPress
                        .create( 'toggle-merge' )
                        .whenNotVisible([ '.is-popup' ])
                        .whenPressed( nsShortcuts[ shortcut ] !== null ? nsShortcuts[ shortcut ].join( '+' ) : null, ( event ) => {
                            event.preventDefault();
                            this.posToggleMerge();
                        });
                }
            }
        },

        posToggleMerge() {
            POS.set( 'ns_pos_items_merge', ! this.settings.ns_pos_items_merge );
        },

        /**
         * Keeps the last burst of keys the field received, so a reader that produces nothing
         * usable can be told apart from a reader that produces the wrong characters. Held in
         * memory only and read back through window.nsLastScanKeys when diagnosing a till.
         */
        recordKey( event ) {
            const now = Date.now();

            if ( this.keyLogStartedAt === null || now - this.keyLogStartedAt > 1500 ) {
                this.keyLog = [];
                this.keyLogStartedAt = now;
            }

            this.keyLog.push( {
                key: event.key,
                code: event.code,
                at: now - this.keyLogStartedAt,
            } );

            window.nsLastScanKeys = this.keyLog;
        },

        /**
         * Runs a lookup shortly after the digits stop arriving, which is how a reader that
         * sends no terminator still works. Silent by design: nothing is reported when the
         * field is empty, because this path is driven by state changes rather than by the
         * cashier asking for anything.
         *
         * It used to fire 200ms after any keystroke with no length check, which meant it ran
         * against half of a barcode while the reader was still typing the rest. The access log
         * shows what that produced at the counter: lookups for "1", "12", "124", "00", "19" and
         * 28 other fragments, each answered 404 and each raising a red error over the till —
         * and four cases where the fragment of a code was looked up seconds before the same
         * code, complete, was found. The cashier sees a wall of errors on products that scan
         * perfectly well.
         *
         * A reader sends its digits in one burst, so waiting until the burst has actually
         * stopped, and until there is enough of a code to be one, removes the fragments without
         * touching the readers this path exists for.
         */
        scheduleSearch() {
            if ( ! this.options.ns_pos_force_autofocus ) {
                return;
            }

            clearTimeout( this.searchTimeout );

            const value = String( this.barcode ).trim();

            if ( value.length < this.minimumAutoSearchLength ) {
                return;
            }

            this.searchTimeout = setTimeout( () => {
                /**
                 * Checked again on the way out: the digits kept coming while this was waiting,
                 * so what is in the field now is what matters, not what triggered the timer.
                 */
                if ( String( this.barcode ).trim().length < this.minimumAutoSearchLength ) {
                    return;
                }

                this.submitSearch( this.barcode, false );
            }, 450 );
        },

        openSearchPopup() {
            Popup.show( nsPosSearchProductVue );
        },

        /**
         * Keeps the caret inside the barcode field so a USB scanner
         * (keyboard emulation) always types into it. Skipped while a popup
         * is open so the popup keeps its own focus.
         */
        checkFocus() {
            if ( this.options.ns_pos_force_autofocus ) {
                const popup = document.querySelectorAll( '.is-popup' );

                if ( popup.length === 0 && this.$refs.search ) {
                    this.$refs.search.focus();
                }
            }
        },

        /**
         * Reached from three places: the Enter a keyboard-emulating reader sends after the
         * digits, the debounce that runs while auto focus is on, and the wireless reader
         * subscription. The guard stops the same code being looked up twice when the Enter
         * and the debounce land together, which would add the item to the cart twice.
         */
        submitSearch( rawValue, userInitiated = true ) {
            if ( rawValue === undefined || rawValue === null ) {
                return;
            }

            /**
             * Readers append their own terminator and some prepend a space. Left in place a
             * lone space passes a plain length check, reaches the server as a bare trailing
             * slash and comes back 404, which looks like the product is missing rather than
             * like nothing was decoded.
             */
            const value = String( rawValue ).trim();

            if ( value.length === 0 ) {
                this.barcode = '';

                /**
                 * Only worth saying when the cashier pressed Enter or a reader fired, since
                 * that is the only time someone is waiting for an answer.
                 */
                if ( userInitiated ) {
                    nsSnackBar.error( __( 'The reader sent no barcode. Scan again, and check the label is not damaged.' ) );
                }

                return;
            }

            if ( this.isLoading ) {
                return;
            }

            clearTimeout( this.searchTimeout );

            this.isLoading = true;

            /**
             * Encoded because internal codes are not all digits: values such as "C1-75" and
             * "207*39" exist in this catalogue, and a raw slash, space or hash in the path
             * would silently change which route is matched.
             */
            /**
             * The open till is named on the request, because it is the till that decides which
             * branch's shelf a scan is answered from. Left unsaid, the server has to fall back to the
             * branch picker at the top of the screen, which is how a scan at one branch was answered
             * with another branch's price — or with nothing at all for goods this branch stocks and
             * that one does not.
             */
            const register = this.settings.register;
            const registerQuery = register && register.id ? `?register_id=${ encodeURIComponent( register.id ) }` : '';

            const url = nsHooks.applyFilters(
                'ns-pos-submit-search-url',
                `/api/products/search/using-barcode/${ encodeURIComponent( value ) }${ registerQuery }`,
                value
            );

            nsHttpClient.get( url )
                .subscribe({
                    next: async ( result: any ) => {
                        this.isLoading = false;
                        this.barcode = '';

                        const product = <{ [key: string]: any }>{};

                        /**
                         * When the unit is not resolved server side but we have
                         * enough information, ask the user which unit quantity applies.
                         */
                        if ( result.unit === null && result.product.id ) {
                            const fetchUnitPromise = new ProductUnitPromise({
                                $original: () => result.product,
                            });

                            try {
                                const fetchResult = <{ unit_quantity_id: number, unit_name: string, $quantities: () => any }>( await fetchUnitPromise.run() );
                                const unitQuantityId = fetchResult.unit_quantity_id;
                                result.unitQuantity = result.product.unit_quantities.find( uq => uq.id === unitQuantityId );
                                result.unit = result.unitQuantity.unit;
                            } catch ( error ) {
                                return nsSnackBar.error( __( 'An unexpected error occurred while fetching the unit quantity for this product.' ) );
                            }
                        }

                        product.name = result.product.name;
                        product.id = result.product.id;
                        product.product_type = result.product.product_type;
                        product.rate = result.product.rate;
                        product.tax_group_id = result.product.tax_group_id;
                        product.tax_type = result.product.tax_type;
                        product.unit_id = result.unit.id;
                        product.unit_price = result.unitQuantity.sale_price;
                        product.price_gross = result.unitQuantity.sale_price_gross;
                        product.price_net = result.unitQuantity.sale_price_net;
                        product.unit_name = result.unit.name;
                        product.unitQuantity = result.unitQuantity;

                        const scaleInfo = result.scale ? result.scale : null;

                        /**
                         * A scan goes straight into the cart. Asking the cashier to confirm
                         * a popup for every item makes a queue crawl, and the scanner has
                         * already identified the product and its price.
                         *
                         * Two cases still need the popup: a weighed item, where the weight
                         * or amount encoded in the scale barcode has to be applied, and a
                         * product priced at zero, which would otherwise be handed over for
                         * nothing without anyone noticing.
                         */
                        const needsConfirmation = scaleInfo !== null
                            || Number( product.unit_price ) <= 0;

                        if ( ! needsConfirmation ) {
                            POS.addToCart( product );

                            return;
                        }

                        import( '~/popups/ns-pos-product-info.vue' ).then( component => {
                            Popup.show( component.default, {
                                product: product,
                                scaleInfo: scaleInfo,
                            });
                        }).catch( () => {
                            nsSnackBar.error( __( 'Unable to load the product details popup.' ) );
                        });
                    },
                    error: ( error ) => {
                        this.isLoading = false;
                        this.barcode = '';

                        /**
                         * Only the lookup someone is waiting for is allowed to complain. The
                         * background one runs on its own while the digits are still arriving, and
                         * a failure there says nothing about the scan the cashier is making — it
                         * was the source of the errors that appeared on products which then
                         * scanned perfectly well a moment later.
                         */
                        if ( ! userInitiated ) {
                            return;
                        }

                        /**
                         * The code is quoted back so the cashier can see what the reader
                         * actually produced. Without it a mismatch between the label and the
                         * stored barcode is indistinguishable from the product being absent.
                         */
                        nsSnackBar.error(
                            `${ error.message } [${ value }]`,
                            null,
                            { duration: 6000, type: 'error' }
                        );
                    },
                });
        },

        inviteToMyRGBFConnexion() {
            nsNotice.info(
                __( 'Authentication Required' ),
                __( 'You need to connect your installation to My RGBF for using websocket features.' ), {
                    actions: {
                        close: {
                            type: 'info',
                            label: __( 'No thanks' ),
                        },
                        confirm: {
                            label: __( 'Continue' ),
                            type: 'error',
                            onClick: () => {
                                const settings = POS.settings.getValue();
                                document.location = settings.urls.marketplace_url;
                            },
                        },
                    },
                }
            );
        },
    },
}
</script>

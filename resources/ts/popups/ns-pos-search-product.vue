<template>
    <div id="product-search" class="ns-box shadow-lg w-95vw h-95vh md:h-[60vh] md:w-half flex flex-col overflow-hidden">
        <div class="p-2 border-b ns-box-header flex justify-between items-center">
            <h3 class="text-fontcolor">{{ __( 'Search Product' ) }}</h3>
            <div>
                <ns-close-button @click="popup.close()"></ns-close-button>
            </div>
        </div>
        <div class="flex-auto overflow-hidden flex flex-col">
            <div class="p-2 border-b ns-box-body">
                <div class="flex input-group info border-2 rounded overflow-hidden">
                    <input aria-label="ค้นหาสินค้า" @keyup.enter.stop="search()" v-model="searchValue" ref="searchField" type="text" class="p-2  outline-hidden flex-auto text-font">
                    <button @click="search()" class="px-2">{{ __( 'Search' ) }}</button>
                </div>
            </div>
            <p class="p-2 text-fontcolor text-sm">สแกนบาร์โค้ดหรือพิมพ์ชื่อเพื่อดูรายละเอียด แล้วกดเลือกรายการเมื่อต้องการเพิ่มเข้าบิล</p>
            <div class="overflow-y-auto ns-scrollbar flex-auto relative">
                <ul class="ns-vertical-menu">
                    <li v-for="product of products" :key="product.id" @click="addToCart( product )" class="cursor-pointer p-2 flex justify-between border-b hover:bg-gray-100 dark:hover:bg-gray-800">
                        <div class="flex-1 min-w-0">
                            <h2 class="text-fontcolor font-semibold truncate">{{ product.name }}</h2>
                            <div class="flex flex-wrap gap-3 mt-1">
                                <small class="text-blue-600 dark:text-blue-400 text-xs font-medium font-mono">{{ __( 'Barcode' ) }}: {{ product.barcode || '-' }}</small>
                                <small class="text-soft-secondary text-xs">{{ product.category?.name }}</small>
                                <small class="text-soft-secondary text-xs">{{ productUnitName( product ) }}</small>
                            </div>
                        </div>
                        <div class="text-right flex flex-col justify-center pl-3 shrink-0">
                            <span class="text-fontcolor font-semibold">{{ productPrice( product ) }}</span>
                            <small class="text-soft-secondary text-xs">{{ __( 'In Stock' ) }}: {{ productQuantity( product ) }}</small>
                        </div>
                    </li>
                </ul>
                <ul v-if="products.length === 0">
                    <li class="text-fontcolor text-center p-2">{{ __( 'There is nothing to display. Have you started the search ?' ) }}</li>
                </ul>
                <div v-if="isLoading" class="absolute h-full w-full flex items-center justify-center z-10 top-0" style="background: rgb(187 203 214 / 29%)">
                    <ns-spinner></ns-spinner>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import popupCloser from "~/libraries/popup-closer";
import popupResolver from '~/libraries/popup-resolver';
import { nsHttpClient, nsSnackBar } from '~/bootstrap';
import { __ } from '~/libraries/lang';
import { nsCurrency } from '~/filters/currency';
import nsPosConfirmPopupVue from './ns-pos-confirm-popup.vue';
export default {
    name: 'ns-pos-search-product',
    props: [ 'popup' ],
    data() {
        return {
            searchValue: '',
            products: [],
            isLoading: false,
            debounce: null,
            requestVersion: 0,
            searchSubscription: null,
        }
    },
    watch:{
        searchValue() {
            clearTimeout( this.debounce );
            this.requestVersion++;
            this.searchSubscription?.unsubscribe();
            this.products = [];
            this.isLoading = false;
            this.debounce   =   setTimeout( () => {
                this.search();
            }, 500 );
        }
    },
    mounted() {
        this.$refs.searchField.focus();

        /**
         * hotkey can't catch esc if
         * the focus is on the field.
         */
        this.$refs.searchField.addEventListener( 'keydown', ( e ) => {
            if ( e.keyCode === 27 ) {
                this.popupResolver( false );
            }
        });

        this.popupCloser();
    },
    beforeUnmount() {
        clearTimeout( this.debounce );
        this.requestVersion++;
        this.searchSubscription?.unsubscribe();
    },
    methods: {
        __,

        nsCurrency,

        popupCloser,
        popupResolver,

        /**
         * The search endpoint returns the unit quantities the searcher's branch owns, so
         * the first row is the branch price. A product reachable by search always has one,
         * but the guard keeps a stale result from rendering "undefined" at the till.
         */
        branchUnitQuantity( product ) {
            return product.unit_quantities && product.unit_quantities.length > 0
                ? product.unit_quantities[ 0 ]
                : null;
        },

        productPrice( product ) {
            const unitQuantity  =   this.branchUnitQuantity( product );

            return unitQuantity ? nsCurrency( unitQuantity.sale_price ) : '-';
        },

        productQuantity( product ) {
            const unitQuantity  =   this.branchUnitQuantity( product );

            return unitQuantity ? unitQuantity.quantity : 0;
        },

        productUnitName( product ) {
            const unitQuantity  =   this.branchUnitQuantity( product );

            return unitQuantity && unitQuantity.unit ? unitQuantity.unit.name : '';
        },

        addToCart( product ) {
            this.popup.close();

            if ( parseInt( product.accurate_tracking ) === 1 ) {
                return Popup.show( nsPosConfirmPopupVue, {
                    title: __( 'Unable to add the product' ),
                    message: __( 'สินค้า "{product}" ไม่สามารถเพิ่มจากช่องค้นหาได้ เนื่องจากเปิดใช้ "การติดตามที่แม่นยำ" กรุณาเลือกจากตารางสินค้า' ).replace( '{product}', product.name ),
                    onAction: ( action ) => {
                        // do nothing
                    }
                });
            }

            POS.addToCart( product );
        },

        search() {
            clearTimeout( this.debounce );
            this.searchSubscription?.unsubscribe();
            const version = ++this.requestVersion;
            const query = this.searchValue.trim();
            if ( ! query ) {
                this.products = [];
                this.isLoading = false;
                return;
            }
            this.isLoading  =   true;

            /**
             * The open till travels with the search for the same reason it travels with a scan: it is
             * the till that says which branch's shelf is being looked at, not the picker at the top of
             * the screen.
             */
            const register  =   POS.get( 'register' );

            this.searchSubscription = nsHttpClient.post( '/api/products/search', {
                search: query,
                register_id: register?.id,
            })
                .subscribe({
                    next: result => {
                        if ( version !== this.requestVersion ) return;
                        this.isLoading  =   false;
                        this.products   =   result;

                        if ( this.products.length === 0 ) {
                            return nsSnackBar.info( __( 'No result to result match the search value provided.' ) );
                        }
                    },
                    error: ( error ) => {
                        if ( version !== this.requestVersion ) return;
                        this.isLoading  =   false;
                        nsSnackBar.error( error.message );
                    } 
                })
        }
    }
}
</script>
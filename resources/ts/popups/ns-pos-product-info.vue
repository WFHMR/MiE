<template>
    <div class="ns-box shadow-lg w-95vw md:w-3/5 lg:w-2/5 flex flex-col overflow-hidden">
        <div class="p-2 border-b ns-box-header flex justify-between items-center">
            <h3 class="text-fontcolor">{{ __( 'Product Information' ) }}</h3>
            <div>
                <ns-close-button @click="popup.close()"></ns-close-button>
            </div>
        </div>
        <div class="flex-auto overflow-y-auto ns-scrollbar">
            <div class="p-4 ns-box-body">
                <!-- Product Name -->
                <div class="mb-4">
                    <h2 class="text-xl font-bold text-fontcolor">{{ product.name }}</h2>
                </div>

                <!-- Product Details Grid -->
                <div class="space-y-3">
                    <!-- Price -->
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-soft-secondary">{{ __( 'Price' ) }}:</span>
                        <span class="text-2xl font-bold text-fontcolor">{{ nsCurrency( product.unit_price ) }}</span>
                    </div>

                    <!-- Stock -->
                    <div v-if="product.unitQuantity" class="flex justify-between items-center border-b pb-2">
                        <span class="text-soft-secondary">{{ __( 'Stock' ) }}:</span>
                        <span class="font-semibold text-fontcolor">{{ product.unitQuantity.quantity || 0 }} {{ product.unit_name }}</span>
                    </div>

                    <!-- Unit -->
                    <div class="flex justify-between items-center border-b pb-2">
                        <span class="text-soft-secondary">{{ __( 'Unit' ) }}:</span>
                        <span class="text-fontcolor">{{ product.unit_name }}</span>
                    </div>

                    <!-- Tax Type -->
                    <div v-if="product.tax_type" class="flex justify-between items-center border-b pb-2">
                        <span class="text-soft-secondary">{{ __( 'Tax Type' ) }}:</span>
                        <span class="text-fontcolor">{{ product.tax_type }}</span>
                    </div>

                    <!-- Gross Price (if different) -->
                    <div v-if="product.price_gross && product.price_gross !== product.unit_price" class="flex justify-between items-center border-b pb-2">
                        <span class="text-soft-secondary">{{ __( 'Gross Price' ) }}:</span>
                        <span class="text-fontcolor">{{ nsCurrency( product.price_gross ) }}</span>
                    </div>

                    <!-- Net Price (if different) -->
                    <div v-if="product.price_net && product.price_net !== product.unit_price" class="flex justify-between items-center border-b pb-2">
                        <span class="text-soft-secondary">{{ __( 'Net Price' ) }}:</span>
                        <span class="text-fontcolor">{{ nsCurrency( product.price_net ) }}</span>
                    </div>
                </div>

                <!-- Scale Barcode Info (if applicable) -->
                <div v-if="scaleInfo" class="mt-4 p-3 bg-blue-100 dark:bg-blue-900 rounded">
                    <p class="text-sm text-fontcolor">
                        <strong>{{ __( 'Scale Barcode Detected' ) }}:</strong>
                        <span v-if="scaleInfo.type === 'weight'">
                            {{ scaleInfo.value.toFixed(3) }} {{ scaleInfo.unit?.name || 'kg' }}
                        </span>
                        <span v-else-if="scaleInfo.type === 'price'">
                            {{ nsCurrency( scaleInfo.value ) }}
                        </span>
                    </p>
                </div>
            </div>
        </div>
        <div class="p-2 border-t ns-box-footer flex justify-end gap-2">
            <button @click="popup.close()" class="px-4 py-2 rounded border hover:bg-gray-100 dark:hover:bg-gray-800">
                {{ __( 'Close' ) }}
            </button>
            <button @click="addToCart()" class="px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">
                {{ __( 'Add to Cart' ) }}
            </button>
        </div>
    </div>
</template>

<script lang="ts">
import { __ } from '~/libraries/lang';
import { nsCurrency } from '~/filters/currency';
import { Popup } from '~/libraries/popup';

// POS is a global variable available at runtime
declare const POS: any;

export default {
    name: 'ns-pos-product-info',
    props: [ 'popup' ],
    data() {
        return {
            product: this.popup.params.product || {},
            scaleInfo: this.popup.params.scaleInfo || null,
        }
    },
    mounted() {
        // Auto-focus close button for easy keyboard dismiss
        this.$el.querySelector('button').focus();
    },
    methods: {
        __,
        nsCurrency,

        addToCart() {
            // If scale info exists, include quantity
            if (this.scaleInfo && this.scaleInfo.type === 'weight') {
                this.product.quantity = this.scaleInfo.value;
            } else if (this.scaleInfo && this.scaleInfo.type === 'price') {
                const unitPrice = this.product.unit_price || 0;
                if (unitPrice > 0) {
                    this.product.quantity = this.scaleInfo.value / unitPrice;
                }
            }

            POS.addToCart(this.product);
            this.popup.close();
        }
    }
}
</script>

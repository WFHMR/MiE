<template>
    <div id="ns-orders-summary" class="flex ns-box flex-auto flex-col shadow-lg rounded-2xl overflow-hidden">
        <div class="p-3 flex title ns-box-header items-center justify-between border-b">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-400 to-purple-600 text-white shadow">
                    <i class="las la-boxes text-xl"></i>
                </span>
                <div>
                    <h3 class="font-bold text-base">{{ __( 'สต็อกสินค้า' ) }}</h3>
                    <p class="text-xs text-fontcolor-soft">Low Stock / Over Stock</p>
                </div>
            </div>
            <div class="flex items-center gap-1">
                <div class="px-1">
                    <ns-icon-button class="widget-handle" className="la-expand-arrows-alt"></ns-icon-button>
                </div>
                <div class="px-1">
                    <ns-close-button @click="$emit( 'onRemove' )"></ns-close-button>
                </div>
            </div>
        </div>

        <!-- Tab Switch -->
        <div class="p-3 border-b ns-box-body">
            <div class="flex rounded-xl bg-box-elevation-background p-1 gap-1">
                <button 
                    @click="activeTab = 'low'"
                    :class="activeTab === 'low' ? 'bg-error-secondary text-white shadow-md' : 'text-fontcolor-soft hover:text-fontcolor hover:bg-box-elevation-hover'"
                    class="flex-1 py-2 px-3 rounded-lg text-xs font-semibold transition-all duration-200 text-center">
                    🔴 Low Stock ({{ lowStockItems.length }})
                </button>
                <button 
                    @click="activeTab = 'over'"
                    :class="activeTab === 'over' ? 'bg-warning-tertiary text-white shadow-md' : 'text-fontcolor-soft hover:text-fontcolor hover:bg-box-elevation-hover'"
                    class="flex-1 py-2 px-3 rounded-lg text-xs font-semibold transition-all duration-200 text-center">
                    🟡 Over Stock ({{ overStockItems.length }})
                </button>
            </div>
        </div>

        <!-- Content -->
        <div class="head flex-auto flex-col flex h-64 overflow-y-auto ns-scrollbar ns-box-body">
            <div class="h-full flex items-center justify-center" v-if="!hasLoaded">
                <ns-spinner size="8" border="4"></ns-spinner>
            </div>

            <!-- Low Stock Tab -->
            <div v-if="hasLoaded && activeTab === 'low'">
                <div v-if="lowStockItems.length === 0" class="h-full flex items-center justify-center flex-col py-8">
                    <i class="las la-check-circle text-4xl text-success-tertiary"></i>
                    <p class="text-sm text-center text-fontcolor-soft mt-2">{{ __( 'ไม่มีสินค้าใกล้หมด' ) }}</p>
                </div>
                <div v-else class="p-2 space-y-2">
                    <div v-for="item of lowStockItems" :key="item.id" 
                        class="flex items-center justify-between p-3 rounded-xl bg-box-elevation-background border border-error-primary/20">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-fontcolor truncate">{{ item.name }}</p>
                            <p class="text-xs text-fontcolor-soft">SKU: {{ item.sku || '-' }}</p>
                        </div>
                        <div class="text-right ml-2">
                            <span class="inline-flex items-center px-2 py-1 rounded-lg bg-error-primary/20 text-error-secondary text-xs font-bold">
                                {{ item.quantity }} {{ __( 'ชิ้น' ) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Over Stock Tab -->
            <div v-if="hasLoaded && activeTab === 'over'">
                <div v-if="overStockItems.length === 0" class="h-full flex items-center justify-center flex-col py-8">
                    <i class="las la-check-circle text-4xl text-success-tertiary"></i>
                    <p class="text-sm text-center text-fontcolor-soft mt-2">{{ __( 'ไม่มีสินค้าเกินสต็อก' ) }}</p>
                </div>
                <div v-else class="p-2 space-y-2">
                    <div v-for="item of overStockItems" :key="item.id" 
                        class="flex items-center justify-between p-3 rounded-xl bg-box-elevation-background border border-warning-tertiary/20">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-fontcolor truncate">{{ item.name }}</p>
                            <p class="text-xs text-fontcolor-soft">SKU: {{ item.sku || '-' }}</p>
                        </div>
                        <div class="text-right ml-2">
                            <span class="inline-flex items-center px-2 py-1 rounded-lg bg-warning-primary/30 text-warning-tertiary text-xs font-bold">
                                {{ item.quantity }} {{ __( 'ชิ้น' ) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { nsCurrency } from '~/filters/currency';
import { __ } from '~/libraries/lang';

export default {
    name: 'ns-orders-summary',
    data() {
        return {
            hasLoaded: false,
            activeTab: 'low',
            lowStockItems: [],
            overStockItems: [],
        }
    },
    mounted() {
        this.loadReport();
    },
    methods: { 
        __, 
        nsCurrency,
        loadReport() {
            this.hasLoaded = false;
            
            nsHttpClient.get( '/api/products' )
                .subscribe({
                    next: response => {
                        // response could be paginated or array
                        const products = response.data || response;
                        
                        if ( Array.isArray(products) ) {
                            // Low stock: quantity <= 10 and > 0
                            this.lowStockItems = products
                                .filter( p => {
                                    const qty = parseFloat(p.quantity || 0);
                                    return qty > 0 && qty <= 10;
                                })
                                .map( p => ({
                                    id: p.id,
                                    name: p.name,
                                    sku: p.sku,
                                    quantity: parseFloat(p.quantity || 0)
                                }))
                                .sort( (a, b) => a.quantity - b.quantity )
                                .slice(0, 10);

                            // Over stock: quantity > 100
                            this.overStockItems = products
                                .filter( p => {
                                    const qty = parseFloat(p.quantity || 0);
                                    return qty > 100;
                                })
                                .map( p => ({
                                    id: p.id,
                                    name: p.name,
                                    sku: p.sku,
                                    quantity: parseFloat(p.quantity || 0)
                                }))
                                .sort( (a, b) => b.quantity - a.quantity )
                                .slice(0, 10);
                        }
                        
                        this.hasLoaded = true;
                    },
                    error: () => {
                        this.lowStockItems = [];
                        this.overStockItems = [];
                        this.hasLoaded = true;
                    }
                });
        }
    },
}
</script>

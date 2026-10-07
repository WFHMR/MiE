<template>
    <div id="ns-orders-chart" class="flex flex-auto flex-col shadow-lg ns-box rounded-2xl overflow-hidden">
        <div class="p-3 flex ns-box-header items-center justify-between border-b">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-error-primary to-error-tertiary text-white shadow">
                    <i class="las la-file-invoice-dollar text-xl"></i>
                </span>
                <div>
                    <h3 class="font-bold text-base">{{ __( 'ยอดเสียลูกหนี้' ) }}</h3>
                    <p class="text-xs text-fontcolor-soft">{{ periodLabel }}</p>
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

        <!-- Period Tabs -->
        <div class="p-3 border-b ns-box-body">
            <div class="flex rounded-xl bg-box-elevation-background p-1 gap-1">
                <button 
                    v-for="tab of tabs" 
                    :key="tab.value"
                    @click="switchPeriod(tab.value)"
                    :class="period === tab.value ? 'bg-error-secondary text-white shadow-md' : 'text-fontcolor-soft hover:text-fontcolor hover:bg-box-elevation-hover'"
                    class="flex-1 py-2 px-3 rounded-lg text-xs font-semibold transition-all duration-200 text-center">
                    {{ tab.label }}
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-auto ns-box-body p-4">
            <div v-if="isLoading" class="flex items-center justify-center py-8">
                <ns-spinner size="8" border="4"></ns-spinner>
            </div>
            <div v-else>
                <!-- Big Number -->
                <div class="text-center mb-4">
                    <h2 class="text-3xl font-black text-error-secondary">{{ nsCurrency( totalUnpaid ) }}</h2>
                    <p class="text-sm text-fontcolor-soft mt-1">{{ __( 'ยอดค้างชำระทั้งหมด' ) }}</p>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="rounded-xl bg-box-elevation-background p-3 text-center">
                        <p class="text-xs text-fontcolor-soft mb-1">{{ __( 'จำนวนรายการ' ) }}</p>
                        <h4 class="text-lg font-bold text-fontcolor">{{ unpaidCount }}</h4>
                    </div>
                    <div class="rounded-xl bg-box-elevation-background p-3 text-center">
                        <p class="text-xs text-fontcolor-soft mb-1">{{ __( 'ชำระบางส่วน' ) }}</p>
                        <h4 class="text-lg font-bold text-warning-tertiary">{{ partialCount }}</h4>
                    </div>
                </div>

                <!-- Recent Unpaid List -->
                <div v-if="unpaidOrders.length > 0" class="space-y-2 max-h-40 overflow-y-auto ns-scrollbar">
                    <div v-for="order of unpaidOrders" :key="order.id" 
                        class="flex items-center justify-between p-2 rounded-lg bg-box-elevation-background">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-fontcolor truncate">{{ order.code }}</p>
                            <p class="text-xs text-fontcolor-soft">{{ order.created_at }}</p>
                        </div>
                        <span class="text-sm font-bold text-error-secondary whitespace-nowrap ml-2">{{ nsCurrency( order.total ) }}</span>
                    </div>
                </div>
                <div v-else class="text-center py-4">
                    <i class="las la-check-circle text-4xl text-success-tertiary"></i>
                    <p class="text-sm text-fontcolor-soft mt-2">{{ __( 'ไม่มียอดค้างชำระ' ) }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { __ } from '~/libraries/lang';
import { nsCurrency, nsRawCurrency } from '~/filters/currency';

export default {
    name: 'ns-orders-chart',
    data() {
        return {
            period: 'day',
            isLoading: false,
            totalUnpaid: 0,
            unpaidCount: 0,
            partialCount: 0,
            unpaidOrders: [],
            tabs: [
                { value: 'day', label: __( 'วันนี้' ) },
                { value: 'month', label: __( 'เดือนนี้' ) },
                { value: 'year', label: __( 'ปีนี้' ) },
            ],
        }
    },
    computed: {
        periodLabel() {
            if ( this.period === 'day' ) return __( 'วันนี้' );
            if ( this.period === 'month' ) return __( 'เดือนนี้' );
            return __( 'ปีนี้' );
        }
    },
    mounted() {
        this.loadData();
    },
    methods: {
        __,
        nsCurrency,
        nsRawCurrency,
        switchPeriod( period ) {
            this.period = period;
            this.loadData();
        },
        loadData() {
            this.isLoading = true;

            // Fetch recent orders and filter unpaid/partially paid
            nsHttpClient.get( '/api/dashboard/recent-orders' )
                .subscribe({
                    next: orders => {
                        const now = new Date();
                        let filtered = orders;

                        if ( this.period === 'day' ) {
                            filtered = orders.filter( o => {
                                const d = new Date( o.created_at );
                                return d.toDateString() === now.toDateString();
                            });
                        } else if ( this.period === 'month' ) {
                            filtered = orders.filter( o => {
                                const d = new Date( o.created_at );
                                return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
                            });
                        }
                        // year = all orders returned (API returns recent ones)

                        const unpaid = filtered.filter( o => o.payment_status === 'unpaid' || o.payment_status === 'partially_paid' );
                        
                        this.unpaidOrders = unpaid.slice(0, 5);
                        this.unpaidCount = unpaid.filter( o => o.payment_status === 'unpaid' ).length;
                        this.partialCount = unpaid.filter( o => o.payment_status === 'partially_paid' ).length;
                        this.totalUnpaid = unpaid.reduce( (sum, o) => sum + parseFloat(o.total || 0), 0 );
                        this.isLoading = false;
                    },
                    error: () => {
                        this.unpaidOrders = [];
                        this.unpaidCount = 0;
                        this.partialCount = 0;
                        this.totalUnpaid = 0;
                        this.isLoading = false;
                    }
                });
        }
    },
}
</script>

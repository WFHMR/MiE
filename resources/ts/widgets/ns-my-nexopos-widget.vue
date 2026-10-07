<template>
    <div id="ns-my-nexopos-widget" class="ns-box flex flex-auto flex-col overflow-hidden rounded-2xl border border-box-edge bg-box-background text-fontcolor shadow-lg">
        <div class="ns-box-header flex items-center justify-between border-b border-box-edge p-3">
            <div class="flex min-w-0 items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-info-secondary to-info-tertiary text-white shadow">
                    <i class="las la-chart-line text-xl"></i>
                </span>
                <div class="min-w-0">
                    <h5 class="truncate font-bold text-base">{{ __( 'Total Sales' ) }}</h5>
                    <p class="truncate text-xs text-fontcolor-soft">{{ periodLabel }}</p>
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

        <div class="flex flex-auto flex-col p-4 gap-4">
            <!-- Period Tabs -->
            <div class="flex rounded-xl bg-box-elevation-background p-1 gap-1">
                <button 
                    v-for="tab of tabs" 
                    :key="tab.value"
                    @click="switchPeriod(tab.value)"
                    :class="period === tab.value ? 'bg-info-secondary text-white shadow-md' : 'text-fontcolor-soft hover:text-fontcolor hover:bg-box-elevation-hover'"
                    class="flex-1 py-2 px-3 rounded-lg text-xs font-semibold transition-all duration-200 text-center">
                    {{ tab.label }}
                </button>
            </div>

            <!-- Main Amount -->
            <div class="flex flex-col items-center justify-center py-4">
                <div v-if="isLoading" class="flex items-center justify-center py-6">
                    <ns-spinner size="8" border="4"></ns-spinner>
                </div>
                <div v-else class="text-center">
                    <h2 class="text-3xl font-black text-fontcolor leading-tight">
                        {{ nsCurrency( totalSales ) }}
                    </h2>
                    <p class="text-sm text-fontcolor-soft mt-2">{{ periodDescription }}</p>
                </div>
            </div>

            <!-- Stats Row -->
            <div v-if="!isLoading" class="grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-box-elevation-background p-3 text-center">
                    <p class="text-xs text-fontcolor-soft mb-1">{{ __( 'Orders' ) }}</p>
                    <h4 class="text-lg font-bold text-fontcolor">{{ totalOrders }}</h4>
                </div>
                <div class="rounded-xl bg-box-elevation-background p-3 text-center">
                    <p class="text-xs text-fontcolor-soft mb-1">{{ __( 'Customers' ) }}</p>
                    <h4 class="text-lg font-bold text-fontcolor">{{ totalCustomers }}</h4>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { __ } from '~/libraries/lang';
import { nsCurrency } from '~/filters/currency';

export default {
    name: 'ns-my-nexopos-widget',
    props: [ 'widget' ],
    data() {
        return {
            period: 'day',
            isLoading: false,
            totalSales: 0,
            totalOrders: 0,
            totalCustomers: 0,
            tabs: [
                { value: 'day', label: __( 'Today' ) },
                { value: 'month', label: __( 'เดือนนี้' ) },
                { value: 'year', label: __( 'ปีนี้' ) },
            ],
        }
    },
    computed: {
        periodLabel() {
            if ( this.period === 'day' ) return __( 'Today' );
            if ( this.period === 'month' ) return __( 'เดือนนี้' );
            return __( 'ปีนี้' );
        },
        periodDescription() {
            const now = new Date();
            if ( this.period === 'day' ) {
                return now.toLocaleDateString('th-TH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            }
            if ( this.period === 'month' ) {
                return now.toLocaleDateString('th-TH', { month: 'long', year: 'numeric' });
            }
            return now.toLocaleDateString('th-TH', { year: 'numeric' });
        }
    },
    mounted() {
        this.loadData();
    },
    methods: {
        __,
        nsCurrency,
        switchPeriod( period ) {
            this.period = period;
            this.loadData();
        },
        /**
         * One endpoint, asked for the period the tab names.
         *
         * All three tabs used to call the single-day report instead. The day tab read the paid-orders
         * column — an amount of money — as a number of bills, and a customers field that table does not
         * have, so the count was always zero. The month and year tabs both read the cumulative income
         * column, which is a running total of every day since the shop opened, so they showed the same
         * all-time figure under two different headings and neither was a month or a year.
         */
        loadData() {
            this.isLoading = true;

            nsHttpClient.get( `/api/dashboard/sales-summary?period=${this.period}` )
                .subscribe({
                    next: summary => {
                        this.totalSales = summary.income || 0;
                        this.totalOrders = summary.orders || 0;
                        this.totalCustomers = summary.customers || 0;
                        this.isLoading = false;
                    },
                    error: () => {
                        this.totalSales = 0;
                        this.totalOrders = 0;
                        this.totalCustomers = 0;
                        this.isLoading = false;
                    }
                });
        }
    },
}
</script>

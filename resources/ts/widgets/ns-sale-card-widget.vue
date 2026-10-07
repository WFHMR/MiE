<template>
    <div class="flex card-widget widget-handle flex-auto flex-col rounded-2xl shadow-lg bg-gradient-to-br from-emerald-400 to-teal-600 px-4 py-5">
        <div class="flex flex-row md:flex-col flex-auto">
            <div class="w-1/2 md:w-full flex md:flex-col md:items-start items-center justify-center">
                <div class="flex justify-between w-full items-center">
                    <div class="flex items-center gap-2">
                        <i class="las la-cash-register text-xl text-white/80"></i>
                        <h6 class="font-bold hidden text-right md:inline-block">{{ __( 'ยอดขายรวม/วัน' ) }}</h6>
                    </div>
                    <div class="flex justify-between">
                        <div class="px-1">
                            <ns-close-button @click="$emit( 'onRemove' )"></ns-close-button>
                        </div>
                    </div>
                </div>
                <h3 class="text-2xl font-black mt-1">
                    {{ nsCurrency( report.day_income || 0, 'abbreviate' ) }}
                </h3>
            </div>
            <div class="w-1/2 md:w-full flex flex-col px-2 justify-end items-end">
                <h6 class="font-bold inline-block text-right md:hidden">{{ __( 'ยอดขายรวม/วัน' ) }}</h6>
                <!--
                    day_orders_count, not day_paid_orders. The label says รายการ — a number of bills — while
                    day_paid_orders is a sum of money, so a day taking 6,948 baht over eleven bills reported
                    "6948 รายการ".
                -->
                <h4 class="text-xs text-right text-white/80">{{ report.day_orders_count || 0 }} {{ __( 'รายการ' ) }}</h4>
            </div>
        </div>
    </div>
</template>
<script>
import { nsCurrency } from '~/filters/currency';
import { __ } from '~/libraries/lang';

export default {
    name: 'ns-sale-card-widget',
    methods: {
        __,
        nsCurrency,
        loadReport() {
            nsHttpClient.get( '/api/dashboard/day' )
                .subscribe({
                    next: report => {
                        this.report     =   report;
                    },
                    error: error => {
                        this.report     =   {};
                    }
                })
        }
    },
    data() {
        return {
            report: {},
        }
    },
    mounted() {
        this.loadReport();
    }
}
</script>

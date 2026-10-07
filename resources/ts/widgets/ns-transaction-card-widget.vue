<template>
    <div class="flex card-widget widget-handle flex-auto flex-col rounded-2xl shadow-lg bg-gradient-to-br from-amber-400 to-orange-500 px-4 py-5">
        <div class="flex flex-row md:flex-col flex-auto">
            <div class="w-1/2 md:w-full flex md:flex-col md:items-start items-center justify-center">
                <div class="flex justify-between w-full items-center">
                    <div class="flex items-center gap-2">
                        <i class="las la-receipt text-xl text-white/80"></i>
                        <h6 class="font-bold hidden text-right md:inline-block">{{ __( 'บิลที่รอจ่าย' ) }}</h6>
                    </div>
                    <div class="flex justify-between">
                        <div class="px-1">
                            <ns-close-button @click="$emit( 'onRemove' )"></ns-close-button>
                        </div>
                    </div>
                </div>
                <h3 class="text-2xl font-black mt-1">
                    {{ nsCurrency( totalPending, 'abbreviate' ) }}
                </h3>
            </div>
            <div class="w-1/2 md:w-full flex flex-col px-2 justify-end items-end">
                <h6 class="font-bold inline-block text-right md:hidden">{{ __( 'บิลที่รอจ่าย' ) }}</h6>
                <h4 class="text-xs text-right text-white/80">{{ pendingCount }} {{ __( 'รายการ' ) }}</h4>
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
            nsHttpClient.get( '/api/dashboard/recent-orders' )
                .subscribe({
                    next: orders => {
                        const pending = orders.filter( o => 
                            o.payment_status === 'unpaid' || 
                            o.payment_status === 'hold' || 
                            o.payment_status === 'partially_paid'
                        );
                        this.totalPending = pending.reduce( (sum, o) => sum + parseFloat(o.total || 0), 0 );
                        this.pendingCount = pending.length;
                    },
                    error: () => {
                        this.totalPending = 0;
                        this.pendingCount = 0;
                    }
                })
        }
    },
    data() {
        return {
            totalPending: 0,
            pendingCount: 0,
        }
    },
    mounted() {
        this.loadReport();
    }
}
</script>

<template>
    <div class="flex card-widget widget-handle flex-auto flex-col rounded-lg shadow-lg bg-gradient-to-br from-green-400 to-green-600 px-3 py-5">
        <div class="flex flex-row md:flex-col flex-auto">
            <div class="w-1/2 md:w-full flex md:flex-col md:items-start items-center justify-center">
                <div class="flex justify-between w-full items-center">
                    <h6 class="font-bold hidden text-right md:inline-block">{{ __( 'Incomplete Orders' ) }}</h6>
                    <div>
                        <ns-close-button class="border-success-secondary" @click="$emit( 'onRemove' )"></ns-close-button>
                    </div>
                </div>
                <!--
                    total_unpaid and today_unpaid are what the endpoint answers now. They were being read
                    before too, but the endpoint handed back a row of the dashboard_days table and that
                    table carries neither name, so this card printed zero for every shop on every day.
                -->
                <h3 class="text-2xl font-black">
                    {{ nsCurrency( report.total_unpaid || 0, 'abbreviate' ) }}
                </h3>
            </div>
            <div class="w-1/2 md:w-full flex flex-col px-2 justify-end items-end">
                <h6 class="font-bold inline-block text-right md:hidden">{{ __( 'Incomplete Orders' ) }}</h6>
                <h4 class="text-xs text-right">+{{ nsCurrency( report.today_unpaid || 0 ) }} {{ __( 'Today' ) }}</h4>
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
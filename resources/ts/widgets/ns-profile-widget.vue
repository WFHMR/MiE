<template>
    <div id="ns-profile" class="flex ns-box flex-auto flex-col shadow-lg rounded-2xl overflow-hidden">
        <div class="flex-auto">
            <div class="head ns-box-header border-b w-full flex justify-between items-center p-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-400 to-indigo-600 text-white shadow">
                        <i class="las la-store text-xl"></i>
                    </span>
                    <div>
                        <h5 class="font-bold text-base">{{ __( 'ยอดรายได้สาขา' ) }}</h5>
                        <p class="text-xs text-fontcolor-soft">{{ __( 'วันนี้' ) }}</p>
                    </div>
                </div>
                <div class="flex -mx-1">
                    <div class="px-1">
                        <ns-icon-button class="widget-handle" className="la-expand-arrows-alt"></ns-icon-button>
                    </div>
                    <div class="px-1">
                        <ns-icon-button class-name="la-sync-alt" @click="loadData()"></ns-icon-button>
                    </div>
                    <div class="px-1">
                        <ns-close-button @click="$emit( 'onRemove' )"></ns-close-button>
                    </div>
                </div>
            </div>
            <div class="body ns-box-body p-3">
                <div v-if="!hasLoaded" class="flex items-center justify-center py-8">
                    <ns-spinner size="8" border="4"></ns-spinner>
                </div>
                <div v-else-if="branches.length === 0" class="flex flex-col items-center justify-center py-8 text-center">
                    <i class="las la-store text-4xl text-fontcolor-soft"></i>
                    <p class="mt-2 text-sm text-fontcolor-soft">{{ __( 'ยังไม่มีสาขา' ) }}</p>
                </div>
                <div v-else class="space-y-3">
                    <div v-for="branch of branches" :key="branch.id"
                        class="flex items-center justify-between p-3 rounded-xl bg-box-elevation-background border border-box-edge transition-all duration-200 hover:shadow-md">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-white shadow-sm text-sm font-bold"
                                :class="colorFor( branch )">
                                <i :class="iconFor( branch )"></i>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-fontcolor">{{ branch.name }}</p>
                                <p class="text-xs text-fontcolor-soft">{{ branch.orders }} {{ __( 'รายการ' ) }}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-fontcolor">{{ nsCurrency( branch.revenue ) }}</p>
                        </div>
                    </div>

                    <!-- Total -->
                    <div class="flex items-center justify-between p-3 rounded-xl bg-gradient-to-r from-info-secondary/10 to-info-tertiary/10 border border-info-secondary/30">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-info-secondary text-white shadow-sm">
                                <i class="las la-calculator text-lg"></i>
                            </span>
                            <p class="text-sm font-bold text-fontcolor">{{ __( 'รวมทั้งหมด' ) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-base font-black text-info-tertiary">{{ nsCurrency( totalRevenue ) }}</p>
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
    name: 'ns-profile-widget',
    data() {
        return {
            hasLoaded: false,

            /**
             * Filled from the API. This used to hold six branch names written into
             * the component, with the whole day's income put against the second one
             * and zero against the rest, which showed five shops that did not exist
             * and hid the fact that the total was never broken down.
             */
            branches: [],
        }
    },
    computed: {
        totalRevenue() {
            return this.branches.reduce( ( sum, branch ) => sum + branch.revenue, 0 );
        }
    },
    mounted() {
        this.loadData();
    },
    methods: {
        __,
        nsCurrency,

        /**
         * The API returns what a branch is, not how it should look, so the icon is
         * chosen here. A warehouse does not sell, so showing it a till icon would
         * misrepresent a zero as a shop that took nothing today.
         */
        iconFor( branch ) {
            if ( branch.is_main_warehouse ) {
                return 'las la-warehouse';
            }

            return branch.is_main ? 'las la-store-alt' : 'las la-store';
        },

        colorFor( branch ) {
            if ( branch.is_main_warehouse ) {
                return 'bg-gray-500';
            }

            return branch.is_main ? 'bg-emerald-500' : 'bg-blue-500';
        },

        loadData() {
            this.hasLoaded = false;

            nsHttpClient.get( '/api/dashboard/branches-revenue' )
                .subscribe({
                    next: branches => {
                        this.branches = ( branches || [] ).map( branch => ({
                            ...branch,
                            revenue: parseFloat( branch.revenue || 0 ),
                            orders: parseInt( branch.orders || 0 ),
                        }));

                        this.hasLoaded = true;
                    },
                    error: () => {
                        /**
                         * Left empty rather than falling back to a total, so a failed
                         * request reads as "no data" instead of as a real figure
                         * attributed to a branch it may not belong to.
                         */
                        this.branches = [];
                        this.hasLoaded = true;
                    }
                });
        }
    }
}
</script>

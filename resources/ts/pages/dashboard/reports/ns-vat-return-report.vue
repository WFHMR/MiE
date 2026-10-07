<template>
    <div id="report-section" class="px-4">
        <div class="flex -mx-2 flex-wrap items-end">
            <div class="px-2">
                <ns-field :field="monthField"></ns-field>
            </div>
            <div class="px-2">
                <button @click="loadReport()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i class="las la-sync-alt text-xl"></i>
                    <span class="pl-2">{{ __( 'Load' ) }}</span>
                </button>
            </div>
            <div class="px-2">
                <button @click="printSaleReport()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i class="las la-print text-xl"></i>
                    <span class="pl-2">{{ __( 'Print' ) }}</span>
                </button>
            </div>
        </div>
        <div id="sale-report" class="anim-duration-500 fade-in-entrance">
            <div class="flex w-full">
                <div class="my-4 flex justify-between w-full">
                    <div class="text-fontcolor-soft">
                        <ul>
                            <li class="pb-1 border-b border-dashed">{{ __( 'เอกสาร: รายงานภาษีขาย (ภ.พ.30)' ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'รอบภาษี: {period}' ).replace( '{period}', periodLabel ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'เลขประจำตัวผู้เสียภาษี: {taxId}' ).replace( '{taxId}', taxId || '—' ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'สาขา: {branch}' ).replace( '{branch}', branchCode || '—' ) }}</li>
                        </ul>
                    </div>
                    <div>
                        <img class="w-24" :src="storeLogo" :alt="storeName">
                    </div>
                </div>
            </div>

            <div class="bg-box-background shadow rounded my-4">
                <div class="border-b border-box-edge">
                    <table class="table ns-table w-full">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'รายการ' ) }}</th>
                                <th width="180" class="text-fontcolor border p-2 text-right">{{ __( 'จำนวนเงิน' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr :key="index" v-for="( summary, index ) of report.summary" class="font-semibold">
                                <td class="p-2 border border-box-edge">{{ summary.label }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( summary.total ) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="text-fontcolor font-semibold">
                            <tr>
                                <td class="p-2 border border-box-edge text-font">{{ __( 'ยอดขายรวม (ไม่รวมภาษีขาย)' ) }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( report.total_sales ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div v-if="report.invoices" class="bg-box-background shadow rounded my-4 p-4 text-fontcolor">
                <h4 class="font-semibold mb-2">{{ __( 'ใบกำกับภาษีในรอบนี้' ) }}</h4>
                <ul class="text-sm">
                    <li>{{ __( 'ออกแล้ว: {count} ฉบับ' ).replace( '{count}', report.invoices.issued ) }}</li>
                    <li>{{ __( 'ยกเลิก: {count} ฉบับ' ).replace( '{count}', report.invoices.cancelled ) }}</li>
                </ul>
            </div>

            <div v-if="report.notes && report.notes.length > 0" class="my-4">
                <ns-notice color="warning">
                    <template v-slot:title>{{ __( 'ข้อควรทราบก่อนยื่นแบบ' ) }}</template>
                    <template v-slot:description>
                        <ul class="list-disc pl-4">
                            <li :key="index" v-for="( note, index ) of report.notes">{{ note }}</li>
                        </ul>
                    </template>
                </ns-notice>
            </div>
        </div>
    </div>
</template>
<script>
import moment from "moment";
import { nsHttpClient, nsSnackBar } from '~/bootstrap';
import { __ } from '~/libraries/lang';
import { nsCurrency } from '~/filters/currency';

export default {
    name: 'ns-vat-return-report',
    props: [ 'storeName', 'storeLogo', 'taxId', 'branchCode' ],
    data() {
        return {
            /**
             * A PP30 return covers a calendar month, so the picker is a month rather than
             * a free date range. The exact boundaries are derived here and re-derived on
             * the server, which keeps a browser in another timezone from shifting the
             * period across midnight on the 1st.
             */
            monthField: {
                label: __( 'เดือนภาษี' ),
                type: 'text',
                name: 'tax_month',
                description: __( 'รูปแบบ ปปปป-ดด เช่น 2026-09' ),
                value: moment( ns.date.current ).format( 'YYYY-MM' ),
            },
            report: {
                summary: [],
                total_sales: 0,
                notes: [],
            },
            ns: window.ns,
        }
    },
    computed: {
        periodLabel() {
            if ( ! this.report.period ) {
                return this.monthField.value;
            }

            return `${ moment( this.report.period.start ).format( 'DD/MM/YYYY' ) } — ${ moment( this.report.period.end ).format( 'DD/MM/YYYY' ) }`;
        }
    },
    mounted() {
        this.loadReport();
    },
    methods: {
        __,
        nsCurrency,

        printSaleReport() {
            this.$htmlToPaper( 'sale-report' );
        },

        loadReport() {
            const month     =   moment( this.monthField.value, 'YYYY-MM', true );

            if ( ! month.isValid() ) {
                return nsSnackBar.error( __( 'เดือนภาษีไม่ถูกต้อง ใช้รูปแบบ ปปปป-ดด เช่น 2026-09' ) );
            }

            nsHttpClient.post( '/api/reports/vat-return', {
                startDate: month.clone().startOf( 'month' ).format( 'YYYY-MM-DD HH:mm:ss' ),
                endDate: month.clone().endOf( 'month' ).format( 'YYYY-MM-DD HH:mm:ss' ),
            }).subscribe({
                next: report => {
                    this.report     =   report;
                },
                error: ( error ) => {
                    nsSnackBar.error( error.message );
                }
            });
        },
    }
}
</script>

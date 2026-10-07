<template>
    <div id="report-section" class="px-4">
        <div class="flex -mx-2 flex-wrap items-end">
            <div class="px-2">
                <ns-field :field="asOfField"></ns-field>
            </div>
            <div class="px-2">
                <button @click="loadReport()" data-action="load-report" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i class="las la-sync-alt text-xl"></i>
                    <span class="pl-2">{{ __( 'Load' ) }}</span>
                </button>
            </div>
            <div class="px-2">
                <button @click="printReport()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i class="las la-print text-xl"></i>
                    <span class="pl-2">{{ __( 'Print' ) }}</span>
                </button>
            </div>
        </div>
        <div id="receivables-report" class="anim-duration-500 fade-in-entrance">
            <div class="flex w-full">
                <div class="my-4 flex justify-between w-full">
                    <div class="text-fontcolor-soft">
                        <ul>
                            <li class="pb-1 border-b border-dashed">{{ __( 'เอกสาร: รายงานลูกหนี้การค้า (อายุหนี้)' ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'ณ วันที่: {date}' ).replace( '{date}', asOfLabel ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'จำนวนบิลค้างชำระ: {count}' ).replace( '{count}', report.count ) }}</li>
                        </ul>
                    </div>
                    <div>
                        <img class="w-24" :src="storeLogo" :alt="storeName">
                    </div>
                </div>
            </div>

            <!--
                The bands first: a shop reads the shape of its exposure before it reads any
                individual name. A total sitting mostly in "over 90 days" is a different
                problem from the same total sitting in "not due yet".
            -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2 my-4">
                <div
                    :key="bucket.key"
                    v-for="bucket of report.buckets"
                    :data-bucket="bucket.key"
                    :class="bucketClass( bucket.key )"
                    class="rounded border p-3 flex flex-col justify-between">
                    <span class="text-sm">{{ bucket.label }}</span>
                    <span class="text-xl font-bold">{{ nsCurrency( bucket.total ) }}</span>
                </div>
            </div>

            <div class="bg-box-background shadow rounded my-4">
                <div class="border-b border-box-edge p-2 text-fontcolor font-semibold">
                    {{ __( 'สรุปตามลูกค้า' ) }}
                </div>
                <div class="overflow-x-auto">
                    <table class="table ns-table w-full" data-role="receivables-by-customer">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'ลูกค้า' ) }}</th>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'เบอร์โทร' ) }}</th>
                                <th class="text-fontcolor border p-2 text-right">{{ __( 'เครดิต (วัน)' ) }}</th>
                                <th class="text-fontcolor border p-2 text-right">{{ __( 'วงเงิน' ) }}</th>
                                <th class="text-fontcolor border p-2 text-right">{{ __( 'ค้างนานสุด (วัน)' ) }}</th>
                                <th :key="bucket.key" v-for="bucket of report.buckets" class="text-fontcolor border p-2 text-right">{{ bucket.label }}</th>
                                <th class="text-fontcolor border p-2 text-right">{{ __( 'รวม' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr v-if="report.customers.length === 0">
                                <td :colspan="6 + report.buckets.length" class="p-4 border text-center">{{ __( 'ไม่มียอดค้างชำระ' ) }}</td>
                            </tr>
                            <tr :key="customer.customer_id" v-for="customer of report.customers">
                                <td class="p-2 border border-box-edge">{{ customer.name }}</td>
                                <td class="p-2 border border-box-edge">{{ customer.phone || '—' }}</td>
                                <td class="p-2 border text-right">{{ customer.payment_term_days || '—' }}</td>
                                <td class="p-2 border text-right">{{ customer.credit_limit_amount > 0 ? nsCurrency( customer.credit_limit_amount ) : __( 'ไม่จำกัด' ) }}</td>
                                <td class="p-2 border text-right">{{ customer.oldest_days }}</td>
                                <td :key="bucket.key" v-for="bucket of report.buckets" class="p-2 border text-right">
                                    {{ customer.buckets[ bucket.key ] > 0 ? nsCurrency( customer.buckets[ bucket.key ] ) : '—' }}
                                </td>
                                <td class="p-2 border text-right font-semibold">{{ nsCurrency( customer.total ) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="text-fontcolor font-semibold">
                            <tr>
                                <td colspan="5" class="p-2 border border-box-edge">{{ __( 'รวมทั้งหมด' ) }}</td>
                                <td :key="bucket.key" v-for="bucket of report.buckets" class="p-2 border text-right">{{ nsCurrency( bucket.total ) }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( report.total ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="bg-box-background shadow rounded my-4">
                <div class="border-b border-box-edge p-2 text-fontcolor font-semibold">
                    {{ __( 'รายบิล (เรียงตามค้างนานสุด)' ) }}
                </div>
                <div class="overflow-x-auto">
                    <table class="table ns-table w-full" data-role="receivables-by-order">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'เลขที่บิล' ) }}</th>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'ลูกค้า' ) }}</th>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'วันที่ขาย' ) }}</th>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'ครบกำหนด' ) }}</th>
                                <th class="text-fontcolor border p-2 text-right">{{ __( 'ค้าง (วัน)' ) }}</th>
                                <th class="text-fontcolor border p-2 text-right">{{ __( 'ยอดบิล' ) }}</th>
                                <th class="text-fontcolor border p-2 text-right">{{ __( 'ชำระแล้ว' ) }}</th>
                                <th class="text-fontcolor border p-2 text-right">{{ __( 'ค้างชำระ' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr v-if="report.orders.length === 0">
                                <td colspan="8" class="p-4 border text-center">{{ __( 'ไม่มียอดค้างชำระ' ) }}</td>
                            </tr>
                            <tr :key="order.id" v-for="order of report.orders">
                                <td class="p-2 border border-box-edge">
                                    <a class="underline" :href="orderUrl( order )">{{ order.code }}</a>
                                </td>
                                <td class="p-2 border border-box-edge">{{ order.customer_name }}</td>
                                <td class="p-2 border border-box-edge">{{ shortDate( order.created_at ) }}</td>
                                <td class="p-2 border border-box-edge">{{ order.due_on ? shortDate( order.due_on ) : __( 'ไม่กำหนด' ) }}</td>
                                <td class="p-2 border text-right">{{ order.days_overdue > 0 ? order.days_overdue : '—' }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( order.total ) }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( order.paid ) }}</td>
                                <td class="p-2 border text-right font-semibold">{{ nsCurrency( order.outstanding ) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import moment from 'moment';
import { nsHttpClient, nsSnackBar } from '~/bootstrap';
import { __ } from '~/libraries/lang';
import { nsCurrency } from '~/filters/currency';

/**
 * Accounts receivable, aged.
 *
 * Selling on credit is lending, and a shop that lends without a list of who owes what and
 * for how long finds out about a bad debt when the customer stops answering. The orders
 * screen can filter unpaid orders, but it cannot say which debts have gone stale or which
 * customer is the real exposure, which is what decides who gets called on Monday.
 */
export default {
    name: 'ns-receivables-report',
    props: [ 'storeName', 'storeLogo' ],
    data() {
        return {
            asOfField: {
                label: __( 'ณ วันที่' ),
                type: 'date',
                name: 'as_of',
                description: __( 'คำนวณอายุหนี้จากวันที่นี้ เว้นไว้เพื่อใช้วันปัจจุบัน' ),
                value: moment( ns.date.current ).format( 'YYYY-MM-DD' ),
            },
            report: {
                as_of: null,
                buckets: [],
                customers: [],
                orders: [],
                total: 0,
                count: 0,
            },
            ns: window.ns,
        };
    },
    computed: {
        asOfLabel() {
            if ( ! this.report.as_of ) {
                return this.asOfField.value;
            }

            return moment( this.report.as_of ).format( 'DD/MM/YYYY' );
        }
    },
    mounted() {
        this.loadReport();
    },
    methods: {
        __,
        nsCurrency,

        shortDate( value ) {
            return value ? moment( value ).format( 'DD/MM/YYYY' ) : '—';
        },

        orderUrl( order ) {
            return `${ns.url}/dashboard/orders?active=id&direction=desc&search=${order.code}`;
        },

        /**
         * The bands are coloured by how worrying they are rather than by position, so the
         * eye lands on the money that is late without reading any numbers.
         */
        bucketClass( key ) {
            switch ( key ) {
                case 'not_due':
                    return 'bg-info-primary border-info-tertiary text-info-fontcolor';
                case '1_30':
                    return 'bg-warning-primary border-warning-tertiary text-warning-fontcolor';
                case '31_60':
                case '61_90':
                case 'over_90':
                    return 'bg-error-primary border-error-tertiary text-error-fontcolor';
                default:
                    return 'bg-box-background border-box-edge text-fontcolor';
            }
        },

        printReport() {
            this.$htmlToPaper( 'receivables-report' );
        },

        loadReport() {
            const asOf = moment( this.asOfField.value, 'YYYY-MM-DD', true );

            if ( ! asOf.isValid() ) {
                return nsSnackBar.error( __( 'วันที่ไม่ถูกต้อง ใช้รูปแบบ ปปปป-ดด-วว' ) );
            }

            nsHttpClient.post( '/api/reports/receivables', {
                asOf: asOf.endOf( 'day' ).format( 'YYYY-MM-DD HH:mm:ss' ),
            } ).subscribe( {
                next: report => {
                    this.report = report;
                },
                error: ( error ) => {
                    nsSnackBar.error( error.message );
                }
            } );
        },
    }
};
</script>

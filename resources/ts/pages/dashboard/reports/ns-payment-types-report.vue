<template>
    <div id="report-section" class="px-4">
        <p class="p-2 text-sm">รายงานนี้รวมยอดที่บันทึกรับชำระตามเวลารับเงิน ก่อนหักเงินทอนและเงินคืน จึงไม่เท่ากับยอดขายสุทธิ จำนวนรายการเป็นจำนวนครั้งที่รับชำระ ไม่ใช่จำนวนบิล</p>
        <div class="flex -mx-2">
            <div class="px-2">
                <ns-field :field="startDateField"></ns-field>
            </div>
            <div class="px-2">
                <ns-field :field="endDateField"></ns-field>
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
                            <li class="pb-1 border-b border-dashed">{{ __( 'Date : {date}' ).replace( '{date}', ns.date.current ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'Document : Payment Type' ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'By : {user}' ).replace( '{user}', ns.user.username ) }}</li>
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
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'ช่องทางการชำระเงิน' ) }}</th>
                                <th width="100" class="text-fontcolor border p-2 text-right">{{ __( 'จำนวนรายการ' ) }}</th>
                                <th width="150" class="text-fontcolor border p-2 text-right">{{ __( 'Total' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr :key="index" v-for="(summary,index) of report.summary" class="font-semibold">
                                <td class="p-2 border border-box-edge">
                                    {{ summary.label }}
                                    <span v-if="summary.active === false" class="text-sm font-normal text-fontcolor-soft">
                                        — {{ __( 'ปิดใช้งานแล้ว' ) }}
                                    </span>
                                </td>
                                <td class="p-2 border text-right">{{ summary.count }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( summary.total ) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="text-fontcolor font-semibold">
                            <tr>
                                <td class="p-2 border border-box-edge text-font">{{ __( 'Total' ) }}</td>
                                <td class="p-2 border text-right">{{ report.count }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( report.total ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!--
                The channels where the money does not arrive at the till. Each one lands on
                a statement the shop receives later, so a total on its own is not enough —
                matching it needs the reference the cashier recorded, and the shop has to
                know which payments have none before the statement arrives.
            -->
            <div v-if="reconciliation.length > 0" class="bg-box-background shadow rounded my-4">
                <div class="p-2 border-b border-box-edge text-fontcolor font-semibold">
                    {{ __( 'กระทบยอดช่องทางที่เงินเข้าภายหลัง' ) }}
                </div>
                <div :key="index" v-for="(channel,index) of reconciliation" class="border-b border-box-edge">
                    <div class="p-2 flex flex-wrap justify-between text-fontcolor">
                        <span class="font-semibold">{{ channel.label }}</span>
                        <span>
                            {{ __( 'ยอดรวม {total} จาก {count} รายการ' )
                                .replace( '{total}', nsCurrency( channel.total ) )
                                .replace( '{count}', channel.count ) }}
                        </span>
                    </div>
                    <div v-if="channel.missing_reference > 0" class="px-2 pb-2 text-sm text-error-tertiary">
                        {{ __( 'มี {count} รายการที่ไม่ได้กรอกเลขอ้างอิง กระทบยอดกับใบแจ้งยอดไม่ได้' )
                            .replace( '{count}', channel.missing_reference ) }}
                    </div>
                    <table v-if="channel.references.length > 0" class="table ns-table w-full">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'เลขอ้างอิง' ) }}</th>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'บิล' ) }}</th>
                                <th class="text-fontcolor border p-2 text-left">{{ __( 'เวลา' ) }}</th>
                                <th width="150" class="text-fontcolor border p-2 text-right">{{ __( 'Total' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr :key="refIndex" v-for="(entry,refIndex) of channel.references">
                                <td class="p-2 border border-box-edge">{{ entry.reference }}</td>
                                <td class="p-2 border border-box-edge">{{ entry.order_code }}</td>
                                <td class="p-2 border border-box-edge">{{ entry.created_at }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( entry.value ) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import moment from "moment";
import nsDatepicker from "~/components/ns-datepicker.vue";
import { nsHttpClient, nsSnackBar } from '~/bootstrap';
import { __ } from '~/libraries/lang';
import { default as nsDateTimePicker } from '~/components/ns-date-time-picker.vue';
import { nsCurrency } from '~/filters/currency';

export default {
    name: 'ns-payment-types-report',
    props: [ 'storeName', 'storeLogo' ],
    data() {
        return {
            startDateField: {
                type: 'datetimepicker',
                name: 'start_date',
                value: moment( ns.date.current ).startOf( 'day' ).format( 'YYYY-MM-DD HH:mm:ss' ),
            },
            endDateField: {
                type: 'datetimepicker',
                name: 'end_date',
                value: moment( ns.date.current ).endOf( 'day' ).format( 'YYYY-MM-DD HH:mm:ss' ),
            },
            report: [],
            ns: window.ns,
            field: {
                type: 'datetimepicker',
                value: '2021-02-07',
                name: 'date'
            }
        }
    },
    components: {
        nsDatepicker,
        nsDateTimePicker,
    },
    computed: {
        /**
         * "report" starts as an array, so every read has to tolerate the pre-load state
         * rather than assuming the payload shape.
         */
        reconciliation() {
            return this.report.reconciliation || [];
        },
    },
    mounted() {
        /**
         * โหลดข้อมูลทันทีที่เปิดหน้า
         *
         * เดิมหน้ารายงานเปิดมาแล้วตารางว่าง ต้องกดปุ่มโหลดเองก่อน แต่ไม่มีอะไรบอกไว้
         * คนเปิดดูจึงเข้าใจว่ารายงานใช้งานไม่ได้ ทั้งที่ข้อมูลฝั่งหลังบ้านถูกต้องอยู่แล้ว
         * ช่วงเวลาเริ่มต้นคือเดือนปัจจุบัน ซึ่งเป็นช่วงที่คนเปิดรายงานต้องการดูอยู่แล้ว
         */
        this.loadReport();
    },
    methods: {
        __,
        nsCurrency,
        printSaleReport() {
            this.$htmlToPaper( 'sale-report' );
        },

        loadReport() {
            if ( this.startDateField.value === null || this.endDateField.value ===null ) {
                return nsSnackBar.error( __( 'Unable to proceed. Select a correct time range.' ) );
            }

            const startMoment   =   moment( this.startDateField.value );
            const endMoment     =   moment( this.endDateField.value );

            if ( endMoment.isBefore( startMoment ) ) {
                return nsSnackBar.error( __( 'Unable to proceed. The current time range is not valid.' ) );
            }

            nsHttpClient.post( '/api/reports/payment-types', { 
                startDate: this.startDateField.value,
                endDate: this.endDateField.value
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
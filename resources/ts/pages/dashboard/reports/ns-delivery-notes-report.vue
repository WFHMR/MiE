<template>
    <div id="report-section" class="px-4">
        <div class="flex -mx-2 flex-wrap items-end">
            <div class="px-2">
                <ns-field :field="startField"></ns-field>
            </div>
            <div class="px-2">
                <ns-field :field="endField"></ns-field>
            </div>
            <div class="px-2">
                <button @click="loadReport()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
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

        <!--
            Only this element is printed. $htmlToPaper copies the innerHTML into a blank
            window, so the header block below has to be inside it or the printed sheet
            comes out with no shop name and no period on it.
        -->
        <div id="sale-report" class="anim-duration-500 fade-in-entrance">
            <div class="my-4 flex justify-between items-start w-full text-fontcolor">
                <div>
                    <div class="text-2xl font-bold">{{ storeName || '—' }}</div>
                    <div class="text-lg">{{ report.title }}</div>
                    <div class="text-sm">{{ periodLabel }}</div>
                </div>
                <div class="text-sm text-right">
                    <div>{{ __( 'พิมพ์วันที่ : {date}' ).replace( '{date}', printedAt ) }}</div>
                    <!--
                        The page count the paper form carries. The browser paginates the
                        printout and does not tell the page how many sheets it produced, so
                        this states the common case; a register long enough to spill onto a
                        second sheet will understate it.
                    -->
                    <div>{{ __( 'Page 1 of 1' ) }}</div>
                </div>
            </div>

            <div class="bg-box-background shadow rounded my-4">
                <table class="table ns-table w-full">
                    <thead class="text-fontcolor">
                        <tr>
                            <th width="110" class="text-fontcolor border p-2 text-left">{{ __( 'วันที่ส่งฯ' ) }}</th>
                            <th width="150" class="text-fontcolor border p-2 text-left">{{ __( 'เลขที่ใบส่งฯ' ) }}</th>
                            <th width="100" class="text-fontcolor border p-2 text-left">{{ __( 'ลูกค้า' ) }}</th>
                            <th class="text-fontcolor border p-2 text-left">{{ __( 'ชื่อบริษัท' ) }}</th>
                            <th width="110" class="text-fontcolor border p-2 text-right">{{ __( 'กำหนดชำระ' ) }}</th>
                            <th width="140" class="text-fontcolor border p-2 text-right">{{ __( 'จำนวนเงินสุทธิ' ) }}</th>
                            <th width="100" class="text-fontcolor border p-2 text-right">{{ __( 'ภาษี' ) }}</th>
                            <th width="140" class="text-fontcolor border p-2 text-right">{{ __( 'จำนวนเงินรวม' ) }}</th>
                        </tr>
                    </thead>
                    <tbody class="text-fontcolor">
                        <tr v-if="report.rows.length === 0">
                            <td colspan="8" class="p-4 border border-box-edge text-fontcolor-soft">
                                {{ __( 'ช่วงวันที่นี้ไม่มีการออกใบส่งสินค้าในทะเบียนนี้' ) }}
                            </td>
                        </tr>
                        <tr :key="index" v-for="( row, index ) of report.rows">
                            <td class="p-2 border border-box-edge">{{ formatDate( row.delivered_at ) }}</td>
                            <td class="p-2 border border-box-edge">{{ row.document_number }}</td>
                            <td class="p-2 border border-box-edge">{{ row.customer_code }}</td>
                            <td class="p-2 border border-box-edge">{{ row.customer_name }}</td>
                            <td class="p-2 border text-right">{{ row.payment_term_days }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( row.net ) }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( row.tax ) }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( row.total ) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="text-fontcolor font-semibold">
                        <tr>
                            <td colspan="5" class="p-2 border border-box-edge">
                                {{ __( 'จำนวนรวม' ) }}
                                <span class="text-fontcolor-soft font-normal pl-2">
                                    {{ __( '{count} ใบ' ).replace( '{count}', report.totals.documents ) }}
                                </span>
                            </td>
                            <td class="p-2 border text-right">{{ nsCurrency( report.totals.net ) }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( report.totals.tax ) }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( report.totals.total ) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div v-if="report.notes && report.notes.length > 0" class="my-4">
                <ns-notice color="info">
                    <template v-slot:title>{{ __( 'รายงานนี้นับอะไร' ) }}</template>
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
    name: 'ns-delivery-notes-report',

    /**
     * registerType comes from the route rather than a control on the page. The two
     * registers are two separate sheets in the shop's filing and staff bookmark them
     * separately, so which one you are looking at is part of the address.
     */
    props: [ 'registerType', 'storeName' ],
    data() {
        return {
            /**
             * A free date range rather than a month: this is a daily control sheet that
             * gets checked against the till at closing, so a single day is the normal
             * case and a range is the exception.
             */
            startField: {
                label: __( 'ตั้งแต่วันที่' ),
                type: 'date',
                name: 'start_date',
                value: moment( ns.date.current ).format( 'YYYY-MM-DD' ),
            },
            endField: {
                label: __( 'ถึงวันที่' ),
                type: 'date',
                name: 'end_date',
                value: moment( ns.date.current ).format( 'YYYY-MM-DD' ),
            },
            report: {
                title: '',
                rows: [],
                totals: { documents: 0, net: 0, tax: 0, total: 0 },
                notes: [],
            },
            ns: window.ns,
        }
    },
    computed: {
        /**
         * The period line as the paper form prints it: Buddhist years in the heading, even
         * though the row dates below it are Gregorian. That inconsistency is on the shop's
         * own stationery and staff read the heading to confirm they pulled the right day,
         * so it is reproduced rather than corrected.
         */
        periodLabel() {
            const start = this.report.period ? this.report.period.start : this.startField.value;
            const end = this.report.period ? this.report.period.end : this.endField.value;

            return `'${ __( 'ระว่างวันที่' ) } ${ this.buddhistDate( start ) } ${ __( 'ถึงวันที่' ) } ${ this.buddhistDate( end ) }'`;
        },

        printedAt() {
            return moment( this.report.period ? this.report.period.printed_at : ns.date.current ).format( 'DD/MM/YYYY' );
        },
    },
    mounted() {
        this.loadReport();
    },
    methods: {
        __,
        nsCurrency,

        formatDate( date ) {
            return date ? moment( date ).format( 'DD/MM/YYYY' ) : '';
        },

        buddhistDate( date ) {
            const parsed = moment( date );

            if ( ! parsed.isValid() ) {
                return '';
            }

            return `${ parsed.format( 'DD/MM' ) }/${ parsed.year() + 543 }`;
        },

        printReport() {
            this.$htmlToPaper( 'sale-report' );
        },

        loadReport() {
            const start = moment( this.startField.value, 'YYYY-MM-DD', true );
            const end = moment( this.endField.value, 'YYYY-MM-DD', true );

            if ( ! start.isValid() || ! end.isValid() ) {
                return nsSnackBar.error( __( 'วันที่ไม่ถูกต้อง' ) );
            }

            if ( end.isBefore( start ) ) {
                return nsSnackBar.error( __( 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มต้น' ) );
            }

            nsHttpClient.post( '/api/reports/delivery-notes', {
                /**
                 * Widened to cover the whole of both days here and on the server. A range
                 * sent as bare dates would compare against a datetime column and silently
                 * drop everything sold after midnight on the closing day.
                 */
                startDate: start.startOf( 'day' ).format( 'YYYY-MM-DD HH:mm:ss' ),
                endDate: end.endOf( 'day' ).format( 'YYYY-MM-DD HH:mm:ss' ),
                type: this.registerType,
            }).subscribe({
                next: report => {
                    this.report = report;
                },
                error: ( error ) => {
                    nsSnackBar.error( error.message );
                }
            });
        },
    }
}
</script>

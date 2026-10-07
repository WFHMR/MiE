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
                <button @click="printReport()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
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
                            <li class="pb-1 border-b border-dashed">{{ __( 'เอกสาร: รายงานภาษีหัก ณ ที่จ่าย (ภ.ง.ด.3 / ภ.ง.ด.53)' ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'เดือนที่จ่าย: {period}' ).replace( '{period}', periodLabel ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'ผู้จ่ายเงิน: {name}' ).replace( '{name}', storeName || '—' ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'เลขประจำตัวผู้เสียภาษี: {taxId}' ).replace( '{taxId}', taxId || '—' ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'สาขา: {branch}' ).replace( '{branch}', branchCode || '—' ) }}</li>
                        </ul>
                    </div>
                    <div>
                        <img class="w-24" :src="storeLogo" :alt="storeName">
                    </div>
                </div>
            </div>

            <!--
                Both returns are shown one after the other, never merged. A shop that paid
                an individual and a company in the same month owes two separate filings,
                and a single combined figure is how the second one gets forgotten.
            -->
            <div :key="form.form" v-for="form of forms" class="bg-box-background shadow rounded my-4">
                <div class="p-3 border-b border-box-edge flex flex-wrap justify-between items-center">
                    <div>
                        <span class="text-fontcolor font-bold text-lg">{{ form.form_label }}</span>
                        <span class="text-fontcolor-soft text-sm pl-2">{{ payeeScopeLabel( form.form ) }}</span>
                    </div>
                    <div class="text-fontcolor-soft text-sm">
                        {{ __( 'กำหนดนำส่ง {due}' ).replace( '{due}', form.period ? form.period.due_date : '—' ) }}
                    </div>
                </div>

                <div v-if="form.count === 0" class="p-4 text-fontcolor-soft">
                    {{ __( 'เดือนนี้ไม่มีการหักภาษีที่ต้องยื่นในแบบนี้ — ไม่ต้องยื่นแบบเปล่า' ) }}
                </div>

                <table v-else class="table ns-table w-full">
                    <thead class="text-fontcolor">
                        <tr>
                            <th class="text-fontcolor border p-2 text-left">{{ __( 'ผู้รับเงิน' ) }}</th>
                            <th class="text-fontcolor border p-2 text-left" width="150">{{ __( 'เลขผู้เสียภาษี' ) }}</th>
                            <th class="text-fontcolor border p-2 text-left" width="90">{{ __( 'สาขา' ) }}</th>
                            <th class="text-fontcolor border p-2 text-left" width="110">{{ __( 'ประเภทเงินได้' ) }}</th>
                            <th class="text-fontcolor border p-2 text-right" width="70">{{ __( 'อัตรา' ) }}</th>
                            <th class="text-fontcolor border p-2 text-right" width="80">{{ __( 'ครั้ง' ) }}</th>
                            <th class="text-fontcolor border p-2 text-right" width="140">{{ __( 'ฐานก่อน VAT' ) }}</th>
                            <th class="text-fontcolor border p-2 text-right" width="140">{{ __( 'ภาษีที่หัก' ) }}</th>
                        </tr>
                    </thead>
                    <tbody class="text-fontcolor">
                        <tr :key="index" v-for="( payee, index ) of form.payees">
                            <td class="p-2 border border-box-edge">
                                {{ payee.payee_name }}
                                <div v-if="payee.certificates && payee.certificates.length > 0" class="text-sm text-fontcolor-soft">
                                    {{ payee.certificates.map( c => c.number ).join( ', ' ) }}
                                </div>
                            </td>
                            <td class="p-2 border border-box-edge">{{ payee.payee_tax_id }}</td>
                            <td class="p-2 border border-box-edge">{{ payee.payee_branch_code }}</td>
                            <td class="p-2 border border-box-edge">{{ payee.income_type }}</td>
                            <td class="p-2 border text-right">{{ formatRate( payee.rate ) }}</td>
                            <td class="p-2 border text-right">{{ payee.count }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( payee.base_amount ) }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( payee.tax_amount ) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="text-fontcolor font-semibold">
                        <tr>
                            <td colspan="6" class="p-2 border border-box-edge">{{ __( 'รวม {form}' ).replace( '{form}', form.form_label ) }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( form.total_base ) }}</td>
                            <td class="p-2 border text-right">{{ nsCurrency( form.total_tax ) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="bg-box-background shadow rounded my-4">
                <table class="table ns-table w-full">
                    <tbody class="text-fontcolor font-semibold">
                        <tr>
                            <td class="p-2 border border-box-edge">{{ __( 'ภาษีที่ต้องนำส่งทั้งสองแบบรวมกัน' ) }}</td>
                            <td class="p-2 border text-right" width="180">{{ nsCurrency( totalTax ) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="notes.length > 0" class="my-4">
                <ns-notice color="warning">
                    <template v-slot:title>{{ __( 'ข้อควรทราบก่อนยื่นแบบ' ) }}</template>
                    <template v-slot:description>
                        <ul class="list-disc pl-4">
                            <li :key="index" v-for="( note, index ) of notes">{{ note }}</li>
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
    name: 'ns-withholding-tax-report',
    props: [ 'storeName', 'storeLogo', 'taxId', 'branchCode' ],
    data() {
        return {
            /**
             * Both returns cover a calendar month and are due on the 7th of the next one,
             * so the picker is a month rather than a free range. The boundaries are
             * derived again on the server, which keeps a browser in another timezone from
             * shifting the period across midnight on the 1st.
             */
            monthField: {
                label: __( 'เดือนที่จ่ายเงิน' ),
                type: 'text',
                name: 'wht_month',
                description: __( 'รูปแบบ ปปปป-ดด เช่น 2026-09' ),
                value: moment( ns.date.current ).format( 'YYYY-MM' ),
            },
            report: {},
            ns: window.ns,
        }
    },
    computed: {
        forms() {
            return [ this.report.pnd3, this.report.pnd53 ].filter( form => form !== undefined && form !== null );
        },

        totalTax() {
            return this.forms.reduce( ( total, form ) => total + ( form.total_tax || 0 ), 0 );
        },

        /**
         * The notes are the same deadline warning on both payloads, so they are collapsed
         * to avoid printing the identical line twice.
         */
        notes() {
            const collected = [];

            this.forms.forEach( form => {
                ( form.notes || [] ).forEach( note => {
                    if ( ! collected.includes( note ) ) {
                        collected.push( note );
                    }
                });
            });

            return collected;
        },

        periodLabel() {
            const form = this.forms[ 0 ];

            if ( ! form || ! form.period ) {
                return this.monthField.value;
            }

            return `${ moment( form.period.start ).format( 'DD/MM/YYYY' ) } — ${ moment( form.period.end ).format( 'DD/MM/YYYY' ) }`;
        }
    },
    mounted() {
        this.loadReport();
    },
    methods: {
        __,
        nsCurrency,

        payeeScopeLabel( form ) {
            return form === 'pnd3'
                ? __( 'ผู้รับเงินเป็นบุคคลธรรมดา' )
                : __( 'ผู้รับเงินเป็นนิติบุคคล' );
        },

        formatRate( rate ) {
            return `${ parseFloat( rate ).toString() }%`;
        },

        printReport() {
            this.$htmlToPaper( 'sale-report' );
        },

        loadReport() {
            const month     =   moment( this.monthField.value, 'YYYY-MM', true );

            if ( ! month.isValid() ) {
                return nsSnackBar.error( __( 'เดือนไม่ถูกต้อง ใช้รูปแบบ ปปปป-ดด เช่น 2026-09' ) );
            }

            nsHttpClient.post( '/api/reports/withholding-tax', {
                startDate: month.clone().startOf( 'month' ).format( 'YYYY-MM-DD' ),
                endDate: month.clone().endOf( 'month' ).format( 'YYYY-MM-DD' ),
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

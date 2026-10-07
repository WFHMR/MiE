<template>
    <div class="px-4">
        <div class="mb-4">
            <ns-search
                :placeholder="__( 'ค้นหาลูกค้า...' )"
                :label="[ 'first_name', 'last_name' ]"
                value="id"
                @select="selectCustomer( $event )"
                :url="searchUrl"
                ></ns-search>
        </div>

        <div v-if="! customer" class="ns-box shadow rounded p-6 text-center text-fontcolor-soft">
            {{ __( 'เลือกลูกค้าเพื่อดูบิลที่ยังไม่ได้รับเงินครบ' ) }}
        </div>

        <template v-else>
            <div class="ns-box shadow rounded mb-4">
                <div class="ns-box-header p-3 border-b flex justify-between items-center">
                    <div>
                        <h3 class="font-bold text-fontcolor">{{ customerName }}</h3>
                        <p class="text-sm text-fontcolor-soft">{{ __( 'รหัสลูกค้า {code}' ).replace( '{code}', customer.id ) }}</p>
                    </div>
                    <div>
                        <button @click="loadBillableOrders()" class="ns-inset-button rounded border px-3 py-1">
                            <i class="las la-sync-alt"></i> {{ __( 'โหลดใหม่' ) }}
                        </button>
                    </div>
                </div>

                <div class="ns-box-body">
                    <div v-if="loading" class="p-6 flex justify-center">
                        <ns-spinner></ns-spinner>
                    </div>

                    <div v-else-if="orders.length === 0" class="p-6 text-center text-fontcolor-soft">
                        {{ __( 'ลูกค้ารายนี้ไม่มีบิลค้างชำระที่ยังไม่ได้วางบิล' ) }}
                    </div>

                    <table v-else class="table ns-table w-full">
                        <thead class="text-fontcolor">
                            <tr>
                                <th width="50" class="border p-2">
                                    <ns-checkbox :checked="allSelected" @change="toggleAll()"></ns-checkbox>
                                </th>
                                <th class="border p-2 text-left">{{ __( 'เลขที่บิล' ) }}</th>
                                <th width="130" class="border p-2 text-center">{{ __( 'วันที่' ) }}</th>
                                <th width="150" class="border p-2 text-center">{{ __( 'ครบกำหนดวันที่' ) }}</th>
                                <th width="120" class="border p-2 text-left">{{ __( 'สถานะ' ) }}</th>
                                <th width="140" class="border p-2 text-right">{{ __( 'จำนวนเงินรวม' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr :key="order.id" v-for="order of orders" :class="isSelected( order ) ? 'bg-info-secondary' : ''">
                                <td class="border border-box-edge p-2">
                                    <ns-checkbox :checked="isSelected( order )" @change="toggle( order )"></ns-checkbox>
                                </td>
                                <td class="border border-box-edge p-2 font-semibold">{{ order.code }}</td>
                                <td class="border border-box-edge p-2 text-center">{{ formatDate( order.created_at ) }}</td>
                                <td class="border border-box-edge p-2 text-center">{{ formatDate( order.final_payment_date ) }}</td>
                                <td class="border border-box-edge p-2">{{ paymentLabel( order.payment_status ) }}</td>
                                <td class="border border-box-edge p-2 text-right">{{ nsCurrency( order.total ) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="text-fontcolor font-semibold">
                            <tr>
                                <td colspan="5" class="border border-box-edge p-2">
                                    {{ __( 'เลือกไว้ {count} ใบ' ).replace( '{count}', selected.length ) }}
                                </td>
                                <td class="border border-box-edge p-2 text-right">{{ nsCurrency( totalBilled ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div v-if="orders.length > 0" class="ns-box shadow rounded mb-4">
                <div class="ns-box-header p-3 border-b">
                    <h4 class="font-bold text-fontcolor">{{ __( 'รายละเอียดการวางบิล' ) }}</h4>
                </div>
                <div class="ns-box-body p-3">
                    <div class="flex flex-wrap -mx-2">
                        <div class="px-2 w-full md:w-1/3">
                            <ns-field :field="deductionField"></ns-field>
                        </div>
                        <div class="px-2 w-full md:w-1/3">
                            <ns-field :field="appointmentField"></ns-field>
                        </div>
                        <div class="px-2 w-full md:w-1/3">
                            <ns-field :field="notesField"></ns-field>
                        </div>
                    </div>

                    <!--
                        The three closing figures, live. The clerk reads them back to the
                        customer's accounts desk over the phone before the runner leaves, so
                        they have to move as the selection changes rather than only appearing
                        on the printed sheet.
                    -->
                    <table class="table ns-table w-full md:w-1/2 md:ml-auto mt-4">
                        <tbody class="text-fontcolor">
                            <tr>
                                <td class="border border-box-edge p-2">{{ __( 'จำนวนเงินที่วางบิลรวม' ) }}</td>
                                <td width="150" class="border border-box-edge p-2 text-right">{{ nsCurrency( totalBilled ) }}</td>
                            </tr>
                            <tr>
                                <td class="border border-box-edge p-2">{{ __( 'หักตามหมายเหตุรวม' ) }}</td>
                                <td class="border border-box-edge p-2 text-right">{{ nsCurrency( deduction ) }}</td>
                            </tr>
                            <tr class="font-bold">
                                <td class="border border-box-edge p-2">{{ __( 'จำนวนเงินรับวางบิลสุทธิ' ) }}</td>
                                <td class="border border-box-edge p-2 text-right">{{ nsCurrency( totalNet ) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="deductionExceedsBilled" class="mt-3">
                        <ns-notice color="error">
                            <template v-slot:title>{{ __( 'ยอดหักมากกว่ายอดที่วางบิล' ) }}</template>
                            <template v-slot:description>
                                {{ __( 'ถ้าต้องคืนเงินให้ลูกค้าต้องออกใบลดหนี้ ไม่ใช่ใบวางบิล' ) }}
                            </template>
                        </ns-notice>
                    </div>

                    <div class="flex justify-end mt-4">
                        <ns-button @click="issue()" :disabled="! canIssue" type="info">
                            <i class="las la-file-invoice"></i>
                            {{ issuing ? __( 'กำลังออก...' ) : __( 'ออกใบวางบิลและพิมพ์' ) }}
                        </ns-button>
                    </div>
                </div>
            </div>

            <div v-if="notes.length > 0" class="ns-box shadow rounded">
                <div class="ns-box-header p-3 border-b">
                    <h4 class="font-bold text-fontcolor">{{ __( 'ใบวางบิลของลูกค้ารายนี้' ) }}</h4>
                </div>
                <div class="ns-box-body">
                    <table class="table ns-table w-full">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="border p-2 text-left">{{ __( 'เลขที่' ) }}</th>
                                <th width="130" class="border p-2 text-center">{{ __( 'วันที่' ) }}</th>
                                <th width="140" class="border p-2 text-right">{{ __( 'รับวางบิลสุทธิ' ) }}</th>
                                <th width="110" class="border p-2 text-left">{{ __( 'สถานะ' ) }}</th>
                                <th width="180" class="border p-2 text-center">{{ __( 'จัดการ' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr :key="note.id" v-for="note of notes">
                                <td class="border border-box-edge p-2 font-semibold">{{ note.number }}</td>
                                <td class="border border-box-edge p-2 text-center">{{ formatDate( note.issued_at ) }}</td>
                                <td class="border border-box-edge p-2 text-right">{{ nsCurrency( note.total_net ) }}</td>
                                <td class="border border-box-edge p-2">
                                    <span :class="note.status === 'cancelled' ? 'text-error-primary' : 'text-success-primary'">
                                        {{ note.status === 'cancelled' ? __( 'ยกเลิกแล้ว' ) : __( 'ใช้งาน' ) }}
                                    </span>
                                </td>
                                <td class="border border-box-edge p-2 text-center">
                                    <button @click="printNote( note )" class="ns-inset-button rounded border px-2 py-1 text-sm mr-1">
                                        <i class="las la-print"></i> {{ __( 'พิมพ์' ) }}
                                    </button>
                                    <button v-if="note.status !== 'cancelled'" @click="cancelNote( note )" class="ns-inset-button rounded border px-2 py-1 text-sm">
                                        <i class="las la-ban"></i> {{ __( 'ยกเลิก' ) }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
<script>
import moment from 'moment';
import { forkJoin } from 'rxjs';
import { nsHttpClient, nsSnackBar } from '~/bootstrap';
import { nsCurrency } from '~/filters/currency';
import { __ } from '~/libraries/lang';
import nsPromptPopupVue from '~/popups/ns-prompt-popup.vue';

export default {
    name: 'ns-billing-note-issue',
    props: [ 'search-url', 'billing-note-url' ],
    data() {
        return {
            customer: null,
            orders: [],
            notes: [],
            selectedIds: [],
            loading: false,
            issuing: false,
            deductionField: {
                label: __( 'หักตามหมายเหตุ' ),
                name: 'deduction',
                type: 'number',
                value: 0,
                description: __( 'ยอดที่ฝ่ายบัญชีลูกค้าหักออกตอนตรวจ เช่น ส่งของขาด' ),
            },
            appointmentField: {
                label: __( 'นัดชำระวันที่' ),
                name: 'appointment_date',
                type: 'date',
                value: '',
                description: __( 'เว้นว่างได้ ถ้ายังไม่ได้นัด' ),
            },
            notesField: {
                label: __( 'หมายเหตุ' ),
                name: 'notes',
                type: 'textarea',
                value: '',
                description: __( 'เหตุผลของยอดที่หัก' ),
            },
        }
    },
    computed: {
        customerName() {
            if ( ! this.customer ) {
                return '';
            }

            return `${ this.customer.first_name || '' } ${ this.customer.last_name || '' }`.trim();
        },

        selected() {
            return this.orders.filter( order => this.selectedIds.includes( order.id ) );
        },

        allSelected() {
            return this.orders.length > 0 && this.selectedIds.length === this.orders.length;
        },

        totalBilled() {
            return this.selected.reduce( ( total, order ) => total + parseFloat( order.total || 0 ), 0 );
        },

        deduction() {
            const value = parseFloat( this.deductionField.value );

            return isNaN( value ) ? 0 : value;
        },

        totalNet() {
            return this.totalBilled - this.deduction;
        },

        deductionExceedsBilled() {
            return this.deduction < 0 || this.deduction > this.totalBilled;
        },

        canIssue() {
            return ! this.issuing && this.selected.length > 0 && ! this.deductionExceedsBilled;
        },
    },
    methods: {
        __,
        nsCurrency,

        formatDate( date ) {
            return date ? moment( date ).format( 'DD/MM/YYYY' ) : '—';
        },

        paymentLabel( status ) {
            return {
                unpaid: __( 'ยังไม่ชำระ' ),
                partially_paid: __( 'ชำระบางส่วน' ),
            }[ status ] || status;
        },

        selectCustomer( customer ) {
            this.customer = customer;
            this.selectedIds = [];
            this.load();
        },

        load() {
            this.loading = true;

            forkJoin([
                nsHttpClient.get( `/api/customers/${ this.customer.id }/billable-orders` ),
                nsHttpClient.get( `/api/customers/${ this.customer.id }/billing-notes` ),
            ]).subscribe({
                next: result => {
                    this.orders = result[ 0 ];
                    this.notes = result[ 1 ];
                    this.loading = false;
                },
                error: error => {
                    this.loading = false;
                    nsSnackBar.error( error.message || __( 'ไม่สามารถโหลดข้อมูลลูกค้าได้' ) );
                },
            });
        },

        loadBillableOrders() {
            this.selectedIds = [];
            this.load();
        },

        isSelected( order ) {
            return this.selectedIds.includes( order.id );
        },

        toggle( order ) {
            if ( this.isSelected( order ) ) {
                this.selectedIds = this.selectedIds.filter( id => id !== order.id );

                return;
            }

            this.selectedIds = [ ...this.selectedIds, order.id ];
        },

        toggleAll() {
            this.selectedIds = this.allSelected ? [] : this.orders.map( order => order.id );
        },

        issue() {
            if ( ! this.canIssue ) {
                return;
            }

            this.issuing = true;

            nsHttpClient.post( `/api/customers/${ this.customer.id }/billing-note`, {
                orders: this.selectedIds,
                deduction: this.deduction,
                notes: this.notesField.value,
                appointment_date: this.appointmentField.value || null,
            }).subscribe({
                next: result => {
                    this.issuing = false;
                    nsSnackBar.success( result.message );
                    this.openDocument( result.data.url );

                    /**
                     * Reloaded rather than patched: issuing removes those deliveries from the
                     * billable pool, and leaving them ticked on screen invites the clerk to
                     * bill them again on the next note.
                     */
                    this.selectedIds = [];
                    this.deductionField.value = 0;
                    this.notesField.value = '';
                    this.load();
                },
                error: error => {
                    this.issuing = false;
                    nsSnackBar.error( error.message || __( 'ออกใบวางบิลไม่สำเร็จ' ) );
                },
            });
        },

        printNote( note ) {
            this.openDocument( `${ this.billingNoteUrl }${ note.id }` );
        },

        /**
         * Opened in a tab rather than through the receipt printer's hidden iframe: the sheet
         * carries an @page rule for A4 and the iframe route drops the page geometry.
         */
        openDocument( url ) {
            const separator = url.includes( '?' ) ? '&' : '?';

            window.open( `${ url }${ separator }dash-visibility=disabled&autoprint=true`, '_blank' );
        },

        cancelNote( note ) {
            new Promise( ( resolve, reject ) => {
                Popup.show( nsPromptPopupVue, {
                    resolve,
                    reject,
                    title: __( 'ยกเลิกใบวางบิล' ),
                    message: __( 'บิลในใบนี้จะกลับมาวางบิลได้อีก แต่เลขที่ใบวางบิลจะไม่ถูกใช้ซ้ำ กรุณาระบุเหตุผล' ),
                    onAction: reason => {
                        if ( reason === false ) {
                            return;
                        }

                        nsHttpClient.post( `/api/billing-notes/${ note.id }/cancel`, { reason }).subscribe({
                            next: result => {
                                nsSnackBar.success( result.message );
                                this.load();
                            },
                            error: error => {
                                nsSnackBar.error( error.message || __( 'ยกเลิกไม่สำเร็จ' ) );
                            },
                        });
                    },
                });
            }).catch( () => {
                // the clerk backed out of the prompt
            });
        },
    },
}
</script>

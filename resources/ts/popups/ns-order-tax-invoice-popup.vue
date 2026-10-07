<template>
    <div class="ns-box shadow w-95vw h-95vh md:w-[70vw] md:h-[80vh] flex flex-col overflow-hidden">
        <div class="flex-shrink-0 flex p-3 border-b ns-box-header justify-between items-center">
            <div>
                <h3 class="text-lg font-bold text-fontcolor">{{ __( 'ใบกำกับภาษี' ) }}</h3>
                <p class="text-sm text-fontcolor-soft">{{ __( 'บิลเลขที่ {code}' ).replace( '{code}', order.code || '...' ) }}</p>
            </div>
            <div>
                <ns-close-button @click="closePopup()"></ns-close-button>
            </div>
        </div>

        <div class="p-3 ns-box-body flex-auto overflow-y-auto">
            <div v-if="! loaded" class="h-full w-full flex items-center justify-center">
                <ns-spinner></ns-spinner>
            </div>

            <template v-else>
                <!--
                    The tax point for goods sold over a counter is payment, so an unsettled
                    order has nothing to invoice yet. Saying so up front beats letting the
                    user fill in a buyer block and then rejecting it.
                -->
                <div v-if="! isInvoiceable" class="mb-4">
                    <ns-notice color="warning">
                        <template v-slot:title>{{ __( 'ยังออกใบกำกับภาษีไม่ได้' ) }}</template>
                        <template v-slot:description>
                            {{ __( 'ออกใบกำกับภาษีได้เฉพาะบิลที่ชำระเงินแล้ว บิลนี้สถานะ "{status}"' ).replace( '{status}', order.payment_status ) }}
                        </template>
                    </ns-notice>
                </div>

                <!-- Documents already issued against this order -->
                <div class="mb-4">
                    <h4 class="font-semibold text-fontcolor mb-2">{{ __( 'เอกสารที่ออกแล้ว' ) }}</h4>

                    <div v-if="invoices.length === 0" class="text-fontcolor-soft text-sm p-3 border border-box-edge rounded">
                        {{ __( 'บิลนี้ยังไม่ได้ออกใบกำกับภาษี' ) }}
                    </div>

                    <table v-else class="table ns-table w-full">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="border p-2 text-left">{{ __( 'เลขที่' ) }}</th>
                                <th class="border p-2 text-left" width="130">{{ __( 'ประเภท' ) }}</th>
                                <th class="border p-2 text-right" width="120">{{ __( 'รวม' ) }}</th>
                                <th class="border p-2 text-left" width="110">{{ __( 'สถานะ' ) }}</th>
                                <th class="border p-2 text-center" width="170">{{ __( 'จัดการ' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr :key="invoice.id" v-for="invoice of invoices">
                                <td class="border border-box-edge p-2 font-semibold">{{ invoice.number }}</td>
                                <td class="border border-box-edge p-2">{{ typeLabel( invoice.type ) }}</td>
                                <td class="border border-box-edge p-2 text-right">{{ nsCurrency( invoice.total_gross ) }}</td>
                                <td class="border border-box-edge p-2">
                                    <span :class="invoice.status === 'cancelled' ? 'text-error-primary' : 'text-success-primary'">
                                        {{ invoice.status === 'cancelled' ? __( 'ยกเลิกแล้ว' ) : __( 'ใช้งาน' ) }}
                                    </span>
                                </td>
                                <td class="border border-box-edge p-2 text-center">
                                    <button @click="printInvoice( invoice )" class="ns-inset-button rounded border px-2 py-1 text-sm mr-1">
                                        <i class="las la-print"></i> {{ __( 'พิมพ์' ) }}
                                    </button>
                                    <button v-if="invoice.status !== 'cancelled'" @click="cancelInvoice( invoice )" class="ns-inset-button rounded border px-2 py-1 text-sm">
                                        <i class="las la-ban"></i> {{ __( 'ยกเลิก' ) }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Issue a new one -->
                <div v-if="isInvoiceable">
                    <h4 class="font-semibold text-fontcolor mb-2">{{ __( 'ออกใบกำกับภาษีใหม่' ) }}</h4>

                    <div class="mb-3">
                        <ns-field :field="typeField" @change="handleTypeChange()"></ns-field>
                    </div>

                    <!--
                        Section 86/4 requires the buyer's name, address and taxpayer number on
                        a full form invoice, because without them the buyer cannot claim the
                        VAT as input tax — which is the only reason the full form exists. The
                        abbreviated form is handed to a walk-in and carries none of this.
                    -->
                    <div v-if="isFullForm" class="p-3 border border-box-edge rounded">
                        <p class="text-sm text-fontcolor-soft mb-3">
                            {{ __( 'ผู้ซื้อต้องมีข้อมูลครบเพื่อนำไปใช้เป็นภาษีซื้อได้ ตามมาตรา 86/4' ) }}
                        </p>
                        <div class="flex flex-wrap -mx-2">
                            <div class="px-2 w-full md:w-1/2" v-for="field of buyerFields" :key="field.name">
                                <ns-field :field="field"></ns-field>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div v-if="loaded" class="p-2 flex justify-between border-t ns-box-footer flex-shrink-0">
            <div>
                <ns-button @click="closePopup()" type="default">{{ __( 'ปิด' ) }}</ns-button>
            </div>
            <div>
                <ns-button v-if="isInvoiceable" @click="issue()" :disabled="issuing" type="info">
                    <i class="las la-file-invoice-dollar"></i>
                    {{ issuing ? __( 'กำลังออก...' ) : __( 'ออกใบกำกับภาษีและพิมพ์' ) }}
                </ns-button>
            </div>
        </div>
    </div>
</template>
<script>
import { nsHttpClient, nsSnackBar } from '~/bootstrap';
import { nsCurrency } from '~/filters/currency';
import { __ } from '~/libraries/lang';
import popupCloser from '~/libraries/popup-closer';
import popupResolver from '~/libraries/popup-resolver';
import nsPromptPopupVue from './ns-prompt-popup.vue';

export default {
    name: 'ns-order-tax-invoice-popup',
    props: [ 'popup' ],
    data() {
        return {
            order: {},
            invoices: [],
            loaded: false,
            issuing: false,
            typeField: {
                label: __( 'ประเภทเอกสาร' ),
                name: 'type',
                type: 'select',
                value: 'abbreviated',
                options: [
                    { label: __( 'ใบกำกับภาษีอย่างย่อ (หน้าร้าน 58มม.)' ), value: 'abbreviated' },
                    { label: __( 'ใบส่งสินค้า/ใบกำกับภาษี/ใบเสร็จรับเงิน (เต็มรูป A4)' ), value: 'full' },
                ],
                description: __( 'แบบย่อใช้กับลูกค้าหน้าร้าน แบบเต็มรูปใช้เมื่อผู้ซื้อจะนำไปเป็นภาษีซื้อ' ),
            },
            buyerFields: [
                { label: __( 'ชื่อผู้ซื้อ' ), name: 'name', type: 'text', value: '', description: __( 'จำเป็น' ) },
                { label: __( 'เลขประจำตัวผู้เสียภาษี' ), name: 'tax_id', type: 'text', value: '' },
                { label: __( 'ที่อยู่' ), name: 'address', type: 'textarea', value: '' },
                { label: __( 'สถานประกอบการ' ), name: 'branch', type: 'text', value: 'สำนักงานใหญ่' },
                { label: __( 'โทรศัพท์' ), name: 'phone', type: 'text', value: '' },
            ],
        }
    },
    computed: {
        isFullForm() {
            return this.typeField.value === 'full';
        },

        /**
         * Mirrors the guard in ThaiTaxInvoiceService: a settled or part settled sale has a
         * tax point, a held or voided one does not.
         */
        isInvoiceable() {
            return [ 'paid', 'partially_paid' ].includes( this.order.payment_status );
        },
    },
    mounted() {
        this.load();
        this.popupCloser();
    },
    methods: {
        __,
        nsCurrency,
        popupCloser,
        popupResolver,

        closePopup() {
            this.popupResolver( true );
        },

        typeLabel( type ) {
            return type === 'full' ? __( 'เต็มรูป (A4)' ) : __( 'อย่างย่อ (58มม.)' );
        },

        load() {
            const orderId = this.popup.params.order.id;

            /**
             * The CRUD row cannot be used directly: its payment_status has been run through
             * a display cast, so it reads "Paid" rather than "paid" and every status check
             * here would fail. The order is fetched raw instead.
             */
            nsHttpClient.get( `/api/orders/${orderId}` ).subscribe({
                next: order => {
                    this.order = order;
                    this.prefillBuyer( order );
                    this.loadInvoices();
                },
                error: error => {
                    nsSnackBar.error( error.message || __( 'ไม่สามารถโหลดข้อมูลบิลได้' ) );
                    this.loaded = true;
                },
            });
        },

        loadInvoices() {
            nsHttpClient.get( `/api/orders/${this.order.id}/tax-invoices` ).subscribe({
                next: invoices => {
                    this.invoices = invoices;
                    this.loaded = true;
                },
                error: error => {
                    nsSnackBar.error( error.message || __( 'ไม่สามารถโหลดรายการใบกำกับภาษีได้' ) );
                    this.loaded = true;
                },
            });
        },

        /**
         * Filled from the customer on the order so the clerk does not retype what the shop
         * already knows. Left editable, because the person standing at the counter may be
         * buying for a company whose details differ from the account the sale was rung up on.
         */
        prefillBuyer( order ) {
            const customer = order.customer;
            const address = order.billing_address;

            if ( customer ) {
                this.fieldByName( 'name' ).value = `${ customer.first_name || '' } ${ customer.last_name || '' }`.trim();
                this.fieldByName( 'phone' ).value = customer.phone || '';
            }

            if ( address ) {
                this.fieldByName( 'address' ).value = [ address.address_1, address.address_2 ]
                    .filter( line => line )
                    .join( '\n' );
            }
        },

        fieldByName( name ) {
            return this.buyerFields.find( field => field.name === name );
        },

        handleTypeChange() {
            // The select writes straight to typeField.value; nothing else to do.
        },

        buyerPayload() {
            const payload = {};

            this.buyerFields.forEach( field => {
                payload[ field.name ] = field.value;
            });

            return payload;
        },

        issue() {
            if ( this.isFullForm && ! this.fieldByName( 'name' ).value ) {
                return nsSnackBar.error( __( 'ใบกำกับภาษีแบบเต็มรูปต้องระบุชื่อผู้ซื้อ' ) );
            }

            this.issuing = true;

            const payload = { type: this.typeField.value };

            if ( this.isFullForm ) {
                payload.buyer = this.buyerPayload();
            }

            nsHttpClient.post( `/api/orders/${this.order.id}/tax-invoice`, payload ).subscribe({
                next: result => {
                    this.issuing = false;
                    nsSnackBar.success( result.message );
                    this.openDocument( result.data.url );
                    this.loadInvoices();
                    this.refreshCrudTable();
                },
                error: error => {
                    this.issuing = false;
                    nsSnackBar.error( error.message || __( 'ออกใบกำกับภาษีไม่สำเร็จ' ) );
                },
            });
        },

        printInvoice( invoice ) {
            this.openDocument( `${ this.popup.params.taxInvoiceUrl }${ invoice.id }` );
        },

        /**
         * Opened in a tab rather than printed through the hidden iframe the receipt uses.
         * The A4 form carries an @page rule, and the iframe route strips the page geometry,
         * which would print the form at whatever size the browser defaults to.
         */
        openDocument( url ) {
            const separator = url.includes( '?' ) ? '&' : '?';

            window.open( `${ url }${ separator }dash-visibility=disabled&autoprint=true`, '_blank' );
        },

        cancelInvoice( invoice ) {
            new Promise( ( resolve, reject ) => {
                Popup.show( nsPromptPopupVue, {
                    resolve,
                    reject,
                    title: __( 'ยกเลิกใบกำกับภาษี' ),
                    message: __( 'เอกสารจะไม่ถูกลบและเลขที่จะไม่ถูกนำกลับมาใช้ซ้ำ กรุณาระบุเหตุผล' ),
                    onAction: reason => {
                        if ( reason === false ) {
                            return;
                        }

                        nsHttpClient.post( `/api/tax-invoices/${invoice.id}/cancel`, { reason }).subscribe({
                            next: result => {
                                nsSnackBar.success( result.message );
                                this.loadInvoices();
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

        refreshCrudTable() {
            if ( this.popup.params.component ) {
                this.popup.params.component.$emit( 'updated', true );
            }
        },
    },
}
</script>

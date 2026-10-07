<template>
    <div id="report-section" class="px-4">
        <p class="p-2 text-sm">ยอดขายรวมบิลรับเงินครบและยังรับไม่ครบ ตามวันที่ขาย โดยใช้ยอดคงเหลือหลังคืนสินค้า ยอดรับชำระด้านล่างเป็นยอดล่าสุดของบิลเหล่านี้ ไม่ใช่กระแสเงินสดเฉพาะวัน</p>
        <div class="flex -mx-2">
            <div class="px-2">
                <ns-date-time-picker :field="startDateField"></ns-date-time-picker>
            </div>
            <div class="px-2">
                <ns-date-time-picker :field="endDateField"></ns-date-time-picker>
            </div>
            <div class="px-2">
                <button @click="loadReport()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i :class="isLoading ? 'animate-spin' : ''" class="las la-sync-alt text-xl"></i>
                    <span class="pl-2">{{ __( 'Load' ) }}</span>
                </button>
            </div>
        </div>
        <div class="flex -mx-2">
            <div class="px-2">
                <button @click="printSaleReport()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i class="las la-print text-xl"></i>
                    <span class="pl-2">{{ __( 'Print' ) }}</span>
                </button>
            </div>
            <div class="px-2">
                <button @click="openSettings()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i class="las la-filter text-xl"></i>
                    <span class="pl-2">{{ __( 'By Type' ) }} : {{ getType( reportType.value ) }}</span>
                </button>
            </div>
            <div class="px-2">
                <button @click="openUserFiltering()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i class="las la-filter text-xl"></i>
                    <span class="pl-2">{{ __( 'By User' ) }} : {{ selectedUser || __( 'All Users' ) }}</span>
                </button>
            </div>
            <div class="px-2">
                <button @click="openCategoryFiltering()" class="rounded flex justify-between bg-input-button shadow py-1 items-center text-fontcolor px-2">
                    <i class="las la-filter text-xl"></i>
                    <span class="pl-2">{{ __( 'By Category' ) }} : {{ selectedCategory || __( 'All Category' ) }}</span>
                </button>
            </div>
        </div>
        <div id="sale-report" class="anim-duration-500 fade-in-entrance">
            <div class="flex w-full">
                <div class="my-4 flex justify-between w-full">
                    <div class="text-fontcolor-soft">
                        <ul>
                            <li class="pb-1 border-b border-dashed" v-html="__( 'Range : {date1} &mdash; {date2}' ).replace( '{date1}', startDateField.value ).replace( '{date2}', endDateField.value )"></li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'Document : Sale Report' ) }}</li>
                            <li class="pb-1 border-b border-dashed">{{ __( 'By : {user}' ).replace( '{user}', ns.user.username ) }}</li>
                        </ul>
                    </div>
                    <div>
                        <img class="w-24" :src="storeLogo" :alt="storeName">
                    </div>
                </div>
            </div>
            <div>
                <div class="-mx-4 flex md:flex-row flex-col">
                    <div class="w-full md:w-1/2 px-4">
                        <div class="shadow rounded my-4 ns-box">
                            <div class="border-b ns-box-body">
                                <table class="table ns-table w-full">
                                    <tbody class="text-fontcolor">
                                        <tr class="">
                                            <td width="200" class="font-semibold p-2 border text-left info text-white">{{ __( 'Sub Total' ) }}</td>
                                            <td class="p-2 border text-right border-info-primary">{{ nsCurrency( summary.subtotal ) }}</td>
                                        </tr>
                                        <tr class="">
                                            <td width="200" class="font-semibold p-2 border text-left error text-white">{{ __( 'Sales Discounts' ) }}</td>
                                            <td class="p-2 border text-right border-error-primary">{{ nsCurrency( summary.sales_discounts ) }}</td>
                                        </tr>
                                        <tr class="">
                                            <td width="200" class="font-semibold p-2 border text-left error text-white">{{ __( 'Sales Taxes' ) }}</td>
                                            <td class="p-2 border text-right border-error-primary">{{ nsCurrency( summary.sales_taxes ) }}</td>
                                        </tr>
                                        <!-- Shown only when it is not simply the sales tax under a second
                                             name. In every product VAT mode the order's tax is the sum of
                                             its lines' taxes, so listing both put the same money on screen
                                             twice and read as though the shop had been taxed twice. -->
                                        <tr class="" v-if="showProductTaxes">
                                            <td width="200" class="font-semibold p-2 border text-left error text-white">{{ __( 'Product Taxes' ) }}</td>
                                            <td class="p-2 border text-right border-error-primary">{{ nsCurrency( summary.product_taxes ) }}</td>
                                        </tr>
                                        <tr class="">
                                            <td width="200" class="font-semibold p-2 border text-left info text-white">{{ __( 'Shipping' ) }}</td>
                                            <td class="p-2 border text-right border-success-primary">{{ nsCurrency( summary.shipping ) }}</td>
                                        </tr>
                                        <tr class="">
                                            <td width="200" class="font-semibold p-2 border text-left success text-white">{{ __( 'Total' ) }}</td>
                                            <td class="p-2 border text-right border-success-primary">{{ nsCurrency( summary.total ) }}</td>
                                        </tr>
                                        <tr><td class="p-2 border">รับชำระสุทธิของบิลที่แสดง</td><td class="p-2 border text-right">{{ nsCurrency( summary.received ) }}</td></tr>
                                        <tr><td class="p-2 border">ยอดค้างชำระของบิลที่แสดง</td><td class="p-2 border text-right">{{ nsCurrency( summary.outstanding ) }}</td></tr>
                                        <tr class="">
                                            <td width="200" class="font-semibold p-2 border text-left success text-white">{{ __( 'Cost Of Goods' ) }}</td>
                                            <td class="p-2 border text-right border-success-primary">{{ nsCurrency( summary.total_purchase_price ) }}</td>
                                        </tr>
                                        <tr class="">
                                            <td width="200" class="font-semibold p-2 border text-left success text-white">{{ __( 'Profit' ) }}</td>
                                            <td class="p-2 border text-right border-success-primary">{{ nsCurrency( summary.profit ) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 px-4">
                    </div>
                </div>
            </div>
            <div class="bg-box-background shadow rounded my-4" v-if="reportType.value === 'products_report'">
                <div class="border-b border-box-edge">
                    <table class="table ns-table w-full">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="border p-2 text-left">{{ __( 'Products' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Quantity' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Discounts' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Cost' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Taxes' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Total' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Profit' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <tr v-for="product of result" :key="product.id">
                                <td class="p-2 border">{{ product.name }}</td>
                                <td class="p-2 border text-right">{{ product.quantity }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( product.discount ) }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( product.total_purchase_price ) }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( product.tax_value ) }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( product.total_price ) }}</td>
                                <td class="p-2 border text-right">{{ nsCurrency( productProfit( product ) ) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="text-fontcolor font-semibold">
                            <tr>
                                <td class="p-2 border text-font"></td>
                                <td class="p-2 border text-right text-font">{{ computeTotal( result, 'quantity' ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'discount' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_purchase_price' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'tax_value' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_price' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( collectionProfit( result ) ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="bg-box-background shadow rounded my-4" v-if="reportType.value === 'categories_report'">
                <div class="border-b border-box-edge">
                    <table class="table ns-table w-full">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="border p-2 text-left">{{ __( 'Category' ) }}</th>
                                <th class="border p-2 text-left">{{ __( 'Product' ) }}</th>
                                <th width="100" class="border p-2">{{ __( 'Quantity' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Discounts' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Taxes' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Total' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Purchase Price' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Profit' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <template v-for="(category, categoryIndex) of result" :key="categoryIndex">
                                <template v-if="category.products.length > 0">
                                    <tr v-for="(product) of category.products" :key="parseInt( category.id + '' + product.id )">
                                        <td class="p-2 border">{{ category.name }}</td>
                                        <td class="p-2 border">{{ product.name }}</td>
                                        <td class="p-2 border text-right">{{ product.quantity }}</td>
                                        <td class="p-2 border text-right">{{ nsCurrency( product.discount ) }}</td>
                                        <td class="p-2 border text-right">{{ nsCurrency( product.tax_value ) }}</td>
                                        <td class="p-2 border text-right">{{ nsCurrency( product.total_price ) }}</td>
                                        <td class="p-2 border text-right">{{ nsCurrency( product.total_purchase_price ) }}</td>
                                        <td class="p-2 border text-right">{{ nsCurrency( productProfit( product ) ) }}</td>
                                    </tr>
                                </template>
                                <tr class="bg-info-primary font-semibold">
                                    <td colspan="2" class="p-2 border border-info-secondary">{{ __( 'Subtotal' ) }} &mdash; {{ category.name }}</td>
                                    <td class="p-2 border text-right border-info-secondary">{{ computeTotal( category.products, 'quantity' ) }}</td>
                                    <td class="p-2 border text-right border-info-secondary">{{ nsCurrency( computeTotal( category.products, 'discount' ) ) }}</td>
                                    <td class="p-2 border text-right border-info-secondary">{{ nsCurrency( computeTotal( category.products, 'tax_value' ) ) }}</td>
                                    <td class="p-2 border text-right border-info-secondary">{{ nsCurrency( computeTotal( category.products, 'total_price' ) ) }}</td>
                                    <td class="p-2 border text-right border-info-secondary">{{ nsCurrency( computeTotal( category.products, 'total_purchase_price' ) ) }}</td>
                                    <td class="p-2 border text-right border-info-secondary">{{ nsCurrency( collectionProfit( category.products ) ) }}</td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="text-fontcolor font-semibold">
                            <tr>
                                <td colspan="2" class="p-2 border text-font">{{ __( 'Total' ) }}</td>
                                <td class="p-2 border text-right text-font">{{ computeTotal( result, 'total_sold_items' ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_discount' ) ) }}</td>
                                <!-- A category row carries total_tax_value, not tax_value. Asking for the
                                     latter is what printed "NaN" here and in the profit cell beside it. -->
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_tax_value' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_price' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_purchase_price' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( 
                                    computeTotal( result, 'total_price' ) -
                                    (
                                        computeTotal( result, 'total_purchase_price' ) +
                                        computeTotal( result, 'total_tax_value' )
                                    )
                                ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="bg-box-background shadow rounded my-4" v-if="reportType.value === 'categories_summary'">
                <div class="border-b border-box-edge">
                    <table class="table ns-table w-full">
                        <thead class="text-fontcolor">
                            <tr>
                                <th class="border p-2 text-left">{{ __( 'Category' ) }}</th>
                                <th width="100" class="border p-2">{{ __( 'Quantity' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Discounts' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Cost' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Taxes' ) }}</th>
                                <th width="150" class="border p-2">{{ __( 'Total' ) }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-fontcolor">
                            <template v-for="(category, categoryIndex) of result" :key="categoryIndex">
                                <tr class="">
                                    <td class="p-2 border text-left border-info-primary">{{ category.name }}</td>
                                    <td class="p-2 border text-right border-info-primary">{{ computeTotal( category.products, 'quantity' ) }}</td>
                                    <td class="p-2 border text-right border-info-primary">{{ nsCurrency( computeTotal( category.products, 'discount' ) ) }}</td>
                                    <td class="p-2 border text-right border-info-primary">{{ nsCurrency( computeTotal( category.products, 'total_purchase_price' ) ) }}</td>
                                    <td class="p-2 border text-right border-info-primary">{{ nsCurrency( computeTotal( category.products, 'tax_value' ) ) }}</td>
                                    <td class="p-2 border text-right border-info-primary">{{ nsCurrency( computeTotal( category.products, 'total_price' ) ) }}</td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="text-fontcolor font-semibold">
                            <tr>
                                <td class="p-2 border text-font"></td>
                                <td class="p-2 border text-right text-font">{{ computeTotal( result, 'total_sold_items' ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_discount' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_purchase_price' ) ) }}</td>
                                <td class="p-2 border text-right text-font">{{ nsCurrency( computeTotal( result, 'total_price' ) ) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import moment from "moment";
import nsDatepicker from "~/components/ns-datepicker.vue";
import { default as nsDateTimePicker } from '~/components/ns-date-time-picker.vue';
import { nsHttpClient, nsSnackBar } from '~/bootstrap';
import { __ } from '~/libraries/lang';
import nsSelectPopupVue from '~/popups/ns-select-popup.vue';
import { nsCurrency } from '~/filters/currency';
import { joinArray } from "~/libraries/join-array";

export default {
    name: 'ns-sale-report',
    data() {
        return {
            saleReport: '',
            startDateField: {
                name: 'start_date',
                type: 'datetime',
                value: ns.date.moment.startOf( 'day' ).format()
            },
            endDateField: {
                name: 'end_date',
                type: 'datetime',
                value: ns.date.moment.endOf( 'day' ).format()
            },
            result: [],
            isLoading: false,
            users: [],
            ns: window.ns,
            summary: {},
            selectedUser: '',
            selectedCategory: '',
            reportType: {
                label: __( 'Report Type' ),
                name: 'reportType',
                type: 'select',
                value: 'categories_report',
                options: [
                    {
                        label: __( 'Categories Detailed' ),
                        value: 'categories_report',
                    }, {
                        label: __( 'Categories Summary' ),
                        value: 'categories_summary',
                    }, {
                        label: __( 'Products' ),
                        value: 'products_report',
                    }
                ],
                description: __( 'Allow you to choose the report type.' ),
            },
            filterUser: {
                label: __( 'Filter User' ),
                name: 'filterUser',
                type: 'select',
                value: '',
                options: [
                    // ...
                ],
                description: __( 'Allow you to choose the report type.' ),
            },
            filterCategory: {
                label: __( 'Filter By Category' ),
                name: 'filterCategory',
                type: 'multiselect',
                value: '',
                options: [
                    // ...
                ],
                description: __( 'Allow you to choose the category.' ),
            },
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
         * Whether the product tax line adds anything the sales tax line does not already say.
         */
        showProductTaxes() {
            const productTaxes  =   parseFloat( this.summary.product_taxes );
            const salesTaxes    =   parseFloat( this.summary.sales_taxes );

            if ( ! isFinite( productTaxes ) || productTaxes <= 0 ) {
                return false;
            }

            return ! isFinite( salesTaxes ) || Math.abs( productTaxes - salesTaxes ) >= 0.005;
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
        joinArray,
        printSaleReport() {
            this.$htmlToPaper( 'sale-report' );
        },

        async openSettings() {
            try {
                const result    =   await new Promise( ( resolve, reject ) => {
                    Popup.show( nsSelectPopupVue, {
                        ...this.reportType,
                        resolve, 
                        reject
                    });
                });

                this.reportType.value   =   result;
                this.result             =   [];
                this.loadReport();
            } catch( exception ) {
                console.log({ exception })
            }
        },

        async openUserFiltering() {
            try {
                /**
                 * let's try to pull the users first.
                 */
                this.isLoading  =   true;
                const result    =   await new Promise( ( resolve, reject ) => {
                    nsHttpClient.get( `/api/users` )
                        .subscribe({
                            next: (users) => {
                                this.users      =   users;
                                this.isLoading  =   false;

                                this.filterUser.options     =   [
                                    {
                                        label: __( 'All Users' ),
                                        value: ''
                                    },
                                    ...this.users.map( user => {
                                        return {
                                            label: user.username,
                                            value: user.id
                                        }
                                    })
                                ];

                                Popup.show( nsSelectPopupVue, {
                                    ...this.filterUser,
                                    resolve, 
                                    reject
                                });
                            },
                            error: error => {
                                this.isLoading  =   false;
                                nsSnackBar.error( __( 'No user was found for proceeding the filtering.' ) );
                                reject( error );
                            }
                        });
                });  
                
                const searchUser  =   this.users.filter( __user => __user.id === result );

                if ( searchUser.length > 0 ) {
                    let user    =   searchUser[0];
                    this.selectedUser       =   `${user.username} ${(user.first_name || user.last_name) ? user.first_name + ' ' + user.last_name : '' }`;
                    this.filterUser.value   =   result;
                    this.result             =   [];
                    this.loadReport();
                }
            } catch( exception ) {
                console.log({ exception });
            }
        },

        async openCategoryFiltering() {
            try {
                let categories  =   [];

                this.isLoading  =   true;
                const result    =   await new Promise( ( resolve, reject ) => {
                    nsHttpClient.get( `/api/categories` )
                        .subscribe({
                            next: (retrievedCategories) => {
                                this.isLoading  =   false;
                                categories  =   retrievedCategories;
                                this.filterCategory.options     =   [
                                    ...retrievedCategories.map( category => {
                                        return {
                                            label: category.name,
                                            value: category.id
                                        }
                                    })
                                ];

                                Popup.show( nsSelectPopupVue, {
                                    ...this.filterCategory,
                                    resolve, 
                                    reject
                                });
                            },
                            error: error => {
                                this.isLoading  =   false;
                                nsSnackBar.error( __( 'No category was found for proceeding the filtering.' ) );
                                reject( error );
                            }
                        });
                });

                if ( result.length > 0 ) {
                    let categoryNames   =   categories
                        .filter( category => result.includes( category.id ) )
                        .map( category => category.name );

                    this.selectedCategory       =   this.joinArray( categoryNames );
                    this.filterCategory.value   =   result;
                } else {
                    this.selectedCategory       =   '';
                    this.filterCategory.value   =   [];
                }

                this.result             =   [];
                this.loadReport();

            } catch( exception ) {
                // ...
                console.log( exception );
            }
        },

        getType( type ) {
            const option    =   this.reportType.options.filter( option => {
                return option.value === type;
            });

            if ( option.length > 0 ) {
                return option[0].label;
            }

            return __( 'Unknown' );
        },        

        loadReport() {
            if ( this.startDate === null || this.endDate ===null ) {
                return nsSnackBar.error( __( 'Unable to proceed. Select a correct time range.' ) );
            }

            const startMoment   =   moment( this.startDate );
            const endMoment     =   moment( this.endDate );

            if ( endMoment.isBefore( startMoment ) ) {
                return nsSnackBar.error( __( 'Unable to proceed. The current time range is not valid.' ) );
            }

            this.isLoading  =   true;
            nsHttpClient.post( '/api/reports/sale-report', { 
                startDate: this.startDateField.value,
                endDate: this.endDateField.value,
                type: this.reportType.value,
                user_id: this.filterUser.value,
                categories_id: this.filterCategory.value
            }).subscribe({
                next: response => {
                    this.isLoading  =   false;
                    this.result     =   response.result;
                    this.summary    =   response.summary;
                }, 
                error : ( error ) => {
                    this.isLoading  =   false;
                    nsSnackBar.error( error.message );
                }
            });
        },

        /**
         * Profit on one sold line.
         *
         * The discount is deliberately not subtracted. total_price is already the discounted amount —
         * OrdersService stores ( unit price x quantity ) - discount — so taking the discount off again
         * charged it to the shop twice. The products table never did that; the categories table did, so
         * the same sale showed two different profits depending on which view was open. Both now use this.
         */
        productProfit( product ) {
            const total       =   parseFloat( product.total_price );
            const cost        =   parseFloat( product.total_purchase_price );
            const tax         =   parseFloat( product.tax_value );

            const safe        =   value => isFinite( value ) ? value : 0;

            return safe( total ) - ( safe( cost ) + safe( tax ) );
        },

        /**
         * Profit across a set of sold lines, using the same rule as a single line.
         */
        collectionProfit( collection ) {
            if ( ! Array.isArray( collection ) ) {
                return 0;
            }

            return collection.reduce( ( carry, product ) => carry + this.productProfit( product ), 0 );
        },

        /**
         * Sums one attribute across a collection.
         *
         * Anything that is not a number counts as zero. Before that, a single missing attribute turned
         * the whole column total into "NaN" on screen — which is how the tax and profit totals of this
         * report read, because the footer asked category rows for a key they do not carry. A wrong
         * spelling should cost one column a value, not replace a shop's figures with nonsense.
         */
        computeTotal( collection, attribute ) {
            if ( ! Array.isArray( collection ) ) {
                return 0;
            }

            return collection.reduce( ( carry, entry ) => {
                const value     =   parseFloat( entry === null ? NaN : entry[ attribute ] );

                return carry + ( isFinite( value ) ? value : 0 );
            }, 0 );
        },
    },
    props: [ 'storeLogo', 'storeName' ],
    mounted() {
        // ...
    }
}
</script>
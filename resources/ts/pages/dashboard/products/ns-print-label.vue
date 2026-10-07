<template>
    <div class="flex flex-auto overflow-hidden">
        <div class="flex-auto overflow-y-scroll ns-scrollbar bg-gray-900 p-10">
            <!--
                Without this the page looked broken. The paper below has no content until products are
                added and "ใช้ค่าที่ตั้งไว้" is pressed, so a first-time visitor was shown nothing but a
                dark empty rectangle with no indication that anything was missing or what to do next.
            -->
            <div class="h-full flex flex-col items-center justify-center text-gray-400" v-if="itemsToPrint.length === 0">
                <i class="las la-tags text-6xl"></i>
                <p class="py-2 font-semibold">{{ __( 'ยังไม่มีฉลากให้พิมพ์' ) }}</p>
                <p class="text-sm">{{ __( 'ค้นหาสินค้าทางขวา เลือกหน่วยและจำนวนดวง แล้วกด "ใช้ค่าที่ตั้งไว้"' ) }}</p>
            </div>
            <div class="shadow-lg bg-white mx-auto" id="label-printing-paper" :style="paperStyle" v-show="itemsToPrint.length > 0">
                <div v-for="(page, pageIndex) in printPages" :key="pageIndex" :class="{ 'label-roll-page': isRoll }" :style="gridStyle">
                    <!--
                        Every length here is a millimetre. The page used to describe a label in pixels,
                        which only become millimetres through a printer's own resolution, so a sheet
                        that fitted on one printer came out cropped on the next.
                    -->
                    <div class="label-item overflow-hidden" :style="labelStyle" v-for="(item, itemIndex) of page" :key="itemIndex">
                        <div class="font-bold text-center leading-tight truncate" v-if="visibility.show_store_name">{{ storename }}</div>
                        <div class="leading-tight" v-if="visibility.show_product_name">{{ item.name }}</div>
                        <div class="leading-tight" v-if="visibility.show_product_name">{{ item.selectedUnitQuantity.unit.name }}</div>
                        <div class="font-bold leading-tight" v-if="visibility.show_product_price">{{ nsCurrency( item.selectedUnitQuantity.sale_price ) }}</div>
                        <div class="flex justify-center flex-col items-center">
                            <img
                                :style="{ height: form.barcode_height + 'mm', maxWidth: '100%' }"
                                :src="barcodeSource( item.selectedUnitQuantity.barcode )"
                                :alt="item.selectedUnitQuantity.barcode">
                            <span class="leading-none" v-if="visibility.show_barcode_text">{{ item.selectedUnitQuantity.barcode }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="w-1/4 p-4 flex-shrink-0 overflow-y-scroll ns-scrollbar">
            <div>
                <div class="shadow ns-box mb-4">
                    <div class="header border-b ns-box-header p-2">
                        <h3 class="font-semibold">{{ __( 'Products' ) }}</h3>
                    </div>
                    <div class="body p-2">
                        <div class="input-group info rounded border-2">
                            <input v-model="search_product" class=" w-full p-2" :placeholder="__( 'Search Products...' )"/>
                        </div>
                        <div class="h-0 relative anim-duration-300 fade-in-entrance" v-if="resultSuggestions.length > 0">
                            <ul class="shadow-lg ns-vertical-menu absolute w-full z-10">
                                <li @click="addProduct( product )" v-for="product of resultSuggestions" class="border p-2 flex flex-col cursor-pointer" style="margin-bottom:-1px;">
                                    <span class="font-semibold">{{ product.name }}</span>
                                    <span class="text-xs">{{ product.barcode }}</span>
                                </li>
                            </ul>
                        </div>
                        <div class="flex flex-col" v-if="products.length > 0">
                            <h3 class="font-semibold">{{ __( 'Included Products' ) }}</h3>
                            <ul>
                                <li v-for="product of products" :key="product.id" class="border border-box-elevation-edge bg-box-elevation-background p-2 flex justify-between items-center" style="margin-bottom:-1px;">
                                    <p class="flex flex-col">
                                        <span class="font-semibold">{{ product.name }} ({{ product.selectedUnitQuantity.unit.name }}) x{{ product.times }}</span>
                                        <span class="text-xs">{{ product.selectedUnitQuantity.barcode }}</span>
                                    </p>
                                    <div class="flex items-center">
                                        <p class="flex flex-col">
                                            <button @click="editProduct( product )" class="rounded-full flex h-6 w-6 items-center bg-success-primary text-white justify-center">
                                                <i class="las la-cog"></i>
                                            </button>
                                        </p>
                                        <div>
                                            <span @click="removeProduct( product )" class="ml-1 cursor-pointer bg-error-primary text-white rounded-full h-6 w-6 flex items-center justify-center font-bold">
                                                <i class="las la-times"></i>
                                            </span>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="border-t ns-box-footer p-2 flex justify-between">
                        <ns-button @click="print()" type="success"><i class="las la-print mr-1"></i> {{ __( 'พิมพ์' ) }}</ns-button>
                        <ns-button @click="applySettings()" type="info">{{ __( 'ใช้ค่าที่ตั้งไว้' ) }}</ns-button>
                    </div>
                </div>

                <!--
                    The shop uses many sticker sizes and no fixed one, so the size has to be defined by
                    whoever loads the printer. Sizes are shared, and the one a branch chooses is
                    remembered for that branch — between sessions and between the tills in it.
                -->
                <div class="shadow ns-box mb-4">
                    <div class="header border-b ns-box-header p-2">
                        <h3 class="font-semibold">{{ __( 'ขนาดสติกเกอร์ที่บันทึกไว้' ) }}</h3>
                    </div>
                    <div class="body p-2 ns-box-body">
                        <div v-if="presets.length > 0">
                            <ul>
                                <li
                                    v-for="preset of presets"
                                    :key="preset.name"
                                    class="border border-box-elevation-edge bg-box-elevation-background p-2 flex justify-between items-center"
                                    style="margin-bottom:-1px;">
                                    <p class="flex flex-col cursor-pointer flex-auto" @click="usePreset( preset )">
                                        <span class="font-semibold" :class="preset.name === selectedPresetName ? 'text-success-tertiary' : ''">
                                            <i class="las" :class="preset.name === selectedPresetName ? 'la-check-circle' : 'la-circle'"></i>
                                            {{ preset.name }}
                                        </span>
                                        <span class="text-xs">{{ describePreset( preset ) }}</span>
                                    </p>
                                    <span
                                        @click="deletePreset( preset )"
                                        class="ml-1 cursor-pointer bg-error-primary text-white rounded-full h-6 w-6 flex items-center justify-center font-bold flex-shrink-0">
                                        <i class="las la-times"></i>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <p class="text-sm py-2" v-else>{{ __( 'ยังไม่มีขนาดที่บันทึกไว้ ตั้งค่าด้านล่างแล้วตั้งชื่อเก็บไว้ใช้ซ้ำได้' ) }}</p>
                        <div class="input-group info rounded border-2 mt-2 flex">
                            <input v-model="presetName" class="w-full p-2" :placeholder="__( 'ชื่อขนาด เช่น 50x25 มม. 3 ดวง' )"/>
                        </div>
                    </div>
                    <div class="border-t ns-box-footer p-2 flex justify-end">
                        <ns-button @click="savePreset()" type="info"><i class="las la-save mr-1"></i> {{ __( 'บันทึกขนาดนี้' ) }}</ns-button>
                    </div>
                </div>

                <div class="shadow ns-box mb-4 p-3 text-sm">
                    <h3 class="font-semibold">วิธีพิมพ์ด้วยเครื่องพิมพ์สติกเกอร์</h3>
                    <p class="py-2">เลือกขนาดกระดาษ “ม้วนสติกเกอร์” ด้านล่าง ใส่ขนาดดวงเดียว จำนวนดวงต่อแถว และช่องไฟแนวนอนตามม้วนจริง แล้วบันทึกขนาดไว้ใช้ซ้ำ</p>
                    <p v-if="isRoll" class="font-semibold">ขนาดหน้าในไดรเวอร์: {{ paperWidth }} × {{ form.label_height }} มม. ({{ form.columns }} ดวงต่อแถว)</p>
                    <p v-else class="font-semibold">ขณะนี้ใช้กระดาษแผ่น หากใช้เครื่องพิมพ์ม้วน ให้เปลี่ยนขนาดกระดาษก่อนพิมพ์</p>
                    <p class="py-2">ในหน้าต่างพิมพ์ เลือกเครื่องพิมพ์บาร์โค้ดและขนาดกระดาษให้ตรงกัน ใช้แนวตั้ง สเกล 100% ขอบไม่มี และปิดหัว/ท้ายกระดาษ ตั้งช่องว่างระหว่างแถว (Gap) ในไดรเวอร์ แล้วทดสอบ 1 แถวก่อน</p>
                    <p>เว็บไม่สามารถติดตั้งไดรเวอร์หรือปรับเซ็นเซอร์เครื่องพิมพ์ได้ หากดวงเลื่อน ให้ Calibrate ตามคู่มือเครื่อง ขนาดกระดาษต้องไม่เกินความกว้างที่เครื่องรองรับ</p>
                </div>
                <div class="shadow ns-box mb-4">
                    <div class="header border-b ns-box-header p-2">
                        <h3 class="font-semibold">{{ __( 'ขนาดฉลาก (มิลลิเมตร)' ) }}</h3>
                    </div>
                    <div class="body p-2 ns-box-body">
                        <ns-field :field="field" v-for="(field, index) of fields" :key="index"></ns-field>
                    </div>
                </div>
                <div class="shadow ns-box mb-4">
                    <div class="header border-b ns-box-header p-2">
                        <h3 class="font-semibold">{{ __( 'Visibility Settings' ) }}</h3>
                    </div>
                    <div class="body p-2 ns-box-body">
                        <ns-field :field="field" v-for="(field, index) of visibilityFields" class="mb-2" :key="index"></ns-field>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script lang="ts">
import { __ } from '~/libraries/lang';
import { defineComponent } from "vue";
import nsPrintLabelSettings from "./ns-print-label-settings.vue";

declare const Popup, nsEvent, nsHttpClient, nsSnackBar, FormValidation, nsCurrency;

/**
 * Named paper widths in millimetres, used to draw the preview at something close to its real size.
 * The printed sheet gets the name itself through @page, which is what the printer driver understands.
 */
const PAPER_SIZES = {
    a4: { width: 210, height: 297, css: 'A4' },
    a5: { width: 148, height: 210, css: 'A5' },
    letter: { width: 216, height: 279, css: 'Letter' },
};

export default defineComponent({
    name: 'ns-print-label',
    props: {
        // ...
        barcodeurl: {
            type: String,
            required: true,
        },
        storename: {
            type: String,
            required: true
        }
    },
    computed: {
        form() {
            return ( new FormValidation ).extractFields( this.fields );
        },
        visibility() {
            return ( new FormValidation ).extractFields( this.visibilityFields );
        },

        /**
         * A roll page holds one complete row, including every column. Everything else is a
         * sheet of stickers with a margin around the block.
         */
        isRoll() {
            return this.form.paper === 'roll';
        },

        paperStyle() {
            const margin    =   this.isRoll ? 0 : ( this.form.page_margin || 0 );

            return {
                width: this.paperWidth + 'mm',
                padding: margin + 'mm',
                boxSizing: 'border-box',
            };
        },

        paperWidth() {
            if ( this.isRoll ) {
                return Number( this.form.label_width || 50 ) * Number( this.form.columns || 1 )
                    + Number( this.form.gap_x || 0 ) * ( Number( this.form.columns || 1 ) - 1 );
            }

            if ( this.form.paper === 'custom' ) {
                return this.form.paper_width || 210;
            }

            return ( PAPER_SIZES[ this.form.paper ] || PAPER_SIZES.a4 ).width;
        },

        gridStyle() {
            const columns   =   Math.max( 1, parseInt( this.form.columns ) || 1 );

            return {
                display: 'grid',
                gridTemplateColumns: `repeat( ${ columns }, ${ this.form.label_width || 50 }mm )`,
                columnGap: ( this.form.gap_x || 0 ) + 'mm',
                rowGap: ( this.isRoll ? 0 : ( this.form.gap_y || 0 ) ) + 'mm',
                height: this.isRoll ? ( this.form.label_height || 25 ) + 'mm' : undefined,
                justifyContent: 'start',
            };
        },

        printPages() {
            if ( ! this.isRoll ) {
                return [ this.itemsToPrint ];
            }
            const columns = Math.max( 1, parseInt( this.form.columns ) || 1 );
            const pages = [];
            for ( let i = 0; i < this.itemsToPrint.length; i += columns ) {
                pages.push( this.itemsToPrint.slice( i, i + columns ) );
            }
            return pages;
        },

        labelStyle() {
            return {
                width: ( this.form.label_width || 50 ) + 'mm',
                height: ( this.form.label_height || 25 ) + 'mm',
                fontSize: ( this.form.font_size || 8 ) + 'pt',
                padding: '1mm',
                boxSizing: 'border-box',
                border: '1px dashed #999',
                color: '#000',
            };
        }
    },
    data() {
        return {
            resultSuggestions: [],
            fields: [],
            search_product: '',
            searchTimer: null,
            products: [],
            itemsToPrint: [],
            visibilityFields: [],
            printingPopup: null,
            presets: [],
            presetName: '',
            selectedPresetName: null,
        }
    },
    watch: {
        search_product() {
            if ( this.search_product.length > 0 ) {
                clearTimeout( this.searchTimer );
                this.searchTimer    =   setTimeout( () => {
                    this.searchProduct()
                }, 500 );
            } else {
                this.resultSuggestions      =   [];
            }
        }
    },
    mounted() {
        this.buildFields();
        this.loadPresets();
    },
    methods: {
        __,
        nsCurrency,

        /**
         * These labels are built here rather than in the template, so they were never passed through
         * __() and stayed in English on a Thai screen where every heading around them was translated.
         */
        buildFields() {
            const validation    =   new FormValidation;

            this.fields         =   validation.createFields([
                {
                    type: 'number',
                    label: __( 'ความกว้างสติกเกอร์ (มม.)' ),
                    name: 'label_width',
                    description: __( 'วัดจากขอบซ้ายถึงขอบขวาของดวงเดียว' ),
                    value: 50,
                }, {
                    type: 'number',
                    label: __( 'ความสูงสติกเกอร์ (มม.)' ),
                    name: 'label_height',
                    value: 25,
                }, {
                    type: 'select',
                    label: __( 'ขนาดกระดาษ' ),
                    name: 'paper',
                    description: __( 'เลือก "ม้วนสติกเกอร์" สำหรับเครื่องพิมพ์บาร์โค้ด แล้วกำหนดจำนวนดวงต่อแถวตามม้วนจริง' ),
                    value: 'a4',
                    options: [
                        { label: 'A4 (210 x 297 มม.)', value: 'a4' },
                        { label: 'A5 (148 x 210 มม.)', value: 'a5' },
                        { label: 'Letter (216 x 279 มม.)', value: 'letter' },
                        { label: __( 'ม้วนสติกเกอร์ (1 แถวต่อหน้า)' ), value: 'roll' },
                        { label: __( 'กำหนดขนาดเอง' ), value: 'custom' },
                    ],
                }, {
                    type: 'number',
                    label: __( 'กว้างกระดาษเอง (มม.)' ),
                    name: 'paper_width',
                    description: __( 'ใช้เมื่อเลือก "กำหนดขนาดเอง"' ),
                    value: 210,
                }, {
                    type: 'number',
                    label: __( 'สูงกระดาษเอง (มม.)' ),
                    name: 'paper_height',
                    value: 297,
                }, {
                    type: 'number',
                    label: __( 'จำนวนดวงต่อแถว' ),
                    name: 'columns',
                    value: 3,
                }, {
                    type: 'number',
                    label: __( 'ช่องไฟแนวนอน (มม.)' ),
                    name: 'gap_x',
                    value: 2,
                }, {
                    type: 'number',
                    label: __( 'ช่องไฟแนวตั้ง (มม.)' ),
                    name: 'gap_y',
                    description: __( 'ใช้กับกระดาษแผ่นเท่านั้น สำหรับม้วน ให้ตั้งช่องว่างระหว่างแถว (Gap) และปรับเซ็นเซอร์ในไดรเวอร์เครื่องพิมพ์' ),
                    value: 2,
                }, {
                    type: 'number',
                    label: __( 'ขอบกระดาษ (มม.)' ),
                    name: 'page_margin',
                    description: __( 'ใช้กับกระดาษแผ่นเท่านั้น โหมดม้วนไม่มีขอบเพิ่ม' ),
                    value: 5,
                }, {
                    type: 'number',
                    label: __( 'ความสูงบาร์โค้ด (มม.)' ),
                    name: 'barcode_height',
                    value: 12,
                }, {
                    type: 'number',
                    label: __( 'ขนาดตัวอักษร (pt)' ),
                    name: 'font_size',
                    value: 8,
                }
            ]);

            this.visibilityFields   =   validation.createFields([
                {
                    type: 'checkbox',
                    label: __( 'แสดงชื่อร้าน' ),
                    name: 'show_store_name',
                    value: true,
                }, {
                    type: 'checkbox',
                    label: __( 'แสดงเลขบาร์โค้ด' ),
                    name: 'show_barcode_text',
                    value: true,
                }, {
                    type: 'checkbox',
                    label: __( 'แสดงราคาขาย' ),
                    name: 'show_product_price',
                    value: true,
                }, {
                    type: 'checkbox',
                    label: __( 'แสดงชื่อสินค้า' ),
                    name: 'show_product_name',
                    value: true,
                }
            ]);
        },

        barcodeSource( barcode ) {
            return `${ this.barcodeurl }/${ encodeURIComponent( barcode ) }.png`;
        },

        describePreset( preset ) {
            const s     =   preset.settings || {};
            const paper =   s.paper === 'roll' ? __( 'ม้วน' ) : String( s.paper || 'a4' ).toUpperCase();

            return `${ s.label_width }x${ s.label_height } ${ __( 'มม.' ) } · ${ s.columns } ${ __( 'ดวง/แถว' ) } · ${ paper }`;
        },

        loadPresets() {
            nsHttpClient.get( '/api/products/label-presets' )
                .subscribe({
                    next: result => {
                        this.presets                =   result.presets || [];
                        this.selectedPresetName     =   result.selected?.name || null;

                        /**
                         * The branch's own saved size is applied on load, so whoever opens the page is
                         * already set up for the printer standing next to them.
                         */
                        if ( result.selected?.settings ) {
                            this.applyPresetSettings( result.selected.settings );
                        }

                        if ( this.selectedPresetName ) {
                            this.presetName     =   this.selectedPresetName;
                        }
                    },
                    error: error => nsSnackBar.error( error.message || __( 'โหลดขนาดฉลากที่บันทึกไว้ไม่ได้' ) ),
                });
        },

        applyPresetSettings( settings ) {
            [ ...this.fields, ...this.visibilityFields ].forEach( field => {
                if ( settings[ field.name ] !== undefined ) {
                    field.value     =   settings[ field.name ];
                }
            });
        },

        usePreset( preset ) {
            nsHttpClient.post( '/api/products/label-presets/select', { name: preset.name })
                .subscribe({
                    next: result => {
                        this.selectedPresetName     =   preset.name;
                        this.presetName             =   preset.name;
                        this.applyPresetSettings( result.data?.selected?.settings || preset.settings );
                        this.applySettings();
                        nsSnackBar.success( result.message );
                    },
                    error: error => nsSnackBar.error( error.message || __( 'เลือกขนาดฉลากไม่สำเร็จ' ) ),
                });
        },

        savePreset() {
            if ( ! this.validateLayout() ) { return; }
            if ( ( this.presetName || '' ).trim().length === 0 ) {
                return nsSnackBar.error( __( 'ตั้งชื่อขนาดฉลากก่อนบันทึก' ) );
            }

            nsHttpClient.post( '/api/products/label-presets', {
                name: this.presetName,
                settings: { ...this.form, ...this.visibility },
            }).subscribe({
                next: result => {
                    this.presets                =   result.data?.presets || this.presets;
                    this.selectedPresetName     =   result.data?.selected?.name || this.presetName;
                    nsSnackBar.success( result.message );
                },
                error: error => nsSnackBar.error( error.message || __( 'บันทึกขนาดฉลากไม่สำเร็จ' ) ),
            });
        },

        deletePreset( preset ) {
            nsHttpClient.delete( `/api/products/label-presets/${ encodeURIComponent( preset.name ) }` )
                .subscribe({
                    next: result => {
                        this.presets    =   result.data?.presets || [];

                        if ( this.selectedPresetName === preset.name ) {
                            this.selectedPresetName     =   null;
                        }

                        nsSnackBar.success( result.message );
                    },
                    error: error => nsSnackBar.error( error.message || __( 'ลบขนาดฉลากไม่สำเร็จ' ) ),
                });
        },

        removeProduct( product ) {
            const index     =   this.products.indexOf( product );
            this.products.splice( index, 1 );
        },

        /**
         * What @page has to say about the sheet. A named size is handed over by name, because the
         * driver knows those better than a pair of measurements; a roll is one complete row per page.
         */
        validateLayout() {
            const f = this.form;
            for ( const key of [ 'label_width', 'label_height', 'columns', 'barcode_height', 'font_size' ] ) {
                if ( ! Number.isFinite( Number( f[key] ) ) || Number( f[key] ) <= 0 ) {
                    nsSnackBar.error( __( 'กรอกขนาดฉลาก จำนวนดวง และขนาดข้อความเป็นตัวเลขมากกว่า 0' ) );
                    return false;
                }
            }
            if ( ! Number.isInteger( Number( f.columns ) ) || Number( f.columns ) > 12 ) {
                nsSnackBar.error( __( 'จำนวนดวงต่อแถวต้องเป็นจำนวนเต็ม 1–12' ) );
                return false;
            }
            for ( const key of [ 'gap_x', 'gap_y', 'page_margin' ] ) {
                if ( ! Number.isFinite( Number( f[key] ) ) || Number( f[key] ) < 0 ) {
                    nsSnackBar.error( __( 'ช่องไฟและขอบกระดาษต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป' ) );
                    return false;
                }
            }
            const width = Number( f.label_width ) * Number( f.columns ) + Number( f.gap_x ) * ( Number( f.columns ) - 1 );
            const height = f.paper === 'custom' ? Number( f.paper_height ) : ( PAPER_SIZES[f.paper] || PAPER_SIZES.a4 ).height;
            if ( ! this.isRoll && ( ! Number.isFinite( height ) || height <= 0 || ! Number.isFinite( Number( this.paperWidth ) ) || Number( this.paperWidth ) <= 0
                || width + 2 * Number( f.page_margin ) > Number( this.paperWidth )
                || Number( f.label_height ) + 2 * Number( f.page_margin ) > height ) ) {
                nsSnackBar.error( __( 'ฉลากและช่องไฟเกินขนาดกระดาษ กรุณาลดจำนวนดวงหรือแก้ขนาดกระดาษ' ) );
                return false;
            }
            return true;
        },

        pageRule() {
            const margin    =   this.isRoll ? 0 : ( this.form.page_margin || 0 );

            if ( this.isRoll ) {
                return `@page { size: ${ this.paperWidth }mm ${ this.form.label_height || 25 }mm; margin: 0; }`;
            }

            if ( this.form.paper === 'custom' ) {
                return `@page { size: ${ this.form.paper_width || 210 }mm ${ this.form.paper_height || 297 }mm; margin: ${ margin }mm; }`;
            }

            const named     =   ( PAPER_SIZES[ this.form.paper ] || PAPER_SIZES.a4 ).css;

            return `@page { size: ${ named }; margin: ${ margin }mm; }`;
        },

        print() {
            if ( ! this.validateLayout() ) { return; }
            const clipped = Array.from( document.querySelectorAll( '#label-printing-paper .label-item' ) )
                .some( ( label: HTMLElement ) => label.scrollHeight > label.clientHeight + 1 || label.scrollWidth > label.clientWidth + 1 );
            if ( clipped ) {
                return nsSnackBar.error( __( 'ข้อมูลเกินขนาดฉลาก กรุณาลดขนาดตัวอักษร/ความสูงบาร์โค้ด หรือปิดข้อมูลบางรายการก่อนพิมพ์' ) );
            }
            const windowFeatures = "menubar=yes,location=yes,resizable=yes,scrollbars=yes,status=yes"

            /**
             * Pressing print before applying the settings used to open an empty window and send a
             * blank sheet to the printer, which reads as "the printer is broken".
             */
            if ( this.itemsToPrint.length === 0 ) {
                return nsSnackBar.error( __( 'ยังไม่มีฉลากให้พิมพ์ เลือกสินค้าแล้วกด "ใช้ค่าที่ตั้งไว้" ก่อน' ) );
            }

            if ( this.printingPopup ) {
                this.printingPopup.close();
            }

            this.printingPopup     =   window.open( '', 'printPopup', windowFeatures );

            /**
             * A blocked popup returns null, and reaching into it throws a TypeError that surfaces as
             * nothing at all happening.
             */
            if ( this.printingPopup === null ) {
                return nsSnackBar.error( __( 'เบราว์เซอร์บล็อกหน้าต่างพิมพ์ กดอนุญาตป๊อปอัปของเว็บนี้ก่อน' ) );
            }

            const styleOutput   =   Array.from( document.querySelectorAll( 'link' ) ).map( link => link.outerHTML ).join( "\n" )
            const paper         =   document.getElementById( 'label-printing-paper' );

            this.printingPopup.document.writeln( `
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta http-equiv="X-UA-Compatible" content="IE=edge">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                ${styleOutput}
                <title>Printing Labels</title>
                <style>
                    ${ this.pageRule() }
                    body { margin: 0; }
                    #label-printing-paper { box-shadow: none !important; margin: 0 !important; padding: 0 !important; width: auto !important; }
                    /**
                     * The dashed outline is a cutting guide for the screen. Pre-cut sticker sheets do
                     * not need it printed on them.
                     */
                    .label-item { border: 0 !important; break-inside: avoid; page-break-inside: avoid; }
                    ${ this.isRoll ? '.label-roll-page { break-inside: avoid; page-break-inside: avoid; } .label-roll-page:not(:last-child) { break-after: page; page-break-after: always; }' : '' }
                </style>
            </head>
            <body>
                ${paper.outerHTML}
                ${'<scr' + 'ipt'} type="text/javascript">
                    /**
                     * Printing waits for the stylesheets and the barcode images copied above to
                     * finish loading. Called while the document was still parsing, the dialog opened
                     * over an unstyled page and the labels came out as a plain list with no barcodes.
                     */
                    window.addEventListener( 'load', function () { window.print(); } );
                ${'</scr' + 'ipt'}>
            </body>
            </html>
            ` );

            /**
             * The labels are already part of the document written above. Writing
             * them again appended a second copy to every printed sheet.
             */
            this.printingPopup.document.close();
        },
        async addProduct( product ) {
            try {
                await this.editProduct( product );   

                this.resultSuggestions      =   [];
                this.search_product         =   '';

                this.products.push( product );

            } catch( exception ) {
                console.log( exception );
            }
        },

        async editProduct( product ) {
            return new Promise( async ( resolve, reject ) => {
                const result:any    =   ( await new Promise( ( resolve, reject ) => {
                    Popup.show( nsPrintLabelSettings, { product, resolve, reject });
                }) );

                product.selectedUnitQuantity    =   result.selectedUnitQuantity;
                product.times                   =   result.times;

                this.$forceUpdate();

                return resolve( product );
            })
        },

        searchProduct() {
            nsHttpClient.post( `/api/products/search`, { search: this.search_product })
                .subscribe( result => {
                    this.resultSuggestions      =   result;
                }, ( error ) => {
                    nsSnackBar.error( error.message );
                })
        },

        applySettings() {
            if ( ! this.validateLayout() ) { return; }
            this.itemsToPrint   =   [];

            this.products.forEach( product => {
                /**
                 * An empty or non-numeric quantity produced new Array( NaN ), which throws and left
                 * the preview empty with nothing said about why.
                 */
                const times     =   Math.max( 1, parseInt( product.times ) || 1 );
                const reference =   ( new Array( times ) )
                    .fill( '' )
                    .map( _ => product );

                this.itemsToPrint.push( ...reference );
            });

            if ( this.itemsToPrint.length === 0 ) {
                nsSnackBar.error( __( 'ยังไม่ได้เลือกสินค้า' ) );
            }
        }
    }
})

</script>

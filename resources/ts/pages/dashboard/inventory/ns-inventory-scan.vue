<template>
    <div class="ns-box">
        <div class="ns-box-header p-2 border-b flex items-center justify-between">
            <h3 class="font-bold">{{ __( 'ยิงบาร์โค้ดเพื่อรับเข้า / นับสต๊อก' ) }}</h3>
            <span class="text-xs text-secondary">{{ __( 'ยิงได้ต่อเนื่อง ยิงซ้ำตัวเดิมจะบวกจำนวนให้เอง' ) }}</span>
        </div>

        <div class="ns-box-body p-2">
            <div class="flex flex-col md:flex-row md:-mx-2">
                <div class="md:px-2 md:w-1/2">
                    <label class="block text-sm font-bold">{{ __( 'สาขาที่นับ' ) }}</label>
                    <select v-model="branchId" class="my-2 block w-full text-sm border rounded p-2">
                        <option v-for="branch of branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                </div>
                <div class="md:px-2 md:w-1/2">
                    <label class="block text-sm font-bold">{{ __( 'รอบนี้คืออะไร' ) }}</label>
                    <div class="my-2 flex flex-col">
                        <label class="flex items-start text-sm py-1">
                            <input type="radio" v-model="mode" value="receive" class="mt-1">
                            <span class="px-2">
                                <b>{{ __( 'รับสินค้าเข้า' ) }}</b>
                                <span class="block text-xs text-secondary">{{ __( 'บวกเพิ่มจากยอดที่มีอยู่' ) }}</span>
                            </span>
                        </label>
                        <label class="flex items-start text-sm py-1">
                            <input type="radio" v-model="mode" value="set" class="mt-1">
                            <span class="px-2">
                                <b>{{ __( 'อัปเดตสต๊อก' ) }}</b>
                                <span class="block text-xs text-secondary">{{ __( 'ทับยอดเดิมด้วยจำนวนที่นับได้' ) }}</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <label class="block text-sm font-bold mt-2">{{ __( 'ยิงบาร์โค้ด' ) }}</label>
            <div class="border rounded flex overflow-hidden bg-input-background my-2">
                <button
                    :title="__( 'เปิด/ปิดการจับโฟกัสอัตโนมัติ' )"
                    @click="autofocus = ! autofocus"
                    :class="autofocus ? 'pos-button-clicked' : ''"
                    class="outline-hidden w-10 h-10 border-r">
                    <i class="las la-barcode"></i>
                </button>
                <input
                    ref="scanner"
                    v-model="barcode"
                    @keyup.enter="submitScan( barcode )"
                    type="text"
                    :placeholder="__( 'ยิงบาร์โค้ด หรือพิมพ์แล้วกด Enter...' )"
                    class="flex-auto outline-hidden px-2">
            </div>
            <div style="height: 0px">
                <div v-if="isLooking" class="fade-in-entrance ns-loader">
                    <div class="bar"></div>
                </div>
            </div>

            <p v-if="notice" class="text-sm py-2" :class="noticeIsError ? 'text-error-tertiary' : 'text-secondary'">{{ notice }}</p>

            <div v-if="choices.length > 0" class="my-2 border rounded p-2">
                <p class="text-sm font-bold">{{ chooseLabel }}</p>
                <div class="flex flex-wrap -mx-1 mt-1">
                    <button
                        v-for="choice of choices"
                        :key="choice.unit_quantity_id"
                        @click="addLine( choice )"
                        class="m-1 px-2 py-1 border rounded text-sm ns-inset-button">
                        {{ choice.unit_name }} · {{ __( 'คงเหลือ' ) }} {{ choice.on_hand }}
                    </button>
                </div>
            </div>

            <div v-if="lines.length === 0" class="py-4 text-center text-sm text-secondary">
                {{ __( 'ยังไม่มีรายการ — ยิงบาร์โค้ดแรกเพื่อเริ่ม' ) }}
            </div>

            <table v-else class="ns-table w-full my-2">
                <thead>
                    <tr>
                        <th class="text-left p-1 border">{{ __( 'สินค้า' ) }}</th>
                        <th class="text-left p-1 border">{{ __( 'หน่วย' ) }}</th>
                        <th class="text-right p-1 border">{{ __( 'คงเหลือ' ) }}</th>
                        <th class="text-right p-1 border" style="width: 120px;">{{ __( 'จำนวน' ) }}</th>
                        <th class="text-right p-1 border">{{ __( 'จะเป็น' ) }}</th>
                        <th class="p-1 border" style="width: 60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="( line, index ) of lines" :key="line.unit_quantity_id" class="ns-table-row border text-sm">
                        <td class="p-1 border">
                            <div>{{ line.product_name }}</div>
                            <div class="text-xs text-secondary">{{ line.barcode }}</div>
                        </td>
                        <td class="p-1 border">
                            <div>{{ line.unit_name }}</div>
                            <div v-if="line.stocks_on_unit_name" class="text-xs text-secondary">
                                {{ __( 'ตัดสต๊อกเป็น' ) }} {{ line.stocks_on_unit_name }} × {{ line.stocks_on_factor }}
                            </div>
                        </td>
                        <td class="p-1 border text-right">{{ line.on_hand }} {{ line.unit_name }}</td>
                        <td class="p-1 border text-right">
                            <input
                                type="text"
                                v-model="line.quantity"
                                class="w-full text-right border rounded p-1 bg-input-background outline-hidden">
                        </td>
                        <td class="p-1 border text-right">{{ resultFor( line ) }}</td>
                        <td class="p-1 border text-center">
                            <button @click="removeLine( index )" class="text-xs text-error-tertiary underline">{{ __( 'ลบ' ) }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="lines.length > 0" class="flex flex-col md:flex-row md:items-center md:justify-between py-2">
                <span class="text-sm text-secondary">{{ __( 'รวม' ) }} {{ lines.length }} {{ __( 'รายการ' ) }}</span>
                <div class="flex">
                    <button @click="clearLines()" class="mr-2 px-3 h-10 border rounded text-sm ns-inset-button">{{ __( 'ล้างรายการ' ) }}</button>
                    <button
                        @click="save()"
                        :disabled="isSaving"
                        class="px-3 h-10 rounded text-sm ns-button info">
                        {{ isSaving ? __( 'กำลังบันทึก...' ) : __( 'บันทึกเข้าสต๊อก' ) }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
<script lang="ts">
import { nsHttpClient, nsSnackBar } from '~/bootstrap';
import { __ } from '~/libraries/lang';

/**
 * Counting or receiving goods by scanning the shelf.
 *
 * The stock upload page could only be fed a spreadsheet. Receiving a delivery of eight lines meant
 * opening Excel, finding each barcode, typing the figure and uploading the file — so in practice the
 * count happened on paper and reached the system late or not at all. Scanning is how the goods are
 * actually in front of the person counting them.
 *
 * The scan rules are lifted from ns-pos-barcode-bar.vue rather than reinvented, because they were
 * settled against this shop's own readers: a minimum length so a half-typed barcode is not looked up, a
 * wait for the burst of digits to stop for readers that send no Enter, and a guard so the Enter and the
 * wait cannot both fire on one scan.
 *
 * Nothing is written while scanning. The list is built on screen and applied in one go, which is what
 * makes a miscount fixable: a figure written the instant a barcode is read cannot be taken back, and a
 * shelf is often scanned twice before the person is happy with the number.
 */
export default {
    name: 'ns-inventory-scan',
    props: [ 'branches', 'currentBranchId' ],
    data() {
        return {
            branchId: <number | null>null,
            mode: '',
            barcode: '',
            lines: <Array<{ [key: string]: any }>>[],
            choices: <Array<{ [key: string]: any }>>[],
            chooseLabel: '',
            notice: '',
            noticeIsError: false,
            isLooking: false,
            isSaving: false,
            autofocus: true,
            searchTimeout: <any>null,
            focusInterval: <any>null,

            /**
             * Shorter than any barcode in this catalogue. Without it the lookup ran against fragments
             * while the reader was still typing, which at the till produced a wall of errors on goods
             * that scan perfectly well.
             */
            minimumAutoSearchLength: 6,
        };
    },
    watch: {
        barcode() {
            this.scheduleScan();
        },
    },
    mounted() {
        const branches = this.branches || [];

        this.branchId = this.currentBranchId && this.currentBranchId > 0
            ? this.currentBranchId
            : ( branches.length > 0 ? branches[0].id : null );

        this.focusInterval = setInterval( () => this.keepFocus(), 500 );
    },
    unmounted() {
        clearInterval( this.focusInterval );
        clearTimeout( this.searchTimeout );
    },
    methods: {
        __,

        /**
         * Keeps the caret in the field so a USB reader, which types like a keyboard, always types here.
         * Skipped while a popup is open so the popup keeps its own focus, and skipped while the operator
         * is editing a quantity so a scan does not steal the caret mid-number.
         */
        keepFocus() {
            if ( ! this.autofocus || ! this.$refs.scanner ) {
                return;
            }

            if ( document.querySelectorAll( '.is-popup' ).length > 0 ) {
                return;
            }

            const active = document.activeElement as HTMLElement | null;

            if ( active && active !== this.$refs.scanner && [ 'INPUT', 'SELECT', 'TEXTAREA' ].includes( active.tagName ) ) {
                return;
            }

            this.$refs.scanner.focus();
        },

        /**
         * For readers that send no Enter: waits until the digits stop arriving, then looks the code up.
         */
        scheduleScan() {
            clearTimeout( this.searchTimeout );

            const value = String( this.barcode ).trim();

            if ( value.length < this.minimumAutoSearchLength ) {
                return;
            }

            this.searchTimeout = setTimeout( () => {
                if ( String( this.barcode ).trim().length < this.minimumAutoSearchLength ) {
                    return;
                }

                this.submitScan( this.barcode, false );
            }, 450 );
        },

        submitScan( rawValue, userInitiated = true ) {
            const value = String( rawValue === undefined || rawValue === null ? '' : rawValue ).trim();

            if ( value.length === 0 ) {
                this.barcode = '';

                if ( userInitiated ) {
                    this.say( __( 'เครื่องอ่านไม่ได้ส่งบาร์โค้ดมา ลองยิงอีกครั้ง และดูว่าสติกเกอร์ไม่ชำรุด' ), true );
                }

                return;
            }

            if ( this.branchId === null ) {
                return this.say( __( 'เลือกสาขาก่อนเริ่มยิง' ), true );
            }

            /**
             * Stops the Enter and the wait above from both looking up the same scan, which would add the
             * goods to the list twice.
             */
            if ( this.isLooking ) {
                return;
            }

            clearTimeout( this.searchTimeout );

            this.isLooking = true;
            this.choices = [];

            /**
             * Encoded because a barcode in this catalogue is not always digits: values such as "C1-75"
             * and "207*39" exist, and a raw slash, space or hash in the path would quietly change which
             * route is matched.
             */
            const url = `/api/inventory/scan/lookup/${ encodeURIComponent( value ) }`
                + `?branch_id=${ encodeURIComponent( String( this.branchId ) ) }`;

            nsHttpClient.get( url )
                .subscribe({
                    next: ( result: any ) => {
                        this.isLooking = false;
                        this.barcode = '';

                        if ( result.status === 'choose' ) {
                            this.choices = result.units || [];
                            this.chooseLabel = result.message;

                            return;
                        }

                        this.addLine( result.line );
                    },
                    error: ( error: any ) => {
                        this.isLooking = false;
                        this.barcode = '';
                        this.say( error.message || __( 'ค้นหาบาร์โค้ดนี้ไม่ได้' ), true );
                    },
                });
        },

        /**
         * Scanning the same goods again means one more of them, both when receiving a delivery and when
         * counting a shelf, so the quantity is raised rather than a second row appearing.
         */
        addLine( line ) {
            this.choices = [];

            const existing = this.lines.find( row => row.unit_quantity_id === line.unit_quantity_id );

            if ( existing !== undefined ) {
                existing.quantity = this.numberOf( existing.quantity ) + 1;
                this.say( `${ existing.product_name } · ${ existing.unit_name } → ${ existing.quantity }` );

                return;
            }

            this.lines.unshift( Object.assign( {}, line, { quantity: 1 } ) );
            this.say( `${ line.product_name } · ${ line.unit_name }` );
        },

        removeLine( index ) {
            this.lines.splice( index, 1 );
        },

        clearLines() {
            this.lines = [];
            this.notice = '';
        },

        numberOf( value ) {
            const parsed = parseFloat( String( value ).replace( /,/g, '' ) );

            return isNaN( parsed ) ? 0 : parsed;
        },

        /**
         * What the shelf will read once this is saved, worked out the same way the server does it, so the
         * operator can see the consequence of the mode before committing to it.
         */
        resultFor( line ) {
            const counted = this.numberOf( line.quantity );

            if ( this.mode === 'receive' ) {
                return `${ this.numberOf( line.on_hand ) + counted } ${ line.unit_name }`;
            }

            if ( this.mode === 'set' ) {
                return `${ counted } ${ line.unit_name }`;
            }

            return '—';
        },

        say( message, isError = false ) {
            this.notice = message;
            this.noticeIsError = isError;
        },

        save() {
            if ( this.isSaving ) {
                return;
            }

            if ( this.lines.length === 0 ) {
                return nsSnackBar.error( __( 'ยังไม่มีรายการที่ยิงเข้ามา' ) );
            }

            /**
             * No default mode, the same rule the file upload follows. Reading a delivery as a count loses
             * the goods already on the shelf; reading a count as a delivery doubles them. Neither can be
             * guessed from what was scanned.
             */
            if ( this.mode !== 'receive' && this.mode !== 'set' ) {
                return nsSnackBar.error( __( 'เลือกก่อนว่ารอบนี้คือการรับสินค้าเข้า หรือการตั้งยอดคงเหลือใหม่' ) );
            }

            const empty = this.lines.filter( line => this.numberOf( line.quantity ) <= 0 );

            if ( empty.length > 0 ) {
                return nsSnackBar.error( __( 'มีรายการที่จำนวนเป็น 0 — ใส่จำนวนหรือลบรายการนั้นออกก่อน' ) );
            }

            this.isSaving = true;

            nsHttpClient.post( '/api/inventory/scan/apply', {
                branch_id: this.branchId,
                stock_mode: this.mode,
                lines: this.lines.map( line => ( {
                    unit_quantity_id: line.unit_quantity_id,
                    quantity: this.numberOf( line.quantity ),
                } ) ),
            } ).subscribe({
                next: ( result: any ) => {
                    this.isSaving = false;
                    nsSnackBar.success( result.message );

                    if ( result.skipped && result.skipped.length > 0 ) {
                        this.say( result.skipped.join( ' | ' ), true );
                    } else {
                        this.notice = '';
                    }

                    this.lines = [];
                },
                error: ( error: any ) => {
                    this.isSaving = false;
                    nsSnackBar.error( error.message || __( 'บันทึกไม่สำเร็จ' ) );
                },
            });
        },
    },
};
</script>

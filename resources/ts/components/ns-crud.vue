<template>
    <div id="crud-table" class="w-full rounded-lg" :class="mode !== 'light' ? 'shadow mb-8': ''">
        <div id="crud-table-header" class="p-2 flex flex-col md:flex-row justify-between flex-wrap" v-if="mode !== 'light'">
            <div id="crud-search-box" class="w-full md:w-auto -mx-2 mb-2 md:mb-0 flex flex-wrap gap-y-2">
                <div v-if="createUrl" class="px-2 flex items-center justify-center">
                    <a :href="createUrl || '#'" class="rounded-full ns-crud-button text-sm h-10 flex items-center justify-center cursor-pointer px-3 outline-hidden border"><i class="las la-plus"></i></a>
                </div>
                <div class="px-2">
                    <div class="rounded-full p-1 ns-crud-input flex">
                        <input @keypress.enter="search()" v-model="searchInput" type="text" :aria-label="crudConfig.identifier === 'ns.products' ? 'ค้นหาสินค้า' : 'ค้นหา'" :placeholder="crudConfig.identifier === 'ns.products' ? 'เช่น ถ้วย 150 หรือ ถ้วย*150' : ''" class="w-36 md:w-auto bg-transparent outline-hidden px-2">
                        <button @click="search()" class="rounded-full w-8 h-8 outline-hidden ns-crud-input-button"><i class="las la-search"></i></button>
                        <button v-if="searchQuery" @click="cancelSearch()" class="ml-1 rounded-full w-8 h-8 outline-hidden ns-crud-input-button error"><i class="las la-times"></i></button>
                    </div>
                </div>
                <div v-if="crudConfig.identifier === 'ns.products'" class="px-2 flex items-center gap-1">
                    <input v-model="supplierInput" @keypress.enter="searchSupplier()" aria-label="ค้นหาซัพพลายเออร์ประจำ" placeholder="ชื่อ / รหัสซัพพลายเออร์ประจำ" class="ns-crud-input border rounded px-2 h-10 w-48">
                    <button @click="searchSupplier()" class="ns-crud-button border rounded px-2 h-10">ค้นหาซัพ</button>
                    <button v-if="supplierQuery" @click="supplierInput = ''; searchSupplier()" aria-label="ล้างซัพพลายเออร์" class="ns-crud-button border rounded px-2 h-10">×</button>
                </div>
                <div class="px-2 flex items-center justify-center">
                    <button @click="refresh()" class="rounded-full
                    text-sm
                    h-10
                    px-3
                    outline-hidden
                    border
                    ns-crud-button
                    "><i :class="isRefreshing ? 'animate-spin' : ''" class="las la-sync"></i> </button>
                </div>
                <div class="px-2 flex items-center" v-if="showQueryFilters">
                    <button @click="openQueryFilter()" :class="withFilters ? 'table-filters-enabled' : 'table-filters-disabled'" class="ns-crud-button border rounded-full text-sm h-10 px-3 outline-hidden ">
                        <i v-if="! withFilters" class="las la-filter"></i>
                        <i v-if="withFilters" class="las la-check"></i>
                        <span class="ml-1" v-if="! withFilters">{{ __( 'Filters' ) }}</span>
                        <span class="ml-1" v-if="withFilters">{{ __( 'Has Filters' ) }}</span>
                    </button>
                </div>
                <div id="custom-buttons" v-if="headerButtonsComponents.length > 0">
                    <component @update="handleHeaderButtonUpdate( $event )" :config="crudConfig" @refresh="refresh()" :selectedSubject="selectedEntriesSubject" :result="result" :is="component" :key="index" v-for="(component, index) of headerButtonsComponents"/>
                </div>
                <p v-if="crudConfig.identifier === 'ns.products'" class="w-full px-2 text-xs mt-1">ค้นหาหลายคำโดยเว้นวรรค หรือคั่นด้วย * / + ต้องตรงครบทุกคำ • ช่องซัพค้นจากซัพพลายเออร์ประจำในข้อมูลสินค้า</p>
            </div>
            <div id="crud-buttons" class="-mx-1 flex flex-wrap w-full md:w-auto">
                <div class="px-1 flex items-center" v-if="selectedEntries.length > 0  && showSelectedEntries">
                    <button @click="clearSelectedEntries()" class="flex justify-center items-center rounded-full text-sm h-10 px-3 outline-hidden ns-crud-button border">
                        <i class="lar la-check-square"></i> {{ __( '{entries} entries selected' ).replace( '{entries}', selectedEntries.length ) }}
                    </button>
                </div>
                <div class="px-1 flex items-center flex-wrap gap-2">
                    <select v-model="exportScope" aria-label="ขอบเขตข้อมูลดาวน์โหลด" class="ns-crud-input border rounded px-2 h-10">
                        <option value="filtered">ตามตัวกรองทุกหน้า</option>
                        <option value="selected">เฉพาะรายการที่เลือก ({{ selectedEntries.length }})</option>
                        <option value="all">ทั้งหมดตามสิทธิ์ (ไม่ใช้ตัวกรอง)</option>
                    </select>
                    <button @click="downloadContent()" :disabled="isExporting" class="flex justify-center items-center rounded-full text-sm h-10 px-3 ns-crud-button border outline-hidden"><i class="las la-download"></i> {{ isExporting ? 'กำลังสร้างไฟล์...' : 'ดาวน์โหลด Excel' }}</button>
                    <span class="text-xs w-full">“ตามตัวกรองทุกหน้า” ดึงข้อมูลครบตามคำค้นและตัวกรอง • “ทั้งหมดตามสิทธิ์” ไม่ใช้คำค้นและตัวกรอง</span>
                </div>
            </div>
        </div>
        <div id="crud-table-body" class="flex">
            <div class="overflow-x-auto flex-auto">
                <table class="table ns-table w-full" :class="isDenseTable ? 'ns-table-compact' : ''" v-if="Object.values( columns ).length > 0">
                    <thead>
                        <tr>
                            <th v-if="showCheckboxes" class="text-center p-1 border w-16 align-middle">
                                <ns-checkbox class="inline-block" :checked="globallyChecked" @change="handleGlobalChange( $event )"></ns-checkbox>
                            </th>
                            <th v-if="prependOptions && showOptions" class="text-left p-1 w-16 border"></th>
                            <th :key="identifier" @click="sort( identifier )" v-for="(column, identifier) of columns" :style="{ 
                                'width' : column.width || 'auto', 
                                'max-width': column.maxWidth || 'auto', 
                                'min-width': column.minWidth || 'auto' 
                            }" class="cursor-pointer justify-between border text-left p-1">
                                <div class="w-full flex justify-between items-center">
                                    <!--
                                        Headings never break. Thai carries no spaces, so a browser given
                                        too little room breaks it at whatever character it lands on:
                                        "เลขที่ใบวางบิล" came out of a squeezed purchase list as four
                                        stacked fragments, "เลขที่ / ใบ / วาง / บิล". Pinning the heading
                                        to one line costs a little width and makes the column readable.
                                    -->
                                    <span class="flex whitespace-nowrap">{{ column.label }}</span>
                                    <!--
                                        The sort arrow only reserves its box on a column that can be
                                        sorted. It used to be reserved on every column, so a list of
                                        eighteen unsortable columns gave away 432px to empty squares.
                                    -->
                                    <span v-if="column.$sort !== false" class="h-6 w-6 flex justify-center items-center">
                                        <i v-if="column.$direction === 'desc'" class="las la-sort-amount-up"></i>
                                        <i v-if="column.$direction === 'asc'" class="las la-sort-amount-down"></i>
                                    </span>
                                </div>
                            </th>
                            <th v-if="!prependOptions && showOptions" class="text-left p-1 w-16 border"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="result.data !== undefined && result.data.length > 0">
                            <ns-table-row
                                :key="index"
                                @updated="refreshRow( $event )"
                                v-for="(row,index) of result.data"
                                :columns="columns"
                                :prependOptions="prependOptions"
                                :showOptions="showOptions"
                                :showCheckboxes="showCheckboxes"
                                :row="row"
                                @reload="refresh()"
                                @toggled="handleShowOptions( $event )"></ns-table-row>
                        </template>
                        <tr v-if="! result || result.data.length === 0">
                            <td :colspan="Object.values( columns ).length + 2" class="text-center border py-3">{{ __( 'There is nothing to display...' ) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="p-2 flex flex-col md:flex-row justify-between footer">
            <div v-if="bulkActions.length > 0" id="grouped-actions" class="mb-2 md:mb-0 flex justify-between rounded-full ns-crud-input p-1">
                <select class="outline-hidden bg-transparent" v-model="bulkAction" id="grouped-actions">
                    <option class="bg-input-disabled" selected value=""><slot name="bulk-label">{{ __( 'Bulk Actions' ) }}</slot></option>
                    <option class="bg-input-disabled" :key="index" v-for="(action, index) of bulkActions" :value="action.identifier">{{ action.label }}</option>
                </select>
                <button @click="bulkDo()" class="ns-crud-input-button h-8 px-3 text-sm outline-hidden rounded-full flex items-center justify-center"><slot name="bulk-go">{{ __( 'Apply' ) }}</slot></button>
            </div>
            <div class="flex">
                <div class="items-center flex text-fontcolor mx-4 text-sm">{{ resultInfo }}</div>
                <div id="pagination" class="flex items-center -mx-1">
                    <template v-if="result.current_page">
                        <a href="javascript:void(0)" @click="page=result.first_page;refresh()" class="mx-1 flex items-center justify-center h-8 w-8 rounded-full ns-crud-button border shadow">
                            <i class="las la-angle-double-left"></i>
                        </a>
                        <template v-for="(_paginationPage, index) of pagination">
                            <a :key="index" v-if="page !== '...'" :class="page == _paginationPage ? 'active' : ''" @click="page=_paginationPage;refresh()" href="javascript:void(0)" class="mx-1 flex items-center justify-center h-8 w-8 rounded-full ns-crud-button border">{{ _paginationPage }}</a>
                            <a :key="index" v-if="page === '...'" href="javascript:void(0)" class="mx-1 flex items-center justify-center h-8 w-8 rounded-full ns-crud-button border">...</a>
                        </template>
                        <a href="javascript:void(0)" @click="page=result.last_page;refresh()" class="mx-1 flex items-center justify-center h-8 w-8 rounded-full ns-crud-button border shadow">
                            <i class="las la-angle-double-right"></i>
                        </a>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
<script lang="ts">
import { nsSnackBar } from '~/bootstrap';
import nsPosConfirmPopupVue from '~/popups/ns-pos-confirm-popup.vue';
import { Popup } from '~/libraries/popup';
import { __ } from '~/libraries/lang';
import { HttpStatusResponse } from '~/interfaces/http-status-response';
import { HttpCrudResponse } from '~/interfaces/http-crud-response';
import nsOrdersFilterPopupVue from '~/popups/ns-orders-filter-popup.vue';
import { defineAsyncComponent } from 'vue';

declare const nsCrudHandler;
declare const nsComponents;

export default {
    data: () => {
        return {
            prependOptions: false,
            showOptions: true,
            showCheckboxes: true,
            isRefreshing: false,
            sortColumn: '',
            searchInput: '',
            supplierInput: '',
            supplierQuery: '',
            queryFiltersString: '',
            searchQuery: '',
            page: 1,
            bulkAction: '',
            bulkActions: [],
            queryFilters:[],
            headerButtons: [],
            withFilters: false,
            selectedEntriesSubject: new RxJS.BehaviorSubject([]),
            columns: [],
            selectedEntries:[],
            exportScope: 'filtered',
            isExporting: false,
            globallyChecked: false,
            showSelectedEntries: true,
            crudConfig: {},

            /**
             * Live refresh state, used only when the page names a "liveChannel".
             */
            liveChannelSubscription: null,
            liveChannelTimer: null,
            liveRefreshTimer: null,
            result: {
                current_page: null,
                data: [],
                first_page_url: null,
                from: null,
                last_page: null,
                last_page_url: null,
                next_page_url: null,
                path: null,
                per_page: null,
                prev_page_url: null,
                to: null,
                total: null,
            }
        }
    },
    name: 'ns-crud',
    mounted() {
        if ( this.identifier !== undefined  ) {
            nsCrudHandler.defineInstance( this.identifier, this );
        }

        this.loadConfig();
        this.listenToLiveChannel();
    },
    unmounted() {
        this.stopListeningToLiveChannel();
    },
    watch: {
        selectedEntries: {
            deep: true, 
            handler( entries ) {
                this.selectedEntriesSubject.next( entries );

                // the selectedEntries can now be updated by external components.
                // when it's the case, entries that are visually selected remain selected event if the 
                // selectedEntrie is an empty array. We need to make sure that the entries are
                // aligned with the selectedEntries.
                this.result.data.forEach( row => {
                    const selected  =   entries.filter( e => e.$id === row.$id ).length > 0;

                    if( selected ) {
                        row.$checked  =   true;
                    } else {
                        row.$checked  =   false;
                    }
                });
            }
        }
    },
    /**
     * "liveChannel" is opt-in. A page that names a private broadcast channel gets its table
     * refreshed whenever anything is published there — the orders list uses "orders" so a
     * sale rung up on a till appears on a manager's screen without a reload. Left unset the
     * component behaves exactly as it did before.
     */
    props: [ 'src', 'createUrl', 'mode', 'identifier', 'queryParams', 'popup', 'liveChannel' ],
    computed: {
        /**
         * helps to get parsed
         * src link. Useful to enable sort
         * pagination, total items per pages
         */
        getParsedSrc() {
            return `${this.src}?${this.sortColumn}${this.searchQuery}${this.queryFiltersString}${this.queryPage}${this.supplierQuery ? '&supplier=' + encodeURIComponent(this.supplierQuery) : ''}${this.getQueryParams() ? '&' + this.getQueryParams() : ''}`
        },

        showQueryFilters() {
            return this.queryFilters.length > 0;
        },

        /**
         * A list carrying many columns is rendered smaller and tighter.
         *
         * The purchase list needs eighteen: the two desks that handle a purchase after it is raised
         * read the supplier's delivery note against the goods and the supplier's billing note against
         * the payment, and each of those documents has its own number and its own date. At the normal
         * size that does not fit a screen, so a list past a dozen columns switches to the compact
         * variant rather than being cut down. The threshold is a count and not a per-list setting so
         * no list has to remember to ask for it.
         */
        isDenseTable() {
            return Object.keys( this.columns || {} ).length > 12;
        },

        getSelectedAction() {
            const action    =   this.bulkActions.filter( action => {
                return action.identifier === this.bulkAction;
            });
            return action.length > 0 ? action[0] : false;
        },

        pagination() {
            if ( this.result ) {
                return this.pageNumbers( this.result.last_page, this.result.current_page );
            }
            return [];
        },

        queryPage() {
            if ( this.result ) {
                return `&page=${this.page}`
            }
            return '';
        },
        resultInfo() {
            return __( 'displaying {perPage} of {items} items' )
                .replace( '{perPage}', this.result.data.length || 0 )
                .replace( '{items}', this.result.total || 0 )
        },
        headerButtonsComponents() {
            return this.headerButtons.map( buttonComponent => {
                return defineAsyncComponent( () => {
                    return new Promise( ( resolve ) => {
                        resolve( nsComponents[ buttonComponent ] );
                    })
                })
            });
        },
    },
    methods: {
        __,
        getQueryParams() {
            if ( this.queryParams ) {
                return ( Object.keys( this.queryParams )
                    .map(key => `${key}=${this.queryParams[key]}`)
                    .join('&') );
            }
            return '';
        },

        pageNumbers(count, current) {
            var shownPages = 3;
            var result = [];

            if ( current - 3 > 1 ) {
                result.push( 1, '...' );
            }

            for( let i = 1; i <= count; i++ ) {
                if ( current + 3 > i && current - 3 < i ) {
                    result.push(i);
                }
            }

            if ( current + 3 < count ) {
                result.push( '...', count );
            }

            return result.filter( f => f > 0 || typeof f === 'string' );
        },

        // We'll allow external components to change this component state. We'll circumscribe the state that can be changed.
        // the $event consist of a object where the key is the property and the value is the new value.
        handleHeaderButtonUpdate( $event ) {
            for ( let key in $event ) {
                if ([
                    'selectedEntries',
                    'page',
                    'bulkAction',
                    'searchInput',
                    'globallyChecked',
                    'sortColumn',
                    'searchQuery',
                    'queryFiltersString',
                    'withFilters',
                    'bulkActions',
                ].includes( key )) {
                    this[ key ]  =   $event[ key ];
                }
            }
        },

        downloadContent() {
            if (this.isExporting) return;
            if (this.exportScope === 'selected' && this.selectedEntries.length === 0) {
                return nsSnackBar.error('กรุณาเลือกรายการที่ต้องการดาวน์โหลด');
            }
            this.isExporting = true;
            nsHttpClient.post( `${this.src}/export?${this.getParsedSrc}`, { scope: this.exportScope, entries : this.exportScope === 'selected' ? this.selectedEntries.map( e => e.$id ) : [] })
                .subscribe( (result: any) => {
                    this.isExporting = false;
                    setTimeout( () => document.location   =   result.url, 300 );
                    nsSnackBar
                        .success( __( 'The document has been generated.' ) );
                }, error => {
                    this.isExporting = false;
                    nsSnackBar
                        .error( error.message || __( 'Unexpected error occurred.' ) );
                })
        },

        searchSupplier() {
            this.search();
        },

        clearSelectedEntries() {
            Popup.show( nsPosConfirmPopupVue, {
                title: __( 'Clear Selected Entries ?' ),
                message: __( 'Would you like to clear all selected entries ?' ),
                onAction: ( action ) => {
                    if ( action ) {
                        this.selectedEntries    =   [];
                        this.handleGlobalChange( false );
                    }
                }
            });
        },

        /**
         * Will select a row and add it to a virtual storage
         * that will ensure that while browsing to pages, these
         * entries remains selected.
         * @param row actual row
         */
        refreshRow( row ) {
            if ( row.$checked === true ) {
                const result    =   this.selectedEntries.filter( e => e.$id === row.$id );

                if ( result.length  === 0 ) {
                    this.selectedEntries.push( row );
                }
            } else {
                const result    =   this.selectedEntries.filter( e => e.$id === row.$id );

                if ( result.length > 0 ) {
                    const index     =   this.selectedEntries.indexOf( result[0] );
                    this.selectedEntries.splice( index, 1 );
                }
            }
        },

        handleShowOptions( e ) {
            this.result.data.forEach( row => {
                if ( row.$id !== e.$id ) {
                    row.$toggled    =   false;
                }
            });
        },
        handleGlobalChange( event ) {
            this.globallyChecked    =   event;
            this.result.data.forEach( r => {
                r.$checked = event;
                this.refreshRow( r );
            });
        },
        loadConfig() {
            const request   =   nsHttpClient.get( `${this.src}/config?${this.getQueryParams()}` );
            request.subscribe( (f:any) => {
                this.crudConfig             =   f;
                this.columns                =   f.columns;
                this.bulkActions            =   f.bulkActions;
                this.queryFilters           =   f.queryFilters;
                this.prependOptions         =   f.prependOptions;
                this.showOptions            =   f.showOptions;
                this.showCheckboxes         =   f.showCheckboxes;
                this.headerButtons          =   f.headerButtons || [];
                this.showSelectedEntries    =   f.showSelectedEntries;
                this.refresh();
            }, ( error ) => {
                nsSnackBar.error( error.message, 'OK', { duration: false });
            });
        },
        cancelSearch() {
            this.searchInput    =   '';
            this.search();
        },
        search() {
            if (this.crudConfig.identifier === 'ns.products') {
                this.supplierQuery = this.supplierInput.trim();
            }
            if ( this.searchInput ) {
                this.searchQuery    =   `&search=${encodeURIComponent(this.searchInput)}`;
            } else {
                this.searchQuery    =   '';
            }

            this.page   =   1;

            this.refresh();
        },
        sort( identifier ) {
            if ( this.columns[ identifier ].$sort === false ) {
                return nsSnackBar.error( __( 'Sorting is explicitly disabled on this column' ) );
            }

            for ( let key in this.columns ) {
                if ( key !== identifier ) {
                    this.columns[ key ].$sorted     =   false;
                    this.columns[ key ].$direction  =   '';
                }
            }

            this.columns[ identifier ].$sorted      =   true;

            switch( this.columns[ identifier ].$direction ) {
                case 'asc':
                    this.columns[ identifier ].$direction   =   'desc';
                break;
                case 'desc':
                    this.columns[ identifier ].$direction   =   '';
                break;
                case '':
                default:
                    this.columns[ identifier ].$direction   =   'asc';
                break;
            }

            if ( [ 'asc', 'desc' ].includes( this.columns[ identifier ].$direction ) ) {
                this.sortColumn     =   `active=${identifier}&direction=${this.columns[ identifier ].$direction}`;
            } else {
                this.sortColumn     =   '';
            }

            this.$emit( 'sort', this.columns[ identifier ] );
            this.refresh();
        },
        bulkDo() {
            if ( this.bulkAction ) {
                if ( this.selectedEntries.length > 0 ) {
                    if ( confirm( this.getSelectedAction.confirm || __( 'Would you like to perform the selected bulk action on the selected entries ?' ) ) ) {
                        return nsHttpClient.post( `${this.src}/bulk-actions`, {
                            action: this.bulkAction,
                            entries: this.selectedEntries.map( r => r.$id )
                        }).subscribe({
                            next: (result: HttpStatusResponse ) => {
                                nsSnackBar.info( result.message );
                                this.selectedEntries    =   [];
                                this.refresh();
                            },
                            error: ( error ) => {
                                nsSnackBar.error( error.message );
                            }
                        })
                    }
                } else {
                    return nsSnackBar.error( __( 'No selection has been made.' ) );
                }
            } else {
                return nsSnackBar.error( __( 'No action has been selected.' ) );
            }

        },

        async openQueryFilter() {
            try {
                const result    =   await new Promise( ( resolve, reject ) => {
                    Popup.show( nsOrdersFilterPopupVue, { resolve, reject, queryFilters: this.queryFilters });
                });

                this.withFilters                =   false;
                this.queryFiltersString         =   '';

                /**
                 * in case there is a change
                 * on the query filters.
                 */
                if ( result !== null ) {
                    this.withFilters            =   true;
                    this.queryFiltersString     =   '&queryFilters=' + encodeURIComponent( JSON.stringify(result) );
                }

                this.refresh();
            } catch( exception ) {
                // ...
            }
        },

        /**
         * Refreshes the table when something is published on the channel this page named.
         *
         * Echo is created on DOMContentLoaded in the dashboard footer, and only when the
         * broadcast driver is reverb, so it may not exist yet — or at all. Rather than
         * assume, this polls briefly and then gives up quietly: a shop with realtime turned
         * off must not sit in a retry loop.
         */
        listenToLiveChannel( attempt: number = 0 ) {
            if ( this.liveChannel === undefined || this.liveChannel === null || this.liveChannel === '' ) {
                return;
            }

            const echo  =   ( window as any ).Echo;

            if ( ! echo ) {
                /**
                 * Roughly ten seconds of retries. Beyond that the driver is not reverb and
                 * no amount of waiting will produce an Echo instance.
                 */
                if ( attempt < 20 ) {
                    this.liveChannelTimer   =   setTimeout( () => this.listenToLiveChannel( attempt + 1 ), 500 );
                }

                return;
            }

            try {
                this.liveChannelSubscription    =   echo.private( this.liveChannel )
                    .listenToAll( () => this.scheduleLiveRefresh() );
            } catch ( exception ) {
                /**
                 * A subscription that cannot be established is a lost live update, not a
                 * broken page. The manual refresh button still works.
                 */
            }
        },

        stopListeningToLiveChannel() {
            clearTimeout( this.liveChannelTimer );
            clearTimeout( this.liveRefreshTimer );

            if ( this.liveChannelSubscription !== null && this.liveChannel ) {
                try {
                    ( window as any ).Echo.leave( this.liveChannel );
                } catch ( exception ) {
                    // nothing to leave
                }
            }
        },

        /**
         * Coalesces a burst of events into one request. A busy counter can ring up several
         * sales in a second, and one table reload per event would hammer the API for a
         * result the next event immediately invalidates.
         */
        scheduleLiveRefresh() {
            clearTimeout( this.liveRefreshTimer );

            this.liveRefreshTimer   =   setTimeout( () => {
                /**
                 * Refreshing under a selection would silently drop rows the user had ticked,
                 * and refreshing while a bulk action is being chosen is worse than being
                 * slightly stale.
                 */
                if ( this.selectedEntries.length === 0 ) {
                    this.refresh();
                }
            }, 1500 );
        },

        refresh() {
            this.globallyChecked    =   false;
            this.isRefreshing       =   true;
            const request           =   nsHttpClient.get( `${this.getParsedSrc}` );
            request.subscribe( (f:HttpCrudResponse) => {
                /**
                 * if the entries were already
                 * checked, we'll make sure to restore the checked status.
                 */
                f.data          =   f.data.map( entry => {
                    const selected  =   this.selectedEntries.filter( e => e.$id === entry.$id ).length > 0;

                    if( selected ) {
                        entry.$checked  =   true;
                    }

                    return entry;
                });

                this.isRefreshing   =   false;
                this.result     =   f;
                this.page       =   f.current_page;
            }, ( error ) => {
                this.isRefreshing   =   false;
                nsSnackBar.error( error.message );
            });
        }
    },
}
</script>

/**
 * Will bootstrap time and 
 * start counting
 */
import './shared/time';

import * as baseComponents  from './components/components';

import {
    createApp,
    defineAsyncComponent
}  from 'vue/dist/vue.esm-bundler';

import { NsHotPress }       from './libraries/ns-hotpress';
import VueHtmlToPaper from './libraries/html-printer';

const nsRewardsSystem               =   defineAsyncComponent( () => import( '~/pages/dashboard/rewards-system.vue' ) );
const nsCreateCoupons               =   defineAsyncComponent( () => import( './pages/dashboard/create-coupons.vue' ) );
const nsSettings                    =   defineAsyncComponent( () => import( './pages/dashboard/ns-settings.vue' ) );
const nsReset                       =   defineAsyncComponent( () => import( './pages/dashboard/reset.vue' ) );
const nsModules                     =   defineAsyncComponent( () => import( './pages/dashboard/modules.vue' ) );
const nsPermissions                 =   defineAsyncComponent( () => import( './pages/dashboard/ns-permissions.vue' ) );
const nsProcurement                 =   defineAsyncComponent( () => import( './pages/dashboard/procurements/ns-procurement.vue' ) );
const nsManageProducts              =   defineAsyncComponent( () => import( './pages/dashboard/procurements/manage-products.vue' ) );
const nsProcurementInvoice          =   defineAsyncComponent( () => import( './pages/dashboard/procurements/ns-procurement-invoice.vue' ) );
const nsNotifications               =   defineAsyncComponent( () => import( './pages/dashboard/ns-notifications.vue' ) );
const nsMedia                       =   defineAsyncComponent( () => import( './pages/dashboard/ns-media.vue' ) );
const nsMediaLibrary                =   defineAsyncComponent( () => import( './pages/dashboard/ns-media-library.vue' ) );
const nsTransaction                 =   defineAsyncComponent( () => import( './pages/dashboard/transactions/ns-transaction.vue' ) );
const nsDashboard                   =   defineAsyncComponent( () => import( './pages/dashboard/home/ns-dashboard.vue' ) );
const nsLowStockReport              =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-low-stock-report.vue' ) );
const nsSaleReport                  =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-sale-report.vue' ) );
const nsSoldStockReport             =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-sold-stock-report.vue' ) );
const nsProfitReport                =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-profit-report.vue' ) );
const nsStockCombinedReport         =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-stock-combined-report.vue' ) );
const nsCashFlowReport              =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-cash-flow-report.vue' ) );
const nsYearlyReport                =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-yearly-report.vue' ) );
const nsBestProductsReport          =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-best-products-report.vue' ) );
const nsPaymentTypesReport          =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-payment-types-report.vue' ) );
const nsVatReturnReport             =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-vat-return-report.vue' ) );
const nsWithholdingTaxReport        =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-withholding-tax-report.vue' ) );
const nsCustomersStatementReport    =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-customers-statement-report.vue' ) );
const nsReceivablesReport           =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-receivables-report.vue' ) );
const nsDeliveryNotesReport         =   defineAsyncComponent( () => import( './pages/dashboard/reports/ns-delivery-notes-report.vue' ) );
const nsBillingNoteIssue            =   defineAsyncComponent( () => import( './pages/dashboard/orders/ns-billing-note-issue.vue' ) );
const nsStockAdjustment             =   defineAsyncComponent( () => import( './pages/dashboard/products/ns-stock-adjustment.vue' ) );
const nsInventoryScan               =   defineAsyncComponent( () => import( './pages/dashboard/inventory/ns-inventory-scan.vue' ) );
const nsOrderInvoice                =   defineAsyncComponent( () => import( './pages/dashboard/orders/ns-order-invoice.vue' ) );
const nsPrintLabel                  =   defineAsyncComponent( () => import( './pages/dashboard/products/ns-print-label.vue' ) );
const nsTransactionsRules           =   defineAsyncComponent( () => import( './pages/dashboard/transactions/ns-transactions-rules.vue' ) );
const nsScaleSettingsPreview        =   defineAsyncComponent( () => import( './components/ns-scale-settings-preview.vue' ) );
const nsWirelessBarcodeSettingsTab  =   defineAsyncComponent( () => import( './pages/dashboard/settings/ns-wireless-barcode-tab.vue' ) );
const nsMarketplace                 =   defineAsyncComponent( () => import( './pages/dashboard/modules/ns-marketplace.vue' ) );

declare const window;
declare let nsExtraComponents;   
declare const nsHooks;

const nsState               =   window[ 'nsState' ];
const nsScreen              =   window[ 'nsScreen' ]; 

/**
 * bootstrap.ts puts these on the window before this file runs. They are read the same
 * way as nsState above rather than imported, so this module keeps a single instance
 * shared with every other script on the page instead of constructing its own.
 */
const nsHttpClient          =   window[ 'nsHttpClient' ];
const nsSnackBar            =   window[ 'nsSnackBar' ];
const __                    =   window[ '__' ];

nsExtraComponents.nsToken       =   defineAsyncComponent( () => import( './pages/dashboard/profile/ns-token.vue' ) );

window.VueHtmlToPaper   =   VueHtmlToPaper;
window.nsHotPress   =   new NsHotPress;

const allComponents    =   Object.assign({
    nsModules,
    nsRewardsSystem,
    nsCreateCoupons,
    nsManageProducts,
    nsSettings,
    nsReset,
    nsPermissions,
    nsProcurement,
    nsProcurementInvoice,
    nsMedia,
    nsTransaction,
    nsDashboard,
    nsPrintLabel,

    nsNotifications,
    nsMediaLibrary,
    nsSaleReport,
    nsSoldStockReport,
    nsProfitReport,
    nsStockCombinedReport,
    nsCashFlowReport,
    nsYearlyReport,
    nsPaymentTypesReport,
    nsVatReturnReport,
    nsWithholdingTaxReport,
    nsBestProductsReport,
    nsLowStockReport,
    nsCustomersStatementReport,
    nsReceivablesReport,
    nsDeliveryNotesReport,
    nsBillingNoteIssue,
    nsTransactionsRules,

    nsStockAdjustment,
    nsInventoryScan,
    nsOrderInvoice,
    nsScaleSettingsPreview,
    NsWireLessBarcodeSettingsTab: nsWirelessBarcodeSettingsTab,
    nsMarketplace,
    ...baseComponents
}, nsExtraComponents );

window.nsDashboardAside     =   createApp({
    data() {
        return {
            sidebar: 'visible',
            popups: [],
        }
    },
    components: {
        nsMenu : baseComponents.nsMenu,
        nsSubmenu : baseComponents.nsSubmenu,
    },
    mounted() {
        nsState.subscribe(( state ) => {
            if ( state.sidebar ) {
                this.sidebar    =   state.sidebar;
            }
        });
    }
});

window.nsDashboardOverlay   =   createApp({
    data() {
        return {
            sidebar: null,
            popups: []
        }
    },
    components: allComponents,
    mounted() {
        nsState.subscribe( state => {
            if ( state.sidebar ) {
                this.sidebar    =   state.sidebar;
            }
        });
    },
    methods: {
        /**
         * this is mean to appear only on mobile.
         * If it's clicked, the menu should hide
         */
        closeMenu() {
            nsState.setState({
                sidebar: this.sidebar === 'hidden' ? 'visible' : 'hidden'
            });
        },
    }
});

window.nsDashboardHeader    =   createApp({
    data() {
        return {
            menuToggled: false,
            branchMenuToggled: false,
            switchingBranch: false,
            sidebar: null,
        }
    },
    components: allComponents,
    methods: {
        toggleMenu() {
            this.menuToggled    =   !this.menuToggled;
        },

        /**
         * The branch picker in the header.
         *
         * It was written with Alpine directives — x-data, x-show, @click.away — but Alpine
         * is not part of this build, so none of them did anything. The button rendered and
         * ignored every click, and the dropdown kept the inline display:none it ships with.
         * The header is inside this Vue app, so the state belongs here alongside the account
         * menu it sits next to.
         */
        toggleBranchMenu() {
            this.branchMenuToggled  =   ! this.branchMenuToggled;
        },

        /**
         * Switching reloads the page, so the labels stay server rendered and there is no
         * client side branch state to keep in step with the session.
         */
        switchBranch( branchId ) {
            if ( this.switchingBranch ) {
                return;
            }

            this.switchingBranch    =   true;

            /**
             * This route lives in the web group, so it is CSRF verified. The token is sent
             * explicitly rather than relying on axios reading the XSRF cookie, which is a
             * behaviour this application never configured.
             */
            nsHttpClient.post( `/dashboard/branches/switch/${branchId}`, {}, {
                headers: {
                    'X-CSRF-TOKEN': window[ 'ns' ]?.csrfToken,
                }
            })
                .subscribe({
                    next: ( result ) => {
                        nsSnackBar.success( result.message );
                        this.branchMenuToggled  =   false;

                        setTimeout( () => document.location.reload(), 400 );
                    },
                    error: ( error ) => {
                        this.switchingBranch    =   false;
                        nsSnackBar.error( error.message || __( 'ไม่สามารถสลับสาขาได้' ) );
                    }
                });
        },

        toggleSideMenu() {
            if ([ 'lg', 'xl' ].includes( nsScreen.breakpoint ) ) {
                nsState.setState({ sidebar: this.sidebar === 'hidden' ? 'visible': 'hidden' });    
            } else {
                nsState.setState({ sidebar: this.sidebar === 'hidden' ? 'visible': 'hidden' });
            }
        }
    },
    mounted() {
        nsState.subscribe( ( state ) => {
            if ( state.sidebar ) {
                this.sidebar    =   state.sidebar;
            }
        })
    }
});

window.nsDashboardContent   =   createApp({});

/**
 * let's register the component that has
 * a valid name globally
 */
for( let name in allComponents ) {
    window.nsDashboardContent.component( name, allComponents[ name ] );
}

/**
 * let's add the library
 * to the body dashboard content
 */
window.nsDashboardContent.use( VueHtmlToPaper, {
    styles: Object.values( window.ns.cssFiles )
});

window.nsComponents          =   Object.assign( allComponents, baseComponents );

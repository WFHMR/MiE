declare const nsHooks;
declare const nsSnackBar;
declare const __;

export default class Print {
    private urls;
    private options;
    private printingURL     =   {
        'refund'    :   'refund_printing_url',
        'sale'      :   'sale_printing_url',
        'payment'   :   'payment_printing_url',
        'z-report'  :   'z_report_printing_url',
    }

    constructor({ urls, options }) {
        this.urls       =   urls || {};
        this.options    =   options || {};
    }

    /**
     * The dashboard pages declare their printing URLs and options as page globals
     * (systemUrls / systemOptions). The POS page declares neither: it hands the same
     * information to the POS object at DOMContentLoaded instead. The adapter therefore has
     * to be told about its context after construction, otherwise every print started from
     * the POS looks up a URL on an empty object — which is why closing a shift reported a
     * failed print and the sale receipt never reached the printer, without a single request
     * for either document appearing in the access log.
     *
     * Merged over what is already known rather than replacing it. The POS page carries both
     * sets — the orders footer is injected into it, and the POS hands over its own — and each
     * one holds keys the other does not: the footer knows the refund and payment documents and
     * the printing gateway, the POS knows the addresses its own buttons print. Replacing would
     * silently drop whichever half arrived first.
     */
    setContext({ urls = undefined, options = undefined }) {
        if ( urls !== undefined && urls !== null ) {
            this.urls   =   { ...this.urls, ...urls };
        }

        if ( options !== undefined && options !== null ) {
            this.options    =   { ...this.options, ...options };
        }
    }

    setCustomPrintingUrl( documentType, url ) {
        this.printingURL[ documentType ] = url;
    }

    processRegularPrinting( reference_id, documentType ) {
        const item  =   document.querySelector( '#printing-section' );

        if ( item ) {
            item.remove();
        }

        const template  =   this.urls[ this.printingURL[ documentType ] ];

        /**
         * Reported back rather than thrown, and left to the caller to announce. A missing URL is
         * a wiring mistake, and the callers that matter have already committed their work — the
         * shift is closed, the sale is recorded — so each one words the failure in terms of what
         * the operator can still do about it.
         */
        if ( template === undefined || template === null ) {
            console.error( `No printing URL is configured for the document type "${ documentType }".` );

            return false;
        }

        /**
         * The dashboard pages template these URLs on {reference_id}, the POS page on {id}.
         * Both are replaced so one adapter serves both without the caller having to know
         * which page it is running on.
         */
        const url               =   template
            .replace( '{reference_id}', reference_id )
            .replace( '{id}', reference_id );
        const printSection      =   document.createElement( 'iframe' );

        printSection.id         =   'printing-section';
        printSection.className  =   'hidden';
        printSection.src        =   url; // should be different regarding the document

        document.body.appendChild( printSection );

        setTimeout( () => {
            const section   =   document.querySelector( '#printing-section' );

            /**
             * The frame may already be gone, either removed by a print that started after
             * this one or by a page change. Calling remove() on null throws inside a timer,
             * where nothing catches it.
             */
            if ( section ) {
                section.remove();
            }
        }, 5000 );

        return true;
    }

    process( reference_id, document, mode = 'aloud' ) {
        /**
         * An unset gateway means the browser's own print dialog. Reading the raw value would
         * send an install that never saved the setting down the custom-gateway branch, where
         * it fails with "the selected print gateway doesn't support this type of printing".
         */
        const gateway   =   this.options.ns_pos_printing_gateway || 'default';

        switch( gateway ) {
            case 'default' : return this.processRegularPrinting( reference_id, document );
            default: return this.processCustomPrinting( reference_id, gateway, document, mode );
        }
    }

    processCustomPrinting( reference_id, gateway, document, mode = 'aloud' ) {
        const params    =   { printed: false, reference_id, gateway, document, mode };
        const result =  nsHooks.applyFilters( 'ns-custom-print', {
            params,
            promise: () => new Promise( ( resolve, reject ) => {
                reject({
                    status: 'error',
                    message: __( `The selected print gateway doesn't support this type of printing.`, 'NsPrintAdapter' )
                });
            }),
        });

        result.promise().then( result => {
            nsSnackBar.success( result.message );
        }).catch( exception => {
            nsSnackBar.error( exception.message || __( `An error unexpected occurred while printing.` ) );
        })    
    }
}

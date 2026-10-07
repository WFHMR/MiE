<script>
/**
 * Injected under the billing note register by RenderCrudTableFooterEventListener.
 *
 * The cancel row action is declared as a POPUP rather than a GET with a confirm dialog,
 * because the service refuses a cancellation without a written reason and a confirm has
 * nowhere to type one. No dedicated component is needed: the shared prompt popup collects
 * the reason, and the action already carries the endpoint in its url.
 */
document.addEventListener( 'DOMContentLoaded', () => {
    nsEvent.subject().subscribe( event => {
        if (
            event.identifier !== 'ns-table-row-action' ||
            event.value.action.identifier !== 'ns.billing-note-cancel'
        ) {
            return;
        }

        const action    =   event.value.action;
        const component =   event.value.component;

        new Promise( ( resolve, reject ) => {
            Popup.show( nsPromptPopup, {
                resolve,
                reject,
                title: '{{ __( 'ยกเลิกใบวางบิล' ) }}',
                message: '{{ __( 'บิลในใบนี้จะกลับมาวางบิลได้อีก แต่เลขที่ใบวางบิลจะไม่ถูกใช้ซ้ำ กรุณาระบุเหตุผล' ) }}',
                onAction: reason => {
                    if ( reason === false ) {
                        return;
                    }

                    nsHttpClient.post( action.url, { reason }).subscribe({
                        next: result => {
                            nsSnackBar.success( result.message );

                            /**
                             * Reloads the table rather than patching the row, so the status
                             * cell and the row colouring both come back from the server.
                             */
                            component.$emit( 'updated', true );
                        },
                        error: error => {
                            nsSnackBar.error( error.message || '{{ __( 'ยกเลิกไม่สำเร็จ' ) }}' );
                        },
                    });
                },
            });
        }).catch( () => {
            // the clerk backed out of the prompt
        });
    });
});
</script>

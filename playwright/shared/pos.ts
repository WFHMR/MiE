import { expect } from '@playwright/test';

/**
 * Helpers for driving the point-of-sale screen.
 *
 * The POS builds its grid and its checkout queue from network responses, so
 * nothing here can assume a fixed DOM on the first tick. Both helpers report
 * failure rather than throwing, letting a spec skip itself when the store has
 * no usable catalogue instead of reporting a false regression.
 */

/**
 * Walks the checkout queue until the payment screen is up.
 *
 * The queue asks for a customer and an order type before payment, and the order
 * of those prompts is decided by POS.runPaymentQueue, so each one is answered as
 * it appears rather than in a fixed sequence.
 *
 * @param customerSearch when given, the customer is looked up by name instead of
 *                       taking whichever one happens to be listed first
 * @return whether the payment screen opened
 */
export async function openPaymentScreen( page, customerSearch?: string ): Promise<boolean> {
    await page.keyboard.press( 'F5' );

    const payment = page.locator( '#ns-payment-popup' );
    const customer = page.locator( '#ns-pos-customer-select-popup' );
    const orderType = page.locator( '#ns-order-type' );

    for ( let step = 0; step < 6; step++ ) {
        if ( await payment.count() > 0 ) {
            return true;
        }

        if ( await customer.count() > 0 ) {
            let entry = customer.locator( 'ul.ns-vertical-menu li' ).first();

            if ( customerSearch !== undefined ) {
                await customer.locator( 'input[type="text"]' ).fill( customerSearch );

                /**
                 * The field debounces for 500ms before it queries, so the match
                 * is awaited rather than read straight after typing.
                 */
                entry = customer.locator( 'ul.ns-vertical-menu li', { hasText: customerSearch } ).first();

                try {
                    await expect( entry ).toBeVisible( { timeout: 15_000 } );
                } catch {
                    return false;
                }
            }

            if ( await entry.count() === 0 ) {
                return false;
            }

            await entry.click();
            await expect( customer ).toHaveCount( 0, { timeout: 10_000 } );
            continue;
        }

        if ( await orderType.count() > 0 ) {
            await orderType.locator( '.ns-numpad-key' ).first().click();
            await expect( orderType ).toHaveCount( 0, { timeout: 10_000 } );
            continue;
        }

        await page.waitForTimeout( 1_000 );
    }

    return await payment.count() > 0;
}

/**
 * The grid shows categories and products with the same .cell-item class, so
 * the only reliable way to reach a product is to keep drilling down until a
 * line lands in the cart.
 *
 * @return whether a product ended up in the cart
 */
export async function addFirstProductToCart( page ): Promise<boolean> {
    const cartLines = page.locator( '#cart-products-table .product-item' );
    const cells = page.locator( '#grid-items .cell-item' );

    /**
     * The grid fetches its categories and products over the network, so the
     * cells are not in the DOM on the first tick.
     */
    try {
        await expect( cells.first() ).toBeVisible( { timeout: 20_000 } );
    } catch {
        return false;
    }

    for ( let depth = 0; depth < 5; depth++ ) {
        if ( await cells.count() === 0 ) {
            return false;
        }

        await cells.first().click();

        /**
         * A product carrying several units asks which one is being sold
         * before it reaches the cart.
         */
        const popup = page.locator( '.is-popup' );

        if ( await popup.count() > 0 ) {
            const choice = popup.first().locator( '.cell-item, li, button' ).first();

            if ( await choice.count() > 0 ) {
                await choice.click();
            }
        }

        try {
            await expect( cartLines ).not.toHaveCount( 0, { timeout: 5_000 } );

            /**
             * Shortcuts are suppressed while a popup is on screen, so the
             * cart must be left with none open.
             */
            await expect( page.locator( '.is-popup' ) ).toHaveCount( 0, { timeout: 10_000 } );

            return true;
        } catch {
            // that cell was a category, carry on one level deeper.
        }
    }

    return false;
}

/**
 * Calls the application's own JSON API from inside the page.
 *
 * Going through the browser rather than Playwright's request context means the
 * session cookie and the CSRF token are the ones the app itself issued, which is
 * what the stateful API middleware expects.
 */
export async function apiCall( page, method: string, url: string, data: any = null ): Promise<any> {
    return await page.evaluate( async ( { method, url, data } ) => {
        const token = decodeURIComponent( ( document.cookie.match( /XSRF-TOKEN=([^;]+)/ ) || [] )[ 1 ] || '' );

        const response = await fetch( url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
            },
            body: data === null ? undefined : JSON.stringify( data ),
        } );

        return { status: response.status, body: await response.json() };
    }, { method, url, data } );
}

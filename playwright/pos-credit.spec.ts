import { test, expect } from '@playwright/test';
import { addFirstProductToCart, openPaymentScreen, apiCall } from './shared/pos';

/**
 * Credit terms at the counter.
 *
 * A trade customer takes the goods and settles the invoice later. From the
 * cashier's seat that has to be distinguishable from a layaway, which looks
 * identical on the payment screen: both are unpaid, but only one releases the
 * stock. The screen therefore says so, and the button changes its wording.
 *
 * The customer group and the customer are created through the application's own
 * API rather than by clicking through two CRUD forms, keeping the spec about the
 * part only a browser can answer.
 */

/**
 * Fixture names are fixed rather than stamped with the run time. A unique name per
 * run would leave behind a new customer group and a new customer every time the
 * suite is executed, which slowly fills the store's own lists with test data.
 */
const CREDIT_GROUP = 'E2E Credit Term 30 Days';
const CREDIT_CUSTOMER = 'E2ECreditCustomer';
const CASH_GROUP = 'E2E Cash Only';
const CASH_CUSTOMER = 'E2ECashCustomer';

/**
 * Returns the customer group with the given name, creating it on the first run.
 *
 * The creation goes through the CRUD form, so a term that came back on the created
 * entry also proves the new field is part of the form definition. A field the form
 * does not declare is dropped silently rather than rejected.
 */
async function ensureCustomerGroup( page, name: string, term: number ): Promise<number> {
    const existing = await apiCall( page, 'GET', '/api/crud/ns.customers-groups?perPage=500' );

    const found = ( existing.body?.data ?? [] ).find( ( row ) => row.name === name );

    if ( found !== undefined ) {
        return found.id;
    }

    const created = await apiCall( page, 'POST', '/api/crud/ns.customers-groups', {
        name,
        general: {
            minimal_credit_payment: 0,
            payment_term_days: term,
        },
    } );

    expect( created.status, `creating "${name}" failed: ${JSON.stringify( created.body )}` ).toBe( 200 );

    expect(
        Number( created.body?.data?.entry?.payment_term_days ),
        'the customer group form dropped the credit term'
    ).toBe( term );

    return created.body?.data?.entry?.id;
}

/**
 * Returns the name of a customer belonging to the given group, creating him on the
 * first run. The name is what the POS customer search is driven with.
 */
async function ensureCustomer( page, name: string, groupId: number ): Promise<string> {
    const search = await apiCall( page, 'POST', '/api/customers/search', { search: name } );

    const found = ( search.body ?? [] ).find( ( customer ) => customer.first_name === name );

    if ( found !== undefined ) {
        return name;
    }

    const created = await apiCall( page, 'POST', '/api/crud/ns.customers', {
        first_name: name,
        general: {
            group_id: groupId,
            last_name: 'Automated',
            email: `${name.toLowerCase()}@example.test`,
        },
    } );

    expect( created.status, `creating "${name}" failed: ${JSON.stringify( created.body )}` ).toBe( 200 );

    return name;
}

test.describe( 'POS credit terms', () => {

    const term = 30;

    test( 'a credit sale is announced, released and dated', async ( { page } ) => {
        test.slow();

        await page.goto( '/dashboard/pos' );
        await page.waitForSelector( '#pos-container', { state: 'visible', timeout: 30_000 } );

        const groupId = await ensureCustomerGroup( page, CREDIT_GROUP, term );
        const customerName = await ensureCustomer( page, CREDIT_CUSTOMER, groupId );

        const added = await addFirstProductToCart( page );

        test.skip( ! added, 'no product could be added from the grid' );

        const opened = await openPaymentScreen( page, customerName );

        test.skip( ! opened, 'the checkout queue could not reach the payment screen' );

        const paymentPopup = page.locator( '#ns-payment-popup' );
        await expect( paymentPopup ).toBeVisible( { timeout: 20_000 } );

        /**
         * The cashier is told, before touching a button, that this customer is
         * on terms and when the money is expected.
         */
        const notice = paymentPopup.locator( '[data-role="credit-term-notice"]' );

        await expect( notice ).toBeVisible( { timeout: 10_000 } );
        await expect( notice ).toContainText( String( term ) );

        /**
         * And the button stops reading like a layaway. Asserted on the icon
         * rather than the wording, which follows the store's language.
         */
        const creditButton = paymentPopup.locator( '[data-action="save-as-unpaid"]' );

        await expect( creditButton ).toBeVisible();
        await expect( creditButton.locator( 'i.la-file-invoice-dollar' ) ).toHaveCount( 1 );
        await expect( creditButton.locator( 'i.la-hands-helping' ) ).toHaveCount( 0 );

        await creditButton.click();

        const confirm = page.locator( '#confirm-popup' );
        await expect( confirm ).toBeVisible( { timeout: 10_000 } );

        await confirm.locator( '.action-buttons button:not(.cancel)' ).click();

        // A successful submission closes the payment screen.
        await expect( paymentPopup ).toHaveCount( 0, { timeout: 30_000 } );

        /**
         * The order the POS just recorded. The shortcut bar keeps its identifier
         * for the reprint shortcut, which is the only handle on it once the cart
         * has been reset.
         */
        const orderId = await page.evaluate( () => window.localStorage.getItem( 'ns-pos-last-order-id' ) );

        expect( orderId, 'the credit sale did not produce an order' ).toBeTruthy();

        const order = ( await apiCall( page, 'GET', `/api/orders/${orderId}` ) ).body;

        expect( order.payment_status, 'a credit sale should be recorded as unpaid' ).toBe( 'unpaid' );
        expect( order.final_payment_date, 'the credit sale carries no due date' ).toBeTruthy();
        expect( Number( order.total_instalments ) ).toBe( 1 );

        const instalments = ( await apiCall( page, 'GET', `/api/orders/${orderId}/instalments` ) ).body;

        expect( Array.isArray( instalments ) ? instalments.length : 0, 'the invoice has no instalment to collect' ).toBe( 1 );
        expect( Boolean( instalments[ 0 ].paid ), 'the invoice instalment should still be outstanding' ).toBe( false );

        expect( Array.isArray( order.products ) ? order.products.length : 0, 'the order recorded no product' ).toBeGreaterThan( 0 );
    } );

    test( 'a cash customer is not offered credit wording', async ( { page } ) => {
        test.slow();

        await page.goto( '/dashboard/pos' );
        await page.waitForSelector( '#pos-container', { state: 'visible', timeout: 30_000 } );

        const groupId = await ensureCustomerGroup( page, CASH_GROUP, 0 );
        const customerName = await ensureCustomer( page, CASH_CUSTOMER, groupId );

        const added = await addFirstProductToCart( page );

        test.skip( ! added, 'no product could be added from the grid' );

        const opened = await openPaymentScreen( page, customerName );

        test.skip( ! opened, 'the checkout queue could not reach the payment screen' );

        const paymentPopup = page.locator( '#ns-payment-popup' );
        await expect( paymentPopup ).toBeVisible( { timeout: 20_000 } );

        await expect( paymentPopup.locator( '[data-role="credit-term-notice"]' ) ).toHaveCount( 0 );

        const unpaidButton = paymentPopup.locator( '[data-action="save-as-unpaid"]' );

        await expect( unpaidButton.locator( 'i.la-hands-helping' ) ).toHaveCount( 1 );
        await expect( unpaidButton.locator( 'i.la-file-invoice-dollar' ) ).toHaveCount( 0 );
    } );
} );

import { test, expect } from '@playwright/test';
import { addFirstProductToCart, openPaymentScreen } from './shared/pos';

/**
 * Entering an amount at the till.
 *
 * The pad used to treat every key as the smallest unit of the currency, so "5" meant
 * five satang and 1,504 baht had to be entered as 150400. A cashier reads the total off
 * the screen and types it; one keystroke short of the padding produced a payment a
 * hundred times too small, and nothing on screen looked obviously wrong.
 *
 * The amount is now written as it is spoken, and the readout is an input so a keyboard
 * can be used instead of the pad.
 */

test.describe( 'POS amount entry', () => {

    let screen, paymentPopup;

    test.beforeEach( async ( { page } ) => {
        test.slow();

        await page.goto( '/dashboard/pos' );
        await page.waitForSelector( '#pos-container', { state: 'visible', timeout: 30_000 } );

        const added = await addFirstProductToCart( page );

        test.skip( ! added, 'no product could be added from the grid' );

        const opened = await openPaymentScreen( page );

        test.skip( ! opened, 'the checkout queue could not reach the payment screen' );

        paymentPopup = page.locator( '#ns-payment-popup' );
        await expect( paymentPopup ).toBeVisible( { timeout: 20_000 } );

        screen = paymentPopup.locator( '#screen [data-role="numpad-screen"]' );
        await expect( screen ).toBeVisible( { timeout: 10_000 } );
    } );

    /**
     * @param page
     * @param identifiers keys to press, by their identifier
     */
    async function press( page, identifiers: ( string | number )[] ) {
        for ( const identifier of identifiers ) {
            await paymentPopup.locator( `[data-identifier="${identifier}"]` ).first().click();
        }
    }

    test( 'a single key press means whole units, not fractions', async ( { page } ) => {
        await press( page, [ 5 ] );

        await expect( screen ).toHaveValue( '5' );
    } );

    test( 'keys append left to right', async ( { page } ) => {
        await press( page, [ 1, 5, 0, 4 ] );

        await expect( screen ).toHaveValue( '1504' );
    } );

    test( 'the separator key adds a fractional part once', async ( { page } ) => {
        await press( page, [ 1, 2, '.', 5, '.', 0 ] );

        await expect( screen ).toHaveValue( '12.50' );
    } );

    test( 'backspace removes the last key rather than clearing everything', async ( { page } ) => {
        await press( page, [ 1, 2, 3, 'backspace' ] );

        await expect( screen ).toHaveValue( '12' );
    } );

    test( 'the amount recorded is the amount shown', async ( { page } ) => {
        await press( page, [ 5, 0, 0 ] );

        await expect( screen ).toHaveValue( '500' );

        await paymentPopup.locator( '[data-action="add-payment"]' ).click();

        const payments = await page.evaluate( () => POS.order.getValue().payments );

        expect( payments.length, 'no payment was recorded' ).toBe( 1 );
        expect( payments[ 0 ].value, 'the payment does not match what was on the screen' ).toBe( 500 );
    } );

    test( 'the readout can be typed into directly', async ( { page } ) => {
        await screen.fill( '' );
        await screen.type( '250.75' );

        await expect( screen ).toHaveValue( '250.75' );

        /**
         * The pad and the keyboard write to the same amount, so a key pressed after
         * typing appends instead of replacing what was typed.
         */
        await press( page, [ 'backspace' ] );

        await expect( screen ).toHaveValue( '250.7' );

        await paymentPopup.locator( '[data-action="add-payment"]' ).click();

        const payments = await page.evaluate( () => POS.order.getValue().payments );

        expect( payments.length ).toBe( 1 );
        expect( payments[ 0 ].value ).toBe( 250.7 );
    } );

    test( 'letters typed into the readout are ignored', async ( { page } ) => {
        await screen.fill( '' );
        await screen.type( '1a2b-3' );

        await expect( screen ).toHaveValue( '123' );
    } );

    test( 'an empty readout records no payment', async ( { page } ) => {
        await expect( screen ).toHaveValue( '' );

        await paymentPopup.locator( '[data-action="add-payment"]' ).click();

        const payments = await page.evaluate( () => POS.order.getValue().payments );

        expect( payments.length, 'a payment worth nothing was recorded' ).toBe( 0 );
    } );

    /**
     * The pad listens for digits on the keyboard so a till without a touchscreen can be
     * driven from the number row. Those listeners must not also fire while the cashier is
     * typing into the readout, which would double every keystroke.
     */
    test( 'typing into the readout does not also feed the pad', async ( { page } ) => {
        await screen.click();
        await screen.fill( '' );

        await page.keyboard.type( '75' );

        await expect( screen ).toHaveValue( '75' );
    } );
} );

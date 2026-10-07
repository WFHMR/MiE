import { expect } from '@playwright/test';

/**
 * Sets the POS VAT mode on Settings > POS > VAT Settings.
 *
 * Navigation goes straight to the settings URL and the controls are matched on
 * their name attributes, so nothing here depends on the store's language.
 *
 * @param page      Playwright page
 * @param index     index of the option to pick on the ns_pos_vat select
 * @return the value that was selected before the change, so a caller can put it back
 */
export default async function changeTaxSettings( page, index = 1 ): Promise<string> {
    await page.goto( '/dashboard/settings/pos' );

    /**
     * The VAT tab has to be active before its fields are in the DOM. Tabs
     * expose their identifier through data-identifier on the header.
     */
    const vatTab = page.locator( '.ns-tab .tab[data-identifier="vat"]' );

    await expect( vatTab ).toBeVisible( { timeout: 30_000 } );
    await vatTab.click();

    const select = page.locator( 'select[name="ns_pos_vat"]' );

    await expect( select ).toBeVisible( { timeout: 15_000 } );

    const previousValue = await select.inputValue();

    await select.selectOption( { index } );

    await page.locator( '[data-action="submit-settings"] button' ).click();

    /**
     * The settings form reports the outcome through a snackbar.
     */
    await expect( page.locator( '.ns-notice' ).first() ).toBeVisible( { timeout: 15_000 } );

    return previousValue;
}

/**
 * Puts a previously captured ns_pos_vat value back, so a test run leaves the
 * store's configuration exactly as it found it.
 */
export async function restoreTaxSettings( page, value: string ): Promise<void> {
    await page.goto( '/dashboard/settings/pos' );

    const vatTab = page.locator( '.ns-tab .tab[data-identifier="vat"]' );

    await expect( vatTab ).toBeVisible( { timeout: 30_000 } );
    await vatTab.click();

    const select = page.locator( 'select[name="ns_pos_vat"]' );

    await expect( select ).toBeVisible( { timeout: 15_000 } );
    await select.selectOption( value );

    await page.locator( '[data-action="submit-settings"] button' ).click();

    await expect( page.locator( '.ns-notice' ).first() ).toBeVisible( { timeout: 15_000 } );
}

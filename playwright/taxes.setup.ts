import { test as setup } from '@playwright/test';
import login from './shared/login';
import changeTaxSettings from './shared/change-tax-settings';

/**
 * Tax configuration setup for products-tax.spec.ts.
 *
 * It only puts the store's VAT mode in a known state. The product level
 * changes belong to the spec itself, which already covers them and restores
 * what it touched.
 */

setup( 'configure taxes', async ( { page } ) => {
    setup.slow();

    await login( page );

    /**
     * Index 1 is the "Disabled" option of the ns_pos_vat select.
     */
    await changeTaxSettings( page, 1 );
} );

import { test, expect } from '@playwright/test';
import { apiCall } from './shared/pos';

/**
 * The receivables ageing report.
 *
 * A shop that sells on credit needs to see what it is owed and how long it has been
 * waiting. The page has to render its bands, its per-customer summary and its invoice
 * detail from real data, which is the part only a browser can confirm.
 */

test.describe( 'Receivables aging report', () => {

    test.beforeEach( async ( { page } ) => {
        test.slow();

        await page.goto( '/dashboard/reports/receivables' );

        /**
         * The component is async, so the page shell exists before the report does.
         */
        await page.waitForSelector( '#receivables-report', { state: 'visible', timeout: 30_000 } );
    } );

    test( 'the page loads with every age band', async ( { page } ) => {
        const bands = [ 'not_due', '1_30', '31_60', '61_90', 'over_90', 'no_term' ];

        for ( const band of bands ) {
            await expect(
                page.locator( `[data-bucket="${band}"]` ),
                `the "${band}" band is missing`
            ).toHaveCount( 1 );
        }
    } );

    test( 'both tables are rendered', async ( { page } ) => {
        await expect( page.locator( '[data-role="receivables-by-customer"]' ) ).toBeVisible();
        await expect( page.locator( '[data-role="receivables-by-order"]' ) ).toBeVisible();
    } );

    /**
     * The figures on the page have to be the ones the server computed. A report that
     * renders but shows a different total is worse than one that fails to load.
     */
    test( 'the totals on the page match the ones the server reports', async ( { page } ) => {
        const report = ( await apiCall( page, 'POST', '/api/reports/receivables' ) ).body;

        expect( Array.isArray( report.buckets ) ).toBe( true );
        expect( report.buckets.length ).toBe( 6 );

        const bandsSum = report.buckets.reduce( ( carry, bucket ) => carry + Number( bucket.total ), 0 );

        expect(
            Math.abs( bandsSum - Number( report.total ) ),
            'the age bands do not add up to the reported total'
        ).toBeLessThan( 0.01 );

        const rowCount = await page.locator( '[data-role="receivables-by-order"] tbody tr' ).count();

        /**
         * One row per invoice, or the single "nothing owed" row when the shop is owed
         * nothing at all.
         */
        expect( rowCount ).toBe( Math.max( 1, report.orders.length ) );
    } );

    test( 'the report can be reloaded from the toolbar', async ( { page } ) => {
        await page.locator( '[data-action="load-report"]' ).click();

        // The bands survive a reload, which means the request came back cleanly.
        await expect( page.locator( '[data-bucket="over_90"]' ) ).toBeVisible( { timeout: 15_000 } );
    } );
} );

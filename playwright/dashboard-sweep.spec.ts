import { test, expect } from '@playwright/test';

/**
 * Visits every parameterless dashboard page and reports anything that looks
 * broken at runtime: server errors, failed API calls, uncaught exceptions and
 * console errors.
 *
 * Run on demand:
 *   npx playwright test dashboard-sweep.spec.ts --project=chromium --no-deps
 */

const pages = [
    'dashboard',
    'dashboard/accounting/accounts',
    'dashboard/accounting/accounts/create',
    'dashboard/accounting/rules',
    'dashboard/accounting/transactions',
    'dashboard/accounting/transactions/create',
    'dashboard/accounting/transactions/history',
    'dashboard/cash-registers',
    'dashboard/cash-registers/create',
    'dashboard/customers',
    'dashboard/customers/coupons',
    'dashboard/customers/coupons/create',
    'dashboard/customers/coupons-generated',
    'dashboard/customers/create',
    'dashboard/customers/groups',
    'dashboard/customers/groups/create',
    'dashboard/customers/rewards-system',
    'dashboard/customers/rewards-system/create',
    'dashboard/medias',
    'dashboard/modules',
    'dashboard/orders',
    'dashboard/orders/instalments',
    'dashboard/orders/payments-types',
    'dashboard/orders/payments-types/create',
    'dashboard/procurements',
    'dashboard/procurements/create',
    'dashboard/procurements/products',
    'dashboard/products',
    'dashboard/products/adjustment-history',
    'dashboard/products/categories',
    'dashboard/products/categories/create',
    'dashboard/products/create',
    'dashboard/products/print-labels',
    'dashboard/products/scale-range',
    'dashboard/products/scale-range/create',
    'dashboard/products/stock-adjustment',
    'dashboard/products/stock-flow-records',
    'dashboard/providers',
    'dashboard/providers/create',
    'dashboard/reports/annual-report',
    'dashboard/reports/customers-statement',
    'dashboard/reports/low-stock',
    'dashboard/reports/payment-types',
    'dashboard/reports/profit',
    'dashboard/reports/sales',
    'dashboard/reports/sales-progress',
    'dashboard/reports/sold-stock',
    'dashboard/reports/stock-history',
    'dashboard/reports/transactions',
    'dashboard/reports/vat-return',
    'dashboard/reports/withholding-tax',
    'dashboard/settings/receipt-builder',
    'dashboard/taxes',
    'dashboard/taxes/create',
    'dashboard/taxes/groups',
    'dashboard/taxes/groups/create',
    'dashboard/taxes/withholding',
    'dashboard/taxes/withholding/create',
    'dashboard/units',
    'dashboard/units/create',
    'dashboard/units/groups',
    'dashboard/units/groups/create',
    'dashboard/users',
    'dashboard/users/create',
    'dashboard/users/profile',
    'dashboard/users/roles',
    'dashboard/users/roles/create',
    'dashboard/users/roles/permissions-manager',
];

type Finding = {
    path: string;
    status: number;
    seconds: number;
    consoleErrors: string[];
    pageErrors: string[];
    failedRequests: string[];
};

test('sweep every dashboard page for runtime failures', async ({ page }) => {
    test.setTimeout(15 * 60 * 1000);

    const findings: Finding[] = [];

    for (const path of pages) {
        const consoleErrors: string[] = [];
        const pageErrors: string[] = [];
        const failedRequests: string[] = [];

        const onConsole = (message: any) => {
            if (message.type() === 'error') {
                consoleErrors.push(message.text().slice(0, 200));
            }
        };

        const onPageError = (error: Error) => {
            pageErrors.push(`${error.name}: ${error.message}`.slice(0, 200));
        };

        const onResponse = (response: any) => {
            if (response.status() >= 400) {
                failedRequests.push(`${response.status()} ${response.url().replace(/^https?:\/\/[^/]+/, '')}`.slice(0, 200));
            }
        };

        page.on('console', onConsole);
        page.on('pageerror', onPageError);
        page.on('response', onResponse);

        const started = Date.now();
        let status = 0;

        try {
            const response = await page.goto(`/${path}`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
            status = response?.status() ?? 0;

            // Give the Vue app a moment to boot and fire its API calls.
            await page.waitForTimeout(2_500);
        } catch (error: any) {
            pageErrors.push(`navigation: ${error.message}`.slice(0, 200));
        }

        const seconds = Math.round(((Date.now() - started) / 1000) * 10) / 10;

        page.off('console', onConsole);
        page.off('pageerror', onPageError);
        page.off('response', onResponse);

        findings.push({ path, status, seconds, consoleErrors, pageErrors, failedRequests });
    }

    const broken = findings.filter(finding =>
        finding.status >= 400 ||
        finding.status === 0 ||
        finding.pageErrors.length > 0 ||
        finding.failedRequests.length > 0 ||
        finding.consoleErrors.length > 0
    );

    const slow = findings.filter(finding => finding.seconds > 8).sort((a, b) => b.seconds - a.seconds);

    console.log(`\n=== dashboard sweep: ${findings.length} pages ===\n`);

    if (broken.length === 0) {
        console.log('  no runtime failures found\n');
    }

    for (const finding of broken) {
        console.log(`  ${finding.path}  [HTTP ${finding.status}]  ${finding.seconds}s`);

        for (const entry of [...new Set(finding.pageErrors)]) {
            console.log(`      uncaught  ${entry}`);
        }

        for (const entry of [...new Set(finding.failedRequests)]) {
            console.log(`      request   ${entry}`);
        }

        for (const entry of [...new Set(finding.consoleErrors)]) {
            console.log(`      console   ${entry}`);
        }

        console.log('');
    }

    if (slow.length > 0) {
        console.log('  slowest pages:');

        for (const finding of slow) {
            console.log(`      ${finding.seconds}s  ${finding.path}`);
        }

        console.log('');
    }

    // The sweep is a report; it only fails when a page is outright broken.
    const serverErrors = findings.filter(finding => finding.status >= 500 || finding.status === 0);

    expect(
        serverErrors.map(finding => `${finding.path} -> ${finding.status}`),
        'pages that failed to render'
    ).toEqual([]);
});

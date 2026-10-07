import { defineConfig, devices } from '@playwright/test';
import { config } from 'dotenv';

/**
 * Load Laravel's .env file so variables like APP_URL, TEST_USERNAME,
 * and TEST_PASSWORD are available to Playwright without needing to
 * export them manually in the shell first.
 */
config(); // reads .env from the project root

/**
 * NexoPOS Playwright Configuration
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
    testDir: './playwright',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    /**
     * One retry locally as well. `php artisan serve` handles a single request at
     * a time, so a burst can make an otherwise good test time out — and because
     * the sign-in project gates every other project, one such blip would
     * otherwise abort the whole run.
     */
    retries: process.env.CI ? 2 : 1,
    workers: process.env.CI ? 1 : undefined,
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ],
    use: {
        baseURL: process.env.APP_URL || 'http://localhost:8000',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        trace: 'retain-on-failure',
        headless: true,
    },
    projects: [
        /**
         * The sign-in spec drives real logins as the shared test user, which
         * regenerates the server-side session. It therefore runs on its own,
         * before the session state every other project relies on is saved.
         */
        {
            name: 'chromium:auth',
            testMatch: /auth\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
            },
        },

        /**
         * Authentication setup — saves the session state reused by the
         * projects below, once the sign-in spec is done interfering with it.
         */
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
            dependencies: ['chromium:auth'],
        },

        /**
         * Chromium - main browser for all tests.
         * Uses previously saved authentication state.
         */
        {
            name: 'chromium',
            /**
             * contrast-audit is a reporting tool rather than a check, so it is
             * run on demand instead of on every pass.
             */
            testIgnore: [
                /products-tax\.spec\.ts/,
                /auth\.spec\.ts/,
                /contrast-audit\.spec\.ts/,
                /dashboard-sweep\.spec\.ts/,
            ],
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/user.json',
            },
            dependencies: ['setup'],
        },

        /**
         * On-demand diagnostics rather than checks.
         *
         * They stay unselected unless PW_TOOLS is set, because the dashboard
         * sweep walks every page and would starve the single-process dev server
         * that the rest of the suite is talking to at the same time.
         *
         *   $env:PW_TOOLS=1; npx playwright test --project=chromium:tools
         */
        {
            name: 'chromium:tools',
            testMatch: process.env.PW_TOOLS === '1'
                ? [ /contrast-audit\.spec\.ts/, /dashboard-sweep\.spec\.ts/ ]
                : [],
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/user.json',
            },
        },

        /**
         * Tax configuration, only needed by the tax spec. It changes a
         * store-wide setting, so it waits for the main project to finish
         * instead of pulling the rug from under it.
         */
        {
            name: 'setup:taxes',
            testMatch: /taxes\.setup\.ts/,
            use: {
                ...devices['Desktop Chrome'],
            },
            dependencies: ['chromium'],
        },

        /**
         * The tax spec expects the store's taxes to be configured first.
         */
        {
            name: 'chromium:taxes',
            testMatch: /products-tax\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/user.json',
            },
            dependencies: ['setup:taxes'],
        },
    ],
    outputDir: 'playwright-results/',
});

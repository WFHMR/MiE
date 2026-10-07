import { expect } from "@playwright/test";

export default async function login( page ) {
    const username = process.env.TEST_USERNAME || 'admin';
    const password = process.env.TEST_PASSWORD || 'admin123';
    const baseUrl  = process.env.APP_URL || 'http://localhost:8000';

    /**
     * 1 – Navigate to the sign-in page and fill the Vue-rendered form.
     *     The ns-input component sets :id="field.name", so we can use
     *     #username and #password as stable selectors.
     */
    await page.goto('/sign-in');

    // Wait for the Vue app to hydrate the form fields
    await page.waitForSelector('#username', { state: 'visible' });

    await page.fill('#username', username);
    await page.fill('#password', password);

    /**
     * 2 – Click the sign-in button and wait for navigation
     *     to the dashboard to confirm a successful login.
     *     ns-login.vue renders a single button inside .ns-box-footer,
     *     so this stays valid whatever locale the store runs in.
     */
    await page.locator('.ns-box-footer button').first().click();

    await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
    await expect(page).toHaveURL(/\/dashboard/);
}
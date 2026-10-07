import { test as setup } from '@playwright/test';
import path from 'path';
import login from './shared/login';

/**
 * Authentication setup — logs in once and saves the session to disk.
 * All other test projects depend on this setup so they reuse the
 * stored session instead of re-logging-in for every spec.
 */

const authFile = path.join(import.meta.dirname, '.auth/user.json');

setup('authenticate as admin', async ({ page }) => {

    await login(page);

    /**
     * Persist the authenticated state (cookies + localStorage)
     * so dependent projects don't need to log in again.
     */
    await page.context().storageState({ path: authFile });
});

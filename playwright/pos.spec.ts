import { test, expect } from '@playwright/test';
import { measureContrast, getActiveTheme } from './shared/contrast';
import { addFirstProductToCart, openPaymentScreen } from './shared/pos';

/**
 * Point-of-Sale spec
 *
 * Covers the POS interface at /dashboard/pos.
 * Runs with the pre-saved auth state from auth.setup.ts.
 */

test.describe('POS interface', () => {

    test.beforeEach(async ({ page }) => {
        // The catalogue can be large, so the POS takes a while to settle.
        test.slow();

        await page.goto('/dashboard/pos');

        /**
         * ns-pos.vue renders #pos-container once the Vue app has mounted.
         * Waiting on it is far more reliable than 'networkidle', which never
         * settles while the grid keeps fetching products.
         */
        await page.waitForSelector('#pos-container', { state: 'visible', timeout: 30_000 });
    });

    test('POS page navigates successfully', async ({ page }) => {
        await expect(page).toHaveURL(/\/dashboard\/pos/);
    });

    test('product grid is rendered', async ({ page }) => {
        // The product grid component ns-pos-grid should be visible
        const grid = page.locator('ns-pos-grid, [class*="pos-grid"], [id*="pos-grid"]').first();
        await expect(grid).toBeVisible({ timeout: 15_000 });
    });

    test('cart is rendered', async ({ page }) => {
        const cart = page.locator(
            'ns-pos-cart, [class*="pos-cart"], [class*="cart"]'
        ).first();
        await expect(cart).toBeVisible({ timeout: 15_000 });
    });

    test('payment button is accessible', async ({ page }) => {
        // ns-pos-pay-button.vue renders id="pay-button", which does not
        // depend on the store's language.
        await expect(page.locator('#pay-button')).toBeVisible({ timeout: 15_000 });
    });
});

test.describe('POS shortcut bar', () => {

    /**
     * The bar is rendered by ns-pos-shortcut-bar.vue as the first child
     * of #pos-container, so it only exists once the Vue app has mounted.
     */
    const bar = '.ns-pos-shortcut-bar';

    test.beforeEach(async ({ page }) => {
        await page.goto('/dashboard/pos');
        await page.waitForSelector(bar, { state: 'visible', timeout: 20_000 });
    });

    test('renders the ten F-key buttons in order', async ({ page }) => {
        const keys = page.locator(`${bar} button b`);

        await expect(keys).toHaveCount(10);
        await expect(keys).toHaveText([
            'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10',
        ]);
    });

    test('pressing F1 opens the product search popup', async ({ page }) => {
        await page.keyboard.press('F1');

        await expect(page.locator('.is-popup').first()).toBeVisible({ timeout: 10_000 });
    });

    test('clicking F1 opens the same popup as the key', async ({ page }) => {
        await page.locator(`${bar} button`, { has: page.locator('b', { hasText: /^F1$/ }) }).click();

        await expect(page.locator('.is-popup').first()).toBeVisible({ timeout: 10_000 });
    });

    test('shortcuts do not fire while a popup is open', async ({ page }) => {
        await page.keyboard.press('F1');
        await expect(page.locator('.is-popup').first()).toBeVisible({ timeout: 10_000 });

        const openPopups = await page.locator('.is-popup').count();

        // F1 is guarded by whenNotVisible([ '.is-popup' ]), so this is a no-op.
        await page.keyboard.press('F1');

        await expect(page.locator('.is-popup')).toHaveCount(openPopups);
    });

    test('F2 warns when the cart is empty instead of removing anything', async ({ page }) => {
        await page.keyboard.press('F2');

        // No confirmation popup should open, a snackbar reports the empty cart.
        await expect(page.locator('.is-popup')).toHaveCount(0);
    });

    test('F6 does nothing while the cart is empty', async ({ page }) => {
        await page.keyboard.press('F6');

        await expect(page.locator('.is-popup')).toHaveCount(0);
    });

    test('clicking a cart line selects it, clicking again clears it', async ({ page }) => {
        // Reaching a product means loading the grid and drilling into a category.
        test.slow();

        const added = await addFirstProductToCart(page);

        test.skip(!added, 'no product could be added from the grid');

        const cartLine = page.locator('#cart-products-table .product-item').first();

        await cartLine.click();
        await expect(cartLine).toHaveClass(/bg-teal-500\/10/);

        await cartLine.click();
        await expect(cartLine).not.toHaveClass(/bg-teal-500\/10/);
    });

    test('F2 removes the selected line rather than the last one', async ({ page }) => {
        test.slow();

        const added = await addFirstProductToCart(page);

        test.skip(!added, 'no product could be added from the grid');

        const cartLines = page.locator('#cart-products-table .product-item');
        const before = await cartLines.count();

        await cartLines.first().click();
        await expect(cartLines.first()).toHaveClass(/bg-teal-500\/10/);

        await page.keyboard.press('F2');

        // ns-pos-confirm-popup.vue asks for confirmation before removing.
        const confirm = page.locator('#confirm-popup');
        await expect(confirm).toBeVisible({ timeout: 10_000 });

        // "Yes" is the button that is not the cancel one.
        await confirm.locator('.action-buttons button:not(.cancel)').click();

        await expect(cartLines).toHaveCount(before - 1, { timeout: 10_000 });
    });
});

test.describe('POS product grid', () => {

    test.beforeEach(async ({ page }) => {
        test.slow();

        await page.goto('/dashboard/pos');
        await page.waitForSelector('#grid-items .cell-item', { state: 'visible', timeout: 30_000 });
    });

    test('browsing into a category lists its products', async ({ page }) => {
        // The root level shows categories; entering one reveals the products.
        await page.locator('#grid-items .cell-item').first().click();

        const products = page.locator('#grid-products .cell-item');

        await expect(products.first()).toBeVisible({ timeout: 20_000 });
        expect(await products.count()).toBeGreaterThan(0);
    });

    test('category and product labels are readable against their band', async ({ page }) => {
        const theme = await getActiveTheme(page);

        // Category tiles at the root level.
        const category = (await measureContrast(page, ['#grid-items .cell-item .cell-item-label h3']))[0];

        expect(category.ratio, `category label on the "${theme}" theme: ${category.color} on ${category.background}`)
            .toBeGreaterThanOrEqual(4.5);

        // Product tiles, one level down.
        await page.locator('#grid-items .cell-item').first().click();
        await expect(page.locator('#grid-products .cell-item').first()).toBeVisible({ timeout: 20_000 });

        const product = (await measureContrast(page, ['#grid-products .cell-item .cell-item-label h3']))[0];

        expect(product.ratio, `product label on the "${theme}" theme: ${product.color} on ${product.background}`)
            .toBeGreaterThanOrEqual(4.5);
    });

    test('adding the same product twice keeps one cart line and raises the quantity', async ({ page }) => {
        test.slow();

        const added = await addFirstProductToCart(page);

        test.skip(!added, 'no product could be added from the grid');

        const cartLines = page.locator('#cart-products-table .product-item');

        await expect(cartLines).toHaveCount(1);

        // The very same grid tile is clicked again.
        await page.locator('#grid-products .cell-item').first().click();

        await expect(page.locator('.is-popup')).toHaveCount(0, { timeout: 10_000 });

        // One line, not two.
        await expect(cartLines).toHaveCount(1);

        const quantity = cartLines.first().locator('[data-role="cart-quantity"]');

        await expect(quantity).toHaveText(/\b2\b/);
    });

    test('the payment screen is readable and releases its hotkeys', async ({ page }) => {
        test.slow();

        const added = await addFirstProductToCart(page);

        test.skip(!added, 'no product could be added from the grid');

        const opened = await openPaymentScreen(page);

        test.skip(!opened, 'the checkout queue could not reach the payment screen');

        const paymentPopup = page.locator('#ns-payment-popup');
        await expect(paymentPopup).toBeVisible({ timeout: 20_000 });

        const theme = await getActiveTheme(page);

        /**
         * The gateway list and the title sit on the panel surface. They used to
         * carry the theme's dark font colour over a dark panel.
         */
        const [ title, gateway ] = await measureContrast(page, [
            '#ns-payment-popup h3',
            '#ns-payment-popup li.ns-payment-gateway:not(.ns-visible)',
        ]);

        expect(title.ratio, `payment title on "${theme}": ${title.color} on ${title.background}`)
            .toBeGreaterThanOrEqual(4.5);

        expect(gateway.ratio, `gateway entry on "${theme}": ${gateway.color} on ${gateway.background}`)
            .toBeGreaterThanOrEqual(4.5);

        // Close the payment screen, then open an unrelated popup.
        // There are two close buttons, one of which is hidden at this breakpoint.
        await page.locator('#ns-payment-popup .ns-close-button:visible').first().click();
        await expect(paymentPopup).toBeHidden({ timeout: 10_000 });

        await page.keyboard.press('F1');
        await expect(page.locator('.is-popup').first()).toBeVisible({ timeout: 10_000 });

        /**
         * The numpad hotkeys were registered by the payment screen and released
         * in a Vue 2 hook that never fires, so digits typed into a later popup
         * still recorded payments.
         */
        const paymentsBefore = await page.evaluate(() => POS.order.getValue().payments.length);

        await page.keyboard.press('5');
        await page.keyboard.press('Enter');

        const paymentsAfter = await page.evaluate(() => POS.order.getValue().payments.length);

        expect(paymentsAfter, 'a payment was recorded by a hotkey that should have been released')
            .toBe(paymentsBefore);
    });

    test('the cart total row is readable', async ({ page }) => {
        const theme = await getActiveTheme(page);

        // The grand total sits on a filled success row.
        const [ label, amount ] = await measureContrast(page, [
            '#cart-products-summary tr.success td:nth-last-child(2)',
            '#cart-products-summary tr.success td:last-child',
        ]);

        expect(label.ratio, `total label on the "${theme}" theme: ${label.color} on ${label.background}`)
            .toBeGreaterThanOrEqual(4.5);

        expect(amount.ratio, `total amount on the "${theme}" theme: ${amount.color} on ${amount.background}`)
            .toBeGreaterThanOrEqual(4.5);
    });

    test('a large category is served one page at a time', async ({ page }) => {
        await page.locator('#grid-items .cell-item').first().click();
        await expect(page.locator('#grid-products .cell-item').first()).toBeVisible({ timeout: 20_000 });

        const loadMore = page.locator('#grid-load-more');

        test.skip(
            await loadMore.count() === 0,
            'the first category fits on a single page'
        );

        const products = page.locator('#grid-products .cell-item');
        const firstPageCount = await products.count();

        // A page must be bounded, otherwise the payload grows without limit.
        expect(firstPageCount).toBeLessThanOrEqual(100);

        await loadMore.locator('[data-action="load-more-products"]').click();

        await expect
            .poll(async () => await products.count(), { timeout: 20_000 })
            .toBeGreaterThan(firstPageCount);
    });
});


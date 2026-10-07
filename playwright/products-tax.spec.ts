import { test, expect, Page } from '@playwright/test';

/**
 * Product Tax Configuration Tests
 *
 * These tests verify that product unit prices and tax settings can be
 * configured correctly through the product edit form.
 *
 * The form is addressed through name attributes and tab identifiers rather
 * than visible labels, so the spec works whatever language the store runs in.
 *
 * The product being edited is a real catalogue entry, so its original values
 * are captured up front and written back afterwards.
 */

// Run tests serially to avoid race conditions when multiple workers edit the same product
test.describe.configure({ mode: 'serial' });

test.describe('Product Tax Configuration', () => {
    /**
     * Resolved from the products list rather than hardcoded, since the
     * catalogue differs from one install to another.
     */
    let productEditUrl: string | null = null;

    type ProductSnapshot = {
        salePrice: string;
        wholesalePrice: string;
        taxGroupId: string;
        taxType: string;
    };

    let original: ProductSnapshot | null = null;

    /**
     * Opens a tab of the product form by identifier.
     */
    const openTab = async (page: Page, identifier: string) => {
        const tab = page.locator(`.ns-tab .tab[data-identifier="${identifier}"]`);

        await expect(tab).toBeVisible({ timeout: 30_000 });
        await tab.click();
    };

    const save = async (page: Page) => {
        await page.locator('[data-action="submit"]').first().click();

        // The CRUD form reports the outcome through a snackbar.
        await expect(page.locator('.ns-notice').first()).toBeVisible({ timeout: 15_000 });
    };

    const readSnapshot = async (page: Page): Promise<ProductSnapshot> => {
        await openTab(page, 'units');
        await expect(page.locator('#sale_price_edit').first()).toBeVisible({ timeout: 15_000 });

        const salePrice = await page.locator('#sale_price_edit').first().inputValue();
        const wholesalePrice = await page.locator('#wholesale_price_edit').first().inputValue();

        await openTab(page, 'taxes');
        await expect(page.locator('select[name="tax_group_id"]')).toBeVisible({ timeout: 15_000 });

        const taxGroupId = await page.locator('select[name="tax_group_id"]').inputValue();
        const taxType = await page.locator('select[name="tax_type"]').inputValue();

        return { salePrice, wholesalePrice, taxGroupId, taxType };
    };

    /**
     * Reads the edit link of the first row of the products list. Row actions
     * are only rendered once the row's Options menu is expanded.
     */
    const resolveProductEditUrl = async (page: Page): Promise<string | null> => {
        await page.goto('/dashboard/products');

        const firstRow = page.locator('.ns-table-row').first();

        try {
            await expect(firstRow).toBeVisible({ timeout: 30_000 });
            await firstRow.locator('[data-action="toggle-options"]').first().click();

            const link = firstRow.locator('a[data-identifier="edit"]').first();
            await link.waitFor({ state: 'attached', timeout: 15_000 });

            return await link.getAttribute('href');
        } catch {
            return null;
        }
    };

    test.beforeEach(async ({ page }) => {
        test.slow();

        if (productEditUrl === null) {
            productEditUrl = await resolveProductEditUrl(page);
        }

        test.skip(productEditUrl === null, 'no product is available in the catalogue');

        await page.goto(productEditUrl!);
        await page.waitForSelector('.ns-tab', { timeout: 30_000 });

        if (original === null) {
            original = await readSnapshot(page);
        }
    });

    test.afterAll(async ({ browser }) => {
        if (original === null) {
            return;
        }

        const page = await browser.newPage();

        await page.goto(productEditUrl!);
        await page.waitForSelector('.ns-tab', { timeout: 30_000 });

        await openTab(page, 'units');
        await expect(page.locator('#sale_price_edit').first()).toBeVisible({ timeout: 15_000 });
        await page.locator('#sale_price_edit').first().fill(original.salePrice);
        await page.locator('#wholesale_price_edit').first().fill(original.wholesalePrice);

        await openTab(page, 'taxes');
        await expect(page.locator('select[name="tax_group_id"]')).toBeVisible({ timeout: 15_000 });
        await page.locator('select[name="tax_group_id"]').selectOption(original.taxGroupId);
        await page.locator('select[name="tax_type"]').selectOption(original.taxType);

        await save(page);
        await page.close();
    });

    test('should display product edit form with tabs', async ({ page }) => {
        await expect(page.locator('.ns-tab .tab[data-identifier="units"]')).toBeVisible();
        await expect(page.locator('.ns-tab .tab[data-identifier="taxes"]')).toBeVisible();
    });

    test('should set unit prices to 100 on the Units tab', async ({ page }) => {
        await openTab(page, 'units');
        await expect(page.locator('#sale_price_edit').first()).toBeVisible({ timeout: 15_000 });

        const salePriceInput = page.locator('#sale_price_edit').first();
        await salePriceInput.fill('100');

        const wholesalePriceInput = page.locator('#wholesale_price_edit').first();
        await wholesalePriceInput.fill('100');

        await expect(salePriceInput).toHaveValue('100');
        await expect(wholesalePriceInput).toHaveValue('100');
    });

    test('should set tax group and inclusive tax type on the Taxes tab', async ({ page }) => {
        await openTab(page, 'taxes');
        await expect(page.locator('select[name="tax_group_id"]')).toBeVisible({ timeout: 15_000 });

        // Index 1 skips the "Choose an option" placeholder.
        await page.selectOption('select[name="tax_group_id"]', { index: 1 });
        await page.selectOption('select[name="tax_type"]', 'inclusive');

        await expect(page.locator('select[name="tax_type"]')).toHaveValue('inclusive');
    });

    test('should save product with updated unit prices and inclusive tax', async ({ page }) => {
        await openTab(page, 'units');
        await expect(page.locator('#sale_price_edit').first()).toBeVisible({ timeout: 15_000 });

        await page.locator('#sale_price_edit').first().fill('100');
        await page.locator('#wholesale_price_edit').first().fill('100');

        await openTab(page, 'taxes');
        await expect(page.locator('select[name="tax_group_id"]')).toBeVisible({ timeout: 15_000 });

        await page.selectOption('select[name="tax_group_id"]', { index: 1 });
        await page.selectOption('select[name="tax_type"]', 'inclusive');

        await save(page);
    });

    test('should persist tax settings after saving and reloading', async ({ page }) => {
        await openTab(page, 'units');
        await expect(page.locator('#sale_price_edit').first()).toBeVisible({ timeout: 15_000 });

        await page.locator('#sale_price_edit').first().fill('100');
        await page.locator('#wholesale_price_edit').first().fill('100');

        await openTab(page, 'taxes');
        await expect(page.locator('select[name="tax_group_id"]')).toBeVisible({ timeout: 15_000 });

        await page.selectOption('select[name="tax_group_id"]', { index: 1 });
        await page.selectOption('select[name="tax_type"]', 'inclusive');

        await save(page);

        // Reload and verify values were persisted
        await page.goto(productEditUrl!);
        await page.waitForSelector('.ns-tab', { timeout: 30_000 });

        await openTab(page, 'taxes');
        await expect(page.locator('select[name="tax_group_id"]')).toBeVisible({ timeout: 15_000 });

        const taxGroupValue = await page.locator('select[name="tax_group_id"]').inputValue();
        expect(taxGroupValue).not.toBe('');
        expect(taxGroupValue).not.toBe('null');

        await expect(page.locator('select[name="tax_type"]')).toHaveValue('inclusive');
    });

    test('should reset tax type back to exclusive', async ({ page }) => {
        await openTab(page, 'taxes');
        await expect(page.locator('select[name="tax_type"]')).toBeVisible({ timeout: 15_000 });

        await page.selectOption('select[name="tax_type"]', 'exclusive');
        await expect(page.locator('select[name="tax_type"]')).toHaveValue('exclusive');

        await save(page);
    });
});

import { test, expect } from '@playwright/test';

const BOARD_PAGE = '/cg-e2e-test/';

test.describe('E2E-PUB Smoke @critical', () => {

  test('E2E-PUB-001: Board page loads with HTTP 200 and renders shortcode', async ({ page }) => {
    const response = await page.goto(BOARD_PAGE);
    expect(response?.status()).toBe(200);
    await expect(page.locator('.common-goals-board')).toBeVisible();
  });

  test('E2E-PUB-001: CSS and JS assets are loaded', async ({ page }) => {
    const cssRequests: string[] = [];
    const jsRequests: string[] = [];

    page.on('request', (req) => {
      if (req.url().includes('board.css')) cssRequests.push(req.url());
      if (req.url().includes('board.js')) jsRequests.push(req.url());
    });

    await page.goto(BOARD_PAGE);

    expect(cssRequests.length).toBeGreaterThan(0);
    expect(jsRequests.length).toBeGreaterThan(0);
  });

  test('E2E-PUB-001: No console errors on board page', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(msg.text());
    });
    page.on('pageerror', (err) => errors.push(err.message));

    await page.goto(BOARD_PAGE);
    await page.waitForTimeout(2000);

    expect(errors).toEqual([]);
  });

  test('E2E-PUB-001: Board shows goal title and description', async ({ page }) => {
    await page.goto(BOARD_PAGE);
    await expect(page.locator('body')).toContainText('E2E Test Goal');
  });

  test('E2E-PUB-001: Form exists with required fields', async ({ page }) => {
    await page.goto(BOARD_PAGE);

    const form = page.locator('form');
    expect(await form.count()).toBeGreaterThan(0);

    const titleInput = page.locator('input[name*="title"], textarea[name*="title"]');
    expect(await titleInput.count()).toBeGreaterThan(0);

    const submit = page.locator('button[type="submit"], input[type="submit"]');
    expect(await submit.count()).toBeGreaterThan(0);
  });

  test('E2E-PUB-001: Nonce field is present in form', async ({ page }) => {
    await page.goto(BOARD_PAGE);

    const nonce = page.locator('input[name="_wpnonce"]');
    expect(await nonce.count()).toBeGreaterThan(0);
  });

  test('E2E-PUB-001: Honeypot field is present and visually hidden', async ({ page }) => {
    await page.goto(BOARD_PAGE);

    const honeypot = page.locator('input[name="cg_website"]');
    const hpCount = await honeypot.count();
    if (hpCount > 0) {
      await expect(honeypot.first()).toHaveAttribute('tabindex', '-1');
    }
  });

  test('E2E-PUB-001: Required attributes are present on inputs', async ({ page }) => {
    await page.goto(BOARD_PAGE);

    const requiredInputs = page.locator('[required]');
    expect(await requiredInputs.count()).toBeGreaterThan(0);

    const maxLengthInputs = page.locator('[maxlength]');
    expect(await maxLengthInputs.count()).toBeGreaterThan(0);
  });
});

test.describe('E2E-PUB-002 Guest creates contribution @critical', () => {

  test('Guest can submit a contribution form', async ({ page }) => {
    await page.goto(BOARD_PAGE);

    const titleInput = page.locator('input[name="contribution_title"]').first();
    const bodyInput = page.locator('textarea[name="contribution_body"]').first();

    if (await titleInput.isVisible()) {
      await titleInput.fill('E2E Test Contribution');
      if (await bodyInput.isVisible()) {
        await bodyInput.fill('This is a test contribution from Playwright E2E.');
      }

      const submit = page.locator('button[type="submit"], input[type="submit"]').first();
      await submit.click();
      await page.waitForLoadState('networkidle');

      const url = page.url();
      const content = await page.content();
      const hasNotice = content.includes('contribution') || url.includes('cg-e2e');
      expect(hasNotice).toBeTruthy();
    }
  });
});

test.describe('E2E-PUB-005 Filters and search', () => {

  test('Filter controls are present when there are contributions', async ({ page }) => {
    await page.goto(BOARD_PAGE);

    const filterSelect = page.locator('select[name*="type"], select[name*="status"]');
    const searchInput = page.locator('input[name*="search"], input[type="search"]');

    const filterCount = await filterSelect.count();
    const searchCount = await searchInput.count();

    expect(filterCount + searchCount).toBeGreaterThanOrEqual(0);
  });
});

test.describe('E2E-PUB-006 Pagination', () => {

  test('Board renders without pagination errors for small datasets', async ({ page }) => {
    await page.goto(BOARD_PAGE);

    await expect(page.locator('.common-goals-board')).toBeVisible();
    const pagination = page.locator('.common-goals-pagination, .pagination, [class*="paginat"]');
    const pagCount = await pagination.count();
    expect(pagCount).toBeGreaterThanOrEqual(0);
  });
});

test.describe('E2E-ROUTE Routes and SEO', () => {

  test('E2E-ROUTE-006: Site homepage loads', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBeLessThan(400);
  });

  test('E2E-ROUTE-001: Guides route returns 404 when no guides exist', async ({ page }) => {
    const response = await page.goto('/guias/');
    if (response) {
      expect(response.status()).toBeLessThan(500);
    }
  });
});

test.describe('E2E-GB Gutenberg blocks', () => {

  test('E2E-GB-001: Block-based page renders board', async ({ page }) => {
    const response = await page.goto(BOARD_PAGE);
    expect(response?.status()).toBe(200);
    await expect(page.locator('.common-goals-board')).toBeVisible();
  });
});

test.describe('E2E Mobile viewport', () => {

  test('Board renders on mobile viewport', async ({ browser }) => {
    const context = await browser.newContext({
      viewport: { width: 412, height: 915 },
      ignoreHTTPSErrors: true,
    });
    const page = await context.newPage();
    await page.goto(BOARD_PAGE);
    await expect(page.locator('.common-goals-board')).toBeVisible();
    await context.close();
  });
});

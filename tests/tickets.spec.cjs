// @ts-check
const { test, expect } = require('@playwright/test');

// When using playwright.config.cjs, baseURL is set there.
// Falls back to env var or localhost for standalone runs.
const BASE_URL = process.env.TICKETS_URL || '/';

test.describe('FreshService Tickets Dashboard', () => {

    test('page loads and displays title', async ({ page }) => {
        await page.goto(BASE_URL);
        await expect(page).toHaveTitle('FreshService Tickets');
        await expect(page.locator('#tickets-tab h1')).toHaveText('FreshService Tickets');
    });

    test('tickets table is populated with data', async ({ page }) => {
        await page.goto(BASE_URL);

        // Wait for tickets to load (table rows appear)
        const rows = page.locator('#ticketBody tr');
        await expect(rows.first()).toBeVisible({ timeout: 10000 });

        // Should have at least 1 ticket
        const count = await rows.count();
        expect(count).toBeGreaterThan(0);
    });

    test('stats cards are displayed', async ({ page }) => {
        await page.goto(BASE_URL);

        // Wait for stats to render
        const stats = page.locator('.stat');
        await expect(stats.first()).toBeVisible({ timeout: 10000 });

        // Should have Active, Open, Pending + category cards
        const count = await stats.count();
        expect(count).toBeGreaterThanOrEqual(3);

        // Active count should be a number > 0
        const activeValue = page.locator('.stat .stat-value').first();
        await expect(activeValue).not.toHaveText('0');
    });

    test('filter buttons work', async ({ page }) => {
        await page.goto(BASE_URL);

        // Wait for data to load
        await expect(page.locator('#ticketBody tr').first()).toBeVisible({ timeout: 10000 });

        // Click "All" filter
        await page.click('[data-filter="all"]');
        await expect(page.locator('[data-filter="all"]')).toHaveClass(/active/);

        // Click "Closed" filter
        await page.click('[data-filter="closed"]');
        await expect(page.locator('[data-filter="closed"]')).toHaveClass(/active/);

        // Click back to "Active"
        await page.click('[data-filter="active"]');
        await expect(page.locator('[data-filter="active"]')).toHaveClass(/active/);
    });

    test('search filters tickets', async ({ page }) => {
        await page.goto(BASE_URL);

        // Wait for data
        await expect(page.locator('#ticketBody tr').first()).toBeVisible({ timeout: 10000 });
        const initialCount = await page.locator('#ticketBody tr').count();

        // Type a search term that likely won't match everything
        await page.fill('#search', 'Guiney');
        
        // Wait for filter to apply
        await page.waitForTimeout(500);
        const filteredCount = await page.locator('#ticketBody tr').count();

        // Should have fewer or equal results
        expect(filteredCount).toBeLessThanOrEqual(initialCount);
    });

    test('table columns are sortable', async ({ page }) => {
        await page.goto(BASE_URL);
        await expect(page.locator('#ticketBody tr').first()).toBeVisible({ timeout: 10000 });

        // Click ID header to sort
        await page.click('th:has-text("ID")');
        
        // Sort arrow should appear
        const sortArrow = page.locator('#sort-id');
        await expect(sortArrow).not.toBeEmpty();
    });

    test('ticket IDs link to FreshService', async ({ page }) => {
        await page.goto(BASE_URL);
        await expect(page.locator('#ticketBody tr').first()).toBeVisible({ timeout: 10000 });

        // First ticket link should point to FreshService
        const firstLink = page.locator('#ticketBody tr:first-child a').first();
        const href = await firstLink.getAttribute('href');
        expect(href).toContain('youritsolutions.freshservice.com/a/tickets/');
    });

    test('last synced timestamp is displayed', async ({ page }) => {
        await page.goto(BASE_URL);
        
        const lastUpdated = page.locator('#lastUpdated');
        await expect(lastUpdated).toContainText('Last synced:', { timeout: 10000 });
    });

    test('no error message when loading tickets', async ({ page }) => {
        await page.goto(BASE_URL);
        
        // Wait for either success or error
        await page.waitForTimeout(5000);
        
        // Should NOT show error message
        await expect(page.locator('text=Failed to load tickets')).not.toBeVisible();
    });

    test('JSON and DB File links are present', async ({ page }) => {
        await page.goto(BASE_URL);

        await expect(page.locator('#tickets-tab a.json-link:has-text("JSON")')).toBeVisible();
        await expect(page.locator('#tickets-tab a.json-link:has-text("DB File")')).toBeVisible();
    });

    test('table has custom internal field columns', async ({ page }) => {
        await page.goto(BASE_URL);
        await expect(page.locator('#ticketBody tr').first()).toBeVisible({ timeout: 10000 });

        // Verify the new column headers exist
        await expect(page.locator('th:has-text("Summary")')).toBeVisible();
        await expect(page.locator('th:has-text("Next Action")')).toBeVisible();
        await expect(page.locator('th:has-text("Ticket File")')).toBeVisible();
    });

    test('API returns internal fields for tickets', async ({ page }) => {
        const response = await page.request.get(BASE_URL + 'api/tickets');
        expect(response.ok()).toBeTruthy();

        const data = await response.json();
        const tickets = Object.values(data.tickets || {});
        expect(tickets.length).toBeGreaterThan(0);

        // Each ticket should have an internal sub-object
        const firstTicket = tickets[0];
        expect(firstTicket).toHaveProperty('internal');
        expect(firstTicket.internal).toHaveProperty('ticket_file');
        expect(firstTicket.internal).toHaveProperty('next_action');
        expect(firstTicket.internal).toHaveProperty('summary');
    });

    test('AI Sessions tab renders and endpoint returns valid JSON', async ({ page }) => {
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE_URL);

        // Tab button exists and switches without JS errors.
        const tabBtn = page.locator('.page-tab[data-page="ai-sessions"]');
        await expect(tabBtn).toBeVisible();
        await tabBtn.click();
        await expect(page.locator('#ai-sessions-tab')).toBeVisible();
        await expect(page.locator('#ai-sessions-tab h1')).toHaveText('AI Sessions');
        await expect(page.locator('#aiSessionsStats .stat')).not.toHaveCount(0);
        expect(errors).toEqual([]);

        // Endpoint returns the documented structure.
        const response = await page.request.get(BASE_URL + 'api/ai-sessions');
        expect(response.ok()).toBeTruthy();
        const data = await response.json();
        expect(data).toHaveProperty('sessions');
        expect(data).toHaveProperty('summary');
        expect(data.summary).toHaveProperty('live');
        expect(data.summary).toHaveProperty('orphans');
        expect(data.summary).toHaveProperty('tokens_24h');
        expect(Array.isArray(data.sessions)).toBeTruthy();
    });
});

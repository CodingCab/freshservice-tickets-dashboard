// @ts-check
//
// Bulk actions on the tickets list: select several tickets with the row
// checkboxes, then apply one action to all of them (status change, add /
// remove a shared label, star / unstar).
//
// The write endpoints are intercepted so the suite never touches FreshService
// or the shared label store — the assertions are about what the dashboard
// SENDS and what it reports back to the operator.
const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TICKETS_URL || '/';

const LABELS = {
    labels: [
        { id: 1, name: 'Robert', color: '#3b82f6', ticket_count: 1 },
        { id: 2, name: 'Waiting', color: '#f59e0b', ticket_count: 0 },
    ],
};

/** Serve a fixed shared-label list so the label menus are deterministic. */
async function stubLabels(page) {
    await page.route('**/api/labels', route => {
        if (route.request().method() !== 'GET') return route.continue();
        route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(LABELS) });
    });
}

/**
 * Capture every per-ticket write and answer it. `failFor` lists ticket ids that
 * should come back as a server error, so the "X succeeded, Y errors" summary
 * can be asserted.
 */
async function stubWrites(page, { pattern, failFor = [] } = {}) {
    const calls = [];
    await page.route(pattern, async route => {
        const req = route.request();
        const id = (req.url().match(/\/tickets\/([^/]+)\//) || [])[1];
        calls.push({ id, body: JSON.parse(req.postData() || '{}') });
        if (failFor.includes(id)) {
            return route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ error: 'boom', message: 'FreshService unreachable' }),
            });
        }
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ status: 'saved', label_ids: (JSON.parse(req.postData() || '{}').label_ids) || [] }),
        });
    });
    return calls;
}

/**
 * The table re-renders asynchronously (a filter click can be followed by a
 * refresh landing), so row counts are only trustworthy once they stop moving.
 */
async function stableRowCount(page) {
    let last = -1;
    await expect.poll(async () => {
        const n = await page.locator('#ticketBody tr').count();
        const stable = n > 0 && n === last;
        last = n;
        return stable;
    }, { timeout: 10000 }).toBe(true);
    return last;
}

/** Load the dashboard with a clean per-browser state and wait for rows. */
async function openDashboard(page) {
    await stubLabels(page);
    await page.addInitScript(() => window.localStorage.clear());
    await page.goto(BASE_URL);
    await expect(page.locator('#ticketBody tr').first()).toBeVisible({ timeout: 10000 });
    await page.click('[data-filter="all"]');
    await stableRowCount(page);
}

const rowCheckbox = (page, n) => page.locator('#ticketBody tr .bulk-select-box').nth(n);

test.describe('Bulk ticket actions', () => {

    test('the action bar only appears once something is selected', async ({ page }) => {
        await openDashboard(page);
        const bar = page.locator('#bulkActionBar');
        await expect(bar).toBeHidden();

        await rowCheckbox(page, 0).check();
        await expect(bar).toBeVisible();
        await expect(page.locator('#bulkSelectionCount')).toHaveText('1 ticket selected');

        await rowCheckbox(page, 1).check();
        await expect(page.locator('#bulkSelectionCount')).toHaveText('2 tickets selected');

        await page.click('#bulkClearBtn');
        await expect(bar).toBeHidden();
    });

    test('the header checkbox selects every visible ticket', async ({ page }) => {
        await openDashboard(page);
        const total = await stableRowCount(page);

        await page.locator('#bulkSelectAll').check();
        await expect(page.locator('#bulkSelectionCount')).toHaveText(`${total} tickets selected`);
        await expect(page.locator('#ticketBody tr .bulk-select-box:checked')).toHaveCount(total);

        await page.locator('#bulkSelectAll').uncheck();
        await expect(page.locator('#bulkActionBar')).toBeHidden();
    });

    test('selection survives a filter change, keeping only still-visible tickets', async ({ page }) => {
        await openDashboard(page);
        const all = await stableRowCount(page);
        await page.locator('#bulkSelectAll').check();
        await expect(page.locator('#bulkSelectionCount')).toHaveText(`${all} tickets selected`);

        await page.click('[data-filter="open"]');
        const openCount = await stableRowCount(page);
        await expect(page.locator('#bulkSelectionCount')).toHaveText(
            `${openCount} ticket${openCount === 1 ? '' : 's'} selected`
        );
    });

    test('bulk status change posts one status call per ticket and reports the result', async ({ page }) => {
        await openDashboard(page);
        const calls = await stubWrites(page, { pattern: '**/api/tickets/*/status' });

        await rowCheckbox(page, 0).check();
        await rowCheckbox(page, 1).check();

        page.once('dialog', d => {
            expect(d.message()).toContain('2 tickets');
            expect(d.message()).toContain('Closed');
            d.accept();
        });
        await page.click('#bulkStatusBtn');
        await page.click('.bulk-menu-item[data-status="closed"]');

        await expect(page.locator('#bulkToast')).toContainText('2 succeeded, 0 errors');
        expect(calls).toHaveLength(2);
        expect(calls.every(c => c.body.status === 'closed')).toBeTruthy();
    });

    test('cancelling the confirmation changes nothing', async ({ page }) => {
        await openDashboard(page);
        const calls = await stubWrites(page, { pattern: '**/api/tickets/*/status' });

        await rowCheckbox(page, 0).check();
        page.once('dialog', d => d.dismiss());
        await page.click('#bulkStatusBtn');
        await page.click('.bulk-menu-item[data-status="closed"]');

        await expect(page.locator('#bulkToast')).toBeHidden();
        expect(calls).toHaveLength(0);
        await expect(page.locator('#bulkActionBar')).toBeVisible();
    });

    test('failures are counted in the summary', async ({ page }) => {
        await openDashboard(page);
        const ids = await page.locator('#ticketBody tr .bulk-select-box').evaluateAll(
            els => els.slice(0, 2).map(e => e.getAttribute('data-ticket-id'))
        );
        await stubWrites(page, { pattern: '**/api/tickets/*/status', failFor: [ids[1]] });

        await rowCheckbox(page, 0).check();
        await rowCheckbox(page, 1).check();
        page.once('dialog', d => d.accept());
        await page.click('#bulkStatusBtn');
        await page.click('.bulk-menu-item[data-status="pending"]');

        await expect(page.locator('#bulkToast')).toContainText('1 succeeded, 1 error');
    });

    test('adding a label keeps the labels a ticket already has', async ({ page }) => {
        await openDashboard(page);
        const calls = await stubWrites(page, { pattern: '**/api/tickets/*/labels' });

        // Give the first ticket an existing label through the per-row picker so
        // the merge behaviour is exercised on a non-empty set.
        await page.locator('#ticketBody tr').first().locator('.label-add-btn').click();
        await page.locator('#labelPicker .label-chip', { hasText: 'Robert' }).click();
        await expect(page.locator('#labelPicker')).toBeHidden();

        await rowCheckbox(page, 0).check();
        await rowCheckbox(page, 1).check();
        page.once('dialog', d => {
            expect(d.message()).toContain('Waiting');
            d.accept();
        });
        await page.click('#bulkAddLabelBtn');
        await page.click('.bulk-menu-item[data-label-id="2"]');

        await expect(page.locator('#bulkToast')).toContainText('2 succeeded, 0 errors');
        const bulk = calls.slice(1); // first call is the per-row picker assignment
        expect(bulk).toHaveLength(2);
        expect(bulk[0].body.label_ids.sort()).toEqual([1, 2]);
        expect(bulk[1].body.label_ids).toEqual([2]);
    });

    test('removing a label leaves the other labels alone', async ({ page }) => {
        await openDashboard(page);
        const calls = await stubWrites(page, { pattern: '**/api/tickets/*/labels' });

        await page.locator('#ticketBody tr').first().locator('.label-add-btn').click();
        await page.locator('#labelPicker .label-chip', { hasText: 'Robert' }).click();
        await page.locator('#ticketBody tr').first().locator('.label-add-btn').click();
        await page.locator('#labelPicker .label-chip', { hasText: 'Waiting' }).click();

        await rowCheckbox(page, 0).check();
        page.once('dialog', d => d.accept());
        await page.click('#bulkRemoveLabelBtn');
        await page.click('.bulk-menu-item[data-label-id="1"]');

        await expect(page.locator('#bulkToast')).toContainText('1 succeeded, 0 errors');
        expect(calls[calls.length - 1].body.label_ids).toEqual([2]);
    });

    test('bulk star is local-only: no server call, stars appear on the rows', async ({ page }) => {
        await openDashboard(page);
        let serverCalls = 0;
        await page.route('**/api/tickets/*/**', route => { serverCalls++; route.continue(); });

        await rowCheckbox(page, 0).check();
        await rowCheckbox(page, 1).check();
        await page.click('#bulkStarBtn');
        await page.click('.bulk-menu-item[data-star="on"]');

        await expect(page.locator('#ticketBody tr.row-starred')).toHaveCount(2);
        expect(serverCalls).toBe(0);

        await page.click('#bulkStarBtn');
        await page.click('.bulk-menu-item[data-star="off"]');
        await expect(page.locator('#ticketBody tr.row-starred')).toHaveCount(0);
    });

    test('no JS errors while using the bulk bar', async ({ page }) => {
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await openDashboard(page);
        await page.locator('#bulkSelectAll').check();
        await page.click('#bulkStatusBtn');
        await page.keyboard.press('Escape');
        await page.click('#bulkClearBtn');
        expect(errors).toEqual([]);
    });
});

// @ts-check
const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TICKETS_URL || '/';
const HOUR = 3600e3;

// Recordings on for every test in this file (ShipTown-style: always recorded).
test.use({ video: 'on', trace: 'on' });

// One point of GET /api/agents/usage: a snapshot taken at `atMs`. `stale` is what the
// API sets when the value was fetched longer than the logger's cache window before
// the snapshot's own time — a replayed cache, not a reading.
function point(atMs, fiveHour, sevenDay, stale = false) {
    const fetchedAtMs = stale ? atMs - 60 * 24 * HOUR : atMs - 60e3;
    return {
        ts: new Date(atMs).toISOString(),
        five_hour: fiveHour,
        seven_day: sevenDay,
        five_hour_resets_at: null,
        seven_day_resets_at: null,
        fetched_at_ms: fetchedAtMs,
        task_id: null,
        stale,
    };
}

// The chart opens on its 7-day range (3-hour buckets), so points 20h and 10h ago fall in
// two different buckets of the plotted window, and none of them is filtered by `since`.
function usageFixture(now) {
    const lastTs = new Date(now - 10 * HOUR).toISOString();
    return {
        users: {
            // A fresh reading and, later, a replayed cache carrying a higher value.
            fresh_and_stale: [
                point(now - 20 * HOUR, 40, 10),
                point(now - 10 * HOUR, 100, 25, true),
            ],
            // Nothing but replayed caches.
            stale_only: [point(now - 8 * HOUR, 100, 25, true)],
            // Every fetch failed: the API lists the account with an empty series.
            adam: [],
        },
        status: {
            fresh_and_stale: { entries: 2, stale: 1, no_data: false, last_error: null, last_ts: lastTs },
            stale_only: { entries: 1, stale: 1, no_data: true, last_error: null, last_ts: lastTs },
            adam: { entries: 3, stale: 0, no_data: true, last_error: 'HTTP 429 rate_limit_error (retry after 545s)', last_ts: lastTs },
        },
    };
}

// The usage plot's y scale: 190px tall, 10px top and 24px bottom margin (renderUsageChart).
const plotY = v => 10 + (190 - 10 - 24) * (1 - v / 100);

// The tab loads the usage chart right after the agent queue, so both feeds are stubbed:
// the queue table is not under test here, and an empty queue keeps the run hermetic.
async function stubUsage(page, body) {
    const json = data => ({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
    await page.route('**/api/agents/usage**', route => route.fulfill(json(body)));
    await page.route('**/api/agents?**', route => route.fulfill(json({ tasks: [] })));
}

async function openAgentsTab(page) {
    const consoleErrors = [];
    page.on('console', msg => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
    page.on('pageerror', err => consoleErrors.push(err.message));
    await page.goto(BASE_URL + '#agents');
    await expect(page.locator('#agents-tab')).toBeVisible();
    await expect(page.locator('#usagePlot5h svg, #usagePlot5h .usage-empty')).toBeVisible();
    await expect(page.locator('#usagePlot7d svg, #usagePlot7d .usage-empty')).toBeVisible();
    return consoleErrors;
}

test.describe('Agents tab — usage limits chart', () => {

    test('a stale snapshot is never drawn: only the fresh reading is plotted', async ({ page }) => {
        // STEP 1: the usage API answers with the fixture (one fresh point, one stale point, one stale-only account, one account with no data)
        await stubUsage(page, usageFixture(Date.now()));

        // STEP 2: open the Agents tab
        const consoleErrors = await openAgentsTab(page);

        for (const [plotId, fresh, stale] of [['#usagePlot5h', 40, 100], ['#usagePlot7d', 10, 25]]) {
            const svg = page.locator(`${plotId} svg`);

            // STEP 3: exactly one line is drawn — fresh_and_stale; stale_only has nothing to plot
            await expect(svg.locator('path')).toHaveCount(1);
            await expect(svg.locator('.usage-endlabel-ink')).toHaveText(['fresh_and_stale']);

            // STEP 4: that line has one plotted point (r=2.5 markers), at the fresh value — the
            // stale 100/25 % value would sit at the top of the plot and is not drawn
            const markers = svg.locator('circle[r="2.5"]');
            await expect(markers).toHaveCount(1);
            const cy = parseFloat(await markers.first().getAttribute('cy') || 'NaN');
            expect(Math.abs(cy - plotY(fresh))).toBeLessThan(0.1);
            expect(Math.abs(cy - plotY(stale))).toBeGreaterThan(1);
        }

        // STEP 5: the browser console reports no error
        expect(consoleErrors).toEqual([]);
    });

    test('an account with only stale snapshots leaves the plot empty', async ({ page }) => {
        // STEP 1: the usage API lists one account whose every point is stale
        const now = Date.now();
        await stubUsage(page, {
            users: { stale_only: [point(now - 8 * HOUR, 100, 25, true), point(now - 4 * HOUR, 100, 25, true)] },
            status: { stale_only: { entries: 2, stale: 2, no_data: true, last_error: null, last_ts: new Date(now - 4 * HOUR).toISOString() } },
        });

        // STEP 2: open the Agents tab
        const consoleErrors = await openAgentsTab(page);

        // STEP 3: neither chart draws a line — an empty plot, not a flat one at 100 %
        for (const plotId of ['#usagePlot5h', '#usagePlot7d']) {
            await expect(page.locator(`${plotId} .usage-empty`)).toHaveText('No usage snapshots in this range yet.');
            await expect(page.locator(`${plotId} svg`)).toHaveCount(0);
        }

        // STEP 4: the legend still names the account, as no data with the reason
        const key = page.locator('#usageLegend .usage-key-nodata');
        await expect(key).toHaveCount(1);
        await expect(key).toContainText('stale_only');
        await expect(key.locator('.usage-nodata')).toHaveText('no data — only stale snapshots');

        expect(consoleErrors).toEqual([]);
    });

    test('the legend names each account without data and says why', async ({ page }) => {
        // STEP 1: the usage API answers with the fixture
        await stubUsage(page, usageFixture(Date.now()));

        // STEP 2: open the Agents tab
        const consoleErrors = await openAgentsTab(page);
        const legend = page.locator('#usageLegend');

        // STEP 3: the account with a fresh reading is a plain legend key, with no "no data" note
        const freshKey = legend.locator('.usage-key', { hasText: 'fresh_and_stale' });
        await expect(freshKey).toHaveCount(1);
        await expect(freshKey).not.toHaveClass(/usage-key-nodata/);
        await expect(freshKey.locator('.usage-nodata')).toHaveCount(0);

        // STEP 4: adam — whose fetches all failed — is listed with the last recorded error
        const adamKey = legend.locator('.usage-key-nodata', { hasText: 'adam' });
        await expect(adamKey).toHaveCount(1);
        await expect(adamKey.locator('.usage-nodata')).toHaveText('no data — HTTP 429 rate_limit_error (retry after 545s)');
        await expect(adamKey).toHaveAttribute('title', /^HTTP 429 rate_limit_error \(retry after 545s\) · last /);

        // STEP 5: the stale-only account is listed with "only stale snapshots" as its reason
        const staleKey = legend.locator('.usage-key-nodata', { hasText: 'stale_only' });
        await expect(staleKey).toHaveCount(1);
        await expect(staleKey.locator('.usage-nodata')).toHaveText('no data — only stale snapshots');

        // STEP 6: no other account is marked no data
        await expect(legend.locator('.usage-key-nodata')).toHaveCount(2);

        expect(consoleErrors).toEqual([]);
    });
});

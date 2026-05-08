// @ts-check
const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TICKETS_URL || '/';

const SAMPLE_JSON = {
    generated_at: '2026-05-08 17:00:00',
    source: '/shared/task-lists',
    task_lists: [
        {
            file: 'CODING-TASK-LIST.md',
            sections: [
                { name: 'New', count: 3 },
                { name: 'In Progress', count: 1 },
                { name: 'Done', count: 0 },
            ],
        },
        {
            file: 'BUG-HUNTER-TASK-LIST.md',
            sections: [
                { name: 'Investigation', count: 2 },
                { name: 'Done', count: 0 },
            ],
        },
    ],
};

// Recordings on for every test in this file (ShipTown-style: always recorded).
test.use({ video: 'on', trace: 'on' });

// Stub the JSON feed (it lives outside the Laravel app at /www/task-lists.json,
// so Laravel's php artisan serve won't have it).
test.beforeEach(async ({ page }) => {
    await page.route('**/www/task-lists.json**', (route) => {
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(SAMPLE_JSON),
        });
    });
});

test('TaskListsTabBasics: tab renders header, cards, sections and counts', async ({ page }) => {
    await page.goto(BASE_URL + '#task-lists');

    await expect(page.locator('#task-lists-app h1')).toHaveText('Task Lists');

    const cards = page.locator('#task-lists-app .tl-card');
    await expect(cards).toHaveCount(2);

    await expect(cards.nth(0).locator('.tl-card-title')).toHaveText('CODING-TASK-LIST.md');
    await expect(cards.nth(0).locator('.tl-section-row')).toHaveCount(3);
    await expect(cards.nth(0).locator('.tl-card-meta')).toContainText('3 sections');
    await expect(cards.nth(0).locator('.tl-card-meta')).toContainText('4 items');

    await expect(cards.nth(1).locator('.tl-card-title')).toHaveText('BUG-HUNTER-TASK-LIST.md');
    await expect(cards.nth(1).locator('.tl-section-row')).toHaveCount(2);
});

test('TaskListsBadgeHighlight: only non-zero counts get the highlight class', async ({ page }) => {
    await page.goto(BASE_URL + '#task-lists');

    const firstCard = page.locator('#task-lists-app .tl-card').first();
    const badges = firstCard.locator('.tl-section-count');

    await expect(badges.nth(0)).toHaveClass(/has-items/);
    await expect(badges.nth(1)).toHaveClass(/has-items/);
    await expect(badges.nth(2)).not.toHaveClass(/has-items/);
});

test('TaskListsTabSwitch: clicking the tab button shows the Task Lists view', async ({ page }) => {
    await page.goto(BASE_URL);

    await expect(page.locator('.page-tab[data-page="task-lists"]')).toBeVisible();
    await page.click('.page-tab[data-page="task-lists"]');

    await expect(page.locator('#task-lists-tab')).toBeVisible();
    await expect(page.locator('#task-lists-app h1')).toHaveText('Task Lists');
    expect(page.url()).toContain('#task-lists');
});

test('TaskListsRefresh: Refresh button refetches the JSON feed', async ({ page }) => {
    let calls = 0;
    await page.unroute('**/www/task-lists.json**');
    await page.route('**/www/task-lists.json**', (route) => {
        calls += 1;
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                ...SAMPLE_JSON,
                generated_at: '2026-05-08 17:00:0' + calls,
            }),
        });
    });

    await page.goto(BASE_URL + '#task-lists');
    await expect(page.locator('#task-lists-app .tl-card')).toHaveCount(2);
    const before = calls;

    await page.click('#task-lists-app .refresh-btn');
    await expect.poll(() => calls).toBeGreaterThan(before);
    await expect(page.locator('#task-lists-app .last-updated')).toContainText(String(calls));
});

test('TaskListsJsonLink: JSON link points at /www/task-lists.json', async ({ page }) => {
    await page.goto(BASE_URL + '#task-lists');
    await expect(page.locator('#task-lists-app a.json-link'))
        .toHaveAttribute('href', '/www/task-lists.json');
});

test('TaskListsErrorState: feed failure renders an error', async ({ page }) => {
    await page.unroute('**/www/task-lists.json**');
    await page.route('**/www/task-lists.json**', (route) => {
        route.fulfill({ status: 500, body: 'boom' });
    });

    await page.goto(BASE_URL + '#task-lists');
    await expect(page.locator('#task-lists-app .empty.error'))
        .toContainText('Failed to load task-lists.json');
});

// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests',
    testMatch: '**/*.spec.cjs',
    timeout: 30000,
    retries: 0,
    use: {
        baseURL: process.env.TICKETS_URL || 'http://127.0.0.1:8199',
    },
    webServer: process.env.TICKETS_URL ? undefined : {
        command: 'ASSET_URL= APP_URL=http://127.0.0.1:8199 TICKETS_DB_PATH=' + require('path').join(__dirname, 'tests/fixtures/freshservice-tickets-db.json') + ' php artisan serve --port=8199',
        port: 8199,
        timeout: 15000,
        reuseExistingServer: true,
    },
});

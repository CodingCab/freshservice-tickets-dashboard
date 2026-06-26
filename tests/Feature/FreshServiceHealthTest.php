<?php

namespace Tests\Feature;

use Tests\TestCase;

class FreshServiceHealthTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'fs-health-test-');
        putenv('FS_HEALTH_PATH=' . $this->tmpFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
        putenv('FS_HEALTH_PATH');
        parent::tearDown();
    }

    public function test_returns_ok_status_when_all_checks_pass(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'tested_at' => gmdate('c'),
            'status' => 'ok',
            'checks' => [
                'api_reachable' => ['ok' => true, 'duration_ms' => 100, 'error' => null],
                'sync_recent' => ['ok' => true, 'error' => null, 'last_synced' => gmdate('c'), 'age_seconds' => 30],
                'events_poller_recent' => ['ok' => true, 'error' => null, 'last_run' => gmdate('c'), 'age_seconds' => 15],
            ],
            'summary' => 'All checks passed',
        ]));

        $response = $this->getJson('/api/health/freshservice');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok', 'summary' => 'All checks passed']);
        $response->assertJsonStructure([
            'status', 'tested_at', 'checks' => [
                'api_reachable' => ['ok', 'duration_ms', 'error'],
                'sync_recent' => ['ok', 'last_synced', 'age_seconds'],
                'events_poller_recent' => ['ok', 'last_run', 'age_seconds'],
            ],
            'summary', 'age_seconds',
        ]);
    }

    public function test_returns_failed_when_check_fails(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'tested_at' => gmdate('c'),
            'status' => 'failed',
            'checks' => [
                'api_reachable' => ['ok' => false, 'duration_ms' => 30000, 'error' => 'CLI exited 1: pw cache cold'],
            ],
            'summary' => 'Failed: api_reachable',
        ]));

        $response = $this->getJson('/api/health/freshservice');
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'failed',
            'summary' => 'Failed: api_reachable',
        ]);
        $this->assertSame('pw cache cold', substr($response->json('checks.api_reachable.error'), -13));
    }

    public function test_returns_failed_when_health_file_missing(): void
    {
        @unlink($this->tmpFile);
        $response = $this->getJson('/api/health/freshservice');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'failed']);
        $this->assertStringContainsString('not found', $response->json('summary'));
    }

    public function test_returns_failed_when_health_file_is_stale(): void
    {
        $threeHoursAgo = gmdate('c', time() - 3 * 3600);
        file_put_contents($this->tmpFile, json_encode([
            'tested_at' => $threeHoursAgo,
            'status' => 'ok',
            'checks' => [],
            'summary' => 'All checks passed',
        ]));

        $response = $this->getJson('/api/health/freshservice');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'failed']);
        $this->assertStringContainsString('stale', $response->json('summary'));
        $this->assertGreaterThan(7200, $response->json('age_seconds'));
    }

    public function test_returns_failed_when_health_file_is_unreadable_json(): void
    {
        file_put_contents($this->tmpFile, '{not valid json');
        $response = $this->getJson('/api/health/freshservice');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'failed']);
        $this->assertStringContainsString('unreadable', $response->json('summary'));
    }
}

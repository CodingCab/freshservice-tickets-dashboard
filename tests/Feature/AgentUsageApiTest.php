<?php

namespace Tests\Feature;

use Tests\TestCase;

class AgentUsageApiTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('phpunit-agent-usage-' . uniqid());
        mkdir($this->dir, 0775, true);
        config(['dashboard.agent_usage_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeLog(string $user, array $entries): void
    {
        $lines = array_map(fn ($e) => json_encode($e), $entries);
        file_put_contents($this->dir . '/' . $user . '.jsonl', implode("\n", $lines) . "\n");
    }

    private function entry(string $ts, ?int $fiveHour, ?int $sevenDay, array $extra = []): array
    {
        return array_merge([
            'ts' => $ts,
            'user' => 'x',
            'task_id' => 'T1',
            'fetched_at_ms' => 1755550000000,
            'utilization' => [
                'five_hour' => ['utilization' => $fiveHour, 'resets_at' => '2026-08-18T23:00:00+00:00'],
                'seven_day' => ['utilization' => $sevenDay, 'resets_at' => '2026-08-24T02:00:00+00:00'],
            ],
        ], $extra);
    }

    public function test_returns_points_per_user_sorted_by_time(): void
    {
        $this->writeLog('beta', [
            $this->entry('2026-08-18T12:00:00+00:00', 40, 10),
            $this->entry('2026-08-18T10:00:00+00:00', 20, 5),
        ]);
        $this->writeLog('alpha', [
            $this->entry('2026-08-18T11:00:00+00:00', 90, 60),
        ]);

        $response = $this->getJson('/api/agents/usage');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame(['alpha', 'beta'], array_keys($data['users']));

        $beta = $data['users']['beta'];
        $this->assertCount(2, $beta);
        $this->assertSame(20, $beta[0]['five_hour']);
        $this->assertSame(40, $beta[1]['five_hour']);
        $this->assertSame(5, $beta[0]['seven_day']);
        $this->assertSame('2026-08-18T23:00:00+00:00', $beta[0]['five_hour_resets_at']);
        $this->assertSame('2026-08-24T02:00:00+00:00', $beta[0]['seven_day_resets_at']);
        $this->assertSame('T1', $beta[0]['task_id']);
        $this->assertSame(1755550000000, $beta[0]['fetched_at_ms']);

        $this->assertSame(90, $data['users']['alpha'][0]['five_hour']);
    }

    public function test_since_and_until_filter_points(): void
    {
        $this->writeLog('alpha', [
            $this->entry('2026-08-18T08:00:00+00:00', 10, 1),
            $this->entry('2026-08-18T12:00:00+00:00', 50, 2),
            $this->entry('2026-08-18T18:00:00+00:00', 99, 3),
        ]);

        $response = $this->getJson('/api/agents/usage?since=2026-08-18T10:00:00Z&until=2026-08-18T14:00:00Z');
        $response->assertStatus(200);
        $points = $response->json()['users']['alpha'];
        $this->assertCount(1, $points);
        $this->assertSame(50, $points[0]['five_hour']);
    }

    public function test_skips_malformed_lines_and_entries_without_utilization(): void
    {
        file_put_contents($this->dir . '/alpha.jsonl', implode("\n", [
            'not-json{{{',
            json_encode(['ts' => '2026-08-18T10:00:00+00:00', 'utilization' => null]),
            json_encode(['no_ts' => true]),
            json_encode($this->entry('2026-08-18T11:00:00+00:00', 42, 7)),
        ]) . "\n");

        $response = $this->getJson('/api/agents/usage');
        $response->assertStatus(200);
        $points = $response->json()['users']['alpha'];
        $this->assertCount(1, $points);
        $this->assertSame(42, $points[0]['five_hour']);
        $this->assertSame(7, $points[0]['seven_day']);
    }

    public function test_user_with_no_valid_points_is_omitted(): void
    {
        $this->writeLog('alpha', [$this->entry('2026-08-18T11:00:00+00:00', 42, 7)]);
        file_put_contents($this->dir . '/empty.jsonl', "not-json\n");

        $response = $this->getJson('/api/agents/usage');
        $response->assertStatus(200);
        $this->assertSame(['alpha'], array_keys($response->json()['users']));
    }

    public function test_missing_directory_returns_empty_users(): void
    {
        config(['dashboard.agent_usage_dir' => $this->dir . '-nonexistent']);
        $response = $this->getJson('/api/agents/usage');
        $response->assertStatus(200);
        $this->assertSame([], $response->json()['users']);
    }

    // ── B0026: stale snapshots are never plotted, accounts without data are surfaced ──

    /** A point taken at $ts whose data was fetched $ageSeconds before $ts. */
    private function snapshot(string $ts, int $ageSeconds, int $fiveHour, int $sevenDay, array $extra = []): array
    {
        return $this->entry($ts, $fiveHour, $sevenDay, array_merge([
            'fetched_at_ms' => (strtotime($ts) - $ageSeconds) * 1000,
        ], $extra));
    }

    public function test_point_fetched_longer_than_the_cache_window_before_its_ts_is_flagged_stale(): void
    {
        $julyMs = strtotime('2026-07-23T18:01:00+00:00') * 1000;
        $this->writeLog('alpha', [
            // Replayed July cache written as a "new" snapshot today — must not be plotted.
            $this->entry('2026-09-25T17:19:01+00:00', 100, 25, ['fetched_at_ms' => $julyMs]),
            // Fetched 5 minutes before the snapshot — fresh, plotted unchanged.
            $this->snapshot('2026-09-25T17:20:01+00:00', 5 * 60, 40, 10),
            // Exactly at the 30-minute boundary — still fresh.
            $this->snapshot('2026-09-25T17:21:01+00:00', 30 * 60, 41, 11),
            // One second past the boundary — stale.
            $this->snapshot('2026-09-25T17:22:01+00:00', 30 * 60 + 1, 99, 99),
        ]);

        $response = $this->getJson('/api/agents/usage');
        $response->assertStatus(200);
        $data = $response->json();

        $points = $data['users']['alpha'];
        $this->assertSame([true, false, false, true], array_column($points, 'stale'));
        $fresh = array_values(array_filter($points, fn ($p) => !$p['stale']));
        $this->assertSame(
            ['2026-09-25T17:20:01+00:00', '2026-09-25T17:21:01+00:00'],
            array_column($fresh, 'ts')
        );
        $this->assertSame([40, 41], array_column($fresh, 'five_hour'));
        $this->assertSame([10, 11], array_column($fresh, 'seven_day'));
        $this->assertSame((strtotime('2026-09-25T17:20:01+00:00') - 300) * 1000, $fresh[0]['fetched_at_ms']);
        // The replayed July value is still visible to a consumer that asks, but flagged.
        $this->assertSame($julyMs, $points[0]['fetched_at_ms']);
        $this->assertSame(100, $points[0]['five_hour']);

        $status = $data['status']['alpha'];
        $this->assertSame(4, $status['entries']);
        $this->assertSame(2, $status['stale']);
        $this->assertFalse($status['no_data']);
        $this->assertNull($status['last_error']);
        $this->assertSame('2026-09-25T17:22:01+00:00', $status['last_ts']);
    }

    public function test_point_without_fetched_at_is_kept_because_its_freshness_is_unknown(): void
    {
        $this->writeLog('alpha', [
            $this->entry('2026-09-25T17:20:01+00:00', 33, 12, ['fetched_at_ms' => null]),
        ]);

        $points = $this->getJson('/api/agents/usage')->assertStatus(200)->json()['users']['alpha'];
        $this->assertCount(1, $points);
        $this->assertSame(33, $points[0]['five_hour']);
        $this->assertNull($points[0]['fetched_at_ms']);
        $this->assertFalse($points[0]['stale']);
    }

    public function test_user_whose_entries_all_lack_utilization_is_listed_with_no_data_and_the_last_error(): void
    {
        $this->writeLog('adam', [
            ['ts' => '2026-09-25T17:19:21+00:00', 'user' => 'adam', 'utilization' => null, 'fetched_at_ms' => null,
                'source' => 'fetch-failed', 'error' => 'HTTP 429 rate_limit_error (retry after 3411s)'],
            ['ts' => '2026-09-25T17:21:21+00:00', 'user' => 'adam', 'utilization' => null, 'fetched_at_ms' => null,
                'source' => 'cache-stale', 'error' => 'HTTP 429 rate_limit_error (retry after 545s)'],
        ]);
        // A second account with real data must be untouched by adam's status.
        $this->writeLog('beta', [$this->snapshot('2026-09-25T17:20:01+00:00', 60, 40, 10)]);

        $response = $this->getJson('/api/agents/usage');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame(['adam', 'beta'], array_keys($data['users']));
        $this->assertSame([], $data['users']['adam']);

        $adam = $data['status']['adam'];
        $this->assertTrue($adam['no_data']);
        $this->assertSame(2, $adam['entries']);
        $this->assertSame(0, $adam['stale']);
        $this->assertSame('HTTP 429 rate_limit_error (retry after 545s)', $adam['last_error']);
        $this->assertSame('2026-09-25T17:21:21+00:00', $adam['last_ts']);

        $this->assertCount(1, $data['users']['beta']);
        $this->assertSame(40, $data['users']['beta'][0]['five_hour']);
        $this->assertFalse($data['users']['beta'][0]['stale']);
        $this->assertFalse($data['status']['beta']['no_data']);
        $this->assertSame(1, $data['status']['beta']['entries']);
        $this->assertNull($data['status']['beta']['last_error']);
    }

    public function test_user_whose_points_are_all_stale_is_listed_with_no_data(): void
    {
        $julyMs = strtotime('2026-07-23T18:01:00+00:00') * 1000;
        $this->writeLog('airforce', [
            $this->entry('2026-09-25T17:19:01+00:00', 100, 25, ['fetched_at_ms' => $julyMs]),
            $this->entry('2026-09-25T17:20:01+00:00', 100, 25, ['fetched_at_ms' => $julyMs]),
            $this->entry('2026-09-25T17:21:01+00:00', 100, 25, ['fetched_at_ms' => $julyMs]),
        ]);

        $data = $this->getJson('/api/agents/usage')->assertStatus(200)->json();

        // Every point is there for a consumer that asks, every one flagged, none fresh.
        $this->assertSame([true, true, true], array_column($data['users']['airforce'], 'stale'));
        $status = $data['status']['airforce'];
        $this->assertTrue($status['no_data']);
        $this->assertSame(3, $status['entries']);
        $this->assertSame(3, $status['stale']);
        $this->assertNull($status['last_error']);
        $this->assertSame('2026-09-25T17:21:01+00:00', $status['last_ts']);
    }

    public function test_no_data_marker_respects_the_since_and_until_window(): void
    {
        $this->writeLog('adam', [
            ['ts' => '2026-09-20T10:00:00+00:00', 'utilization' => null, 'error' => 'old failure'],
            ['ts' => '2026-09-25T17:00:00+00:00', 'utilization' => null, 'error' => 'recent failure'],
        ]);

        // Only the old entry falls outside the window — adam is still listed, from the recent one.
        $data = $this->getJson('/api/agents/usage?since=2026-09-25T00:00:00Z')->assertStatus(200)->json();
        $this->assertSame([], $data['users']['adam']);
        $this->assertSame(1, $data['status']['adam']['entries']);
        $this->assertSame('recent failure', $data['status']['adam']['last_error']);

        // No entry in the window at all — adam is not listed, same as a user with no file.
        $data = $this->getJson('/api/agents/usage?since=2026-09-26T00:00:00Z')->assertStatus(200)->json();
        $this->assertSame([], $data['users']);
        $this->assertSame([], $data['status']);
    }

    public function test_status_is_present_for_every_listed_user_and_empty_when_there_are_none(): void
    {
        $this->writeLog('alpha', [$this->snapshot('2026-09-25T17:20:01+00:00', 60, 40, 10)]);

        $data = $this->getJson('/api/agents/usage')->assertStatus(200)->json();
        $this->assertSame(['alpha'], array_keys($data['status']));
        $this->assertSame(
            ['entries' => 1, 'stale' => 0, 'no_data' => false, 'last_error' => null, 'last_ts' => '2026-09-25T17:20:01+00:00'],
            $data['status']['alpha']
        );

        config(['dashboard.agent_usage_dir' => $this->dir . '-nonexistent']);
        $data = $this->getJson('/api/agents/usage')->assertStatus(200)->json();
        $this->assertSame([], $data['status']);
    }
}

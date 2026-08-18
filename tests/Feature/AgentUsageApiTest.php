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
}

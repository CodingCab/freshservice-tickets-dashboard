<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiSessionsApiTest extends TestCase
{
    public function test_ai_sessions_returns_valid_json_structure(): void
    {
        $response = $this->getJson('/api/ai-sessions');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'sessions',
            'collected_at',
            'summary' => [
                'live',
                'orphans',
                'tokens_24h',
                'users',
            ],
        ]);
    }

    public function test_ai_sessions_merges_snapshot_and_flags_orphans(): void
    {
        $dir = storage_path('ai-sessions');
        @mkdir($dir, 0775, true);
        $fixture = $dir . '/phpunit-test.json';
        file_put_contents($fixture, json_encode([
            'user' => 'phpunit-test',
            'collected_at' => '2026-07-01T12:00:00+00:00',
            'sessions' => [
                [
                    'pid' => 111, 'session_id' => 'aaa', 'status' => 'live',
                    'tab_backed' => true, 'orphan' => false, 'model' => 'claude-opus-4-8',
                    'runtime_seconds' => 60, 'started_at' => '2026-07-01T11:59:00+00:00',
                    'last_active' => '2026-07-01T12:00:00+00:00', 'cwd' => '/home/x',
                    'tokens' => ['input' => 1, 'output' => 2, 'cache_read' => 3, 'cache_write' => 4],
                ],
                [
                    'pid' => 222, 'session_id' => 'bbb', 'status' => 'live',
                    'tab_backed' => false, 'orphan' => true, 'model' => 'claude-opus-4-8',
                    'runtime_seconds' => 120, 'started_at' => '2026-07-01T11:58:00+00:00',
                    'last_active' => '2026-07-01T12:00:00+00:00', 'cwd' => '/home/x',
                    'tokens' => ['input' => 10, 'output' => 20, 'cache_read' => 30, 'cache_write' => 40],
                ],
            ],
        ]));

        try {
            $response = $this->getJson('/api/ai-sessions');
            $response->assertStatus(200);
            $data = $response->json();

            // Find our injected sessions.
            $ours = array_values(array_filter($data['sessions'], fn ($s) => ($s['user'] ?? '') === 'phpunit-test'));
            $this->assertCount(2, $ours, 'Both injected sessions should be merged');

            // Orphan must be sorted before the non-orphan live session.
            $this->assertTrue($ours[0]['orphan'], 'Orphan should sort to the top');
            $this->assertEquals(222, $ours[0]['pid']);

            // Summary counts our two live + one orphan (plus whatever else exists).
            $this->assertGreaterThanOrEqual(2, $data['summary']['live']);
            $this->assertGreaterThanOrEqual(1, $data['summary']['orphans']);
            $this->assertIsInt($data['summary']['tokens_24h']);
        } finally {
            @unlink($fixture);
        }
    }
}

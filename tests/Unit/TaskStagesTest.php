<?php

namespace Tests\Unit;

use App\Services\TaskStages;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The Workflow panel shows, per tracked coding/feature task, the stage it is at
 * — the release it shipped in, or the branch it merged to, or where it sits in
 * the pipeline. The stage map itself is built by
 * `/shared/scripts/task-stages.py`; this covers how the dashboard reads it.
 *
 * The rule that matters: an unknown task must produce EMPTY values, never a
 * guess and never a missing key — the panel's columns are fixed, and a row with
 * missing keys would collapse them and misalign the table.
 */
class TaskStagesTest extends TestCase
{
    private function withMap(array $tasks): TaskStages
    {
        $stages = new TaskStages();
        $p = new ReflectionProperty(TaskStages::class, 'tasks');
        $p->setAccessible(true);
        $p->setValue($stages, $tasks);

        return $stages;
    }

    public function test_a_released_task_reports_its_release_and_pr(): void
    {
        $stages = $this->withMap([
            'F4492' => ['stage' => 'Released — 67.0.0', 'kind' => 'released',
                        'release' => '67.0.0', 'pr' => 3394, 'merged_at' => '2026-08-19'],
        ]);

        $row = $stages->forTask('F4492');

        $this->assertSame('Released — 67.0.0', $row['stage']);
        $this->assertSame('released', $row['stage_kind']);
        $this->assertSame('67.0.0', $row['release']);
        $this->assertSame('https://github.com/CodingCab/ShipTown/pull/3394', $row['pr_url']);
    }

    public function test_a_task_merged_to_a_long_lived_branch_names_that_branch(): void
    {
        // Some tenants run a feature branch directly, so "merged but not in a
        // release" is a real, reportable state — not "unfinished".
        $stages = $this->withMap([
            'B4488' => ['stage' => 'feature-purchase-orders', 'kind' => 'branch',
                        'branch' => 'feature-purchase-orders', 'pr' => 3393],
        ]);

        $row = $stages->forTask('B4488');

        $this->assertSame('feature-purchase-orders', $row['stage']);
        $this->assertSame('branch', $row['stage_kind']);
        $this->assertNull($row['release']);
    }

    public function test_an_unknown_task_is_null_not_a_guess(): void
    {
        $this->assertNull($this->withMap([])->forTask('B7887'));
        $this->assertNull($this->withMap([])->forTask(''));
    }

    public function test_decorate_fills_every_column_key_on_every_row(): void
    {
        $stages = $this->withMap([
            'F4492' => ['stage' => 'Released — 67.0.0', 'kind' => 'released',
                        'release' => '67.0.0', 'pr' => 3394],
        ]);

        $rows = $stages->decorate([
            ['task_id' => 'F4492', 'title' => 'shipped'],
            ['task_id' => 'B7887', 'title' => 'not on any list'],
            ['title' => 'a plain subtask with no task id'],
        ]);

        foreach (['stage', 'stage_kind', 'release', 'pr', 'pr_url', 'merged_at'] as $key) {
            foreach ($rows as $i => $row) {
                $this->assertArrayHasKey($key, $row, "row {$i} is missing {$key}");
            }
        }
        $this->assertSame('Released — 67.0.0', $rows[0]['stage']);
        $this->assertSame('', $rows[1]['stage']);
        $this->assertNull($rows[1]['pr_url']);
        $this->assertSame('', $rows[2]['stage']);
    }

    public function test_task_ids_are_matched_regardless_of_casing_or_padding(): void
    {
        $stages = $this->withMap([
            'F4492' => ['stage' => 'Released — 67.0.0', 'kind' => 'released'],
        ]);

        $this->assertNotNull($stages->forTask(' f4492 '));
    }

    public function test_a_missing_map_file_degrades_to_no_stages(): void
    {
        // Nothing built yet / unreadable: the panel shows `-` everywhere rather
        // than erroring or inventing a stage.
        $rows = (new TaskStages())->decorate([['task_id' => 'ZZ0000']]);

        $this->assertSame('', $rows[0]['stage']);
    }
}

<?php

namespace App\Services;

/**
 * TaskStages — where a coding / feature task actually is.
 *
 * Reads the map built by `/shared/scripts/task-stages.py` (refreshed hourly by
 * `/shared/automations/every-1-hour/refresh-task-stages.py`). That script, not
 * this class, decides what a stage IS — in particular that a merged task's
 * release is resolved from git rather than from wherever its entry happens to
 * sit on the task list, so a reshuffled list cannot make the dashboard lie.
 *
 * Everything here is read-only and forgiving: a missing, stale or unreadable
 * map means "no stage known", which the panel renders as `-`. A stage is never
 * guessed.
 */
class TaskStages
{
    private const MAP_FILE = '/shared/state/task-stages.json';
    private const REPO_URL = 'https://github.com/CodingCab/ShipTown';

    /** @var array<string, array>|null Loaded once per request. */
    private ?array $tasks = null;

    /**
     * Stage row for a task id, or null when nothing is known about it.
     *
     * @return array{stage:string, stage_kind:string, release:?string, branch:?string, pr:?int, pr_url:?string, merged_at:?string}|null
     */
    public function forTask(string $taskId): ?array
    {
        $taskId = strtoupper(trim($taskId));
        if ($taskId === '') {
            return null;
        }
        $row = $this->load()[$taskId] ?? null;
        if ($row === null) {
            return null;
        }

        $stage = (string) ($row['stage'] ?? '');
        $pr = isset($row['pr']) && is_numeric($row['pr']) ? (int) $row['pr'] : null;

        return [
            'stage'      => $stage,
            'stage_kind' => (string) ($row['kind'] ?? 'unknown'),
            'release'    => $row['release'] ?? null,
            'branch'     => $row['branch'] ?? null,
            'pr'         => $pr,
            'pr_url'     => $pr !== null ? self::REPO_URL . '/pull/' . $pr : null,
            'merged_at'  => $row['merged_at'] ?? null,
        ];
    }

    /**
     * Attach `stage_*` / `pr_*` keys to every row of a list that carries a
     * `task_id`. Rows without a task id, or whose task is unknown to the map,
     * are left with empty values so the panel keeps its columns aligned
     * instead of collapsing them.
     *
     * @param array<int, array> $rows
     * @return array<int, array>
     */
    public function decorate(array $rows): array
    {
        foreach ($rows as &$row) {
            $stage = $this->forTask((string) ($row['task_id'] ?? ''));
            $row['stage']      = $stage['stage'] ?? '';
            $row['stage_kind'] = $stage['stage_kind'] ?? '';
            $row['release']    = $stage['release'] ?? null;
            $row['pr']         = $stage['pr'] ?? null;
            $row['pr_url']     = $stage['pr_url'] ?? null;
            $row['merged_at']  = $stage['merged_at'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /** @return array<string, array> */
    private function load(): array
    {
        if ($this->tasks !== null) {
            return $this->tasks;
        }
        $this->tasks = [];
        $raw = @file_get_contents(self::MAP_FILE);
        if (!is_string($raw) || $raw === '') {
            return $this->tasks;
        }
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['tasks']) && is_array($data['tasks'])) {
            $this->tasks = $data['tasks'];
        }

        return $this->tasks;
    }
}

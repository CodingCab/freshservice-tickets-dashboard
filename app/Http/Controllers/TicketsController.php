<?php

namespace App\Http\Controllers;

use App\Services\ReplyHtmlBuilder;
use App\Services\TaskStages;
use App\Services\TicketFile;
use App\Services\TicketHtmlEnricher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class TicketsController extends Controller
{
    /**
     * Return the path to the tickets database JSON file.
     * Defaults to the shared cron location; override via TICKETS_DB_PATH env var.
     */
    private static function dbPath(): string
    {
        return env('TICKETS_DB_PATH', '/home/artur/shared/cron/freshservice-tickets-db.json');
    }

    /**
     * Serve the SPA shell — no server-side data.
     */
    public function index()
    {
        return view('tickets');
    }

    /**
     * API endpoint — returns full ticket DB as JSON.
     *
     * Each ticket gets an `internal` sub-object with:
     *   - ticket_file: path to a task file referencing this ticket (if any)
     *   - next_action: next action for this ticket
     *   - summary: brief summary of the ticket
     */
    public function api()
    {
        $data = json_decode(file_get_contents(self::dbPath()), true);
        $sectionMap = $this->loadPipelineSectionMap();
        $labelAssignments = \App\Services\LabelStore::assignments();

        // Ensure every ticket has an `internal` sub-object
        if (isset($data['tickets'])) {
            foreach ($data['tickets'] as $id => &$ticket) {
                if (!isset($ticket['internal'])) {
                    $ticket['internal'] = [];
                }
                $ticket['internal'] = array_merge([
                    'ticket_file' => null,
                    'next_action' => null,
                    'summary' => null,
                    'pipeline_section' => null,
                    'label_ids' => [],
                ], $ticket['internal']);

                // Shared labels assigned to this ticket (see LabelStore).
                $ticket['internal']['label_ids'] = $labelAssignments[(string) $id] ?? [];

                // Resolve pipeline section from TICKETS-TASK-LIST.md (e.g.
                // "Reply Drafting", "Security Check", "Ready to Send",
                // "Notification", "Human Review", "Closed", etc.). Tickets
                // not present on the task list (e.g. just-arrived FS tickets
                // not yet ingested) get null.
                $key = (string) $id;
                $ticket['internal']['pipeline_section'] = $sectionMap[$key] ?? $sectionMap['T' . $key] ?? null;
            }
            unset($ticket);
        }

        return response()->json($data);
    }

    /**
     * Single-pass scan of TICKETS-TASK-LIST.md → returns `{ticket_id: section}`
     * for every ticket entry on the list. Far cheaper than per-ticket section
     * lookups when serving the dashboard list endpoint.
     *
     * @return array<string, string>
     */
    private function loadPipelineSectionMap(): array
    {
        $path = env('TICKETS_TASK_LIST_PATH', '/shared/task-lists/TICKETS-TASK-LIST.md');
        if (!is_readable($path)) {
            return [];
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return [];
        }
        $lines = preg_split("/\r\n|\n|\r/", $contents);
        $section = '';
        $map = [];
        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $hm)) {
                $section = trim($hm[1]);
                continue;
            }
            // Entry format: `- [ ] [[T66248]](path) | ...`
            if (preg_match('/\[\[T(\d+)\]\]/', $line, $tm)) {
                $map[$tm[1]] = $section;
                $map['T' . $tm[1]] = $section;
            }
        }
        return $map;
    }

    /**
     * GET `/api/automation-feedback` — list automation-feedback submissions for the
     * dashboard's Feedback tab. Read-only: reads the feedback files under
     * tasks/automation-feedback/ and their section (New/Triaged/Done) from
     * AUTOMATION-FEEDBACK-TASK-LIST.md.
     */
    public function automationFeedback()
    {
        $root = env('TASK_LISTS_DIR', '/shared/task-lists');
        $dir = $root . '/tasks/automation-feedback';
        $listFile = $root . '/AUTOMATION-FEEDBACK-TASK-LIST.md';

        // id → section (New / Triaged / Done)
        $sectionOf = [];
        if (is_readable($listFile)) {
            $section = '';
            foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($listFile)) as $line) {
                if (preg_match('/^##\s+(.+?)\s*$/', $line, $hm)) { $section = trim($hm[1]); continue; }
                if (preg_match('/\[\[([A-Za-z0-9._\-]+)\]\]/', $line, $im)) { $sectionOf[$im[1]] = $section; }
            }
        }

        $items = [];
        foreach (glob($dir . '/*.md') ?: [] as $path) {
            $md = (string) @file_get_contents($path);
            if ($md === '') { continue; }
            $fm = $this->parseFrontmatter($md);
            $id = $fm['id'] ?? pathinfo($path, PATHINFO_FILENAME);
            $ticket = $fm['ticket'] ?? '';
            $ticketUrl = preg_match('/(\d{4,})/', $ticket, $tm)
                ? 'https://youritsolutions.freshservice.com/a/tickets/' . $tm[1]
                : '';
            $items[] = [
                'id'         => $id,
                'ticket'     => $ticket,
                'ticket_url' => $ticketUrl,
                'created'    => $fm['created'] ?? '',
                'status'     => $sectionOf[strtoupper($id)] ?? ($sectionOf[$id] ?? ($fm['status'] ?? 'New')),
                'note'       => $this->extractMarkdownSection($md, 'What the user wants changed'),
                'triage'     => $this->extractMarkdownSection($md, 'Triage'),
                'file'       => basename($path),
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string) $b['created'], (string) $a['created']));

        return response()->json(['feedback' => $items]);
    }

    /** Extract the body of a `## <Section>` block from markdown (until the next `## ` or EOF). */
    private function extractMarkdownSection(string $markdown, string $section): string
    {
        $text = str_replace("\r\n", "\n", $markdown);
        $pattern = '/^##\s+' . preg_quote($section, '/') . '\s*$(.*?)(?=^##\s+|\z)/sm';
        return preg_match($pattern, $text, $m) ? trim($m[1]) : '';
    }

    /**
     * FreshService connection health — JSON written by /shared/scripts/fs-connection-test.py
     * (cron runs every 30 min). Returns the file contents verbatim plus an age_seconds hint.
     * If the file is missing or stale (older than 2 h), surface that as status=failed so the
     * dashboard badge goes red instead of misleading-green.
     */
    public function health()
    {
        $path = env('FS_HEALTH_PATH', '/shared/state/fs-connection-health.json');
        if (!is_readable($path)) {
            return response()->json([
                'status' => 'failed',
                'tested_at' => null,
                'summary' => 'Health-check file not found — has the cron job run yet?',
                'checks' => (object) [],
                'age_seconds' => null,
                'file_path' => $path,
            ]);
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return response()->json([
                'status' => 'failed',
                'tested_at' => null,
                'summary' => 'Health-check file is unreadable JSON',
                'checks' => (object) [],
                'age_seconds' => null,
                'file_path' => $path,
            ]);
        }
        $age = isset($data['tested_at'])
            ? max(0, time() - strtotime($data['tested_at']))
            : null;
        $data['age_seconds'] = $age;
        if ($age !== null && $age > 7200) {
            $data['status'] = 'failed';
            $data['summary'] = "Health-check result is stale ({$age}s old). Cron job may have stopped.";
        }

        // Ticket pipeline section counts — quick operational snapshot.
        $data['pipeline_counts'] = $this->ticketPipelineCounts();

        // Recent sync errors from the cron log.
        $data['recent_errors'] = $this->recentSyncErrors();

        return response()->json($data);
    }

    /**
     * Count tickets per section in TICKETS-TASK-LIST.md.
     *
     * @return array<string, int>
     */
    private function ticketPipelineCounts(): array
    {
        $listPath = env('TASK_LISTS_DIR', '/shared/task-lists') . '/TICKETS-TASK-LIST.md';
        if (!is_readable($listPath)) {
            return [];
        }
        $counts = [];
        $section = '';
        foreach (explode("\n", file_get_contents($listPath)) as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                $section = trim($m[1]);
                continue;
            }
            if ($section !== '' && preg_match('/^-\s*\[/', $line)) {
                $counts[$section] = ($counts[$section] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Pull last few error lines from today's sync log, if any.
     *
     * @return list<string>
     */
    private function recentSyncErrors(): array
    {
        $logDir = '/shared/app-files/logs';
        $today = gmdate('Y-m-d');
        $errors = [];
        // Check a handful of relevant log files written by cron scripts.
        $candidates = [
            $logDir . '/freshservice-sync-' . $today . '.log',
            $logDir . '/freshservice-events-' . $today . '.log',
            $logDir . '/reopen-tickets-freshservice-has-open/' . $today . '.log',
        ];
        foreach ($candidates as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice($lines, -100) as $line) {
                if (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
                    $errors[] = basename($file) . ': ' . $line;
                }
            }
        }
        // Return at most 10 most recent errors.
        return array_slice($errors, -10);
    }

    /**
     * Agent queue API — returns all agent tasks from the queue folders.
     */
    public function agents(Request $request)
    {
        // This endpoint builds the full in-range history as one array and
        // json_encodes it; on high-volume days the 24h window alone is ~50MB of
        // JSON / ~210MB peak, which blows php.ini's 128M limit and returns an
        // empty 500 body ("Unexpected end of JSON input"). Measured real peak is
        // ~456MB (186MB response) for a busy 24h window, so raise well above that
        // at runtime — it applies immediately (the .user.ini backstop only lands
        // after FPM's 300s ini cache TTL). Box has 62G RAM and FPM caps at 5
        // workers, so 1G/request is safe. Durable fix: cap/paginate this response.
        @ini_set('memory_limit', '1024M');

        $queueDir = '/shared/agents/queue';
        // Optional ISO-8601 UTC bounds. No params = full history, no caps —
        // how much is visible is the caller's choice, not the server's.
        $since = $request->query('since');
        $until = $request->query('until');

        $timeOf = fn($t) => $t['created_at'] ?? $t['failed_at'] ?? $t['started_at'] ?? '';

        // Range applies to finished work only; running/pending are "now" and are
        // always kept. This is applied AS each file is read (not after loading
        // everything) so out-of-range archive entries are never accumulated —
        // the archive can be >100MB, and loading a whole overlapping month into
        // memory before filtering exhausts PHP's memory limit, which makes the
        // endpoint return an empty 500 body ("Unexpected end of JSON input").
        $inRange = function ($task) use ($since, $until, $timeOf) {
            if (in_array($task['status'] ?? '', ['running', 'pending'], true)) return true;
            $ts = $timeOf($task);
            if ($since && strcmp($ts, $since) < 0) return false;
            if ($until && strcmp($ts, $until) > 0) return false;
            return true;
        };

        $tasks = [];
        $readDir = function (string $dir, ?string $status) use (&$tasks, $inRange) {
            if (!is_dir($dir)) return;
            foreach (glob($dir . '/*.json') as $file) {
                if (!is_readable($file)) continue;
                $task = @json_decode(file_get_contents($file), true);
                if (!$task) continue;
                if ($status !== null) {
                    $task['status'] = $status;
                } else {
                    // Archived entry: the folder no longer says what it was.
                    // New archives carry a stamped terminal status; legacy ones
                    // are inferred from which terminal timestamp they have.
                    if (!empty($task['failed_at'])) $task['status'] = 'failed';
                    elseif (!empty($task['completed_at'])) $task['status'] = 'completed';
                    elseif (!in_array($task['status'] ?? '', ['completed', 'failed', 'cancelled'], true)) $task['status'] = 'completed';
                }
                if (!$inRange($task)) continue;
                $tasks[] = $task;
            }
        };

        foreach (['pending', 'running', 'completed', 'failed', 'cancelled'] as $status) {
            $readDir($queueDir . '/' . $status, $status);
        }

        // Pull in archive months that overlap the requested range (all of them
        // when unbounded). ISO timestamps compare correctly as strings; the
        // "-31" upper bound is safe for short months since it's lexical, not a
        // real date.
        foreach (glob($queueDir . '/archive/*', GLOB_ONLYDIR) ?: [] as $monthDir) {
            $month = basename($monthDir);
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) continue;
            if ($since && strcmp($month . '-31T23:59:59Z', $since) < 0) continue;
            if ($until && strcmp($month . '-01T00:00:00Z', $until) > 0) continue;
            $readDir($monthDir, null);
        }

        // One flat list, newest first — no status grouping.
        usort($tasks, fn($a, $b) => strcmp($timeOf($b), $timeOf($a)));

        return response()->json(['tasks' => $tasks]);
    }

    /**
     * AI Sessions — merge the per-user Claude Code session snapshots written by
     * bin/collect-ai-sessions.py (each user must collect their own because
     * ~/.claude is mode 0700). Returns the flattened session list plus summary
     * counters. Read-only; scope is live sessions + anything active in the last
     * 24h. See bin/collect-ai-sessions.py for the field contract.
     */
    public function aiSessions()
    {
        $dir = storage_path('ai-sessions');
        $sessions = [];
        $collectedAt = null;

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (!is_readable($file)) continue;
            $data = @json_decode(file_get_contents($file), true);
            if (!is_array($data) || !isset($data['sessions']) || !is_array($data['sessions'])) continue;
            $user = $data['user'] ?? pathinfo($file, PATHINFO_FILENAME);
            $userCollected = $data['collected_at'] ?? null;
            if ($userCollected && (!$collectedAt || strcmp($userCollected, $collectedAt) > 0)) {
                $collectedAt = $userCollected;
            }
            foreach ($data['sessions'] as $s) {
                if (!is_array($s)) continue;
                $s['user'] = $user;
                $s['user_collected_at'] = $userCollected;
                $sessions[] = $s;
            }
        }

        // Summary counters.
        $liveCount = 0;
        $orphanCount = 0;
        $tokens24h = 0;
        foreach ($sessions as $s) {
            if (($s['status'] ?? '') === 'live') {
                $liveCount++;
                if (!empty($s['orphan'])) $orphanCount++;
            }
            $t = $s['tokens'] ?? [];
            $tokens24h += ($t['input'] ?? 0) + ($t['output'] ?? 0)
                + ($t['cache_read'] ?? 0) + ($t['cache_write'] ?? 0);
        }

        // Orphans first, then live, then by runtime desc, ended last.
        usort($sessions, function ($a, $b) {
            $rank = function ($s) {
                if (($s['status'] ?? '') === 'live') {
                    return !empty($s['orphan']) ? 0 : 1;
                }
                return 2;
            };
            $ra = $rank($a);
            $rb = $rank($b);
            if ($ra !== $rb) return $ra - $rb;
            return (int)($b['runtime_seconds'] ?? 0) - (int)($a['runtime_seconds'] ?? 0);
        });

        return response()->json([
            'sessions'     => $sessions,
            'collected_at' => $collectedAt,
            'summary'      => [
                'live'        => $liveCount,
                'orphans'     => $orphanCount,
                'tokens_24h'  => $tokens24h,
                'users'       => count(glob($dir . '/*.json') ?: []),
            ],
        ]);
    }

    /**
     * Agent task output — returns the output file content for a given task.
     */
    public function agentOutput(string $id)
    {
        $queueDir = '/shared/agents/queue';
        foreach (['running', 'completed', 'failed', 'pending'] as $status) {
            $dir = $queueDir . '/' . $status;
            foreach (glob($dir . '/*.json') as $file) {
                if (!is_readable($file)) continue;
                $task = @json_decode(file_get_contents($file), true);
                if ($task && ($task['id'] ?? '') === $id) {
                    $outputFile = $task['output_file'] ?? null;
                    if ($outputFile && file_exists($outputFile)) {
                        return response()->json([
                            'success' => true,
                            'content' => file_get_contents($outputFile),
                        ]);
                    }
                    return response()->json([
                        'success' => true,
                        'content' => null,
                        'status' => $status,
                    ]);
                }
            }
        }
        return response()->json(['success' => false, 'error' => 'Task not found']);
    }

    /**
     * Usage-limit history per agent account — one JSONL file per user under
     * /shared/logs/agent-usage/, appended after every completed agent job
     * (queue OnJobCompleted handler) and after interactive turns (Stop hook).
     * Each entry snapshots the account's cached limit utilization: five-hour
     * and seven-day window percentages plus reset times.
     */
    public function agentUsage(Request $request)
    {
        $since = $request->query('since');
        $until = $request->query('until');
        $sinceTs = $since ? strtotime($since) : null;
        $untilTs = $until ? strtotime($until) : null;

        $users = [];
        $dir = config('dashboard.agent_usage_dir') ?: '/shared/logs/agent-usage';
        foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
            if (!is_readable($file)) continue;
            $user = basename($file, '.jsonl');
            $points = [];
            $fh = fopen($file, 'r');
            if (!$fh) continue;
            while (($line = fgets($fh)) !== false) {
                $e = @json_decode($line, true);
                if (!$e || empty($e['ts'])) continue;
                $ts = strtotime($e['ts']);
                if ($ts === false) continue;
                if ($sinceTs && $ts < $sinceTs) continue;
                if ($untilTs && $ts > $untilTs) continue;
                $u = $e['utilization'] ?? null;
                if (!is_array($u)) continue;
                $points[] = [
                    'ts' => gmdate('c', $ts),
                    'five_hour' => $u['five_hour']['utilization'] ?? null,
                    'seven_day' => $u['seven_day']['utilization'] ?? null,
                    'five_hour_resets_at' => $u['five_hour']['resets_at'] ?? null,
                    'seven_day_resets_at' => $u['seven_day']['resets_at'] ?? null,
                    'fetched_at_ms' => $e['fetched_at_ms'] ?? null,
                    'task_id' => $e['task_id'] ?? null,
                ];
            }
            fclose($fh);
            if ($points) {
                usort($points, fn($a, $b) => strcmp($a['ts'], $b['ts']));
                $users[$user] = $points;
            }
        }
        ksort($users);
        return response()->json(['users' => $users]);
    }

    /**
     * Serve a ticket attachment (inline image or file) from the sibling
     * `T{id}-attachments/` directory next to the ticket .md file.
     *
     * Lookup order (first hit wins):
     *   1. `{ticket_dir}/T{numeric}-attachments/{filename}`
     *   2. `{ticket_dir}/{datePrefix}-T{numeric}-attachments/{filename}`
     *
     * Path traversal is prevented by the route regex (`[A-Za-z0-9._\-]+`),
     * and we also realpath-check the resolved path stays inside the ticket
     * directory. Returns 404 on miss.
     */
    public function attachment(string $ticketId, string $filename)
    {
        if (str_contains($filename, '/') || str_contains($filename, '..')) {
            abort(404);
        }

        $ticketFile = TicketFile::find($ticketId);
        if ($ticketFile === null) {
            abort(404);
        }
        $ticketPath = $ticketFile->getPath();
        $ticketDir = dirname($ticketPath);
        $ticketBasename = basename($ticketPath);

        $numericId = ltrim($ticketId, 'Tt');
        $datePrefix = preg_match('/^(\d{8})-/', $ticketBasename, $m) ? $m[1] : '';

        $candidates = [
            $ticketDir . '/T' . $numericId . '-attachments/' . $filename,
        ];
        if ($datePrefix !== '') {
            $candidates[] = $ticketDir . '/' . $datePrefix . '-T' . $numericId . '-attachments/' . $filename;
        }

        $resolved = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $real = realpath($candidate);
                $dirReal = realpath($ticketDir);
                if ($real !== false && $dirReal !== false && str_starts_with($real, $dirReal . DIRECTORY_SEPARATOR)) {
                    $resolved = $real;
                    break;
                }
            }
        }
        if ($resolved === null) {
            abort(404);
        }

        $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'             => 'image/png',
            'jpg', 'jpeg'     => 'image/jpeg',
            'gif'             => 'image/gif',
            'webp'            => 'image/webp',
            'svg'             => 'image/svg+xml',
            'pdf'             => 'application/pdf',
            'txt', 'log'      => 'text/plain; charset=utf-8',
            default           => 'application/octet-stream',
        };

        return response()->file($resolved, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control'       => 'private, max-age=3600',
        ]);
    }

    /**
     * Serve the raw markdown content of a task file linked from a ticket's
     * `## Subtasks` section, given as `?path=` relative to the ticket file.
     *
     * The link is NOT always a sibling of the ticket: spawned subtasks live
     * next to it (`./file.md`), while filed/linked tasks live one level up in
     * the shared tasks directory (`../file.md`). Both must open — restricting
     * this to the ticket's own directory 404'd every tracked task (B/F ids).
     * Containment is therefore enforced against the task-lists root that holds
     * the ticket directory, not against the ticket directory itself.
     *
     * Returns JSON `{ filename, path, content }` on success.
     */
    public function subtask(string $ticketId, Request $request)
    {
        $path = (string) $request->query('path', '');
        if ($path === '' || str_starts_with($path, '/')) {
            return response()->json(['error' => 'invalid_filename'], 400);
        }
        if (!str_ends_with(strtolower($path), '.md')) {
            return response()->json(['error' => 'invalid_filename'], 400);
        }

        $ticketFile = TicketFile::find($ticketId);
        if ($ticketFile === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }
        $ticketDir = dirname($ticketFile->getPath());
        $subtaskPath = $ticketDir . '/' . $path;
        if (!is_file($subtaskPath)) {
            return response()->json(['error' => 'subtask_not_found', 'path' => $subtaskPath], 404);
        }
        // Ticket dir is `<task-lists>/tasks/drafts`, so its grandparent is the
        // task-lists root — the widest tree a ticket may legitimately link into.
        $rootReal = realpath(dirname($ticketDir, 2));
        $real = realpath($subtaskPath);
        if ($real === false || $rootReal === false
            || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)
        ) {
            return response()->json(['error' => 'invalid_path'], 400);
        }

        $content = @file_get_contents($real);
        if ($content === false) {
            return response()->json(['error' => 'read_failed'], 500);
        }

        return response()->json([
            'filename' => basename($real),
            'path'     => $path,
            'content'  => $content,
        ]);
    }

    /**
     * Serve the raw markdown content of a task related to this ticket — i.e. a
     * task on another list (Coding, Consult, …) that references the ticket and
     * is rendered in the panel's `## Related tasks` block.
     *
     * Unlike subtasks, related tasks live outside the ticket's own directory,
     * so this endpoint does NOT accept a caller-supplied path. It re-derives the
     * vetted related-task list server-side (findRelatedTasks), matches on the
     * requested task id, and returns only that file — so only files the panel
     * would already surface as related are ever readable.
     *
     * Returns JSON `{ filename, content }` on success.
     */
    public function relatedTask(string $ticketId, string $taskId)
    {
        $related = $this->findRelatedTasks($ticketId);
        $match = null;
        foreach ($related as $r) {
            if (($r['task_id'] ?? '') === $taskId) {
                $match = $r;
                break;
            }
        }
        if ($match === null) {
            return response()->json(['error' => 'related_task_not_found'], 404);
        }

        $resolved = $this->resolveTaskFilePath((string) ($match['path'] ?? ''));
        if ($resolved === null) {
            return response()->json(['error' => 'related_task_file_not_found', 'path' => $match['path'] ?? ''], 404);
        }

        $content = @file_get_contents($resolved);
        if ($content === false) {
            return response()->json(['error' => 'read_failed'], 500);
        }

        return response()->json([
            'filename' => $match['path'] ?? basename($resolved),
            'content'  => $content,
        ]);
    }

    /**
     * Resolve a related-task list-entry path to a readable absolute file.
     *
     * List entries link the file relative to different bases per list (some to
     * /shared/task-lists, some to its parent /shared), or absolutely — so try
     * each candidate. The result is confined via realpath to the /shared tree
     * and must be a .md file: defence in depth on top of findRelatedTasks having
     * already vetted the entry as related to this ticket. Returns null if none
     * resolves safely.
     *
     * `$relativeTo` adds one more base to try FIRST: a subtask is linked from
     * the ticket file as `./{name}.md`, i.e. relative to the TICKET's own
     * directory, which matches neither list base. Without it such a link never
     * resolves, and a subtask that cannot be resolved cannot be recognised as
     * already-shown — so it surfaces a second time under "Related tasks".
     */
    private function resolveTaskFilePath(string $entryPath, ?string $relativeTo = null): ?string
    {
        if ($entryPath === '') {
            return null;
        }
        $dir  = env('TASK_LISTS_DIR', '/shared/task-lists');
        $base = dirname($dir); // /shared
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }
        if (str_starts_with($entryPath, '/')) {
            $candidates = [$entryPath];
        } else {
            $candidates = [];
            if ($relativeTo !== null && $relativeTo !== '') {
                $candidates[] = rtrim($relativeTo, '/') . '/' . $entryPath;
            }
            $candidates[] = $dir . '/' . $entryPath;
            $candidates[] = $base . '/' . $entryPath;
        }
        foreach ($candidates as $p) {
            if (!is_file($p)) {
                continue;
            }
            $real = realpath($p);
            if ($real === false) {
                continue;
            }
            if (!str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (!str_ends_with(strtolower($real), '.md')) {
                continue;
            }
            return $real;
        }
        return null;
    }

    /**
     * Ingest a ticket into the pipeline on demand by running the same
     * deterministic creator the OnTicketCreated poller uses. Idempotent
     * (no-op if the file already exists). Best-effort: logs and swallows
     * failures so the caller can fall back to a soft "ingesting" response.
     */
    private function ingestTicketFile(string $ticketId): void
    {
        $numericId = ltrim($ticketId, 'Tt');
        if (!ctype_digit($numericId)) {
            return;
        }
        $script = env('TICKET_CREATE_SCRIPT', '/shared/scripts/freshservice_ticket_create.py');
        if (!is_readable($script)) {
            Log::warning('ingestTicketFile: creator script not readable', ['script' => $script]);
            return;
        }
        try {
            $process = new Process(['python3', $script, $numericId]);
            // FS fetch + attachment download can take a while; cap generously
            // but bounded so a slow FS doesn't hang the request thread.
            $process->setTimeout(90.0);
            $process->run();
            if (!$process->isSuccessful()) {
                Log::warning('ingestTicketFile: creator exited non-zero', [
                    'ticket_id' => $ticketId,
                    'exit'      => $process->getExitCode(),
                    'err'       => trim($process->getErrorOutput() ?: $process->getOutput()),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ingestTicketFile: creator failed to run', [
                'ticket_id' => $ticketId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return the parsed ticket file (metadata, subject, body, replies,
     * subtasks, timeline) for the ticket detail viewer.
     */
    public function detail(string $ticketId)
    {
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            // Auto-heal: a ticket can be in the FreshService DB (so it shows
            // in the dashboard list) but have no pipeline file yet — e.g. its
            // OnTicketCreated ingestion never ran or failed. Rather than show
            // the operator an error, ingest it on demand via the same
            // deterministic creator the poller uses, then retry. The creator
            // is idempotent and fetches fresh FS data + attachments and adds
            // the ticket to TICKETS-TASK-LIST § New so the pipeline picks it up.
            $this->ingestTicketFile($ticketId);
            $file = TicketFile::find($ticketId);
        }
        if ($file === null) {
            // Still missing after the ingest attempt — most likely FS was
            // unreachable. Return a soft "ingesting" state, not a hard error,
            // so the panel can show "fetching…" and the operator can retry.
            return response()->json([
                'error'   => 'ticket_file_ingesting',
                'message' => 'This ticket was not in the pipeline yet — fetching it from FreshService now. Refresh in a few seconds.',
            ], 202);
        }

        $replies = $file->getReplies();
        $rawSubtasks = $file->getSubtasks();
        $subtasks = $this->enrichSubtasks($rawSubtasks, dirname($file->getPath()));
        // Build a set of resolved subtask paths so findRelatedTasks can skip them —
        // a subtask file must not appear in both panels simultaneously.
        $subtaskPaths = [];
        $ticketDir = dirname($file->getPath());
        foreach ($rawSubtasks as $s) {
            $resolved = $this->resolveTaskFilePath($s['path'] ?? '', $ticketDir);
            if ($resolved !== null) {
                $subtaskPaths[$resolved] = true;
            }
        }
        $relatedTasks = $this->findRelatedTasks($ticketId, $subtaskPaths);

        // What stage each tracked coding / feature task is at — the release it
        // shipped in, or the branch it merged to, or where it sits in the
        // pipeline. Resolved from git for merged work (see task-stages.py), so
        // a task list shuffled between sections cannot make this wrong.
        $stages = app(TaskStages::class);
        $subtasks = $stages->decorate($subtasks);
        $relatedTasks = $stages->decorate($relatedTasks);
        $pipelineSection = $this->loadPipelineSectionMap()[ltrim($ticketId, 'Tt')] ?? null;
        $bodyHtml = null;

        // Best-effort enrichment with FS-side HTML so inline images render
        // in their original position. Cached to disk to avoid hitting FS on
        // every panel open (~1-3s per call). Cache invalidates on ticket file mtime.
        $numericId = ltrim($ticketId, 'Tt');
        if (ctype_digit($numericId)) {
            $enriched = $this->loadOrFetchEnrichment((int) $numericId, $file->getPath());
            if ($enriched !== null) {
                $bodyHtml = $enriched['description_html'] ?? null;
                $enricher = app(TicketHtmlEnricher::class);
                foreach ($replies as &$r) {
                    $html = $enricher->matchReplyHtml($r, $enriched['conversations'] ?? []);
                    if ($html !== null) {
                        $r['body_html'] = $html;
                    }
                }
                unset($r);
            }
        }

        // Parse the YAML frontmatter for tenant-version-tracking fields. These
        // are set by the orchestrator (when a linked Released task framing
        // includes "your instance is currently on vY.Y.Y") and cleared by the
        // tenant-version-upgrade-watcher when the tenant catches up. The
        // dashboard renders a banner from this so operators see at-a-glance
        // that the ticket is parked waiting on a tenant bump.
        $frontmatter = $this->parseFrontmatter(@file_get_contents($file->getPath()) ?: '');
        $versionStatus = null;
        if (!empty($frontmatter['awaiting_version'])) {
            $versionStatus = [
                'state'                  => 'awaiting',
                'awaiting_version'       => $frontmatter['awaiting_version'],
                'tenant_current_release' => $frontmatter['tenant_current_release'] ?? null,
                'set_at'                 => $frontmatter['awaiting_version_set_at'] ?? null,
            ];
        } elseif (!empty($frontmatter['version_landed_release'])) {
            $versionStatus = [
                'state'                  => 'landed',
                'tenant_release'         => $frontmatter['version_landed_release'],
                'landed_at'              => $frontmatter['version_landed_at'] ?? null,
            ];
        }

        return response()->json([
            'path'         => $file->getPath(),
            'metadata'     => $file->getMetadata(),
            'subject'      => $file->getSection('Subject'),
            'body'         => $file->getSection('Body'),
            'body_html'    => $bodyHtml,
            // Files the customer sent with the opening message. Inline images
            // are already rendered inside the body, but a real file attachment
            // (a PDF, a spreadsheet) had no representation at all until this
            // was surfaced — it sat on disk, downloaded and scanned, with
            // nothing in the panel pointing at it.
            'attachments'  => $file->getAttachments(),
            'replies'      => $replies,
            'subtasks'     => $subtasks,
            'related_tasks' => $relatedTasks,
            'pipeline_section' => $pipelineSection,
            'timeline'     => $file->getSection('Timeline'),
            'reply_draft'  => $this->loadReplyDraft($file->getPath()),
            'version_status' => $versionStatus,
            'operator_note' => $file->getSection('Operator Note'),
        ]);
    }

    /**
     * POST `/api/tickets/{id}/operator-note` — save (replace) the single
     * editable internal operator note on a ticket. Stored as the `## Operator
     * Note` section in the ticket file. No history — the latest text wins.
     * An empty note clears the section to a placeholder.
     */
    public function saveOperatorNote(string $ticketId)
    {
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }
        $note = (string) request()->input('note', '');
        if (mb_strlen($note) > 10000) {
            $note = mb_substr($note, 0, 10000);
        }
        $body = trim($note) === '' ? '_(empty)_' : $note;
        try {
            $file->setSection('Operator Note', $body);
        } catch (\Throwable $e) {
            Log::warning('saveOperatorNote failed', ['ticket_id' => $ticketId, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'write_failed', 'message' => $e->getMessage()], 500);
        }
        return response()->json(['status' => 'saved']);
    }

    /**
     * Scan every `*-TASK-LIST.md` under `/shared/task-lists/` for entries that
     * reference this ticket and return them as
     *   `[{task_id, title, list, section, path, label, status_marker}, ...]`.
     *
     * Detection heuristic: a checklist line matching
     *   `- [ x or space ] [[TASKID]](path) | **LABEL** ... (INC-{ticket})`
     * with the ticket numeric id appearing as `INC-NNNNN` or `tickets/NNNNN`
     * counts as a hit; the current `## Section` (most-recent heading above
     * the line) becomes the entry's section.
     *
     * Subtask files belonging to this ticket are filtered out (they're
     * already rendered in the panel's Subtasks block). Same for the ticket
     * file itself.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findRelatedTasks(string $ticketId, array $subtaskPaths = []): array
    {
        $numeric = ltrim($ticketId, 'Tt');
        if ($numeric === '' || !ctype_digit($numeric)) {
            return [];
        }
        $dir = env('TASK_LISTS_DIR', '/shared/task-lists');
        if (!is_dir($dir)) {
            return [];
        }
        $needles = ['INC-' . $numeric, 'tickets/' . $numeric];

        $results = [];
        $seenFiles = [];
        foreach (glob($dir . '/*-TASK-LIST.md') ?: [] as $listPath) {
            $contents = @file_get_contents($listPath);
            if (!is_string($contents) || $contents === '') {
                continue;
            }
            $lines = preg_split("/\r\n|\n|\r/", $contents);
            $section = '';
            $listName = basename($listPath, '.md');
            // Trim `-TASK-LIST` suffix for a friendlier label.
            $listLabel = preg_replace('/-TASK-LIST$/', '', $listName);

            foreach ($lines as $line) {
                if (preg_match('/^##\s+(.+?)\s*$/', $line, $hm)) {
                    $section = trim($hm[1]);
                    continue;
                }
                // Only consider checklist entries that link a task file.
                if (!preg_match('/^-\s*\[([ xX])\]\s*\[\[([A-Za-z0-9._\-]+)\]\]\(([^)]+)\)(.*)$/', $line, $m)) {
                    continue;
                }
                $taskId = trim($m[2]);
                $path = trim($m[3]);
                $tail = trim($m[4]);

                // Skip subtask entries belonging to this same ticket (rendered
                // in the panel's Subtasks block) and skip the ticket file itself.
                if (preg_match('/T' . $numeric . '-sub-/', $taskId) || preg_match('/T' . $numeric . '-sub-/', $path)) {
                    continue;
                }
                // A ticket is not a task. Ticket files (`*-T<id>-fs-ticket.md`)
                // are this panel's own subject matter, so surfacing one under
                // "Related tasks" renders a ticket inside a ticket.
                //
                // This used to skip only THIS ticket's own file. But any other
                // ticket gets pulled in as soon as its body mentions this
                // ticket's number — e.g. "Same incident as INC-67311" in an
                // unrelated ticket's analysis. That is a cross-reference
                // between two tickets, not a task anyone has to do, and it
                // belongs nowhere near this list.
                if (preg_match('/-T\d+-fs-ticket/', $path)) {
                    continue;
                }

                // Related if the ticket is referenced either inline on the entry
                // line OR inside the task file itself (frontmatter `ticket:` / an
                // INC link). The file check catches tasks whose list entry does
                // not inline the ticket reference (e.g. B0452, C0025).
                $hit = false;
                foreach ($needles as $n) {
                    if (strpos($line, $n) !== false) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit && $this->taskFileMentionsTicket($path, $numeric)) {
                    $hit = true;
                }
                if (!$hit) {
                    continue;
                }

                // A task file is attached to a ticket at its HOME — the one list
                // where it is really processed. The task file's frontmatter
                // `home:` (maintained automatically by task-manager on add/move)
                // names that list. Every appearance of the same file on any
                // OTHER list is an informational pointer and is skipped here, so
                // the panel shows the task once, at its real destination.
                $home = $this->taskFileHome($path);
                if ($home !== null && $home !== $listName) {
                    continue;
                }
                // Safety dedup by resolved file (covers legacy files with no
                // `home` stamp yet that happen to sit on two lists).
                $canon = $this->resolveTaskFilePath($path);
                if ($canon !== null) {
                    if (isset($seenFiles[$canon])) {
                        continue;
                    }
                    $seenFiles[$canon] = true;
                    // Already shown in the Subtasks panel — skip from Related.
                    if (isset($subtaskPaths[$canon])) {
                        continue;
                    }
                }

                // Extract label (`**LABEL**`) and title.
                $label = '';
                $title = $tail;
                if (preg_match('/\*\*([A-Z][A-Z0-9 \-]+)\*\*\s*(.*)/', ltrim($title, '| '), $lm)) {
                    $label = trim($lm[1]);
                    $title = trim($lm[2]);
                }
                // Strip trailing `(INC-XXX...)` to keep the title clean.
                $title = preg_replace('/\s*\(\[INC-\d+\]\([^)]*\)\)\s*$/', '', $title);
                $title = preg_replace('/\s*\(INC-\d+\)\s*$/', '', $title);
                $title = trim($title);

                // Pull common inline status markers (✅ MERGED, 🔍 In PR review, etc.)
                $statusMarker = '';
                if (preg_match('/(✅ MERGED|🔍 In PR review|🔧 In development|🚀 Released|⏳ Awaiting|🛑 Blocked)/u', $title, $sm)) {
                    $statusMarker = $sm[1];
                }

                $results[] = [
                    'task_id'        => $taskId,
                    'title'          => $title !== '' ? $title : $taskId,
                    'list'           => $listLabel,
                    'section'        => $section,
                    'path'           => $path,
                    'checked'        => strtolower(trim($m[1])) === 'x',
                    'label'          => $label,
                    'status_marker'  => $statusMarker,
                ];
            }
        }
        return $results;
    }

    /**
     * The task's HOME list — the one list where it is really processed, read
     * from the task file's frontmatter `home:` (list filename stem, e.g.
     * `CODING-TASK-LIST`). Maintained automatically by task-manager on add/move.
     * Returns null when the file is missing or carries no `home` stamp (legacy).
     */
    private function taskFileHome(string $entryPath): ?string
    {
        $path = $this->resolveTaskFilePath($entryPath);
        if ($path === null) {
            return null;
        }
        $head = @file_get_contents($path, false, null, 0, 4096);
        if (!is_string($head) || $head === '') {
            return null;
        }
        return preg_match('/^home:\s*(.+?)\s*$/mi', $head, $m) ? trim($m[1]) : null;
    }

    /**
     * True if the task file linked by a list entry references this ticket — via
     * frontmatter `ticket:` or an INC-/tickets- link in its first 4 KB (where the
     * frontmatter lives). Lets findRelatedTasks catch tasks whose list-entry line
     * doesn't inline the ticket reference. The entry path base differs per list
     * (some relative to /shared, some to /shared/task-lists), so try both.
     */
    private function taskFileMentionsTicket(string $entryPath, string $numeric): bool
    {
        $dir = env('TASK_LISTS_DIR', '/shared/task-lists');
        $candidates = str_starts_with($entryPath, '/')
            ? [$entryPath]
            : [$dir . '/' . $entryPath, dirname($dir) . '/' . $entryPath];
        foreach ($candidates as $p) {
            if (!is_file($p)) {
                continue;
            }
            $head = @file_get_contents($p, false, null, 0, 4096);
            if (!is_string($head) || $head === '') {
                return false;
            }
            if (strpos($head, 'INC-' . $numeric) !== false) {
                return true;
            }
            if (strpos($head, 'tickets/' . $numeric) !== false) {
                return true;
            }
            return (bool) preg_match('/^ticket:\s*\[?(?:INC-|T)?' . $numeric . '\b/mi', $head);
        }
        return false;
    }

    /**
     * For each subtask reference parsed from the parent ticket, open the
     * linked file and pull a few useful fields from its YAML frontmatter so
     * the panel can render them as badges:
     *   - blocks_reply (default true; subtasks marked false don't block the
     *     reply, which the user needs to see at a glance)
     *   - status       (e.g. "done", "open", "blocked") — supplementary
     *     context beyond the parent ticket's `- [x]` / `- [ ]` checkbox
     *
     * Subtask files that can't be opened (missing, unreadable, malformed
     * frontmatter) keep the original parsed row unchanged; default
     * `blocks_reply: true` is filled in so the frontend always has a value
     * to render.
     *
     * @param array<int, array<string, mixed>> $subtasks
     * @return array<int, array<string, mixed>>
     */
    private function enrichSubtasks(array $subtasks, string $ticketDir): array
    {
        foreach ($subtasks as &$s) {
            $s['blocks_reply'] = true;
            $s['status'] = null;

            $rel = (string) ($s['path'] ?? '');
            if ($rel === '') {
                continue;
            }
            $rel = ltrim($rel, './');
            if (str_contains($rel, '/') || str_contains($rel, '..')) {
                continue;
            }
            $abs = $ticketDir . '/' . $rel;
            if (!is_file($abs)) {
                continue;
            }
            $text = @file_get_contents($abs);
            if (!is_string($text) || $text === '') {
                continue;
            }
            $text = preg_replace("/\r\n|\r/", "\n", $text);
            if (!str_starts_with($text, "---\n")) {
                continue;
            }
            $end = strpos($text, "\n---", 4);
            if ($end === false) {
                continue;
            }
            $block = substr($text, 4, $end - 4);
            foreach (explode("\n", $block) as $line) {
                if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $m)) {
                    continue;
                }
                $key = strtolower(trim($m[1]));
                $value = trim($m[2]);
                if ($key === 'blocks_reply') {
                    $lowered = strtolower($value);
                    $s['blocks_reply'] = !in_array($lowered, ['false', 'no', '0', 'off', ''], true);
                } elseif ($key === 'status') {
                    $s['status'] = $value;
                }
            }
        }
        unset($s);
        return $subtasks;
    }

    /**
     * Load FS enrichment from disk cache or fetch fresh. Cache invalidates
     * when the ticket .md file is modified.
     *
     * @return array{description_html:?string, conversations:array}|null
     */
    private function loadOrFetchEnrichment(int $numericTicketId, string $ticketPath): ?array
    {
        $ticketDir = dirname($ticketPath);
        $cachePath = $ticketDir . '/T' . $numericTicketId . '-attachments/_fs-html-cache.json';
        $ticketMtime = @filemtime($ticketPath) ?: 0;

        if (is_file($cachePath)) {
            $cacheData = @json_decode((string) @file_get_contents($cachePath), true);
            if (is_array($cacheData)
                && isset($cacheData['ticket_mtime'])
                && (int) $cacheData['ticket_mtime'] === $ticketMtime
                && isset($cacheData['payload']) && is_array($cacheData['payload'])
            ) {
                return $cacheData['payload'];
            }
        }

        $enricher = app(TicketHtmlEnricher::class);
        $fresh = $enricher->fetch($numericTicketId, $ticketDir);
        if ($fresh === null) {
            return null;
        }

        if (is_dir(dirname($cachePath))) {
            @file_put_contents($cachePath, json_encode([
                'ticket_mtime' => $ticketMtime,
                'fetched_at'   => gmdate('c'),
                'payload'      => $fresh,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $fresh;
    }

    /**
     * Locate and parse the sibling reply-draft file for the given ticket.
     *
     * Returns null if no draft is present. Returns the parsed structure
     * (filename, frontmatter `to` / `cc` / `language`, raw body markdown,
     * rendered HTML preview) when one exists. The HTML preview is exactly
     * what would be POST'd to FreshService, suitable for use as the
     * "this is what will be sent" preview in the send-reply modal.
     */
    private function loadReplyDraft(string $ticketPath): ?array
    {
        $filename = basename($ticketPath);
        if (!preg_match('/^(\d{8})-T([0-9]+)-fs-ticket\.md$/', $filename, $m)) {
            return null;
        }
        $datePrefix = $m[1];
        $numericId = $m[2];

        // The drafter writes the first round to `{date}-T{N}-reply-draft.md` and
        // every subsequent follow-up round to `{date}-T{N}-reply-draft-{NN}.md`
        // where NN matches the send round (02, 03, …). One file per round means
        // no draft is ever lost to overwrite and the "already sent" check below
        // can rely on filename match against the parent Timeline. Pick the
        // latest existing draft: highest NN if any numbered files exist,
        // otherwise the unnumbered file.
        $dir = dirname($ticketPath);
        $base = $datePrefix . '-T' . $numericId . '-reply-draft';
        $candidates = glob($dir . '/' . $base . '*.md') ?: [];
        $latest = null;
        $latestRound = 0; // 1 = unnumbered "first" draft, 2..N = -NN suffix
        foreach ($candidates as $path) {
            $name = basename($path);
            if ($name === $base . '.md') {
                $round = 1;
            } elseif (preg_match('/^' . preg_quote($base, '/') . '-(\d{2,})\.md$/', $name, $mm)) {
                $round = (int) $mm[1];
            } else {
                continue;
            }
            if ($round > $latestRound) {
                $latestRound = $round;
                $latest = $path;
            }
        }
        if ($latest === null) {
            return null;
        }
        $draftPath = $latest;
        $draftFilename = basename($latest);
        $markdown = @file_get_contents($draftPath);
        if ($markdown === false || $markdown === '') {
            return null;
        }

        $frontmatter = $this->parseFrontmatter($markdown);
        $bodyMd = $this->extractDraftBodyMarkdown($markdown);
        $bodyHtmlPreview = ReplyHtmlBuilder::signedFromMarkdownDraft($markdown);

        $to = $frontmatter['to'] ?? $this->extractSectionLine($markdown, 'To');
        $cc = $frontmatter['cc'] ?? $this->extractSectionLine($markdown, 'CC');
        $language = $frontmatter['language'] ?? 'en';
        $mtime = @filemtime($draftPath);

        // Prefer the canonical `created_at` recorded by the drafter in frontmatter
        // (always in UTC, e.g. "2026-05-13 11:30 UTC"). File mtime is unreliable —
        // it updates on every edit (rejection rewrites, linter touches, etc.) and
        // the previous `date('c', $mtime)` produced server-local TZ which mismatched
        // the UTC timestamps shown elsewhere on the ticket.
        $createdAt = $frontmatter['created_at'] ?? ($mtime ? gmdate('Y-m-d H:i', $mtime) . ' UTC' : null);

        // Detect whether this draft was already sent by scanning the parent
        // ticket's Timeline for the matching idempotency entry written by
        // TicketActionController::sendReply (step 8).
        $sentAt = null;
        $conversationId = null;
        $ticketMarkdown = @file_get_contents($ticketPath);
        if (is_string($ticketMarkdown) && $ticketMarkdown !== '') {
            $escaped = preg_quote($draftFilename, '/');
            if (preg_match(
                '/^[-\s]*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}) UTC:?\s*Public reply sent to .*?\(FS conversation #(\d+)\).*?Draft: \.\/' . $escaped . '\.?/m',
                $ticketMarkdown,
                $match
            )) {
                $sentAt = $match[1] . ' UTC';
                $conversationId = (int) $match[2];
            }
        }

        // Check if auto-send is armed for this draft filename.
        $autoSendArmed = false;
        $armsDir = env('AUTO_SEND_ARMS_DIR', '/shared/state/auto-send-arms');
        $armFile = $armsDir . '/T' . $numericId . '.json';
        if (is_file($armFile)) {
            $arm = @json_decode(@file_get_contents($armFile), true);
            $autoSendArmed = is_array($arm) && ($arm['draft_filename'] ?? '') === $draftFilename;
        }

        return [
            'filename'          => $draftFilename,
            'path'              => $draftPath,
            'created_at'        => $createdAt,
            'sent_at'           => $sentAt,
            'conversation_id'   => $conversationId,
            'to'                => $to,
            'cc'                => $cc,
            'language'          => $language,
            'subject'           => $this->extractSectionLine($markdown, 'Subject'),
            'body_markdown'     => $bodyMd,
            'body_html_preview' => $bodyHtmlPreview,
            'auto_send_armed'   => $autoSendArmed,
        ];
    }

    /**
     * Minimal YAML frontmatter parser — flat key:value pairs only.
     *
     * @return array<string, string>
     */
    private function parseFrontmatter(string $markdown): array
    {
        $text = preg_replace("/\r\n|\r/", "\n", $markdown);
        if (!str_starts_with($text, "---\n")) {
            return [];
        }
        $end = strpos($text, "\n---", 4);
        if ($end === false) {
            return [];
        }
        $block = substr($text, 4, $end - 4);
        $out = [];
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $m)) {
                $key = strtolower(trim($m[1]));
                $value = trim($m[2]);
                if ($value !== '') {
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * First non-blank line of a `## {section}` block in the draft.
     */
    private function extractSectionLine(string $markdown, string $section): string
    {
        $text = preg_replace("/\r\n|\r/", "\n", $markdown);
        $pattern = '/^##\s+' . preg_quote($section, '/') . '\s*$(.*?)(?=^##\s+|\z)/sm';
        if (!preg_match($pattern, $text, $m)) {
            return '';
        }
        foreach (explode("\n", trim($m[1])) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '_(none)_') {
                continue;
            }
            return $line;
        }
        return '';
    }

    /**
     * Return the markdown body of the draft — the contents of `## Body`, or
     * the post-frontmatter remainder if no Body section exists.
     */
    private function extractBodyMarkdown(string $markdown): string
    {
        $text = preg_replace("/\r\n|\r/", "\n", $markdown);
        if (preg_match('/^##\s+Body\s*$(.*?)(?=^##\s+|\z)/sm', $text, $m)) {
            return trim($m[1]);
        }
        return trim($text);
    }

    /**
     * Extract the editable body of a reply-draft file: strip the YAML
     * frontmatter and the `# Reply draft …` title, then de-quote the `>`-quoted
     * body block into plain paragraphs — i.e. exactly the text the customer
     * reads and the user edits in the panel.
     *
     * The body ENDS where the blockquote ends. Everything a draft file carries
     * below it — `---`, `## Internal notes`, `## Knowledge gaps` — is internal
     * working material and must never appear in the panel: the panel's text is
     * what an operator edits and sends, so anything shown there can reach the
     * customer. (2026-08-12: T67887's draft showed its full internal notes —
     * reviewer feedback, source file references, tenant deployment state — in
     * the send box.) Blank lines inside the quote block are kept so paragraph
     * spacing survives.
     */
    private function extractDraftBodyMarkdown(string $markdown): string
    {
        $text = preg_replace("/\r\n|\r/", "\n", $markdown);
        if (str_starts_with($text, "---\n")) {
            $end = strpos($text, "\n---", 4);
            if ($end !== false) {
                $text = substr($text, $end + 4);
            }
        }
        $out = [];
        $seenQuote = false;
        $pendingBlanks = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^#\s+/', $line)) {
                continue; // drop the "# Reply draft — T…" title
            }
            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                // A blank line only belongs to the body if the quote resumes
                // after it — otherwise it was the gap before the internal tail.
                foreach ($pendingBlanks as $blank) {
                    $out[] = $blank;
                }
                $pendingBlanks = [];
                $out[] = $m[1];
                $seenQuote = true;
            } elseif ($seenQuote) {
                if (trim($line) === '') {
                    $pendingBlanks[] = $line;
                    continue;
                }
                break; // first unquoted content line ends the customer-facing body
            }
        }
        // A drafter that put its internal notes INSIDE the blockquote (it
        // happens — T67881's second draft did) would otherwise smuggle them
        // through the de-quoting, so the same heading rule applies here too.
        $body = self::stripInternalTail(trim(implode("\n", $out)));
        if ($body !== '') {
            return $body;
        }
        // No blockquote in this draft (plain `## Body` convention, or a body
        // written unquoted). Same rule still applies: nothing from an internal
        // `## …` section may reach the panel.
        return self::stripInternalTail($this->extractBodyMarkdown($markdown));
    }

    /**
     * Drop everything from the first `## …` heading onward, plus a trailing
     * horizontal rule. A customer-facing reply is prose and never carries an H2;
     * the draft files use H2s exclusively for internal sections.
     */
    public static function stripInternalTail(string $body): string
    {
        $text = preg_replace("/\r\n|\r/", "\n", $body) ?? '';
        if (preg_match('/^##\s+/m', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, 0, $m[0][1]);
        }
        // A draft separates the body from its internal tail with `---`; once the
        // tail is gone the rule is a dangling artefact, not content.
        $text = preg_replace('/\n\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', '', rtrim($text)) ?? $text;
        return trim($text);
    }
}

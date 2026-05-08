<?php

namespace App\Http\Controllers;

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
                ], $ticket['internal']);
            }
            unset($ticket);
        }

        return response()->json($data);
    }

    /**
     * Agent queue API — returns all agent tasks from the queue folders.
     */
    public function agents()
    {
        $queueDir = '/shared/agents/queue';
        $dirs = [
            'pending'   => $queueDir . '/pending',
            'running'   => $queueDir . '/running',
            'completed' => $queueDir . '/completed',
            'failed'    => $queueDir . '/failed',
            'cancelled' => $queueDir . '/cancelled',
        ];

        $tasks = [];

        foreach ($dirs as $status => $dir) {
            if (!is_dir($dir)) continue;
            $files = glob($dir . '/*.json');

            // Filter out unreadable files first
            $files = array_filter($files, 'is_readable');

            // For completed/cancelled, sort by mtime desc and limit
            if ($status === 'completed' || $status === 'cancelled') {
                usort($files, fn($a, $b) => @filemtime($b) - @filemtime($a));
                $files = array_slice($files, 0, 50);
            }

            foreach ($files as $file) {
                if (!is_readable($file)) continue;
                $task = @json_decode(file_get_contents($file), true);
                if (!$task) continue;
                $task['status'] = $status;
                $tasks[] = $task;
            }
        }

        // Sort: running first, pending, failed, completed; within same status by created_at desc
        $order = ['running' => 0, 'pending' => 1, 'failed' => 2, 'cancelled' => 3, 'completed' => 4];
        usort($tasks, function ($a, $b) use ($order) {
            $aO = $order[$a['status']] ?? 4;
            $bO = $order[$b['status']] ?? 4;
            if ($aO !== $bO) return $aO - $bO;
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return response()->json(['tasks' => $tasks]);
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
}

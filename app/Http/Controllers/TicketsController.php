<?php

namespace App\Http\Controllers;

use App\Services\ReplyHtmlBuilder;
use App\Services\TicketFile;

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

    /**
     * Return the parsed ticket file (metadata, subject, body, replies,
     * subtasks, timeline) for the ticket detail viewer.
     */
    public function detail(string $ticketId)
    {
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }

        return response()->json([
            'path'         => $file->getPath(),
            'metadata'     => $file->getMetadata(),
            'subject'      => $file->getSection('Subject'),
            'body'         => $file->getSection('Body'),
            'replies'      => $file->getReplies(),
            'subtasks'     => $file->getSubtasks(),
            'timeline'     => $file->getSection('Timeline'),
            'reply_draft'  => $this->loadReplyDraft($file->getPath()),
        ]);
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

        $draftFilename = $datePrefix . '-T' . $numericId . '-reply-draft.md';
        $draftPath = dirname($ticketPath) . '/' . $draftFilename;
        if (!is_file($draftPath)) {
            return null;
        }
        $markdown = @file_get_contents($draftPath);
        if ($markdown === false || $markdown === '') {
            return null;
        }

        $frontmatter = $this->parseFrontmatter($markdown);
        $bodyMd = $this->extractBodyMarkdown($markdown);
        $bodyHtmlPreview = ReplyHtmlBuilder::fromMarkdownDraft($markdown);

        $to = $frontmatter['to'] ?? $this->extractSectionLine($markdown, 'To');
        $cc = $frontmatter['cc'] ?? $this->extractSectionLine($markdown, 'CC');
        $language = $frontmatter['language'] ?? 'en';
        $mtime = @filemtime($draftPath);

        return [
            'filename'          => $draftFilename,
            'path'              => $draftPath,
            'created_at'        => $mtime ? date('c', $mtime) : null,
            'to'                => $to,
            'cc'                => $cc,
            'language'          => $language,
            'subject'           => $this->extractSectionLine($markdown, 'Subject'),
            'body_markdown'     => $bodyMd,
            'body_html_preview' => $bodyHtmlPreview,
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
}

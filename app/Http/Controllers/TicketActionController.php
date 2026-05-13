<?php

namespace App\Http\Controllers;

use App\Services\FreshServiceClient;
use App\Services\ReplyHtmlBuilder;
use App\Services\TicketFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * TicketActionController — inline ticket actions invoked from the dashboard.
 *
 * Phase 3: `sendReply` is fully implemented per the `freshservice-send-reply`
 * skill. `rejectDraft` is implemented in the parallel phase 4 work. The
 * remaining four actions are still 405 stubs.
 *
 * Authorization: per the user's "anyone can do everything" decision, no auth
 * middleware is attached. May be revisited later.
 */
class TicketActionController extends Controller
{
    /**
     * Sections where a draft can legitimately be rejected — i.e. one already
     * exists or is being produced.
     */
    private const REJECTABLE_SECTIONS = [
        'Ready to Send',
        'Security Check',
        'Reply Drafting',
    ];

    private const SEND_REPLY_REQUIRED_SECTION = 'Ready to Send';
    private const SEND_REPLY_TARGET_SECTION = 'Replied — Awaiting Customer';
    private const FS_RETRY_DELAY_SECONDS = 10;

    /**
     * POST `/api/tickets/{id}/send-reply` — send a public customer reply via FS.
     *
     * Follows the canonical `freshservice-send-reply` skill procedure:
     *   1. Locate the parent ticket file.
     *   2. Verify the ticket is currently in `## Ready to Send`.
     *   3. Locate the reply-draft sibling file.
     *   4. Validate `to` matches the parent ticket's `**Requester:**`.
     *   5. Idempotency: if Timeline already records this draft as sent,
     *      return `already_sent`.
     *   6. Convert markdown body → HTML.
     *   7. POST to FreshService (one retry after 10s on failure).
     *   8. Append Timeline entry on parent ticket.
     *   9. Move ticket to `## Replied — Awaiting Customer` (best-effort).
     *  10. Return `{status: 'sent', conversation_id, to}`.
     */
    public function sendReply(Request $request, string $ticketId)
    {
        try {
            $ticketFile = TicketFile::find($ticketId);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'ticket_file_error',
                'message' => $e->getMessage(),
            ], 500);
        }
        if ($ticketFile === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }

        $ticketPath = $ticketFile->getPath();
        $ticketFilename = basename($ticketPath);

        // Step 2 — section check.
        $currentSection = $this->currentSection($ticketId);
        if ($currentSection !== self::SEND_REPLY_REQUIRED_SECTION) {
            return response()->json([
                'error' => 'wrong_section',
                'current' => $currentSection,
            ], 409);
        }

        // Step 3 — locate the reply-draft sibling file.
        $driveDir = dirname($ticketPath);
        $datePrefix = $this->extractDatePrefix($ticketFilename);
        if ($datePrefix === null) {
            return response()->json([
                'error' => 'bad_filename',
                'filename' => $ticketFilename,
            ], 500);
        }
        $draftFilename = $datePrefix . '-T' . ltrim($ticketId, 'Tt') . '-reply-draft.md';
        $draftPath = $driveDir . '/' . $draftFilename;
        if (!is_file($draftPath)) {
            return response()->json([
                'error' => 'no_draft',
                'expected_path' => $draftPath,
            ], 404);
        }
        $draftMarkdown = @file_get_contents($draftPath);
        if ($draftMarkdown === false || $draftMarkdown === '') {
            return response()->json([
                'error' => 'no_draft',
                'expected_path' => $draftPath,
            ], 404);
        }

        // Step 4 — parse frontmatter and validate `to` matches requester.
        $frontmatter = $this->parseFrontmatter($draftMarkdown);
        $toRaw = $frontmatter['to'] ?? $this->extractSectionLine($draftMarkdown, 'To');
        $ccRaw = $frontmatter['cc'] ?? $this->extractSectionLine($draftMarkdown, 'CC');
        $language = $frontmatter['language'] ?? 'en';

        $toEmail = $this->extractEmail($toRaw);
        $ccEmails = $this->extractEmails($ccRaw);

        $meta = $ticketFile->getMetadata();
        $requesterRaw = $meta['Requester'] ?? '';
        $requesterEmail = $this->extractEmail($requesterRaw);

        if ($toEmail === '' || $requesterEmail === '' || strcasecmp($toEmail, $requesterEmail) !== 0) {
            return response()->json([
                'error' => 'requester_mismatch',
                'to' => $toEmail,
                'requester' => $requesterEmail,
            ], 422);
        }

        // Step 5 — idempotency check on Timeline.
        $timeline = $ticketFile->getSection('Timeline');
        if ($this->timelineRecordsSend($timeline, $draftFilename)) {
            return response()->json([
                'status' => 'already_sent',
                'conversation_id' => $this->extractExistingConversationId($timeline, $draftFilename),
            ], 200);
        }

        // Step 6 — convert markdown → HTML.
        $htmlBody = ReplyHtmlBuilder::fromMarkdownDraft($draftMarkdown);
        if (trim($htmlBody) === '') {
            return response()->json([
                'error' => 'empty_body',
                'message' => 'Reply draft body is empty after conversion.',
            ], 422);
        }

        // Step 7 — POST to FreshService (one bounded retry).
        /** @var FreshServiceClient $client */
        $client = app(FreshServiceClient::class);
        $numericTicketId = (int) ltrim($ticketId, 'Tt');

        $conversation = null;
        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $conversation = $client->postReply($numericTicketId, $htmlBody, $ccEmails);
                break;
            } catch (Throwable $e) {
                $lastError = $e;
                if ($attempt === 1) {
                    sleep(self::FS_RETRY_DELAY_SECONDS);
                }
            }
        }

        if (!is_array($conversation) || !isset($conversation['id'])) {
            $msg = $lastError !== null ? $lastError->getMessage() : 'no conversation id in response';
            Log::error('FreshService reply post failed', [
                'ticket_id' => $ticketId,
                'draft' => $draftFilename,
                'error' => $msg,
            ]);
            return response()->json([
                'error' => 'fs_api_failed',
                'message' => $msg,
            ], 502);
        }

        $conversationId = (int) $conversation['id'];

        // Step 8 — append Timeline entry on parent ticket.
        $timestampUtc = gmdate('Y-m-d H:i') . ' UTC';
        $timelineEntry = sprintf(
            '%s: Public reply sent to %s (FS conversation #%d). Lang: %s. Draft: ./%s.',
            $timestampUtc,
            $requesterEmail,
            $conversationId,
            $language,
            $draftFilename
        );
        try {
            $ticketFile->appendTimeline($timelineEntry);
        } catch (Throwable $e) {
            Log::warning('Failed to append Timeline entry after FS send', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        // Step 9 — move ticket section (best-effort).
        try {
            $this->moveToSection($ticketPath, self::SEND_REPLY_TARGET_SECTION);
        } catch (Throwable $e) {
            Log::warning('task-manager.py move failed after FS reply', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        // Step 10 — success response.
        return response()->json([
            'status' => 'sent',
            'conversation_id' => $conversationId,
            'to' => $requesterEmail,
        ], 200);
    }

    /**
     * Extract the `YYYYMMDD` prefix from a draft filename.
     */
    private function extractDatePrefix(string $filename): ?string
    {
        if (preg_match('/^(\d{8})-/', $filename, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Parse YAML frontmatter from a draft (between leading `---` markers).
     * Only flat scalar key:value pairs are extracted. Returns lowercase-keyed.
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
     * Read the first non-blank line of `## {section}` from a draft file.
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
     * Extract a single email address from `Name <email@host>` or `email@host`.
     */
    private function extractEmail(string $s): string
    {
        if (preg_match('/<([^>]+@[^>]+)>/', $s, $m)) {
            return strtolower(trim($m[1]));
        }
        if (preg_match('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $s, $m)) {
            return strtolower(trim($m[1]));
        }
        return '';
    }

    /**
     * Extract a list of email addresses from a comma-separated free-form string.
     * Returns empty for `—`, `_(none)_`, `(none)`, or blank.
     *
     * @return array<int, string>
     */
    private function extractEmails(string $s): array
    {
        $s = trim($s);
        if ($s === '' || $s === '—' || strcasecmp($s, '_(none)_') === 0 || strcasecmp($s, '(none)') === 0) {
            return [];
        }
        $emails = [];
        if (preg_match_all('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $s, $matches)) {
            foreach ($matches[1] as $m) {
                $emails[] = strtolower(trim($m));
            }
        }
        return array_values(array_unique($emails));
    }

    /**
     * True if the Timeline already records this draft as sent.
     */
    private function timelineRecordsSend(string $timeline, string $draftFilename): bool
    {
        if ($timeline === '') {
            return false;
        }
        $needle = 'Draft: ./' . $draftFilename;
        return str_contains($timeline, 'Public reply sent') && str_contains($timeline, $needle);
    }

    /**
     * Pull the FS conversation id from a Timeline `... (FS conversation #NNNN).
     * Draft: ./...` line if present; returns null otherwise.
     */
    private function extractExistingConversationId(string $timeline, string $draftFilename): ?int
    {
        $lines = preg_split("/\r\n|\n|\r/", $timeline);
        foreach ($lines as $line) {
            if (str_contains($line, 'Draft: ./' . $draftFilename)
                && preg_match('/FS conversation #(\d+)/', $line, $m)
            ) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Reject the proposed draft on a ticket and send it back to Reply Drafting
     * with a feedback note. The reply-drafting handler picks the feedback up
     * on section entry and produces a new draft that respects it.
     */
    public function rejectDraft(Request $request, string $ticketId)
    {
        // 1. Locate the parent ticket file.
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }

        // 2. Validate the request body.
        $validator = Validator::make($request->all(), [
            'feedback' => 'required|string|min:5|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => 'validation_failed',
                'messages' => $validator->errors()->toArray(),
            ], 422);
        }
        $feedback = trim((string) $request->input('feedback'));

        // 3. Verify the ticket is in a section that has (or is producing) a draft.
        $currentSection = $this->currentSection($ticketId);
        if (!in_array($currentSection, self::REJECTABLE_SECTIONS, true)) {
            return response()->json([
                'error' => 'wrong_section',
                'current' => $currentSection,
                'message' => 'no draft to reject from this section',
            ], 409);
        }

        $timestamp = gmdate('Y-m-d H:i') . ' UTC';

        // 4. Set the **Drafter Feedback:** metadata line.
        $file->setMetadataLine(
            'Drafter Feedback',
            $timestamp . ' — ' . $feedback
        );

        // 5. Mark prior draft + security check metadata stale (preserve audit).
        // Only touch lines that already exist — the current pipeline forbids
        // (re-)creating these metadata keys, so we never insert them anew.
        $staleMarker = '_(stale — superseded by Drafter Feedback)_';
        $existingMeta = $file->getMetadata();
        foreach (['Reply Draft', 'Security Check'] as $staleKey) {
            if (array_key_exists($staleKey, $existingMeta)) {
                $file->setMetadataLine($staleKey, $staleMarker);
            }
        }

        // 6. Append a Timeline entry.
        $truncated = mb_strlen($feedback) > 100
            ? rtrim(mb_substr($feedback, 0, 100)) . '...'
            : $feedback;
        $file->appendTimeline(
            $timestamp . ': Draft rejected by reviewer. Feedback: ' . $truncated
            . '. Moving back to Reply Drafting.'
        );

        // 7. Move the ticket back to Reply Drafting via task-manager.py.
        $this->moveToSection($file->getPath(), 'Reply Drafting');

        // 8. Success response.
        return response()->json([
            'status' => 'rejected',
            'section' => 'Reply Drafting',
            'feedback' => $feedback,
        ]);
    }

    public function addSubtask(Request $request, string $ticketId)
    {
        return response()->json(['error' => 'not_yet_implemented'], 405);
    }

    public function completeConsult(Request $request, string $ticketId)
    {
        return response()->json(['error' => 'not_yet_implemented'], 405);
    }

    public function humanReview(Request $request, string $ticketId)
    {
        return response()->json(['error' => 'not_yet_implemented'], 405);
    }

    public function close(Request $request, string $ticketId)
    {
        return response()->json(['error' => 'not_yet_implemented'], 405);
    }

    /**
     * Scan the tickets task list (TICKETS-TASK-LIST.md) and return the name of
     * the `## Section` the ticket entry currently lives in, or empty string if
     * the ticket isn't found.
     *
     * Override via TICKETS_TASK_LIST_PATH env var (defaults to
     * `/shared/task-lists/TICKETS-TASK-LIST.md`).
     */
    private function currentSection(string $ticketId): string
    {
        $path = $this->tasksListPath();
        if (!is_readable($path)) {
            return '';
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return '';
        }

        $lines = preg_split("/\r\n|\n|\r/", $contents);
        $section = '';
        $needle = '[[T' . $ticketId . ']]';

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                $section = trim($m[1]);
                continue;
            }
            if (strpos($line, $needle) !== false) {
                return $section;
            }
        }
        return '';
    }

    /**
     * Shell out to task-manager.py to move the ticket entry between sections.
     */
    private function moveToSection(string $ticketFilePath, string $section): void
    {
        $tasksRoot = $this->tasksRoot();
        $relPath = $this->relativeTaskFile($ticketFilePath, $tasksRoot);
        $taskListFile = $this->tasksListBasename();

        $cmd = [
            'python3',
            $this->taskManagerPath(),
            'move',
            '--task-file', $relPath,
            '--task-list-file', $taskListFile,
            '--section', $section,
        ];

        $process = new Process($cmd);
        $process->setTimeout(30.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'task-manager.py move failed (exit ' . $process->getExitCode() . '): '
                . trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    private function taskManagerPath(): string
    {
        return env('TASK_MANAGER_PATH', '/shared/scripts/task-manager.py');
    }

    private function tasksListPath(): string
    {
        return env('TICKETS_TASK_LIST_PATH', '/shared/task-lists/TICKETS-TASK-LIST.md');
    }

    private function tasksListBasename(): string
    {
        $basename = basename($this->tasksListPath(), '.md');
        // task-manager.py's `resolve_task_list_path` accepts:
        //   - short name: 'tickets' → TICKETS-TASK-LIST.md
        //   - full filename: 'TICKETS-TASK-LIST.md' → as-is
        //   - absolute path
        // It does NOT accept the basename without `.md` (e.g. 'TICKETS-TASK-LIST'),
        // which is what `basename($path, '.md')` returns. Convert to the short
        // form by stripping the `-TASK-LIST` suffix and lowercasing — this is
        // the canonical convention used elsewhere in the codebase.
        if (str_ends_with($basename, '-TASK-LIST')) {
            return strtolower(substr($basename, 0, -strlen('-TASK-LIST')));
        }
        return $basename;
    }

    private function tasksRoot(): string
    {
        return env('TASK_LISTS_ROOT', '/shared/task-lists');
    }

    /**
     * task-manager.py expects --task-file as a path relative to /shared/task-lists/
     * (or absolute). We pass relative when the path lives under the tasks root.
     */
    private function relativeTaskFile(string $absolutePath, string $tasksRoot): string
    {
        $tasksRoot = rtrim($tasksRoot, '/');
        if (str_starts_with($absolutePath, $tasksRoot . '/')) {
            return substr($absolutePath, strlen($tasksRoot) + 1);
        }
        return $absolutePath;
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\FreshServiceClient;
use App\Services\ReplyHtmlBuilder;
use App\Services\TicketFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
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
    // Rejecting a draft is NOT gated by section — deliberately. "Reject and
    // send back" is a human deciding the draft is wrong and asking for a new
    // one with their feedback as a hard constraint. That judgement is the
    // reviewer's to make from wherever the ticket happens to sit; the pipeline
    // does not get to tell a human they may not ask for a better draft. The
    // action is safe from any section: it records the feedback, marks the old
    // draft stale, and routes to Reply Drafting — which is exactly the state a
    // rejected draft should be in regardless of where it came from.
    //
    // Sending a reply IS gated (Ready to Send) — that one is irreversible and
    // leaves our system. Rejecting is not. Only irreversible, outward-facing
    // actions get a gate.

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

        // Step 2 — section check. The pipeline normally requires the ticket to
        // have reached "Ready to Send" (i.e. passed Security Check). But the
        // operator is the final authority on whether a reply goes out: when they
        // explicitly confirm (`confirm_wrong_section`), we honour the send from
        // any section and skip the gate. Block by default with a clear message,
        // and tell the panel an override is available.
        $currentSection = $this->currentSection($ticketId);
        $forceSection = filter_var($request->input('confirm_wrong_section', false), FILTER_VALIDATE_BOOLEAN);
        if ($currentSection !== self::SEND_REPLY_REQUIRED_SECTION && !$forceSection) {
            return response()->json([
                'error' => 'wrong_section',
                'current' => $currentSection,
                'can_override' => true,
                'message' => "This ticket is in \"{$currentSection}\", not \"Ready to Send\" — it hasn't passed Security Check yet. "
                    . "You can send it anyway if you're sure.",
            ], 409);
        }
        if ($currentSection !== self::SEND_REPLY_REQUIRED_SECTION && $forceSection) {
            Log::info('sendReply: section gate overridden by operator', [
                'ticket_id' => $ticketId,
                'current_section' => $currentSection,
            ]);
        }

        // Step 3 — locate the reply-draft sibling file.
        //
        // The drafter writes round 1 to `-reply-draft.md` and every subsequent
        // round to `-reply-draft-{NN}.md` (NN = 02, 03, …). Pick the latest
        // existing round so a follow-up send targets the latest unsent draft,
        // not a stale earlier-round file (which may still exist as audit
        // history). Mirror logic in `TicketsController::loadReplyDraft`.
        $driveDir = dirname($ticketPath);
        $datePrefix = $this->extractDatePrefix($ticketFilename);
        if ($datePrefix === null) {
            return response()->json([
                'error' => 'bad_filename',
                'filename' => $ticketFilename,
            ], 500);
        }
        $base = $datePrefix . '-T' . ltrim($ticketId, 'Tt') . '-reply-draft';
        $candidates = glob($driveDir . '/' . $base . '*.md') ?: [];
        $latest = null;
        $latestRound = 0;
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
            return response()->json([
                'error' => 'no_draft',
                'expected_path' => $driveDir . '/' . $base . '*.md',
            ], 404);
        }
        $draftPath = $latest;
        $draftFilename = basename($latest);
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

        // CC override — the panel lets the operator edit / add CC recipients
        // before sending. When the request carries a `cc` field we use it
        // verbatim (a present-but-empty value means "send with no CC"); when
        // the field is absent we keep the draft's recorded CC list. Each
        // address is validated so a typo is reported back rather than silently
        // dropped.
        if ($request->has('cc')) {
            $ccInput = $request->input('cc');
            $ccInputRaw = is_array($ccInput) ? implode(', ', $ccInput) : (string) $ccInput;
            $tokens = preg_split('/[,;\n]+/', $ccInputRaw) ?: [];
            $invalid = [];
            $ccEmails = [];
            foreach ($tokens as $tok) {
                $tok = trim($tok);
                if ($tok === '') {
                    continue;
                }
                $email = $this->extractEmail($tok);
                if ($email === '') {
                    $invalid[] = $tok;
                } else {
                    $ccEmails[] = $email;
                }
            }
            if (!empty($invalid)) {
                return response()->json([
                    'error' => 'invalid_cc',
                    'invalid' => $invalid,
                    'message' => 'These CC addresses are not valid: ' . implode(', ', $invalid),
                ], 422);
            }
            $ccEmails = array_values(array_unique($ccEmails));
        }

        $meta = $ticketFile->getMetadata();
        $requesterRaw = $meta['Requester'] ?? '';
        $requesterEmail = $this->extractEmail($requesterRaw);

        // The draft recipient and the ticket's recorded requester differ. This
        // is a guard against accidentally sending to the wrong person — but it
        // is NOT always an error: a thread can legitimately be addressed to a
        // different participant than the recorded requester (e.g. the requester
        // contact record is wrong/internal, or staff looped a colleague in).
        // So we BLOCK by default with a clear, human-readable explanation, and
        // allow the operator to override with an explicit confirmation.
        $mismatch = $toEmail === '' || $requesterEmail === '' || strcasecmp($toEmail, $requesterEmail) !== 0;
        $confirmedMismatch = filter_var($request->input('confirm_requester_mismatch', false), FILTER_VALIDATE_BOOLEAN);
        if ($mismatch && !$confirmedMismatch) {
            $toShown = $toEmail !== '' ? $toEmail : '(none in draft)';
            $reqShown = $requesterEmail !== '' ? $requesterEmail : '(none on ticket)';
            return response()->json([
                'error' => 'requester_mismatch',
                'to' => $toEmail,
                'requester' => $requesterEmail,
                'message' => "This draft is addressed to {$toShown}, but the ticket's recorded requester is {$reqShown}. "
                    . "That can be correct (e.g. the reply goes to a different participant on the thread, or the "
                    . "requester contact record is wrong) — or it can mean the draft is going to the wrong person. "
                    . "Check the recipient above, then confirm to send anyway.",
                'can_override' => true,
            ], 422);
        }
        if ($mismatch && $confirmedMismatch) {
            Log::info('sendReply: requester mismatch overridden by operator', [
                'ticket_id' => $ticketId,
                'to' => $toEmail,
                'requester' => $requesterEmail,
            ]);
        }

        // Step 5 — idempotency check on Timeline.
        $timeline = $ticketFile->getSection('Timeline');
        if ($this->timelineRecordsSend($timeline, $draftFilename)) {
            return response()->json([
                'status' => 'already_sent',
                'conversation_id' => $this->extractExistingConversationId($timeline, $draftFilename),
            ], 200);
        }

        // Step 6 — convert markdown → HTML. An optional `body` override lets the
        // user edit the draft in the panel and send the edited text immediately —
        // no bounce back to the agent for a small tweak.
        $bodyOverride = trim((string) $request->input('body', ''));
        $edited = $bodyOverride !== '';
        $htmlBody = $edited
            ? ReplyHtmlBuilder::signedFromBodyMarkdown($bodyOverride)
            : ReplyHtmlBuilder::signedFromMarkdownDraft($draftMarkdown);
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

        // If the user edited the draft in the panel, persist the edited body to
        // the draft file so the record — and the modal's "already sent" view —
        // reflects exactly what was sent, not the stale original. Preserve the
        // frontmatter + title, replace the `>`-quoted body with the edited text.
        if ($edited) {
            $head = $draftMarkdown;
            if (preg_match('/^#\s+Reply\s+draft[^\n]*\n/im', $draftMarkdown, $tm, PREG_OFFSET_CAPTURE)) {
                $head = substr($draftMarkdown, 0, $tm[0][1] + strlen($tm[0][0]));
            }
            $quoted = implode("\n", array_map(
                static fn (string $l): string => rtrim($l) === '' ? '>' : '> ' . rtrim($l),
                explode("\n", trim($bodyOverride))
            ));
            @file_put_contents($draftPath, rtrim($head) . "\n\n" . $quoted . "\n");
        }

        // Step 8 — append Timeline entry on parent ticket.
        $timestampUtc = gmdate('Y-m-d H:i') . ' UTC';
        $timelineEntry = sprintf(
            '%s: Public reply sent to %s (FS conversation #%d). Lang: %s. Draft: ./%s.%s',
            $timestampUtc,
            $requesterEmail,
            $conversationId,
            $language,
            $draftFilename,
            $edited ? ' (manually edited in panel before send)' : ''
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

        // Step 9b — ingest the reply we just sent into `## Replies` now, so the
        // panel shows it the moment the modal closes (best-effort).
        try {
            $this->syncRepliesFromFs($ticketId);
        } catch (Throwable $e) {
            Log::warning('Immediate FS reply-sync threw after send', [
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
        // A genuine send is a SINGLE Timeline line that BOTH says "Public reply
        // sent" AND names this draft file. Testing the two substrings across the
        // whole timeline gives false positives on every follow-up reply: the
        // drafter's "Draft reply produced (round N) … Draft: ./…-NN.md" line
        // names the file, and a PRIOR draft's send already left a "Public reply
        // sent" line elsewhere — together they wrongly read as "already sent",
        // so the send is skipped and the customer never gets the reply.
        foreach (preg_split("/\r\n|\n|\r/", $timeline) as $line) {
            if (str_contains($line, 'Public reply sent') && str_contains($line, $needle)) {
                return true;
            }
        }
        return false;
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
            'feedback' => 'required|string|min:5|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => 'validation_failed',
                'messages' => $validator->errors()->toArray(),
            ], 422);
        }
        $feedback = trim((string) $request->input('feedback'));

        // 3. No section gate — see the note on SEND_REPLY_REQUIRED_SECTION above.
        // The reviewer may reject from any section; we record where it came from
        // so the Timeline shows the route the ticket actually took.
        $currentSection = $this->currentSection($ticketId);

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
            $timestamp . ': Draft rejected by reviewer (from ## ' . ($currentSection ?: 'unknown')
            . '). Feedback: ' . $truncated . '. Moving back to Reply Drafting.'
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

    /**
     * POST `/api/tickets/{id}/send-manual-reply` — send a manually-typed public
     * reply that did NOT go through the drafter / Security Check pipeline. The
     * dashboard user is the author and the implicit reviewer.
     *
     * Request: `{ body: string (markdown), cc?: string[] }`
     *
     * Flow:
     *   1. Validate ticket file + body.
     *   2. Resolve requester from ticket metadata → `to_email`.
     *   3. Render markdown body → HTML.
     *   4. POST to FreshService (one bounded retry, same policy as sendReply).
     *   5. Append Timeline entry that records this was a manual send.
     *   6. NO section move — the pipeline's section semantics are based on
     *      drafter / security flow; a manual reply is an out-of-band action
     *      and intentionally leaves the section alone. Next sync (every 2 min)
     *      ingests the reply into `## Replies`.
     */
    public function sendManualReply(Request $request, string $ticketId)
    {
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|min:2|max:50000',
            'cc'   => 'sometimes|array',
            'cc.*' => 'email',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => 'validation_failed',
                'messages' => $validator->errors()->toArray(),
            ], 422);
        }

        $bodyMd = (string) $request->input('body');
        $ccEmails = array_values(array_unique(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            $request->input('cc', [])
        )));

        $meta = $file->getMetadata();
        $requesterEmail = $this->extractEmail($meta['Requester'] ?? '');
        if ($requesterEmail === '') {
            return response()->json([
                'error' => 'no_requester',
                'message' => 'Ticket file has no resolvable Requester email.',
            ], 422);
        }

        $htmlBody = ReplyHtmlBuilder::signedFromBodyMarkdown($bodyMd);
        if (trim($htmlBody) === '') {
            return response()->json([
                'error' => 'empty_body',
                'message' => 'Body is empty after conversion.',
            ], 422);
        }

        $numericRaw = ltrim($ticketId, 'Tt');
        if ($numericRaw === '' || !ctype_digit($numericRaw)) {
            return response()->json([
                'error' => 'invalid_ticket_id',
                'ticket_id' => $ticketId,
            ], 422);
        }
        $numericId = (int) $numericRaw;

        /** @var FreshServiceClient $client */
        $client = app(FreshServiceClient::class);
        $conversation = null;
        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $conversation = $client->postReply($numericId, $htmlBody, $ccEmails);
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
            Log::error('FreshService manual reply post failed', [
                'ticket_id' => $ticketId,
                'error' => $msg,
            ]);
            return response()->json([
                'error' => 'fs_api_failed',
                'message' => $msg,
            ], 502);
        }

        $conversationId = (int) $conversation['id'];
        $timestamp = gmdate('Y-m-d H:i') . ' UTC';
        $ccTrail = empty($ccEmails) ? '' : ' CC: ' . implode(', ', $ccEmails) . '.';
        try {
            $file->appendTimeline(sprintf(
                '%s dashboard - Manual public reply sent to %s (FS conversation #%d).%s',
                $timestamp,
                $requesterEmail,
                $conversationId,
                $ccTrail
            ));
        } catch (Throwable $e) {
            Log::warning('Failed to append Timeline entry after manual FS send', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        // Ingest the reply into `## Replies` now rather than on the next
        // 2-minute poll, so the panel shows it as soon as the modal closes.
        try {
            $this->syncRepliesFromFs($ticketId);
        } catch (Throwable $e) {
            Log::warning('Immediate FS reply-sync threw after manual send', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'sent',
            'conversation_id' => $conversationId,
            'to' => $requesterEmail,
            'cc' => $ccEmails,
        ], 200);
    }

    public function addSubtask(Request $request, string $ticketId)
    {
        return response()->json(['error' => 'not_yet_implemented'], 405);
    }

    public function completeConsult(Request $request, string $ticketId)
    {
        return response()->json(['error' => 'not_yet_implemented'], 405);
    }

    /**
     * POST `/api/tickets/{id}/human-review` — manually override the ticket into
     * the `## Human Review` section so the pipeline stops auto-handling it.
     */
    public function humanReview(Request $request, string $ticketId)
    {
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }

        $currentSection = $this->currentSection($ticketId);
        if ($currentSection === 'Human Review') {
            return response()->json(['status' => 'already_in_human_review'], 200);
        }

        $reason = trim((string) $request->input('reason', ''));
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }
        if ($reason === '') {
            $reason = '(no reason given)';
        }

        $timestamp = gmdate('Y-m-d H:i') . ' UTC';
        $fromSection = $currentSection !== '' ? $currentSection : '(unknown)';
        try {
            $file->appendTimeline(
                $timestamp . ' dashboard - Manually overridden to Human Review from `## '
                . $fromSection . '`. Reason: ' . $reason . '.'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to append Timeline entry on human-review override', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        $moveWarning = null;
        try {
            $this->moveToSection($file->getPath(), 'Human Review');
        } catch (Throwable $e) {
            Log::warning('task-manager.py move failed on human-review override', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
            $moveWarning = $e->getMessage();
        }

        $response = [
            'status' => 'moved',
            'from' => $currentSection,
            'to' => 'Human Review',
        ];
        if ($moveWarning !== null) {
            $response['move_warning'] = $moveWarning;
        }
        return response()->json($response, 200);
    }

    /**
     * POST `/api/tickets/{id}/report-automation` — capture the user's feedback
     * about how the ticket automation handled THIS ticket. Phase 1: persist only,
     * nothing is changed automatically.
     *
     *   1. Load the ticket file (for context).
     *   2. Write a feedback file under tasks/automation-feedback/ containing the
     *      user's note PLUS a snapshot of how this ticket was processed (verdict
     *      + Timeline) and a static pointer to where the automations live — so
     *      whoever handles it later always has the full context.
     *   3. Register it on AUTOMATION-FEEDBACK-TASK-LIST.md § New (task-manager.py).
     *   4. Append a Timeline entry on the ticket.
     */
    public function reportAutomationIssue(Request $request, string $ticketId)
    {
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }

        $note = trim((string) $request->input('reason', ''));
        if ($note === '') {
            return response()->json(['error' => 'note_required'], 422);
        }
        if (mb_strlen($note) > 4000) {
            $note = mb_substr($note, 0, 4000);
        }

        $meta = $file->getMetadata();
        $subject = trim($file->getSection('Subject'));
        if ($subject === '') {
            $subject = (string) ($meta['description'] ?? '');
        }
        $verdict = (string) ($meta['Decomposition Verdict'] ?? '(none recorded)');
        $ticketUrl = (string) ($meta['Ticket URL'] ?? '');
        $section = $this->currentSection($ticketId);
        $timeline = rtrim($file->getSection('Timeline'));

        // Locate the latest reply-draft file at submission time. Operators
        // often file feedback BECAUSE the draft is wrong — capturing the
        // verbatim draft snapshot here means the batch processor later sees
        // exactly what the operator was looking at when they clicked the
        // button, not just the operator's prose complaint about it.
        $draftSnapshot = $this->snapshotCurrentReplyDraft($file->getPath(), $ticketId);

        $tsHuman = gmdate('Y-m-d H:i') . ' UTC';
        $safeId = preg_replace('/[^0-9A-Za-z]/', '', $ticketId);

        // Build the per-entry section that will be appended to (or create)
        // the single pending backlog file. The entry header doubles as a
        // stable id the batch processor matches against.
        $entryId = 'AF-' . gmdate('Ymd-His') . '-T' . $safeId;
        $entryMarkdown = $this->buildAutomationFeedbackEntry(
            $entryId, $ticketId, $subject, $ticketUrl, $section, $verdict,
            $timeline, $note, $tsHuman, $draftSnapshot
        );

        // Append-or-create the pending backlog file. One file at a time per
        // backlog; new submissions accumulate. After the operator processes
        // the batch and the apply step runs, the pending file is deleted by
        // the apply step and the next submission starts a fresh backlog.
        $pendingRelPath = 'tasks/automation-feedback/pending.md';
        $pendingResult = $this->appendToPendingBacklog(
            $pendingRelPath,
            $entryMarkdown,
            'AUTOMATION-FEEDBACK',
            'automation-feedback',
            $tsHuman,
            'process-automation-feedback-batch.md'
        );
        $createdFresh = $pendingResult['created_fresh'];
        $entryCount = $pendingResult['entries'];

        $listWarning = null;
        try {
            if ($createdFresh) {
                // First entry → add a single Consult § New tasks to approve
                // pointer at the pending file.
                $process = new Process([
                    'python3', $this->taskManagerPath(), 'add',
                    '--task-file', $pendingRelPath,
                    '--task-list-file', 'consult',
                    '--section', 'New tasks to approve',
                ]);
                $process->setTimeout(30.0);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
                }
            } else {
                // Subsequent entry → refresh the Consult pointer so the
                // entry count in its description updates. Remove + re-add
                // (task-manager.py reads `description:` from frontmatter at
                // add time; the pending file's frontmatter has been bumped
                // to the new count by `appendToPendingBacklog`).
                $remove = new Process([
                    'python3', $this->taskManagerPath(), 'remove',
                    '--task-file', $pendingRelPath,
                    '--task-list-file', 'consult',
                ]);
                $remove->setTimeout(30.0);
                $remove->run();
                // If the entry was already missing for some reason (race,
                // manual cleanup) the add below still puts it back.
                $add = new Process([
                    'python3', $this->taskManagerPath(), 'add',
                    '--task-file', $pendingRelPath,
                    '--task-list-file', 'consult',
                    '--section', 'New tasks to approve',
                ]);
                $add->setTimeout(30.0);
                $add->run();
                if (!$add->isSuccessful()) {
                    throw new RuntimeException(trim($add->getErrorOutput() ?: $add->getOutput()));
                }
            }
        } catch (Throwable $e) {
            Log::warning('task-manager.py sync failed for automation feedback pending backlog', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
            $listWarning = $e->getMessage();
        }

        try {
            $file->appendTimeline(
                $tsHuman . ' dashboard - Automation feedback appended to pending backlog (see '
                    . $pendingRelPath . '; ' . $entryCount . ' total).'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to append Timeline entry after automation feedback', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        $resp = ['status' => 'saved', 'file' => $pendingRelPath, 'entries' => $entryCount];
        if ($listWarning !== null) {
            $resp['list_warning'] = $listWarning;
        }
        return response()->json($resp, 200);
    }

    /**
     * POST `/api/tickets/{id}/add-knowledge-fact` — file a knowledge-base
     * suggestion produced from a ticket's context. The submitter writes only
     * the FACT body in plain prose; the downstream batch processor decides
     * title / category / keywords / slot (append / amend / new category).
     *
     * Mirrors `reportAutomationIssue` in shape: append the entry to the
     * pending KB backlog file at `tasks/knowledge-base-suggestions/pending.md`,
     * sync the single Consult § New tasks to approve pointer, and append a
     * Timeline entry on the parent ticket.
     */
    public function addKnowledgeFact(Request $request, string $ticketId)
    {
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }

        $fact = trim((string) $request->input('fact', ''));
        if ($fact === '') {
            return response()->json(['error' => 'fact_required'], 422);
        }
        if (mb_strlen($fact) > 8000) {
            $fact = mb_substr($fact, 0, 8000);
        }

        $meta = $file->getMetadata();
        $subject = trim($file->getSection('Subject'));
        if ($subject === '') {
            $subject = (string) ($meta['description'] ?? '');
        }
        $ticketUrl = (string) ($meta['Ticket URL'] ?? '');

        $tsHuman = gmdate('Y-m-d H:i') . ' UTC';
        $safeId = preg_replace('/[^0-9A-Za-z]/', '', $ticketId);

        $entryId = 'KB-' . gmdate('Ymd-His') . '-T' . $safeId;
        $entryMarkdown = $this->buildKnowledgeFactEntry(
            $entryId, $ticketId, $subject, $ticketUrl, $fact, $tsHuman
        );

        $pendingRelPath = 'tasks/knowledge-base-suggestions/pending.md';
        $pendingResult = $this->appendToPendingBacklog(
            $pendingRelPath,
            $entryMarkdown,
            'KNOWLEDGE-BASE-SUGGESTIONS',
            'knowledge-base-suggestions',
            $tsHuman,
            'process-knowledge-base-suggestions-batch.md'
        );
        $createdFresh = $pendingResult['created_fresh'];
        $entryCount = $pendingResult['entries'];

        $listWarning = null;
        try {
            if ($createdFresh) {
                $process = new Process([
                    'python3', $this->taskManagerPath(), 'add',
                    '--task-file', $pendingRelPath,
                    '--task-list-file', 'consult',
                    '--section', 'New tasks to approve',
                ]);
                $process->setTimeout(30.0);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
                }
            } else {
                $remove = new Process([
                    'python3', $this->taskManagerPath(), 'remove',
                    '--task-file', $pendingRelPath,
                    '--task-list-file', 'consult',
                ]);
                $remove->setTimeout(30.0);
                $remove->run();
                $add = new Process([
                    'python3', $this->taskManagerPath(), 'add',
                    '--task-file', $pendingRelPath,
                    '--task-list-file', 'consult',
                    '--section', 'New tasks to approve',
                ]);
                $add->setTimeout(30.0);
                $add->run();
                if (!$add->isSuccessful()) {
                    throw new RuntimeException(trim($add->getErrorOutput() ?: $add->getOutput()));
                }
            }
        } catch (Throwable $e) {
            Log::warning('task-manager.py sync failed for knowledge-base pending backlog', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
            $listWarning = $e->getMessage();
        }

        try {
            $file->appendTimeline(
                $tsHuman . ' dashboard - Knowledge fact appended to pending backlog (see '
                    . $pendingRelPath . '; ' . $entryCount . ' total).'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to append Timeline entry after knowledge-fact filing', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        $resp = ['status' => 'saved', 'file' => $pendingRelPath, 'entries' => $entryCount];
        if ($listWarning !== null) {
            $resp['list_warning'] = $listWarning;
        }
        return response()->json($resp, 200);
    }

    /** POST `/api/automation-feedback/{id}/approve` — accept the triaged proposal. */
    public function approveFeedback(Request $request, string $id)
    {
        return $this->decideFeedback($id, 'approve', '');
    }

    /** POST `/api/automation-feedback/{id}/reject` — discard the proposal (reason optional). */
    public function rejectFeedback(Request $request, string $id)
    {
        return $this->decideFeedback($id, 'reject', trim((string) $request->input('reason', '')));
    }

    /**
     * Record an approve/reject decision on an automation-feedback entry:
     * append a `## Decision` block to the file, move it on the feedback list
     * (Approved or Done), and clear any mirrored Consult entry.
     */
    private function decideFeedback(string $id, string $decision, string $reason)
    {
        $dir = $this->tasksRoot() . '/tasks/automation-feedback';
        $found = null;
        foreach (glob($dir . '/*.md') ?: [] as $path) {
            $md = (string) @file_get_contents($path);
            if (preg_match('/^id:\s*(.+)$/mi', $md, $m) && strcasecmp(trim($m[1]), $id) === 0) { $found = $path; break; }
            if (strcasecmp(pathinfo($path, PATHINFO_FILENAME), $id) === 0) { $found = $path; break; }
        }
        if ($found === null) {
            return response()->json(['error' => 'feedback_not_found'], 404);
        }
        if (mb_strlen($reason) > 500) { $reason = mb_substr($reason, 0, 500); }

        $relPath = 'tasks/automation-feedback/' . basename($found);
        $targetSection = $decision === 'approve' ? 'Approved' : 'Done';
        $ts = gmdate('Y-m-d H:i') . ' UTC';

        $block = "\n## Decision\n\n"
            . '- **Decision:** ' . ($decision === 'approve' ? 'Approved — to implement' : 'Rejected') . "\n"
            . '- **By:** dashboard' . "\n"
            . '- **At:** ' . $ts . "\n"
            . '- **Reason:** ' . ($reason !== '' ? $reason : '—') . "\n";
        @file_put_contents($found, rtrim((string) @file_get_contents($found)) . "\n" . $block);

        $moveWarning = null;
        try {
            $this->runTaskManager(['move', '--task-file', $relPath, '--task-list-file', 'automation-feedback', '--section', $targetSection]);
        } catch (Throwable $e) {
            Log::warning('feedback decision move failed', ['id' => $id, 'error' => $e->getMessage()]);
            $moveWarning = $e->getMessage();
        }
        // Best-effort: drop the mirrored Consult entry (if triage added one).
        try {
            $this->runTaskManager(['remove', '--task-file', $relPath, '--task-list-file', 'consult']);
        } catch (Throwable $e) { /* not present — fine */ }

        $resp = ['status' => $decision === 'approve' ? 'approved' : 'rejected', 'section' => $targetSection];
        if ($moveWarning !== null) { $resp['move_warning'] = $moveWarning; }
        return response()->json($resp, 200);
    }

    /** Run task-manager.py with the given args; throw on non-zero exit. */
    private function runTaskManager(array $args): void
    {
        $process = new Process(array_merge(['python3', $this->taskManagerPath()], $args));
        $process->setTimeout(30.0);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * Render the automation-feedback file: the user's note + a snapshot of how
     * this ticket was processed + a static context pointer so any handling agent
     * always knows where the automations live and how to act on the feedback.
     */
    private function buildAutomationFeedbackFile(
        string $ticketId,
        string $subject,
        string $ticketUrl,
        string $section,
        string $verdict,
        string $timeline,
        string $note,
        string $tsHuman,
        string $id,
        string $descLine
    ): string {
        $ticketRef = $ticketUrl !== ''
            ? '[T' . $ticketId . '](' . $ticketUrl . ')'
            : 'T' . $ticketId;
        $sectionLabel = $section !== '' ? $section : '(unknown)';

        return implode("\n", [
            '---',
            'id: ' . $id,
            'description: ' . $descLine,
            'ticket: T' . $ticketId,
            'created: ' . $tsHuman,
            'status: new',
            'source: dashboard',
            '---',
            '',
            '# Automation feedback — T' . $ticketId,
            '',
            '**Ticket:** ' . $ticketRef,
            '**Filed:** ' . $tsHuman . ' (from the dashboard ticket panel)',
            '**Pipeline section when reported:** `## ' . $sectionLabel . '`',
            '**Decomposition Verdict:** ' . $verdict,
            '',
            '## What the user wants changed',
            '',
            $note,
            '',
            '## How this ticket was processed (snapshot)',
            '',
            'Subject: ' . ($subject !== '' ? $subject : '(none)'),
            '',
            'Timeline (which handlers ran, in order):',
            '',
            '```',
            $timeline !== '' ? $timeline : '(no timeline recorded)',
            '```',
            '',
            '## How to process this submission',
            '',
            '**Do NOT triage this entry alone.** Feedback entries accumulate in `## New` on '
                . '`/shared/task-lists/AUTOMATION-FEEDBACK-TASK-LIST.md` and are reviewed in BATCH '
                . 'when an operator asks for it. Batch review reads every `## New` entry collectively '
                . 'so it can spot overlapping complaints about the same handler, contradictions between '
                . 'submissions, and recurring themes — then proposes one consolidated set of instruction '
                . 'changes for approval.',
            '',
            '**Trigger:** an operator says "process the automation-feedback backlog" (or similar). Any '
                . 'agent given that instruction follows the skill at '
                . '`/shared/agents/skills/process-automation-feedback-batch.md` — read it top-to-bottom, '
                . 'then execute it.',
            '',
            '**What this file contributes to the batch:** the `## What the user wants changed` body above '
                . '(authoritative input — the operator described the issue in their own words; the batch '
                . 'processor decides which handler is responsible and what the concrete instruction change '
                . 'should be). The Timeline snapshot above is for traceability — it names which sections '
                . 'this ticket passed through, so the batch processor can map the complaint to the right '
                . 'handler.',
            '',
            '**When this file becomes Done:** the batch processor writes a `BATCH-AF-{timestamp}` file '
                . 'under `/shared/task-lists/tasks/automation-feedback-batches/` whose `## On approval` '
                . 'section lists exactly how to apply every change and mark every contributing submission '
                . 'as Done. After the operator approves the batch and the apply step runs, this file gets '
                . 'frontmatter `applied_in_batch: BATCH-AF-...` and moves to `## Done` on the feedback list.',
            '',
            '**Context for the handling agent:** the ticket automation is a pipeline of event handlers '
                . 'under `/shared/schedules/events/task-lists/TICKETS-TASK-LIST/<section>/` (decompose, '
                . 'reply-drafting, security-check, etc.), governed by '
                . '`/shared/agents/skills/automations-skill.md`. The Timeline above names which handlers '
                . 'ran. Never edit any handler instruction silently — every change goes through the '
                . 'batch-review + approval flow.',
            '',
        ]);
    }

    // ─── Auto-send arm / disarm ──────────────────────────────────────────────

    /**
     * POST `/api/tickets/{id}/arm-auto-send`
     *
     * Arm auto-send for the current reply draft. When the ticket next enters
     * `## Ready to Send`, the ready-to-send handler checks this flag and
     * triggers the send automatically if the draft filename still matches.
     */
    public function armAutoSend(Request $request, string $ticketId)
    {
        $numericId = ltrim($ticketId, 'Tt');
        if ($numericId === '' || !ctype_digit($numericId)) {
            return response()->json(['error' => 'invalid_ticket_id'], 422);
        }

        $draftFilename = $request->input('draft_filename');
        if (!$draftFilename) {
            return response()->json(['error' => 'draft_filename_required'], 422);
        }

        $dir = env('AUTO_SEND_ARMS_DIR', '/shared/state/auto-send-arms');
        @mkdir($dir, 0775, true);
        $path = $dir . '/T' . $numericId . '.json';
        file_put_contents($path, json_encode([
            'draft_filename' => $draftFilename,
            'armed_at'       => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_PRETTY_PRINT));

        return response()->json(['armed' => true, 'draft_filename' => $draftFilename]);
    }

    /**
     * POST `/api/tickets/{id}/disarm-auto-send`
     *
     * Remove the auto-send arm for this ticket (if any).
     */
    public function disarmAutoSend(Request $request, string $ticketId)
    {
        $numericId = ltrim($ticketId, 'Tt');
        if ($numericId === '' || !ctype_digit($numericId)) {
            return response()->json(['error' => 'invalid_ticket_id'], 422);
        }

        $dir = env('AUTO_SEND_ARMS_DIR', '/shared/state/auto-send-arms');
        $path = $dir . '/T' . $numericId . '.json';
        if (file_exists($path)) {
            @unlink($path);
        }

        return response()->json(['armed' => false]);
    }

    /**
     * POST `/api/tickets/{id}/internal-note` — add an internal note that lands
     * BOTH in FreshService (as a private note on the ticket) and in this
     * dashboard (ingested into `## Replies` by the sync, so it shows in the
     * conversation stream as an internal message).
     *
     * Unlike the local "operator note" scratchpad (a single mutable field, this
     * dashboard only), an internal note is append-only and immutable — it mirrors
     * exactly what a FreshService private note is. Reuses the same FS client and
     * sync plumbing as `sendReply`, minus the section gate and reply-draft.
     */
    public function addInternalNote(Request $request, string $ticketId)
    {
        $numericId = ltrim($ticketId, 'Tt');
        if ($numericId === '' || !ctype_digit($numericId)) {
            return response()->json(['error' => 'invalid_ticket_id'], 422);
        }

        $note = trim((string) $request->input('note', ''));
        if ($note === '') {
            return response()->json([
                'error'   => 'empty_note',
                'message' => 'The internal note is empty.',
            ], 422);
        }

        // Every ticket MUST have a pipeline file. Heal a missing one before
        // recording anything against it (idempotent creator).
        $ticketFile = TicketFile::find($ticketId);
        if ($ticketFile === null) {
            $this->ingestTicketFile($ticketId);
            $ticketFile = TicketFile::find($ticketId);
        }
        if ($ticketFile === null) {
            return response()->json([
                'error'   => 'ticket_file_could_not_be_created',
                'message' => 'The ticket has no pipeline file and it could not be created from FreshService (FS may be unreachable). Try again shortly.',
            ], 502);
        }

        // Private notes carry no signature — this is internal, not a customer reply.
        $htmlBody = ReplyHtmlBuilder::fromBodyMarkdown($note);
        if (trim($htmlBody) === '') {
            return response()->json([
                'error'   => 'empty_note',
                'message' => 'The internal note is empty after conversion.',
            ], 422);
        }

        // POST to FreshService (one bounded retry, same policy as sendReply).
        /** @var FreshServiceClient $client */
        $client = app(FreshServiceClient::class);
        $conversation = null;
        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $conversation = $client->postPrivateNote((int) $numericId, $htmlBody);
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
            Log::error('FreshService private-note post failed', [
                'ticket_id' => $ticketId,
                'error'     => $msg,
            ]);
            return response()->json([
                'error'   => 'fs_api_failed',
                'message' => $msg,
            ], 502);
        }

        $conversationId = (int) $conversation['id'];

        // Timeline entry on the parent ticket (best-effort).
        $timelineEntry = sprintf(
            '%s UTC: Internal note added (FS private note #%d).',
            gmdate('Y-m-d H:i'),
            $conversationId
        );
        try {
            $ticketFile->appendTimeline($timelineEntry);
        } catch (Throwable $e) {
            Log::warning('Failed to append Timeline entry after FS private note', [
                'ticket_id' => $ticketId,
                'error'     => $e->getMessage(),
            ]);
        }

        // Pull the note back into `## Replies` now, so it shows in the panel's
        // conversation stream the moment we return (best-effort).
        try {
            $this->syncRepliesFromFs($ticketId);
        } catch (Throwable $e) {
            Log::warning('Immediate FS reply-sync threw after private note', [
                'ticket_id' => $ticketId,
                'error'     => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status'          => 'added',
            'conversation_id' => $conversationId,
        ], 200);
    }

    /**
     * POST `/api/tickets/{id}/split` — split a second issue out of this ticket
     * into a brand-new, standalone FreshService ticket.
     *
     * The operator supplies a new subject + body; everything else (requester,
     * CC) is carried over from THIS ticket, so the new ticket looks exactly like
     * a fresh customer submission. The original ticket is left untouched. Both
     * tickets get a private note recording the split (audit trail). The new
     * ticket is then ingested into the pipeline (## New) like any other.
     */
    public function split(Request $request, string $ticketId)
    {
        $numericId = ltrim($ticketId, 'Tt');
        if ($numericId === '' || !ctype_digit($numericId)) {
            return response()->json(['error' => 'invalid_ticket_id'], 422);
        }

        $subject = trim((string) $request->input('subject', ''));
        $body    = trim((string) $request->input('body', ''));
        if ($subject === '') {
            return response()->json(['error' => 'empty_subject', 'message' => 'A subject for the new ticket is required.'], 422);
        }
        if ($body === '') {
            return response()->json(['error' => 'empty_body', 'message' => 'A description for the new ticket is required.'], 422);
        }

        // Every ticket MUST have a pipeline file — heal a missing one first.
        $ticketFile = TicketFile::find($ticketId);
        if ($ticketFile === null) {
            $this->ingestTicketFile($ticketId);
            $ticketFile = TicketFile::find($ticketId);
        }
        if ($ticketFile === null) {
            return response()->json([
                'error'   => 'ticket_file_could_not_be_created',
                'message' => 'The source ticket has no pipeline file and it could not be created from FreshService. Try again shortly.',
            ], 502);
        }

        $meta = $ticketFile->getMetadata();
        $requesterEmail = $this->extractEmail($meta['Requester'] ?? '');
        if ($requesterEmail === '') {
            return response()->json([
                'error'   => 'no_requester',
                'message' => "Can't split — the source ticket has no requester email to carry over.",
            ], 422);
        }
        $ccEmails = $this->extractEmails($meta['CC'] ?? '');

        $htmlDescription = ReplyHtmlBuilder::fromBodyMarkdown($body);
        if (trim($htmlDescription) === '') {
            return response()->json(['error' => 'empty_body', 'message' => 'The description is empty after conversion.'], 422);
        }

        // FreshService create-ticket payload. status=2 Open, priority=1 Low,
        // source=2 Portal — a normal inbound ticket. cc_emails carried over.
        $payload = [
            'email'       => $requesterEmail,
            'subject'     => $subject,
            'description' => $htmlDescription,
            'status'      => 2,
            'priority'    => 1,
            'source'      => 2,
        ];
        if (!empty($ccEmails)) {
            $payload['cc_emails'] = array_values($ccEmails);
        }

        // Attachments the operator chose to carry over — resolve each selected
        // filename to a real file inside THIS ticket's attachments dir (with a
        // realpath-containment guard against traversal). Only existing, contained
        // files are uploaded to the new ticket.
        $selected = $request->input('attachments', []);
        if (!is_array($selected)) {
            $selected = [];
        }
        $attachmentPaths = [];
        if (!empty($selected)) {
            $ticketDir = dirname($ticketFile->getPath());
            $datePrefix = preg_match('/^(\d{8})-/', basename($ticketFile->getPath()), $dm) ? $dm[1] : '';
            $attDirs = [$ticketDir . '/T' . $numericId . '-attachments'];
            if ($datePrefix !== '') {
                $attDirs[] = $ticketDir . '/' . $datePrefix . '-T' . $numericId . '-attachments';
            }
            foreach ($selected as $name) {
                $name = (string) $name;
                if ($name === '' || str_contains($name, '/') || str_contains($name, '..')) {
                    continue;
                }
                foreach ($attDirs as $dir) {
                    $candidate = $dir . '/' . $name;
                    if (!is_file($candidate)) {
                        continue;
                    }
                    $real = realpath($candidate);
                    $dirReal = realpath($dir);
                    if ($real !== false && $dirReal !== false
                        && str_starts_with($real, $dirReal . DIRECTORY_SEPARATOR)) {
                        $attachmentPaths[$real] = true; // dedupe
                        break;
                    }
                }
            }
        }
        $attachmentPaths = array_keys($attachmentPaths);

        /** @var FreshServiceClient $client */
        $client = app(FreshServiceClient::class);
        $created = null;
        $lastError = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $created = empty($attachmentPaths)
                    ? $client->createTicket($payload)
                    : $client->createTicketWithAttachments($payload, $attachmentPaths);
                break;
            } catch (Throwable $e) {
                $lastError = $e;
                if ($attempt === 1) {
                    sleep(self::FS_RETRY_DELAY_SECONDS);
                }
            }
        }
        if (!is_array($created) || !isset($created['id'])) {
            $msg = $lastError !== null ? $lastError->getMessage() : 'no ticket id in response';
            Log::error('FreshService ticket split (create) failed', ['source' => $ticketId, 'error' => $msg]);
            return response()->json(['error' => 'fs_api_failed', 'message' => $msg], 502);
        }
        $newId = (int) $created['id'];

        // Cross-link notes on both sides (best-effort — the split already happened).
        $origSubject = trim($meta['Subject'] ?? '');
        if ($origSubject === '') {
            $origSubject = trim($ticketFile->getSection('Subject'));
        }
        try {
            $client->postPrivateNote($newId, ReplyHtmlBuilder::fromBodyMarkdown(
                "Split from ticket #{$numericId}" . ($origSubject !== '' ? " ({$origSubject})" : '') . "."
            ));
        } catch (Throwable $e) {
            Log::warning('split: note on new ticket failed', ['new' => $newId, 'error' => $e->getMessage()]);
        }
        try {
            $client->postPrivateNote((int) $numericId, ReplyHtmlBuilder::fromBodyMarkdown(
                "Split into new ticket #{$newId}: {$subject}."
            ));
        } catch (Throwable $e) {
            Log::warning('split: note on source ticket failed', ['source' => $ticketId, 'error' => $e->getMessage()]);
        }

        // Ingest the new ticket into the pipeline (creates its file, lands in
        // ## New) so it behaves like every other ticket from here on.
        $this->ingestTicketFile('T' . $newId);

        // Record the split on the source ticket's Timeline.
        try {
            $ticketFile->appendTimeline(gmdate('Y-m-d H:i') . " UTC: Split into new ticket #{$newId} — \"{$subject}\".");
        } catch (Throwable $e) {
            Log::warning('split: timeline append failed', ['source' => $ticketId, 'error' => $e->getMessage()]);
        }

        // Build the FS agent URL for the new ticket from the source's URL pattern.
        $newFsUrl = '';
        $srcUrl = $meta['Ticket URL'] ?? '';
        if (preg_match('#^(https?://[^/]+/a/tickets/)\d+#', $srcUrl, $um)) {
            $newFsUrl = $um[1] . $newId;
        }

        return response()->json([
            'status'         => 'split',
            'new_ticket_id'  => $newId,
            'new_ticket_url' => $newFsUrl,
        ], 200);
    }

    /**
     * POST `/api/tickets/{id}/close` — backwards-compatible shorthand
     * for `setStatus` with `status = closed`.
     */
    public function close(Request $request, string $ticketId)
    {
        $request->merge(['status' => 'closed']);
        return $this->setStatus($request, $ticketId);
    }

    /**
     * POST `/api/tickets/{id}/status` — set the FreshService status of a
     * ticket to one of `open` / `pending` / `resolved` / `closed`.
     *
     * For `closed`, the ticket entry is also moved to `## Closed` on
     * `TICKETS-TASK-LIST.md` (preserves prior `close` behaviour). For the
     * other three target statuses the section is left alone — the FS status
     * change is independent of where the entry lives in our pipeline.
     */
    public function setStatus(Request $request, string $ticketId)
    {
        // ── Status name → FS status code ───────────────────────────────
        // 2=Open, 3=Pending, 4=Resolved, 5=Closed (FreshService API).
        $statusMap = [
            'open'     => 2,
            'pending'  => 3,
            'resolved' => 4,
            'closed'   => 5,
        ];

        $targetName = strtolower(trim((string) $request->input('status', '')));
        if ($targetName === '' || !isset($statusMap[$targetName])) {
            return response()->json([
                'error' => 'invalid_status',
                'allowed' => array_keys($statusMap),
            ], 422);
        }
        $targetCode = $statusMap[$targetName];
        $targetLabel = ucfirst($targetName);
        $marker = $targetName === 'closed'
            ? 'Ticket closed via dashboard'
            : 'Ticket status changed via dashboard';

        // Every ticket MUST have a pipeline file. A ticket can be visible on
        // the dashboard (it lives in the FreshService DB) yet have no file yet
        // — e.g. a GitHub-notification ticket whose OnTicketCreated ingestion
        // never ran. Rather than block the action, create the file on demand
        // via the same idempotent creator the poller uses, then retry.
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            $this->ingestTicketFile($ticketId);
            $file = TicketFile::find($ticketId);
        }
        if ($file === null) {
            // Still missing — FS was likely unreachable. Surface it so the
            // operator knows the file could not be created (not a silent skip).
            return response()->json([
                'error'   => 'ticket_file_could_not_be_created',
                'message' => 'The ticket has no pipeline file and it could not be created from FreshService (FS may be unreachable). Try again shortly.',
            ], 502);
        }

        // Section the entry currently lives in — computed once up front so it
        // is available both for the idempotent short-circuit and for the final
        // response payload. (It was previously only assigned inside the
        // idempotent branch, so the normal path threw an "undefined variable"
        // error when building the response — AFTER the FS status change and
        // section move had already happened, surfacing as a spurious HTTP 500
        // on a ticket that was in fact closed correctly.)
        $currentSection = $this->currentSection($ticketId);

        $reason = trim((string) $request->input('reason', ''));
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }
        if ($reason === '') {
            $reason = '(no reason given)';
        }

        // Strip leading T and validate digits.
        $numericRaw = ltrim($ticketId, 'Tt');
        if ($numericRaw === '' || !ctype_digit($numericRaw)) {
            return response()->json([
                'error' => 'invalid_ticket_id',
                'ticket_id' => $ticketId,
            ], 422);
        }
        $numericId = (int) $numericRaw;

        // Idempotency is based on the LIVE state on FreshService, not on
        // historical Timeline markers. (Earlier versions scanned Timeline
        // for "Ticket closed via dashboard" — but Timeline is append-only,
        // so once a ticket had been closed once it could never be closed
        // again through the dashboard, even after the customer's reply
        // reopened it on FS. T66591 hit this: closed 2026-06-11, reopened
        // by customer reply, and the dashboard refused every subsequent
        // close because the old marker was still in Timeline.) Pull the
        // current status from FS and only short-circuit when the live
        // status already equals the target.
        try {
            $liveTicket = app(FreshServiceClient::class)->get('/api/v2/tickets/' . $numericId);
        } catch (RuntimeException | ProcessFailedException $e) {
            Log::warning('FS GET failed before setStatus — proceeding without live-state check', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
            $liveTicket = null;
        }
        $currentFsCode = null;
        if (is_array($liveTicket) && isset($liveTicket['ticket']['status'])) {
            $currentFsCode = (int) $liveTicket['ticket']['status'];
        }
        if ($currentFsCode !== null && $currentFsCode === $targetCode) {
            // Already in the target state on FS — short-circuit the PUT,
            // but still drive the local section forward when target is
            // closed and our local task-list entry hasn't caught up yet
            // (otherwise a "Send to Closed" click on a ticket that's
            // already Closed on FS would silently no-op while the entry
            // sat in some non-Closed section forever).
            if ($targetName === 'closed' && $currentSection !== 'Closed') {
                try {
                    $this->moveToSection($file->getPath(), 'Closed');
                } catch (Throwable $e) {
                    Log::warning('task-manager.py move failed on idempotent close', [
                        'ticket_id' => $ticketId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            return response()->json([
                'status' => 'already_set',
                'target' => $targetName,
                'fs_status_code' => $currentFsCode,
            ], 200);
        }

        try {
            app(FreshServiceClient::class)->setStatus($numericId, $targetCode);
        } catch (RuntimeException | ProcessFailedException $e) {
            Log::error('FreshService setStatus failed', [
                'ticket_id' => $ticketId,
                'target_status' => $targetName,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'freshservice_status_change_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        $timestamp = gmdate('Y-m-d H:i') . ' UTC';
        try {
            $file->appendTimeline(
                $timestamp . ' dashboard - ' . $marker . '. '
                . 'FS status → ' . $targetCode . ' (' . $targetLabel . '). '
                . 'Reason: ' . $reason . '.'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to append Timeline entry after FS status change', [
                'ticket_id' => $ticketId,
                'target_status' => $targetName,
                'error' => $e->getMessage(),
            ]);
        }

        // Section move on status change:
        //  - Closing moves the entry to ## Closed.
        //  - REOPENING (open/pending) a ticket that is currently parked in a
        //    terminal/parked section pulls it back into the active pipeline
        //    immediately (## Customer Replied), so the operator sees it reopen
        //    right away instead of waiting for the 2-minute sync's reopen
        //    reconciliation to do it. This mirrors that sync behaviour exactly.
        //  - Resolved, or reopening a ticket already in a live section, leaves
        //    the pipeline section as-is.
        $moveWarning = null;
        $movedTo = null;
        $terminalSections = ['Closed', 'Spam', 'Informational', 'Notification'];
        if ($targetName === 'closed') {
            try {
                $this->moveToSection($file->getPath(), 'Closed');
                $movedTo = 'Closed';
            } catch (Throwable $e) {
                Log::warning('task-manager.py move failed after FS close', [
                    'ticket_id' => $ticketId,
                    'error' => $e->getMessage(),
                ]);
                $moveWarning = $e->getMessage();
            }
        } elseif (in_array($targetName, ['open', 'pending'], true)
            && in_array($currentSection, $terminalSections, true)) {
            try {
                $this->moveToSection($file->getPath(), 'Customer Replied');
                $movedTo = 'Customer Replied';
            } catch (Throwable $e) {
                Log::warning('task-manager.py move failed after FS reopen', [
                    'ticket_id' => $ticketId,
                    'error' => $e->getMessage(),
                ]);
                $moveWarning = $e->getMessage();
            }
        }

        $response = [
            'status' => 'set',
            'target' => $targetName,
            'fs_status_code' => $targetCode,
            'from_section' => $currentSection,
            'moved_to' => $movedTo,
        ];
        if ($moveWarning !== null) {
            $response['move_warning'] = $moveWarning;
        }
        return response()->json($response, 200);
    }

    /**
     * POST `/api/tickets/{id}/ai-compose` — record a free-form operator
     * instruction on the ticket and route it back through the orchestrator.
     * The existing `orchestrate.md` handler is the single brain that decides
     * what happens to a ticket — when, where, and which downstream handler
     * picks it up. We add a `**Manual Agent Request:**` line on the ticket
     * and put the ticket back in `## Awaiting Subtasks` so the orchestrator
     * fires; the orchestrator reads the operator instruction as the primary
     * driver for this run and dispatches the right action (draft a reply,
     * spawn a subtask, look up data, append a note, or any combination).
     * No dedicated lane, no separate agent — same brain, additional input.
     */
    public function aiCompose(Request $request, string $ticketId)
    {
        $file = TicketFile::find($ticketId);
        if ($file === null) {
            return response()->json(['error' => 'ticket_file_not_found'], 404);
        }

        $instructions = trim((string) $request->input('instructions', ''));
        if ($instructions === '') {
            return response()->json(['error' => 'instructions_required'], 422);
        }
        if (mb_strlen($instructions) > 4000) {
            $instructions = mb_substr($instructions, 0, 4000);
        }

        // Idempotency: if an unaddressed Manual Agent Request is already on
        // the ticket (the marker is reset to `_(addressed in this run)_` by
        // the handler when it finishes), reject a second one so we don't
        // queue concurrent agent runs racing on the same file.
        $metadata = $file->getMetadata();
        $existing = (string) ($metadata['Manual Agent Request'] ?? '');
        $existingTrim = trim($existing);
        if ($existingTrim !== ''
            && !str_starts_with($existingTrim, '_(addressed')
            && !str_starts_with($existingTrim, '_(none')
        ) {
            return response()->json([
                'status' => 'already_queued',
                'message' => 'A previous Manual Agent Request on this ticket has not been addressed yet.',
            ], 200);
        }

        // Pre-flight: the ticket MUST have an entry on the production
        // tickets task list for the move below to fire the orchestrate
        // handler. If the file lives only on a side-list (e.g. the test
        // list `TICKETS-TASK-LIST-TEST.md`), `task-manager.py move` would
        // just log "not found" and the request would sit in `_(queued)_`
        // forever — exactly what stranded T66124 for 25 minutes. Fail loud
        // here so the operator sees the problem in the dashboard toast.
        $currentSection = $this->currentSection($ticketId);
        if ($currentSection === '') {
            return response()->json([
                'error' => 'ticket_not_on_tickets_list',
                'message' => 'Ticket ' . $ticketId . ' has no entry on the production tickets task list. '
                    . 'It may have been moved to a side-list (test, archive). Restore it to '
                    . 'TICKETS-TASK-LIST.md first, then click Ask agent again.',
            ], 409);
        }

        // Write the request as a metadata line. The handler reads it from
        // here and clears it to `_(addressed in this run)_` when done, so
        // subsequent requests pass the idempotency check above.
        $ts = gmdate('Y-m-d H:i') . ' UTC';
        $value = str_replace(["\r\n", "\n"], ' ', $instructions) . " — added " . $ts . " via dashboard";
        $file->setMetadataLine('Manual Agent Request', $value);

        try {
            $file->appendTimeline(
                $ts . ' dashboard - Manual Agent Request queued. '
                . 'Instructions length: ' . mb_strlen($instructions) . ' chars.'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to append Timeline entry after AI compose request', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }

        $moveWarning = null;
        try {
            $this->moveToSection($file->getPath(), 'Awaiting Subtasks');
        } catch (Throwable $e) {
            Log::warning('task-manager.py move to Awaiting Subtasks failed', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
            $moveWarning = $e->getMessage();
        }

        $response = [
            'status' => 'queued',
            'from_section' => $currentSection,
            'instructions_chars' => mb_strlen($instructions),
        ];
        if ($moveWarning !== null) {
            $response['move_warning'] = $moveWarning;
        }
        return response()->json($response, 200);
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
    /**
     * Pull the ticket's conversations from FreshService into the ticket file
     * right now, instead of waiting for the 2-minute poller.
     *
     * `## Replies` has exactly one writer — the FS sync. We do NOT append the
     * reply we just sent ourselves: dual-writing the same block would risk
     * duplicates (the sync dedups on timestamp-minute + from-email, so a
     * send at 14:51:59 landing in FS as 14:52 would slip through as a second
     * copy) and would leave two places rendering the same format. Instead we
     * just run the one writer immediately, so a reply is in the panel by the
     * time the modal closes rather than up to 2 minutes later.
     *
     * Strictly best-effort: the reply has ALREADY left our system by the time
     * this runs, so a sync failure must never turn a successful send into an
     * error response. On failure the 2-minute poller still picks it up.
     */
    private function syncRepliesFromFs(string $ticketId): void
    {
        $process = new Process(['python3', '/shared/scripts/freshservice_ticket_sync.py', $ticketId]);
        $process->setTimeout(25.0);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::warning('Immediate FS reply-sync after send failed; the 2-minute poller will still ingest it', [
                'ticket_id' => $ticketId,
                'stderr' => $process->getErrorOutput(),
            ]);
        }
    }

    /**
     * Create the pipeline file for a ticket that exists on FreshService but
     * has no file yet. Every ticket MUST have a file — a ticket without one is
     * a gap to be healed, never a reason to skip an action. The creator script
     * is idempotent: it fetches fresh FS data + attachments and adds the ticket
     * to TICKETS-TASK-LIST § New so the pipeline picks it up.
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
            $process->setTimeout(90.0);
            $process->run();
            if (!$process->isSuccessful()) {
                Log::warning('ingestTicketFile: creator exited non-zero', [
                    'ticket_id' => $ticketId,
                    'exit'      => $process->getExitCode(),
                    'err'       => trim($process->getErrorOutput() ?: $process->getOutput()),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('ingestTicketFile: creator failed to run', [
                'ticket_id' => $ticketId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

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

    /**
     * Locate the latest reply-draft file for a ticket and return a snapshot
     * (filename + frontmatter `created_at` + body). Mirrors the numbered-draft
     * glob used by `TicketsController::loadReplyDraft` and the send action.
     * Returns null when no draft exists (ticket never had one, or it has been
     * sent and superseded with no follow-up yet).
     */
    private function snapshotCurrentReplyDraft(string $ticketPath, string $ticketId): ?array
    {
        $ticketFilename = basename($ticketPath);
        if (!preg_match('/^(\d{8})-T([0-9]+)-fs-ticket\.md$/', $ticketFilename, $m)) {
            return null;
        }
        $datePrefix = $m[1];
        $numericId = $m[2];
        $base = $datePrefix . '-T' . $numericId . '-reply-draft';
        $dir = dirname($ticketPath);
        $candidates = glob($dir . '/' . $base . '*.md') ?: [];
        $latest = null;
        $latestRound = 0;
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
        $md = @file_get_contents($latest);
        if ($md === false || $md === '') {
            return null;
        }
        // Split off frontmatter
        $createdAt = null;
        $bodyMd = $md;
        if (preg_match('/^---\n(.*?)\n---\n(.*)$/s', $md, $m2)) {
            $fm = $m2[1];
            $bodyMd = $m2[2];
            if (preg_match('/^created_at:\s*(.+)$/m', $fm, $m3)) {
                $createdAt = trim($m3[1]);
            }
        }
        // Strip the `# Reply draft — T{ID}` heading; everything below is the
        // body (typically a `> blockquoted` reply).
        $bodyMd = preg_replace('/^#\s+Reply draft.*?\n+/m', '', $bodyMd, 1);
        $bodyMd = rtrim($bodyMd);

        return [
            'filename' => basename($latest),
            'round' => $latestRound,
            'created_at' => $createdAt,
            'body_markdown' => $bodyMd,
        ];
    }

    /**
     * Compose the per-entry markdown section for one AUTOMATION-FEEDBACK
     * submission. Caller appends this to the pending backlog file.
     */
    private function buildAutomationFeedbackEntry(
        string $entryId,
        string $ticketId,
        string $subject,
        string $ticketUrl,
        string $section,
        string $verdict,
        string $timeline,
        string $note,
        string $tsHuman,
        ?array $draftSnapshot
    ): string {
        $safeId = preg_replace('/[^0-9A-Za-z]/', '', $ticketId);
        $shortNote = mb_substr(trim(preg_replace('/\s+/', ' ', $note)), 0, 90);
        $sectionLabel = $section !== '' ? $section : 'unknown';

        $md  = "## {$entryId} — T{$safeId} — {$shortNote}\n\n";
        $md .= "**Ticket:** [T{$safeId}]({$ticketUrl})\n";
        $md .= "**Subject:** " . ($subject !== '' ? $subject : '_(none)_') . "\n";
        $md .= "**Filed:** {$tsHuman} (dashboard 'Report automation issue' button)\n";
        $md .= "**Pipeline section when reported:** {$sectionLabel}\n";
        $md .= "**Decomposition Verdict:** {$verdict}\n\n";

        $md .= "### What the user wants changed\n\n";
        $md .= "{$note}\n\n";

        $md .= "### How this ticket was processed (snapshot)\n\n";
        $md .= "Timeline (which handlers ran, in order):\n\n";
        $md .= "```\n";
        $md .= ($timeline !== '' ? $timeline : '_(no timeline recorded)_') . "\n";
        $md .= "```\n\n";

        $md .= "### Reply draft at submission time\n\n";
        if ($draftSnapshot === null) {
            $md .= "_(no reply draft on this ticket at the time the operator filed)_\n\n";
        } else {
            $createdAt = $draftSnapshot['created_at'] ?? '_(unknown)_';
            $md .= "**Draft file:** `{$draftSnapshot['filename']}` (round {$draftSnapshot['round']})\n";
            $md .= "**Draft created_at:** {$createdAt}\n\n";
            $md .= "```\n";
            $md .= rtrim($draftSnapshot['body_markdown']) . "\n";
            $md .= "```\n\n";
        }

        return $md;
    }

    /**
     * Compose the per-entry markdown section for one KNOWLEDGE-BASE
     * suggestion. Caller appends this to the pending backlog file.
     */
    private function buildKnowledgeFactEntry(
        string $entryId,
        string $ticketId,
        string $subject,
        string $ticketUrl,
        string $fact,
        string $tsHuman
    ): string {
        $safeId = preg_replace('/[^0-9A-Za-z]/', '', $ticketId);
        $firstLine = trim(preg_replace('/\s+/', ' ', strtok($fact, "\n")));
        $shortLead = mb_substr($firstLine, 0, 90);

        $md  = "## {$entryId} — T{$safeId} — {$shortLead}\n\n";
        $md .= "**Ticket:** [T{$safeId}]({$ticketUrl})\n";
        $md .= "**Subject:** " . ($subject !== '' ? $subject : '_(none)_') . "\n";
        $md .= "**Filed:** {$tsHuman} (dashboard 'Add knowledge fact' button)\n\n";
        $md .= "### Fact\n\n{$fact}\n\n";
        $md .= "### Source\n\nFiled from FS dashboard on T{$safeId} by the operator handling it.\n\n";
        return $md;
    }

    /**
     * Append a new entry to the pending backlog file, or create the file
     * fresh if it doesn't exist. Returns ['created_fresh' => bool, 'entries' => int].
     *
     * The pending backlog file is the single place new submissions accumulate
     * between batch reviews. One Consult § New tasks to approve entry points
     * at it. After the operator approves the batch and the apply step runs,
     * the apply step deletes this file; the next submission starts a fresh
     * backlog.
     *
     * Concurrency: writes are wrapped in `flock(LOCK_EX)` so simultaneous
     * dashboard submissions can't tear each other's frontmatter or duplicate
     * the entry-count bump.
     */
    private function appendToPendingBacklog(
        string $pendingRelPath,
        string $entryMarkdown,
        string $domainLabel,
        string $listShortName,
        string $tsHuman,
        string $batchSkillFilename
    ): array {
        $absPath = $this->tasksRoot() . '/' . $pendingRelPath;
        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Acquire an exclusive lock on the pending file (or a sibling lock
        // if it doesn't exist yet) so simultaneous submissions serialise.
        $lockHandle = @fopen($absPath . '.lock', 'c+');
        if ($lockHandle !== false) {
            @flock($lockHandle, LOCK_EX);
        }

        try {
            $existing = is_file($absPath) ? @file_get_contents($absPath) : false;
            if (is_string($existing) && $existing !== '') {
                $createdFresh = false;
                $entries = 1;
                if (preg_match('/^entries:\s*(\d+)/m', $existing, $m)) {
                    $entries = ((int) $m[1]) + 1;
                }
                $createdTs = '';
                if (preg_match('/^created:\s*(.+)$/m', $existing, $mc)) {
                    $createdTs = trim($mc[1]);
                }

                // Update frontmatter: entries count, last_added, description.
                $updated = preg_replace('/^entries:.*$/m', 'entries: ' . $entries, $existing, 1);
                $updated = preg_replace('/^last_added:.*$/m', 'last_added: ' . $tsHuman, $updated, 1);
                $newDesc = $domainLabel . ' backlog (' . $entries . ' entries pending review)';
                $updated = preg_replace('/^description:.*$/m', 'description: ' . $newDesc, $updated, 1);

                // Update the "Entries / Newest" header lines in the body
                // (best-effort; not a hard requirement for the batch processor).
                $updated = preg_replace('/^\*\*Entries:\*\*\s*\d+/m', '**Entries:** ' . $entries, $updated, 1);
                $updated = preg_replace('/^\*\*Newest:\*\*\s*.*$/m', '**Newest:** ' . $tsHuman, $updated, 1);

                // Append the new entry section + a horizontal rule separator
                // above it (matches the in-file divider style).
                $updated = rtrim($updated) . "\n\n---\n\n" . $entryMarkdown;

                if (@file_put_contents($absPath, $updated) === false) {
                    throw new RuntimeException('write_failed');
                }
                @chmod($absPath, 0664);
                return ['created_fresh' => $createdFresh, 'entries' => $entries];
            }

            // Fresh create — first entry.
            $entries = 1;
            $createdFresh = true;
            $idSlug = strtoupper($domainLabel) . '-PENDING-BACKLOG';
            $descLine = $domainLabel . ' backlog (1 entry pending review)';

            $body  = "---\n";
            $body .= "id: {$idSlug}\n";
            $body .= "description: {$descLine}\n";
            $body .= "created: {$tsHuman}\n";
            $body .= "last_added: {$tsHuman}\n";
            $body .= "entries: 1\n";
            $body .= "status: pending-review\n";
            $body .= "---\n\n";
            $body .= "# {$domainLabel} pending backlog\n\n";
            $body .= "**Entries:** 1\n";
            $body .= "**Oldest:** {$tsHuman}\n";
            $body .= "**Newest:** {$tsHuman}\n\n";

            $body .= "## How to process this backlog\n\n";
            $body .= "**Do NOT process individual entries piecemeal.** This file is the accumulating inbox of submissions made via the dashboard. Submissions are reviewed in BATCH when the operator asks for it — reading every entry below collectively to detect overlaps, duplicates, contradictions, and themes that an entry-by-entry triage would miss.\n\n";
            $body .= "**Trigger:** an operator says \"process the " . strtolower($domainLabel) . " backlog\" (or similar). Any agent given that instruction follows the skill at `/shared/agents/skills/{$batchSkillFilename}` — read it top-to-bottom, then execute it against this file.\n\n";
            $body .= "**What the batch processor produces:** one consolidated proposal staged on `CONSULT-TASK-LIST.md` for approval. After the operator approves and the apply step runs, the apply step **deletes this pending file** + **removes the Consult pointer**. The next submission from the dashboard creates a fresh pending backlog.\n\n";
            $body .= "---\n\n";
            $body .= $entryMarkdown;

            if (@file_put_contents($absPath, $body) === false) {
                throw new RuntimeException('write_failed');
            }
            @chmod($absPath, 0664);
            return ['created_fresh' => $createdFresh, 'entries' => $entries];
        } finally {
            if ($lockHandle !== false) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        }
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

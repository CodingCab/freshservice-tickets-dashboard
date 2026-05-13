<?php

namespace Tests\Feature;

use App\Services\FreshServiceClient;
use Tests\TestCase;

/**
 * Feature test for the `Send draft` action end-to-end.
 *
 * We avoid the real FS API call (and the real /shared/task-lists/ tree) by:
 *   - Pointing TICKET_DRAFTS_DIR + TICKETS_TASK_LIST_PATH at temp paths.
 *   - Binding a fake FreshServiceClient in the container that records calls
 *     and returns a synthetic `conversation` object.
 *   - Stubbing task-manager.py with a temp shell script that records its
 *     arguments to a log file so we can assert the move was attempted.
 */
class SendReplyTest extends TestCase
{
    private string $tmpDir;
    private string $draftsDir;
    private string $tasksRoot;
    private string $tasksListPath;
    private string $ticketPath;
    private string $replyDraftPath;
    private string $taskManagerStub;
    private string $taskManagerLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/send-reply-test-' . uniqid();
        $this->tasksRoot = $this->tmpDir;
        $this->draftsDir = $this->tasksRoot . '/tasks/drafts';
        @mkdir($this->draftsDir, 0777, true);

        $this->tasksListPath = $this->tasksRoot . '/TICKETS-TASK-LIST.md';
        $this->ticketPath = $this->draftsDir . '/20260507-T66050-fs-ticket.md';
        $this->replyDraftPath = $this->draftsDir . '/20260507-T66050-reply-draft.md';

        file_put_contents($this->tasksListPath, $this->makeTaskList('Ready to Send'));
        file_put_contents($this->ticketPath, $this->makeTicketFile());
        file_put_contents($this->replyDraftPath, $this->makeReplyDraft());

        // task-manager.py stub: just appends its argv to a log file.
        $this->taskManagerLog = $this->tmpDir . '/task-manager.log';
        $this->taskManagerStub = $this->tmpDir . '/task-manager.py';
        $stub = "#!/usr/bin/env python3\nimport sys, json\nwith open(" . var_export($this->taskManagerLog, true) . ', "a") as f:' . "\n"
              . "    f.write(json.dumps(sys.argv[1:]) + \"\\n\")\n";
        file_put_contents($this->taskManagerStub, $stub);
        chmod($this->taskManagerStub, 0755);

        // Set env vars that the controller reads.
        putenv('TICKET_DRAFTS_DIR=' . $this->draftsDir);
        putenv('TICKETS_TASK_LIST_PATH=' . $this->tasksListPath);
        putenv('TASK_LISTS_ROOT=' . $this->tasksRoot);
        putenv('TASK_MANAGER_PATH=' . $this->taskManagerStub);
        $_ENV['TICKET_DRAFTS_DIR'] = $this->draftsDir;
        $_ENV['TICKETS_TASK_LIST_PATH'] = $this->tasksListPath;
        $_ENV['TASK_LISTS_ROOT'] = $this->tasksRoot;
        $_ENV['TASK_MANAGER_PATH'] = $this->taskManagerStub;
    }

    protected function tearDown(): void
    {
        putenv('TICKET_DRAFTS_DIR');
        putenv('TICKETS_TASK_LIST_PATH');
        putenv('TASK_LISTS_ROOT');
        putenv('TASK_MANAGER_PATH');
        unset($_ENV['TICKET_DRAFTS_DIR'], $_ENV['TICKETS_TASK_LIST_PATH'], $_ENV['TASK_LISTS_ROOT'], $_ENV['TASK_MANAGER_PATH']);

        if (is_dir($this->tmpDir)) {
            $this->rmrf($this->tmpDir);
        }
        parent::tearDown();
    }

    /**
     * Happy path: ticket is in Ready to Send, draft is valid, FS API returns
     * a conversation. Expect 200, Timeline append, task-manager.py invoked.
     */
    public function test_send_reply_happy_path(): void
    {
        $fake = $this->bindFakeFreshServiceClient(['id' => 4242000]);

        $response = $this->postJson('/api/tickets/66050/send-reply');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'sent',
            'conversation_id' => 4242000,
            'to' => 'requester@example.com',
        ]);

        // Recorded call to FS.
        $this->assertCount(1, $fake->calls, 'FS postReply called once');
        $this->assertSame(66050, $fake->calls[0]['ticket_id']);
        $this->assertStringContainsString('Pierwszy akapit treści.', $fake->calls[0]['html_body']);
        $this->assertStringContainsString('<br><br>', $fake->calls[0]['html_body']);
        $this->assertStringNotContainsString('<p>', $fake->calls[0]['html_body']);
        $this->assertEquals(['ccperson@example.com'], $fake->calls[0]['cc_emails']);

        // Timeline appended on parent ticket.
        $ticketContents = file_get_contents($this->ticketPath);
        $this->assertStringContainsString('Public reply sent to requester@example.com', $ticketContents);
        $this->assertStringContainsString('FS conversation #4242000', $ticketContents);
        $this->assertStringContainsString('Draft: ./20260507-T66050-reply-draft.md', $ticketContents);

        // task-manager.py invoked with the right args.
        $this->assertFileExists($this->taskManagerLog);
        $argv = json_decode(trim(file_get_contents($this->taskManagerLog)), true);
        $this->assertContains('move', $argv);
        $this->assertContains('--task-list-file', $argv);
        $this->assertContains('tickets', $argv);
        $this->assertContains('--section', $argv);
        $this->assertContains('Replied — Awaiting Customer', $argv);
        $this->assertContains('tasks/drafts/20260507-T66050-fs-ticket.md', $argv);
    }

    /**
     * Ticket not in Ready to Send → 409 wrong_section.
     */
    public function test_returns_409_when_ticket_not_in_ready_to_send(): void
    {
        file_put_contents($this->tasksListPath, $this->makeTaskList('Reply Drafting'));
        $this->bindFakeFreshServiceClient(['id' => 1]);

        $response = $this->postJson('/api/tickets/66050/send-reply');

        $response->assertStatus(409);
        $response->assertJson(['error' => 'wrong_section', 'current' => 'Reply Drafting']);
    }

    /**
     * Draft sibling file missing → 404 no_draft.
     */
    public function test_returns_404_when_draft_missing(): void
    {
        unlink($this->replyDraftPath);
        $this->bindFakeFreshServiceClient(['id' => 1]);

        $response = $this->postJson('/api/tickets/66050/send-reply');

        $response->assertStatus(404);
        $response->assertJsonFragment(['error' => 'no_draft']);
    }

    /**
     * `to` doesn't match the parent ticket's Requester → 422.
     */
    public function test_returns_422_when_requester_mismatch(): void
    {
        // Rewrite the draft with a `to:` that doesn't match requester@example.com.
        $draft = $this->makeReplyDraft();
        $draft = str_replace('to: requester@example.com', 'to: somebody.else@example.com', $draft);
        file_put_contents($this->replyDraftPath, $draft);
        $this->bindFakeFreshServiceClient(['id' => 1]);

        $response = $this->postJson('/api/tickets/66050/send-reply');

        $response->assertStatus(422);
        $response->assertJson(['error' => 'requester_mismatch']);
    }

    /**
     * Idempotency: Timeline already has a "Public reply sent ... Draft: ./{file}"
     * line → 200 already_sent, FS NOT called.
     */
    public function test_returns_already_sent_when_timeline_records_send(): void
    {
        $ticket = file_get_contents($this->ticketPath);
        $ticket .= "\n- 2026-05-12 10:00 UTC: Public reply sent to requester@example.com (FS conversation #1111111). Lang: en. Draft: ./20260507-T66050-reply-draft.md.\n";
        file_put_contents($this->ticketPath, $ticket);
        $fake = $this->bindFakeFreshServiceClient(['id' => 999]);

        $response = $this->postJson('/api/tickets/66050/send-reply');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'already_sent',
            'conversation_id' => 1111111,
        ]);
        $this->assertCount(0, $fake->calls, 'FS API not called on idempotent re-send');
    }

    /**
     * FS API fails both attempts → 502 fs_api_failed, ticket NOT moved.
     */
    public function test_returns_502_when_fs_api_fails(): void
    {
        $this->app->singleton(FreshServiceClient::class, function () {
            return new class extends FreshServiceClient {
                public function postReply(int $ticketId, string $htmlBody, array $ccEmails = []): array
                {
                    throw new \RuntimeException('FS upstream 500');
                }
            };
        });

        // Shrink the retry sleep to keep the test fast — the controller's
        // `sleep(self::FS_RETRY_DELAY_SECONDS)` is 10s by default; we can't
        // override the const without subclassing, so accept the 10s pause.
        // (Marking the test slow is acceptable here for safety coverage.)

        $response = $this->postJson('/api/tickets/66050/send-reply');

        $response->assertStatus(502);
        $response->assertJsonFragment(['error' => 'fs_api_failed']);

        // task-manager.py must NOT have been invoked.
        $this->assertFileDoesNotExist($this->taskManagerLog);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Bind a fake FreshServiceClient that returns the given conversation
     * payload and records every call.
     */
    private function bindFakeFreshServiceClient(array $conversation): object
    {
        $fake = new class($conversation) extends FreshServiceClient {
            public array $calls = [];
            private array $conversation;
            public function __construct(array $conversation)
            {
                $this->conversation = $conversation;
            }
            public function postReply(int $ticketId, string $htmlBody, array $ccEmails = []): array
            {
                $this->calls[] = [
                    'ticket_id' => $ticketId,
                    'html_body' => $htmlBody,
                    'cc_emails' => $ccEmails,
                ];
                return $this->conversation;
            }
        };
        $this->app->instance(FreshServiceClient::class, $fake);
        return $fake;
    }

    private function makeTaskList(string $section): string
    {
        return <<<MD
# TICKETS

## New

## Reply Drafting

## {$section}

- [ ] [[T66050]](tasks/drafts/20260507-T66050-fs-ticket.md) | **TICKET** FS #66050 - test

## Replied — Awaiting Customer

## Closed
MD;
    }

    private function makeTicketFile(): string
    {
        return <<<MD
---
id: T66050
labels: TICKET
description: FreshService Ticket #66050 - test
---

# Ticket 66050

**Domain:** Other
**Category:** Bug
**Priority:** Medium

**Client:** Acme
**Requester:** Test Requester <requester@example.com>
**CC:** ccperson@example.com
**Ticket URL:** https://example.freshservice.com/a/tickets/66050
**Status:** Open

## Subject

Test ticket

## Body

A customer body.

## Replies

_(none yet)_

## Subtasks

_(none yet)_

## Timeline

- 2026-05-07T13:18:02Z - Ticket received from FreshService
MD;
    }

    private function makeReplyDraft(): string
    {
        return <<<MD
---
ticket: T66050
status: Draft (awaiting review)
drafted_by: claude
drafted_at: 2026-05-07 14:00 UTC
language: pl
to: requester@example.com
cc: ccperson@example.com
---

# Reply Draft — T66050

## To

Test Requester <requester@example.com>

## CC

ccperson@example.com

## Subject

Re: Test ticket

## Body

Dzień dobry,

Pierwszy akapit treści.

Drugi akapit treści.

Pozdrawiam,
Adam
MD;
    }

    private function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->rmrf($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

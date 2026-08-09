<?php

namespace Tests\Feature;

use Tests\TestCase;

class RejectDraftTest extends TestCase
{
    private string $draftsDir;
    private string $tasksListPath;
    private string $tasksRoot;
    private string $taskManagerStub;
    private string $taskManagerLog;
    private string $ticketId = '99001';

    protected function setUp(): void
    {
        parent::setUp();

        // Build an isolated tasks tree under sys_get_temp_dir().
        $this->tasksRoot = sys_get_temp_dir() . '/rejectdraft-' . uniqid();
        $this->draftsDir = $this->tasksRoot . '/tasks/drafts';
        mkdir($this->draftsDir, 0777, true);

        $this->tasksListPath = $this->tasksRoot . '/TICKETS-TASK-LIST.md';
        file_put_contents(
            $this->tasksListPath,
            "# Tickets Task List\n\n## Reply Drafting\n\n## Security Check\n\n- [ ] [[T{$this->ticketId}]](tasks/drafts/20260513-T{$this->ticketId}-fs-ticket.md) | **TICKET** stub\n\n## Ready to Send\n\n## Closed\n"
        );

        // Stub ticket file with the metadata lines the controller will mark stale.
        $ticketPath = $this->draftsDir . "/20260513-T{$this->ticketId}-fs-ticket.md";
        file_put_contents($ticketPath, $this->buildTicketFixture());

        // task-manager.py stub — a python script that records its argv to a
        // log file and exits 0. The controller invokes `python3 <stub>`, so
        // the stub must be valid python.
        $this->taskManagerLog = $this->tasksRoot . '/task-manager.log';
        $this->taskManagerStub = $this->tasksRoot . '/task-manager-stub.py';
        $logPathRepr = var_export($this->taskManagerLog, true);
        file_put_contents(
            $this->taskManagerStub,
            "import sys\nwith open(" . $logPathRepr . ", 'a') as fh:\n    for arg in sys.argv[1:]:\n        fh.write(arg + '\\n')\nsys.exit(0)\n"
        );
        chmod($this->taskManagerStub, 0644);

        // Wire env so the controller picks up our fixtures.
        putenv('TICKET_DRAFTS_DIR=' . $this->draftsDir);
        putenv('TICKETS_TASK_LIST_PATH=' . $this->tasksListPath);
        putenv('TASK_LISTS_ROOT=' . $this->tasksRoot);
        putenv('TASK_MANAGER_PATH=' . $this->taskManagerStub);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tasksRoot);
        putenv('TICKET_DRAFTS_DIR');
        putenv('TICKETS_TASK_LIST_PATH');
        putenv('TASK_LISTS_ROOT');
        putenv('TASK_MANAGER_PATH');
        parent::tearDown();
    }

    public function test_reject_draft_writes_feedback_marks_stale_and_moves(): void
    {
        $feedback = 'use Pan/Pani not ty';

        $response = $this->postJson(
            "/api/tickets/{$this->ticketId}/reject-draft",
            ['feedback' => $feedback]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'rejected',
            'section' => 'Reply Drafting',
            'feedback' => $feedback,
        ]);

        $ticketPath = $this->draftsDir . "/20260513-T{$this->ticketId}-fs-ticket.md";
        $contents = file_get_contents($ticketPath);

        // Drafter feedback line added with timestamp + verbatim text.
        $this->assertMatchesRegularExpression(
            '/\*\*Drafter Feedback:\*\*\s+\d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC — ' . preg_quote($feedback, '/') . '/',
            $contents
        );

        // Prior metadata marked stale (not removed).
        $this->assertStringContainsString(
            '**Reply Draft:** _(stale — superseded by Drafter Feedback)_',
            $contents
        );
        $this->assertStringContainsString(
            '**Security Check:** _(stale — superseded by Drafter Feedback)_',
            $contents
        );

        // Timeline entry appended with the originating section and the feedback.
        $this->assertMatchesRegularExpression(
            '/- \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC: Draft rejected by reviewer \(from ## Security Check\)\. Feedback: '
                . preg_quote($feedback, '/') . '\. Moving back to Reply Drafting\./',
            $contents
        );

        // task-manager.py move was invoked with the right args.
        $this->assertFileExists($this->taskManagerLog);
        $log = file_get_contents($this->taskManagerLog);
        $this->assertStringContainsString('move', $log);
        $this->assertStringContainsString('--task-file', $log);
        $this->assertStringContainsString("tasks/drafts/20260513-T{$this->ticketId}-fs-ticket.md", $log);
        $this->assertStringContainsString('--task-list-file', $log);
        $this->assertStringContainsString('tickets', $log);
        $this->assertStringContainsString('--section', $log);
        $this->assertStringContainsString('Reply Drafting', $log);
    }

    public function test_reject_draft_404_when_ticket_file_missing(): void
    {
        $response = $this->postJson(
            '/api/tickets/77777/reject-draft',
            ['feedback' => 'something'] // 9 chars >= 5
        );
        $response->assertStatus(404);
        $response->assertJson(['error' => 'ticket_file_not_found']);
    }

    public function test_reject_draft_422_when_feedback_too_short(): void
    {
        $response = $this->postJson(
            "/api/tickets/{$this->ticketId}/reject-draft",
            ['feedback' => 'no']
        );
        $response->assertStatus(422);
        $response->assertJsonPath('error', 'validation_failed');
    }

    public function test_reject_draft_422_when_feedback_missing(): void
    {
        $response = $this->postJson(
            "/api/tickets/{$this->ticketId}/reject-draft",
            []
        );
        $response->assertStatus(422);
        $response->assertJsonPath('error', 'validation_failed');
    }

    public function test_reject_draft_422_when_feedback_too_long(): void
    {
        $response = $this->postJson(
            "/api/tickets/{$this->ticketId}/reject-draft",
            ['feedback' => str_repeat('a', 2001)]
        );
        $response->assertStatus(422);
        $response->assertJsonPath('error', 'validation_failed');
    }

    /**
     * Rejecting is not gated by section: a reviewer saying "this draft is
     * wrong, write another" is a decision the pipeline position never overrides.
     * The section the ticket came from is recorded in the Timeline instead.
     */
    public function test_reject_draft_allowed_from_any_section(): void
    {
        // Move the entry to a section that holds no draft at all.
        file_put_contents(
            $this->tasksListPath,
            "# Tickets Task List\n\n## New\n\n- [ ] [[T{$this->ticketId}]](tasks/drafts/20260513-T{$this->ticketId}-fs-ticket.md) | **TICKET** stub\n\n## Reply Drafting\n\n## Closed\n"
        );

        $response = $this->postJson(
            "/api/tickets/{$this->ticketId}/reject-draft",
            ['feedback' => 'this is some feedback']
        );
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'rejected');
        $response->assertJsonPath('section', 'Reply Drafting');

        $contents = file_get_contents($this->draftsDir . "/20260513-T{$this->ticketId}-fs-ticket.md");
        $this->assertStringContainsString(
            'Draft rejected by reviewer (from ## New).',
            $contents
        );
    }

    public function test_reject_draft_truncates_long_feedback_in_timeline(): void
    {
        $longFeedback = str_repeat('x', 150);
        $response = $this->postJson(
            "/api/tickets/{$this->ticketId}/reject-draft",
            ['feedback' => $longFeedback]
        );
        $response->assertStatus(200);

        $ticketPath = $this->draftsDir . "/20260513-T{$this->ticketId}-fs-ticket.md";
        $contents = file_get_contents($ticketPath);
        // Timeline should contain the truncated form ending in `...`
        $this->assertMatchesRegularExpression(
            '/Feedback: x{100}\.\.\./',
            $contents
        );
    }

    private function buildTicketFixture(): string
    {
        return <<<MD
# Ticket T{$this->ticketId}

**Subject:** Test ticket
**Requester:** Test User <user@example.com>
**Reply Draft:** [./20260513-T{$this->ticketId}-reply-draft.md](./20260513-T{$this->ticketId}-reply-draft.md)
**Security Check:** pass (no findings)

## Subject

Test subject

## Body

Test body

## Replies

_(none)_

## Subtasks

_(none)_

## Timeline

- 2026-05-13 09:00 UTC: Created.

MD;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

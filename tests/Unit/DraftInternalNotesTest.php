<?php

namespace Tests\Unit;

use App\Http\Controllers\TicketsController;
use App\Services\ReplyHtmlBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A reply draft carries the customer-facing reply on top and the agent's
 * internal working material below it — reviewer feedback, verification notes,
 * source-file references, tenant deployment state. None of that may ever be
 * visible in the send panel or reach a customer.
 *
 * The panel is the sharp edge: its text is what an operator edits, and an
 * edited body is posted verbatim to FreshService. So "the send path filters it
 * out" is not enough — it must not be in the box in the first place.
 *
 * Source: Robert, 2026-08-12 — T67887's draft showed its whole internal notes
 * section in the send box.
 */
class DraftInternalNotesTest extends TestCase
{
    private function panelBody(string $markdown): string
    {
        $m = new ReflectionMethod(TicketsController::class, 'extractDraftBodyMarkdown');
        $m->setAccessible(true);

        return $m->invoke(new TicketsController(), $markdown);
    }

    private function draft(string $tail): string
    {
        return <<<MD
---
parent_ticket: T1
to: customer@example.com
---

# Reply draft — T1

> Hi Paul,
>
> That's right — the columns are not on your system yet.
>
> Best,
> Adam
{$tail}
MD;
    }

    private const INTERNAL_TAIL = <<<'MD'

---

## Internal notes (not sent)

- **Reviewer feedback applied**: the retraction framing is REMOVED.
- Verified against ref `origin/feature-purchase-orders`, `Report.vue:14`.

## Knowledge gaps surfaced

- None.
MD;

    public function test_panel_shows_only_the_customer_facing_reply(): void
    {
        $body = $this->panelBody($this->draft(self::INTERNAL_TAIL));

        $this->assertSame(
            "Hi Paul,\n\nThat's right — the columns are not on your system yet.\n\nBest,\nAdam",
            $body
        );
    }

    public function test_internal_notes_inside_the_blockquote_are_stripped_too(): void
    {
        // A drafter that quoted its own notes (T67881 round 2 did exactly this)
        // must not smuggle them through the de-quoting step.
        $quotedTail = "\n>\n> ---\n>\n> ## Internal notes (not sent)\n>\n> - Verified at `routes/web.php:147`.";
        $body = $this->panelBody($this->draft($quotedTail));

        $this->assertStringNotContainsString('Internal notes', $body);
        $this->assertStringNotContainsString('routes/web.php', $body);
        $this->assertStringContainsString('Best,', $body);
    }

    public function test_blank_lines_between_paragraphs_survive(): void
    {
        $body = $this->panelBody($this->draft(self::INTERNAL_TAIL));

        $this->assertStringContainsString("yet.\n\nBest,", $body);
    }

    public function test_unquoted_draft_body_also_loses_its_internal_tail(): void
    {
        $markdown = "---\nto: c@example.com\n---\n\n# Reply draft — T2\n\n## Body\n\n"
            . "Hi Sheila,\n\nAll done.\n\nBest,\nAdam\n\n"
            . "## Internal notes\n\n- Do not show this.\n";

        $body = $this->panelBody($markdown);

        $this->assertStringContainsString('All done.', $body);
        $this->assertStringNotContainsString('Do not show this.', $body);
    }

    public function test_sending_an_unedited_draft_carries_no_internal_notes(): void
    {
        $html = ReplyHtmlBuilder::signedFromMarkdownDraft($this->draft(self::INTERNAL_TAIL));

        $this->assertStringContainsString("That's right", $html);
        $this->assertStringNotContainsString('Internal notes', $html);
        $this->assertStringNotContainsString('Reviewer feedback', $html);
        // The separator above the internal tail is not content either.
        $this->assertStringNotContainsString('---', $html);
    }

    public function test_what_the_operator_types_is_sent_verbatim(): void
    {
        // The panel is the operator's to write in. Whatever they type or paste
        // goes out as written — headings, rules, anything. Nothing filters it.
        $typed = "Hi Paul,\n\n## Summary\n\nAll set.\n\n---\n\nBest,\nAdam";

        $html = ReplyHtmlBuilder::signedFromBodyMarkdown($typed);

        $this->assertStringContainsString('## Summary', $html);
        $this->assertStringContainsString('---', $html);
        $this->assertStringContainsString('All set.', $html);
    }

    public function test_a_normal_reply_is_untouched(): void
    {
        $plain = "Hi Paul,\n\nThe report is ready.\n\nBest,\nAdam";

        $this->assertSame($plain, ReplyHtmlBuilder::stripInternalSections($plain));
    }
}

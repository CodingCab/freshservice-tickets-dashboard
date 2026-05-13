<?php

namespace Tests\Unit;

use App\Services\ReplyHtmlBuilder;
use PHPUnit\Framework\TestCase;

class ReplyHtmlBuilderTest extends TestCase
{
    public function test_canonical_pattern_from_skill(): void
    {
        $markdown = <<<MD
---
ticket: T1
language: pl
to: x@y.com
---

# Reply Draft — T1

## Body

Dzień dobry,

Pierwszy akapit treści.

Drugi akapit treści.

Pozdrawiam,
Adam
MD;

        $html = ReplyHtmlBuilder::fromMarkdownDraft($markdown);

        $this->assertSame(
            'Dzień dobry,<br><br>Pierwszy akapit treści.<br><br>Drugi akapit treści.<br><br>Pozdrawiam,<br>Adam',
            $html
        );
    }

    public function test_no_p_tags_emitted(): void
    {
        $markdown = "## Body\n\nLine one.\n\nLine two.\n";
        $html = ReplyHtmlBuilder::fromMarkdownDraft($markdown);
        $this->assertStringNotContainsString('<p>', $html);
        $this->assertStringNotContainsString('</p>', $html);
        $this->assertSame('Line one.<br><br>Line two.', $html);
    }

    public function test_blockquote_prefix_stripped(): void
    {
        // Older skill convention — body lines prefixed with `> `.
        $markdown = <<<MD
---
language: en
---

# Reply draft — T2

> Hi there,
>
> Here is the message.
>
> Best,
> Adam
MD;
        $html = ReplyHtmlBuilder::fromMarkdownDraft($markdown);
        $this->assertSame(
            'Hi there,<br><br>Here is the message.<br><br>Best,<br>Adam',
            $html
        );
    }

    public function test_signoff_lines_use_single_br(): void
    {
        $markdown = "## Body\n\nHello.\n\nPozdrawiam,\nAdam\n";
        $html = ReplyHtmlBuilder::fromMarkdownDraft($markdown);
        $this->assertSame('Hello.<br><br>Pozdrawiam,<br>Adam', $html);
    }

    public function test_empty_body_returns_empty_string(): void
    {
        $markdown = "---\nlang: en\n---\n\n# Reply Draft — T9\n\n## Body\n\n";
        $this->assertSame('', ReplyHtmlBuilder::fromMarkdownDraft($markdown));
    }

    public function test_inline_bold_and_italic(): void
    {
        $markdown = "## Body\n\nThis is **bold** and *italic* text.\n";
        $html = ReplyHtmlBuilder::fromMarkdownDraft($markdown);
        $this->assertSame('This is <strong>bold</strong> and <em>italic</em> text.', $html);
    }

    public function test_strips_other_sections_when_no_body_heading(): void
    {
        // Older skill format: blockquoted body with no `## Body` heading; the
        // ## Sources section must be excluded.
        $markdown = <<<MD
---
language: en
---

# Reply draft — T7

> Hi,
>
> Some content.

## Sources

- never include this
MD;
        $html = ReplyHtmlBuilder::fromMarkdownDraft($markdown);
        $this->assertSame('Hi,<br><br>Some content.', $html);
    }
}

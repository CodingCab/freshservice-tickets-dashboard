<?php

namespace App\Services;

/**
 * ReplyHtmlBuilder — convert a reply-draft markdown file's contents into
 * FreshService-compatible HTML.
 *
 * FreshService renders `<p>...</p>` containers as `<div>...</div>` and strips
 * margins, so a body built from `<p>` tags renders to the customer as a wall
 * of text. The canonical workaround (see `freshservice-send-reply` skill) is
 * to use explicit `<br>` tags:
 *
 *   - Paragraph break (blank line in source)        →  `<br><br>`
 *   - Soft line break inside a sign-off chunk       →  `<br>` (single)
 *   - Plain text                                    →  kept as-is
 *
 * The drafter produces files with either:
 *   (a) A `## Body` section containing plain markdown text.
 *   (b) A blockquoted body where every line starts with `> ` (the older
 *       convention described in the skill).
 *
 * We accept both: if a line begins with `> `, the prefix is stripped before
 * paragraph processing.
 */
class ReplyHtmlBuilder
{
    /**
     * Standard ShipTown signature footer, appended to every outgoing reply so
     * Adam's messages carry the same branded block as the human agents (logo +
     * ship.town + tagline). The body itself provides the "Best,/Pozdrawiam, Adam"
     * sign-off; this is only the company block. No "quick call / Calendly" line —
     * Adam does not offer calls (per the no-call-offers rule). FreshService strips
     * `<p>` margins, so the block uses `<br>` separators. Logo is the canonical
     * ship.town CDN asset (same image the human signatures use).
     */
    private const SIGNATURE_HTML =
        '<br><br>ShipTown<br>'
        . '<a href="https://ship.town">https://ship.town</a><br><br>'
        . '<img src="https://ship.town/cdn/shop/files/ShipTownLogo-Large.png?v=1731432222&amp;width=500"'
        . ' alt="ShipTown" width="170" style="display:block;border:0;margin:4px 0;"><br>'
        . '<strong>Empowering Your Business with Smarter Solutions</strong>';

    /**
     * Convert the full contents of a reply-draft markdown file into the HTML
     * body to POST to FreshService.
     */
    public static function fromMarkdownDraft(string $markdownBody): string
    {
        return self::renderBody(self::extractBody($markdownBody));
    }

    /**
     * Convert a raw body markdown (no frontmatter, no `## Body` header) into
     * the HTML body to POST to FreshService. Used by the manual-reply flow,
     * where the user types body text directly in a textarea.
     */
    public static function fromBodyMarkdown(string $bodyMarkdown): string
    {
        $text = preg_replace("/\r\n|\r/", "\n", $bodyMarkdown) ?? '';
        return self::renderBody(trim($text));
    }

    /**
     * Same as {@see fromMarkdownDraft()} but with the standard ShipTown
     * signature footer appended. Used at the outgoing-reply boundary (send +
     * preview) so every reply is signed, while the pure body converters above
     * remain signature-free. An empty body stays empty (no lone signature).
     */
    public static function signedFromMarkdownDraft(string $markdownBody): string
    {
        return self::appendSignature(self::fromMarkdownDraft($markdownBody));
    }

    /**
     * Same as {@see fromBodyMarkdown()} but with the standard ShipTown
     * signature footer appended. See {@see signedFromMarkdownDraft()}.
     */
    public static function signedFromBodyMarkdown(string $bodyMarkdown): string
    {
        return self::appendSignature(self::fromBodyMarkdown($bodyMarkdown));
    }

    private static function appendSignature(string $html): string
    {
        return $html === '' ? '' : $html . self::SIGNATURE_HTML;
    }

    private static function renderBody(string $body): string
    {
        $blocks = self::splitBlocks($body);
        if (empty($blocks)) {
            return '';
        }

        $rendered = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'code') {
                $code = self::renderCodeBlock($block['lines']);
                if ($code !== '') {
                    $rendered[] = $code;
                }
            } else {
                $rendered[] = implode('<br>', array_map([self::class, 'escapeInline'], $block['lines']));
            }
        }

        if (empty($rendered)) {
            return '';
        }

        return implode('<br><br>', $rendered);
    }

    /**
     * Extract the body portion of a draft file:
     *  - Strip YAML frontmatter (between `---` markers at the top).
     *  - Strip the leading `# Reply Draft — T{ID}` heading (any casing of
     *    "Reply Draft" / "Reply draft").
     *  - Strip any preceding section that isn't the body (e.g. `## To`,
     *    `## CC`, `## Subject`, `## Sources`).
     *  - If the body is contained under a `## Body` section, return only that
     *    section (up to the next `##`).
     *  - Otherwise, return everything after the title.
     *
     * Also: strip the leading `> ` blockquote prefix from any body line that
     * has it (the older skill convention).
     */
    private static function extractBody(string $markdown): string
    {
        $text = preg_replace("/\r\n|\r/", "\n", $markdown);

        // Strip YAML frontmatter.
        if (str_starts_with($text, "---\n")) {
            $end = strpos($text, "\n---", 4);
            if ($end !== false) {
                $text = substr($text, $end + strlen("\n---"));
                // Skip the following newline(s).
                $text = ltrim($text, "\n");
            }
        }

        // Strip leading `# Reply Draft — T{ID}` / `# Reply draft — T{ID}`
        // heading line, plus following blank lines.
        $text = preg_replace('/^#\s+Reply\s+draft.*$\n?/im', '', $text, 1);

        // Prefer the explicit `## Body` section if present.
        if (preg_match('/^##\s+Body\s*$/m', $text, $m, PREG_OFFSET_CAPTURE)) {
            $bodyStart = $m[0][1] + strlen($m[0][0]);
            $tail = substr($text, $bodyStart);
            // Trim everything from the next `## ` heading onward.
            if (preg_match('/^##\s+/m', $tail, $nm, PREG_OFFSET_CAPTURE)) {
                $tail = substr($tail, 0, $nm[0][1]);
            }
            $text = $tail;
        } else {
            // Otherwise, drop any other `## ` sections that aren't the body
            // (To/CC/Subject/Sources). Take everything before the first `## `.
            if (preg_match('/^##\s+/m', $text, $nm, PREG_OFFSET_CAPTURE)) {
                // If the FIRST `##` is something other than "Body" we
                // already handled it above. Reaching here means there's no
                // `## Body`. Take everything before the first `## `.
                $text = substr($text, 0, $nm[0][1]);
            }
        }

        // Strip blockquote prefix from each line if present.
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            if (str_starts_with($line, '> ')) {
                $line = substr($line, 2);
            } elseif ($line === '>') {
                $line = '';
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * Split the body into an ordered list of blocks — plain paragraphs and
     * code blocks. Code is detected from either a fenced block (```...```) or
     * an indented block (a run of lines indented 4+ spaces). Code blocks are
     * kept verbatim so {@see renderCodeBlock()} can HTML-escape them: any code
     * the customer must copy (e.g. an HTML/Blade label template) is then shown
     * as literal, copyable text instead of being interpreted and rendered by
     * the customer's mail client.
     *
     * @return array<int, array{type:string, lines:array<int,string>}>
     */
    private static function splitBlocks(string $body): array
    {
        $lines = explode("\n", $body);
        $n = count($lines);
        $blocks = [];
        $para = [];

        $flush = function () use (&$para, &$blocks): void {
            if (!empty($para)) {
                $blocks[] = ['type' => 'paragraph', 'lines' => $para];
                $para = [];
            }
        };

        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];

            // Fenced code block: ``` ... ```
            if (preg_match('/^\s*```/', $line)) {
                $flush();
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^\s*```/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++; // consume the closing fence (or EOF)
                $blocks[] = ['type' => 'code', 'lines' => $code];
                continue;
            }

            // Indented code block: a run of 4+ space indented lines, only when
            // starting fresh (not mid-paragraph). Blank lines inside the run
            // are kept when the block resumes afterwards.
            if (empty($para) && preg_match('/^ {4,}\S/', $line)) {
                $flush();
                $code = [];
                while ($i < $n) {
                    if (trim($lines[$i]) === '') {
                        $j = $i + 1;
                        while ($j < $n && trim($lines[$j]) === '') {
                            $j++;
                        }
                        if ($j < $n && preg_match('/^ {4,}\S/', $lines[$j])) {
                            $code[] = '';
                            $i++;
                            continue;
                        }
                        break;
                    }
                    if (!preg_match('/^ {4,}/', $lines[$i])) {
                        break;
                    }
                    $code[] = substr($lines[$i], 4); // dedent one code level
                    $i++;
                }
                $blocks[] = ['type' => 'code', 'lines' => $code];
                continue;
            }

            if (trim($line) === '') {
                $flush();
                $i++;
                continue;
            }

            $para[] = trim($line);
            $i++;
        }
        $flush();

        return $blocks;
    }

    /**
     * Render a code block as literal, copyable text. Each line is HTML-escaped
     * (so `<x-...>` shows as text rather than being interpreted by the mail
     * client), leading spaces become non-breaking spaces to preserve
     * indentation, and lines are joined with `<br>`. Wrapped in a styled
     * `<pre>` for a monospace, visually distinct block; the `<br>`/`&nbsp;`
     * formatting survives even if the mail client strips the `<pre>` styling.
     *
     * @param array<int, string> $lines
     */
    private static function renderCodeBlock(array $lines): string
    {
        // Drop leading/trailing blank lines.
        while (!empty($lines) && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while (!empty($lines) && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if (empty($lines)) {
            return '';
        }

        $rendered = array_map(static function (string $l): string {
            $escaped = htmlspecialchars($l, ENT_QUOTES, 'UTF-8');
            // Preserve leading indentation with non-breaking spaces.
            return preg_replace_callback('/^ +/', static function (array $m): string {
                return str_repeat('&nbsp;', strlen($m[0]));
            }, $escaped);
        }, $lines);

        $code = implode('<br>', $rendered);

        return '<pre style="font-family:Consolas,Monaco,&#39;Courier New&#39;,monospace;'
            . 'font-size:13px;background:#f4f4f4;border:1px solid #ddd;border-radius:4px;'
            . 'padding:10px;white-space:pre-wrap;word-break:break-word;margin:0;">'
            . $code . '</pre>';
    }

    /**
     * Apply inline markdown emphasis conversions to a single line.
     *
     * The drafter rarely uses these, but we handle them defensively:
     *   **text** → <strong>text</strong>
     *   *text*   → <em>text</em>
     *
     * The text is otherwise passed through verbatim — FreshService accepts
     * raw HTML and the drafter writes valid prose, so we deliberately do not
     * HTML-escape (which would break legitimate `&` or `<` characters in
     * URLs). The drafter's output is the trusted source.
     */
    private static function escapeInline(string $line): string
    {
        // Bold: **text**
        $line = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $line);
        // Italic: *text* (after bold so we don't double-match).
        $line = preg_replace('/(?<!\*)\*([^*\s][^*]*?)\*(?!\*)/u', '<em>$1</em>', $line);
        return $line;
    }
}

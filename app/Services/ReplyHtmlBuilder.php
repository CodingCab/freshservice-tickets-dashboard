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
     * Convert the full contents of a reply-draft markdown file into the HTML
     * body to POST to FreshService.
     */
    public static function fromMarkdownDraft(string $markdownBody): string
    {
        $body = self::extractBody($markdownBody);
        $paragraphs = self::splitParagraphs($body);

        if (empty($paragraphs)) {
            return '';
        }

        $rendered = [];
        $last = count($paragraphs) - 1;
        foreach ($paragraphs as $i => $lines) {
            // The last paragraph may be a sign-off chunk (2-3 short lines,
            // each ≤ 30 chars) — join with `<br>` (single). All other
            // multi-line paragraphs join with `<br>` too (soft line break).
            $rendered[] = implode('<br>', array_map([self::class, 'escapeInline'], $lines));
            unset($i, $last);
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
     * Split body into logical paragraphs.
     *
     * @return array<int, array<int, string>>  list of paragraphs, each is a
     *   list of (already trimmed) non-blank lines.
     */
    private static function splitParagraphs(string $body): array
    {
        $lines = explode("\n", $body);
        $paragraphs = [];
        $current = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if (!empty($current)) {
                    $paragraphs[] = $current;
                    $current = [];
                }
                continue;
            }
            $current[] = $trimmed;
        }
        if (!empty($current)) {
            $paragraphs[] = $current;
        }

        return $paragraphs;
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

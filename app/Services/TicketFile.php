<?php

namespace App\Services;

use RuntimeException;

/**
 * TicketFile — reads, parses, and mutates ticket draft files.
 *
 * Files live at `${TICKET_DRAFTS_DIR}/*-T{ID}-fs-ticket.md`.
 * Default drafts dir: /shared/task-lists/tasks/drafts.
 *
 * Every write operation acquires an exclusive `flock` on the file handle
 * (matches the Python `fcntl.flock` semantics used by sibling cron jobs).
 */
class TicketFile
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Locate the draft file for ticket `$ticketId`.
     *
     * Returns null when no match is found. Throws when >1 match — that means
     * the drafts dir has duplicate files for the same ticket, which should
     * never happen and must be fixed by hand.
     */
    public static function find(string $ticketId): ?self
    {
        $dir = self::draftsDir();
        $pattern = $dir . '/*-T' . $ticketId . '-fs-ticket.md';
        $matches = glob($pattern) ?: [];

        if (count($matches) === 0) {
            return null;
        }
        if (count($matches) > 1) {
            throw new RuntimeException(
                'Multiple ticket files match T' . $ticketId . ': ' . implode(', ', $matches)
            );
        }

        return new self($matches[0]);
    }

    /**
     * Drafts directory — overridable via TICKET_DRAFTS_DIR env var.
     */
    private static function draftsDir(): string
    {
        $dir = getenv('TICKET_DRAFTS_DIR');
        if ($dir === false || $dir === '') {
            $dir = $_ENV['TICKET_DRAFTS_DIR'] ?? null;
        }
        if (!$dir && function_exists('env')) {
            $dir = env('TICKET_DRAFTS_DIR', '/shared/task-lists/tasks/drafts');
        }
        return $dir ?: '/shared/task-lists/tasks/drafts';
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Read full file contents.
     */
    private function read(): string
    {
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ticket file: ' . $this->path);
        }
        return $contents;
    }

    /**
     * Parse `**Key:** Value` lines from the metadata block — between the
     * `# Ticket {id}` title and the first `## Subject` heading.
     *
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        $text = $this->read();
        $lines = preg_split("/\r\n|\n|\r/", $text);

        $inMeta = false;
        $meta = [];

        foreach ($lines as $line) {
            if (preg_match('/^#\s+Ticket\s+\S+/', $line)) {
                $inMeta = true;
                continue;
            }
            if ($inMeta && preg_match('/^##\s+/', $line)) {
                break;
            }
            if (!$inMeta) {
                continue;
            }
            if (preg_match('/^\*\*([^:*]+):\*\*\s*(.*)$/', $line, $m)) {
                $meta[trim($m[1])] = trim($m[2]);
            }
        }

        return $meta;
    }

    /**
     * Return the body of section `## {name}` — text from the line after the
     * heading up to (but not including) the next `##` heading or EOF.
     */
    public function getSection(string $name): string
    {
        $text = $this->read();
        $pattern = '/^##\s+' . preg_quote($name, '/') . '\s*$(.*?)(?=^##\s+|\z)/sm';
        if (!preg_match($pattern, $text, $m)) {
            return '';
        }
        return trim($m[1]);
    }

    /**
     * Parse `## Attachments` — the files the customer sent with the opening
     * message, as written by `freshservice_ticket_create.py`:
     *
     *   - Legenda.pdf — clean ([link](./T67340-attachments/Legenda.pdf))
     *   - shot.png (inline) — clean ([link](./T67340-attachments/shot.png))
     *   - macro.docm — **BLOCKED**: malware scan hit
     *   - big.zip — **DOWNLOAD FAILED**
     *
     * `inline` marks images already rendered inside the message body; a real
     * file attachment (the PDF above) has no such marker and is otherwise
     * invisible in the panel — which is why this parse exists.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttachments(): array
    {
        $section = $this->getSection('Attachments');
        if ($section === '' || trim($section) === '_(none)_') {
            return [];
        }

        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $section) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, '- ')) {
                continue;
            }
            // `- <filename>[ (inline)] — <status text>`
            // Filename may contain spaces (e.g. macOS screenshots like
            // "Zrzut ekranu 2026-07-21 o 14.26.42.png"), so match up to the
            // " — " status separator rather than to the first whitespace.
            if (!preg_match('/^-\s+(.+?)(\s+\(inline\))?\s+—\s+(.*)$/u', $line, $m)) {
                continue;
            }
            $filename = trim($m[1]);
            $inline = trim($m[2] ?? '') !== '';
            $rest = trim($m[3]);

            if (stripos($rest, '**BLOCKED**') !== false) {
                $status = 'blocked';
            } elseif (stripos($rest, '**DOWNLOAD FAILED**') !== false) {
                $status = 'download_failed';
            } elseif (stripos($rest, 'already downloaded') !== false) {
                $status = 'existing';
            } elseif (stripos($rest, 'clean') === 0) {
                $status = 'clean';
            } else {
                $status = 'unknown';
            }

            // Strip the markdown link tail so `note` carries only the reason
            // text (e.g. why it was blocked), not the plumbing.
            $note = trim(preg_replace('/\s*\(\[link\]\([^)]*\)\)\s*$/', '', $rest));
            $note = trim(str_replace(['**BLOCKED**:', '**BLOCKED**', '**DOWNLOAD FAILED**'], '', $note));

            $out[] = [
                'filename'   => $filename,
                'inline'     => $inline,
                'status'     => $status,
                'note'       => $note,
                // Only a file we actually hold is offerable for download.
                'downloadable' => in_array($status, ['clean', 'existing'], true),
            ];
        }

        return $out;
    }

    /**
     * Parse `## Replies` — each `### [n] timestamp — Name <email> (role, type)`
     * block becomes an associative array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReplies(): array
    {
        $section = $this->getSection('Replies');
        // Treat the section as empty only when its WHOLE body is the
        // `_(none)_` placeholder. A substring check is wrong here —
        // individual replies routinely contain `**Attachments:** _(none)_`,
        // which used to short-circuit the entire parse to [].
        if ($section === '' || trim($section) === '_(none)_') {
            return [];
        }

        // Split on `### [n] ...` headers.
        $parts = preg_split('/^### \[(\d+)\]\s+(.*)$/m', $section, -1, PREG_SPLIT_DELIM_CAPTURE);
        // $parts: [pre, n1, header1, body1, n2, header2, body2, ...]
        $replies = [];
        for ($i = 1; $i + 2 < count($parts) + 1; $i += 3) {
            if (!isset($parts[$i + 2])) {
                break;
            }
            $n = (int) $parts[$i];
            $header = $parts[$i + 1];
            $body = trim($parts[$i + 2]);

            $replies[] = self::parseReplyHeader($n, $header, $body);
        }

        return $replies;
    }

    /**
     * Parse a reply header line.
     *
     * Expected format examples:
     *   "2026-05-06 07:36 UTC — User #1198076 (agent, private note)"
     *   "2026-05-01 10:36 UTC — ShipTown Support <support@myshiptown.com> (agent, public reply)"
     *
     * @return array<string, mixed>
     */
    private static function parseReplyHeader(int $n, string $header, string $body): array
    {
        $timestamp = '';
        $authorName = '';
        $authorEmail = '';
        $role = '';
        $type = '';

        // Split header by em-dash (—). Accept ASCII hyphen as fallback.
        $sep = '—';
        $pos = mb_strpos($header, $sep);
        if ($pos === false) {
            // Fallback to " - " (space-hyphen-space) — rare but possible.
            $pos = mb_strpos($header, ' - ');
            if ($pos !== false) {
                $tsPart = trim(mb_substr($header, 0, $pos));
                $rest = trim(mb_substr($header, $pos + 3));
            } else {
                $tsPart = trim($header);
                $rest = '';
            }
        } else {
            $tsPart = trim(mb_substr($header, 0, $pos));
            $rest = trim(mb_substr($header, $pos + mb_strlen($sep)));
        }
        $timestamp = $tsPart;

        // Extract trailing `(role, type)` block.
        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $rest, $m)) {
            $whoPart = trim($m[1]);
            $tags = array_map('trim', explode(',', $m[2]));
            $role = $tags[0] ?? '';
            $type = $tags[1] ?? '';
        } else {
            $whoPart = $rest;
        }

        // Extract email if `Name <email>` form is used.
        if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/', $whoPart, $m)) {
            $authorName = trim($m[1]);
            $authorEmail = trim($m[2]);
        } else {
            $authorName = $whoPart;
        }

        return [
            'n' => $n,
            'timestamp' => $timestamp,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'role' => $role,
            'type' => $type,
            'body' => $body,
            'attachments' => self::parseReplyAttachments($body),
        ];
    }

    /**
     * Parse a reply's `**Attachments:**` line into a list of downloadable files.
     *
     * The sync writes each downloaded file as `name — clean ([link](./dir/name))`,
     * so we pull every markdown `[link](path)` and take the basename as the
     * filename (the attachment-serving endpoint resolves it inside the ticket's
     * attachments dir). Blocked / failed / still-pending files carry no link and
     * are intentionally not surfaced as openable. Returns [] when the reply has
     * no downloadable attachments.
     *
     * @return array<int, array{filename:string}>
     */
    private static function parseReplyAttachments(string $body): array
    {
        if (stripos($body, '**Attachments:**') === false) {
            return [];
        }
        $out = [];
        $seen = [];
        if (preg_match_all('/\[link\]\(([^)]+)\)/', $body, $mm)) {
            foreach ($mm[1] as $path) {
                $filename = basename(trim($path));
                if ($filename === '' || isset($seen[$filename])) {
                    continue;
                }
                $seen[$filename] = true;
                $out[] = ['filename' => $filename];
            }
        }
        return $out;
    }

    /**
     * Parse `## Subtasks` checklist lines.
     *
     * Expected format:
     *   - [ ] [Title here](./path.md) — kind: ask-requester · depends-on: —
     *   - [x] [Title here](./path.md) — kind: consult-robert
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSubtasks(): array
    {
        $section = $this->getSection('Subtasks');
        if ($section === '' || str_contains($section, '_(none')) {
            return [];
        }

        $subtasks = [];
        $lines = preg_split("/\r\n|\n|\r/", $section);

        foreach ($lines as $line) {
            if (!preg_match('/^-\s+\[([ xX])\]\s+\[([^\]]+)\]\(([^)]+)\)(.*)$/', $line, $m)) {
                continue;
            }
            $checked = strtolower(trim($m[1])) === 'x';
            $title = trim($m[2]);
            $path = trim($m[3]);
            $tail = trim($m[4]);

            $kind = '';
            // Kind runs until the first separator: `·` (bullet) or ` — ` (spaced em-dash)
            // or end of line. Trailing whitespace is trimmed.
            if (preg_match('/kind:\s*(.+?)(?:\s+·|\s+—|$)/u', $tail, $km)) {
                $kind = trim($km[1]);
            }

            $subtasks[] = [
                'checked' => $checked,
                'title' => $title,
                'path' => $path,
                'kind' => $kind,
            ];
        }

        return $subtasks;
    }

    /**
     * Append a `- ...` line at the end of `## Timeline`.
     *
     * The given $line should NOT include the leading "- "; it is prepended.
     * Uses `flock(LOCK_EX)` for the read-modify-write cycle.
     */
    public function appendTimeline(string $line): void
    {
        $entry = '- ' . ltrim($line, "- \t");
        $this->mutate(function (string $contents) use ($entry): string {
            return self::appendToTimelineSection($contents, $entry);
        });
    }

    /**
     * Set or insert a `**Key:** value` line in the metadata block.
     *
     * If the key exists, its line is replaced. Otherwise the line is appended
     * at the end of the metadata block (just before `## Subject`).
     */
    public function setMetadataLine(string $key, string $value): void
    {
        $this->mutate(function (string $contents) use ($key, $value): string {
            return self::upsertMetadataLine($contents, $key, $value);
        });
    }

    /**
     * Set (replace) or insert a whole `## {name}` section's body. If the
     * section exists, its body is replaced with $body. Otherwise the section
     * is inserted immediately before `## Timeline` (or appended at the end if
     * there is no Timeline section). Used for the single editable operator note.
     */
    public function setSection(string $name, string $body): void
    {
        $this->mutate(function (string $contents) use ($name, $body): string {
            $block = "## " . $name . "\n\n" . trim($body) . "\n";
            $pattern = '/^##\s+' . preg_quote($name, '/') . '\s*$.*?(?=^##\s+|\z)/sm';
            if (preg_match($pattern, $contents)) {
                // Replace existing section (keep one trailing blank line).
                return preg_replace($pattern, $block . "\n", $contents, 1);
            }
            // Insert before ## Timeline, else append at end.
            if (preg_match('/^##\s+Timeline\s*$/m', $contents, $m, PREG_OFFSET_CAPTURE)) {
                $at = (int) $m[0][1];
                return rtrim(substr($contents, 0, $at), "\n") . "\n\n" . $block . "\n" . substr($contents, $at);
            }
            return rtrim($contents, "\n") . "\n\n" . $block;
        });
    }

    /**
     * Acquire LOCK_EX on the file handle, read current contents, pass through
     * $transform, write the result back. Lock is released on close().
     *
     * @param callable(string): string $transform
     */
    private function mutate(callable $transform): void
    {
        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            throw new RuntimeException('Unable to open ticket file for writing: ' . $this->path);
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire LOCK_EX on ' . $this->path);
            }

            rewind($fh);
            $contents = stream_get_contents($fh);
            if ($contents === false) {
                throw new RuntimeException('Unable to read ticket file: ' . $this->path);
            }

            $new = $transform($contents);

            // Truncate + rewrite from offset 0.
            rewind($fh);
            ftruncate($fh, 0);
            $written = fwrite($fh, $new);
            if ($written === false) {
                throw new RuntimeException('Unable to write ticket file: ' . $this->path);
            }
            fflush($fh);
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * Insert $entry at the end of the `## Timeline` section.
     */
    private static function appendToTimelineSection(string $contents, string $entry): string
    {
        $eol = self::detectEol($contents);

        // Find `## Timeline` heading.
        if (!preg_match('/^##\s+Timeline\s*$/m', $contents, $m, PREG_OFFSET_CAPTURE)) {
            // No Timeline section — append one at the end of file.
            $suffix = (substr($contents, -1) === "\n" ? '' : $eol)
                . $eol . '## Timeline' . $eol . $eol . $entry . $eol;
            return $contents . $suffix;
        }
        $headingStart = $m[0][1];
        $headingEnd = $headingStart + strlen($m[0][0]);

        // Find the next `## ` heading after timeline (the section end).
        $tail = substr($contents, $headingEnd);
        $nextOffset = null;
        if (preg_match('/^##\s+/m', $tail, $nm, PREG_OFFSET_CAPTURE)) {
            $nextOffset = $headingEnd + $nm[0][1];
        }

        if ($nextOffset === null) {
            // Timeline is the last section. Append at EOF, ensuring trailing newline.
            $body = rtrim($contents, "\r\n");
            return $body . $eol . $entry . $eol;
        }

        // Insert entry just before the next section, preserving spacing.
        $before = substr($contents, 0, $nextOffset);
        $after = substr($contents, $nextOffset);
        $before = rtrim($before, "\r\n");
        return $before . $eol . $entry . $eol . $eol . $after;
    }

    /**
     * Replace or insert `**Key:** value` in the metadata block.
     */
    private static function upsertMetadataLine(string $contents, string $key, string $value): string
    {
        $eol = self::detectEol($contents);
        $newLine = '**' . $key . ':** ' . $value;

        // Find metadata block: from `# Ticket ...` to the first `## ` heading.
        if (!preg_match('/^#\s+Ticket\s+\S+.*$/m', $contents, $titleM, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('No `# Ticket {id}` title found in ' . $contents);
        }
        $titleEnd = $titleM[0][1] + strlen($titleM[0][0]);

        $afterTitle = substr($contents, $titleEnd);
        if (!preg_match('/^##\s+/m', $afterTitle, $nm, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('No `## ` section heading after title');
        }
        $sectionStart = $titleEnd + $nm[0][1];

        $metaBlock = substr($contents, $titleEnd, $sectionStart - $titleEnd);

        // Try to replace existing line.
        $keyPattern = '/^\*\*' . preg_quote($key, '/') . ':\*\*[^\r\n]*$/m';
        if (preg_match($keyPattern, $metaBlock)) {
            $newMeta = preg_replace($keyPattern, $newLine, $metaBlock, 1);
            return substr($contents, 0, $titleEnd) . $newMeta . substr($contents, $sectionStart);
        }

        // Insert at end of metadata block (just before `## Subject`).
        $trimmed = rtrim($metaBlock, "\r\n");
        $newMeta = $trimmed . $eol . $newLine . $eol . $eol;
        return substr($contents, 0, $titleEnd) . $newMeta . substr($contents, $sectionStart);
    }

    private static function detectEol(string $contents): string
    {
        if (str_contains($contents, "\r\n")) {
            return "\r\n";
        }
        return "\n";
    }
}

<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * Fetches FS-side HTML for a ticket's description + conversations and produces
 * a sanitized, rewritten HTML body for each, with inline-image `<img src=...>`
 * pointed at `/api/tickets/{id}/attachment/{filename}` so the dashboard can
 * render images in their original position within the text flow.
 *
 * The .md-side parser flattens reply text to paragraphs and either embeds
 * `[inline image: ...]` markers (some ingestion paths) or just lists files at
 * the bottom as `**Attachments:**` (other paths). For the second case the
 * positional info is lost — the FS HTML is the only place it's preserved.
 *
 * Filename mapping (FS attachment id → local filename) is built by scanning
 * the sibling `T{id}-attachments/` directory and extracting the trailing
 * numeric chunk before the extension, e.g.
 *   inline-40188230.png             → 40188230
 *   inline-1-40172661.png           → 40172661
 *   1234567.pdf                     → 1234567
 */
class TicketHtmlEnricher
{
    /**
     * Tag → allowed-attribute-list. Anything else gets stripped.
     */
    private const ALLOWED_TAGS = [
        'p'          => ['class'],
        'br'         => [],
        'span'       => ['class'],
        'div'        => ['class'],
        'strong'     => [],
        'b'          => [],
        'em'         => [],
        'i'          => [],
        'u'          => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'a'          => ['href', 'title', 'target', 'rel'],
        'img'        => ['src', 'alt', 'title', 'width', 'height'],
        'blockquote' => [],
        'pre'        => [],
        'code'       => [],
        'h1'         => [],
        'h2'         => [],
        'h3'         => [],
        'h4'         => [],
        'h5'         => [],
        'h6'         => [],
        'table'      => [],
        'thead'      => [],
        'tbody'      => [],
        'tr'         => [],
        'th'         => [],
        'td'         => [],
        'hr'         => [],
        'details'    => ['class', 'open'],
        'summary'    => ['class'],
    ];

    /**
     * Patterns that mark the start of an email signature / closing block.
     * Lines whose trimmed text matches any of these are treated as the
     * cutoff; everything from the matching block to the end gets folded
     * into a collapsed `<details>` element.
     */
    private const SIGNATURE_PATTERNS = [
        '/^(best\s+|kind\s+|warm\s+|with\s+)?regards[,.\s]*$/iu',
        '/^cheers[,.\s]*$/iu',
        '/^thanks(?:\s+(?:a\s+lot|so\s+much|in\s+advance))?[,.\s]*$/iu',
        '/^thank\s+you[,.\s]*$/iu',
        '/^(many\s+)?thanks(?:\s+again)?[,.\s]*$/iu',
        '/^best[,.\s]*$/iu',
        '/^sincerely(\s+yours)?[,.\s]*$/iu',
        '/^yours(\s+sincerely|\s+truly|\s+faithfully)?[,.\s]*$/iu',
        '/^pozdrawiam[,.\s]*$/iu',
        '/^pozdrawiam\s+serdecznie[,.\s]*$/iu',
        '/^z\s+powa[zż]aniem[,.\s]*$/iu',
        '/^z\s+wyrazami(\s+szacunku)?[,.\s]*$/iu',
        '/^pozdrowienia[,.\s]*$/iu',
        '/^--\s*$/u',
        '/^—\s*$/u',
        '/^__+\s*$/u',
    ];

    /**
     * Build enriched HTML for a ticket.
     *
     * @return array{
     *     description_html: ?string,
     *     conversations: array<int, array{
     *         id: int,
     *         incoming: bool,
     *         private: bool,
     *         from_email: ?string,
     *         created_at: ?string,
     *         body_html: string,
     *     }>
     * }|null  null if FS lookup failed (caller should fall back to plain text)
     */
    public function fetch(int $numericTicketId, string $ticketDir): ?array
    {
        try {
            /** @var FreshServiceClient $client */
            $client = app(FreshServiceClient::class);
            $ticketResp = $client->get('/api/v2/tickets/' . $numericTicketId);
            $convsResp  = $client->get('/api/v2/tickets/' . $numericTicketId . '/conversations');
        } catch (Throwable $e) {
            return null;
        }

        $attMap = $this->buildAttachmentMap($ticketDir, 'T' . $numericTicketId);

        $descHtml = $ticketResp['ticket']['description'] ?? null;
        $descRewritten = is_string($descHtml) && $descHtml !== ''
            ? $this->rewriteAndSanitize($descHtml, $numericTicketId, $attMap)
            : null;

        $conversations = [];
        foreach (($convsResp['conversations'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $body = $c['body'] ?? '';
            if (!is_string($body) || $body === '') {
                continue;
            }
            $conversations[] = [
                'id'         => (int) ($c['id'] ?? 0),
                'incoming'   => (bool) ($c['incoming'] ?? false),
                'private'    => (bool) ($c['private'] ?? false),
                'from_email' => isset($c['from_email']) ? strtolower((string) $c['from_email']) : null,
                'created_at' => isset($c['created_at']) ? (string) $c['created_at'] : null,
                'body_html'  => $this->rewriteAndSanitize($body, $numericTicketId, $attMap),
            ];
        }

        return [
            'description_html' => $descRewritten,
            'conversations'    => $conversations,
        ];
    }

    /**
     * Match a .md-side reply (parsed by TicketFile::getReplies) to a FS
     * conversation by from_email + created_at minute precision. Returns the
     * conversation's body_html or null if no match.
     *
     * @param array<int, array{id:int, incoming:bool, private:bool, from_email:?string, created_at:?string, body_html:string}> $conversations
     */
    public function matchReplyHtml(array $reply, array $conversations): ?string
    {
        $authorEmail = strtolower((string) ($reply['author_email'] ?? ''));
        $timestamp   = (string) ($reply['timestamp'] ?? '');
        $tsMinute    = $this->normaliseToMinute($timestamp);

        foreach ($conversations as $c) {
            $cMinute = $this->normaliseToMinute((string) ($c['created_at'] ?? ''));
            $emailMatch = $authorEmail !== '' && $c['from_email'] !== null
                && strtolower($c['from_email']) === $authorEmail;
            $tsMatch = $tsMinute !== '' && $cMinute !== '' && $tsMinute === $cMinute;
            if ($emailMatch && $tsMatch) {
                return $c['body_html'];
            }
        }
        // Fallback: if only timestamp matches and there's exactly one conv at that
        // minute, take it. (Outgoing replies from us have synthetic from_email
        // that may differ from FS's.)
        if ($tsMinute !== '') {
            $tsHits = array_filter($conversations, fn ($c) => $this->normaliseToMinute((string) ($c['created_at'] ?? '')) === $tsMinute);
            if (count($tsHits) === 1) {
                return reset($tsHits)['body_html'];
            }
        }
        return null;
    }

    private function normaliseToMinute(string $value): string
    {
        if ($value === '') {
            return '';
        }
        // .md side: "2026-05-15 08:33 UTC"
        // FS side:  "2026-05-11T13:57:53Z"
        $ts = strtotime($value);
        if ($ts === false) {
            return '';
        }
        return gmdate('Y-m-d H:i', $ts);
    }

    /**
     * Build a map of FS attachment id → local filename by scanning the
     * sibling `T{ID}-attachments/` directory (and `{datePrefix}-T{ID}-attachments/`
     * fallback). The id is the trailing numeric segment before the extension.
     *
     * @return array<int, string>
     */
    private function buildAttachmentMap(string $ticketDir, string $ticketIdPrefix): array
    {
        $map = [];
        $dirs = glob($ticketDir . '/{*-,}' . $ticketIdPrefix . '-attachments', GLOB_BRACE) ?: [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (preg_match('/(\d+)\.[A-Za-z0-9]+$/', $entry, $m)) {
                    $map[(int) $m[1]] = $entry;
                }
            }
        }
        return $map;
    }

    /**
     * Rewrite `<img>` sources to local attachment URLs and run the HTML
     * through a tag/attribute allowlist.
     *
     * @param array<int, string> $attMap
     */
    private function rewriteAndSanitize(string $html, int $numericTicketId, array $attMap): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // Wrap to force a single root + declare UTF-8.
        $wrapped = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return $this->plainTextFallback($html);
        }

        $root = $doc->documentElement;
        if ($root === null) {
            return $this->plainTextFallback($html);
        }
        $this->walkAndSanitize($doc, $root, $numericTicketId, $attMap);
        $this->foldSignature($doc, $root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /**
     * Detect an email signature / closing block and wrap it in `<details>`
     * so it renders collapsed. We walk top-level block children of the root
     * looking for the last block whose text matches a closing-remark pattern
     * (Regards, / Pozdrawiam / --- etc.); everything from that block to the
     * end becomes the collapsed signature. If no marker is found, leaves the
     * HTML untouched.
     */
    private function foldSignature(DOMDocument $doc, DOMNode $root): void
    {
        // FS often wraps the whole body in a single outer <div>; descend until
        // we find a node with multiple element children so we operate on the
        // actual sequence of paragraphs / divs.
        while (true) {
            $elemChildren = [];
            foreach ($root->childNodes as $c) {
                if ($c instanceof DOMElement) {
                    $elemChildren[] = $c;
                }
            }
            if (count($elemChildren) !== 1) {
                break;
            }
            $only = $elemChildren[0];
            $tag = strtolower($only->tagName);
            if (!in_array($tag, ['div', 'span'], true)) {
                break;
            }
            $root = $only;
        }

        $children = [];
        foreach ($root->childNodes as $c) {
            $children[] = $c;
        }
        if (count($children) < 2) {
            return;
        }

        $cutoff = -1;
        foreach ($children as $i => $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($c->tagName);
            // Hard separator: `<hr>` → fold from here.
            if ($tag === 'hr') {
                $cutoff = $i;
                continue;
            }
            if (!in_array($tag, ['p', 'div', 'span'], true)) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $c->textContent) ?? '');
            if ($text === '') {
                continue;
            }
            foreach (self::SIGNATURE_PATTERNS as $pat) {
                if (preg_match($pat, $text)) {
                    $cutoff = $i;
                    break;
                }
            }
        }

        // Require at least 1 trailing block after the closing remark, otherwise
        // there's nothing to hide (the user-visible name etc. is one line — fine).
        if ($cutoff < 0 || $cutoff >= count($children) - 1) {
            return;
        }

        $details = $doc->createElement('details');
        $details->setAttribute('class', 'td-signature');
        $summary = $doc->createElement('summary');
        $summary->setAttribute('class', 'td-signature-summary');
        $summary->appendChild($doc->createTextNode('— signature —'));
        $details->appendChild($summary);

        // Move all children from $cutoff onward into details.
        for ($i = $cutoff; $i < count($children); $i++) {
            $details->appendChild($children[$i]);
        }
        $root->appendChild($details);
    }

    private function walkAndSanitize(DOMDocument $doc, DOMNode $node, int $numericTicketId, array $attMap): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }
        // Snapshot children first — we may remove some during iteration.
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);

            // Disallowed tag → drop element entirely (and its subtree).
            if (!isset(self::ALLOWED_TAGS[$tag])) {
                // Special: <body>, <html>, <head>, <font>, <meta>, <link>, <style>, <script>:
                // for structural ones (body/html/head/font) unwrap children up; for
                // dangerous (script/style/link/meta), just drop subtree.
                if (in_array($tag, ['script', 'style', 'link', 'meta'], true)) {
                    $child->parentNode?->removeChild($child);
                    continue;
                }
                // Unwrap: move children up and remove the wrapper.
                $this->walkAndSanitize($doc, $child, $numericTicketId, $attMap);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $child->parentNode?->removeChild($child);
                continue;
            }

            // Allowed tag: strip disallowed attrs.
            $allowedAttrs = self::ALLOWED_TAGS[$tag];
            $attrs = [];
            foreach ($child->attributes as $a) {
                $attrs[] = $a->nodeName;
            }
            foreach ($attrs as $name) {
                if (!in_array(strtolower($name), $allowedAttrs, true)) {
                    $child->removeAttribute($name);
                }
            }

            if ($tag === 'img') {
                $this->rewriteImg($child, $numericTicketId, $attMap);
            }
            if ($tag === 'a') {
                $this->sanitizeAnchor($child);
            }

            $this->walkAndSanitize($doc, $child, $numericTicketId, $attMap);
        }
    }

    /**
     * Rewrite img src. The FS inline pattern is
     *   src="https://attachment.freshservice.com/inline/attachment?token=eyJ..."
     * where the JWT payload contains {"id": <attachment_id>, ...}. If the id
     * maps to a local file, rewrite to /api/tickets/{id}/attachment/{filename}.
     * Otherwise drop the element (we don't proxy arbitrary remote images —
     * keeps the dashboard self-contained and avoids leaks).
     *
     * @param array<int, string> $attMap
     */
    private function rewriteImg(DOMElement $img, int $numericTicketId, array $attMap): void
    {
        $src = $img->getAttribute('src');
        if ($src === '') {
            $img->parentNode?->removeChild($img);
            return;
        }

        $attachmentId = null;
        if (str_contains($src, 'attachment.freshservice.com')
            && preg_match('/[?&]token=([A-Za-z0-9._\-]+)/', $src, $m)
        ) {
            $parts = explode('.', $m[1]);
            if (count($parts) >= 2) {
                $payload = $this->base64UrlDecode($parts[1]);
                if (is_string($payload)) {
                    $decoded = json_decode($payload, true);
                    if (is_array($decoded) && isset($decoded['id'])) {
                        $attachmentId = (int) $decoded['id'];
                    }
                }
            }
        }

        if ($attachmentId !== null && isset($attMap[$attachmentId])) {
            $img->setAttribute(
                'src',
                '/api/tickets/' . $numericTicketId . '/attachment/' . rawurlencode($attMap[$attachmentId])
            );
            $img->setAttribute('class', trim($img->getAttribute('class') . ' td-inline-html-img'));
            // Drop legacy inline sizing — let CSS handle it.
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            return;
        }

        // Unknown / external image → drop. The .md `**Attachments:**` block
        // (rendered separately by the frontend) is the user's backup.
        $img->parentNode?->removeChild($img);
    }

    private function sanitizeAnchor(DOMElement $a): void
    {
        $href = $a->getAttribute('href');
        if ($href === '') {
            return;
        }
        $scheme = strtolower(parse_url($href, PHP_URL_SCHEME) ?: '');
        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
            $a->removeAttribute('href');
            return;
        }
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener noreferrer');
    }

    private function base64UrlDecode(string $s): ?string
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($s, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private function plainTextFallback(string $html): string
    {
        return nl2br(htmlspecialchars(trim(strip_tags($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

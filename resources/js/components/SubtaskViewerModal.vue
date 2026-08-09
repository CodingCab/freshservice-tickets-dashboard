<template>
    <div v-if="isOpen" class="svm-overlay">
        <div class="svm-dialog" role="dialog" aria-modal="true" :aria-label="label + ' viewer'">
            <header class="svm-header">
                <h2 class="svm-title">
                    {{ label }}
                    <span v-if="title" class="svm-title-sub">&mdash; {{ title }}</span>
                </h2>
                <button class="svm-close" type="button" aria-label="Close" @click="close">&times;</button>
            </header>

            <div class="svm-body">
                <div v-if="loading" class="svm-status">Loading&hellip;</div>
                <div v-else-if="loadError" class="svm-status svm-error">{{ loadError }}</div>
                <div v-else class="svm-content md-body" v-html="renderedContent"></div>
                <div v-if="filename" class="svm-path">{{ filename }}</div>
            </div>

            <footer class="svm-footer">
                <button type="button" class="btn btn-secondary" @click="close">Close</button>
            </footer>
        </div>
    </div>
</template>

<script>
import modalStackMixin from '../modalStackMixin';
/**
 * SubtaskViewerModal — fetches and shows a subtask markdown file's raw content.
 *
 * Props:
 *   - ticketId: String  (e.g. "T66199")
 *   - filename: String  (e.g. "20260515-T66199-sub-01-extend-session-timeout.md")
 *   - title:    String  optional, rendered in the header for context
 *   - isOpen:   Boolean
 *   - url:      String  optional; when set, the modal fetches this URL directly
 *                       instead of building the ticket-relative subtask URL.
 *                       Used for related-task files, which live outside the
 *                       ticket's own directory. `filename` is then display-only.
 *   - label:    String  header label (default "Subtask"; e.g. "Task" for related)
 */
export default {
    mixins: [modalStackMixin],
    name: 'SubtaskViewerModal',
    props: {
        ticketId: { type: String, default: '' },
        filename: { type: String, default: '' },
        title:    { type: String, default: '' },
        isOpen:   { type: Boolean, default: false },
        url:      { type: String, default: '' },
        label:    { type: String, default: 'Subtask' },
    },
    emits: ['close'],
    data() {
        return {
            loading: false,
            loadError: '',
            content: '',
        };
    },
    computed: {
        renderedContent() {
            return this.renderMarkdown(this.content);
        },
    },
    watch: {
        isOpen(open) {
            if (open) this.load();
        },
        filename() {
            if (this.isOpen) this.load();
        },
        url() {
            if (this.isOpen) this.load();
        },
    },
    methods: {
        close() { this.$emit('close'); },
        // Lightweight markdown → HTML renderer for task/subtask file content.
        // Handles headings, bold/italic, inline code, links, checkboxes,
        // bullet lists, horizontal rules and blockquotes. Frontmatter is stripped.
        renderMarkdown(md) {
            if (!md) return '';
            const esc = (s) => s
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const inline = (s) => esc(s)
                .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>')
                // Underscore italics (_text_) — only at word boundaries so
                // snake_case identifiers are left alone.
                .replace(/(?<![A-Za-z0-9_])_(?!_)([^_\n]+?)_(?![A-Za-z0-9_])/g, '<em>$1</em>')
                .replace(/`([^`]+)`/g, '<code class="md-code">$1</code>')
                .replace(/\[([^\]]+)\]\(([^)]+)\)/g,
                    '<a href="$2" target="_blank" rel="noopener" class="md-link">$1</a>');

            // Strip YAML frontmatter.
            md = md.replace(/^---\n[\s\S]*?\n---\n?/, '');
            // Collapse HTML comments (template guidance) into a single-line
            // token so they render as a muted note instead of raw markup.
            md = md.replace(/<!--([\s\S]*?)-->/g,
                (m, c) => '\n' + c.replace(/\s+/g, ' ').trim() + '\n');

            const out = [];
            let inList = false, inBQ = false;
            const closeList = () => { if (inList) { out.push('</ul>'); inList = false; } };
            const closeBQ   = () => { if (inBQ) { out.push('</blockquote>'); inBQ = false; } };

            for (const rawLine of md.split('\n')) {
                const line = rawLine.replace(/\s+$/, '');

                if (/^(---+|\*\*\*+)$/.test(line)) { closeList(); closeBQ(); out.push('<hr class="md-hr">'); continue; }

                const hm = line.match(/^(#{1,4})\s+(.+)$/);
                if (hm) { closeList(); closeBQ(); const l = hm[1].length; out.push(`<h${l} class="md-h${l}">${inline(hm[2])}</h${l}>`); continue; }

                if (/^>\s?/.test(line)) {
                    closeList();
                    if (!inBQ) { out.push('<blockquote class="md-blockquote">'); inBQ = true; }
                    out.push(`<p>${inline(line.replace(/^>\s?/, ''))}</p>`);
                    continue;
                }
                closeBQ();

                const clm = line.match(/^(\s*)-\s*\[([ xX])\]\s+(.*)$/);
                if (clm) {
                    if (!inList) { out.push('<ul class="md-list">'); inList = true; }
                    const checked = clm[2].trim().toLowerCase() === 'x';
                    const ml = clm[1].length ? ` style="margin-left:${clm[1].length * 8}px"` : '';
                    out.push(`<li class="md-check-item"${ml}><span class="md-checkbox">${checked ? '☑' : '☐'}</span> ${inline(clm[3])}</li>`);
                    continue;
                }

                const bm = line.match(/^(\s*)[-*+]\s+(.*)$/);
                if (bm) {
                    if (!inList) { out.push('<ul class="md-list">'); inList = true; }
                    const ml = bm[1].length ? ` style="margin-left:${bm[1].length * 8}px"` : '';
                    out.push(`<li class="md-list-item"${ml}>${inline(bm[2])}</li>`);
                    continue;
                }

                if (line.trim() === '') { closeList(); out.push('<div class="md-gap"></div>'); continue; }

                closeList();
                out.push(`<p class="md-p">${inline(line)}</p>`);
            }
            closeList(); closeBQ();
            return out.join('\n');
        },
        async load() {
            const fetchUrl = this.url
                || (this.ticketId && this.filename
                    ? '/api/tickets/' + encodeURIComponent(this.ticketId)
                        + '/subtask/' + encodeURIComponent(this.filename)
                    : '');
            if (!fetchUrl) return;
            this.loading = true;
            this.loadError = '';
            this.content = '';
            try {
                const resp = await fetch(fetchUrl);
                const body = await resp.json().catch(() => ({}));
                if (!resp.ok) {
                    this.loadError = body && body.error
                        ? body.error + (body.path ? ': ' + body.path : '')
                        : 'HTTP ' + resp.status;
                    return;
                }
                this.content = body.content || '';
            } catch (e) {
                this.loadError = e.message || String(e);
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>

<style scoped>
.svm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(1, 4, 9, 0.7);
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.svm-dialog {
    width: min(900px, 100%);
    max-height: 92vh;
    background: #0d1117;
    color: #c9d1d9;
    border: 1px solid #30363d;
    border-radius: 8px;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.6);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.svm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #30363d;
    background: #161b22;
}
.svm-title {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #c9d1d9;
}
.svm-title-sub { color: #8b949e; font-weight: 500; }
.svm-close {
    background: transparent;
    border: 0;
    color: #8b949e;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.svm-close:hover { color: #c9d1d9; }
.svm-body {
    flex: 1;
    overflow: hidden;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
}
.svm-status { padding: 12px; color: #8b949e; text-align: center; }
.svm-error { color: #f85149; }
.svm-content {
    flex: 1;
    overflow: auto;
    margin: 0;
    padding: 14px 16px;
    background: #161b22;
    border: 1px solid #21262d;
    border-radius: 6px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 13px;
    color: #c9d1d9;
    word-break: break-word;
}
/* Rendered markdown */
.md-body :deep(.md-h1) { font-size: 1.3em; font-weight: 700; color: #e6edf3; margin: 16px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #21262d; }
.md-body :deep(.md-h2) { font-size: 1.12em; font-weight: 700; color: #e6edf3; margin: 15px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #21262d; }
.md-body :deep(.md-h3) { font-size: 1em; font-weight: 600; color: #c9d1d9; margin: 11px 0 4px; }
.md-body :deep(.md-h4) { font-size: 0.9em; font-weight: 600; color: #8b949e; margin: 9px 0 3px; text-transform: uppercase; letter-spacing: 0.04em; }
.md-body :deep(.md-p)   { margin: 4px 0; line-height: 1.6; color: #c9d1d9; }
.md-body :deep(.md-gap) { height: 7px; }
.md-body :deep(.md-hr)  { border: none; border-top: 1px solid #30363d; margin: 12px 0; }
.md-body :deep(.md-list) { list-style: none; padding: 0; margin: 5px 0; }
.md-body :deep(.md-list-item) { padding: 2px 0 2px 18px; position: relative; color: #c9d1d9; line-height: 1.55; }
.md-body :deep(.md-list-item)::before { content: '•'; position: absolute; left: 4px; color: #58a6ff; }
.md-body :deep(.md-check-item) { padding: 2px 0 2px 4px; color: #c9d1d9; line-height: 1.55; }
.md-body :deep(.md-checkbox) { color: #58a6ff; margin-right: 5px; }
.md-body :deep(.md-code) { background: #0d1117; color: #79c0ff; padding: 1px 5px; border-radius: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.88em; }
.md-body :deep(.md-link) { color: #58a6ff; text-decoration: none; }
.md-body :deep(.md-link):hover { text-decoration: underline; }
.md-body :deep(.md-blockquote) { border-left: 3px solid #30363d; padding: 4px 12px; margin: 7px 0; color: #8b949e; background: #0d1117; border-radius: 0 4px 4px 0; }
.md-body :deep(strong) { color: #e6edf3; font-weight: 600; }
.md-body :deep(em) { color: #d2a8ff; font-style: italic; }
.svm-path {
    margin-top: 6px;
    font-size: 11px;
    color: #8b949e;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    word-break: break-all;
}
.svm-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid #30363d;
    background: #161b22;
}
.btn {
    font-size: 13px;
    padding: 6px 14px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
}
.btn-secondary {
    background: #21262d;
    color: #c9d1d9;
    border-color: #30363d;
}
.btn-secondary:hover { background: #30363d; }
</style>

<template>
    <div v-if="isOpen" class="td-overlay" @click.self="close">
        <aside class="td-panel" role="dialog" aria-label="Ticket detail">
            <header class="td-header">
                <div class="td-header-title">
                    <span class="td-id">{{ ticketId }}</span>
                    <span v-if="data && data.path" class="td-path" :title="data.path">{{ shortPath }}</span>
                </div>
                <button class="td-close" type="button" aria-label="Close" @click="close">&times;</button>
            </header>

            <ticket-actions :data="data" @action="onAction" />

            <reject-feedback-modal
                :ticket-id="ticketId"
                :is-open="rejectModalOpen"
                @close="rejectModalOpen = false"
                @rejected="onRejected"
            />

            <reply-draft-modal
                :ticket-id="ticketId"
                :is-open="sendModalOpen"
                @close="sendModalOpen = false"
                @sent="onReplySent"
                @reject-requested="onRejectRequested"
            />

            <div v-if="toastMessage" class="td-toast" role="status">{{ toastMessage }}</div>

            <div class="td-body">
                <div v-if="loading" class="td-empty">Loading ticket {{ ticketId }}...</div>
                <div v-else-if="error" class="td-empty td-error">Failed to load: {{ error }}</div>
                <template v-else-if="data">
                    <!-- Metadata block -->
                    <section v-if="metaEntries.length" class="td-section">
                        <dl class="td-meta">
                            <template v-for="m in metaEntries" :key="m.key">
                                <dt>{{ m.key }}</dt>
                                <dd>
                                    <a v-if="m.key === 'Ticket URL' && m.value" :href="m.value" target="_blank" rel="noopener">{{ m.value }}</a>
                                    <template v-else>{{ m.value }}</template>
                                </dd>
                            </template>
                        </dl>
                    </section>

                    <!-- Reply draft button -->
                    <section v-if="data.reply_draft" class="td-section">
                        <button type="button" class="btn btn-primary td-draft-btn" @click="sendModalOpen = true">📝 View reply draft</button>
                        <div v-if="draftCreatedAt" class="td-draft-meta">Created: {{ draftCreatedAt }}</div>
                    </section>

                    <!-- Subject -->
                    <section v-if="data.subject" class="td-section">
                        <h2 class="td-h2">Subject</h2>
                        <div class="td-subject">{{ data.subject }}</div>
                    </section>

                    <!-- Body -->
                    <section v-if="data.body" class="td-section">
                        <h2 class="td-h2">Body</h2>
                        <pre class="td-body-text">{{ data.body }}</pre>
                    </section>

                    <!-- Replies -->
                    <section v-if="data.replies && data.replies.length" class="td-section">
                        <h2 class="td-h2">Replies</h2>
                        <article
                            v-for="r in data.replies"
                            :key="r.n"
                            class="td-reply"
                            :class="replyClass(r)"
                        >
                            <div class="td-reply-header">
                                [{{ r.n }}] {{ r.timestamp }}<template v-if="r.author_name || r.author_email"> &mdash; {{ r.author_name }}<template v-if="r.author_email"> &lt;{{ r.author_email }}&gt;</template></template><template v-if="r.role || r.type"> ({{ r.role }}<template v-if="r.role && r.type">, </template>{{ r.type }})</template>
                            </div>
                            <div class="td-reply-body">
                                <p v-for="(para, i) in splitParagraphs(r.body)" :key="i">{{ para }}</p>
                            </div>
                        </article>
                    </section>

                    <!-- Subtasks -->
                    <section v-if="data.subtasks && data.subtasks.length" class="td-section">
                        <h2 class="td-h2">Subtasks</h2>
                        <ul class="td-subtasks">
                            <li v-for="(s, i) in data.subtasks" :key="i" class="td-subtask">
                                <span class="td-checkbox">{{ s.checked ? '[x]' : '[ ]' }}</span>
                                <a v-if="s.path" :href="s.path" target="_blank" rel="noopener" class="td-subtask-title">{{ s.title }}</a>
                                <span v-else class="td-subtask-title">{{ s.title }}</span>
                                <span v-if="s.kind" class="td-badge">{{ s.kind }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Timeline -->
                    <section v-if="data.timeline" class="td-section">
                        <h2 class="td-h2 td-h2-toggle" @click="timelineOpen = !timelineOpen">
                            <span class="td-disclosure">{{ timelineOpen ? '▾' : '▸' }}</span>
                            Timeline
                        </h2>
                        <pre v-if="timelineOpen" class="td-timeline">{{ data.timeline }}</pre>
                    </section>
                </template>
                <div v-else class="td-empty">No data.</div>
            </div>
        </aside>
    </div>
</template>

<script>
import TicketActions from './TicketActions.vue';
import RejectFeedbackModal from './RejectFeedbackModal.vue';
import ReplyDraftModal from './ReplyDraftModal.vue';

/**
 * TicketDetail — side panel that loads and displays a parsed ticket file.
 *
 * Props:
 *   - ticketId: String   — the ticket id (e.g. "T66050"). When this changes
 *     and isOpen is true, the panel re-fetches.
 *   - isOpen:   Boolean  — controls visibility. The panel emits `@close` when
 *     the user dismisses it (overlay click or close button); the parent is
 *     responsible for flipping isOpen back to false.
 *
 * Phase 2: action buttons just emit; we log them. Real modal handlers come
 * in Phase 3 / 4.
 */
export default {
    name: 'TicketDetail',
    components: { TicketActions, RejectFeedbackModal, ReplyDraftModal },
    props: {
        ticketId: { type: String, default: '' },
        isOpen:   { type: Boolean, default: false },
    },
    emits: ['close'],
    data() {
        return {
            loading: false,
            error: '',
            data: null,
            timelineOpen: false,
            rejectModalOpen: false,
            sendModalOpen: false,
            toastMessage: '',
            toastTimer: null,
        };
    },
    computed: {
        metaEntries() {
            if (!this.data || !this.data.metadata) return [];
            return Object.entries(this.data.metadata)
                .filter(([key]) => key !== 'Reply Draft')
                .map(([key, value]) => ({ key, value }));
        },
        draftCreatedAt() {
            const iso = this.data && this.data.reply_draft ? this.data.reply_draft.created_at : null;
            return this.formatDraftDate(iso);
        },
        shortPath() {
            if (!this.data || !this.data.path) return '';
            const p = this.data.path;
            const idx = p.lastIndexOf('/');
            return idx >= 0 ? p.substring(idx + 1) : p;
        },
    },
    watch: {
        ticketId(newId) {
            if (newId && this.isOpen) this.load();
        },
        isOpen(open) {
            if (open && this.ticketId) this.load();
        },
    },
    mounted() {
        if (this.isOpen && this.ticketId) this.load();
    },
    methods: {
        async load() {
            this.loading = true;
            this.error = '';
            this.data = null;
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/detail');
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status);
                }
                this.data = await resp.json();
            } catch (e) {
                this.error = e.message || String(e);
            } finally {
                this.loading = false;
            }
        },
        close() {
            this.$emit('close');
        },
        onAction(key) {
            if (key === 'reject-draft') {
                this.rejectModalOpen = true;
                return;
            }
            if (key === 'send-reply') {
                this.sendModalOpen = true;
                return;
            }
            // Other actions still log-only until their phases land.
            console.log('[TicketDetail] action:', key, 'ticket:', this.ticketId);
        },
        onReplySent(payload) {
            const cid = payload && payload.conversation_id ? payload.conversation_id : '';
            this.showToast(cid ? ('Reply sent (FS #' + cid + ')') : 'Reply sent');
            this.sendModalOpen = false;
            this.load();
        },
        onRejectRequested() {
            this.sendModalOpen = false;
            this.$nextTick(() => {
                this.rejectModalOpen = true;
            });
        },
        formatDraftDate(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return '';
            const pad = (n) => String(n).padStart(2, '0');
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        },
        onRejected() {
            this.showToast('Draft sent back to Reply Drafting');
            // Refresh ticket data so the updated metadata/timeline shows.
            this.load();
        },
        showToast(msg) {
            this.toastMessage = msg;
            if (this.toastTimer) clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => {
                this.toastMessage = '';
                this.toastTimer = null;
            }, 4000);
        },
        replyClass(r) {
            const role = (r.role || '').toLowerCase();
            const type = (r.type || '').toLowerCase();
            if (role === 'agent' && type === 'draft proposal') return 'td-reply-draft';
            if (role === 'agent' && type === 'private note')   return 'td-reply-private';
            if (role === 'agent' && type === 'public reply')   return 'td-reply-agent-public';
            return 'td-reply-default';
        },
        splitParagraphs(body) {
            if (!body) return [];
            // Preserve paragraph breaks (blank line separated). Within a paragraph,
            // collapse to a single string — line wrapping handled by CSS.
            return body
                .replace(/\r\n/g, '\n')
                .split(/\n\s*\n/)
                .map(p => p.trim())
                .filter(p => p.length > 0);
        },
    },
};
</script>

<style scoped>
.td-overlay {
    position: fixed;
    inset: 0;
    background: rgba(1, 4, 9, 0.55);
    z-index: 1000;
    display: flex;
    justify-content: flex-end;
}
.td-panel {
    width: min(880px, 100%);
    max-width: 100%;
    height: 100%;
    background: #0d1117;
    color: #c9d1d9;
    border-left: 1px solid #30363d;
    box-shadow: -4px 0 16px rgba(0, 0, 0, 0.4);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.td-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-bottom: 1px solid #30363d;
    background: #161b22;
}
.td-header-title {
    display: flex;
    align-items: baseline;
    gap: 10px;
    min-width: 0;
}
.td-id {
    font-weight: 700;
    font-size: 14px;
    color: #58a6ff;
}
.td-path {
    font-size: 11px;
    color: #6e7681;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.td-close {
    background: transparent;
    border: 0;
    color: #8b949e;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.td-close:hover { color: #c9d1d9; }

.td-body {
    flex: 1;
    overflow-y: auto;
    padding: 14px 18px 24px;
}

.td-section { margin-bottom: 20px; }

.td-h2 {
    font-size: 14px;
    font-weight: 600;
    color: #58a6ff;
    margin: 0 0 8px;
    padding-bottom: 4px;
    border-bottom: 1px solid #21262d;
}
.td-h2-toggle {
    cursor: pointer;
    user-select: none;
}
.td-disclosure {
    display: inline-block;
    width: 14px;
    color: #8b949e;
}

.td-meta {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 14px;
    font-size: 13px;
    margin: 0;
}
.td-meta dt {
    font-weight: 700;
    color: #c9d1d9;
}
.td-meta dd {
    margin: 0;
    color: #8b949e;
    word-break: break-word;
}
.td-meta a { color: #58a6ff; }

.td-subject {
    font-size: 15px;
    color: #c9d1d9;
}

.td-body-text {
    background: #161b22;
    border: 1px solid #21262d;
    border-radius: 6px;
    padding: 10px 12px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    color: #c9d1d9;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
}

.td-reply {
    border: 1px solid #21262d;
    border-radius: 6px;
    padding: 8px 12px;
    margin-bottom: 8px;
}
.td-reply-header {
    font-size: 12px;
    font-weight: 600;
    color: #8b949e;
    margin-bottom: 6px;
    border-bottom: 1px dashed #21262d;
    padding-bottom: 4px;
}
.td-reply-body p {
    margin: 0 0 8px;
    font-size: 13px;
    line-height: 1.45;
    white-space: pre-wrap;
}
.td-reply-body p:last-child { margin-bottom: 0; }
.td-reply-default       { background: #161b22; }
.td-reply-agent-public  { background: #0c1a2e; border-color: #1f3a5f; }
.td-reply-private       { background: #1c1f24; border-color: #30363d; }
.td-reply-draft         { background: #2a230e; border-color: #5a4a1a; }

.td-subtasks {
    list-style: none;
    padding: 0;
    margin: 0;
}
.td-subtask {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 0;
    font-size: 13px;
}
.td-checkbox {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    color: #8b949e;
}
.td-subtask-title {
    color: #58a6ff;
    text-decoration: none;
    flex: 1;
}
.td-subtask-title:hover { text-decoration: underline; }
.td-badge {
    background: #21262d;
    color: #8b949e;
    border-radius: 10px;
    padding: 1px 8px;
    font-size: 11px;
}

.td-timeline {
    background: #161b22;
    border: 1px solid #21262d;
    border-radius: 6px;
    padding: 10px 12px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    color: #8b949e;
    white-space: pre-wrap;
    margin: 0;
    max-height: 360px;
    overflow-y: auto;
}

.td-empty {
    padding: 20px;
    color: #6e7681;
    text-align: center;
}
.td-empty.td-error { color: #f85149; }

.btn {
    font-size: 13px;
    padding: 6px 12px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s;
}
.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-primary {
    background: #1f6feb;
    color: #fff;
    border-color: #1f6feb;
}
.btn-primary:hover:not(:disabled) {
    background: #388bfd;
}
.td-draft-btn {
    font-weight: 600;
}
.td-draft-meta {
    margin-top: 6px;
    font-size: 12px;
    color: #6e7681;
}

.td-toast {
    margin: 8px 14px 0;
    padding: 8px 12px;
    background: #102a1c;
    border: 1px solid #2ea04355;
    border-radius: 6px;
    color: #3fb950;
    font-size: 12.5px;
}
</style>

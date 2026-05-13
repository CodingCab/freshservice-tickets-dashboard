<template>
    <div v-if="isOpen" class="rdm-overlay" @click.self="cancel">
        <div class="rdm-dialog" role="dialog" aria-modal="true" aria-label="Confirm send reply">
            <header class="rdm-header">
                <h2 class="rdm-title">Reply draft &mdash; {{ ticketId }}</h2>
                <button type="button" class="rdm-close" aria-label="Cancel" @click="cancel">&times;</button>
            </header>

            <div class="rdm-body">
                <div v-if="loading" class="rdm-status">Loading draft&hellip;</div>
                <div v-else-if="loadError" class="rdm-status rdm-error">
                    Failed to load draft: {{ loadError }}
                </div>
                <div v-else-if="!draft" class="rdm-status rdm-error">
                    No draft found for this ticket.
                </div>
                <template v-else>
                    <p class="rdm-recipient">
                        This is the email that will be sent to <strong>{{ draft.to }}</strong>.
                    </p>
                    <dl class="rdm-meta">
                        <template v-if="draft.created_at">
                            <dt>Created</dt><dd>{{ formattedCreatedAt }}</dd>
                        </template>
                        <template v-if="draft.cc && !ccIsEmpty">
                            <dt>CC</dt><dd>{{ draft.cc }}</dd>
                        </template>
                        <template v-if="draft.subject">
                            <dt>Subject</dt><dd>{{ draft.subject }}</dd>
                        </template>
                        <template v-if="draft.language">
                            <dt>Lang</dt><dd>{{ draft.language }}</dd>
                        </template>
                    </dl>
                    <div class="rdm-preview-label">Body preview (HTML as customer will see it):</div>
                    <div class="rdm-preview" v-html="draft.body_html_preview"></div>
                </template>

                <div v-if="sendError" class="rdm-send-error">{{ sendError }}</div>
                <div v-if="sendSuccess" class="rdm-send-success">
                    Sent. FS conversation #{{ sendSuccess.conversation_id }}.
                </div>
            </div>

            <footer class="rdm-footer">
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="sending"
                    @click="cancel"
                >Cancel</button>
                <button
                    type="button"
                    class="btn btn-reject"
                    :disabled="sending || !!sendSuccess"
                    @click="requestReject"
                >Reject + feedback</button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="!canSend"
                    @click="send"
                >{{ sending ? 'Sending&hellip;' : 'Send now' }}</button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * ReplyDraftModal — confirmation modal for the "Send draft" inline action.
 *
 * Props:
 *   - ticketId: String (e.g. "T66050")
 *   - isOpen:   Boolean
 *
 * Emits:
 *   - @close — user dismissed without sending.
 *   - @sent  — server accepted the send; payload is the server's response
 *              `{status, conversation_id, to}` so the parent can refresh.
 *
 * On open:
 *   1. Fetch `/api/tickets/{id}/detail` and pluck `reply_draft` from the
 *      response (the endpoint was extended to include the parsed draft).
 *   2. Render the body HTML preview (exactly what will be POST'd to FS).
 *   3. On "Send now", POST `/api/tickets/{id}/send-reply` and surface any
 *      error inline.
 */
export default {
    name: 'ReplyDraftModal',
    props: {
        ticketId: { type: String, default: '' },
        isOpen: { type: Boolean, default: false },
    },
    emits: ['close', 'sent', 'reject-requested'],
    data() {
        return {
            loading: false,
            loadError: '',
            draft: null,
            sending: false,
            sendError: '',
            sendSuccess: null,
        };
    },
    computed: {
        canSend() {
            return !!this.draft && !this.sending && !this.sendSuccess;
        },
        ccIsEmpty() {
            const cc = (this.draft && this.draft.cc) || '';
            const norm = cc.trim().toLowerCase();
            return norm === '' || norm === '—' || norm === '_(none)_' || norm === '(none)';
        },
        formattedCreatedAt() {
            const iso = this.draft && this.draft.created_at ? this.draft.created_at : '';
            if (!iso) return '';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return '';
            const pad = (n) => String(n).padStart(2, '0');
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        },
    },
    watch: {
        ticketId(newId) {
            if (newId && this.isOpen) this.loadDraft();
        },
        isOpen(open) {
            if (open) {
                this.reset();
                if (this.ticketId) this.loadDraft();
            }
        },
    },
    mounted() {
        if (this.isOpen && this.ticketId) this.loadDraft();
    },
    methods: {
        reset() {
            this.loadError = '';
            this.draft = null;
            this.sendError = '';
            this.sendSuccess = null;
            this.sending = false;
        },
        async loadDraft() {
            this.loading = true;
            this.loadError = '';
            this.draft = null;
            try {
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/detail');
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                this.draft = data && data.reply_draft ? data.reply_draft : null;
                if (!this.draft) {
                    this.loadError = 'no draft for this ticket';
                }
            } catch (e) {
                this.loadError = e.message || String(e);
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            if (this.sending) return;
            this.$emit('close');
        },
        requestReject() {
            if (this.sending || this.sendSuccess) return;
            this.$emit('reject-requested');
            this.cancel();
        },
        async send() {
            this.sending = true;
            this.sendError = '';
            try {
                const resp = await fetch(
                    '/api/tickets/' + encodeURIComponent(this.ticketId) + '/send-reply',
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({}),
                    }
                );
                const body = await resp.json().catch(() => ({}));
                if (!resp.ok) {
                    this.sendError = body && body.error
                        ? body.error + (body.message ? ': ' + body.message : '')
                        : 'HTTP ' + resp.status;
                    return;
                }
                this.sendSuccess = body;
                this.$emit('sent', body);
            } catch (e) {
                this.sendError = e.message || String(e);
            } finally {
                this.sending = false;
            }
        },
    },
};
</script>

<style scoped>
.rdm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(1, 4, 9, 0.7);
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.rdm-dialog {
    width: min(720px, 100%);
    max-height: 90vh;
    background: #0d1117;
    color: #c9d1d9;
    border: 1px solid #30363d;
    border-radius: 8px;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.6);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.rdm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #30363d;
    background: #161b22;
}
.rdm-title {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: #c9d1d9;
}
.rdm-close {
    background: transparent;
    border: 0;
    color: #8b949e;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.rdm-close:hover { color: #c9d1d9; }

.rdm-body {
    flex: 1;
    overflow-y: auto;
    padding: 14px 18px;
}
.rdm-status {
    padding: 12px;
    color: #8b949e;
    text-align: center;
}
.rdm-error { color: #f85149; }

.rdm-recipient {
    margin: 0 0 10px;
    font-size: 13px;
    color: #c9d1d9;
}
.rdm-recipient strong { color: #58a6ff; }

.rdm-meta {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 12px;
    font-size: 12.5px;
    margin: 0 0 12px;
}
.rdm-meta dt { color: #c9d1d9; font-weight: 700; }
.rdm-meta dd { margin: 0; color: #8b949e; word-break: break-word; }

.rdm-preview-label {
    font-size: 12px;
    color: #8b949e;
    margin-bottom: 4px;
}
.rdm-preview {
    background: #161b22;
    border: 1px solid #21262d;
    border-radius: 6px;
    padding: 12px 14px;
    font-size: 13px;
    line-height: 1.5;
    color: #c9d1d9;
    word-break: break-word;
}

.rdm-send-error {
    margin-top: 12px;
    padding: 8px 12px;
    border: 1px solid #f8514955;
    background: #4a1d1d;
    color: #ffa198;
    border-radius: 6px;
    font-size: 13px;
}
.rdm-send-success {
    margin-top: 12px;
    padding: 8px 12px;
    border: 1px solid #1f6feb55;
    background: #0c1a2e;
    color: #79c0ff;
    border-radius: 6px;
    font-size: 13px;
}

.rdm-footer {
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
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary {
    background: #1f6feb;
    color: #fff;
    border-color: #1f6feb;
}
.btn-primary:hover:not(:disabled) { background: #388bfd; }
.btn-secondary {
    background: #21262d;
    color: #c9d1d9;
    border-color: #30363d;
}
.btn-secondary:hover:not(:disabled) { background: #30363d; }
.btn-reject {
    background: transparent;
    color: #d29922;
    border-color: #d29922;
}
.btn-reject:hover:not(:disabled) {
    background: #d2992222;
}
</style>

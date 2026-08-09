<template>
    <div v-if="isOpen" class="mrm-overlay">
        <div class="mrm-dialog" role="dialog" aria-modal="true" aria-label="Compose manual reply">
            <header class="mrm-header">
                <h2 class="mrm-title">Compose reply &mdash; {{ ticketId }}</h2>
                <button type="button" class="mrm-close" aria-label="Cancel" @click="cancel">&times;</button>
            </header>

            <div class="mrm-body">
                <div class="mrm-notice">
                    Manual reply: this skips the drafter / Security Check pipeline. You write it, you own it.
                </div>

                <dl class="mrm-meta">
                    <dt>To</dt><dd>{{ toEmail || '(unknown — ticket has no Requester)' }}</dd>
                    <dt>Subject</dt><dd>{{ subjectLine || '(none)' }}</dd>
                </dl>

                <label class="mrm-label" for="mrm-cc">CC <span class="mrm-hint">(comma-separated)</span></label>
                <input
                    id="mrm-cc"
                    v-model="cc"
                    type="text"
                    class="mrm-input"
                    placeholder="cc1@example.com, cc2@example.com"
                    :disabled="sending"
                />
                <div v-if="ccError" class="mrm-field-error">{{ ccError }}</div>

                <label class="mrm-label" for="mrm-body">Body <span class="mrm-hint">(blank line separates paragraphs)</span></label>
                <div class="mrm-toolbar">
                    <button type="button" class="mrm-tool" title="Bold (Ctrl+B)" :disabled="sending" @click="wrap('**')"><strong>B</strong></button>
                    <button type="button" class="mrm-tool" title="Italic (Ctrl+I)" :disabled="sending" @click="wrap('*')"><em>I</em></button>
                    <button type="button" class="mrm-tool" title="Bullet list" :disabled="sending" @click="prefixLines('- ')">&bull; List</button>
                    <button type="button" class="mrm-tool" title="Numbered list" :disabled="sending" @click="prefixLines('1. ')">1. List</button>
                    <span class="mrm-saved">{{ savedHint }}</span>
                    <button type="button" class="mrm-tool mrm-tool-discard" title="Discard the saved draft" :disabled="sending" @click="discardDraft">Discard draft</button>
                </div>
                <textarea
                    id="mrm-body"
                    ref="bodyRef"
                    v-model="body"
                    class="mrm-textarea"
                    rows="14"
                    placeholder="Hi …"
                    :disabled="sending"
                    @keydown="onBodyKeydown"
                ></textarea>

                <div v-if="confirming && !sendSuccess" class="mrm-confirm">
                    <div class="mrm-confirm-title">This goes straight to the customer. Are you sure?</div>
                    <div class="mrm-confirm-text">
                        Your message will be emailed to
                        <strong>{{ toEmail }}</strong><span v-if="ccList.length"> (cc {{ ccList.join(', ') }})</span>
                        as a public reply on the FreshService ticket, right now.
                        It skips the drafter and Security Check, and <strong>it cannot be unsent.</strong>
                    </div>
                </div>

                <div v-if="sendError" class="mrm-send-error">{{ sendError }}</div>
                <div v-if="sendSuccess" class="mrm-send-success">
                    Sent. FS conversation #{{ sendSuccess.conversation_id }}.
                </div>
            </div>

            <footer class="mrm-footer">
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="sending"
                    @click="secondaryAction"
                >{{ secondaryLabel }}</button>
                <button
                    v-if="!sendSuccess && !confirming"
                    type="button"
                    class="btn btn-primary"
                    :disabled="!canSend"
                    @click="confirming = true"
                >Send reply</button>
                <button
                    v-if="!sendSuccess && confirming"
                    type="button"
                    class="btn btn-danger"
                    :disabled="!canSend"
                    @click="send"
                >{{ sending ? 'Sending&hellip;' : 'Yes, send to customer' }}</button>
            </footer>
        </div>
    </div>
</template>

<script>
import modalStackMixin from '../modalStackMixin';
/**
 * ManualReplyModal — compose-and-send a manual public reply.
 *
 * Props:
 *   - ticketId:   String
 *   - isOpen:     Boolean
 *   - toEmail:    String  pre-filled requester
 *   - ccEmails:   Array<String>  pre-filled CC from ticket
 *   - subjectLine:String  for display only
 *
 * Emits:
 *   - @close
 *   - @sent  payload: server response (conversation_id, to, cc, status)
 */
export default {
    mixins: [modalStackMixin],
    name: 'ManualReplyModal',
    props: {
        ticketId:    { type: String, default: '' },
        isOpen:      { type: Boolean, default: false },
        toEmail:     { type: String, default: '' },
        ccEmails:    { type: Array, default: () => [] },
        subjectLine: { type: String, default: '' },
    },
    emits: ['close', 'sent'],
    data() {
        return {
            body: '',
            cc: '',
            sending: false,
            sendError: '',
            sendSuccess: null,
            // Send is irreversible and leaves our system — the button arms a
            // confirmation step rather than firing the reply straight away.
            confirming: false,
            // Autosave state: what the user typed survives closing the modal
            // (and a page reload) until it is actually sent or discarded.
            savedHint: '',
            restored: false,
        };
    },
    computed: {
        ccList() {
            return this.cc
                .split(',')
                .map((s) => s.trim())
                .filter((s) => s.length > 0);
        },
        ccError() {
            const bad = this.ccList.filter((e) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e));
            return bad.length ? 'Invalid email(s): ' + bad.join(', ') : '';
        },
        canSend() {
            if (this.sending || this.sendSuccess) return false;
            if (this.body.trim().length < 2) return false;
            if (!this.toEmail) return false;
            if (this.ccError) return false;
            return true;
        },
        secondaryLabel() {
            if (this.sendSuccess) return 'Close';
            return this.confirming ? 'Back' : 'Cancel';
        },
    },
    watch: {
        body() { this.persist(); },
        cc()   { this.persist(); },
        isOpen(open) {
            if (open) {
                this.reset();
                this.$nextTick(() => {
                    if (this.$refs.bodyRef) this.$refs.bodyRef.focus();
                });
            }
        },
    },
    methods: {
        /** localStorage key for this ticket's in-progress manual reply. */
        storageKey() {
            return 'ticketsMr:' + this.ticketId;
        },
        reset() {
            this.sending = false;
            this.sendError = '';
            this.sendSuccess = null;
            this.confirming = false;
            this.restored = false;
            this.savedHint = '';

            // Restore whatever was typed last time this modal was open.
            let saved = null;
            try {
                const raw = window.localStorage.getItem(this.storageKey());
                if (raw) saved = JSON.parse(raw);
            } catch (e) { saved = null; }

            this.body = (saved && typeof saved.body === 'string') ? saved.body : '';
            this.cc = (saved && typeof saved.cc === 'string')
                ? saved.cc
                : (this.ccEmails || []).join(', ');

            if (saved && (this.body || '').trim() !== '') {
                this.savedHint = 'Restored unsent draft';
            }
            // Mark ready only after the restore assignments settle, so the
            // watchers they trigger do not overwrite storage with defaults.
            this.$nextTick(() => { this.restored = true; });
        },
        /** Persist the in-progress reply so closing the modal never loses it. */
        persist() {
            if (!this.isOpen || !this.restored || this.sendSuccess) return;
            try {
                if ((this.body || '').trim() === '') {
                    window.localStorage.removeItem(this.storageKey());
                    this.savedHint = '';
                    return;
                }
                window.localStorage.setItem(
                    this.storageKey(),
                    JSON.stringify({ body: this.body, cc: this.cc })
                );
                this.savedHint = 'Draft saved';
            } catch (e) { /* storage unavailable — typing still works */ }
        },
        clearSaved() {
            try { window.localStorage.removeItem(this.storageKey()); } catch (e) { /* ignore */ }
            this.savedHint = '';
        },
        discardDraft() {
            if (this.sending) return;
            this.clearSaved();
            this.body = '';
            this.cc = (this.ccEmails || []).join(', ');
        },
        /** Wrap the current selection in a markdown marker (bold / italic). */
        wrap(marker) {
            const el = this.$refs.bodyRef;
            if (!el) return;
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const text = this.body;
            const sel = text.slice(start, end) || 'text';
            this.body = text.slice(0, start) + marker + sel + marker + text.slice(end);
            this.$nextTick(() => {
                el.focus();
                el.setSelectionRange(start + marker.length, start + marker.length + sel.length);
            });
        },
        /** Prefix every selected line (or the current line) with a list marker. */
        prefixLines(prefix) {
            const el = this.$refs.bodyRef;
            if (!el) return;
            const text = this.body;
            const lineStart = text.lastIndexOf('\n', Math.max(0, el.selectionStart - 1)) + 1;
            let lineEnd = text.indexOf('\n', el.selectionEnd);
            if (lineEnd === -1) lineEnd = text.length;
            const numbered = /^\d+\.\s/.test(prefix);
            const lines = text.slice(lineStart, lineEnd).split('\n').map((l, i) =>
                (numbered ? (i + 1) + '. ' : prefix) + l
            );
            const block = lines.join('\n');
            this.body = text.slice(0, lineStart) + block + text.slice(lineEnd);
            this.$nextTick(() => {
                el.focus();
                el.setSelectionRange(lineStart, lineStart + block.length);
            });
        },
        onBodyKeydown(e) {
            if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
            const k = (e.key || '').toLowerCase();
            if (k === 'b') { e.preventDefault(); this.wrap('**'); }
            else if (k === 'i') { e.preventDefault(); this.wrap('*'); }
        },
        cancel() {
            if (this.sending) return;
            this.$emit('close');
        },
        /** Back out of the confirmation step; otherwise close the modal. */
        secondaryAction() {
            if (this.sending) return;
            if (this.confirming && !this.sendSuccess) {
                this.confirming = false;
                return;
            }
            this.cancel();
        },
        async send() {
            this.sending = true;
            this.sendError = '';
            try {
                const resp = await fetch(
                    '/api/tickets/' + encodeURIComponent(this.ticketId) + '/send-manual-reply',
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ body: this.body, cc: this.ccList }),
                    }
                );
                const data = await resp.json().catch(() => ({}));
                if (!resp.ok) {
                    this.sendError = data && data.error
                        ? data.error + (data.message ? ': ' + data.message : '')
                        : 'HTTP ' + resp.status;
                    return;
                }
                this.sendSuccess = data;
                // Sent for real — the saved draft has served its purpose.
                this.clearSaved();
                this.$emit('sent', data);
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
.mrm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(1, 4, 9, 0.7);
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.mrm-dialog {
    width: min(760px, 100%);
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
.mrm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #30363d;
    background: #161b22;
}
.mrm-title {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: #c9d1d9;
}
.mrm-close {
    background: transparent;
    border: 0;
    color: #8b949e;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.mrm-close:hover { color: #c9d1d9; }

.mrm-body {
    flex: 1;
    overflow-y: auto;
    padding: 14px 18px;
}
.mrm-notice {
    margin: 0 0 12px;
    padding: 8px 12px;
    background: #1d2536;
    border: 1px solid #1f6feb55;
    border-radius: 6px;
    color: #79c0ff;
    font-size: 12.5px;
}
.mrm-meta {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 12px;
    font-size: 12.5px;
    margin: 0 0 12px;
}
.mrm-meta dt { color: #c9d1d9; font-weight: 700; }
.mrm-meta dd { margin: 0; color: #8b949e; word-break: break-word; }

.mrm-label {
    display: block;
    font-size: 12px;
    color: #c9d1d9;
    margin: 10px 0 4px;
    font-weight: 600;
}
.mrm-hint {
    color: #8b949e;
    font-weight: 400;
}
.mrm-input,
.mrm-textarea {
    width: 100%;
    box-sizing: border-box;
    background: #0d1117;
    color: #c9d1d9;
    border: 1px solid #30363d;
    border-radius: 6px;
    padding: 8px 10px;
    font-size: 13px;
    font-family: inherit;
}
.mrm-textarea {
    resize: vertical;
    min-height: 200px;
    line-height: 1.5;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.mrm-input:focus,
.mrm-textarea:focus {
    outline: none;
    border-color: #1f6feb;
}
.mrm-toolbar {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
}
.mrm-tool {
    background: #21262d;
    color: #c9d1d9;
    border: 1px solid #30363d;
    border-radius: 4px;
    padding: 3px 9px;
    font-size: 12px;
    line-height: 1.4;
    cursor: pointer;
}
.mrm-tool:hover:not(:disabled) { background: #30363d; }
.mrm-tool:disabled { opacity: 0.5; cursor: not-allowed; }
.mrm-tool-discard { color: #8b949e; }
.mrm-tool-discard:hover:not(:disabled) { color: #ffa198; }
.mrm-saved {
    margin-left: auto;
    font-size: 11.5px;
    color: #56d364;
}
.mrm-field-error {
    margin-top: 4px;
    color: #f85149;
    font-size: 12px;
}

.mrm-confirm {
    margin-top: 12px;
    padding: 10px 12px;
    border: 1px solid #f85149;
    background: #2d1618;
    border-radius: 6px;
}
.mrm-confirm-title {
    color: #ffa198;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 4px;
}
.mrm-confirm-text {
    color: #e6c3c0;
    font-size: 12.5px;
    line-height: 1.5;
}
.mrm-confirm-text strong { color: #ffdad6; }

.mrm-send-error {
    margin-top: 12px;
    padding: 8px 12px;
    border: 1px solid #f8514955;
    background: #4a1d1d;
    color: #ffa198;
    border-radius: 6px;
    font-size: 13px;
}
.mrm-send-success {
    margin-top: 12px;
    padding: 8px 12px;
    border: 1px solid #2ea04355;
    background: #0d1f17;
    color: #56d364;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
}

.mrm-footer {
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
.btn-danger {
    background: #da3633;
    color: #fff;
    border-color: #da3633;
}
.btn-danger:hover:not(:disabled) { background: #f85149; }
.btn-secondary {
    background: #21262d;
    color: #c9d1d9;
    border-color: #30363d;
}
.btn-secondary:hover:not(:disabled) { background: #30363d; }
</style>

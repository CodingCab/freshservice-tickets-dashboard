<template>
    <div v-if="isOpen" class="mrm-overlay" @click.self="cancel">
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

                <label class="mrm-label" for="mrm-body">Body <span class="mrm-hint">(plain text / minimal markdown — blank line separates paragraphs)</span></label>
                <textarea
                    id="mrm-body"
                    ref="bodyRef"
                    v-model="body"
                    class="mrm-textarea"
                    rows="14"
                    placeholder="Hi …"
                    :disabled="sending"
                ></textarea>

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
                    @click="cancel"
                >{{ sendSuccess ? 'Close' : 'Cancel' }}</button>
                <button
                    v-if="!sendSuccess"
                    type="button"
                    class="btn btn-primary"
                    :disabled="!canSend"
                    @click="send"
                >{{ sending ? 'Sending&hellip;' : 'Send reply' }}</button>
            </footer>
        </div>
    </div>
</template>

<script>
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
    },
    watch: {
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
        reset() {
            this.body = '';
            this.cc = (this.ccEmails || []).join(', ');
            this.sending = false;
            this.sendError = '';
            this.sendSuccess = null;
        },
        cancel() {
            if (this.sending) return;
            this.$emit('close');
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
.mrm-field-error {
    margin-top: 4px;
    color: #f85149;
    font-size: 12px;
}

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
.btn-secondary {
    background: #21262d;
    color: #c9d1d9;
    border-color: #30363d;
}
.btn-secondary:hover:not(:disabled) { background: #30363d; }
</style>

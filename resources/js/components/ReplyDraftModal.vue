<template>
    <div v-if="isOpen" class="rdm-overlay">
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
                    <div v-if="alreadySent" class="rdm-sent-banner">
                        ✅ Already sent on {{ draft.sent_at }}<template v-if="draft.conversation_id"> &mdash; FS conversation #{{ draft.conversation_id }}</template>.
                    </div>
                    <p v-else class="rdm-recipient">
                        This is the email that will be sent to <strong>{{ draft.to }}</strong>.
                    </p>
                    <dl class="rdm-meta">
                        <template v-if="draft.sent_at">
                            <dt>Sent</dt><dd>{{ draft.sent_at }}</dd>
                        </template>
                        <template v-if="draft.created_at">
                            <dt>Created</dt><dd>{{ formattedCreatedAt }}</dd>
                        </template>
                        <dt>CC</dt>
                        <dd>
                            <input
                                type="text"
                                class="rdm-cc-input"
                                v-model="editCc"
                                :disabled="sending || !!sendSuccess || alreadySent"
                                placeholder="Add CC recipients, comma-separated"
                                spellcheck="false"
                                autocomplete="off"
                            />
                        </dd>
                        <template v-if="draft.subject">
                            <dt>Subject</dt><dd>{{ draft.subject }}</dd>
                        </template>
                        <template v-if="draft.language">
                            <dt>Lang</dt><dd>{{ draft.language }}</dd>
                        </template>
                    </dl>
                    <div class="rdm-preview-label">Body — edit if needed, then send (this exact text goes to the customer):</div>
                    <textarea
                        class="rdm-editbody"
                        v-model="editBody"
                        :disabled="sending || !!sendSuccess || alreadySent"
                        rows="14"
                        spellcheck="true"
                    ></textarea>
                    <p class="rdm-sig-note">The standard ShipTown signature (logo · ship.town · tagline) is appended automatically when sent — no need to add it here.</p>
                </template>

                <div v-if="autoSendArmed" class="rdm-armed-banner">
                    ⚡ Auto-send is armed — this reply will send automatically the moment it passes Security Check and reaches “Ready to Send”.
                    <button type="button" class="rdm-armed-cancel" :disabled="arming" @click="disarmAutoSend">Cancel auto-send</button>
                </div>
                <div v-if="sendError" class="rdm-send-error">{{ sendError }}</div>
                <!-- Blocked only because the ticket hasn't passed Security Check
                     yet. The operator is the final authority: offer BOTH an
                     immediate "send anyway" override AND arm-after-security. -->
                <div v-if="canForceSend && !sendSuccess" class="rdm-arm-offer">
                    <button type="button" class="btn btn-warning" :disabled="sending || arming" @click="forceSend">
                        {{ sending ? 'Sending…' : '⚠️ Send anyway — I confirm this goes out now' }}
                    </button>
                    <button v-if="!autoSendArmed" type="button" class="btn btn-arm" :disabled="arming || sending" @click="armAutoSend">
                        {{ arming ? 'Arming…' : '⚡ Or: send automatically once it passes Security Check' }}
                    </button>
                    <span v-if="armError" class="rdm-arm-error">{{ armError }}</span>
                </div>
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
                >{{ alreadySent ? 'Close' : 'Cancel' }}</button>
                <template v-if="!alreadySent">
                    <button
                        type="button"
                        class="btn btn-reject"
                        :disabled="sending || !!sendSuccess"
                        @click="requestReject"
                    >Reject + feedback</button>
                    <button
                        v-if="canOverrideMismatch"
                        type="button"
                        class="btn btn-warning"
                        :disabled="sending || !!sendSuccess"
                        @click="sendAnyway"
                    >{{ sending ? 'Sending&hellip;' : 'Send anyway' }}</button>
                    <button
                        v-else
                        type="button"
                        class="btn btn-primary"
                        :disabled="!canSend"
                        @click="send"
                    >{{ sending ? 'Sending&hellip;' : 'Send now' }}</button>
                </template>
            </footer>
        </div>
    </div>
</template>

<script>
import modalStackMixin from '../modalStackMixin';
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
    mixins: [modalStackMixin],
    name: 'ReplyDraftModal',
    props: {
        ticketId: { type: String, default: '' },
        isOpen: { type: Boolean, default: false },
    },
    emits: ['close', 'sent', 'reject-requested', 'armed'],
    data() {
        return {
            loading: false,
            loadError: '',
            draft: null,
            editBody: '',
            editCc: '',
            sending: false,
            sendError: '',
            sendSuccess: null,
            canOverrideMismatch: false,
            canForceSend: false,
            autoSendArmed: false,
            arming: false,
            armError: '',
        };
    },
    computed: {
        alreadySent() {
            return !!(this.draft && this.draft.sent_at);
        },
        canSend() {
            return !!this.draft && !this.sending && !this.sendSuccess && !this.alreadySent
                && this.editBody.trim() !== '';
        },
        ccIsEmpty() {
            const cc = (this.draft && this.draft.cc) || '';
            const norm = cc.trim().toLowerCase();
            return norm === '' || norm === '—' || norm === '_(none)_' || norm === '(none)';
        },
        originalCc() {
            if (!this.draft) return '';
            return this.ccIsEmpty ? '' : String(this.draft.cc || '').trim();
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
            this.editBody = '';
            this.editCc = '';
            this.sendError = '';
            this.sendSuccess = null;
            this.sending = false;
            this.canOverrideMismatch = false;
            this.canForceSend = false;
            this.autoSendArmed = false;
            this.arming = false;
            this.armError = '';
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
                this.editBody = this.draft && this.draft.body_markdown ? this.draft.body_markdown : '';
                this.editCc = this.originalCc;
                this.autoSendArmed = !!(this.draft && this.draft.auto_send_armed);
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
        friendlySendError(body, status) {
            const code = body && body.error;
            const current = body && body.current;
            if (code === 'wrong_section') {
                if (body && body.message) return body.message;
                return current
                    ? `This ticket is in "${current}", not "Ready to Send" — it hasn't passed Security Check yet. You can send it anyway if you're sure.`
                    : `The ticket isn't in "Ready to Send" yet (it hasn't passed Security Check). You can send it anyway if you're sure.`;
            }
            if (code === 'ticket_file_not_found') return "Can't send — the ticket file wasn't found.";
            if (code === 'bad_filename') return "Can't send — the ticket filename is malformed.";
            if (code === 'draft_not_found' || code === 'no_draft' || code === 'reply_draft_not_found') return "Can't send — no reply draft was found for this ticket.";
            if (code === 'already_sent') return 'This reply has already been sent.';
            if (code === 'invalid_cc') {
                return (body && body.message)
                    ? body.message
                    : 'One or more CC addresses are not valid email addresses.';
            }
            if (code === 'requester_mismatch') {
                return (body && body.message)
                    ? body.message
                    : `The draft is addressed to ${(body && body.to) || '(none)'} but the ticket requester is ${(body && body.requester) || '(none)'}. Confirm to send anyway.`;
            }
            if (code) return code + (body && body.message ? ': ' + body.message : '');
            return 'Send failed (HTTP ' + status + ').';
        },
        async send({ overrideMismatch = false, forceSection = false } = {}) {
            this.sending = true;
            this.sendError = '';
            try {
                // Send the edited text only if the user actually changed it;
                // otherwise send {} so the backend uses the original draft file
                // verbatim (no rendering drift for the untouched case).
                const original = (this.draft && this.draft.body_markdown ? this.draft.body_markdown : '').trim();
                const payload = {};
                if (this.editBody.trim() !== original) payload.body = this.editBody;
                if (this.editCc.trim() !== this.originalCc) payload.cc = this.editCc.trim();
                if (overrideMismatch) payload.confirm_requester_mismatch = true;
                if (forceSection) payload.confirm_wrong_section = true;
                const resp = await fetch(
                    '/api/tickets/' + encodeURIComponent(this.ticketId) + '/send-reply',
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify(payload),
                    }
                );
                const body = await resp.json().catch(() => ({}));
                if (!resp.ok) {
                    this.sendError = this.friendlySendError(body, resp.status);
                    // Offer an explicit override path for a recipient mismatch.
                    this.canOverrideMismatch = body && body.error === 'requester_mismatch' && body.can_override === true;
                    // Blocked only because it hasn't passed Security Check yet →
                    // the operator can force-send now, or arm auto-send for later.
                    this.canForceSend = body && body.error === 'wrong_section';
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
        sendAnyway() {
            this.canOverrideMismatch = false;
            this.send({ overrideMismatch: true });
        },
        forceSend() {
            // Operator confirmed the send should go out despite the ticket not
            // having reached "Ready to Send". Carry the requester-mismatch
            // override too if that was also flagged, so one click resolves both.
            const overrideMismatch = this.canOverrideMismatch;
            this.canForceSend = false;
            this.sendError = '';
            this.send({ forceSection: true, overrideMismatch });
        },
        async armAutoSend() {
            if (!this.draft || !this.draft.filename) {
                this.armError = 'No draft to arm.';
                return;
            }
            this.arming = true;
            this.armError = '';
            try {
                const resp = await fetch(
                    '/api/tickets/' + encodeURIComponent(this.ticketId) + '/arm-auto-send',
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ draft_filename: this.draft.filename }),
                    }
                );
                const body = await resp.json().catch(() => ({}));
                if (!resp.ok) throw new Error((body && (body.message || body.error)) || ('HTTP ' + resp.status));
                this.autoSendArmed = true;
                this.canForceSend = false;
                this.sendError = '';
                this.$emit('armed', { armed: true });
            } catch (e) {
                this.armError = 'Could not arm auto-send: ' + (e.message || e);
            } finally {
                this.arming = false;
            }
        },
        async disarmAutoSend() {
            this.arming = true;
            this.armError = '';
            try {
                const resp = await fetch(
                    '/api/tickets/' + encodeURIComponent(this.ticketId) + '/disarm-auto-send',
                    { method: 'POST', headers: { 'Accept': 'application/json' } }
                );
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                this.autoSendArmed = false;
                this.$emit('armed', { armed: false });
            } catch (e) {
                this.armError = 'Could not cancel auto-send: ' + (e.message || e);
            } finally {
                this.arming = false;
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
.rdm-cc-input {
    width: 100%;
    box-sizing: border-box;
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 6px;
    padding: 4px 8px;
    font-family: inherit;
    font-size: 12.5px;
    color: #c9d1d9;
}
.rdm-cc-input:focus { outline: none; border-color: #1f6feb; }
.rdm-cc-input:disabled { opacity: 0.6; }
.rdm-cc-input::placeholder { color: #6e7681; }

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
.rdm-editbody {
    width: 100%;
    box-sizing: border-box;
    min-height: 220px;
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 6px;
    padding: 12px 14px;
    font-family: inherit;
    font-size: 13px;
    line-height: 1.55;
    color: #c9d1d9;
    resize: vertical;
}
.rdm-editbody:focus { outline: none; border-color: #1f6feb; }
.rdm-editbody:disabled { opacity: 0.6; }
.rdm-sig-note { margin: 6px 0 0; font-size: 11.5px; color: #6e7681; font-style: italic; }

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
.rdm-sent-banner {
    margin: 0 0 12px;
    padding: 10px 14px;
    border: 1px solid #2ea04355;
    background: #0d1f17;
    color: #56d364;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
}

.rdm-armed-banner {
    margin: 0 0 12px;
    padding: 9px 12px;
    border: 1px solid #2ea04355;
    background: #0d1f17;
    color: #56d364;
    border-radius: 6px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.rdm-armed-cancel {
    margin-left: auto;
    background: transparent;
    border: 1px solid #2ea04366;
    color: #56d364;
    border-radius: 4px;
    padding: 3px 10px;
    font-size: 12px;
    cursor: pointer;
}
.rdm-armed-cancel:hover:not(:disabled) { background: #16311f; }
.rdm-armed-cancel:disabled { opacity: 0.5; cursor: default; }
.rdm-arm-offer {
    margin-top: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.btn-arm {
    background: #14332a;
    color: #56d364;
    border-color: #2ea04366;
}
.btn-arm:hover:not(:disabled) { background: #1a4636; border-color: #2ea043; }
.rdm-arm-error { color: #ffa198; font-size: 12.5px; }

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
.btn-warning {
    background: #9e6a00;
    color: #fff;
    border-color: #9e6a00;
}
.btn-warning:hover:not(:disabled) { background: #bd8200; }
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

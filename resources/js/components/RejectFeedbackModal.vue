<template>
    <div v-if="isOpen" class="rfm-overlay" @click.self="onCancel">
        <div class="rfm-modal" role="dialog" aria-label="Reject draft with feedback">
            <header class="rfm-header">
                <h3 class="rfm-title">Reject draft &amp; send back</h3>
                <button class="rfm-close" type="button" aria-label="Close" @click="onCancel">&times;</button>
            </header>

            <div class="rfm-body">
                <p class="rfm-hint">
                    The draft will go back to <strong>Reply Drafting</strong>. Your feedback is read by the
                    drafting agent as a hard constraint when composing the new version.
                </p>

                <label class="rfm-label" :for="textareaId">Feedback</label>
                <textarea
                    :id="textareaId"
                    ref="textareaRef"
                    v-model="feedback"
                    class="rfm-textarea"
                    rows="6"
                    :maxlength="MAX_LEN"
                    :disabled="submitting"
                    placeholder='What needs to change? E.g. "make the tone less formal", "address concern #3 separately"'
                ></textarea>

                <div class="rfm-counter-row">
                    <span class="rfm-counter" :class="{ 'rfm-counter-warn': feedback.length > (MAX_LEN - 50) }">
                        {{ feedback.length }} / {{ MAX_LEN }}
                    </span>
                    <span v-if="feedback.length > 0 && feedback.length < MIN_LEN" class="rfm-counter-warn">
                        min {{ MIN_LEN }} chars
                    </span>
                </div>

                <div v-if="errorMessage" class="rfm-error">{{ errorMessage }}</div>

                <div v-if="successMessage" class="rfm-success">{{ successMessage }}</div>
            </div>

            <footer class="rfm-footer">
                <button
                    type="button"
                    class="rfm-btn rfm-btn-secondary"
                    :disabled="submitting"
                    @click="onCancel"
                >Cancel</button>
                <button
                    type="button"
                    class="rfm-btn rfm-btn-primary"
                    :disabled="!canSubmit || submitting"
                    @click="onSubmit"
                >{{ submitting ? 'Sending back...' : 'Reject and send back' }}</button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * RejectFeedbackModal — small modal that asks the reviewer for a short note
 * explaining what needs to change, then POSTs `/api/tickets/{id}/reject-draft`.
 *
 * Props:
 *   - ticketId: String — the ticket id (e.g. "T66050").
 *   - isOpen:   Boolean — controls visibility.
 *
 * Emits:
 *   - @close     — user dismissed the modal (Cancel or overlay click).
 *   - @rejected  — server returned 200; payload is the server response object.
 *
 * On success the modal closes itself after a brief confirmation; the parent
 * is expected to refresh its data on `@rejected`.
 */
export default {
    name: 'RejectFeedbackModal',
    props: {
        ticketId: { type: String, default: '' },
        isOpen:   { type: Boolean, default: false },
    },
    emits: ['close', 'rejected'],
    data() {
        return {
            feedback: '',
            submitting: false,
            errorMessage: '',
            successMessage: '',
            MIN_LEN: 5,
            MAX_LEN: 500,
            textareaId: 'rfm-textarea-' + Math.random().toString(36).slice(2, 8),
        };
    },
    computed: {
        canSubmit() {
            const len = this.feedback.trim().length;
            return len >= this.MIN_LEN && len <= this.MAX_LEN;
        },
    },
    watch: {
        isOpen(open) {
            if (open) {
                this.feedback = '';
                this.errorMessage = '';
                this.successMessage = '';
                this.submitting = false;
                this.$nextTick(() => {
                    if (this.$refs.textareaRef) this.$refs.textareaRef.focus();
                });
            }
        },
    },
    methods: {
        onCancel() {
            if (this.submitting) return;
            this.$emit('close');
        },
        async onSubmit() {
            if (!this.canSubmit || this.submitting) return;
            this.submitting = true;
            this.errorMessage = '';
            this.successMessage = '';

            try {
                const url = '/api/tickets/' + encodeURIComponent(this.ticketId) + '/reject-draft';
                const resp = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ feedback: this.feedback.trim() }),
                });

                let body = null;
                try { body = await resp.json(); } catch (_) { /* ignore parse error */ }

                if (!resp.ok) {
                    this.errorMessage = this.extractErrorMessage(resp.status, body);
                    return;
                }

                this.successMessage = 'Draft sent back to Reply Drafting.';
                this.$emit('rejected', body);

                // Close shortly after so the user sees the confirmation.
                setTimeout(() => {
                    this.$emit('close');
                }, 900);
            } catch (e) {
                this.errorMessage = 'Network error: ' + (e.message || String(e));
            } finally {
                this.submitting = false;
            }
        },
        extractErrorMessage(status, body) {
            if (body && typeof body === 'object') {
                if (body.error === 'wrong_section') {
                    return 'Cannot reject draft: ticket is in "' + (body.current || 'unknown')
                        + '". ' + (body.message || '');
                }
                if (body.error === 'validation_failed' && body.messages) {
                    const first = Object.values(body.messages)[0];
                    if (Array.isArray(first) && first.length) return first[0];
                }
                if (body.error === 'ticket_file_not_found') {
                    return 'Ticket file not found.';
                }
                if (body.error) return String(body.error);
                if (body.message) return String(body.message);
            }
            return 'Request failed (HTTP ' + status + ').';
        },
    },
};
</script>

<style scoped>
.rfm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(1, 4, 9, 0.65);
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.rfm-modal {
    width: min(520px, 100%);
    background: #0d1117;
    color: #c9d1d9;
    border: 1px solid #30363d;
    border-radius: 8px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.rfm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #30363d;
    background: #161b22;
}
.rfm-title {
    font-size: 14px;
    font-weight: 600;
    margin: 0;
    color: #c9d1d9;
}
.rfm-close {
    background: transparent;
    border: 0;
    color: #8b949e;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.rfm-close:hover { color: #c9d1d9; }
.rfm-body { padding: 14px 16px; }
.rfm-hint {
    font-size: 12.5px;
    color: #8b949e;
    margin: 0 0 12px;
    line-height: 1.45;
}
.rfm-hint strong { color: #c9d1d9; }
.rfm-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #c9d1d9;
    margin-bottom: 4px;
}
.rfm-textarea {
    width: 100%;
    box-sizing: border-box;
    background: #161b22;
    color: #c9d1d9;
    border: 1px solid #30363d;
    border-radius: 6px;
    padding: 8px 10px;
    font-family: inherit;
    font-size: 13px;
    line-height: 1.45;
    resize: vertical;
}
.rfm-textarea:focus {
    outline: none;
    border-color: #1f6feb;
    box-shadow: 0 0 0 2px rgba(31, 111, 235, 0.3);
}
.rfm-textarea:disabled { opacity: 0.6; }
.rfm-counter-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 4px;
    font-size: 11px;
    color: #8b949e;
}
.rfm-counter-warn { color: #d29922; }
.rfm-error {
    margin-top: 10px;
    padding: 8px 10px;
    background: #2d1318;
    border: 1px solid #f8514955;
    border-radius: 6px;
    color: #ff7b72;
    font-size: 12.5px;
}
.rfm-success {
    margin-top: 10px;
    padding: 8px 10px;
    background: #102a1c;
    border: 1px solid #2ea04355;
    border-radius: 6px;
    color: #3fb950;
    font-size: 12.5px;
}
.rfm-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid #30363d;
    background: #161b22;
}
.rfm-btn {
    font-size: 13px;
    padding: 6px 14px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s;
}
.rfm-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.rfm-btn-secondary {
    background: #21262d;
    color: #c9d1d9;
    border-color: #30363d;
}
.rfm-btn-secondary:hover:not(:disabled) { background: #30363d; }
.rfm-btn-primary {
    background: #1f6feb;
    color: #fff;
    border-color: #1f6feb;
}
.rfm-btn-primary:hover:not(:disabled) { background: #388bfd; }
</style>

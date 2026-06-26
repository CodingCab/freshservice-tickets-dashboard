<template>
    <div v-if="isOpen" class="cm-overlay" @click.self="cancel">
        <div class="cm-dialog" role="dialog" aria-modal="true" :aria-label="title">
            <header class="cm-header">
                <h2 class="cm-title">{{ title }}</h2>
                <button type="button" class="cm-close" aria-label="Cancel" @click="cancel">&times;</button>
            </header>

            <div class="cm-body">
                <p class="cm-message">{{ message }}</p>
                <div v-if="requireReason" class="cm-reason-block">
                    <label class="cm-reason-label" for="cm-reason-textarea">
                        {{ reasonRequired ? 'Reason (required)' : 'Reason (optional)' }}
                    </label>
                    <textarea
                        id="cm-reason-textarea"
                        v-model="reason"
                        class="cm-reason"
                        rows="3"
                        :disabled="busy"
                        :placeholder="reasonPlaceholder || (reasonRequired ? 'Required — explain why' : 'Optional — explain why')"
                    ></textarea>
                </div>
            </div>

            <footer class="cm-footer">
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="busy"
                    @click="cancel"
                >Cancel</button>
                <button
                    type="button"
                    class="btn"
                    :class="confirmBtnClass"
                    :disabled="confirmDisabled"
                    @click="confirm"
                >{{ confirmLabel }}</button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * ConfirmModal — minimal reusable confirmation dialog with optional reason field.
 *
 * Props mirror ReplyDraftModal's visual conventions (.cm-overlay/.cm-dialog).
 * The parent owns the open/busy state; this component only emits.
 */
export default {
    name: 'ConfirmModal',
    props: {
        isOpen: { type: Boolean, default: false },
        title: { type: String, required: true },
        message: { type: String, default: '' },
        confirmLabel: { type: String, default: 'Confirm' },
        confirmVariant: { type: String, default: 'primary' }, // 'primary' | 'danger'
        requireReason: { type: Boolean, default: false },
        reasonRequired: { type: Boolean, default: false },
        reasonPlaceholder: { type: String, default: '' },
        busy: { type: Boolean, default: false },
    },
    emits: ['close', 'confirm'],
    data() {
        return { reason: '' };
    },
    computed: {
        confirmBtnClass() {
            return this.confirmVariant === 'danger' ? 'btn-danger' : 'btn-primary';
        },
        confirmDisabled() {
            if (this.busy) return true;
            if (this.requireReason && this.reasonRequired && this.reason.trim().length === 0) {
                return true;
            }
            return false;
        },
    },
    watch: {
        isOpen(open) {
            if (open) this.reason = '';
        },
    },
    methods: {
        cancel() {
            if (this.busy) return;
            this.$emit('close');
        },
        confirm() {
            if (this.confirmDisabled) return;
            this.$emit('confirm', { reason: this.reason.trim() });
        },
    },
};
</script>

<style scoped>
.cm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(1, 4, 9, 0.7);
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.cm-dialog {
    width: min(520px, 100%);
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
.cm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #30363d;
    background: #161b22;
}
.cm-title {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: #c9d1d9;
}
.cm-close {
    background: transparent;
    border: 0;
    color: #8b949e;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.cm-close:hover { color: #c9d1d9; }

.cm-body {
    flex: 1;
    overflow-y: auto;
    padding: 14px 18px;
}
.cm-message {
    margin: 0 0 12px;
    font-size: 13px;
    line-height: 1.5;
    color: #c9d1d9;
    white-space: pre-wrap;
}
.cm-reason-block {
    margin-top: 10px;
}
.cm-reason-label {
    display: block;
    font-size: 12px;
    color: #8b949e;
    margin-bottom: 4px;
}
.cm-reason {
    width: 100%;
    box-sizing: border-box;
    background: #161b22;
    color: #c9d1d9;
    border: 1px solid #30363d;
    border-radius: 6px;
    padding: 8px 10px;
    font-family: inherit;
    font-size: 13px;
    resize: vertical;
    min-height: 60px;
}
.cm-reason:focus {
    outline: none;
    border-color: #1f6feb;
}

.cm-footer {
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
.btn-danger {
    background: #b62324;
    color: #fff;
    border-color: #b62324;
}
.btn-danger:hover:not(:disabled) {
    background: #da3633;
    border-color: #da3633;
}
</style>

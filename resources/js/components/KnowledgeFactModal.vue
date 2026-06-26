<template>
    <div v-if="isOpen" class="cm-overlay" @click.self="cancel">
        <div class="cm-dialog" role="dialog" aria-modal="true" aria-label="Add knowledge fact">
            <header class="cm-header">
                <h2 class="cm-title">Add knowledge fact</h2>
                <button type="button" class="cm-close" aria-label="Cancel" @click="cancel">&times;</button>
            </header>

            <div class="cm-body">
                <p class="cm-message">
                    Describe in your own words what every agent should know going forward. The
                    triage agent then processes it automatically — picks the right category,
                    rephrases as needed, and slots it into the knowledge base. Applies to all
                    relevant future tickets, not just this one.
                </p>

                <textarea
                    id="kb-fact"
                    v-model="fact"
                    class="cm-reason"
                    rows="8"
                    :disabled="busy"
                    :placeholder="placeholderExample"
                ></textarea>
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
                    class="btn btn-primary"
                    :disabled="confirmDisabled"
                    @click="confirm"
                >Save knowledge fact</button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * KnowledgeFactModal — capture a fact for the knowledge base.
 *
 * Single textarea: the submitter writes WHAT should be added. The
 * downstream triage handler picks the category, the title, the keywords,
 * and the slot (append to existing / amend existing / new category).
 * Submitters don't see categories — they just describe the fact.
 */
export default {
    name: 'KnowledgeFactModal',
    props: {
        isOpen: { type: Boolean, default: false },
        busy: { type: Boolean, default: false },
    },
    emits: ['close', 'confirm'],
    data() {
        return { fact: '' };
    },
    computed: {
        confirmDisabled() {
            if (this.busy) return true;
            if (!this.fact.trim()) return true;
            return false;
        },
        placeholderExample() {
            return [
                'e.g. We use a branded build of PrintNode called "ShipTown Print Client".',
                '',
                'Every customer has separate login credentials for it. The ShipTown Print Client download link is at [xxx].',
            ].join('\n');
        },
    },
    watch: {
        isOpen(open) {
            if (open) this.fact = '';
        },
    },
    methods: {
        cancel() {
            if (this.busy) return;
            this.$emit('close');
        },
        confirm() {
            if (this.confirmDisabled) return;
            this.$emit('confirm', { fact: this.fact.trim() });
        },
    },
};
</script>

<style scoped>
/* Copied from ConfirmModal.vue (its `<style scoped>` doesn't reach here).
   Kept in sync deliberately — these two modals share a visual language. */
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
    min-height: 120px;
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
</style>

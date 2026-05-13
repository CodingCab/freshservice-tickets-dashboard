<template>
    <div class="ticket-actions">
        <button
            type="button"
            class="btn btn-primary"
            :disabled="!canSendDraft"
            @click="emitAction('send-reply')"
        >Send draft</button>
        <button
            type="button"
            class="btn btn-secondary"
            @click="emitAction('reject-draft')"
        >Reject + feedback</button>
        <button
            type="button"
            class="btn btn-secondary"
            @click="emitAction('add-subtask')"
        >Add follow-on subtask</button>
        <button
            type="button"
            class="btn btn-secondary"
            @click="emitAction('complete-consult')"
        >Complete consult</button>
        <button
            type="button"
            class="btn btn-secondary"
            @click="emitAction('human-review')"
        >Override to Human Review</button>
        <button
            type="button"
            class="btn btn-danger"
            @click="emitAction('close')"
        >Close ticket</button>
    </div>
</template>

<script>
/**
 * TicketActions — button row at the top of TicketDetail.
 *
 * Phase 2: every button just emits `@action` with a string key. The parent
 * (TicketDetail) is expected to log them; Phase 3 / 4 will wire real modals
 * and POST handlers.
 *
 * The Send-draft gating logic ("only when an `(agent, draft proposal)` block
 * exists AND `Security Check` indicates pass") is intentionally not enforced
 * yet — `canSendDraft` always returns true. The hook is in place so Phase 3
 * can tighten it without restructuring the template.
 */
export default {
    name: 'TicketActions',
    props: {
        data: { type: Object, default: null },
    },
    emits: ['action'],
    computed: {
        canSendDraft() {
            // Phase 2: always enabled. Phase 3 will gate on:
            //   - data.replies contains an entry with role=agent, type=draft proposal
            //   - data.metadata['Security Check'] indicates pass
            return true;
        },
    },
    methods: {
        emitAction(key) {
            this.$emit('action', key);
        },
    },
};
</script>

<style scoped>
.ticket-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 10px 14px;
    border-bottom: 1px solid #30363d;
    background: #161b22;
}
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
.btn-secondary {
    background: #21262d;
    color: #c9d1d9;
    border-color: #30363d;
}
.btn-secondary:hover:not(:disabled) {
    background: #30363d;
}
.btn-danger {
    background: #21262d;
    color: #f85149;
    border-color: #f8514955;
}
.btn-danger:hover:not(:disabled) {
    background: #4a1d1d;
    border-color: #f85149;
}
</style>

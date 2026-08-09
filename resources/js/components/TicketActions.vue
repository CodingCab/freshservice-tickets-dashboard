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
            @click="emitAction('compose-reply')"
        >Compose reply</button>
        <button
            type="button"
            class="btn btn-secondary"
            title="Tell the agent what to do (draft a reply, create a subtask, look up data, etc.). The agent reads the full ticket context, follows your instruction, and the result lands in the ticket's pipeline."
            @click="emitAction('ai-compose')"
        >🤖 Ask agent</button>
        <button
            type="button"
            class="btn btn-secondary"
            title="Split a second, unrelated issue the customer raised into its own new ticket (created in FreshService too). You give it a new subject + description; requester and CC carry over. This ticket stays as-is."
            @click="emitAction('split')"
        >✂ Split ticket</button>
        <button
            type="button"
            class="btn btn-secondary"
            @click="emitAction('human-review')"
        >Override to Human Review</button>
        <button
            type="button"
            class="btn btn-secondary btn-feedback"
            title="Report what the automation should have done differently. Instructions are updated so similar cases work better next time, not just this one."
            @click="emitAction('report-automation')"
        >⚑ Report automation issue</button>
        <button
            type="button"
            class="btn btn-secondary btn-feedback"
            title="Add a fact agents should know going forward. Triage decides where it fits; applies to all future relevant cases."
            @click="emitAction('add-knowledge-fact')"
        >📚 Add knowledge fact</button>
        <div class="status-dropdown">
            <button
                type="button"
                class="btn btn-danger dropdown-toggle"
                :title="isClosed ? 'This ticket is Closed — reopen it (Open/Pending) or change its status. You decide.' : 'Change the ticket status.'"
                @click="toggleStatusMenu"
                aria-haspopup="true"
                :aria-expanded="statusMenuOpen ? 'true' : 'false'"
            >{{ isClosed ? 'Reopen / set status ▾' : 'Set status ▾' }}</button>
            <ul v-if="statusMenuOpen" class="status-menu" role="menu">
                <li role="none">
                    <button type="button" role="menuitem" class="status-menu-item"
                            @click="pickStatus('open')">Open</button>
                </li>
                <li role="none">
                    <button type="button" role="menuitem" class="status-menu-item"
                            @click="pickStatus('pending')">Pending</button>
                </li>
                <li role="none">
                    <button type="button" role="menuitem" class="status-menu-item"
                            @click="pickStatus('resolved')">Resolved</button>
                </li>
                <li role="none">
                    <button type="button" role="menuitem" class="status-menu-item status-menu-item--danger"
                            @click="pickStatus('closed')">Closed</button>
                </li>
            </ul>
        </div>
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
    data() {
        return { statusMenuOpen: false };
    },
    mounted() {
        document.addEventListener('click', this.handleDocumentClick);
    },
    beforeUnmount() {
        document.removeEventListener('click', this.handleDocumentClick);
    },
    computed: {
        canSendDraft() {
            // Enabled only when (a) there's a non-stale current draft and
            // (b) the most recent Security Check verdict on the parent ticket
            // is `pass`. Failed-and-stale drafts are explicitly not sendable —
            // they're either auto-retrying in Reply Drafting (rewritable
            // findings) or parked in Human Review for admin action
            // (infrastructural findings). If the draft has already been sent,
            // the Send button isn't relevant — return false to keep it
            // disabled (the "Reply sent" badge handles that state).
            if (!this.data || !this.data.metadata) return false;

            // Already sent → no Send action needed
            if (this.data.reply_draft && this.data.reply_draft.sent_at) return false;

            // No draft to send
            if (!this.data.reply_draft) return false;

            // Reply Draft metadata marked stale (rejected / superseded by security retry)
            const draftMeta = String(this.data.metadata['Reply Draft'] || '');
            if (draftMeta.toLowerCase().includes('stale')) return false;

            // Security Check must have run and verdict must be pass
            const sec = String(this.data.metadata['Security Check'] || '').trim().toLowerCase();
            if (!sec || !sec.startsWith('pass')) return false;

            return true;
        },
        isClosed() {
            const status = (this.data && this.data.metadata && this.data.metadata.Status) || '';
            return String(status).trim().toLowerCase() === 'closed';
        },
    },
    methods: {
        emitAction(key) {
            this.$emit('action', key);
        },
        toggleStatusMenu(event) {
            event.stopPropagation();
            this.statusMenuOpen = !this.statusMenuOpen;
        },
        pickStatus(name) {
            this.statusMenuOpen = false;
            this.$emit('action', 'set-status:' + name);
        },
        handleDocumentClick(event) {
            if (!this.statusMenuOpen) return;
            if (!this.$el || !this.$el.contains(event.target)) {
                this.statusMenuOpen = false;
            }
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
.btn-feedback {
    margin-left: auto;
    color: #d29922;
    border-color: #d2992255;
}
.btn-feedback:hover:not(:disabled) {
    background: #3a2d12;
    border-color: #d29922;
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
.status-dropdown {
    position: relative;
}
.status-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    min-width: 160px;
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 6px;
    list-style: none;
    margin: 0;
    padding: 4px;
    z-index: 50;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
}
.status-menu-item {
    width: 100%;
    text-align: left;
    background: transparent;
    color: #c9d1d9;
    border: 0;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 13px;
    cursor: pointer;
}
.status-menu-item:hover {
    background: #21262d;
}
.status-menu-item--danger {
    color: #f85149;
}
.status-menu-item--danger:hover {
    background: #4a1d1d;
}
</style>

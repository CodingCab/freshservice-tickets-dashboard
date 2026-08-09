<template>
    <div v-if="isOpen" class="stm-overlay">
        <div class="stm-dialog" role="dialog" aria-modal="true" aria-label="Split ticket">
            <header class="stm-header">
                <h2 class="stm-title">Split ticket &mdash; {{ ticketId }}</h2>
                <button type="button" class="stm-close" aria-label="Cancel" @click="cancel">&times;</button>
            </header>

            <div class="stm-body">
                <p class="stm-intro">
                    Create a NEW, standalone ticket for a separate issue raised in this thread.
                    Requester and CC carry over from this ticket, and the new ticket is created in
                    FreshService too — it then runs the pipeline like any other. This ticket stays as-is.
                </p>

                <dl class="stm-carry">
                    <dt>Requester</dt><dd>{{ requesterDisplay || '—' }}</dd>
                    <dt>CC</dt><dd>{{ ccDisplay || '(none)' }}</dd>
                </dl>

                <label class="stm-label" for="stm-subject">New subject</label>
                <input
                    id="stm-subject"
                    type="text"
                    class="stm-subject"
                    v-model="subject"
                    :disabled="busy || !!done"
                    placeholder="e.g. Inventory search returns no result for SKU"
                    spellcheck="true"
                />

                <label class="stm-label" for="stm-body">New description (what the new ticket is about)</label>
                <textarea
                    id="stm-body"
                    class="stm-textbody"
                    v-model="body"
                    :disabled="busy || !!done"
                    rows="10"
                    spellcheck="true"
                    placeholder="Describe the second issue as if the customer submitted it fresh."
                ></textarea>

                <template v-if="availableAttachments && availableAttachments.length">
                    <div class="stm-att-head">
                        <span class="stm-label stm-att-label">Carry attachments to the new ticket</span>
                        <button type="button" class="stm-att-all" :disabled="busy || !!done" @click="toggleAll">
                            {{ allSelected ? 'Clear all' : 'Select all' }}
                        </button>
                    </div>
                    <div class="stm-att-grid">
                        <label v-for="att in availableAttachments" :key="att.filename"
                               class="stm-att-tile" :class="{ 'stm-att-selected': selected[att.filename] }">
                            <input type="checkbox" class="stm-att-check"
                                   :disabled="busy || !!done"
                                   :checked="!!selected[att.filename]"
                                   @change="toggleOne(att.filename)" />
                            <img v-if="isImage(att.filename)" :src="attachmentUrl(att.filename)" :alt="att.filename" class="stm-att-thumb" />
                            <span v-else class="stm-att-fileicon">📄</span>
                            <span class="stm-att-name">{{ att.filename }}</span>
                        </label>
                    </div>
                </template>

                <div v-if="error" class="stm-error">{{ error }}</div>
                <div v-if="done" class="stm-success">
                    ✅ Created new ticket #{{ done.new_ticket_id }}.
                    <a v-if="done.new_ticket_url" :href="done.new_ticket_url" target="_blank" rel="noopener">Open in FreshService</a>
                </div>
            </div>

            <footer class="stm-footer">
                <button type="button" class="btn btn-secondary" :disabled="busy" @click="cancel">
                    {{ done ? 'Close' : 'Cancel' }}
                </button>
                <button
                    v-if="!done"
                    type="button"
                    class="btn btn-primary"
                    :disabled="!canSubmit"
                    @click="submit"
                >{{ busy ? 'Splitting…' : '✂ Create split ticket' }}</button>
            </footer>
        </div>
    </div>
</template>

<script>
import modalStackMixin from '../modalStackMixin';
/**
 * SplitTicketModal — split a second issue out of a ticket into a new standalone
 * FreshService ticket. The operator gives a new subject + body; requester/CC
 * carry over (shown read-only for reassurance). On confirm it POSTs to
 * /api/tickets/{id}/split and emits `split` with the server response.
 */
export default {
    mixins: [modalStackMixin],
    name: 'SplitTicketModal',
    props: {
        ticketId: { type: String, default: '' },
        isOpen:   { type: Boolean, default: false },
        // Carried-over context for display only (from the ticket detail data).
        requesterDisplay: { type: String, default: '' },
        ccDisplay:        { type: String, default: '' },
        // All attachments across the whole conversation, [{filename}, ...].
        availableAttachments: { type: Array, default: () => [] },
    },
    emits: ['close', 'split'],
    data() {
        return { subject: '', body: '', busy: false, error: '', done: null, selected: {} };
    },
    computed: {
        canSubmit() {
            return !this.busy && !this.done && this.subject.trim() !== '' && this.body.trim() !== '';
        },
        allSelected() {
            return this.availableAttachments.length > 0
                && this.availableAttachments.every(a => this.selected[a.filename]);
        },
    },
    watch: {
        isOpen(open) {
            if (open) { this.subject = ''; this.body = ''; this.error = ''; this.done = null; this.busy = false; this.selected = {}; }
        },
    },
    methods: {
        cancel() {
            if (this.busy) return;
            this.$emit('close');
        },
        isImage(name) {
            return /\.(png|jpe?g|gif|webp|svg)$/i.test(name || '');
        },
        attachmentUrl(filename) {
            return '/api/tickets/' + encodeURIComponent(this.ticketId)
                + '/attachment/' + encodeURIComponent(filename);
        },
        toggleOne(filename) {
            // Reassign for reactivity.
            this.selected = { ...this.selected, [filename]: !this.selected[filename] };
        },
        toggleAll() {
            if (this.allSelected) {
                this.selected = {};
            } else {
                const next = {};
                for (const a of this.availableAttachments) next[a.filename] = true;
                this.selected = next;
            }
        },
        async submit() {
            if (!this.canSubmit) return;
            this.busy = true;
            this.error = '';
            try {
                const attachments = this.availableAttachments
                    .map(a => a.filename)
                    .filter(f => this.selected[f]);
                const resp = await fetch('/api/tickets/' + encodeURIComponent(this.ticketId) + '/split', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ subject: this.subject.trim(), body: this.body, attachments }),
                });
                const b = await resp.json().catch(() => ({}));
                if (!resp.ok) throw new Error((b && (b.message || b.error)) || ('HTTP ' + resp.status));
                this.done = b;
                this.$emit('split', b);
            } catch (e) {
                this.error = e.message || String(e);
            } finally {
                this.busy = false;
            }
        },
    },
};
</script>

<style scoped>
.stm-overlay {
    position: fixed; inset: 0; background: rgba(1, 4, 9, 0.7); z-index: 1100;
    display: flex; align-items: center; justify-content: center; padding: 24px;
}
.stm-dialog {
    width: min(680px, 100%); max-height: 90vh; background: #0d1117; color: #c9d1d9;
    border: 1px solid #30363d; border-radius: 8px; box-shadow: 0 12px 36px rgba(0,0,0,0.6);
    display: flex; flex-direction: column; overflow: hidden;
}
.stm-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 16px; border-bottom: 1px solid #30363d; background: #161b22;
}
.stm-title { margin: 0; font-size: 15px; font-weight: 600; color: #c9d1d9; }
.stm-close { background: transparent; border: 0; color: #8b949e; font-size: 22px; line-height: 1; cursor: pointer; padding: 0 6px; }
.stm-close:hover { color: #c9d1d9; }
.stm-body { flex: 1; overflow-y: auto; padding: 14px 18px; }
.stm-intro { margin: 0 0 12px; font-size: 12.5px; line-height: 1.5; color: #8b949e; }
.stm-carry {
    display: grid; grid-template-columns: max-content 1fr; gap: 3px 12px;
    font-size: 12.5px; margin: 0 0 14px; padding: 8px 10px;
    background: #161b22; border: 1px solid #21262d; border-radius: 6px;
}
.stm-carry dt { color: #8b949e; font-weight: 600; }
.stm-carry dd { margin: 0; color: #c9d1d9; word-break: break-word; }
.stm-label { display: block; font-size: 12px; color: #8b949e; margin: 8px 0 4px; }
.stm-subject, .stm-textbody {
    width: 100%; box-sizing: border-box; background: #161b22; border: 1px solid #30363d;
    border-radius: 6px; padding: 8px 10px; font-family: inherit; font-size: 13px;
    color: #c9d1d9; line-height: 1.5;
}
.stm-textbody { resize: vertical; min-height: 160px; }
.stm-subject:focus, .stm-textbody:focus { outline: none; border-color: #1f6feb; }
.stm-subject:disabled, .stm-textbody:disabled { opacity: 0.6; }
.stm-att-head {
    display: flex; align-items: center; justify-content: space-between; margin-top: 10px;
}
.stm-att-label { margin: 0; }
.stm-att-all {
    background: #21262d; color: #c9d1d9; border: 1px solid #30363d; border-radius: 4px;
    padding: 2px 10px; font-size: 12px; cursor: pointer;
}
.stm-att-all:hover:not(:disabled) { background: #30363d; }
.stm-att-all:disabled { opacity: 0.5; cursor: default; }
.stm-att-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 8px; margin-top: 6px;
}
.stm-att-tile {
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    padding: 8px 6px; background: #161b22; border: 1px solid #30363d; border-radius: 6px;
    cursor: pointer; position: relative;
}
.stm-att-tile:hover { border-color: #6e7681; }
.stm-att-selected { border-color: #1f6feb; background: #0c1a2e; }
.stm-att-check { position: absolute; top: 6px; left: 6px; width: 15px; height: 15px; cursor: pointer; }
.stm-att-thumb { max-width: 100%; max-height: 72px; border-radius: 4px; object-fit: contain; }
.stm-att-fileicon { font-size: 30px; line-height: 1.4; }
.stm-att-name { font-size: 11px; color: #8b949e; word-break: break-all; text-align: center; line-height: 1.3; }

.stm-error {
    margin-top: 12px; padding: 8px 12px; border: 1px solid #f8514955;
    background: #4a1d1d; color: #ffa198; border-radius: 6px; font-size: 13px;
}
.stm-success {
    margin-top: 12px; padding: 8px 12px; border: 1px solid #2ea04355;
    background: #0d1f17; color: #56d364; border-radius: 6px; font-size: 13px;
}
.stm-success a { color: #58a6ff; margin-left: 6px; }
.stm-footer {
    display: flex; justify-content: flex-end; gap: 8px; padding: 12px 16px;
    border-top: 1px solid #30363d; background: #161b22;
}
.btn { font-size: 13px; padding: 6px 14px; border-radius: 6px; border: 1px solid transparent; cursor: pointer; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary { background: #1f6feb; color: #fff; border-color: #1f6feb; }
.btn-primary:hover:not(:disabled) { background: #388bfd; }
.btn-secondary { background: #21262d; color: #c9d1d9; border-color: #30363d; }
.btn-secondary:hover:not(:disabled) { background: #30363d; }
</style>

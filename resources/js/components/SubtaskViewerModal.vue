<template>
    <div v-if="isOpen" class="svm-overlay" @click.self="close">
        <div class="svm-dialog" role="dialog" aria-modal="true" aria-label="Subtask viewer">
            <header class="svm-header">
                <h2 class="svm-title">
                    Subtask
                    <span v-if="title" class="svm-title-sub">&mdash; {{ title }}</span>
                </h2>
                <button class="svm-close" type="button" aria-label="Close" @click="close">&times;</button>
            </header>

            <div class="svm-body">
                <div v-if="loading" class="svm-status">Loading subtask&hellip;</div>
                <div v-else-if="loadError" class="svm-status svm-error">{{ loadError }}</div>
                <pre v-else class="svm-content">{{ content }}</pre>
                <div v-if="filename" class="svm-path">{{ filename }}</div>
            </div>

            <footer class="svm-footer">
                <button type="button" class="btn btn-secondary" @click="close">Close</button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * SubtaskViewerModal — fetches and shows a subtask markdown file's raw content.
 *
 * Props:
 *   - ticketId: String  (e.g. "T66199")
 *   - filename: String  (e.g. "20260515-T66199-sub-01-extend-session-timeout.md")
 *   - title:    String  optional, rendered in the header for context
 *   - isOpen:   Boolean
 */
export default {
    name: 'SubtaskViewerModal',
    props: {
        ticketId: { type: String, default: '' },
        filename: { type: String, default: '' },
        title:    { type: String, default: '' },
        isOpen:   { type: Boolean, default: false },
    },
    emits: ['close'],
    data() {
        return {
            loading: false,
            loadError: '',
            content: '',
        };
    },
    watch: {
        isOpen(open) {
            if (open) this.load();
        },
        filename() {
            if (this.isOpen) this.load();
        },
    },
    methods: {
        close() { this.$emit('close'); },
        async load() {
            if (!this.ticketId || !this.filename) return;
            this.loading = true;
            this.loadError = '';
            this.content = '';
            try {
                const url = '/api/tickets/' + encodeURIComponent(this.ticketId)
                    + '/subtask/' + encodeURIComponent(this.filename);
                const resp = await fetch(url);
                const body = await resp.json().catch(() => ({}));
                if (!resp.ok) {
                    this.loadError = body && body.error
                        ? body.error + (body.path ? ': ' + body.path : '')
                        : 'HTTP ' + resp.status;
                    return;
                }
                this.content = body.content || '';
            } catch (e) {
                this.loadError = e.message || String(e);
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>

<style scoped>
.svm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(1, 4, 9, 0.7);
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.svm-dialog {
    width: min(900px, 100%);
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
.svm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #30363d;
    background: #161b22;
}
.svm-title {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #c9d1d9;
}
.svm-title-sub { color: #8b949e; font-weight: 500; }
.svm-close {
    background: transparent;
    border: 0;
    color: #8b949e;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    padding: 0 6px;
}
.svm-close:hover { color: #c9d1d9; }
.svm-body {
    flex: 1;
    overflow: hidden;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
}
.svm-status { padding: 12px; color: #8b949e; text-align: center; }
.svm-error { color: #f85149; }
.svm-content {
    flex: 1;
    overflow: auto;
    margin: 0;
    padding: 12px;
    background: #161b22;
    border: 1px solid #21262d;
    border-radius: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    color: #c9d1d9;
    white-space: pre-wrap;
    word-break: break-word;
}
.svm-path {
    margin-top: 6px;
    font-size: 11px;
    color: #8b949e;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    word-break: break-all;
}
.svm-footer {
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
.btn-secondary {
    background: #21262d;
    color: #c9d1d9;
    border-color: #30363d;
}
.btn-secondary:hover { background: #30363d; }
</style>

<template>
    <div class="tl-section-block">
        <div class="tl-section-row">
            <span class="tl-section-name">{{ section.name }}</span>
            <span class="tl-section-count" :class="{ 'has-items': has }">{{ count }}</span>
        </div>
        <div v-if="handlers.length" class="tl-section-handlers">
            <div
                v-for="h in handlers"
                :key="h.name"
                class="tl-handler"
                :class="'tl-handler-' + h.type"
                :title="'Event listener: ' + h.name"
            >
                <span class="tl-handler-arrow">▸</span>
                <span class="tl-handler-name">{{ h.name }}</span>
                <span class="tl-handler-type">({{ h.type }})</span>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'TaskListSectionRow',
    props: {
        section: { type: Object, required: true },
    },
    computed: {
        count() { return this.section.count || 0; },
        has() { return this.count > 0; },
        handlers() { return this.section.handlers || []; },
    },
};
</script>

<style scoped>
.tl-section-block {
    margin-bottom: 4px;
}
.tl-section-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #21262d;
    border: 1px solid #30363d;
    border-radius: 6px;
    padding: 5px 9px;
    font-size: 12px;
}
.tl-section-handlers {
    margin: 3px 0 0 10px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.tl-handler {
    font-size: 10.5px;
    line-height: 1.3;
    color: #8b949e;
    display: flex;
    gap: 4px;
    align-items: baseline;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tl-handler-arrow { color: #6e7681; }
.tl-handler-name {
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 0 1 auto;
    min-width: 0;
}
.tl-handler-type {
    font-size: 9.5px;
    padding: 0 4px;
    border-radius: 6px;
    background: #30363d;
    color: #8b949e;
}
.tl-handler-md .tl-handler-name { color: #d2a8ff; }
.tl-handler-md .tl-handler-type { background: #3c2a4d; color: #d2a8ff; }
.tl-handler-py .tl-handler-name { color: #7ee787; }
.tl-handler-py .tl-handler-type { background: #1f3a26; color: #7ee787; }
.tl-handler-sh .tl-handler-name { color: #79c0ff; }
.tl-handler-sh .tl-handler-type { background: #1f2f4d; color: #79c0ff; }
.tl-section-name {
    color: #c9d1d9;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}
.tl-section-count {
    background: #30363d;
    color: #8b949e;
    padding: 1px 7px;
    border-radius: 10px;
    font-size: 11px;
    min-width: 22px;
    text-align: center;
}
.tl-section-count.has-items {
    background: #1f6feb;
    color: #fff;
}
</style>

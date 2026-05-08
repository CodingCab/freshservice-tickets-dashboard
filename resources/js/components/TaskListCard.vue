<template>
    <div class="tl-card">
        <div class="tl-card-title">{{ tl.file }}</div>
        <task-list-section-row v-for="s in sections" :key="s.name" :section="s" />
        <div class="tl-card-meta">{{ sections.length }} sections &middot; {{ total }} items</div>
    </div>
</template>

<script>
import TaskListSectionRow from './TaskListSectionRow.vue';

export default {
    name: 'TaskListCard',
    components: { TaskListSectionRow },
    props: {
        tl: { type: Object, required: true },
    },
    computed: {
        sections() { return this.tl.sections || []; },
        total() { return this.sections.reduce((s, x) => s + (x.count || 0), 0); },
    },
};
</script>

<style scoped>
.tl-card {
    background: linear-gradient(180deg, #1c2128, #161b22);
    border: 1px solid #30363d;
    border-radius: 10px;
    padding: 12px;
}
.tl-card-title {
    font-size: 13px;
    font-weight: 600;
    color: #58a6ff;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid #30363d;
    word-break: break-word;
}
.tl-card-meta {
    font-size: 11px;
    color: #6e7681;
    margin-top: 6px;
}
</style>

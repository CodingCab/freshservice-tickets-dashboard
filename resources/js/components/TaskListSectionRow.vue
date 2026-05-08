<template>
    <div class="tl-section-row">
        <span class="tl-section-name">{{ section.name }}</span>
        <span class="tl-section-count" :class="{ 'has-items': has }">{{ count }}</span>
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
    },
};
</script>

<style scoped>
.tl-section-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #21262d;
    border: 1px solid #30363d;
    border-radius: 6px;
    padding: 5px 9px;
    font-size: 12px;
    margin-bottom: 4px;
}
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

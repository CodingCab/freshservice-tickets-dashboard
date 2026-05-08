<template>
    <div>
        <div class="header">
            <h1>Task Lists</h1>
            <button class="refresh-btn" @click="load">&#x21bb; Refresh</button>
            <a :href="jsonUrl" target="_blank" class="json-link">JSON</a>
            <span class="last-updated">{{ generatedAt ? 'Generated: ' + generatedAt : '' }}</span>
        </div>
        <div v-if="error" class="empty error">
            Failed to load task-lists.json: {{ error }}
        </div>
        <div v-else-if="loading && !taskLists.length" class="empty">Loading...</div>
        <div v-else-if="!taskLists.length" class="empty">No task lists.</div>
        <div v-else class="tl-grid">
            <task-list-card v-for="tl in taskLists" :key="tl.file" :tl="tl" />
        </div>
    </div>
</template>

<script>
import TaskListCard from './TaskListCard.vue';

export default {
    name: 'TaskLists',
    components: { TaskListCard },
    data() {
        return {
            jsonUrl: '/www/task-lists.json',
            generatedAt: '',
            loading: false,
            error: '',
            taskLists: [],
        };
    },
    mounted() {
        this.load();
    },
    methods: {
        async load() {
            this.loading = true;
            this.error = '';
            try {
                const resp = await fetch(this.jsonUrl + '?t=' + Date.now());
                const data = await resp.json();
                this.generatedAt = data.generated_at || '';
                this.taskLists = data.task_lists || [];
            } catch (e) {
                this.error = e.message;
                this.taskLists = [];
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>

<style scoped>
.tl-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 14px;
    padding: 8px;
}
.empty.error {
    color: #f85149;
}
</style>

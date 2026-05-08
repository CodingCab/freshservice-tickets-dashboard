/**
 * Vite entry — Vue 3 SPA bootstrap.
 *
 * Mirrors ShipTown's component-system pattern: components live in
 * resources/js/components/*.vue (Single File Components), and we register
 * them globally on a single Vue app, then mount it.
 *
 * The legacy hand-written /public/js/app.js (Tickets + Agents tabs) renders
 * its DOM imperatively into #app and includes a placeholder element
 * <div id="task-lists-app"><task-lists /></div> for the Vue side. It calls
 * window.mountTaskListsApp() once that placeholder is in the DOM.
 */

import './bootstrap';
import { createApp } from 'vue';

import TaskLists from './components/TaskLists.vue';
import TaskListCard from './components/TaskListCard.vue';
import TaskListSectionRow from './components/TaskListSectionRow.vue';

let _mountedVm = null;

function mountTaskListsApp() {
    if (_mountedVm) return _mountedVm;
    const el = document.getElementById('task-lists-app');
    if (!el) return null;

    // Mount TaskLists as the root so external code can call vm.load().
    // Children are still registered globally so they can be referenced
    // by kebab-case tags inside any template (ShipTown-style).
    const app = createApp(TaskLists);
    app.component('task-lists', TaskLists);
    app.component('task-list-card', TaskListCard);
    app.component('task-list-section-row', TaskListSectionRow);

    _mountedVm = app.mount(el);
    return _mountedVm;
}

window.mountTaskListsApp = mountTaskListsApp;

// Legacy interop: the hand-written SPA shell calls these.
window.TASK_LISTS_JSON = '/www/task-lists.json';
window.taskListsTabHTML = () => `
    <div id="task-lists-tab" class="tab-page" style="display:none;">
        <div id="task-lists-app"></div>
    </div>
`;
window.loadTaskLists = () => {
    const vm = mountTaskListsApp();
    if (vm && typeof vm.load === 'function') vm.load();
};

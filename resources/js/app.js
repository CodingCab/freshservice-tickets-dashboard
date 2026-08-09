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
 *
 * The ticket detail viewer (`TicketDetail.vue`) mounts on its own root
 * (#ticket-detail-app), which is injected lazily into <body> by
 * mountTicketDetailApp(). The legacy SPA opens it by calling
 * window.openTicketDetail(id).
 */

import './bootstrap';
import { createApp, reactive, h } from 'vue';

import TaskLists from './components/TaskLists.vue';
import TaskListCard from './components/TaskListCard.vue';
import TaskListSectionRow from './components/TaskListSectionRow.vue';
import TicketDetail from './components/TicketDetail.vue';
import TicketActions from './components/TicketActions.vue';
import ReplyDraftModal from './components/ReplyDraftModal.vue';

/*
 * Base-path shim for subdirectory deployment (e.g. /www/tickets).
 *
 * Components and the legacy public/js/app.js issue API calls as root-absolute
 * '/api/...' paths. Under a subdirectory mount those escape the app root and
 * hit the parent host instead, returning HTML (so JSON.parse fails with
 * "Unexpected token '<'"). Here we wrap window.fetch once — before any
 * component or the deferred legacy script runs — to prepend the app base,
 * matching public/js/app.js's BASE_URL = location.pathname convention.
 */
const __API_BASE = window.location.pathname.replace(/\/$/, '');
// Expose the resolved base so components can prefix URLs the browser loads
// directly (img src, anchor href) — those bypass the fetch() wrapper below.
window.__API_BASE = __API_BASE;
if (__API_BASE && !window.__apiBaseShimInstalled) {
    window.__apiBaseShimInstalled = true;
    const __origFetch = window.fetch.bind(window);
    window.fetch = (input, init) => {
        if (typeof input === 'string' && input.indexOf('/api/') === 0) {
            input = __API_BASE + input;
        }
        return __origFetch(input, init);
    };
}

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

// ─── Ticket Detail viewer ───────────────────────────────────────
//
// Phase 2: the legacy tickets table calls window.openTicketDetail(id) when
// the user clicks a "View detail" affordance on a row. We lazily inject a
// mount point (#ticket-detail-app) into <body> the first time it's needed.

let _ticketDetailVm = null;
const _ticketDetailState = reactive({
    isOpen: false,
    ticketId: '',
});

function mountTicketDetailApp() {
    if (_ticketDetailVm) return _ticketDetailVm;

    let el = document.getElementById('ticket-detail-app');
    if (!el) {
        el = document.createElement('div');
        el.id = 'ticket-detail-app';
        document.body.appendChild(el);
    }

    const state = _ticketDetailState;
    const app = createApp({
        name: 'TicketDetailRoot',
        components: { TicketDetail },
        setup() {
            return () => h(TicketDetail, {
                ticketId: state.ticketId,
                isOpen: state.isOpen,
                onClose: () => { state.isOpen = false; },
            });
        },
    });
    app.component('ticket-detail', TicketDetail);
    app.component('ticket-actions', TicketActions);
    app.component('reply-draft-modal', ReplyDraftModal);

    _ticketDetailVm = app.mount(el);
    return _ticketDetailVm;
}

window.openTicketDetail = (ticketId) => {
    mountTicketDetailApp();
    // Normalise to string and trim any leading `#`. The TicketFile loader
    // accepts ids with or without the `T` prefix.
    const id = String(ticketId || '').replace(/^#/, '').trim();
    _ticketDetailState.ticketId = id;
    _ticketDetailState.isOpen = true;
};

window.closeTicketDetail = () => {
    _ticketDetailState.isOpen = false;
};

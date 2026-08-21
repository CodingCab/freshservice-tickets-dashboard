/**
 * FreshService Tickets + Agent Queue SPA
 */

const BASE_URL = window.location.pathname.replace(/\/$/, '');
const TICKETS_API = BASE_URL + '/api/tickets';
const AGENTS_API = BASE_URL + '/api/agents';
const FS_HEALTH_API = BASE_URL + '/api/health/freshservice';
const AI_SESSIONS_API = BASE_URL + '/api/ai-sessions';
const PIPELINE_PAGE = BASE_URL + '/pipeline-metrics';
const PIPELINE_STATUS_API = BASE_URL + '/api/pipeline-metrics/status';
// window.taskListsTabHTML / window.loadTaskLists / window.mountTaskListsApp
// are provided by the Vite-built Vue bundle (resources/js/app.js).

const STATUS_MAP = { 2: 'Open', 3: 'Pending', 4: 'Resolved', 5: 'Closed', 9: 'Adam', 10: 'Notification' };
const PRIORITY_MAP = { 1: 'Low', 2: 'Medium', 3: 'High', 4: 'Urgent' };

let tickets = [];
let agentTasks = [];
let currentFilter = 'active';
let currentSort = 'category';
let sortDir = 1;
let currentTab = 'tickets';
// The Pipeline tab shows an hourly snapshot produced outside this app, so it is loaded
// once, when the tab is first opened, rather than on page load or on the thirty-second
// refresh the ticket data needs. Declared up here with the other page state because the
// hash router can switch to that tab before the file has finished evaluating, and a `let`
// declared further down is unreachable at that moment — which left the frame blank.
let pipelineLoaded = false;
let showStarredOnly = false;

// ─── Modal stack ──────────────────────────────────────────────────
// Shared, cross-world (legacy + Vue) stack of open modals/panels. Every modal
// registers itself when it opens and unregisters when it closes. A single
// Escape handler closes ONLY the frontmost (last-opened) one, so nested
// modals unwind one Esc-press at a time. Clicking outside never closes a modal
// (all backdrop-close handlers are removed). The Vue components push/pop via
// window.ModalStack too — same object, one source of truth.
window.ModalStack = window.ModalStack || (function () {
    const stack = []; // [{ id, close }] — order = open order; last = frontmost
    return {
        push(entry) {
            if (!entry || !entry.id) return;
            this.remove(entry.id);           // no dupes; re-open moves to top
            stack.push(entry);
        },
        remove(id) {
            const i = stack.findIndex(e => e.id === id);
            if (i !== -1) stack.splice(i, 1);
        },
        pop() {
            const top = stack.pop();
            if (top && typeof top.close === 'function') {
                try { top.close(); } catch (e) { /* ignore */ }
            }
            return !!top;
        },
        get size() { return stack.length; },
    };
})();

// ─── Starred tickets (persisted in localStorage) ──────────────────
// Operators star tickets they want quick access to. The "★ Starred only"
// filter button at the top of the tickets table toggles a view that hides
// everything else. The star icon also appears as the leftmost column on
// each row and as a button in the Vue detail panel header; clicking either
// toggles the state. State is a Set held in memory + persisted as a JSON
// array under localStorage key STARRED_TICKETS_KEY.
const STARRED_TICKETS_KEY = 'starred_tickets';
function loadStarredTickets() {
    try {
        const raw = localStorage.getItem(STARRED_TICKETS_KEY);
        if (!raw) return new Set();
        const arr = JSON.parse(raw);
        return new Set(Array.isArray(arr) ? arr.map(String) : []);
    } catch (e) {
        return new Set();
    }
}
function saveStarredTickets(set) {
    try {
        localStorage.setItem(STARRED_TICKETS_KEY, JSON.stringify(Array.from(set)));
    } catch (e) { /* quota / private mode — fail silently */ }
}
let starredTickets = loadStarredTickets();
function isTicketStarred(id) { return starredTickets.has(String(id)); }
function toggleTicketStar(id, event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const sid = String(id);
    if (starredTickets.has(sid)) starredTickets.delete(sid);
    else starredTickets.add(sid);
    saveStarredTickets(starredTickets);
    // Re-render the visible table so the row icon + the filter (if active) update.
    if (typeof renderTable === 'function') renderTable();
    // Tell the Vue detail panel (if open on this ticket) to refresh its star state.
    if (typeof window.onTicketStarChanged === 'function') window.onTicketStarChanged(sid);
}
function toggleStarredOnlyFilter() {
    showStarredOnly = !showStarredOnly;
    const btn = document.getElementById('starredOnlyBtn');
    if (btn) btn.classList.toggle('active', showStarredOnly);
    saveUiPrefs();
    renderTable();
}
// Expose to the Vue bundle (which calls toggleTicketStar from its star button).
window.toggleTicketStar = toggleTicketStar;
window.isTicketStarred = isTicketStarred;

// ─── UI preferences persistence (per browser, like Starred) ───────
// Remembers the operator's toolbar choices across a page refresh: the status
// filter (Active/Open/Pending/Closed/All), the label filter selections
// (show-only / hide), the "★ Starred only" toggle and the sort column/dir.
// Stored in localStorage, so it's per browser/user — nothing server-side.
const UI_PREFS_KEY = 'tickets_ui_prefs';
function saveUiPrefs() {
    try {
        localStorage.setItem(UI_PREFS_KEY, JSON.stringify({
            statusFilter: currentFilter,
            labelFilter: labelFilterState,
            starredOnly: showStarredOnly,
            sort: currentSort,
            sortDir: sortDir,
        }));
    } catch (e) { /* quota / private mode — ignore */ }
}
function loadUiPrefs() {
    try {
        const raw = localStorage.getItem(UI_PREFS_KEY);
        if (!raw) return;
        const p = JSON.parse(raw);
        if (typeof p.statusFilter === 'string') currentFilter = p.statusFilter;
        if (p.labelFilter && typeof p.labelFilter === 'object') labelFilterState = p.labelFilter;
        if (typeof p.starredOnly === 'boolean') showStarredOnly = p.starredOnly;
        if (typeof p.sort === 'string') currentSort = p.sort;
        if (p.sortDir === 1 || p.sortDir === -1) sortDir = p.sortDir;
    } catch (e) { /* ignore corrupt prefs */ }
}
// Sync the toolbar buttons/arrows to the (restored) state after the shell renders.
function applyUiPrefs() {
    document.querySelectorAll('#tickets-tab .filter-btn').forEach(b => {
        if (b.dataset.filter) b.classList.toggle('active', b.dataset.filter === currentFilter);
    });
    const starBtn = document.getElementById('starredOnlyBtn');
    if (starBtn) starBtn.classList.toggle('active', showStarredOnly);
    document.querySelectorAll('#tickets-tab .sort-arrow').forEach(s => s.textContent = '');
    const arrow = document.getElementById('sort-' + currentSort);
    if (arrow) arrow.textContent = sortDir === -1 ? '▼' : '▲';
}

// ─── Shared ticket labels ─────────────────────────────────────────
// A common list of coloured labels operators create once (e.g. "Robert",
// "Chris", "urgent"), assign to any number of tickets, and filter the table
// by. Unlike Starred (per-browser localStorage) these are SHARED — stored
// server-side, so everyone sees the same list. Backend: /api/labels + the
// per-ticket /api/tickets/{id}/labels assignment endpoint.
let labels = [];                 // [{id, name, color, ticket_count}]
// Per-label filter state: id -> 'include' (show only these) | 'exclude' (hide these).
// A label not present in the map is neutral. Clicking a filter chip cycles
// neutral → include → exclude → neutral.
let labelFilterState = {};

function labelsById() {
    const map = {};
    labels.forEach(l => { map[l.id] = l; });
    return map;
}

async function loadLabels() {
    try {
        const resp = await fetch(BASE_URL + '/api/labels');
        const data = await resp.json();
        labels = Array.isArray(data.labels) ? data.labels : [];
    } catch (e) {
        labels = [];
    }
    // Drop any filter selections whose label no longer exists.
    const ids = new Set(labels.map(l => l.id));
    Object.keys(labelFilterState).forEach(k => { if (!ids.has(Number(k))) delete labelFilterState[k]; });
    renderLabelFilterBar();
    if (typeof renderTable === 'function') renderTable();
    // Let the Vue detail panel (if open) refresh its label chips.
    if (typeof window.onTicketLabelsChanged === 'function') window.onTicketLabelsChanged();
}

// Exposed for the Vue ticket-detail panel, which offers the same
// assign-labels affordance (reuses openLabelPicker + these read helpers).
window.getAllLabels = () => labels;
window.getTicketLabelIds = (ticketId) => {
    const t = tickets.find(x => String(x.id) === String(ticketId));
    return (t && t._internal && t._internal.label_ids) || [];
};
// openLabelPicker is already global (function declaration) — the Vue panel
// reaches it via window.openLabelPicker. Do NOT reassign it to a wrapper that
// calls itself, or it recurses infinitely.

function anyLabelFilterActive() {
    return Object.keys(labelFilterState).length > 0;
}

function renderLabelFilterBar() {
    const bar = document.getElementById('labelFilterBar');
    if (!bar) return;
    const chips = labels.map(l => {
        const state = labelFilterState[l.id];          // undefined | 'include' | 'exclude'
        const cls = state === 'include' ? ' state-include' : state === 'exclude' ? ' state-exclude' : '';
        const mark = state === 'include' ? '✓ ' : state === 'exclude' ? '⊘ ' : '';
        const tip = state === 'include' ? `Showing only tickets with "${esc(l.name)}" — click to hide them`
            : state === 'exclude' ? `Hiding tickets with "${esc(l.name)}" — click to reset`
            : `Click to show only "${esc(l.name)}"`;
        return `<button class="label-chip label-filter-chip${cls}" style="--lc:${l.color}" onclick="cycleLabelFilter(${l.id})" title="${tip}">${mark}${esc(l.name)}</button>`;
    }).join('');
    bar.innerHTML =
        `<span class="label-filter-caption">Labels:</span>`
        + (chips || `<span class="label-filter-empty">none yet — use “Manage labels” to add some</span>`)
        + (anyLabelFilterActive() ? `<button class="filter-btn" onclick="clearLabelFilter()">Clear</button>` : '');
}

// neutral → include (show only) → exclude (hide) → neutral
function cycleLabelFilter(id) {
    const s = labelFilterState[id];
    if (!s) labelFilterState[id] = 'include';
    else if (s === 'include') labelFilterState[id] = 'exclude';
    else delete labelFilterState[id];
    saveUiPrefs();
    renderLabelFilterBar();
    renderTable();
}

function clearLabelFilter() {
    labelFilterState = {};
    saveUiPrefs();
    renderLabelFilterBar();
    renderTable();
}

// ─── Bulk actions on many tickets ─────────────────────────────────
// Operators tick the checkbox on each row (or the one in the table header,
// which takes every ticket currently visible after the filters) and then apply
// ONE action to the whole selection: set the FreshService status, add a shared
// label, remove a shared label, or star/unstar.
//
// The selection is in-memory only — a refresh clears it. It is also pruned on
// every render to the tickets still visible, so changing a filter never leaves
// invisible tickets silently selected (the count always matches what is ticked
// on screen).
//
// Every action reuses the SAME single-ticket endpoints the per-row affordances
// use, called one ticket at a time — one writer, one behaviour. Stars are
// per-browser localStorage, so that one never talks to the server at all.
let selectedTicketIds = new Set();

function isTicketSelected(id) { return selectedTicketIds.has(String(id)); }

function toggleTicketSelection(id, checked) {
    const sid = String(id);
    if (checked) selectedTicketIds.add(sid); else selectedTicketIds.delete(sid);
    renderBulkBar();
    syncBulkSelectAll();
    const row = document.querySelector(`#ticketBody tr[data-ticket-id="${sid}"]`);
    if (row) row.classList.toggle('row-selected', checked);
}

// Header checkbox: select / clear every ticket the current filters leave visible.
function toggleSelectAllVisible(checked) {
    const visible = getFiltered().map(t => String(t.id));
    if (checked) visible.forEach(id => selectedTicketIds.add(id));
    else visible.forEach(id => selectedTicketIds.delete(id));
    renderTable();
}

function clearTicketSelection() {
    selectedTicketIds.clear();
    renderTable();
}

// Drop anything no longer on screen, so the counter can never claim more than
// the operator can see.
function pruneSelection(visibleTickets) {
    const visible = new Set(visibleTickets.map(t => String(t.id)));
    Array.from(selectedTicketIds).forEach(id => { if (!visible.has(id)) selectedTicketIds.delete(id); });
}

function syncBulkSelectAll() {
    const box = document.getElementById('bulkSelectAll');
    if (!box) return;
    const visible = getFiltered().map(t => String(t.id));
    const picked = visible.filter(id => selectedTicketIds.has(id)).length;
    box.checked = visible.length > 0 && picked === visible.length;
    box.indeterminate = picked > 0 && picked < visible.length;
}

function renderBulkBar() {
    const bar = document.getElementById('bulkActionBar');
    if (!bar) return;
    const n = selectedTicketIds.size;
    bar.style.display = n ? '' : 'none';
    if (!n) return;
    const countEl = document.getElementById('bulkSelectionCount');
    if (countEl) countEl.textContent = `${n} ticket${n === 1 ? '' : 's'} selected`;
}

// ── The four action menus ──
// All four share one popover element, so opening any of them closes the others.
function closeBulkMenu() {
    const m = document.getElementById('bulkMenu');
    if (m) m.remove();
    window.ModalStack.remove('bulkMenu');
}

function openBulkMenu(event, caption, itemsHtml) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    closeBulkMenu();
    const menu = document.createElement('div');
    menu.className = 'status-picker bulk-menu';
    menu.id = 'bulkMenu';
    menu.addEventListener('click', e => e.stopPropagation());
    menu.innerHTML = `<div class="status-picker-caption">${caption}</div>`
        + itemsHtml
        + `<button class="status-picker-cancel" onclick="closeBulkMenu()">Cancel</button>`;
    document.body.appendChild(menu);
    const r = event.currentTarget.getBoundingClientRect();
    menu.style.top = (window.scrollY + r.bottom + 4) + 'px';
    const left = Math.min(window.scrollX + r.left, window.scrollX + window.innerWidth - 220);
    menu.style.left = Math.max(8, left) + 'px';
    window.ModalStack.push({ id: 'bulkMenu', close: closeBulkMenu });
}

function openBulkStatusMenu(event) {
    openBulkMenu(event, 'Set FreshService status', STATUS_CHOICES.map(s =>
        `<button class="status-picker-item bulk-menu-item" data-status="${s.key}" onclick="runBulkStatus('${s.key}')">
            <span class="badge badge-${s.cls}">${s.label}</span>
        </button>`).join(''));
}

function bulkLabelItems(mode) {
    if (!labels.length) return `<div class="label-picker-empty">No labels yet</div>`;
    return labels.map(l =>
        `<button class="status-picker-item bulk-menu-item" data-label-id="${l.id}" onclick="runBulkLabel('${mode}', ${l.id})">
            <span class="label-chip" style="--lc:${l.color}">${esc(l.name)}</span>
        </button>`).join('');
}

function openBulkAddLabelMenu(event) { openBulkMenu(event, 'Add label to selected', bulkLabelItems('add')); }
function openBulkRemoveLabelMenu(event) { openBulkMenu(event, 'Remove label from selected', bulkLabelItems('remove')); }

function openBulkStarMenu(event) {
    openBulkMenu(event, 'Stars (only you see these)',
        `<button class="status-picker-item bulk-menu-item" data-star="on" onclick="runBulkStar(true)">★ Star selected</button>`
        + `<button class="status-picker-item bulk-menu-item" data-star="off" onclick="runBulkStar(false)">☆ Unstar selected</button>`);
}

// ── Running an action over the selection ──
// Tickets are processed one at a time (the endpoints are per-ticket and each
// one talks to FreshService); the operator gets a single "X succeeded, Y errors"
// summary at the end rather than a dialog per ticket.
function selectedTicketsInOrder() {
    return getFiltered().filter(t => selectedTicketIds.has(String(t.id)));
}

function showBulkToast(message, isError) {
    const el = document.getElementById('bulkToast');
    if (!el) return;
    el.textContent = message;
    el.classList.toggle('bulk-toast-error', !!isError);
    el.style.display = '';
    clearTimeout(showBulkToast._timer);
    showBulkToast._timer = setTimeout(() => { el.style.display = 'none'; }, 6000);
}

function bulkSummary(ok, failed) {
    return `Done: ${ok} succeeded, ${failed} error${failed === 1 ? '' : 's'}.`;
}

function setBulkBarBusy(busy) {
    const bar = document.getElementById('bulkActionBar');
    if (bar) bar.classList.toggle('bulk-busy', busy);
}

async function runBulkStatus(statusKey) {
    const chosen = STATUS_CHOICES.find(s => s.key === statusKey);
    const targets = selectedTicketsInOrder();
    closeBulkMenu();
    if (!chosen || !targets.length) return;

    const ok = window.confirm(
        `Set ${targets.length} ticket${targets.length === 1 ? '' : 's'} to "${chosen.label}"?\n\n`
        + `This changes the status on FreshService and moves the tickets on our pipeline.`
    );
    if (!ok) return;

    setBulkBarBusy(true);
    let done = 0, failed = 0;
    for (const t of targets) {
        try {
            const resp = await fetch(BASE_URL + '/api/tickets/' + encodeURIComponent(t.id) + '/status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ status: statusKey, reason: 'Changed from the tickets list (bulk action)' }),
            });
            if (!resp.ok) failed++; else done++;
        } catch (e) {
            failed++;
        }
    }
    setBulkBarBusy(false);
    showBulkToast(bulkSummary(done, failed), failed > 0);
    selectedTicketIds.clear();
    await loadTickets();
}

// mode: 'add' adds the label on top of whatever each ticket already carries;
// 'remove' takes only that one off. Neither ever replaces a ticket's set, so
// labels put on by someone else survive.
async function runBulkLabel(mode, labelId) {
    const label = labels.find(l => l.id === labelId);
    const targets = selectedTicketsInOrder();
    closeBulkMenu();
    if (!label || !targets.length) return;

    const verb = mode === 'add' ? 'Add' : 'Remove';
    const prep = mode === 'add' ? 'to' : 'from';
    const ok = window.confirm(
        `${verb} label "${label.name}" ${prep} ${targets.length} ticket${targets.length === 1 ? '' : 's'}?`
    );
    if (!ok) return;

    setBulkBarBusy(true);
    let done = 0, failed = 0;
    for (const t of targets) {
        const current = new Set((t._internal && t._internal.label_ids) || []);
        if (mode === 'add') current.add(labelId); else current.delete(labelId);
        const ids = Array.from(current);
        try {
            const resp = await fetch(BASE_URL + '/api/tickets/' + encodeURIComponent(t.id) + '/labels', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ label_ids: ids }),
            });
            if (!resp.ok) { failed++; continue; }
            const data = await resp.json();
            if (!t._internal) t._internal = {};
            t._internal.label_ids = data.label_ids;
            done++;
        } catch (e) {
            failed++;
        }
    }
    setBulkBarBusy(false);
    showBulkToast(bulkSummary(done, failed), failed > 0);
    selectedTicketIds.clear();
    renderTable();
    loadLabels();
}

// Stars live in this browser only, so this one is instant and needs no
// confirmation — nothing leaves the machine and nobody else is affected.
function runBulkStar(on) {
    const targets = selectedTicketsInOrder();
    closeBulkMenu();
    if (!targets.length) return;
    targets.forEach(t => {
        const sid = String(t.id);
        if (on) starredTickets.add(sid); else starredTickets.delete(sid);
    });
    saveStarredTickets(starredTickets);
    renderTable();
    targets.forEach(t => {
        if (typeof window.onTicketStarChanged === 'function') window.onTicketStarChanged(String(t.id));
    });
}

// Close the bulk menu on any outside click (the buttons stop their own clicks).
document.addEventListener('click', e => {
    const menu = document.getElementById('bulkMenu');
    if (menu && !menu.contains(e.target)) closeBulkMenu();
});

// ─── Per-row FreshService status picker ───────────────────────────
// Change a ticket's FS status straight from the list, without opening the
// detail panel. Same endpoint the detail panel's "Set status" menu uses
// (POST /api/tickets/{id}/status) — one writer, one behaviour.
//
// Closing/resolving is destructive-ish and outward-facing (it changes state on
// FreshService and moves the ticket's pipeline section), so those two ask for
// confirmation first. Open/Pending are freely reversible and don't.
const STATUS_CHOICES = [
    { key: 'open',     label: 'Open',     code: 2, cls: 'open' },
    { key: 'pending',  label: 'Pending',  code: 3, cls: 'pending' },
    { key: 'resolved', label: 'Resolved', code: 4, cls: 'resolved' },
    { key: 'closed',   label: 'Closed',   code: 5, cls: 'closed' },
];

function openStatusPicker(event, ticketId) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    closeStatusPicker();
    const t = tickets.find(x => String(x.id) === String(ticketId));
    if (!t) return;

    const menu = document.createElement('div');
    menu.className = 'status-picker';
    menu.id = 'statusPicker';
    menu.addEventListener('click', e => e.stopPropagation());
    menu.innerHTML =
        `<div class="status-picker-caption">Set FreshService status</div>`
        + STATUS_CHOICES.map(s => {
            const isCurrent = t.status === s.code;
            return `<button class="status-picker-item${isCurrent ? ' current' : ''}"
                        ${isCurrent ? 'disabled title="Already this status"' : `onclick="pickTicketStatus(event, '${ticketId}', '${s.key}')"`}>
                        <span class="badge badge-${s.cls}">${s.label}</span>
                        ${isCurrent ? '<span class="status-picker-current-mark">current</span>' : ''}
                    </button>`;
        }).join('')
        + `<button class="status-picker-cancel" onclick="closeStatusPicker()">Cancel</button>`;

    document.body.appendChild(menu);
    const r = event.currentTarget.getBoundingClientRect();
    menu.style.top = (window.scrollY + r.bottom + 4) + 'px';
    const left = Math.min(window.scrollX + r.left, window.scrollX + window.innerWidth - 200);
    menu.style.left = Math.max(8, left) + 'px';
    window.ModalStack.push({ id: 'statusPicker', close: closeStatusPicker });
}

function closeStatusPicker() {
    const m = document.getElementById('statusPicker');
    if (m) m.remove();
    window.ModalStack.remove('statusPicker');
}

async function pickTicketStatus(event, ticketId, statusKey) {
    if (event) { event.preventDefault(); event.stopPropagation(); }

    // Resolved/Closed change state on FreshService AND move the ticket's
    // pipeline section — confirm before doing it from a one-click list badge,
    // where a misclick is easy.
    if (statusKey === 'resolved' || statusKey === 'closed') {
        const ok = window.confirm(
            `Set ticket #${ticketId} to ${statusKey === 'closed' ? 'Closed' : 'Resolved'}?\n\n`
            + `This changes the status on FreshService and moves the ticket on our pipeline.`
        );
        if (!ok) return;
    }

    closeStatusPicker();
    try {
        const resp = await fetch(BASE_URL + '/api/tickets/' + encodeURIComponent(ticketId) + '/status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ status: statusKey, reason: 'Changed from the tickets list' }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) {
            alert('Could not change status: ' + (data.error || ('HTTP ' + resp.status))
                + (data.message ? '\n' + data.message : ''));
            return;
        }
        // Re-pull from the server rather than patching the row locally: the
        // endpoint may also have moved the pipeline section, and that column
        // is rendered from the same payload.
        await loadTickets();
    } catch (e) {
        alert('Could not change status: ' + (e.message || e));
    }
}

// ── Per-ticket assignment popover ──
function openLabelPicker(event, ticketId) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    closeLabelPicker();
    const menu = document.createElement('div');
    menu.className = 'label-picker';
    menu.id = 'labelPicker';
    menu.dataset.ticketId = String(ticketId);
    // Clicks inside the popover must never bubble to the document close handler,
    // so toggling a row keeps the popover open (multi-select without native
    // <label> forwarding quirks — the whole row is one toggle target).
    menu.addEventListener('click', e => e.stopPropagation());
    renderLabelPickerItems(menu, ticketId);
    document.body.appendChild(menu);
    const r = event.target.getBoundingClientRect();
    menu.style.top = (window.scrollY + r.bottom + 4) + 'px';
    // Keep the menu on-screen horizontally.
    const left = Math.min(window.scrollX + r.left, window.scrollX + window.innerWidth - 240);
    menu.style.left = Math.max(8, left) + 'px';
    window.ModalStack.push({ id: 'labelPicker', close: closeLabelPicker });
}

function renderLabelPickerItems(menu, ticketId) {
    const t = tickets.find(x => String(x.id) === String(ticketId));
    const current = new Set((t && t._internal && t._internal.label_ids) || []);
    const items = labels.length
        ? labels.map(l => {
            const on = current.has(l.id);
            // Two targets: the checkbox toggles in multi-select and keeps the
            // popover open; clicking the label NAME picks only that one and
            // closes the popover immediately.
            return `<div class="label-picker-item${on ? ' checked' : ''}">
                <span class="label-picker-check" onclick="toggleTicketLabel(event, '${ticketId}', ${l.id})" title="Toggle (keeps this open for multiple)">${on ? '✓' : ''}</span>
                <span class="label-chip" style="--lc:${l.color}" onclick="pickSingleLabel(event, '${ticketId}', ${l.id})" title="Pick only this one and close">${esc(l.name)}</span>
            </div>`;
        }).join('')
        : `<div class="label-picker-empty">No labels yet</div>`;
    menu.innerHTML = items
        + `<button class="label-picker-manage" onclick="openLabelManager()">Manage labels…</button>`
        + `<button class="label-picker-done" onclick="closeLabelPicker()">Done</button>`;
}

function closeLabelPicker() {
    const m = document.getElementById('labelPicker');
    if (m) m.remove();
    window.ModalStack.remove('labelPicker');
}

// Click on the label NAME: ADD this label to the ticket's existing set (never
// removes anything already assigned) and close the popover immediately.
async function pickSingleLabel(event, ticketId, labelId) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const t = tickets.find(x => String(x.id) === String(ticketId));
    if (!t) return;
    const set = new Set((t._internal && t._internal.label_ids) || []);
    set.add(labelId);
    await saveTicketLabels(ticketId, Array.from(set));
    closeLabelPicker();
}

// Click on the checkbox: toggle this label in/out, keep the popover open so
// several can be selected in one go.
async function toggleTicketLabel(event, ticketId, labelId) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const t = tickets.find(x => String(x.id) === String(ticketId));
    if (!t) return;
    const set = new Set((t._internal && t._internal.label_ids) || []);
    if (set.has(labelId)) set.delete(labelId); else set.add(labelId);
    await saveTicketLabels(ticketId, Array.from(set), { keepOpen: true });
}

// Persist a ticket's full label set and refresh the row, counts and (optionally)
// the open popover.
async function saveTicketLabels(ticketId, ids, { keepOpen = false } = {}) {
    const t = tickets.find(x => String(x.id) === String(ticketId));
    if (!t) return;
    if (!t._internal) t._internal = {};
    try {
        const resp = await fetch(BASE_URL + '/api/tickets/' + encodeURIComponent(ticketId) + '/labels', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ label_ids: ids }),
        });
        if (!resp.ok) throw new Error('save failed (' + resp.status + ')');
        const data = await resp.json();
        t._internal.label_ids = data.label_ids;
        // When staying open (checkbox multi-select), refresh the popover in
        // place so the ticks update. The row chips + shared counts always refresh.
        if (keepOpen) {
            const menu = document.getElementById('labelPicker');
            if (menu && menu.dataset.ticketId === String(ticketId)) renderLabelPickerItems(menu, ticketId);
        }
        renderTable();
        loadLabels();
    } catch (e) {
        alert('Failed to save labels: ' + e.message);
    }
}

// ── Label manager modal (create / delete the shared list) ──
function openLabelManager() {
    closeLabelPicker();
    renderLabelManagerList();
    const err = document.getElementById('labelCreateError');
    if (err) err.textContent = '';
    document.getElementById('labelManagerModal')?.classList.add('open');
    window.ModalStack.push({ id: 'labelManagerModal', close: closeLabelManager });
}

function closeLabelManager() {
    document.getElementById('labelManagerModal')?.classList.remove('open');
    window.ModalStack.remove('labelManagerModal');
}

function renderLabelManagerList() {
    const box = document.getElementById('labelManagerList');
    if (!box) return;
    if (!labels.length) {
        box.innerHTML = `<div class="label-manager-empty">No labels yet — add your first one below.</div>`;
        return;
    }
    box.innerHTML = labels.map(l => `
        <div class="label-manager-row">
            <span class="label-chip" style="--lc:${l.color}">${esc(l.name)}</span>
            <span class="label-manager-count">${l.ticket_count} ticket${l.ticket_count === 1 ? '' : 's'}</span>
            <button class="label-delete-btn" onclick="deleteLabelPrompt(${l.id})" title="Delete this label">Delete</button>
        </div>`).join('');
}

async function createLabel() {
    const nameEl = document.getElementById('newLabelName');
    const colorEl = document.getElementById('newLabelColor');
    const errEl = document.getElementById('labelCreateError');
    const name = (nameEl.value || '').trim();
    const color = colorEl.value || '#3b82f6';
    errEl.textContent = '';
    if (!name) { errEl.textContent = 'Enter a name.'; return; }
    try {
        const resp = await fetch(BASE_URL + '/api/labels', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, color }),
        });
        const data = await resp.json();
        if (!resp.ok) { errEl.textContent = data.message || 'Could not create label.'; return; }
        nameEl.value = '';
        await loadLabels();
        renderLabelManagerList();
    } catch (e) {
        errEl.textContent = 'Could not create label: ' + e.message;
    }
}

async function deleteLabelPrompt(id) {
    const l = labels.find(x => x.id === id);
    if (!l) return;
    const n = l.ticket_count;
    const warning = n > 0
        ? `Delete label "${l.name}"?\n\nIt is currently used on ${n} ticket${n === 1 ? '' : 's'} and will be removed from ${n === 1 ? 'it' : 'them'}.`
        : `Delete label "${l.name}"?`;
    if (!confirm(warning)) return;
    try {
        const resp = await fetch(BASE_URL + '/api/labels/' + id, { method: 'DELETE' });
        if (!resp.ok) throw new Error('delete failed (' + resp.status + ')');
        await loadLabels();
        renderLabelManagerList();
    } catch (e) {
        alert('Failed to delete label: ' + e.message);
    }
}

// Close the assignment popover on any outside click.
document.addEventListener('click', e => {
    const picker = document.getElementById('labelPicker');
    if (picker && !picker.contains(e.target) && !(e.target.classList && e.target.classList.contains('label-add-btn'))) {
        closeLabelPicker();
    }
});

// Same for the per-row status picker. (The badge's own click stops
// propagation, so opening one never trips this handler.)
document.addEventListener('click', e => {
    const picker = document.getElementById('statusPicker');
    if (picker && !picker.contains(e.target)) {
        closeStatusPicker();
    }
});

let agentSort = 'created_at';
let agentSortDir = -1;
let agentFilter = 'all';
let agentRange = '24h';  // '24h' | '7d' | '30d' | 'all' | 'custom'

// ─── Agent column definitions ───────────────────────────────────
// key: data field, label: header text, default: shown by default, sort: sortable field key
const AGENT_COLUMNS = [
    { key: 'name',            label: 'Task',          default: true,  sortKey: 'name' },
    { key: 'prompt',          label: 'Prompt',        default: true,  sortKey: 'prompt' },
    { key: 'model',           label: 'Model',         default: true,  sortKey: 'model' },
    { key: 'username',        label: 'User',          default: true,  sortKey: 'username' },
    { key: 'source',          label: 'Source',        default: false, sortKey: 'source' },
    { key: 'status',          label: 'Status',        default: true,  sortKey: 'status' },
    { key: 'created_at',      label: 'Created',       default: true,  sortKey: 'created_at' },
    { key: 'started_at',      label: 'Started',       default: true,  sortKey: 'started_at' },
    { key: 'completed_at',    label: 'Finished',      default: true,  sortKey: 'completed_at' },
    { key: 'duration',        label: 'Duration',      default: true,  sortKey: 'duration_ms' },
    { key: 'queue_wait',      label: 'Queue Wait',    default: false, sortKey: 'queue_wait_seconds' },
    { key: 'cost_usd',        label: 'Cost',          default: true,  sortKey: 'cost_usd' },
    { key: 'num_turns',       label: 'Turns',         default: true,  sortKey: 'num_turns' },
    { key: 'input_tokens',    label: 'In Tokens',     default: false, sortKey: 'input_tokens' },
    { key: 'output_tokens',   label: 'Out Tokens',    default: false, sortKey: 'output_tokens' },
    { key: 'cache_read',      label: 'Cache Read',    default: false, sortKey: 'cache_read_tokens' },
    { key: 'cache_create',    label: 'Cache Create',  default: false, sortKey: 'cache_creation_tokens' },
    { key: 'total_tokens',    label: 'Total Tokens',  default: false, sortKey: 'total_tokens' },
    { key: 'stop_reason',     label: 'Stop Reason',   default: false, sortKey: 'stop_reason' },
    { key: 'error',           label: 'Error',         default: false, sortKey: 'error' },
];

// ─── Column visibility (localStorage) ───────────────────────────

const COL_STORAGE_KEY = 'agent-columns-visible';

function getVisibleColumns() {
    try {
        const stored = localStorage.getItem(COL_STORAGE_KEY);
        if (stored) return JSON.parse(stored);
    } catch {}
    return AGENT_COLUMNS.filter(c => c.default).map(c => c.key);
}

function saveVisibleColumns(cols) {
    localStorage.setItem(COL_STORAGE_KEY, JSON.stringify(cols));
}

function isColVisible(key) {
    return getVisibleColumns().includes(key);
}

function toggleColumn(key) {
    let cols = getVisibleColumns();
    if (cols.includes(key)) {
        cols = cols.filter(c => c !== key);
    } else {
        // Insert in original order
        cols = AGENT_COLUMNS.filter(c => cols.includes(c.key) || c.key === key).map(c => c.key);
    }
    saveVisibleColumns(cols);
    renderAgentTable();
    updateColumnToggles();
}

function updateColumnToggles() {
    const visible = getVisibleColumns();
    document.querySelectorAll('.col-toggle').forEach(btn => {
        btn.classList.toggle('active', visible.includes(btn.dataset.col));
    });
}

// ─── Render the full app shell ───────────────────────────────────

function renderApp() {
    document.getElementById('app').innerHTML = `
        <div class="page-tabs">
            <button class="page-tab active" data-page="tickets" onclick="switchTab('tickets')">Tickets</button>
            <button class="page-tab" data-page="agents" onclick="switchTab('agents')">Agents</button>
            <button class="page-tab" data-page="task-lists" onclick="switchTab('task-lists')">Task Lists</button>
            <button class="page-tab" data-page="feedback" onclick="switchTab('feedback')">Feedback</button>
            <button class="page-tab" data-page="ai-sessions" onclick="switchTab('ai-sessions')">AI Sessions</button>
            <button class="page-tab" data-page="pipeline" onclick="switchTab('pipeline')">Pipeline</button>
        </div>

        <div id="tickets-tab" class="tab-page">
            <div class="header">
                <h1>FreshService Tickets</h1>
                <button class="fs-health-badge fs-health-unknown" id="fsHealthBadge" onclick="openFsHealthModal()" title="FreshService connection health — click for details">
                    <span class="fs-health-dot"></span>
                    <span class="fs-health-label" id="fsHealthLabel">checking…</span>
                </button>
                <button class="refresh-btn" onclick="loadTickets()">&#x21bb; Refresh</button>
                <button class="manage-labels-btn" onclick="openLabelManager()" title="Create, colour and delete shared labels">&#127991; Manage labels</button>
                <a href="${TICKETS_API}" target="_blank" class="json-link">JSON</a>
                <a href="/www/freshservice-tickets-db.json" target="_blank" class="json-link">DB File</a>
                <span class="last-updated" id="lastUpdated"></span>
            </div>
            <div class="stats" id="stats"></div>
            <div class="filters">
                <input type="text" id="search" placeholder="Search tickets..." oninput="renderTable()">
                <button class="filter-btn active" data-filter="active" onclick="setFilter('active')">Active</button>
                <button class="filter-btn" data-filter="all" onclick="setFilter('all')">All</button>
                <button class="filter-btn" data-filter="open" onclick="setFilter('open')">Open</button>
                <button class="filter-btn" data-filter="pending" onclick="setFilter('pending')">Pending</button>
                <button class="filter-btn" data-filter="closed" onclick="setFilter('closed')">Closed</button>
                <button class="filter-btn filter-btn-starred" id="starredOnlyBtn" onclick="toggleStarredOnlyFilter()" title="Show only tickets you have starred">★ Starred only</button>
            </div>
            <div class="label-filter-bar" id="labelFilterBar"></div>
            <div class="bulk-action-bar" id="bulkActionBar" style="display:none;">
                <span class="bulk-selection-count" id="bulkSelectionCount">0 tickets selected</span>
                <button class="filter-btn" id="bulkStatusBtn" onclick="openBulkStatusMenu(event)">Status &#x25BE;</button>
                <button class="filter-btn" id="bulkAddLabelBtn" onclick="openBulkAddLabelMenu(event)">Add label &#x25BE;</button>
                <button class="filter-btn" id="bulkRemoveLabelBtn" onclick="openBulkRemoveLabelMenu(event)">Remove label &#x25BE;</button>
                <button class="filter-btn" id="bulkStarBtn" onclick="openBulkStarMenu(event)">&#9733; &#x25BE;</button>
                <button class="filter-btn bulk-clear-btn" id="bulkClearBtn" onclick="clearTicketSelection()">Clear selection</button>
            </div>
            <div class="bulk-toast" id="bulkToast" style="display:none;"></div>
            <table>
                <thead><tr>
                    <th class="bulk-col"><input type="checkbox" id="bulkSelectAll" class="bulk-select-box" onchange="toggleSelectAllVisible(this.checked)" title="Select every ticket currently visible"></th>
                    <th class="star-col" title="Star this ticket — toggles the per-row marker"></th>
                    <th onclick="sortBy('category')">Category <span class="sort-arrow" id="sort-category">&#x25B2;</span></th>
                    <th class="labels-col">Labels</th>
                    <th onclick="sortBy('id')">ID <span class="sort-arrow" id="sort-id"></span></th>
                    <th onclick="sortBy('status')">FreshService Status <span class="sort-arrow" id="sort-status"></span></th>
                    <th onclick="sortBy('pipeline_section')">Pipeline Stage <span class="sort-arrow" id="sort-pipeline_section"></span></th>
                    <th onclick="sortBy('priority')">Priority <span class="sort-arrow" id="sort-priority"></span></th>
                    <th onclick="sortBy('subject')">Subject <span class="sort-arrow" id="sort-subject"></span></th>
                    <th onclick="sortBy('requester_name')">Requester <span class="sort-arrow" id="sort-requester_name"></span></th>
                    <th onclick="sortBy('created_at')">Created <span class="sort-arrow" id="sort-created_at"></span></th>
                    <th onclick="sortBy('updated_at')">Updated <span class="sort-arrow" id="sort-updated_at"></span></th>
                    <th>Summary</th>
                    <th>Next Action</th>
                    <th>Ticket File</th>
                </tr></thead>
                <tbody id="ticketBody"></tbody>
            </table>
        </div>

        <div id="agents-tab" class="tab-page" style="display:none;">
            <div class="header">
                <h1>Agent Queue</h1>
                <button class="refresh-btn" onclick="loadAgents()">&#x21bb; Refresh</button>
                <a href="${AGENTS_API}" target="_blank" class="json-link">JSON</a>
                <button class="columns-btn" onclick="toggleColumnsPanel()">Columns</button>
            </div>
            <div class="columns-panel" id="columnsPanel" style="display:none;">
                <div class="columns-grid" id="columnsGrid"></div>
            </div>
            <div class="stats" id="agentStats"></div>
            <div class="usage-section">
                <div class="usage-head">
                    <h2>Usage limits</h2>
                    <span class="usage-sub">peak % of each account's rate limit per period, snapshotted after every agent job</span>
                    <span class="usage-ranges">
                        <button class="filter-btn" data-usage-range="24h" onclick="setUsageRange('24h')">24h</button>
                        <button class="filter-btn" data-usage-range="3d" onclick="setUsageRange('3d')">3 days</button>
                        <button class="filter-btn active" data-usage-range="7d" onclick="setUsageRange('7d')">7 days</button>
                        <button class="filter-btn" data-usage-range="14d" onclick="setUsageRange('14d')">14 days</button>
                        <button class="filter-btn" data-usage-range="30d" onclick="setUsageRange('30d')">30 days</button>
                    </span>
                    <div class="usage-legend" id="usageLegend"></div>
                </div>
                <div class="usage-charts">
                    <div class="usage-chart">
                        <h3>Session (5-hour) window</h3>
                        <div class="usage-plot" id="usagePlot5h"></div>
                    </div>
                    <div class="usage-chart">
                        <h3>Weekly (7-day) window</h3>
                        <div class="usage-plot" id="usagePlot7d"></div>
                    </div>
                </div>
                <div class="usage-tooltip" id="usageTooltip" style="display:none;"></div>
            </div>
            <div class="filters">
                <input type="text" id="agentSearch" placeholder="Search agents..." oninput="renderAgentTable()">
                <button class="filter-btn active" data-agent-filter="all" onclick="setAgentFilter('all')">All</button>
                <button class="filter-btn" data-agent-filter="running" onclick="setAgentFilter('running')">Running</button>
                <button class="filter-btn" data-agent-filter="pending" onclick="setAgentFilter('pending')">Pending</button>
                <button class="filter-btn" data-agent-filter="completed" onclick="setAgentFilter('completed')">Completed</button>
                <button class="filter-btn" data-agent-filter="failed" onclick="setAgentFilter('failed')">Failed</button>
                <button class="filter-btn" data-agent-filter="cancelled" onclick="setAgentFilter('cancelled')">Cancelled</button>
            </div>
            <div class="filters">
                <button class="filter-btn active" data-agent-range="24h" onclick="setAgentRange('24h')">24h</button>
                <button class="filter-btn" data-agent-range="7d" onclick="setAgentRange('7d')">7 days</button>
                <button class="filter-btn" data-agent-range="30d" onclick="setAgentRange('30d')">30 days</button>
                <button class="filter-btn" data-agent-range="all" onclick="setAgentRange('all')">All time</button>
                <button class="filter-btn" data-agent-range="custom" onclick="setAgentRange('custom')">Custom</button>
                <span id="agentCustomRange" style="display:none;">
                    <input type="date" id="agentSinceDate" onchange="loadAgents()">
                    <input type="date" id="agentUntilDate" onchange="loadAgents()">
                </span>
                <select id="agentUserFilter" onchange="renderAgentTable();renderUsageCharts()"><option value="">All users</option></select>
                <select id="agentModelFilter" onchange="renderAgentTable()"><option value="">All models</option></select>
                <select id="agentSourceFilter" onchange="renderAgentTable()"><option value="">All sources</option></select>
            </div>
            <div class="table-wrap">
                <table id="agentTable">
                    <thead><tr id="agentHead"></tr></thead>
                    <tbody id="agentBody"></tbody>
                </table>
            </div>
        </div>

        ${taskListsTabHTML()}

        <div id="feedback-tab" class="tab-page" style="display:none;">
            <div class="header">
                <h1>Automation Feedback</h1>
                <button class="refresh-btn" onclick="loadFeedback()">&#x21bb; Refresh</button>
            </div>
            <p class="feedback-intro">Reports filed from a ticket panel about how the automation handled a ticket. Flow: <strong>New</strong> → <strong>Triaged</strong> (proposal ready) → <strong>Done</strong>.</p>
            <div id="feedbackContainer"><p class="feedback-empty">Loading…</p></div>
        </div>

        <div id="pipeline-tab" class="tab-page" style="display:none;">
            <div class="header">
                <h1>Pipeline</h1>
                <button class="refresh-btn" onclick="loadPipeline(true)">&#x21bb; Refresh</button>
                <a href="${PIPELINE_PAGE}" target="_blank" class="json-link">Open alone</a>
                <span class="last-updated" id="pipelineLastSynced"></span>
            </div>
            <p class="feedback-intro">Every step of the developer pipeline: how often each step's work had to be done again, what one run of it costs, and where the defects come from. This page is a snapshot written hourly, so it lags the live figures by up to an hour — the time above is when it was written, not when you opened it.</p>
            <iframe id="pipelineFrame" title="Pipeline metrics" style="width:100%;height:calc(100vh - 230px);min-height:520px;border:1px solid #2c2c2a;border-radius:6px;background:#141414;"></iframe>
        </div>

        <div id="ai-sessions-tab" class="tab-page" style="display:none;">
            <div class="header">
                <h1>AI Sessions</h1>
                <button class="refresh-btn" onclick="loadAiSessions()">&#x21bb; Refresh</button>
                <a href="${AI_SESSIONS_API}" target="_blank" class="json-link">JSON</a>
                <span class="last-updated" id="aiSessionsLastSynced"></span>
            </div>
            <p class="feedback-intro">Live Claude Code sessions across all users plus anything active in the last 24h. <strong>Activity</strong>: active (&lt;30m) · idle (30m–6h) · <strong>dormant</strong> (no activity 6h+) · <strong>looping</strong> (still active after 8h+). An <strong>ORPHAN</strong> is a live session backed by neither a terminal tab nor the Studio panel.</p>
            <div class="stats" id="aiSessionsStats"></div>
            <div class="filters">
                <input type="text" id="aiSessionsSearch" placeholder="Search sessions..." oninput="renderAiSessions()">
                <button class="filter-btn active" data-ai-filter="all" onclick="setAiSessionsFilter('all')">All</button>
                <button class="filter-btn" data-ai-filter="live" onclick="setAiSessionsFilter('live')">Live</button>
                <button class="filter-btn" data-ai-filter="active" onclick="setAiSessionsFilter('active')">Active</button>
                <button class="filter-btn" data-ai-filter="dormant" onclick="setAiSessionsFilter('dormant')">Dormant</button>
                <button class="filter-btn" data-ai-filter="looping" onclick="setAiSessionsFilter('looping')">Looping</button>
                <button class="filter-btn" data-ai-filter="orphan" onclick="setAiSessionsFilter('orphan')">Orphans</button>
                <button class="filter-btn" data-ai-filter="ended" onclick="setAiSessionsFilter('ended')">Ended (24h)</button>
            </div>
            <div class="table-wrap">
                <table id="aiSessionsTable">
                    <thead><tr>
                        <th>User</th>
                        <th>PID</th>
                        <th>Model</th>
                        <th>Runtime</th>
                        <th>Tokens (out / cache-read)</th>
                        <th>Activity</th>
                        <th>Attached to</th>
                        <th>Status</th>
                    </tr></thead>
                    <tbody id="aiSessionsBody"></tbody>
                </table>
            </div>
        </div>

        <div class="modal" id="agentModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="modalTitle">Task</h2>
                    <button class="modal-close" onclick="closeAgentModal()">&times;</button>
                </div>
                <div class="modal-tabs">
                    <button class="mtab active" data-mtab="prompt" onclick="switchModalTab('prompt')">Prompt</button>
                    <button class="mtab" data-mtab="output" onclick="switchModalTab('output')">Output</button>
                </div>
                <div class="modal-body">
                    <div id="modalPrompt" class="mtab-content active md-body"></div>
                    <div id="modalOutput" class="mtab-content output-formatted"></div>
                </div>
            </div>
        </div>

        <div class="modal" id="fsHealthModal">
            <div class="modal-content fs-health-modal-content">
                <div class="modal-header">
                    <h2>FreshService Connection Health</h2>
                    <button class="modal-close" onclick="closeFsHealthModal()">&times;</button>
                </div>
                <div class="modal-body" id="fsHealthModalBody"></div>
            </div>
        </div>

        <div class="modal" id="labelManagerModal">
            <div class="modal-content label-manager-content">
                <div class="modal-header">
                    <h2>Manage labels</h2>
                    <button class="modal-close" onclick="closeLabelManager()">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="labelManagerList"></div>
                    <div class="label-create-row">
                        <input type="text" id="newLabelName" placeholder="New label name" maxlength="40" onkeydown="if(event.key==='Enter')createLabel()">
                        <input type="color" id="newLabelColor" value="#3b82f6" title="Label colour">
                        <button class="filter-btn label-create-btn" onclick="createLabel()">Add label</button>
                    </div>
                    <div class="label-create-error" id="labelCreateError"></div>
                </div>
            </div>
        </div>
    `;

    // Render column toggles
    renderColumnToggles();
}

function renderColumnToggles() {
    const grid = document.getElementById('columnsGrid');
    if (!grid) return;
    const visible = getVisibleColumns();
    grid.innerHTML = AGENT_COLUMNS.map(c =>
        `<button class="col-toggle ${visible.includes(c.key) ? 'active' : ''}" data-col="${c.key}" onclick="toggleColumn('${c.key}')">${c.label}</button>`
    ).join('');
}

function toggleColumnsPanel() {
    const panel = document.getElementById('columnsPanel');
    panel.style.display = panel.style.display === 'none' ? '' : 'none';
}

// ─── Tab switching ──────────────────────────────────────────────

function switchTab(tab, updateHash = true) {
    currentTab = tab;
    document.querySelectorAll('.page-tab').forEach(b =>
        b.classList.toggle('active', b.dataset.page === tab)
    );
    document.getElementById('tickets-tab').style.display = tab === 'tickets' ? '' : 'none';
    document.getElementById('agents-tab').style.display = tab === 'agents' ? '' : 'none';
    document.getElementById('task-lists-tab').style.display = tab === 'task-lists' ? '' : 'none';
    const fbTab = document.getElementById('feedback-tab');
    if (fbTab) fbTab.style.display = tab === 'feedback' ? '' : 'none';
    const aiTab = document.getElementById('ai-sessions-tab');
    if (aiTab) aiTab.style.display = tab === 'ai-sessions' ? '' : 'none';
    const pipelineTab = document.getElementById('pipeline-tab');
    if (pipelineTab) pipelineTab.style.display = tab === 'pipeline' ? '' : 'none';
    if (tab === 'agents' && agentTasks.length === 0) loadAgents();
    if (tab === 'task-lists') loadTaskLists();
    if (tab === 'feedback') loadFeedback();
    if (tab === 'ai-sessions') loadAiSessions();
    if (tab === 'pipeline') loadPipeline();
    if (updateHash) window.location.hash = tab === 'tickets' ? '' : tab;
}

// ─── Automation Feedback ────────────────────────────────────────

function escFb(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function loadFeedback() {
    const c = document.getElementById('feedbackContainer');
    if (!c) return;
    c.innerHTML = '<p class="feedback-empty">Loading…</p>';
    try {
        const resp = await fetch('/api/automation-feedback');
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        renderFeedback(data.feedback || []);
    } catch (e) {
        c.innerHTML = '<p class="feedback-empty">Failed to load: ' + escFb(e.message || e) + '</p>';
    }
}

function renderFeedback(items) {
    const c = document.getElementById('feedbackContainer');
    if (!c) return;
    if (!items.length) {
        c.innerHTML = '<p class="feedback-empty">No feedback yet. Use the ⚑ Report automation issue button on any ticket.</p>';
        return;
    }
    const badge = (st) => {
        const k = String(st || '').toLowerCase();
        const cls = k.includes('triag') ? 'fb-badge-triaged'
            : k.includes('approv') ? 'fb-badge-approved'
            : k.includes('done') ? 'fb-badge-done'
            : 'fb-badge-new';
        return '<span class="fb-badge ' + cls + '">' + escFb(st || 'New') + '</span>';
    };
    c.innerHTML = items.map(it => {
        const tlink = it.ticket_url
            ? '<a href="' + escFb(it.ticket_url) + '" target="_blank">' + escFb(it.ticket || '') + '</a>'
            : escFb(it.ticket || '');
        const triage = it.triage
            ? '<details class="fb-triage"><summary>Triage proposal</summary><pre>' + escFb(it.triage) + '</pre></details>'
            : '<p class="fb-untriaged">Not triaged yet.</p>';
        const isTriaged = String(it.status || '').toLowerCase().includes('triag');
        const actions = isTriaged
            ? '<div class="fb-actions">'
                + '<button class="fb-btn fb-approve" onclick="approveFeedback(\'' + escFb(it.id) + '\')">Approve</button>'
                + '<button class="fb-btn fb-reject" onclick="rejectFeedback(\'' + escFb(it.id) + '\')">Reject</button>'
                + '</div>'
            : '';
        return '<div class="fb-card">'
            + '<div class="fb-card-head">' + badge(it.status)
            + ' <span class="fb-ticket">' + tlink + '</span>'
            + '<span class="fb-created">' + escFb(it.created || '') + '</span></div>'
            + '<div class="fb-note">' + escFb(it.note || '') + '</div>'
            + triage
            + actions
            + '</div>';
    }).join('');
}

async function approveFeedback(id) {
    if (!confirm('Approve this proposal? It moves to "Approved — to implement".')) return;
    await feedbackDecision(id, 'approve', {});
}
async function rejectFeedback(id) {
    const reason = prompt('Reject this feedback — optional reason:');
    if (reason === null) return; // cancelled
    await feedbackDecision(id, 'reject', { reason: reason });
}
async function feedbackDecision(id, action, body) {
    try {
        const resp = await fetch('/api/automation-feedback/' + encodeURIComponent(id) + '/' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body || {}),
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        await resp.json();
        loadFeedback();
    } catch (e) {
        alert('Failed: ' + (e.message || e));
    }
}

// ─── Tickets data fetching ──────────────────────────────────────

async function loadTickets() {
    try {
        const resp = await fetch(TICKETS_API + '?t=' + Date.now());
        const db = await resp.json();
        tickets = Object.values(db.tickets || {}).map(t => {
            const fs = t.freshservice || {};
            fs._internal = t.internal || {};
            // Mirror pipeline_section to top-level so sortBy('pipeline_section') works.
            fs.pipeline_section = fs._internal.pipeline_section || '';
            return fs;
        });
        document.getElementById('lastUpdated').textContent = 'Last synced: ' + (db.last_synced || 'unknown');
        renderStats();
        renderTable();
    } catch (e) {
        document.getElementById('ticketBody').innerHTML =
            '<tr><td colspan="14" class="empty">Failed to load tickets: ' + e.message + '</td></tr>';
    }
}

// ─── Agents data fetching ───────────────────────────────────────

function agentRangeParams() {
    const now = Date.now();
    const hours = { '24h': 24, '7d': 24 * 7, '30d': 24 * 30 };
    if (agentRange in hours) {
        return '&since=' + encodeURIComponent(new Date(now - hours[agentRange] * 3600e3).toISOString());
    }
    if (agentRange === 'custom') {
        let p = '';
        const since = document.getElementById('agentSinceDate')?.value;
        const until = document.getElementById('agentUntilDate')?.value;
        if (since) p += '&since=' + encodeURIComponent(new Date(since + 'T00:00:00').toISOString());
        if (until) p += '&until=' + encodeURIComponent(new Date(until + 'T23:59:59').toISOString());
        return p;
    }
    return ''; // 'all' — no bounds, full history
}

function setAgentRange(range) {
    agentRange = range;
    document.querySelectorAll('[data-agent-range]').forEach(b =>
        b.classList.toggle('active', b.dataset.agentRange === range));
    const custom = document.getElementById('agentCustomRange');
    if (custom) custom.style.display = range === 'custom' ? '' : 'none';
    loadAgents();
}

// Rebuild the user/model/source dropdowns from the loaded tasks, keeping the
// current selection when it still exists in the new data.
function rebuildAgentFilterOptions() {
    const defs = [
        { id: 'agentUserFilter',   field: 'username', label: 'All users' },
        { id: 'agentModelFilter',  field: 'model',    label: 'All models' },
        { id: 'agentSourceFilter', field: 'source',   label: 'All sources' },
    ];
    for (const { id, field, label } of defs) {
        const sel = document.getElementById(id);
        if (!sel) continue;
        const current = sel.value;
        const values = [...new Set(agentTasks.map(t => t[field]).filter(Boolean))].sort();
        sel.innerHTML = `<option value="">${label}</option>` +
            values.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
        if (values.includes(current)) sel.value = current;
    }
}

async function loadAgents() {
    try {
        const resp = await fetch(AGENTS_API + '?t=' + Date.now() + agentRangeParams());
        const data = await resp.json();
        agentTasks = data.tasks || [];
        rebuildAgentFilterOptions();
        renderAgentStats();
        renderAgentTable();
        loadUsage();
    } catch (e) {
        const cols = getVisibleColumns().length;
        document.getElementById('agentBody').innerHTML =
            `<tr><td colspan="${cols}" class="empty">Failed to load agents: ${e.message}</td></tr>`;
    }
}

// ─── Ticket Stats ───────────────────────────────────────────────

// A ticket is "internally parked" when our pipeline has moved it to a
// terminal section. The pipeline is the canonical source of truth — our
// sections decide what shows where. External channel state (FreshService
// status, future Gmail / WhatsApp / etc.) is just input that feeds our
// channel-side handlers (OnCustomerReplied, OnTicketClosed, watchdog spam
// routing); those handlers translate channel events into pipeline section
// changes. The dashboard trusts the pipeline_section directly — no
// defensive cross-check against channel-specific status codes, because
// that would make the pipeline depend on whichever channel happens to be
// in use today.
//
// If a customer reply reopens a ticket we had marked Closed, the pipeline
// reacts via the OnCustomerReplied handler which moves the ticket out of
// ## Closed. If that handler ever fails to fire, that's a pipeline bug to
// fix at the source — not a defensive filter to add here.
function isInternallyParked(t) {
    const sec = (t._internal && t._internal.pipeline_section) || '';
    // FreshService's own spam flag counts too: such a ticket keeps a normal
    // Open status, so without this it sits in the Active/Open queue forever
    // even though FreshService has already judged it as spam.
    return sec === 'Spam' || sec === 'Closed' || t.spam === true;
}

function renderStats() {
    const active = tickets.filter(t => [2, 3, 10].includes(t.status) && !t.deleted && !isInternallyParked(t));
    const open = active.filter(t => t.status === 2);
    const pending = active.filter(t => t.status === 3);
    const categories = {};
    active.forEach(t => { const cat = t.category || 'Uncategorized'; categories[cat] = (categories[cat] || 0) + 1; });

    let html = `
        <div class="stat"><div class="stat-value">${active.length}</div><div class="stat-label">Active</div></div>
        <div class="stat"><div class="stat-value">${open.length}</div><div class="stat-label">Open</div></div>
        <div class="stat"><div class="stat-value">${pending.length}</div><div class="stat-label">Pending</div></div>
    `;
    Object.entries(categories).sort((a, b) => b[1] - a[1]).forEach(([cat, count]) => {
        html += `<div class="stat"><div class="stat-value">${count}</div><div class="stat-label">${cat}</div></div>`;
    });
    document.getElementById('stats').innerHTML = html;
}

// ─── Agent Stats ────────────────────────────────────────────────

function renderAgentStats() {
    const running = agentTasks.filter(t => t.status === 'running').length;
    const pending = agentTasks.filter(t => t.status === 'pending').length;
    const completed = agentTasks.filter(t => t.status === 'completed').length;
    const failed = agentTasks.filter(t => t.status === 'failed').length;
    const cancelled = agentTasks.filter(t => t.status === 'cancelled').length;
    const totalCost = agentTasks.reduce((s, t) => s + (t.cost_usd || 0), 0);

    document.getElementById('agentStats').innerHTML = `
        <div class="stat"><div class="stat-value" style="color:#58a6ff">${running}</div><div class="stat-label">Running</div></div>
        <div class="stat"><div class="stat-value" style="color:#d29922">${pending}</div><div class="stat-label">Pending</div></div>
        <div class="stat"><div class="stat-value" style="color:#3fb950">${completed}</div><div class="stat-label">Completed</div></div>
        <div class="stat"><div class="stat-value" style="color:${failed > 0 ? '#f85149' : '#8b949e'}">${failed}</div><div class="stat-label">Failed</div></div>
        <div class="stat"><div class="stat-value" style="color:${cancelled > 0 ? '#8b949e' : '#8b949e'}">${cancelled}</div><div class="stat-label">Cancelled</div></div>
        <div class="stat"><div class="stat-value" style="color:#bc8cff">$${totalCost.toFixed(2)}</div><div class="stat-label">Total Cost</div></div>
    `;
}

// ─── Ticket Filtering & Rendering ───────────────────────────────

function getFiltered() {
    const search = document.getElementById('search').value.toLowerCase();
    const lblMap = labelsById();
    // Split the label filter into include (show only) / exclude (hide) sets once.
    const includeIds = [], excludeIds = [];
    for (const [id, state] of Object.entries(labelFilterState)) {
        (state === 'exclude' ? excludeIds : includeIds).push(Number(id));
    }
    return tickets.filter(t => {
        // Starred-only filter is layered ON TOP of the status filter — when
        // active, the status filter still applies, but only starred tickets
        // pass the gate.
        if (showStarredOnly && !isTicketStarred(t.id)) return false;
        // Label filter. Exclude wins: a ticket carrying ANY hidden label is
        // dropped. Otherwise, if any "show only" labels are active, the ticket
        // must carry AT LEAST ONE of them (OR).
        const ticketLabelIds = (t._internal && t._internal.label_ids) || [];
        if (excludeIds.length && ticketLabelIds.some(id => excludeIds.includes(id))) return false;
        if (includeIds.length && !ticketLabelIds.some(id => includeIds.includes(id))) return false;
        // Internally-parked tickets (## Spam, ## Closed) never show in Active /
        // Open / Pending — that's the whole point of those sections.
        if (['active', 'open', 'pending'].includes(currentFilter) && isInternallyParked(t)) return false;
        if (currentFilter === 'active') { if (![2, 3, 10].includes(t.status) || t.deleted) return false; }
        else if (currentFilter === 'open') { if (t.status !== 2 || t.deleted) return false; }
        else if (currentFilter === 'pending') { if (t.status !== 3 || t.deleted) return false; }
        else if (currentFilter === 'closed') { if (![4, 5].includes(t.status) && !t.deleted) return false; }
        if (search) {
            const labelNames = ticketLabelIds.map(id => (lblMap[id] && lblMap[id].name) || '').join(' ');
            const hay = `${t.id} ${t.subject} ${t.requester_name} ${t.category} ${labelNames}`.toLowerCase();
            if (!hay.includes(search)) return false;
        }
        return true;
    });
}

function renderTable() {
    const filtered = getFiltered();
    filtered.sort((a, b) => {
        let va = a[currentSort], vb = b[currentSort];
        if (typeof va === 'string') { va = va.toLowerCase(); vb = (vb || '').toLowerCase(); }
        if (va < vb) return -1 * sortDir;
        if (va > vb) return 1 * sortDir;
        return 0;
    });

    // Keep the selection honest: only tickets the operator can still see stay
    // selected, so the bulk counter always matches the ticked boxes on screen.
    pruneSelection(filtered);

    if (!filtered.length) {
        document.getElementById('ticketBody').innerHTML = '<tr><td colspan="15" class="empty">No tickets found</td></tr>';
        renderBulkBar();
        syncBulkSelectAll();
        return;
    }

    document.getElementById('ticketBody').innerHTML = filtered.map(t => {
        const statusClass = t.status === 2 ? 'open' : t.status === 3 ? 'pending' : t.status === 10 ? 'notification' : t.status === 5 ? 'closed' : 'resolved';
        const catClass = (t.category || '').toLowerCase().replace(/[^a-z]/g, '');
        const prioClass = (PRIORITY_MAP[t.priority] || '').toLowerCase();
        const created = compactLocalTime(t.created_at);
        const updated = compactLocalTime(t.updated_at);
        const pipelineSection = t._internal.pipeline_section || '';
        const pipelineSlug = pipelineSection.toLowerCase().replace(/[^a-z]+/g, '-').replace(/(^-|-$)/g, '');
        const starred = isTicketStarred(t.id);
        const lblMap = labelsById();
        const labelChipsHtml = ((t._internal.label_ids) || [])
            .map(id => lblMap[id])
            .filter(Boolean)
            .map(l => `<span class="label-chip" style="--lc:${l.color}">${esc(l.name)}</span>`)
            .join('');
        const selected = isTicketSelected(t.id);
        const rowCls = [starred ? 'row-starred' : '', selected ? 'row-selected' : ''].filter(Boolean).join(' ');
        return `<tr data-ticket-id="${t.id}"${rowCls ? ` class="${rowCls}"` : ''}>
            <td class="bulk-col"><input type="checkbox" class="bulk-select-box" data-ticket-id="${t.id}" ${selected ? 'checked' : ''} onchange="toggleTicketSelection('${t.id}', this.checked)" title="Select for bulk actions"></td>
            <td class="star-col"><span class="ticket-star ${starred ? 'starred' : ''}" onclick="toggleTicketStar('${t.id}', event)" title="${starred ? 'Unstar' : 'Star this ticket'}">${starred ? '★' : '☆'}</span></td>
            <td><span class="category category-${catClass}">${t.category || '-'}</span></td>
            <td class="labels-cell">${labelChipsHtml}<button class="label-add-btn" onclick="openLabelPicker(event, '${t.id}')" title="Assign labels">+</button></td>
            <td>
                <a href="https://youritsolutions.freshservice.com/a/tickets/${t.id}" target="_blank">#${t.id}</a>
                <a href="#" class="ticket-detail-link" onclick="openTicketDetailFromTable(event, '${t.id}')" title="Open inline detail viewer">Details</a>
            </td>
            <td><button type="button" class="badge badge-${statusClass} badge-status-btn" onclick="openStatusPicker(event, '${t.id}')" title="Click to change the FreshService status">${STATUS_MAP[t.status] || t.status || '—'}<span class="badge-status-caret">▾</span></button></td>
            <td class="pipeline-stage">${pipelineSection ? `<span class="badge badge-stage badge-stage-${pipelineSlug}">${esc(pipelineSection)}</span>` : '<span class="badge badge-stage-none">—</span>'}</td>
            <td class="priority-${prioClass}">${PRIORITY_MAP[t.priority] || t.priority}</td>
            <td class="subject" title="${esc(t.subject)}">${t.spam ? '<span class="badge badge-spam" title="FreshService marked this ticket as spam">SPAM</span> ' : ''}${esc(t.subject)}</td>
            <td class="requester" title="${esc(t.requester_name)}">${esc(t.requester_name)}</td>
            <td class="timestamp" title="${esc(created.utc)}">${created.text}</td>
            <td class="timestamp" title="${esc(updated.utc)}">${updated.text}</td>
            <td class="internal-summary" title="${esc(t._internal.summary)}">${esc(t._internal.summary)}</td>
            <td class="internal-action">${esc(t._internal.next_action)}</td>
            <td>${t._internal.ticket_file ? '<a href="' + t._internal.ticket_file + '" target="_blank">View</a>' : ''}</td>
        </tr>`;
    }).join('');

    renderBulkBar();
    syncBulkSelectAll();
}

// ─── Ticket Detail viewer (Vue) ─────────────────────────────────
// The "Details" link in each ticket row calls into here. The Vue bundle
// (resources/js/app.js) exposes window.openTicketDetail(id).

function openTicketDetailFromTable(event, ticketId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    if (typeof window.openTicketDetail === 'function') {
        window.openTicketDetail(ticketId);
    } else {
        console.warn('openTicketDetail not available; Vue bundle not loaded?');
    }
}

// ─── Helpers ────────────────────────────────────────────────────

function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Table-width timestamp in the viewer's zone: `{ text, utc }`.
 *
 * The table columns are narrow, so the date stays in the compact
 * "07 Aug 11:55 CEST" shape rather than the panel's full form — but it carries
 * the same zone abbreviation, and the same stored-UTC tooltip, so a time read
 * in the list and the same time read in the panel can never disagree.
 */
function compactLocalTime(value) {
    const L = window.LocalTime;
    const d = L ? L.parse(value) : (value ? new Date(value) : null);
    if (!d || isNaN(d.getTime())) return { text: '', utc: '' };
    const day = String(d.getDate()).padStart(2, '0');
    const mon = d.toLocaleString('en-IE', { month: 'short' });
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    const zone = L ? ' ' + L.zoneAbbr(d) : '';
    return { text: `${day} ${mon} ${hh}:${mm}${zone}`, utc: L ? L.utc(d) : '' };
}

function formatTime(iso) {
    if (!iso) return '-';
    return compactLocalTime(iso).text || '-';
}

/** A `<td>` showing a timestamp in the viewer's zone, stored UTC on hover. */
function timestampCell(value) {
    const t = compactLocalTime(value);
    return `<td class="timestamp" title="${esc(t.utc)}">${t.text || '-'}</td>`;
}

function formatDurationSec(sec) {
    if (sec == null || sec < 0) return '-';
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm ' + s + 's';
}

function formatDuration(start, end) {
    if (!start) return '-';
    const diff = Math.floor(((end ? new Date(end) : new Date()) - new Date(start)) / 1000);
    return formatDurationSec(diff);
}

function formatCost(v) {
    if (v == null) return '-';
    return '$' + v.toFixed(2);
}

function formatTokens(v) {
    if (v == null) return '-';
    if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
    if (v >= 1000) return (v / 1000).toFixed(1) + 'K';
    return v.toString();
}

function getPromptFirstLine(prompt) {
    if (!prompt) return '-';
    const first = prompt.split('\n')[0].replace(/^#+\s*/, '').trim();
    return first.length > 40 ? first.substring(0, 37) + '...' : (first || '-');
}

// ─── Agent Table rendering ──────────────────────────────────────

function getFilteredAgents() {
    const search = (document.getElementById('agentSearch')?.value || '').toLowerCase();
    const user = document.getElementById('agentUserFilter')?.value || '';
    const model = document.getElementById('agentModelFilter')?.value || '';
    const source = document.getElementById('agentSourceFilter')?.value || '';
    return agentTasks.filter(t => {
        if (agentFilter !== 'all' && t.status !== agentFilter) return false;
        if (user && t.username !== user) return false;
        if (model && t.model !== model) return false;
        if (source && t.source !== source) return false;
        if (search) {
            const hay = `${t.id} ${t.name} ${t.prompt} ${t.username} ${t.model} ${t.source} ${t.error}`.toLowerCase();
            if (!hay.includes(search)) return false;
        }
        return true;
    });
}

function agentCellValue(t, col) {
    const isRunning = t.status === 'running';
    const startTs = t.started_at ? Math.floor(new Date(t.started_at).getTime() / 1000) : '';
    switch (col.key) {
        case 'name':         return `<td class="task-name" title="${esc(t.id)}">${esc(t.name || t.id || '-')}</td>`;
        case 'prompt':       return `<td class="command" title="${esc(getPromptFirstLine(t.prompt))}">${esc(getPromptFirstLine(t.prompt))}</td>`;
        case 'model':        return `<td class="model">${esc(t.model || '-')}</td>`;
        case 'username':     return `<td class="user">${esc(t.username || '-')}</td>`;
        case 'source':       return `<td class="source"><span class="source-badge source-${esc(t.source || 'unknown')}">${esc(t.source || '-')}</span></td>`;
        case 'status':       return `<td><span class="agent-status agent-status-${t.status}">${t.status}</span></td>`;
        case 'created_at':   return timestampCell(t.created_at);
        case 'started_at':   return timestampCell(t.started_at);
        case 'completed_at': return timestampCell(t.completed_at);
        case 'duration':     return `<td class="timestamp ${isRunning ? 'agent-tick' : ''}" ${isRunning && startTs ? `data-start="${startTs}"` : ''}>${t.duration_ms != null ? formatDurationSec(Math.round(t.duration_ms / 1000)) : formatDuration(t.started_at, t.completed_at)}</td>`;
        case 'queue_wait':   return `<td class="timestamp">${t.queue_wait_seconds != null ? formatDurationSec(t.queue_wait_seconds) : '-'}</td>`;
        case 'cost_usd':     return `<td class="cost">${formatCost(t.cost_usd)}</td>`;
        case 'num_turns':    return `<td class="num">${t.num_turns != null ? t.num_turns : '-'}</td>`;
        case 'input_tokens': return `<td class="num">${formatTokens(t.input_tokens)}</td>`;
        case 'output_tokens':return `<td class="num">${formatTokens(t.output_tokens)}</td>`;
        case 'cache_read':   return `<td class="num">${formatTokens(t.cache_read_tokens)}</td>`;
        case 'cache_create': return `<td class="num">${formatTokens(t.cache_creation_tokens)}</td>`;
        case 'total_tokens': return `<td class="num">${formatTokens(t.total_tokens)}</td>`;
        case 'stop_reason':  return `<td class="meta">${esc(t.stop_reason || '-')}</td>`;
        case 'error':        return `<td class="error-cell" title="${esc(t.error)}">${esc(t.error ? (t.error.length > 30 ? t.error.substring(0, 27) + '...' : t.error) : '-')}</td>`;
        default:             return '<td>-</td>';
    }
}

function agentSortValue(t, sortKey) {
    if (sortKey === 'name') return (t.name || t.id || '').toLowerCase();
    if (sortKey === 'prompt') return getPromptFirstLine(t.prompt).toLowerCase();
    if (sortKey === 'duration_ms') return t.duration_ms || 0;
    // Date columns must compare as numbers: a single row with a missing date
    // would otherwise compare number-vs-string ("equal" to everything), making
    // the comparator intransitive and silently breaking the whole sort.
    if (sortKey === 'created_at' || sortKey === 'started_at' || sortKey === 'completed_at') {
        const ts = new Date(t[sortKey] || t.failed_at || 0).getTime();
        return isNaN(ts) ? 0 : ts;
    }
    const v = t[sortKey];
    if (typeof v === 'string') return v.toLowerCase();
    return v ?? 0;
}

function renderAgentTable() {
    const visible = getVisibleColumns();
    const visCols = AGENT_COLUMNS.filter(c => visible.includes(c.key));

    // Render header
    document.getElementById('agentHead').innerHTML = visCols.map(c =>
        `<th onclick="agentSortBy('${c.sortKey}')">${c.label} <span class="sort-arrow" id="asort-${c.sortKey}">${agentSort === c.sortKey ? (agentSortDir === -1 ? '&#x25BC;' : '&#x25B2;') : ''}</span></th>`
    ).join('');

    const filtered = getFilteredAgents();

    // Sort
    filtered.sort((a, b) => {
        const va = agentSortValue(a, agentSort);
        const vb = agentSortValue(b, agentSort);
        if (va < vb) return -1 * agentSortDir;
        if (va > vb) return 1 * agentSortDir;
        return 0;
    });

    if (!filtered.length) {
        document.getElementById('agentBody').innerHTML =
            `<tr><td colspan="${visCols.length}" class="empty">No agent tasks found</td></tr>`;
        return;
    }

    document.getElementById('agentBody').innerHTML = filtered.map(t => {
        const cells = visCols.map(c => agentCellValue(t, c)).join('');
        return `<tr class="agent-row" data-id="${esc(t.id)}" data-prompt="${esc(t.prompt || 'No prompt')}" data-status="${esc(t.status)}">${cells}</tr>`;
    }).join('');

    // Attach click handlers
    document.querySelectorAll('.agent-row').forEach(row => {
        row.addEventListener('click', () => openAgentModal(row));
    });
}

// ─── Agent modal ────────────────────────────────────────────────

let currentAgentId = null;
let agentOutputInterval = null;

function openAgentModal(row) {
    currentAgentId = row.dataset.id;
    const status = row.dataset.status;
    document.getElementById('modalTitle').textContent = currentAgentId || 'Task';
    document.getElementById('modalPrompt').innerHTML = renderMarkdown((row.dataset.prompt || '').replace(/\\n/g, '\n'));
    document.getElementById('modalOutput').textContent = '';

    document.querySelectorAll('.mtab').forEach(t => t.classList.remove('active'));
    document.querySelector('.mtab[data-mtab="prompt"]').classList.add('active');
    document.querySelectorAll('.mtab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('modalPrompt').classList.add('active');
    document.getElementById('agentModal').classList.add('open');
    window.ModalStack.push({ id: 'agentModal', close: closeAgentModal });

    if (agentOutputInterval) clearInterval(agentOutputInterval);
    if (status === 'running') {
        agentOutputInterval = setInterval(() => {
            if (document.getElementById('modalOutput').classList.contains('active')) loadAgentOutput(currentAgentId);
        }, 3000);
    }
}

function switchModalTab(tab) {
    document.querySelectorAll('.mtab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.mtab[data-mtab="${tab}"]`).classList.add('active');
    document.querySelectorAll('.mtab-content').forEach(c => c.classList.remove('active'));
    document.getElementById(tab === 'prompt' ? 'modalPrompt' : 'modalOutput').classList.add('active');
    if (tab === 'output' && currentAgentId) loadAgentOutput(currentAgentId);
}

function formatAgentOutput(raw) {
    const lines = raw.split('\n').filter(l => l.trim());
    const parts = [];

    for (const line of lines) {
        let obj;
        try { obj = JSON.parse(line); } catch { continue; }

        if (obj.type === 'system' && obj.subtype === 'init') continue;
        if (obj.type === 'system' && obj.subtype === 'result') {
            const r = obj.result || '';
            parts.push(`<div class="out-result"><strong>Result:</strong> ${esc(typeof r === 'string' ? r : JSON.stringify(r))}</div>`);
            if (obj.cost_usd) parts.push(`<div class="out-meta">Cost: $${obj.cost_usd.toFixed(2)} | Turns: ${obj.num_turns || '-'} | Duration: ${obj.duration_ms ? formatDurationSec(Math.round(obj.duration_ms / 1000)) : '-'}</div>`);
            continue;
        }
        if (obj.type === 'system') continue;

        if (obj.type === 'assistant' && obj.message?.content) {
            for (const block of obj.message.content) {
                if (block.type === 'thinking') continue;
                if (block.type === 'text' && block.text) {
                    parts.push(`<div class="out-text">${esc(block.text)}</div>`);
                }
                if (block.type === 'tool_use') {
                    const args = block.input ? JSON.stringify(block.input) : '';
                    const shortArgs = args.length > 200 ? args.substring(0, 197) + '...' : args;
                    parts.push(`<div class="out-tool"><span class="out-tool-name">${esc(block.name)}</span> <span class="out-tool-args">${esc(shortArgs)}</span></div>`);
                }
            }
            continue;
        }

        if (obj.type === 'user' && obj.message?.content) {
            for (const block of obj.message.content) {
                if (block.type === 'tool_result') {
                    const content = typeof block.content === 'string' ? block.content : (Array.isArray(block.content) ? block.content.map(c => c.text || '').join('') : JSON.stringify(block.content));
                    const short = content.length > 500 ? content.substring(0, 497) + '...' : content;
                    const isErr = block.is_error;
                    parts.push(`<div class="out-tool-result ${isErr ? 'out-error' : ''}">${esc(short)}</div>`);
                }
            }
            continue;
        }
    }

    return parts.length ? parts.join('') : '<span style="color:#484f58">(no meaningful output)</span>';
}

async function loadAgentOutput(taskId) {
    const el = document.getElementById('modalOutput');
    if (!el.innerHTML.trim() || el.querySelector('[data-loading]')) el.innerHTML = '<span style="color:#484f58" data-loading>Loading output...</span>';
    try {
        const resp = await fetch(BASE_URL + '/api/agents/' + encodeURIComponent(taskId) + '/output');
        const data = await resp.json();
        if (data.success && data.content) {
            el.innerHTML = formatAgentOutput(data.content);
        }
        else if (data.status === 'running') el.innerHTML = '<span style="color:#484f58">Task is running. Output will stream here...</span>';
        else el.textContent = '(no output recorded)';
        el.scrollTop = el.scrollHeight;
    } catch {
        el.innerHTML = '<span style="color:#f85149">Failed to load output</span>';
    }
}

function closeAgentModal() {
    document.getElementById('agentModal').classList.remove('open');
    if (agentOutputInterval) { clearInterval(agentOutputInterval); agentOutputInterval = null; }
    window.ModalStack.remove('agentModal');
}

// ─── Live duration ticker ───────────────────────────────────────

setInterval(() => {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('.agent-tick').forEach(el => {
        const start = parseInt(el.dataset.start);
        if (!start) return;
        el.textContent = formatDurationSec(now - start);
    });
}, 1000);

// ─── User interactions ──────────────────────────────────────────

function setFilter(f) {
    currentFilter = f;
    document.querySelectorAll('#tickets-tab .filter-btn').forEach(b => b.classList.toggle('active', b.dataset.filter === f));
    saveUiPrefs();
    renderTable();
}

function setAgentFilter(f) {
    agentFilter = f;
    document.querySelectorAll('#agents-tab .filter-btn').forEach(b => b.classList.toggle('active', b.dataset.agentFilter === f));
    renderAgentTable();
}

function sortBy(col) {
    if (currentSort === col) { sortDir *= -1; } else { currentSort = col; sortDir = -1; }
    document.querySelectorAll('#tickets-tab .sort-arrow').forEach(s => s.textContent = '');
    document.getElementById('sort-' + col).textContent = sortDir === -1 ? '\u25BC' : '\u25B2';
    saveUiPrefs();
    renderTable();
}

function agentSortBy(col) {
    if (agentSort === col) { agentSortDir *= -1; } else { agentSort = col; agentSortDir = -1; }
    renderAgentTable();
}

// ─── Init ────────────────────────────────────────────────────────

loadUiPrefs();   // restore the operator's toolbar choices before the first render
renderApp();
applyUiPrefs();  // reflect the restored status filter / starred / sort on the buttons

const VALID_TABS = ['tickets', 'agents', 'task-lists', 'feedback', 'ai-sessions', 'pipeline'];
const initialTab = window.location.hash.replace('#', '') || 'tickets';
if (VALID_TABS.includes(initialTab)) switchTab(initialTab, false);
window.addEventListener('hashchange', () => {
    const tab = window.location.hash.replace('#', '') || 'tickets';
    if (VALID_TABS.includes(tab)) switchTab(tab, false);
});

loadTickets();
loadLabels();
// Auto-refresh the ACTIVE tab every 30s so the panel always shows current data.
// Skipped while the browser tab is hidden, to avoid pointless background fetches.
setInterval(() => {
    if (document.hidden) return;
    if (currentTab === 'tickets') loadTickets();
    else if (currentTab === 'agents') loadAgents();
    else if (currentTab === 'feedback') loadFeedback();
    else if (currentTab === 'task-lists') loadTaskLists();
    else if (currentTab === 'ai-sessions') loadAiSessions();
}, 30 * 1000);

// ─── Pipeline ───────────────────────────────────────────────────

async function loadPipeline(force = false) {
    const frame = document.getElementById('pipelineFrame');
    if (!frame) return;
    if (!pipelineLoaded || force) {
        frame.src = PIPELINE_PAGE + '?t=' + Date.now();
        pipelineLoaded = true;
    }
    try {
        const resp = await fetch(PIPELINE_STATUS_API + '?t=' + Date.now());
        const status = await resp.json();
        const label = document.getElementById('pipelineLastSynced');
        if (!label) return;
        label.textContent = status.available
            ? 'Snapshot written: ' + status.written_at
            : 'No snapshot written yet';
    } catch (e) {
        const label = document.getElementById('pipelineLastSynced');
        if (label) label.textContent = 'Could not read when the snapshot was written';
    }
}

// ─── AI Sessions ────────────────────────────────────────────────

let aiSessions = [];
let aiSessionsSummary = { live: 0, orphans: 0, tokens_24h: 0, users: 0 };
let aiSessionsCollectedAt = null;
let aiSessionsFilter = 'all';

async function loadAiSessions() {
    try {
        const resp = await fetch(AI_SESSIONS_API + '?t=' + Date.now());
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        aiSessions = data.sessions || [];
        aiSessionsSummary = data.summary || { live: 0, orphans: 0, tokens_24h: 0, users: 0 };
        aiSessionsCollectedAt = data.collected_at || null;
        renderAiSessionsStats();
        renderAiSessions();
        const el = document.getElementById('aiSessionsLastSynced');
        if (el) el.textContent = 'Last synced: ' + (aiSessionsCollectedAt ? formatTime(aiSessionsCollectedAt) : 'unknown');
    } catch (e) {
        const b = document.getElementById('aiSessionsBody');
        if (b) b.innerHTML = '<tr><td colspan="8" class="empty">Failed to load AI sessions: ' + esc(e.message || String(e)) + '</td></tr>';
    }
}

function renderAiSessionsStats() {
    const s = aiSessionsSummary;
    const dormant = aiSessions.filter(x => x.activity === 'dormant').length;
    const looping = aiSessions.filter(x => x.activity === 'looping').length;
    document.getElementById('aiSessionsStats').innerHTML = `
        <div class="stat"><div class="stat-value" style="color:#58a6ff">${s.live || 0}</div><div class="stat-label">Live sessions</div></div>
        <div class="stat"><div class="stat-value" style="color:${dormant > 0 ? '#d29922' : '#8b949e'}">${dormant}</div><div class="stat-label">Dormant (6h+)</div></div>
        <div class="stat"><div class="stat-value" style="color:${looping > 0 ? '#f85149' : '#8b949e'}">${looping}</div><div class="stat-label">Looping (8h+)</div></div>
        <div class="stat"><div class="stat-value" style="color:${(s.orphans || 0) > 0 ? '#f85149' : '#8b949e'}">${s.orphans || 0}</div><div class="stat-label">Orphans</div></div>
        <div class="stat"><div class="stat-value" style="color:#bc8cff">${formatTokens(s.tokens_24h || 0)}</div><div class="stat-label">Tokens (24h)</div></div>
        <div class="stat"><div class="stat-value">${s.users || 0}</div><div class="stat-label">Users reporting</div></div>
    `;
}

function setAiSessionsFilter(f) {
    aiSessionsFilter = f;
    document.querySelectorAll('#ai-sessions-tab .filter-btn').forEach(b => b.classList.toggle('active', b.dataset.aiFilter === f));
    renderAiSessions();
}

// Humanise a duration in seconds like "1d 2h", "23m", "45s".
function formatRuntime(sec) {
    if (sec == null || sec < 0) return '-';
    const d = Math.floor(sec / 86400);
    const h = Math.floor((sec % 86400) / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    if (d > 0) return d + 'd ' + h + 'h';
    if (h > 0) return h + 'h ' + m + 'm';
    if (m > 0) return m + 'm';
    return s + 's';
}

// Compact token count like "102k", "15.0m".
function formatTokensCompact(v) {
    if (v == null) return '0';
    if (v >= 1000000) return (v / 1000000).toFixed(1) + 'm';
    if (v >= 1000) return Math.round(v / 1000) + 'k';
    return String(v);
}

function getFilteredAiSessions() {
    const search = (document.getElementById('aiSessionsSearch')?.value || '').toLowerCase();
    return aiSessions.filter(s => {
        if (aiSessionsFilter === 'live' && s.status !== 'live') return false;
        if (aiSessionsFilter === 'orphan' && !s.orphan) return false;
        if (aiSessionsFilter === 'ended' && s.status !== 'ended') return false;
        if (aiSessionsFilter === 'active' && s.activity !== 'active') return false;
        if (aiSessionsFilter === 'dormant' && s.activity !== 'dormant') return false;
        if (aiSessionsFilter === 'looping' && s.activity !== 'looping') return false;
        if (search) {
            const hay = `${s.user} ${s.pid} ${s.model} ${s.session_id} ${s.cwd} ${s.attached_label || ''}`.toLowerCase();
            if (!hay.includes(search)) return false;
        }
        return true;
    });
}

// Activity badge colours: active=green, idle=grey, dormant=amber, looping=red.
const AI_ACTIVITY_STYLE = {
    active:  { color: '#3fb950', label: 'active' },
    idle:    { color: '#8b949e', label: 'idle' },
    dormant: { color: '#d29922', label: 'dormant' },
    looping: { color: '#f85149', label: 'looping' },
    ended:   { color: '#6e7681', label: 'ended' },
    unknown: { color: '#6e7681', label: '—' },
};

function aiActivityCell(s) {
    const a = AI_ACTIVITY_STYLE[s.activity] || AI_ACTIVITY_STYLE.unknown;
    const age = (s.last_active_age_seconds != null)
        ? 'last activity ' + formatRuntime(s.last_active_age_seconds) + ' ago' : '';
    return `<span class="ai-badge" style="background:${a.color}22;color:${a.color};border:1px solid ${a.color}55" title="${esc(age)}">${a.label}</span>`;
}

function aiAttachedCell(s) {
    if (!s.attached_label) return '<span style="color:#6e7681">—</span>';
    const stateColor = s.attached_state === 'attached' ? '#3fb950'
        : (s.attached_state === 'panel' ? '#bc8cff' : '#8b949e');
    const proj = s.project ? ` · <span style="color:#6e7681">${esc(s.project)}</span>` : '';
    const state = s.attached_state ? ` <span style="color:${stateColor};font-size:0.85em">(${esc(s.attached_state)})</span>` : '';
    return `<span>${esc(s.attached_label)}${state}${proj}</span>`;
}

function renderAiSessions() {
    const body = document.getElementById('aiSessionsBody');
    if (!body) return;
    const rows = getFilteredAiSessions();
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="8" class="empty">No AI sessions found</td></tr>';
        return;
    }
    body.innerHTML = rows.map(s => {
        const tok = s.tokens || {};
        const tokenCell = formatTokensCompact(tok.output || 0) + ' / ' + formatTokensCompact(tok.cache_read || 0);
        let statusCell, rowClass = '';
        if (s.orphan) {
            rowClass = ' class="ai-orphan-row"';
            statusCell = '<span class="ai-badge ai-badge-orphan">ORPHAN — no open tab</span>';
        } else if (s.status === 'live') {
            statusCell = '<span class="ai-badge ai-badge-live">live</span>';
        } else {
            statusCell = '<span class="ai-badge ai-badge-ended">ended</span>';
        }
        return `<tr${rowClass}>
            <td class="user">${esc(s.user || '-')}</td>
            <td class="num">${s.pid != null ? esc(String(s.pid)) : '-'}</td>
            <td class="model">${esc(s.model || '-')}</td>
            <td class="timestamp">${s.status === 'live' ? formatRuntime(s.runtime_seconds) : '-'}</td>
            <td class="num" title="in ${formatTokensCompact(tok.input || 0)} · out ${formatTokensCompact(tok.output || 0)} · cache-read ${formatTokensCompact(tok.cache_read || 0)} · cache-write ${formatTokensCompact(tok.cache_write || 0)}">${tokenCell}</td>
            <td>${aiActivityCell(s)}</td>
            <td class="ai-attached">${aiAttachedCell(s)}</td>
            <td>${statusCell}</td>
        </tr>`;
    }).join('');
}

// ─── FreshService connection health ─────────────────────────────

let fsHealthData = null;

async function loadFsHealth() {
    try {
        const resp = await fetch(FS_HEALTH_API + '?t=' + Date.now());
        fsHealthData = await resp.json();
    } catch (e) {
        fsHealthData = {
            status: 'failed',
            tested_at: null,
            summary: 'Could not reach /api/health/freshservice: ' + e.message,
            checks: {},
            age_seconds: null,
        };
    }
    renderFsHealthBadge();
    // If the modal is open, refresh its body too.
    if (document.getElementById('fsHealthModal')?.classList.contains('open')) {
        renderFsHealthModalBody();
    }
}

function renderFsHealthBadge() {
    const badge = document.getElementById('fsHealthBadge');
    const label = document.getElementById('fsHealthLabel');
    if (!badge || !label || !fsHealthData) return;
    badge.classList.remove('fs-health-ok', 'fs-health-fail', 'fs-health-unknown');
    if (fsHealthData.status === 'ok') {
        badge.classList.add('fs-health-ok');
        label.textContent = 'FS OK';
    } else {
        badge.classList.add('fs-health-fail');
        label.textContent = 'FS error';
    }
}

function openFsHealthModal() {
    const modal = document.getElementById('fsHealthModal');
    if (!modal) return;
    renderFsHealthModalBody();
    modal.classList.add('open');
    window.ModalStack.push({ id: 'fsHealthModal', close: closeFsHealthModal });
}

function closeFsHealthModal() {
    document.getElementById('fsHealthModal')?.classList.remove('open');
    window.ModalStack.remove('fsHealthModal');
}

function renderFsHealthModalBody() {
    const body = document.getElementById('fsHealthModalBody');
    if (!body) return;
    if (!fsHealthData) {
        body.innerHTML = '<p>Loading…</p>';
        return;
    }
    const d = fsHealthData;
    const ageLine = d.age_seconds == null ? ''
        : `<p class="fs-health-age">Result is ${d.age_seconds}s old (cron runs every minute)</p>`;
    const statusBanner = d.status === 'ok'
        ? '<div class="fs-health-banner fs-health-banner-ok">All checks passed</div>'
        : `<div class="fs-health-banner fs-health-banner-fail">${escapeHtml(d.summary || 'Failed')}</div>`;
    const checks = d.checks || {};
    const rows = Object.entries(checks).map(([name, c]) => {
        const ok = c.ok ? '✓' : '✗';
        const cls = c.ok ? 'fs-check-ok' : 'fs-check-fail';
        const detail = formatCheckDetail(name, c);
        return `
            <div class="fs-check-row ${cls}">
                <div class="fs-check-name"><span class="fs-check-mark">${ok}</span> ${escapeHtml(humanCheckName(name))}</div>
                <div class="fs-check-detail">${detail}</div>
            </div>`;
    }).join('');
    // Pipeline section counts table.
    const counts = d.pipeline_counts || {};
    const countEntries = Object.entries(counts);
    const totalOpen = countEntries
        .filter(([s]) => !['Closed','Informational','Notification','Spam'].includes(s))
        .reduce((sum, [, n]) => sum + n, 0);
    const countRows = countEntries.map(([section, n]) => {
        const terminal = ['Closed','Informational','Notification','Spam'].includes(section);
        const cls = terminal ? 'fs-pipeline-terminal' : (n > 0 ? 'fs-pipeline-active' : 'fs-pipeline-empty');
        return `<tr class="${cls}"><td>${escapeHtml(section)}</td><td class="fs-pipeline-count">${n}</td></tr>`;
    }).join('');
    const countsBlock = countRows ? `
        <div class="fs-health-section-title">Pipeline (${totalOpen} active)</div>
        <table class="fs-pipeline-table">${countRows}</table>
    ` : '';

    // Recent errors block.
    const errors = (d.recent_errors || []);
    const errorsBlock = errors.length ? `
        <div class="fs-health-section-title fs-health-section-title-error">Recent errors (today)</div>
        <div class="fs-errors-list">${errors.map(e => `<div class="fs-error-line">${escapeHtml(e)}</div>`).join('')}</div>
    ` : '';

    body.innerHTML = `
        ${statusBanner}
        <p class="fs-health-tested-at">Tested at: <code>${escapeHtml(d.tested_at || 'never')}</code></p>
        ${ageLine}
        <div class="fs-check-list">${rows || '<p>No checks ran.</p>'}</div>
        ${countsBlock}
        ${errorsBlock}
    `;
}

function humanCheckName(name) {
    return ({
        'api_reachable': 'FreshService API reachable',
        'sync_recent': 'Ticket sync ran recently',
        'events_poller_recent': 'Events poller ran recently',
        'db_integrity': 'Ticket statuses intact',
    })[name] || name;
}

function formatCheckDetail(name, c) {
    if (c.ok) {
        if (name === 'api_reachable') return `OK (${c.duration_ms}ms)`;
        if (name === 'sync_recent') return `Last sync: ${escapeHtml(c.last_synced)} (${c.age_seconds}s ago)`;
        if (name === 'events_poller_recent') return `Last run: ${escapeHtml(c.last_run)} (${c.age_seconds}s ago)`;
        if (name === 'db_integrity') return `All ${c.total} tickets have a real status`;
        return 'OK';
    }
    return `<span class="fs-check-error">${escapeHtml(c.error || 'Unknown error')}</span>`;
}

function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));
}

// Lightweight markdown renderer for task file content.
// Handles: headings, bold/italic, inline code, links, checkboxes,
// bullet lists, horizontal rules, blockquotes, and <sub>/<sup> HTML.
function renderMarkdown(md) {
    if (!md) return '';

    // Strip YAML frontmatter (--- block at top).
    md = md.replace(/^---\n[\s\S]*?\n---\n?/, '');
    // Collapse HTML comments (template guidance) onto one line so they render
    // as readable text instead of raw <!-- ... --> markup.
    md = md.replace(/<!--([\s\S]*?)-->/g, (m, c) => '\n' + c.replace(/\s+/g, ' ').trim() + '\n');

    const lines = md.split('\n');
    const out = [];
    let inList = false;
    let inBlockquote = false;

    const closeList = () => { if (inList) { out.push('</ul>'); inList = false; } };
    const closeBQ   = () => { if (inBlockquote) { out.push('</blockquote>'); inBlockquote = false; } };

    const inline = (s) => {
        // Preserve <sub>, <sup>, <a href=...> tags.
        s = s
            // Bold+italic
            .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
            // Bold
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Italic (asterisk)
            .replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>')
            // Italic (underscore) — word boundaries only, so snake_case is safe
            .replace(/(?<![A-Za-z0-9_])_(?!_)([^_\n]+?)_(?![A-Za-z0-9_])/g, '<em>$1</em>')
            // Inline code
            .replace(/`([^`]+)`/g, '<code class="md-code">$1</code>')
            // Links [text](url)
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener" class="md-link">$1</a>');
        return s;
    };

    const esc = (s) => s.replace(/&(?!amp;|lt;|gt;|quot;|#)/g, '&amp;').replace(/<(?!\/?(?:sub|sup|strong|em|a|code|br)\b)/g, '&lt;');

    for (let i = 0; i < lines.length; i++) {
        const raw = lines[i];
        const line = raw.trimEnd();

        // Horizontal rule
        if (/^---+$/.test(line) || /^\*\*\*+$/.test(line)) {
            closeList(); closeBQ();
            out.push('<hr class="md-hr">');
            continue;
        }

        // Headings
        const hm = line.match(/^(#{1,4})\s+(.+)$/);
        if (hm) {
            closeList(); closeBQ();
            const level = Math.min(hm[1].length, 4);
            out.push(`<h${level} class="md-h${level}">${inline(esc(hm[2]))}</h${level}>`);
            continue;
        }

        // Blockquote
        if (/^>\s?/.test(line)) {
            closeList();
            if (!inBlockquote) { out.push('<blockquote class="md-blockquote">'); inBlockquote = true; }
            out.push(`<p>${inline(esc(line.replace(/^>\s?/, '')))}</p>`);
            continue;
        } else {
            closeBQ();
        }

        // Checklist item
        const clm = line.match(/^(\s*)-\s*\[([ xX])\]\s+(.*)$/);
        if (clm) {
            if (!inList) { out.push('<ul class="md-list">'); inList = true; }
            const checked = clm[2].trim().toLowerCase() === 'x';
            const indent = clm[1] ? 'style="margin-left:' + (clm[1].length * 8) + 'px"' : '';
            out.push(`<li class="md-check-item" ${indent}><span class="md-checkbox">${checked ? '☑' : '☐'}</span> ${inline(esc(clm[3]))}</li>`);
            continue;
        }

        // Bullet list item
        const bm = line.match(/^(\s*)[-*+]\s+(.*)$/);
        if (bm) {
            if (!inList) { out.push('<ul class="md-list">'); inList = true; }
            const indent = bm[1] ? 'style="margin-left:' + (bm[1].length * 8) + 'px"' : '';
            out.push(`<li class="md-list-item" ${indent}>${inline(esc(bm[2]))}</li>`);
            continue;
        }

        // Blank line
        if (line.trim() === '') {
            closeList();
            out.push('<div class="md-gap"></div>');
            continue;
        }

        // Paragraph / plain line
        closeList();
        out.push(`<p class="md-p">${inline(esc(line))}</p>`);
    }

    closeList();
    closeBQ();
    return out.join('\n');
}

loadFsHealth();
setInterval(loadFsHealth, 60 * 1000);  // refresh every 60s on the page (cron writes every minute)

// Single Escape handler for the whole app: close ONLY the frontmost modal.
// Capture phase + stopPropagation so no component-level handler also fires.
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && window.ModalStack.size > 0) {
        e.preventDefault();
        e.stopPropagation();
        window.ModalStack.pop();
    }
}, true);
// NOTE: backdrop-click-to-close is intentionally removed — clicking outside a
// modal must never close it.

// ─── Usage Limits Charts ────────────────────────────────────────
// Per-account rate-limit utilization over time, from /api/agents/usage
// (fed by /shared/logs/agent-usage/*.jsonl — one snapshot per finished
// agent job). Two small multiples on a shared 0–100% scale: the 5-hour
// session window and the 7-day weekly window.

let usageData = {};          // user -> [{ts, five_hour, seven_day, ...}]
const USAGE_API = BASE_URL + '/api/agents/usage';

// The usage charts have their own range, independent of the table's filter:
// the default question is "where were we against the limits this week, and
// at what times of day" — so 7 days, aggregated into equal periods.
let usageRange = '7d';
const USAGE_RANGES = { '24h': 24, '3d': 72, '7d': 168, '14d': 336, '30d': 720 };
// Bucket width per range: equal periods, peak utilization per period.
const USAGE_BUCKETS = { '24h': 1, '3d': 3, '7d': 3, '14d': 6, '30d': 12 };

function setUsageRange(range) {
    usageRange = range;
    document.querySelectorAll('[data-usage-range]').forEach(b =>
        b.classList.toggle('active', b.dataset.usageRange === range));
    loadUsage();
}

// Validated categorical palette (dark surface #161b22): fixed slot order,
// color follows the account — filtering never repaints survivors.
const USAGE_COLORS = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
const USAGE_USER_ORDER = ['adam', 'adam_airforce1', 'adam_airforce2', 'robert', 'artur', 'chris'];

function usageColor(user) {
    let idx = USAGE_USER_ORDER.indexOf(user);
    if (idx === -1) {
        const extras = Object.keys(usageData).filter(u => !USAGE_USER_ORDER.includes(u)).sort();
        idx = USAGE_USER_ORDER.length + extras.indexOf(user);
    }
    return USAGE_COLORS[idx % USAGE_COLORS.length];
}

async function loadUsage() {
    try {
        const [t0] = usageTimeDomain();
        const resp = await fetch(USAGE_API + '?t=' + Date.now() +
            '&since=' + encodeURIComponent(new Date(t0).toISOString()));
        const data = await resp.json();
        usageData = data.users || {};
    } catch (e) {
        usageData = {};
    }
    renderUsageCharts();
}

function usageTimeDomain() {
    const now = Date.now();
    const bucketMs = USAGE_BUCKETS[usageRange] * 3600e3;
    // Align the window to whole buckets so periods are equal and stable.
    const end = Math.ceil(now / bucketMs) * bucketMs;
    return [end - USAGE_RANGES[usageRange] * 3600e3, end];
}

// Equal periods, peak per period: within each bucket keep the snapshot with
// the highest utilization (the question is "did we hit the limit then").
function usageBucketize(pts, field, t0, bucketMs) {
    const buckets = new Map();
    for (const pt of pts) {
        if (pt[field] == null) continue;
        const idx = Math.floor((Date.parse(pt.ts) - t0) / bucketMs);
        if (idx < 0) continue;
        const cur = buckets.get(idx);
        if (!cur || pt[field] > cur.raw[field]) {
            buckets.set(idx, { count: (cur?.count || 0) + 1, raw: pt });
        } else {
            cur.count++;
        }
    }
    return [...buckets.entries()].sort((a, b) => a[0] - b[0]).map(([idx, b]) => ({
        t: t0 + (idx + 0.5) * bucketMs,
        bucketStart: t0 + idx * bucketMs,
        bucketEnd: t0 + (idx + 1) * bucketMs,
        v: Math.max(0, Math.min(100, b.raw[field])),
        count: b.count,
        raw: b.raw,
    }));
}

function usageVisibleUsers() {
    const sel = document.getElementById('agentUserFilter')?.value || '';
    let users = Object.keys(usageData).sort((a, b) => a.localeCompare(b));
    if (sel) users = users.filter(u => u === sel);
    return users;
}

function usageTimeTicks(t0, t1) {
    const span = t1 - t0;
    if (span <= 26 * 3600e3) {
        const ticks = [];
        for (let i = 0; i <= 4; i++) ticks.push(t0 + span * i / 4);
        return ticks;
    }
    // Multi-day: tick on local midnights so day/night periods line up.
    const days = span / (24 * 3600e3);
    const stepDays = days <= 8 ? 1 : days <= 16 ? 2 : 5;
    const ticks = [];
    const d = new Date(t0);
    d.setHours(0, 0, 0, 0);
    if (d.getTime() < t0) d.setDate(d.getDate() + 1);
    while (d.getTime() <= t1) {
        ticks.push(d.getTime());
        d.setDate(d.getDate() + stepDays);
    }
    return ticks;
}

function usageFmtTime(ms, spanMs) {
    const d = new Date(ms);
    const hm = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (spanMs <= 26 * 3600e3) return hm;
    const dm = d.toLocaleDateString([], { day: 'numeric', month: 'short' });
    return spanMs <= 8 * 24 * 3600e3 ? dm + ' ' + hm : dm;
}

function renderUsageCharts() {
    renderUsageChart('usagePlot5h', 'five_hour');
    renderUsageChart('usagePlot7d', 'seven_day');
    renderUsageLegend();
}

function renderUsageLegend() {
    const el = document.getElementById('usageLegend');
    if (!el) return;
    const users = usageVisibleUsers();
    el.innerHTML = users.map(u =>
        `<span class="usage-key"><span class="usage-chip" style="background:${usageColor(u)}"></span>${esc(u)}</span>`
    ).join('');
}

function renderUsageChart(elId, field) {
    const host = document.getElementById(elId);
    if (!host) return;
    const users = usageVisibleUsers();
    const [t0, t1] = usageTimeDomain();
    const bucketMs = USAGE_BUCKETS[usageRange] * 3600e3;
    const series = users.map(u => ({
        user: u,
        pts: usageBucketize(usageData[u] || [], field, t0, bucketMs),
    })).filter(sr => sr.pts.length);

    if (!series.length) {
        host.innerHTML = '<div class="usage-empty">No usage snapshots in this range yet.</div>';
        return;
    }

    const W = Math.max(host.clientWidth || 500, 320), H = 190;
    const direct = series.length <= 4;
    const m = { l: 36, r: direct ? 110 : 14, t: 10, b: 24 };
    const x = t => m.l + (W - m.l - m.r) * (t - t0) / Math.max(t1 - t0, 1);
    const y = v => m.t + (H - m.t - m.b) * (1 - v / 100);

    let g = '';
    for (const v of [0, 25, 50, 75, 100]) {
        const yy = y(v);
        g += `<line x1="${m.l}" x2="${W - m.r}" y1="${yy}" y2="${yy}" stroke="${v === 0 ? '#30363d' : '#21262d'}" stroke-width="1"/>`;
        g += `<text x="${m.l - 6}" y="${yy + 3}" text-anchor="end" class="usage-tick">${v}%</text>`;
    }
    const spanMs = t1 - t0;
    const dayTicks = spanMs > 26 * 3600e3;
    for (const tk of usageTimeTicks(t0, t1)) {
        if (dayTicks) g += `<line x1="${x(tk).toFixed(1)}" x2="${x(tk).toFixed(1)}" y1="${m.t}" y2="${H - m.b}" stroke="#21262d" stroke-width="1"/>`;
        const lbl = dayTicks
            ? new Date(tk).toLocaleDateString([], { weekday: 'short', day: 'numeric' })
            : usageFmtTime(tk, spanMs);
        g += `<text x="${x(tk)}" y="${H - 6}" text-anchor="middle" class="usage-tick">${esc(lbl)}</text>`;
    }

    let lines = '', labels = [];
    for (const sr of series) {
        const c = usageColor(sr.user);
        const pts = sr.pts;
        // Break the line where buckets are missing — an empty period is
        // "no snapshots", not an interpolated value.
        const bucketGap = (USAGE_BUCKETS[usageRange] * 3600e3) * 1.5;
        let d = '';
        pts.forEach((pt, i) => {
            const move = i === 0 || (pt.t - pts[i - 1].t) > bucketGap;
            d += (move ? 'M' : 'L') + x(pt.t).toFixed(1) + ' ' + y(pt.v).toFixed(1) + ' ';
        });
        lines += `<path d="${d}" fill="none" stroke="${c}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`;
        for (const pt of pts) {
            lines += `<circle cx="${x(pt.t).toFixed(1)}" cy="${y(pt.v).toFixed(1)}" r="2.5" fill="${c}" stroke="#161b22" stroke-width="1.5"/>`;
        }
        if (direct) {
            const last = pts[pts.length - 1];
            labels.push({ y: y(last.v), color: c, text: sr.user });
        }
    }
    // De-collide direct end-labels (12px line height).
    labels.sort((a, b) => a.y - b.y);
    for (let i = 1; i < labels.length; i++) labels[i].y = Math.max(labels[i].y, labels[i - 1].y + 13);
    const labelSvg = labels.map(lb =>
        `<text x="${W - m.r + 8}" y="${Math.min(lb.y, H - m.b - 2) + 3}" class="usage-endlabel" fill="${lb.color}">` +
        `<tspan class="usage-endlabel-ink">${esc(lb.text)}</tspan></text>` +
        `<circle cx="${W - m.r + 3}" cy="${Math.min(lb.y, H - m.b - 2)}" r="3" fill="${lb.color}"/>`
    ).join('');

    host.innerHTML =
        `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" data-field="${field}">` +
        g + lines + labelSvg +
        `<line class="usage-cross" x1="0" x2="0" y1="${m.t}" y2="${H - m.b}" stroke="#484f58" stroke-width="1" style="display:none;"/>` +
        `<g class="usage-hover"></g>` +
        `<rect class="usage-capture" x="${m.l}" y="${m.t}" width="${W - m.l - m.r}" height="${H - m.t - m.b}" fill="transparent"/>` +
        `</svg>`;

    const svg = host.querySelector('svg');
    const capture = svg.querySelector('.usage-capture');
    capture.addEventListener('mousemove', ev => usageHover(ev, svg, series, { x, y, t0, t1, spanMs }));
    capture.addEventListener('mouseleave', () => usageHoverEnd(svg));
}

function usageHover(ev, svg, series, scale) {
    const rect = svg.getBoundingClientRect();
    const px = (ev.clientX - rect.left) * (svg.viewBox.baseVal.width / rect.width);
    // Nearest snapshot per series to the cursor's x position.
    const rows = [];
    let anchorX = null, anchorT = null;
    for (const sr of series) {
        let best = null, bestDx = Infinity;
        for (const pt of sr.pts) {
            const dx = Math.abs(scale.x(pt.t) - px);
            if (dx < bestDx) { bestDx = dx; best = pt; }
        }
        if (best && bestDx < 60) {
            rows.push({ sr, pt: best });
            if (anchorX === null || Math.abs(scale.x(best.t) - px) < Math.abs(anchorX - px)) {
                anchorX = scale.x(best.t); anchorT = best.t;
            }
        }
    }
    const cross = svg.querySelector('.usage-cross');
    const hover = svg.querySelector('.usage-hover');
    const tip = document.getElementById('usageTooltip');
    if (!rows.length) { usageHoverEnd(svg); return; }
    cross.style.display = '';
    cross.setAttribute('x1', anchorX); cross.setAttribute('x2', anchorX);
    hover.innerHTML = rows.map(r =>
        `<circle cx="${scale.x(r.pt.t).toFixed(1)}" cy="${scale.y(r.pt.v).toFixed(1)}" r="4" fill="${usageColor(r.sr.user)}" stroke="#161b22" stroke-width="2"/>`
    ).join('');
    const field = svg.dataset.field;
    const fmtReset = iso => iso ? new Date(iso).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : null;
    tip.style.display = '';
    const anchor = rows[0].pt;
    const fmtHM = ms => new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const periodLabel = new Date(anchor.bucketStart).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' }) +
        ' ' + fmtHM(anchor.bucketStart) + '–' + fmtHM(anchor.bucketEnd);
    tip.innerHTML =
        `<div class="usage-tip-time">${esc(periodLabel)}</div>` +
        rows.sort((a, b) => b.pt.v - a.pt.v).map(r => {
            const resetKey = field === 'five_hour' ? 'five_hour_resets_at' : 'seven_day_resets_at';
            const reset = fmtReset(r.pt.raw[resetKey]);
            const stale = r.pt.raw.fetched_at_ms && (Date.parse(r.pt.raw.ts) - r.pt.raw.fetched_at_ms) > 6 * 3600e3;
            return `<div class="usage-tip-row"><span class="usage-chip" style="background:${usageColor(r.sr.user)}"></span>` +
                `${esc(r.sr.user)} <strong>peak ${r.pt.v}%</strong>` +
                ` <span class="usage-tip-muted">(${r.pt.count} snapshot${r.pt.count === 1 ? '' : 's'})</span>` +
                (reset ? ` <span class="usage-tip-muted">resets ${esc(reset)}</span>` : '') +
                (stale ? ' <span class="usage-tip-muted">(stale cache)</span>' : '') + `</div>`;
        }).join('');
    const page = svg.closest('.usage-section').getBoundingClientRect();
    tip.style.left = Math.min(ev.clientX - page.left + 14, page.width - tip.offsetWidth - 8) + 'px';
    tip.style.top = (ev.clientY - page.top + 14) + 'px';
}

function usageHoverEnd(svg) {
    svg.querySelector('.usage-cross').style.display = 'none';
    svg.querySelector('.usage-hover').innerHTML = '';
    const tip = document.getElementById('usageTooltip');
    if (tip) tip.style.display = 'none';
}

window.addEventListener('resize', () => {
    if (document.getElementById('agents-tab')?.style.display !== 'none') renderUsageCharts();
});

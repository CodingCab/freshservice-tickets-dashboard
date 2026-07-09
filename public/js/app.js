/**
 * FreshService Tickets + Agent Queue SPA
 */

const BASE_URL = window.location.pathname.replace(/\/$/, '');
const TICKETS_API = BASE_URL + '/api/tickets';
const AGENTS_API = BASE_URL + '/api/agents';
const FS_HEALTH_API = BASE_URL + '/api/health/freshservice';
const AI_SESSIONS_API = BASE_URL + '/api/ai-sessions';
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
let showStarredOnly = false;

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
    renderTable();
}
// Expose to the Vue bundle (which calls toggleTicketStar from its star button).
window.toggleTicketStar = toggleTicketStar;
window.isTicketStarred = isTicketStarred;

let agentSort = 'created_at';
let agentSortDir = -1;
let agentFilter = 'all';

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
        </div>

        <div id="tickets-tab" class="tab-page">
            <div class="header">
                <h1>FreshService Tickets</h1>
                <button class="fs-health-badge fs-health-unknown" id="fsHealthBadge" onclick="openFsHealthModal()" title="FreshService connection health — click for details">
                    <span class="fs-health-dot"></span>
                    <span class="fs-health-label" id="fsHealthLabel">checking…</span>
                </button>
                <button class="refresh-btn" onclick="loadTickets()">&#x21bb; Refresh</button>
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
            <table>
                <thead><tr>
                    <th class="star-col" title="Star this ticket — toggles the per-row marker"></th>
                    <th onclick="sortBy('category')">Category <span class="sort-arrow" id="sort-category">&#x25B2;</span></th>
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
            <div class="filters">
                <input type="text" id="agentSearch" placeholder="Search agents..." oninput="renderAgentTable()">
                <button class="filter-btn active" data-agent-filter="all" onclick="setAgentFilter('all')">All</button>
                <button class="filter-btn" data-agent-filter="running" onclick="setAgentFilter('running')">Running</button>
                <button class="filter-btn" data-agent-filter="pending" onclick="setAgentFilter('pending')">Pending</button>
                <button class="filter-btn" data-agent-filter="completed" onclick="setAgentFilter('completed')">Completed</button>
                <button class="filter-btn" data-agent-filter="failed" onclick="setAgentFilter('failed')">Failed</button>
                <button class="filter-btn" data-agent-filter="cancelled" onclick="setAgentFilter('cancelled')">Cancelled</button>
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
                    <pre id="modalPrompt" class="mtab-content active"></pre>
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
    if (tab === 'agents' && agentTasks.length === 0) loadAgents();
    if (tab === 'task-lists') loadTaskLists();
    if (tab === 'feedback') loadFeedback();
    if (tab === 'ai-sessions') loadAiSessions();
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
            '<tr><td colspan="12" class="empty">Failed to load tickets: ' + e.message + '</td></tr>';
    }
}

// ─── Agents data fetching ───────────────────────────────────────

async function loadAgents() {
    try {
        const resp = await fetch(AGENTS_API + '?t=' + Date.now());
        const data = await resp.json();
        agentTasks = data.tasks || [];
        renderAgentStats();
        renderAgentTable();
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
    return sec === 'Spam' || sec === 'Closed';
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
    return tickets.filter(t => {
        // Starred-only filter is layered ON TOP of the status filter — when
        // active, the status filter still applies, but only starred tickets
        // pass the gate.
        if (showStarredOnly && !isTicketStarred(t.id)) return false;
        // Internally-parked tickets (## Spam, ## Closed) never show in Active /
        // Open / Pending — that's the whole point of those sections.
        if (['active', 'open', 'pending'].includes(currentFilter) && isInternallyParked(t)) return false;
        if (currentFilter === 'active') { if (![2, 3, 10].includes(t.status) || t.deleted) return false; }
        else if (currentFilter === 'open') { if (t.status !== 2 || t.deleted) return false; }
        else if (currentFilter === 'pending') { if (t.status !== 3 || t.deleted) return false; }
        else if (currentFilter === 'closed') { if (![4, 5].includes(t.status) && !t.deleted) return false; }
        if (search) {
            const hay = `${t.id} ${t.subject} ${t.requester_name} ${t.category}`.toLowerCase();
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

    if (!filtered.length) {
        document.getElementById('ticketBody').innerHTML = '<tr><td colspan="13" class="empty">No tickets found</td></tr>';
        return;
    }

    document.getElementById('ticketBody').innerHTML = filtered.map(t => {
        const statusClass = t.status === 2 ? 'open' : t.status === 3 ? 'pending' : t.status === 10 ? 'notification' : t.status === 5 ? 'closed' : 'resolved';
        const catClass = (t.category || '').toLowerCase().replace(/[^a-z]/g, '');
        const prioClass = (PRIORITY_MAP[t.priority] || '').toLowerCase();
        const created = t.created_at ? new Date(t.created_at).toLocaleDateString('en-IE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
        const updated = t.updated_at ? new Date(t.updated_at).toLocaleDateString('en-IE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
        const pipelineSection = t._internal.pipeline_section || '';
        const pipelineSlug = pipelineSection.toLowerCase().replace(/[^a-z]+/g, '-').replace(/(^-|-$)/g, '');
        const starred = isTicketStarred(t.id);
        return `<tr${starred ? ' class="row-starred"' : ''}>
            <td class="star-col"><span class="ticket-star ${starred ? 'starred' : ''}" onclick="toggleTicketStar('${t.id}', event)" title="${starred ? 'Unstar' : 'Star this ticket'}">${starred ? '★' : '☆'}</span></td>
            <td><span class="category category-${catClass}">${t.category || '-'}</span></td>
            <td>
                <a href="https://youritsolutions.freshservice.com/a/tickets/${t.id}" target="_blank">#${t.id}</a>
                <a href="#" class="ticket-detail-link" onclick="openTicketDetailFromTable(event, '${t.id}')" title="Open inline detail viewer">Details</a>
            </td>
            <td><span class="badge badge-${statusClass}">${STATUS_MAP[t.status] || t.status}</span></td>
            <td class="pipeline-stage">${pipelineSection ? `<span class="badge badge-stage badge-stage-${pipelineSlug}">${esc(pipelineSection)}</span>` : '<span class="badge badge-stage-none">—</span>'}</td>
            <td class="priority-${prioClass}">${PRIORITY_MAP[t.priority] || t.priority}</td>
            <td class="subject" title="${esc(t.subject)}">${esc(t.subject)}</td>
            <td class="requester" title="${esc(t.requester_name)}">${esc(t.requester_name)}</td>
            <td class="timestamp">${created}</td>
            <td class="timestamp">${updated}</td>
            <td class="internal-summary" title="${esc(t._internal.summary)}">${esc(t._internal.summary)}</td>
            <td class="internal-action">${esc(t._internal.next_action)}</td>
            <td>${t._internal.ticket_file ? '<a href="' + t._internal.ticket_file + '" target="_blank">View</a>' : ''}</td>
        </tr>`;
    }).join('');
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

function formatTime(iso) {
    if (!iso) return '-';
    const d = new Date(iso);
    const day = d.getDate().toString().padStart(2, '0');
    const mon = d.toLocaleString('en-IE', { month: 'short' });
    const hh = d.getHours().toString().padStart(2, '0');
    const mm = d.getMinutes().toString().padStart(2, '0');
    return `${day} ${mon} ${hh}:${mm}`;
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
    return agentTasks.filter(t => {
        if (agentFilter !== 'all' && t.status !== agentFilter) return false;
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
        case 'created_at':   return `<td class="timestamp">${formatTime(t.created_at)}</td>`;
        case 'started_at':   return `<td class="timestamp">${formatTime(t.started_at)}</td>`;
        case 'completed_at': return `<td class="timestamp">${formatTime(t.completed_at)}</td>`;
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
    document.getElementById('modalPrompt').textContent = (row.dataset.prompt || '').replace(/\\n/g, '\n');
    document.getElementById('modalOutput').textContent = '';

    document.querySelectorAll('.mtab').forEach(t => t.classList.remove('active'));
    document.querySelector('.mtab[data-mtab="prompt"]').classList.add('active');
    document.querySelectorAll('.mtab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('modalPrompt').classList.add('active');
    document.getElementById('agentModal').classList.add('open');

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
    renderTable();
}

function agentSortBy(col) {
    if (agentSort === col) { agentSortDir *= -1; } else { agentSort = col; agentSortDir = -1; }
    renderAgentTable();
}

// ─── Init ────────────────────────────────────────────────────────

renderApp();

const VALID_TABS = ['tickets', 'agents', 'task-lists', 'feedback', 'ai-sessions'];
const initialTab = window.location.hash.replace('#', '') || 'tickets';
if (VALID_TABS.includes(initialTab)) switchTab(initialTab, false);
window.addEventListener('hashchange', () => {
    const tab = window.location.hash.replace('#', '') || 'tickets';
    if (VALID_TABS.includes(tab)) switchTab(tab, false);
});

loadTickets();
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
}

function closeFsHealthModal() {
    document.getElementById('fsHealthModal')?.classList.remove('open');
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
        : `<p class="fs-health-age">Result is ${d.age_seconds}s old (cron runs every 30 min)</p>`;
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
    body.innerHTML = `
        ${statusBanner}
        <p class="fs-health-tested-at">Tested at: <code>${escapeHtml(d.tested_at || 'never')}</code></p>
        ${ageLine}
        <div class="fs-check-list">${rows || '<p>No checks ran.</p>'}</div>
        <p class="fs-health-source">Source: <code>${escapeHtml(d.file_path || '/shared/state/fs-connection-health.json')}</code> · written by <code>/shared/scripts/fs-connection-test.py</code></p>
    `;
}

function humanCheckName(name) {
    return ({
        'api_reachable': 'FreshService API reachable',
        'sync_recent': 'Ticket sync ran recently',
        'events_poller_recent': 'Events poller ran recently',
    })[name] || name;
}

function formatCheckDetail(name, c) {
    if (c.ok) {
        if (name === 'api_reachable') return `OK (${c.duration_ms}ms)`;
        if (name === 'sync_recent') return `Last sync: ${escapeHtml(c.last_synced)} (${c.age_seconds}s ago)`;
        if (name === 'events_poller_recent') return `Last run: ${escapeHtml(c.last_run)} (${c.age_seconds}s ago)`;
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

loadFsHealth();
setInterval(loadFsHealth, 5 * 60 * 1000);  // refresh every 5 min on the page (cron writes every 30 min)

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeAgentModal();
        closeFsHealthModal();
    }
});
document.getElementById('agentModal')?.addEventListener('click', e => { if (e.target.id === 'agentModal') closeAgentModal(); });
document.getElementById('fsHealthModal')?.addEventListener('click', e => { if (e.target.id === 'fsHealthModal') closeFsHealthModal(); });

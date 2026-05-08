/**
 * FreshService Tickets + Agent Queue SPA
 */

const BASE_URL = window.location.pathname.replace(/\/$/, '');
const TICKETS_API = BASE_URL + '/api/tickets';
const AGENTS_API = BASE_URL + '/api/agents';
// TASK_LISTS_JSON, taskListsTabHTML(), loadTaskLists() are provided by task-lists-view.js

const STATUS_MAP = { 2: 'Open', 3: 'Pending', 4: 'Resolved', 5: 'Closed', 9: 'Adam', 10: 'Notification' };
const PRIORITY_MAP = { 1: 'Low', 2: 'Medium', 3: 'High', 4: 'Urgent' };

let tickets = [];
let agentTasks = [];
let currentFilter = 'active';
let currentSort = 'category';
let sortDir = 1;
let currentTab = 'tickets';

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
        </div>

        <div id="tickets-tab" class="tab-page">
            <div class="header">
                <h1>FreshService Tickets</h1>
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
            </div>
            <table>
                <thead><tr>
                    <th onclick="sortBy('category')">Category <span class="sort-arrow" id="sort-category">&#x25B2;</span></th>
                    <th onclick="sortBy('id')">ID <span class="sort-arrow" id="sort-id"></span></th>
                    <th onclick="sortBy('status')">FreshService Status <span class="sort-arrow" id="sort-status"></span></th>
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
    if (tab === 'agents' && agentTasks.length === 0) loadAgents();
    if (tab === 'task-lists') loadTaskLists();
    if (updateHash) window.location.hash = tab === 'tickets' ? '' : tab;
}

// ─── Tickets data fetching ──────────────────────────────────────

async function loadTickets() {
    try {
        const resp = await fetch(TICKETS_API + '?t=' + Date.now());
        const db = await resp.json();
        tickets = Object.values(db.tickets || {}).map(t => {
            const fs = t.freshservice || {};
            fs._internal = t.internal || {};
            return fs;
        });
        document.getElementById('lastUpdated').textContent = 'Last synced: ' + (db.last_synced || 'unknown');
        renderStats();
        renderTable();
    } catch (e) {
        document.getElementById('ticketBody').innerHTML =
            '<tr><td colspan="11" class="empty">Failed to load tickets: ' + e.message + '</td></tr>';
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

function renderStats() {
    const active = tickets.filter(t => [2, 3, 10].includes(t.status) && !t.deleted);
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
        document.getElementById('ticketBody').innerHTML = '<tr><td colspan="11" class="empty">No tickets found</td></tr>';
        return;
    }

    document.getElementById('ticketBody').innerHTML = filtered.map(t => {
        const statusClass = t.status === 2 ? 'open' : t.status === 3 ? 'pending' : t.status === 10 ? 'notification' : t.status === 5 ? 'closed' : 'resolved';
        const catClass = (t.category || '').toLowerCase().replace(/[^a-z]/g, '');
        const prioClass = (PRIORITY_MAP[t.priority] || '').toLowerCase();
        const created = t.created_at ? new Date(t.created_at).toLocaleDateString('en-IE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
        const updated = t.updated_at ? new Date(t.updated_at).toLocaleDateString('en-IE', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
        return `<tr>
            <td><span class="category category-${catClass}">${t.category || '-'}</span></td>
            <td><a href="https://youritsolutions.freshservice.com/a/tickets/${t.id}" target="_blank">#${t.id}</a></td>
            <td><span class="badge badge-${statusClass}">${STATUS_MAP[t.status] || t.status}</span></td>
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

const VALID_TABS = ['tickets', 'agents', 'task-lists'];
const initialTab = window.location.hash.replace('#', '') || 'tickets';
if (VALID_TABS.includes(initialTab)) switchTab(initialTab, false);
window.addEventListener('hashchange', () => {
    const tab = window.location.hash.replace('#', '') || 'tickets';
    if (VALID_TABS.includes(tab)) switchTab(tab, false);
});

loadTickets();
setInterval(loadTickets, 5 * 60 * 1000);
setInterval(loadAgents, 60 * 1000);

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAgentModal(); });
document.getElementById('agentModal')?.addEventListener('click', e => { if (e.target.id === 'agentModal') closeAgentModal(); });

/**
 * FreshService Tickets SPA
 */

// API endpoint — served by Laravel
// Derive API URL from current page location so it works behind any prefix
const API_URL = window.location.pathname.replace(/\/$/, '') + '/api/tickets';
const STATUS_MAP = { 2: 'Open', 3: 'Pending', 4: 'Resolved', 5: 'Closed', 9: 'Adam', 10: 'Notification' };
const PRIORITY_MAP = { 1: 'Low', 2: 'Medium', 3: 'High', 4: 'Urgent' };

let tickets = [];       // Each entry: { ...freshservice fields, _internal: { ticket_file, next_action, summary } }
let currentFilter = 'active';
let currentSort = 'category';
let sortDir = 1;

// ─── Render the full app shell ───────────────────────────────────

function renderApp() {
    document.getElementById('app').innerHTML = `
        <div class="header">
            <h1>FreshService Tickets</h1>
            <button class="refresh-btn" onclick="loadTickets()">↻ Refresh</button>
            <a href="${API_URL}" target="_blank" class="json-link">JSON</a>
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
            <thead>
                <tr>
                    <th onclick="sortBy('category')">Category <span class="sort-arrow" id="sort-category">▲</span></th>
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
                </tr>
            </thead>
            <tbody id="ticketBody"></tbody>
        </table>
    `;
}

// ─── Data fetching ───────────────────────────────────────────────

async function loadTickets() {
    try {
        const resp = await fetch(API_URL + '?t=' + Date.now());
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

// ─── Stats ───────────────────────────────────────────────────────

function renderStats() {
    const active = tickets.filter(t => [2, 3, 10].includes(t.status) && !t.deleted);
    const open = active.filter(t => t.status === 2);
    const pending = active.filter(t => t.status === 3);
    const categories = {};
    active.forEach(t => {
        const cat = t.category || 'Uncategorized';
        categories[cat] = (categories[cat] || 0) + 1;
    });

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

// ─── Filtering ───────────────────────────────────────────────────

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

// ─── Table rendering ─────────────────────────────────────────────

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
        document.getElementById('ticketBody').innerHTML =
            '<tr><td colspan="11" class="empty">No tickets found</td></tr>';
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
            <td class="subject" title="${(t.subject || '').replace(/"/g, '&quot;')}">${t.subject || ''}</td>
            <td class="requester" title="${(t.requester_name || '').replace(/"/g, '&quot;')}">${t.requester_name || ''}</td>
            <td class="timestamp">${created}</td>
            <td class="timestamp">${updated}</td>
            <td class="internal-summary" title="${(t._internal.summary || '').replace(/"/g, '&quot;')}">${t._internal.summary || ''}</td>
            <td class="internal-action">${t._internal.next_action || ''}</td>
            <td>${t._internal.ticket_file ? '<a href="' + t._internal.ticket_file + '" target="_blank">View</a>' : ''}</td>
        </tr>`;
    }).join('');
}

// ─── User interactions ───────────────────────────────────────────

function setFilter(f) {
    currentFilter = f;
    document.querySelectorAll('.filter-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.filter === f)
    );
    renderTable();
}

function sortBy(col) {
    if (currentSort === col) { sortDir *= -1; } else { currentSort = col; sortDir = -1; }
    document.querySelectorAll('.sort-arrow').forEach(s => s.textContent = '');
    document.getElementById('sort-' + col).textContent = sortDir === -1 ? '▼' : '▲';
    renderTable();
}

// ─── Init ────────────────────────────────────────────────────────

renderApp();
loadTickets();
setInterval(loadTickets, 5 * 60 * 1000);

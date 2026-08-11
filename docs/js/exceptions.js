// Log-on exceptions entry form.
// Records approved short days in logon_exceptions so the weekly productivity
// report excludes them from the short-log-on trigger.

const REASON_LABELS = {
    sickness: 'Sickness',
    appointment: 'Appointment',
    half_day: 'Half-day shift',
    approved_early: 'Approved early leave',
    other: 'Other',
};

// ── DOM ──
const $agent  = document.getElementById('agent');
const $date   = document.getElementById('date');
const $reason = document.getElementById('reason');
const $note   = document.getElementById('note');
const $submit = document.getElementById('submit');
const $toast  = document.getElementById('toast');
const $recent = document.getElementById('recent-list');

// ── Supabase REST helpers (anon) ──
const HEADERS = {
    'apikey': SUPABASE_ANON_KEY,
    'Authorization': `Bearer ${SUPABASE_ANON_KEY}`,
    'Content-Type': 'application/json',
};

function sbGet(table, query) {
    return fetch(`${SUPABASE_URL}/rest/v1/${table}?${query}`, { headers: HEADERS }).then(r => r.json());
}
function sbUpsert(table, conflict, body) {
    return fetch(`${SUPABASE_URL}/rest/v1/${table}?on_conflict=${conflict}`, {
        method: 'POST',
        headers: { ...HEADERS, 'Prefer': 'resolution=merge-duplicates,return=minimal' },
        body: JSON.stringify(body),
    });
}
function sbDelete(table, query) {
    return fetch(`${SUPABASE_URL}/rest/v1/${table}?${query}`, {
        method: 'DELETE',
        headers: { ...HEADERS, 'Prefer': 'return=minimal' },
    });
}

// ── Helpers ──
function todayLocal() {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}
function fmtDate(iso) {
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}
function showToast(msg, ok) {
    $toast.textContent = msg;
    $toast.className = 'toast ' + (ok ? 'ok' : 'err');
    if (ok) setTimeout(() => { $toast.className = 'toast'; }, 4000);
}
function escapeHtml(s) {
    return (s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ── Load agent list ──
async function loadAgents() {
    try {
        const rows = await sbGet('productivity_agents', 'select=name&order=name.asc');
        const preAgent = new URLSearchParams(location.search).get('agent');
        if (!Array.isArray(rows) || !rows.length) {
            $agent.innerHTML = '<option value="">No agents found</option>';
            return;
        }
        $agent.innerHTML = '<option value="">Select an agent&hellip;</option>' +
            rows.map(r => `<option value="${escapeHtml(r.name)}"${r.name === preAgent ? ' selected' : ''}>${escapeHtml(r.name)}</option>`).join('');
    } catch (e) {
        $agent.innerHTML = '<option value="">Could not load agents</option>';
    }
}

// ── Load recent exceptions ──
async function loadRecent() {
    try {
        const rows = await sbGet('logon_exceptions', 'select=*&order=exception_date.desc,created_at.desc&limit=25');
        if (!Array.isArray(rows) || !rows.length) {
            $recent.innerHTML = '<p class="muted">No exceptions logged yet.</p>';
            return;
        }
        $recent.innerHTML = rows.map(r => `
            <div class="exc">
                <div>
                    <span class="who">${escapeHtml(r.agent_name)}</span>
                    <span class="reason-tag">${escapeHtml(REASON_LABELS[r.reason] || r.reason)}</span>
                    <div class="meta">${fmtDate(r.exception_date)}${r.note ? ' &mdash; ' + escapeHtml(r.note) : ''}</div>
                </div>
                <button class="del" title="Remove" data-id="${r.id}">&#10005;</button>
            </div>`).join('');
        $recent.querySelectorAll('.del').forEach(b => b.addEventListener('click', () => removeException(b.dataset.id)));
    } catch (e) {
        $recent.innerHTML = '<p class="muted">Could not load recent exceptions.</p>';
    }
}

// ── Submit ──
async function submit() {
    const agent = $agent.value;
    const date  = $date.value;
    const reason = $reason.value;
    const note = $note.value.trim();
    if (!agent) return showToast('Please choose an agent.', false);
    if (!date)  return showToast('Please choose a date.', false);

    $submit.disabled = true;
    try {
        const res = await sbUpsert('logon_exceptions', 'agent_name,exception_date', {
            agent_name: agent,
            exception_date: date,
            reason: reason,
            note: note || null,
            created_by: 'Tina',
        });
        if (!res.ok) throw new Error(await res.text());
        showToast(`Logged: ${agent} — ${fmtDate(date)} (${REASON_LABELS[reason]}).`, true);
        $note.value = '';
        loadRecent();
    } catch (e) {
        showToast('Could not save. Please try again.', false);
    } finally {
        $submit.disabled = false;
    }
}

// ── Delete ──
async function removeException(id) {
    try {
        const res = await sbDelete('logon_exceptions', `id=eq.${id}`);
        if (!res.ok) throw new Error(await res.text());
        loadRecent();
    } catch (e) {
        showToast('Could not remove that entry.', false);
    }
}

// ── Init ──
function init() {
    const params = new URLSearchParams(location.search);
    $date.value = params.get('date') || todayLocal();
    if (params.get('reason') && REASON_LABELS[params.get('reason')]) $reason.value = params.get('reason');
    $submit.addEventListener('click', submit);
    loadAgents();
    loadRecent();
}
init();

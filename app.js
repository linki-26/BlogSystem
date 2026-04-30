// app.js — LD4 Backend version
// No more hardcoded POSTS/USERS arrays. All data comes from PHP API.

// ─────────────────────────────────────────────────────────────
// SESSION CACHE
// We fetch the logged-in user once and cache it for the page
// so we don't make multiple requests to auth.php on one page
// ─────────────────────────────────────────────────────────────
let _sessionCache = undefined; // undefined = not yet fetched, null = not logged in

async function getSession() {
    if (_sessionCache !== undefined) return _sessionCache;
    try {
        const res  = await fetch('api/auth.php?action=me');
        const data = await res.json();
        _sessionCache = data.user || null;
    } catch {
        _sessionCache = null;
    }
    return _sessionCache;
}

// ─────────────────────────────────────────────────────────────
// API HELPER
// Central function for all fetch() calls to PHP
// Automatically throws an error if the server returns a non-ok status
// ─────────────────────────────────────────────────────────────
async function api(url, options = {}) {
    try {
        const res  = await fetch(url, options);
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            // data.errors is an array of validation errors
            // data.error is a single error string
            const messages = data.errors || (data.error ? [data.error] : ['Server error']);
            throw new Error(messages.join('\n'));
        }
        return data;
    } catch (err) {
        // Re-throw so callers can catch and show in the UI
        throw err;
    }
}

// ─────────────────────────────────────────────────────────────
// AUTH HELPERS
// ─────────────────────────────────────────────────────────────

// Call this at the top of any protected page
// roles = array like ['admin'] or ['admin','moderator']
async function requireAuth(roles) {
    const u = await getSession();
    if (!u) {
        window.location.href = 'login.html';
        return null;
    }
    if (roles && !roles.includes(u.role)) {
        window.location.href = 'index.html';
        return null;
    }
    return u;
}

// Call this on login.html — if already logged in, redirect to dashboard
async function redirectIfLoggedIn() {
    const u = await getSession();
    if (u) redirectByRole(u.role);
}

function redirectByRole(role) {
    if (role === 'admin')          window.location.href = 'dashboard-admin.html';
    else if (role === 'moderator') window.location.href = 'dashboard-moderator.html';
    else                           window.location.href = 'dashboard-student.html';
}

async function logout() {
    await fetch('api/auth.php?action=logout', { method: 'POST' });
    _sessionCache = null;
    window.location.href = 'index.html';
}

// ─────────────────────────────────────────────────────────────
// NAV BAR — builds the top-right user area
// ─────────────────────────────────────────────────────────────
async function buildNavUser() {
    const u  = await getSession();
    const el = document.getElementById('navActions');
    if (!el) return;

    const onDashboard = /dashboard-(admin|moderator|student)\.html/.test(window.location.pathname);

    if (u) {
        const ini  = u.username.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
        const dash = u.role === 'admin'     ? 'dashboard-admin.html'
                   : u.role === 'moderator' ? 'dashboard-moderator.html'
                   : 'dashboard-student.html';
        const dashBtn = onDashboard
            ? ''
            : `<a href="${dash}" class="btn btn-sm btn-outline">Dashboard</a>`;

        el.innerHTML = `
            <div class="nav-user-badge">
                <div class="avatar-sm">${ini}</div>
                <span>${u.username}</span>
                <span class="role-chip role-${u.role}">${u.role}</span>
            </div>
            ${dashBtn}
            <button class="btn btn-sm btn-ghost" onclick="logout()">Log out</button>`;
    } else {
        el.innerHTML = `<a href="login.html" class="btn btn-sm btn-primary">Log in</a>`;
    }
}

// ─────────────────────────────────────────────────────────────
// TOAST NOTIFICATION
// ─────────────────────────────────────────────────────────────
let _toastTimer;
function showToast(msg, type = '') {
    let t = document.getElementById('toast');
    if (!t) {
        t = document.createElement('div');
        t.id        = 'toast';
        t.className = 'toast';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.className   = 'toast' + (type ? ' ' + type : '') + ' show';
    clearTimeout(_toastTimer);
    _toastTimer   = setTimeout(() => t.classList.remove('show'), 3500);
}

// ─────────────────────────────────────────────────────────────
// CATEGORY BADGE helper — still needed for rendering
// ─────────────────────────────────────────────────────────────
function getCatBadge(cat) {
    const map = {
        Technology:  'badge-tech',
        Science:     'badge-sci',
        Arts:        'badge-arts',
        Engineering: 'badge-eng',
        Health:      'badge-health',
        Mathematics: 'badge-math',
        Education:   'badge-tech',
    };
    return map[cat] || 'badge-tech';
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[ch]));
}

function formatDate(value) {
    if (!value) return '';
    const d = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(d.getTime())
        ? String(value)
        : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function normalizePost(p) {
    const cat = p.category || p.cat || 'Education';
    return {
        ...p,
        id: Number(p.id),
        authorId: Number(p.author_id || p.authorId || 0),
        cat,
        badgeClass: getCatBadge(cat),
        excerpt: p.excerpt || p.content || '',
        coverImageUrl: p.cover_image_url || p.coverImageUrl || '',
        date: formatDate(p.created_at || p.date),
        rejectionNote: p.rejection_note || p.rejectionNote || '',
    };
}

async function loadCategoriesIntoSelect(selectId, includeBlank = true) {
    const select = document.getElementById(selectId);
    if (!select) return [];
    const data = await api('api/categories.php');
    const categories = data.categories || [];
    select.innerHTML = (includeBlank ? '<option value="">Select a category...</option>' : '') +
        categories.map(c => `<option value="${escapeHtml(c.name)}">${escapeHtml(c.name)}</option>`).join('');
    return categories;
}

// ─────────────────────────────────────────────────────────────
// THEME
// ─────────────────────────────────────────────────────────────
function setTheme(mode, btn) {
    document.body.classList.toggle('dark-mode', mode === 'dark');
    document.querySelectorAll('#themeLight, #themeDark').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    localStorage.setItem('sb_theme', mode);
}

function loadSavedTheme() {
    const saved = localStorage.getItem('sb_theme');
    if (saved === 'dark') {
        document.body.classList.add('dark-mode');
        document.getElementById('themeDark')?.classList.add('active');
        document.getElementById('themeLight')?.classList.remove('active');
    }
}

function toggleMenu() {
    document.getElementById('navLinks')?.classList.toggle('open');
}

// ─────────────────────────────────────────────────────────────
// ON EVERY PAGE LOAD
// ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    buildNavUser();
    loadSavedTheme();
});

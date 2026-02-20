// FILE: isolir/chat/app.js (Full Update dengan Smart Sidebar)

let activeVisitId = null;
let lastMsgCount = 0;
let pollingInterval = null;
let usersData = []; // Master Data (Memory)
let isFetching = false;
let noteTimeout = null;
let lastDataJson = ""; 
let editingMsgId = null; 
let lastGlobalUnread = -1; 
let isDeepLinkChecked = false;
let currentFilter = 'all'; 
let isDeletingSession = false;

// [SMART SYNC VARS]
let lastSyncTime = '';       // Untuk Chat Room
let lastContactSync = '';    // Untuk Sidebar List
let isSearching = false;     // Mode Pencarian
let chatRoomCache = {};      // visit_id -> { html, lastSyncTime, cachedAt }
const CHAT_CACHE_MAX = 40;
const CHAT_INITIAL_LIMIT = 60;
const CHAT_OLDER_BATCH = 80;
const CHAT_BACKGROUND_PREFETCH_BATCHES = 2;
let oldestLoadedId = null;
let hasMoreHistory = false;
let loadingOlderHistory = false;
let historyPrefetchTimer = null;
const CHAT_SESSION_CACHE_KEY = 'noci_chat_room_cache_v3';
const CHAT_SESSION_TTL_MS = 30 * 60 * 1000;
const CHAT_CONTACTS_SESSION_CACHE_KEY = 'noci_chat_contacts_cache_v1';
const CHAT_CONTACTS_SESSION_TTL_MS = 10 * 60 * 1000;
const CHAT_CONTACTS_CACHE_MAX = 300;
const CHAT_INDEXEDDB_NAME = 'noci_chat_cache_db';
const CHAT_INDEXEDDB_STORE = 'chat_rooms';
const CHAT_INDEXEDDB_TTL_MS = 12 * 60 * 60 * 1000;
const CHAT_CONTACTS_INDEXEDDB_TTL_MS = 12 * 60 * 60 * 1000;
const CHAT_CACHE_SCOPE = (() => {
    try {
        return `${window.location.host}:${window.location.pathname.split('?')[0]}`;
    } catch (e) {
        return 'chat-admin';
    }
})();
let chatCacheHydrated = false;
let contactsCacheHydrated = false;
let contactsSessionSnapshot = null;
let chatDbPromise = null;
let lastIndexedDbPersistAt = 0;
let lastContactsIndexedDbPersistAt = 0;
let customerOverviewPeriod = 'today';
let customerOverviewReqSeq = 0;
let customerOverviewPoll = null;
const CUSTOMER_OVERVIEW_POLL_MS = 8000;
let customerOverviewChart = null;
let customerOverviewApexPromise = null;
const CHAT_WEEKDAY_SHORT = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

function parseChatDateTime(rawValue) {
    if (!rawValue) return null;
    if (rawValue instanceof Date) return Number.isNaN(rawValue.getTime()) ? null : rawValue;

    const text = String(rawValue).trim();
    if (!text) return null;

    const normalized = text.includes('T') ? text : text.replace(' ', 'T');
    let dt = new Date(normalized);
    if (!Number.isNaN(dt.getTime())) return dt;

    dt = new Date(text.replace(/-/g, '/'));
    if (!Number.isNaN(dt.getTime())) return dt;

    return null;
}

function getDayStart(dateObj) {
    return new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate());
}

function getCalendarDiffDays(fromDate, targetDate) {
    const fromMs = getDayStart(fromDate).getTime();
    const targetMs = getDayStart(targetDate).getTime();
    return Math.round((fromMs - targetMs) / 86400000);
}

function formatHourMinute(rawValue) {
    const dt = parseChatDateTime(rawValue);
    if (!dt) return '';
    return `${String(dt.getHours()).padStart(2, '0')}:${String(dt.getMinutes()).padStart(2, '0')}`;
}

function formatContactTimestamp(rawValue, fallbackText = '') {
    const dt = parseChatDateTime(rawValue);
    if (!dt) return String(fallbackText || '');

    const now = new Date();
    const diffDays = getCalendarDiffDays(now, dt);
    if (diffDays <= 0) return formatHourMinute(dt);
    if (diffDays === 1) return 'Kemarin';
    if (diffDays < 7) return CHAT_WEEKDAY_SHORT[dt.getDay()] || formatHourMinute(dt);

    const dd = String(dt.getDate()).padStart(2, '0');
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    if (dt.getFullYear() === now.getFullYear()) return `${dd}/${mm}`;
    return `${dd}/${mm}/${String(dt.getFullYear()).slice(-2)}`;
}

function formatContactTimestampTooltip(rawValue) {
    const dt = parseChatDateTime(rawValue);
    if (!dt) return '';
    try {
        return new Intl.DateTimeFormat('id-ID', {
            weekday: 'long',
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(dt);
    } catch (e) {
        const yyyy = String(dt.getFullYear());
        const mm = String(dt.getMonth() + 1).padStart(2, '0');
        const dd = String(dt.getDate()).padStart(2, '0');
        return `${dd}/${mm}/${yyyy} ${formatHourMinute(dt)}`;
    }
}

function getMessageDayKey(rawValue) {
    const dt = parseChatDateTime(rawValue);
    if (!dt) return '';
    const yyyy = String(dt.getFullYear());
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    const dd = String(dt.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function formatMessageDayLabel(rawValue) {
    const dt = parseChatDateTime(rawValue);
    if (!dt) return 'Tanggal tidak diketahui';

    const diffDays = getCalendarDiffDays(new Date(), dt);
    if (diffDays === 0) return 'Hari Ini';
    if (diffDays === 1) return 'Kemarin';

    try {
        return new Intl.DateTimeFormat('id-ID', {
            day: '2-digit',
            month: 'long',
            year: 'numeric',
        }).format(dt);
    } catch (e) {
        const yyyy = String(dt.getFullYear());
        const mm = String(dt.getMonth() + 1).padStart(2, '0');
        const dd = String(dt.getDate()).padStart(2, '0');
        return `${dd}/${mm}/${yyyy}`;
    }
}

function setMessageWrapperMeta(wrapper, msg) {
    if (!wrapper) return;
    const rawDate = String((msg && (msg.created_at || msg.last_msg_at || '')) || '').trim();
    if (rawDate) {
        wrapper.setAttribute('data-message-date', rawDate);
    } else {
        wrapper.removeAttribute('data-message-date');
    }
}

function refreshMessageDaySeparators() {
    const container = document.getElementById('messages');
    if (!container) return;

    container.querySelectorAll('.msg-day-separator').forEach(el => el.remove());

    const wrappers = Array.from(container.querySelectorAll('[id^="msg-wrapper-"]'));
    if (!wrappers.some((wrapper) => (wrapper.getAttribute('data-message-date') || '').trim() !== '')) {
        return;
    }

    let prevDayKey = '__unset__';
    wrappers.forEach((wrapper) => {
        const rawDate = wrapper.getAttribute('data-message-date') || '';
        const dayKey = getMessageDayKey(rawDate) || '__unknown__';
        if (dayKey === prevDayKey) return;
        prevDayKey = dayKey;

        const label = formatMessageDayLabel(rawDate);
        const separator = document.createElement('div');
        separator.className = 'msg-day-separator flex justify-center my-2';
        separator.innerHTML = `<span class="px-3 py-1 rounded-full bg-slate-100 dark:bg-white/10 border border-slate-200 dark:border-white/10 text-[10px] font-semibold text-slate-500 dark:text-slate-300">${escapeHtml(label)}</span>`;
        container.insertBefore(separator, wrapper);
    });
}

// In SPA (Inertia), the chat DOM can be mounted/unmounted without reloading this script.
// Keep a mutable reference so boot() can re-bind the current <audio> element.
let audioNotif = null;

function ensureDefaultCustomerSidebarVisibility() {
    const sidebar = document.getElementById('user-detail-sidebar');
    const backdrop = document.getElementById('user-sidebar-backdrop');
    if (!sidebar) return;

    if (window.innerWidth >= 1024) {
        sidebar.classList.remove('translate-x-full');
        sidebar.classList.add('translate-x-0');
    } else {
        sidebar.classList.remove('translate-x-0');
        sidebar.classList.add('translate-x-full');
    }

    if (backdrop) backdrop.classList.add('hidden');
}

// In SPA (Inertia), DOMContentLoaded might have already fired before this script loads.
// Expose boot/dispose so the Vue page can re-init / cleanup when navigating.
function __chatBoot() {
    if (window.__chatBooting) return;
    window.__chatBooting = true;

    audioNotif = document.getElementById('notif-sound');
    document.body.addEventListener('click', () => { if(audioNotif) { audioNotif.muted = false; } }, { once: true });
    injectSmartModal(); 
    injectEditIndicator(); 
    ensureListStateForDeepLink();
    setupMobileBackHandler();
    ensureDefaultCustomerSidebarVisibility();
    bindSidebarQuickActionButtons();
    hydrateChatCacheFromSession();
    hydrateContactsCacheFromSession();
    const restoredContacts = restoreContactsListFromSession();
    if (!restoredContacts) {
        restoreContactsListFromIndexedDb().catch(() => {});
    }
    
    // Initial Load
    loadContacts(!restoredContacts); 
    
    // Polling Sidebar tiap 3 detik
    if (window.__chatContactsPoll) clearInterval(window.__chatContactsPoll);
    window.__chatContactsPoll = setInterval(() => loadContacts(false), 3000);

    const msgInput = document.getElementById('msg-input');
    if (msgInput) {
        msgInput.addEventListener('keydown', function(e) {
            const isMobile = window.innerWidth < 768;
            if ((e.key === 'Enter' || e.keyCode === 13) && !e.shiftKey) { 
                if (isMobile) { return; } 
                else { e.preventDefault(); sendMessage(); }
            }
        });
        msgInput.addEventListener('input', function(e) { 
            this.style.height = 'auto'; 
            this.style.height = (this.scrollHeight) + 'px'; 
            const val = e.target.value; 
            if (val.startsWith('/')) showTemplatePopup(val.substring(1)); 
            else document.getElementById('tpl-popup').classList.add('hidden'); 
        });
    }

    const noteInput = document.getElementById('customer-note');
    if (noteInput) {
        noteInput.addEventListener('input', function() {
            const statusEl = document.getElementById('note-status');
            if (statusEl) { statusEl.innerText = 'Saving...'; statusEl.className = 'text-[9px] text-yellow-600 font-bold opacity-100'; }
            clearTimeout(noteTimeout); noteTimeout = setTimeout(saveNote, 1000);
        });
    }
    loadTemplates(); 
    setupShortcuts();
    setupHistoryScrollListener();
    initCustomerOverviewPanel();
    loadCustomerOverview(true);
    if (customerOverviewPoll) clearInterval(customerOverviewPoll);
    customerOverviewPoll = setInterval(() => loadCustomerOverview(false), CUSTOMER_OVERVIEW_POLL_MS);

    window.__chatBooting = false;
}

window.__chatBoot = __chatBoot;
window.__chatDispose = function () {
    try {
        if (window.__chatContactsPoll) { clearInterval(window.__chatContactsPoll); window.__chatContactsPoll = null; }
        if (pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; }
        if (historyPrefetchTimer) { clearTimeout(historyPrefetchTimer); historyPrefetchTimer = null; }
        const msgContainer = document.getElementById('messages');
        if (msgContainer && window.__chatHistoryScrollHandler) {
            msgContainer.removeEventListener('scroll', window.__chatHistoryScrollHandler);
        }
        window.__chatHistoryScrollHandler = null;
        activeVisitId = null;
        lastSyncTime = '';
        oldestLoadedId = null;
        hasMoreHistory = false;
        loadingOlderHistory = false;
        lastContactSync = '';
        isFetching = false;
        if (customerOverviewPoll) { clearInterval(customerOverviewPoll); customerOverviewPoll = null; }
        if (customerOverviewChart) {
            try { customerOverviewChart.destroy(); } catch (e) {}
            customerOverviewChart = null;
        }
    } catch (e) {
        // best-effort cleanup
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', __chatBoot, { once: true });
} else {
    __chatBoot();
}

// --- CORE FUNCTIONS ---

function ensureListStateForDeepLink() {
    if (window.innerWidth >= 768) return;
    const url = new URL(window.location);
    const id = url.searchParams.get('id');
    if (!id) return;
    const baseUrl = new URL(window.location);
    baseUrl.searchParams.delete('id');
    window.history.replaceState({ view: 'list' }, '', baseUrl);
    window.history.pushState({ view: 'chat', id: String(id) }, '', url);
}

function setupMobileBackHandler() {
    if (window.__chatBackHandlerSetup) return;
    window.__chatBackHandlerSetup = true;
    window.addEventListener('popstate', () => {
        if (window.innerWidth < 768 && activeVisitId) {
            closeChatMobile();
        }
    });
}

function pushChatState(visitId) {
    const url = new URL(window.location);
    url.searchParams.set('id', visitId);
    const state = { view: 'chat', id: String(visitId) };
    if (window.innerWidth < 768) {
        if (window.history.state && window.history.state.view === 'chat') {
            window.history.replaceState(state, '', url);
        } else {
            window.history.pushState(state, '', url);
        }
    } else {
        window.history.replaceState(state, '', url);
    }
}

function closeChatMobile() {
    if (window.innerWidth >= 768) {
        closeActiveChat();
        return;
    }
    if (window.history.state && window.history.state.view === 'chat') {
        window.history.back();
        return;
    }
    const panelList = document.getElementById('panel-list');
    const panelChat = document.getElementById('panel-chat');
    if (panelList) panelList.classList.remove('hidden');
    if (panelChat) panelChat.classList.add('mobile-hidden');
    const sidebar = document.getElementById('user-detail-sidebar');
    const backdrop = document.getElementById('user-sidebar-backdrop');
    if (sidebar && !sidebar.classList.contains('translate-x-full')) {
        sidebar.classList.remove('translate-x-0');
        sidebar.classList.add('translate-x-full');
    }
    if (backdrop) backdrop.classList.add('hidden');
    const url = new URL(window.location);
    url.searchParams.delete('id');
    window.history.replaceState({ view: 'list' }, '', url);
}

function isCustomerOverviewVisible() {
    const emptyState = document.getElementById('empty-state');
    const panel = document.getElementById('customer-overview-panel');
    return !!panel && !!emptyState && !emptyState.classList.contains('hidden');
}

function initCustomerOverviewPanel() {
    const panel = document.getElementById('customer-overview-panel');
    if (!panel) return;

    const periodEl = document.getElementById('customer-overview-period');
    const refreshBtn = document.getElementById('customer-overview-refresh');

    if (periodEl) {
        if (customerOverviewPeriod) periodEl.value = customerOverviewPeriod;
        if (!periodEl.dataset.bound) {
            periodEl.addEventListener('change', () => {
                customerOverviewPeriod = periodEl.value || 'today';
                loadCustomerOverview(true);
            });
            periodEl.dataset.bound = '1';
        }
    }

    if (refreshBtn && !refreshBtn.dataset.bound) {
        refreshBtn.addEventListener('click', () => loadCustomerOverview(true));
        refreshBtn.dataset.bound = '1';
    }
}

function setCustomerOverviewOnline(isOnline) {
    const dot = document.getElementById('customer-overview-live-dot');
    const ping = document.getElementById('customer-overview-live-ping');
    const text = document.getElementById('customer-overview-live-text');
    if (!dot || !text) return;

    if (isOnline) {
        text.textContent = 'LIVE';
        dot.classList.remove('bg-red-500');
        dot.classList.add('bg-green-500');
        if (ping) ping.classList.remove('hidden');
        text.classList.remove('text-red-500');
    } else {
        text.textContent = 'OFFLINE';
        dot.classList.remove('bg-green-500');
        dot.classList.add('bg-red-500');
        if (ping) ping.classList.add('hidden');
        text.classList.add('text-red-500');
    }
}

function buildOverviewSignature(payload) {
    try {
        return JSON.stringify(payload ?? null);
    } catch (e) {
        return String(payload ?? '');
    }
}

function toSafeOverviewNumber(value) {
    const num = Number(value);
    return Number.isFinite(num) ? num : 0;
}

function animateOverviewStatValue(el, fromVal, toVal) {
    const delta = toVal - fromVal;
    if (delta === 0) return;

    const duration = Math.min(650, Math.max(260, Math.abs(delta) * 14));
    const startAt = performance.now();
    const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

    const prevRaf = Number(el.dataset.overviewRafId || 0);
    if (prevRaf) cancelAnimationFrame(prevRaf);

    const step = (now) => {
        const progress = Math.min(1, (now - startAt) / duration);
        const eased = easeOutCubic(progress);
        const current = Math.round(fromVal + (toVal - fromVal) * eased);
        el.textContent = String(current);
        if (progress < 1) {
            const rafId = requestAnimationFrame(step);
            el.dataset.overviewRafId = String(rafId);
            return;
        }
        el.textContent = String(toVal);
        el.dataset.overviewRafId = '';
    };

    const rafId = requestAnimationFrame(step);
    el.dataset.overviewRafId = String(rafId);

    const accentColor = delta > 0 ? 'rgb(22, 163, 74)' : 'rgb(220, 38, 38)';
    try {
        el.animate(
            [
                { transform: 'translateY(2px) scale(0.97)', opacity: 0.78, color: accentColor },
                { transform: 'translateY(0) scale(1.08)', opacity: 1, color: accentColor, offset: 0.55 },
                { transform: 'translateY(0) scale(1)', opacity: 1, color: '' }
            ],
            { duration: 430, easing: 'cubic-bezier(.2,.8,.2,1)' }
        );
    } catch (e) {
        el.style.transform = 'scale(1.06)';
        setTimeout(() => { el.style.transform = 'scale(1)'; }, 180);
    }
}

function updateCustomerOverviewStat(id, value) {
    const el = document.getElementById(id);
    if (!el) return;

    const nextVal = toSafeOverviewNumber(value);
    const prevVal = toSafeOverviewNumber(
        el.dataset.overviewValue ?? String(el.textContent || '').replace(/[^\d.-]/g, '')
    );
    if (prevVal === nextVal) return;

    el.dataset.overviewValue = String(nextVal);
    animateOverviewStatValue(el, prevVal, nextVal);
}

function renderCustomerOverviewLogs(logs) {
    const holder = document.getElementById('customer-overview-logs');
    if (!holder) return;

    const safeLogs = Array.isArray(logs) ? logs : [];
    const logsSignature = buildOverviewSignature(
        safeLogs.map((log) => ({
            time: String(log?.time ?? '-'),
            actor: String(log?.ip ?? '-'),
            device: String(log?.device ?? '-'),
            status: String(log?.status ?? '-'),
            badge: String(log?.badge_class ?? ''),
        }))
    );
    if (holder.dataset.logsSignature === logsSignature) return;

    const previousKeys = new Set(
        Array.from(holder.querySelectorAll('[data-log-key]')).map((el) => String(el.getAttribute('data-log-key') || ''))
    );
    holder.dataset.logsSignature = logsSignature;

    if (safeLogs.length === 0) {
        holder.innerHTML = '<div class="px-3 py-6 text-center text-xs text-slate-400">Belum ada log pelanggan.</div>';
        return;
    }

    holder.innerHTML = safeLogs.map((log) => {
        const badgeClass = (typeof log.badge_class === 'string' && log.badge_class.trim() !== '')
            ? log.badge_class
            : 'text-slate-500 bg-slate-100 dark:bg-slate-800';
        const actorRaw = String(log.ip || '-');
        const deviceRaw = String(log.device || '-');
        const timeRaw = String(log.time || '-');
        const statusRaw = String(log.status || '-');
        const logKey = encodeURIComponent(`${timeRaw}|${actorRaw}|${statusRaw}|${deviceRaw}`);
        const actor = escapeHtml(actorRaw);
        const device = escapeHtml(deviceRaw);
        const time = escapeHtml(timeRaw);
        const status = escapeHtml(statusRaw);
        return `
            <div data-log-key="${logKey}" class="px-3 py-2.5 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-xs font-bold text-slate-700 dark:text-slate-200 truncate">${actor}</div>
                    <div class="text-[10px] text-slate-400 truncate">${device}</div>
                </div>
                <div class="shrink-0 text-right">
                    <div class="text-[10px] font-mono text-slate-400 mb-1">${time}</div>
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-bold ${badgeClass}">${status}</span>
                </div>
            </div>
        `;
    }).join('');

    holder.querySelectorAll('[data-log-key]').forEach((row) => {
        const key = String(row.getAttribute('data-log-key') || '');
        if (previousKeys.has(key)) return;
        try {
            row.animate(
                [
                    { opacity: 0, transform: 'translateY(8px) scale(0.98)' },
                    { opacity: 1, transform: 'translateY(0) scale(1)' }
                ],
                { duration: 280, easing: 'cubic-bezier(.2,.8,.2,1)' }
            );
        } catch (e) {
        }
    });
}

function ensureCustomerOverviewApexLoaded() {
    if (window.ApexCharts) return Promise.resolve(true);
    if (customerOverviewApexPromise) return customerOverviewApexPromise;

    customerOverviewApexPromise = new Promise((resolve) => {
        const existing = document.getElementById('chat-overview-apexcharts');
        if (existing) {
            existing.addEventListener('load', () => resolve(!!window.ApexCharts), { once: true });
            existing.addEventListener('error', () => resolve(false), { once: true });
            return;
        }

        const s = document.createElement('script');
        s.id = 'chat-overview-apexcharts';
        s.src = 'https://cdn.jsdelivr.net/npm/apexcharts';
        s.async = true;
        s.onload = () => resolve(!!window.ApexCharts);
        s.onerror = () => resolve(false);
        document.body.appendChild(s);
    }).finally(() => {
        setTimeout(() => { customerOverviewApexPromise = null; }, 0);
    });

    return customerOverviewApexPromise;
}

function renderCustomerOverviewChart(labels, series) {
    const chartEl = document.getElementById('customer-overview-chart');
    const emptyEl = document.getElementById('customer-overview-chart-empty');
    if (!chartEl) return;

    const safeLabels = Array.isArray(labels) ? labels.map((label) => String(label ?? '')) : [];
    const safeSeries = Array.isArray(series)
        ? series.map((entry) => ({
            name: String(entry?.name ?? ''),
            data: Array.isArray(entry?.data) ? entry.data.map((v) => toSafeOverviewNumber(v)) : [],
        }))
        : [];
    const isHourlyChart = safeLabels.length === 24 && safeLabels.every((label) => /^\d{2}:00$/.test(String(label || '')));
    const isNarrowScreen = window.innerWidth < 768;
    const hourlyLabelStep = isHourlyChart ? (isNarrowScreen ? 4 : 2) : 1;
    const isDark = document.documentElement.classList.contains('dark');
    const hasData = safeSeries.some((s) => Array.isArray(s?.data) && s.data.some((v) => Number(v || 0) > 0));
    const nextSignature = hasData
        ? buildOverviewSignature({
            labels: safeLabels,
            series: safeSeries,
            theme: isDark ? 'dark' : 'light',
            tickStep: hourlyLabelStep,
        })
        : '__empty__';
    const prevSignature = chartEl.dataset.chartSignature || '';
    if (prevSignature === nextSignature) return;
    chartEl.dataset.chartSignature = nextSignature;

    if (!hasData) {
        if (customerOverviewChart) {
            try { customerOverviewChart.destroy(); } catch (e) {}
            customerOverviewChart = null;
        }
        chartEl.innerHTML = '';
        if (emptyEl) emptyEl.classList.remove('hidden');
        return;
    }

    if (emptyEl) emptyEl.classList.add('hidden');

    ensureCustomerOverviewApexLoaded().then((ok) => {
        if (!ok || !window.ApexCharts || !document.body.contains(chartEl)) return;

        const options = {
            series: safeSeries,
            chart: {
                type: 'area',
                height: 220,
                fontFamily: 'Plus Jakarta Sans, sans-serif',
                background: 'transparent',
                toolbar: { show: false },
                animations: {
                    enabled: true,
                    speed: 420,
                    animateGradually: { enabled: true, delay: 55 },
                    dynamicAnimation: { enabled: true, speed: 320 },
                }
            },
            colors: [
                '#3b82f6', '#10b981', '#f59e0b', '#ef4444',
                '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
                '#f97316', '#6366f1', '#14b8a6', '#d946ef'
            ],
            stroke: { curve: 'smooth', width: 2 },
            dataLabels: { enabled: false },
            fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
            xaxis: {
                categories: safeLabels,
                tickAmount: isHourlyChart ? Math.ceil(24 / hourlyLabelStep) : undefined,
                labels: {
                    style: { colors: isDark ? '#94a3b8' : '#64748b', fontSize: '10px' },
                    rotate: isHourlyChart ? -45 : 0,
                    hideOverlappingLabels: true,
                    minHeight: isHourlyChart ? 44 : undefined,
                    formatter: function (val) {
                        if (!isHourlyChart) return val;
                        const m = String(val || '').match(/^(\d{2}):/);
                        if (!m) return val;
                        return (Number(m[1]) % hourlyLabelStep === 0) ? val : '';
                    }
                },
                axisBorder: { show: false },
                axisTicks: { show: false },
                tooltip: { enabled: false }
            },
            yaxis: {
                labels: {
                    style: { colors: isDark ? '#94a3b8' : '#64748b', fontSize: '10px' },
                    formatter: function (val) { return Number(val || 0).toFixed(0); }
                }
            },
            grid: {
                borderColor: isDark ? '#334155' : '#e2e8f0',
                strokeDashArray: 4,
                padding: { left: 8, right: 4, top: 4, bottom: isHourlyChart ? 8 : 0 }
            },
            legend: {
                show: true,
                position: 'top',
                fontSize: '10px',
                labels: { colors: isDark ? '#cbd5e1' : '#475569' }
            },
            tooltip: { theme: isDark ? 'dark' : 'light' },
            responsive: [{
                breakpoint: 1024,
                options: {
                    legend: { position: 'bottom' }
                }
            }]
        };

        if (customerOverviewChart) {
            customerOverviewChart.updateOptions(options, false, true, true);
            return;
        }

        customerOverviewChart = new window.ApexCharts(chartEl, options);
        customerOverviewChart.render();
    });
}

function setCustomerOverviewUpdatedLabel(serverTime) {
    const el = document.getElementById('customer-overview-updated');
    if (!el) return;
    if (!serverTime) {
        el.textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        return;
    }
    const dt = new Date(serverTime);
    if (Number.isNaN(dt.getTime())) {
        el.textContent = String(serverTime);
        return;
    }
    el.textContent = dt.toLocaleString('id-ID', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function loadCustomerOverview(force = false) {
    const panel = document.getElementById('customer-overview-panel');
    if (!panel) return;
    if (!force && !isCustomerOverviewVisible()) return;

    const periodEl = document.getElementById('customer-overview-period');
    if (periodEl && periodEl.value) {
        customerOverviewPeriod = periodEl.value;
    }

    const reqSeq = ++customerOverviewReqSeq;
    const url = `admin_api.php?action=get_customer_overview&period=${encodeURIComponent(customerOverviewPeriod || 'today')}`;

    fetch(url)
        .then((r) => r.json())
        .then((res) => {
            if (reqSeq !== customerOverviewReqSeq) return;
            if (!res || res.status === 'error') {
                if (res && res.msg === 'Unauthorized') {
                    window.location.href = '../login.php';
                    return;
                }
                setCustomerOverviewOnline(false);
                return;
            }

            setCustomerOverviewOnline(true);
            updateCustomerOverviewStat('customer-overview-online', res.online_users ?? 0);
            updateCustomerOverviewStat('customer-overview-unique', res.unique_visits ?? 0);
            updateCustomerOverviewStat('customer-overview-leads', res.total_leads ?? 0);
            updateCustomerOverviewStat('customer-overview-hits', res.total_hits ?? 0);
            renderCustomerOverviewLogs(res.logs_pelanggan || []);
            renderCustomerOverviewChart(res.chart_labels || [], res.chart_series_pelanggan || []);
            setCustomerOverviewUpdatedLabel(res.server_time || '');
        })
        .catch(() => {
            if (reqSeq !== customerOverviewReqSeq) return;
            setCustomerOverviewOnline(false);
        });
}

function compactChatCacheMap(inputMap, ttlMs) {
    const now = Date.now();
    const src = (inputMap && typeof inputMap === 'object') ? inputMap : {};
    const validKeys = Object.keys(src).filter((k) => {
        const item = src[k];
        if (!item || typeof item.html !== 'string') return false;
        const ts = Number(item.cachedAt || 0);
        if (!ts) return false;
        return (now - ts) <= ttlMs;
    });

    validKeys.sort((a, b) => Number(src[b]?.cachedAt || 0) - Number(src[a]?.cachedAt || 0));
    const trimmed = {};
    validKeys.slice(0, CHAT_CACHE_MAX).forEach((k) => {
        trimmed[k] = src[k];
    });
    return trimmed;
}

function hydrateChatCacheFromSession() {
    if (chatCacheHydrated) return;
    chatCacheHydrated = true;
    try {
        const raw = sessionStorage.getItem(CHAT_SESSION_CACHE_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        chatRoomCache = compactChatCacheMap(parsed, CHAT_SESSION_TTL_MS);
        sessionStorage.setItem(CHAT_SESSION_CACHE_KEY, JSON.stringify(chatRoomCache));
    } catch (e) {
        chatRoomCache = {};
    }
}

function persistChatCacheToSession() {
    try {
        chatRoomCache = compactChatCacheMap(chatRoomCache, CHAT_SESSION_TTL_MS);
        sessionStorage.setItem(CHAT_SESSION_CACHE_KEY, JSON.stringify(chatRoomCache));
    } catch (e) {
        // Ignore quota/storage issues.
    }
}

function hasIndexedDb() {
    try {
        return typeof indexedDB !== 'undefined';
    } catch (e) {
        return false;
    }
}

function openChatCacheDb() {
    if (!hasIndexedDb()) return Promise.resolve(null);
    if (chatDbPromise) return chatDbPromise;

    chatDbPromise = new Promise((resolve) => {
        try {
            const req = indexedDB.open(CHAT_INDEXEDDB_NAME, 1);
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains(CHAT_INDEXEDDB_STORE)) {
                    const store = db.createObjectStore(CHAT_INDEXEDDB_STORE, { keyPath: 'cache_key' });
                    store.createIndex('cachedAt', 'cachedAt', { unique: false });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => resolve(null);
            req.onblocked = () => resolve(null);
        } catch (e) {
            resolve(null);
        }
    });
    return chatDbPromise;
}

function indexedDbRoomKey(visitId) {
    return `${CHAT_CACHE_SCOPE}:${String(visitId || '')}`;
}

function persistChatRoomToIndexedDb(visitId, payload) {
    if (!visitId || !payload || typeof payload.html !== 'string') return;
    openChatCacheDb().then((db) => {
        if (!db) return;
        try {
            const tx = db.transaction(CHAT_INDEXEDDB_STORE, 'readwrite');
            const store = tx.objectStore(CHAT_INDEXEDDB_STORE);
            const row = {
                cache_key: indexedDbRoomKey(visitId),
                scope: CHAT_CACHE_SCOPE,
                visit_id: String(visitId),
                html: payload.html,
                lastSyncTime: payload.lastSyncTime || '',
                oldestLoadedId: payload.oldestLoadedId ?? null,
                hasMoreHistory: !!payload.hasMoreHistory,
                cachedAt: Number(payload.cachedAt || Date.now()),
            };
            store.put(row);
            const cutoff = Date.now() - CHAT_INDEXEDDB_TTL_MS;
            const idx = store.index('cachedAt');
            idx.openCursor(IDBKeyRange.upperBound(cutoff)).onsuccess = (e) => {
                const cursor = e.target.result;
                if (cursor) {
                    cursor.delete();
                    cursor.continue();
                }
            };
        } catch (e) {
        }
    }).catch(() => {});
}

function readChatRoomFromIndexedDb(visitId) {
    if (!visitId) return Promise.resolve(null);
    return openChatCacheDb().then((db) => {
        if (!db) return null;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(CHAT_INDEXEDDB_STORE, 'readwrite');
                const store = tx.objectStore(CHAT_INDEXEDDB_STORE);
                const req = store.get(indexedDbRoomKey(visitId));
                req.onsuccess = () => {
                    const row = req.result;
                    if (!row || typeof row.html !== 'string') {
                        resolve(null);
                        return;
                    }
                    const ts = Number(row.cachedAt || 0);
                    if (!ts || (Date.now() - ts) > CHAT_INDEXEDDB_TTL_MS) {
                        try { store.delete(indexedDbRoomKey(visitId)); } catch (e) {}
                        resolve(null);
                        return;
                    }
                    resolve(row);
                };
                req.onerror = () => resolve(null);
            } catch (e) {
                resolve(null);
            }
        });
    }).catch(() => null);
}

function deleteChatRoomFromIndexedDb(visitId) {
    if (!visitId) return;
    openChatCacheDb().then((db) => {
        if (!db) return;
        try {
            const tx = db.transaction(CHAT_INDEXEDDB_STORE, 'readwrite');
            tx.objectStore(CHAT_INDEXEDDB_STORE).delete(indexedDbRoomKey(visitId));
        } catch (e) {
        }
    }).catch(() => {});
}

function maybeHandleDeepLinkOpen() {
    if (isDeepLinkChecked) return;
    isDeepLinkChecked = true;
    const urlParams = new URLSearchParams(window.location.search);
    const linkId = urlParams.get('id');
    if (linkId) openChat(linkId);
}

function compactContactsCacheData(inputData) {
    if (!Array.isArray(inputData)) return [];
    const compacted = [];
    const seen = new Set();
    for (let i = 0; i < inputData.length; i++) {
        const item = inputData[i];
        if (!item || typeof item !== 'object') continue;
        const key = String(item.visit_id || '');
        if (!key || seen.has(key)) continue;
        seen.add(key);
        compacted.push(item);
        if (compacted.length >= CHAT_CONTACTS_CACHE_MAX) break;
    }
    return compacted;
}

function hydrateContactsCacheFromSession() {
    if (contactsCacheHydrated) return;
    contactsCacheHydrated = true;
    contactsSessionSnapshot = null;
    try {
        const raw = sessionStorage.getItem(CHAT_CONTACTS_SESSION_CACHE_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        const ts = Number(parsed?.cachedAt || 0);
        if (!ts || (Date.now() - ts) > CHAT_CONTACTS_SESSION_TTL_MS) {
            sessionStorage.removeItem(CHAT_CONTACTS_SESSION_CACHE_KEY);
            return;
        }
        if ((parsed?.scope || '') !== CHAT_CACHE_SCOPE) {
            sessionStorage.removeItem(CHAT_CONTACTS_SESSION_CACHE_KEY);
            return;
        }
        const data = compactContactsCacheData(parsed?.data);
        if (!data.length) return;
        contactsSessionSnapshot = {
            scope: CHAT_CACHE_SCOPE,
            data,
            lastContactSync: String(parsed?.lastContactSync || ''),
            cachedAt: ts,
        };
    } catch (e) {
        contactsSessionSnapshot = null;
    }
}

function contactsCachePayloadFromState() {
    return {
        scope: CHAT_CACHE_SCOPE,
        data: compactContactsCacheData(usersData),
        lastContactSync: String(lastContactSync || ''),
        cachedAt: Date.now(),
    };
}

function persistContactsCacheToSession(payload = null) {
    try {
        const snapshot = payload || contactsCachePayloadFromState();
        if (!Array.isArray(snapshot.data) || snapshot.data.length === 0) return;
        sessionStorage.setItem(CHAT_CONTACTS_SESSION_CACHE_KEY, JSON.stringify(snapshot));
    } catch (e) {
        // Ignore quota/storage issues.
    }
}

function applyContactsCachePayload(payload) {
    if (!payload || !Array.isArray(payload.data)) return false;
    const ts = Number(payload.cachedAt || 0);
    if (!ts) return false;

    const data = compactContactsCacheData(payload.data);
    if (!data.length) return false;

    usersData = data;
    const syncCursor = String(payload.lastContactSync || '');
    if (syncCursor) {
        lastContactSync = syncCursor;
    }
    renderUserList(usersData, true);
    renderFilterButtons(usersData);
    maybeHandleDeepLinkOpen();
    return true;
}

function restoreContactsListFromSession() {
    hydrateContactsCacheFromSession();
    if (!contactsSessionSnapshot) return false;
    return applyContactsCachePayload(contactsSessionSnapshot);
}

function indexedDbContactsKey() {
    return `${CHAT_CACHE_SCOPE}:__contacts__`;
}

function persistContactsListToIndexedDb(payload = null) {
    const snapshot = payload || contactsCachePayloadFromState();
    if (!Array.isArray(snapshot.data) || snapshot.data.length === 0) return;
    openChatCacheDb().then((db) => {
        if (!db) return;
        try {
            const tx = db.transaction(CHAT_INDEXEDDB_STORE, 'readwrite');
            const store = tx.objectStore(CHAT_INDEXEDDB_STORE);
            store.put({
                cache_key: indexedDbContactsKey(),
                scope: CHAT_CACHE_SCOPE,
                kind: 'contacts',
                data: snapshot.data,
                lastContactSync: snapshot.lastContactSync || '',
                cachedAt: Number(snapshot.cachedAt || Date.now()),
            });
        } catch (e) {
        }
    }).catch(() => {});
}

function clearContactsCache() {
    try {
        sessionStorage.removeItem(CHAT_CONTACTS_SESSION_CACHE_KEY);
    } catch (e) {
    }

    openChatCacheDb().then((db) => {
        if (!db) return;
        try {
            const tx = db.transaction(CHAT_INDEXEDDB_STORE, 'readwrite');
            tx.objectStore(CHAT_INDEXEDDB_STORE).delete(indexedDbContactsKey());
        } catch (e) {
        }
    }).catch(() => {});
}

function readContactsListFromIndexedDb() {
    return openChatCacheDb().then((db) => {
        if (!db) return null;
        return new Promise((resolve) => {
            try {
                const tx = db.transaction(CHAT_INDEXEDDB_STORE, 'readwrite');
                const store = tx.objectStore(CHAT_INDEXEDDB_STORE);
                const req = store.get(indexedDbContactsKey());
                req.onsuccess = () => {
                    const row = req.result;
                    const ts = Number(row?.cachedAt || 0);
                    if (!row || !Array.isArray(row.data) || !ts) {
                        resolve(null);
                        return;
                    }
                    if ((Date.now() - ts) > CHAT_CONTACTS_INDEXEDDB_TTL_MS) {
                        try { store.delete(indexedDbContactsKey()); } catch (e) {}
                        resolve(null);
                        return;
                    }
                    resolve(row);
                };
                req.onerror = () => resolve(null);
            } catch (e) {
                resolve(null);
            }
        });
    }).catch(() => null);
}

function restoreContactsListFromIndexedDb() {
    if (usersData.length > 0) return Promise.resolve(false);
    return readContactsListFromIndexedDb().then((cached) => {
        if (!cached) return false;
        const restored = applyContactsCachePayload({
            data: cached.data,
            lastContactSync: cached.lastContactSync || '',
            cachedAt: Number(cached.cachedAt || Date.now()),
        });
        if (restored) {
            persistContactsCacheToSession(contactsCachePayloadFromState());
        }
        return restored;
    }).catch(() => false);
}

function persistContactsCache(force = false) {
    if (isSearching) return;
    if (!Array.isArray(usersData) || usersData.length === 0) {
        clearContactsCache();
        return;
    }
    const snapshot = contactsCachePayloadFromState();
    persistContactsCacheToSession(snapshot);
    const nowTs = Date.now();
    if (force || (nowTs - lastContactsIndexedDbPersistAt) > 2500) {
        lastContactsIndexedDbPersistAt = nowTs;
        persistContactsListToIndexedDb(snapshot);
    }
}

function pruneChatCache() {
    chatRoomCache = compactChatCacheMap(chatRoomCache, CHAT_SESSION_TTL_MS);
}

function cacheActiveChatState(force = false) {
    if (!activeVisitId) return;
    const container = document.getElementById('messages');
    if (!container) return;
    if (!force && container.querySelector('#chat-loader')) return;

    const key = String(activeVisitId);
    const nowTs = Date.now();
    const payload = {
        html: container.innerHTML || '',
        lastSyncTime: lastSyncTime || '',
        oldestLoadedId: oldestLoadedId,
        hasMoreHistory: !!hasMoreHistory,
        cachedAt: nowTs,
    };
    const prev = chatRoomCache[key];
    const unchanged = !!prev
        && prev.html === payload.html
        && (prev.lastSyncTime || '') === payload.lastSyncTime
        && (prev.oldestLoadedId ?? null) === (payload.oldestLoadedId ?? null)
        && !!prev.hasMoreHistory === !!payload.hasMoreHistory;
    if (!force && unchanged) return;

    chatRoomCache[key] = payload;
    pruneChatCache();
    persistChatCacheToSession();

    if (force || (nowTs - lastIndexedDbPersistAt) > 2500) {
        lastIndexedDbPersistAt = nowTs;
        persistChatRoomToIndexedDb(key, payload);
    }
}

function applyChatRoomSnapshot(cached) {
    if (!cached || typeof cached.html !== 'string') return false;
    const container = document.getElementById('messages');
    if (!container) return false;

    container.innerHTML = cached.html;
    refreshMessageDaySeparators();
    lastSyncTime = cached.lastSyncTime || '';
    oldestLoadedId = cached.oldestLoadedId ?? null;
    hasMoreHistory = !!cached.hasMoreHistory;
    return true;
}

function restoreChatState(visitId) {
    hydrateChatCacheFromSession();
    const key = String(visitId || '');
    const cached = key ? chatRoomCache[key] : null;
    return applyChatRoomSnapshot(cached);
}

function restoreChatStateFromIndexedDb(visitId) {
    const key = String(visitId || '');
    if (!key) return Promise.resolve(false);

    return readChatRoomFromIndexedDb(key).then((cached) => {
        if (!cached) return false;
        const restored = applyChatRoomSnapshot(cached);
        if (!restored) return false;

        chatRoomCache[key] = {
            html: cached.html,
            lastSyncTime: cached.lastSyncTime || '',
            oldestLoadedId: cached.oldestLoadedId ?? null,
            hasMoreHistory: !!cached.hasMoreHistory,
            cachedAt: Number(cached.cachedAt || Date.now()),
        };
        pruneChatCache();
        persistChatCacheToSession();
        return true;
    }).catch(() => false);
}

function setupHistoryScrollListener() {
    const msgContainer = document.getElementById('messages');
    if (!msgContainer) return;
    if (window.__chatHistoryScrollHandler) {
        msgContainer.removeEventListener('scroll', window.__chatHistoryScrollHandler);
    }
    window.__chatHistoryScrollHandler = () => {
        if (!activeVisitId || !hasMoreHistory || loadingOlderHistory) return;
        if (msgContainer.scrollTop <= 80) {
            loadOlderMessages();
        }
    };
    msgContainer.addEventListener('scroll', window.__chatHistoryScrollHandler, { passive: true });
}

function queueHistoryPrefetch(visitId, remaining = CHAT_BACKGROUND_PREFETCH_BATCHES) {
    if (historyPrefetchTimer) {
        clearTimeout(historyPrefetchTimer);
        historyPrefetchTimer = null;
    }
    if (remaining <= 0) return;

    historyPrefetchTimer = setTimeout(() => {
        historyPrefetchTimer = null;
        if (String(activeVisitId || '') !== String(visitId || '')) return;
        if (!hasMoreHistory || loadingOlderHistory) return;
        loadOlderMessages()
            .then((loaded) => {
                if (loaded) queueHistoryPrefetch(visitId, remaining - 1);
            })
            .catch(() => {});
    }, 260);
}

function prependOlderMessages(messages) {
    const container = document.getElementById('messages');
    if (!container || !Array.isArray(messages) || messages.length === 0) return;

    const prevHeight = container.scrollHeight;
    const prevTop = container.scrollTop;
    const loader = document.getElementById('chat-loader');
    const emptyState = document.getElementById('chat-empty');
    if (loader || emptyState) container.innerHTML = '';

    for (let i = messages.length - 1; i >= 0; i--) {
        const msg = messages[i];
        const existingEl = document.getElementById(`msg-wrapper-${msg.id}`);
        if (msg.status === 'deleted') {
            if (existingEl) existingEl.remove();
            continue;
        }

        const bubbleHtml = createBubbleHtml(msg);
        if (existingEl) {
            existingEl.innerHTML = bubbleHtml;
            setMessageWrapperMeta(existingEl, msg);
            continue;
        }

        const wrapper = document.createElement('div');
        wrapper.id = `msg-wrapper-${msg.id}`;
        wrapper.className = `flex ${msg.sender === 'admin' ? 'justify-end' : 'justify-start'} mb-2 shrink-0 group animate-in fade-in zoom-in duration-200`;
        wrapper.innerHTML = bubbleHtml;
        setMessageWrapperMeta(wrapper, msg);
        container.insertBefore(wrapper, container.firstChild);
    }

    refreshMessageDaySeparators();
    const newHeight = container.scrollHeight;
    container.scrollTop = newHeight - prevHeight + prevTop;
}

function loadOlderMessages() {
    if (!activeVisitId || !hasMoreHistory || loadingOlderHistory || !oldestLoadedId) {
        return Promise.resolve(false);
    }

    loadingOlderHistory = true;
    const visitId = String(activeVisitId);
    const beforeId = parseInt(oldestLoadedId, 10);
    if (!beforeId || beforeId <= 0) {
        loadingOlderHistory = false;
        hasMoreHistory = false;
        return Promise.resolve(false);
    }

    const url = `admin_api.php?action=get_messages&visit_id=${encodeURIComponent(visitId)}&viewer=admin&before_id=${beforeId}&limit=${CHAT_OLDER_BATCH}`;
    return fetch(url)
        .then(r => r.json())
        .then(data => {
            loadingOlderHistory = false;
            if (String(activeVisitId || '') !== visitId) return false;
            if (data.status === 'error' && data.msg === 'Unauthorized') {
                window.location.href = '../login.php';
                return false;
            }

            const messages = Array.isArray(data.messages) ? data.messages : [];
            if (messages.length === 0) {
                hasMoreHistory = false;
                cacheActiveChatState(true);
                return false;
            }

            prependOlderMessages(messages);
            oldestLoadedId = data.oldest_id || messages[0]?.id || oldestLoadedId;
            hasMoreHistory = !!data.has_more;
            cacheActiveChatState(true);
            return true;
        })
        .catch(() => {
            loadingOlderHistory = false;
            return false;
        });
}

// [SMART SIDEBAR] Load Contacts (Delta Updates)
function loadContacts(isFirstLoad = false) { 
    // Jangan polling jika sedang mode search (user sedang mengetik/mencari)
    if (isSearching && !isFirstLoad) return;

    let url = 'admin_api.php?action=get_contacts';
    
    // Jika bukan load pertama dan bukan search, kirim last_sync
    if (!isFirstLoad && lastContactSync && !isSearching) {
        url += `&last_sync=${lastContactSync}`;
    }

    fetch(url)
    .then(r => r.json())
    .then(res => { 
        if (res.status === 'error') return; 
        
        const newContacts = res.data || [];
        const serverTime = res.server_time;
        
        if (serverTime) lastContactSync = serverTime;

        // Jika ada data baru atau ini load pertama
        if (newContacts.length > 0 || isFirstLoad) {
            mergeUserData(newContacts, isFirstLoad); // Gabungkan data baru ke memory

            // Update info user yang sedang aktif (Realtime update status/nama)
            if (activeVisitId) {
                const currentUser = usersData.find(u => String(u.visit_id) === String(activeVisitId));
                if (currentUser) {
                    setText('.info-name', currentUser.name);
                    setText('.info-phone', currentUser.phone || '-');
                    setText('.info-address', currentUser.address || '-');
                    const hStatus = document.getElementById('h-status'); 
                    if(hStatus) hStatus.innerHTML = getStatus(currentUser.last_seen);
                    
                    if (currentUser.status === 'Selesai') {
                         document.getElementById('footer-active').classList.add('hidden');
                         document.getElementById('footer-locked').classList.remove('hidden');
                         document.getElementById('footer-locked').classList.add('flex');
                    } else {
                         document.getElementById('footer-active').classList.remove('hidden');
                         document.getElementById('footer-locked').classList.add('hidden');
                    }
                }
            }
        } else {
            // Update filter counts walaupun tidak ada list baru
            renderFilterButtons(usersData);
        }
        maybeHandleDeepLinkOpen();
        persistContactsCache();
    }).catch(e => { console.error("Polling Error:", e); }); 
}

// [SMART SIDEBAR] Merge Logic
function mergeUserData(newItems, reset = false) {
    if (reset) {
        usersData = newItems;
    } else {
        newItems.forEach(newItem => {
            const index = usersData.findIndex(u => String(u.visit_id) === String(newItem.visit_id));
            if (index > -1) {
                // Update data lama
                usersData[index] = newItem;
            } else {
                // Data baru, masukkan (nanti disortir)
                usersData.push(newItem);
            }
        });
    }

    // Sortir: Terakhir dilihat (last_seen) paling atas
    usersData.sort((a, b) => {
        const timeA = new Date(a.last_seen || 0);
        const timeB = new Date(b.last_seen || 0);
        return timeB - timeA;
    });

    renderUserList(usersData);
    renderFilterButtons(usersData);
}

// [SMART SIDEBAR] Search (Server Side)
function filterUsers() { 
    const q = document.getElementById('search-user').value.trim();
    const listContainer = document.getElementById('user-list');
    
    if (q.length === 0) {
        isSearching = false;
        lastContactSync = ''; // Reset sync agar load ulang default list
        loadContacts(true); // Load ulang list normal
        return;
    }

    isSearching = true;
    listContainer.innerHTML = `<div class="flex h-20 items-center justify-center text-xs text-slate-400 animate-pulse">Mencari '${escapeHtml(q)}'...</div>`;

    // Request Search ke Server
    fetch(`admin_api.php?action=get_contacts&search=${encodeURIComponent(q)}`)
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            // Render hasil pencarian langsung (jangan merge ke usersData utama agar tidak kotor)
            renderUserList(res.data, true); 
        }
    });
}


function renderUserList(data, force = false) {
    const listContainer = document.getElementById('user-list');
    if (!listContainer) return;

    // Filter Client-Side (kecuali mode search)
    let filteredData = data;
    if (!isSearching) {
        filteredData = data.filter(user => {
            if (currentFilter === 'all') return true;
            if (currentFilter === 'unread') return parseInt(user.unread) > 0;
            return user.status === currentFilter;
        });
    }

    if (filteredData.length === 0) {
        let emptyMsg = 'Data tidak ditemukan.';
        if (currentFilter === 'unread') emptyMsg = 'Tidak ada pesan belum dibaca.';
        listContainer.innerHTML = `<div class="flex flex-col items-center justify-center h-40 text-slate-400 text-xs text-center"><p>${escapeHtml(emptyMsg)}</p></div>`;
        updateTitleNotification(0);
        return;
    }

    const currentScroll = listContainer.scrollTop;
    let html = '';

    filteredData.forEach(user => {
        const currentUnread = parseInt(user.unread) || 0;
        const visitIdStr = String(user.visit_id || '');
        const isActive = (String(activeVisitId) === visitIdStr);

        const bgClass = isActive ? 'user-item-active bg-blue-50 border-l-4 border-blue-600' : 'hover:bg-slate-50 border-l-4 border-transparent';
        const avatarColor = isActive ? 'from-blue-600 to-blue-700 text-white' : 'from-blue-100 to-blue-200 text-blue-600';

        let badge = '';
        if (currentUnread > 0) {
            badge = `<span class="ml-auto bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center shadow-md shadow-red-200 animate-pulse">${currentUnread}</span>`;
        }

        // Safe strings (anti-XSS)
        const uNameRaw = (user.name || 'Tanpa Nama');
        const uName = escapeHtml(uNameRaw);
        const shortIdRaw = visitIdStr.length > 4 ? visitIdStr.substring(visitIdStr.length - 4) : visitIdStr;
        const shortId = escapeHtml(shortIdRaw);

        let prevMsgRaw = user.last_msg || '...';
        if (user.msg_type === 'image') prevMsgRaw = '📷 Gambar';
        const prevMsg = escapeHtml(String(prevMsgRaw)).substring(0, 30);

        const listDateRaw = String(user.last_msg_at || user.last_seen || '');
        const displayTime = escapeHtml(formatContactTimestamp(listDateRaw, String(user.display_time || '')));
        const displayTimeTitle = formatContactTimestampTooltip(listDateRaw);
        const displayTimeAttr = displayTimeTitle ? ` title="${escapeHtml(displayTimeTitle)}"` : '';
        const statusIcon = (user.status === 'Selesai')
            ? '<span class="text-[9px] text-green-600 font-bold ml-1">✔</span>'
            : '';

        html += `
            <div class="user-item flex items-center gap-3 p-3 cursor-pointer transition border-b border-slate-50 dark:border-white/5 ${bgClass} relative select-none"
                 data-visit-id="${escapeHtml(visitIdStr)}"
                 id="user-item-${escapeHtml(visitIdStr)}">
                <div class="relative">
                    <div class="user-avatar w-10 h-10 rounded-full bg-gradient-to-br ${avatarColor} flex items-center justify-center font-bold shrink-0 text-sm shadow-sm transition-all">
                        ${uName.charAt(0).toUpperCase()}
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between items-center mb-0.5">
                        <h4 class="text-slate-800 dark:text-white font-bold text-sm truncate flex items-center gap-1">
                            ${uName}
                            <span class="text-[10px] text-slate-400 font-normal">#${shortId}</span>
                            ${statusIcon}
                        </h4>
                        <span class="text-[10px] text-slate-400 font-mono"${displayTimeAttr}>${displayTime}</span>
                    </div>
                    <div class="flex items-center text-xs text-slate-500 dark:text-slate-400">
                        <p class="truncate flex-1 opacity-80">${prevMsg}</p>
                        ${badge}
                    </div>
                </div>
            </div>`;
    });

    // Play Sound jika ada unread global bertambah
    const globalUnread = data.reduce((sum, u) => sum + (parseInt(u.unread) || 0), 0);
    if (lastGlobalUnread !== -1 && globalUnread > lastGlobalUnread) { playSound(); }
    lastGlobalUnread = globalUnread;
    updateTitleNotification(globalUnread);

    listContainer.innerHTML = html;

    // Click handler (hindari inline onclick)
    listContainer.querySelectorAll('.user-item').forEach(el => {
        el.addEventListener('click', () => {
            const vid = el.getAttribute('data-visit-id');
            if (vid) openChat(vid);
        });
    });

    // Pertahankan posisi scroll kecuali dipaksa (misal search)
    if (!force) listContainer.scrollTop = currentScroll;
}

function renderFilterButtons(data) {
    const container = document.getElementById('filter-container');
    if (!container) return;

    let unreadCount = 0;
    const statusCounts = {};

    data.forEach(u => {
        if (parseInt(u.unread) > 0) unreadCount++;
        const st = u.status || 'Baru'; 
        if (!statusCounts[st]) statusCounts[st] = 0;
        statusCounts[st]++;
    });

    let filters = [
        { key: 'all', label: 'Semua', count: data.length },
        { key: 'unread', label: 'Belum Dibaca', count: unreadCount }
    ];

    Object.keys(statusCounts).forEach(st => {
        filters.push({ key: st, label: st, count: statusCounts[st] });
    });

    let html = '';
    filters.forEach(f => {
        if (f.key !== 'all' && f.count === 0 && f.key !== 'unread') return;
        const isActive = (currentFilter === f.key);
        const activeClass = isActive 
            ? 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-green-900/30 dark:text-green-400 dark:border-green-800' 
            : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50 dark:bg-white/5 dark:text-slate-400 dark:border-white/10 dark:hover:bg-white/10';
        const countBadge = f.count > 0 
            ? `<span class="ml-1.5 text-[9px] px-1.5 py-0.5 rounded-full ${isActive ? 'bg-blue-200 dark:bg-green-900 text-blue-800 dark:text-green-300' : 'bg-slate-100 dark:bg-black/30 text-slate-500 dark:text-slate-400'}">${f.count}</span>` 
            : '';
        html += `<button onclick="setFilter('${f.key}')" class="flex-shrink-0 px-3 py-1.5 text-[11px] font-bold rounded-full border transition-all flex items-center ${activeClass}">${f.label} ${countBadge}</button>`;
    });
    container.innerHTML = html;
}

function setFilter(type) {
    currentFilter = type;
    renderUserList(usersData, true);
    renderFilterButtons(usersData); 
}

// ... (Fungsi setupShortcuts, handleEscapeKey, toggleUserSidebar, closeActiveChat, openChat, dll tetap SAMA seperti sebelumnya. Pastikan fungsi sendMessage dan sendImageAdmin menggunakan versi FIX dari langkah sebelumnya).
// ... Copy-Paste fungsi-fungsi di bawah ini dari file app.js versi sebelumnya (yang sudah di-fix) ...

function setupShortcuts() {
    if (window.__chatShortcutsSetup) return;
    window.__chatShortcutsSetup = true;
    document.addEventListener('keydown', (e) => {
        const smartModal = document.getElementById('smart-modal');
        if (smartModal && !smartModal.classList.contains('hidden')) {
            const btnCancel = document.getElementById('smart-btn-cancel'); const btnConfirm = document.getElementById('smart-btn-confirm'); const btnOk = document.getElementById('smart-btn-ok'); const btnSave = document.getElementById('smart-btn-save');
            if (e.key === 'ArrowLeft') { e.preventDefault(); if (btnCancel) btnCancel.focus(); }
            if (e.key === 'ArrowRight') { e.preventDefault(); if (btnConfirm) btnConfirm.focus(); if (btnSave) btnSave.focus(); }
            if (e.key === 'Enter') { 
                e.preventDefault(); 
                if (document.activeElement === btnCancel) btnCancel.click(); 
                else if (document.activeElement === btnConfirm) btnConfirm.click(); 
                else if (document.activeElement === btnOk) btnOk.click(); 
                else if (document.activeElement === btnSave) btnSave.click();
            }
            if (e.key === 'Escape') { e.preventDefault(); handleEscapeKey(); } return; 
        }
        if (e.key === 'Escape') { e.preventDefault(); handleEscapeKey(); }
    });
}

function handleEscapeKey() {
    const imgViewer = document.getElementById('img-viewer'); if (imgViewer && !imgViewer.classList.contains('hidden')) { closeImageViewer(); return; }
    const tplPopup = document.getElementById('tpl-popup'); if (tplPopup && !tplPopup.classList.contains('hidden')) { tplPopup.classList.add('hidden'); return; }
    if (editingMsgId) { cancelEditMode(); return; }
    const smartModal = document.getElementById('smart-modal'); if (smartModal && !smartModal.classList.contains('hidden')) { closeSmartModal(); return; }
    const sidebar = document.getElementById('user-detail-sidebar');
    if (sidebar && !sidebar.classList.contains('translate-x-full') && window.innerWidth < 1024) { toggleUserSidebar(); return; }
    const activeModals = ['modal-edit-user', 'modal-detail', 'modal-tpl-manager', 'modal-form-tpl', 'modal-admin-profile', 'chat-menu', 'sidebar-menu'];
    let modalClosed = false;
    activeModals.forEach(id => { const el = document.getElementById(id); if (el && !el.classList.contains('hidden')) { el.classList.add('hidden'); modalClosed = true; } });
    if (modalClosed) return;
    if (activeVisitId) closeActiveChat();
}

function toggleUserSidebar() {
    const sidebar = document.getElementById('user-detail-sidebar');
    const backdrop = document.getElementById('user-sidebar-backdrop');
    if (sidebar.classList.contains('translate-x-full')) {
        sidebar.classList.remove('translate-x-full'); sidebar.classList.add('translate-x-0');
        if(backdrop) backdrop.classList.remove('hidden');
    } else {
        sidebar.classList.remove('translate-x-0'); sidebar.classList.add('translate-x-full');
        if(backdrop) backdrop.classList.add('hidden');
    }
}

function closeActiveChat() {
    cacheActiveChatState();
    if (pollingInterval) clearInterval(pollingInterval);
    if (historyPrefetchTimer) { clearTimeout(historyPrefetchTimer); historyPrefetchTimer = null; }
    loadingOlderHistory = false;
    oldestLoadedId = null;
    hasMoreHistory = false;
    activeVisitId = null; lastSyncTime = ''; 
    cancelEditMode(); 
    document.getElementById('chat-interface').classList.add('hidden'); 
    document.getElementById('chat-interface').classList.remove('flex');
    document.getElementById('empty-state').classList.remove('hidden'); 
    document.getElementById('panel-list').classList.remove('hidden');
    document.getElementById('panel-chat').classList.add('mobile-hidden'); 
    initCustomerOverviewPanel();
    loadCustomerOverview(true);
    document.title = "Chat Admin | Isolir";
    const url = new URL(window.location); url.searchParams.delete('id'); window.history.replaceState({}, '', url);
    document.querySelectorAll('.user-item-active').forEach(el => {
        el.classList.remove('user-item-active', 'bg-blue-50', 'border-blue-600');
        el.classList.add('hover:bg-slate-50', 'border-transparent');
        const avatar = el.querySelector('.user-avatar');
        if(avatar) { avatar.classList.remove('from-blue-600', 'to-blue-700', 'text-white'); avatar.classList.add('from-blue-100', 'to-blue-200', 'text-blue-600'); }
    });
}

function openChat(visitId) {
    if (typeof closeGame === 'function') closeGame();
    document.getElementById('empty-state').classList.add('hidden');
    const chatInterface = document.getElementById('chat-interface'); chatInterface.classList.remove('hidden'); chatInterface.classList.add('flex');
    if (window.innerWidth < 768) { document.getElementById('panel-list').classList.add('hidden'); document.getElementById('panel-chat').classList.remove('mobile-hidden'); }
    pushChatState(visitId);
    setTimeout(() => { const input = document.getElementById('msg-input'); if (input) input.focus(); }, 50);
    
    // Reset Polling & Sync
    if (pollingInterval) clearInterval(pollingInterval); 
    isFetching = false; 
    cancelEditMode();
    if (historyPrefetchTimer) { clearTimeout(historyPrefetchTimer); historyPrefetchTimer = null; }
    loadingOlderHistory = false;
    if (activeVisitId) {
        cacheActiveChatState();
    }
    lastSyncTime = ''; 
    oldestLoadedId = null;
    hasMoreHistory = false;
    
    if (activeVisitId) {
        const prevItem = document.getElementById(`user-item-${activeVisitId}`);
        if (prevItem) {
            prevItem.classList.remove('user-item-active', 'bg-blue-50', 'border-blue-600');
            prevItem.classList.add('hover:bg-slate-50', 'border-transparent');
            const av = prevItem.querySelector('.user-avatar');
            if(av) { av.classList.remove('from-blue-600', 'to-blue-700', 'text-white'); av.classList.add('from-blue-100', 'to-blue-200', 'text-blue-600'); }
        }
    }
    const newItem = document.getElementById(`user-item-${visitId}`);
    if (newItem) {
        newItem.classList.add('user-item-active', 'bg-blue-50', 'border-blue-600'); 
        newItem.classList.remove('hover:bg-slate-50', 'border-transparent');
        const av = newItem.querySelector('.user-avatar');
        if(av) { av.classList.remove('from-blue-100', 'to-blue-200', 'text-blue-600'); av.classList.add('from-blue-600', 'to-blue-700', 'text-white'); }
        const badge = newItem.querySelector('.bg-red-500'); if(badge) badge.remove();
        newItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    activeVisitId = visitId; lastMsgCount = 0; 
    let restoredFromCache = false;
    
    try {
        const msgContainer = document.getElementById('messages');
        restoredFromCache = restoreChatState(visitId);
        if (!restoredFromCache) {
            msgContainer.innerHTML = `<div id="chat-loader" class="flex flex-col items-center justify-center h-full space-y-3 animate-pulse"><div class="w-8 h-8 rounded-full border-2 border-slate-300 border-t-transparent animate-spin"></div><div class="text-xs text-slate-400">Memuat riwayat...</div></div>`;
        }
        
        const user = usersData.find(u => String(u.visit_id) === String(visitId));
        if (user) {
            setText('.info-name', user.name); setText('.info-phone', user.phone || '-'); setText('.info-address', user.address || '-'); setText('.info-id', '#' + user.visit_id); setText('.info-location', user.location_info || '-'); setText('.info-ip', user.ip_address || '-');
            const noteInput = document.getElementById('customer-note'); if(noteInput) { noteInput.value = user.notes || ''; const st = document.getElementById('note-status'); if(st) { st.innerText = 'Saved'; st.className = 'text-[9px] text-slate-400 font-normal opacity-0'; } }
            const hName = document.getElementById('h-name'); if(hName) hName.innerText = user.name;
            const hAvatar = document.getElementById('h-avatar'); if(hAvatar) hAvatar.innerText = (user.name||'U').charAt(0).toUpperCase();
            const hStatus = document.getElementById('h-status'); if(hStatus) hStatus.innerHTML = getStatus(user.last_seen);
            const hId = document.getElementById('h-id'); if(hId) hId.innerText = '#' + user.visit_id;
            
            const waLink = document.getElementById('wa-link'); 
            if (user.phone && user.phone.length > 5 && !user.phone.includes('Unknown')) { 
                let clean = user.phone.replace(/\D/g, ''); 
                if (clean.startsWith('0')) clean = '62' + clean.substring(1); 
                else if (!clean.startsWith('62')) clean = '62' + clean; 
                waLink.href = `https://wa.me/${clean}`; waLink.classList.remove('hidden'); 
            } else { waLink.classList.add('hidden'); }
            
            const menuToggle = document.getElementById('menu-toggle-session'); 
            const footerActive = document.getElementById('footer-active'); 
            const footerLocked = document.getElementById('footer-locked'); 
            const btnEnd = document.getElementById('btn-end-session'); 
            const btnReopen = document.getElementById('btn-reopen-session');

            if (user.status === 'Selesai') {
                if(menuToggle) { menuToggle.innerHTML = 'Buka Kembali Sesi'; menuToggle.setAttribute('onclick', 'reopenSession()'); menuToggle.className = 'block px-4 py-2.5 text-sm hover:bg-blue-50 dark:hover:bg-white/5 text-blue-600 dark:text-blue-400 border-b border-slate-100 dark:border-white/10 font-bold'; }
                if(footerActive) footerActive.classList.add('hidden'); if(footerLocked) { footerLocked.classList.remove('hidden'); footerLocked.classList.add('flex'); }
                if(btnEnd) btnEnd.classList.add('hidden'); if(btnReopen) btnReopen.classList.remove('hidden');
            } else {
                if(menuToggle) { menuToggle.innerHTML = 'Selesaikan Sesi'; menuToggle.setAttribute('onclick', 'openEndModal()'); menuToggle.className = 'block px-4 py-2.5 text-sm hover:bg-green-50 dark:hover:bg-white/5 text-green-600 dark:text-green-400 border-b border-slate-100 dark:border-white/10 font-bold'; }
                if(footerActive) footerActive.classList.remove('hidden'); if(footerLocked) footerLocked.classList.add('hidden');
                if(btnEnd) btnEnd.classList.remove('hidden'); if(btnReopen) btnReopen.classList.add('hidden');
            }
        }
    } catch (e) {}
    
    const runInitialLoad = (useWarmCache) => {
        loadMessages(!useWarmCache);
        if (hasMoreHistory) {
            queueHistoryPrefetch(visitId);
        }
    };

    if (restoredFromCache) {
        runInitialLoad(true);
    } else {
        restoreChatStateFromIndexedDb(visitId)
            .then((restoredFromDb) => {
                if (String(activeVisitId || '') !== String(visitId || '')) return;
                runInitialLoad(!!restoredFromDb);
            })
            .catch(() => {
                if (String(activeVisitId || '') !== String(visitId || '')) return;
                runInitialLoad(false);
            });
    }
    pollingInterval = setInterval(() => loadMessages(false), 3000);
}

function updateTitleNotification(totalUnread) { document.title = totalUnread > 0 ? `(${totalUnread}) Chat Admin` : "Chat Admin | Isolir"; }

function loadMessages(isFirstLoad = false) {
    if (!activeVisitId || isFetching) return; 
    if (!isFirstLoad) isFetching = true;
    const visitId = String(activeVisitId);

    let url = `admin_api.php?action=get_messages&visit_id=${visitId}&viewer=admin`;
    if (isFirstLoad) {
        url += `&limit=${CHAT_INITIAL_LIMIT}`;
    } else if (lastSyncTime) {
        url += `&last_sync=${lastSyncTime}`;
    }

    fetch(url)
    .then(r => r.json())
    .then(data => {
        isFetching = false;
        if (String(activeVisitId) !== visitId) return;
        
        if (data.status === 'error' && data.msg === 'Unauthorized') { 
            window.location.href = '../login.php'; return; 
        }

        const messages = data.messages || [];
        const serverTime = data.server_time;
        if (isFirstLoad) {
            hasMoreHistory = !!data.has_more;
            oldestLoadedId = data.oldest_id || (messages[0]?.id ?? null);
        }

        if (isFirstLoad) {
            const container = document.getElementById('messages');
            container.innerHTML = '';
            if (messages.length === 0) {
                 container.innerHTML = '<div id="chat-empty" class="flex h-full items-center justify-center text-xs text-slate-400">Belum ada riwayat chat.</div>'; 
            }
        }

        if (messages.length > 0) {
            processMessageUpdates(messages);
            const lastM = messages[messages.length - 1];
            if (isFirstLoad || lastM.sender === 'user' || lastM.sender === 'admin') {
                scrollToBottom();
                if(!isFirstLoad && lastM.sender === 'user') playSound();
            }
        }
        
        if (serverTime) { lastSyncTime = serverTime; }
        cacheActiveChatState();
        if (isFirstLoad && hasMoreHistory) {
            queueHistoryPrefetch(visitId);
        }
    })
    .catch(e => { isFetching = false; });
}

function processMessageUpdates(data) {
    const container = document.getElementById('messages');
    const loader = document.getElementById('chat-loader');
    const emptyState = document.getElementById('chat-empty');

    if (loader || emptyState) {
        container.innerHTML = '';
    }

    data.forEach(msg => {
        const existingEl = document.getElementById(`msg-wrapper-${msg.id}`);
        if (msg.status === 'deleted') {
            if (existingEl) existingEl.remove();
            return;
        }

        const bubbleHtml = createBubbleHtml(msg);

        if (existingEl) {
            existingEl.innerHTML = bubbleHtml;
            setMessageWrapperMeta(existingEl, msg);
        } else {
            const wrapper = document.createElement('div');
            wrapper.id = `msg-wrapper-${msg.id}`;
            wrapper.className = `flex ${msg.sender === 'admin' ? 'justify-end' : 'justify-start'} mb-2 shrink-0 group animate-in fade-in zoom-in duration-200`;
            wrapper.innerHTML = bubbleHtml;
            setMessageWrapperMeta(wrapper, msg);
            container.appendChild(wrapper);
        }
    });
    refreshMessageDaySeparators();
}

function createBubbleHtml(msg) {
    const isMe = (msg.sender === 'admin'); 
    const isImg = (msg.type === 'image'); 
    const bg = isMe ? 'bg-[#d9fdd3] dark:bg-darkme text-gray-900 dark:text-white rounded-br-none shadow-sm' : 'bg-white dark:bg-darkbubble text-gray-900 dark:text-white rounded-bl-none shadow-sm border border-gray-100 dark:border-transparent'; 
    const padClass = isImg ? 'p-1' : 'px-3 py-2'; 
    
    let content = ''; 
    if (isImg) { 
        content = `<div class="cursor-pointer group relative" onclick="openImageViewer('uploads/${msg.message}')"><img src="uploads/${msg.message}" class="block rounded-lg max-w-[250px] max-h-[300px] w-auto h-auto shadow-sm bg-slate-50 dark:bg-black/20 object-contain border border-slate-200 dark:border-white/10" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'p-3 bg-red-50 text-[10px] text-red-500 rounded border border-red-100 italic\\'>Gagal muat gambar</div>';"><div class="absolute inset-0 bg-black/0 group-hover:bg-black/5 transition rounded-lg"></div></div>`; 
    } else { 
        content = linkify(msg.message); 
    }
    
    let statusIcon = ''; 
    if(isMe) statusIcon = `<span class="text-blue-500 dark:text-blue-300 font-bold ml-1">✓</span>`; 
    const editedLabel = (msg.is_edited == 1) ? `<span class="text-[9px] italic opacity-60 mx-1">(diedit)</span>` : '';
    
    let menuBtn = ''; 
    if (isMe) { 
        const canEdit = !isImg ? `<button onclick="editMessage('${msg.id}')" class="block w-full text-left px-4 py-2 text-xs hover:bg-slate-50 dark:hover:bg-white/5 text-slate-700 dark:text-slate-200 font-medium">✏️ Edit</button>` : ''; 
        menuBtn = `<div class="relative group/menu flex items-center self-center opacity-0 group-hover:opacity-100 transition-opacity mx-2"><button class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 text-xs font-bold px-1 tracking-widest">•••</button><div class="hidden group-hover/menu:block absolute right-0 top-4 bg-white dark:bg-[#233138] border border-slate-200 dark:border-white/10 shadow-xl rounded-lg z-50 w-24 overflow-hidden ring-1 ring-black/5">${canEdit}<button onclick="deleteMessage('${msg.id}')" class="block w-full text-left px-4 py-2 text-xs hover:bg-red-50 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400 font-medium border-t border-slate-100 dark:border-white/10">🗑️ Hapus</button></div></div>`; 
    }

    let smartActions = '';
    if (!isMe && !isImg) { smartActions = detectSmartData(msg.message, msg.id); }

    const displayMsgTime = escapeHtml(String(msg.time || formatHourMinute(msg.created_at) || ''));
    const bubbleInner = `<div id="msg-${msg.id}" class="${bg} ${padClass} rounded-lg text-[13.5px] leading-relaxed relative break-words shadow-sm"><span class="msg-text">${content}</span><div class="msg-time text-[9px] opacity-50 text-right mt-1 font-mono tracking-wide flex justify-end items-center gap-1 select-none">${editedLabel} ${displayMsgTime} ${statusIcon}</div></div>`;

    if (isMe) {
        return `<div class="max-w-[85%] md:max-w-[75%] flex items-start">${menuBtn}${bubbleInner}</div>`;
    } else {
        return `<div class="flex flex-col items-start max-w-[85%] md:max-w-[75%]">${bubbleInner}${smartActions}</div>`;
    }
}

function sendImageAdmin() {
    const fileInput = document.getElementById('admin-img-input');
    if (fileInput.files.length === 0 || !activeVisitId) return;
    
    const file = fileInput.files[0];
    
    if(file.size > 5*1024*1024) {
        showSmartAlert("File Besar", "Maksimal 5MB.", "warning");
        fileInput.value='';
        return;
    }

    // ANIMASI LOADING UPLOAD
    const container = document.getElementById('messages');
    const tempId = 'loading-' + Date.now();
    const loaderHtml = `<div id="${tempId}" class="flex justify-end mb-2 shrink-0 animate-pulse"><div class="bg-blue-50 dark:bg-white/10 px-4 py-3 rounded-lg rounded-br-none border border-blue-100 dark:border-white/5 flex items-center gap-2 shadow-sm"><svg class="animate-spin h-4 w-4 text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="text-xs font-bold text-blue-700 dark:text-blue-300">Mengupload gambar...</span></div></div>`;
    container.insertAdjacentHTML('beforeend', loaderHtml);
    scrollToBottom();

    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('visit_id', activeVisitId);
    fd.append('sender', 'admin');
    fd.append('image', file);
    
    fileInput.value = '';

    fetch('admin_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            const loaderEl = document.getElementById(tempId);
            if (loaderEl) loaderEl.remove();

            if (res.status === 'success') {
                loadMessages(false);
            } else {
                showSmartAlert("Gagal", res.msg || "Upload gagal.", "error");
            }
        })
        .catch(err => {
            const loaderEl = document.getElementById(tempId);
            if (loaderEl) loaderEl.remove();
            showSmartAlert("Error", "Gagal menghubungi server.", "error");
        });
}

function deleteMessage(id) { showSmartConfirm("Hapus Pesan?", "Hapus permanen?", "danger", "Hapus").then(yes => { if(yes) { const fd = new FormData(); fd.append('action', 'delete_message'); fd.append('id', id); fetch('admin_api.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res => { if(res.status === 'success') loadMessages(false); }); } }); }

function editMessage(id) { const msgDiv = document.getElementById(`msg-${id}`); if (!msgDiv) { alert("Pesan tidak ditemukan."); return; } const bubbleText = msgDiv.querySelector('.msg-text'); if(!bubbleText) return; editingMsgId = id; const currentText = bubbleText.innerText; const input = document.getElementById('msg-input'); const indicator = document.getElementById('edit-indicator'); if (input) { input.value = currentText; input.focus(); input.style.height = 'auto'; input.style.height = (input.scrollHeight) + 'px'; input.parentElement.classList.add('ring-2', 'ring-blue-500', 'bg-blue-50'); } if (indicator) { indicator.classList.remove('hidden'); indicator.classList.add('flex'); } }

function cancelEditMode() { editingMsgId = null; const input = document.getElementById('msg-input'); const indicator = document.getElementById('edit-indicator'); if (input) { input.value = ''; input.style.height = 'auto'; input.parentElement.classList.remove('ring-2', 'ring-blue-500', 'bg-blue-50'); input.blur(); } if (indicator) { indicator.classList.add('hidden'); indicator.classList.remove('flex'); } }

function sendMessage() {
    if (!activeVisitId) return;
    const input = document.getElementById('msg-input');
    const text = input.value.trim();
    if (!text) return;

    if (editingMsgId) {
        const tempId = editingMsgId;
        const tempText = text;
        const fd = new FormData();
        fd.append('action', 'edit_message');
        fd.append('id', tempId);
        fd.append('message', tempText);
        fetch('admin_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if(res.status !== 'success') loadMessages(false); });
        cancelEditMode();
    } else {
        input.value = '';
        input.style.height = 'auto';
        input.focus();
        const fd = new FormData();
        fd.append('action', 'send');
        fd.append('visit_id', activeVisitId);
        fd.append('sender', 'admin');
        fd.append('message', text);
        fetch('admin_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { loadMessages(false); });
    }
}

// ... (Paste sisa fungsi: injectEditIndicator, openAdminProfile, saveAdminProfile, setText, getStatus, linkify, scrollToBottom, playSound, openImageViewer, closeImageViewer, toggleSidebarMenu, toggleMenu, showDetail, closeDetail, openEditUser, loadTemplates, showTemplatePopup, useTemplate, toggleTpl, openTplManager, renderTemplateManager, openFormTpl, saveTemplate, deleteTemplate, saveEditUser, openEndModal, confirmEndSession, reopenSession, deleteSession, injectSmartModal, showSmartAlert, showSmartConfirm, setupModal, openSmartModal, closeSmartModal, saveNote, detectSmartData, toTitleCase, showSmartPrompt, quickUpdateUser)
// Pastikan tidak ada duplikasi fungsi. Copy dari file app.js sebelumnya untuk fungsi-fungsi helper ini.

function injectEditIndicator() { const footer = document.getElementById('footer-active'); if(footer) { const div = document.createElement('div'); div.id = 'edit-indicator'; div.className = 'hidden w-full bg-blue-50 dark:bg-white/10 border-t border-x border-blue-200 dark:border-white/10 rounded-t-xl px-4 py-2 flex justify-between items-center text-xs text-blue-700 dark:text-blue-300 absolute bottom-full left-0 mb-[-5px] z-0 shadow-sm'; div.innerHTML = `<div class="flex items-center gap-2"><span class="font-bold">✏️ Edit Mode</span></div><button onclick="cancelEditMode()" class="text-slate-400 hover:text-red-500 font-bold px-2 uppercase text-[10px]">Batal (ESC)</button>`; footer.parentElement.style.position = 'relative'; footer.parentElement.insertBefore(div, footer.parentElement.firstChild); } }

function openAdminProfile() { fetch('admin_api.php?action=get_admin_profile').then(r => r.json()).then(res => { if (res.status === 'success') { const d = res.data; document.getElementById('adm-name').value = d.name || ''; document.getElementById('adm-username').value = d.username || ''; document.getElementById('adm-password').value = ''; const s = document.getElementById('sidebar-menu'); if (s) s.classList.add('hidden'); document.getElementById('modal-admin-profile').classList.remove('hidden'); } else { showSmartAlert("Gagal", res.msg || "Gagal.", "error"); } }); }

function saveAdminProfile() { const name = document.getElementById('adm-name').value.trim(); const username = document.getElementById('adm-username').value.trim(); const password = document.getElementById('adm-password').value; if (!name || !username) { showSmartAlert("Gagal", "Lengkapi data.", "warning"); return; } const fd = new FormData(); fd.append('action', 'update_admin_profile'); fd.append('name', name); fd.append('username', username); if (password) fd.append('password', password); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if (res.status === 'success') { document.getElementById('modal-admin-profile').classList.add('hidden'); showSmartAlert("Berhasil", "Profil disimpan.", "success").then(() => location.reload()); } else { showSmartAlert("Gagal", res.msg || "Error.", "error"); } }); }

function setText(selector, text) { document.querySelectorAll(selector).forEach(el => el.innerText = text || '-'); }

function getStatus(lastSeen) {
    const seenDate = parseChatDateTime(lastSeen);
    if (!seenDate) {
        return '<span class="text-slate-400 dark:text-slate-500 text-xs">Offline</span>';
    }

    const diffMs = Date.now() - seenDate.getTime();
    const diffSeconds = Number.isFinite(diffMs) ? Math.floor(diffMs / 1000) : Number.POSITIVE_INFINITY;
    if (diffSeconds <= 30) {
        return '<span class="text-green-600 dark:text-green-400 text-xs font-bold">Online</span>';
    }

    return '<span class="text-slate-400 dark:text-slate-500 text-xs">Offline</span>';
}

function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function linkify(text) {
    if (!text) return '';
    // Escape dulu agar aman dari XSS, lalu baru format newline dan URL.
    let formatted = escapeHtml(text).replace(/\n/g, '<br>');
    return formatted.replace(
        /(\b(https?):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/ig,
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline break-all">$1</a>',
    );
}

function scrollToBottom() { const el = document.getElementById('messages'); el.scrollTop = el.scrollHeight; }

function playSound() { if(audioNotif) { audioNotif.currentTime = 0; const playPromise = audioNotif.play(); if (playPromise !== undefined) { playPromise.catch(error => { console.log("Audio autoplay prevented."); }); } } }

function openImageViewer(src) { document.getElementById('img-viewer-src').src = src; document.getElementById('img-download-btn').href = src; document.getElementById('img-viewer').classList.remove('hidden'); document.getElementById('img-viewer').classList.add('flex'); }

function closeImageViewer() { document.getElementById('img-viewer').classList.add('hidden'); document.getElementById('img-viewer').classList.remove('flex'); }

function toggleSidebarMenu() { const s = document.getElementById('sidebar-menu'); if(s) s.classList.toggle('hidden'); }

function toggleMenu() { const c = document.getElementById('chat-menu'); if(c) c.classList.toggle('hidden'); }

function showDetail() { toggleUserSidebar(); toggleMenu(); }

function closeDetail() { document.getElementById('modal-detail').classList.add('hidden'); }

function openEditUser() { if (!activeVisitId) return; const user = usersData.find(u => u.visit_id == activeVisitId); if (!user) return; document.getElementById('edit-visit-id').value = user.visit_id; document.getElementById('edit-name').value = user.name || ''; document.getElementById('edit-phone').value = user.phone || ''; document.getElementById('edit-addr').value = user.address || ''; document.getElementById('modal-edit-user').classList.remove('hidden'); toggleMenu(); }

window.chatTemplates = []; 
function loadTemplates() { fetch('admin_api.php?action=get_templates').then(r => r.json()).then(data => { if(Array.isArray(data)) { window.chatTemplates = data; renderTemplateManager(); } }).catch(e => console.error("Gagal muat template:", e)); }

function showTemplatePopup(k) { const p = document.getElementById('tpl-popup'); const l = document.getElementById('tpl-list'); const m = window.chatTemplates.filter(t => (t.label || '').toLowerCase().includes(k.toLowerCase())); if(m.length === 0) { p.classList.add('hidden'); return; } l.innerHTML = m.map(t => { const rawMsg = String(t.message ?? ''); const jsMsg = rawMsg.replace(/\\/g, "\\\\").replace(/'/g,"\\'"); const safeLabel = escapeHtml(t.label ?? ''); const safeMsg = escapeHtml(rawMsg); return `<div onclick="useTemplate('${jsMsg}')" class="px-3 py-2 hover:bg-slate-100 dark:hover:bg-white/5 cursor-pointer border-b border-slate-100 dark:border-white/10 text-xs"><div class="text-blue-600 dark:text-blue-400 font-bold mb-0.5">/${safeLabel}</div><div class="text-slate-500 dark:text-slate-400 truncate">${safeMsg}</div></div>`; }).join(''); p.classList.remove('hidden'); }

function useTemplate(t) { const i=document.getElementById('msg-input'); i.value=t; i.dispatchEvent(new Event('input')); document.getElementById('tpl-popup').classList.add('hidden'); i.focus(); }

function toggleTpl() { const p=document.getElementById('tpl-popup'); if(p.classList.contains('hidden')) showTemplatePopup(''); else p.classList.add('hidden'); }

function openTplManager() { loadTemplates(); document.getElementById('modal-tpl-manager').classList.remove('hidden'); }

function renderTemplateManager() { const c = document.getElementById('manager-list'); if(c) { if(window.chatTemplates.length === 0) { c.innerHTML = '<div class="text-center text-slate-400 text-xs py-5">Belum ada template.</div>'; return; } c.innerHTML = window.chatTemplates.map(t => { const safeLabel = escapeHtml(t.label ?? ''); const safeMsg = escapeHtml(String(t.message ?? '')); const jsId = String(t.id ?? '').replace(/\\/g, "\\\\").replace(/'/g, "\\'"); return `<div class="bg-slate-50 dark:bg-white/5 p-3 rounded-xl border border-slate-200 dark:border-white/10 flex justify-between gap-3 items-start"><div class="overflow-hidden"><div class="text-blue-600 dark:text-blue-400 text-xs font-bold mb-1">/${safeLabel}</div><div class="text-xs text-slate-500 dark:text-slate-400 line-clamp-2">${safeMsg}</div></div><button onclick="deleteTemplate('${jsId}')" class="text-slate-400 hover:text-red-500 p-1 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></div>`; }).join(''); } }

function openFormTpl() { document.getElementById('tpl-label').value=''; document.getElementById('tpl-msg').value=''; document.getElementById('modal-tpl-manager').classList.add('hidden'); document.getElementById('modal-form-tpl').classList.remove('hidden'); }

function saveTemplate() { const l = document.getElementById('tpl-label').value.trim(); const m = document.getElementById('tpl-msg').value.trim(); if(l && m) { const fd = new FormData(); fd.append('action', 'save_template'); fd.append('label', l); fd.append('message', m); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if(res.status === 'success') { loadTemplates(); openTplManager(); document.getElementById('modal-form-tpl').classList.add('hidden'); showSmartAlert("Berhasil", "Template disimpan.", "success"); } else { showSmartAlert("Gagal", "Error.", "error"); } }); } else { showSmartAlert("Validasi", "Wajib diisi.", "warning"); } }

function deleteTemplate(id) { showSmartConfirm('Hapus Template', 'Hapus permanen?', 'warning').then(ok=>{ if(ok){ const fd = new FormData(); fd.append('action', 'delete_template'); fd.append('id', id); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if(res.status === 'success') { loadTemplates(); } else { showSmartAlert("Gagal", "Error.", "error"); } }); } }); }

function saveEditUser() { const id = document.getElementById('edit-visit-id').value; const name = document.getElementById('edit-name').value.trim(); const phone = document.getElementById('edit-phone').value.trim(); const addr = document.getElementById('edit-addr').value.trim(); if (!id || !name) { showSmartAlert("Validasi", "Nama wajib diisi.", "warning"); return; } document.getElementById('modal-edit-user').classList.add('hidden'); const fd = new FormData(); fd.append('action', 'update_customer'); fd.append('visit_id', id); fd.append('name', name); fd.append('phone', phone); fd.append('address', addr); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if (res.status === 'success') { const userIndex = usersData.findIndex(u => u.visit_id == id); if (userIndex !== -1) { usersData[userIndex].name = name; usersData[userIndex].phone = phone; usersData[userIndex].address = addr; } openChat(id); showSmartAlert("Berhasil", "Data disimpan.", "success"); } else { showSmartAlert("Gagal", 'Error.', "error"); } }); }

function openEndModal() { if (!activeVisitId) return; toggleMenu(); showSmartConfirm("Selesaikan Sesi?", "Sesi akan ditutup.", "success", "Ya, Selesaikan").then((isConfirmed) => { if (isConfirmed) confirmEndSession(); }); }

function confirmEndSession() { if (!activeVisitId) return; /* Optimistic UI: switch footer instantly */ const userIndex = usersData.findIndex(u => u.visit_id == activeVisitId); if (userIndex !== -1) usersData[userIndex].status = 'Selesai'; const fa = document.getElementById('footer-active'); const fl = document.getElementById('footer-locked'); if(fa) fa.classList.add('hidden'); if(fl) { fl.classList.remove('hidden'); fl.classList.add('flex'); } const mt = document.getElementById('menu-toggle-session'); if(mt) { mt.innerHTML = 'Buka Kembali Sesi'; mt.setAttribute('onclick', 'reopenSession()'); mt.className = 'block px-4 py-2.5 text-sm hover:bg-blue-50 dark:hover:bg-white/5 text-blue-600 dark:text-blue-400 border-b border-slate-100 dark:border-white/10 font-bold'; } const be = document.getElementById('btn-end-session'); const br = document.getElementById('btn-reopen-session'); if(be) be.classList.add('hidden'); if(br) br.classList.remove('hidden'); /* Fire & forget */ const fd = new FormData(); fd.append('action', 'end_session'); fd.append('visit_id', activeVisitId); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if (res.status !== 'success') { if (userIndex !== -1) usersData[userIndex].status = 'Proses'; openChat(activeVisitId); showSmartAlert("Error", "Gagal menutup sesi.", "error"); } else { loadContacts(false); } }); }

function reopenSession() { if (!activeVisitId) return; /* Optimistic UI: switch footer instantly */ const userIndex = usersData.findIndex(u => u.visit_id == activeVisitId); if(userIndex !== -1) usersData[userIndex].status = 'Proses'; const fa = document.getElementById('footer-active'); const fl = document.getElementById('footer-locked'); if(fa) fa.classList.remove('hidden'); if(fl) fl.classList.add('hidden'); const mt = document.getElementById('menu-toggle-session'); if(mt) { mt.innerHTML = 'Selesaikan Sesi'; mt.setAttribute('onclick', 'openEndModal()'); mt.className = 'block px-4 py-2.5 text-sm hover:bg-green-50 dark:hover:bg-white/5 text-green-600 dark:text-green-400 border-b border-slate-100 dark:border-white/10 font-bold'; } const be = document.getElementById('btn-end-session'); const br = document.getElementById('btn-reopen-session'); if(be) be.classList.remove('hidden'); if(br) br.classList.add('hidden'); /* Fire & forget: send reopen + background refresh */ const fd = new FormData(); fd.append('action', 'reopen_session'); fd.append('visit_id', activeVisitId); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if (res.status !== 'success') { /* Rollback on failure */ if(userIndex !== -1) usersData[userIndex].status = 'Selesai'; openChat(activeVisitId); showSmartAlert("Error", "Gagal membuka sesi.", "error"); } else { loadContacts(false); } }); }

function resolveCurrentVisitId() {
    if (activeVisitId) return String(activeVisitId).trim();

    const activeItem = document.querySelector('.user-item-active[data-visit-id]');
    const fromActiveItem = String(activeItem?.getAttribute('data-visit-id') || '').trim();
    if (fromActiveItem) return fromActiveItem;

    const fromHeader = String(document.getElementById('h-id')?.innerText || '').replace(/^#/, '').trim();
    if (fromHeader) return fromHeader;

    try {
        const fromUrl = String(new URLSearchParams(window.location.search).get('id') || '').trim();
        if (fromUrl) return fromUrl;
    } catch (e) {
    }

    return '';
}

function bindSidebarQuickActionButtons() {
    const sidebar = document.getElementById('user-detail-sidebar');
    if (!sidebar) return;

    const quickActionButtons = Array.from(sidebar.querySelectorAll('.grid.grid-cols-2.gap-3 button'));
    if (quickActionButtons.length === 0) return;

    quickActionButtons.forEach((btn) => {
        if (!btn.getAttribute('type')) btn.setAttribute('type', 'button');
    });

    const deleteBtn = quickActionButtons.find((btn) => /hapus/i.test(String(btn.textContent || '')));
    if (!deleteBtn || deleteBtn.dataset.boundDeleteSession === '1') return;

    deleteBtn.dataset.boundDeleteSession = '1';
    deleteBtn.removeAttribute('onclick');
    deleteBtn.addEventListener('click', (e) => {
        e.preventDefault();
        deleteSession();
    });
}

function deleteSession() { 
    if (isDeletingSession) return;
    const resolvedVisitId = resolveCurrentVisitId();
    if (!resolvedVisitId) {
        showSmartAlert("Validasi", "Pilih chat pelanggan dulu.", "warning");
        return;
    }

    activeVisitId = resolvedVisitId;
    showSmartConfirm("Hapus Chat?", "Data akan hilang permanen.", "danger", "Hapus").then((isConfirmed) => { 
        if (isConfirmed) { 
            isDeletingSession = true;
            const deletingVisitId = String(resolvedVisitId);
            const wasActive = String(activeVisitId || '') === deletingVisitId;
            const fd = new FormData(); 
            fd.append('action', 'delete_session'); 
            fd.append('visit_id', deletingVisitId); 
            fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { 
                if (res.status === 'success') { 
                    delete chatRoomCache[deletingVisitId];
                    persistChatCacheToSession();
                    deleteChatRoomFromIndexedDb(deletingVisitId);
                    usersData = usersData.filter(u => String(u.visit_id) !== deletingVisitId);
                    renderUserList(usersData, true);
                    renderFilterButtons(usersData);
                    persistContactsCache(true);
                    if (wasActive || String(activeVisitId || '') === deletingVisitId) {
                        closeActiveChat();
                    }
                    lastContactSync = '';
                    loadContacts(true); 
                    showSmartAlert("Terhapus", "Data chat berhasil dihapus.", "success"); 
                } else { 
                    showSmartAlert("Gagal", res.msg || "Error.", "error"); 
                } 
            }).catch(() => {
                showSmartAlert("Gagal", "Error.", "error");
            }).finally(() => {
                isDeletingSession = false;
            }); 
        } 
    }); 
}

function injectSmartModal() { if (document.getElementById('smart-modal')) return; const modalHtml = `<div id="smart-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0" id="smart-modal-bg"></div><div class="relative bg-white dark:bg-[#233138] rounded-2xl shadow-2xl max-w-sm w-full p-6 transform scale-95 opacity-0 transition-all duration-300 border border-slate-200 dark:border-white/10" id="smart-modal-panel"><div class="text-center"><h3 class="text-lg font-bold text-slate-800 dark:text-white tracking-wide" id="smart-title">Notification</h3><div class="mt-2"><p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed" id="smart-msg">Message goes here...</p></div></div><div class="mt-6 flex gap-3 justify-center" id="smart-actions"></div></div></div>`; document.body.insertAdjacentHTML('beforeend', modalHtml); }

function showSmartAlert(title, message, type = 'info') { return new Promise((resolve) => { setupModal(title, message, type); const btnArea = document.getElementById('smart-actions'); btnArea.innerHTML = `<button id="smart-btn-ok" class="w-full justify-center rounded-xl bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 text-sm font-bold shadow-lg shadow-blue-200 dark:shadow-none transition-all outline-none focus:ring-4 focus:ring-blue-300 focus:ring-offset-2">Oke</button>`; const btn = document.getElementById('smart-btn-ok'); btn.onclick = () => { closeSmartModal(); resolve(true); }; openSmartModal(); setTimeout(() => btn.focus(), 50); }); }

function showSmartConfirm(title, message, type = 'warning', confirmText = 'Ya') { return new Promise((resolve) => { setupModal(title, message, type); const btnArea = document.getElementById('smart-actions'); let confirmColor = 'bg-blue-600 hover:bg-blue-700 shadow-blue-200 focus:ring-blue-300'; if (type === 'danger') { confirmColor = 'bg-red-600 hover:bg-red-700 shadow-red-200 focus:ring-red-300'; } if (type === 'success') { confirmColor = 'bg-green-600 hover:bg-green-700 shadow-green-200 focus:ring-green-300'; } btnArea.innerHTML = `<button id="smart-btn-cancel" class="flex-1 justify-center rounded-xl bg-white dark:bg-transparent hover:bg-slate-50 dark:hover:bg-white/5 text-slate-600 dark:text-slate-300 px-5 py-2.5 text-sm font-bold transition-all border border-slate-200 dark:border-white/20 outline-none focus:ring-4 focus:ring-slate-100 focus:ring-offset-2">Batal</button><button id="smart-btn-confirm" class="flex-1 justify-center rounded-xl ${confirmColor} text-white px-5 py-2.5 text-sm font-bold shadow-lg dark:shadow-none transition-all outline-none focus:ring-4 focus:ring-offset-2">${confirmText}</button>`; const btnCancel = document.getElementById('smart-btn-cancel'); const btnConfirm = document.getElementById('smart-btn-confirm'); btnConfirm.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') { e.preventDefault(); btnCancel.focus(); } }); btnCancel.addEventListener('keydown', (e) => { if (e.key === 'ArrowRight') { e.preventDefault(); btnConfirm.focus(); } }); btnCancel.onclick = () => { closeSmartModal(); resolve(false); }; btnConfirm.onclick = () => { closeSmartModal(); resolve(true); }; openSmartModal(); setTimeout(() => btnConfirm.focus(), 50); }); }

function setupModal(title, message, type) { document.getElementById('smart-title').innerText = title; document.getElementById('smart-msg').innerHTML = message; }

function openSmartModal() { const modal = document.getElementById('smart-modal'); const bg = document.getElementById('smart-modal-bg'); const panel = document.getElementById('smart-modal-panel'); modal.classList.remove('hidden'); modal.classList.add('flex'); setTimeout(() => { bg.classList.remove('opacity-0'); panel.classList.remove('opacity-0', 'scale-95'); panel.classList.add('opacity-100', 'scale-100'); }, 10); }

function closeSmartModal() { const bg = document.getElementById('smart-modal-bg'); const panel = document.getElementById('smart-modal-panel'); bg.classList.add('opacity-0'); panel.classList.remove('opacity-100', 'scale-100'); panel.classList.add('opacity-0', 'scale-95'); setTimeout(() => { document.getElementById('smart-modal').classList.add('hidden'); document.getElementById('smart-modal').classList.remove('flex'); }, 300); }

function saveNote() { if (!activeVisitId) return; const noteInput = document.getElementById('customer-note'); if (!noteInput) return; const noteVal = noteInput.value; const fd = new FormData(); fd.append('action', 'save_note'); fd.append('visit_id', activeVisitId); fd.append('note', noteVal); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { const statusEl = document.getElementById('note-status'); if (res.status === 'success') { if (statusEl) { statusEl.innerText = 'Saved'; statusEl.className = 'text-[9px] text-green-600 font-bold opacity-100 transition-opacity duration-300'; setTimeout(() => { if(statusEl.innerText === 'Saved') { statusEl.className = 'text-[9px] text-green-600 font-bold opacity-0 transition-opacity duration-500'; } }, 2000); } const userIndex = usersData.findIndex(u => String(u.visit_id) === String(activeVisitId)); if (userIndex !== -1) { usersData[userIndex].notes = noteVal; } } else { if (statusEl) { statusEl.innerText = 'Gagal'; statusEl.className = 'text-[9px] text-red-500 font-bold opacity-100'; } } }).catch(err => { console.error("Save note error:", err); const statusEl = document.getElementById('note-status'); if (statusEl) { statusEl.innerText = 'Error'; statusEl.className = 'text-[9px] text-red-500 font-bold opacity-100'; } }); }

function detectSmartData(msgText, msgId) {
    if (!msgText) return '';
    let suggestions = '';
    const namePattern = /(?:^|\n|\.|,)\s*(?:nama|nm|user|an\.?|a\/n|atas\s*nama)\s*(?:lengkap|panggilan|saya|asli|cust|customer|pelanggan)?\s*(?:[:=]|\s+)(?!(?:saya|aku|kamu|dia|mereka|kita|anda|adalah|itu|ini|yang|mau|ingin|tolong|bisa|sudah)\b)([a-zA-Z\s\.\']{3,30})/i;
    const introPattern = /(?:saya|aku|panggil)\s+(?:adalah|nama|panggil)?\s*([a-zA-Z\s\.\']{3,20})/i;
    let detectedName = null;
    let matchName = msgText.match(namePattern);
    if (matchName && matchName[1]) {
        detectedName = matchName[1];
    } else {
        let matchIntro = msgText.match(introPattern);
        if (matchIntro && matchIntro[1]) {
             const temp = matchIntro[1].trim();
             const firstWord = temp.split(' ')[0].toLowerCase();
             const blacklist = ['mau','ingin','tolong','admin','kak','halo','ada','butuh','perlu','mohon','bisa','sudah','belum'];
             if (!blacklist.includes(firstWord)) {
                 detectedName = temp;
             }
        }
    }
    if (detectedName) {
        detectedName = detectedName.replace(/\b(loh|dong|sih|min|kak|gan|sis|pak|bu)\b/gi, '').trim();
        if (detectedName.length > 2) {
            const safeName = detectedName.replace(/'/g, "\\'");
            suggestions += `<button onclick="quickUpdateUser('name', '${safeName}', '${msgId}')" class="mt-1 mr-1 px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 text-[10px] rounded-full border border-blue-200 font-bold transition-colors flex items-center gap-1">💾 Simpan Nama: ${detectedName}</button>`;
        }
    }
    const hpPattern = /(?:\+?62|0)8[0-9\-\s]{8,15}/;
    const matchHP = msgText.match(hpPattern);
    if (matchHP) {
        let cleanHP = matchHP[0].replace(/[^0-9]/g, '');
        if (cleanHP.startsWith('0')) cleanHP = '62' + cleanHP.substring(1);
        suggestions += `<button onclick="quickUpdateUser('phone', '${cleanHP}', '${msgId}')" class="mt-1 mr-1 px-2 py-1 bg-green-100 hover:bg-green-200 text-green-700 text-[10px] rounded-full border border-green-200 font-bold transition-colors flex items-center gap-1">💾 Simpan HP: ${cleanHP}</button>`;
    }
    const addrKeyword = /(?:al?a?mat|lokasi|posisi|domisili|hunian|t?empat\s*tinggal|rumah|rmh|kost|kediaman|ber?a?lamat(?:\s*di)?|tinggal\s*di)\s*[:=]?\s*([a-zA-Z0-9\s\.\,\-\/]+)/i;
    const addrDirect = /(?:jln|jl\.?|jalan|dusun|dsn?\.?|kp\.?|kampung|gang|gg\.?|blok|perum|komplek|rt\/rw|kel\.?|kec\.?|kab\.?|kota)\s+([a-zA-Z0-9\s\.\,\-\/]+)/i;
    let matchAddr = msgText.match(addrKeyword) || msgText.match(addrDirect);
    if (matchAddr) {
        let cleanAddr = matchAddr[1] ? matchAddr[1] : matchAddr[0];
        cleanAddr = cleanAddr.trim();
        if (cleanAddr.length > 100) cleanAddr = cleanAddr.substring(0, 97) + '...';
        if (cleanAddr.length > 4) {
            const safeAddr = cleanAddr.replace(/'/g, "\\'");
            suggestions += `<button onclick="quickUpdateUser('address', '${safeAddr}', '${msgId}')" class="mt-1 mr-1 px-2 py-1 bg-orange-100 hover:bg-orange-200 text-orange-700 text-[10px] rounded-full border border-orange-200 font-bold transition-colors flex items-center gap-1">💾 Simpan Alamat</button>`;
        }
    }
    return suggestions ? `<div class="flex flex-wrap mt-1 animate-in fade-in slide-in-from-top-1">${suggestions}</div>` : '';
}

function toTitleCase(str) { return str.replace(/\w\S*/g, function(txt){ return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase(); }); }

function showSmartPrompt(title, label, value) {
    return new Promise((resolve) => {
        setupModal(title, '', 'info');
        const msgArea = document.getElementById('smart-msg');
        msgArea.innerHTML = `<div class="text-left"><label class="block text-xs font-bold text-slate-500 mb-1">${label}</label><input type="text" id="smart-prompt-input" class="w-full px-3 py-2 border border-slate-300 dark:border-white/20 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-slate-50 dark:bg-black/20 dark:text-white" value="${value}"></div>`;
        const btnArea = document.getElementById('smart-actions');
        btnArea.innerHTML = `<button id="smart-btn-cancel" class="flex-1 justify-center rounded-xl bg-white dark:bg-transparent hover:bg-slate-50 dark:hover:bg-white/5 text-slate-600 dark:text-slate-300 px-5 py-2.5 text-sm font-bold border border-slate-200 dark:border-white/20">Batal</button><button id="smart-btn-save" class="flex-1 justify-center rounded-xl bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 text-sm font-bold shadow-lg shadow-blue-200 dark:shadow-none">Simpan</button>`;
        const input = document.getElementById('smart-prompt-input');
        const btnCancel = document.getElementById('smart-btn-cancel');
        const btnSave = document.getElementById('smart-btn-save');
        setTimeout(() => { input.focus(); input.select(); }, 100);
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') btnSave.click(); });
        btnCancel.onclick = () => { closeSmartModal(); resolve(false); };
        btnSave.onclick = () => { const finalVal = input.value.trim(); closeSmartModal(); resolve(finalVal); };
        openSmartModal();
    });
}

async function quickUpdateUser(field, value, msgId) {
    if (!activeVisitId) return;
    let formattedValue = value;
    if (field === 'name') formattedValue = toTitleCase(value);
    let label = "Pastikan ejaan benar:";
    if (field === 'phone') label = "Pastikan format nomor HP (62...):";
    if (field === 'address') label = "Edit alamat agar lebih rapi:";
    const finalValue = await showSmartPrompt(`Validasi ${field}`, label, formattedValue);
    if (finalValue === false || finalValue === '') return;
    const currentUser = usersData.find(u => u.visit_id == activeVisitId);
    let newName = (currentUser && currentUser.name) ? currentUser.name : '';
    let newPhone = (currentUser && currentUser.phone) ? currentUser.phone : '';
    let newAddr = (currentUser && currentUser.address) ? currentUser.address : '';
    if (field === 'name') newName = finalValue;
    if (field === 'phone') newPhone = finalValue;
    if (field === 'address') newAddr = finalValue;
    showSmartAlert("Menyimpan...", "Mohon tunggu...", "info");
    const fd = new FormData();
    fd.append('action', 'update_customer');
    fd.append('visit_id', activeVisitId);
    fd.append('name', newName);
    fd.append('phone', newPhone);
    fd.append('address', newAddr);
    fetch('admin_api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        const smartModal = document.getElementById('smart-modal');
        if(smartModal) smartModal.classList.add('hidden'); 
        if (res.status === 'success') {
            if (currentUser) {
                currentUser.name = newName;
                currentUser.phone = newPhone;
                currentUser.address = newAddr;
            }
            openChat(activeVisitId); 
            showSmartAlert("Sukses", "Data berhasil diperbarui.", "success");
        } else {
            showSmartAlert("Gagal", "Update gagal.", "error");
        }
    })
    .catch(err => {
        showSmartAlert("Error", "Koneksi bermasalah.", "error");
    });
}

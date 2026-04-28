<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const page = usePage();
const API_BASE = '/api/v1';
const AUTO_REGISTER_BATCH_SIZE = 10;
const OLT_LAST_SELECTED_STORAGE_KEY = 'noci:olt:last-selected';
const DEFAULT_TEKNISI_ONU_RX_MAX_DBM = -11.0;
const DEFAULT_TEKNISI_ONU_RX_MIN_DBM = -24.99;

const role = computed(() => String(page.props.auth?.user?.role || '').trim().toLowerCase());
const isTeknisi = computed(() => ['teknisi', 'svp lapangan', 'svp_lapangan'].includes(role.value));
const canManualRegister = computed(() => isTeknisi.value || ['admin', 'cs', 'owner'].includes(role.value));
const canManageOltProfile = computed(() => !isTeknisi.value);

// In-memory caches (native parity): keep list state when switching OLT (within the same page session).
const memoryUncfgCache = {};
const memoryRegisteredCache = {};

async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
        headers: {
            Accept: 'application/json',
            ...(options.headers || {}),
        },
        ...options,
    });

    const contentType = String(res.headers.get('content-type') || '').toLowerCase();
    const text = await res.text();

    let data = null;
    if (contentType.includes('application/json')) {
        try {
            data = JSON.parse(text);
        } catch {
            // ignore
        }
    }

    if (!res.ok) {
        const msg =
            data && (data.message || data.error)
                ? String(data.message || data.error)
                : `HTTP ${res.status}`;
        const err = new Error(msg);
        err.status = res.status;
        err.data = data;
        err.body = text;
        throw err;
    }

    if (data === null) {
        throw new Error('Non-JSON response');
    }

    return data;
}

function parseSummaryJson(value) {
    const text = String(value || '').trim();
    if (!text) return {};
    try {
        const parsed = JSON.parse(text);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
}

function sortFspList(list) {
    const items = Array.isArray(list) ? list.slice() : [];
    items.sort((a, b) => {
        const pa = String(a || '')
            .split('/')
            .map(n => parseInt(n, 10) || 0);
        const pb = String(b || '')
            .split('/')
            .map(n => parseInt(n, 10) || 0);
        if ((pa[0] || 0) !== (pb[0] || 0)) return (pa[0] || 0) - (pb[0] || 0);
        if ((pa[1] || 0) !== (pb[1] || 0)) return (pa[1] || 0) - (pb[1] || 0);
        return (pa[2] || 0) - (pb[2] || 0);
    });
    return items;
}

function sanitizeFspMetadataPayload(entries) {
    const rawItems = Array.isArray(entries)
        ? entries
        : entries && typeof entries === 'object'
            ? Object.values(entries)
            : [];

    const normalized = new Map();
    rawItems.forEach((item) => {
        const fsp = String(item?.fsp || '').trim();
        if (!/^\d+\/\d+\/\d+$/.test(fsp)) return;
        normalized.set(fsp, {
            fsp: String(item?.fsp || '').trim(),
            name: String(item?.name || '').trim().slice(0, 100),
            description: String(item?.description || '').trim().slice(0, 255),
        });
    });

    return sortFspList(Array.from(normalized.keys()))
        .map((fsp) => normalized.get(fsp))
        .filter((item) => item.fsp && (item.name || item.description));
}

function buildFspMetadataMap(input) {
    const map = {};
    sanitizeFspMetadataPayload(input).forEach((item) => {
        map[item.fsp] = item;
    });
    return map;
}

function toneClass(tone) {
    if (tone === 'success') return 'text-emerald-700 dark:text-emerald-200';
    if (tone === 'error') return 'text-rose-700 dark:text-rose-200';
    return 'text-slate-700 dark:text-slate-200';
}

function getStatusToneStyle(tone) {
    const tones = {
        info: {
            container:
                'bg-slate-50/80 dark:bg-slate-900/60 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-white/10',
            dot: 'bg-slate-400',
        },
        success: {
            container:
                'bg-emerald-50/80 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-200/70 dark:border-emerald-500/30',
            dot: 'bg-emerald-500',
        },
        error: {
            container:
                'bg-red-50/80 dark:bg-red-500/10 text-red-700 dark:text-red-300 border-red-200/70 dark:border-red-500/30',
            dot: 'bg-red-500',
        },
        loading: {
            container:
                'bg-blue-50/80 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 border-blue-200/70 dark:border-blue-500/30',
            dot: 'bg-blue-500',
        },
    };
    return tones[tone] || tones.info;
}

function extractRxValue(rx) {
    if (rx === null || rx === undefined || rx === '') return null;
    if (typeof rx === 'number' && Number.isFinite(rx)) return rx;
    const match = String(rx).match(/-?\d+(\.\d+)?/);
    if (!match) return null;
    const value = parseFloat(match[0]);
    return Number.isFinite(value) ? value : null;
}

function formatRx(rx) {
    const value = extractRxValue(rx);
    if (value === null) return '-';
    return `${value.toFixed(2)} dBm`;
}

function normalizeOnuRxBounds(maxValue, minValue) {
    let max = extractRxValue(maxValue);
    let min = extractRxValue(minValue);

    if (max === null) max = DEFAULT_TEKNISI_ONU_RX_MAX_DBM;
    if (min === null) min = DEFAULT_TEKNISI_ONU_RX_MIN_DBM;
    if (max < min) [max, min] = [min, max];

    return {
        max: Number(max.toFixed(2)),
        min: Number(min.toFixed(2)),
    };
}

function formatRxInputValue(value, fallback) {
    const parsed = extractRxValue(value);
    if (parsed === null) return String((fallback ?? 0).toFixed(2));
    return parsed.toFixed(2);
}

function formatSampledAt(value) {
    if (!value) return '-';
    const text = String(value).trim();
    if (!text) return '-';

    const normalized = text.includes('T') ? text : text.replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return text;

    return new Intl.DateTimeFormat('id-ID', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function normalizeRegState(value) {
    const raw = String(value || '').trim().toLowerCase();
    if (!raw) return '';
    if (['online', 'ready', 'working', 'up', 'active', 'enable', 'enabled', 'auth'].includes(raw)) return 'online';
    if (['offline', 'down', 'inactive', 'disable', 'disabled', 'unauth', 'los', 'oos', 'dying'].includes(raw)) return 'offline';
    if (raw.indexOf('online') !== -1) return 'online';
    if (raw.indexOf('offline') !== -1) return 'offline';
    return '';
}

function getBaseRegStatus(item) {
    const state = normalizeRegState(item?.state);
    if (state) return state;
    const status = normalizeRegState(item?.status);
    if (status) return status;
    return '';
}

function isRegRxPending(item) {
    const fsp = String(item?.fsp || '').trim();
    if (!fsp) return false;
    return !!regLiveLoadingFsp.value?.[fsp];
}

function getRegStatusLabel(item) {
    const base = getBaseRegStatus(item);
    const rxValue = extractRxValue(item?.rx);

    // Strict mode: online only when Rx is available and base state isn't explicitly offline.
    if (rxValue !== null) {
        return base === 'offline' ? 'offline' : 'online';
    }

    // While OLT Rx is still loading, avoid prematurely showing offline.
    if (isRegRxPending(item)) {
        return 'loading';
    }

    return 'offline';
}

function getRxToneClass(value) {
    if (value === null) return 'text-slate-600 dark:text-slate-300';
    if (value >= -25) return 'text-emerald-600 dark:text-emerald-300';
    if (value >= -28) return 'text-amber-600 dark:text-amber-300';
    return 'text-rose-600 dark:text-rose-300';
}

function getRxTextClass(item) {
    const value = extractRxValue(item?.rx);
    if (value === null) return 'text-slate-400';
    return getRxToneClass(value);
}

function getRxStatusClass(value, status) {
    if (status === 'loading') {
        return 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-200';
    }
    if (status !== 'online') {
        return 'bg-slate-100 text-slate-600 dark:bg-slate-700/40 dark:text-slate-300';
    }
    if (value === null || value >= -25) {
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200';
    }
    if (value >= -28) {
        return 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200';
    }
    return 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200';
}

function formatRegStatus(item) {
    const status = getRegStatusLabel(item);
    const rxValue = extractRxValue(item?.rx);
    const label = status === 'loading' ? 'Memuat Rx...' : status === 'online' ? 'Online' : 'Offline';
    return {
        label,
        className: getRxStatusClass(rxValue, status),
    };
}

function onuKey(onu) {
    const fsp = String(onu?.fsp || '');
    const onuId = Number(onu?.onu_id || 0);
    if (onu?.interface) return String(onu.interface);
    if (fsp && onuId > 0) return `gpon-onu_${fsp}:${onuId}`;
    return `${fsp}:${onuId}`;
}

// ========== OLT Profiles ==========
const olts = ref([]);
const loadingOlts = ref(false);
const selectedOltId = ref('');
const selectedOlt = computed(() => olts.value.find(o => String(o.id) === String(selectedOltId.value)) || null);
const selectedOltFspMetadataMap = computed(() => buildFspMetadataMap(selectedOlt.value?.fsp_metadata || []));
const selectedOltWriteConfigPending = computed(() => !!selectedOlt.value?.write_config_pending);
const hasAutoRegisterItems = computed(() => Array.isArray(uncfg.value) && uncfg.value.length > 0);
const OLT_META_POLL_MS = 10000;
let oltMetaPollTimer = null;
const openPortSlotFromLink = ref(false);

function readLastSelectedOltId() {
    try {
        return String(window.localStorage.getItem(OLT_LAST_SELECTED_STORAGE_KEY) || '').trim();
    } catch {
        return '';
    }
}

function writeLastSelectedOltId(value) {
    try {
        const normalized = String(value || '').trim();
        if (!normalized) {
            window.localStorage.removeItem(OLT_LAST_SELECTED_STORAGE_KEY);
            return;
        }
        window.localStorage.setItem(OLT_LAST_SELECTED_STORAGE_KEY, normalized);
    } catch {
        // ignore storage errors
    }
}

function shouldOpenPortSlotFromLocation() {
    try {
        const params = new URLSearchParams(window.location.search || '');
        const feature = String(params.get('feature') || params.get('open') || '').trim().toLowerCase();
        const hash = String(window.location.hash || '').replace(/^#/, '').trim().toLowerCase();
        return ['slot-port', 'port-slot', 'slot_port', 'port_slot'].includes(feature)
            || ['slot-port', 'port-slot', 'slot_port', 'port_slot'].includes(hash);
    } catch {
        return false;
    }
}

function openPortSlotModalFromLink() {
    if (!openPortSlotFromLink.value || !selectedOltId.value || portSlotModalOpen.value) return;
    openPortSlotFromLink.value = false;
    openPortSlotModal();
}

function sanitizeOnuName(value) {
    const text = String(value || '').trim();
    if (!text) return '';
    return text
        .replace(/\s+/g, '')
        .replace(/[^A-Za-z0-9_.-]/g, '')
        .slice(0, 32);
}

function buildScanLiveLines() {
    return ['show gpon onu uncfg', 'parsing output', 'filtering results', 'sync table'];
}

function buildRegisterLiveLines({ fsp, sn, name }) {
    const safeFsp = String(fsp || 'x/x/x');
    const safeSn = String(sn || 'SN');
    const onuName = sanitizeOnuName(name) || name || `ONU-${safeSn}`;
    const description = `AUTO-PROV-${safeSn}`.slice(0, 64);
    const onuType = selectedOlt.value?.onu_type_default || 'ALL-ONT';
    const tcont = selectedOlt.value?.tcont_default || 'pppoe';
    const vlan = selectedOlt.value?.vlan_default || '';
    const spid = selectedOlt.value?.service_port_id_default || '';

    const lines = [];
    lines.push('conf t');
    lines.push(`interface gpon-olt_${safeFsp}`);
    lines.push(`onu <auto-id> type ${onuType} sn ${safeSn}`);
    lines.push('exit');
    lines.push(`interface gpon-onu_${safeFsp}:<auto-id>`);
    lines.push(`name ${onuName}`);
    lines.push(`description ${description}`);
    lines.push('sn-bind enable sn');
    lines.push(`tcont 1 name T1 profile ${tcont}`);
    lines.push('gemport 1 name G1 tcont 1');
    lines.push('encrypt 1 enable downstream');
    if (vlan && spid) lines.push(`service-port ${spid} vport 1 user-vlan ${vlan} vlan ${vlan}`);
    if (spid) lines.push(`service-port ${spid} description Internet`);
    lines.push('exit');
    lines.push(`pon-onu-mng gpon-onu_${safeFsp}:<auto-id>`);
    if (vlan) lines.push(`service internet gemport 1 vlan ${vlan}`);
    lines.push('exit');
    lines.push('end');
    return lines;
}

function getUncfgItemKey(item) {
    const fsp = String(item?.fsp || '').trim();
    const sn = String(item?.sn || '').trim().toUpperCase();
    return `${fsp}:${sn}`;
}

async function loadOlts() {
    loadingOlts.value = true;
    try {
        const data = await fetchJson(`${API_BASE}/olts`);
        olts.value = data.status === 'ok' ? data.data : [];

        if (selectedOltId.value && !olts.value.some(o => String(o.id) === String(selectedOltId.value))) {
            selectedOltId.value = '';
            writeLastSelectedOltId('');
        }

        if (!selectedOltId.value) {
            const storedId = readLastSelectedOltId();
            if (storedId && olts.value.some(o => String(o.id) === storedId)) {
                selectedOltId.value = storedId;
            } else if (storedId) {
                writeLastSelectedOltId('');
            }
        }
    } catch (e) {
        olts.value = [];
    } finally {
        loadingOlts.value = false;
    }
}

function setSelectedOltConfigPending(pending, pendingAt = '') {
    const currentId = String(selectedOltId.value || '').trim();
    if (!currentId || !Array.isArray(olts.value)) return;

    const nextList = olts.value.map((item) => {
        if (String(item?.id || '') !== currentId) {
            return item;
        }

        return {
            ...item,
            write_config_pending: !!pending,
            write_config_pending_at: pending ? String(pendingAt || new Date().toISOString()) : null,
        };
    });

    olts.value = nextList;
}

function syncSelectedOltFspContext({ fspCache = null, fspMetadata = null } = {}) {
    const currentId = String(selectedOltId.value || '').trim();
    if (!currentId || !Array.isArray(olts.value)) return;

    const safeFspCache = Array.isArray(fspCache) ? sortFspList(fspCache) : null;
    const safeMetadata = Array.isArray(fspMetadata) ? sanitizeFspMetadataPayload(fspMetadata) : null;

    olts.value = olts.value.map((item) => {
        if (String(item?.id || '') !== currentId) return item;
        return {
            ...item,
            ...(safeFspCache ? { fsp_cache: safeFspCache, fsp_count: safeFspCache.length } : {}),
            ...(safeMetadata ? { fsp_metadata: safeMetadata } : {}),
        };
    });
}

function getFspMeta(fsp) {
    const safeFsp = String(fsp || '').trim();
    return selectedOltFspMetadataMap.value[safeFsp] || null;
}

function formatFspOptionLabel(fsp) {
    const safeFsp = String(fsp || '').trim();
    if (!safeFsp) return '-';
    const meta = getFspMeta(safeFsp);
    return meta?.name ? `${safeFsp} - ${meta.name}` : safeFsp;
}

function formatFspMetaLine(fsp) {
    const meta = getFspMeta(fsp);
    if (!meta) return '';
    const parts = [];
    if (meta.name) parts.push(meta.name);
    if (meta.description) parts.push(meta.description);
    return parts.join(' • ');
}

async function refreshSelectedOltMeta() {
    const currentId = String(selectedOltId.value || '').trim();
    if (!currentId) return;

    try {
        const data = await fetchJson(`${API_BASE}/olts/${currentId}`);
        const olt = data?.data;
        if (!olt) return;

        const nextList = Array.isArray(olts.value) ? olts.value.slice() : [];
        const idx = nextList.findIndex((item) => String(item?.id || '') === currentId);
        if (idx >= 0) {
            nextList[idx] = {
                ...nextList[idx],
                write_config_pending: !!olt.write_config_pending,
                write_config_pending_at: olt.write_config_pending_at || null,
            };
            olts.value = nextList;
        }
    } catch {
        // keep current badge state on transient errors
    }
}

function stopOltMetaPolling() {
    if (oltMetaPollTimer) {
        clearInterval(oltMetaPollTimer);
        oltMetaPollTimer = null;
    }
}

function startOltMetaPolling() {
    stopOltMetaPolling();
    if (!selectedOltId.value) return;

    oltMetaPollTimer = setInterval(() => {
        refreshSelectedOltMeta().catch(() => {});
    }, OLT_META_POLL_MS);
}

// ========== OLT Modal ==========
const showOltModal = ref(false);
const editingOltId = ref(null);
const formData = ref({
    nama_olt: '',
    host: '',
    port: 23,
    username: '',
    password: '',
    tcont_default: 'pppoe',
    vlan_default: 200,
    onu_type_default: 'ALL-ONT',
    service_port_id_default: 1,
    teknisi_onu_rx_max_dbm: formatRxInputValue(DEFAULT_TEKNISI_ONU_RX_MAX_DBM, DEFAULT_TEKNISI_ONU_RX_MAX_DBM),
    teknisi_onu_rx_min_dbm: formatRxInputValue(DEFAULT_TEKNISI_ONU_RX_MIN_DBM, DEFAULT_TEKNISI_ONU_RX_MIN_DBM),
});

// ========== Deep Sync Modal (Native Parity) ==========
const syncOpen = ref(false);
const syncText = ref('Menyiapkan proses...');
const syncNote = ref('Mohon tunggu, jangan menutup halaman.');
const syncEta = ref('Estimasi: menghitung...');
const syncPercent = ref(0);

function showSyncModal(text) {
    syncText.value = String(text || 'Menyiapkan proses...');
    syncNote.value = 'Mohon tunggu, jangan menutup halaman.';
    syncEta.value = 'Estimasi: menghitung...';
    syncPercent.value = 0;
    syncOpen.value = true;
}

function updateSyncModal(text, percent, note, eta) {
    syncText.value = String(text || '');
    syncPercent.value = Math.max(0, Math.min(100, Number(percent || 0)));
    if (note !== undefined) syncNote.value = String(note || '');
    if (eta !== undefined) syncEta.value = String(eta || '');
}

function hideSyncModal() {
    syncOpen.value = false;
}

async function openOltModal(mode) {
    if (mode === 'edit') {
        if (!selectedOltId.value) {
            alert('Pilih OLT dulu.');
            return;
        }

        try {
            const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}`);
            const olt = data?.data;
            if (!olt) throw new Error('OLT tidak ditemukan');

            editingOltId.value = olt.id;
            formData.value = {
                nama_olt: olt.nama_olt || '',
                host: olt.host || '',
                port: olt.port || 23,
                username: olt.username || '',
                password: '',
                tcont_default: olt.tcont_default || 'pppoe',
                vlan_default: Number(olt.vlan_default || 200),
                onu_type_default: olt.onu_type_default || 'ALL-ONT',
                service_port_id_default: Number(olt.service_port_id_default || 1),
                teknisi_onu_rx_max_dbm: formatRxInputValue(
                    olt.teknisi_onu_rx_max_dbm,
                    DEFAULT_TEKNISI_ONU_RX_MAX_DBM
                ),
                teknisi_onu_rx_min_dbm: formatRxInputValue(
                    olt.teknisi_onu_rx_min_dbm,
                    DEFAULT_TEKNISI_ONU_RX_MIN_DBM
                ),
            };

            showOltModal.value = true;
        } catch (e) {
            alert(`Error: ${e.message}`);
        }
        return;
    }

    // add
    editingOltId.value = null;
    formData.value = {
        nama_olt: '',
        host: '',
        port: 23,
        username: '',
        password: '',
        tcont_default: 'pppoe',
        vlan_default: 200,
        onu_type_default: 'ALL-ONT',
        service_port_id_default: 1,
        teknisi_onu_rx_max_dbm: formatRxInputValue(DEFAULT_TEKNISI_ONU_RX_MAX_DBM, DEFAULT_TEKNISI_ONU_RX_MAX_DBM),
        teknisi_onu_rx_min_dbm: formatRxInputValue(DEFAULT_TEKNISI_ONU_RX_MIN_DBM, DEFAULT_TEKNISI_ONU_RX_MIN_DBM),
    };
    showOltModal.value = true;
}

async function saveOlt() {
    const onuRxBounds = normalizeOnuRxBounds(formData.value.teknisi_onu_rx_max_dbm, formData.value.teknisi_onu_rx_min_dbm);
    const payload = {
        nama_olt: String(formData.value.nama_olt || '').trim(),
        host: String(formData.value.host || '').trim(),
        port: Number(formData.value.port || 23),
        username: String(formData.value.username || '').trim(),
        password: String(formData.value.password || ''),
        tcont_default: String(formData.value.tcont_default || '').trim(),
        vlan_default: Number(formData.value.vlan_default || 0),
        onu_type_default: String(formData.value.onu_type_default || '').trim(),
        service_port_id_default: Number(formData.value.service_port_id_default || 0),
        teknisi_onu_rx_max_dbm: onuRxBounds.max,
        teknisi_onu_rx_min_dbm: onuRxBounds.min,
    };

    if (!payload.nama_olt || !payload.host || !payload.username) {
        alert('Nama OLT, Host, dan Username wajib diisi.');
        return;
    }

    try {
        let createdId = null;
        if (editingOltId.value) {
            const data = await fetchJson(`${API_BASE}/olts/${editingOltId.value}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (data.status !== 'ok') throw new Error(data.message || 'Gagal menyimpan');
        } else {
            if (!payload.password) {
                alert('Password wajib diisi untuk OLT baru.');
                return;
            }

            const data = await fetchJson(`${API_BASE}/olts`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (data.status !== 'ok') throw new Error(data.message || 'Gagal menyimpan');
            createdId = data?.data?.id ? Number(data.data.id) : null;
        }

        showOltModal.value = false;
        await loadOlts();

        if (createdId) {
            selectedOltId.value = String(createdId);
            const doSync = confirm(
                'Muat data ONU terdaftar?\n\nSistem akan mengambil semua ONU registered dan menyimpan ke database.'
            );
            if (doSync) {
                await syncRegisteredAll(createdId);
            }
        } else {
            await loadFspList();
        }
    } catch (e) {
        alert(`Error: ${e.message}`);
    }
}

async function syncRegisteredAll(oltId, opts = {}) {
    const id = parseInt(String(oltId || '0'), 10) || 0;
    if (!id) {
        alert('OLT tidak valid untuk sync.');
        return;
    }

    showSyncModal('Menyiapkan daftar FSP...');
    const startTime = Date.now();
    const formatEta = (processed, total) => {
        if (!processed || !total || total <= processed) return 'Estimasi: menghitung...';
        const elapsed = (Date.now() - startTime) / 1000;
        if (elapsed <= 1) return 'Estimasi: menghitung...';
        const rate = processed / elapsed;
        if (rate <= 0) return 'Estimasi: menghitung...';
        const remaining = Math.max(0, Math.round((total - processed) / rate));
        if (!remaining) return 'Estimasi: < 1 menit';
        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        return `Estimasi: ${minutes > 0 ? `${minutes}m ` : ''}${seconds}s`;
    };

    try {
        const listRes = await fetchJson(`${API_BASE}/olts/${id}/fsp`);
        const fspList = listRes.status === 'ok' && Array.isArray(listRes.data) ? listRes.data.filter(Boolean) : [];
        if (!fspList.length) {
            updateSyncModal('Tidak ada FSP yang ditemukan.', 100, 'Tidak ada data untuk disinkronkan.', 'Estimasi: -');
            setRegStatus('Tidak ada FSP untuk disinkronkan.', 'error');
            return;
        }

        const totalFspCount = fspList.length;
        let processedAll = 0;
        let totalKnownAll = 0;

        for (let i = 0; i < fspList.length; i += 1) {
            const fsp = fspList[i];
            let offset = 0;
            let totalFsp = null;
            let processedFsp = 0;
            let done = false;

            while (!done) {
                const baseMsg = `Sync FSP ${fsp} (${i + 1}/${fspList.length})`;
                const fspPercent = totalFsp ? Math.min(100, Math.round((processedFsp / totalFsp) * 100)) : 0;
                const overallPercent = Math.min(99, Math.round(((i + fspPercent / 100) / totalFspCount) * 100));

                updateSyncModal(
                    `${baseMsg}...`,
                    overallPercent,
                    totalFsp
                        ? `FSP ${i + 1}/${totalFspCount} | ${processedFsp}/${totalFsp} ONU`
                        : `FSP ${i + 1}/${totalFspCount} | Menghitung total ONU...`,
                    formatEta(processedAll, totalKnownAll)
                );

                const res = await fetchJson(`${API_BASE}/olts/${id}/sync-onu-names`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        fsp,
                        offset,
                        limit: 20,
                        only_missing: false,
                    }),
                });

                if (res.status !== 'ok') throw new Error(res.message || 'Gagal sync ONU');

                if (totalFsp === null && typeof res.total === 'number') {
                    totalFsp = res.total;
                    totalKnownAll += totalFsp;
                }

                processedAll += res.processed || 0;
                if (totalFsp) {
                    processedFsp = Math.min(totalFsp, (res.offset || 0) + (res.processed || 0));
                }

                done = !!res.done;
                offset = res.next_offset || 0;
            }
        }

        updateSyncModal('Sinkronisasi selesai.', 100, `Total ONU: ${processedAll}`, 'Estimasi: selesai');
        setRegStatus(`Deep sync selesai. Total ONU: ${processedAll}.`, 'success');

        if (String(selectedOltId.value || '') === String(id)) {
            // Refresh list on current page (cache-first UX).
            loadFspList().catch(() => {});
        }
    } catch (e) {
        setRegStatus(e.message || 'Gagal memuat data ONU', 'error');
    } finally {
        hideSyncModal();
    }
}

async function deleteOlt() {
    if (!editingOltId.value) return;
    if (!confirm('Hapus profil OLT ini? Semua data ONU cache akan ikut terhapus.')) return;

    try {
        const data = await fetchJson(`${API_BASE}/olts/${editingOltId.value}`, { method: 'DELETE' });
        if (data.status !== 'ok') throw new Error(data.message || 'Gagal menghapus');

        if (String(selectedOltId.value) === String(editingOltId.value)) {
            selectedOltId.value = '';
        }

        showOltModal.value = false;
        await loadOlts();
    } catch (e) {
        alert(`Error: ${e.message}`);
    }
}

// ========== FSP ==========
const fspList = ref([]);
const fspLoading = ref(false);

async function loadFspList() {
    if (!selectedOltId.value) {
        fspList.value = [];
        return;
    }
    fspLoading.value = true;
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/fsp`);
        const list = data.status === 'ok' ? data.data : [];
        fspList.value = sortFspList(list);
        syncSelectedOltFspContext({
            fspCache: fspList.value,
            fspMetadata: Array.isArray(data?.fsp_metadata) ? data.fsp_metadata : null,
        });
    } catch (e) {
        fspList.value = [];
    } finally {
        fspLoading.value = false;
    }
}

// ========== Unregistered ==========
const uncfg = ref([]);
const uncfgLoading = ref(false);
const uncfgSelectedIndex = ref(null);
const uncfgSelected = computed(() => {
    if (uncfgSelectedIndex.value === null) return null;
    return uncfg.value[uncfgSelectedIndex.value] || null;
});
const registerName = ref('');
const registerBusy = ref(false);
const manualRegisterActive = ref(false);
const manualRegisterModalOpen = ref(false);
const manualRegisterResultOpen = ref(false);
const manualRegisterResultTone = ref('error');
const manualRegisterResultTitle = ref('');
const manualRegisterResultMessage = ref('');
const manualRegisterResultOnuRx = ref(null);
const manualRegisterSlotSummaryLoading = ref(false);
const manualRegisterSlotSummaryError = ref('');
const manualRegisterSlotSummary = ref(null);
const portSlotModalOpen = ref(false);
const portSlotSummaryLoading = ref(false);
const portSlotSummaryError = ref('');
const portSlotSummary = ref([]);
const portSlotSaveBusy = ref(false);
const portSlotSaveError = ref('');
const portSlotSaveMessage = ref('');
const portSlotTotals = ref({
    total_ports: 0,
    total_used_slots: 0,
    total_empty_slots: 0,
});
const registerProgressText = ref('Registrasi berjalan...');
const uncfgStatus = ref({ tone: 'info', message: '' });
const autoRegisterRunId = ref(null);
const autoRegisterSetupOpen = ref(false);
const autoRegisterNamePrefix = ref('');
const autoRegisterModalOpen = ref(false);
const autoRegisterModalTone = ref('loading');
const autoRegisterModalClosable = ref(false);
const autoRegisterModalText = ref('Menyiapkan auto register...');
const autoRegisterModalNote = ref('Mohon tunggu, proses berjalan di queue worker.');
const autoRegisterModalEta = ref('Estimasi: menunggu queue worker...');
const autoRegisterModalPercent = ref(0);
const autoRegisterRealtimeEnabled = ref(false);
const autoRegisterRealtimeSubscribed = ref(false);
let autoRegisterPollTimer = null;
let autoRegisterEchoInitPromise = null;
let autoRegisterRealtimeChannelName = '';

// Live status (native parity): rotate command lines while actions run.
let statusTimer = null;
let statusLines = [];
let statusIndex = 0;
let statusBase = '';
let statusTarget = 'uncfg';

function stopLiveStatus() {
    if (statusTimer) {
        clearInterval(statusTimer);
        statusTimer = null;
    }
}

function updateStatusText(text) {
    const msg = String(text || '');
    if (statusTarget === 'reg') {
        regStatus.value = { ...regStatus.value, message: msg };
        return;
    }

    uncfgStatus.value = { ...uncfgStatus.value, message: msg };
    if (manualRegisterActive.value) {
        if (msg) registerProgressText.value = msg;
        return;
    }
}

function startLiveStatus(base, lines, target = 'uncfg') {
    const safeLines = Array.isArray(lines) ? lines.filter(Boolean) : [];
    if (!safeLines.length) {
        if (target === 'reg') setRegStatus(base, 'loading');
        else setUncfgStatus(base, 'loading');
        return;
    }

    statusLines = safeLines;
    statusIndex = 0;
    statusBase = String(base || '');
    statusTarget = target === 'reg' ? 'reg' : 'uncfg';
    stopLiveStatus();

    const first = `${statusBase} | ${statusLines[0]}`;
    if (statusTarget === 'reg') setRegStatus(first, 'loading');
    else setUncfgStatus(first, 'loading');

    statusTimer = setInterval(() => {
        statusIndex = (statusIndex + 1) % statusLines.length;
        updateStatusText(`${statusBase} | ${statusLines[statusIndex]}`);
    }, 900);
}

function setUncfgStatus(message, tone = 'info') {
    if (tone !== 'loading') stopLiveStatus();
    statusTarget = 'uncfg';
    uncfgStatus.value = { tone, message: String(message || '') };
    if (manualRegisterActive.value) {
        if (uncfgStatus.value.message) registerProgressText.value = uncfgStatus.value.message;
        return;
    }
}

function clearUncfgSelection() {
    uncfgSelectedIndex.value = null;
    registerName.value = '';
    manualRegisterModalOpen.value = false;
    manualRegisterSlotSummaryLoading.value = false;
    manualRegisterSlotSummaryError.value = '';
    manualRegisterSlotSummary.value = null;
}

function showManualRegisterResult(title, message, tone = 'error', onuRx = null) {
    manualRegisterResultTitle.value = String(title || 'Registrasi gagal');
    manualRegisterResultMessage.value = String(message || '');
    manualRegisterResultTone.value = tone === 'success' ? 'success' : 'error';
    manualRegisterResultOnuRx.value = extractRxValue(onuRx);
    manualRegisterResultOpen.value = true;
}

function closeManualRegisterResult() {
    manualRegisterResultOpen.value = false;
    manualRegisterResultOnuRx.value = null;
}

function selectUncfg(idx) {
    if (!canManualRegister.value) return;
    if (idx < 0 || idx >= uncfg.value.length) return;
    uncfgSelectedIndex.value = idx;
    registerName.value = '';
    manualRegisterModalOpen.value = true;
    loadManualRegisterSlotSummary().catch(() => {});
}

function hideManualRegisterModal() {
    if (registerBusy.value || manualRegisterActive.value) return;
    manualRegisterModalOpen.value = false;
}

function openPortSlotModal() {
    if (!selectedOltId.value) return;
    portSlotModalOpen.value = true;
    portSlotSaveError.value = '';
    portSlotSaveMessage.value = '';
    loadPortSlotSummary().catch(() => {});
}

function closePortSlotModal() {
    if (portSlotSummaryLoading.value || portSlotSaveBusy.value) return;
    portSlotModalOpen.value = false;
}

async function loadPortSlotSummary() {
    if (!selectedOltId.value || !portSlotModalOpen.value) {
        portSlotSummaryLoading.value = false;
        return;
    }

    portSlotSummaryLoading.value = true;
    portSlotSummaryError.value = '';
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/port-slot-summary`);
        const payload = data?.data && typeof data.data === 'object' ? data.data : {};
        portSlotSummary.value = Array.isArray(payload.items) ? payload.items : [];
        portSlotTotals.value = {
            total_ports: Number(payload.total_ports || 0),
            total_used_slots: Number(payload.total_used_slots || 0),
            total_empty_slots: Number(payload.total_empty_slots || 0),
        };
    } catch (e) {
        portSlotSummaryError.value = e.message || 'Ringkasan slot port tidak tersedia.';
        portSlotSummary.value = [];
        portSlotTotals.value = {
            total_ports: 0,
            total_used_slots: 0,
            total_empty_slots: 0,
        };
    } finally {
        portSlotSummaryLoading.value = false;
    }
}

async function savePortSlotMetadata() {
    if (!selectedOltId.value || portSlotSaveBusy.value || !canManageOltProfile.value) return;

    portSlotSaveBusy.value = true;
    portSlotSaveError.value = '';
    portSlotSaveMessage.value = '';

    try {
        const items = Array.isArray(portSlotSummary.value)
            ? portSlotSummary.value.map((item) => ({
                fsp: String(item?.fsp || '').trim(),
                name: String(item?.name || '').trim(),
                description: String(item?.description || '').trim(),
            }))
            : [];

        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/fsp-metadata`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items }),
        });

        const metadata = Array.isArray(data?.data?.fsp_metadata) ? data.data.fsp_metadata : [];
        const metaMap = buildFspMetadataMap(metadata);

        portSlotSummary.value = (Array.isArray(portSlotSummary.value) ? portSlotSummary.value : []).map((item) => {
            const meta = metaMap[String(item?.fsp || '').trim()] || null;
            return {
                ...item,
                name: meta?.name || '',
                description: meta?.description || '',
            };
        });

        if (manualRegisterSlotSummary.value?.fsp) {
            const currentFsp = String(manualRegisterSlotSummary.value.fsp || '').trim();
            const currentMeta = metaMap[currentFsp] || null;
            manualRegisterSlotSummary.value = {
                ...manualRegisterSlotSummary.value,
                name: currentMeta?.name || '',
                description: currentMeta?.description || '',
            };
        }

        syncSelectedOltFspContext({ fspMetadata: metadata });
        portSlotSaveMessage.value = data?.message || 'Metadata port berhasil disimpan.';
    } catch (e) {
        portSlotSaveError.value = e.message || 'Metadata port gagal disimpan.';
    } finally {
        portSlotSaveBusy.value = false;
    }
}

async function loadManualRegisterSlotSummary() {
    const item = uncfgSelected.value;
    if (!manualRegisterModalOpen.value || !item || !selectedOltId.value) {
        manualRegisterSlotSummaryLoading.value = false;
        manualRegisterSlotSummaryError.value = '';
        manualRegisterSlotSummary.value = null;
        return;
    }

    manualRegisterSlotSummaryLoading.value = true;
    manualRegisterSlotSummaryError.value = '';

    try {
        const params = new URLSearchParams({ fsp: String(item.fsp || '') });
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/register-slot-summary?${params}`);
        manualRegisterSlotSummary.value = data?.data && typeof data.data === 'object' ? data.data : null;
    } catch (e) {
        manualRegisterSlotSummaryError.value = e.message || 'Slot interface tidak tersedia.';
        manualRegisterSlotSummary.value = null;
    } finally {
        manualRegisterSlotSummaryLoading.value = false;
    }
}

function removeUncfgItemsByKeys(keys) {
    const normalizedKeys = Array.isArray(keys)
        ? keys.map(key => String(key || '').trim().toUpperCase()).filter(Boolean)
        : [];
    if (!normalizedKeys.length) return;

    const keySet = new Set(normalizedKeys);
    const selectedKey = uncfgSelected.value ? getUncfgItemKey(uncfgSelected.value).toUpperCase() : '';

    uncfg.value = uncfg.value.filter(item => !keySet.has(getUncfgItemKey(item).toUpperCase()));

    if (selectedKey && keySet.has(selectedKey)) {
        clearUncfgSelection();
    } else if (selectedKey) {
        const nextIndex = uncfg.value.findIndex(item => getUncfgItemKey(item).toUpperCase() === selectedKey);
        uncfgSelectedIndex.value = nextIndex >= 0 ? nextIndex : null;
    }

    storeUncfgCache(selectedOltId.value);
}

function buildCurrentUncfgItems() {
    return Array.isArray(uncfg.value)
        ? uncfg.value
            .map(item => ({
                fsp: String(item?.fsp || '').trim(),
                sn: String(item?.sn || '').trim(),
            }))
            .filter(item => item.fsp && item.sn)
        : [];
}

const autoRegisterSetupCount = computed(() => buildCurrentUncfgItems().length);
const autoRegisterSetupBatches = computed(() => Math.ceil(autoRegisterSetupCount.value / AUTO_REGISTER_BATCH_SIZE) || 0);

function openAutoRegisterSetupModal() {
    autoRegisterNamePrefix.value = '';
    autoRegisterSetupOpen.value = true;
}

function hideAutoRegisterSetupModal() {
    autoRegisterSetupOpen.value = false;
}

function getAutoRegisterModalToneClasses(tone) {
    const map = {
        loading: {
            iconWrap: 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300',
            badge: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
            bar: 'bg-[linear-gradient(90deg,#2563eb_0%,#0ea5e9_55%,#10b981_100%)]',
        },
        success: {
            iconWrap: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300',
            badge: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
            bar: 'bg-[linear-gradient(90deg,#10b981_0%,#22c55e_100%)]',
        },
        info: {
            iconWrap: 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300',
            badge: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
            bar: 'bg-[linear-gradient(90deg,#f59e0b_0%,#f97316_100%)]',
        },
        error: {
            iconWrap: 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300',
            badge: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
            bar: 'bg-[linear-gradient(90deg,#ef4444_0%,#f97316_100%)]',
        },
    };
    return map[tone] || map.loading;
}

function showAutoRegisterModal(text, note = 'Mohon tunggu, proses berjalan di queue worker.', percent = 0, eta = 'Estimasi: menunggu queue worker...', tone = 'loading', closable = false) {
    autoRegisterModalText.value = String(text || 'Menyiapkan auto register...');
    autoRegisterModalNote.value = String(note || '');
    autoRegisterModalEta.value = String(eta || '');
    autoRegisterModalPercent.value = Math.max(0, Math.min(100, Number(percent || 0)));
    autoRegisterModalTone.value = String(tone || 'loading');
    autoRegisterModalClosable.value = !!closable;
    autoRegisterModalOpen.value = true;
}

function updateAutoRegisterModal(text, percent, note, eta, tone = autoRegisterModalTone.value, closable = autoRegisterModalClosable.value) {
    autoRegisterModalText.value = String(text || autoRegisterModalText.value || 'Auto register berjalan...');
    autoRegisterModalPercent.value = Math.max(0, Math.min(100, Number(percent || 0)));
    if (note !== undefined) autoRegisterModalNote.value = String(note || '');
    if (eta !== undefined) autoRegisterModalEta.value = String(eta || '');
    autoRegisterModalTone.value = String(tone || 'loading');
    autoRegisterModalClosable.value = !!closable;
    autoRegisterModalOpen.value = true;
}

function hideAutoRegisterModal() {
    autoRegisterModalOpen.value = false;
    autoRegisterModalClosable.value = false;
    autoRegisterModalTone.value = 'loading';
}

function completeAutoRegisterModal(text, note, percent = 100, eta = 'Selesai', tone = 'success') {
    updateAutoRegisterModal(text, percent, note, eta, tone, true);
}

function buildAutoRegisterRealtimeChannelName(runId = autoRegisterRunId.value) {
    const tenantId = Number(page.props.auth?.user?.tenant_id || 0);
    const oltId = Number(selectedOltId.value || 0);
    const safeRunId = Number(runId || 0);

    if (tenantId <= 0 || oltId <= 0 || safeRunId <= 0) {
        return '';
    }

    return `tenants.${tenantId}.olts.${oltId}.auto-register.${safeRunId}`;
}

async function ensureAutoRegisterEcho() {
    if (window.Echo) {
        autoRegisterRealtimeEnabled.value = true;
        return window.Echo;
    }

    if (!autoRegisterEchoInitPromise) {
        autoRegisterEchoInitPromise = import('@/echo.js')
            .then(() => window.Echo || null)
            .catch(() => null);
    }

    const echo = await autoRegisterEchoInitPromise;
    autoRegisterRealtimeEnabled.value = !!echo;
    return echo;
}

function stopAutoRegisterRealtime() {
    if (autoRegisterRealtimeChannelName && window.Echo?.leave) {
        try {
            window.Echo.leave(autoRegisterRealtimeChannelName);
        } catch {
            // ignore websocket cleanup errors
        }
    }

    autoRegisterRealtimeChannelName = '';
    autoRegisterRealtimeSubscribed.value = false;
}

async function startAutoRegisterRealtime(runId) {
    const channelName = buildAutoRegisterRealtimeChannelName(runId);
    if (!channelName) {
        stopAutoRegisterRealtime();
        return false;
    }

    if (autoRegisterRealtimeChannelName === channelName && autoRegisterRealtimeSubscribed.value) {
        return true;
    }

    stopAutoRegisterRealtime();

    const echo = await ensureAutoRegisterEcho();
    if (!echo?.private) {
        autoRegisterRealtimeEnabled.value = false;
        return false;
    }

    try {
        echo.private(channelName).listen('.olt.auto-register.progress.updated', (payload) => {
            autoRegisterRealtimeSubscribed.value = true;
            handleAutoRegisterPayload(payload || {}).catch(() => {});
        });

        autoRegisterRealtimeChannelName = channelName;
        autoRegisterRealtimeSubscribed.value = true;
        autoRegisterRealtimeEnabled.value = true;
        return true;
    } catch {
        autoRegisterRealtimeSubscribed.value = false;
        return false;
    }
}

function stopAutoRegisterPolling(resetRun = true) {
    if (autoRegisterPollTimer) {
        clearTimeout(autoRegisterPollTimer);
        autoRegisterPollTimer = null;
    }
    if (resetRun) {
        autoRegisterRunId.value = null;
    }
}

function scheduleAutoRegisterPoll(runId, delay = 3000) {
    stopAutoRegisterPolling(false);
    autoRegisterPollTimer = setTimeout(() => {
        pollAutoRegisterStatus(runId).catch(() => {});
    }, delay);
}

async function finishAutoRegisterRun(status, summary = {}) {
    stopAutoRegisterPolling();
    stopAutoRegisterRealtime();
    registerBusy.value = false;

    const errorCount = Number(summary.error || 0);
    const successCount = Number(summary.success || 0);
    let message = String(summary.state_text || summary.message || '').trim();
    if (!message) {
        if (status === 'error') {
            message = 'Auto register gagal.';
        } else if (errorCount > 0) {
            message = `Auto register selesai dengan error ${errorCount} ONU.`;
        } else {
            message = `Auto register selesai. Success ${Number(summary.success || 0)}, error ${errorCount}.`;
        }
    }

    try {
        await scanUncfg();
    } catch {
        // ignore refresh scan errors
    }

    if (successCount > 0) {
        setSelectedOltConfigPending(true);
    }

    completeAutoRegisterModal(
        message,
        status === 'error'
            ? 'Proses auto register berhenti dengan error. Periksa log command untuk detail.'
            : errorCount > 0
                ? `Proses selesai dengan ${errorCount} ONU gagal. Periksa log command untuk detail. Data ONU Registered tidak di-refresh otomatis.`
                : `Semua batch selesai diproses. Success ${successCount}, error ${errorCount}. Data ONU Registered tidak di-refresh otomatis.`,
        100,
        'Selesai',
        status === 'error' ? 'error' : errorCount > 0 ? 'info' : 'success'
    );
    setUncfgStatus(message, status === 'error' ? 'error' : errorCount > 0 ? 'info' : 'success');
    loadLogs().catch(() => {});
}

async function handleAutoRegisterPayload(payload) {
    const runId = Number(payload?.run_id || autoRegisterRunId.value || 0);
    if (!runId) return;

    autoRegisterRunId.value = runId;

    const summary = payload?.summary && typeof payload.summary === 'object' ? payload.summary : {};
    const status = String(payload?.status || '');

    if (payload?.log_text) {
        lastLogExcerpt.value = String(payload.log_text);
    }

    if (Array.isArray(summary.success_keys)) {
        removeUncfgItemsByKeys(summary.success_keys);
    }

    if (payload?.is_finished) {
        await finishAutoRegisterRun(status, summary);
        return;
    }

    const processedCount = Number(summary.processed_count || 0);
    const processedBatches = Number(summary.processed_batches || 0);
    const totalBatches = Number(summary.total_batches || 0);
    const currentBatch = Number(summary.current_batch || 0);
    const totalCount = Number(summary.total_count || 0);
    const remainingCount = Number(summary.remaining_count || 0);
    const successCount = Number(summary.success || 0);
    const errorCount = Number(summary.error || 0);
    const stateText = String(summary.state_text || '').trim();
    const progressText = stateText || `Auto register berjalan. Batch ${processedBatches}/${totalBatches}, sisa ${remainingCount} ONU.`;
    const percent = totalCount > 0 ? (processedCount / totalCount) * 100 : 0;

    updateAutoRegisterModal(
        progressText,
        percent,
        `Batch ${Math.max(currentBatch, processedBatches)}/${Math.max(totalBatches, 1)} | Success ${successCount} | Error ${errorCount}`,
        remainingCount > 0 ? `Sisa ${remainingCount} ONU untuk diproses.` : 'Menyelesaikan proses...'
    );
    setUncfgStatus(`${progressText} Success ${successCount}, error ${errorCount}.`, 'loading');

    scheduleAutoRegisterPoll(runId, autoRegisterRealtimeSubscribed.value ? 10000 : 3000);
}

async function pollAutoRegisterStatus(runId) {
    if (!selectedOltId.value || !runId) {
        stopAutoRegisterPolling();
        registerBusy.value = false;
        return;
    }

    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/auto-register-status?run_id=${encodeURIComponent(runId)}`);
        await handleAutoRegisterPayload(data?.data || {});
    } catch (e) {
        if ([403, 404, 422].includes(Number(e?.status || 0))) {
            stopAutoRegisterPolling();
            stopAutoRegisterRealtime();
            registerBusy.value = false;
            completeAutoRegisterModal(
                e.message || 'Auto register gagal dipantau.',
                'Polling status berhenti karena proses tidak bisa dilanjutkan.',
                autoRegisterModalPercent.value || 100,
                'Berhenti',
                'error'
            );
            setUncfgStatus(e.message || 'Auto register gagal dipantau.', 'error');
            return;
        }
        updateAutoRegisterModal(
            'Koneksi status terputus sementara.',
            autoRegisterModalPercent.value,
            'Frontend akan mencoba sinkron ulang otomatis.',
            'Estimasi: mencoba lagi dalam beberapa detik...',
            'loading',
            false
        );
        setUncfgStatus(e.message || 'Gagal memantau auto register. Mencoba lagi...', 'loading');
        scheduleAutoRegisterPoll(runId, autoRegisterRealtimeSubscribed.value ? 10000 : 5000);
    }
}

function storeUncfgCache(oltId) {
    if (!canManualRegister.value) return;
    const key = String(oltId || '').trim();
    if (!key) return;
    memoryUncfgCache[key] = {
        list: Array.isArray(uncfg.value) ? uncfg.value.slice() : [],
        selectedIndex: typeof uncfgSelectedIndex.value === 'number' ? uncfgSelectedIndex.value : null,
    };
}

function loadUncfgCache(oltId) {
    if (!canManualRegister.value) return false;
    const key = String(oltId || '').trim();
    if (!key) return false;
    const cached = memoryUncfgCache[key];
    if (!cached || !Array.isArray(cached.list)) return false;
    uncfg.value = cached.list.slice();
    uncfgSelectedIndex.value = typeof cached.selectedIndex === 'number' ? cached.selectedIndex : null;
    registerName.value = '';
    return true;
}

async function scanUncfg() {
    if (!selectedOltId.value) {
        setUncfgStatus('Pilih OLT dulu.', 'error');
        return;
    }

    uncfgLoading.value = true;
    startLiveStatus('Sedang scan ONU', buildScanLiveLines(), 'uncfg');
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/scan-uncfg`);
        uncfg.value = data.status === 'ok' ? (Array.isArray(data.data) ? data.data : []) : [];
        clearUncfgSelection();
        storeUncfgCache(selectedOltId.value);
        if (uncfg.value.length > 0) {
            setUncfgStatus(`Scan selesai. Ditemukan ${uncfg.value.length} ONU.`, 'success');
        } else {
            setUncfgStatus('Tidak ada onu yang perlu diregistrasi.', 'info');
        }
    } catch (e) {
        setUncfgStatus(e.message || 'Gagal scan ONU.', 'error');
    } finally {
        uncfgLoading.value = false;
    }
}

const writeConfigConfirmOpen = ref(false);
const writeConfigFromTeknisi = ref(false);
const writeConfigBusy = ref(false);

function requestWriteConfig(fromTeknisi = false) {
    if (writeConfigBusy.value) {
        return;
    }
    if (!selectedOltId.value) {
        setUncfgStatus('Pilih OLT dulu.', 'error');
        return;
    }
    if (!selectedOltWriteConfigPending.value) {
        setUncfgStatus('Tidak ada data yang harus disimpan.', 'info');
        return;
    }

    writeConfigFromTeknisi.value = !!fromTeknisi;
    writeConfigConfirmOpen.value = true;
}

function hideWriteConfigConfirm() {
    if (writeConfigBusy.value) return;
    writeConfigConfirmOpen.value = false;
}

async function confirmWriteConfig() {
    if (writeConfigBusy.value) return;
    writeConfigBusy.value = true;
    writeConfigConfirmOpen.value = false;

    const ok = await writeConfigInternal();
    if (ok) {
        setSelectedOltConfigPending(false, null);
    }
    writeConfigFromTeknisi.value = false;
    writeConfigBusy.value = false;
}

async function writeConfigInternal() {
    if (!selectedOltId.value) {
        setUncfgStatus('Pilih OLT dulu.', 'error');
        return false;
    }

    setUncfgStatus('Menulis konfigurasi (write)...', 'loading');
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/write-config`, { method: 'POST' });
        if (data.status !== 'ok') throw new Error(data.message || 'Write gagal');
        if (data?.log_excerpt) lastLogExcerpt.value = String(data.log_excerpt);
        setUncfgStatus(data.message || 'Write selesai.', 'success');
        return true;
    } catch (e) {
        if (e?.data?.log_excerpt) lastLogExcerpt.value = String(e.data.log_excerpt);
        setUncfgStatus(e.message || 'Write gagal', 'error');
        return false;
    } finally {
        loadLogs().catch(() => {});
    }
}

async function writeConfigTeknisi() {
    if (!isTeknisi.value) return;
    requestWriteConfig(true);
}

async function autoRegister() {
    if (!selectedOltId.value) {
        setUncfgStatus('Pilih OLT dulu.', 'error');
        return;
    }
    const currentItems = buildCurrentUncfgItems();
    if (!currentItems.length) {
        setUncfgStatus('Tidak ada hasil scan ONU untuk diproses.', 'info');
        return;
    }
    openAutoRegisterSetupModal();
}

async function submitAutoRegister() {
    const currentItems = buildCurrentUncfgItems();
    if (!selectedOltId.value) {
        setUncfgStatus('Pilih OLT dulu.', 'error');
        hideAutoRegisterSetupModal();
        return;
    }
    if (!currentItems.length) {
        setUncfgStatus('Tidak ada hasil scan ONU untuk diproses.', 'info');
        hideAutoRegisterSetupModal();
        return;
    }

    const prefix = String(autoRegisterNamePrefix.value || '').trim().slice(0, 16);
    hideAutoRegisterSetupModal();
    registerBusy.value = true;
    stopAutoRegisterPolling();
    stopAutoRegisterRealtime();
    try {
        showAutoRegisterModal(
            `Mengantrikan auto register per ${AUTO_REGISTER_BATCH_SIZE} ONU...`,
            'Daftar ONU unregistered sedang dipersiapkan untuk queue worker.',
            2,
            'Estimasi: menunggu job pertama diproses...',
            'loading',
            false
        );
        setUncfgStatus(`Mengantrikan auto register per ${AUTO_REGISTER_BATCH_SIZE} ONU...`, 'loading');
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/auto-register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name_prefix: prefix,
                items: currentItems,
            }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'Auto register gagal');
        if (data?.log_excerpt) lastLogExcerpt.value = String(data.log_excerpt);

        const summary = data.summary && typeof data.summary === 'object' ? data.summary : {};
        const runId = Number(data.run_id || data.log_id || 0);
        if (!runId) {
            if (Number(summary.total_count || 0) === 0) {
                completeAutoRegisterModal(
                    data.message || 'Tidak ada onu yang perlu diregistrasi.',
                    'Tidak ada ONU yang perlu diproses pada OLT ini.',
                    100,
                    'Selesai',
                    'info'
                );
                setUncfgStatus(data.message || 'Tidak ada onu yang perlu diregistrasi.', 'info');
                registerBusy.value = false;
                return;
            }
            throw new Error('Run auto register tidak valid.');
        }

        autoRegisterRunId.value = runId;
        await startAutoRegisterRealtime(runId);
        const totalCount = Number(summary.total_count || 0);
        const totalBatches = Number(summary.total_batches || Math.ceil(totalCount / AUTO_REGISTER_BATCH_SIZE));
        updateAutoRegisterModal(
            data.message || `Auto register diantrikan: ${totalCount} ONU dalam ${totalBatches} batch.`,
            4,
            `Queue worker akan memproses ${totalBatches} batch.`,
            totalCount > 0 ? `Total ${totalCount} ONU menunggu diproses.` : 'Estimasi: menunggu queue worker...',
            'loading',
            false
        );
        setUncfgStatus(
            data.message || `Auto register diantrikan: ${totalCount} ONU dalam ${totalBatches} batch.`,
            'loading'
        );
        await pollAutoRegisterStatus(runId);
    } catch (e) {
        if (e?.status === 409 && Number(e?.data?.run_id || 0) > 0) {
            autoRegisterRunId.value = Number(e.data.run_id);
            await startAutoRegisterRealtime(autoRegisterRunId.value);
            showAutoRegisterModal(
                'Menyambungkan ke proses auto register yang sudah berjalan...',
                'Halaman mendeteksi run aktif pada OLT ini.',
                autoRegisterModalPercent.value || 5,
                'Estimasi: memuat progres terbaru...',
                'loading',
                false
            );
            setUncfgStatus(e.message || 'Auto register sedang berjalan. Menyambungkan polling status...', 'loading');
            await pollAutoRegisterStatus(autoRegisterRunId.value);
            return;
        }
        if (e?.data?.log_excerpt) lastLogExcerpt.value = String(e.data.log_excerpt);
        completeAutoRegisterModal(
            e.message || 'Auto register gagal',
            'Proses tidak berhasil dijalankan. Periksa konfigurasi queue worker atau log command.',
            autoRegisterModalPercent.value || 100,
            'Berhenti',
            'error'
        );
        setUncfgStatus(e.message || 'Auto register gagal', 'error');
        registerBusy.value = false;
    } finally {
        loadLogs().catch(() => {});
    }
}

async function registerSelectedOnu() {
    if (!selectedOltId.value) return;
    const item = uncfgSelected.value;
    if (!item) {
        setUncfgStatus('Pilih ONU unregistered dulu.', 'error');
        return;
    }

    const name = String(registerName.value || '').trim();
    if (!name) {
        setUncfgStatus('Nama ONU wajib diisi.', 'error');
        return;
    }

    registerBusy.value = true;
    manualRegisterActive.value = true;
    registerProgressText.value = 'Registrasi berjalan...';
    startLiveStatus('Registrasi berjalan', buildRegisterLiveLines({ fsp: item.fsp, sn: item.sn, name }), 'uncfg');

    try {
        const payload = { fsp: item.fsp, sn: item.sn, name };
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/register-onu`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        if (data.status !== 'ok') throw new Error(data.message || 'Gagal register');
        if (data?.log_excerpt) lastLogExcerpt.value = String(data.log_excerpt);

        // Remove from uncfg list
        uncfg.value = uncfg.value.filter(o => String(o.sn) !== String(item.sn));
        clearUncfgSelection();
        storeUncfgCache(selectedOltId.value);
        const nameApplied = data?.data?.name_applied !== false;
        setUncfgStatus(String(data.message || 'ONU berhasil diregistrasi.'), nameApplied ? 'success' : 'info');
        setSelectedOltConfigPending(true);

        const res = data?.data || {};
        const fsp = String(res.fsp || item.fsp || '');
        const onuId = res.onu_id ? Number(res.onu_id) : 0;
        const sn = String(res.sn || item.sn || '');
        const savedName = String(res.name || name || '');

        if (fsp && !fspList.value.includes(fsp)) {
            fspList.value = sortFspList(fspList.value.concat(fsp));
        }

        if (fsp && onuId > 0) {
            upsertRegisteredAfterRegister({ fsp, onu_id: onuId, sn, name: savedName });
        }

        // Native parity: auto-switch to the FSP where this ONU was registered so it appears instantly.
        if (fsp) {
            regFilterFsp.value = fsp;
            await changeRegFsp();
            if (onuId > 0) setRegHighlight(`gpon-onu_${fsp}:${onuId}`);
        }

        if (fsp && onuId > 0) {
            const key = `gpon-onu_${fsp}:${onuId}`;
            const regItem = registered.value.find(it => onuKey(it) === key) || {
                fsp,
                onu_id: onuId,
                sn,
                name: savedName,
            };
            await toggleRegDetail(regItem, { skipLoad: true });
        }
    } catch (e) {
        if (e?.data?.log_excerpt) lastLogExcerpt.value = String(e.data.log_excerpt);
        setUncfgStatus(e.message || 'Gagal register', 'error');
        showManualRegisterResult(
            'Registrasi gagal',
            e.message || 'Registrasi ONU tidak berhasil.',
            'error',
            e?.data?.data?.onu_rx
        );
    } finally {
        registerBusy.value = false;
        manualRegisterActive.value = false;
        loadLogs().catch(() => {});
    }
}

// ========== Registered ==========
const registered = ref([]);
const regLoading = ref(false);
const regLoadingText = ref('');
const regFilterFsp = ref('');
const regLoadedFsp = ref({});
const regLiveLoadingFsp = ref({});
let regAllRequestToken = 0;
let regSearchLiveRefreshToken = 0;
const REG_SEARCH_LIVE_REFRESH_DELAY_MS = 2200;
const regStatus = ref({ tone: 'info', message: '' });
const regToast = ref({ open: false, tone: 'info', message: '' });
const regSearch = ref('');
const regSearchMode = ref(false);
const regQuickFilter = ref('all');
const regPage = ref(1);
const regPageSize = ref(25);
const regSortKey = ref('interface');
const regSortDir = ref('asc');
const regSelectedKeys = ref([]);
const regBulkActionBusy = ref(false);
const regBulkProgress = ref({
    visible: false,
    tone: 'loading',
    action: '',
    total: 0,
    current: 0,
    success: 0,
    failed: 0,
    currentLabel: '',
    message: '',
    done: false,
});

const regHighlightKey = ref('');
const regExpandedKey = ref('');
const regDetailModalOpen = ref(false);
const regDetailLoadingKey = ref('');
const regDetailRxLoadingKey = ref('');
const regDetailInfoLoadingKey = ref('');
const regDetails = ref({});
const regEditingKey = ref('');
const regEditingName = ref('');
const regEditingError = ref('');
const regNameSavedKey = ref('');
const rowActionKey = ref('');
const rowActionType = ref('');
const regRxHistoryRanges = [
    { value: '24h', label: '24 Jam' },
    { value: '7d', label: '7 Hari' },
    { value: '30d', label: '30 Hari' },
];
const regRxHistoryRange = ref('24h');
const regRxHistoryOpen = ref(false);
const regRxHistoryLoadingKey = ref('');
const regRxHistoryByKey = ref({});
const regRxHistoryErrorByKey = ref({});
const regRxHistoryPage = ref(1);
const regRxHistoryPageSize = ref(10);
const regRxHistoryPageSizeOptions = [10, 20, 50, 100];
let regDetailRefreshToken = 0;
let regNameSavedTimer = null;
let regStatusTimer = null;
let regToastTimer = null;

const regQuickFilterOptions = [
    { value: 'all', label: 'Semua' },
    { value: 'online', label: 'Online' },
    { value: 'offline', label: 'Offline' },
    { value: 'rx_bad', label: 'Rx Jelek' },
];

function sanitizeRxHistoryRange(value) {
    const raw = String(value || '').trim().toLowerCase();
    if (raw === '7d' || raw === '30d') return raw;
    return '24h';
}

function rxHistoryErrorKey(key, range) {
    return `${String(key || '')}::${sanitizeRxHistoryRange(range)}`;
}

function getRxHistoryCacheEntry(key, range = regRxHistoryRange.value) {
    const safeKey = String(key || '').trim();
    if (!safeKey) return null;
    const safeRange = sanitizeRxHistoryRange(range);
    const byRange = regRxHistoryByKey.value?.[safeKey];
    if (!byRange || typeof byRange !== 'object') return null;
    return byRange[safeRange] || null;
}

function setRxHistoryCacheEntry(key, range, payload) {
    const safeKey = String(key || '').trim();
    if (!safeKey) return;
    const safeRange = sanitizeRxHistoryRange(range);
    const nextByKey = { ...(regRxHistoryByKey.value || {}) };
    const byRange = { ...(nextByKey[safeKey] || {}) };
    byRange[safeRange] = payload;
    nextByKey[safeKey] = byRange;
    regRxHistoryByKey.value = nextByKey;
}

function clearRxHistoryCache(key = '') {
    const safeKey = String(key || '').trim();
    if (!safeKey) {
        regRxHistoryByKey.value = {};
        regRxHistoryErrorByKey.value = {};
        regRxHistoryLoadingKey.value = '';
        return;
    }

    const nextCache = { ...(regRxHistoryByKey.value || {}) };
    delete nextCache[safeKey];
    regRxHistoryByKey.value = nextCache;

    const nextErr = { ...(regRxHistoryErrorByKey.value || {}) };
    Object.keys(nextErr).forEach((k) => {
        if (k.startsWith(`${safeKey}::`)) delete nextErr[k];
    });
    regRxHistoryErrorByKey.value = nextErr;

    if (regRxHistoryLoadingKey.value === safeKey) regRxHistoryLoadingKey.value = '';
}

function getOnuRxHistoryRows(onu) {
    const entry = getRxHistoryCacheEntry(onuKey(onu), regRxHistoryRange.value);
    const list = entry?.data;
    return Array.isArray(list) ? list : [];
}

function getOnuRxHistoryMeta(onu) {
    const entry = getRxHistoryCacheEntry(onuKey(onu), regRxHistoryRange.value);
    const meta = entry?.meta;
    return meta && typeof meta === 'object' ? meta : {};
}

function getOnuRxHistoryError(onu) {
    const key = rxHistoryErrorKey(onuKey(onu), regRxHistoryRange.value);
    return String(regRxHistoryErrorByKey.value?.[key] || '');
}

function isOnuRxHistoryLoading(onu) {
    return regRxHistoryLoadingKey.value === onuKey(onu);
}

function isOnuRxHistoryRangeActive(range) {
    return sanitizeRxHistoryRange(range) === sanitizeRxHistoryRange(regRxHistoryRange.value);
}

function rxHistoryRangeLabel(value) {
    const safe = sanitizeRxHistoryRange(value);
    const found = regRxHistoryRanges.find((it) => it.value === safe);
    return found?.label || '24 Jam';
}

async function loadOnuRxHistory(onu, { range = '24h', force = false, silent = false } = {}) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    const safeRange = sanitizeRxHistoryRange(range);
    const cached = getRxHistoryCacheEntry(key, safeRange);
    if (!force && cached) return;

    regRxHistoryLoadingKey.value = key;
    const errKey = rxHistoryErrorKey(key, safeRange);
    const nextErr = { ...(regRxHistoryErrorByKey.value || {}) };
    delete nextErr[errKey];
    regRxHistoryErrorByKey.value = nextErr;

    try {
        const params = new URLSearchParams({
            fsp: String(onu.fsp || ''),
            onu_id: String(onu.onu_id || ''),
            range: safeRange,
            limit: '200',
        });
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/onu-rx-history?${params}`);
        if (data.status !== 'ok') throw new Error(data.message || 'Gagal memuat histori Rx');

        const rows = Array.isArray(data.data) ? data.data : [];
        const normalizedRows = rows.map((it) => ({
            sampled_at: String(it?.sampled_at || ''),
            rx_power: extractRxValue(it?.rx_power),
        }));
        const meta = data.meta && typeof data.meta === 'object' ? data.meta : {};

        setRxHistoryCacheEntry(key, safeRange, {
            data: normalizedRows,
            meta: {
                range: sanitizeRxHistoryRange(meta.range || safeRange),
                count: Number(meta.count || 0) || 0,
                latest: extractRxValue(meta.latest),
                min: extractRxValue(meta.min),
                max: extractRxValue(meta.max),
                avg: extractRxValue(meta.avg),
            },
            loaded_at: Date.now(),
        });
    } catch (e) {
        const updatedErr = { ...(regRxHistoryErrorByKey.value || {}) };
        updatedErr[errKey] = String(e?.message || 'Gagal memuat histori Rx.');
        regRxHistoryErrorByKey.value = updatedErr;
        if (!silent) setRegStatus(updatedErr[errKey], 'error');
    } finally {
        if (regRxHistoryLoadingKey.value === key) regRxHistoryLoadingKey.value = '';
    }
}

function setOnuRxHistoryRange(onu, range) {
    const safeRange = sanitizeRxHistoryRange(range);
    if (regRxHistoryRange.value === safeRange && getRxHistoryCacheEntry(onuKey(onu), safeRange)) return;
    regRxHistoryRange.value = safeRange;
    regRxHistoryPage.value = 1;
    loadOnuRxHistory(onu, { range: safeRange, force: false, silent: true }).catch(() => {});
}

function upsertRegisteredAfterRegister({ fsp, onu_id, sn, name }) {
    const safeFsp = String(fsp || '').trim();
    const onuId = Number(onu_id || 0);
    if (!safeFsp || !onuId) return;

    const safeSn = String(sn || '').trim();
    const safeName = String(name || '').trim();

    const iface = `gpon-onu_${safeFsp}:${onuId}`;
    const fspOnu = `${safeFsp}:${onuId}`;

    const list = Array.isArray(registered.value) ? registered.value.slice() : [];
    const idx = list.findIndex((it) => {
        if (!it) return false;
        if (String(it.fsp || '') === safeFsp && Number(it.onu_id || 0) === onuId) return true;
        if (safeSn && String(it.sn || '') === safeSn) return true;
        return false;
    });

    if (idx >= 0) {
        const cur = list[idx] || {};
        list[idx] = {
            ...cur,
            fsp: safeFsp,
            onu_id: onuId,
            interface: cur.interface || iface,
            fsp_onu: cur.fsp_onu || fspOnu,
            sn: cur.sn || safeSn,
            name: safeName && (!cur.name || String(cur.name) === '-') ? safeName : cur.name,
        };
    } else {
        list.push({
            fsp: safeFsp,
            onu_id: onuId,
            interface: iface,
            fsp_onu: fspOnu,
            sn: safeSn,
            state: '',
            status: 'offline',
            rx: '',
            name: safeName,
            online_duration: '',
            vlan: 0,
        });
    }

    registered.value = list;
}

function setRegStatus(message, tone = 'info') {
    if (tone !== 'loading') stopLiveStatus();
    statusTarget = 'reg';
    if (regStatusTimer) {
        clearTimeout(regStatusTimer);
        regStatusTimer = null;
    }
    const text = String(message || '');
    regStatus.value = { tone, message: text };
    if (!text || tone === 'loading' || tone === 'error') return;
    regStatusTimer = setTimeout(() => {
        regStatus.value = { ...regStatus.value, message: '' };
        regStatusTimer = null;
    }, tone === 'success' ? 2600 : 3200);
}

function hideRegToast() {
    if (regToastTimer) {
        clearTimeout(regToastTimer);
        regToastTimer = null;
    }
    regToast.value = { ...regToast.value, open: false, message: '' };
}

function showRegToast(message, tone = 'info', duration = 3200) {
    const text = String(message || '').trim();
    if (!text) {
        hideRegToast();
        return;
    }
    if (regBulkProgress.value.visible && !regBulkProgress.value.done) return;
    if (regToastTimer) {
        clearTimeout(regToastTimer);
        regToastTimer = null;
    }
    regToast.value = { open: true, tone, message: text };
    regToastTimer = setTimeout(() => {
        regToast.value = { ...regToast.value, open: false, message: '' };
        regToastTimer = null;
    }, Math.max(1800, Number(duration || 0) || 3200));
}

function closeRegBulkProgress() {
    regBulkProgress.value = {
        visible: false,
        tone: 'loading',
        action: '',
        total: 0,
        current: 0,
        success: 0,
        failed: 0,
        currentLabel: '',
        message: '',
        done: false,
    };
}

function startRegBulkProgress(action, total) {
    regBulkProgress.value = {
        visible: true,
        tone: 'loading',
        action: String(action || ''),
        total: Math.max(0, Number(total || 0)),
        current: 0,
        success: 0,
        failed: 0,
        currentLabel: '',
        message: `Memproses ${Math.max(0, Number(total || 0))} ONU`,
        done: false,
    };
}

function updateRegBulkProgress({ current = 0, success = 0, failed = 0, currentLabel = '' } = {}) {
    regBulkProgress.value = {
        ...regBulkProgress.value,
        current: Math.max(0, Number(current || 0)),
        success: Math.max(0, Number(success || 0)),
        failed: Math.max(0, Number(failed || 0)),
        currentLabel: String(currentLabel || ''),
        message: String(currentLabel || '')
            ? `Sedang memproses ${String(currentLabel || '')}`
            : regBulkProgress.value.message,
    };
}

function finishRegBulkProgress({ success = 0, failed = 0 } = {}) {
    const okCount = Math.max(0, Number(success || 0));
    const failCount = Math.max(0, Number(failed || 0));
    const total = Math.max(0, Number(regBulkProgress.value.total || okCount + failCount));
    let tone = 'success';
    if (failCount > 0 && okCount > 0) tone = 'info';
    if (failCount > 0 && okCount === 0) tone = 'error';

    regBulkProgress.value = {
        ...regBulkProgress.value,
        visible: true,
        tone,
        current: total,
        success: okCount,
        failed: failCount,
        currentLabel: '',
        message:
            failCount > 0
                ? `Selesai. Berhasil ${okCount}, gagal ${failCount}.`
                : `${okCount} ONU berhasil diproses.`,
        done: true,
    };
}

function cancelEditOnuName() {
    regEditingKey.value = '';
    regEditingName.value = '';
    regEditingError.value = '';
}

function markOnuNameSaved(key) {
    regNameSavedKey.value = String(key || '');
    if (regNameSavedTimer) clearTimeout(regNameSavedTimer);
    regNameSavedTimer = setTimeout(() => {
        if (regNameSavedKey.value === String(key || '')) {
            regNameSavedKey.value = '';
        }
    }, 1800);
}

function setRegSort(key) {
    const safeKey = String(key || '').trim();
    if (!safeKey) return;
    if (regSortKey.value === safeKey) {
        regSortDir.value = regSortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        regSortKey.value = safeKey;
        regSortDir.value = 'asc';
    }
    regPage.value = 1;
}

function setRegLiveLoadingState(fsp, isLoading) {
    const safeFsp = String(fsp || '').trim();
    if (!safeFsp) return;
    const next = { ...(regLiveLoadingFsp.value || {}) };
    if (isLoading) {
        next[safeFsp] = true;
    } else {
        delete next[safeFsp];
    }
    regLiveLoadingFsp.value = next;
}

function parseFspParts(fsp) {
    return String(fsp || '')
        .split('/')
        .map((n) => parseInt(n, 10) || 0);
}

function compareFspRaw(a, b) {
    const pa = parseFspParts(a);
    const pb = parseFspParts(b);
    if ((pa[0] || 0) !== (pb[0] || 0)) return (pa[0] || 0) - (pb[0] || 0);
    if ((pa[1] || 0) !== (pb[1] || 0)) return (pa[1] || 0) - (pb[1] || 0);
    return (pa[2] || 0) - (pb[2] || 0);
}

function normalizeSortString(value) {
    const text = String(value || '').trim().toLowerCase();
    return text ? text : null;
}

function compareNullable(a, b, cmp, dir) {
    const aEmpty = a === null || a === undefined || a === '';
    const bEmpty = b === null || b === undefined || b === '';
    if (aEmpty && bEmpty) return 0;
    if (aEmpty) return 1;
    if (bEmpty) return -1;
    const res = cmp(a, b);
    return res === 0 ? 0 : res * dir;
}

function toNumberOrNull(value) {
    if (value === null || value === undefined || value === '') return null;
    const num = Number(value);
    return Number.isFinite(num) ? num : null;
}

function compareNumber(a, b, dir) {
    return compareNullable(
        toNumberOrNull(a),
        toNumberOrNull(b),
        (x, y) => (x === y ? 0 : x < y ? -1 : 1),
        dir
    );
}

function compareString(a, b, dir) {
    return compareNullable(
        normalizeSortString(a),
        normalizeSortString(b),
        (x, y) => x.localeCompare(y),
        dir
    );
}

function sortRegisteredList(list) {
    const dir = regSortDir.value === 'desc' ? -1 : 1;
    const key = regSortKey.value || 'interface';
    const items = Array.isArray(list) ? list : [];

    return items.sort((a, b) => {
        if (key === 'interface') {
            const fspCmp = compareFspRaw(a?.fsp, b?.fsp);
            if (fspCmp !== 0) return fspCmp * dir;
            const onuCmp = compareNumber(a?.onu_id, b?.onu_id, dir);
            if (onuCmp !== 0) return onuCmp;
            return compareString(a?.sn, b?.sn, dir);
        }
        if (key === 'name') return compareString(a?.name, b?.name, dir);
        if (key === 'sn') return compareString(a?.sn, b?.sn, dir);
        if (key === 'rx') return compareNumber(extractRxValue(a?.rx), extractRxValue(b?.rx), dir);
        if (key === 'status') return compareString(getRegStatusLabel(a), getRegStatusLabel(b), dir);
        return compareString(a?.fsp_onu || a?.interface, b?.fsp_onu || b?.interface, dir);
    });
}

function getRegSortIcon(key) {
    const safeKey = String(key || '').trim();
    if (!safeKey) return '';
    if (regSortKey.value !== safeKey) return '↕';
    return regSortDir.value === 'asc' ? '↑' : '↓';
}

function getRegAriaSort(key) {
    const safeKey = String(key || '').trim();
    if (!safeKey) return 'none';
    if (regSortKey.value !== safeKey) return 'none';
    return regSortDir.value === 'asc' ? 'ascending' : 'descending';
}

let regHighlightTimer = null;
function setRegHighlight(key) {
    regHighlightKey.value = String(key || '');
    if (regHighlightTimer) {
        clearTimeout(regHighlightTimer);
        regHighlightTimer = null;
    }
    if (!regHighlightKey.value) return;
    regHighlightTimer = setTimeout(() => {
        regHighlightKey.value = '';
        regHighlightTimer = null;
    }, 2400);
}

function isRegPoorRx(item) {
    const value = extractRxValue(item?.rx);
    return value !== null && value < -25;
}

function matchesRegQuickFilter(item) {
    const mode = String(regQuickFilter.value || 'all').trim();
    if (mode === 'online') return getRegStatusLabel(item) === 'online';
    if (mode === 'offline') return getRegStatusLabel(item) === 'offline';
    if (mode === 'rx_bad') return isRegPoorRx(item);
    return true;
}

const regLocalQuery = computed(() => String(regSearch.value || '').trim().toLowerCase());
const registeredFiltered = computed(() => {
    let list = Array.isArray(registered.value) ? registered.value.slice() : [];

    const fspFilter = String(regFilterFsp.value || '').trim() || 'all';
    if (fspFilter !== 'all') {
        list = list.filter(it => String(it?.fsp || '') === fspFilter);
    }

    if (!regSearchMode.value && regLocalQuery.value) {
        const q = regLocalQuery.value;
        list = list.filter((it) => {
            const hay = `${it.fsp_onu || ''} ${it.interface || ''} ${it.fsp || ''}:${it.onu_id || ''} ${it.sn || ''} ${it.name || ''} ${getRegStatusLabel(it)}`.toLowerCase();
            return hay.includes(q);
        });
    }

    list = list.filter((it) => matchesRegQuickFilter(it));

    return sortRegisteredList(list);
});

const regTotal = computed(() => registeredFiltered.value.length);
const regOnlineTotal = computed(() => registeredFiltered.value.filter((item) => getRegStatusLabel(item) === 'online').length);
const regOfflineTotal = computed(() => registeredFiltered.value.filter((item) => getRegStatusLabel(item) === 'offline').length);
const regHasSelectedFsp = computed(() => String(regFilterFsp.value || '').trim() !== '');
const regTotalPages = computed(() => Math.max(1, Math.ceil(regTotal.value / regPageSize.value)));
const regPageStart = computed(() => (Math.max(1, regPage.value) - 1) * regPageSize.value);
const regPageItems = computed(() => registeredFiltered.value.slice(regPageStart.value, regPageStart.value + regPageSize.value));
const regSelectedKeySet = computed(() => new Set((Array.isArray(regSelectedKeys.value) ? regSelectedKeys.value : []).filter(Boolean)));
const regSelectedItems = computed(() => {
    const selected = regSelectedKeySet.value;
    return (Array.isArray(registered.value) ? registered.value : []).filter((item) => selected.has(onuKey(item)));
});
const regSelectedCount = computed(() => regSelectedItems.value.length);
const regModalOnu = computed(() => {
    const key = String(regExpandedKey.value || '').trim();
    if (!key) return null;
    return registered.value.find((it) => onuKey(it) === key) || null;
});
const regActiveRxHistoryRows = computed(() => {
    const onu = regModalOnu.value;
    if (!onu) return [];
    return getOnuRxHistoryRows(onu);
});
const regRxHistoryTotal = computed(() => regActiveRxHistoryRows.value.length);
const regRxHistoryTotalPages = computed(() => Math.max(1, Math.ceil(regRxHistoryTotal.value / regRxHistoryPageSize.value)));
const regRxHistoryPageStart = computed(() => (Math.max(1, regRxHistoryPage.value) - 1) * regRxHistoryPageSize.value);
const regRxHistoryPageRows = computed(() =>
    regActiveRxHistoryRows.value.slice(regRxHistoryPageStart.value, regRxHistoryPageStart.value + regRxHistoryPageSize.value)
);
const regRxHistoryPageEnd = computed(() =>
    Math.min(regRxHistoryPageStart.value + regRxHistoryPageRows.value.length, regRxHistoryTotal.value)
);

watch(regTotalPages, () => {
    if (regPage.value > regTotalPages.value) regPage.value = regTotalPages.value;
    if (regPage.value < 1) regPage.value = 1;
});

watch(registered, () => {
    const validKeys = new Set((Array.isArray(registered.value) ? registered.value : []).map((item) => onuKey(item)).filter(Boolean));
    regSelectedKeys.value = (Array.isArray(regSelectedKeys.value) ? regSelectedKeys.value : []).filter((key) => validKeys.has(String(key || '')));
});

watch(regRxHistoryTotalPages, () => {
    if (regRxHistoryPage.value > regRxHistoryTotalPages.value) regRxHistoryPage.value = regRxHistoryTotalPages.value;
    if (regRxHistoryPage.value < 1) regRxHistoryPage.value = 1;
});

watch(regRxHistoryPageSize, () => {
    regRxHistoryPage.value = 1;
});

watch(regModalOnu, (onu) => {
    if (regDetailModalOpen.value && !onu) {
        regDetailModalOpen.value = false;
        regExpandedKey.value = '';
        cancelEditOnuName();
    }
});

function isRegSelected(onu) {
    return regSelectedKeySet.value.has(onuKey(onu));
}

function clearRegSelection() {
    regSelectedKeys.value = [];
}

function toggleRegSelection(onu) {
    const key = onuKey(onu);
    if (!key) return;
    const next = new Set(regSelectedKeySet.value);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    regSelectedKeys.value = Array.from(next);
}

function setRegQuickFilterValue(value) {
    regQuickFilter.value = String(value || 'all').trim() || 'all';
    regPage.value = 1;
}

function mergeRegisteredOnuPatch(onu, patch = {}) {
    const key = onuKey(onu);
    if (!key) return;

    const idx = registered.value.findIndex((it) => onuKey(it) === key);
    const baseRow = idx >= 0 ? (registered.value[idx] || {}) : (onu || {});
    const mergedRow = {
        ...baseRow,
        ...patch,
        name: patch.name ?? baseRow.name ?? '',
        sn: patch.sn ?? baseRow.sn ?? '',
        online_duration: patch.online_duration ?? baseRow.online_duration ?? '',
        status: patch.status ?? baseRow.status ?? '',
        state: patch.state ?? baseRow.state ?? '',
        rx:
            patch.rx !== undefined
                ? patch.rx
                : baseRow.rx,
    };

    if (idx >= 0) {
        const copy = registered.value.slice();
        copy[idx] = mergedRow;
        registered.value = copy;
    }

    regDetails.value = {
        ...regDetails.value,
        [key]: {
            ...(regDetails.value[key] || baseRow || {}),
            ...mergedRow,
        },
    };
}

function seedRegDetailFromCache(onu) {
    mergeRegisteredOnuPatch(onu, { ...onu });
}

function isRegDetailRxLoading(onu) {
    return regDetailRxLoadingKey.value === onuKey(onu);
}

function isRegDetailInfoLoading(onu) {
    return regDetailInfoLoadingKey.value === onuKey(onu);
}

function isRegDetailSyncing(onu) {
    const key = onuKey(onu);
    return regDetailLoadingKey.value === key || regDetailRxLoadingKey.value === key || regDetailInfoLoadingKey.value === key;
}

function hasRegisteredFspData(fsp) {
    const safeFsp = String(fsp || '').trim();
    if (!safeFsp) return false;
    return registered.value.some(it => String(it?.fsp || '') === safeFsp);
}

function collectRegisteredFspSet(list) {
    const set = new Set();
    const items = Array.isArray(list) ? list : [];
    items.forEach((it) => {
        const fsp = String(it?.fsp || '').trim();
        if (!fsp) return;
        set.add(fsp);
    });
    return set;
}

function mergeRegisteredFsp(fsp, items, replace = true) {
    const safeFsp = String(fsp || '').trim();
    if (!safeFsp) return;

    const incoming = Array.isArray(items) ? items : [];
    const current = Array.isArray(registered.value) ? registered.value : [];
    const prevDetails = {};

    if (replace) {
        current.forEach((it) => {
            if (String(it?.fsp || '') !== safeFsp) return;
            prevDetails[onuKey(it)] = it;
        });
    }

    let next = replace ? current.filter(it => String(it?.fsp || '') !== safeFsp) : current.slice();
    const existing = new Set(next.map(it => onuKey(it)));

    incoming.forEach((it) => {
        const key = onuKey(it);
        if (!replace && existing.has(key)) return;

        const prev = prevDetails[key];
        if (prev) {
            if (!it.name && prev.name) it.name = prev.name;
            if (!it.online_duration && prev.online_duration) it.online_duration = prev.online_duration;
            if ((it.rx === null || it.rx === undefined || it.rx === '') && prev.rx) it.rx = prev.rx;
        }

        next.push(it);
    });

    registered.value = next;
}

function mergeRegisteredFspBatch(replaceByFsp) {
    const raw = replaceByFsp && typeof replaceByFsp === 'object' ? replaceByFsp : {};
    const targets = Object.keys(raw)
        .map((fsp) => String(fsp || '').trim())
        .filter(Boolean);
    if (!targets.length) return;

    const targetSet = new Set(targets);
    const current = Array.isArray(registered.value) ? registered.value : [];
    const prevByFsp = {};

    current.forEach((it) => {
        const fsp = String(it?.fsp || '').trim();
        if (!targetSet.has(fsp)) return;
        if (!prevByFsp[fsp]) prevByFsp[fsp] = {};
        prevByFsp[fsp][onuKey(it)] = it;
    });

    const next = current.filter((it) => !targetSet.has(String(it?.fsp || '').trim()));
    const existing = new Set(next.map((it) => onuKey(it)));

    targets.forEach((fsp) => {
        const incoming = Array.isArray(raw[fsp]) ? raw[fsp] : [];
        const prevDetails = prevByFsp[fsp] || {};
        incoming.forEach((it) => {
            const key = onuKey(it);
            if (existing.has(key)) return;

            const prev = prevDetails[key];
            if (prev) {
                if (!it.name && prev.name) it.name = prev.name;
                if (!it.online_duration && prev.online_duration) it.online_duration = prev.online_duration;
                if ((it.rx === null || it.rx === undefined || it.rx === '') && prev.rx) it.rx = prev.rx;
            }

            next.push(it);
            existing.add(key);
        });
    });

    registered.value = next;
}

function mergeRegisteredSearchResultsFromLive(items) {
    const liveItems = Array.isArray(items) ? items : [];
    if (!liveItems.length || !Array.isArray(registered.value) || !registered.value.length) return;

    const liveByKey = {};
    liveItems.forEach((item) => {
        liveByKey[onuKey(item)] = item;
    });

    registered.value = registered.value.map((item) => {
        const live = liveByKey[onuKey(item)];
        if (!live) return item;

        return {
            ...item,
            ...live,
            name: live.name || item.name || '',
            online_duration: live.online_duration || item.online_duration || '',
            vlan: live.vlan || item.vlan || 0,
            rx:
                live.rx !== null && live.rx !== undefined && live.rx !== ''
                    ? live.rx
                    : item.rx,
        };
    });
}

const regFspInfoText = computed(() => {
    const total = Array.isArray(fspList.value) ? fspList.value.length : 0;
    const loaded = Object.keys(regLoadedFsp.value || {}).length;
    if (!total) return 'FSP belum dimuat.';
    if (regFilterFsp.value && regFilterFsp.value !== 'all') {
        const metaLine = formatFspMetaLine(regFilterFsp.value);
        if (metaLine) {
            return `FSP dimuat: ${loaded}/${total} • ${metaLine}`;
        }
    }
    return `FSP dimuat: ${loaded}/${total}`;
});

const regRxLoadingInfoText = computed(() => {
    const loadingFsp = Object.keys(regLiveLoadingFsp.value || {}).filter((fsp) => !!regLiveLoadingFsp.value?.[fsp]);
    if (!loadingFsp.length) return '';
    if (loadingFsp.length === 1) {
        return `Rx sedang dimuat dari OLT (FSP ${loadingFsp[0]}).`;
    }
    return `Rx sedang dimuat dari OLT (${loadingFsp.length} FSP).`;
});

async function loadRegisteredCache({ fsp = '', search = '' } = {}) {
    if (!selectedOltId.value) return;

    const params = new URLSearchParams();
    if (fsp) params.set('fsp', fsp);
    if (search) params.set('search', search);

    const url = `${API_BASE}/olts/${selectedOltId.value}/cache${params.toString() ? `?${params}` : ''}`;
    const data = await fetchJson(url);
    const items = data.status === 'ok' ? (Array.isArray(data.data) ? data.data : []) : [];

    if (search) {
        registered.value = items;
        return;
    }

    if (fsp) {
        mergeRegisteredFsp(fsp, items, true);
    } else {
        registered.value = items;
    }
}

async function loadRegisteredLive(fsp, { silent = false } = {}) {
    if (!selectedOltId.value || !fsp) return;

    setRegLiveLoadingState(fsp, true);

    const showBlockingLoader = !silent;
    if (showBlockingLoader) {
        regLoading.value = true;
        regLoadingText.value = 'Memuat data dari OLT...';
        setRegStatus('Memuat data dari OLT...', 'info');
    }

    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/registered?fsp=${encodeURIComponent(fsp)}`);
        if (data.status === 'ok') {
            const items = Array.isArray(data.data) ? data.data : [];
            const hadCache = hasRegisteredFspData(fsp);
            const shouldReplace = items.length > 0 || !hadCache;
            if (shouldReplace) {
                mergeRegisteredFsp(fsp, items, true);
            }
            regLoadedFsp.value = { ...regLoadedFsp.value, [fsp]: true };
            if (!silent) {
                if (!shouldReplace) {
                    setRegStatus('Hasil telnet kosong, menampilkan cache ONU.', 'info');
                }
            }
        }
    } catch (e) {
        if (!silent) setRegStatus(e.message || 'Gagal memuat data dari OLT.', 'error');
    } finally {
        setRegLiveLoadingState(fsp, false);
        if (showBlockingLoader) {
            regLoading.value = false;
            regLoadingText.value = '';
        }
    }
}

async function loadRegisteredAll({ force = false, silent = false } = {}) {
    if (!selectedOltId.value) return;

    // Ensure we have a FSP list (prefer cached list from OLT).
    if (!fspList.value.length) {
        await loadFspList();
    }

    const all = Array.isArray(fspList.value) ? fspList.value.filter(f => f && f !== 'all') : [];
    if (!all.length) {
        if (!silent) setRegStatus('FSP belum dimuat.', 'error');
        return;
    }

    const targets = force ? all : all.filter(f => !regLoadedFsp.value?.[f]);
    if (!targets.length) {
        if (!silent) setRegStatus('Semua FSP sudah dimuat.', 'info');
        return;
    }

    const requestToken = ++regAllRequestToken;

    // Cache-first (single call): avoid N requests when "Semua F/S/P" is selected.
    if (!regSearchMode.value && (force || !registered.value.length)) {
        try {
            await loadRegisteredCache();
        } catch {
            // ignore cache failures; live may still work
        }
    }

    const hasLocalData = Array.isArray(registered.value) && registered.value.length > 0;
    const showBlockingLoader = !hasLocalData;

    if (showBlockingLoader) {
        regLoading.value = true;
        regLoadingText.value = 'Memuat data FSP...';
    }
    if (!silent) {
        if (hasLocalData) {
            setRegStatus(`Menampilkan cache ONU (${registered.value.length}), sinkron OLT berjalan...`, 'loading');
        } else {
            setRegStatus('Memuat data FSP dari OLT...', 'info');
        }
    }

    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/registered-all`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fsp_list: targets }),
        });
        if (requestToken !== regAllRequestToken) return;
        if (data.status !== 'ok') throw new Error(data.message || 'Gagal memuat data FSP');

        const items = Array.isArray(data.data) ? data.data : [];
        const grouped = {};
        items.forEach((it) => {
            const fsp = String(it?.fsp || '').trim();
            if (!fsp) return;
            if (!grouped[fsp]) grouped[fsp] = [];
            grouped[fsp].push(it);
        });

        const loadedFspSet = collectRegisteredFspSet(registered.value);
        const replaceByFsp = {};
        let replacedCount = 0;
        targets.forEach((fsp) => {
            const list = grouped[fsp] || [];
            const hadCache = loadedFspSet.has(fsp);
            const shouldReplace = list.length > 0 || !hadCache;
            if (!shouldReplace) return;
            replaceByFsp[fsp] = list;
            replacedCount++;
        });
        if (replacedCount > 0) mergeRegisteredFspBatch(replaceByFsp);

        const loaded = { ...(regLoadedFsp.value || {}) };
        targets.forEach((fsp) => {
            loaded[fsp] = true;
        });
        regLoadedFsp.value = loaded;

        if (!silent) {
            const failed = Array.isArray(data.failed) ? data.failed : [];
            const failedCount = failed.length;
            if (failedCount > 0) {
                setRegStatus(`Load FSP selesai ${replacedCount}/${targets.length}, gagal ${failedCount}.`, 'info');
            }
        }
    } catch (e) {
        if (requestToken !== regAllRequestToken) return;
        if (!silent) setRegStatus(e.message || 'Gagal memuat data FSP.', 'error');
    } finally {
        if (requestToken !== regAllRequestToken) return;
        if (showBlockingLoader) {
            regLoading.value = false;
            regLoadingText.value = '';
        }
    }
}

function cancelRegisteredSearchLiveRefresh() {
    regSearchLiveRefreshToken += 1;
    if (regSearchLiveTimer) {
        clearTimeout(regSearchLiveTimer);
        regSearchLiveTimer = null;
    }
    regLiveLoadingFsp.value = {};
}

async function refreshRegisteredSearchLive(query) {
    const q = String(query || '').trim();
    if (!selectedOltId.value || !q || !regSearchMode.value || String(regFilterFsp.value || '').trim() !== '') {
        return;
    }

    const searchItems = Array.isArray(registered.value)
        ? registered.value
            .map((item) => ({
                fsp: String(item?.fsp || '').trim(),
                onu_id: Number(item?.onu_id || 0),
            }))
            .filter((item) => item.fsp && item.onu_id > 0)
        : [];
    if (!searchItems.length) return;

    const fspTargets = sortFspList(Array.from(new Set(searchItems.map((item) => item.fsp))));

    const requestToken = ++regSearchLiveRefreshToken;
    fspTargets.forEach((fsp) => setRegLiveLoadingState(fsp, true));
    setRegStatus(`Hasil cache ditemukan, memuat Rx/status per ONU (${searchItems.length} hasil)...`, 'loading');

    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/search-rx-refresh`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items: searchItems }),
        });

        if (requestToken !== regSearchLiveRefreshToken) return;
        if (String(regSearch.value || '').trim() !== q) return;
        if (!regSearchMode.value || String(regFilterFsp.value || '').trim() !== '') return;
        if (data.status !== 'ok') throw new Error(data.message || 'Gagal memuat Rx ONU');

        mergeRegisteredSearchResultsFromLive(Array.isArray(data.data) ? data.data : []);
        setRegStatus('Hasil pencarian tampil dengan Rx/status terbaru.', 'success');
    } catch (e) {
        if (requestToken !== regSearchLiveRefreshToken) return;
        if (String(regSearch.value || '').trim() !== q) return;
        if (!regSearchMode.value || String(regFilterFsp.value || '').trim() !== '') return;
        setRegStatus(`Hasil pencarian tampil dari cache. Refresh Rx/status gagal: ${e.message || 'error'}`, 'info');
    } finally {
        if (requestToken !== regSearchLiveRefreshToken) return;
        fspTargets.forEach((fsp) => setRegLiveLoadingState(fsp, false));
    }
}

function scheduleRegisteredSearchLiveRefresh(query) {
    const q = String(query || '').trim();
    if (!q || !regSearchMode.value || String(regFilterFsp.value || '').trim() !== '') {
        return;
    }
    if (!Array.isArray(registered.value) || !registered.value.length) {
        return;
    }

    if (regSearchLiveTimer) {
        clearTimeout(regSearchLiveTimer);
        regSearchLiveTimer = null;
    }

    regSearchLiveTimer = setTimeout(() => {
        regSearchLiveTimer = null;
        if (String(regSearch.value || '').trim() !== q) return;
        if (!regSearchMode.value || String(regFilterFsp.value || '').trim() !== '') return;
        refreshRegisteredSearchLive(q).catch(() => {});
    }, REG_SEARCH_LIVE_REFRESH_DELAY_MS);
}

async function changeRegFsp() {
    if (regSearchMode.value) exitRegSearchMode();
    cancelRegisteredSearchLiveRefresh();
    regPage.value = 1;
    closeRegDetailModal();

    if (!selectedOltId.value) return;
    const fsp = String(regFilterFsp.value || '');

    if (!fsp) {
        // Keep current data; user hasn't requested a load action.
        return;
    }

    if (fsp === 'all') {
        await loadRegisteredAll({ force: false, silent: false });
        return;
    }

    // Cache first, then live refresh in background (native parity).
    try {
        if (!hasRegisteredFspData(fsp)) {
            await loadRegisteredCache({ fsp });
        }
    } catch {
        // ignore cache failures; live may still work
    }
    if (!regLoadedFsp.value?.[fsp]) {
        loadRegisteredLive(fsp, { silent: true }).catch(() => {});
    }
}

const regSearchBackup = ref(null);
let regDbSearchTimer = null;
let regSnSearchTimer = null;
let regSearchLiveTimer = null;
let regLastDbQuery = '';
let regLastSnQuery = '';
let suppressRegSearchWatcher = false;

function enterRegSearchMode() {
    if (regSearchMode.value) return;
    regSearchMode.value = true;
    regSearchBackup.value = {
        list: Array.isArray(registered.value) ? registered.value.slice() : [],
        loadedFsp: { ...(regLoadedFsp.value || {}) },
        page: regPage.value || 1,
        expandedKey: regExpandedKey.value || '',
        detailModalOpen: !!regDetailModalOpen.value,
        editingKey: regEditingKey.value || '',
        editingName: regEditingName.value || '',
    };
    registered.value = [];
    regLoadedFsp.value = {};
    regPage.value = 1;
    closeRegDetailModal();
}

function exitRegSearchMode() {
    if (!regSearchMode.value) return;
    regSearchMode.value = false;
    const backup = regSearchBackup.value;
    regSearchBackup.value = null;
    if (backup) {
        registered.value = Array.isArray(backup.list) ? backup.list.slice() : [];
        regLoadedFsp.value = backup.loadedFsp ? { ...backup.loadedFsp } : {};
        regPage.value = backup.page || 1;
        regExpandedKey.value = backup.expandedKey || '';
        regDetailModalOpen.value = !!(backup.detailModalOpen && backup.expandedKey);
        if (regDetailModalOpen.value) {
            regEditingKey.value = backup.editingKey || '';
            regEditingName.value = backup.editingName || '';
        } else {
            cancelEditOnuName();
        }
        return;
    }
    registered.value = [];
    regLoadedFsp.value = {};
    regPage.value = 1;
    closeRegDetailModal();
}

function cancelRegSearch() {
    cancelRegisteredSearchLiveRefresh();
    if (regDbSearchTimer) {
        clearTimeout(regDbSearchTimer);
        regDbSearchTimer = null;
    }
    if (regSnSearchTimer) {
        clearTimeout(regSnSearchTimer);
        regSnSearchTimer = null;
    }
    regLastDbQuery = '';
    regLastSnQuery = '';
    suppressRegSearchWatcher = true;
    regSearch.value = '';
    setTimeout(() => {
        suppressRegSearchWatcher = false;
    }, 0);
    if (regSearchMode.value) {
        exitRegSearchMode();
    }
    closeRegDetailModal();
    setRegStatus('', 'info');
}

function isLikelySn(query) {
    const text = String(query || '').trim();
    if (text === '' || /\s/.test(text)) return false;
    return /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9]{8,20}$/.test(text);
}

async function searchRegisteredCache(query) {
    if (!selectedOltId.value) return;
    const q = String(query || '').trim();
    if (q.length < 2) return;

    const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/search?q=${encodeURIComponent(q)}`);
    if (data.status !== 'ok') throw new Error(data.message || 'Gagal mencari data');

    if (String(regSearch.value || '').trim() !== q) return;
    if (!regSearchMode.value || String(regFilterFsp.value || '') !== '') return;

    registered.value = Array.isArray(data.data) ? data.data : [];

    if (registered.value.length) {
        scheduleRegisteredSearchLiveRefresh(q);
        return;
    }

    if (!registered.value.length && isLikelySn(q)) {
        searchRegisteredBySn(q).catch(() => {});
    }
}

async function searchRegisteredBySn(sn) {
    if (!selectedOltId.value) return;
    const q = String(sn || '').trim();
    if (!q) return;

    setRegStatus(`Mencari SN ${q}...`, 'info');
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/find-onu-by-sn`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sn: q }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'SN tidak ditemukan.');
        const found = data.data || {};
        if (!found.fsp) {
            setRegStatus('SN tidak ditemukan.', 'error');
            return;
        }

        enterRegSearchMode();

        const row = {
            ...found,
            interface: found.interface || (found.fsp && found.onu_id ? `gpon-onu_${found.fsp}:${found.onu_id}` : ''),
            fsp_onu: found.fsp_onu || (found.fsp && found.onu_id ? `${found.fsp}:${found.onu_id}` : ''),
        };
        registered.value = [row];
        regLoadedFsp.value = found.fsp ? { [String(found.fsp)]: true } : {};

        if (found.fsp && !fspList.value.includes(found.fsp)) {
            fspList.value = sortFspList(fspList.value.concat(found.fsp));
        }

        const key = String(row.interface || '').trim();
        if (key) setRegHighlight(key);
        setRegStatus(`SN ditemukan cepat di FSP ${found.fsp}.`, 'success');
    } catch (e) {
        setRegStatus(e.message || 'SN tidak ditemukan.', 'error');
    }
}

watch(
    () => String(regSearch.value || ''),
    (val) => {
        if (suppressRegSearchWatcher) return;
        const query = String(val || '').trim();
        regPage.value = 1;
        closeRegDetailModal();
        cancelRegisteredSearchLiveRefresh();

        if (regDbSearchTimer) {
            clearTimeout(regDbSearchTimer);
            regDbSearchTimer = null;
        }
        if (regSnSearchTimer) {
            clearTimeout(regSnSearchTimer);
            regSnSearchTimer = null;
        }

        if (!query) {
            regLastDbQuery = '';
            regLastSnQuery = '';
            if (regSearchMode.value) exitRegSearchMode();
            return;
        }

        // When filter is blank (Pilih FSP), use DB cache search mode (native behavior).
        if (!String(regFilterFsp.value || '').trim()) {
            if (isLikelySn(query)) {
                enterRegSearchMode();
                setRegStatus(`Mencari SN ${query} langsung ke OLT...`, 'info');
                regSnSearchTimer = setTimeout(() => {
                    if (String(regSearch.value || '').trim() !== query) return;
                    if (regLastSnQuery === query) return;
                    regLastSnQuery = query;
                    searchRegisteredBySn(query);
                }, 250);
                return;
            }

            if (query.length < 2) {
                setRegStatus('Minimal 2 karakter untuk pencarian.', 'error');
                return;
            }

            enterRegSearchMode();

            setRegStatus('Mencari di cache ONU...', 'info');
            regDbSearchTimer = setTimeout(() => {
                if (String(regSearch.value || '').trim() !== query) return;
                if (!regSearchMode.value || String(regFilterFsp.value || '') !== '') return;
                if (regLastDbQuery === query) return;
                regLastDbQuery = query;
                searchRegisteredCache(query).catch((e) => {
                    if (String(regSearch.value || '').trim() !== query) return;
                    setRegStatus(e.message || 'Gagal mencari data', 'error');
                });
            }, 600);
            return;
        }

        // Local filter mode (FSP specific / all).
        if (regSearchMode.value) exitRegSearchMode();

        if (!isLikelySn(query)) return;
        if (registeredFiltered.value.length > 0) return;

        regSnSearchTimer = setTimeout(() => {
            if (String(regSearch.value || '').trim() !== query) return;
            if (regLastSnQuery === query) return;
            regLastSnQuery = query;
            searchRegisteredBySn(query);
        }, 600);
    }
);

function isRowActionLoading(key, type) {
    return rowActionKey.value === key && rowActionType.value === type;
}

function setRowActionLoading(key, type, active) {
    if (!active) {
        if (rowActionKey.value === key && rowActionType.value === type) {
            rowActionKey.value = '';
            rowActionType.value = '';
        }
        return;
    }
    rowActionKey.value = key;
    rowActionType.value = type;
}

function closeRegDetailModal() {
    regDetailRefreshToken += 1;
    regDetailModalOpen.value = false;
    regExpandedKey.value = '';
    regDetailLoadingKey.value = '';
    regDetailRxLoadingKey.value = '';
    regDetailInfoLoadingKey.value = '';
    regRxHistoryOpen.value = false;
    regRxHistoryPage.value = 1;
    regNameSavedKey.value = '';
    cancelEditOnuName();
}

async function toggleRegDetail(onu, { skipLoad = false } = {}) {
    const key = onuKey(onu);
    if (regDetailModalOpen.value && regExpandedKey.value === key) {
        closeRegDetailModal();
        return;
    }
    regExpandedKey.value = key;
    regDetailModalOpen.value = true;
    regRxHistoryOpen.value = false;
    regRxHistoryRange.value = '24h';
    regRxHistoryPage.value = 1;
    seedRegDetailFromCache(onu);
    if (!skipLoad) {
        loadOnuDetail(onu, { force: true, silent: true }).catch(() => {});
    }
}

async function loadOnuDetail(onu, { force = false, silent = false, throwOnError = false } = {}) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    if (!key) return;

    if (!force && regDetailLoadingKey.value === key) {
        return;
    }

    seedRegDetailFromCache(onu);

    const token = ++regDetailRefreshToken;
    regDetailLoadingKey.value = key;
    try {
        regDetailRxLoadingKey.value = key;
        const rxParams = new URLSearchParams({ fsp: String(onu.fsp || ''), onu_id: String(onu.onu_id || '') });
        const rxData = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/onu-rx-live?${rxParams}`);
        if (rxData.status !== 'ok') throw new Error(rxData.message || 'Gagal memuat Rx live');

        if (token !== regDetailRefreshToken || regExpandedKey.value !== key) return;
        const rxPatch = { ...(rxData.data || {}) };
        if ((rxPatch.rx === undefined || rxPatch.rx === null || rxPatch.rx === '') && rxPatch.rx_power) {
            const parsedRx = extractRxValue(rxPatch.rx_power);
            if (parsedRx !== null) rxPatch.rx = parsedRx;
        }
        mergeRegisteredOnuPatch(onu, rxPatch);
    } catch (e) {
        if (!silent) setRegStatus(e.message || 'Gagal memuat Rx live', 'error');
        if (throwOnError) throw e;
        if (token === regDetailRefreshToken && regDetailLoadingKey.value === key) {
            regDetailLoadingKey.value = '';
        }
        return;
    } finally {
        if (token === regDetailRefreshToken && regDetailRxLoadingKey.value === key) {
            regDetailRxLoadingKey.value = '';
        }
    }

    try {
        regDetailInfoLoadingKey.value = key;
        const detailParams = new URLSearchParams({ fsp: String(onu.fsp || ''), onu_id: String(onu.onu_id || '') });
        const detailData = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/onu-detail-refresh?${detailParams}`);
        if (detailData.status !== 'ok') throw new Error(detailData.message || 'Gagal sinkron detail ONU');

        if (token !== regDetailRefreshToken || regExpandedKey.value !== key) return;
        if (detailData.changed) {
            const currentDetail = regDetails.value[key] || registered.value.find((it) => onuKey(it) === key) || onu || {};
            const detailPatch = { ...(detailData.data || {}) };
            if ((detailPatch.rx === undefined || detailPatch.rx === null || detailPatch.rx === '') && detailPatch.rx_power) {
                const parsedRx = extractRxValue(detailPatch.rx_power);
                if (parsedRx !== null) detailPatch.rx = parsedRx;
            }
            if (detailPatch.rx === undefined || detailPatch.rx === null || detailPatch.rx === '') {
                detailPatch.rx = currentDetail.rx ?? null;
            }
            detailPatch.status = currentDetail.status || '';
            detailPatch.state = currentDetail.state || detailPatch.status || '';
            mergeRegisteredOnuPatch(onu, detailPatch);
        }
    } catch (e) {
        if (!silent) setRegStatus(e.message || 'Gagal sinkron detail ONU', 'error');
        if (throwOnError) throw e;
    } finally {
        if (token === regDetailRefreshToken && regDetailInfoLoadingKey.value === key) {
            regDetailInfoLoadingKey.value = '';
        }
        if (token === regDetailRefreshToken && regDetailLoadingKey.value === key) {
            regDetailLoadingKey.value = '';
        }
    }
}

async function toggleOnuRxHistory(onu) {
    const nextOpen = !regRxHistoryOpen.value;
    regRxHistoryOpen.value = nextOpen;
    regRxHistoryPage.value = 1;

    if (!nextOpen) {
        return;
    }

    await loadOnuRxHistory(onu, { range: regRxHistoryRange.value, force: false, silent: true });
}

function startEditOnuName(onu) {
    const key = onuKey(onu);
    regEditingKey.value = key;
    regEditingName.value = String(onu.name || '').trim();
    regEditingError.value = '';
    if (regNameSavedKey.value === key) {
        regNameSavedKey.value = '';
    }
}

async function saveEditOnuName(onu) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    const name = String(regEditingName.value || '').trim();
    if (!name) {
        regEditingError.value = 'Nama ONU wajib diisi.';
        return;
    }

    regEditingError.value = '';
    setRowActionLoading(key, 'rename', true);
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/update-onu-name`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                fsp: String(onu.fsp || ''),
                onu_id: Number(onu.onu_id || 0),
                name,
            }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'Gagal update nama');
        if (data?.log_excerpt) lastLogExcerpt.value = String(data.log_excerpt);

        const idx = registered.value.findIndex(it => onuKey(it) === key);
        if (idx >= 0) {
            const cur = registered.value[idx] || {};
            const copy = registered.value.slice();
            copy[idx] = { ...cur, name };
            registered.value = copy;
        }

        if (regDetails.value[key]) {
            regDetails.value = { ...regDetails.value, [key]: { ...regDetails.value[key], name } };
        }

        cancelEditOnuName();
        markOnuNameSaved(key);
        setSelectedOltConfigPending(true);
    } catch (e) {
        if (e?.data?.log_excerpt) lastLogExcerpt.value = String(e.data.log_excerpt);
        regEditingError.value = e.message || 'Gagal update nama';
    } finally {
        setRowActionLoading(key, 'rename', false);
        loadLogs().catch(() => {});
    }
}

async function refreshRegisteredOnu(onu) {
    const key = onuKey(onu);
    setRowActionLoading(key, 'refresh', true);
    try {
        await loadOnuDetail(onu, { force: true, silent: true, throwOnError: true });
        if (regRxHistoryOpen.value && regExpandedKey.value === key) {
            await loadOnuRxHistory(onu, { range: regRxHistoryRange.value, force: true, silent: true });
        }
    } catch (e) {
        setRegStatus(e.message || 'Gagal refresh', 'error');
    } finally {
        setRowActionLoading(key, 'refresh', false);
    }
}

async function restartRegisteredOnuRequest(onu, { skipConfirm = false, silentStatus = false, reloadLogs = true } = {}) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    if (!skipConfirm && !confirm(`Restart ONU ${onu.fsp}:${onu.onu_id}?`)) return false;

    setRowActionLoading(key, 'restart', true);
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/restart-onu`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fsp: String(onu.fsp || ''), onu_id: Number(onu.onu_id || 0) }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'Restart gagal');
        if (data?.log_excerpt) lastLogExcerpt.value = String(data.log_excerpt);
        if (!silentStatus) showRegToast(data.message || 'ONU sedang di-restart.', 'success');
        return true;
    } catch (e) {
        if (e?.data?.log_excerpt) lastLogExcerpt.value = String(e.data.log_excerpt);
        if (!silentStatus) showRegToast(e.message || 'Restart gagal', 'error', 4200);
        throw e;
    } finally {
        setRowActionLoading(key, 'restart', false);
        if (reloadLogs) loadLogs().catch(() => {});
    }
}

async function restartRegisteredOnu(onu) {
    try {
        await restartRegisteredOnuRequest(onu);
    } catch {
        // handled in request helper
    }
}

async function deleteRegisteredOnuRequest(onu, { skipConfirm = false, silentStatus = false, reloadLogs = true } = {}) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    if (!skipConfirm && !confirm(`Hapus ONU ${onu.fsp}:${onu.onu_id}?`)) return false;

    setRowActionLoading(key, 'delete', true);
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/delete-onu`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fsp: String(onu.fsp || ''), onu_id: Number(onu.onu_id || 0) }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'Hapus gagal');
        if (data?.log_excerpt) lastLogExcerpt.value = String(data.log_excerpt);

        registered.value = registered.value.filter(it => onuKey(it) !== key);
        if (regExpandedKey.value === key) closeRegDetailModal();
        clearRxHistoryCache(key);
        regSelectedKeys.value = regSelectedKeys.value.filter((it) => String(it) !== key);
        if (!silentStatus) showRegToast('ONU berhasil dihapus.', 'success');
        setSelectedOltConfigPending(true);
        return true;
    } catch (e) {
        if (e?.data?.log_excerpt) lastLogExcerpt.value = String(e.data.log_excerpt);
        if (!silentStatus) showRegToast(e.message || 'Hapus gagal', 'error', 4200);
        throw e;
    } finally {
        setRowActionLoading(key, 'delete', false);
        if (reloadLogs) loadLogs().catch(() => {});
    }
}

async function deleteRegisteredOnu(onu) {
    try {
        await deleteRegisteredOnuRequest(onu);
    } catch {
        // handled in request helper
    }
}

async function runRegisteredBulkAction(action) {
    if (!selectedOltId.value || regBulkActionBusy.value) return;

    const selectedItems = regSelectedItems.value.slice();
    if (!selectedItems.length) {
        setRegStatus('Pilih ONU dulu untuk bulk action.', 'error');
        return;
    }

    const isDelete = action === 'delete';
    const actionLabel = isDelete ? 'hapus' : 'restart';
    const confirmed = confirm(
        `${isDelete ? 'Hapus' : 'Restart'} ${selectedItems.length} ONU terpilih?\n\nAksi ini akan diproses satu per satu.`
    );
    if (!confirmed) return;

    regBulkActionBusy.value = true;
    hideRegToast();
    setRegStatus('', 'info');
    startRegBulkProgress(actionLabel, selectedItems.length);
    let success = 0;
    let failed = 0;

    try {
        for (let i = 0; i < selectedItems.length; i += 1) {
            const item = selectedItems[i];
            const label = `${String(item?.fsp || '')}:${Number(item?.onu_id || 0)}`;
            updateRegBulkProgress({
                current: i + 1,
                success,
                failed,
                currentLabel: label,
            });
            try {
                if (isDelete) {
                    await deleteRegisteredOnuRequest(item, { skipConfirm: true, silentStatus: true, reloadLogs: false });
                } else {
                    await restartRegisteredOnuRequest(item, { skipConfirm: true, silentStatus: true, reloadLogs: false });
                }
                success += 1;
            } catch {
                failed += 1;
            }
            updateRegBulkProgress({
                current: i + 1,
                success,
                failed,
                currentLabel: label,
            });
        }

        clearRegSelection();
        finishRegBulkProgress({ success, failed });
    } finally {
        regBulkActionBusy.value = false;
        loadLogs().catch(() => {});
    }
}

// ========== Logs ==========
const logs = ref([]);
const logsLoading = ref(false);
const lastLogExcerpt = ref('');
const logMeta = ref({
    total: 0,
    filtered_total: 0,
    actors: [],
    actions: [],
    statuses: [],
});
const logFilters = ref({
    actor: '',
    action: '',
    status: '',
    hideConnectFailed: true,
    limit: 100,
});
const selectedLogId = ref(null);
const showLogPanel = ref(false);

const selectedLogEntry = computed(() => {
    const items = Array.isArray(logs.value) ? logs.value : [];
    const key = String(selectedLogId.value || '').trim();
    if (!key) return null;
    const match = items.find((item) => String(item?.id || '') === key);
    return match || null;
});

const selectedLogSummaryPretty = computed(() => {
    const summary = parseSummaryJson(selectedLogEntry.value?.summary_json);
    const keys = Object.keys(summary || {});
    if (!keys.length) return '';
    try {
        return JSON.stringify(summary, null, 2);
    } catch {
        return '';
    }
});

const selectedLogSummaryEntries = computed(() => {
    const summary = parseSummaryJson(selectedLogEntry.value?.summary_json);
    return Object.entries(summary || {})
        .filter(([, value]) => value !== null && value !== undefined && value !== '')
        .slice(0, 12)
        .map(([key, value]) => ({
            key,
            label: formatLogSummaryLabel(key),
            value: formatLogSummaryValue(value),
        }));
});

const selectedLogTranscript = computed(() => {
    const selectedText = String(selectedLogEntry.value?.log_text || '').trim();
    if (selectedText) return selectedText;
    const fallbackText = String(lastLogExcerpt.value || '').trim();
    if (fallbackText) return fallbackText;
    return 'Belum ada transcript command.';
});

const hasActiveLogFilters = computed(() => !!(
    logFilters.value.actor
    || logFilters.value.action
    || logFilters.value.status
    || !logFilters.value.hideConnectFailed
    || Number(logFilters.value.limit || 100) !== 100
));

const logFilterQueryKey = computed(() => JSON.stringify({
    actor: logFilters.value.actor || '',
    action: logFilters.value.action || '',
    status: logFilters.value.status || '',
    hideConnectFailed: !!logFilters.value.hideConnectFailed,
    limit: Number(logFilters.value.limit || 100),
}));

function formatLogCreatedAt(value) {
    const text = String(value || '').trim();
    if (!text) return '-';
    return text.replace('T', ' ').slice(0, 19);
}

function logStatusTone(status) {
    const value = String(status || '').trim().toLowerCase();
    if (['done', 'success'].includes(value)) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
    if (['error', 'failed', 'fail'].includes(value)) {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300';
    }
    return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300';
}

function formatLogStatus(status) {
    const value = String(status || '').trim();
    return value ? value.replace(/_/g, ' ') : '-';
}

function formatLogAction(action) {
    const key = String(action || '').trim().toLowerCase();
    const labels = {
        register: 'Register ONU',
        register_auto: 'Auto Register',
        update_onu_detail: 'Edit Detail ONU',
        delete_onu: 'Hapus ONU',
        restart_onu: 'Restart ONU',
        write: 'Write Config',
        sync_daily: 'Sync Harian',
        sync_registered_all: 'Sync Registered',
        connect: 'Koneksi OLT',
        delete_profile: 'Hapus Profil',
    };
    return labels[key] || (key ? key.replace(/_/g, ' ') : '-');
}

function formatLogSummaryLabel(key) {
    const text = String(key || '')
        .trim()
        .replace(/_/g, ' ')
        .replace(/\s+/g, ' ');
    if (!text) return '-';
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function formatLogSummaryValue(value) {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'number' || typeof value === 'boolean') return String(value);
    if (Array.isArray(value)) {
        return value
            .map(item => {
                if (item === null || item === undefined || item === '') return null;
                if (typeof item === 'string' || typeof item === 'number' || typeof item === 'boolean') return String(item);
                try {
                    return JSON.stringify(item);
                } catch {
                    return '[data]';
                }
            })
            .filter(Boolean)
            .join(', ');
    }
    if (typeof value === 'object') {
        try {
            return JSON.stringify(value);
        } catch {
            return '[data]';
        }
    }
    return String(value);
}

function extractLogHeadline(text) {
    const lines = String(text || '')
        .split(/\r?\n/)
        .map(line => line.trim())
        .filter(Boolean);
    const preferred = lines.find(line => !line.startsWith('>>>'));
    return preferred || lines[0] || '';
}

function summarizeLogEntry(entry) {
    const action = String(entry?.action || '').trim().toLowerCase();
    const summary = parseSummaryJson(entry?.summary_json);

    if (action === 'register') {
        const parts = [];
        const fsp = String(summary.fsp || '').trim();
        const onuId = summary.onu_id !== undefined && summary.onu_id !== null ? String(summary.onu_id) : '';
        const sn = String(summary.sn || '').trim();
        const onuName = String(summary.onu_name || '').trim();
        const onuRx = summary.onu_rx;
        if (fsp && onuId) parts.push(`${fsp}:${onuId}`);
        if (onuName) parts.push(onuName);
        if (sn) parts.push(sn);
        if (onuRx !== undefined && onuRx !== null && onuRx !== '') parts.push(`Rx ${formatRx(onuRx)}`);
        if (parts.length) return parts.join(' • ');
    }

    if (action === 'register_auto') {
        const success = Number(summary.success || 0);
        const error = Number(summary.error || 0);
        const total = Number(summary.total_count || summary.count || success + error || 0);
        const batch = Number(summary.current_batch || 0);
        const batches = Number(summary.total_batches || 0);
        const parts = [];
        if (total > 0) parts.push(`${total} ONU`);
        parts.push(`success ${success}`);
        if (error > 0) parts.push(`error ${error}`);
        if (batch > 0 && batches > 0) parts.push(`batch ${batch}/${batches}`);
        return parts.join(' • ');
    }

    if (action === 'write') {
        const success = Number(summary.success || 0);
        return success > 0 ? 'Konfigurasi berhasil disimpan ke flash.' : 'Write-config dijalankan.';
    }

    if (action === 'delete_onu' || action === 'restart_onu') {
        const fsp = String(summary.fsp || '').trim();
        const onuId = summary.onu_id !== undefined && summary.onu_id !== null ? String(summary.onu_id) : '';
        if (fsp && onuId) return `${fsp}:${onuId}`;
    }

    if (action === 'update_onu_detail') {
        const fsp = String(summary.fsp || '').trim();
        const onuId = summary.onu_id !== undefined && summary.onu_id !== null ? String(summary.onu_id) : '';
        const onuName = String(summary.onu_name || '').trim();
        const parts = [];
        if (fsp && onuId) parts.push(`${fsp}:${onuId}`);
        if (onuName) parts.push(onuName);
        if (parts.length) return parts.join(' • ');
    }

    if (action === 'sync_daily' || action === 'sync_registered_all') {
        const count = Number(summary.count || 0);
        const success = Number(summary.success || 0);
        const error = Number(summary.error || 0);
        const parts = [];
        if (count > 0) parts.push(`${count} ONU`);
        if (success > 0) parts.push(`success ${success}`);
        if (error > 0) parts.push(`error ${error}`);
        const stateText = String(summary.state_text || '').trim();
        if (stateText) parts.push(stateText);
        if (parts.length) return parts.join(' • ');
    }

    return extractLogHeadline(entry?.log_text);
}

function resetLogFilters() {
    logFilters.value = {
        actor: '',
        action: '',
        status: '',
        hideConnectFailed: true,
        limit: 100,
    };
}

function toggleLogEntry(entryId) {
    const nextKey = String(entryId || '').trim();
    const currentKey = String(selectedLogId.value || '').trim();
    selectedLogId.value = currentKey && currentKey === nextKey ? null : entryId;
}

async function loadLogs() {
    if (!selectedOltId.value || isTeknisi.value) return;
    logsLoading.value = true;
    try {
        const params = new URLSearchParams();
        if (logFilters.value.actor) params.set('actor', String(logFilters.value.actor));
        if (logFilters.value.action) params.set('action', String(logFilters.value.action));
        if (logFilters.value.status) params.set('status', String(logFilters.value.status));
        if (logFilters.value.hideConnectFailed) params.set('hide_connect_failed', '1');
        params.set('limit', String(Number(logFilters.value.limit || 100)));

        const query = params.toString();
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/logs${query ? `?${query}` : ''}`);
        logs.value = data.status === 'ok' ? (Array.isArray(data.data) ? data.data : []) : [];
        const meta = data?.meta && typeof data.meta === 'object' ? data.meta : {};
        logMeta.value = {
            total: Number(meta.total || 0),
            filtered_total: Number(meta.filtered_total || 0),
            actors: Array.isArray(meta.actors) ? meta.actors : [],
            actions: Array.isArray(meta.actions) ? meta.actions : [],
            statuses: Array.isArray(meta.statuses) ? meta.statuses : [],
        };

        if (Array.isArray(logs.value) && logs.value.length) {
            const t = logs.value[0]?.log_text ? String(logs.value[0].log_text) : '';
            if (t) lastLogExcerpt.value = t;
        }
        const selectedKey = String(selectedLogId.value || '').trim();
        const selectedStillExists = selectedKey && logs.value.some(item => String(item?.id || '') === selectedKey);
        selectedLogId.value = selectedStillExists ? selectedKey : null;

        if (!autoRegisterRunId.value) {
            const activeRun = (Array.isArray(logs.value) ? logs.value : []).find(item => {
                const action = String(item?.action || '');
                const status = String(item?.status || '');
                return action === 'register_auto' && ['queued', 'processing'].includes(status);
            });
            if (activeRun?.id) {
                autoRegisterRunId.value = Number(activeRun.id);
                registerBusy.value = true;
                const summary = parseSummaryJson(activeRun.summary_json);
                const progressText = String(summary.state_text || '').trim() || 'Auto register sedang berjalan.';
                setUncfgStatus(progressText, 'loading');
                startAutoRegisterRealtime(autoRegisterRunId.value).catch(() => {});
                scheduleAutoRegisterPoll(autoRegisterRunId.value, 1500);
            }
        }
    } catch {
        logs.value = [];
        logMeta.value = {
            total: 0,
            filtered_total: 0,
            actors: [],
            actions: [],
            statuses: [],
        };
        selectedLogId.value = null;
    } finally {
        logsLoading.value = false;
    }
}

function clearLogs() {
    logs.value = [];
    logMeta.value = {
        total: 0,
        filtered_total: 0,
        actors: [],
        actions: [],
        statuses: [],
    };
    selectedLogId.value = null;
}

async function copyCurrentLog() {
    const entry = selectedLogEntry.value;
    const summaryText = selectedLogSummaryPretty.value;
    const parts = [];
    if (entry) {
        parts.push(`Waktu: ${formatLogCreatedAt(entry.created_at)}`);
        parts.push(`Actor: ${String(entry.actor || '-')}`);
        parts.push(`Action: ${formatLogAction(entry.action)}`);
        parts.push(`Status: ${formatLogStatus(entry.status)}`);
        const summaryLine = summarizeLogEntry(entry);
        if (summaryLine) parts.push(`Ringkasan: ${summaryLine}`);
        if (summaryText) {
            parts.push('');
            parts.push('Summary JSON:');
            parts.push(summaryText);
        }
        const transcript = String(entry.log_text || '').trim();
        if (transcript) {
            parts.push('');
            parts.push('Transcript:');
            parts.push(transcript);
        }
    } else if (lastLogExcerpt.value) {
        parts.push(String(lastLogExcerpt.value).trim());
    }
    const text = parts.join('\n').trim();
    if (!text) return;
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // ignore
    }
}

function storeRegisteredMemoryCache(oltId) {
    const key = String(oltId || '').trim();
    if (!key) return;
    if (regSearchMode.value) return;
    memoryRegisteredCache[key] = {
        list: Array.isArray(registered.value) ? registered.value.slice() : [],
        fspList: Array.isArray(fspList.value) ? fspList.value.slice() : [],
        loadedFsp: { ...(regLoadedFsp.value || {}) },
        filter: regFilterFsp.value || '',
        page: regPage.value || 1,
        pageSize: regPageSize.value || 25,
        query: regSearch.value || '',
        sortKey: regSortKey.value || 'interface',
        sortDir: regSortDir.value || 'asc',
    };
}

function loadRegisteredMemoryCache(oltId) {
    const key = String(oltId || '').trim();
    if (!key) return false;
    const cached = memoryRegisteredCache[key];
    if (!cached || !Array.isArray(cached.list)) return false;

    suppressRegSearchWatcher = true;

    regSearchMode.value = false;
    regSearchBackup.value = null;
    if (regDbSearchTimer) {
        clearTimeout(regDbSearchTimer);
        regDbSearchTimer = null;
    }
    if (regSnSearchTimer) {
        clearTimeout(regSnSearchTimer);
        regSnSearchTimer = null;
    }
    regLastDbQuery = '';
    regLastSnQuery = '';

    registered.value = cached.list.slice();
    fspList.value = Array.isArray(cached.fspList) ? sortFspList(cached.fspList.slice()) : [];
    regLoadedFsp.value = cached.loadedFsp ? { ...cached.loadedFsp } : {};
    regFilterFsp.value = cached.filter || '';
    regPage.value = cached.page || 1;
    regPageSize.value = cached.pageSize || 25;
    regSearch.value = cached.query || '';
    regSortKey.value = cached.sortKey || 'interface';
    regSortDir.value = cached.sortDir || 'asc';

    setTimeout(() => {
        suppressRegSearchWatcher = false;
    }, 0);

    return true;
}

async function onOltChanged(newId, prevId) {
    stopLiveStatus();
    stopAutoRegisterPolling();
    stopAutoRegisterRealtime();
    hideAutoRegisterSetupModal();
    hideAutoRegisterModal();

    if (prevId) {
        storeUncfgCache(prevId);
        storeRegisteredMemoryCache(prevId);
    }

    // Reset UI state
    fspList.value = [];

    uncfg.value = [];
    clearUncfgSelection();
    registerBusy.value = false;
    manualRegisterActive.value = false;
    registerProgressText.value = 'Registrasi berjalan...';
    setUncfgStatus(newId ? 'Pilih OLT untuk mulai scan.' : '', 'info');

    registered.value = [];
    regFilterFsp.value = '';
    regQuickFilter.value = 'all';
    regSelectedKeys.value = [];
    regBulkActionBusy.value = false;
    closeRegBulkProgress();
    hideRegToast();
    suppressRegSearchWatcher = true;
    regSearch.value = '';
    setTimeout(() => {
        suppressRegSearchWatcher = false;
    }, 0);
    regSearchMode.value = false;
    regLoadedFsp.value = {};
    cancelRegisteredSearchLiveRefresh();
    regDetailModalOpen.value = false;
    regExpandedKey.value = '';
    regDetails.value = {};
    regRxHistoryOpen.value = false;
    regRxHistoryRange.value = '24h';
    regRxHistoryPage.value = 1;
    clearRxHistoryCache();
    cancelEditOnuName();
    setRegStatus(newId ? 'Pilih F/S/P dulu.' : '', 'info');

    logs.value = [];
    logMeta.value = {
        total: 0,
        filtered_total: 0,
        actors: [],
        actions: [],
        statuses: [],
    };
    selectedLogId.value = null;
    lastLogExcerpt.value = '';
    showLogPanel.value = false;

    if (!newId) return;

    // Restore in-memory cache first (native parity).
    loadUncfgCache(newId);
    const hadReg = loadRegisteredMemoryCache(newId);
    if (hadReg) {
        const hasData = registered.value.length || Object.keys(regLoadedFsp.value || {}).length;
        if (hasData) setRegStatus('', 'info');
        if (uncfg.value.length) setUncfgStatus('', 'info');
    }

    await loadFspList();
    await loadLogs();
}

watch(selectedOltId, (val, oldVal) => {
    writeLastSelectedOltId(val);
    stopOltMetaPolling();
    onOltChanged(val, oldVal).catch(() => {});
    startOltMetaPolling();
    openPortSlotModalFromLink();
});

watch(logFilterQueryKey, () => {
    if (!selectedOltId.value || isTeknisi.value) return;
    loadLogs().catch(() => {});
});

onMounted(async () => {
    openPortSlotFromLink.value = shouldOpenPortSlotFromLocation();
    await loadOlts();
    startOltMetaPolling();
    openPortSlotModalFromLink();
});

onBeforeUnmount(() => {
    stopLiveStatus();
    stopOltMetaPolling();
    stopAutoRegisterPolling();
    stopAutoRegisterRealtime();
    hideAutoRegisterSetupModal();
    hideAutoRegisterModal();
    if (regNameSavedTimer) clearTimeout(regNameSavedTimer);
    if (regStatusTimer) clearTimeout(regStatusTimer);
    if (regToastTimer) clearTimeout(regToastTimer);
});
</script>

<template>
    <Head title="OLT Provisioning" />
    <AdminLayout>
        <div id="olt-root" class="px-3 py-4 sm:p-6">
            <div class="flex flex-col gap-6 fade-in pb-20 md:pb-0">
                <div class="border-b border-slate-200 dark:border-white/10 pb-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                        <div>
                            <h2 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
                                OLT Provisioning
                            </h2>
                            <p class="text-slate-500 dark:text-slate-400 text-xs md:text-sm mt-1">
                                Registrasi ONU ZTE C320 via telnet (auto & manual).
                            </p>
                        </div>
                        <div v-if="!isTeknisi" class="flex items-center gap-3">
                            <div class="hidden md:block text-xs text-slate-400">Kelola profil OLT dari header dan panel pilihan.</div>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-slate-950"
                                @click="openOltModal('add')"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14m-7-7h14" />
                                </svg>
                                Tambah OLT
                            </button>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-white/10 p-4 md:p-5 space-y-4">
                    <div class="flex items-end gap-2">
                        <div class="flex-1 space-y-2">
                            <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide">Pilih OLT</label>
                            <select
                                v-model="selectedOltId"
                                class="w-full h-12 border border-slate-200 dark:border-white/10 rounded-lg px-4 text-sm font-semibold bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                :disabled="loadingOlts"
                            >
                                <option value="">Pilih OLT...</option>
                                <option v-for="olt in olts" :key="olt.id" :value="String(olt.id)">
                                    {{ olt.nama_olt || `OLT #${olt.id}` }}
                                </option>
                            </select>
                        </div>
                        <div class="flex shrink-0 items-end gap-2">
                            <button
                                type="button"
                                class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-sm font-bold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/15 sm:px-4"
                                :disabled="!selectedOltId"
                                title="Slot Port OLT"
                                @click="openPortSlotModal()"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16" />
                                </svg>
                                <span class="hidden sm:inline">Slot Port</span>
                            </button>
                            <button
                                v-if="!isTeknisi"
                                type="button"
                                class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                                :disabled="!selectedOltId"
                                @click="openOltModal('edit')"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536M9 11l6.768-6.768a2.5 2.5 0 113.536 3.536L12.536 14.536A4 4 0 019.708 15.7L7 16l.3-2.708A4 4 0 018.464 10.464L15.232 3.696" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div v-if="selectedOlt" class="space-y-4 border-t border-slate-200 pt-4 dark:border-white/10">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-end">
                            <template v-if="isTeknisi">
                                <div
                                    class="grid w-full grid-cols-1 gap-2 sm:grid-cols-2 xl:ml-auto xl:w-auto"
                                    :class="selectedOltWriteConfigPending ? 'xl:grid-cols-2' : 'xl:grid-cols-1'"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70 xl:min-w-[148px]"
                                        :disabled="uncfgLoading || registerBusy"
                                        @click="scanUncfg()"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m14.836 2A8.001 8.001 0 005.582 9m0 0H9m11 11v-5h-.581m0 0A8.003 8.003 0 016.582 15m13.418 0H15" />
                                        </svg>
                                        {{ uncfgLoading ? 'Scanning...' : 'Scan ONU Baru' }}
                                    </button>
                                    <button
                                        v-if="selectedOltWriteConfigPending"
                                        type="button"
                                        class="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-white/10 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/70 xl:min-w-[148px]"
                                        :disabled="registerBusy || writeConfigBusy"
                                        @click="writeConfigTeknisi()"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        {{ writeConfigBusy ? 'Menyimpan...' : 'Simpan Config' }}
                                    </button>
                                </div>
                            </template>

                            <template v-else>
                                <div
                                    class="grid w-full grid-cols-1 gap-2 sm:grid-cols-2 xl:ml-auto xl:w-auto"
                                    :class="hasAutoRegisterItems
                                        ? (selectedOltWriteConfigPending ? 'xl:grid-cols-3' : 'xl:grid-cols-2')
                                        : (selectedOltWriteConfigPending ? 'xl:grid-cols-2' : 'xl:grid-cols-1')"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70 xl:min-w-[148px]"
                                        :disabled="uncfgLoading || registerBusy"
                                        @click="scanUncfg()"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m14.836 2A8.001 8.001 0 005.582 9m0 0H9m11 11v-5h-.581m0 0A8.003 8.003 0 016.582 15m13.418 0H15" />
                                        </svg>
                                        {{ uncfgLoading ? 'Scanning...' : 'Scan ONU Baru' }}
                                    </button>
                                    <button
                                        v-if="hasAutoRegisterItems"
                                        type="button"
                                        class="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-slate-900 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-slate-950 disabled:cursor-not-allowed disabled:opacity-70 xl:min-w-[148px]"
                                        :disabled="registerBusy"
                                        @click="autoRegister()"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                                        </svg>
                                        Auto Register
                                    </button>
                                    <button
                                        v-if="selectedOltWriteConfigPending"
                                        type="button"
                                        class="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-5 text-sm font-bold text-amber-800 shadow-sm transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-amber-400/20 dark:bg-amber-500/10 dark:text-amber-200 dark:hover:bg-amber-500/15 xl:min-w-[148px]"
                                        :disabled="registerBusy || writeConfigBusy"
                                        @click="requestWriteConfig(false)"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5h14v14H5zM8 5v4h8V5" />
                                        </svg>
                                        {{ writeConfigBusy ? 'Writing...' : 'Write Config' }}
                                    </button>
                                </div>
                            </template>
                        </div>

                        <div v-if="uncfgStatus.message" class="pt-4">
                            <div
                                class="relative overflow-hidden rounded-2xl border px-4 py-3 text-[12px] font-semibold"
                                :class="getStatusToneStyle(uncfgStatus.tone).container"
                            >
                                <div
                                    v-if="uncfgStatus.tone === 'loading'"
                                    class="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent dark:via-white/10 animate-pulse"
                                ></div>
                                <div class="relative flex items-center gap-3">
                                    <span class="h-2.5 w-2.5 rounded-full" :class="getStatusToneStyle(uncfgStatus.tone).dot"></span>
                                    <span data-status-text>{{ uncfgStatus.message }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="uncfg.length" class="overflow-hidden rounded-2xl border border-slate-200 dark:border-white/10">
                            <div class="flex items-center justify-between gap-4 border-b border-slate-100 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-900/50">
                                <div class="text-[11px] font-black uppercase tracking-wide text-slate-600 dark:text-slate-300">ONU Unregistered</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">
                                    Total:
                                    <span class="font-bold text-slate-700 dark:text-slate-200">{{ uncfg.length }}</span>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm whitespace-nowrap">
                                    <thead class="bg-slate-50 dark:bg-slate-800/50 text-xs uppercase text-slate-500 dark:text-slate-400 font-bold border-b border-slate-100 dark:border-white/5">
                                        <tr v-if="canManualRegister">
                                            <th class="px-3 sm:px-6 py-3">F/S/P</th>
                                            <th class="px-3 sm:px-6 py-3">SN</th>
                                            <th class="px-3 sm:px-6 py-3 text-right">Aksi</th>
                                        </tr>
                                        <tr v-else>
                                            <th class="px-3 sm:px-6 py-3">F/S/P</th>
                                            <th class="px-3 sm:px-6 py-3">SN</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                                        <template v-if="canManualRegister">
                                            <tr
                                                v-for="(item, idx) in uncfg"
                                                :key="`${item.fsp}:${item.sn}`"
                                                class="cursor-pointer"
                                                :class="idx === uncfgSelectedIndex ? 'bg-blue-50/80 dark:bg-blue-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40'"
                                                @click="selectUncfg(idx)"
                                            >
                                                <td class="px-3 sm:px-6 py-4 text-xs font-bold text-slate-700 dark:text-slate-200">{{ item.fsp }}</td>
                                                <td class="px-3 sm:px-6 py-4">
                                                    <div class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ item.sn }}</div>
                                                    <div class="text-[10px] text-slate-400">Ketuk untuk pilih</div>
                                                </td>
                                                <td class="px-3 sm:px-6 py-4 text-right">
                                                    <button
                                                        type="button"
                                                        class="h-9 px-4 rounded-xl text-xs font-bold"
                                                        :class="idx === uncfgSelectedIndex ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                                                        @click.stop="selectUncfg(idx)"
                                                    >
                                                        Pilih
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>

                                        <template v-else>
                                            <tr v-for="item in uncfg" :key="`${item.fsp}:${item.sn}`" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                                <td class="px-3 sm:px-6 py-3 text-xs font-bold text-slate-700 dark:text-slate-200">{{ item.fsp }}</td>
                                                <td class="px-3 sm:px-6 py-3 text-xs text-slate-600 dark:text-slate-300">{{ item.sn }}</td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    v-if="selectedOlt"
                    id="olt-reg-card"
                    class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-white/10 overflow-hidden"
                >
                    <div class="p-4 md:p-5 border-b border-slate-100 dark:border-white/10 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 flex items-center justify-center border border-emerald-100 dark:border-emerald-500/20">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7 6h10a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2V8a2 2 0 012-2z" />
                                </svg>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <div class="text-base font-black text-slate-700 dark:text-slate-200 uppercase">ONU Registered</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="regStatus.message" class="px-4 md:px-5 pt-3 pb-3">
                        <div class="text-xs">
                            <div
                                class="relative overflow-hidden rounded-2xl border px-4 py-3 text-[12px] font-semibold"
                                :class="getStatusToneStyle(regStatus.tone).container"
                            >
                                <div
                                    v-if="regStatus.tone === 'loading'"
                                    class="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent dark:via-white/10 animate-pulse"
                                ></div>
                                <div class="relative flex items-center gap-3">
                                    <span class="h-2.5 w-2.5 rounded-full" :class="getStatusToneStyle(regStatus.tone).dot"></span>
                                    <span data-status-text>{{ regStatus.message }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="px-4 md:px-5 pb-4 space-y-3">
                        <div class="space-y-2.5">
                            <div>
                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Cari ONU</label>
                                <div class="relative">
                                    <input
                                        v-model="regSearch"
                                        type="text"
                                        placeholder="Cari SN atau nama..."
                                        class="h-10 w-full rounded-lg border border-slate-200 bg-white px-10 pr-20 text-sm font-medium dark:border-white/10 dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z" /></svg>
                                    </span>
                                    <button
                                        v-if="regSearch || regSearchMode"
                                        type="button"
                                        class="absolute right-2 top-1/2 inline-flex h-7 -translate-y-1/2 items-center justify-center rounded-md border border-slate-200 bg-white px-2 text-[11px] font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-white/10 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800/70 dark:hover:text-white"
                                        @click="cancelRegSearch()"
                                    >
                                        Batal
                                    </button>
                                </div>
                            </div>

                            <div class="grid grid-cols-[minmax(0,1fr)_132px] gap-2 sm:grid-cols-[minmax(0,1fr)_160px]">
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Filter F/S/P</label>
                                    <select
                                        v-model="regFilterFsp"
                                        @change="changeRegFsp()"
                                        class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium dark:border-white/10 dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    >
                                        <option value="">Pilih F/S/P</option>
                                        <option value="all">Semua F/S/P</option>
                                        <option v-for="fsp in fspList" :key="fsp" :value="fsp">{{ formatFspOptionLabel(fsp) }}</option>
                                    </select>
                                    <div class="mt-1 text-[10px] text-slate-400">{{ regFspInfoText }}</div>
                                    <div v-if="regRxLoadingInfoText" class="mt-1 text-[10px] text-blue-500">
                                        {{ regRxLoadingInfoText }}
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Status</label>
                                    <select
                                        :value="regQuickFilter"
                                        class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium dark:border-white/10 dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        @change="setRegQuickFilterValue($event.target.value)"
                                    >
                                        <option
                                            v-for="opt in regQuickFilterOptions"
                                            :key="`reg-qf-${opt.value}`"
                                            :value="opt.value"
                                        >
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                            <span
                                v-if="regHasSelectedFsp"
                                class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-semibold dark:border-white/10 dark:bg-slate-900/50"
                            >
                                Total {{ regTotal }}
                            </span>
                            <span
                                v-if="regHasSelectedFsp"
                                class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
                            >
                                Online {{ regOnlineTotal }}
                            </span>
                            <span
                                v-if="regHasSelectedFsp"
                                class="rounded-full border border-slate-200 bg-white px-2.5 py-1 font-semibold dark:border-white/10 dark:bg-slate-900/70"
                            >
                                Offline {{ regOfflineTotal }}
                            </span>
                            <span
                                v-if="regSelectedCount"
                                class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 font-semibold text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300"
                            >
                                Dipilih {{ regSelectedCount }}
                            </span>
                        </div>

                        <div
                            v-if="regBulkProgress.visible"
                            class="rounded-2xl border px-3 py-3 text-[12px] md:px-4"
                            :class="getStatusToneStyle(regBulkProgress.tone).container"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full" :class="getStatusToneStyle(regBulkProgress.tone).dot"></span>
                                        <span class="font-bold text-[12px] uppercase tracking-wide">
                                            {{ regBulkProgress.done ? `Bulk ${regBulkProgress.action} selesai` : `Bulk ${regBulkProgress.action}` }}
                                        </span>
                                    </div>
                                    <div class="mt-1 text-[12px] font-semibold">
                                        {{ regBulkProgress.message }}
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/60 dark:bg-slate-950/40">
                                        <div
                                            class="h-full rounded-full bg-current transition-all duration-300"
                                            :style="{ width: `${Math.min(100, Math.max(0, regBulkProgress.total ? (regBulkProgress.current / regBulkProgress.total) * 100 : 0))}%` }"
                                        ></div>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] font-semibold">
                                        <span>{{ regBulkProgress.current }}/{{ regBulkProgress.total }}</span>
                                        <span>Berhasil {{ regBulkProgress.success }}</span>
                                        <span>Gagal {{ regBulkProgress.failed }}</span>
                                        <span v-if="!regBulkProgress.done && regBulkProgress.currentLabel" class="truncate">
                                            ONU {{ regBulkProgress.currentLabel }}
                                        </span>
                                    </div>
                                </div>
                                <button
                                    v-if="regBulkProgress.done"
                                    type="button"
                                    class="inline-flex h-8 shrink-0 items-center justify-center rounded-lg border border-current/20 px-2.5 text-[11px] font-bold hover:bg-white/40 dark:hover:bg-slate-950/20"
                                    @click="closeRegBulkProgress()"
                                >
                                    Tutup
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="regSelectedCount"
                            class="grid grid-cols-2 gap-2 rounded-xl border border-slate-200/80 bg-slate-50/80 p-2 dark:border-white/10 dark:bg-slate-900/30 sm:flex sm:flex-wrap"
                        >
                            <button
                                type="button"
                                class="inline-flex h-9 w-full items-center justify-center rounded-lg border border-amber-200 px-3 text-[11px] font-bold text-amber-700 transition hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-500/30 dark:text-amber-300 dark:hover:bg-amber-500/10 sm:w-auto"
                                :disabled="regBulkActionBusy"
                                @click="runRegisteredBulkAction('restart')"
                            >
                                {{ regBulkActionBusy ? 'Memproses...' : 'Restart Terpilih' }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-9 w-full items-center justify-center rounded-lg border border-rose-200 px-3 text-[11px] font-bold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-500/30 dark:text-rose-300 dark:hover:bg-rose-500/10 sm:w-auto"
                                :disabled="regBulkActionBusy"
                                @click="runRegisteredBulkAction('delete')"
                            >
                                {{ regBulkActionBusy ? 'Memproses...' : 'Hapus Terpilih' }}
                            </button>
                            <button
                                type="button"
                                class="col-span-2 inline-flex h-9 w-full items-center justify-center rounded-lg border border-slate-200 px-3 text-[11px] font-bold text-slate-500 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800/70 sm:col-auto sm:w-auto"
                                :disabled="regBulkActionBusy"
                                @click="clearRegSelection()"
                            >
                                Clear
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 text-xs uppercase text-slate-500 dark:text-slate-400 font-bold border-b border-slate-100 dark:border-white/5">
                                <tr>
                                    <th class="px-3 sm:px-4 py-3 text-left">Pilih</th>
                                    <th class="px-3 sm:px-6 py-3 text-left" :aria-sort="getRegAriaSort('interface')">
                                        <button
                                            type="button"
                                            class="group inline-flex items-center gap-1 text-xs font-bold uppercase tracking-wide text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:hover:text-white"
                                            @click.stop="setRegSort('interface')"
                                        >
                                            <span>Interface</span>
                                            <span
                                                class="text-[10px]"
                                                :class="
                                                    regSortKey === 'interface'
                                                        ? 'text-slate-500 dark:text-slate-300'
                                                        : 'text-slate-300 dark:text-slate-600 group-hover:text-slate-400 dark:group-hover:text-slate-400'
                                                "
                                                >{{ getRegSortIcon('interface') }}</span
                                            >
                                        </button>
                                    </th>
                                    <th class="px-3 sm:px-6 py-3 text-left" :aria-sort="getRegAriaSort('name')">
                                        <button
                                            type="button"
                                            class="group inline-flex items-center gap-1 text-xs font-bold uppercase tracking-wide text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:hover:text-white"
                                            @click.stop="setRegSort('name')"
                                        >
                                            <span>Nama</span>
                                            <span
                                                class="text-[10px]"
                                                :class="
                                                    regSortKey === 'name'
                                                        ? 'text-slate-500 dark:text-slate-300'
                                                        : 'text-slate-300 dark:text-slate-600 group-hover:text-slate-400 dark:group-hover:text-slate-400'
                                                "
                                                >{{ getRegSortIcon('name') }}</span
                                            >
                                        </button>
                                    </th>
                                    <th class="px-3 sm:px-6 py-3 text-left" :aria-sort="getRegAriaSort('sn')">
                                        <button
                                            type="button"
                                            class="group inline-flex items-center gap-1 text-xs font-bold uppercase tracking-wide text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:hover:text-white"
                                            @click.stop="setRegSort('sn')"
                                        >
                                            <span>SN</span>
                                            <span
                                                class="text-[10px]"
                                                :class="
                                                    regSortKey === 'sn'
                                                        ? 'text-slate-500 dark:text-slate-300'
                                                        : 'text-slate-300 dark:text-slate-600 group-hover:text-slate-400 dark:group-hover:text-slate-400'
                                                "
                                                >{{ getRegSortIcon('sn') }}</span
                                            >
                                        </button>
                                    </th>
                                    <th class="px-3 sm:px-6 py-3 text-left" :aria-sort="getRegAriaSort('rx')">
                                        <button
                                            type="button"
                                            class="group inline-flex items-center gap-1 text-xs font-bold uppercase tracking-wide text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:hover:text-white"
                                            @click.stop="setRegSort('rx')"
                                        >
                                            <span>Power Rx</span>
                                            <span
                                                class="text-[10px]"
                                                :class="
                                                    regSortKey === 'rx'
                                                        ? 'text-slate-500 dark:text-slate-300'
                                                        : 'text-slate-300 dark:text-slate-600 group-hover:text-slate-400 dark:group-hover:text-slate-400'
                                                "
                                                >{{ getRegSortIcon('rx') }}</span
                                            >
                                        </button>
                                    </th>
                                    <th class="px-3 sm:px-6 py-3 text-left" :aria-sort="getRegAriaSort('status')">
                                        <button
                                            type="button"
                                            class="group inline-flex items-center gap-1 text-xs font-bold uppercase tracking-wide text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:hover:text-white"
                                            @click.stop="setRegSort('status')"
                                        >
                                            <span>Status</span>
                                            <span
                                                class="text-[10px]"
                                                :class="
                                                    regSortKey === 'status'
                                                        ? 'text-slate-500 dark:text-slate-300'
                                                        : 'text-slate-300 dark:text-slate-600 group-hover:text-slate-400 dark:group-hover:text-slate-400'
                                                "
                                                >{{ getRegSortIcon('status') }}</span
                                            >
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                                <tr v-if="regLoading">
                                    <td colspan="6" class="px-3 sm:px-6 py-10 text-center text-slate-400 italic">
                                        {{ regLoadingText || 'Memuat data...' }}
                                    </td>
                                </tr>
                                <tr v-else-if="!registered.length">
                                    <td colspan="6" class="px-3 sm:px-6 py-10 text-center text-slate-400 italic">
                                        {{ Object.keys(regLoadedFsp || {}).length ? 'Belum ada data.' : 'Data belum dimuat.' }}
                                    </td>
                                </tr>
                                <tr v-else-if="!regPageItems.length">
                                    <td colspan="6" class="px-3 sm:px-6 py-10 text-center text-slate-400 italic">
                                        Tidak ada data yang cocok.
                                    </td>
                                </tr>
                                <template v-else>
                                    <template v-for="item in regPageItems" :key="onuKey(item)">
                                        <tr
                                            class="cursor-pointer"
                                            :class="[
                                                regDetailModalOpen && regExpandedKey === onuKey(item)
                                                    ? 'bg-emerald-50/80 dark:bg-emerald-500/10'
                                                    : 'hover:bg-slate-50 dark:hover:bg-slate-800/40',
                                                regHighlightKey === onuKey(item) ? 'ring-2 ring-emerald-400/60' : '',
                                            ]"
                                            @click="toggleRegDetail(item)"
                                        >
                                            <td class="px-3 sm:px-4 py-4" @click.stop>
                                                <input
                                                    type="checkbox"
                                                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                    :checked="isRegSelected(item)"
                                                    :disabled="regBulkActionBusy"
                                                    @change="toggleRegSelection(item)"
                                                />
                                            </td>
                                            <td class="px-3 sm:px-6 py-4 text-xs font-bold text-slate-700 dark:text-slate-200">{{ item.fsp_onu || `${item.fsp}:${item.onu_id}` }}</td>
                                            <td class="px-3 sm:px-6 py-4 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ item.name || '-' }}</td>
                                            <td class="px-3 sm:px-6 py-4 text-xs text-slate-600 dark:text-slate-300">{{ item.sn || '-' }}</td>
                                            <td class="px-3 sm:px-6 py-4 text-xs" :class="getRxTextClass(item)">{{ formatRx(item.rx) }}</td>
                                            <td class="px-3 sm:px-6 py-4">
                                                <span
                                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold"
                                                    :class="formatRegStatus(item).className"
                                                >
                                                    {{ formatRegStatus(item).label }}
                                                </span>
                                            </td>
                                        </tr>
                                    </template>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 md:px-5 py-3 border-t border-slate-100 dark:border-white/5 flex items-center justify-between gap-3">
                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            <span v-if="!regTotal">Menampilkan 0 data.</span>
                            <span v-else>
                                Menampilkan {{ regPageStart + 1 }}-{{ Math.min(regPageStart + regPageItems.length, regTotal) }} dari {{ regTotal }} (Page {{ regPage }}/{{ regTotalPages }})
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="h-9 px-3 rounded-lg border border-slate-200 dark:border-white/10 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition disabled:opacity-60 disabled:cursor-not-allowed"
                                :disabled="regPage <= 1"
                                @click="regPage = Math.max(1, regPage - 1)"
                            >
                                Prev
                            </button>
                            <button
                                type="button"
                                class="h-9 px-3 rounded-lg border border-slate-200 dark:border-white/10 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition disabled:opacity-60 disabled:cursor-not-allowed"
                                :disabled="regPage >= regTotalPages"
                                @click="regPage = Math.min(regTotalPages, regPage + 1)"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>

                <Teleport to="body">
                    <div
                        v-if="regToast.open && regToast.message"
                        class="fixed bottom-4 right-4 z-[90] w-[calc(100vw-2rem)] max-w-sm"
                    >
                        <div
                            class="rounded-2xl border px-4 py-3 shadow-lg backdrop-blur"
                            :class="getStatusToneStyle(regToast.tone).container"
                        >
                            <div class="flex items-start gap-3">
                                <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" :class="getStatusToneStyle(regToast.tone).dot"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[11px] font-black uppercase tracking-wide">
                                        {{ regToast.tone === 'error' ? 'Gagal' : 'Info ONU' }}
                                    </div>
                                    <div class="mt-1 text-sm font-semibold">
                                        {{ regToast.message }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-current/15 text-[11px] font-bold hover:bg-white/40 dark:hover:bg-slate-950/20"
                                    @click="hideRegToast()"
                                >
                                    ×
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <Teleport to="body">
                    <div v-if="regDetailModalOpen && regModalOnu" class="fixed inset-0 z-[85]">
                        <div class="absolute inset-0 bg-transparent" @click="closeRegDetailModal()"></div>
                        <div class="absolute top-1/2 left-1/2 w-[96%] sm:w-[94%] max-w-5xl -translate-x-1/2 -translate-y-1/2">
                            <div class="max-h-[90vh] bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 shadow-xl overflow-hidden flex flex-col">
                                <template v-for="item in [regModalOnu]" :key="onuKey(item)">
                                <div class="px-4 sm:px-5 py-3 border-b border-slate-100 dark:border-white/10 flex items-start justify-between gap-3">
                                    <div class="min-w-0 space-y-2">
                                        <div class="text-xs uppercase tracking-wide text-slate-400">Detail ONU</div>
                                        <div class="text-sm font-bold text-slate-800 dark:text-slate-100 truncate">
                                            {{ item.fsp_onu || `${item.fsp}:${item.onu_id}` }} - {{ item.name || item.sn || 'ONU' }}
                                        </div>
                                        <div v-if="isRegDetailSyncing(item)" class="flex flex-wrap items-center gap-2">
                                            <span
                                                v-if="isRegDetailRxLoading(item)"
                                                class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                            >
                                                <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                    <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                </svg>
                                                Memuat Rx live
                                            </span>
                                            <span
                                                v-if="isRegDetailInfoLoading(item)"
                                                class="inline-flex items-center gap-2 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-[11px] font-semibold text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300"
                                            >
                                                <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                    <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                </svg>
                                                Sinkron detail
                                            </span>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="h-8 px-3 rounded-lg border border-slate-200 dark:border-white/10 text-xs font-bold text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/70 transition"
                                        @click="closeRegDetailModal()"
                                    >
                                        Tutup
                                    </button>
                                </div>

                                <div class="flex-1 min-h-0 px-4 sm:px-5 py-4 sm:py-5 overflow-y-auto space-y-4">
                                    <div class="space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs text-slate-600 dark:text-slate-300">
                                            <div class="min-w-0 space-y-1">
                                                <div class="text-[10px] uppercase tracking-wide text-slate-400">Interface</div>
                                                <div class="font-semibold text-slate-700 dark:text-slate-200 break-all">{{ item.interface || onuKey(item) }}</div>
                                            </div>
                                            <div class="min-w-0 space-y-1">
                                                <div class="flex items-center justify-between gap-2">
                                                    <div class="text-[10px] uppercase tracking-wide text-slate-400">Nama</div>
                                                    <button
                                                        v-if="regEditingKey !== onuKey(item)"
                                                        type="button"
                                                        class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-white/10 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-200"
                                                        @click.stop="startEditOnuName(item)"
                                                    >
                                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                            <path d="M13.586 2.586a2 2 0 112.828 2.828l-8.9 8.9a2 2 0 01-.878.497l-2.54.726a.75.75 0 01-.928-.928l.726-2.54a2 2 0 01.497-.878l8.9-8.9zM12.525 4.707L5.655 11.577a.5.5 0 00-.124.22l-.364 1.273 1.273-.364a.5.5 0 00.22-.124l6.87-6.87-1.005-1.005z" />
                                                        </svg>
                                                    </button>
                                                </div>
                                                <div v-if="regEditingKey === onuKey(item)">
                                                    <div class="space-y-2 rounded-xl border border-emerald-200/80 bg-emerald-50/60 p-2.5 dark:border-emerald-500/20 dark:bg-emerald-500/5">
                                                        <input
                                                            v-model="regEditingName"
                                                            class="block w-full max-w-full rounded-lg border border-emerald-200 bg-white/90 px-3 py-2 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-400/40 dark:border-emerald-500/20 dark:bg-slate-900/60 dark:text-slate-200"
                                                            @keydown.enter.prevent="saveEditOnuName(item)"
                                                            @keydown.esc.prevent="cancelEditOnuName()"
                                                        />
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <button
                                                                type="button"
                                                                class="inline-flex items-center gap-2 rounded-lg border border-emerald-300 px-3 py-1.5 text-[11px] font-bold text-emerald-700 transition hover:bg-emerald-100/70 disabled:cursor-not-allowed disabled:opacity-60 dark:border-emerald-500/30 dark:text-emerald-300 dark:hover:bg-emerald-500/10"
                                                                :disabled="isRowActionLoading(onuKey(item), 'rename')"
                                                                @click.stop="saveEditOnuName(item)"
                                                            >
                                                                <span v-if="isRowActionLoading(onuKey(item), 'rename')" class="inline-flex h-4 w-4 items-center justify-center">
                                                                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                                        <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                                    </svg>
                                                                </span>
                                                                Simpan
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="rounded-lg border border-slate-200 px-3 py-1.5 text-[11px] font-bold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800/60"
                                                                @click.stop="cancelEditOnuName()"
                                                            >
                                                                Batal
                                                            </button>
                                                        </div>
                                                        <div v-if="regEditingError" class="text-[11px] font-medium text-rose-600 dark:text-rose-300">
                                                            {{ regEditingError }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div v-else class="space-y-1">
                                                    <div class="font-semibold text-slate-700 dark:text-slate-200">{{ item.name || '-' }}</div>
                                                    <div
                                                        v-if="regNameSavedKey === onuKey(item)"
                                                        class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
                                                    >
                                                        Nama tersimpan
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="min-w-0 space-y-1">
                                                <div class="text-[10px] uppercase tracking-wide text-slate-400">SN</div>
                                                <div class="font-semibold text-slate-700 dark:text-slate-200 break-all">{{ item.sn || '-' }}</div>
                                            </div>
                                            <div class="min-w-0 space-y-1">
                                                <div class="text-[10px] uppercase tracking-wide text-slate-400">Status</div>
                                                <div class="font-semibold text-slate-700 dark:text-slate-200">{{ formatRegStatus(item).label }}</div>
                                            </div>
                                            <div class="min-w-0 space-y-1">
                                                <div class="text-[10px] uppercase tracking-wide text-slate-400">Power Rx</div>
                                                <div class="inline-flex min-h-[1.25rem] items-center gap-2 font-semibold" :class="getRxTextClass(item)">
                                                    <span
                                                        v-if="isRegDetailRxLoading(item)"
                                                        class="inline-flex h-4 w-4 items-center justify-center text-emerald-500"
                                                    >
                                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                            <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                        </svg>
                                                    </span>
                                                    <span>{{ extractRxValue(item.rx) !== null ? formatRx(item.rx) : '-' }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="rounded-xl border border-slate-200/80 dark:border-white/10 bg-slate-50/70 dark:bg-slate-900/40 p-3 sm:p-4 space-y-3">
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <div class="text-[10px] uppercase tracking-wide text-slate-400">Histori Rx</div>
                                                    <div class="text-xs font-semibold text-slate-600 dark:text-slate-300">
                                                        {{ regRxHistoryOpen ? `Periode aktif: ${rxHistoryRangeLabel(regRxHistoryRange)}` : 'Klik tombol untuk menampilkan histori Rx.' }}
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-2 px-3 py-2 text-[11px] font-bold rounded-lg border transition"
                                                    :class="
                                                        regRxHistoryOpen
                                                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                            : 'border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800/70'
                                                    "
                                                    @click.stop="toggleOnuRxHistory(item)"
                                                >
                                                    <span>{{ regRxHistoryOpen ? 'Sembunyikan Riwayat Rx' : 'Riwayat Rx' }}</span>
                                                    <svg
                                                        class="h-3.5 w-3.5 transition-transform"
                                                        :class="regRxHistoryOpen ? 'rotate-180' : ''"
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                    >
                                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.51a.75.75 0 01-1.08 0l-4.25-4.51a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                                    </svg>
                                                </button>
                                            </div>

                                            <div v-if="regRxHistoryOpen" class="space-y-3">
                                                <div class="grid grid-cols-3 gap-1.5 w-full sm:w-auto sm:flex sm:flex-wrap">
                                                    <button
                                                        v-for="opt in regRxHistoryRanges"
                                                        :key="opt.value"
                                                        type="button"
                                                        class="w-full sm:w-auto px-2 py-1.5 text-[11px] rounded-lg border transition"
                                                        :class="
                                                            isOnuRxHistoryRangeActive(opt.value)
                                                                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                                : 'border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800/70'
                                                        "
                                                        @click.stop="setOnuRxHistoryRange(item, opt.value)"
                                                    >
                                                        {{ opt.label }}
                                                    </button>
                                                </div>

                                                <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-1.5 sm:gap-x-3 sm:gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
                                                    <span class="rounded-md bg-white/80 dark:bg-slate-900/50 px-2 py-1">Total: {{ getOnuRxHistoryMeta(item).count ?? 0 }}</span>
                                                    <span class="rounded-md bg-white/80 dark:bg-slate-900/50 px-2 py-1">Latest: {{ formatRx(getOnuRxHistoryMeta(item).latest) }}</span>
                                                    <span class="rounded-md bg-white/80 dark:bg-slate-900/50 px-2 py-1">Min: {{ formatRx(getOnuRxHistoryMeta(item).min) }}</span>
                                                    <span class="rounded-md bg-white/80 dark:bg-slate-900/50 px-2 py-1">Max: {{ formatRx(getOnuRxHistoryMeta(item).max) }}</span>
                                                    <span class="rounded-md bg-white/80 dark:bg-slate-900/50 px-2 py-1 col-span-2 sm:col-span-1">Avg: {{ formatRx(getOnuRxHistoryMeta(item).avg) }}</span>
                                                </div>

                                                <div v-if="isOnuRxHistoryLoading(item)" class="text-xs text-blue-600 dark:text-blue-300">
                                                    Memuat histori Rx...
                                                </div>
                                                <div v-else-if="getOnuRxHistoryError(item)" class="text-xs text-rose-600 dark:text-rose-300">
                                                    {{ getOnuRxHistoryError(item) }}
                                                </div>
                                                <div v-else-if="regRxHistoryTotal === 0" class="text-xs text-slate-500 dark:text-slate-400 italic">
                                                    Belum ada snapshot Rx untuk periode ini.
                                                </div>
                                                <div v-else class="space-y-3">
                                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                                        <div class="text-[11px] text-slate-500 dark:text-slate-400">
                                                            Menampilkan {{ regRxHistoryPageStart + 1 }}-{{ regRxHistoryPageEnd }} dari {{ regRxHistoryTotal }} data
                                                        </div>
                                                        <label class="inline-flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                                                            <span>Tampilkan</span>
                                                            <select
                                                                v-model.number="regRxHistoryPageSize"
                                                                class="h-8 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 px-2 text-[11px] font-semibold text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-400/40"
                                                                @click.stop
                                                            >
                                                                <option v-for="opt in regRxHistoryPageSizeOptions" :key="`rx-size-${opt}`" :value="opt">
                                                                    {{ opt }}
                                                                </option>
                                                            </select>
                                                            <span>baris</span>
                                                        </label>
                                                    </div>

                                                    <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-white/10">
                                                        <table class="min-w-full text-xs">
                                                            <thead class="bg-slate-100/80 dark:bg-slate-800/70 text-slate-500 dark:text-slate-300 uppercase tracking-wide">
                                                                <tr>
                                                                    <th class="px-3 py-2 text-left">Waktu</th>
                                                                    <th class="px-3 py-2 text-left">Rx</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100 dark:divide-white/10 bg-white/80 dark:bg-slate-900/30">
                                                                <tr v-for="(rxItem, idx) in regRxHistoryPageRows" :key="`${onuKey(item)}-rx-${idx}-${rxItem.sampled_at}`">
                                                                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300 whitespace-nowrap">
                                                                        {{ formatSampledAt(rxItem.sampled_at) }}
                                                                    </td>
                                                                    <td class="px-3 py-2 font-semibold" :class="getRxToneClass(extractRxValue(rxItem.rx_power))">
                                                                        {{ formatRx(rxItem.rx_power) }}
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>

                                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                                        <div class="text-[11px] text-slate-500 dark:text-slate-400">
                                                            Halaman {{ regRxHistoryPage }} / {{ regRxHistoryTotalPages }}
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <button
                                                                type="button"
                                                                class="h-8 px-3 rounded-lg border border-slate-200 dark:border-white/10 text-[11px] font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition disabled:opacity-60 disabled:cursor-not-allowed"
                                                                :disabled="regRxHistoryPage <= 1"
                                                                @click.stop="regRxHistoryPage = Math.max(1, regRxHistoryPage - 1)"
                                                            >
                                                                Prev
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="h-8 px-3 rounded-lg border border-slate-200 dark:border-white/10 text-[11px] font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition disabled:opacity-60 disabled:cursor-not-allowed"
                                                                :disabled="regRxHistoryPage >= regRxHistoryTotalPages"
                                                                @click.stop="regRxHistoryPage = Math.min(regRxHistoryTotalPages, regRxHistoryPage + 1)"
                                                            >
                                                                Next
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                                <div class="shrink-0 px-4 sm:px-5 py-3 border-t border-slate-100 dark:border-white/10 bg-white/95 dark:bg-slate-900/95">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="px-3 py-1.5 text-xs font-bold rounded-lg border border-blue-200 text-blue-700 hover:bg-blue-50 transition disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center gap-2"
                                            :disabled="isRowActionLoading(onuKey(item), 'refresh')"
                                            @click.stop="refreshRegisteredOnu(item)"
                                        >
                                            <span v-if="isRowActionLoading(onuKey(item), 'refresh')" class="inline-flex h-4 w-4 items-center justify-center">
                                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                    <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                </svg>
                                            </span>
                                            Refresh
                                        </button>

                                        <button
                                            type="button"
                                            class="px-3 py-1.5 text-xs font-bold rounded-lg border border-amber-200 text-amber-700 hover:bg-amber-50 transition disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center gap-2"
                                            :disabled="isRowActionLoading(onuKey(item), 'restart')"
                                            @click.stop="restartRegisteredOnu(item)"
                                        >
                                            <span v-if="isRowActionLoading(onuKey(item), 'restart')" class="inline-flex h-4 w-4 items-center justify-center">
                                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                    <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                </svg>
                                            </span>
                                            Restart
                                        </button>

                                        <button
                                            type="button"
                                            class="px-3 py-1.5 text-xs font-bold rounded-lg border border-rose-300 text-rose-700 hover:bg-rose-100 transition disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center gap-2"
                                            :disabled="isRowActionLoading(onuKey(item), 'delete')"
                                            @click.stop="deleteRegisteredOnu(item)"
                                        >
                                            <span v-if="isRowActionLoading(onuKey(item), 'delete')" class="inline-flex h-4 w-4 items-center justify-center">
                                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                    <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                </svg>
                                            </span>
                                            Hapus ONU
                                        </button>
                                    </div>
                                </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </Teleport>
                <div
                    v-if="selectedOlt && !isTeknisi"
                    class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-white/10 overflow-hidden"
                >
                    <div class="p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="text-xs font-black uppercase text-slate-700 dark:text-slate-200">Histori Log Command</div>
                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-bold text-slate-600 dark:border-white/10 dark:bg-slate-900/60 dark:text-slate-300">
                                    {{ logMeta.filtered_total || 0 }} / {{ logMeta.total || 0 }}
                                </span>
                                <span v-if="logsLoading" class="inline-flex items-center gap-1.5 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-[11px] font-semibold text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-200">
                                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                    </svg>
                                    Memuat
                                </span>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 px-3 text-[11px] font-bold text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-700/40"
                                @click="showLogPanel = !showLogPanel"
                            >
                                {{ showLogPanel ? 'Tutup Histori' : 'Buka Histori' }}
                            </button>
                        </div>
                    </div>
                    <div v-if="showLogPanel" class="border-t border-slate-100 dark:border-white/5">
                        <div class="space-y-3 p-4">
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_140px]">
                                <select
                                    v-model="logFilters.actor"
                                    class="h-9 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 px-3 text-[12px] font-semibold text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-900/10"
                                >
                                    <option value="">Semua actor</option>
                                    <option v-for="actor in logMeta.actors" :key="`log-actor-${actor}`" :value="actor">
                                        {{ actor }}
                                    </option>
                                </select>
                                <select
                                    v-model="logFilters.action"
                                    class="h-9 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 px-3 text-[12px] font-semibold text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-900/10"
                                >
                                    <option value="">Semua aksi</option>
                                    <option v-for="action in logMeta.actions" :key="`log-action-${action}`" :value="action">
                                        {{ formatLogAction(action) }}
                                    </option>
                                </select>
                                <select
                                    v-model="logFilters.status"
                                    class="h-9 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 px-3 text-[12px] font-semibold text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-900/10"
                                >
                                    <option value="">Semua status</option>
                                    <option v-for="status in logMeta.statuses" :key="`log-status-${status}`" :value="status">
                                        {{ formatLogStatus(status) }}
                                    </option>
                                </select>
                                <select
                                    v-model.number="logFilters.limit"
                                    class="h-9 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 px-3 text-[12px] font-semibold text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-900/10"
                                >
                                    <option :value="50">50 log</option>
                                    <option :value="100">100 log</option>
                                    <option :value="200">200 log</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <label class="inline-flex min-h-9 items-center gap-2 rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50/80 dark:bg-slate-900/60 px-3 py-2 text-[12px] font-semibold text-slate-600 dark:text-slate-300">
                                    <input
                                        v-model="logFilters.hideConnectFailed"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400"
                                    >
                                    <span>Sembunyikan connect failed</span>
                                </label>
                                <button
                                    v-if="hasActiveLogFilters"
                                    type="button"
                                    class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 px-3 text-[11px] font-bold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-700/40"
                                    @click="resetLogFilters()"
                                >
                                    Reset Filter
                                </button>
                            </div>
                        </div>
                        <div v-if="!logs.length" class="border-t border-slate-100 p-5 text-sm text-slate-500 dark:border-white/5 dark:text-slate-400">
                            Tidak ada log yang cocok dengan filter saat ini.
                        </div>
                        <div v-else class="divide-y divide-slate-100 dark:divide-white/5">
                            <div
                                v-for="entry in logs"
                                :key="`log-row-${entry.id}`"
                                class="border-t border-slate-100 p-4 first:border-t-0 dark:border-white/5"
                            >
                                <button
                                    type="button"
                                    class="w-full text-left"
                                    @click="toggleLogEntry(entry.id)"
                                >
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-[12px] font-black text-slate-800 dark:text-white">
                                                        {{ formatLogAction(entry.action) }}
                                                    </span>
                                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide" :class="logStatusTone(entry.status)">
                                                        {{ formatLogStatus(entry.status) }}
                                                    </span>
                                                </div>
                                            </div>
                                            <span class="text-[11px] text-slate-400 dark:text-slate-500">
                                                {{ formatLogCreatedAt(entry.created_at) }}
                                            </span>
                                        </div>
                                        <div class="break-words text-xs font-semibold leading-relaxed text-slate-600 dark:text-slate-300">
                                            {{ summarizeLogEntry(entry) || 'Tanpa ringkasan tambahan.' }}
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-400 dark:text-slate-500">
                                            <span>{{ entry.actor || '-' }}</span>
                                            <span v-if="entry.olt_name">{{ entry.olt_name }}</span>
                                            <span>#{{ entry.id }}</span>
                                        </div>
                                    </div>
                                </button>

                                <div
                                    v-if="selectedLogEntry && String(selectedLogEntry.id) === String(entry.id)"
                                    class="mt-4 space-y-4 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-slate-900/50"
                                >
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                            Detail Log
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex h-8 items-center justify-center rounded-lg border border-slate-200 px-3 text-[11px] font-bold text-slate-600 transition hover:bg-white dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800"
                                            @click.stop="copyCurrentLog()"
                                        >
                                            Copy Detail
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2 text-[11px]">
                                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900/70">
                                            <div class="text-[10px] font-black uppercase tracking-wide text-slate-400 dark:text-slate-500">Waktu</div>
                                            <div class="mt-1 break-words font-semibold text-slate-600 dark:text-slate-300">
                                                {{ formatLogCreatedAt(entry.created_at) }}
                                            </div>
                                        </div>
                                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900/70">
                                            <div class="text-[10px] font-black uppercase tracking-wide text-slate-400 dark:text-slate-500">Actor</div>
                                            <div class="mt-1 break-words font-semibold text-slate-600 dark:text-slate-300">
                                                {{ entry.actor || '-' }}
                                            </div>
                                        </div>
                                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900/70">
                                            <div class="text-[10px] font-black uppercase tracking-wide text-slate-400 dark:text-slate-500">OLT</div>
                                            <div class="mt-1 break-words font-semibold text-slate-600 dark:text-slate-300">
                                                {{ entry.olt_name || '-' }}
                                            </div>
                                        </div>
                                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900/70">
                                            <div class="text-[10px] font-black uppercase tracking-wide text-slate-400 dark:text-slate-500">Log ID</div>
                                            <div class="mt-1 break-words font-semibold text-slate-600 dark:text-slate-300">
                                                #{{ entry.id }}
                                            </div>
                                        </div>
                                    </div>

                                    <div v-if="selectedLogSummaryEntries.length" class="space-y-2">
                                        <div class="text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">Ringkasan</div>
                                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                            <div
                                                v-for="item in selectedLogSummaryEntries"
                                                :key="`log-summary-${entry.id}-${item.key}`"
                                                class="rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900/80"
                                            >
                                                <div class="text-[10px] font-black uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                                    {{ item.label }}
                                                </div>
                                                <div class="mt-1 break-words text-[12px] font-semibold text-slate-700 dark:text-slate-200">
                                                    {{ item.value }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-else-if="selectedLogSummaryPretty" class="space-y-2">
                                        <div class="text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">Summary JSON</div>
                                        <pre class="overflow-x-auto whitespace-pre-wrap rounded-2xl border border-slate-200 bg-white p-4 text-[11px] text-slate-700 dark:border-white/10 dark:bg-slate-950/40 dark:text-slate-200">{{ selectedLogSummaryPretty }}</pre>
                                    </div>

                                    <div class="space-y-2">
                                        <div class="text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">Transcript Command</div>
                                        <pre class="max-h-[26rem] overflow-x-auto whitespace-pre-wrap rounded-2xl bg-slate-950 p-4 text-[11px] text-slate-100 sm:max-h-[30rem]">{{ selectedLogTranscript }}</pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <Teleport to="body">
                    <div v-if="showOltModal" class="fixed inset-0 z-[90]">
                        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" @click="showOltModal = false"></div>
                        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6">
                            <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-[28px] border border-slate-200/80 bg-white shadow-[0_32px_90px_rgba(15,23,42,0.28)] dark:border-white/10 dark:bg-slate-900">
                                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <div>
                                        <div class="text-[11px] font-black uppercase tracking-[0.32em] text-slate-400 dark:text-slate-500">OLT Setup</div>
                                        <div class="mt-1 text-base font-black text-slate-800 dark:text-white">
                                            {{ editingOltId ? 'Edit OLT' : 'Tambah OLT' }}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 text-lg text-slate-400 transition hover:border-slate-300 hover:text-slate-600 dark:border-white/10 dark:text-slate-500 dark:hover:border-white/20 dark:hover:text-slate-300"
                                        @click="showOltModal = false"
                                    >
                                        ✕
                                    </button>
                                </div>

                                <div class="flex-1 overflow-y-auto px-5 py-5 sm:px-6">
                                    <div class="space-y-4">
                                        <div>
                                            <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Nama OLT</label>
                                            <input
                                                v-model="formData.nama_olt"
                                                type="text"
                                                class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                            />
                                        </div>

                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                            <div class="sm:col-span-2">
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Host/IP</label>
                                                <input
                                                    v-model="formData.host"
                                                    type="text"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Port</label>
                                                <input
                                                    v-model="formData.port"
                                                    type="number"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Username</label>
                                                <input
                                                    v-model="formData.username"
                                                    type="text"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">
                                                    Password {{ editingOltId ? '(kosongkan jika tidak diubah)' : '' }}
                                                </label>
                                                <input
                                                    v-model="formData.password"
                                                    type="password"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">TCONT Default</label>
                                                <input
                                                    v-model="formData.tcont_default"
                                                    type="text"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">VLAN Default</label>
                                                <input
                                                    v-model="formData.vlan_default"
                                                    type="number"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">ONU Type Default</label>
                                                <input
                                                    v-model="formData.onu_type_default"
                                                    type="text"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Service-Port ID (Fixed)</label>
                                                <input
                                                    v-model="formData.service_port_id_default"
                                                    type="number"
                                                    min="1"
                                                    max="65535"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">ONU Rx Max Teknisi</label>
                                                <input
                                                    v-model="formData.teknisi_onu_rx_max_dbm"
                                                    type="number"
                                                    step="0.01"
                                                    max="0"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">ONU Rx Min Teknisi</label>
                                                <input
                                                    v-model="formData.teknisi_onu_rx_min_dbm"
                                                    type="number"
                                                    step="0.01"
                                                    max="0"
                                                    class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                                />
                                            </div>
                                        </div>

                                    </div>
                                </div>

                                <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <button
                                        v-if="editingOltId"
                                        type="button"
                                        class="h-11 rounded-lg bg-rose-600 px-4 text-sm font-bold text-white transition hover:bg-rose-700"
                                        @click="deleteOlt()"
                                    >
                                        Hapus OLT
                                    </button>
                                    <div class="ml-auto flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="h-11 rounded-lg bg-slate-100 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-200"
                                            @click="showOltModal = false"
                                        >
                                            Batal
                                        </button>
                                        <button
                                            type="button"
                                            class="h-11 rounded-lg bg-slate-900 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-slate-950"
                                            @click="saveOlt()"
                                        >
                                            Simpan
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <div v-if="syncOpen" id="olt-sync-modal" class="fixed inset-0 z-[80]">
                    <div class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm"></div>
                    <div class="absolute top-1/2 left-1/2 w-[92%] max-w-md -translate-x-1/2 -translate-y-1/2">
                        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-white/10 shadow-xl p-5">
                            <div class="flex items-start gap-3">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                                    </svg>
                                </span>
                                <div class="flex-1">
                                    <div class="text-sm font-bold text-slate-800 dark:text-white">Sinkronisasi ONU</div>
                                    <div id="olt-sync-text" class="text-xs text-slate-500 mt-1">{{ syncText }}</div>
                                    <div id="olt-sync-note" class="text-[11px] text-slate-400 mt-2">{{ syncNote }}</div>
                                    <div id="olt-sync-eta" class="text-[11px] text-slate-400 mt-1">{{ syncEta }}</div>
                                    <div class="mt-3 h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                        <div
                                            id="olt-sync-bar"
                                            class="h-full bg-emerald-500 transition-all"
                                            :style="{ width: `${Math.round(syncPercent)}%` }"
                                        ></div>
                                    </div>
                                    <div id="olt-sync-percent" class="mt-2 text-[11px] text-slate-500">{{ Math.round(syncPercent) }}%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <Teleport to="body">
                    <div v-if="portSlotModalOpen" class="fixed inset-0 z-[93]">
                        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" @click="closePortSlotModal()"></div>
                        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6">
                            <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-[28px] border border-slate-200/80 bg-white shadow-[0_32px_90px_rgba(15,23,42,0.28)] dark:border-white/10 dark:bg-slate-900">
                                <div class="border-b border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-lg font-black text-slate-800 dark:text-white">Slot Port OLT</div>
                                            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                {{ canManageOltProfile ? 'Nama/deskripsi FSP bisa diedit langsung dari popup ini.' : 'Popup ini menampilkan label FSP yang sudah disimpan.' }}
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 text-slate-400 transition hover:border-slate-300 hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:text-slate-500 dark:hover:border-white/20 dark:hover:text-slate-300"
                                            :disabled="portSlotSummaryLoading || portSlotSaveBusy"
                                            @click="closePortSlotModal()"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 sm:px-6">
                                    <div v-if="portSlotSummaryLoading" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-6 text-sm font-semibold text-slate-500 dark:border-white/10 dark:bg-slate-950/40 dark:text-slate-400">
                                        Memuat ringkasan slot port...
                                    </div>

                                    <div v-else-if="portSlotSummaryError" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-6 text-sm font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                        {{ portSlotSummaryError }}
                                    </div>

                                    <div v-else class="overflow-hidden rounded-2xl border border-slate-200 dark:border-white/10">
                                        <div v-if="portSlotSaveError" class="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
                                            {{ portSlotSaveError }}
                                        </div>

                                        <div v-else-if="portSlotSaveMessage" class="border-b border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                                            {{ portSlotSaveMessage }}
                                        </div>

                                        <div class="max-h-[48vh] overflow-auto">
                                            <table class="w-full text-left text-sm whitespace-nowrap">
                                                <thead class="bg-slate-50 text-xs uppercase text-slate-500 font-bold border-b border-slate-100 dark:bg-slate-900/60 dark:text-slate-400 dark:border-white/10">
                                                    <tr>
                                                        <th class="px-4 py-3">Port</th>
                                                        <th class="px-4 py-3">Nama</th>
                                                        <th class="px-4 py-3">Deskripsi</th>
                                                        <th class="px-4 py-3">Terpakai</th>
                                                        <th class="px-4 py-3">Tersedia</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                                                    <tr v-if="!portSlotSummary.length">
                                                        <td colspan="5" class="px-4 py-10 text-center text-slate-400 italic">
                                                            Belum ada data port.
                                                        </td>
                                                    </tr>
                                                    <tr v-for="item in portSlotSummary" :key="item.fsp" class="bg-white dark:bg-slate-900/40">
                                                        <td class="px-4 py-3 font-bold text-slate-800 dark:text-white">{{ item.fsp }}</td>
                                                        <td class="px-4 py-3">
                                                            <input
                                                                v-if="canManageOltProfile"
                                                                v-model="item.name"
                                                                type="text"
                                                                maxlength="100"
                                                                placeholder="Nama port"
                                                                class="h-10 w-full min-w-[12rem] rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-900/20 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"
                                                            />
                                                            <span v-else class="font-semibold text-slate-700 dark:text-slate-200">{{ item.name || '-' }}</span>
                                                        </td>
                                                        <td class="px-4 py-3">
                                                            <input
                                                                v-if="canManageOltProfile"
                                                                v-model="item.description"
                                                                type="text"
                                                                maxlength="255"
                                                                placeholder="Deskripsi port"
                                                                class="h-10 w-full min-w-[16rem] rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-slate-900/20 dark:border-white/10 dark:bg-slate-950 dark:text-slate-200"
                                                            />
                                                            <span v-else class="text-slate-500 dark:text-slate-400">{{ item.description || '-' }}</span>
                                                        </td>
                                                        <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{{ item.used_slots }} / {{ item.total_slots }}</td>
                                                        <td class="px-4 py-3 font-semibold text-emerald-600 dark:text-emerald-300">{{ item.empty_slots }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <button
                                        type="button"
                                        class="h-10 rounded-lg bg-slate-100 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-200"
                                        :disabled="portSlotSaveBusy"
                                        @click="closePortSlotModal()"
                                    >
                                        Tutup
                                    </button>
                                    <button
                                        v-if="canManageOltProfile"
                                        type="button"
                                        class="inline-flex h-10 items-center justify-center rounded-lg bg-slate-900 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                        :disabled="portSlotSaveBusy || portSlotSummaryLoading"
                                        @click="savePortSlotMetadata()"
                                    >
                                        <span
                                            v-if="portSlotSaveBusy"
                                            class="mr-2 inline-flex h-4 w-4 items-center justify-center"
                                        >
                                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                            </svg>
                                        </span>
                                        {{ portSlotSaveBusy ? 'Menyimpan...' : 'Simpan Nama FSP' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <Teleport to="body">
                    <div v-if="manualRegisterModalOpen && canManualRegister && uncfgSelected" class="fixed inset-0 z-[94]">
                        <div
                            class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm"
                            @click="hideManualRegisterModal()"
                        ></div>
                        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6">
                            <div class="w-full max-w-md overflow-hidden rounded-[28px] border border-slate-200/80 bg-white shadow-[0_32px_90px_rgba(15,23,42,0.28)] dark:border-white/10 dark:bg-slate-900">
                                <div class="border-b border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-lg font-black text-slate-800 dark:text-white">Registrasi ONU</div>
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 text-slate-400 transition hover:border-slate-300 hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:text-slate-500 dark:hover:border-white/20 dark:hover:text-slate-300"
                                            :disabled="registerBusy || manualRegisterActive"
                                            @click="hideManualRegisterModal()"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="space-y-4 px-5 py-5 sm:px-6">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/40">
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">ONU</div>
                                        <div class="mt-1 text-sm font-bold text-slate-800 dark:text-white">
                                            <span>{{ uncfgSelected.fsp || '-' }}</span> | <span>{{ uncfgSelected.sn || '-' }}</span>
                                        </div>
                                        <div v-if="formatFspMetaLine(uncfgSelected.fsp)" class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                                            {{ formatFspMetaLine(uncfgSelected.fsp) }}
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/40">
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Slot Interface</div>

                                        <div v-if="manualRegisterSlotSummaryLoading" class="mt-2 text-sm font-semibold text-slate-500 dark:text-slate-400">
                                            Memuat slot interface...
                                        </div>

                                        <div v-else-if="manualRegisterSlotSummaryError" class="mt-2 text-sm font-semibold text-amber-600 dark:text-amber-300">
                                            {{ manualRegisterSlotSummaryError }}
                                        </div>

                                        <div v-else-if="manualRegisterSlotSummary" class="mt-3 space-y-3">
                                            <div
                                                v-if="manualRegisterSlotSummary.name || manualRegisterSlotSummary.description"
                                                class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-xs dark:border-white/10 dark:bg-slate-900/60"
                                            >
                                                <div class="font-semibold text-slate-700 dark:text-slate-200">
                                                    {{ manualRegisterSlotSummary.name || manualRegisterSlotSummary.fsp }}
                                                </div>
                                                <div v-if="manualRegisterSlotSummary.description" class="mt-1 text-slate-500 dark:text-slate-400">
                                                    {{ manualRegisterSlotSummary.description }}
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-2 gap-3 text-xs">
                                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-3 dark:border-white/10 dark:bg-slate-900/60">
                                                    <div class="text-[10px] uppercase tracking-wide text-slate-400 dark:text-slate-500">Terpakai</div>
                                                    <div class="mt-1 text-base font-black text-slate-800 dark:text-white">
                                                        {{ manualRegisterSlotSummary.used_slots }} / {{ manualRegisterSlotSummary.total_slots }}
                                                    </div>
                                                </div>
                                                <div class="rounded-xl border border-slate-200 bg-white px-3 py-3 dark:border-white/10 dark:bg-slate-900/60">
                                                    <div class="text-[10px] uppercase tracking-wide text-slate-400 dark:text-slate-500">Tersedia</div>
                                                    <div class="mt-1 text-base font-black text-emerald-600 dark:text-emerald-300">
                                                        {{ manualRegisterSlotSummary.empty_slots }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-if="isTeknisi" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/40">
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Validasi Redaman</div>
                                        <div class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-300">
                                            Redaman ONU akan dicek otomatis setelah registrasi selesai.
                                        </div>
                                    </div>

                                    <div v-if="!manualRegisterActive" class="space-y-2">
                                        <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500">Nama ONU</label>
                                        <input
                                            v-model="registerName"
                                            type="text"
                                            placeholder="Contoh: ONU-RT01"
                                            class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"
                                            :disabled="registerBusy"
                                        />
                                    </div>

                                    <div v-if="manualRegisterActive" class="space-y-3">
                                        <div class="flex items-center gap-3 rounded-2xl border border-emerald-200/70 bg-emerald-50/70 px-4 py-3 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                                            <span class="inline-flex h-5 w-5 items-center justify-center text-emerald-600">
                                                <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                                                </svg>
                                            </span>
                                            <div class="text-sm font-semibold text-emerald-700 dark:text-emerald-200">
                                                {{ registerProgressText || 'Registrasi berjalan...' }}
                                            </div>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-emerald-100 dark:bg-emerald-500/10">
                                            <div class="h-full w-1/3 bg-emerald-500 animate-pulse"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <button
                                        type="button"
                                        class="h-11 rounded-lg bg-slate-100 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="registerBusy || manualRegisterActive"
                                        @click="hideManualRegisterModal()"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        v-if="!manualRegisterActive"
                                        type="button"
                                        class="h-11 rounded-lg bg-emerald-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-70"
                                        :disabled="registerBusy"
                                        @click="registerSelectedOnu()"
                                    >
                                        {{ registerBusy ? 'Registrasi...' : 'Registrasi ONU' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <Teleport to="body">
                    <div v-if="manualRegisterResultOpen" class="fixed inset-0 z-[96]">
                        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" @click="closeManualRegisterResult()"></div>
                        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6">
                            <div class="w-full max-w-sm overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-[0_32px_90px_rgba(15,23,42,0.28)] dark:border-white/10 dark:bg-slate-900">
                                <div class="border-b border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <div
                                        class="text-lg font-black"
                                        :class="manualRegisterResultTone === 'success' ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300'"
                                    >
                                        {{ manualRegisterResultTitle }}
                                    </div>
                                </div>
                                <div class="px-5 py-5 text-sm text-slate-600 dark:text-slate-300 sm:px-6">
                                    {{ manualRegisterResultMessage }}
                                    <div
                                        v-if="manualRegisterResultOnuRx !== null"
                                        class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/40"
                                    >
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">ONU Rx</div>
                                        <div class="mt-1 text-base font-black" :class="getRxToneClass(manualRegisterResultOnuRx)">
                                            {{ formatRx(manualRegisterResultOnuRx) }}
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center justify-end border-t border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <button
                                        type="button"
                                        class="h-10 rounded-lg bg-slate-100 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-200"
                                        @click="closeManualRegisterResult()"
                                    >
                                        Tutup
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <Teleport to="body">
                    <div v-if="writeConfigConfirmOpen" class="fixed inset-0 z-[94]">
                        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" @click="hideWriteConfigConfirm()"></div>
                        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6">
                            <div class="w-full max-w-sm overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-[0_32px_90px_rgba(15,23,42,0.28)] dark:border-white/10 dark:bg-slate-900">
                                <div class="border-b border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <div class="text-lg font-black text-slate-800 dark:text-white">Simpan Config</div>
                                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        Konfirmasi penulisan konfigurasi ke OLT.
                                    </div>
                                </div>
                                <div class="px-5 py-5 text-sm text-slate-600 dark:text-slate-300 sm:px-6">
                                    Lanjutkan write-config untuk OLT terpilih?
                                </div>
                                <div class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <button
                                        type="button"
                                        class="h-10 rounded-lg bg-slate-100 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="writeConfigBusy"
                                        @click="hideWriteConfigConfirm()"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="button"
                                        class="h-10 rounded-lg bg-amber-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="writeConfigBusy"
                                        @click="confirmWriteConfig()"
                                    >
                                        {{ writeConfigBusy ? 'Menyimpan...' : 'Simpan' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <Teleport to="body">
                    <div v-if="autoRegisterSetupOpen" class="fixed inset-0 z-[94]">
                        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" @click="hideAutoRegisterSetupModal()"></div>
                        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6">
                            <div class="w-full max-w-md overflow-hidden rounded-[28px] border border-slate-200/80 bg-white shadow-[0_32px_90px_rgba(15,23,42,0.28)] dark:border-white/10 dark:bg-slate-900">
                                <div class="border-b border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <div class="text-[11px] font-black uppercase tracking-[0.24em] text-slate-400 dark:text-slate-500">Auto Register</div>
                                    <div class="mt-2 text-lg font-black text-slate-800 dark:text-white">Mulai Auto Register ONU</div>
                                </div>

                                <div class="space-y-4 px-5 py-5 sm:px-6">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/40">
                                            <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">ONU</div>
                                            <div class="mt-1 text-2xl font-black text-slate-800 dark:text-white">{{ autoRegisterSetupCount }}</div>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/40">
                                            <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Batch</div>
                                            <div class="mt-1 text-2xl font-black text-slate-800 dark:text-white">{{ autoRegisterSetupBatches }}</div>
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500">Prefix Nama ONU</label>
                                        <input
                                            v-model="autoRegisterNamePrefix"
                                            type="text"
                                            maxlength="16"
                                            placeholder="Opsional, mis. RT01"
                                            class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-900/60 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"
                                        />
                                    </div>
                                </div>

                                <div class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-white/10 sm:px-6">
                                    <button
                                        type="button"
                                        class="h-11 rounded-lg bg-slate-100 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-200"
                                        @click="hideAutoRegisterSetupModal()"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="button"
                                        class="h-11 rounded-lg bg-slate-900 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-slate-950"
                                        @click="submitAutoRegister()"
                                    >
                                        Mulai
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>

                <Teleport to="body">
                    <div v-if="autoRegisterModalOpen" id="olt-auto-register-modal" class="fixed inset-0 z-[95]">
                        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm"></div>
                        <div class="relative flex min-h-full items-center justify-center p-4 sm:p-6">
                            <div class="w-full max-w-lg rounded-[28px] border border-slate-200/80 dark:border-white/10 bg-white dark:bg-slate-900 shadow-[0_32px_90px_rgba(15,23,42,0.28)] overflow-hidden">
                                <div class="relative">
                                    <div class="absolute inset-x-0 top-0 h-24 bg-gradient-to-r from-blue-500/10 via-cyan-400/10 to-emerald-400/10"></div>
                                    <div class="relative p-5 sm:p-6">
                                        <div class="mb-4 flex items-center justify-between gap-3">
                                            <div class="text-[11px] font-black uppercase tracking-[0.24em] text-slate-400 dark:text-slate-500">Status Auto Register</div>
                                            <button
                                                v-if="autoRegisterModalClosable"
                                                type="button"
                                                class="inline-flex items-center rounded-full border border-slate-200 px-3 py-1.5 text-[11px] font-bold text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800/60"
                                                @click="hideAutoRegisterModal()"
                                            >
                                                Tutup
                                            </button>
                                        </div>
                                        <div class="flex items-start gap-4">
                                            <span
                                                class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl shadow-sm"
                                                :class="getAutoRegisterModalToneClasses(autoRegisterModalTone).iconWrap"
                                            >
                                                <svg v-if="autoRegisterModalTone === 'loading'" class="h-6 w-6 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                                                </svg>
                                                <svg v-else-if="autoRegisterModalTone === 'error'" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86l-7.5 13A1 1 0 003.66 18h16.68a1 1 0 00.87-1.14l-7.5-13a1 1 0 00-1.74 0z" />
                                                </svg>
                                                <svg v-else class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                    <div class="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-white">Auto Register ONU</div>
                                                    <div
                                                        class="inline-flex w-fit items-center rounded-full px-2.5 py-1 text-[11px] font-bold"
                                                        :class="getAutoRegisterModalToneClasses(autoRegisterModalTone).badge"
                                                    >
                                                        {{ Math.round(autoRegisterModalPercent) }}%
                                                    </div>
                                                </div>
                                                <div class="mt-2 text-sm font-semibold leading-6 text-slate-700 dark:text-slate-100 break-words">
                                                    {{ autoRegisterModalText }}
                                                </div>
                                                <div class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400 break-words">
                                                    {{ autoRegisterModalNote }}
                                                </div>
                                                <div class="mt-1 text-[11px] leading-5 text-slate-400 dark:text-slate-500 break-words">
                                                    {{ autoRegisterModalEta }}
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-5 rounded-2xl border border-slate-200/70 dark:border-white/10 bg-slate-50/80 dark:bg-slate-950/40 p-4">
                                            <div class="flex items-center justify-between gap-3 text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                <span>Progress Queue</span>
                                                <span>{{ Math.round(autoRegisterModalPercent) }}%</span>
                                            </div>
                                            <div class="mt-3 h-3 overflow-hidden rounded-full bg-white dark:bg-slate-800 ring-1 ring-slate-200/70 dark:ring-white/10">
                                                <div
                                                    class="h-full rounded-full transition-all duration-500"
                                                    :class="getAutoRegisterModalToneClasses(autoRegisterModalTone).bar"
                                                    :style="{ width: `${Math.round(autoRegisterModalPercent)}%` }"
                                                ></div>
                                            </div>
                                            <div class="mt-3 flex flex-col gap-2 text-[11px] text-slate-500 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                                                <span>Queue worker sedang memproses batch auto register.</span>
                                                <span class="font-semibold text-slate-600 dark:text-slate-300">Jangan tutup worker selama proses berjalan.</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>
            </div>
        </div>
    </AdminLayout>
</template>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap');
#olt-root {
    font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
}
.fade-in {
    animation: oltFadeIn 0.25s ease-out both;
}
@keyframes oltFadeIn {
    from {
        opacity: 0;
        transform: translateY(4px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

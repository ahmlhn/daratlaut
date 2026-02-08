<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const page = usePage();
const API_BASE = '/api/v1';

const role = computed(() => String(page.props.auth?.user?.role || '').trim().toLowerCase());
const isTeknisi = computed(() => ['teknisi', 'svp lapangan'].includes(role.value));
const canManualRegister = computed(() => isTeknisi.value || ['admin', 'cs', 'owner'].includes(role.value));

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
        throw new Error(msg);
    }

    if (data === null) {
        throw new Error('Non-JSON response');
    }

    return data;
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
    if (status && status !== 'offline') return status;
    if (status === 'online') return status;
    return '';
}

function getRegStatusLabel(item) {
    const base = getBaseRegStatus(item);
    if (base) return base;
    return extractRxValue(item?.rx) !== null ? 'online' : 'offline';
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

function getRxStatusClass(value, isOnline) {
    if (!isOnline) {
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
    const isOnline = getRegStatusLabel(item) === 'online';
    const rxValue = extractRxValue(item?.rx);
    return {
        label: isOnline ? 'Online' : 'Offline',
        className: getRxStatusClass(rxValue, isOnline),
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

const selectedInfoText = computed(() => {
    if (!selectedOlt.value) {
        return canManualRegister.value
            ? 'Langkah singkat: pilih OLT, scan Uncfg, ketuk SN, isi nama ONU, lalu registrasi.'
            : 'Langkah singkat: pilih OLT, scan Uncfg, pilih SN, isi nama ONU, lalu registrasi.';
    }

    if (canManualRegister.value) {
        return `OLT dipilih: ${selectedOlt.value.nama_olt || 'OLT'}. Scan Uncfg lalu ketuk SN untuk registrasi.`;
    }

    const host = selectedOlt.value.host || '-';
    const port = selectedOlt.value.port || '-';
    const vlan = selectedOlt.value.vlan_default || '-';
    const tcont = selectedOlt.value.tcont_default || '-';
    const onuType = selectedOlt.value.onu_type_default || '-';
    const spid = selectedOlt.value.service_port_id_default || '-';
    return `Host ${host}:${port} | VLAN ${vlan} | TCONT ${tcont} | ONU ${onuType} | SP ${spid}`;
});

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
    const tcont = selectedOlt.value?.tcont_default || 'pppoe';
    const vlan = selectedOlt.value?.vlan_default || '';
    const spid = selectedOlt.value?.service_port_id_default || '';

    const lines = [];
    lines.push('conf t');
    lines.push(`interface gpon-olt_${safeFsp}`);
    lines.push(`onu <auto-id> sn ${safeSn}`);
    lines.push(`interface gpon-onu_${safeFsp}`);
    lines.push(`name ${onuName}`);
    lines.push(`tcont profile ${tcont}`);
    if (vlan && spid) lines.push(`service-port ${spid} vlan ${vlan}`);
    lines.push('pon-onu-mng');
    lines.push('end');
    return lines;
}

async function loadOlts() {
    loadingOlts.value = true;
    try {
        const data = await fetchJson(`${API_BASE}/olts`);
        olts.value = data.status === 'ok' ? data.data : [];

        if (selectedOltId.value && !olts.value.some(o => String(o.id) === String(selectedOltId.value))) {
            selectedOltId.value = '';
        }
    } catch (e) {
        olts.value = [];
    } finally {
        loadingOlts.value = false;
    }
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
    };
    showOltModal.value = true;
}

async function saveOlt() {
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
const registerProgressText = ref('Registrasi berjalan...');
const teknisiWriteReady = ref(false);
const uncfgStatus = ref({ tone: 'info', message: '' });

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
    if (manualRegisterActive.value && msg) {
        registerProgressText.value = msg;
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
    if (manualRegisterActive.value && uncfgStatus.value.message) {
        registerProgressText.value = uncfgStatus.value.message;
    }
}

function clearUncfgSelection() {
    uncfgSelectedIndex.value = null;
    registerName.value = '';
}

function selectUncfg(idx) {
    if (!canManualRegister.value) return;
    if (idx < 0 || idx >= uncfg.value.length) return;
    uncfgSelectedIndex.value = idx;
    registerName.value = '';
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
        setUncfgStatus(`Scan selesai. Ditemukan ${uncfg.value.length} ONU.`, 'success');
    } catch (e) {
        setUncfgStatus(e.message || 'Gagal scan ONU.', 'error');
    } finally {
        uncfgLoading.value = false;
    }
}

async function writeConfig() {
    if (!selectedOltId.value) {
        setUncfgStatus('Pilih OLT dulu.', 'error');
        return false;
    }
    if (!confirm('Simpan config OLT sekarang? (write)')) return;

    setUncfgStatus('Menulis konfigurasi (write)...', 'loading');
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/write-config`, { method: 'POST' });
        if (data.status !== 'ok') throw new Error(data.message || 'Write gagal');
        setUncfgStatus(data.message || 'Write selesai.', 'success');
        await loadLogs();
        return true;
    } catch (e) {
        setUncfgStatus(e.message || 'Write gagal', 'error');
        return false;
    }
}

async function writeConfigTeknisi() {
    if (!isTeknisi.value) return;
    if (!teknisiWriteReady.value) {
        setUncfgStatus('Tidak ada data yang harus disimpan.', 'info');
        return;
    }
    const ok = await writeConfig();
    if (ok) teknisiWriteReady.value = false;
}

async function autoRegister() {
    if (!selectedOltId.value) {
        setUncfgStatus('Pilih OLT dulu.', 'error');
        return;
    }
    if (!confirm('Auto register semua ONU unregistered?')) return;

    const prefix = prompt('Prefix nama ONU (opsional, max 16). Contoh: RT01', '') || '';

    setUncfgStatus('Auto register berjalan...', 'info');
    registerBusy.value = true;
    startLiveStatus('Auto register berjalan', buildScanLiveLines(), 'uncfg');
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/auto-register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name_prefix: prefix }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'Auto register gagal');

        const s = data.summary || {};
        setUncfgStatus(data.message || `Auto register selesai. Success ${s.success || 0}, error ${s.error || 0}.`, 'success');

        // Clear uncfg list; user can re-scan to confirm.
        uncfg.value = [];
        clearUncfgSelection();
        storeUncfgCache(selectedOltId.value);

        regFilterFsp.value = 'all';
        await changeRegFsp();
        await loadLogs();
    } catch (e) {
        setUncfgStatus(e.message || 'Auto register gagal', 'error');
    } finally {
        registerBusy.value = false;
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

        // Remove from uncfg list
        uncfg.value = uncfg.value.filter(o => String(o.sn) !== String(item.sn));
        clearUncfgSelection();
        storeUncfgCache(selectedOltId.value);
        setUncfgStatus('ONU berhasil diregistrasi.', 'success');
        if (isTeknisi.value) teknisiWriteReady.value = true;
        await loadLogs();

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
    } catch (e) {
        setUncfgStatus(e.message || 'Gagal register', 'error');
    } finally {
        registerBusy.value = false;
        manualRegisterActive.value = false;
    }
}

// ========== Registered ==========
const registered = ref([]);
const regLoading = ref(false);
const regLoadingText = ref('');
const regFilterFsp = ref('');
const regLoadedFsp = ref({});
const regStatus = ref({ tone: 'info', message: '' });
const regSearch = ref('');
const regSearchMode = ref(false);
const regPage = ref(1);
const regPageSize = ref(25);
const regSortKey = ref('interface');
const regSortDir = ref('asc');

const regHighlightKey = ref('');
const regExpandedKey = ref('');
const regDetailLoadingKey = ref('');
const regDetails = ref({});
const regEditingKey = ref('');
const regEditingName = ref('');
const rowActionKey = ref('');
const rowActionType = ref('');

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
    regStatus.value = { tone, message: String(message || '') };
}

function cancelEditOnuName() {
    regEditingKey.value = '';
    regEditingName.value = '';
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
    const items = Array.isArray(list) ? list.slice() : [];

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

    return sortRegisteredList(list);
});

const regTotal = computed(() => registeredFiltered.value.length);
const regTotalPages = computed(() => Math.max(1, Math.ceil(regTotal.value / regPageSize.value)));
const regPageStart = computed(() => (Math.max(1, regPage.value) - 1) * regPageSize.value);
const regPageItems = computed(() => registeredFiltered.value.slice(regPageStart.value, regPageStart.value + regPageSize.value));

watch(regTotalPages, () => {
    if (regPage.value > regTotalPages.value) regPage.value = regTotalPages.value;
    if (regPage.value < 1) regPage.value = 1;
});

function hasRegisteredFspData(fsp) {
    const safeFsp = String(fsp || '').trim();
    if (!safeFsp) return false;
    return registered.value.some(it => String(it?.fsp || '') === safeFsp);
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

const regFspInfoText = computed(() => {
    const total = Array.isArray(fspList.value) ? fspList.value.length : 0;
    const loaded = Object.keys(regLoadedFsp.value || {}).length;
    if (!total) return 'FSP belum dimuat.';
    return `FSP dimuat: ${loaded}/${total}`;
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

    regLoading.value = true;
    regLoadingText.value = 'Memuat data dari OLT...';
    if (!silent) setRegStatus('Memuat data dari OLT...', 'info');

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
                if (shouldReplace) {
                    setRegStatus(`FSP ${fsp} siap.`, 'success');
                } else {
                    setRegStatus('Hasil telnet kosong, menampilkan cache ONU.', 'info');
                }
            }
        }
    } catch (e) {
        if (!silent) setRegStatus(e.message || 'Gagal memuat data dari OLT.', 'error');
    } finally {
        regLoading.value = false;
        regLoadingText.value = '';
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

    // Cache-first for targets without any local data (native parity).
    for (const fsp of targets) {
        if (hasRegisteredFspData(fsp)) continue;
        try {
            await loadRegisteredCache({ fsp });
        } catch {
            // ignore cache failures; live may still work
        }
    }

    regLoading.value = true;
    regLoadingText.value = 'Memuat data FSP...';
    if (!silent) setRegStatus('Memuat data FSP...', 'info');

    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/registered-all`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fsp_list: targets }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'Gagal memuat data FSP');

        const items = Array.isArray(data.data) ? data.data : [];
        const grouped = {};
        items.forEach((it) => {
            const fsp = String(it?.fsp || '').trim();
            if (!fsp) return;
            if (!grouped[fsp]) grouped[fsp] = [];
            grouped[fsp].push(it);
        });

        targets.forEach((fsp) => {
            const list = grouped[fsp] || [];
            const hadCache = hasRegisteredFspData(fsp);
            const shouldReplace = list.length > 0 || !hadCache;
            if (shouldReplace) {
                mergeRegisteredFsp(fsp, list, true);
            }
        });

        const loaded = { ...(regLoadedFsp.value || {}) };
        targets.forEach((fsp) => {
            loaded[fsp] = true;
        });
        regLoadedFsp.value = loaded;

        if (!silent) setRegStatus(`Load FSP selesai (${targets.length}).`, 'success');
    } catch (e) {
        if (!silent) setRegStatus(e.message || 'Gagal memuat data FSP.', 'error');
    } finally {
        regLoading.value = false;
        regLoadingText.value = '';
    }
}

async function changeRegFsp() {
    if (regSearchMode.value) exitRegSearchMode();
    regPage.value = 1;
    regExpandedKey.value = '';
    cancelEditOnuName();

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
        editingKey: regEditingKey.value || '',
        editingName: regEditingName.value || '',
    };
    registered.value = [];
    regLoadedFsp.value = {};
    regPage.value = 1;
    regExpandedKey.value = '';
    cancelEditOnuName();
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
        regEditingKey.value = backup.editingKey || '';
        regEditingName.value = backup.editingName || '';
        return;
    }
    registered.value = [];
    regLoadedFsp.value = {};
    regPage.value = 1;
    regExpandedKey.value = '';
    cancelEditOnuName();
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

    if (!registered.value.length && isLikelySn(q)) {
        searchRegisteredBySn(q).catch(() => {});
    }
}

async function searchRegisteredBySn(sn) {
    if (!selectedOltId.value) return;
    const q = String(sn || '').trim();
    if (!q) return;

    if (regSearchMode.value) exitRegSearchMode();

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

        if (found.fsp && !fspList.value.includes(found.fsp)) {
            fspList.value = sortFspList(fspList.value.concat(found.fsp));
        }

        regFilterFsp.value = String(found.fsp);
        await changeRegFsp();
        const key = String(
            found.interface || (found.fsp && found.onu_id ? `gpon-onu_${found.fsp}:${found.onu_id}` : '')
        ).trim();
        if (key) setRegHighlight(key);
        setRegStatus(`SN ditemukan di FSP ${found.fsp}.`, 'success');
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
        regExpandedKey.value = '';
        cancelEditOnuName();

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
            }, 450);
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

async function toggleRegDetail(onu) {
    const key = onuKey(onu);
    if (regExpandedKey.value === key) {
        regExpandedKey.value = '';
        if (regEditingKey.value === key) cancelEditOnuName();
        return;
    }
    regExpandedKey.value = key;
    await loadOnuDetail(onu, { force: false, silent: true });
}

async function loadOnuDetail(onu, { force = false, silent = false } = {}) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    if (!force && regDetails.value[key]) return;

    regDetailLoadingKey.value = key;
    try {
        const params = new URLSearchParams({ fsp: String(onu.fsp || ''), onu_id: String(onu.onu_id || '') });
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/onu-detail?${params}`);
        if (data.status !== 'ok') throw new Error(data.message || 'Gagal load detail');

        regDetails.value = { ...regDetails.value, [key]: data.data || {} };

        // Keep row consistent with detail (best-effort).
        const idx = registered.value.findIndex(it => onuKey(it) === key);
        if (idx >= 0) {
            const cur = registered.value[idx] || {};
            const merged = { ...cur, ...(data.data || {}) };
            const copy = registered.value.slice();
            copy[idx] = merged;
            registered.value = copy;
        }

        if (!silent) setRegStatus('Detail ONU dimuat.', 'success');
    } catch (e) {
        if (!silent) setRegStatus(e.message || 'Gagal load detail', 'error');
    } finally {
        if (regDetailLoadingKey.value === key) regDetailLoadingKey.value = '';
    }
}

function startEditOnuName(onu) {
    const key = onuKey(onu);
    regEditingKey.value = key;
    regEditingName.value = String(onu.name || '').trim();
}

async function saveEditOnuName(onu) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    const name = String(regEditingName.value || '').trim();
    if (!name) {
        setRegStatus('Nama ONU wajib diisi.', 'error');
        return;
    }

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
        setRegStatus('Nama ONU berhasil diupdate.', 'success');
        await loadLogs();
    } catch (e) {
        setRegStatus(e.message || 'Gagal update nama', 'error');
    } finally {
        setRowActionLoading(key, 'rename', false);
    }
}

async function refreshRegisteredOnu(onu) {
    const key = onuKey(onu);
    setRowActionLoading(key, 'refresh', true);
    try {
        await loadOnuDetail(onu, { force: true, silent: true });
        setRegStatus('Detail ONU di-refresh.', 'success');
        const fsp = String(onu?.fsp || '').trim();
        if (fsp) loadRegisteredLive(fsp, { silent: true }).catch(() => {});
    } catch (e) {
        setRegStatus(e.message || 'Gagal refresh', 'error');
    } finally {
        setRowActionLoading(key, 'refresh', false);
    }
}

async function restartRegisteredOnu(onu) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    if (!confirm(`Restart ONU ${onu.fsp}:${onu.onu_id}?`)) return;

    setRowActionLoading(key, 'restart', true);
    try {
        setRegStatus('Mengirim perintah restart ONU...', 'loading');
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/restart-onu`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fsp: String(onu.fsp || ''), onu_id: Number(onu.onu_id || 0) }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'Restart gagal');
        setRegStatus(data.message || 'ONU sedang di-restart.', 'success');
        await loadLogs();
    } catch (e) {
        setRegStatus(e.message || 'Restart gagal', 'error');
    } finally {
        setRowActionLoading(key, 'restart', false);
    }
}

async function deleteRegisteredOnu(onu) {
    if (!selectedOltId.value) return;
    const key = onuKey(onu);
    if (!confirm(`Hapus ONU ${onu.fsp}:${onu.onu_id}?`)) return;

    setRowActionLoading(key, 'delete', true);
    try {
        setRegStatus('Menghapus ONU dari OLT...', 'loading');
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/delete-onu`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fsp: String(onu.fsp || ''), onu_id: Number(onu.onu_id || 0) }),
        });
        if (data.status !== 'ok') throw new Error(data.message || 'Hapus gagal');

        registered.value = registered.value.filter(it => onuKey(it) !== key);
        if (regExpandedKey.value === key) regExpandedKey.value = '';
        setRegStatus('ONU berhasil dihapus.', 'success');
        await loadLogs();
    } catch (e) {
        setRegStatus(e.message || 'Hapus gagal', 'error');
    } finally {
        setRowActionLoading(key, 'delete', false);
    }
}

// ========== Logs ==========
const logs = ref([]);
const logsLoading = ref(false);

function formatLogsText(list) {
    const items = Array.isArray(list) ? list : [];
    if (!items.length) return 'Log akan tampil di sini.';
    return items
        .map((it) => {
            const at = it?.created_at ? String(it.created_at).replace('T', ' ').slice(0, 19) : '';
            const action = it?.action ? String(it.action) : '';
            const status = it?.status ? String(it.status) : '';
            const actor = it?.actor ? String(it.actor) : '';
            const text = it?.log_text ? String(it.log_text) : '';
            return `[${at}] ${action} ${status}${actor ? ` (${actor})` : ''}\n${text}`.trim();
        })
        .join('\n\n');
}

async function loadLogs() {
    if (!selectedOltId.value || isTeknisi.value) return;
    logsLoading.value = true;
    try {
        const data = await fetchJson(`${API_BASE}/olts/${selectedOltId.value}/logs`);
        logs.value = data.status === 'ok' ? (Array.isArray(data.data) ? data.data : []) : [];
    } catch {
        logs.value = [];
    } finally {
        logsLoading.value = false;
    }
}

function clearLogs() {
    logs.value = [];
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

    if (prevId) {
        storeUncfgCache(prevId);
        storeRegisteredMemoryCache(prevId);
    }

    // Reset UI state
    fspList.value = [];

    uncfg.value = [];
    clearUncfgSelection();
    manualRegisterActive.value = false;
    registerProgressText.value = 'Registrasi berjalan...';
    teknisiWriteReady.value = false;
    setUncfgStatus(newId ? 'Pilih OLT untuk mulai scan.' : '', 'info');

    registered.value = [];
    regFilterFsp.value = '';
    suppressRegSearchWatcher = true;
    regSearch.value = '';
    setTimeout(() => {
        suppressRegSearchWatcher = false;
    }, 0);
    regSearchMode.value = false;
    regLoadedFsp.value = {};
    regExpandedKey.value = '';
    regDetails.value = {};
    cancelEditOnuName();
    setRegStatus(newId ? 'Pilih F/S/P dulu.' : '', 'info');

    logs.value = [];

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
    onOltChanged(val, oldVal).catch(() => {});
});

onMounted(async () => {
    await loadOlts();
});
</script>

<template>
    <AdminLayout>
        <div id="olt-root" class="p-6">
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
                        <div class="text-xs text-slate-400">Kelola OLT via dropdown dan modal setting.</div>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-white/10 p-4 md:p-5 space-y-4">
                    <div class="flex flex-col lg:flex-row lg:items-end gap-4">
                        <div class="flex-1 space-y-2">
                            <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide">Pilih OLT</label>
                            <select
                                v-model="selectedOltId"
                                class="w-full h-12 border border-slate-200 dark:border-white/10 rounded-lg px-4 text-sm font-semibold bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                :disabled="loadingOlts"
                            >
                                <option value="">-- Pilih OLT --</option>
                                <option v-for="olt in olts" :key="olt.id" :value="String(olt.id)">
                                    {{ olt.nama_olt || `OLT #${olt.id}` }}
                                </option>
                            </select>
                        </div>

                        <div v-if="!isTeknisi" class="grid grid-cols-2 gap-2 w-full lg:w-auto">
                            <button
                                type="button"
                                class="h-12 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-bold transition disabled:opacity-60 disabled:cursor-not-allowed"
                                :disabled="!selectedOltId"
                                @click="openOltModal('edit')"
                            >
                                Edit OLT
                            </button>
                            <button
                                type="button"
                                class="h-12 px-4 bg-slate-900 hover:bg-slate-950 text-white rounded-lg text-sm font-bold transition shadow-sm"
                                @click="openOltModal('add')"
                            >
                                Tambah OLT
                            </button>
                        </div>
                    </div>

                    <div
                        id="olt-selected-info"
                        class="text-[11px] text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-white/10 rounded-lg px-3 py-2"
                    >
                        {{ selectedInfoText }}
                    </div>
                </div>

                <div
                    v-if="selectedOlt"
                    id="olt-uncfg-card"
                    class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-white/10 overflow-hidden"
                >
                    <div class="p-4 md:p-5 border-b border-slate-100 dark:border-white/10 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-300 flex items-center justify-center border border-blue-100 dark:border-blue-500/20">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a4 4 0 014-4h6a4 4 0 010 8H9a4 4 0 01-4-4z" />
                                </svg>
                            </div>
                            <div>
                                <div class="text-base font-black text-slate-700 dark:text-slate-200 uppercase">ONU Unregistered</div>
                                <div class="text-sm text-slate-500 dark:text-slate-400">
                                    Total:
                                    <span class="font-bold text-slate-700 dark:text-slate-200">{{ uncfg.length }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3 gap-2 w-full lg:w-auto">
                            <button
                                type="button"
                                class="h-12 px-5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-bold transition shadow-sm disabled:opacity-70 disabled:cursor-not-allowed"
                                :disabled="uncfgLoading || registerBusy"
                                @click="scanUncfg()"
                            >
                                {{ uncfgLoading ? 'Scanning...' : 'Scan Uncfg' }}
                            </button>

                             <button
                                 v-if="isTeknisi"
                                 type="button"
                                 class="h-12 px-5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-200 rounded-lg text-sm font-bold shadow-sm hover:bg-slate-50 transition disabled:opacity-70 disabled:cursor-not-allowed"
                                 :disabled="registerBusy"
                                 @click="writeConfigTeknisi()"
                             >
                                 Simpan Config
                             </button>

                            <template v-else>
                                <button
                                    type="button"
                                    class="h-12 px-5 bg-slate-900 hover:bg-slate-950 text-white rounded-lg text-sm font-bold shadow-sm transition disabled:opacity-70 disabled:cursor-not-allowed"
                                    :disabled="registerBusy"
                                    @click="autoRegister()"
                                >
                                    Auto Register
                                </button>
                                <button
                                    type="button"
                                    class="h-12 px-5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-200 rounded-lg text-sm font-bold shadow-sm hover:bg-slate-50 transition disabled:opacity-70 disabled:cursor-not-allowed"
                                    :disabled="registerBusy"
                                    @click="writeConfig()"
                                >
                                    Simpan Config
                                </button>
                            </template>
                        </div>
                    </div>

                    <div v-if="uncfgStatus.message && !manualRegisterActive" class="px-4 md:px-5 pt-3 pb-3">
                        <div class="text-xs">
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
                    </div>

                    <div v-if="canManualRegister" class="px-4 md:px-5 pb-2 text-[11px] text-slate-500 dark:text-slate-400">
                        Ketuk baris SN atau tombol Pilih untuk memilih ONU.
                    </div>

                    <div v-if="canManualRegister && uncfgSelected" class="px-4 md:px-5 pb-4">
                        <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-white/90 dark:bg-slate-900/60 p-4 space-y-3 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-[10px] uppercase tracking-wide text-slate-400 dark:text-slate-500 font-bold">ONU Terpilih</div>
                                    <div class="text-sm font-bold text-slate-700 dark:text-slate-200">
                                        <span>{{ uncfgSelected.fsp || '-' }}</span> | <span>{{ uncfgSelected.sn || '-' }}</span>
                                    </div>
                                </div>
                                <div class="text-[10px] text-slate-400">Isi nama ONU di bawah</div>
                            </div>

                            <div v-if="!manualRegisterActive" class="flex flex-col lg:flex-row lg:items-end gap-3">
                                <div class="flex-1 space-y-1">
                                    <label class="text-[11px] font-bold text-slate-500 uppercase tracking-wide">Nama ONU</label>
                                    <input
                                        v-model="registerName"
                                        type="text"
                                        placeholder="Contoh: ONU-RT01"
                                        class="w-full h-14 lg:h-11 border border-slate-300 dark:border-white/15 rounded-2xl lg:rounded-lg px-4 text-base lg:text-sm font-semibold bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        :disabled="registerBusy"
                                    />
                                </div>
                                <button
                                    type="button"
                                    class="h-14 lg:h-11 px-6 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-base lg:text-sm font-bold shadow-sm transition w-full lg:w-auto disabled:opacity-70 disabled:cursor-not-allowed"
                                    :disabled="registerBusy"
                                    @click="registerSelectedOnu()"
                                >
                                    {{ registerBusy ? 'Registrasi...' : 'Registrasi ONU' }}
                                </button>
                            </div>

                            <div v-if="manualRegisterActive" class="space-y-2">
                                <div class="flex items-center gap-3 rounded-2xl border border-emerald-200/70 dark:border-emerald-500/30 bg-emerald-50/70 dark:bg-emerald-500/10 px-4 py-3">
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
                                <div class="h-2 rounded-full bg-emerald-100 dark:bg-emerald-500/10 overflow-hidden">
                                    <div class="h-full w-1/3 bg-emerald-500 animate-pulse"></div>
                                </div>
                            </div>

                            <div class="text-[10px] text-slate-400">Spasi akan dihapus, karakter khusus dibuang.</div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 text-xs uppercase text-slate-500 dark:text-slate-400 font-bold border-b border-slate-100 dark:border-white/5">
                                <tr v-if="canManualRegister">
                                    <th class="px-6 py-3">F/S/P</th>
                                    <th class="px-6 py-3">SN</th>
                                    <th class="px-6 py-3 text-right">Aksi</th>
                                </tr>
                                <tr v-else>
                                    <th class="px-6 py-3">F/S/P</th>
                                    <th class="px-6 py-3">SN</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                                <tr v-if="!uncfg.length">
                                    <td :colspan="canManualRegister ? 3 : 2" class="px-6 py-10 text-center text-slate-400 italic">
                                        Belum ada data.
                                    </td>
                                </tr>

                                <template v-else-if="canManualRegister">
                                    <tr
                                        v-for="(item, idx) in uncfg"
                                        :key="`${item.fsp}:${item.sn}`"
                                        class="cursor-pointer"
                                        :class="idx === uncfgSelectedIndex ? 'bg-blue-50/80 dark:bg-blue-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40'"
                                        @click="selectUncfg(idx)"
                                    >
                                        <td class="px-6 py-4 text-xs font-bold text-slate-700 dark:text-slate-200">{{ item.fsp }}</td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ item.sn }}</div>
                                            <div class="text-[10px] text-slate-400">Ketuk untuk pilih</div>
                                        </td>
                                        <td class="px-6 py-4 text-right">
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
                                        <td class="px-6 py-3 text-xs font-bold text-slate-700 dark:text-slate-200">{{ item.fsp }}</td>
                                        <td class="px-6 py-3 text-xs text-slate-600 dark:text-slate-300">{{ item.sn }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
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
                                <div class="text-base font-black text-slate-700 dark:text-slate-200 uppercase">ONU Registered</div>
                                <div class="text-sm text-slate-500 dark:text-slate-400">
                                    Total:
                                    <span class="font-bold text-slate-700 dark:text-slate-200">{{ registered.length }}</span>
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
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">Filter F/S/P</label>
                                <select
                                    v-model="regFilterFsp"
                                    @change="changeRegFsp()"
                                    class="w-full h-12 border border-slate-200 dark:border-white/10 rounded-lg px-4 text-sm font-semibold bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="">Pilih F/S/P</option>
                                    <option value="all">Semua F/S/P</option>
                                    <option v-for="fsp in fspList" :key="fsp" :value="fsp">{{ fsp }}</option>
                                </select>
                                <div class="text-[10px] text-slate-400 mt-1">{{ regFspInfoText }}</div>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">Pencarian</label>
                                <div class="relative">
                                    <input
                                        v-model="regSearch"
                                        type="text"
                                        placeholder="Cari SN atau nama..."
                                        class="w-full h-12 border border-slate-200 dark:border-white/10 rounded-lg px-11 text-sm font-semibold bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z" /></svg>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="px-4 md:px-5 pb-2 text-[11px] text-slate-500 dark:text-slate-400">
                        Ketuk baris untuk melihat detail ONU.
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 text-xs uppercase text-slate-500 dark:text-slate-400 font-bold border-b border-slate-100 dark:border-white/5">
                                <tr>
                                    <th class="px-6 py-3 text-left" :aria-sort="getRegAriaSort('interface')">
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
                                    <th class="px-6 py-3 text-left" :aria-sort="getRegAriaSort('name')">
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
                                    <th class="px-6 py-3 text-left" :aria-sort="getRegAriaSort('sn')">
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
                                    <th class="px-6 py-3 text-left" :aria-sort="getRegAriaSort('rx')">
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
                                    <th class="px-6 py-3 text-left" :aria-sort="getRegAriaSort('status')">
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
                                    <td colspan="5" class="px-6 py-10 text-center text-slate-400 italic">
                                        {{ regLoadingText || 'Memuat data...' }}
                                    </td>
                                </tr>
                                <tr v-else-if="!registered.length">
                                    <td colspan="5" class="px-6 py-10 text-center text-slate-400 italic">
                                        {{ Object.keys(regLoadedFsp || {}).length ? 'Belum ada data.' : 'Data belum dimuat.' }}
                                    </td>
                                </tr>
                                <tr v-else-if="!regPageItems.length">
                                    <td colspan="5" class="px-6 py-10 text-center text-slate-400 italic">
                                        Tidak ada data yang cocok.
                                    </td>
                                </tr>
                                <template v-else>
                                    <template v-for="item in regPageItems" :key="onuKey(item)">
                                        <tr
                                            class="cursor-pointer"
                                            :class="[
                                                regExpandedKey === onuKey(item)
                                                    ? 'bg-emerald-50/80 dark:bg-emerald-500/10'
                                                    : 'hover:bg-slate-50 dark:hover:bg-slate-800/40',
                                                regHighlightKey === onuKey(item) ? 'ring-2 ring-emerald-400/60' : '',
                                            ]"
                                            @click="toggleRegDetail(item)"
                                        >
                                            <td class="px-6 py-4 text-xs font-bold text-slate-700 dark:text-slate-200">{{ item.fsp_onu || `${item.fsp}:${item.onu_id}` }}</td>
                                            <td class="px-6 py-4 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ item.name || '-' }}</td>
                                            <td class="px-6 py-4 text-xs text-slate-600 dark:text-slate-300">{{ item.sn || '-' }}</td>
                                            <td class="px-6 py-4 text-xs" :class="getRxTextClass(item)">{{ formatRx(item.rx) }}</td>
                                            <td class="px-6 py-4">
                                                <span
                                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold"
                                                    :class="formatRegStatus(item).className"
                                                >
                                                    {{ formatRegStatus(item).label }}
                                                </span>
                                            </td>
                                        </tr>

                                        <tr v-if="regExpandedKey === onuKey(item)" class="bg-white dark:bg-slate-900/40">
                                            <td colspan="5" class="px-6 py-4">
                                                <div v-if="regDetailLoadingKey === onuKey(item)" class="flex items-center gap-3 text-sm text-slate-500">
                                                    <span class="inline-flex h-5 w-5 items-center justify-center text-emerald-500">
                                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                                            <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                        </svg>
                                                    </span>
                                                    Memuat detail ONU...
                                                </div>

                                                <div v-else class="space-y-4">
                                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs text-slate-600 dark:text-slate-300">
                                                        <div class="min-w-0 space-y-1">
                                                            <div class="text-[10px] uppercase tracking-wide text-slate-400">Interface</div>
                                                            <div class="font-semibold text-slate-700 dark:text-slate-200 break-all">{{ item.interface || onuKey(item) }}</div>
                                                        </div>
                                                        <div class="min-w-0 space-y-1">
                                                            <div class="text-[10px] uppercase tracking-wide text-slate-400">Nama</div>
                                                            <div v-if="regEditingKey === onuKey(item)">
                                                                <input
                                                                    v-model="regEditingName"
                                                                    class="block w-full max-w-full rounded-lg border border-slate-200 dark:border-white/10 bg-white/70 dark:bg-slate-900/60 px-3 py-1.5 text-xs text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-400/40"
                                                                    @click.stop
                                                                />
                                                            </div>
                                                            <div v-else class="font-semibold text-slate-700 dark:text-slate-200">{{ item.name || '-' }}</div>
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
                                                            <div class="font-semibold" :class="getRxTextClass(item)">
                                                                {{ extractRxValue(item.rx) !== null ? formatRx(item.rx) : '-' }}
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="flex flex-wrap gap-2">
                                                        <template v-if="regEditingKey === onuKey(item)">
                                                            <button
                                                                type="button"
                                                                class="px-3 py-1.5 text-xs font-bold rounded-lg border border-emerald-300 text-emerald-700 hover:bg-emerald-50 transition disabled:opacity-60 disabled:cursor-not-allowed inline-flex items-center gap-2"
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
                                                                class="px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition"
                                                                @click.stop="cancelEditOnuName()"
                                                            >
                                                                Batal
                                                            </button>
                                                        </template>
                                                        <button
                                                            v-else
                                                            type="button"
                                                            class="px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition"
                                                            @click.stop="startEditOnuName(item)"
                                                        >
                                                            Edit Nama
                                                        </button>

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
                <div
                    v-if="selectedOlt && !isTeknisi"
                    class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-white/10 overflow-hidden"
                >
                    <div class="p-4 border-b border-slate-100 dark:border-white/5 flex items-center justify-between">
                        <div class="text-xs font-black text-slate-700 dark:text-slate-200 uppercase">Log Command</div>
                        <button type="button" class="text-xs font-bold text-slate-500 hover:text-slate-700" @click="clearLogs()">Clear</button>
                    </div>
                    <pre class="p-4 text-[11px] bg-slate-900 text-slate-100 overflow-x-auto max-h-80 whitespace-pre-wrap">{{ formatLogsText(logs) }}</pre>
                </div>
                <div v-if="showOltModal" class="fixed inset-0 z-[80]">
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showOltModal = false"></div>
                    <div class="absolute top-1/2 left-1/2 w-[92%] max-w-lg -translate-x-1/2 -translate-y-1/2 p-4">
                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 shadow-2xl overflow-hidden">
                            <div class="p-5 border-b border-slate-100 dark:border-white/10 flex items-center justify-between">
                                <div class="text-sm font-black text-slate-800 dark:text-white uppercase">
                                    {{ editingOltId ? 'Edit OLT' : 'Tambah OLT' }}
                                </div>
                                <button type="button" class="text-slate-400 hover:text-slate-600" @click="showOltModal = false">✕</button>
                            </div>

                            <div class="p-5 space-y-4">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">Nama OLT</label>
                                    <input
                                        v-model="formData.nama_olt"
                                        type="text"
                                        class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                    />
                                </div>

                                <div class="grid grid-cols-3 gap-3">
                                    <div class="col-span-2">
                                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">Host/IP</label>
                                        <input
                                            v-model="formData.host"
                                            type="text"
                                            class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">Port</label>
                                        <input
                                            v-model="formData.port"
                                            type="number"
                                            class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                        />
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">Username</label>
                                        <input
                                            v-model="formData.username"
                                            type="text"
                                            class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">
                                            Password {{ editingOltId ? '(kosongkan jika tidak diubah)' : '' }}
                                        </label>
                                        <input
                                            v-model="formData.password"
                                            type="password"
                                            class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                        />
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">TCONT Default</label>
                                        <input
                                            v-model="formData.tcont_default"
                                            type="text"
                                            class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">VLAN Default</label>
                                        <input
                                            v-model="formData.vlan_default"
                                            type="number"
                                            class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">ONU Type Default</label>
                                        <input
                                            v-model="formData.onu_type_default"
                                            type="text"
                                            class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">Service-Port ID (Fixed)</label>
                                        <input
                                            v-model="formData.service_port_id_default"
                                            type="number"
                                            min="1"
                                            max="65535"
                                            class="w-full h-11 border border-slate-200 rounded-lg px-3 text-sm font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-slate-900/60"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="p-5 border-t border-slate-100 dark:border-white/10 flex items-center justify-between gap-3">
                                <button
                                    v-if="editingOltId"
                                    type="button"
                                    class="h-11 px-4 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold transition"
                                    @click="deleteOlt()"
                                >
                                    Hapus OLT
                                </button>
                                <div class="flex items-center gap-2 ml-auto">
                                    <button
                                        type="button"
                                        class="h-11 px-4 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold transition"
                                        @click="showOltModal = false"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="button"
                                        class="h-11 px-4 rounded-lg bg-slate-900 hover:bg-slate-950 text-white text-sm font-bold transition shadow-sm"
                                        @click="saveOlt()"
                                    >
                                        Simpan
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

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

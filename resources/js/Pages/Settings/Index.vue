<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SystemUpdatePanel from '@/Pages/Settings/Partials/SystemUpdatePanel.vue';
import SettingsNavIcon from '@/Pages/Settings/Partials/SettingsNavIcon.vue';

const API = '/api/v1/settings';
const page = usePage();
const loading = ref(true);
const refreshing = ref(false);
const saving = ref(false);
const testLoading = ref('');
const lastLoadedAt = ref(null);
const navQuery = ref('');

const userPermissions = computed(() =>
    (page.props.auth?.user?.permissions || []).map((p) => String(p || '').trim().toLowerCase())
);
const canOpenRoleSettings = computed(() => {
    const perms = userPermissions.value || [];
    return perms.includes('manage roles') || perms.includes('manage settings');
});

// ===== Reactive State =====
const gatewayStatus = ref({ wa: {}, tg: {} });
const waConfig = reactive({ base_url: '', group_url: '', token: '', sender_number: '', target_number: '', group_id: '', recap_group_id: '', is_active: 0, failover_mode: 'manual' });
const mpwaConfig = reactive({ base_url: '', token: '', sender_number: '', target_number: '', group_id: '', footer: '', is_active: 0 });
const tgConfig = reactive({ bot_token: '', chat_id: '' });

const templates = ref([]);
const templateForm = reactive({ code: '', message: '' });
const editingTemplateCode = ref(null);
const showTemplateForm = ref(false);
const installVars = ref([]);

const pops = ref([]);
const popForm = reactive({ id: 0, pop_name: '', wa_number: '', group_id: '' });
const editingPop = ref(false);

const recapGroups = ref([]);
const recapForm = reactive({ id: 0, name: '', group_id: '' });
const editingRecap = ref(false);

const feeSettings = reactive({ teknisi_fee_install: 0, sales_fee_install: 0, expense_categories: [] });
const mapsConfig = reactive({ google_maps_api_key: '' });
const showMapsKey = ref(false);

const publicUrl = ref('');

const notifLogs = ref([]);
const notifStats = ref({ total: 0, sent: 0, failed: 0 });
const logFilter = reactive({ search: '', status: '', range: '' });

const activeSection = ref('status');
const sections = [
    { id: 'status', name: 'Gateway Status', icon: 'wifi' },
    { id: 'wa', name: 'WhatsApp', icon: 'chat' },
    { id: 'mpwa', name: 'WA Backup (MPWA)', icon: 'bolt' },
    { id: 'tg', name: 'Telegram', icon: 'paper-airplane' },
    { id: 'templates', name: 'Template Pesan', icon: 'document-text' },
    { id: 'pops', name: 'Manajemen POP', icon: 'map-pin' },
    { id: 'recap_groups', name: 'Group Rekap', icon: 'user-group' },
    { id: 'fee', name: 'Fee Teknisi', icon: 'cash' },
    { id: 'maps', name: 'Google Maps', icon: 'map' },
    { id: 'public_url', name: 'Link Isolir', icon: 'link' },
    { id: 'logs', name: 'Log Notifikasi', icon: 'clock' },
    { id: 'system_update', name: 'Update Sistem', icon: 'refresh' },
];

// Option B navigation: top tabs + chips (no settings sidebar).
const tabs = [
    { id: 'gateway', title: 'Gateway', items: ['status', 'wa', 'mpwa', 'tg'] },
    { id: 'pesan', title: 'Pesan', items: ['templates', 'logs'] },
    { id: 'operasional', title: 'Operasional', items: ['pops', 'recap_groups', 'fee'] },
    { id: 'system', title: 'System', items: ['public_url', 'maps', 'system_update'] },
];

const sectionById = computed(() => Object.fromEntries(sections.map((s) => [s.id, s])));

const tabBySectionId = computed(() => {
    const map = {};
    for (const t of tabs) {
        for (const id of t.items) {
            map[id] = t.id;
        }
    }
    return map;
});

const lastSectionByTab = reactive({
    gateway: 'status',
    pesan: 'templates',
    operasional: 'pops',
    system: 'public_url',
});

const activeTab = ref('gateway');

function setActiveSection(id) {
    if (!id) return;
    activeSection.value = id;
    const tab = tabBySectionId.value[id];
    if (tab) {
        activeTab.value = tab;
        lastSectionByTab[tab] = id;
    }
}

function setActiveTab(tabId) {
    activeTab.value = tabId;
    const t = tabs.find((x) => x.id === tabId);
    const next = lastSectionByTab[tabId] || t?.items?.[0];
    if (next) activeSection.value = next;
}

watch(
    () => activeSection.value,
    (id) => {
        const tab = tabBySectionId.value[id];
        if (!tab) return;
        activeTab.value = tab;
        lastSectionByTab[tab] = id;
    },
    { immediate: true }
);

const activeTabSections = computed(() => {
    const t = tabs.find((x) => x.id === activeTab.value) || tabs[0];
    const byId = sectionById.value;
    return (t?.items || []).map((id) => byId[id]).filter(Boolean);
});

const sectionSearchResults = computed(() => {
    const q = navQuery.value.trim().toLowerCase();
    if (!q) return [];
    return sections.filter((s) => s.name.toLowerCase().includes(q));
});

// ===== Fetch helpers =====
async function api(path, opts = {}) {
    const res = await fetch(`${API}${path}`, {
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...opts.headers },
        ...opts,
    });
    return res.json();
}

// ===== Load all data =====
async function loadAll(opts = {}) {
    const isRefresh = !!opts.refresh;
    if (isRefresh) refreshing.value = true;
    else loading.value = true;
    try {
        const data = await api('');
        // Gateway status
        if (data.gateway_status) gatewayStatus.value = data.gateway_status;
        // WA
        if (data.wa_config) {
            Object.assign(waConfig, {
                base_url: data.wa_config.base_url || '',
                group_url: data.wa_config.group_url || '',
                token: data.wa_config.token || '',
                sender_number: data.wa_config.sender_number || '',
                target_number: data.wa_config.target_number || '',
                group_id: data.wa_config.group_id || '',
                recap_group_id: data.wa_config.recap_group_id || '',
                is_active: data.wa_config.is_active ? 1 : 0,
            });
        }
        if (data.wa_failover_mode) waConfig.failover_mode = data.wa_failover_mode;
        // MPWA
        if (data.wa_gateways?.backup) {
            const bk = data.wa_gateways.backup;
            const ex = bk.extra || {};
            Object.assign(mpwaConfig, {
                base_url: bk.base_url || '', token: bk.token || '', sender_number: bk.sender_number || '',
                target_number: ex.target_number || '', group_id: bk.group_id || '', footer: ex.footer || '',
                is_active: bk.is_active ? 1 : 0,
            });
        }
        // TG
        if (data.tg_config) {
            tgConfig.bot_token = data.tg_config.bot_token || '';
            tgConfig.chat_id = data.tg_config.chat_id || '';
        }
        // Templates
        templates.value = data.templates || [];
        // POPs
        pops.value = data.pops || [];
        // Recap Groups
        recapGroups.value = data.recap_groups || [];
        // Fee
        if (data.fee_settings) Object.assign(feeSettings, data.fee_settings);
        // Maps
        if (data.maps_config) {
            mapsConfig.google_maps_api_key = data.maps_config.google_maps_api_key || '';
        } else {
            mapsConfig.google_maps_api_key = '';
        }
        // Public URL
        publicUrl.value = data.public_url || '';
    } catch (e) {
        console.error('Load settings error:', e);
    } finally {
        if (isRefresh) refreshing.value = false;
        else loading.value = false;
        lastLoadedAt.value = new Date();
    }
    // Load logs separately
    loadLogs();
    loadInstallVars();
}

async function loadLogs() {
    const params = new URLSearchParams();
    if (logFilter.search) params.set('search', logFilter.search);
    if (logFilter.status) params.set('status', logFilter.status);
    if (logFilter.range) params.set('range', logFilter.range);
    const data = await api(`/notif-logs?${params}`);
    notifLogs.value = data.data || [];
    if (data.stats) notifStats.value = data.stats;
}

async function loadInstallVars() {
    const data = await api('/install-variables');
    installVars.value = data.data || [];
}

// ===== Save WA =====
async function saveWa() {
    saving.value = true;
    try {
        await api('/wa', { method: 'POST', body: JSON.stringify(waConfig) });
        alert('WhatsApp config tersimpan!');
    } catch (e) { alert('Gagal menyimpan'); }
    finally { saving.value = false; }
}

// ===== Save MPWA =====
async function saveMpwa() {
    saving.value = true;
    try {
        await api('/wa-backup', { method: 'POST', body: JSON.stringify({ ...mpwaConfig, failover_mode: waConfig.failover_mode }) });
        alert('MPWA config tersimpan!');
    } catch (e) { alert('Gagal menyimpan'); }
    finally { saving.value = false; }
}

// ===== Save TG =====
async function saveTg() {
    saving.value = true;
    try {
        await api('/tg', { method: 'POST', body: JSON.stringify(tgConfig) });
        alert('Telegram config tersimpan!');
    } catch (e) { alert('Gagal menyimpan'); }
    finally { saving.value = false; }
}

// ===== Test WA =====
async function doTestWa(isGroup = false) {
    testLoading.value = isGroup ? 'wa_group' : 'wa_personal';
    try {
        const res = await api('/test-wa', { method: 'POST', body: JSON.stringify({
            url: waConfig.base_url, token: waConfig.token, sender: waConfig.sender_number,
            target: isGroup ? waConfig.group_id : waConfig.target_number, is_group: isGroup,
        }) });
        alert(res.status === 'success' ? 'Berhasil!' : `Gagal: ${res.message}`);
        loadLogs();
    } catch (e) { alert('Error: ' + e.message); }
    finally { testLoading.value = ''; }
}

async function doTestMpwa(isGroup = false) {
    testLoading.value = isGroup ? 'mpwa_group' : 'mpwa_personal';
    try {
        const res = await api('/test-mpwa', { method: 'POST', body: JSON.stringify({
            url: mpwaConfig.base_url, token: mpwaConfig.token, sender: mpwaConfig.sender_number,
            target: isGroup ? mpwaConfig.group_id : mpwaConfig.target_number,
            footer: mpwaConfig.footer, is_group: isGroup,
        }) });
        alert(res.status === 'success' ? 'Berhasil!' : `Gagal: ${res.message}`);
        loadLogs();
    } catch (e) { alert('Error: ' + e.message); }
    finally { testLoading.value = ''; }
}

async function doTestTg() {
    testLoading.value = 'tg';
    try {
        const res = await api('/test-tg', { method: 'POST', body: JSON.stringify(tgConfig) });
        alert(res.status === 'success' ? 'Berhasil!' : `Gagal: ${res.message}`);
        loadLogs();
    } catch (e) { alert('Error: ' + e.message); }
    finally { testLoading.value = ''; }
}

// ===== Templates =====
function openTemplateEdit(tpl) {
    templateForm.code = tpl.code;
    templateForm.message = tpl.message;
    editingTemplateCode.value = tpl.code;
    showTemplateForm.value = true;
}
function openTemplateNew() {
    templateForm.code = '';
    templateForm.message = '';
    editingTemplateCode.value = null;
    showTemplateForm.value = true;
}
async function saveTemplate() {
    await api('/templates', { method: 'POST', body: JSON.stringify(templateForm) });
    showTemplateForm.value = false;
    const data = await api('/templates');
    templates.value = data.data || [];
}
async function deleteTemplate(tpl) {
    if (!confirm(`Hapus template "${tpl.code}"?`)) return;
    await api(`/templates/${tpl.id}`, { method: 'DELETE' });
    const data = await api('/templates');
    templates.value = data.data || [];
}
function insertVar(varName) {
    templateForm.message += `{${varName}}`;
}

// ===== POP CRUD =====
function editPop(p) { Object.assign(popForm, { id: p.id, pop_name: p.pop_name, wa_number: p.wa_number || '', group_id: p.group_id || '' }); editingPop.value = true; }
function cancelPop() { Object.assign(popForm, { id: 0, pop_name: '', wa_number: '', group_id: '' }); editingPop.value = false; }
async function savePop() {
    const res = await api('/pops', { method: 'POST', body: JSON.stringify(popForm) });
    if (res.status === 'error') { alert(res.message); return; }
    cancelPop();
    const d = await api('/pops'); pops.value = d.data || [];
}
async function deletePop(p) {
    if (!confirm(`Hapus POP "${p.pop_name}"?`)) return;
    await api(`/pops/${p.id}`, { method: 'DELETE' });
    const d = await api('/pops'); pops.value = d.data || [];
}

// ===== Recap Group CRUD =====
function editRecap(r) { Object.assign(recapForm, { id: r.id, name: r.name, group_id: r.group_id }); editingRecap.value = true; }
function cancelRecap() { Object.assign(recapForm, { id: 0, name: '', group_id: '' }); editingRecap.value = false; }
async function saveRecap() {
    const res = await api('/recap-groups', { method: 'POST', body: JSON.stringify(recapForm) });
    if (res.status === 'error') { alert(res.message); return; }
    cancelRecap();
    const d = await api('/recap-groups'); recapGroups.value = d.data || [];
}
async function deleteRecap(r) {
    if (!confirm(`Hapus group "${r.name}"?`)) return;
    await api(`/recap-groups/${r.id}`, { method: 'DELETE' });
    const d = await api('/recap-groups'); recapGroups.value = d.data || [];
}

// ===== Fee Settings =====
function addCategory() { feeSettings.expense_categories.push(''); }
function removeCategory(idx) { feeSettings.expense_categories.splice(idx, 1); }
async function saveFee() {
    saving.value = true;
    try {
        await api('/fee-settings', { method: 'POST', body: JSON.stringify(feeSettings) });
        alert('Fee settings tersimpan!');
    } catch (e) { alert('Gagal'); }
    finally { saving.value = false; }
}

// ===== Google Maps =====
async function saveMaps() {
    saving.value = true;
    try {
        await api('/maps', { method: 'POST', body: JSON.stringify(mapsConfig) });
        alert('Google Maps API key tersimpan!');
    } catch (e) { alert('Gagal'); }
    finally { saving.value = false; }
}

// ===== Public URL =====
function copyUrl() {
    navigator.clipboard.writeText(publicUrl.value);
    alert('URL disalin!');
}

// ===== Helpers =====
function fmtDate(d) { return d ? new Date(d).toLocaleString('id-ID') : '-'; }
function fmtNum(n) { return new Intl.NumberFormat('id-ID').format(n || 0); }
function statusColor(s) {
    s = (s || '').toLowerCase();
    if (['success', 'sent', 'ok'].includes(s)) return 'bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-400';
    if (['failed', 'error', 'fail'].includes(s)) return 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-400';
    return 'bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-300';
}

onMounted(loadAll);
</script>

<template>
    <Head title="Pengaturan" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-10 pb-16">
            <!-- Header -->
            <div class="relative overflow-hidden rounded-3xl border border-gray-200/60 dark:border-white/10 bg-white dark:bg-dark-900 shadow-sm">
                <div class="pointer-events-none absolute inset-0">
                    <div class="absolute -top-24 -right-24 h-72 w-72 rounded-full bg-gradient-to-br from-primary-500/25 to-primary-700/10 blur-3xl"></div>
                    <div class="absolute -bottom-24 -left-24 h-72 w-72 rounded-full bg-gradient-to-br from-emerald-500/10 to-cyan-500/10 blur-3xl"></div>
                </div>
                <div class="relative p-7 sm:p-10">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h1 class="text-3xl sm:text-4xl font-black tracking-tight text-gray-900 dark:text-white">Pengaturan</h1>
                            <p class="mt-2 text-sm sm:text-base text-gray-600 dark:text-gray-400 max-w-2xl">
                                Konfigurasi gateway, notifikasi, template, POP, dan fee teknisi.
                            </p>
                            <p v-if="lastLoadedAt" class="mt-3 text-xs text-gray-500 dark:text-gray-500">
                                Terakhir refresh: <span class="font-semibold">{{ lastLoadedAt.toLocaleString('id-ID') }}</span>
                            </p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <Link
                                v-if="canOpenRoleSettings"
                                href="/settings/roles"
                                class="btn btn-secondary btn-press gap-2"
                            >
                                <SettingsNavIcon name="shield-check" className="h-4 w-4" />
                                <span>Kelola Role</span>
                            </Link>
                            <button @click="loadAll({ refresh: true })" :disabled="refreshing" class="btn btn-secondary btn-press gap-2">
                                <SettingsNavIcon name="refresh" className="h-4 w-4" />
                                <span>{{ refreshing ? 'Refreshing...' : 'Refresh' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading (initial) -->
            <div v-if="loading" class="card p-0 overflow-hidden rounded-2xl">
                <div class="p-10 flex items-center justify-center gap-3">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600"></div>
                    <span class="text-gray-600 dark:text-gray-300 text-sm">Memuat data...</span>
                </div>
            </div>

            <div v-else class="space-y-6 lg:space-y-8">
                <!-- Top Tabs + Submenu Chips (Option B) -->
                <div class="card p-0 overflow-hidden rounded-2xl">
                    <div class="px-5 py-4 sm:px-7 sm:py-5 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex gap-2 overflow-x-auto custom-scrollbar">
                                <div class="shrink-0 inline-flex gap-1 p-1 rounded-2xl bg-gray-100/70 dark:bg-white/5 border border-gray-200/70 dark:border-white/10">
                                    <button
                                        v-for="t in tabs"
                                        :key="t.id"
                                        type="button"
                                        @click="setActiveTab(t.id)"
                                        :class="[
                                            'px-3.5 py-2 rounded-xl text-xs sm:text-sm font-black tracking-tight transition whitespace-nowrap',
                                            activeTab === t.id
                                                ? 'bg-primary-600 text-white shadow-sm shadow-primary-500/20'
                                                : 'text-gray-700 dark:text-gray-200 hover:bg-white/80 dark:hover:bg-white/10'
                                        ]"
                                    >
                                        {{ t.title }}
                                    </button>
                                </div>
                            </div>

                            <div class="relative w-full lg:w-[340px]">
                                <input v-model="navQuery" type="text" class="input !rounded-xl" placeholder="Cari pengaturan...">
                                <div
                                    v-if="navQuery.trim()"
                                    class="absolute z-30 mt-2 w-full rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 shadow-xl overflow-hidden"
                                >
                                    <div v-if="sectionSearchResults.length === 0" class="p-4 text-sm text-gray-500 dark:text-gray-400">
                                        Menu tidak ditemukan.
                                    </div>
                                    <div v-else class="max-h-72 overflow-auto custom-scrollbar p-2">
                                        <button
                                            v-for="s in sectionSearchResults"
                                            :key="s.id"
                                            type="button"
                                            @click="setActiveSection(s.id); navQuery = ''"
                                            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left text-sm font-semibold text-gray-800 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-white/5 transition"
                                        >
                                            <SettingsNavIcon :name="s.icon" className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                                            <span class="flex-1">{{ s.name }}</span>
                                            <span class="text-[10px] font-black tracking-widest text-gray-400 dark:text-gray-500 uppercase">
                                                {{ tabs.find((t) => t.id === tabBySectionId[s.id])?.title || '' }}
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                v-for="s in activeTabSections"
                                :key="s.id"
                                type="button"
                                @click="setActiveSection(s.id)"
                                :class="[
                                    'inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs sm:text-sm font-semibold transition border',
                                    activeSection === s.id
                                        ? 'bg-primary-600 text-white border-primary-600 shadow-sm shadow-primary-500/20'
                                        : 'bg-white dark:bg-dark-900 text-gray-700 dark:text-gray-200 border-gray-200/70 dark:border-white/10 hover:bg-gray-50 dark:hover:bg-white/5'
                                ]"
                            >
                                <SettingsNavIcon :name="s.icon" :className="activeSection === s.id ? 'h-4 w-4 text-white' : 'h-4 w-4 text-gray-400 dark:text-gray-500'" />
                                <span class="whitespace-nowrap">{{ s.name }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div class="min-w-0 space-y-6 lg:space-y-8">

                    <!-- ===== GATEWAY STATUS ===== -->
                    <div v-if="activeSection === 'status'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                            <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Gateway Status</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Ringkasan kondisi & aktivitas terakhir</p>
                        </div>
                        <div class="px-6 py-6 sm:px-8">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-5">
                                <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5 hover:shadow-sm transition">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="h-10 w-10 rounded-2xl bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center shrink-0">
                                                <SettingsNavIcon name="chat" className="h-5 w-5 text-emerald-700 dark:text-emerald-300" />
                                            </div>
                                            <div class="min-w-0">
                                                <div class="text-sm font-bold text-gray-900 dark:text-white">WhatsApp</div>
                                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">
                                                    Last Log: <span class="font-semibold">{{ fmtDate(gatewayStatus.wa?.last_time) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <span :class="[statusColor(gatewayStatus.wa?.last_status), 'px-2 py-0.5 rounded-md text-xs font-bold uppercase whitespace-nowrap']">
                                            {{ gatewayStatus.wa?.last_status || '-' }}
                                        </span>
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5 hover:shadow-sm transition">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="h-10 w-10 rounded-2xl bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center shrink-0">
                                                <SettingsNavIcon name="paper-airplane" className="h-5 w-5 text-blue-700 dark:text-blue-300" />
                                            </div>
                                            <div class="min-w-0">
                                                <div class="text-sm font-bold text-gray-900 dark:text-white">Telegram</div>
                                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">
                                                    Last Log: <span class="font-semibold">{{ fmtDate(gatewayStatus.tg?.last_time) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <span :class="[statusColor(gatewayStatus.tg?.last_status), 'px-2 py-0.5 rounded-md text-xs font-bold uppercase whitespace-nowrap']">
                                            {{ gatewayStatus.tg?.last_status || '-' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== WHATSAPP PRIMARY ===== -->
                    <div v-if="activeSection === 'wa'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">WhatsApp (Gateway Utama)</h2>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Gateway utama untuk notifikasi personal dan group.</p>
                            </div>
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ waConfig.is_active ? 'Aktif' : 'Non-aktif' }}</span>
                                <input type="checkbox" v-model="waConfig.is_active" :true-value="1" :false-value="0" class="h-5 w-5 rounded-md text-primary-600 dark:bg-gray-700">
                            </label>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">URL Pesan Personal</label>
                                    <input v-model="waConfig.base_url" type="url" class="input w-full" placeholder="https://api.balesotomatis.id/v1/send-message">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">API Token</label>
                                    <input v-model="waConfig.token" type="text" class="input w-full" placeholder="Token...">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Device Key (Sender)</label>
                                    <input v-model="waConfig.sender_number" type="text" class="input w-full" placeholder="Device Key...">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Target Tes</label>
                                    <input v-model="waConfig.target_number" type="text" class="input w-full" placeholder="08xxx">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Mode Failover</label>
                                    <select v-model="waConfig.failover_mode" class="input w-full">
                                        <option value="manual">Manual (tetap gateway aktif)</option>
                                        <option value="auto">Auto (pindah ke backup jika gagal)</option>
                                    </select>
                                    <p class="text-xs text-gray-400 mt-1">Manual: aktifkan hanya gateway yang ingin dipakai. Auto: gunakan backup bila primary gagal.</p>
                                </div>
                            </div>

                            <!-- Advanced Group Settings -->
                            <details class="rounded-2xl border border-gray-200/70 dark:border-white/10 overflow-hidden bg-gray-50/60 dark:bg-dark-900/30">
                                <summary class="px-5 py-4 cursor-pointer text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-100/80 dark:hover:bg-white/5">
                                    Pengaturan Group (Advanced)
                                </summary>
                                <div class="p-5 space-y-4 bg-white dark:bg-dark-950 border-t border-gray-200/70 dark:border-white/10">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">URL Pesan Group</label>
                                        <input v-model="waConfig.group_url" type="url" class="input w-full" placeholder="https://api.balesotomatis.id/v1/send-group-message">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Default Group ID</label>
                                        <input v-model="waConfig.group_id" type="text" class="input w-full" placeholder="xxx@g.us">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Group Laporan Rekap</label>
                                        <input v-model="waConfig.recap_group_id" type="text" class="input w-full" placeholder="xxx@g.us">
                                        <p class="text-xs text-gray-400 mt-1">Grup khusus untuk laporan harian teknisi. Jika kosong, gunakan Default Group ID.</p>
                                    </div>
                                </div>
                            </details>

                            <div class="flex flex-wrap gap-2 pt-5 border-t border-gray-200/70 dark:border-white/10">
                                <button @click="doTestWa(false)" :disabled="testLoading === 'wa_personal'" class="btn btn-secondary">
                                    {{ testLoading === 'wa_personal' ? 'Sending...' : 'Tes Personal' }}
                                </button>
                                <button @click="doTestWa(true)" :disabled="testLoading === 'wa_group'" class="btn btn-secondary">
                                    {{ testLoading === 'wa_group' ? 'Sending...' : 'Tes Group' }}
                                </button>
                                <button @click="saveWa" :disabled="saving" class="btn btn-primary ml-auto">
                                    {{ saving ? 'Menyimpan...' : 'Simpan' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ===== MPWA BACKUP ===== -->
                    <div v-if="activeSection === 'mpwa'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">WhatsApp Backup (MPWA)</h2>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Gateway cadangan untuk failover atau penggunaan manual.</p>
                            </div>
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ mpwaConfig.is_active ? 'Aktif' : 'Non-aktif' }}</span>
                                <input type="checkbox" v-model="mpwaConfig.is_active" :true-value="1" :false-value="0" class="h-5 w-5 rounded-md text-orange-600 dark:bg-gray-700">
                            </label>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">URL API</label>
                                    <input v-model="mpwaConfig.base_url" type="url" class="input w-full" placeholder="https://app.mpwa.net/send-message">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">API Key</label>
                                    <input v-model="mpwaConfig.token" type="text" class="input w-full">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Sender</label>
                                    <input v-model="mpwaConfig.sender_number" type="text" class="input w-full" placeholder="62888xxxx">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Target Tes</label>
                                    <input v-model="mpwaConfig.target_number" type="text" class="input w-full" placeholder="62888xxxx atau xxx@g.us">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Default Group ID</label>
                                    <input v-model="mpwaConfig.group_id" type="text" class="input w-full" placeholder="xxx@g.us">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Footer (Opsional)</label>
                                    <input v-model="mpwaConfig.footer" type="text" class="input w-full" placeholder="Sent via mpwa">
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2 pt-5 border-t border-gray-200/70 dark:border-white/10">
                                <button @click="doTestMpwa(false)" :disabled="testLoading === 'mpwa_personal'" class="btn btn-secondary">
                                    {{ testLoading === 'mpwa_personal' ? 'Sending...' : 'Tes Personal' }}
                                </button>
                                <button @click="doTestMpwa(true)" :disabled="testLoading === 'mpwa_group'" class="btn btn-secondary">
                                    {{ testLoading === 'mpwa_group' ? 'Sending...' : 'Tes Group' }}
                                </button>
                                <button @click="saveMpwa" :disabled="saving" class="btn btn-primary ml-auto">
                                    {{ saving ? 'Menyimpan...' : 'Simpan' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ===== TELEGRAM ===== -->
                    <div v-if="activeSection === 'tg'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                            <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Telegram Backup</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Backup notifikasi jika WhatsApp bermasalah.</p>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Bot Token</label>
                                    <input v-model="tgConfig.bot_token" type="text" class="input w-full" placeholder="123:ABC...">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Chat ID</label>
                                    <input v-model="tgConfig.chat_id" type="text" class="input w-full" placeholder="-100xxx">
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2 pt-5 border-t border-gray-200/70 dark:border-white/10">
                                <button @click="doTestTg" :disabled="testLoading === 'tg'" class="btn btn-secondary">
                                    {{ testLoading === 'tg' ? 'Sending...' : 'Tes Kirim' }}
                                </button>
                                <button @click="saveTg" :disabled="saving" class="btn btn-primary ml-auto">
                                    {{ saving ? 'Menyimpan...' : 'Simpan' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ===== TEMPLATES ===== -->
                    <div v-if="activeSection === 'templates'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Template Pesan</h2>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Kelola template notifikasi (WA/Telegram) yang dipakai sistem.</p>
                            </div>
                            <button @click="openTemplateNew" class="btn btn-primary shrink-0">+ Tambah</button>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-4">
                            <div v-for="tpl in templates" :key="tpl.id || tpl.code" class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5 hover:shadow-sm transition">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="font-bold text-gray-900 dark:text-white">{{ tpl.code }}</h3>
                                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 whitespace-pre-line">{{ tpl.message }}</p>
                                    </div>
                                </div>
                                <div class="mt-4 flex gap-3">
                                    <button @click="openTemplateEdit(tpl)" class="text-sm font-semibold text-primary-600 hover:text-primary-800">Edit</button>
                                    <button @click="deleteTemplate(tpl)" class="text-sm font-semibold text-red-600 hover:text-red-800">Hapus</button>
                                </div>
                            </div>
                            <div v-if="templates.length === 0" class="text-center py-12 text-gray-500 dark:text-gray-400">Belum ada template</div>
                        </div>
                    </div>

                    <!-- ===== POP MANAGEMENT ===== -->
                    <div v-if="activeSection === 'pops'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                            <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Manajemen Data POP</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Pengaturan area & group notifikasi spesifik.</p>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-6">
                            <!-- POP Form -->
                            <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5">
                                <div class="flex flex-col lg:flex-row gap-4 items-end">
                                    <div class="flex-1 w-full">
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Nama POP / Area</label>
                                        <input v-model="popForm.pop_name" type="text" class="input w-full" placeholder="Contoh: Krui Selatan...">
                                    </div>
                                    <div class="w-full lg:w-1/4">
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">WA Admin (PIC)</label>
                                        <input v-model="popForm.wa_number" type="text" class="input w-full" placeholder="08xxx...">
                                    </div>
                                    <div class="w-full lg:w-1/3">
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Group ID (Notif)</label>
                                        <input v-model="popForm.group_id" type="text" class="input w-full" placeholder="xxxx@g.us">
                                    </div>
                                    <div class="flex gap-2">
                                        <button v-if="editingPop" @click="cancelPop" class="btn btn-secondary">Batal</button>
                                        <button @click="savePop" class="btn btn-primary whitespace-nowrap">
                                            {{ editingPop ? 'Update' : '+ Tambah' }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- POP Table -->
                            <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 overflow-hidden">
                                <div class="max-h-72 overflow-y-auto">
                                    <table class="w-full text-left">
                                        <thead class="bg-gray-50/80 dark:bg-dark-900/60 text-[11px] uppercase font-black tracking-widest text-gray-500 sticky top-0">
                                            <tr>
                                                <th class="px-4 py-3">Nama Area</th>
                                                <th class="px-4 py-3">PIC WA</th>
                                                <th class="px-4 py-3">Group ID</th>
                                                <th class="px-4 py-3 text-right">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-sm divide-y divide-gray-200 dark:divide-white/10 bg-white dark:bg-dark-950">
                                            <tr v-for="p in pops" :key="p.id" class="hover:bg-gray-50/80 dark:hover:bg-white/5">
                                                <td class="px-4 py-3 text-gray-900 dark:text-white">{{ p.pop_name }}</td>
                                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ p.wa_number || '-' }}</td>
                                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ p.group_id || '-' }}</td>
                                                <td class="px-4 py-3 text-right space-x-3">
                                                    <button @click="editPop(p)" class="text-sm font-semibold text-primary-600 hover:text-primary-800">Edit</button>
                                                    <button @click="deletePop(p)" class="text-sm font-semibold text-red-600 hover:text-red-800">Hapus</button>
                                                </td>
                                            </tr>
                                            <tr v-if="pops.length === 0">
                                                <td colspan="4" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">Belum ada POP</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== RECAP GROUPS ===== -->
                    <div v-if="activeSection === 'recap_groups'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                            <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Manajemen Group Rekap</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Kelola grup WhatsApp untuk laporan teknisi.</p>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-6">
                            <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5">
                                <div class="flex flex-col lg:flex-row gap-4 items-end">
                                    <div class="flex-1 w-full">
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Nama Group Rekap</label>
                                        <input v-model="recapForm.name" type="text" class="input w-full" placeholder="Contoh: Rekap Jakarta...">
                                    </div>
                                    <div class="flex-1 w-full">
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Group ID WhatsApp</label>
                                        <input v-model="recapForm.group_id" type="text" class="input w-full" placeholder="xxxx@g.us">
                                    </div>
                                    <div class="flex gap-2">
                                        <button v-if="editingRecap" @click="cancelRecap" class="btn btn-secondary">Batal</button>
                                        <button @click="saveRecap" class="btn btn-primary whitespace-nowrap">
                                            {{ editingRecap ? 'Update' : '+ Tambah' }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 overflow-hidden">
                                <div class="max-h-72 overflow-y-auto">
                                    <table class="w-full text-left">
                                        <thead class="bg-gray-50/80 dark:bg-dark-900/60 text-[11px] uppercase font-black tracking-widest text-gray-500 sticky top-0">
                                            <tr>
                                                <th class="px-4 py-3">Nama Group</th>
                                                <th class="px-4 py-3">Group ID</th>
                                                <th class="px-4 py-3 text-right">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-sm divide-y divide-gray-200 dark:divide-white/10 bg-white dark:bg-dark-950">
                                            <tr v-for="r in recapGroups" :key="r.id" class="hover:bg-gray-50/80 dark:hover:bg-white/5">
                                                <td class="px-4 py-3 text-gray-900 dark:text-white">{{ r.name }}</td>
                                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ r.group_id }}</td>
                                                <td class="px-4 py-3 text-right space-x-3">
                                                    <button @click="editRecap(r)" class="text-sm font-semibold text-primary-600 hover:text-primary-800">Edit</button>
                                                    <button @click="deleteRecap(r)" class="text-sm font-semibold text-red-600 hover:text-red-800">Hapus</button>
                                                </td>
                                            </tr>
                                            <tr v-if="recapGroups.length === 0">
                                                <td colspan="3" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">Belum ada group</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== FEE SETTINGS ===== -->
                    <div v-if="activeSection === 'fee'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                            <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Konfigurasi Fee Teknisi</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Atur fee instalasi dan kategori pengeluaran.</p>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-6">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Fee Instalasi - Teknisi (Rp)</label>
                                    <input v-model.number="feeSettings.teknisi_fee_install" type="number" min="0" class="input w-full" placeholder="0">
                                    <p class="text-xs text-gray-400 mt-1">Fee default untuk setiap teknisi per instalasi</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Fee Instalasi - Sales (Rp)</label>
                                    <input v-model.number="feeSettings.sales_fee_install" type="number" min="0" class="input w-full" placeholder="0">
                                    <p class="text-xs text-gray-400 mt-1">Fee default untuk setiap sales per instalasi</p>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5">
                                <div class="flex items-center justify-between gap-3 mb-4">
                                    <div>
                                        <h5 class="text-sm font-black tracking-tight text-gray-900 dark:text-white">Kategori Pengeluaran</h5>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Dipakai untuk input pengeluaran teknisi.</p>
                                    </div>
                                    <button @click="addCategory" class="btn btn-secondary shrink-0">+ Tambah</button>
                                </div>

                                <div v-if="feeSettings.expense_categories.length > 0" class="space-y-2">
                                    <div v-for="(cat, idx) in feeSettings.expense_categories" :key="idx" class="flex items-center gap-2">
                                        <input v-model="feeSettings.expense_categories[idx]" type="text" class="input flex-1" :placeholder="`Kategori ${idx + 1}`">
                                        <button @click="removeCategory(idx)" class="h-10 w-10 rounded-xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-900 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="Hapus">
                                            ✕
                                        </button>
                                    </div>
                                </div>
                                <div v-else class="text-sm text-gray-500 dark:text-gray-400">
                                    Belum ada kategori. Klik <span class="font-semibold">Tambah</span> untuk membuat.
                                </div>
                            </div>

                            <div class="pt-2">
                                <button @click="saveFee" :disabled="saving" class="btn btn-primary">
                                    {{ saving ? 'Menyimpan...' : 'Simpan Konfigurasi' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ===== PUBLIC URL ===== -->
                    <div v-if="activeSection === 'public_url'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                            <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Link Isolir (URL Publik)</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Link tenant untuk halaman isolir/direct/chat.</p>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-4">
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input :value="publicUrl" readonly class="input flex-1 font-mono text-sm bg-gray-50 dark:bg-gray-800" placeholder="Belum tersedia">
                                <button @click="copyUrl" class="btn btn-primary shrink-0">Copy</button>
                            </div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Bagikan link ini ke pelanggan agar masuk ke halaman isolir/chat.</p>
                        </div>
                    </div>

                    <!-- ===== GOOGLE MAPS ===== -->
                    <div v-if="activeSection === 'maps'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                            <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Google Maps</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">API key untuk modul yang membutuhkan peta (contoh: Kabel FO).</p>
                        </div>

                        <div class="px-6 py-6 sm:px-8 space-y-5">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-1">Google Maps API Key</label>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <input
                                        v-model="mapsConfig.google_maps_api_key"
                                        :type="showMapsKey ? 'text' : 'password'"
                                        class="input flex-1 font-mono text-sm"
                                        placeholder="AIzaSy..."
                                        autocomplete="off"
                                    >
                                    <button @click="showMapsKey = !showMapsKey" class="btn btn-secondary shrink-0">
                                        {{ showMapsKey ? 'Hide' : 'Show' }}
                                    </button>
                                </div>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Catatan: API key dipakai di browser (bukan rahasia). Tetap wajib dibatasi via HTTP referrer/domain agar aman.
                                </p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Pastikan API <span class="font-semibold">Maps JavaScript API</span> aktif di Google Cloud dan billing aktif.
                                </p>
                            </div>

                            <div class="pt-1">
                                <button @click="saveMaps" :disabled="saving" class="btn btn-primary">
                                    {{ saving ? 'Menyimpan...' : 'Simpan Google Maps Key' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ===== LOG NOTIFIKASI ===== -->
                    <div v-if="activeSection === 'logs'" class="space-y-6">
                        <div class="card p-0 overflow-hidden rounded-2xl">
                            <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                                <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Log Notifikasi</h2>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Monitoring status pengiriman notifikasi (WA/Telegram).</p>
                            </div>

                            <div class="px-6 py-6 sm:px-8 space-y-6">
                                <!-- Stats -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5 text-center">
                                        <div class="text-3xl font-black text-gray-900 dark:text-white">{{ notifStats.total }}</div>
                                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Total</div>
                                    </div>
                                    <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5 text-center">
                                        <div class="text-3xl font-black text-emerald-600 dark:text-emerald-300">{{ notifStats.sent }}</div>
                                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Terkirim</div>
                                    </div>
                                    <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-950 p-5 text-center">
                                        <div class="text-3xl font-black text-red-600 dark:text-red-300">{{ notifStats.failed }}</div>
                                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Gagal</div>
                                    </div>
                                </div>

                                <!-- Filters -->
                                <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                                    <div class="flex flex-wrap gap-2">
                                        <select v-model="logFilter.range" @change="loadLogs" class="input text-sm !rounded-xl">
                                            <option value="">All Time</option>
                                            <option value="24h">24 Jam</option>
                                            <option value="7d">7 Hari</option>
                                            <option value="30d">30 Hari</option>
                                        </select>
                                        <select v-model="logFilter.status" @change="loadLogs" class="input text-sm !rounded-xl">
                                            <option value="">All Status</option>
                                            <option value="success">Success</option>
                                            <option value="failed">Failed</option>
                                        </select>
                                        <input v-model="logFilter.search" @input="loadLogs" type="text" class="input text-sm w-56 !rounded-xl" placeholder="Cari...">
                                    </div>
                                    <button @click="loadLogs" class="btn btn-secondary sm:ml-auto">Refresh</button>
                                </div>
                            </div>
                        </div>

                        <!-- Log table -->
                        <div class="card p-0 overflow-hidden rounded-2xl">
                            <div class="px-6 py-4 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-gray-50/80 dark:bg-dark-900/40 backdrop-blur flex items-center justify-between gap-4">
                                <div>
                                    <h3 class="text-sm font-black tracking-tight text-gray-900 dark:text-white">Daftar Log</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Menampilkan {{ notifLogs.length }} baris</p>
                                </div>
                                <button @click="loadLogs" class="btn btn-secondary shrink-0">Refresh</button>
                            </div>
                            <div class="overflow-x-auto max-h-96">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                                    <thead class="bg-white/70 dark:bg-dark-950/60 sticky top-0">
                                        <tr class="text-[11px] uppercase font-black tracking-widest text-gray-500 dark:text-gray-400">
                                            <th class="px-4 py-3 text-left">Waktu</th>
                                            <th class="px-4 py-3 text-left">Platform</th>
                                            <th class="px-4 py-3 text-left">Target</th>
                                            <th class="px-4 py-3 text-left">Pesan</th>
                                            <th class="px-4 py-3 text-left">Status</th>
                                            <th class="px-4 py-3 text-left">Response</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-dark-950 divide-y divide-gray-200 dark:divide-white/10">
                                        <tr v-for="log in notifLogs" :key="log.id" class="hover:bg-gray-50/80 dark:hover:bg-white/5">
                                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ fmtDate(log.timestamp) }}</td>
                                            <td class="px-4 py-3 text-xs text-gray-900 dark:text-gray-200">{{ log.platform }}</td>
                                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ log.target }}</td>
                                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-[260px] truncate">{{ log.message }}</td>
                                            <td class="px-4 py-3">
                                                <span :class="[statusColor(log.normalized_status || log.status), 'px-2 py-1 text-xs rounded-full font-bold uppercase']">
                                                    {{ log.normalized_status || log.status }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-[260px] truncate">{{ log.response_log || '-' }}</td>
                                        </tr>
                                        <tr v-if="notifLogs.length === 0">
                                            <td colspan="6" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">Tidak ada log</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ===== SYSTEM UPDATE ===== -->
                    <div v-if="activeSection === 'system_update'" class="card p-0 overflow-hidden rounded-2xl">
                        <div class="px-6 py-5 sm:px-8 border-b border-gray-200/70 dark:border-white/10 bg-white/60 dark:bg-dark-900/40 backdrop-blur">
                            <h2 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white">Update Sistem</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Update panel via ZIP atau paket build dari GitHub.</p>
                        </div>
                        <div class="px-6 py-6 sm:px-8">
                            <SystemUpdatePanel embedded />
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Template Form Modal -->
        <div v-if="showTemplateForm" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen pt-4 px-4">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80" @click="showTemplateForm = false"></div>
                <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full">
                    <div class="px-6 py-4 border-b dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">{{ editingTemplateCode ? 'Edit Template' : 'Tambah Template' }}</h3>
                        <button @click="showTemplateForm = false" class="text-gray-400 hover:text-gray-500">✕</button>
                    </div>
                    <form @submit.prevent="saveTemplate" class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Kode Template *</label>
                            <input v-model="templateForm.code" type="text" required class="input w-full" :disabled="!!editingTemplateCode" placeholder="web_welcome">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Konten *</label>
                            <textarea v-model="templateForm.message" rows="5" required class="input w-full" placeholder="Tulis template..."></textarea>
                        </div>
                        <!-- Variable buttons -->
                        <div v-if="installVars.length > 0" class="bg-blue-50 dark:bg-gray-900 p-3 rounded-lg border dark:border-gray-700">
                            <p class="text-xs text-gray-500 font-bold uppercase mb-2">Klik untuk insert variable:</p>
                            <div class="flex flex-wrap gap-1">
                                <button v-for="v in installVars" :key="v" type="button" @click="insertVar(v)"
                                    class="px-2 py-1 text-xs bg-white dark:bg-gray-800 border dark:border-gray-600 rounded hover:bg-blue-100 dark:hover:bg-blue-900 transition">
                                    {<span>{{ v }}</span>}
                                </button>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-4 border-t dark:border-gray-700">
                            <button type="button" @click="showTemplateForm = false" class="btn btn-secondary">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

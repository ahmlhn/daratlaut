<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/* ───────────── State ───────────── */
const mapContainer = ref(null);
let map = null;
const techMarkers = new Map();
const instMarkers = [];
let historyLine = null;
let historyCircles = [];
let baseLayer = null;
let labelLayer = null;
let refreshTimer = null;
let hasFit = false;
let fullKeyHandler = null;

const technicians = ref([]);
const installations = ref([]);
const selectedId = ref(null);
const searchQuery = ref('');
const isFull = ref(false);
const showPanel = ref(true);
const mapStyle = ref('voyager');
const lastRefresh = ref(new Date());

// History per tech
const historyMeta = ref({}); // { [techId]: { from, to, statusText, statusTone } }

const API_BASE = '/api/v1';

/* ───────────── Map Styles ───────────── */
const mapStyles = {
    voyager: {
        label: 'Carto Voyager',
        url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors © CARTO',
    },
    light: {
        label: 'Carto Light',
        url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors © CARTO',
    },
    satellite: {
        label: 'Esri Satellite + Label',
        url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        labelUrl: 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
        maxZoom: 19,
        attribution: 'Tiles © Esri',
        labelAttribution: 'Labels © Esri',
    },
};

/* ───────────── Helpers ───────────── */
function escHtml(val) {
    return String(val ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] || c);
}

function formatRelative(value) {
    if (!value) return '-';
    const iso = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
    const d = new Date(iso);
    if (isNaN(d.getTime())) return value;
    const diffMs = Date.now() - d.getTime();
    if (diffMs < 60000) return 'baru saja';
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 60) return diffMin + ' menit lalu';
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return diffHr + ' jam lalu';
    return Math.floor(diffHr / 24) + ' hari lalu';
}

function statusColor(recordedAt) {
    if (!recordedAt) return 'text-slate-400';
    const iso = String(recordedAt).includes('T') ? String(recordedAt) : String(recordedAt).replace(' ', 'T');
    const d = new Date(iso);
    if (isNaN(d.getTime())) return 'text-slate-400';
    const diffMin = Math.floor((Date.now() - d.getTime()) / 60000);
    if (diffMin <= 10) return 'text-emerald-600';
    if (diffMin <= 30) return 'text-amber-600';
    return 'text-red-500';
}

function isOnlineRecently(recordedAt) {
    if (!recordedAt) return false;
    const iso = String(recordedAt).includes('T') ? String(recordedAt) : String(recordedAt).replace(' ', 'T');
    const d = new Date(iso);
    return !isNaN(d.getTime()) && (Date.now() - d.getTime()) < 600000; // 10 min
}

function statusDotBg(recordedAt) {
    if (!recordedAt) return 'bg-slate-400';
    const iso = String(recordedAt).includes('T') ? String(recordedAt) : String(recordedAt).replace(' ', 'T');
    const d = new Date(iso);
    if (isNaN(d.getTime())) return 'bg-slate-400';
    const diffMin = Math.floor((Date.now() - d.getTime()) / 60000);
    if (diffMin <= 10) return 'bg-emerald-500';
    if (diffMin <= 30) return 'bg-amber-500';
    return 'bg-slate-400';
}

function todayStr() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function getHistoryMeta(techId) {
    const key = String(techId);
    if (!historyMeta.value[key]) {
        const today = todayStr();
        historyMeta.value[key] = { from: today, to: today, statusText: '', statusTone: 'muted' };
    }
    return historyMeta.value[key];
}

/* ───────────── Computed ───────────── */
const filteredTechnicians = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    let list = technicians.value;
    if (q) list = list.filter(t => String(t.technician_name || '').toLowerCase().includes(q));
    // Sort: terbaru (recorded_at) di atas
    return [...list].sort((a, b) => {
        const da = a.recorded_at ? new Date(String(a.recorded_at).replace(' ', 'T')).getTime() : 0;
        const db = b.recorded_at ? new Date(String(b.recorded_at).replace(' ', 'T')).getTime() : 0;
        return db - da;
    });
});

const selectedTech = computed(() => {
    if (!selectedId.value) return null;
    return technicians.value.find(t => String(t.technician_id) === String(selectedId.value)) || null;
});

const onlineCount = computed(() => technicians.value.filter(t => t.latitude && t.longitude).length);

/* ───────────── Map Init ───────────── */
function initMap() {
    if (!window.L || !mapContainer.value) return;
    map = window.L.map(mapContainer.value, { zoomControl: true }).setView([-2.5489, 118.0149], 5);
    applyMapStyle(mapStyle.value);
    setTimeout(() => map?.invalidateSize(), 200);
}

function applyMapStyle(styleId) {
    if (!map || !window.L) return;
    const selected = mapStyles[styleId] || mapStyles.voyager;
    if (baseLayer) map.removeLayer(baseLayer);
    if (labelLayer) { map.removeLayer(labelLayer); labelLayer = null; }
    baseLayer = window.L.tileLayer(selected.url, { maxZoom: selected.maxZoom || 19, attribution: selected.attribution || '' }).addTo(map);
    if (selected.labelUrl) {
        labelLayer = window.L.tileLayer(selected.labelUrl, { maxZoom: selected.maxZoom || 19, attribution: selected.labelAttribution || '' }).addTo(map);
        labelLayer.setZIndex(200);
    }
    mapStyle.value = mapStyles[styleId] ? styleId : 'voyager';
    try { localStorage.setItem('maps_teknisi_style', mapStyle.value); } catch {}
}

function onStyleChange(e) {
    applyMapStyle(e.target.value);
}

/* ───────────── Markers ───────────── */
function buildMarkerHtml(name, isSelected) {
    const safeName = escHtml(name || '-');
    const selClass = isSelected ? ' is-selected' : '';
    return `<div class="tech-marker${selClass}">
        <div class="tech-marker-label">${safeName}</div>
        <div class="tech-marker-icon">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/></svg>
        </div>
        <div class="tech-marker-pointer"></div>
    </div>`;
}

function updateTechMarkers() {
    if (!map || !window.L) return;
    const incomingIds = new Set();
    technicians.value.forEach(item => {
        const id = String(item.technician_id);
        incomingIds.add(id);
        const lat = parseFloat(item.latitude);
        const lng = parseFloat(item.longitude);
        if (!isFinite(lat) || !isFinite(lng)) return;
        const isSelected = String(selectedId.value) === id;
        const icon = window.L.divIcon({
            className: 'tech-marker-wrapper',
            html: buildMarkerHtml(item.technician_name, isSelected),
            iconAnchor: [0, 0],
        });
        let marker = techMarkers.get(id);
        if (!marker) {
            marker = window.L.marker([lat, lng], { icon }).addTo(map);
            marker.on('click', () => selectTechnician(id));
            techMarkers.set(id, marker);
        } else {
            marker.setLatLng([lat, lng]);
            marker.setIcon(icon);
        }
        if (marker.getPopup()) marker.unbindPopup();
    });
    // Remove stale markers
    techMarkers.forEach((marker, id) => {
        if (!incomingIds.has(id)) {
            map.removeLayer(marker);
            techMarkers.delete(id);
        }
    });
    // Fit bounds on first load
    if (!hasFit && techMarkers.size > 0) {
        const group = window.L.featureGroup(Array.from(techMarkers.values()));
        map.fitBounds(group.getBounds().pad(0.2));
        hasFit = true;
    }
}

function updateInstallationMarkers() {
    if (!map || !window.L) return;
    instMarkers.forEach(m => map.removeLayer(m));
    instMarkers.length = 0;
    const colors = { Baru: '#3b82f6', Survey: '#8b5cf6', Proses: '#eab308', Pending: '#f97316' };
    installations.value.forEach(inst => {
        if (!inst.lat || !inst.lng) return;
        const color = colors[inst.status] || '#6b7280';
        const icon = window.L.divIcon({
            className: 'custom-inst-marker',
            html: `<div style="width:24px;height:24px;border-radius:999px;background:#fff;display:flex;align-items:center;justify-content:center;border:2px solid ${color};box-shadow:0 2px 6px rgba(0,0,0,.15)"><div style="width:10px;height:10px;border-radius:999px;background:${color}"></div></div>`,
            iconSize: [24, 24],
            iconAnchor: [12, 12],
        });
        const m = window.L.marker([inst.lat, inst.lng], { icon })
            .addTo(map)
            .bindPopup(`<div class="p-2 text-xs"><div class="font-bold">${escHtml(inst.nama)}</div><div class="text-gray-500">${escHtml(inst.alamat)}</div><div><span style="color:${color}">${inst.status}</span></div><div class="text-gray-400">POP: ${escHtml(inst.pop || '-')}</div></div>`);
        instMarkers.push(m);
    });
}

/* ───────────── Selection ───────────── */
function selectTechnician(techId) {
    if (String(selectedId.value) === String(techId)) {
        selectedId.value = null;
    } else {
        selectedId.value = String(techId);
        // Pan map
        const item = technicians.value.find(t => String(t.technician_id) === String(techId));
        if (item && map) {
            const lat = parseFloat(item.latitude);
            const lng = parseFloat(item.longitude);
            if (isFinite(lat) && isFinite(lng)) map.setView([lat, lng], 16);
        }
    }
    updateTechMarkers();
}

/* ───────────── History ───────────── */
function clearHistory() {
    if (!map || !window.L) return;
    if (historyLine) { map.removeLayer(historyLine); historyLine = null; }
    historyCircles.forEach(c => map.removeLayer(c));
    historyCircles = [];
}

async function loadHistory(techId) {
    const id = String(techId || selectedId.value || '');
    if (!id) return;
    const meta = getHistoryMeta(id);
    meta.statusText = 'Memuat jejak...';
    meta.statusTone = 'muted';
    try {
        const params = new URLSearchParams();
        if (meta.from) params.append('date_from', meta.from);
        if (meta.to) params.append('date_to', meta.to);
        const res = await fetch(`${API_BASE}/maps/history/${id}?${params}`);
        const json = await res.json();
        if (!json.success && !json.status) throw new Error(json.message || 'Gagal memuat');
        const points = Array.isArray(json.data) ? json.data : [];
        drawHistory(points);
        if (points.length === 0) {
            meta.statusText = 'Jejak tidak ditemukan.';
            meta.statusTone = 'error';
        } else {
            meta.statusText = `${points.length} titik ditampilkan.`;
            meta.statusTone = 'success';
        }
    } catch (e) {
        meta.statusText = 'Gagal memuat jejak.';
        meta.statusTone = 'error';
    }
}

function drawHistory(points) {
    clearHistory();
    if (!map || !window.L || points.length === 0) return;
    const latlngs = points
        .map(p => [parseFloat(p.latitude), parseFloat(p.longitude)])
        .filter(v => isFinite(v[0]) && isFinite(v[1]));
    if (latlngs.length === 0) return;
    historyLine = window.L.polyline(latlngs, { color: '#2563eb', weight: 3, opacity: 0.85 }).addTo(map);
    // Start marker (green)
    historyCircles.push(window.L.circleMarker(latlngs[0], { radius: 5, color: '#16a34a', fillColor: '#16a34a', fillOpacity: 0.9 }).addTo(map));
    // End marker (red)
    historyCircles.push(window.L.circleMarker(latlngs[latlngs.length - 1], { radius: 5, color: '#dc2626', fillColor: '#dc2626', fillOpacity: 0.9 }).addTo(map));
    map.fitBounds(historyLine.getBounds().pad(0.2));
}

/* ───────────── Data Loading ───────────── */
async function loadLocations() {
    try {
        const res = await fetch(`${API_BASE}/maps/locations`);
        const data = await res.json();
        if (data.success || data.status === 'success') {
            technicians.value = data.data || [];
            updateTechMarkers();
        }
    } catch (e) {
        console.error('Maps: failed to load locations', e);
    }
    lastRefresh.value = new Date();
}

async function loadInstallations() {
    try {
        const res = await fetch(`${API_BASE}/maps/installations`);
        const data = await res.json();
        if (data.success) {
            installations.value = data.data || [];
            updateInstallationMarkers();
        }
    } catch (e) {
        console.error('Maps: failed to load installations', e);
    }
}

function refresh() {
    loadLocations();
    loadInstallations();
}

/* ───────────── Fullscreen ───────────── */
function toggleFull() {
    isFull.value = !isFull.value;
    nextTick(() => {
        setTimeout(() => map?.invalidateSize(), 200);
    });
}

/* ───────────── Lifecycle ───────────── */
onMounted(() => {
    // Restore map style preference
    try {
        const saved = localStorage.getItem('maps_teknisi_style');
        if (saved && mapStyles[saved]) mapStyle.value = saved;
    } catch {}

    // Load Leaflet
    const loadLeaflet = () => {
        if (window.L) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load Leaflet'));
            document.head.appendChild(script);
        });
    };

    loadLeaflet().then(() => {
        nextTick(() => {
            initMap();
            loadLocations();
            loadInstallations();
        });
    });

    refreshTimer = setInterval(() => loadLocations(), 30000);

    fullKeyHandler = (e) => {
        if (e.key === 'Escape' && isFull.value) toggleFull();
    };
    document.addEventListener('keydown', fullKeyHandler);
});

onUnmounted(() => {
    if (refreshTimer) clearInterval(refreshTimer);
    if (fullKeyHandler) document.removeEventListener('keydown', fullKeyHandler);
    clearHistory();
    if (map) { map.remove(); map = null; }
    techMarkers.clear();
    hasFit = false;
});
</script>

<template>
    <Head title="Maps Teknisi" />
    <AdminLayout>
        <!-- Map-first layout: map fills all space, panels float on top -->
        <div :class="['relative', isFull ? 'fixed inset-0 z-[60]' : 'h-[calc(100vh-5.5rem)] lg:h-[calc(100vh-4rem)]']" style="min-height: 400px;">

            <!-- ─── Map (full area) ─── -->
            <div ref="mapContainer" class="absolute inset-0 bg-slate-100 dark:bg-slate-900" :class="[isFull ? '' : 'rounded-2xl overflow-hidden']"></div>

            <!-- ─── Floating top-left: Toggle sidebar + stats ─── -->
            <div class="absolute top-3 left-3 z-[600] flex items-center gap-2">
                <!-- Toggle sidebar -->
                <button @click="showPanel = !showPanel" type="button"
                    class="maps-glass-btn w-10 h-10 flex items-center justify-center"
                    :title="showPanel ? 'Sembunyikan panel' : 'Tampilkan panel'">
                    <svg v-if="!showPanel" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <!-- Compact stats badges -->
                <div class="maps-glass-chip">
                    <span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span>
                    <span class="text-emerald-600 dark:text-emerald-400 font-bold">{{ onlineCount }}</span>
                    <span class="text-slate-400 dark:text-slate-500">/</span>
                    <span class="font-semibold">{{ technicians.length }}</span>
                    <span class="text-slate-400 dark:text-slate-500 text-[10px]">teknisi</span>
                </div>
                <div v-if="installations.length > 0" class="maps-glass-chip">
                    <span class="inline-flex h-2 w-2 rounded-full bg-amber-500"></span>
                    <span class="font-semibold">{{ installations.length }}</span>
                    <span class="text-slate-400 dark:text-slate-500 text-[10px]">instalasi</span>
                </div>
            </div>

            <!-- ─── Floating top-right: Controls ─── -->
            <div class="absolute top-3 right-3 z-[600] flex items-center gap-1.5">
                <!-- Map style selector -->
                <div class="maps-glass-btn px-3 h-10 flex items-center gap-2">
                    <svg class="w-4 h-4 text-slate-500 dark:text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z"/></svg>
                    <select v-model="mapStyle" @change="onStyleChange" class="bg-transparent text-xs font-bold text-slate-700 dark:text-slate-200 focus:outline-none cursor-pointer pr-1">
                        <option value="voyager">Voyager</option>
                        <option value="light">Light</option>
                        <option value="satellite">Satelit</option>
                    </select>
                </div>
                <!-- Refresh -->
                <button @click="refresh" type="button" class="maps-glass-btn w-10 h-10 flex items-center justify-center" title="Refresh lokasi">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182M20.015 4.356v4.992"/></svg>
                </button>
                <!-- Fullscreen -->
                <button @click="toggleFull" type="button" class="maps-glass-btn w-10 h-10 flex items-center justify-center" :title="isFull ? 'Keluar fullscreen' : 'Fullscreen'">
                    <svg v-if="!isFull" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25"/></svg>
                </button>
            </div>

            <!-- ─── Floating bottom-left: Style label + last update ─── -->
            <div class="absolute bottom-3 left-3 z-[500] flex items-center gap-2">
                <div class="maps-glass-chip text-[10px]">
                    {{ mapStyles[mapStyle]?.label || 'Peta' }}
                </div>
                <div class="maps-glass-chip text-[10px]">
                    <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ lastRefresh.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) }}
                </div>
            </div>

            <!-- ─── Floating sidebar panel ─── -->
            <transition name="panel-slide">
                <div v-if="showPanel" class="absolute top-16 left-3 bottom-12 z-[550] w-80 max-w-[calc(100vw-1.5rem)] flex flex-col maps-panel">
                    <!-- Panel header -->
                    <div class="px-4 pt-4 pb-3 flex-shrink-0">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-bold text-slate-800 dark:text-white">Teknisi</h3>
                            <button @click="showPanel = false" class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-slate-200/60 dark:hover:bg-white/10 transition text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <!-- Search -->
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                            <input v-model="searchQuery" type="text" placeholder="Cari teknisi..."
                                class="w-full h-9 pl-9 pr-3 rounded-lg border border-slate-200/80 dark:border-white/10 bg-white/60 dark:bg-slate-800/60 text-xs font-medium text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-500/40 placeholder:text-slate-400" />
                        </div>
                    </div>

                    <!-- Tech list (scrollable) -->
                    <div class="flex-1 overflow-y-auto px-4 pb-4 space-y-1.5 custom-scrollbar">
                        <div v-if="filteredTechnicians.length === 0" class="text-xs text-slate-400 italic py-6 text-center">
                            {{ technicians.length === 0 ? 'Memuat data teknisi...' : 'Tidak ditemukan.' }}
                        </div>

                        <div v-for="tech in filteredTechnicians" :key="tech.technician_id"
                            :class="['rounded-xl transition-all duration-200 cursor-pointer',
                                String(selectedId) === String(tech.technician_id)
                                    ? 'bg-blue-500/10 dark:bg-blue-400/10 ring-1 ring-blue-500/30'
                                    : 'hover:bg-slate-100/60 dark:hover:bg-white/5']">
                            <!-- Tech row -->
                            <button type="button" @click="selectTechnician(tech.technician_id)" class="w-full text-left px-3 py-2.5 flex items-center gap-3">
                                <!-- Status dot -->
                                <div class="relative flex-shrink-0">
                                    <div :class="['w-9 h-9 rounded-full flex items-center justify-center text-white text-xs font-bold',
                                        statusDotBg(tech.recorded_at)]">
                                        {{ (tech.technician_name || '-').charAt(0).toUpperCase() }}
                                    </div>
                                    <span v-if="isOnlineRecently(tech.recorded_at)" class="absolute -bottom-0.5 -right-0.5 block h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-slate-800"></span>
                                </div>
                                <!-- Info -->
                                <div class="flex-1 min-w-0">
                                    <div class="text-xs font-semibold text-slate-800 dark:text-slate-100 truncate">{{ tech.technician_name || '-' }}</div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="text-[10px] text-slate-400 uppercase">{{ tech.technician_role || '-' }}</span>
                                        <span class="text-slate-300 dark:text-slate-600">·</span>
                                        <span :class="['text-[10px] font-medium', statusColor(tech.recorded_at)]">{{ formatRelative(tech.recorded_at) }}</span>
                                    </div>
                                </div>
                                <!-- Chevron -->
                                <svg :class="['w-4 h-4 text-slate-300 dark:text-slate-600 transition-transform flex-shrink-0',
                                    String(selectedId) === String(tech.technician_id) ? 'rotate-90' : '']"
                                    fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                            </button>

                            <!-- ─── Expanded detail ─── -->
                            <div v-if="String(selectedId) === String(tech.technician_id)" class="px-3 pb-3">
                                <!-- Location grid -->
                                <div class="bg-slate-50/80 dark:bg-slate-800/40 rounded-lg p-2.5 space-y-1.5">
                                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-[11px]">
                                        <div class="flex justify-between"><span class="text-slate-400">Lat</span><span class="font-mono text-slate-600 dark:text-slate-300">{{ tech.latitude != null ? Number(tech.latitude).toFixed(6) : '-' }}</span></div>
                                        <div class="flex justify-between"><span class="text-slate-400">Lng</span><span class="font-mono text-slate-600 dark:text-slate-300">{{ tech.longitude != null ? Number(tech.longitude).toFixed(6) : '-' }}</span></div>
                                        <div class="flex justify-between"><span class="text-slate-400">Akurasi</span><span class="text-slate-600 dark:text-slate-300">{{ tech.accuracy != null ? Math.round(tech.accuracy) + ' m' : '-' }}</span></div>
                                        <div class="flex justify-between"><span class="text-slate-400">Speed</span><span class="text-slate-600 dark:text-slate-300">{{ tech.speed != null ? Number(tech.speed).toFixed(1) + ' m/s' : '-' }}</span></div>
                                        <div class="flex justify-between"><span class="text-slate-400">Heading</span><span class="text-slate-600 dark:text-slate-300">{{ tech.heading != null ? Math.round(tech.heading) + '°' : '-' }}</span></div>
                                        <div class="flex justify-between"><span class="text-slate-400">Update</span><span :class="['font-medium', statusColor(tech.recorded_at)]">{{ formatRelative(tech.recorded_at) }}</span></div>
                                    </div>
                                    <!-- Activity -->
                                    <div v-if="tech.last_event" class="pt-1.5 border-t border-slate-200/60 dark:border-white/5 flex items-center justify-between text-[11px]">
                                        <span class="text-slate-400">Aktivitas</span>
                                        <div class="text-right">
                                            <div class="text-slate-600 dark:text-slate-300">{{ tech.last_event }}</div>
                                            <div v-if="tech.last_event_at" class="text-[10px] text-slate-400">{{ formatRelative(tech.last_event_at) }}</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Track history -->
                                <div class="mt-2.5">
                                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Jejak Pergerakan</div>
                                    <div class="flex gap-1.5">
                                        <input type="date" :value="getHistoryMeta(tech.technician_id).from"
                                            @change="getHistoryMeta(tech.technician_id).from = $event.target.value"
                                            class="flex-1 h-8 px-2 rounded-lg border border-slate-200/80 dark:border-white/10 bg-white/60 dark:bg-slate-800/60 text-[11px] font-medium text-slate-700 dark:text-slate-200" />
                                        <input type="date" :value="getHistoryMeta(tech.technician_id).to"
                                            @change="getHistoryMeta(tech.technician_id).to = $event.target.value"
                                            class="flex-1 h-8 px-2 rounded-lg border border-slate-200/80 dark:border-white/10 bg-white/60 dark:bg-slate-800/60 text-[11px] font-medium text-slate-700 dark:text-slate-200" />
                                        <button @click="loadHistory(tech.technician_id)"
                                            class="h-8 px-3 rounded-lg bg-blue-600 text-white text-[11px] font-bold hover:bg-blue-700 transition flex-shrink-0">
                                            Lihat
                                        </button>
                                    </div>
                                    <div v-if="getHistoryMeta(tech.technician_id).statusText"
                                        :class="['text-[10px] mt-1',
                                            getHistoryMeta(tech.technician_id).statusTone === 'error' ? 'text-red-500' :
                                            getHistoryMeta(tech.technician_id).statusTone === 'success' ? 'text-emerald-500' : 'text-slate-400']">
                                        {{ getHistoryMeta(tech.technician_id).statusText }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </div>
    </AdminLayout>
</template>

<style>
/* ── Glass buttons & chips (floating controls) ── */
.maps-glass-btn {
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(148, 163, 184, 0.35);
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08), 0 1px 3px rgba(15, 23, 42, 0.04);
    color: #334155;
    transition: all 0.15s ease;
    cursor: pointer;
}
.maps-glass-btn:hover {
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.12);
    border-color: rgba(59, 130, 246, 0.4);
}
.dark .maps-glass-btn {
    background: rgba(15, 23, 42, 0.75);
    border-color: rgba(148, 163, 184, 0.18);
    color: #e2e8f0;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}
.dark .maps-glass-btn:hover {
    background: rgba(15, 23, 42, 0.9);
    border-color: rgba(59, 130, 246, 0.35);
}

.maps-glass-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 999px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
    font-size: 11px;
    font-weight: 600;
    color: #334155;
    white-space: nowrap;
}
.dark .maps-glass-chip {
    background: rgba(15, 23, 42, 0.75);
    border-color: rgba(148, 163, 184, 0.15);
    color: #e2e8f0;
}

/* ── Floating sidebar panel ── */
.maps-panel {
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(15, 23, 42, 0.1), 0 2px 8px rgba(15, 23, 42, 0.04);
}
.dark .maps-panel {
    background: rgba(15, 23, 42, 0.88);
    border-color: rgba(148, 163, 184, 0.15);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
}

/* Panel slide transition */
.panel-slide-enter-active { transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
.panel-slide-leave-active { transition: all 0.2s cubic-bezier(0.7, 0, 0.84, 0); }
.panel-slide-enter-from,
.panel-slide-leave-to {
    opacity: 0;
    transform: translateX(-20px);
}

/* Custom scrollbar inside panel */
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.25); border-radius: 999px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.4); }
.dark .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.15); }

/* ── Leaflet controls ── */
.leaflet-control-zoom a {
    border-radius: 10px !important;
    border: 1px solid rgba(148,163,184,0.5) !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06) !important;
}
.leaflet-bar a, .leaflet-bar a:hover {
    color: #0f172a !important;
    background: rgba(255,255,255,0.92) !important;
}
.dark .leaflet-bar a, .dark .leaflet-bar a:hover {
    color: #e2e8f0 !important;
    background: rgba(15,23,42,0.85) !important;
    border-color: rgba(148,163,184,0.2) !important;
}
.leaflet-control-attribution {
    font-size: 9px !important;
    background: rgba(255,255,255,0.7) !important;
    border-radius: 8px !important;
    padding: 2px 6px !important;
}
.dark .leaflet-control-attribution {
    background: rgba(15,23,42,0.7) !important;
    color: #94a3b8 !important;
}

/* ── Tech markers ── */
.tech-marker-wrapper { background: transparent; border: none; }
.tech-marker {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    pointer-events: auto;
    transform: translate(-50%, -100%);
}
.tech-marker-icon {
    width: 32px;
    height: 32px;
    border-radius: 999px;
    background: #2563eb;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    border: 2.5px solid #ffffff;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
    transition: all 0.2s ease;
}
.tech-marker.is-selected .tech-marker-icon {
    background: #f59e0b;
    box-shadow: 0 4px 14px rgba(245, 158, 11, 0.4);
    transform: scale(1.15);
}
.tech-marker-pointer {
    width: 0; height: 0;
    border-left: 6px solid transparent;
    border-right: 6px solid transparent;
    border-top: 8px solid #2563eb;
    margin-top: -2px;
}
.tech-marker.is-selected .tech-marker-pointer { border-top-color: #f59e0b; }
.tech-marker-label {
    font-size: 10px;
    font-weight: 700;
    color: #0f172a;
    background: rgba(255,255,255,0.95);
    border: 1px solid rgba(148,163,184,0.4);
    border-radius: 999px;
    padding: 2px 8px;
    max-width: 140px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    box-shadow: 0 3px 10px rgba(15,23,42,0.08);
}
.dark .tech-marker-label {
    color: #e2e8f0;
    background: rgba(15,23,42,0.85);
    border-color: rgba(148,163,184,0.3);
}

/* ── Installation markers ── */
.custom-inst-marker { background: transparent; border: none; }

/* ── Leaflet container ── */
.leaflet-container {
    font-family: 'Inter', 'Plus Jakarta Sans', sans-serif;
}
</style>

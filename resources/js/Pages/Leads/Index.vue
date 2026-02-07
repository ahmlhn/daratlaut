<script setup>
import { ref, onMounted, computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

// State
const leads = ref([]);
const stats = ref({ total: 0, new: 0, contacted: 0, interested: 0, converted: 0, lost: 0 });
const loading = ref(false);
const pagination = ref({ total: 0, per_page: 20, current_page: 1, last_page: 1 });
const filters = ref({
    search: '',
    status: '',
});
const statuses = ref({});
const LEADS_PAGE_CACHE_KEY = 'noci_leads_page_cache_v1';
const LEADS_PAGE_CACHE_TTL_MS = 10 * 60 * 1000;

// Modal
const showModal = ref(false);
const editingLead = ref(null);
const form = ref({
    customer_name: '',
    customer_phone: '',
    customer_address: '',
    notes: '',
    status: 'NEW',
    source: '',
});

const API_BASE = '/api/v1/leads';

function persistLeadsCache() {
    try {
        const payload = {
            cachedAt: Date.now(),
            leads: Array.isArray(leads.value) ? leads.value : [],
            stats: stats.value || {},
            pagination: pagination.value || {},
            filters: filters.value || {},
            statuses: statuses.value || {},
        };
        sessionStorage.setItem(LEADS_PAGE_CACHE_KEY, JSON.stringify(payload));
    } catch (e) {
        // Ignore session quota errors.
    }
}

function restoreLeadsCache() {
    try {
        const raw = sessionStorage.getItem(LEADS_PAGE_CACHE_KEY);
        if (!raw) return false;
        const parsed = JSON.parse(raw);
        const cachedAt = Number(parsed?.cachedAt || 0);
        if (!cachedAt || (Date.now() - cachedAt) > LEADS_PAGE_CACHE_TTL_MS) {
            sessionStorage.removeItem(LEADS_PAGE_CACHE_KEY);
            return false;
        }

        if (Array.isArray(parsed?.leads)) leads.value = parsed.leads;
        if (parsed?.stats && typeof parsed.stats === 'object') stats.value = parsed.stats;
        if (parsed?.pagination && typeof parsed.pagination === 'object') {
            pagination.value = {
                total: Number(parsed.pagination.total || 0),
                per_page: Number(parsed.pagination.per_page || 20),
                current_page: Number(parsed.pagination.current_page || 1),
                last_page: Number(parsed.pagination.last_page || 1),
            };
        }
        if (parsed?.filters && typeof parsed.filters === 'object') {
            filters.value = {
                search: String(parsed.filters.search || ''),
                status: String(parsed.filters.status || ''),
            };
        }
        if (parsed?.statuses && typeof parsed.statuses === 'object') statuses.value = parsed.statuses;
        return true;
    } catch (e) {
        return false;
    }
}

// Load leads
async function loadLeads(options = {}) {
    const silent = !!(options && typeof options === 'object' && options.silent === true);
    if (!silent) loading.value = true;
    try {
        const params = new URLSearchParams();
        if (filters.value.search) params.append('search', filters.value.search);
        if (filters.value.status) params.append('status', filters.value.status);
        params.append('page', pagination.value.current_page);
        
        const res = await fetch(`${API_BASE}?${params}`);
        const data = await res.json();
        if (data.status === 'ok') {
            leads.value = data.data;
            pagination.value = data.pagination;
            persistLeadsCache();
        }
    } catch (e) {
        console.error('Failed to load leads:', e);
    }
    if (!silent) loading.value = false;
}

// Load stats
async function loadStats() {
    try {
        const res = await fetch(`${API_BASE}/stats`);
        const data = await res.json();
        if (data.status === 'ok') {
            stats.value = data.data;
            persistLeadsCache();
        }
    } catch (e) {
        console.error('Failed to load stats:', e);
    }
}

// Load statuses
async function loadStatuses() {
    try {
        const res = await fetch(`${API_BASE}/statuses`);
        const data = await res.json();
        if (data.status === 'ok') {
            statuses.value = data.data;
            persistLeadsCache();
        }
    } catch (e) {
        console.error('Failed to load statuses:', e);
    }
}

// Open modal
function openModal(lead = null) {
    editingLead.value = lead;
    if (lead) {
        form.value = {
            customer_name: lead.customer_name,
            customer_phone: lead.customer_phone,
            customer_address: lead.customer_address || '',
            notes: lead.notes || '',
            status: lead.status,
            source: lead.source || '',
        };
    } else {
        form.value = {
            customer_name: '',
            customer_phone: '',
            customer_address: '',
            notes: '',
            status: 'NEW',
            source: 'manual',
        };
    }
    showModal.value = true;
}

// Save lead
async function saveLead() {
    try {
        const url = editingLead.value ? `${API_BASE}/${editingLead.value.id}` : API_BASE;
        const method = editingLead.value ? 'PUT' : 'POST';
        
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(form.value),
        });
        const data = await res.json();
        
        if (data.status === 'ok') {
            showModal.value = false;
            await loadLeads();
            await loadStats();
        } else {
            alert(data.message || 'Gagal menyimpan');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Delete lead
async function deleteLead(lead) {
    if (!confirm(`Hapus lead "${lead.customer_name}"?`)) return;
    
    try {
        const res = await fetch(`${API_BASE}/${lead.id}`, { method: 'DELETE' });
        const data = await res.json();
        
        if (data.status === 'ok') {
            await loadLeads();
            await loadStats();
        } else {
            alert(data.message || 'Gagal menghapus');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Convert to installation
async function convertLead(lead) {
    if (!confirm(`Konversi "${lead.customer_name}" ke pasang baru?`)) return;
    
    try {
        const res = await fetch(`${API_BASE}/${lead.id}/convert`, { method: 'POST' });
        const data = await res.json();
        
        if (data.status === 'ok') {
            alert('Lead berhasil dikonversi ke pasang baru');
            await loadLeads();
            await loadStats();
        } else {
            alert(data.message || 'Gagal mengkonversi');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Status badge class
function statusClass(status) {
    return {
        'NEW': 'bg-blue-100 text-blue-800',
        'CONTACTED': 'bg-yellow-100 text-yellow-800',
        'INTERESTED': 'bg-purple-100 text-purple-800',
        'CONVERTED': 'bg-green-100 text-green-800',
        'LOST': 'bg-gray-100 text-gray-800',
    }[status] || 'bg-gray-100 text-gray-800';
}

// Status label
function statusLabel(status) {
    return statuses.value[status] || status;
}

// Format date
function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

// Init
onMounted(() => {
    const restored = restoreLeadsCache();
    loadStatuses();
    loadStats();
    loadLeads({ silent: restored });
});
</script>

<template>
    <AdminLayout>
        <div class="p-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Leads</h1>
                    <p class="text-gray-600">Kelola prospek calon pelanggan</p>
                </div>
                <button @click="openModal()" class="btn btn-primary">+ Tambah Lead</button>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
                <div class="card p-4 text-center">
                    <div class="text-2xl font-bold">{{ stats.total }}</div>
                    <div class="text-sm text-gray-500">Total</div>
                </div>
                <div class="card p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ stats.new }}</div>
                    <div class="text-sm text-gray-500">Baru</div>
                </div>
                <div class="card p-4 text-center">
                    <div class="text-2xl font-bold text-yellow-600">{{ stats.contacted }}</div>
                    <div class="text-sm text-gray-500">Dihubungi</div>
                </div>
                <div class="card p-4 text-center">
                    <div class="text-2xl font-bold text-purple-600">{{ stats.interested }}</div>
                    <div class="text-sm text-gray-500">Tertarik</div>
                </div>
                <div class="card p-4 text-center">
                    <div class="text-2xl font-bold text-green-600">{{ stats.converted }}</div>
                    <div class="text-sm text-gray-500">Jadi</div>
                </div>
                <div class="card p-4 text-center">
                    <div class="text-2xl font-bold text-gray-600">{{ stats.lost }}</div>
                    <div class="text-sm text-gray-500">Tidak Jadi</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 mb-4">
                <input 
                    v-model="filters.search" 
                    type="text" 
                    placeholder="Cari nama/HP..." 
                    class="input flex-1"
                    @keyup.enter="loadLeads"
                />
                <select v-model="filters.status" class="input" @change="loadLeads">
                    <option value="">Semua Status</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
                <button @click="loadLeads" class="btn bg-gray-100">Cari</button>
            </div>

            <!-- Leads Table -->
            <div class="card overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left">Nama</th>
                            <th class="px-4 py-3 text-left">No. HP</th>
                            <th class="px-4 py-3 text-left">Alamat</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Terakhir Aktif</th>
                            <th class="px-4 py-3 text-left">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="6" class="text-center py-8 text-gray-500">Loading...</td>
                        </tr>
                        <tr v-else-if="leads.length === 0">
                            <td colspan="6" class="text-center py-8 text-gray-500">Tidak ada data</td>
                        </tr>
                        <tr v-for="lead in leads" :key="lead.id" class="border-b hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium">{{ lead.customer_name }}</td>
                            <td class="px-4 py-3">
                                <a :href="'https://wa.me/' + lead.customer_phone" target="_blank" class="text-green-600 hover:underline">
                                    {{ lead.customer_phone }}
                                </a>
                            </td>
                            <td class="px-4 py-3 max-w-xs truncate">{{ lead.customer_address || '-' }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 rounded text-xs font-medium" :class="statusClass(lead.status)">
                                    {{ statusLabel(lead.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ formatDate(lead.last_seen) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex gap-1">
                                    <button @click="openModal(lead)" class="text-xs px-2 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200">
                                        Edit
                                    </button>
                                    <button 
                                        v-if="lead.status !== 'CONVERTED'"
                                        @click="convertLead(lead)" 
                                        class="text-xs px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200"
                                    >
                                        Konversi
                                    </button>
                                    <button @click="deleteLead(lead)" class="text-xs px-2 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200">
                                        Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="pagination.last_page > 1" class="flex justify-center gap-2 mt-4">
                <button 
                    v-for="p in pagination.last_page" 
                    :key="p"
                    @click="pagination.current_page = p; loadLeads()"
                    class="px-3 py-1 rounded"
                    :class="p === pagination.current_page ? 'bg-primary-500 text-white' : 'bg-gray-100 hover:bg-gray-200'"
                >
                    {{ p }}
                </button>
            </div>
        </div>

        <!-- Lead Modal -->
        <div v-if="showModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
                <div class="p-4 border-b">
                    <h3 class="text-lg font-semibold">{{ editingLead ? 'Edit Lead' : 'Tambah Lead' }}</h3>
                </div>
                <div class="p-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Nama <span class="text-red-500">*</span></label>
                        <input v-model="form.customer_name" type="text" class="input w-full" required />
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">No. HP <span class="text-red-500">*</span></label>
                        <input v-model="form.customer_phone" type="text" class="input w-full" placeholder="08xx..." required />
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Alamat</label>
                        <textarea v-model="form.customer_address" class="input w-full" rows="2"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Status</label>
                            <select v-model="form.status" class="input w-full">
                                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Sumber</label>
                            <input v-model="form.source" type="text" class="input w-full" placeholder="WA, Website, dll" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Catatan</label>
                        <textarea v-model="form.notes" class="input w-full" rows="3"></textarea>
                    </div>
                </div>
                <div class="p-4 border-t flex justify-end gap-3">
                    <button @click="showModal = false" class="btn bg-gray-100">Batal</button>
                    <button @click="saveLead" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

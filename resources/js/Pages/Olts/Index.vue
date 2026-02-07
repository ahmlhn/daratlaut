<script setup>
import { ref, onMounted, computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

// State
const olts = ref([]);
const stats = ref({
    total_olts: 0,
    active_olts: 0,
    total_onus: 0,
    online_onus: 0,
});
const selectedOlt = ref(null);
const fspList = ref([]);
const selectedFsp = ref('');
const onuList = ref([]);
const unconfiguredOnus = ref([]);
const searchQuery = ref('');
const loading = ref(false);
const loadingOnu = ref(false);
const showOltModal = ref(false);
const showOnuModal = ref(false);
const showRegisterModal = ref(false);
const editingOlt = ref(null);
const editingOnu = ref(null);
const registerOnu = ref({
    fsp: '',
    sn: '',
    name: '',
});

// Form data
const formData = ref({
    nama_olt: '',
    host: '',
    port: 23,
    username: '',
    password: '',
    tcont_default: 'pppoe',
    vlan_default: 200,
});

// API base
const API_BASE = '/api/v1';

async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
        headers: {
            'Accept': 'application/json',
            ...(options.headers || {}),
        },
        ...options,
    });

    const contentType = (res.headers.get('content-type') || '').toLowerCase();
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
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : `HTTP ${res.status}`;
        throw new Error(msg);
    }

    if (data === null) {
        throw new Error('Non-JSON response');
    }

    return data;
}

// Load OLTs
async function loadOlts() {
    loading.value = true;
    try {
        const oltsData = await fetchJson(`${API_BASE}/olts`);
        if (oltsData.status === 'ok') olts.value = oltsData.data;
    } catch (e) {
        console.error('Failed to load OLT list:', e);
    }

    try {
        const statsData = await fetchJson(`${API_BASE}/olts/stats`);
        if (statsData.status === 'ok') stats.value = statsData.data;
    } catch (e) {
        console.error('Failed to load OLT stats:', e);
    }
    loading.value = false;
}

// Select OLT
async function selectOlt(olt) {
    selectedOlt.value = olt;
    selectedFsp.value = '';
    onuList.value = [];
    await loadFspList(olt.id);
}

// Load FSP list
async function loadFspList(oltId) {
    try {
        const res = await fetch(`${API_BASE}/olts/${oltId}/fsp`);
        const data = await res.json();
        if (data.status === 'ok') {
            fspList.value = data.data;
            if (fspList.value.length > 0) {
                selectedFsp.value = fspList.value[0];
                await loadOnuList();
            }
        }
    } catch (e) {
        console.error('Failed to load FSP:', e);
    }
}

// Load ONU list for selected FSP
async function loadOnuList() {
    if (!selectedOlt.value || !selectedFsp.value) return;
    
    loadingOnu.value = true;
    try {
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/registered?fsp=${selectedFsp.value}`);
        const data = await res.json();
        if (data.status === 'ok') {
            onuList.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load ONUs:', e);
    }
    loadingOnu.value = false;
}

// Load from cache
async function loadFromCache() {
    if (!selectedOlt.value) return;
    
    loadingOnu.value = true;
    try {
        const params = new URLSearchParams();
        if (selectedFsp.value) params.append('fsp', selectedFsp.value);
        if (searchQuery.value) params.append('search', searchQuery.value);
        
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/cache?${params}`);
        const data = await res.json();
        if (data.status === 'ok') {
            onuList.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load cache:', e);
    }
    loadingOnu.value = false;
}

// Scan unconfigured
async function scanUnconfigured() {
    if (!selectedOlt.value) return;
    
    loading.value = true;
    try {
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/scan-uncfg`);
        const data = await res.json();
        if (data.status === 'ok') {
            unconfiguredOnus.value = data.data;
            if (data.data.length === 0) {
                alert('Tidak ada ONU baru yang terdeteksi');
            }
        } else {
            alert(data.message || 'Gagal scan');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
    loading.value = false;
}

// Test connection
async function testConnection(olt) {
    try {
        const res = await fetch(`${API_BASE}/olts/${olt.id}/test-connection`, { method: 'POST' });
        const data = await res.json();
        alert(data.message || (data.status === 'ok' ? 'Koneksi berhasil!' : 'Koneksi gagal'));
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Sync all ONUs
async function syncAll() {
    if (!selectedOlt.value) return;
    if (!confirm('Sync semua ONU dari OLT ke database?')) return;
    
    loading.value = true;
    try {
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/sync-all`, { method: 'POST' });
        const data = await res.json();
        alert(data.message || 'Sync selesai');
        await loadFromCache();
    } catch (e) {
        alert('Error: ' + e.message);
    }
    loading.value = false;
}

// Open OLT form modal
function openOltModal(olt = null) {
    editingOlt.value = olt;
    if (olt) {
        formData.value = {
            nama_olt: olt.nama_olt,
            host: olt.host,
            port: olt.port,
            username: olt.username || '',
            password: '',
            tcont_default: olt.tcont_default || 'pppoe',
            vlan_default: olt.vlan_default || 200,
        };
    } else {
        formData.value = {
            nama_olt: '',
            host: '',
            port: 23,
            username: '',
            password: '',
            tcont_default: 'pppoe',
            vlan_default: 200,
        };
    }
    showOltModal.value = true;
}

// Save OLT
async function saveOlt() {
    const url = editingOlt.value 
        ? `${API_BASE}/olts/${editingOlt.value.id}` 
        : `${API_BASE}/olts`;
    const method = editingOlt.value ? 'PUT' : 'POST';
    
    try {
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData.value),
        });
        const data = await res.json();
        
        if (data.status === 'ok') {
            showOltModal.value = false;
            await loadOlts();
        } else {
            alert(data.message || 'Gagal menyimpan');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Delete OLT
async function deleteOlt(olt) {
    if (!confirm(`Hapus OLT "${olt.nama_olt}"? Semua data ONU akan ikut terhapus.`)) return;
    
    try {
        const res = await fetch(`${API_BASE}/olts/${olt.id}`, { method: 'DELETE' });
        const data = await res.json();
        
        if (data.status === 'ok') {
            await loadOlts();
            if (selectedOlt.value?.id === olt.id) {
                selectedOlt.value = null;
            }
        } else {
            alert(data.message || 'Gagal menghapus');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Open register modal
function openRegisterModal(uncfgOnu = null) {
    if (uncfgOnu) {
        registerOnu.value = {
            fsp: uncfgOnu.fsp,
            sn: uncfgOnu.sn,
            name: '',
        };
    } else {
        registerOnu.value = {
            fsp: selectedFsp.value || '',
            sn: '',
            name: '',
        };
    }
    showRegisterModal.value = true;
}

// Register ONU
async function doRegisterOnu() {
    if (!selectedOlt.value) return;
    if (!registerOnu.value.sn || !registerOnu.value.name) {
        alert('SN dan Nama wajib diisi');
        return;
    }
    
    loading.value = true;
    try {
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/register-onu`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(registerOnu.value),
        });
        const data = await res.json();
        
        if (data.status === 'ok') {
            showRegisterModal.value = false;
            alert('ONU berhasil diregistrasi');
            // Remove from unconfigured list
            unconfiguredOnus.value = unconfiguredOnus.value.filter(
                o => o.sn !== registerOnu.value.sn
            );
            await loadOnuList();
        } else {
            alert(data.message || 'Gagal registrasi');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
    loading.value = false;
}

// View ONU detail
async function viewOnuDetail(onu) {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            fsp: onu.fsp,
            onu_id: onu.onu_id,
        });
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/onu-detail?${params}`);
        const data = await res.json();
        
        if (data.status === 'ok') {
            editingOnu.value = { ...onu, ...data.data };
            showOnuModal.value = true;
        } else {
            alert(data.message || 'Gagal load detail');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
    loading.value = false;
}

// Update ONU name
async function updateOnuName() {
    if (!editingOnu.value) return;
    
    loading.value = true;
    try {
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/update-onu-name`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                fsp: editingOnu.value.fsp,
                onu_id: editingOnu.value.onu_id,
                name: editingOnu.value.name,
            }),
        });
        const data = await res.json();
        
        if (data.status === 'ok') {
            showOnuModal.value = false;
            await loadOnuList();
        } else {
            alert(data.message || 'Gagal update');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
    loading.value = false;
}

// Restart ONU
async function restartOnu(onu) {
    if (!confirm(`Restart ONU ${onu.fsp}:${onu.onu_id}?`)) return;
    
    try {
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/restart-onu`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                fsp: onu.fsp,
                onu_id: onu.onu_id,
            }),
        });
        const data = await res.json();
        alert(data.message || 'ONU sedang di-restart');
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Delete ONU
async function deleteOnu(onu) {
    if (!confirm(`Hapus ONU ${onu.fsp}:${onu.onu_id}?`)) return;
    
    loading.value = true;
    try {
        const res = await fetch(`${API_BASE}/olts/${selectedOlt.value.id}/delete-onu`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                fsp: onu.fsp,
                onu_id: onu.onu_id,
            }),
        });
        const data = await res.json();
        
        if (data.status === 'ok') {
            await loadOnuList();
        } else {
            alert(data.message || 'Gagal hapus');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
    loading.value = false;
}

// Status badge color
function statusColor(status) {
    return status === 'online' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
}

// Init
onMounted(() => {
    loadOlts();
});
</script>

<template>
    <AdminLayout>
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">OLT Management</h1>
                    <p class="text-gray-600">Kelola perangkat OLT dan ONU</p>
                </div>
                <button @click="openOltModal()" class="btn btn-primary">
                    + Tambah OLT
                </button>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="card p-4">
                    <div class="text-sm text-gray-500">Total OLT</div>
                    <div class="text-2xl font-bold text-gray-800">{{ stats.total_olts }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-sm text-gray-500">OLT Aktif</div>
                    <div class="text-2xl font-bold text-green-600">{{ stats.active_olts }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-sm text-gray-500">Total ONU</div>
                    <div class="text-2xl font-bold text-gray-800">{{ stats.total_onus }}</div>
                </div>
                <div class="card p-4">
                    <div class="text-sm text-gray-500">ONU Online</div>
                    <div class="text-2xl font-bold text-green-600">{{ stats.online_onus }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- OLT List -->
                <div class="card p-4">
                    <h2 class="text-lg font-semibold mb-4">Daftar OLT</h2>
                    <div v-if="loading && !olts.length" class="text-center py-8 text-gray-500">
                        Loading...
                    </div>
                    <div v-else-if="olts.length === 0" class="text-center py-8 text-gray-500">
                        Belum ada OLT
                    </div>
                    <div v-else class="space-y-2">
                        <div 
                            v-for="olt in olts" 
                            :key="olt.id"
                            @click="selectOlt(olt)"
                            class="p-3 border rounded-lg cursor-pointer hover:bg-gray-50 transition"
                            :class="{ 'border-primary-500 bg-primary-50': selectedOlt?.id === olt.id }"
                        >
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-medium">{{ olt.nama_olt }}</div>
                                    <div class="text-sm text-gray-500">{{ olt.host }}:{{ olt.port }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-medium">{{ olt.onu_count }} ONU</div>
                                    <div class="text-xs text-gray-400">{{ olt.fsp_count }} FSP</div>
                                </div>
                            </div>
                            <div class="flex gap-2 mt-2">
                                <button 
                                    @click.stop="testConnection(olt)" 
                                    class="text-xs px-2 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200"
                                >
                                    Test
                                </button>
                                <button 
                                    @click.stop="openOltModal(olt)" 
                                    class="text-xs px-2 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200"
                                >
                                    Edit
                                </button>
                                <button 
                                    @click.stop="deleteOlt(olt)" 
                                    class="text-xs px-2 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200"
                                >
                                    Hapus
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ONU Panel -->
                <div class="lg:col-span-2 card p-4">
                    <div v-if="!selectedOlt" class="text-center py-12 text-gray-500">
                        Pilih OLT untuk melihat daftar ONU
                    </div>
                    <div v-else>
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold">
                                ONU - {{ selectedOlt.nama_olt }}
                            </h2>
                            <div class="flex gap-2">
                                <button @click="scanUnconfigured" class="btn btn-sm bg-yellow-100 text-yellow-700">
                                    Scan ONU Baru
                                </button>
                                <button @click="syncAll" class="btn btn-sm bg-blue-100 text-blue-700">
                                    Sync All
                                </button>
                            </div>
                        </div>

                        <!-- Unconfigured ONUs -->
                        <div v-if="unconfiguredOnus.length > 0" class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <div class="text-sm font-medium text-yellow-800 mb-2">
                                ONU Belum Terdaftar ({{ unconfiguredOnus.length }})
                            </div>
                            <div class="space-y-2">
                                <div 
                                    v-for="onu in unconfiguredOnus" 
                                    :key="onu.sn"
                                    class="flex items-center justify-between p-2 bg-white rounded border"
                                >
                                    <div>
                                        <span class="font-mono text-sm">{{ onu.sn }}</span>
                                        <span class="text-xs text-gray-500 ml-2">{{ onu.fsp }}</span>
                                    </div>
                                    <button 
                                        @click="openRegisterModal(onu)"
                                        class="text-xs px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200"
                                    >
                                        Register
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- FSP Filter & Search -->
                        <div class="flex flex-wrap gap-3 mb-4">
                            <select 
                                v-model="selectedFsp" 
                                @change="loadOnuList"
                                class="input w-32"
                            >
                                <option value="">Semua FSP</option>
                                <option v-for="fsp in fspList" :key="fsp" :value="fsp">{{ fsp }}</option>
                            </select>
                            <input 
                                v-model="searchQuery" 
                                @keyup.enter="loadFromCache"
                                type="text" 
                                placeholder="Cari nama/SN..." 
                                class="input flex-1"
                            />
                            <button @click="loadFromCache" class="btn btn-sm bg-gray-100">
                                Cari
                            </button>
                            <button @click="loadOnuList" class="btn btn-sm bg-primary-100 text-primary-700">
                                Refresh
                            </button>
                        </div>

                        <!-- ONU Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="bg-gray-50">
                                        <th class="px-3 py-2 text-left">#</th>
                                        <th class="px-3 py-2 text-left">FSP:ID</th>
                                        <th class="px-3 py-2 text-left">SN</th>
                                        <th class="px-3 py-2 text-left">Nama</th>
                                        <th class="px-3 py-2 text-left">Status</th>
                                        <th class="px-3 py-2 text-left">RX (dBm)</th>
                                        <th class="px-3 py-2 text-left">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="loadingOnu">
                                        <td colspan="7" class="text-center py-8 text-gray-500">Loading...</td>
                                    </tr>
                                    <tr v-else-if="onuList.length === 0">
                                        <td colspan="7" class="text-center py-8 text-gray-500">Tidak ada data</td>
                                    </tr>
                                    <tr 
                                        v-for="(onu, idx) in onuList" 
                                        :key="`${onu.fsp}:${onu.onu_id}`"
                                        class="border-b hover:bg-gray-50"
                                    >
                                        <td class="px-3 py-2">{{ idx + 1 }}</td>
                                        <td class="px-3 py-2 font-mono">{{ onu.fsp }}:{{ onu.onu_id }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ onu.sn }}</td>
                                        <td class="px-3 py-2">{{ onu.name || '-' }}</td>
                                        <td class="px-3 py-2">
                                            <span 
                                                class="px-2 py-0.5 rounded text-xs font-medium"
                                                :class="statusColor(onu.status)"
                                            >
                                                {{ onu.status }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2">{{ onu.rx ?? '-' }}</td>
                                        <td class="px-3 py-2">
                                            <div class="flex gap-1">
                                                <button 
                                                    @click="viewOnuDetail(onu)"
                                                    class="text-xs px-2 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200"
                                                >
                                                    Detail
                                                </button>
                                                <button 
                                                    @click="restartOnu(onu)"
                                                    class="text-xs px-2 py-1 bg-yellow-100 text-yellow-700 rounded hover:bg-yellow-200"
                                                >
                                                    Restart
                                                </button>
                                                <button 
                                                    @click="deleteOnu(onu)"
                                                    class="text-xs px-2 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200"
                                                >
                                                    Hapus
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- OLT Form Modal -->
        <div v-if="showOltModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto">
                <div class="p-4 border-b">
                    <h3 class="text-lg font-semibold">
                        {{ editingOlt ? 'Edit OLT' : 'Tambah OLT' }}
                    </h3>
                </div>
                <div class="p-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Nama OLT</label>
                        <input v-model="formData.nama_olt" type="text" class="input w-full" />
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium mb-1">Host/IP</label>
                            <input v-model="formData.host" type="text" class="input w-full" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Port</label>
                            <input v-model="formData.port" type="number" class="input w-full" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Username</label>
                        <input v-model="formData.username" type="text" class="input w-full" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">
                            Password {{ editingOlt ? '(kosongkan jika tidak diubah)' : '' }}
                        </label>
                        <input v-model="formData.password" type="password" class="input w-full" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">T-CONT Profile</label>
                            <input v-model="formData.tcont_default" type="text" class="input w-full" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">VLAN Default</label>
                            <input v-model="formData.vlan_default" type="number" class="input w-full" />
                        </div>
                    </div>
                </div>
                <div class="p-4 border-t flex justify-end gap-3">
                    <button @click="showOltModal = false" class="btn bg-gray-100">Batal</button>
                    <button @click="saveOlt" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </div>

        <!-- ONU Detail Modal -->
        <div v-if="showOnuModal && editingOnu" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <div class="p-4 border-b flex items-center justify-between">
                    <h3 class="text-lg font-semibold">
                        Detail ONU - {{ editingOnu.fsp }}:{{ editingOnu.onu_id }}
                    </h3>
                    <span 
                        class="px-2 py-1 rounded text-xs font-medium"
                        :class="statusColor(editingOnu.status)"
                    >
                        {{ editingOnu.status }}
                    </span>
                </div>
                <div class="p-4 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs text-gray-500">Serial Number</label>
                            <div class="font-mono">{{ editingOnu.sn }}</div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Distance</label>
                            <div>{{ editingOnu.distance || '-' }}</div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">RX Power</label>
                            <div>{{ editingOnu.rx_power || '-' }}</div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">TX Power</label>
                            <div>{{ editingOnu.tx_power || '-' }}</div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Temperature</label>
                            <div>{{ editingOnu.temperature || '-' }}</div>
                        </div>
                        <div>
                            <label class="text-xs text-gray-500">Voltage</label>
                            <div>{{ editingOnu.voltage || '-' }}</div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Nama ONU</label>
                        <input v-model="editingOnu.name" type="text" class="input w-full" />
                    </div>
                </div>
                <div class="p-4 border-t flex justify-end gap-3">
                    <button @click="showOnuModal = false" class="btn bg-gray-100">Tutup</button>
                    <button @click="updateOnuName" class="btn btn-primary">Simpan Nama</button>
                </div>
            </div>
        </div>

        <!-- Register ONU Modal -->
        <div v-if="showRegisterModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
                <div class="p-4 border-b">
                    <h3 class="text-lg font-semibold">Register ONU</h3>
                </div>
                <div class="p-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">FSP</label>
                        <select v-model="registerOnu.fsp" class="input w-full">
                            <option value="">Pilih FSP</option>
                            <option v-for="fsp in fspList" :key="fsp" :value="fsp">{{ fsp }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Serial Number</label>
                        <input v-model="registerOnu.sn" type="text" class="input w-full font-mono" placeholder="ZTEG12345678" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Nama Pelanggan</label>
                        <input v-model="registerOnu.name" type="text" class="input w-full" placeholder="Nama pelanggan" />
                    </div>
                </div>
                <div class="p-4 border-t flex justify-end gap-3">
                    <button @click="showRegisterModal = false" class="btn bg-gray-100">Batal</button>
                    <button @click="doRegisterOnu" class="btn btn-primary" :disabled="loading">
                        {{ loading ? 'Registering...' : 'Register' }}
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

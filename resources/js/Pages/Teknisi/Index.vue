<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    techName: { type: String, default: 'Teknisi' },
    techPop: { type: String, default: '' },
    techRole: { type: String, default: 'teknisi' },
    initialFilters: { type: Object, default: () => ({}) },
});

const tasks = ref([]);
const counts = ref({ all: 0, mine: 0 });
const pops = ref([]);
const technicians = ref([]);
const loading = ref(false);

// Filters
const activeTab = ref(props.initialFilters.tab || 'all');
const filters = ref({
    pop: props.initialFilters.pop || '',
    status: props.initialFilters.status || '',
    q: props.initialFilters.q || '',
});

// Modal state
const showDetailModal = ref(false);
const showNewInstallModal = ref(false);
const currentTask = ref(null);
const taskLogs = ref([]);

// New install form
const newInstall = ref({
    nama: '', wa: '', alamat: '', pop: '', paket: '', harga: '',
    sales_1: '', sales_2: '', sales_3: '',
    teknisi_1: '', teknisi_2: '', teknisi_3: '', teknisi_4: '',
    tanggal: new Date().toISOString().split('T')[0],
    koordinat: '', catatan: '',
});

const statusColors = {
    Baru: 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300',
    Survey: 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-300',
    Proses: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-300',
    Pending: 'bg-orange-100 text-orange-800 dark:bg-orange-900/50 dark:text-orange-300',
    Selesai: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300',
    Batal: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300',
    Req_Batal: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
};

const API_BASE = '/api/v1';

async function loadTasks() {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            tab: activeTab.value,
            tech_name: props.techName,
            ...filters.value,
        });
        const res = await fetch(`${API_BASE}/teknisi/tasks?${params}`);
        const data = await res.json();
        tasks.value = data.data || [];
        counts.value = data.counts || { all: 0, mine: 0 };
    } catch (e) {
        console.error('Failed to load tasks:', e);
    } finally {
        loading.value = false;
    }
}

async function loadDropdowns() {
    try {
        const [popsRes, techRes] = await Promise.all([
            fetch(`${API_BASE}/teknisi/pops`),
            fetch(`${API_BASE}/teknisi/technicians`),
        ]);
        const popsData = await popsRes.json();
        const techData = await techRes.json();
        pops.value = popsData.data || [];
        technicians.value = techData.data || [];
    } catch (e) {
        console.error('Failed to load dropdowns:', e);
    }
}

async function openTaskDetail(task) {
    currentTask.value = task;
    showDetailModal.value = true;
    try {
        const res = await fetch(`${API_BASE}/teknisi/tasks/${task.id}`);
        const data = await res.json();
        taskLogs.value = data.logs || [];
    } catch (e) {
        console.error('Failed to load task detail:', e);
    }
}

function closeDetailModal() {
    showDetailModal.value = false;
    currentTask.value = null;
    taskLogs.value = [];
}

function openNewInstallModal() {
    showNewInstallModal.value = true;
    // Reset form
    newInstall.value = {
        nama: '', wa: '', alamat: '', pop: props.techPop || '', paket: '', harga: '',
        sales_1: '', sales_2: '', sales_3: '',
        teknisi_1: '', teknisi_2: '', teknisi_3: '', teknisi_4: '',
        tanggal: new Date().toISOString().split('T')[0],
        koordinat: '', catatan: '',
    };
}

function closeNewInstallModal() {
    showNewInstallModal.value = false;
}

async function saveNewInstall() {
    try {
        const res = await fetch(`${API_BASE}/teknisi/tasks`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(newInstall.value),
        });
        const data = await res.json();
        if (data.success) {
            alert('Pasang baru berhasil disimpan!');
            closeNewInstallModal();
            loadTasks();
        } else {
            alert(data.message || 'Gagal menyimpan');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function updateTaskStatus(taskId, newStatus, extra = {}) {
    try {
        const res = await fetch(`${API_BASE}/teknisi/tasks/${taskId}/status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: newStatus, ...extra }),
        });
        const data = await res.json();
        if (data.success) {
            closeDetailModal();
            loadTasks();
        } else {
            alert(data.message || 'Gagal update status');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function switchTab(tab) {
    activeTab.value = tab;
    loadTasks();
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount || 0);
}

onMounted(() => {
    loadTasks();
    loadDropdowns();
});

watch([activeTab, filters], () => loadTasks(), { deep: true });
</script>

<template>
    <Head title="Dashboard Teknisi" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Dashboard Teknisi</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Halo, {{ techName }}</p>
                </div>
                <button @click="openNewInstallModal" class="btn btn-primary flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Input Pasang Baru
                </button>
            </div>

            <!-- Tabs -->
            <div class="bg-white dark:bg-gray-800 rounded-xl p-1 flex gap-1 shadow-sm border border-gray-200 dark:border-gray-700">
                <button 
                    @click="switchTab('all')" 
                    :class="['flex-1 py-3 rounded-lg font-semibold text-sm transition-all', activeTab === 'all' ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700']"
                >
                    Semua Tugas
                    <span v-if="counts.all > 0" class="ml-2 px-2 py-0.5 bg-red-500 text-white text-xs rounded-full">{{ counts.all }}</span>
                </button>
                <button 
                    @click="switchTab('mine')" 
                    :class="['flex-1 py-3 rounded-lg font-semibold text-sm transition-all', activeTab === 'mine' ? 'bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700']"
                >
                    Tugas Saya
                    <span v-if="counts.mine > 0" class="ml-2 px-2 py-0.5 bg-blue-500 text-white text-xs rounded-full">{{ counts.mine }}</span>
                </button>
            </div>

            <!-- Filters -->
            <div class="flex flex-col sm:flex-row gap-3">
                <select v-model="filters.pop" class="input flex-1">
                    <option value="">Semua Area</option>
                    <option v-for="pop in pops" :key="pop.id" :value="pop.name">{{ pop.name }}</option>
                </select>
                <select v-model="filters.status" class="input w-full sm:w-40">
                    <option value="">Semua Status</option>
                    <option value="Proses">Proses</option>
                    <option value="Pending">Pending</option>
                    <option value="Req_Batal">Req Batal</option>
                </select>
                <div class="relative flex-1">
                    <input v-model="filters.q" type="text" placeholder="Cari nama / alamat..." class="input w-full pl-10" />
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <button @click="loadTasks" class="btn btn-secondary">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
            </div>

            <!-- Loading -->
            <div v-if="loading" class="flex justify-center py-12">
                <div class="animate-spin rounded-full h-10 w-10 border-4 border-primary-500 border-t-transparent"></div>
            </div>

            <!-- Task List -->
            <div v-else-if="tasks.length > 0" class="space-y-3">
                <div 
                    v-for="task in tasks" 
                    :key="task.id" 
                    @click="openTaskDetail(task)"
                    class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700 cursor-pointer hover:shadow-md transition-shadow"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span :class="['px-2 py-0.5 rounded-full text-xs font-semibold', statusColors[task.status] || 'bg-gray-100 text-gray-800']">
                                    {{ task.status }}
                                </span>
                                <span v-if="task.pop" class="text-xs text-gray-500 dark:text-gray-400">{{ task.pop }}</span>
                            </div>
                            <h3 class="font-semibold text-gray-900 dark:text-white truncate">{{ task.nama }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ task.alamat }}</p>
                            <div class="flex items-center gap-4 mt-2 text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="task.tanggal">📅 {{ formatDate(task.tanggal) }}</span>
                                <span v-if="task.harga" class="text-green-600 dark:text-green-400 font-semibold">{{ formatCurrency(task.harga) }}</span>
                            </div>
                        </div>
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-16">
                <div class="w-20 h-20 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <h3 class="text-gray-600 dark:text-gray-300 font-semibold">Tidak ada tugas</h3>
            </div>
        </div>

        <!-- Detail Modal -->
        <Teleport to="body">
            <div v-if="showDetailModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4" @click.self="closeDetailModal">
                <div class="bg-white dark:bg-gray-800 w-full max-w-lg rounded-2xl shadow-2xl max-h-[90vh] flex flex-col">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Detail Tugas</h3>
                        <button @click="closeDetailModal" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div v-if="currentTask" class="p-6 overflow-y-auto flex-1 space-y-4">
                        <div class="flex items-center gap-2">
                            <span :class="['px-3 py-1 rounded-full text-sm font-semibold', statusColors[currentTask.status]]">{{ currentTask.status }}</span>
                            <span v-if="currentTask.pop" class="text-sm text-gray-500">{{ currentTask.pop }}</span>
                        </div>
                        <div>
                            <h4 class="text-xl font-bold text-gray-900 dark:text-white">{{ currentTask.nama }}</h4>
                            <p class="text-gray-600 dark:text-gray-400">{{ currentTask.alamat }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div><span class="text-gray-500">WhatsApp:</span> <span class="font-medium">{{ currentTask.wa || '-' }}</span></div>
                            <div><span class="text-gray-500">Paket:</span> <span class="font-medium">{{ currentTask.paket || '-' }}</span></div>
                            <div><span class="text-gray-500">Harga:</span> <span class="font-medium text-green-600">{{ formatCurrency(currentTask.harga) }}</span></div>
                            <div><span class="text-gray-500">Tanggal:</span> <span class="font-medium">{{ formatDate(currentTask.tanggal) }}</span></div>
                            <div class="col-span-2"><span class="text-gray-500">Teknisi:</span> <span class="font-medium">{{ currentTask.teknisi || '-' }}</span></div>
                        </div>
                        <div v-if="currentTask.catatan" class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <span class="text-xs text-gray-500 uppercase">Catatan:</span>
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ currentTask.catatan }}</p>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                        <button v-if="currentTask?.status === 'Baru' || currentTask?.status === 'Survey'" @click="updateTaskStatus(currentTask.id, 'Proses')" class="btn btn-primary">
                            Mulai Proses
                        </button>
                        <button v-if="currentTask?.status === 'Proses'" @click="updateTaskStatus(currentTask.id, 'Selesai')" class="btn btn-success">
                            Lapor Selesai
                        </button>
                        <button v-if="currentTask?.status === 'Proses'" @click="updateTaskStatus(currentTask.id, 'Pending')" class="btn btn-warning">
                            Pending
                        </button>
                        <button @click="closeDetailModal" class="btn btn-secondary">Tutup</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- New Install Modal -->
        <Teleport to="body">
            <div v-if="showNewInstallModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4" @click.self="closeNewInstallModal">
                <div class="bg-white dark:bg-gray-800 w-full max-w-lg rounded-2xl shadow-2xl max-h-[95vh] flex flex-col">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-gray-900 text-white rounded-t-2xl">
                        <h3 class="text-lg font-bold">Input Pasang Baru</h3>
                        <button @click="closeNewInstallModal" class="p-2 hover:bg-white/10 rounded-full">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="p-6 overflow-y-auto flex-1 space-y-4">
                        <!-- Data Pelanggan -->
                        <div class="space-y-3">
                            <h4 class="text-xs font-bold text-blue-600 uppercase border-b pb-2">Data Pelanggan</h4>
                            <input v-model="newInstall.nama" type="text" placeholder="Nama Pelanggan *" class="input w-full" />
                            <input v-model="newInstall.wa" type="tel" placeholder="WhatsApp (08xxxxxxxxxx) *" class="input w-full" />
                            <textarea v-model="newInstall.alamat" rows="2" placeholder="Alamat Lengkap *" class="input w-full"></textarea>
                            <div class="grid grid-cols-2 gap-3">
                                <select v-model="newInstall.pop" class="input">
                                    <option value="">Pilih POP *</option>
                                    <option v-for="pop in pops" :key="pop.id" :value="pop.name">{{ pop.name }}</option>
                                </select>
                                <select v-model="newInstall.paket" class="input">
                                    <option value="">Pilih Paket</option>
                                    <option>Standar - 10 Mbps</option>
                                    <option>Premium - 15 Mbps</option>
                                    <option>Wide - 20 Mbps</option>
                                    <option>PRO - 50 Mbps</option>
                                </select>
                            </div>
                            <input v-model="newInstall.harga" type="number" placeholder="Harga (Rp)" class="input w-full" />
                        </div>
                        <!-- Tim & Sales -->
                        <div class="space-y-3">
                            <h4 class="text-xs font-bold text-blue-600 uppercase border-b pb-2">Tim & Sales</h4>
                            <input v-model="newInstall.sales_1" type="text" placeholder="Sales 1" class="input w-full" />
                            <div class="grid grid-cols-2 gap-3">
                                <select v-model="newInstall.teknisi_1" class="input">
                                    <option value="">Teknisi 1</option>
                                    <option v-for="tech in technicians" :key="tech.id" :value="tech.name">{{ tech.name }}</option>
                                </select>
                                <select v-model="newInstall.teknisi_2" class="input">
                                    <option value="">Teknisi 2</option>
                                    <option v-for="tech in technicians" :key="tech.id" :value="tech.name">{{ tech.name }}</option>
                                </select>
                            </div>
                        </div>
                        <!-- Info Tambahan -->
                        <div class="space-y-3">
                            <h4 class="text-xs font-bold text-blue-600 uppercase border-b pb-2">Info Tambahan</h4>
                            <input v-model="newInstall.tanggal" type="date" class="input w-full" />
                            <input v-model="newInstall.koordinat" type="text" placeholder="Koordinat (lat,lng)" class="input w-full" />
                            <textarea v-model="newInstall.catatan" rows="2" placeholder="Catatan..." class="input w-full"></textarea>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                        <button @click="closeNewInstallModal" class="btn btn-secondary">Batal</button>
                        <button @click="saveNewInstall" class="btn btn-primary">Simpan</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>

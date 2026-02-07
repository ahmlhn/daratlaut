<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const team = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const stats = ref({ total: 0, active: 0, inactive: 0, by_role: {} });
const pops = ref([]);

const loading = ref(false);
const showForm = ref(false);
const isEditing = ref(false);
const currentItem = ref(null);

// Filters
const filters = ref({
    q: '',
    role: '',
    is_active: '',
    page: 1,
});

const roleOptions = [
    { value: '', label: 'Semua Role' },
    { value: 'teknisi', label: 'Teknisi' },
    { value: 'sales', label: 'Sales' },
    { value: 'admin', label: 'Admin' },
    { value: 'cs', label: 'CS' },
    { value: 'keuangan', label: 'Keuangan' },
    { value: 'owner', label: 'Owner' },
];

const statusOptions = [
    { value: '', label: 'Semua Status' },
    { value: '1', label: 'Aktif' },
    { value: '0', label: 'Non-Aktif' },
];

const roleColors = {
    teknisi: 'bg-blue-100 text-blue-800',
    sales: 'bg-green-100 text-green-800',
    admin: 'bg-purple-100 text-purple-800',
    cs: 'bg-yellow-100 text-yellow-800',
    keuangan: 'bg-indigo-100 text-indigo-800',
    owner: 'bg-red-100 text-red-800',
};

const API_BASE = '/api/v1';

async function loadData() {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        Object.entries(filters.value).forEach(([key, val]) => {
            if (val !== '') params.append(key, val);
        });

        const response = await fetch(`${API_BASE}/team?${params.toString()}`);
        const result = await response.json();
        team.value = result.data || [];
        pagination.value = result.meta || { current_page: 1, last_page: 1, total: 0 };
    } catch (error) {
        console.error('Error loading team:', error);
    } finally {
        loading.value = false;
    }
}

async function loadStats() {
    try {
        const response = await fetch(`${API_BASE}/team/stats`);
        stats.value = await response.json();
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

async function loadPops() {
    try {
        const response = await fetch(`${API_BASE}/pops/dropdown`);
        const result = await response.json();
        pops.value = result.data || [];
    } catch (error) {
        console.error('Error loading pops:', error);
    }
}

function applyFilters() {
    filters.value.page = 1;
    loadData();
}

function resetFilters() {
    filters.value = { q: '', role: '', is_active: '', page: 1 };
    loadData();
}

function changePage(page) {
    filters.value.page = page;
    loadData();
}

function openCreateForm() {
    isEditing.value = false;
    currentItem.value = {
        name: '',
        phone: '',
        email: '',
        role: 'teknisi',
        pop_id: null,
        is_active: true,
        can_login: false,
        notes: '',
    };
    showForm.value = true;
}

function openEditForm(item) {
    isEditing.value = true;
    currentItem.value = { ...item };
    showForm.value = true;
}

async function saveItem() {
    try {
        const url = isEditing.value
            ? `${API_BASE}/team/${currentItem.value.id}`
            : `${API_BASE}/team`;

        const method = isEditing.value ? 'PUT' : 'POST';
        const body = { ...currentItem.value, tenant_id: 1 };

        const response = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });

        if (response.ok) {
            showForm.value = false;
            loadData();
            loadStats();
        } else {
            const error = await response.json();
            alert(error.message || 'Error saving team member');
        }
    } catch (error) {
        console.error('Error saving team member:', error);
        alert('Error saving team member');
    }
}

async function deleteItem(item) {
    if (!confirm(`Hapus anggota tim ${item.name}?`)) return;

    try {
        const response = await fetch(`${API_BASE}/team/${item.id}?tenant_id=1`, {
            method: 'DELETE',
        });

        if (response.ok) {
            loadData();
            loadStats();
        } else {
            const error = await response.json();
            alert(error.message || 'Error deleting team member');
        }
    } catch (error) {
        console.error('Error deleting team member:', error);
    }
}

async function toggleStatus(item) {
    try {
        const response = await fetch(`${API_BASE}/team/${item.id}/toggle-status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tenant_id: 1 }),
        });

        if (response.ok) {
            loadData();
            loadStats();
        }
    } catch (error) {
        console.error('Error toggling status:', error);
    }
}

onMounted(() => {
    loadData();
    loadStats();
    loadPops();
});
</script>

<template>
    <Head title="Team" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Tim</h1>
                    <p class="text-gray-500 text-sm">Kelola anggota tim teknisi, sales, dan admin</p>
                </div>
                <button @click="openCreateForm" class="btn btn-primary">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Tambah Anggota
                </button>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
                <div class="card p-4 text-center">
                    <div class="text-2xl font-bold text-gray-900">{{ stats.total }}</div>
                    <div class="text-sm text-gray-500">Total</div>
                </div>
                <div class="card p-4 text-center cursor-pointer hover:ring-2 hover:ring-green-500"
                     :class="filters.is_active === '1' ? 'ring-2 ring-green-500' : ''"
                     @click="filters.is_active = filters.is_active === '1' ? '' : '1'; applyFilters()">
                    <div class="text-2xl font-bold text-green-600">{{ stats.active }}</div>
                    <div class="text-sm text-gray-500">Aktif</div>
                </div>
                <div class="card p-4 text-center cursor-pointer hover:ring-2 hover:ring-red-500"
                     :class="filters.is_active === '0' ? 'ring-2 ring-red-500' : ''"
                     @click="filters.is_active = filters.is_active === '0' ? '' : '0'; applyFilters()">
                    <div class="text-2xl font-bold text-red-600">{{ stats.inactive }}</div>
                    <div class="text-sm text-gray-500">Non-Aktif</div>
                </div>
                <div class="card p-4 text-center cursor-pointer hover:ring-2 hover:ring-blue-500"
                     :class="filters.role === 'teknisi' ? 'ring-2 ring-blue-500' : ''"
                     @click="filters.role = filters.role === 'teknisi' ? '' : 'teknisi'; applyFilters()">
                    <div class="text-2xl font-bold text-blue-600">{{ stats.by_role?.teknisi || 0 }}</div>
                    <div class="text-sm text-gray-500">Teknisi</div>
                </div>
                <div class="card p-4 text-center cursor-pointer hover:ring-2 hover:ring-green-500"
                     :class="filters.role === 'sales' ? 'ring-2 ring-green-500' : ''"
                     @click="filters.role = filters.role === 'sales' ? '' : 'sales'; applyFilters()">
                    <div class="text-2xl font-bold text-green-600">{{ stats.by_role?.sales || 0 }}</div>
                    <div class="text-sm text-gray-500">Sales</div>
                </div>
                <div class="card p-4 text-center cursor-pointer hover:ring-2 hover:ring-purple-500"
                     :class="filters.role === 'admin' ? 'ring-2 ring-purple-500' : ''"
                     @click="filters.role = filters.role === 'admin' ? '' : 'admin'; applyFilters()">
                    <div class="text-2xl font-bold text-purple-600">{{ stats.by_role?.admin || 0 }}</div>
                    <div class="text-sm text-gray-500">Admin</div>
                </div>
                <div class="card p-4 text-center cursor-pointer hover:ring-2 hover:ring-yellow-500"
                     :class="filters.role === 'cs' ? 'ring-2 ring-yellow-500' : ''"
                     @click="filters.role = filters.role === 'cs' ? '' : 'cs'; applyFilters()">
                    <div class="text-2xl font-bold text-yellow-600">{{ stats.by_role?.cs || 0 }}</div>
                    <div class="text-sm text-gray-500">CS</div>
                </div>
                <div class="card p-4 text-center cursor-pointer hover:ring-2 hover:ring-red-500"
                     :class="filters.role === 'owner' ? 'ring-2 ring-red-500' : ''"
                     @click="filters.role = filters.role === 'owner' ? '' : 'owner'; applyFilters()">
                    <div class="text-2xl font-bold text-red-600">{{ stats.by_role?.owner || 0 }}</div>
                    <div class="text-sm text-gray-500">Owner</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card p-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <input v-model="filters.q" @keyup.enter="applyFilters" type="text"
                           placeholder="Cari nama, telepon, email..."
                           class="input w-full" />
                    <select v-model="filters.role" @change="applyFilters" class="input">
                        <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                    <select v-model="filters.is_active" @change="applyFilters" class="input">
                        <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                    <div class="flex gap-2">
                        <button @click="applyFilters" class="btn btn-primary flex-1">Filter</button>
                        <button @click="resetFilters" class="btn btn-secondary">Reset</button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 dark:bg-dark-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kontak</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">POP</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Login</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-dark-900 divide-y divide-gray-200 dark:divide-white/10">
                            <tr v-if="loading">
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">Loading...</td>
                            </tr>
                            <tr v-else-if="team.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">Tidak ada data</td>
                            </tr>
                            <tr v-for="item in team" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-dark-800">
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ item.name }}</div>
                                    <div v-if="item.notes" class="text-xs text-gray-400 dark:text-gray-500">{{ item.notes }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm text-gray-900 dark:text-white">{{ item.phone || '-' }}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ item.email || '' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="roleColors[item.role]" class="px-2 py-1 text-xs rounded-full font-medium capitalize">
                                        {{ item.role }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ pops.find(p => p.id === item.pop_id)?.name || '-' }}
                                </td>
                                <td class="px-4 py-3">
                                    <button @click="toggleStatus(item)"
                                            :class="item.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                            class="px-2 py-1 text-xs rounded-full font-medium">
                                        {{ item.is_active ? 'Aktif' : 'Non-Aktif' }}
                                    </button>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="item.can_login ? 'text-green-600' : 'text-gray-400'" class="text-sm">
                                        {{ item.can_login ? '✓ Ya' : '✗ Tidak' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-sm space-x-2">
                                    <button @click="openEditForm(item)" class="text-primary-600 hover:text-primary-900">Edit</button>
                                    <button @click="deleteItem(item)" class="text-red-600 hover:text-red-900">Hapus</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="pagination.last_page > 1" class="bg-gray-50 dark:bg-dark-900 px-4 py-3 flex items-center justify-between border-t border-gray-200 dark:border-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Menampilkan {{ team.length }} dari {{ pagination.total }} data
                    </div>
                    <div class="flex gap-2">
                        <button @click="changePage(pagination.current_page - 1)"
                                :disabled="pagination.current_page <= 1"
                                class="btn btn-secondary text-sm">Prev</button>
                        <span class="px-3 py-1 text-sm">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
                        <button @click="changePage(pagination.current_page + 1)"
                                :disabled="pagination.current_page >= pagination.last_page"
                                class="btn btn-secondary text-sm">Next</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen pt-4 px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="showForm = false"></div>

                <div class="relative bg-white dark:bg-dark-900 rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white dark:bg-dark-900 px-6 py-4 border-b border-gray-200 dark:border-white/10 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            {{ isEditing ? 'Edit Anggota Tim' : 'Tambah Anggota Tim' }}
                        </h3>
                        <button @click="showForm = false" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="saveItem" class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nama *</label>
                            <input v-model="currentItem.name" type="text" required class="input w-full" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">No. WhatsApp</label>
                                <input v-model="currentItem.phone" type="text" class="input w-full" placeholder="62812xxxxxxxx" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                                <input v-model="currentItem.email" type="email" class="input w-full" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Role *</label>
                                <select v-model="currentItem.role" required class="input w-full">
                                    <option v-for="opt in roleOptions.slice(1)" :key="opt.value" :value="opt.value">
                                        {{ opt.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">POP</label>
                                <select v-model="currentItem.pop_id" class="input w-full">
                                    <option :value="null">Semua POP</option>
                                    <option v-for="p in pops" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="currentItem.is_active" type="checkbox" class="rounded text-primary-600" />
                                <span class="text-sm text-gray-700">Aktif</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="currentItem.can_login" type="checkbox" class="rounded text-primary-600" />
                                <span class="text-sm text-gray-700">Bisa Login</span>
                            </label>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Catatan</label>
                            <textarea v-model="currentItem.notes" rows="2" class="input w-full"></textarea>
                        </div>

                        <div class="flex justify-end gap-3 pt-4 border-t">
                            <button type="button" @click="showForm = false" class="btn btn-secondary">Batal</button>
                            <button type="submit" class="btn btn-primary">
                                {{ isEditing ? 'Simpan Perubahan' : 'Tambah' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

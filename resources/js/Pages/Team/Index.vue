<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const team = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const stats = ref({ total: 0, active: 0, inactive: 0, by_role: {} });
const pops = ref([]);
const availableRoles = ref([]);

const loading = ref(false);
const showForm = ref(false);
const isEditing = ref(false);
const currentItem = ref(null);
const processing = ref(false);

const page = usePage();
const permissions = computed(() => page.props.auth.user?.permissions || []);
const legacyRole = computed(() => (page.props.auth.user?.role || '').toString().toLowerCase().trim());

function can(permission) {
    // Fallback for early migrations where Spatie roles/permissions are not seeded yet.
    if (['admin', 'owner'].includes(legacyRole.value)) return true;

    // Legacy wildcard permission for Team module.
    if (permissions.value.includes('manage team')) return true;

    return permissions.value.includes(permission);
}

// Filters
const filters = ref({
    q: '',
    role: '',
    is_active: '',
    page: 1,
});

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

async function loadRoles() {
    try {
        const response = await fetch(`${API_BASE}/roles`);
        const result = await response.json();
        availableRoles.value = result || [];
    } catch (error) {
        console.error('Error loading roles:', error);
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
        password: '', // New field
        notes: '',
    };
    showForm.value = true;
}

function openEditForm(item) {
    isEditing.value = true;
    currentItem.value = { 
        ...item,
        password: '', // Reset password field
    };
    showForm.value = true;
}

async function saveItem() {
    processing.value = true;
    try {
        const url = isEditing.value
            ? `${API_BASE}/team/${currentItem.value.id}`
            : `${API_BASE}/team`;

        const method = isEditing.value ? 'PUT' : 'POST';
        const body = { ...currentItem.value, tenant_id: 1 };

        const response = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
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
    } finally {
        processing.value = false;
    }
}

async function deleteItem(item) {
    if (!confirm(`Hapus anggota tim ${item.name}? Jika user ini memiliki akun login, akun tersebut juga akan dihapus.`)) return;

    try {
        const response = await fetch(`${API_BASE}/team/${item.id}?tenant_id=1`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json' }
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
    if (!can('edit team')) return;
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
    loadRoles();
});
</script>

<template>
    <Head title="Team" />

    <AdminLayout>
        <div class="space-y-6 animate-fade-in">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white dark:bg-dark-800 p-6 rounded-xl shadow-card border border-gray-100 dark:border-dark-700">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Tim & Users</h1>
                    <p class="text-gray-500 dark:text-gray-400 text-sm">Kelola anggota tim, role akses, dan akun login.</p>
                </div>
                <!-- Protected Create Button -->
                <button v-if="can('create team')" @click="openCreateForm" class="btn btn-primary shadow-glow-blue hover:shadow-glow-blue-lg transition-all">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Tambah Anggota
                </button>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
                <div class="bg-white dark:bg-dark-800 rounded-xl p-4 text-center shadow-sm border border-gray-100 dark:border-dark-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ stats.total }}</div>
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider mt-1">Total</div>
                </div>
                <div class="bg-white dark:bg-dark-800 rounded-xl p-4 text-center shadow-sm border border-gray-100 dark:border-dark-700 cursor-pointer hover:ring-2 hover:ring-green-500 transition-all"
                     :class="filters.is_active === '1' ? 'ring-2 ring-green-500 bg-green-50 dark:bg-green-900/10' : ''"
                     @click="filters.is_active = filters.is_active === '1' ? '' : '1'; applyFilters()">
                    <div class="text-2xl font-bold text-green-600">{{ stats.active }}</div>
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider mt-1">Aktif</div>
                </div>
                <!-- Dynamic Role Stats -->
                <div v-for="roleName in Object.keys(roleColors)" :key="roleName" 
                     class="bg-white dark:bg-dark-800 rounded-xl p-4 text-center shadow-sm border border-gray-100 dark:border-dark-700 cursor-pointer hover:ring-2 hover:ring-primary-500 transition-all"
                     :class="filters.role === roleName ? 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-900/10' : ''"
                     @click="filters.role = filters.role === roleName ? '' : roleName; applyFilters()">
                     <div class="text-2xl font-bold" :class="roleColors[roleName]?.split(' ')[1] || 'text-gray-700'">
                        {{ stats.by_role?.[roleName] || 0 }}
                     </div>
                     <div class="text-xs font-medium text-gray-500 uppercase tracking-wider mt-1">{{ roleName }}</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white dark:bg-dark-800 rounded-xl p-4 shadow-card border border-gray-100 dark:border-dark-700">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </span>
                        <input v-model="filters.q" @keyup.enter="applyFilters" type="text"
                            placeholder="Cari nama, telepon, email..."
                            class="input w-full pl-9" />
                    </div>
                    <select v-model="filters.role" @change="applyFilters" class="input capitalize">
                        <option value="">Semua Role</option>
                        <option v-for="role in availableRoles" :key="role.id" :value="role.name">
                            {{ role.name.replace(/_/g, ' ') }}
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
            <div class="bg-white dark:bg-dark-800 rounded-xl shadow-card overflow-hidden border border-gray-100 dark:border-dark-700">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-700">
                        <thead class="bg-gray-50 dark:bg-dark-700/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kontak</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">POP</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Login</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-dark-800 divide-y divide-gray-200 dark:divide-dark-700">
                            <tr v-if="loading">
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                    <svg class="w-8 h-8 mx-auto animate-spin text-primary-500 mb-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    Memuat data...
                                </td>
                            </tr>
                            <tr v-else-if="team.length === 0">
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                    <p>Tidak ada data ditemukan.</p>
                                </td>
                            </tr>
                            <tr v-for="item in team" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-dark-700/50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-sm font-bold text-gray-900 dark:text-white">{{ item.name }}</div>
                                    <div v-if="item.notes" class="text-xs text-gray-500 mt-0.5 max-w-[200px] truncate" :title="item.notes">
                                        {{ item.notes }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 dark:text-white">{{ item.phone || '-' }}</div>
                                    <div class="text-xs text-gray-500">{{ item.email || '' }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span :class="roleColors[item.role] || 'bg-gray-100 text-gray-800'" class="px-2.5 py-0.5 text-xs rounded-full font-bold capitalize">
                                        {{ item.role.replace(/_/g, ' ') }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    {{ pops.find(p => p.id === item.pop_id)?.name || '-' }}
                                </td>
                                <td class="px-6 py-4">
                                    <!-- Protected Toggle -->
                                    <button @click="toggleStatus(item)"
                                            :disabled="!can('edit team')"
                                            :class="[item.is_active ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-red-100 text-red-700 hover:bg-red-200', !can('edit team') ? 'opacity-50 cursor-not-allowed' : '']"
                                            class="px-2.5 py-0.5 text-xs rounded-full font-bold transition-colors">
                                        {{ item.is_active ? 'Aktif' : 'Non-Aktif' }}
                                    </button>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div :class="item.can_login ? 'bg-green-500' : 'bg-gray-300'" class="w-2 h-2 rounded-full mr-2"></div>
                                        <span :class="item.can_login ? 'text-green-700 font-medium' : 'text-gray-400'" class="text-sm">
                                            {{ item.can_login ? 'Access' : 'No Access' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right text-sm font-medium space-x-3">
                                    <!-- Protected Actions -->
                                    <button v-if="can('edit team')" @click="openEditForm(item)" class="text-primary-600 hover:text-primary-900 dark:hover:text-primary-400 transition-colors">Edit</button>
                                    <button v-if="can('delete team')" @click="deleteItem(item)" class="text-red-500 hover:text-red-700 transition-colors">Hapus</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="pagination.last_page > 1" class="bg-gray-50 dark:bg-dark-800 px-6 py-4 flex items-center justify-between border-t border-gray-200 dark:border-dark-700">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Menampilkan {{ team.length }} dari {{ pagination.total }} data
                    </div>
                    <div class="flex gap-2">
                        <button @click="changePage(pagination.current_page - 1)"
                                :disabled="pagination.current_page <= 1"
                                class="btn btn-secondary text-sm disabled:opacity-50">Prev</button>
                        <span class="px-3 py-1.5 text-sm font-medium bg-white dark:bg-dark-700 rounded-lg border border-gray-200 dark:border-dark-600">
                            {{ pagination.current_page }} / {{ pagination.last_page }}
                        </span>
                        <button @click="changePage(pagination.current_page + 1)"
                                :disabled="pagination.current_page >= pagination.last_page"
                                class="btn btn-secondary text-sm disabled:opacity-50">Next</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showForm" class="fixed inset-0 z-50 overflow-y-auto">
                    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                        <div class="fixed inset-0 bg-gray-900/75 backdrop-blur-sm transition-opacity" @click="showForm = false"></div>

                        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                        <div class="inline-block align-bottom bg-white dark:bg-dark-900 rounded-2xl shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-xl sm:w-full relative z-10 text-left overflow-hidden">
                            <div class="bg-gray-50 dark:bg-dark-800 px-6 py-4 border-b border-gray-100 dark:border-dark-700 flex justify-between items-center">
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                                    {{ isEditing ? 'Edit Anggota Tim' : 'Tambah Anggota Tim' }}
                                </h3>
                                <button @click="showForm = false" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <form @submit.prevent="saveItem" class="px-6 py-6 space-y-5 max-h-[70vh] overflow-y-auto custom-scrollbar">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Nama Lengkap <span class="text-red-500">*</span></label>
                                    <input v-model="currentItem.name" type="text" required class="input w-full" placeholder="Nama lengkap pegawai" />
                                </div>
                                <div class="grid grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">No. WhatsApp</label>
                                        <input v-model="currentItem.phone" type="text" class="input w-full" placeholder="62812xxxxxxxx" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                                        <input v-model="currentItem.email" type="email" class="input w-full" placeholder="email@contoh.com" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Role <span class="text-red-500">*</span></label>
                                        <select v-model="currentItem.role" required class="input w-full capitalize">
                                            <option v-for="role in availableRoles" :key="role.id" :value="role.name">
                                                {{ role.name.replace(/_/g, ' ') }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">POP (Lokasi)</label>
                                        <select v-model="currentItem.pop_id" class="input w-full">
                                            <option :value="null">Semua POP</option>
                                            <option v-for="p in pops" :key="p.id" :value="p.id">{{ p.name }}</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="bg-gray-50 dark:bg-dark-700/50 rounded-lg p-4 border border-gray-100 dark:border-dark-600 space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <label class="flex items-center gap-3 cursor-pointer group">
                                            <div class="relative flex items-center">
                                                <input v-model="currentItem.is_active" type="checkbox" class="peer h-5 w-5 cursor-pointer appearance-none rounded-md border border-gray-300 checked:bg-green-500 checked:border-green-500 transition-all" />
                                                <svg class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-3.5 h-3.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                                            </div>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-green-600 transition-colors">Status Aktif</span>
                                        </label>
                                        
                                        <label class="flex items-center gap-3 cursor-pointer group">
                                            <div class="relative flex items-center">
                                                <input v-model="currentItem.can_login" type="checkbox" class="peer h-5 w-5 cursor-pointer appearance-none rounded-md border border-gray-300 checked:bg-primary-500 checked:border-primary-500 transition-all" />
                                                <svg class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-3.5 h-3.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                                            </div>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-primary-600 transition-colors">Bisa Login System</span>
                                        </label>
                                    </div>

                                    <!-- Password Login -->
                                    <div v-if="currentItem.can_login" class="pt-2 animate-fade-in">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                                            {{ isEditing ? 'Ubah Password (Optional)' : 'Password Login *' }}
                                        </label>
                                        <input v-model="currentItem.password" type="password" 
                                            :required="!isEditing"
                                            class="input w-full" 
                                            :placeholder="isEditing ? 'Biarkan kosong jika tidak ingin mengubah' : 'Masukkan password untuk login'" />
                                        <p v-if="!isEditing" class="text-xs text-gray-500 mt-1">
                                            Username akan digenerate otomatis dari nama (tanpa spasi).
                                        </p>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Catatan Tambahan</label>
                                    <textarea v-model="currentItem.notes" rows="2" class="input w-full" placeholder="Info tambahan..."></textarea>
                                </div>
                            </form>

                            <div class="bg-gray-50 dark:bg-dark-800 px-6 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-3 border-t border-gray-100 dark:border-dark-700">
                                <button type="button" @click="showForm = false" class="w-full sm:w-auto btn btn-secondary">Batal</button>
                                <button type="button" @click="saveItem" :disabled="processing" class="w-full sm:w-auto btn btn-primary flex justify-center items-center">
                                    <svg v-if="processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    {{ isEditing ? 'Simpan Perubahan' : 'Tambah Member' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AdminLayout>
</template>

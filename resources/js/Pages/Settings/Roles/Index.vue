<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

// State
const roles = ref([]);
const permissions = ref([]);
const loading = ref(false);
const showForm = ref(false);
const isEditing = ref(false);
const processing = ref(false);
const searchQuery = ref('');
const showAdvanced = ref(false);

const form = ref({
    id: null,
    name: '',
    permissions: []
});

const API_BASE = '/api/v1';

const LEVELS = [
    { value: 'none', label: 'Tidak ada' },
    { value: 'read', label: 'Lihat' },
    { value: 'operator', label: 'Operator' },
    { value: 'manager', label: 'Manager' },
];

const MODULE_PRESETS = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        description: 'Ringkasan & statistik.',
        levels: {
            read: ['view dashboard'],
            operator: ['view dashboard'],
            manager: ['view dashboard'],
        },
    },
    {
        key: 'installations',
        label: 'Pasang Baru',
        description: 'Instalasi, monitoring, approval.',
        levels: {
            read: ['view installations', 'view riwayat installations'],
            operator: ['view installations', 'create installations', 'edit installations', 'view riwayat installations'],
            manager: [
                'view installations',
                'create installations',
                'edit installations',
                'delete installations',
                'approve installations',
                'send installations recap',
                'view riwayat installations',
            ],
        },
    },
    {
        key: 'team',
        label: 'Tim',
        description: 'Teknisi/sales/staff.',
        levels: {
            read: ['view team'],
            operator: ['view team', 'create team', 'edit team'],
            manager: ['manage team', 'view team', 'create team', 'edit team', 'delete team'],
        },
    },
    {
        key: 'teknisi',
        label: 'Teknisi',
        description: 'Tugas, riwayat, rekap.',
        levels: {
            read: ['view teknisi'],
            operator: ['view teknisi', 'edit teknisi'],
            manager: ['view teknisi', 'edit teknisi', 'send teknisi recap'],
        },
    },
    {
        key: 'maps',
        label: 'Maps',
        description: 'Tracking teknisi.',
        levels: {
            read: ['view maps'],
            operator: ['view maps', 'manage maps'],
            manager: ['view maps', 'manage maps'],
        },
    },
    {
        key: 'chat',
        label: 'Chat',
        description: 'Chat admin.',
        levels: {
            read: ['view chat'],
            operator: ['view chat', 'send chat', 'edit chat'],
            manager: ['view chat', 'send chat', 'edit chat', 'delete chat'],
        },
    },
    {
        key: 'leads',
        label: 'Leads',
        description: 'Prospek & konversi.',
        levels: {
            read: ['view leads'],
            operator: ['view leads', 'create leads', 'edit leads', 'convert leads', 'bulk leads'],
            manager: ['view leads', 'create leads', 'edit leads', 'delete leads', 'convert leads', 'bulk leads'],
        },
    },
    {
        key: 'customers',
        label: 'Pelanggan',
        description: 'Data pelanggan.',
        levels: {
            read: ['view customers'],
            operator: ['view customers', 'create customers', 'edit customers'],
            manager: ['view customers', 'create customers', 'edit customers', 'delete customers'],
        },
    },
    {
        key: 'plans',
        label: 'Paket Layanan',
        description: 'Produk/paket.',
        levels: {
            read: ['view plans'],
            operator: ['view plans', 'create plans', 'edit plans'],
            manager: ['view plans', 'create plans', 'edit plans', 'delete plans'],
        },
    },
    {
        key: 'invoices',
        label: 'Invoice',
        description: 'Tagihan.',
        levels: {
            read: ['view invoices'],
            operator: ['view invoices', 'create invoices', 'edit invoices'],
            manager: ['view invoices', 'create invoices', 'edit invoices', 'delete invoices'],
        },
    },
    {
        key: 'payments',
        label: 'Pembayaran',
        description: 'Transaksi.',
        levels: {
            read: ['view payments'],
            operator: ['view payments', 'create payments', 'edit payments'],
            manager: ['view payments', 'create payments', 'edit payments', 'delete payments'],
        },
    },
    {
        key: 'reports',
        label: 'Laporan',
        description: 'Analitik & export.',
        levels: {
            read: ['view reports'],
            operator: ['view reports', 'export reports'],
            manager: ['view reports', 'export reports'],
        },
    },
    {
        key: 'finance',
        label: 'Keuangan',
        description: 'Transaksi & approval.',
        levels: {
            read: ['view finance'],
            operator: ['view finance', 'create finance', 'edit finance', 'export finance'],
            manager: ['manage finance', 'view finance', 'create finance', 'edit finance', 'delete finance', 'approve finance', 'export finance'],
        },
    },
    {
        key: 'olts',
        label: 'OLT',
        description: 'Provisioning OLT/ONU.',
        levels: {
            read: ['view olts'],
            operator: ['manage olt', 'view olts', 'create olts', 'edit olts'],
            manager: ['manage olt', 'view olts', 'create olts', 'edit olts', 'delete olts'],
        },
    },
    {
        key: 'isolir',
        label: 'Isolir',
        description: 'Suspend/unsuspend.',
        levels: {
            read: [],
            operator: ['manage isolir'],
            manager: ['manage isolir'],
        },
    },
    {
        key: 'settings',
        label: 'Pengaturan Sistem',
        description: 'Settings, roles, update.',
        levels: {
            read: [],
            operator: ['manage settings'],
            manager: ['manage settings', 'manage roles', 'manage system update'],
        },
    },
];

// Load Data
async function loadRoles() {
    loading.value = true;
    try {
        const response = await fetch(`${API_BASE}/roles`);
        roles.value = await response.json();
    } catch (error) {
        console.error('Error loading roles:', error);
    } finally {
        loading.value = false;
    }
}

async function loadPermissions() {
    try {
        const response = await fetch(`${API_BASE}/permissions`);
        permissions.value = await response.json();
    } catch (error) {
        console.error('Error loading permissions:', error);
    }
}

// Actions
function openCreateForm() {
    isEditing.value = false;
    form.value = { id: null, name: '', permissions: [] };
    showForm.value = true;
    searchQuery.value = '';
    showAdvanced.value = false;
}

function openEditForm(role) {
    isEditing.value = true;
    form.value = {
        id: role.id,
        name: role.name,
        permissions: role.permissions.map(p => p.name)
    };
    showForm.value = true;
    searchQuery.value = '';
    showAdvanced.value = false;
}

async function saveRole() {
    if (!form.value.name) return;
    processing.value = true;
    try {
        const url = isEditing.value ? `${API_BASE}/roles/${form.value.id}` : `${API_BASE}/roles`;
        const method = isEditing.value ? 'PUT' : 'POST';
        
        const response = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(form.value)
        });

        if (response.ok) {
            showForm.value = false;
            loadRoles();
        } else {
            const error = await response.json();
            alert(error.message || 'Gagal menyimpan role');
        }
    } catch (error) {
        console.error('Error saving role:', error);
        alert('Terjadi kesalahan sistem');
    } finally {
        processing.value = false;
    }
}

async function deleteRole(role) {
    if (!confirm(`Yakin ingin menghapus role "${role.name}"? Tindakan ini tidak dapat dibatalkan.`)) return;

    try {
        const response = await fetch(`${API_BASE}/roles/${role.id}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json' }
        });

        if (response.ok) {
            loadRoles();
        } else {
            const error = await response.json();
            alert(error.message || 'Gagal menghapus role');
        }
    } catch (error) {
        console.error('Error deleting role:', error);
    }
}

// Group permissions logic with search filtering
const groupedPermissions = computed(() => {
    const groups = {};
    const query = searchQuery.value.toLowerCase();
    
    permissions.value.forEach(p => {
        // Filter based on search
        if (query && !p.name.toLowerCase().includes(query)) return;

        const parts = p.name.split(' ');
        const entity = parts.length > 1 ? parts.slice(1).join(' ') : 'System'; 
        const groupName = entity.charAt(0).toUpperCase() + entity.slice(1);
        
        if (!groups[groupName]) groups[groupName] = [];
        groups[groupName].push(p);
    });
    
    // Sort groups alphabetically
    return Object.keys(groups).sort().reduce((acc, key) => {
        acc[key] = groups[key];
        return acc;
    }, {});
});

function togglePermission(name) {
    const idx = form.value.permissions.indexOf(name);
    if (idx === -1) form.value.permissions.push(name);
    else form.value.permissions.splice(idx, 1);
}

function toggleGroup(groupName, perms) {
    const allSelected = perms.every(p => form.value.permissions.includes(p.name));
    if (allSelected) {
        // Unselect all
        perms.forEach(p => {
            const idx = form.value.permissions.indexOf(p.name);
            if (idx !== -1) form.value.permissions.splice(idx, 1);
        });
    } else {
        // Select all
        perms.forEach(p => {
            if (!form.value.permissions.includes(p.name)) form.value.permissions.push(p.name);
        });
    }
}

const permissionNameSet = computed(() => new Set((permissions.value || []).map(p => p.name)));

function uniq(arr) {
    const out = [];
    const seen = new Set();
    (arr || []).forEach(v => {
        if (!v || seen.has(v)) return;
        seen.add(v);
        out.push(v);
    });
    return out;
}

function presetPerms(module, level) {
    const all = (module?.levels?.[level] || []).slice();
    // Only apply permissions that exist in the DB (in case catalog differs).
    return all.filter(p => permissionNameSet.value.has(p));
}

function moduleAllPerms(module) {
    return uniq([
        ...presetPerms(module, 'read'),
        ...presetPerms(module, 'operator'),
        ...presetPerms(module, 'manager'),
    ]);
}

function selectedPermsInModule(module) {
    const modulePerms = moduleAllPerms(module);
    const selected = new Set(form.value.permissions || []);
    return modulePerms.filter(p => selected.has(p));
}

function setsEqual(a, b) {
    if (a.size !== b.size) return false;
    for (const v of a) {
        if (!b.has(v)) return false;
    }
    return true;
}

function getModuleLevel(module) {
    const selected = new Set(selectedPermsInModule(module));
    if (selected.size === 0) return 'none';

    const read = new Set(presetPerms(module, 'read'));
    const operator = new Set(presetPerms(module, 'operator'));
    const manager = new Set(presetPerms(module, 'manager'));

    // Prefer the lowest matching level (more intuitive when sets overlap).
    if (setsEqual(selected, read)) return 'read';
    if (setsEqual(selected, operator)) return 'operator';
    if (setsEqual(selected, manager)) return 'manager';

    return 'custom';
}

function setModuleLevel(module, level) {
    if (!module) return;
    if (!['none', 'read', 'operator', 'manager'].includes(level)) return;

    const remove = new Set(moduleAllPerms(module));
    const next = (form.value.permissions || []).filter(p => !remove.has(p));

    if (level !== 'none') {
        next.push(...presetPerms(module, level));
    }

    form.value.permissions = uniq(next);
}

function modulePreview(module) {
    const level = getModuleLevel(module);
    const list = level === 'custom'
        ? selectedPermsInModule(module)
        : (level === 'none' ? [] : presetPerms(module, level));

    const head = list.slice(0, 3);
    const extra = Math.max(0, list.length - head.length);

    return { list, head, extra, level };
}

onMounted(() => {
    loadRoles();
    loadPermissions();
});
</script>

<template>
    <Head title="Kelola Role" />

    <AdminLayout>
        <div class="space-y-8 animate-fade-in">
            <!-- Header Section -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white dark:bg-dark-800 p-6 rounded-2xl shadow-card border border-gray-100 dark:border-dark-700">
                <div>
                    <h1 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-primary-600 to-primary-400">
                        Role & Permissions
                    </h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">
                        Atur akses dan keamanan sistem dengan mudah.
                    </p>
                </div>
                <button @click="openCreateForm" 
                    class="group relative inline-flex items-center justify-center px-6 py-3 text-sm font-medium text-white transition-all duration-200 bg-primary-600 border border-transparent rounded-xl hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 shadow-glow-blue hover:shadow-glow-blue-lg overflow-hidden">
                    <span class="absolute inset-0 w-full h-full -mt-1 rounded-lg opacity-30 bg-gradient-to-b from-transparent via-transparent to-black"></span>
                    <svg class="w-5 h-5 mr-2 -ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    <span>Tambah Role Baru</span>
                </button>
            </div>

            <!-- Content Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                <!-- Loading Skeleton -->
                <template v-if="loading">
                    <div v-for="n in 3" :key="n" class="h-48 bg-gray-100 dark:bg-dark-800 rounded-2xl animate-pulse"></div>
                </template>

                <!-- Role Cards -->
                <div v-else v-for="role in roles" :key="role.id" 
                    class="group relative flex flex-col justify-between bg-white dark:bg-dark-800 rounded-2xl p-6 shadow-card hover:shadow-card-hover transition-all duration-300 border border-transparent hover:border-primary-100 dark:hover:border-primary-900/30">
                    
                    <!-- Card Decoration -->
                    <div class="absolute top-0 right-0 p-4 opacity-5 group-hover:opacity-10 transition-opacity">
                        <svg class="w-24 h-24 text-primary-600 transform rotate-12" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2L2 7l10 5 10-5-10-5zm0 9l2.5-1.25L12 8.5l-2.5 1.25L12 11zm0 2.5l-5-2.5-5 2.5L12 22l10-8.5-5-2.5-5 2.5z"/>
                        </svg>
                    </div>

                    <div>
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center space-x-3">
                                <div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400 group-hover:scale-110 transition-transform duration-300">
                                    <svg v-if="role.name === 'admin' || role.name === 'owner'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                                    <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900 dark:text-white capitalize tracking-tight">{{ role.name.replace(/_/g, ' ') }}</h3>
                                    <span class="text-xs font-medium text-gray-400 uppercase tracking-wider">
                                        {{ role.name === 'owner' ? 'Super Admin' : 'Role Sistem' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3 relative z-10">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">Akses Permission</span>
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                    {{ role.permissions.length }} Item
                                </span>
                            </div>
                            
                            <div class="flex flex-wrap gap-1.5 h-20 overflow-hidden content-start">
                                <span v-for="perm in role.permissions.slice(0, 6)" :key="perm.id" 
                                    class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-50 dark:bg-dark-700 text-gray-600 dark:text-gray-300 border border-gray-100 dark:border-gray-600">
                                    {{ perm.name }}
                                </span>
                                <span v-if="role.permissions.length > 6" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400">
                                    +{{ role.permissions.length - 6 }} lainnya
                                </span>
                                <span v-if="role.permissions.length === 0" class="text-xs text-gray-400 italic py-2">
                                    Tidak ada permission khusus.
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700 flex justify-end items-center gap-3 relative z-10">
                        <button @click="openEditForm(role)" 
                            class="flex-1 inline-flex justify-center items-center px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-dark-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-dark-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
                            <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                            Edit
                        </button>
                        
                        <button v-if="!['admin', 'owner'].includes(role.name)" @click="deleteRole(role)" 
                            class="inline-flex justify-center items-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 dark:bg-red-900/20 border border-transparent rounded-lg hover:bg-red-100 dark:hover:bg-red-900/30 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modern Modal with Teleport -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showForm" class="fixed inset-0 z-[100] overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                    <!-- Backdrop with blur -->
                    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                        <div class="fixed inset-0 bg-gray-900/50 dark:bg-black/70 backdrop-blur-sm transition-opacity" aria-hidden="true" @click="showForm = false"></div>

                        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                        <!-- Modal Panel -->
                        <div class="inline-block align-bottom bg-white dark:bg-dark-900 rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl w-full relative z-10 animate-scale-in">
                            <!-- Modal Header -->
                            <div class="px-6 py-5 border-b border-gray-100 dark:border-dark-800 flex justify-between items-center bg-gray-50/50 dark:bg-dark-800/50">
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900 dark:text-white" id="modal-title">
                                        {{ isEditing ? 'Edit Konfigurasi Role' : 'Buat Role Baru' }}
                                    </h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                        {{ isEditing ? `Mengubah akses untuk role: ${form.name}` : 'Tentukan nama dan hak akses untuk role baru.' }}
                                    </p>
                                </div>
                                <button @click="showForm = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors p-2 rounded-full hover:bg-gray-100 dark:hover:bg-dark-700">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>

                            <div class="px-6 py-6 space-y-6">
                                <!-- Role Name Field -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        Nama Identitas Role <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" /></svg>
                                        </div>
                                        <input v-model="form.name" type="text" 
                                            class="input w-full pl-10 py-2.5 border-gray-300 dark:border-dark-600 focus:ring-primary-500 focus:border-primary-500 rounded-lg dark:bg-dark-800 dark:text-white" 
                                            placeholder="Contoh: staff_admin, supervisor_teknisi" 
                                            :disabled="isEditing && ['admin', 'owner'].includes(form.name)">
                                    </div>
                                    <p v-if="isEditing && ['admin', 'owner'].includes(form.name)" class="text-xs text-amber-600 mt-2 flex items-center">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                        Role sistem tidak dapat diubah namanya.
                                    </p>
                                </div>

                                <!-- Permissions Section -->
                                <div>
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-3 gap-3">
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">
                                                Preset Akses per Modul
                                            </label>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                Pilih level akses. Kalau perlu detail, buka mode lanjutan.
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            @click="showAdvanced = !showAdvanced"
                                            class="inline-flex items-center justify-center px-3 py-2 text-xs font-semibold rounded-xl border border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-dark-700 transition-colors"
                                        >
                                            <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            {{ showAdvanced ? 'Sembunyikan Mode Lanjutan' : 'Tampilkan Mode Lanjutan' }}
                                        </button>
                                    </div>

                                    <div class="border border-gray-200 dark:border-dark-700 rounded-xl bg-gray-50/50 dark:bg-dark-800/50 p-4">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div
                                                v-for="m in MODULE_PRESETS"
                                                :key="m.key"
                                                class="p-4 rounded-xl border bg-white dark:bg-dark-800 transition-colors"
                                                :class="[
                                                    getModuleLevel(m) === 'custom' ? 'border-amber-200 dark:border-amber-900/40' : 'border-gray-100 dark:border-dark-700'
                                                ]"
                                            >
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <div class="text-sm font-bold text-gray-900 dark:text-white">
                                                            {{ m.label }}
                                                            <span v-if="getModuleLevel(m) === 'custom'" class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border border-amber-200/60 dark:border-amber-900/40">
                                                                Custom
                                                            </span>
                                                        </div>
                                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                            {{ m.description }}
                                                        </div>
                                                    </div>

                                                    <div class="shrink-0">
                                                        <select
                                                            :value="getModuleLevel(m)"
                                                            @change="setModuleLevel(m, $event.target.value)"
                                                            class="text-sm rounded-lg border border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-900 text-gray-800 dark:text-gray-200 focus:ring-primary-500 focus:border-primary-500 px-3 py-2"
                                                        >
                                                            <option value="custom" disabled>Custom</option>
                                                            <option v-for="opt in LEVELS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="mt-3 flex flex-wrap gap-1.5">
                                                    <template v-for="p in modulePreview(m).head" :key="p">
                                                        <span class="inline-flex items-center px-2 py-1 rounded text-[11px] font-medium bg-gray-50 dark:bg-dark-700 text-gray-600 dark:text-gray-300 border border-gray-100 dark:border-gray-600">
                                                            {{ p }}
                                                        </span>
                                                    </template>
                                                    <span v-if="modulePreview(m).extra > 0" class="inline-flex items-center px-2 py-1 rounded text-[11px] font-semibold bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300">
                                                        +{{ modulePreview(m).extra }}
                                                    </span>
                                                    <span v-if="modulePreview(m).list.length === 0" class="text-[11px] text-gray-400 italic py-1">
                                                        Tidak ada akses.
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-if="showAdvanced" class="mt-5">
                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-3 gap-4">
                                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">
                                                Mode Lanjutan: Checklist Permission
                                            </label>
                                            <!-- Search Filter -->
                                            <div class="relative w-full sm:w-72">
                                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                                                </div>
                                                <input v-model="searchQuery" type="text" 
                                                    class="w-full pl-9 pr-3 py-1.5 text-sm border border-gray-300 dark:border-dark-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-dark-800 dark:text-white" 
                                                    placeholder="Cari izin akses..." />
                                            </div>
                                        </div>

                                        <div class="h-[60vh] sm:h-96 overflow-y-auto custom-scrollbar border border-gray-200 dark:border-dark-700 rounded-xl bg-gray-50/50 dark:bg-dark-800/50 p-4 space-y-4">
                                            <div v-for="(perms, group) in groupedPermissions" :key="group" class="bg-white dark:bg-dark-800 rounded-lg border border-gray-100 dark:border-dark-700 p-4 shadow-sm">
                                                <div class="flex items-center justify-between mb-3 border-b border-gray-100 dark:border-dark-700 pb-2">
                                                    <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider flex items-center">
                                                        <span class="w-1.5 h-4 bg-primary-500 rounded-full mr-2"></span>
                                                        {{ group }}
                                                    </h4>
                                                    <button @click="toggleGroup(group, perms)" class="text-xs text-primary-600 hover:text-primary-700 font-medium hover:underline">
                                                        Toggle Semua
                                                    </button>
                                                </div>
                                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                                    <label v-for="perm in perms" :key="perm.id" 
                                                        class="group flex items-start space-x-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-dark-700 cursor-pointer transition-colors border border-transparent hover:border-gray-200 dark:hover:border-dark-600">
                                                        <input type="checkbox" :checked="form.permissions.includes(perm.name)" @change="togglePermission(perm.name)" 
                                                            class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer">
                                                        <span class="text-sm text-gray-600 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white transition-colors select-none">
                                                            {{ perm.name }}
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <!-- Keep empty state if search returns nothing -->
                                            <div v-if="Object.keys(groupedPermissions).length === 0" class="text-center py-12 text-gray-500">
                                                <svg class="w-12 h-12 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                <p>Tidak ada permission yang cocok dengan pencarian.</p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex justify-between items-center mt-2 text-xs text-gray-500">
                                        <span>Total Terpilih: {{ form.permissions.length }}</span>
                                        <span>Total Tersedia: {{ permissions.length }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Modal Footer -->
                            <div class="bg-gray-50 dark:bg-dark-800 px-6 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-3 border-t border-gray-100 dark:border-dark-700">
                                <button @click="showForm = false" 
                                    class="w-full sm:w-auto inline-flex justify-center rounded-xl border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2.5 bg-white dark:bg-dark-700 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-dark-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 sm:text-sm transition-all">
                                    Batal
                                </button>
                                <button @click="saveRole" :disabled="processing" 
                                    class="w-full sm:w-auto inline-flex justify-center rounded-xl border border-transparent shadow-glow-blue px-6 py-2.5 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:text-sm transition-all disabled:opacity-70 disabled:cursor-not-allowed">
                                    <svg v-if="processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ processing ? 'Menyimpan Perubahan...' : 'Simpan Konfigurasi' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AdminLayout>
</template>

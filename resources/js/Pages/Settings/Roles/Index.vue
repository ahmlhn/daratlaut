<script setup>
import { ref, onMounted, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useForm } from '@inertiajs/vue3';

// State
const roles = ref([]);
const permissions = ref([]);
const loading = ref(false);
const showForm = ref(false);
const isEditing = ref(false);
const processing = ref(false);

const form = ref({
    id: null,
    name: '',
    permissions: []
});

const API_BASE = '/api/v1';

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
}

function openEditForm(role) {
    isEditing.value = true;
    form.value = {
        id: role.id,
        name: role.name,
        permissions: role.permissions.map(p => p.name)
    };
    showForm.value = true;
}

async function saveRole() {
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
    if (!confirm(`Hapus role ${role.name}?`)) return;

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

// Group permissions for better UI
const groupedPermissions = computed(() => {
    const groups = {};
    permissions.value.forEach(p => {
        const parts = p.name.split(' ');
        const entity = parts.length > 1 ? parts.slice(1).join(' ') : 'Other'; // e.g. "view users" -> "users"
        if (!groups[entity]) groups[entity] = [];
        groups[entity].push(p);
    });
    return groups;
});

function togglePermission(name) {
    const idx = form.value.permissions.indexOf(name);
    if (idx === -1) form.value.permissions.push(name);
    else form.value.permissions.splice(idx, 1);
}

onMounted(() => {
    loadRoles();
    loadPermissions();
});
</script>

<template>
    <Head title="Kelola Role" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Kelola Role & Permission</h1>
                    <p class="text-gray-500 dark:text-gray-400 text-sm">Atur hak akses pengguna dalam sistem</p>
                </div>
                <button @click="openCreateForm" class="btn btn-primary">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Tambah Role
                </button>
            </div>

            <!-- Role List -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Loading State -->
                <div v-if="loading" class="col-span-full text-center py-10 text-gray-500">
                    Loading roles...
                </div>

                <!-- Role Card -->
                <div v-for="role in roles" :key="role.id" class="card p-6 flex flex-col justify-between hover:shadow-md transition-shadow dark:bg-dark-800">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white capitalize">{{ role.name }}</h3>
                            <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-2 py-1 rounded-full">
                                {{ role.permissions.length }} Permissions
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2 mb-4">
                            <span v-for="perm in role.permissions.slice(0, 5)" :key="perm.id" 
                                  class="text-xs px-2 py-0.5 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded border border-blue-100 dark:border-blue-800">
                                {{ perm.name }}
                            </span>
                            <span v-if="role.permissions.length > 5" class="text-xs text-gray-400">
                                +{{ role.permissions.length - 5 }} lainnya...
                            </span>
                            <span v-if="role.permissions.length === 0" class="text-xs text-gray-400 italic">
                                Belum ada permission
                            </span>
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-2 pt-4 border-t border-gray-100 dark:border-gray-700 mt-auto">
                        <button @click="openEditForm(role)" class="text-sm text-primary-600 hover:text-primary-800 font-medium p-2 hover:bg-primary-50 dark:hover:bg-primary-900/10 rounded">
                            Edit Akses
                        </button>
                        <button v-if="!['admin', 'owner'].includes(role.name)" @click="deleteRole(role)" class="text-sm text-red-600 hover:text-red-800 font-medium p-2 hover:bg-red-50 dark:hover:bg-red-900/10 rounded">
                            Hapus
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Modal -->
        <Teleport to="body">
            <div v-if="showForm" class="fixed inset-0 z-[100] overflow-y-auto">
                <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 transition-opacity" aria-hidden="true" @click="showForm = false">
                        <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                    </div>

                    <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                    <div class="inline-block align-bottom bg-white dark:bg-dark-900 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full relative z-10">
                        <div class="bg-white dark:bg-dark-900 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                            <div class="flex justify-between items-center mb-5">
                                <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white">
                                    {{ isEditing ? 'Edit Role: ' + form.name : 'Tambah Role Baru' }}
                                </h3>
                                <button @click="showForm = false" class="text-gray-400 hover:text-gray-500">
                                    <span class="text-2xl">&times;</span>
                                </button>
                            </div>

                            <div class="space-y-4">
                                <!-- Role Name -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nama Role</label>
                                    <input v-model="form.name" type="text" class="input w-full mt-1" placeholder="Contoh: staff_gudang" :disabled="isEditing && ['admin', 'owner'].includes(form.name)">
                                    <p v-if="isEditing && ['admin', 'owner'].includes(form.name)" class="text-xs text-yellow-600 mt-1">
                                        Nama role sistem tidak bisa diubah.
                                    </p>
                                </div>

                                <!-- Permissions Grid -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Permissions</label>
                                    <div class="h-96 overflow-y-auto custom-scrollbar border border-gray-200 dark:border-gray-700 rounded-md p-4">
                                        <div v-for="(perms, group) in groupedPermissions" :key="group" class="mb-6">
                                            <h4 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wider mb-2 border-b border-gray-100 dark:border-gray-800 pb-1">
                                                {{ group }}
                                            </h4>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                                <label v-for="perm in perms" :key="perm.id" class="flex items-center space-x-2 cursor-pointer p-2 hover:bg-gray-50 dark:hover:bg-dark-800 rounded">
                                                    <input type="checkbox" :checked="form.permissions.includes(perm.name)" @change="togglePermission(perm.name)" class="rounded text-primary-600 focus:ring-primary-500">
                                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ perm.name }}</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 dark:bg-dark-800 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                            <button @click="saveRole" :disabled="processing" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:ml-3 sm:w-auto sm:text-sm">
                                {{ processing ? 'Menyimpan...' : 'Simpan' }}
                            </button>
                            <button @click="showForm = false" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-dark-900 text-base font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-dark-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                                Batal
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>

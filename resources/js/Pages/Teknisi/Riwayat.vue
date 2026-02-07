<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    techName: { type: String, default: 'Teknisi' },
    techRole: { type: String, default: 'teknisi' },
    initialFilters: { type: Object, default: () => ({}) },
});

const history = ref([]);
const loading = ref(false);

const filters = ref({
    date_from: props.initialFilters.date_from || new Date(new Date().setDate(1)).toISOString().split('T')[0],
    date_to: props.initialFilters.date_to || new Date().toISOString().split('T')[0],
    status: props.initialFilters.status || 'Selesai',
});

const statusColors = {
    Selesai: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300',
    Batal: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300',
};

const API_BASE = '/api/v1';

async function loadHistory() {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            tech_name: props.techName,
            ...filters.value,
        });
        const res = await fetch(`${API_BASE}/teknisi/riwayat?${params}`);
        const data = await res.json();
        history.value = data.data || [];
    } catch (e) {
        console.error('Failed to load history:', e);
    } finally {
        loading.value = false;
    }
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount || 0);
}

const totalRevenue = computed(() => history.value.reduce((sum, item) => sum + (parseFloat(item.harga) || 0), 0));

onMounted(() => {
    loadHistory();
});

watch(filters, () => loadHistory(), { deep: true });
</script>

<template>
    <Head title="Riwayat Pekerjaan" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Riwayat Pekerjaan</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">History pekerjaan yang sudah selesai</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1 block">Dari Tanggal</label>
                        <input v-model="filters.date_from" type="date" class="input w-full" />
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1 block">Sampai Tanggal</label>
                        <input v-model="filters.date_to" type="date" class="input w-full" />
                    </div>
                    <div class="w-full sm:w-40">
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1 block">Status</label>
                        <select v-model="filters.status" class="input w-full">
                            <option value="">Semua</option>
                            <option value="Selesai">Selesai</option>
                            <option value="Batal">Batal</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button @click="loadHistory" class="btn btn-primary h-10">Refresh</button>
                    </div>
                </div>
            </div>

            <!-- Summary -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Total Job</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ history.length }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Total Revenue</p>
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(totalRevenue) }}</p>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="loading" class="flex justify-center py-12">
                <div class="animate-spin rounded-full h-10 w-10 border-4 border-primary-500 border-t-transparent"></div>
            </div>

            <!-- History List -->
            <div v-else-if="history.length > 0" class="space-y-3">
                <div 
                    v-for="item in history" 
                    :key="item.id" 
                    class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span :class="['px-2 py-0.5 rounded-full text-xs font-semibold', statusColors[item.status] || 'bg-gray-100 text-gray-800']">
                                    {{ item.status }}
                                </span>
                                <span v-if="item.pop" class="text-xs text-gray-500 dark:text-gray-400">{{ item.pop }}</span>
                            </div>
                            <h3 class="font-semibold text-gray-900 dark:text-white truncate">{{ item.nama }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ item.alamat }}</p>
                            <div class="flex items-center gap-4 mt-2 text-xs text-gray-500 dark:text-gray-400">
                                <span>📅 Selesai: {{ formatDate(item.tanggal_selesai) }}</span>
                                <span v-if="item.harga" class="text-green-600 dark:text-green-400 font-semibold">{{ formatCurrency(item.harga) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-16">
                <div class="w-20 h-20 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <h3 class="text-gray-600 dark:text-gray-300 font-semibold">Tidak ada riwayat</h3>
            </div>
        </div>
    </AdminLayout>
</template>

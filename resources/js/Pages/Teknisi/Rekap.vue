<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    techName: { type: String, default: 'Teknisi' },
    techPop: { type: String, default: '' },
    techRole: { type: String, default: 'teknisi' },
    initialDate: { type: String, default: new Date().toISOString().split('T')[0] },
});

const jobs = ref([]);
const expenses = ref([]);
const groups = ref([]);
const summary = ref({ total_jobs: 0, total_revenue: 0, total_expenses: 0 });
const loading = ref(false);

const selectedDate = ref(props.initialDate);
const showExpenseInput = ref(false);
const showGroupModal = ref(false);
const selectedGroupId = ref('');
const expenseInput = ref('');

const API_BASE = '/api/v1';

async function loadRekap() {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            tech_name: props.techName,
            date: selectedDate.value,
        });
        const res = await fetch(`${API_BASE}/teknisi/rekap?${params}`);
        const data = await res.json();
        if (data.success) {
            jobs.value = data.jobs || [];
            expenses.value = data.expenses || [];
            groups.value = data.groups || [];
            summary.value = data.summary || { total_jobs: 0, total_revenue: 0, total_expenses: 0 };
        }
    } catch (e) {
        console.error('Failed to load rekap:', e);
    } finally {
        loading.value = false;
    }
}

function toggleExpenseInput() {
    showExpenseInput.value = !showExpenseInput.value;
    if (!showExpenseInput.value && expenseInput.value) {
        parseAndSaveExpenses();
    }
}

async function parseAndSaveExpenses() {
    const lines = expenseInput.value.split('\n').filter(l => l.trim());
    const parsedExpenses = [];
    
    for (const line of lines) {
        const match = line.match(/(.+?)\s+(\d+)/);
        if (match) {
            parsedExpenses.push({
                name: match[1].trim(),
                amount: parseFloat(match[2]),
            });
        }
    }

    if (parsedExpenses.length === 0) return;

    try {
        const res = await fetch(`${API_BASE}/teknisi/expenses`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tech_name: props.techName,
                date: selectedDate.value,
                expenses: parsedExpenses,
            }),
        });
        const data = await res.json();
        if (data.success) {
            loadRekap();
        }
    } catch (e) {
        console.error('Failed to save expenses:', e);
    }
}

function openGroupModal() {
    showGroupModal.value = true;
}

function closeGroupModal() {
    showGroupModal.value = false;
    selectedGroupId.value = '';
}

async function sendRekapToGroup() {
    if (!selectedGroupId.value) {
        alert('Pilih grup terlebih dahulu');
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/teknisi/rekap/send`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                group_id: selectedGroupId.value,
                message: rekapPreview.value,
            }),
        });
        const data = await res.json();
        if (data.success) {
            alert('Rekap berhasil dikirim ke grup!');
            closeGroupModal();
        } else {
            alert(data.message || 'Gagal mengirim rekap');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function copyRekap() {
    navigator.clipboard.writeText(rekapPreview.value);
    alert('Rekap berhasil disalin!');
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount || 0);
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

const rekapPreview = computed(() => {
    let text = `📋 REKAP HARIAN - ${formatDate(selectedDate.value)}\n`;
    text += `Teknisi: ${props.techName}\n\n`;
    
    if (jobs.value.length > 0) {
        text += `✅ JOB SELESAI (${jobs.value.length}):\n`;
        jobs.value.forEach((job, idx) => {
            text += `${idx + 1}. ${job.nama} - ${job.alamat}\n`;
            if (job.harga) text += `   ${formatCurrency(job.harga)}\n`;
        });
        text += `\n💰 Total Revenue: ${formatCurrency(summary.value.total_revenue)}\n\n`;
    } else {
        text += `Tidak ada job selesai hari ini.\n\n`;
    }

    if (expenses.value.length > 0) {
        text += `💸 PENGELUARAN:\n`;
        expenses.value.forEach((exp, idx) => {
            text += `${idx + 1}. ${exp.name}: ${formatCurrency(exp.amount)}\n`;
        });
        text += `\n💸 Total Pengeluaran: ${formatCurrency(summary.value.total_expenses)}\n\n`;
    }

    text += `📊 NET: ${formatCurrency(summary.value.total_revenue - summary.value.total_expenses)}`;
    return text;
});

onMounted(() => {
    loadRekap();
});

watch(selectedDate, () => {
    loadRekap();
});
</script>

<template>
    <Head title="Rekap Harian" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Rekap Harian</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Selesai hari ini</p>
                </div>
                <button @click="loadRekap" class="btn btn-secondary flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refresh
                </button>
            </div>

            <!-- Date & Expense Toggle -->
            <div class="flex gap-3">
                <input v-model="selectedDate" type="date" class="input flex-1" />
                <button @click="toggleExpenseInput" class="btn btn-secondary">
                    {{ showExpenseInput ? 'Selesai' : 'Pengeluaran' }}
                </button>
            </div>

            <!-- Expense Input -->
            <div v-if="showExpenseInput" class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700 space-y-3">
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300 uppercase">Input Pengeluaran</h4>
                <textarea 
                    v-model="expenseInput" 
                    rows="4" 
                    class="input w-full font-mono text-sm" 
                    placeholder="Contoh:&#10;Makan 30000&#10;Rokok 30000&#10;Bensin 30000"
                ></textarea>
                <p class="text-xs text-gray-500 dark:text-gray-400">Tulis per baris atau dipisah koma.</p>
            </div>

            <!-- Loading -->
            <div v-if="loading" class="flex justify-center py-12">
                <div class="animate-spin rounded-full h-10 w-10 border-4 border-primary-500 border-t-transparent"></div>
            </div>

            <!-- Job List -->
            <div v-else class="space-y-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
                    <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300 uppercase mb-3">Job Selesai ({{ jobs.length }})</h4>
                    <div v-if="jobs.length > 0" class="space-y-2">
                        <div 
                            v-for="(job, idx) in jobs" 
                            :key="job.id" 
                            class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg"
                        >
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ idx + 1 }}.</span>
                                        <h5 class="font-semibold text-gray-900 dark:text-white">{{ job.nama }}</h5>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ job.alamat }}</p>
                                </div>
                                <span class="text-sm font-bold text-green-600 dark:text-green-400">{{ formatCurrency(job.harga) }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">Belum ada job selesai.</p>
                </div>

                <!-- Expenses -->
                <div v-if="expenses.length > 0" class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700">
                    <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300 uppercase mb-3">Pengeluaran</h4>
                    <div class="space-y-1">
                        <div v-for="(exp, idx) in expenses" :key="idx" class="flex items-center justify-between text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ idx + 1 }}. {{ exp.name }}</span>
                            <span class="font-semibold text-red-600 dark:text-red-400">{{ formatCurrency(exp.amount) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Preview -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm border border-gray-200 dark:border-gray-700 space-y-4">
                    <div class="flex items-center justify-between">
                        <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300">Preview Rekap</h4>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ jobs.length }} job</span>
                    </div>
                    <textarea :value="rekapPreview" readonly rows="12" class="input w-full font-mono text-sm bg-gray-50 dark:bg-gray-900"></textarea>
                    
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-3 gap-3">
                        <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <p class="text-xs text-blue-600 dark:text-blue-400 uppercase">Revenue</p>
                            <p class="text-sm font-bold text-blue-700 dark:text-blue-300">{{ formatCurrency(summary.total_revenue) }}</p>
                        </div>
                        <div class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                            <p class="text-xs text-red-600 dark:text-red-400 uppercase">Expense</p>
                            <p class="text-sm font-bold text-red-700 dark:text-red-300">{{ formatCurrency(summary.total_expenses) }}</p>
                        </div>
                        <div class="p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                            <p class="text-xs text-green-600 dark:text-green-400 uppercase">Net</p>
                            <p class="text-sm font-bold text-green-700 dark:text-green-300">{{ formatCurrency(summary.total_revenue - summary.total_expenses) }}</p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="grid grid-cols-2 gap-3">
                        <button @click="copyRekap" class="btn btn-secondary">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            Salin
                        </button>
                        <button @click="openGroupModal" class="btn btn-success">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            Kirim Laporan
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Group Modal -->
        <Teleport to="body">
            <div v-if="showGroupModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4" @click.self="closeGroupModal">
                <div class="bg-white dark:bg-gray-800 w-full max-w-md rounded-2xl shadow-2xl">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Kirim Laporan ke Grup</h3>
                        <button @click="closeGroupModal" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Pilih Grup WhatsApp</label>
                            <select v-model="selectedGroupId" class="input w-full">
                                <option value="">-- Pilih Grup --</option>
                                <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Isi Laporan</label>
                            <textarea :value="rekapPreview" readonly rows="10" class="input w-full font-mono text-sm bg-gray-50 dark:bg-gray-900"></textarea>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                        <button @click="closeGroupModal" class="btn btn-secondary">Batal</button>
                        <button @click="sendRekapToGroup" class="btn btn-success">Kirim</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>

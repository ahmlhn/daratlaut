<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

// State
const activeTab = ref('dashboard');
const loading = ref(false);

// Dashboard data
const dashboard = ref({
    total_balance: 0,
    pending_approvals: 0,
    posted_today: 0,
    revenue_this_month: 0,
    expense_this_month: 0,
    profit_this_month: 0,
    recent_transactions: [],
});

// COA data
const coaList = ref([]);
const coaFlat = ref([]);
const showCoaModal = ref(false);
const editingCoa = ref(null);
const coaForm = ref({
    code: '',
    name: '',
    category: 'asset',
    type: 'detail',
    parent_id: null,
    is_active: true,
});

// Transactions
const transactions = ref([]);
const txPagination = ref({ total: 0, per_page: 20, current_page: 1 });
const txFilters = ref({
    status: '',
    start_date: '',
    end_date: '',
    search: '',
});
const showTxModal = ref(false);
const editingTx = ref(null);
const txForm = ref({
    tx_date: new Date().toISOString().split('T')[0],
    ref_no: '',
    description: '',
    branch_id: null,
    party_name: '',
    method: '',
    lines: [],
});
const coaDropdown = ref([]);
const branches = ref([]);

// Approvals
const approvals = ref([]);
const selectedApprovals = ref([]);

// Reports
const reportType = ref('income');
const reportPeriod = ref({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
});
const reportData = ref(null);

// API base
const API_BASE = '/api/v1/finance';

// Format currency
function formatRp(value) {
    return new Intl.NumberFormat('id-ID').format(value || 0);
}

function extractDateParts(value) {
    if (value == null || value === '') return null;

    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return {
            year: String(value.getFullYear()),
            month: String(value.getMonth() + 1).padStart(2, '0'),
            day: String(value.getDate()).padStart(2, '0'),
        };
    }

    const text = String(value).trim();
    if (!text) return null;

    const ymd = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (ymd) {
        return { year: ymd[1], month: ymd[2], day: ymd[3] };
    }

    const parsed = new Date(text);
    if (!Number.isNaN(parsed.getTime())) {
        return {
            year: String(parsed.getFullYear()),
            month: String(parsed.getMonth() + 1).padStart(2, '0'),
            day: String(parsed.getDate()).padStart(2, '0'),
        };
    }

    return null;
}

function formatDateDisplay(value) {
    const parts = extractDateParts(value);
    if (!parts) return '-';
    return `${parts.day}/${parts.month}/${parts.year}`;
}

function normalizeDateForInput(value) {
    const parts = extractDateParts(value);
    if (!parts) return '';
    return `${parts.year}-${parts.month}-${parts.day}`;
}

// Load dashboard
async function loadDashboard() {
    loading.value = true;
    try {
        const res = await fetch(`${API_BASE}/dashboard`);
        const data = await res.json();
        if (data.status === 'ok') {
            dashboard.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load dashboard:', e);
    }
    loading.value = false;
}

// Load COA
async function loadCoa() {
    loading.value = true;
    try {
        const res = await fetch(`${API_BASE}/coa`);
        const data = await res.json();
        if (data.status === 'ok') {
            coaList.value = data.data;
            coaFlat.value = data.flat;
        }
    } catch (e) {
        console.error('Failed to load COA:', e);
    }
    loading.value = false;
}

// Load COA dropdown
async function loadCoaDropdown() {
    try {
        const res = await fetch(`${API_BASE}/coa/dropdown`);
        const data = await res.json();
        if (data.status === 'ok') {
            coaDropdown.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load COA dropdown:', e);
    }
}

// Load branches
async function loadBranches() {
    try {
        const res = await fetch(`${API_BASE}/branches`);
        const data = await res.json();
        if (data.status === 'ok') {
            branches.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load branches:', e);
    }
}

// Open COA modal
function openCoaModal(coa = null) {
    editingCoa.value = coa;
    if (coa) {
        coaForm.value = { ...coa };
    } else {
        coaForm.value = {
            code: '',
            name: '',
            category: 'asset',
            type: 'detail',
            parent_id: null,
            is_active: true,
        };
    }
    showCoaModal.value = true;
}

// Save COA
async function saveCoa() {
    try {
        const payload = { ...coaForm.value };
        if (editingCoa.value) {
            payload.id = editingCoa.value.id;
        }
        
        const res = await fetch(`${API_BASE}/coa`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        
        if (data.status === 'ok') {
            showCoaModal.value = false;
            await loadCoa();
        } else {
            alert(data.message || 'Gagal menyimpan');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Delete COA
async function deleteCoa(coa) {
    if (!confirm(`Hapus akun "${coa.code} - ${coa.name}"?`)) return;
    
    try {
        const res = await fetch(`${API_BASE}/coa/${coa.id}`, { method: 'DELETE' });
        const data = await res.json();
        
        if (data.status === 'ok') {
            await loadCoa();
        } else {
            alert(data.message || 'Gagal menghapus');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Load transactions
async function loadTransactions() {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (txFilters.value.status) params.append('status', txFilters.value.status);
        if (txFilters.value.start_date) params.append('start_date', txFilters.value.start_date);
        if (txFilters.value.end_date) params.append('end_date', txFilters.value.end_date);
        if (txFilters.value.search) params.append('search', txFilters.value.search);
        params.append('page', txPagination.value.current_page);
        
        const res = await fetch(`${API_BASE}/transactions?${params}`);
        const data = await res.json();
        
        if (data.status === 'ok') {
            transactions.value = data.data;
            txPagination.value = data.pagination;
        }
    } catch (e) {
        console.error('Failed to load transactions:', e);
    }
    loading.value = false;
}

// Open transaction modal
function openTxModal(tx = null) {
    editingTx.value = tx;
    if (tx) {
        txForm.value = {
            tx_date: normalizeDateForInput(tx.tx_date),
            ref_no: tx.ref_no || '',
            description: tx.description,
            branch_id: tx.branch_id,
            party_name: tx.party_name || '',
            method: tx.method || '',
            lines: tx.lines.map(l => ({
                coa_id: l.coa_id,
                description: l.description || '',
                debit: l.debit || 0,
                credit: l.credit || 0,
            })),
        };
    } else {
        txForm.value = {
            tx_date: new Date().toISOString().split('T')[0],
            ref_no: '',
            description: '',
            branch_id: null,
            party_name: '',
            method: '',
            lines: [
                { coa_id: null, description: '', debit: 0, credit: 0 },
                { coa_id: null, description: '', debit: 0, credit: 0 },
            ],
        };
    }
    showTxModal.value = true;
}

// Add transaction line
function addTxLine() {
    txForm.value.lines.push({ coa_id: null, description: '', debit: 0, credit: 0 });
}

// Remove transaction line
function removeTxLine(index) {
    if (txForm.value.lines.length > 2) {
        txForm.value.lines.splice(index, 1);
    }
}

// Calculate totals
const txTotalDebit = computed(() => txForm.value.lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0));
const txTotalCredit = computed(() => txForm.value.lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0));
const txIsBalanced = computed(() => Math.abs(txTotalDebit.value - txTotalCredit.value) < 0.01);

// Save transaction
async function saveTransaction() {
    if (!txIsBalanced.value) {
        alert('Transaksi tidak balance!');
        return;
    }
    
    try {
        const url = editingTx.value 
            ? `${API_BASE}/transactions/${editingTx.value.id}` 
            : `${API_BASE}/transactions`;
        const method = editingTx.value ? 'PUT' : 'POST';
        
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(txForm.value),
        });
        const data = await res.json();
        
        if (data.status === 'ok') {
            showTxModal.value = false;
            await loadTransactions();
        } else {
            alert(data.message || 'Gagal menyimpan');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Delete transaction
async function deleteTransaction(tx) {
    if (!confirm('Hapus transaksi ini?')) return;
    
    try {
        const res = await fetch(`${API_BASE}/transactions/${tx.id}`, { method: 'DELETE' });
        const data = await res.json();
        
        if (data.status === 'ok') {
            await loadTransactions();
        } else {
            alert(data.message || 'Gagal menghapus');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Load approvals
async function loadApprovals() {
    loading.value = true;
    try {
        const res = await fetch(`${API_BASE}/approvals`);
        const data = await res.json();
        if (data.status === 'ok') {
            approvals.value = data.data;
            selectedApprovals.value = [];
        }
    } catch (e) {
        console.error('Failed to load approvals:', e);
    }
    loading.value = false;
}

// Approve transaction
async function approveTransaction(tx) {
    if (!confirm('Approve transaksi ini?')) return;
    
    try {
        const res = await fetch(`${API_BASE}/transactions/${tx.id}/approve`, { method: 'POST' });
        const data = await res.json();
        
        if (data.status === 'ok') {
            await loadApprovals();
            await loadDashboard();
        } else {
            alert(data.message || 'Gagal approve');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Reject transaction
async function rejectTransaction(tx) {
    const reason = prompt('Alasan penolakan:');
    if (!reason) return;
    
    try {
        const res = await fetch(`${API_BASE}/transactions/${tx.id}/reject`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reason }),
        });
        const data = await res.json();
        
        if (data.status === 'ok') {
            await loadApprovals();
        } else {
            alert(data.message || 'Gagal reject');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Bulk approve
async function bulkApprove() {
    if (selectedApprovals.value.length === 0) {
        alert('Pilih transaksi yang akan di-approve');
        return;
    }
    if (!confirm(`Approve ${selectedApprovals.value.length} transaksi?`)) return;
    
    try {
        const res = await fetch(`${API_BASE}/transactions/bulk-approve`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: selectedApprovals.value }),
        });
        const data = await res.json();
        
        alert(data.message || 'Selesai');
        await loadApprovals();
        await loadDashboard();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Load report
async function loadReport() {
    loading.value = true;
    try {
        let endpoint = '';
        switch (reportType.value) {
            case 'income':
                endpoint = 'income-statement';
                break;
            case 'balance':
                endpoint = 'balance-sheet';
                break;
            case 'trial':
                endpoint = 'trial-balance';
                break;
        }
        
        const params = new URLSearchParams({
            start_date: reportPeriod.value.start_date,
            end_date: reportPeriod.value.end_date,
        });
        
        const res = await fetch(`${API_BASE}/reports/${endpoint}?${params}`);
        const data = await res.json();
        
        if (data.status === 'ok') {
            reportData.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load report:', e);
    }
    loading.value = false;
}

// Status badge
function statusClass(status) {
    return {
        'DRAFT': 'bg-red-100 text-red-800',
        'PENDING': 'bg-yellow-100 text-yellow-800',
        'POSTED': 'bg-green-100 text-green-800',
        'REJECTED': 'bg-gray-100 text-gray-800',
    }[status] || 'bg-gray-100 text-gray-800';
}

// Category badge
function categoryClass(category) {
    return {
        'asset': 'bg-blue-100 text-blue-800',
        'liability': 'bg-purple-100 text-purple-800',
        'equity': 'bg-indigo-100 text-indigo-800',
        'revenue': 'bg-green-100 text-green-800',
        'expense': 'bg-red-100 text-red-800',
    }[category] || 'bg-gray-100 text-gray-800';
}

// Watch tab changes
watch(activeTab, (tab) => {
    switch (tab) {
        case 'dashboard':
            loadDashboard();
            break;
        case 'coa':
            loadCoa();
            break;
        case 'transactions':
            loadTransactions();
            loadCoaDropdown();
            loadBranches();
            break;
        case 'approvals':
            loadApprovals();
            break;
        case 'reports':
            loadReport();
            break;
    }
});

// Init
onMounted(() => {
    loadDashboard();
});
</script>

<template>
    <Head title="Keuangan" />
    <AdminLayout>
        <div class="p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-800">Keuangan</h1>
                <p class="text-gray-600">Manajemen keuangan dan akuntansi</p>
            </div>

            <!-- Tabs -->
            <div class="border-b mb-6">
                <nav class="flex gap-4">
                    <button 
                        v-for="tab in [
                            { id: 'dashboard', label: 'Dashboard' },
                            { id: 'coa', label: 'Chart of Accounts' },
                            { id: 'transactions', label: 'Transaksi' },
                            { id: 'approvals', label: 'Approval' },
                            { id: 'reports', label: 'Laporan' },
                        ]"
                        :key="tab.id"
                        @click="activeTab = tab.id"
                        class="px-4 py-2 font-medium border-b-2 transition"
                        :class="activeTab === tab.id 
                            ? 'border-primary-500 text-primary-600' 
                            : 'border-transparent text-gray-500 hover:text-gray-700'"
                    >
                        {{ tab.label }}
                    </button>
                </nav>
            </div>

            <!-- Dashboard Tab -->
            <div v-if="activeTab === 'dashboard'">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="card p-4">
                        <div class="text-sm text-gray-500">Total Saldo</div>
                        <div class="text-xl font-bold">Rp {{ formatRp(dashboard.total_balance) }}</div>
                    </div>
                    <div class="card p-4">
                        <div class="text-sm text-gray-500">Pending Approval</div>
                        <div class="text-xl font-bold text-yellow-600">{{ dashboard.pending_approvals }}</div>
                    </div>
                    <div class="card p-4">
                        <div class="text-sm text-gray-500">Pendapatan Bulan Ini</div>
                        <div class="text-xl font-bold text-green-600">Rp {{ formatRp(dashboard.revenue_this_month) }}</div>
                    </div>
                    <div class="card p-4">
                        <div class="text-sm text-gray-500">Laba Bulan Ini</div>
                        <div class="text-xl font-bold" :class="dashboard.profit_this_month >= 0 ? 'text-green-600' : 'text-red-600'">
                            Rp {{ formatRp(dashboard.profit_this_month) }}
                        </div>
                    </div>
                </div>

                <div class="card p-4">
                    <h3 class="font-semibold mb-4">Transaksi Terbaru</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-2 text-left">Tanggal</th>
                                    <th class="px-3 py-2 text-left">Deskripsi</th>
                                    <th class="px-3 py-2 text-left">Status</th>
                                    <th class="px-3 py-2 text-right">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tx in dashboard.recent_transactions" :key="tx.id" class="border-b">
                                    <td class="px-3 py-2">{{ formatDateDisplay(tx.tx_date) }}</td>
                                    <td class="px-3 py-2">{{ tx.description }}</td>
                                    <td class="px-3 py-2">
                                        <span class="px-2 py-0.5 rounded text-xs" :class="statusClass(tx.status)">
                                            {{ tx.status }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right">Rp {{ formatRp(tx.total_debit) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- COA Tab -->
            <div v-if="activeTab === 'coa'">
                <div class="flex justify-end mb-4">
                    <button @click="openCoaModal()" class="btn btn-primary">+ Tambah Akun</button>
                </div>
                
                <div class="card p-4">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-3 py-2 text-left">Kode</th>
                                <th class="px-3 py-2 text-left">Nama Akun</th>
                                <th class="px-3 py-2 text-left">Kategori</th>
                                <th class="px-3 py-2 text-left">Tipe</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="coa in coaFlat" :key="coa.id" class="border-b hover:bg-gray-50">
                                <td class="px-3 py-2 font-mono">{{ coa.code }}</td>
                                <td class="px-3 py-2" :class="{ 'font-semibold': coa.type === 'header' }">
                                    {{ coa.name }}
                                </td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs" :class="categoryClass(coa.category)">
                                        {{ coa.category }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">{{ coa.type }}</td>
                                <td class="px-3 py-2">
                                    <span :class="coa.is_active ? 'text-green-600' : 'text-red-600'">
                                        {{ coa.is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <button @click="openCoaModal(coa)" class="text-blue-600 hover:underline mr-2">Edit</button>
                                    <button @click="deleteCoa(coa)" class="text-red-600 hover:underline">Hapus</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Transactions Tab -->
            <div v-if="activeTab === 'transactions'">
                <div class="flex flex-wrap gap-3 mb-4">
                    <input v-model="txFilters.start_date" type="date" lang="id-ID" class="input" />
                    <input v-model="txFilters.end_date" type="date" lang="id-ID" class="input" />
                    <select v-model="txFilters.status" class="input">
                        <option value="">Semua Status</option>
                        <option value="PENDING">PENDING</option>
                        <option value="POSTED">POSTED</option>
                        <option value="DRAFT">DRAFT</option>
                        <option value="REJECTED">REJECTED</option>
                    </select>
                    <input v-model="txFilters.search" type="text" placeholder="Cari..." class="input flex-1" />
                    <button @click="loadTransactions" class="btn bg-gray-100">Cari</button>
                    <button @click="openTxModal()" class="btn btn-primary">+ Tambah Transaksi</button>
                </div>
                
                <div class="card p-4">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-3 py-2 text-left">Tanggal</th>
                                <th class="px-3 py-2 text-left">Ref</th>
                                <th class="px-3 py-2 text-left">Deskripsi</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-right">Debit</th>
                                <th class="px-3 py-2 text-right">Credit</th>
                                <th class="px-3 py-2 text-left">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="7" class="text-center py-8 text-gray-500">Loading...</td>
                            </tr>
                            <tr v-else-if="transactions.length === 0">
                                <td colspan="7" class="text-center py-8 text-gray-500">Tidak ada data</td>
                            </tr>
                            <tr v-for="tx in transactions" :key="tx.id" class="border-b hover:bg-gray-50">
                                <td class="px-3 py-2">{{ formatDateDisplay(tx.tx_date) }}</td>
                                <td class="px-3 py-2">{{ tx.ref_no || '-' }}</td>
                                <td class="px-3 py-2">{{ tx.description }}</td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs" :class="statusClass(tx.status)">
                                        {{ tx.status }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right">{{ formatRp(tx.total_debit) }}</td>
                                <td class="px-3 py-2 text-right">{{ formatRp(tx.total_credit) }}</td>
                                <td class="px-3 py-2">
                                    <button @click="openTxModal(tx)" class="text-blue-600 hover:underline mr-2">Edit</button>
                                    <button v-if="tx.status !== 'POSTED'" @click="deleteTransaction(tx)" class="text-red-600 hover:underline">Hapus</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Approvals Tab -->
            <div v-if="activeTab === 'approvals'">
                <div class="flex justify-between mb-4">
                    <div class="text-sm text-gray-600">
                        {{ approvals.length }} transaksi menunggu approval
                    </div>
                    <button 
                        v-if="selectedApprovals.length > 0"
                        @click="bulkApprove" 
                        class="btn btn-primary"
                    >
                        Approve {{ selectedApprovals.length }} Terpilih
                    </button>
                </div>
                
                <div class="card p-4">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-3 py-2">
                                    <input 
                                        type="checkbox" 
                                        @change="selectedApprovals = $event.target.checked ? approvals.map(a => a.id) : []"
                                    />
                                </th>
                                <th class="px-3 py-2 text-left">Tanggal</th>
                                <th class="px-3 py-2 text-left">Deskripsi</th>
                                <th class="px-3 py-2 text-right">Jumlah</th>
                                <th class="px-3 py-2 text-left">Dibuat</th>
                                <th class="px-3 py-2 text-left">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="approvals.length === 0">
                                <td colspan="6" class="text-center py-8 text-gray-500">Tidak ada transaksi pending</td>
                            </tr>
                            <tr v-for="tx in approvals" :key="tx.id" class="border-b hover:bg-gray-50">
                                <td class="px-3 py-2">
                                    <input 
                                        type="checkbox" 
                                        :value="tx.id" 
                                        v-model="selectedApprovals"
                                    />
                                </td>
                                <td class="px-3 py-2">{{ formatDateDisplay(tx.tx_date) }}</td>
                                <td class="px-3 py-2">{{ tx.description }}</td>
                                <td class="px-3 py-2 text-right">Rp {{ formatRp(tx.total_debit) }}</td>
                                <td class="px-3 py-2">{{ tx.created_by }}</td>
                                <td class="px-3 py-2">
                                    <button @click="approveTransaction(tx)" class="text-xs px-2 py-1 bg-green-100 text-green-700 rounded mr-1">Approve</button>
                                    <button @click="rejectTransaction(tx)" class="text-xs px-2 py-1 bg-red-100 text-red-700 rounded">Reject</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Reports Tab -->
            <div v-if="activeTab === 'reports'">
                <div class="flex flex-wrap gap-3 mb-4">
                    <select v-model="reportType" class="input">
                        <option value="income">Laba Rugi</option>
                        <option value="balance">Neraca</option>
                        <option value="trial">Trial Balance</option>
                    </select>
                    <input v-model="reportPeriod.start_date" type="date" lang="id-ID" class="input" />
                    <input v-model="reportPeriod.end_date" type="date" lang="id-ID" class="input" />
                    <button @click="loadReport" class="btn btn-primary">Generate</button>
                </div>
                
                <div v-if="reportData" class="card p-4">
                    <!-- Income Statement -->
                    <div v-if="reportType === 'income'">
                        <h3 class="text-lg font-bold mb-4">Laporan Laba Rugi</h3>
                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold mb-2 text-green-700">Pendapatan</h4>
                                <div v-for="item in reportData.revenue.details" :key="item.code" class="flex justify-between py-1 border-b">
                                    <span>{{ item.code }} - {{ item.name }}</span>
                                    <span>Rp {{ formatRp(item.balance) }}</span>
                                </div>
                                <div class="flex justify-between py-2 font-bold border-t-2">
                                    <span>Total Pendapatan</span>
                                    <span>Rp {{ formatRp(reportData.revenue.total) }}</span>
                                </div>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-2 text-red-700">Beban</h4>
                                <div v-for="item in reportData.expense.details" :key="item.code" class="flex justify-between py-1 border-b">
                                    <span>{{ item.code }} - {{ item.name }}</span>
                                    <span>Rp {{ formatRp(item.balance) }}</span>
                                </div>
                                <div class="flex justify-between py-2 font-bold border-t-2">
                                    <span>Total Beban</span>
                                    <span>Rp {{ formatRp(reportData.expense.total) }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6 p-4 bg-gray-100 rounded-lg">
                            <div class="flex justify-between text-xl font-bold">
                                <span>Laba Bersih</span>
                                <span :class="reportData.net_income >= 0 ? 'text-green-600' : 'text-red-600'">
                                    Rp {{ formatRp(reportData.net_income) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Balance Sheet -->
                    <div v-if="reportType === 'balance'">
                        <h3 class="text-lg font-bold mb-4">Laporan Posisi Keuangan</h3>
                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold mb-2 text-blue-700">Aset</h4>
                                <div v-for="item in reportData.assets.details" :key="item.code" class="flex justify-between py-1 border-b">
                                    <span>{{ item.code }} - {{ item.name }}</span>
                                    <span>Rp {{ formatRp(item.balance) }}</span>
                                </div>
                                <div class="flex justify-between py-2 font-bold border-t-2">
                                    <span>Total Aset</span>
                                    <span>Rp {{ formatRp(reportData.assets.total) }}</span>
                                </div>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-2 text-purple-700">Kewajiban</h4>
                                <div v-for="item in reportData.liabilities.details" :key="item.code" class="flex justify-between py-1 border-b">
                                    <span>{{ item.code }} - {{ item.name }}</span>
                                    <span>Rp {{ formatRp(item.balance) }}</span>
                                </div>
                                <div class="flex justify-between py-2 font-bold border-t-2">
                                    <span>Total Kewajiban</span>
                                    <span>Rp {{ formatRp(reportData.liabilities.total) }}</span>
                                </div>

                                <h4 class="font-semibold mb-2 mt-4 text-indigo-700">Ekuitas</h4>
                                <div v-for="item in reportData.equity.details" :key="item.code" class="flex justify-between py-1 border-b">
                                    <span>{{ item.code }} - {{ item.name }}</span>
                                    <span>Rp {{ formatRp(item.balance) }}</span>
                                </div>
                                <div v-if="reportData.equity.retained_earnings" class="flex justify-between py-1 border-b">
                                    <span>Laba Ditahan</span>
                                    <span>Rp {{ formatRp(reportData.equity.retained_earnings) }}</span>
                                </div>
                                <div class="flex justify-between py-2 font-bold border-t-2">
                                    <span>Total Ekuitas</span>
                                    <span>Rp {{ formatRp(reportData.equity.total) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Trial Balance -->
                    <div v-if="reportType === 'trial'">
                        <h3 class="text-lg font-bold mb-4">Trial Balance</h3>
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-2 text-left">Kode</th>
                                    <th class="px-3 py-2 text-left">Nama Akun</th>
                                    <th class="px-3 py-2 text-right">Debit</th>
                                    <th class="px-3 py-2 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in reportData.report" :key="item.code" class="border-b">
                                    <td class="px-3 py-2 font-mono">{{ item.code }}</td>
                                    <td class="px-3 py-2">{{ item.name }}</td>
                                    <td class="px-3 py-2 text-right">{{ item.debit > 0 ? formatRp(item.debit) : '-' }}</td>
                                    <td class="px-3 py-2 text-right">{{ item.credit > 0 ? formatRp(item.credit) : '-' }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-gray-100 font-bold">
                                    <td class="px-3 py-2" colspan="2">Total</td>
                                    <td class="px-3 py-2 text-right">{{ formatRp(reportData.total_debit) }}</td>
                                    <td class="px-3 py-2 text-right">{{ formatRp(reportData.total_credit) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                        <div v-if="!reportData.is_balanced" class="mt-2 text-red-600 text-sm">
                            ⚠️ Trial balance tidak seimbang!
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- COA Modal -->
        <div v-if="showCoaModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
                <div class="p-4 border-b">
                    <h3 class="text-lg font-semibold">{{ editingCoa ? 'Edit Akun' : 'Tambah Akun' }}</h3>
                </div>
                <div class="p-4 space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Kode</label>
                            <input v-model="coaForm.code" type="text" class="input w-full" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Kategori</label>
                            <select v-model="coaForm.category" class="input w-full">
                                <option value="asset">Aset</option>
                                <option value="liability">Kewajiban</option>
                                <option value="equity">Ekuitas</option>
                                <option value="revenue">Pendapatan</option>
                                <option value="expense">Beban</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Nama Akun</label>
                        <input v-model="coaForm.name" type="text" class="input w-full" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Tipe</label>
                            <select v-model="coaForm.type" class="input w-full">
                                <option value="header">Header</option>
                                <option value="detail">Detail</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Parent</label>
                            <select v-model="coaForm.parent_id" class="input w-full">
                                <option :value="null">- Tidak Ada -</option>
                                <option v-for="c in coaFlat.filter(x => x.type === 'header')" :key="c.id" :value="c.id">
                                    {{ c.code }} - {{ c.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" v-model="coaForm.is_active" />
                        <span class="text-sm">Aktif</span>
                    </label>
                </div>
                <div class="p-4 border-t flex justify-end gap-3">
                    <button @click="showCoaModal = false" class="btn bg-gray-100">Batal</button>
                    <button @click="saveCoa" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </div>

        <!-- Transaction Modal -->
        <div v-if="showTxModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto">
                <div class="p-4 border-b">
                    <h3 class="text-lg font-semibold">{{ editingTx ? 'Edit Transaksi' : 'Tambah Transaksi' }}</h3>
                </div>
                <div class="p-4 space-y-4">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Tanggal</label>
                            <input v-model="txForm.tx_date" type="date" lang="id-ID" class="input w-full" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">No. Ref</label>
                            <input v-model="txForm.ref_no" type="text" class="input w-full" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Cabang</label>
                            <select v-model="txForm.branch_id" class="input w-full">
                                <option :value="null">- Pilih -</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Metode</label>
                            <input v-model="txForm.method" type="text" class="input w-full" placeholder="Transfer/Cash" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1">Deskripsi</label>
                            <input v-model="txForm.description" type="text" class="input w-full" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Pihak Terkait</label>
                            <input v-model="txForm.party_name" type="text" class="input w-full" />
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-sm font-medium">Detail Jurnal</label>
                            <button @click="addTxLine" type="button" class="text-sm text-blue-600 hover:underline">+ Tambah Baris</button>
                        </div>
                        <div class="border rounded-lg overflow-hidden">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-2 py-2 text-left">Akun</th>
                                        <th class="px-2 py-2 text-left">Keterangan</th>
                                        <th class="px-2 py-2 text-right w-32">Debit</th>
                                        <th class="px-2 py-2 text-right w-32">Credit</th>
                                        <th class="px-2 py-2 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(line, idx) in txForm.lines" :key="idx" class="border-t">
                                        <td class="px-2 py-1">
                                            <select v-model="line.coa_id" class="input w-full text-sm">
                                                <option :value="null">Pilih Akun</option>
                                                <option v-for="c in coaDropdown" :key="c.id" :value="c.id">
                                                    {{ c.code }} - {{ c.name }}
                                                </option>
                                            </select>
                                        </td>
                                        <td class="px-2 py-1">
                                            <input v-model="line.description" type="text" class="input w-full text-sm" />
                                        </td>
                                        <td class="px-2 py-1">
                                            <input v-model.number="line.debit" type="number" class="input w-full text-sm text-right" min="0" />
                                        </td>
                                        <td class="px-2 py-1">
                                            <input v-model.number="line.credit" type="number" class="input w-full text-sm text-right" min="0" />
                                        </td>
                                        <td class="px-2 py-1">
                                            <button @click="removeTxLine(idx)" type="button" class="text-red-600 hover:text-red-800">×</button>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="bg-gray-50 font-medium">
                                    <tr>
                                        <td colspan="2" class="px-2 py-2 text-right">Total:</td>
                                        <td class="px-2 py-2 text-right">{{ formatRp(txTotalDebit) }}</td>
                                        <td class="px-2 py-2 text-right">{{ formatRp(txTotalCredit) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div v-if="!txIsBalanced" class="mt-2 text-red-600 text-sm">
                            ⚠️ Debit dan Credit harus sama!
                        </div>
                    </div>
                </div>
                <div class="p-4 border-t flex justify-end gap-3">
                    <button @click="showTxModal = false" class="btn bg-gray-100">Batal</button>
                    <button @click="saveTransaction" class="btn btn-primary" :disabled="!txIsBalanced">Simpan</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

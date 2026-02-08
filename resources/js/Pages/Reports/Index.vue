<script setup>
import { ref, onMounted, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

// State
const loading = ref(false);
const activeTab = ref('overview');
const period = ref({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
});

// Data
const summary = ref(null);
const revenueChart = ref([]);
const customerGrowth = ref([]);
const installationStats = ref(null);
const paymentMethods = ref([]);
const planPopularity = ref([]);
const topCustomers = ref([]);

const API_BASE = '/api/v1/reports';

// Format currency
function formatRp(value) {
    return new Intl.NumberFormat('id-ID').format(value || 0);
}

// Load summary
async function loadSummary() {
    try {
        const params = new URLSearchParams({
            start_date: period.value.start_date,
            end_date: period.value.end_date,
        });
        const res = await fetch(`${API_BASE}/summary?${params}`);
        const data = await res.json();
        if (data.status === 'ok') {
            summary.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load summary:', e);
    }
}

// Load revenue chart
async function loadRevenueChart() {
    try {
        const res = await fetch(`${API_BASE}/revenue-chart?months=12`);
        const data = await res.json();
        if (data.status === 'ok') {
            revenueChart.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load revenue chart:', e);
    }
}

// Load customer growth
async function loadCustomerGrowth() {
    try {
        const res = await fetch(`${API_BASE}/customer-growth?months=12`);
        const data = await res.json();
        if (data.status === 'ok') {
            customerGrowth.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load customer growth:', e);
    }
}

// Load installation stats
async function loadInstallationStats() {
    try {
        const params = new URLSearchParams({
            start_date: period.value.start_date,
            end_date: period.value.end_date,
        });
        const res = await fetch(`${API_BASE}/installation-stats?${params}`);
        const data = await res.json();
        if (data.status === 'ok') {
            installationStats.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load installation stats:', e);
    }
}

// Load payment methods
async function loadPaymentMethods() {
    try {
        const params = new URLSearchParams({
            start_date: period.value.start_date,
            end_date: period.value.end_date,
        });
        const res = await fetch(`${API_BASE}/payment-methods?${params}`);
        const data = await res.json();
        if (data.status === 'ok') {
            paymentMethods.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load payment methods:', e);
    }
}

// Load plan popularity
async function loadPlanPopularity() {
    try {
        const res = await fetch(`${API_BASE}/plan-popularity`);
        const data = await res.json();
        if (data.status === 'ok') {
            planPopularity.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load plan popularity:', e);
    }
}

// Load top customers
async function loadTopCustomers() {
    try {
        const res = await fetch(`${API_BASE}/top-customers?limit=10`);
        const data = await res.json();
        if (data.status === 'ok') {
            topCustomers.value = data.data;
        }
    } catch (e) {
        console.error('Failed to load top customers:', e);
    }
}

// Load all data
async function loadAllData() {
    loading.value = true;
    await Promise.all([
        loadSummary(),
        loadRevenueChart(),
        loadCustomerGrowth(),
        loadInstallationStats(),
        loadPaymentMethods(),
        loadPlanPopularity(),
        loadTopCustomers(),
    ]);
    loading.value = false;
}

// Export CSV
function exportCsv(type) {
    const params = new URLSearchParams({
        type,
        start_date: period.value.start_date,
        end_date: period.value.end_date,
    });
    window.open(`${API_BASE}/export?${params}`, '_blank');
}

// Max revenue for chart scaling
const maxRevenue = computed(() => {
    return Math.max(...revenueChart.value.map(d => d.revenue), 1);
});

// Max customer for chart scaling
const maxCustomer = computed(() => {
    return Math.max(...customerGrowth.value.map(d => d.count), 1);
});

// Init
onMounted(() => {
    loadAllData();
});
</script>

<template>
    <Head title="Laporan & Analitik" />
    <AdminLayout>
        <div class="p-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Laporan & Analitik</h1>
                    <p class="text-gray-600">Statistik dan analisis bisnis</p>
                </div>
                <div class="flex gap-3">
                    <input v-model="period.start_date" type="date" class="input" />
                    <input v-model="period.end_date" type="date" class="input" />
                    <button @click="loadAllData" class="btn btn-primary">Refresh</button>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="loading" class="flex items-center justify-center py-20">
                <div class="text-gray-500">Loading...</div>
            </div>

            <!-- Content -->
            <div v-else>
                <!-- Summary Cards -->
                <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="card p-4">
                        <div class="text-sm text-gray-500">Total Pelanggan</div>
                        <div class="text-2xl font-bold">{{ summary.customers.total }}</div>
                        <div class="text-xs text-green-600">{{ summary.customers.active }} aktif</div>
                    </div>
                    <div class="card p-4">
                        <div class="text-sm text-gray-500">Total Pendapatan</div>
                        <div class="text-2xl font-bold text-green-600">Rp {{ formatRp(summary.revenue.total) }}</div>
                        <div class="text-xs text-gray-500">{{ summary.revenue.paid_invoices }} invoice lunas</div>
                    </div>
                    <div class="card p-4">
                        <div class="text-sm text-gray-500">Invoice Pending</div>
                        <div class="text-2xl font-bold text-yellow-600">{{ summary.revenue.open_invoices }}</div>
                        <div class="text-xs text-red-600">{{ summary.revenue.overdue_invoices }} overdue</div>
                    </div>
                    <div class="card p-4">
                        <div class="text-sm text-gray-500">Pasang Baru</div>
                        <div class="text-2xl font-bold">{{ summary.installations.completed }}</div>
                        <div class="text-xs text-gray-500">{{ summary.installations.pending }} pending</div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid md:grid-cols-2 gap-6 mb-6">
                    <!-- Revenue Chart -->
                    <div class="card p-4">
                        <h3 class="font-semibold mb-4">Pendapatan 12 Bulan Terakhir</h3>
                        <div class="h-48 flex items-end gap-1">
                            <div 
                                v-for="(item, idx) in revenueChart" 
                                :key="idx"
                                class="flex-1 flex flex-col items-center"
                            >
                                <div 
                                    class="w-full bg-primary-500 rounded-t transition-all"
                                    :style="{ height: (item.revenue / maxRevenue * 100) + '%', minHeight: '4px' }"
                                ></div>
                                <div class="text-xs text-gray-500 mt-1 rotate-[-45deg] origin-top-left whitespace-nowrap">
                                    {{ item.month.split(' ')[0] }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Growth Chart -->
                    <div class="card p-4">
                        <h3 class="font-semibold mb-4">Pertumbuhan Pelanggan</h3>
                        <div class="h-48 flex items-end gap-1">
                            <div 
                                v-for="(item, idx) in customerGrowth" 
                                :key="idx"
                                class="flex-1 flex flex-col items-center"
                            >
                                <div 
                                    class="w-full bg-green-500 rounded-t transition-all"
                                    :style="{ height: (item.count / maxCustomer * 100) + '%', minHeight: '4px' }"
                                ></div>
                                <div class="text-xs text-gray-500 mt-1 rotate-[-45deg] origin-top-left whitespace-nowrap">
                                    {{ item.month.split(' ')[0] }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Tables Row -->
                <div class="grid md:grid-cols-2 gap-6 mb-6">
                    <!-- Payment Methods -->
                    <div class="card p-4">
                        <h3 class="font-semibold mb-4">Metode Pembayaran</h3>
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left">Metode</th>
                                    <th class="px-3 py-2 text-right">Jumlah</th>
                                    <th class="px-3 py-2 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in paymentMethods" :key="item.payment_method" class="border-b">
                                    <td class="px-3 py-2">{{ item.payment_method || 'Lainnya' }}</td>
                                    <td class="px-3 py-2 text-right">{{ item.count }}</td>
                                    <td class="px-3 py-2 text-right">Rp {{ formatRp(item.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Plan Popularity -->
                    <div class="card p-4">
                        <h3 class="font-semibold mb-4">Paket Terpopuler</h3>
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left">Paket</th>
                                    <th class="px-3 py-2 text-right">Pelanggan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in planPopularity" :key="item.name" class="border-b">
                                    <td class="px-3 py-2">{{ item.name }}</td>
                                    <td class="px-3 py-2 text-right">{{ item.count }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Customers -->
                <div class="card p-4 mb-6">
                    <h3 class="font-semibold mb-4">Top 10 Pelanggan (Total Pembayaran)</h3>
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left">#</th>
                                <th class="px-3 py-2 text-left">Nama</th>
                                <th class="px-3 py-2 text-right">Total Bayar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, idx) in topCustomers" :key="item.id" class="border-b">
                                <td class="px-3 py-2">{{ idx + 1 }}</td>
                                <td class="px-3 py-2">{{ item.name }}</td>
                                <td class="px-3 py-2 text-right font-medium">Rp {{ formatRp(item.total_paid) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Installation Stats -->
                <div v-if="installationStats" class="grid md:grid-cols-2 gap-6 mb-6">
                    <div class="card p-4">
                        <h3 class="font-semibold mb-4">Status Instalasi</h3>
                        <div class="space-y-2">
                            <div v-for="(count, status) in installationStats.by_status" :key="status" class="flex justify-between py-2 border-b">
                                <span>{{ status }}</span>
                                <span class="font-medium">{{ count }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="card p-4">
                        <h3 class="font-semibold mb-4">Instalasi per POP (Top 10)</h3>
                        <div class="space-y-2">
                            <div v-for="item in installationStats.by_pop" :key="item.pop" class="flex justify-between py-2 border-b">
                                <span>{{ item.pop || 'Tidak ada POP' }}</span>
                                <span class="font-medium">{{ item.count }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Export Buttons -->
                <div class="card p-4">
                    <h3 class="font-semibold mb-4">Export Data</h3>
                    <div class="flex flex-wrap gap-3">
                        <button @click="exportCsv('payments')" class="btn bg-green-100 text-green-700 hover:bg-green-200">
                            📊 Export Pembayaran (CSV)
                        </button>
                        <button @click="exportCsv('customers')" class="btn bg-blue-100 text-blue-700 hover:bg-blue-200">
                            👥 Export Pelanggan (CSV)
                        </button>
                        <button @click="exportCsv('invoices')" class="btn bg-purple-100 text-purple-700 hover:bg-purple-200">
                            📄 Export Invoice (CSV)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

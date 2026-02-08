<script setup>
import { ref, computed, onMounted, inject, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import LoadingSpinner from '@/Components/LoadingSpinner.vue'

const props = defineProps({
  tenantId: String,
})

const toast = inject('toast')
const dashboard = ref({
  customers: { total: 0, active: 0, suspended: 0 },
  invoices: { open: 0, overdue: 0, paid: 0 },
  payments: { total_this_month: 0, count_this_month: 0, change_percent: 0 },
  revenue_chart: { labels: [], data: [] },
  activity: [],
})
const loading = ref(true)
const chartCanvas = ref(null)

const stats = computed(() => [
  {
    label: 'Total Pelanggan',
    value: dashboard.value.customers?.total || 0,
    icon: 'users',
  },
  {
    label: 'Pelanggan Aktif',
    value: dashboard.value.customers?.active || 0,
    icon: 'check',
  },
  {
    label: 'Pelanggan Suspend',
    value: dashboard.value.customers?.suspended || 0,
    icon: 'pause',
  },
  {
    label: 'Invoice Terbuka',
    value: dashboard.value.invoices?.open || 0,
    icon: 'document',
  },
])

async function loadDashboard() {
  loading.value = true
  try {
    const response = await axios.get('/api/v1/dashboard', {
      headers: { 'X-Tenant-ID': props.tenantId },
    })
    // Ensure data structure with defaults
    dashboard.value = {
      customers: response.data.customers || { total: 0, active: 0, suspended: 0 },
      invoices: response.data.invoices || { open: 0, overdue: 0, paid: 0 },
      payments: response.data.payments || { total_this_month: 0, count_this_month: 0, change_percent: 0 },
      revenue_chart: response.data.revenue_chart || { labels: [], data: [] },
      activity: response.data.activity || [],
    }
    if (chartCanvas.value) {
      renderChart()
    }
    // Show success toast on first load
    if (toast) {
      setTimeout(() => {
        toast.success('Dashboard loaded successfully!')
      }, 500)
    }
  } catch (error) {
    console.error('Failed to load dashboard:', error)
    if (toast) {
      toast.error('Failed to load dashboard data')
    }
  } finally {
    loading.value = false
  }
}

async function renderChart() {
  // Check if chart data is available
  if (!dashboard.value.revenue_chart?.labels?.length) {
    return
  }
  
  // Lazy load Chart.js only when needed
  const Chart = (await import('chart.js/auto')).default
  
  // Destroy existing chart if any
  if (chartCanvas.value && chartCanvas.value.chart) {
    chartCanvas.value.chart.destroy()
  }
  
  const chart = new Chart(chartCanvas.value, {
    type: 'bar',
    data: {
      labels: dashboard.value.revenue_chart.labels || [],
      datasets: [
        {
          label: 'Pendapatan',
          data: dashboard.value.revenue_chart.data || [],
          backgroundColor: 'rgba(59, 130, 246, 0.8)', // Blue gradient
          borderColor: 'rgb(59, 130, 246)',
          borderWidth: 2,
          borderRadius: 8,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgb(15, 23, 42)',
          titleColor: '#fff',
          bodyColor: '#fff',
          padding: 12,
          borderRadius: 8,
          displayColors: false,
          callbacks: {
            label: function (context) {
              return 'Rp ' + context.parsed.y.toLocaleString('id-ID')
            },
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(0, 0, 0, 0.05)' },
          ticks: {
            callback: function (value) {
              return 'Rp ' + (value / 1000).toFixed(0) + 'k'
            },
          },
        },
        x: {
          grid: { display: false },
        },
      },
    },
  })
  
  // Store chart instance for cleanup
  chartCanvas.value.chart = chart
}

function formatFullCurrency(amount) {
  return 'Rp ' + parseInt(amount || 0).toLocaleString('id-ID')
}

function getActionClass(action) {
  return {
    CREATE: 'bg-success-100 text-success-800 dark:bg-success-900/30 dark:text-success-300',
    UPDATE: 'bg-primary-100 text-primary-800 dark:bg-primary-900/30 dark:text-primary-300',
    DELETE: 'bg-danger-100 text-danger-800 dark:bg-danger-900/30 dark:text-danger-300',
    PAYMENT: 'bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-300',
  }[action] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
}

function getActionIcon(action) {
  return {
    CREATE: 'plus',
    UPDATE: 'pencil',
    DELETE: 'trash',
    PAYMENT: 'cash',
    SUSPEND: 'pause',
    ACTIVATE: 'play',
  }[action] || 'info'
}

onMounted(() => {
  loadDashboard()
})

onUnmounted(() => {
  // Cleanup chart on unmount
  if (chartCanvas.value?.chart) {
    chartCanvas.value.chart.destroy()
  }
})
</script>

<template>
  <Head title="Dashboard" />
  <AdminLayout>
    <div class="space-y-6">
      <!-- Page header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Dashboard</h1>
          <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            Overview billing dan pelanggan
          </p>
        </div>
        <div class="rounded-xl bg-white px-4 py-2 text-sm font-medium text-gray-600 shadow-card dark:bg-dark-800 dark:text-gray-300">
          {{ new Date().toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) }}
        </div>
      </div>

      <!-- Stats cards -->
      <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <div
          v-for="stat in stats"
          :key="stat.label"
          class="group relative overflow-hidden rounded-xl bg-white p-6 shadow-card transition-all duration-300 hover:shadow-card-hover hover:-translate-y-1 dark:bg-dark-800 dark:shadow-glow-blue/10 dark:hover:shadow-glow-blue/20"
          role="article"
          :aria-label="`${stat.label}: ${stat.value}`"
        >
          <!-- Gradient background overlay -->
          <div 
            class="absolute inset-0 opacity-0 transition-opacity duration-300 group-hover:opacity-100"
            :class="{
              'bg-gradient-to-br from-primary-500/5 to-primary-600/5': stat.icon === 'users',
              'bg-gradient-to-br from-success-500/5 to-success-600/5': stat.icon === 'check',
              'bg-gradient-to-br from-danger-500/5 to-danger-600/5': stat.icon === 'pause',
              'bg-gradient-to-br from-warning-500/5 to-warning-600/5': stat.icon === 'document'
            }"
          ></div>
          
          <div class="relative flex items-center justify-between">
            <div class="flex-1">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ stat.label }}</p>
              <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white">
                {{ loading ? '...' : stat.value }}
              </p>
            </div>
            <div 
              class="flex h-14 w-14 items-center justify-center rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:shadow-xl"
              :class="{
                'bg-gradient-to-br from-primary-500 to-primary-600 shadow-primary-500/30': stat.icon === 'users',
                'bg-gradient-to-br from-success-500 to-success-600 shadow-success-500/30': stat.icon === 'check',
                'bg-gradient-to-br from-danger-500 to-danger-600 shadow-danger-500/30': stat.icon === 'pause',
                'bg-gradient-to-br from-warning-500 to-warning-600 shadow-warning-500/30': stat.icon === 'document'
              }"
            >
              <!-- Users icon -->
              <svg v-if="stat.icon === 'users'" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
              <!-- Check icon -->
              <svg v-else-if="stat.icon === 'check'" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <!-- Pause icon -->
              <svg v-else-if="stat.icon === 'pause'" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <!-- Document icon -->
              <svg v-else class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment summary card -->
      <div class="group relative overflow-hidden rounded-xl bg-gradient-to-br from-primary-500 via-primary-600 to-primary-700 p-8 shadow-glow-blue transition-all duration-300 hover:shadow-glow-blue-lg hover:-translate-y-1">
        <!-- Animated gradient overlay -->
        <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100"></div>
        
        <!-- Decorative circles -->
        <div class="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-white/10 blur-2xl"></div>
        <div class="absolute -bottom-8 -left-8 h-32 w-32 rounded-full bg-white/10 blur-2xl"></div>
        
        <div class="relative flex items-center justify-between">
          <div class="flex-1">
            <p class="text-sm font-semibold uppercase tracking-wider text-primary-100">Pendapatan Bulan Ini</p>
            <p class="mt-3 text-4xl font-bold text-white">
              {{ loading ? '...' : formatFullCurrency(dashboard.payments.total_this_month) }}
            </p>
            <p class="mt-3 text-sm font-medium text-primary-100">
              <span class="inline-flex items-center gap-1">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ dashboard.payments.count_this_month }} pembayaran
              </span>
              <span v-if="dashboard.payments.change_percent !== 0" class="ml-3 inline-flex items-center gap-1 rounded-full bg-white/20 px-2.5 py-0.5">
                <svg v-if="dashboard.payments.change_percent > 0" class="h-3.5 w-3.5 text-green-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
                <svg v-else class="h-3.5 w-3.5 text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" />
                </svg>
                <span :class="dashboard.payments.change_percent > 0 ? 'text-green-200' : 'text-red-200'">
                  {{ dashboard.payments.change_percent > 0 ? '+' : '' }}{{ dashboard.payments.change_percent }}%
                </span>
                <span class="text-primary-200">vs bulan lalu</span>
              </span>
            </p>
          </div>
          <div class="flex h-20 w-20 items-center justify-center rounded-2xl bg-white/20 shadow-lg backdrop-blur-sm transition-all duration-300 group-hover:scale-110 group-hover:bg-white/30 group-hover:shadow-xl">
            <svg class="h-10 w-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Chart + Activity -->
      <div class="grid gap-6 lg:grid-cols-2">
        <!-- Chart -->
        <div class="glass-card group overflow-hidden rounded-xl bg-white p-6 shadow-card transition-all duration-300 hover:shadow-card-hover dark:bg-dark-800">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-primary-500 to-primary-600 shadow-lg shadow-primary-500/30">
                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
              </div>
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Pendapatan 6 Bulan Terakhir</h2>
            </div>
          </div>
          <div class="mt-6 h-64">
            <canvas ref="chartCanvas"></canvas>
          </div>
        </div>

        <!-- Recent activity -->
        <div class="glass-card overflow-hidden rounded-xl bg-white p-6 shadow-card transition-all duration-300 hover:shadow-card-hover dark:bg-dark-800">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-success-500 to-success-600 shadow-lg shadow-success-500/30">
                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Aktivitas Terbaru</h2>
            </div>
            <a href="#" class="text-sm font-medium text-primary-600 transition-colors hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">
              Lihat semua →
            </a>
          </div>
          <div class="custom-scrollbar mt-6 max-h-64 space-y-3 overflow-y-auto pr-2">
            <template v-if="loading">
              <div class="animate-pulse space-y-3">
                <div v-for="i in 5" :key="i" class="flex items-start gap-3">
                  <div class="h-10 w-10 rounded-xl bg-gray-200 dark:bg-dark-700"></div>
                  <div class="flex-1">
                    <div class="h-4 w-3/4 rounded-lg bg-gray-200 dark:bg-dark-700"></div>
                    <div class="mt-2 h-3 w-1/4 rounded-lg bg-gray-200 dark:bg-dark-700"></div>
                  </div>
                </div>
              </div>
            </template>
            <template v-else-if="dashboard.activity.length === 0">
              <div class="py-12 text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-100 dark:bg-dark-700">
                  <svg class="h-8 w-8 text-gray-400 dark:text-dark-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                  </svg>
                </div>
                <p class="mt-3 text-sm font-medium text-gray-500 dark:text-dark-400">Belum ada aktivitas</p>
              </div>
            </template>
            <template v-else>
              <div
                v-for="log in dashboard.activity"
                :key="log.id"
                class="group flex items-start gap-3 rounded-lg p-3 transition-all duration-200 hover:bg-gray-50 dark:hover:bg-dark-700/50"
              >
                <div
                  :class="[
                    'flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl shadow-lg transition-all duration-300 group-hover:scale-110',
                    {
                      'bg-gradient-to-br from-success-500 to-success-600 shadow-success-500/30': getActionIcon(log.action) === 'plus',
                      'bg-gradient-to-br from-primary-500 to-primary-600 shadow-primary-500/30': getActionIcon(log.action) === 'pencil',
                      'bg-gradient-to-br from-warning-500 to-warning-600 shadow-warning-500/30': getActionIcon(log.action) === 'cash',
                      'bg-gradient-to-br from-gray-500 to-gray-600 shadow-gray-500/30': !['plus', 'pencil', 'cash'].includes(getActionIcon(log.action))
                    }
                  ]"
                >
                  <!-- Plus icon -->
                  <svg v-if="getActionIcon(log.action) === 'plus'" class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                  </svg>
                  <!-- Pencil icon -->
                  <svg v-else-if="getActionIcon(log.action) === 'pencil'" class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                  </svg>
                  <!-- Cash icon -->
                  <svg v-else-if="getActionIcon(log.action) === 'cash'" class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                  </svg>
                  <!-- Default icon -->
                  <svg v-else class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="text-sm text-gray-900 dark:text-white">
                    <span :class="getActionClass(log.action)" class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-semibold">
                      {{ log.action }}
                    </span>
                    <span class="ml-2 font-medium">{{ log.description }}</span>
                  </p>
                  <p class="mt-1 flex items-center gap-2 text-xs text-gray-500 dark:text-dark-400">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    {{ log.actor }}
                    <span class="text-gray-400 dark:text-dark-500">•</span>
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ log.time_ago }}
                  </p>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- Quick actions -->
      <div class="overflow-hidden rounded-xl bg-white p-6 shadow-card dark:bg-dark-800">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-primary-500 to-primary-600 shadow-lg shadow-primary-500/30">
            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
          </div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Aksi Cepat</h2>
        </div>
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <a 
            href="/customers" 
            class="btn-press group flex items-center justify-start gap-3 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 px-4 py-3.5 font-semibold text-white shadow-lg shadow-primary-500/30 transition-all duration-300 hover:scale-105 hover:shadow-xl hover:shadow-primary-500/40"
            aria-label="Tambah pelanggan baru"
          >
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/20">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
              </svg>
            </div>
            <span>Tambah Pelanggan</span>
          </a>
          <a 
            href="/invoices" 
            class="btn-press group flex items-center justify-start gap-3 rounded-xl border-2 border-gray-200 bg-white px-4 py-3.5 font-semibold text-gray-700 transition-all duration-300 hover:scale-105 hover:border-primary-500 hover:bg-primary-50 hover:text-primary-700 hover:shadow-lg dark:border-dark-700 dark:bg-dark-800 dark:text-gray-200 dark:hover:border-primary-500 dark:hover:bg-dark-700"
            aria-label="Buat invoice baru"
          >
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 transition-colors group-hover:bg-primary-100 dark:bg-dark-700 dark:group-hover:bg-primary-900/30">
              <svg class="h-4 w-4 transition-colors group-hover:text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <span>Buat Invoice</span>
          </a>
          <a 
            href="/payments" 
            class="btn-press group flex items-center justify-start gap-3 rounded-xl border-2 border-gray-200 bg-white px-4 py-3.5 font-semibold text-gray-700 transition-all duration-300 hover:scale-105 hover:border-success-500 hover:bg-success-50 hover:text-success-700 hover:shadow-lg dark:border-dark-700 dark:bg-dark-800 dark:text-gray-200 dark:hover:border-success-500 dark:hover:bg-dark-700"
            aria-label="Catat pembayaran baru"
          >
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 transition-colors group-hover:bg-success-100 dark:bg-dark-700 dark:group-hover:bg-success-900/30">
              <svg class="h-4 w-4 transition-colors group-hover:text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            </div>
            <span>Catat Pembayaran</span>
          </a>
          <button 
            @click="toast?.info('Export feature coming soon!')"
            class="btn-press group flex items-center justify-start gap-3 rounded-xl border-2 border-gray-200 bg-white px-4 py-3.5 font-semibold text-gray-700 transition-all duration-300 hover:scale-105 hover:border-warning-500 hover:bg-warning-50 hover:text-warning-700 hover:shadow-lg dark:border-dark-700 dark:bg-dark-800 dark:text-gray-200 dark:hover:border-warning-500 dark:hover:bg-dark-700"
            aria-label="Export laporan"
            type="button"
          >
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 transition-colors group-hover:bg-warning-100 dark:bg-dark-700 dark:group-hover:bg-warning-900/30">
              <svg class="h-4 w-4 transition-colors group-hover:text-warning-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
            </div>
            <span>Export Laporan</span>
          </button>
        </div>
      </div>

      <!-- Invoice summary -->
      <div class="grid gap-6 sm:grid-cols-3">
        <div class="group relative overflow-hidden rounded-xl bg-white p-6 shadow-card transition-all duration-300 hover:shadow-card-hover hover:-translate-y-1 dark:bg-dark-800">
          <div class="absolute left-0 top-0 h-full w-1.5 bg-gradient-to-b from-warning-400 to-warning-600"></div>
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-dark-400">Invoice Terbuka</p>
              <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{{ dashboard.invoices.open }}</p>
            </div>
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-warning-500 to-warning-600 shadow-lg shadow-warning-500/30 transition-all duration-300 group-hover:scale-110">
              <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
          </div>
        </div>
        <div class="group relative overflow-hidden rounded-xl bg-white p-6 shadow-card transition-all duration-300 hover:shadow-card-hover hover:-translate-y-1 dark:bg-dark-800">
          <div class="absolute left-0 top-0 h-full w-1.5 bg-gradient-to-b from-danger-400 to-danger-600"></div>
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-dark-400">Invoice Overdue</p>
              <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{{ dashboard.invoices.overdue }}</p>
            </div>
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-danger-500 to-danger-600 shadow-lg shadow-danger-500/30 transition-all duration-300 group-hover:scale-110">
              <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
          </div>
        </div>
        <div class="group relative overflow-hidden rounded-xl bg-white p-6 shadow-card transition-all duration-300 hover:shadow-card-hover hover:-translate-y-1 dark:bg-dark-800">
          <div class="absolute left-0 top-0 h-full w-1.5 bg-gradient-to-b from-success-400 to-success-600"></div>
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-dark-400">Invoice Lunas</p>
              <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{{ dashboard.invoices.paid }}</p>
            </div>
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-success-500 to-success-600 shadow-lg shadow-success-500/30 transition-all duration-300 group-hover:scale-110">
              <svg class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

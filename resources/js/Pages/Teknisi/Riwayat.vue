<script setup>
import { ref, computed, onMounted, watch, inject } from 'vue'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
  techName: { type: String, default: 'Teknisi' },
  techRole: { type: String, default: 'teknisi' },
  initialFilters: { type: Object, default: () => ({}) },
})

const toast = inject('toast', null)
const API_BASE = '/api/v1'

const history = ref([])
const loading = ref(false)

const filters = ref({
  date_from: props.initialFilters.date_from || new Date(new Date().setDate(1)).toISOString().split('T')[0],
  date_to: props.initialFilters.date_to || new Date().toISOString().split('T')[0],
  status: props.initialFilters.status || 'Selesai',
  q: '',
})

const statusColors = {
  Selesai: 'bg-emerald-50 text-emerald-700 border border-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-200 dark:border-emerald-500/30',
  Batal: 'bg-red-50 text-red-700 border border-red-100 dark:bg-red-900/30 dark:text-red-200 dark:border-red-500/30',
}

function notify(message, type = 'error') {
  const msg = String(message || '').trim() || 'Terjadi kesalahan'
  if (toast && typeof toast[type] === 'function') {
    toast[type](msg)
    return
  }
  if (type === 'error') {
    console.error(msg)
    return
  }
  console.log(msg)
}

function normalizeText(v) {
  return String(v || '').trim().toLowerCase()
}

async function loadHistory() {
  loading.value = true
  try {
    const params = new URLSearchParams({
      tech_name: props.techName,
      date_from: filters.value.date_from || '',
      date_to: filters.value.date_to || '',
      status: filters.value.status || '',
    })
    const res = await fetch(`${API_BASE}/teknisi/riwayat?${params}`, { credentials: 'same-origin' })
    const data = await res.json()
    if (!(data?.success === true || data?.status === 'success')) {
      throw new Error(data?.message || data?.msg || 'Gagal memuat riwayat')
    }
    history.value = Array.isArray(data.data) ? data.data : []
  } catch (e) {
    history.value = []
    notify(e?.message || 'Gagal memuat riwayat', 'error')
  } finally {
    loading.value = false
  }
}

function formatDateLong(dateStr) {
  if (!dateStr) return '-'
  const d = new Date(String(dateStr).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatDateWithTime(dateStr) {
  if (!dateStr) return '-'
  const d = new Date(String(dateStr).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(Number(amount || 0))
}

function itemFinishedAt(item) {
  return item?.finished_at || item?.tanggal_selesai || item?.tanggal || item?.installation_date || ''
}

const filteredHistory = computed(() => {
  const q = normalizeText(filters.value.q)
  if (!q) return history.value

  return history.value.filter((item) => {
    return (
      normalizeText(item?.ticket_id).includes(q) ||
      normalizeText(item?.nama || item?.customer_name).includes(q) ||
      normalizeText(item?.alamat || item?.address).includes(q) ||
      normalizeText(item?.pop).includes(q)
    )
  })
})

const totalRevenue = computed(() =>
  filteredHistory.value.reduce((sum, item) => sum + (parseFloat(item?.harga ?? item?.price) || 0), 0)
)

const totalDone = computed(() =>
  filteredHistory.value.filter((item) => String(item?.status || '') === 'Selesai').length
)

const totalCanceled = computed(() =>
  filteredHistory.value.filter((item) => String(item?.status || '') === 'Batal').length
)

function clearSearch() {
  filters.value.q = ''
}

onMounted(() => {
  loadHistory()
})

watch(
  () => [filters.value.date_from, filters.value.date_to, filters.value.status],
  () => loadHistory()
)
</script>

<template>
  <Head title="Riwayat Pekerjaan" />
  <AdminLayout>
    <div class="text-slate-800 dark:text-slate-100 pb-24">
      <div class="sticky top-0 z-30 w-full">
        <div class="absolute inset-x-0 top-0 bottom-0 bg-slate-50/95 dark:bg-slate-900/90 backdrop-blur border-b border-slate-200 dark:border-white/10 pointer-events-none"></div>
        <div class="relative max-w-3xl mx-auto px-3 sm:px-5 pb-2 sm:pb-3 pt-2 space-y-2">
          <div class="flex items-start justify-between gap-2">
            <div>
              <h1 class="text-lg font-black text-slate-900 dark:text-slate-100">Riwayat Pekerjaan</h1>
              <p class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold">
                {{ props.techName }} • {{ props.techRole }}
              </p>
            </div>
            <button
              @click="loadHistory"
              class="h-10 w-10 flex items-center justify-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl text-slate-600 dark:text-slate-300 shadow-sm hover:bg-blue-50 dark:hover:bg-white/5 transition"
              type="button"
              title="Refresh"
            >
              ↻
            </button>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl px-3 py-2 shadow-sm">
              <div class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">Total Job</div>
              <div class="text-lg font-black text-slate-900 dark:text-slate-100">{{ filteredHistory.length }}</div>
            </div>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl px-3 py-2 shadow-sm">
              <div class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">Total Revenue</div>
              <div class="text-sm sm:text-base font-black text-emerald-600 dark:text-emerald-300">{{ formatCurrency(totalRevenue) }}</div>
            </div>
          </div>

          <div class="space-y-2">
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Dari Tanggal</label>
                <input v-model="filters.date_from" type="date" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none" />
              </div>
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Sampai Tanggal</label>
                <input v-model="filters.date_to" type="date" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none" />
              </div>
            </div>

            <div class="grid grid-cols-[minmax(0,1fr)_110px] gap-2">
              <div class="relative">
                <input
                  v-model="filters.q"
                  type="text"
                  class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl pl-10 pr-10 text-sm font-semibold text-slate-700 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none"
                  placeholder="Cari ticket / nama / alamat / pop"
                />
                <div class="absolute inset-y-0 left-0 w-10 flex items-center justify-center text-slate-400 dark:text-slate-500">⌕</div>
                <button v-if="filters.q" @click="clearSearch" type="button" class="absolute inset-y-0 right-0 w-10 flex items-center justify-center text-slate-400 hover:text-red-500">✕</button>
              </div>
              <select v-model="filters.status" class="h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-xs font-bold text-slate-700 dark:text-slate-100 outline-none">
                <option value="">Semua</option>
                <option value="Selesai">Selesai</option>
                <option value="Batal">Batal</option>
              </select>
            </div>

            <div class="grid grid-cols-2 gap-2 text-[11px]">
              <div class="bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-200 border border-emerald-100 dark:border-emerald-500/30 rounded-xl px-3 py-2 font-bold">Selesai: {{ totalDone }}</div>
              <div class="bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-200 border border-red-100 dark:border-red-500/30 rounded-xl px-3 py-2 font-bold">Batal: {{ totalCanceled }}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="max-w-3xl mx-auto px-3 sm:px-5 mt-4">
        <div v-if="loading" class="text-center py-12">
          <div class="animate-spin rounded-full h-10 w-10 border-b-4 border-blue-600 mx-auto"></div>
          <p class="text-sm text-slate-400 dark:text-slate-500 mt-3 font-bold">Memuat riwayat...</p>
        </div>

        <div v-else-if="filteredHistory.length > 0" class="space-y-3 pb-10">
          <div
            v-for="item in filteredHistory"
            :key="item.id"
            class="bg-white dark:bg-slate-900 rounded-2xl p-4 shadow-sm border border-slate-200 dark:border-white/10 space-y-3"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="text-xs font-bold text-slate-400 dark:text-slate-500 mb-1">#{{ item.ticket_id || item.id }}</div>
                <h3 class="font-black text-slate-900 dark:text-slate-100 truncate">{{ item.nama || item.customer_name || '-' }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 line-clamp-2">{{ item.alamat || item.address || '-' }}</p>
              </div>
              <span :class="['px-2 py-1 rounded-lg text-[10px] font-bold uppercase whitespace-nowrap', statusColors[item.status] || 'bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-white/10']">
                {{ item.status || '-' }}
              </span>
            </div>

            <div class="grid grid-cols-2 gap-2 text-[11px]">
              <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-white/10 rounded-lg px-2 py-1.5">
                <div class="text-slate-400 dark:text-slate-500 font-bold uppercase">POP</div>
                <div class="text-slate-700 dark:text-slate-200 font-bold">{{ item.pop || '-' }}</div>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-white/10 rounded-lg px-2 py-1.5">
                <div class="text-slate-400 dark:text-slate-500 font-bold uppercase">Selesai</div>
                <div class="text-slate-700 dark:text-slate-200 font-bold">{{ formatDateLong(itemFinishedAt(item)) }}</div>
              </div>
            </div>

            <div class="flex items-center justify-between text-xs">
              <div class="text-slate-500 dark:text-slate-400 font-semibold">{{ formatDateWithTime(itemFinishedAt(item)) }}</div>
              <div class="text-emerald-600 dark:text-emerald-300 font-black">{{ formatCurrency(item.harga || item.price) }}</div>
            </div>
          </div>
        </div>

        <div v-else class="text-center py-16">
          <div class="w-20 h-20 bg-slate-200 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400 dark:text-slate-500">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <h3 class="text-slate-600 dark:text-slate-300 font-bold text-base">Tidak ada riwayat</h3>
          <p class="text-sm text-slate-400 dark:text-slate-500 mt-1">Coba ubah filter tanggal atau status.</p>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

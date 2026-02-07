
<script setup>
import { ref, reactive, onMounted, inject } from 'vue'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import LoadingSpinner from '@/Components/LoadingSpinner.vue'

const toast = inject('toast')

const props = defineProps({
  initialFilters: {
    type: Object,
    default: () => ({}),
  },
})

const PER_PAGE = 20

const loading = ref(false)
const list = ref([])
const pagination = reactive({
  page: 1,
  per_page: PER_PAGE,
  total: 0,
  total_pages: 1,
})

const filters = reactive({
  search: props.initialFilters.q || '',
  status: props.initialFilters.status || '',
  date_from: props.initialFilters.date_from || '',
  date_to: props.initialFilters.date_to || '',
})

let searchTimer = null

function pad2(n) {
  return String(n).padStart(2, '0')
}

function toYmd(d) {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`
}

function formatDateTime(value) {
  if (!value) return '-'
  const iso = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T')
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleString('id-ID')
}

async function apiGetJson(url) {
  const res = await fetch(url, { credentials: 'same-origin' })
  const json = await res.json().catch(() => null)
  if (!json) throw new Error('Response JSON tidak valid')
  return json
}

async function loadData(page = 1) {
  loading.value = true
  try {
    const params = new URLSearchParams()
    params.set('page', String(page))
    params.set('per_page', String(PER_PAGE))
    if (filters.search) params.set('search', filters.search)
    if (filters.status) params.set('status', filters.status)
    if (filters.date_from) params.set('date_from', filters.date_from)
    if (filters.date_to) params.set('date_to', filters.date_to)

    const json = await apiGetJson(`/api/v1/installations/riwayat?${params.toString()}`)
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal memuat data')

    list.value = Array.isArray(json.data) ? json.data : []
    pagination.page = Number(json.page || 1)
    pagination.per_page = Number(json.per_page || PER_PAGE)
    pagination.total = Number(json.total || 0)
    pagination.total_pages = Number(json.total_pages || 1)
  } catch (e) {
    console.error(e)
    list.value = []
    if (toast) toast.error(e.message || 'Gagal memuat riwayat')
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  pagination.page = 1
  loadData(1)
}

function debounceSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => applyFilters(), 350)
}

function clearSearch() {
  filters.search = ''
  applyFilters()
}

function setThisMonth(trigger = true) {
  const now = new Date()
  const start = new Date(now.getFullYear(), now.getMonth(), 1)
  const end = new Date(now.getFullYear(), now.getMonth() + 1, 0)
  filters.date_from = toYmd(start)
  filters.date_to = toYmd(end)
  if (trigger) applyFilters()
}

// =========================
// Detail modal (parity native: show detail + audit changes)
// =========================
const detailOpen = ref(false)
const detailLoading = ref(false)
const detail = ref(null)
const changeLoading = ref(false)
const changeHistory = ref([])

async function openDetail(id) {
  detailOpen.value = true
  detailLoading.value = true
  changeHistory.value = []
  try {
    const json = await apiGetJson(`/api/v1/installations/${id}`)
    if (json.status !== 'success' || !json.data) throw new Error(json.msg || 'Data tidak ditemukan')
    detail.value = json.data

    changeLoading.value = true
    try {
      const ch = await apiGetJson(`/api/v1/installations/${id}/history`)
      changeHistory.value = ch.status === 'success' ? (ch.data || []) : []
    } catch {
      changeHistory.value = []
    } finally {
      changeLoading.value = false
    }
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal memuat detail')
    detailOpen.value = false
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  detailOpen.value = false
  detail.value = null
  changeHistory.value = []
}

onMounted(() => {
  if (!filters.date_from && !filters.date_to) {
    setThisMonth(false)
  }
  loadData(1)
})
</script>

<template>
  <Head title="Riwayat Pemasangan" />

  <AdminLayout>
    <div class="text-slate-800 dark:text-slate-100 pb-24">
      <div class="sticky top-0 z-30 w-full mb-4">
        <div class="absolute inset-x-0 top-0 bottom-0 bg-slate-50/95 dark:bg-slate-900/90 backdrop-blur border-b border-slate-200 dark:border-white/10 pointer-events-none"></div>
        <div class="relative max-w-4xl mx-auto px-3 sm:px-5 pb-2 sm:pb-3 pt-2 space-y-2">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-sm font-black text-slate-800 dark:text-slate-100">Riwayat</h2>
              <p class="text-[10px] text-slate-400 dark:text-slate-500 font-bold uppercase">Selesai & Batal</p>
            </div>
            <div class="flex items-center gap-2">
              <button @click="loadData(pagination.page)" class="h-10 w-10 flex items-center justify-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl text-slate-600 dark:text-slate-300 shadow-sm hover:bg-blue-50 dark:hover:bg-white/5 transition" type="button" title="Refresh">
                ↻
              </button>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
            <div class="sm:col-span-2">
              <input v-model="filters.search" @input="debounceSearch" class="input w-full" placeholder="Cari ticket/nama/alamat" />
            </div>
            <div>
              <select v-model="filters.status" class="input w-full" @change="applyFilters">
                <option value="">Selesai + Batal</option>
                <option value="Selesai">Selesai</option>
                <option value="Batal">Batal</option>
              </select>
            </div>
            <div class="flex gap-2">
              <button class="btn btn-secondary w-full" type="button" @click="setThisMonth(true)">Bulan ini</button>
              <button class="btn btn-secondary" type="button" @click="clearSearch">Clear</button>
            </div>
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <div>
              <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400">Dari</label>
              <input v-model="filters.date_from" type="date" class="input w-full" @change="applyFilters" />
            </div>
            <div>
              <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400">Sampai</label>
              <input v-model="filters.date_to" type="date" class="input w-full" @change="applyFilters" />
            </div>
            <div class="col-span-2 flex items-end justify-end text-xs text-slate-500 dark:text-slate-400 font-bold">
              Total: {{ pagination.total }}
            </div>
          </div>
        </div>
      </div>

      <div class="max-w-4xl mx-auto px-3 sm:px-5 space-y-3">
        <div v-if="loading" class="py-10 text-center text-slate-500"><LoadingSpinner /></div>

        <div v-else-if="list.length === 0" class="text-center py-16 sm:py-20">
          <div class="w-20 h-20 bg-slate-200 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400 dark:text-slate-500">✓</div>
          <h3 class="text-slate-600 dark:text-slate-300 font-bold text-base">Tidak ada riwayat</h3>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <button
            v-for="item in list"
            :key="item.id"
            type="button"
            class="card p-4 text-left hover:ring-2 hover:ring-primary-500 transition"
            @click="openDetail(item.id)"
          >
            <div class="flex items-center justify-between gap-2">
              <div class="font-black text-slate-900 dark:text-slate-100">{{ item.customer_name || '-' }}</div>
              <span :class="item.status === 'Selesai' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'" class="px-2 py-1 rounded-lg text-xs font-bold">
                {{ item.status || '-' }}
              </span>
            </div>
            <div class="text-xs text-slate-600 dark:text-slate-300 mt-1">#{{ item.ticket_id || '-' }} • WA: {{ item.customer_phone || '-' }}</div>
            <div class="text-xs text-slate-600 dark:text-slate-300 mt-1">{{ item.address || '-' }}</div>
            <div class="flex items-center justify-between mt-3 text-[10px] text-slate-500 dark:text-slate-400 font-bold">
              <div>POP: {{ item.pop || '-' }}</div>
              <div>{{ formatDateTime(item.finished_at) }}</div>
            </div>
          </button>
        </div>

        <div v-if="pagination.total_pages > 1" class="flex items-center justify-between gap-3 pt-2">
          <button @click="loadData(pagination.page - 1)" :disabled="pagination.page === 1 || loading" class="btn btn-secondary" type="button">Prev</button>
          <div class="text-xs text-slate-500 dark:text-slate-400 font-bold">Halaman {{ pagination.page }} / {{ pagination.total_pages }}</div>
          <button @click="loadData(pagination.page + 1)" :disabled="pagination.page === pagination.total_pages || loading" class="btn btn-secondary" type="button">Next</button>
        </div>
      </div>

      <!-- Detail modal -->
      <div v-if="detailOpen" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeDetail">
        <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh] border border-slate-200 dark:border-white/10">
          <div class="bg-slate-50 dark:bg-slate-900 px-5 py-4 border-b border-slate-200 dark:border-white/10 flex justify-between items-center">
            <div>
              <h3 class="font-black text-lg text-slate-800 dark:text-slate-100">Detail Riwayat</h3>
              <div class="text-[10px] text-slate-400 font-bold">{{ detail?.ticket_id ? ('#' + detail.ticket_id) : '' }}</div>
            </div>
            <button @click="closeDetail" class="h-9 w-9 flex items-center justify-center rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-slate-500 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition" type="button">✕</button>
          </div>

          <div class="p-5 overflow-y-auto text-sm bg-slate-50/50 dark:bg-slate-900/50 space-y-4">
            <div v-if="detailLoading" class="py-10 text-center text-slate-500"><LoadingSpinner /></div>

            <template v-else-if="detail">
              <div class="card p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                  <div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold">Nama</div>
                    <div class="font-bold">{{ detail.customer_name || '-' }}</div>
                  </div>
                  <div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold">WA</div>
                    <div class="font-bold">{{ detail.customer_phone || '-' }}</div>
                  </div>
                  <div class="sm:col-span-2">
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold">Alamat</div>
                    <div class="font-bold">{{ detail.address || '-' }}</div>
                  </div>
                  <div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold">POP</div>
                    <div class="font-bold">{{ detail.pop || '-' }}</div>
                  </div>
                  <div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold">Status</div>
                    <div class="font-bold">{{ detail.status || '-' }}</div>
                  </div>
                  <div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold">Tanggal Install</div>
                    <div class="font-bold">{{ detail.installation_date || '-' }}</div>
                  </div>
                  <div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold">Selesai/Batal</div>
                    <div class="font-bold">{{ formatDateTime(detail.finished_at) }}</div>
                  </div>
                </div>
              </div>

              <div class="card p-4">
                <div class="text-xs font-black text-slate-800 dark:text-slate-100 mb-2">Catatan</div>
                <pre class="text-[11px] text-slate-600 dark:text-slate-300 whitespace-pre-wrap break-words">{{ detail.notes || '-' }}</pre>
              </div>

              <div class="card p-4">
                <div class="flex items-center justify-between">
                  <div class="text-xs font-black text-slate-800 dark:text-slate-100">Riwayat Perubahan Data</div>
                  <div v-if="changeLoading" class="text-xs text-slate-500"><LoadingSpinner size="sm" /></div>
                </div>
                <div class="mt-3 space-y-2">
                  <div v-if="!changeLoading && changeHistory.length === 0" class="italic text-xs text-slate-400">Belum ada perubahan.</div>
                  <div v-for="(ch, idx) in changeHistory" :key="idx" class="border-b border-slate-200 dark:border-white/10 pb-2">
                    <div class="text-[10px] text-slate-400 mb-1">{{ ch.changed_at || '-' }} - {{ ch.changed_by || '-' }} ({{ ch.changed_by_role || '-' }}) via {{ ch.source || '-' }}</div>
                    <div class="text-xs text-slate-700 dark:text-slate-200">
                      <span class="font-bold">{{ ch.field_name || '-' }}</span>:
                      <span class="text-slate-500 dark:text-slate-400">{{ (ch.old_value || '-').toString().trim() || '-' }}</span>
                      <span class="text-slate-400 px-1">-></span>
                      <span class="text-slate-800 dark:text-slate-100 font-bold">{{ (ch.new_value || '-').toString().trim() || '-' }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

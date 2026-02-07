
<script setup>
import { ref, reactive, computed, onMounted, nextTick, inject } from 'vue'
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

const PER_PAGE = 10

const loading = ref(false)
const list = ref([])
const summary = ref({
  priority: 0,
  overdue: 0,
  Baru: 0,
  Survey: 0,
  Proses: 0,
  Pending: 0,
  Req_Batal: 0,
  Batal: 0,
  today_done: 0,
})

const pagination = reactive({
  page: 1,
  per_page: PER_PAGE,
  total: 0,
  total_pages: 1,
})

const dropdowns = reactive({
  pops: [],
  technicians: [],
})

const filters = reactive({
  search: props.initialFilters.q || '',
  status: props.initialFilters.status || '',
  pop: props.initialFilters.pop || '',
  tech: props.initialFilters.technician || '',
  date_preset: '',
  date_from: props.initialFilters.date_from || '',
  date_to: props.initialFilters.date_to || '',
  priority_only: false,
  overdue_only: false,
})

const statusOptions = [
  { value: '', label: 'Semua Status' },
  { value: 'Baru', label: 'Baru' },
  { value: 'Survey', label: 'Survey' },
  { value: 'Proses', label: 'Proses' },
  { value: 'Pending', label: 'Pending' },
  { value: 'Req_Batal', label: 'Req Batal' },
  { value: 'Selesai', label: 'Selesai' },
  { value: 'Batal', label: 'Batal' },
]

const statusColors = {
  Baru: 'bg-blue-50 text-blue-700 border border-blue-100 dark:bg-blue-500/15 dark:text-blue-200 dark:border-blue-500/20',
  Survey: 'bg-purple-50 text-purple-700 border border-purple-100 dark:bg-purple-500/15 dark:text-purple-200 dark:border-purple-500/20',
  Proses: 'bg-indigo-50 text-indigo-700 border border-indigo-100 dark:bg-indigo-500/15 dark:text-indigo-200 dark:border-indigo-500/20',
  Pending: 'bg-amber-50 text-amber-700 border border-amber-100 dark:bg-amber-500/15 dark:text-amber-200 dark:border-amber-500/20',
  Req_Batal: 'bg-red-100 text-red-800 border border-red-200 dark:bg-red-500/15 dark:text-red-200 dark:border-red-500/20',
  Selesai: 'bg-emerald-50 text-emerald-700 border border-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-200 dark:border-emerald-500/20',
  Batal: 'bg-red-50 text-red-700 border border-red-100 dark:bg-red-500/15 dark:text-red-200 dark:border-red-500/20',
}

const isCustomDate = computed(() => filters.date_preset === 'custom')

function ymd(date) {
  const d = date || new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function getDateRangeFromPreset(preset) {
  const today = new Date()
  const startDate = new Date(today)
  const endDate = new Date(today)

  switch (preset) {
    case 'today':
      break
    case 'yesterday':
      startDate.setDate(today.getDate() - 1)
      endDate.setDate(today.getDate() - 1)
      break
    case 'this_week':
      startDate.setDate(today.getDate() - today.getDay())
      break
    case 'this_month':
      startDate.setDate(1)
      break
    case 'this_year':
      startDate.setMonth(0)
      startDate.setDate(1)
      break
    case 'last_year':
      startDate.setFullYear(today.getFullYear() - 1)
      startDate.setMonth(0)
      startDate.setDate(1)
      endDate.setFullYear(today.getFullYear() - 1)
      endDate.setMonth(11)
      endDate.setDate(31)
      break
    case 'last_7':
      startDate.setDate(today.getDate() - 7)
      break
    case 'last_30':
      startDate.setDate(today.getDate() - 30)
      break
    default:
      return null
  }

  return { from: ymd(startDate), to: ymd(endDate) }
}

function applyDatePreset(preset) {
  if (preset === 'custom') return
  if (!preset) {
    filters.date_from = ''
    filters.date_to = ''
    return
  }
  const range = getDateRangeFromPreset(preset)
  if (!range) return
  filters.date_from = range.from
  filters.date_to = range.to
}

function isStatusClosed(status) {
  return status === 'Selesai' || status === 'Batal'
}

function daysSinceInstall(installDateYmd) {
  if (!installDateYmd) return null
  const m = String(installDateYmd).match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!m) return null
  const d0 = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  const now = new Date()
  const d1 = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const diffMs = d1.getTime() - d0.getTime()
  return Math.floor(diffMs / (1000 * 60 * 60 * 24))
}

function formatDateShort(dateStr) {
  if (!dateStr) return '-'
  const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!m) return String(dateStr)
  const yyyy = Number(m[1])
  const mm = Number(m[2])
  const dd = Number(m[3])
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
  return `${dd} ${months[mm - 1] || ''} ${yyyy}`.trim()
}

function toDatetimeLocal(dbDatetime) {
  if (!dbDatetime) return ''
  const s = String(dbDatetime).trim()
  const m = s.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/)
  if (!m) return ''
  return `${m[1]}T${m[2]}`
}

function normalizeCoords(raw) {
  if (!raw) return null
  const s = String(raw).trim().replace(/\s+/g, ',')
  const parts = s.split(',').map((x) => x.trim()).filter(Boolean)
  if (parts.length < 2) return null
  const lat = parseFloat(parts[0])
  const lng = parseFloat(parts[1])
  if (Number.isNaN(lat) || Number.isNaN(lng)) return null
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null
  return { lat, lng }
}

const lastSavedId = ref(null)
let searchTimer = null

async function apiGetJson(url) {
  const res = await fetch(url, { credentials: 'same-origin' })
  const json = await res.json().catch(() => null)
  if (!json) throw new Error('Response JSON tidak valid')
  return json
}

async function apiSendJson(url, method, payload) {
  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: payload ? JSON.stringify(payload) : undefined,
  })
  const json = await res.json().catch(() => null)
  if (!json) throw new Error('Response JSON tidak valid')
  if (!res.ok) {
    const msg = json.msg || json.message || 'Request gagal'
    throw new Error(msg)
  }
  return json
}

async function loadDropdownsOnce() {
  if (dropdowns.pops.length === 0) {
    try {
      const pops = await apiGetJson('/api/v1/installations/pops')
      dropdowns.pops = pops.status === 'success' ? (pops.data || []) : []
    } catch {
      dropdowns.pops = []
    }
  }
  if (dropdowns.technicians.length === 0) {
    try {
      const techs = await apiGetJson('/api/v1/installations/technicians')
      dropdowns.technicians = techs.status === 'success' ? (techs.data || []) : []
    } catch {
      dropdowns.technicians = []
    }
  }
}

function focusRowById(id) {
  const el = document.getElementById(`row-${id}`)
  if (!el) return
  el.scrollIntoView({ behavior: 'smooth', block: 'center' })
  el.classList.add('ring-2', 'ring-blue-400')
  el.style.transition = 'background-color 300ms ease'
  el.style.backgroundColor = 'rgba(59,130,246,0.10)'
  setTimeout(() => {
    el.classList.remove('ring-2', 'ring-blue-400')
    el.style.backgroundColor = ''
  }, 1800)
}

async function loadData(page = 1) {
  loading.value = true
  try {
    const params = new URLSearchParams()
    params.set('page', String(page))
    params.set('per_page', String(PER_PAGE))
    if (filters.search) params.set('search', filters.search)
    if (filters.status) params.set('status', filters.status)
    if (filters.pop) params.set('pop', filters.pop)
    if (filters.tech) params.set('tech', filters.tech)
    if (filters.date_from) params.set('date_from', filters.date_from)
    if (filters.date_to) params.set('date_to', filters.date_to)
    if (filters.priority_only) params.set('priority_only', '1')
    if (filters.overdue_only) params.set('overdue_only', '1')

    const json = await apiGetJson(`/api/v1/installations?${params.toString()}`)
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal memuat data')

    list.value = Array.isArray(json.data) ? json.data : []
    pagination.page = Number(json.page || 1)
    pagination.per_page = Number(json.per_page || PER_PAGE)
    pagination.total = Number(json.total || 0)
    pagination.total_pages = Number(json.total_pages || 1)
    summary.value = json.summary || summary.value

    await loadDropdownsOnce()

    if (lastSavedId.value) {
      await nextTick()
      focusRowById(lastSavedId.value)
      lastSavedId.value = null
    }
  } catch (e) {
    console.error(e)
    list.value = []
    if (toast) toast.error(e.message || 'Gagal memuat data')
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  filters.priority_only = false
  filters.overdue_only = false
  pagination.page = 1
  loadData(1)
}

function resetFilters() {
  filters.search = ''
  filters.status = ''
  filters.pop = ''
  filters.tech = ''
  filters.date_preset = ''
  filters.date_from = ''
  filters.date_to = ''
  filters.priority_only = false
  filters.overdue_only = false
  pagination.page = 1
  loadData(1)
}

function filterByCard(type, value) {
  filters.priority_only = false
  filters.overdue_only = false

  if (type === 'status') {
    filters.status = filters.status === value ? '' : value
  } else if (type === 'today_done') {
    filters.status = 'Selesai'
    filters.date_preset = 'today'
    applyDatePreset('today')
  } else if (type === 'priority') {
    filters.status = ''
    filters.priority_only = true
    if (toast) toast.info('Menampilkan data Prioritas')
  } else if (type === 'overdue') {
    filters.status = ''
    filters.overdue_only = true
    if (toast) toast.info('Menampilkan data Overdue')
  }

  pagination.page = 1
  loadData(1)
}

function onSearchInput() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => applyFilters(), 350)
}

function changePreset() {
  applyDatePreset(filters.date_preset)
  applyFilters()
}

function openMaps(coords) {
  const c = normalizeCoords(coords)
  if (!c) {
    if (toast) toast.info('Koordinat kosong / tidak valid. Contoh: -6.200000,106.816666')
    return
  }
  const url = `https://www.google.com/maps?q=${encodeURIComponent(`${c.lat},${c.lng}`)}`
  window.open(url, '_blank', 'noopener')
}

// =========================
// Detail modal (parity native: edit + input baru + notes append + audit)
// =========================
const modalOpen = ref(false)
const modalLoading = ref(false)
const modalMode = ref('edit') // edit | new
const changeLoading = ref(false)
const changeHistory = ref([])

const form = reactive({
  id: null,
  ticket_id: '',
  customer_name: '',
  customer_phone: '',
  address: '',
  pop: '',
  coordinates: '',
  plan_name: '',
  price: '',
  installation_date: '',
  finished_at: '',
  status: 'Baru',
  technician: '',
  technician_2: '',
  technician_3: '',
  technician_4: '',
  sales_name: '',
  sales_name_2: '',
  sales_name_3: '',
  is_priority: false,
  notes_old: '',
  note_append: '',
})

const isReqBatal = computed(() => String(form.status || '') === 'Req_Batal')

function resetFormForNew() {
  form.id = null
  form.ticket_id = ''
  form.customer_name = ''
  form.customer_phone = ''
  form.address = ''
  form.pop = ''
  form.coordinates = ''
  form.plan_name = ''
  form.price = ''
  form.installation_date = ymd(new Date())
  form.finished_at = ''
  form.status = 'Baru'
  form.technician = ''
  form.technician_2 = ''
  form.technician_3 = ''
  form.technician_4 = ''
  form.sales_name = ''
  form.sales_name_2 = ''
  form.sales_name_3 = ''
  form.is_priority = false
  form.notes_old = ''
  form.note_append = ''
  changeHistory.value = []
}

async function loadChangeHistory(id) {
  changeLoading.value = true
  try {
    const json = await apiGetJson(`/api/v1/installations/${id}/history`)
    changeHistory.value = json.status === 'success' ? (json.data || []) : []
  } catch {
    changeHistory.value = []
  } finally {
    changeLoading.value = false
  }
}

function openCreateModal() {
  modalMode.value = 'new'
  resetFormForNew()
  modalOpen.value = true
  loadDropdownsOnce()
}

async function openEditModal(id) {
  modalMode.value = 'edit'
  modalOpen.value = true
  modalLoading.value = true
  changeHistory.value = []
  form.note_append = ''

  try {
    await loadDropdownsOnce()
    const json = await apiGetJson(`/api/v1/installations/${id}`)
    if (json.status !== 'success' || !json.data) throw new Error(json.msg || 'Data tidak ditemukan')
    const item = json.data

    form.id = item.id
    form.ticket_id = item.ticket_id || ''
    form.customer_name = item.customer_name || ''
    form.customer_phone = item.customer_phone || ''
    form.address = item.address || ''
    form.pop = item.pop || ''
    form.coordinates = item.coordinates || ''
    form.plan_name = item.plan_name || ''
    form.price = item.price || ''
    form.installation_date = item.installation_date || ''
    form.finished_at = toDatetimeLocal(item.finished_at)
    form.status = item.status || 'Baru'
    form.technician = item.technician || ''
    form.technician_2 = item.technician_2 || ''
    form.technician_3 = item.technician_3 || ''
    form.technician_4 = item.technician_4 || ''
    form.sales_name = item.sales_name || ''
    form.sales_name_2 = item.sales_name_2 || ''
    form.sales_name_3 = item.sales_name_3 || ''
    form.is_priority = String(item.is_priority) === '1'
    form.notes_old = item.notes || ''

    await loadChangeHistory(id)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal memuat detail')
    modalOpen.value = false
  } finally {
    modalLoading.value = false
  }
}

function closeModal() {
  modalOpen.value = false
}

async function save() {
  if (!String(form.customer_name || '').trim()) {
    if (toast) toast.error('Nama pelanggan wajib diisi.')
    return
  }

  modalLoading.value = true
  try {
    const payload = {
      customer_name: form.customer_name,
      customer_phone: form.customer_phone,
      address: form.address,
      pop: form.pop,
      coordinates: form.coordinates,
      plan_name: form.plan_name,
      price: form.price,
      installation_date: form.installation_date,
      finished_at: form.finished_at || '',
      status: form.status,
      technician: form.technician,
      technician_2: form.technician_2,
      technician_3: form.technician_3,
      technician_4: form.technician_4,
      sales_name: form.sales_name,
      sales_name_2: form.sales_name_2,
      sales_name_3: form.sales_name_3,
      is_priority: form.is_priority ? 1 : 0,
      note_append: (form.note_append || '').trim(),
    }

    let json = null
    if (modalMode.value === 'new') {
      json = await apiSendJson('/api/v1/installations', 'POST', payload)
      lastSavedId.value = json.id || null
    } else {
      json = await apiSendJson(`/api/v1/installations/${form.id}`, 'PUT', payload)
      lastSavedId.value = form.id
    }

    if (json.status !== 'success') throw new Error(json.msg || 'Gagal menyimpan')
    if (toast) toast.success('Data berhasil disimpan.')
    closeModal()
    await loadData(pagination.page)
  } catch (e) {
    console.error(e)
    if (toast) toast.error(e.message || 'Gagal menyimpan')
  } finally {
    modalLoading.value = false
  }
}

async function remove() {
  if (!form.id) return
  if (!confirm('Hapus data ini? Tindakan ini tidak bisa dibatalkan.')) return
  modalLoading.value = true
  try {
    const json = await apiSendJson(`/api/v1/installations/${form.id}`, 'DELETE')
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal menghapus')
    if (toast) toast.success('Data berhasil dihapus.')
    closeModal()
    await loadData(pagination.page)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal menghapus')
  } finally {
    modalLoading.value = false
  }
}

async function togglePriority(id, currentVal) {
  try {
    const nextVal = String(currentVal) === '1' ? 0 : 1
    const json = await apiSendJson(`/api/v1/installations/${id}/toggle-priority`, 'POST', { val: nextVal })
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal toggle prioritas')
    await loadData(pagination.page)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal toggle prioritas')
  }
}

async function requestCancel() {
  if (!form.id) return
  const reason = prompt('Alasan pengajuan batal:') || ''
  if (!reason.trim()) return
  modalLoading.value = true
  try {
    const json = await apiSendJson(`/api/v1/installations/${form.id}/request-cancel`, 'POST', { reason })
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal mengajukan batal')
    if (toast) toast.success('Pengajuan batal terkirim.')
    await openEditModal(form.id)
    await loadData(pagination.page)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal mengajukan batal')
  } finally {
    modalLoading.value = false
  }
}

async function decideCancel(decision) {
  if (!form.id) return
  const note = prompt('Catatan (opsional):') || ''
  modalLoading.value = true
  try {
    const json = await apiSendJson(`/api/v1/installations/${form.id}/decide-cancel`, 'POST', { decision, reason: note })
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal memproses')
    if (toast) toast.success('Berhasil.')
    await openEditModal(form.id)
    await loadData(pagination.page)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal memproses')
  } finally {
    modalLoading.value = false
  }
}

async function transferTask() {
  if (!form.id) return
  const toTech = prompt('Transfer ke teknisi (isi nama persis):') || ''
  if (!toTech.trim()) return
  const reason = prompt('Alasan transfer (opsional):') || ''
  modalLoading.value = true
  try {
    const json = await apiSendJson(`/api/v1/installations/${form.id}/transfer`, 'POST', { to_tech: toTech.trim(), reason })
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal transfer')
    if (toast) toast.success('Transfer berhasil.')
    await openEditModal(form.id)
    await loadData(pagination.page)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal transfer')
  } finally {
    modalLoading.value = false
  }
}

// =========================
// Rekap modal
// =========================
const recapOpen = ref(false)
const recapPop = ref('')
const recapLoading = ref(false)

function openRecapModal() {
  if (!dropdowns.pops.length) {
    if (toast) toast.error('Daftar POP belum termuat. Coba refresh dulu.')
    return
  }
  recapPop.value = filters.pop || dropdowns.pops[0] || ''
  recapOpen.value = true
}

async function sendRecap() {
  if (!recapPop.value) return
  if (!confirm(`Kirim rekap WA untuk POP: ${recapPop.value}?`)) return
  recapLoading.value = true
  try {
    const json = await apiSendJson('/api/v1/installations/send-pop-recap', 'POST', { pop_name: recapPop.value })
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal mengirim rekap')
    const note = json.msg ? ` - ${json.msg}` : ''
    if (toast) toast.success(`Rekap terkirim (${json.count || 0} data, terkirim ${json.sent || 0}, gagal ${json.failed || 0})${note}`)
    recapOpen.value = false
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal mengirim rekap')
  } finally {
    recapLoading.value = false
  }
}

onMounted(() => {
  // Parity native: default preset = today (kalau user belum set range)
  if (!filters.date_from && !filters.date_to) {
    filters.date_preset = 'today'
    applyDatePreset('today')
  }
  loadData(1)
})
</script>

<template>
  <Head title="Pasang Baru" />

  <AdminLayout>
    <div class="space-y-5 pb-24">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-slate-100">Pasang Baru</h1>
          <p class="text-xs text-slate-500 dark:text-slate-400 font-bold uppercase">Progress / Antrian</p>
        </div>
        <div class="flex items-center gap-2">
          <button class="btn btn-secondary" @click="openRecapModal" type="button">Kirim Rekap WA</button>
          <button class="btn btn-primary" @click="openCreateModal" type="button">Tambah</button>
        </div>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-9 gap-3">
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('priority')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">PRIORITY</div>
          <div class="text-2xl font-black text-yellow-500">{{ summary.priority || 0 }}</div>
        </button>
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('overdue')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">OVERDUE</div>
          <div class="text-2xl font-black text-red-600">{{ summary.overdue || 0 }}</div>
        </button>
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('status', 'Baru')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">BARU</div>
          <div class="text-2xl font-black text-blue-600">{{ summary.Baru || 0 }}</div>
        </button>
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('status', 'Survey')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">SURVEY</div>
          <div class="text-2xl font-black text-purple-600">{{ summary.Survey || 0 }}</div>
        </button>
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('status', 'Proses')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">PROSES</div>
          <div class="text-2xl font-black text-indigo-600">{{ summary.Proses || 0 }}</div>
        </button>
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('status', 'Pending')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">PENDING</div>
          <div class="text-2xl font-black text-amber-600">{{ summary.Pending || 0 }}</div>
        </button>
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('status', 'Req_Batal')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">REQ BATAL</div>
          <div class="text-2xl font-black text-red-700">{{ summary.Req_Batal || 0 }}</div>
        </button>
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('status', 'Batal')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">BATAL</div>
          <div class="text-2xl font-black text-red-600">{{ summary.Batal || 0 }}</div>
        </button>
        <button type="button" class="card p-3 text-left hover:ring-2 hover:ring-primary-500" @click="filterByCard('today_done')">
          <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">SELESAI HARI INI</div>
          <div class="text-2xl font-black text-emerald-600">{{ summary.today_done || 0 }}</div>
        </button>
      </div>

      <div class="card p-4 space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <div>
            <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Search</label>
            <input v-model="filters.search" @input="onSearchInput" class="input w-full" placeholder="Ticket/Nama/Alamat/Teknisi" />
          </div>

          <div>
            <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Tanggal</label>
            <select v-model="filters.date_preset" class="input w-full" @change="changePreset">
              <option value="">Semua</option>
              <option value="today">Hari ini</option>
              <option value="yesterday">Kemarin</option>
              <option value="this_week">Minggu ini</option>
              <option value="this_month">Bulan ini</option>
              <option value="this_year">Tahun ini</option>
              <option value="last_year">Tahun lalu</option>
              <option value="last_7">Last 7 hari</option>
              <option value="last_30">Last 30 hari</option>
              <option value="custom">Custom</option>
            </select>
          </div>

          <div v-if="isCustomDate" class="sm:col-span-2 lg:col-span-2 grid grid-cols-2 gap-2">
            <div>
              <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Dari</label>
              <input v-model="filters.date_from" type="date" class="input w-full" @change="applyFilters" />
            </div>
            <div>
              <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Sampai</label>
              <input v-model="filters.date_to" type="date" class="input w-full" @change="applyFilters" />
            </div>
          </div>

          <div>
            <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Status</label>
            <select v-model="filters.status" class="input w-full" @change="applyFilters">
              <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </div>
          <div>
            <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">POP</label>
            <select v-model="filters.pop" class="input w-full" @change="applyFilters">
              <option value="">Semua</option>
              <option v-for="p in dropdowns.pops" :key="p" :value="p">{{ p }}</option>
            </select>
          </div>
          <div>
            <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Teknisi</label>
            <select v-model="filters.tech" class="input w-full" @change="applyFilters">
              <option value="">Semua</option>
              <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
        </div>

        <div class="flex items-center justify-between gap-2">
          <div class="text-xs text-slate-500 dark:text-slate-400">
            <span class="font-bold">{{ pagination.total }}</span> data
          </div>
          <div class="flex items-center gap-2">
            <button class="btn btn-secondary" type="button" @click="resetFilters">Reset</button>
            <button class="btn btn-secondary" type="button" @click="loadData(pagination.page)" :disabled="loading">
              <LoadingSpinner v-if="loading" size="sm" />
              <span v-else>Refresh</span>
            </button>
          </div>
        </div>
      </div>

      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-300">
              <tr>
                <th class="px-3 py-3 text-center w-12">★</th>
                <th class="px-4 py-3 text-left">Pelanggan</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">POP</th>
                <th class="px-4 py-3 text-left">Teknisi</th>
                <th class="px-4 py-3 text-left">Tanggal</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                  <LoadingSpinner />
                </td>
              </tr>
              <tr v-else-if="list.length === 0">
                <td colspan="6" class="px-4 py-10 text-center text-slate-400 italic">Tidak ada data ditemukan.</td>
              </tr>
              <tr
                v-for="row in list"
                :key="row.id"
                :id="`row-${row.id}`"
                class="border-t border-slate-100 dark:border-white/10 cursor-pointer hover:bg-blue-50/40 dark:hover:bg-white/5 transition"
                :class="(() => { const d = daysSinceInstall(row.installation_date); const overdue = (typeof d==='number' && d>0 && !isStatusClosed(row.status)); return overdue ? 'bg-red-50/30 dark:bg-red-900/10 border-l-4 border-l-red-500' : '' })()"
                @click="openEditModal(row.id)"
              >
                <td class="px-3 py-3 text-center">
                  <button
                    type="button"
                    class="p-2 transition-transform"
                    :class="String(row.is_priority)==='1' ? 'text-yellow-400 hover:scale-110' : 'text-slate-200 hover:text-yellow-400'"
                    @click.stop="togglePriority(row.id, row.is_priority)"
                    title="Toggle priority"
                  >
                    ★
                  </button>
                </td>
                <td class="px-4 py-3">
                  <div class="font-bold text-slate-800 dark:text-slate-100">
                    {{ row.customer_name || '-' }}
                    <span
                      v-if="daysSinceInstall(row.installation_date) !== null"
                      class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-bold"
                      :class="(daysSinceInstall(row.installation_date) > 0) ? 'bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-slate-200' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-200'"
                    >
                      D+{{ Math.max(0, daysSinceInstall(row.installation_date)) }}
                    </span>
                    <span
                      v-if="(() => { const d = daysSinceInstall(row.installation_date); return (typeof d==='number' && d>0 && !isStatusClosed(row.status)); })()"
                      class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-black bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-200"
                    >
                      OVERDUE +{{ Math.max(0, daysSinceInstall(row.installation_date)) }}d
                    </span>
                  </div>
                  <div class="text-[11px] text-slate-500 dark:text-slate-400">#{{ row.ticket_id || '-' }} • WA: {{ row.customer_phone || '-' }}</div>
                  <div class="text-xs text-slate-600 dark:text-slate-300 truncate max-w-[520px]">{{ row.address || '-' }}</div>
                </td>
                <td class="px-4 py-3">
                  <span class="px-2 py-1 rounded-lg text-xs font-bold" :class="statusColors[row.status] || 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200'">
                    {{ row.status || '-' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-xs text-slate-700 dark:text-slate-200">{{ row.pop || '-' }}</td>
                <td class="px-4 py-3 text-xs text-slate-700 dark:text-slate-200">
                  {{ [row.technician, row.technician_2, row.technician_3, row.technician_4].filter(Boolean).join(', ') || '-' }}
                </td>
                <td class="px-4 py-3 text-xs text-slate-700 dark:text-slate-200">{{ formatDateShort(row.installation_date) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex items-center justify-between p-4 border-t border-slate-100 dark:border-white/10 text-xs">
          <div class="text-slate-500 dark:text-slate-400 font-bold">Halaman {{ pagination.page }} / {{ pagination.total_pages }}</div>
          <div class="flex items-center gap-2">
            <button class="btn btn-secondary" type="button" :disabled="pagination.page <= 1 || loading" @click="loadData(pagination.page - 1)">Prev</button>
            <button class="btn btn-secondary" type="button" :disabled="pagination.page >= pagination.total_pages || loading" @click="loadData(pagination.page + 1)">Next</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <div v-if="modalOpen" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" @click.self="closeModal">
      <div class="bg-white dark:bg-slate-900 w-full max-w-3xl rounded-2xl shadow-2xl border border-slate-200 dark:border-white/10 overflow-hidden flex flex-col max-h-[92vh]">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-white/10 flex items-center justify-between">
          <div>
            <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold uppercase">{{ modalMode === 'new' ? 'Input Baru' : 'Detail Data' }}</div>
            <div class="text-lg font-black text-slate-900 dark:text-slate-100">{{ modalMode === 'new' ? '#NEW' : ('#' + (form.ticket_id || 'UNKNOWN')) }}</div>
          </div>
          <button class="h-10 w-10 rounded-xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5" @click="closeModal" type="button">✕</button>
        </div>

        <div class="p-5 overflow-y-auto space-y-5">
          <div v-if="modalLoading" class="py-10 text-center text-slate-500"><LoadingSpinner /></div>
          <div v-else class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="sm:col-span-2">
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Nama</label>
                <input v-model="form.customer_name" class="input w-full" />
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">WA</label>
                <input v-model="form.customer_phone" class="input w-full" placeholder="08xxx / 62xxx" />
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">POP</label>
                <select v-model="form.pop" class="input w-full">
                  <option value="">-- Pilih --</option>
                  <option v-for="p in dropdowns.pops" :key="p" :value="p">{{ p }}</option>
                </select>
              </div>
              <div class="sm:col-span-2">
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Alamat</label>
                <textarea v-model="form.address" rows="2" class="input w-full"></textarea>
              </div>
              <div class="sm:col-span-2">
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Koordinat</label>
                <div class="flex gap-2">
                  <input v-model="form.coordinates" class="input w-full" placeholder="-6.2,106.8" />
                  <button class="btn btn-secondary" type="button" @click="openMaps(form.coordinates)">Maps</button>
                </div>
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Paket</label>
                <input v-model="form.plan_name" class="input w-full" />
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Harga</label>
                <input v-model="form.price" class="input w-full" />
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Tanggal Install</label>
                <input v-model="form.installation_date" type="date" class="input w-full" />
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Finished At</label>
                <input v-model="form.finished_at" type="datetime-local" class="input w-full" />
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Status</label>
                <select v-model="form.status" class="input w-full">
                  <option v-for="o in statusOptions.slice(1)" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
              </div>
              <div class="flex items-end gap-2">
                <label class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-200">
                  <input v-model="form.is_priority" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                  Priority
                </label>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Teknisi 1</label>
                <select v-model="form.technician" class="input w-full">
                  <option value="">-- Pilih --</option>
                  <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Teknisi 2</label>
                <select v-model="form.technician_2" class="input w-full">
                  <option value="">-- Pilih --</option>
                  <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Teknisi 3</label>
                <select v-model="form.technician_3" class="input w-full">
                  <option value="">-- Pilih --</option>
                  <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Teknisi 4</label>
                <select v-model="form.technician_4" class="input w-full">
                  <option value="">-- Pilih --</option>
                  <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Sales 1</label>
                <input v-model="form.sales_name" class="input w-full" />
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Sales 2</label>
                <input v-model="form.sales_name_2" class="input w-full" />
              </div>
              <div>
                <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Sales 3</label>
                <input v-model="form.sales_name_3" class="input w-full" />
              </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
              <div class="card p-4">
                <div class="text-xs font-black text-slate-800 dark:text-slate-100 mb-2">Catatan (History)</div>
                <pre class="text-[11px] text-slate-600 dark:text-slate-300 whitespace-pre-wrap break-words">{{ form.notes_old || '-' }}</pre>
              </div>
              <div class="card p-4">
                <div class="text-xs font-black text-slate-800 dark:text-slate-100 mb-2">Tambah Catatan</div>
                <textarea v-model="form.note_append" rows="5" class="input w-full" placeholder="Catatan ini akan di-append (seperti native)"></textarea>
              </div>
            </div>

            <div class="card p-4">
              <div class="flex items-center justify-between">
                <div class="text-xs font-black text-slate-800 dark:text-slate-100">Riwayat Perubahan Data</div>
                <div v-if="changeLoading" class="text-xs text-slate-500"><LoadingSpinner size="sm" /></div>
              </div>
              <div class="mt-3 space-y-2">
                <div v-if="!changeLoading && changeHistory.length === 0" class="italic text-xs text-slate-400">Belum ada perubahan.</div>
                <div v-for="(ch, idx) in changeHistory" :key="idx" class="border-b border-slate-100 dark:border-white/10 pb-2">
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

            <div v-if="modalMode !== 'new'" class="card p-4">
              <div class="text-xs font-black text-slate-800 dark:text-slate-100 mb-2">Workflow</div>
              <div class="flex flex-wrap gap-2">
                <button class="btn btn-secondary" type="button" @click="transferTask" :disabled="modalLoading">Transfer</button>
                <button class="btn btn-secondary" type="button" @click="requestCancel" :disabled="modalLoading">Ajukan Batal</button>
                <button v-if="isReqBatal" class="btn btn-danger" type="button" @click="decideCancel('approve')" :disabled="modalLoading">Approve Batal</button>
                <button v-if="isReqBatal" class="btn btn-secondary" type="button" @click="decideCancel('reject')" :disabled="modalLoading">Reject (Pending)</button>
              </div>
            </div>
          </div>
        </div>

        <div class="px-5 py-4 border-t border-slate-200 dark:border-white/10 flex items-center justify-between gap-3">
          <div>
            <button v-if="modalMode !== 'new'" class="btn btn-danger" type="button" @click="remove" :disabled="modalLoading">Hapus</button>
          </div>
          <div class="flex items-center gap-2">
            <button class="btn btn-secondary" type="button" @click="closeModal" :disabled="modalLoading">Batal</button>
            <button class="btn btn-primary" type="button" @click="save" :disabled="modalLoading">
              <LoadingSpinner v-if="modalLoading" size="sm" />
              <span v-else>Simpan</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Rekap modal -->
    <div v-if="recapOpen" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" @click.self="recapOpen = false">
      <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl border border-slate-200 dark:border-white/10 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-white/10 flex items-center justify-between">
          <div class="text-sm font-black text-slate-900 dark:text-slate-100">Kirim Rekap WA</div>
          <button class="h-9 w-9 rounded-xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/5" @click="recapOpen = false" type="button">✕</button>
        </div>
        <div class="p-5 space-y-3">
          <div>
            <label class="text-[11px] font-bold text-slate-500 dark:text-slate-400">Pilih POP</label>
            <select v-model="recapPop" class="input w-full">
              <option v-for="p in dropdowns.pops" :key="p" :value="p">{{ p }}</option>
            </select>
            <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">Rekap akan mengirim status: Baru/Survey/Proses</div>
          </div>
          <div class="flex items-center justify-end gap-2">
            <button class="btn btn-secondary" type="button" @click="recapOpen = false" :disabled="recapLoading">Batal</button>
            <button class="btn btn-primary" type="button" @click="sendRecap" :disabled="recapLoading">
              <LoadingSpinner v-if="recapLoading" size="sm" />
              <span v-else>Kirim</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

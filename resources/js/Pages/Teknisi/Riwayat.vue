<script setup>
import { ref, computed, onMounted, watch, inject } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
  techName: { type: String, default: 'Teknisi' },
  techRole: { type: String, default: 'teknisi' },
  initialFilters: { type: Object, default: () => ({}) },
})

const toast = inject('toast', null)
const API_BASE = '/api/v1'
const page = usePage()

const history = ref([])
const loading = ref(false)
const savingEdit = ref(false)
const showEditModal = ref(false)
const editTarget = ref(null)

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

const authUser = computed(() => page.props?.auth?.user || null)
const authPermissionSet = computed(() => {
  const list = authUser.value?.permissions
  if (!Array.isArray(list)) return new Set()
  return new Set(list.map((x) => String(x || '').trim().toLowerCase()).filter(Boolean))
})

const hasPermission = (perm) => authPermissionSet.value.has(String(perm || '').trim().toLowerCase())
const canEditRiwayat = computed(() => {
  const role = String(authUser.value?.role || '').trim().toLowerCase()
  if (role === 'admin' || role === 'owner') return true
  return hasPermission('edit teknisi') || hasPermission('edit installations')
})

const editForm = ref({
  id: null,
  customer_name: '',
  customer_phone: '',
  address: '',
  pop: '',
  plan_name: '',
  price: '',
  installation_date: '',
  status: 'Selesai',
  notes: '',
  finished_at: '',
})

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

const totalDone = computed(() =>
  filteredHistory.value.filter((item) => String(item?.status || '') === 'Selesai').length
)

const totalCanceled = computed(() =>
  filteredHistory.value.filter((item) => String(item?.status || '') === 'Batal').length
)

function clearSearch() {
  filters.value.q = ''
}

function pickString(obj, ...keys) {
  for (const key of keys) {
    if (obj?.[key] !== undefined && obj?.[key] !== null) {
      return String(obj[key])
    }
  }
  return ''
}

function pickNumber(obj, ...keys) {
  for (const key of keys) {
    const raw = obj?.[key]
    if (raw !== undefined && raw !== null && String(raw).trim() !== '') {
      const n = Number(raw)
      if (!Number.isNaN(n)) return n
    }
  }
  return 0
}

function formatRupiahTyping(value, prefix = 'Rp. ') {
  const numberString = String(value || '').replace(/[^,\d]/g, '')
  const split = numberString.split(',')
  const sisa = split[0].length % 3
  let rupiah = split[0].substr(0, sisa)
  const ribuan = split[0].substr(sisa).match(/\d{3}/gi)
  if (ribuan) {
    const separator = sisa ? '.' : ''
    rupiah += separator + ribuan.join('.')
  }
  rupiah = split[1] !== undefined ? `${rupiah},${split[1]}` : rupiah
  if (!rupiah) return ''
  return prefix ? `${prefix}${rupiah}` : rupiah
}

function priceInputFromItem(item) {
  const amount = pickNumber(item, 'price', 'harga')
  if (amount <= 0) return ''
  return formatRupiahTyping(String(amount), 'Rp. ')
}

function normalizePhoneForSave(raw) {
  let phone = String(raw || '').replace(/[^0-9]/g, '')
  if (!phone) return ''
  if (phone.startsWith('0')) phone = `62${phone.slice(1)}`
  else if (phone.startsWith('8')) phone = `62${phone}`
  return phone
}

function parsePriceForSave(raw) {
  const digits = String(raw || '').replace(/[^0-9]/g, '')
  return digits ? Number.parseInt(digits, 10) : 0
}

function openEditModal(item) {
  if (!canEditRiwayat.value) {
    notify('Role ini tidak punya izin edit data riwayat.', 'error')
    return
  }

  editTarget.value = item
  editForm.value = {
    id: Number(item?.id || 0) || null,
    customer_name: pickString(item, 'customer_name', 'nama').trim(),
    customer_phone: pickString(item, 'customer_phone', 'wa').trim(),
    address: pickString(item, 'address', 'alamat'),
    pop: pickString(item, 'pop').trim(),
    plan_name: pickString(item, 'plan_name', 'paket').trim(),
    price: priceInputFromItem(item),
    installation_date: pickString(item, 'installation_date', 'tanggal').slice(0, 10),
    status: ['Selesai', 'Batal'].includes(String(item?.status || '')) ? String(item?.status) : 'Selesai',
    notes: pickString(item, 'notes', 'catatan'),
    finished_at: itemFinishedAt(item),
  }
  showEditModal.value = true
}

function closeEditModal() {
  if (savingEdit.value) return
  showEditModal.value = false
  editTarget.value = null
  editForm.value = {
    id: null,
    customer_name: '',
    customer_phone: '',
    address: '',
    pop: '',
    plan_name: '',
    price: '',
    installation_date: '',
    status: 'Selesai',
    notes: '',
    finished_at: '',
  }
}

function formatPriceInput() {
  editForm.value.price = formatRupiahTyping(editForm.value.price, 'Rp. ')
}

async function saveEdit() {
  if (!canEditRiwayat.value) {
    notify('Role ini tidak punya izin edit data riwayat.', 'error')
    return
  }
  const id = Number(editForm.value.id || 0)
  if (!id) {
    notify('Data riwayat tidak valid.', 'error')
    return
  }

  savingEdit.value = true
  try {
    const payload = {
      customer_name: String(editForm.value.customer_name || '').trim(),
      customer_phone: normalizePhoneForSave(editForm.value.customer_phone),
      address: String(editForm.value.address || '').trim(),
      pop: String(editForm.value.pop || '').trim(),
      plan_name: String(editForm.value.plan_name || '').trim(),
      price: parsePriceForSave(editForm.value.price),
      installation_date: String(editForm.value.installation_date || '').trim(),
      status: String(editForm.value.status || '').trim() || 'Selesai',
      notes: String(editForm.value.notes || '').trim(),
      finished_at: String(editForm.value.finished_at || '').trim(),
    }

    const res = await fetch(`${API_BASE}/installations/${id}`, {
      method: 'PUT',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    const data = await res.json()
    if (!res.ok || !(data?.status === 'success' || data?.success === true)) {
      throw new Error(data?.msg || data?.message || 'Gagal menyimpan perubahan')
    }

    notify('Data riwayat berhasil diperbarui.', 'success')
    closeEditModal()
    await loadHistory()
  } catch (e) {
    notify(e?.message || 'Gagal menyimpan perubahan', 'error')
  } finally {
    savingEdit.value = false
  }
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

            <div v-if="canEditRiwayat" class="pt-1">
              <button
                type="button"
                class="w-full h-10 rounded-xl border border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-200 text-xs font-black tracking-wide hover:bg-blue-100 dark:hover:bg-blue-500/20 transition disabled:opacity-60"
                :disabled="savingEdit"
                @click="openEditModal(item)"
              >
                Edit Data Riwayat
              </button>
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

      <div
        v-if="showEditModal"
        class="fixed inset-0 z-[70] bg-slate-900/70 backdrop-blur-sm flex items-end sm:items-center justify-center p-0 sm:p-4"
        @click.self="closeEditModal"
      >
        <div class="w-full sm:max-w-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-t-2xl sm:rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[92vh]">
          <div class="px-4 sm:px-5 py-4 border-b border-slate-200 dark:border-white/10 flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h3 class="text-base font-black text-slate-900 dark:text-slate-100">Edit Data Riwayat</h3>
              <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 truncate">
                #{{ editTarget?.ticket_id || editForm.id || '-' }} • {{ editTarget?.nama || editTarget?.customer_name || '-' }}
              </p>
            </div>
            <button
              type="button"
              class="h-9 w-9 rounded-xl border border-slate-200 dark:border-white/10 text-slate-500 dark:text-slate-300 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 transition"
              :disabled="savingEdit"
              @click="closeEditModal"
            >
              ✕
            </button>
          </div>

          <div class="px-4 sm:px-5 py-4 overflow-y-auto space-y-3 bg-slate-50/70 dark:bg-slate-900/40">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Nama Pelanggan</label>
                <input v-model="editForm.customer_name" type="text" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none" />
              </div>
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">No WA</label>
                <input v-model="editForm.customer_phone" type="text" inputmode="numeric" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none" />
              </div>
            </div>

            <div>
              <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Alamat</label>
              <textarea v-model="editForm.address" rows="2" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl p-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">POP</label>
                <input v-model="editForm.pop" type="text" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none" />
              </div>
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Paket</label>
                <input v-model="editForm.plan_name" type="text" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none" />
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Harga</label>
                <input v-model="editForm.price" @input="formatPriceInput" type="text" inputmode="numeric" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none" />
              </div>
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Tanggal Install</label>
                <input v-model="editForm.installation_date" type="date" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none" />
              </div>
              <div>
                <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Status</label>
                <select v-model="editForm.status" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none">
                  <option value="Selesai">Selesai</option>
                  <option value="Batal">Batal</option>
                </select>
              </div>
            </div>

            <div>
              <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase mb-1 block">Catatan</label>
              <textarea v-model="editForm.notes" rows="3" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl p-3 text-sm font-semibold text-slate-700 dark:text-slate-100 outline-none"></textarea>
            </div>
          </div>

          <div class="px-4 sm:px-5 py-3 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 flex items-center justify-end gap-2">
            <button
              type="button"
              class="h-10 px-4 rounded-xl border border-slate-300 dark:border-white/10 text-slate-700 dark:text-slate-200 text-sm font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition disabled:opacity-60"
              :disabled="savingEdit"
              @click="closeEditModal"
            >
              Batal
            </button>
            <button
              type="button"
              class="h-10 px-4 rounded-xl bg-blue-600 text-white text-sm font-black tracking-wide hover:bg-blue-700 transition disabled:opacity-60"
              :disabled="savingEdit"
              @click="saveEdit"
            >
              {{ savingEdit ? 'Menyimpan...' : 'Simpan Perubahan' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

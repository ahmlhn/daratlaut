<script setup>
import { ref, computed, onMounted, watch, inject } from 'vue'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
  techName: { type: String, default: 'Teknisi' },
  techPop: { type: String, default: '' },
  techRole: { type: String, default: 'teknisi' },
  initialDate: { type: String, default: '' },
})

const toast = inject('toast', null)
const API_BASE = '/api/v1'
const PER_PAGE = 200
const EXPENSE_SAVE_DEBOUNCE_MS = 800

const loading = ref(false)
const jobs = ref([])
const expenses = ref([])
const groups = ref([])

const selectedDate = ref(props.initialDate || '')
const showExpenseInput = ref(false)
const expenseInput = ref('')
const expenseStatus = ref('')
const expenseBusy = ref(false)
const expenseSaveTimer = ref(null)
const expenseStatusTimer = ref(null)
const syncingExpenseInput = ref(false)

const showGroupModal = ref(false)
const selectedGroupId = ref('')
const proofFile = ref(null)
const sendingGroup = ref(false)

function notify(message, type = 'error') {
  const msg = String(message || '').trim() || 'Terjadi kesalahan'
  if (toast && typeof toast[type] === 'function') {
    toast[type](msg)
    return
  }
  if (type === 'error') console.error(msg)
  else console.log(msg)
}

function pad2(num) {
  return String(num).padStart(2, '0')
}

function toDateInput(date) {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`
}

function isBlankValue(value) {
  const v = String(value || '').trim().toLowerCase()
  return v === '' || v === '-' || v === 'null'
}

function formatRupiah(value) {
  const num = Number.parseInt(String(value || '0').replace(/\D/g, ''), 10)
  if (Number.isNaN(num)) return 'Rp. 0'
  return `Rp. ${num.toLocaleString('id-ID')}`
}

function parseRupiahValue(raw) {
  const digits = String(raw || '').replace(/\D/g, '')
  return digits ? Number.parseInt(digits, 10) : 0
}

function normalizeExpenseItems(items) {
  const safe = []
  const list = Array.isArray(items) ? items : []
  list.forEach((item) => {
    if (!item || typeof item !== 'object') return
    const name = String(item.name || '').trim()
    const amount = parseRupiahValue(item.amount || 0)
    if (name === '' && amount <= 0) return
    if (amount <= 0) return
    safe.push({ name: name || 'Pengeluaran', amount })
  })
  return safe
}

function parseExpenseSmartInput(raw) {
  const text = String(raw || '').trim()
  if (!text) return []

  const rows = text.split(/\r?\n/)
  const parts = []
  rows.forEach((row) => {
    row.split(',').forEach((part) => {
      const trimmed = part.trim()
      if (trimmed) parts.push(trimmed)
    })
  })

  const parsed = []
  parts.forEach((line) => {
    const amount = parseRupiahValue(line)
    if (!amount) return
    let name = line.replace(/rp\.?/gi, '')
    name = name.replace(/[\d.,]/g, ' ').replace(/[:\-]+/g, ' ')
    name = name.replace(/\s+/g, ' ').trim()
    parsed.push({ name: name || 'Pengeluaran', amount })
  })

  return normalizeExpenseItems(parsed)
}

function formatExpenseSmartInput(items) {
  return normalizeExpenseItems(items).map((item) => `${item.name} ${formatRupiah(item.amount)}`).join('\n')
}

function getJobName(job) {
  return String(job?.customer_name ?? job?.nama ?? '-')
}

function getJobPhone(job) {
  return String(job?.customer_phone ?? job?.wa ?? '-')
}

function getJobAddress(job) {
  return String(job?.address ?? job?.alamat ?? '-')
}

function getJobPrice(job) {
  return parseRupiahValue(job?.price ?? job?.harga ?? 0)
}

function getJobTeams(job) {
  const techTeam = [
    job?.technician,
    job?.technician_2,
    job?.technician_3,
    job?.technician_4,
    job?.teknisi_1,
    job?.teknisi_2,
    job?.teknisi_3,
    job?.teknisi_4,
  ].filter((name, idx, arr) => {
    if (isBlankValue(name)) return false
    const norm = String(name).trim().toLowerCase()
    return arr.findIndex((x) => String(x || '').trim().toLowerCase() === norm) === idx
  }).join(', ')

  const salesTeam = [
    job?.sales_name,
    job?.sales_name_2,
    job?.sales_name_3,
    job?.sales_1,
    job?.sales_2,
    job?.sales_3,
  ].filter((name, idx, arr) => {
    if (isBlankValue(name)) return false
    const norm = String(name).trim().toLowerCase()
    return arr.findIndex((x) => String(x || '').trim().toLowerCase() === norm) === idx
  }).join(', ')

  return { techTeam, salesTeam }
}

function formatHeaderDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(`${dateStr}T00:00:00`)
  if (Number.isNaN(d.getTime())) return dateStr
  return d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

async function fetchJson(url, options = {}) {
  const opts = {
    credentials: 'same-origin',
    ...options,
    headers: { Accept: 'application/json', ...(options.headers || {}) },
  }
  if (opts.body && !(opts.body instanceof FormData) && !opts.headers['Content-Type']) {
    opts.headers['Content-Type'] = 'application/json'
  }

  const res = await fetch(url, opts)
  const text = await res.text()
  let data = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = null
  }
  if (!data || typeof data !== 'object') {
    throw new Error('Response JSON tidak valid')
  }
  if (!res.ok || !(data.success === true || data.status === 'success')) {
    const base = String(data.message || data.msg || 'Request gagal')
    const detail = String(data.error || '').trim()
    throw new Error(detail ? `${base}: ${detail}` : base)
  }
  return data
}

function setExpenseStatus(message, tone = 'muted') {
  expenseStatus.value = message || ''
  if (expenseStatusTimer.value) {
    clearTimeout(expenseStatusTimer.value)
    expenseStatusTimer.value = null
  }
  if (message && tone === 'success') {
    expenseStatusTimer.value = setTimeout(() => {
      expenseStatus.value = ''
    }, 2000)
  }
}

async function loadRekap() {
  if (expenseSaveTimer.value) {
    clearTimeout(expenseSaveTimer.value)
    expenseSaveTimer.value = null
  }
  loading.value = true
  try {
    const params = new URLSearchParams({
      tech_name: props.techName,
      date: selectedDate.value,
      page: '1',
      per_page: String(PER_PAGE),
    })
    const data = await fetchJson(`${API_BASE}/teknisi/rekap?${params.toString()}`)

    jobs.value = Array.isArray(data.jobs) ? data.jobs : []
    groups.value = Array.isArray(data.groups) ? data.groups : []

    const loadedExpenses = normalizeExpenseItems(data.expenses)
    expenses.value = loadedExpenses
    syncingExpenseInput.value = true
    expenseInput.value = formatExpenseSmartInput(loadedExpenses)
    syncingExpenseInput.value = false

    if (loadedExpenses.length > 0) setExpenseStatus('Tersimpan', 'success')
    else setExpenseStatus('', 'muted')
  } catch (e) {
    jobs.value = []
    groups.value = []
    expenses.value = []
    syncingExpenseInput.value = true
    expenseInput.value = ''
    syncingExpenseInput.value = false
    setExpenseStatus('Gagal memuat', 'error')
    notify(e?.message || 'Gagal memuat rekap', 'error')
  } finally {
    loading.value = false
  }
}

function scheduleSaveExpenses() {
  if (syncingExpenseInput.value || !selectedDate.value) return
  if (expenseSaveTimer.value) clearTimeout(expenseSaveTimer.value)
  setExpenseStatus('Menyimpan...', 'muted')
  expenseSaveTimer.value = setTimeout(() => {
    saveExpenses()
  }, EXPENSE_SAVE_DEBOUNCE_MS)
}

async function saveExpenses() {
  if (expenseBusy.value) return
  expenseBusy.value = true
  try {
    const payload = {
      tech_name: props.techName,
      date: selectedDate.value,
      expenses: expenses.value,
    }
    await fetchJson(`${API_BASE}/teknisi/expenses`, {
      method: 'POST',
      body: JSON.stringify(payload),
    })
    if (expenses.value.length > 0) setExpenseStatus('Tersimpan', 'success')
    else setExpenseStatus('', 'muted')
  } catch (e) {
    setExpenseStatus('Gagal simpan', 'error')
  } finally {
    expenseBusy.value = false
  }
}

function toggleRekapExpenseInput() {
  showExpenseInput.value = !showExpenseInput.value
}

function copyRekap() {
  const text = rekapPreview.value
  if (!text) return
  navigator.clipboard.writeText(text)
  notify('Rekap disalin.', 'success')
}

function openRekapGroupModal() {
  showGroupModal.value = true
}

function closeRekapGroupModal() {
  if (sendingGroup.value) return
  showGroupModal.value = false
  selectedGroupId.value = ''
  proofFile.value = null
}

function onProofFileChange(event) {
  const file = event?.target?.files?.[0] || null
  proofFile.value = file
}

async function uploadRekapProofIfAny() {
  if (!proofFile.value) return ''
  const form = new FormData()
  form.append('file', proofFile.value)
  form.append('date', selectedDate.value)
  form.append('tech_name', props.techName)
  const data = await fetchJson(`${API_BASE}/teknisi/rekap/upload-proof`, {
    method: 'POST',
    body: form,
  })
  return String(data?.data?.file_url || data?.data?.file_path || '')
}

async function sendRekapToGroup() {
  if (!selectedGroupId.value) {
    notify('Pilih grup terlebih dahulu', 'error')
    return
  }
  sendingGroup.value = true
  try {
    const mediaUrl = await uploadRekapProofIfAny()
    const payload = {
      group_id: selectedGroupId.value,
      message: rekapPreview.value,
      recap_date: selectedDate.value,
      tech_name: props.techName,
      media_url: mediaUrl,
    }
    const data = await fetchJson(`${API_BASE}/teknisi/rekap/send`, {
      method: 'POST',
      body: JSON.stringify(payload),
    })
    notify(data?.message || data?.msg || 'Laporan terkirim ke grup WhatsApp', 'success')
    closeRekapGroupModal()
  } catch (e) {
    notify(e?.message || 'Gagal mengirim laporan', 'error')
  } finally {
    sendingGroup.value = false
  }
}

const headerDateLabel = computed(() => formatHeaderDate(selectedDate.value))

const incomeTotal = computed(() =>
  jobs.value.reduce((sum, job) => sum + getJobPrice(job), 0)
)

const expenseTotal = computed(() =>
  expenses.value.reduce((sum, item) => sum + parseRupiahValue(item?.amount || 0), 0)
)

const rekapPreview = computed(() => {
  const jobsList = Array.isArray(jobs.value) ? jobs.value : []
  const expenseList = Array.isArray(expenses.value) ? expenses.value : []

  let text = headerDateLabel.value ? `${headerDateLabel.value}.\n\n` : ''

  jobsList.forEach((job) => {
    const { techTeam, salesTeam } = getJobTeams(job)
    text += `${getJobName(job).toUpperCase()}\n`
    text += `${getJobPhone(job)}\n`
    text += `${getJobAddress(job).toUpperCase()}\n`
    text += `${(techTeam || '-').toUpperCase()}\n`
    text += `SALES : ${salesTeam || '-'}\n`
    text += `${formatRupiah(getJobPrice(job))}\n\n`
  })

  if (expenseList.length > 0) {
    text = text.trimEnd() + '\n\n'
    expenseList.forEach((item) => {
      text += `${item.name || 'Pengeluaran'} ${formatRupiah(item.amount)}\n`
    })
    text = text.trimEnd() + '\n\n'
  }

  if (jobsList.length > 0 || expenseList.length > 0) {
    text = text.trimEnd() + '\n\n'
    text += `Masuk : ${formatRupiah(incomeTotal.value)}\n`
    text += `Keluar : ${formatRupiah(expenseTotal.value)}\n`
    text += `Saldo : ${formatRupiah(incomeTotal.value - expenseTotal.value)}`
  }

  return text
})

watch(selectedDate, () => {
  loadRekap()
})

watch(expenseInput, () => {
  if (syncingExpenseInput.value) return
  expenses.value = parseExpenseSmartInput(expenseInput.value)
  scheduleSaveExpenses()
})

onMounted(() => {
  if (!selectedDate.value) selectedDate.value = toDateInput(new Date())
  if (typeof window.startTechnicianTracking === 'function') {
    window.startTechnicianTracking()
  }
  loadRekap()
})
</script>

<template>
  <Head title="Rekap Harian" />

  <AdminLayout>
    <div class="text-slate-800 dark:text-slate-100 pb-24">
      <div class="sticky top-0 z-30 w-full">
        <div class="absolute inset-x-0 top-0 bottom-0 bg-slate-50/95 dark:bg-slate-900/90 backdrop-blur border-b border-slate-200 dark:border-white/10 pointer-events-none"></div>
        <div class="relative max-w-3xl mx-auto px-3 sm:px-5 pb-2 sm:pb-3 pt-2 space-y-2">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-sm font-black text-slate-800 dark:text-slate-100">Rekap Harian</h2>
              <p class="text-[10px] text-slate-400 dark:text-slate-500 font-bold uppercase">Selesai Hari Ini</p>
            </div>
            <button
              type="button"
              class="h-10 w-10 flex items-center justify-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl text-slate-600 dark:text-slate-300 shadow-sm hover:bg-blue-50 dark:hover:bg-slate-800 transition active:scale-95"
              title="Refresh"
              @click="loadRekap"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
            </button>
          </div>

          <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2 items-center">
            <input v-model="selectedDate" type="date" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 text-xs font-bold rounded-xl px-3 shadow-sm outline-none text-slate-700 dark:text-slate-200">
            <div>
              <button
                v-if="!showExpenseInput"
                type="button"
                class="h-11 px-4 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold rounded-xl text-xs border border-slate-200 dark:border-white/10 active:scale-95 transition"
                @click="toggleRekapExpenseInput"
              >
                Pengeluaran
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="max-w-3xl mx-auto pt-4">
        <div class="px-3 sm:px-5 space-y-3 sm:space-y-4 mt-1">
          <div v-if="loading" class="text-center py-10">
            <div class="animate-spin rounded-full h-10 w-10 border-b-4 border-blue-600 mx-auto"></div>
            <p class="text-sm text-slate-400 dark:text-slate-500 mt-3 font-bold">Memuat...</p>
          </div>

          <div v-if="showExpenseInput" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-4 space-y-2">
            <div class="text-xs font-black text-slate-700 dark:text-slate-200 uppercase">Input Pengeluaran</div>
            <textarea v-model="expenseInput" rows="4" class="w-full text-xs font-mono border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Contoh:&#10;Makan 30000&#10;Rokok 30000&#10;Bensin 30000"></textarea>
            <div class="text-[10px] text-slate-400 dark:text-slate-500">Tulis per baris atau dipisah koma.</div>
            <div :class="[
              'text-[10px]',
              expenseStatus === 'Tersimpan' ? 'text-emerald-600 dark:text-emerald-400' : '',
              expenseStatus === 'Gagal simpan' || expenseStatus === 'Gagal memuat' ? 'text-rose-500 dark:text-rose-400' : '',
              expenseStatus !== 'Tersimpan' && expenseStatus !== 'Gagal simpan' && expenseStatus !== 'Gagal memuat' ? 'text-slate-400 dark:text-slate-500' : '',
            ]">
              {{ expenseStatus }}
            </div>
            <button type="button" class="w-full h-10 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold" @click="toggleRekapExpenseInput">
              Selesai
            </button>
          </div>

          <div class="space-y-2 text-sm">
            <template v-if="jobs.length > 0">
              <div v-for="job in jobs" :key="job.id" class="flex justify-between border-b border-slate-100 dark:border-white/10 pb-2 mb-2">
                <div class="flex flex-col">
                  <span class="font-bold text-slate-700 dark:text-slate-200 text-xs">{{ getJobName(job) }}</span>
                  <span class="text-[10px] text-slate-400 dark:text-slate-500">{{ getJobTeams(job).techTeam || '-' }}</span>
                </div>
                <span class="font-bold text-blue-600 dark:text-blue-300 text-xs">{{ formatRupiah(getJobPrice(job)) }}</span>
              </div>
            </template>
            <div v-else class="text-sm text-slate-400 dark:text-slate-500 italic text-center py-4">Belum ada job.</div>
          </div>

          <div v-if="expenses.length > 0" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-4 space-y-2">
            <div class="text-xs font-black text-slate-700 dark:text-slate-200 uppercase">Pengeluaran</div>
            <div class="space-y-1 text-xs text-slate-600 dark:text-slate-300">
              <div v-for="(item, idx) in expenses" :key="`${idx}-${item.name}`" class="flex items-center justify-between">
                <span class="font-medium">{{ item.name }}</span>
                <span class="font-bold">{{ formatRupiah(item.amount) }}</span>
              </div>
            </div>
          </div>

          <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-4 space-y-3">
            <div class="flex items-center justify-between">
              <h4 class="text-xs font-black text-slate-700 dark:text-slate-200">Preview Rekap</h4>
              <div class="text-[10px] text-slate-400 dark:text-slate-500 font-bold">{{ jobs.length }} job</div>
            </div>
            <textarea :value="rekapPreview" readonly class="w-full h-52 text-xs font-mono border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-100"></textarea>
            <div class="grid grid-cols-2 gap-4">
              <button type="button" class="h-11 bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold rounded-xl text-xs active:scale-95 transition" @click="copyRekap">SALIN</button>
              <button type="button" class="h-11 bg-green-500 text-white font-bold rounded-xl text-xs shadow-lg active:scale-95 transition" @click="openRekapGroupModal">KIRIM LAPORAN</button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="showGroupModal" class="fixed inset-0 bg-black/50 z-[60] flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeRekapGroupModal">
        <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl shadow-2xl border border-slate-200 dark:border-white/10 overflow-hidden flex flex-col max-h-[90vh]">
          <div class="bg-slate-50 dark:bg-slate-900 px-6 py-5 border-b border-slate-200 dark:border-white/10 flex justify-between items-center">
            <h3 class="font-bold text-lg text-slate-800 dark:text-slate-100">Kirim Laporan ke Grup</h3>
            <button type="button" class="h-9 w-9 flex items-center justify-center rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-slate-500 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition" @click="closeRekapGroupModal">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          <div class="p-6 space-y-4 overflow-y-auto">
            <div>
              <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Pilih Grup WhatsApp</label>
              <select v-model="selectedGroupId" class="w-full h-11 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 text-sm font-semibold rounded-xl px-3 shadow-sm outline-none text-slate-700 dark:text-slate-200">
                <option value="">-- Pilih Grup --</option>
                <option v-for="grp in groups" :key="`${grp.id}-${grp.group_id}`" :value="grp.group_id">{{ grp.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Isi Laporan</label>
              <textarea :value="rekapPreview" readonly class="w-full h-40 text-xs font-mono border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300"></textarea>
              <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl p-3 mt-3">
                <div class="text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase">Bukti Transfer</div>
                <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="mt-2 w-full text-[11px] text-slate-600 dark:text-slate-300" @change="onProofFileChange">
                <div class="text-[10px] text-slate-400 mt-1">Opsional. JPG/PNG/PDF. Disertakan ke pesan rekap.</div>
              </div>
            </div>
          </div>
          <div class="p-5 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 grid grid-cols-2 gap-3">
            <button type="button" class="h-11 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold rounded-lg text-sm border border-slate-200 dark:border-white/10 active:scale-95 transition" :disabled="sendingGroup" @click="closeRekapGroupModal">BATAL</button>
            <button type="button" class="h-11 bg-green-500 text-white font-bold rounded-lg text-sm shadow-lg active:scale-95 transition disabled:opacity-60" :disabled="sendingGroup" @click="sendRekapToGroup">{{ sendingGroup ? 'MENGIRIM...' : 'KIRIM' }}</button>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

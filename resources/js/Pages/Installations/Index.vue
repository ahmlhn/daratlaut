
<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch, inject } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const toast = inject('toast')
const page = usePage()

const actorName = computed(() => String(page.props?.auth?.user?.name || sessionStorage.getItem('actor_name') || 'System'))
const actorRole = computed(() => String(page.props?.auth?.user?.role || sessionStorage.getItem('actor_role') || 'system').toLowerCase())

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

const pageStart = computed(() => (pagination.total === 0 ? 0 : (pagination.page - 1) * pagination.per_page + 1))
const pageEnd = computed(() => Math.min(pagination.total, (pagination.page - 1) * pagination.per_page + list.value.length))

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

const isCustomDate = computed(() => filters.date_preset === 'custom')

function statusBadgeClass(status) {
  const s = String(status || '')
  if (s === 'Baru') return 'bg-blue-50 text-blue-600 border border-blue-100'
  if (s === 'Survey') return 'bg-purple-50 text-purple-600 border border-purple-100'
  if (s === 'Proses') return 'bg-indigo-50 text-indigo-600 border border-indigo-100'
  if (s === 'Selesai') return 'bg-green-50 text-green-600 border border-green-100'
  if (s === 'Pending') return 'bg-orange-50 text-orange-600 border border-orange-100'
  if (s === 'Batal') return 'bg-red-50 text-red-600 border border-red-100'
  if (s === 'Req_Batal') return 'bg-red-100 text-red-700 border border-red-200 animate-pulse'
  return 'bg-slate-100 text-slate-600'
}

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

function rowDays(row) {
  return daysSinceInstall(row?.installation_date)
}

function rowOverdue(row) {
  const days = rowDays(row)
  return typeof days === 'number' && days > 0 && !isStatusClosed(row?.status)
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
    filters.status = value || ''
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

function applyDatePresetChange() {
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
const detailLoading = ref(false)
const modalSaving = ref(false)
const modalMode = ref('edit') // edit | new
const changeLoading = ref(false)
const changeHistory = ref([])
const changeError = ref(false)

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
const notesLines = computed(() =>
  String(form.notes_old || '')
    .split(/\r?\n/)
    .map((l) => String(l || '').trim())
    .filter((l) => l !== '')
)

const changeFieldLabels = {
  customer_name: 'Nama',
  customer_phone: 'WA',
  address: 'Alamat',
}

function changeFieldLabel(fieldName) {
  const key = String(fieldName || '').trim()
  return changeFieldLabels[key] || key || '-'
}

// =========================
// Smart input (native parity)
// =========================
const SMART_INPUT_DEBOUNCE_MS = 500
let smartInputTimer = null

const smartInputRaw = ref('')
const smartStatusMsg = ref('')
const smartStatusTone = ref('idle') // idle | success | error
const smartSummaryText = ref('')
const smartReadyMsg = ref('')
const smartReadyTone = ref('idle') // idle | ready | warn | error
const smartReviewRows = ref([])

function setSmartInputStatus(message, tone = 'idle') {
  const msg = String(message || '')
  if (!msg.trim()) {
    smartStatusMsg.value = ''
    smartStatusTone.value = 'idle'
    return
  }
  smartStatusMsg.value = msg
  smartStatusTone.value = tone || 'idle'
}

function setSmartInputSummary(labels, warnings = []) {
  const parts = []
  if (labels && labels.length > 0) parts.push(`Terbaca: ${labels.join(', ')}`)
  if (warnings && warnings.length > 0) parts.push(warnings.join(' | '))
  smartSummaryText.value = parts.join(' | ')
}

function setSmartInputReady(message, tone = 'idle') {
  const msg = String(message || '')
  if (!msg.trim()) {
    smartReadyMsg.value = ''
    smartReadyTone.value = 'idle'
    return
  }
  smartReadyMsg.value = msg
  smartReadyTone.value = tone || 'idle'
}

function resetSmartInputUI() {
  smartInputRaw.value = ''
  smartReviewRows.value = []
  setSmartInputStatus('')
  setSmartInputSummary([])
  setSmartInputReady('')
}

function normalizeName(value) {
  return String(value || '').trim().toLowerCase()
}

function splitNames(raw) {
  return String(raw || '')
    .split(',')
    .map((s) => s.replace(/\(.*?\)/g, '').trim())
    .filter(Boolean)
}

function normalizePriceValue(raw) {
  const digits = String(raw || '').replace(/\D/g, '')
  if (!digits) return ''
  let num = parseInt(digits, 10)
  if (Number.isNaN(num) || num <= 0) return ''
  if (digits.length <= 3) num = num * 1000
  return String(num)
}

function normalizeDateInput(raw) {
  const s = String(raw || '').trim()
  if (!s) return ''
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s
  const m = s.match(/^(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})$/)
  if (m) return `${m[3]}-${m[2]}-${m[1]}`
  return ''
}

function findListMatch(list, value) {
  const needle = normalizeName(value)
  if (!needle) return ''
  const items = Array.isArray(list) ? list : []
  const exact = items.find((x) => normalizeName(x) === needle)
  if (exact) return exact
  const starts = items.filter((x) => normalizeName(x).startsWith(needle))
  if (starts.length === 1) return starts[0]
  const includes = items.filter((x) => normalizeName(x).includes(needle))
  if (includes.length === 1) return includes[0]
  return ''
}

function parseSmartInput(raw) {
  const result = {
    name: '',
    phone: '',
    address: '',
    pop: '',
    plan: '',
    price: '',
    sales: [],
    techs: [],
    coords: '',
    date: '',
  }

  const freeLines = []
  const keySet = new Set([
    'sales',
    'sales1',
    'sales2',
    'sales3',
    'pop',
    'paket',
    'plan',
    'planname',
    'harga',
    'biaya',
    'price',
    'nama',
    'name',
    'wa',
    'nomor',
    'whatsapp',
    'hp',
    'telp',
    'phone',
    'nomorwa',
    'nomorwhatsapp',
    'nomorhp',
    'nomortelp',
    'nomorphone',
    'nowa',
    'nohp',
    'alamat',
    'address',
    'maps',
    'lokasi',
    'tanggal',
    'jadwal',
    'install',
    'installationdate',
  ])

  const isLabelKey = (keyClean) => {
    if (!keyClean) return false
    if (keyClean.startsWith('teknisi') || keyClean.startsWith('psb')) return true
    if (keyClean.startsWith('koordinat') || keyClean.startsWith('coord')) return true
    return keySet.has(keyClean)
  }

  const parseLabel = (text) => {
    let m = String(text || '').match(/^([^:=]{1,30})\s*[:=]\s*(.+)$/)
    if (!m) m = String(text || '').match(/^([a-zA-Z0-9.\s]{2,30})\s+(.+)$/)
    if (!m) return null
    const keyRaw = String(m[1] || '').trim().toLowerCase()
    const key = keyRaw.replace(/\s+/g, '')
    const keyClean = key.replace(/[^a-z0-9]/g, '')
    if (!isLabelKey(keyClean)) return null
    const val = String(m[2] || '').trim()
    if (!val) return null
    return { keyClean, val }
  }

  const lines = []
  const hasStrongFieldSignal = (parts) => {
    return parts.some((part) => {
      const token = String(part || '').trim()
      if (!token) return false
      const digits = token.replace(/\D/g, '')
      if (normalizeCoords(token)) return true
      if (digits.length >= 8) return true
      if (/^\d{3,}$/.test(token)) return true
      if (normalizeDateInput(token)) return true
      return false
    })
  }

  const pushSyntheticCsvFields = (parts) => {
    const map = [
      'Nama',
      'WA',
      'Alamat',
      'Sales',
      'POP',
      'Paket',
      'Harga',
    ]
    parts.forEach((val, idx) => {
      if (idx < map.length) lines.push(`${map[idx]}: ${val}`)
      else lines.push(val)
    })
  }

  String(raw || '')
    .split(/\r?\n/)
    .forEach((line) => {
      const l = String(line || '').trim()
      if (!l) return

      // Keep full line if it is already a valid label-value pair.
      // This prevents value truncation for cases like: "Alamat: Jl A, RT 02".
      if (parseLabel(l)) {
        lines.push(l)
        return
      }

      if (l.includes(',')) {
        const parts = l
          .split(',')
          .map((p) => p.trim())
          .filter(Boolean)

        if (!parts.length) return

        const parsedParts = parts.map((p) => parseLabel(p))
        const labelledCount = parsedParts.filter(Boolean).length

        // Multiple labelled pairs in one line: split and parse each pair.
        if (labelledCount >= 2) {
          parts.forEach((p) => {
            if (p) lines.push(p)
          })
          return
        }

        // Special case: "Teknisi DARWIN, MAKMUR" / "Sales A, B" (without ":")
        // Merge trailing comma parts into the same field value.
        if (labelledCount === 1 && parsedParts[0] && parts.slice(1).every((p) => !parseLabel(p))) {
          const parsedHead = parsedParts[0]
          const extra = parts.slice(1).join(', ')
          const key = parsedHead.keyClean.startsWith('teknisi') || parsedHead.keyClean.startsWith('psb')
            ? 'Teknisi'
            : parsedHead.keyClean.startsWith('sales')
              ? 'Sales'
              : ''
          if (key) {
            lines.push(`${key}: ${[parsedHead.val, extra].filter(Boolean).join(', ')}`)
            return
          }
        }

        // Unlabelled CSV mode (documented in UI): Name, WA, Address, Sales, POP, Plan, Price.
        // Only activate when we see strong numeric/date/coord signal to avoid splitting plain addresses.
        if (parts.length >= 3 && hasStrongFieldSignal(parts) && !normalizeCoords(l)) {
          pushSyntheticCsvFields(parts)
          return
        }

        // Two-part CSV shortcut for "Name, 08xxxx" or similar.
        if (parts.length === 2 && hasStrongFieldSignal(parts) && !normalizeCoords(l)) {
          parts.forEach((p) => lines.push(p))
          return
        }
      }
      lines.push(l)
    })

  lines.forEach((l) => {
    const parsed = parseLabel(l)
    if (parsed) {
      const keyClean = parsed.keyClean
      const val = parsed.val
      if (keyClean.startsWith('teknisi') || keyClean.startsWith('psb')) {
        result.techs = result.techs.concat(splitNames(val))
      } else if (keyClean === 'sales' || keyClean === 'sales1') {
        const sales = splitNames(val)
        sales.forEach((name, idx) => {
          if (idx < 3) result.sales[idx] = name
        })
      } else if (keyClean === 'sales2') result.sales[1] = val
      else if (keyClean === 'sales3') result.sales[2] = val
      else if (keyClean === 'pop') result.pop = val
      else if (keyClean === 'paket' || keyClean === 'plan' || keyClean === 'planname') result.plan = val
      else if (keyClean === 'harga' || keyClean === 'biaya' || keyClean === 'price') result.price = val
      else if (keyClean === 'nama' || keyClean === 'name') result.name = val
      else if (
        keyClean === 'wa' ||
        keyClean === 'nomor' ||
        keyClean === 'whatsapp' ||
        keyClean === 'hp' ||
        keyClean === 'telp' ||
        keyClean === 'phone' ||
        keyClean === 'nomorwa' ||
        keyClean === 'nomorwhatsapp' ||
        keyClean === 'nomorhp' ||
        keyClean === 'nomortelp' ||
        keyClean === 'nomorphone' ||
        keyClean === 'nowa' ||
        keyClean === 'nohp'
      )
        result.phone = val
      else if (keyClean === 'alamat' || keyClean === 'address') result.address = val
      else if (keyClean.startsWith('koordinat') || keyClean.startsWith('coord') || keyClean === 'maps' || keyClean === 'lokasi')
        result.coords = val
      else if (keyClean === 'tanggal' || keyClean === 'jadwal' || keyClean === 'install' || keyClean === 'installationdate') result.date = val
      else freeLines.push(l)
      return
    }
    freeLines.push(l)
  })

  const leftovers = freeLines.slice()
  for (let i = 0; i < leftovers.length; ) {
    const line = leftovers[i]
    const coord = normalizeCoords(line)
    if (!result.coords && coord) {
      result.coords = `${coord.lat},${coord.lng}`
      leftovers.splice(i, 1)
      continue
    }
    const digits = String(line || '').replace(/\D/g, '')
    const hasLetters = /[a-zA-Z]/.test(String(line || ''))
    if (!result.phone && digits.length >= 8) {
      result.phone = String(line || '')
      leftovers.splice(i, 1)
      continue
    }
    if (!result.price && !hasLetters && digits.length > 0) {
      result.price = String(line || '')
      leftovers.splice(i, 1)
      continue
    }
    i += 1
  }

  if (!result.name) {
    const idx = leftovers.findIndex((l) => /[a-zA-Z]/.test(String(l || '')))
    if (idx >= 0) result.name = leftovers.splice(idx, 1)[0]
  }
  if (!result.address && leftovers.length) result.address = leftovers.shift()
  if (!result.sales[0] && leftovers.length) result.sales[0] = leftovers.shift()
  if (!result.pop && leftovers.length) result.pop = leftovers.shift()

  return result
}

function renderSmartInputReview(parsed, options = {}) {
  if (!parsed) {
    smartReviewRows.value = []
    return
  }

  const phoneDigits = String(options.phoneDigits || '').trim()
  const priceValue = options.priceValue || normalizePriceValue(parsed.price)
  const salesList = Array.isArray(parsed.sales) ? parsed.sales.filter((v) => String(v || '').trim() !== '') : []
  const techListLocal = Array.isArray(parsed.techs) ? parsed.techs.filter((v) => String(v || '').trim() !== '') : []

  const formatRupiahValue = (raw) => {
    const num = parseInt(raw || 0, 10)
    if (Number.isNaN(num) || num <= 0) return ''
    return 'Rp. ' + num.toLocaleString('id-ID')
  }

  const fields = [
    { label: 'Nama', value: String(parsed.name || '').trim(), required: true },
    { label: 'WA', value: phoneDigits, required: true },
    { label: 'Alamat', value: String(parsed.address || '').trim(), required: true },
    { label: 'POP', value: String(parsed.pop || '').trim(), required: true },
    { label: 'Paket', value: String(parsed.plan || '').trim(), required: false },
    { label: 'Harga', value: priceValue ? formatRupiahValue(priceValue) : '', required: false },
    { label: 'Sales', value: salesList.join(', '), required: false },
    { label: 'Teknisi', value: techListLocal.join(', '), required: false },
    { label: 'Koordinat', value: String(options.coordsValue || '').trim(), required: false },
    { label: 'Tanggal', value: String(options.dateValue || '').trim(), required: false },
  ]

  smartReviewRows.value = fields.filter((item) => item.required || item.value).map((item) => ({ label: item.label, value: item.value || '-' }))
}

function applyProgressSmartInput(options = {}) {
  const raw = smartInputRaw.value || ''
  if (!raw.trim()) {
    setSmartInputSummary([])
    setSmartInputReady('')
    smartReviewRows.value = []
    if (!options.silent) setSmartInputStatus('Smart input kosong.', 'error')
    else setSmartInputStatus('')
    return
  }

  const parsed = parseSmartInput(raw)
  const filled = []
  const applied = []
  const warnings = []
  const phoneDigits = String(parsed.phone || '').replace(/\D/g, '')
  const coordParsed = parsed.coords ? normalizeCoords(parsed.coords) : null
  const coordsValue = coordParsed ? `${coordParsed.lat},${coordParsed.lng}` : ''
  const dateValue = parsed.date ? normalizeDateInput(parsed.date) : ''
  const priceValue = normalizePriceValue(parsed.price)

  const formatApplied = (label, value) => {
    const clean = String(value ?? '').trim()
    if (!clean) return
    const trimmed = clean.length > 40 ? clean.slice(0, 37) + '...' : clean
    applied.push(`${label}=${trimmed}`)
  }

  const setField = (key, val) => {
    const clean = String(val ?? '').trim()
    if (!clean) return ''
    form[key] = clean
    return clean
  }

  const setMatch = (key, list, val, label) => {
    if (!val) return ''
    const match = findListMatch(list, val)
    if (match) {
      form[key] = match
      return match
    }
    warnings.push(`${label} tidak ditemukan: ${val}`)
    return ''
  }

  if (parsed.name) {
    const v = setField('customer_name', parsed.name)
    if (v) {
      filled.push('Nama')
      formatApplied('Nama', v)
    }
  }
  if (parsed.phone) {
    const v = setField('customer_phone', parsed.phone)
    if (v) {
      filled.push('WA')
      formatApplied('WA', v)
    }
  }
  if (parsed.address) {
    const v = setField('address', parsed.address)
    if (v) {
      filled.push('Alamat')
      formatApplied('Alamat', v)
    }
  }

  if (parsed.coords) {
    if (coordsValue) {
      const v = setField('coordinates', coordsValue)
      if (v) {
        filled.push('Koordinat')
        formatApplied('Koordinat', v)
      }
    } else {
      warnings.push(`Koordinat tidak valid: ${parsed.coords}`)
    }
  }

  if (parsed.plan) {
    const v = setField('plan_name', parsed.plan)
    if (v) {
      filled.push('Paket')
      formatApplied('Paket', v)
    }
  }

  if (parsed.price && priceValue) {
    const v = setField('price', priceValue)
    if (v) {
      filled.push('Harga')
      formatApplied('Harga', v)
    }
  }

  if (parsed.date) {
    if (dateValue) {
      const v = setField('installation_date', dateValue)
      if (v) {
        filled.push('Tanggal')
        formatApplied('Tanggal', v)
      }
    } else {
      warnings.push(`Tanggal tidak valid: ${parsed.date}`)
    }
  }

  if (parsed.pop) {
    const v = setMatch('pop', dropdowns.pops, parsed.pop, 'POP')
    if (v) {
      filled.push('POP')
      formatApplied('POP', v)
    }
  }

  if (parsed.sales[0]) {
    const v = setField('sales_name', parsed.sales[0])
    if (v) {
      filled.push('Sales 1')
      formatApplied('Sales 1', v)
    }
  }
  if (parsed.sales[1]) {
    const v = setField('sales_name_2', parsed.sales[1])
    if (v) {
      filled.push('Sales 2')
      formatApplied('Sales 2', v)
    }
  }
  if (parsed.sales[2]) {
    const v = setField('sales_name_3', parsed.sales[2])
    if (v) {
      filled.push('Sales 3')
      formatApplied('Sales 3', v)
    }
  }

  const techKeys = ['technician', 'technician_2', 'technician_3', 'technician_4']
  parsed.techs.forEach((name, idx) => {
    if (idx >= techKeys.length) return
    const v = setMatch(techKeys[idx], dropdowns.technicians, name, 'Teknisi')
    if (v) {
      filled.push(`Teknisi ${idx + 1}`)
      formatApplied(`Teknisi ${idx + 1}`, v)
    }
  })

  const summaryLabels = []
  if (parsed.name) summaryLabels.push('Nama')
  if (phoneDigits) summaryLabels.push('WA')
  if (parsed.address) summaryLabels.push('Alamat')
  if (parsed.pop) summaryLabels.push('POP')
  if (parsed.plan) summaryLabels.push('Paket')
  if (priceValue) summaryLabels.push('Harga')
  if (Array.isArray(parsed.sales) && parsed.sales.some((v) => String(v || '').trim() !== '')) summaryLabels.push('Sales')
  if (Array.isArray(parsed.techs) && parsed.techs.some((v) => String(v || '').trim() !== '')) summaryLabels.push('Teknisi')
  if (coordsValue) summaryLabels.push('Koordinat')
  if (dateValue) summaryLabels.push('Tanggal')

  setSmartInputSummary(summaryLabels, warnings)
  renderSmartInputReview(parsed, { phoneDigits, coordsValue, dateValue, priceValue })

  const hasRequired = !!(parsed.name && phoneDigits.length >= 8 && parsed.address && parsed.pop)
  if (!hasRequired) setSmartInputReady('Belum lengkap', 'error')
  else if (warnings.length) setSmartInputReady('Periksa data', 'warn')
  else setSmartInputReady('Siap disimpan', 'ready')

  if (warnings.length) {
    setSmartInputStatus(warnings.join(' | '), 'error')
    return
  }

  if (!filled.length) {
    if (!options.silent) setSmartInputStatus('Tidak ada data yang cocok.', 'error')
    return
  }

  const detailText = applied.length ? `Terisi: ${applied.join(' | ')}` : `Terisi: ${filled.join(', ')}`
  setSmartInputStatus(detailText, 'success')
}

watch(smartInputRaw, () => {
  if (!modalOpen.value || modalMode.value !== 'new') return
  if (smartInputTimer) clearTimeout(smartInputTimer)
  smartInputTimer = setTimeout(() => {
    applyProgressSmartInput({ silent: true })
  }, SMART_INPUT_DEBOUNCE_MS)
})

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
  changeError.value = false
}

async function loadChangeHistory(id) {
  changeLoading.value = true
  changeError.value = false
  try {
    const json = await apiGetJson(`/api/v1/installations/${id}/history`)
    if (json.status === 'success') {
      changeHistory.value = json.data || []
      changeError.value = false
    } else {
      changeHistory.value = []
      changeError.value = true
    }
  } catch {
    changeHistory.value = []
    changeError.value = true
  } finally {
    changeLoading.value = false
  }
}

async function openCreateModal() {
  modalMode.value = 'new'
  detailLoading.value = false
  modalSaving.value = false
  resetFormForNew()
  resetSmartInputUI()
  modalOpen.value = true
  await loadDropdownsOnce()
}

async function openEditModal(id) {
  resetSmartInputUI()
  modalMode.value = 'edit'
  modalOpen.value = true
  detailLoading.value = true
  changeHistory.value = []
  changeError.value = false
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
    detailLoading.value = false
  }
}

function closeModal() {
  modalOpen.value = false
  if (smartInputTimer) {
    clearTimeout(smartInputTimer)
    smartInputTimer = null
  }
  resetSmartInputUI()
}

async function save() {
  if (modalSaving.value) {
    if (toast) toast.info('Sedang menyimpan data...')
    return
  }
  if (!String(form.customer_name || '').trim()) {
    if (toast) toast.error('Nama pelanggan wajib diisi.')
    return
  }

  modalSaving.value = true
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
    modalSaving.value = false
  }
}

async function remove() {
  if (!form.id) return
  if (!confirm('Hapus data ini? Tindakan ini tidak bisa dibatalkan.')) return
  modalSaving.value = true
  try {
    const json = await apiSendJson(`/api/v1/installations/${form.id}`, 'DELETE')
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal menghapus')
    if (toast) toast.success('Data berhasil dihapus.')
    closeModal()
    await loadData(pagination.page)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal menghapus')
  } finally {
    modalSaving.value = false
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

async function decideCancel(decision) {
  if (!form.id) return
  const note = prompt('Catatan (opsional):') || ''
  modalSaving.value = true
  try {
    const json = await apiSendJson(`/api/v1/installations/${form.id}/decide-cancel`, 'POST', { decision, reason: note })
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal memproses')
    if (toast) toast.success('Berhasil.')
    await openEditModal(form.id)
    await loadData(pagination.page)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal memproses')
  } finally {
    modalSaving.value = false
  }
}

// =========================
// Rekap (native parity: prompt + confirm)
// =========================
const recapSending = ref(false)

async function openRecapModal() {
  if (recapSending.value) {
    if (toast) toast.info('Sedang mengirim rekap...')
    return
  }

  const pops = (dropdowns.pops || []).map((p) => String(p || '').trim()).filter(Boolean)
  if (!pops.length) {
    if (toast) toast.error('Daftar POP belum termuat. Coba refresh dulu.')
    return
  }

  const currentPop = String(filters.pop || '').trim()
  const listText = pops.map((p, i) => `${i + 1}. ${p}`).join('\n')
  const pick = prompt(`Pilih POP (ketik nomor):\n${listText}`)
  const idx = parseInt(String(pick || '').trim(), 10)
  if (!idx || idx < 1 || idx > pops.length) return

  const chosenPop = pops[idx - 1]
  if (!chosenPop) return

  // Konfirmasi akhir (native)
  if (!confirm(`Kirim rekap WA untuk POP: ${chosenPop}?`)) return

  recapSending.value = true
  try {
    const json = await apiSendJson('/api/v1/installations/send-pop-recap', 'POST', { pop_name: chosenPop })
    if (json.status !== 'success') throw new Error(json.msg || 'Gagal mengirim rekap')
    const note = json.msg ? ` - ${json.msg}` : ''
    if (toast) toast.success(`Rekap terkirim (${json.count || 0} data, terkirim ${json.sent || 0}, gagal ${json.failed || 0})${note}`)
  } catch (e) {
    if (toast) toast.error(e.message || 'Gagal mengirim rekap')
  } finally {
    recapSending.value = false
  }
}

const keydownHandler = (e) => {
  if (e.key === 'Escape' && modalOpen.value) {
    closeModal()
  }
}

onMounted(() => {
  // Native parity: default preset = "Semua Tanggal" (empty). If URL provides a range, show as custom.
  if (filters.date_from || filters.date_to) {
    filters.date_preset = 'custom'
  } else {
    filters.date_preset = ''
  }
  loadData(1)
  document.addEventListener('keydown', keydownHandler)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', keydownHandler)
  if (smartInputTimer) {
    clearTimeout(smartInputTimer)
    smartInputTimer = null
  }
})
</script>

<template>
  <Head title="Monitoring Progress" />

  <AdminLayout>
    <div id="progress-root" class="flex flex-col gap-6 fade-in pb-20 md:pb-0" :data-actor-name="actorName" :data-actor-role="actorRole">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 border-b border-slate-200 dark:border-white/5 pb-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white tracking-tight">Monitoring Progress</h2>
                <p class="text-slate-500 dark:text-slate-400 text-xs md:text-sm mt-1">
                    Pusat kontrol pemasangan, validasi teknisi, dan approval.
                </p>
            </div>

            <div class="flex flex-wrap gap-2 w-full md:w-auto relative z-10 pointer-events-auto">
        <button type="button" @click="loadData(1)" class="group px-4 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-bold hover:bg-slate-50 transition shadow-sm flex items-center gap-2 pointer-events-auto">
            <svg class="w-4 h-4 group-hover:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Refresh
        </button>

        <button type="button" @click="openRecapModal" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold shadow-sm transition flex items-center gap-2 pointer-events-auto">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
            Rekap WA
        </button>

        <button type="button" @click="openCreateModal" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold shadow-lg shadow-blue-200 dark:shadow-none transition flex items-center gap-2 pointer-events-auto relative z-10">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Input Baru
        </button>
    </div>

        </div>

        <div class="flex flex-wrap gap-3 mb-4 transition-all duration-300">

            <div id="card-prio" @click="filterByCard('priority')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-yellow-50 hover:border-yellow-200 group">
                <div class="w-8 h-8 rounded-lg bg-yellow-50 text-yellow-600 flex items-center justify-center font-bold text-lg group-hover:scale-110 transition-transform">★</div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Prioritas</p>
                    <h3 id="stat-prio" class="text-lg font-black text-slate-800 dark:text-white">{{ summary.priority || 0 }}</h3>
                </div>
            </div>

            <div id="card-overdue" @click="filterByCard('overdue')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-red-50 hover:border-red-200 group">
                <div class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    ⏰
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Overdue</p>
                    <h3 id="stat-overdue" class="text-lg font-black text-red-600">{{ summary.overdue || 0 }}</h3>
                </div>
            </div>

            <div id="card-new" @click="filterByCard('status', 'Baru')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-blue-50 hover:border-blue-200 group">
                <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Baru</p>
                    <h3 id="stat-new" class="text-lg font-black text-blue-600">{{ summary.Baru || 0 }}</h3>
                </div>
            </div>

            <div id="card-survey" @click="filterByCard('status', 'Survey')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-purple-50 hover:border-purple-200 group">
                <div class="w-8 h-8 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 7m0 13V7"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Survey</p>
                    <h3 id="stat-survey" class="text-lg font-black text-purple-600">{{ summary.Survey || 0 }}</h3>
                </div>
            </div>

            <div id="card-process" @click="filterByCard('status', 'Proses')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-indigo-50 hover:border-indigo-200 group">
                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Proses</p>
                    <h3 id="stat-process" class="text-lg font-black text-indigo-600">{{ summary.Proses || 0 }}</h3>
                </div>
            </div>

            <div id="card-pending" @click="filterByCard('status', 'Pending')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-orange-50 hover:border-orange-200 group">
                <div class="w-8 h-8 rounded-lg bg-orange-50 text-orange-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Pending</p>
                    <h3 id="stat-pending" class="text-lg font-black text-orange-600">{{ summary.Pending || 0 }}</h3>
                </div>
            </div>

            <div id="card-req-cancel" @click="filterByCard('status', 'Req_Batal')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-pink-50 hover:border-pink-200 group">
                <div class="w-8 h-8 rounded-lg bg-pink-50 text-pink-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Req Batal</p>
                    <h3 id="stat-req-cancel" class="text-lg font-black text-pink-600">{{ summary.Req_Batal || 0 }}</h3>
                </div>
            </div>

            <div id="card-cancel" @click="filterByCard('status', 'Batal')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-red-50 hover:border-red-200 group">
                <div class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Batal</p>
                    <h3 id="stat-cancel" class="text-lg font-black text-red-600">{{ summary.Batal || 0 }}</h3>
                </div>
            </div>

            <div id="card-today-done" @click="filterByCard('today_done')" class="flex-1 min-w-[140px] bg-white dark:bg-slate-800 p-3 rounded-xl border border-slate-100 dark:border-white/5 shadow-sm flex items-center gap-3 transition-all cursor-pointer hover:bg-green-50 hover:border-green-200 group">
                <div class="w-8 h-8 rounded-lg bg-green-50 text-green-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-slate-400 uppercase truncate">Done Today</p>
                    <h3 id="stat-today-done" class="text-lg font-black text-green-600">{{ summary.today_done || 0 }}</h3>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-white/5 overflow-hidden flex flex-col">
            <div class="p-4 border-b border-slate-100 dark:border-white/5 bg-slate-50/50 dark:bg-slate-900/30">
                <!-- Search Bar -->
                <div class="w-full relative shrink-0 mb-3">
                    <input v-model="filters.search" type="text" id="search-progress" @keyup="applyFilters" placeholder="Cari tiket, nama, alamat, teknisi..." class="w-full pl-9 pr-3 py-2.5 rounded-lg text-xs font-medium border border-slate-300 dark:border-white/15 bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 transition shadow-sm hover:border-slate-400">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>

                <!-- Filters Container with Mobile Responsive -->
                <div class="flex flex-col lg:flex-row gap-3 lg:items-end">
                
                    <!-- Date Filter (Full Width on Mobile, Half on Tablet, Auto on Desktop) -->
                    <div class="w-full lg:w-auto lg:flex-1 max-w-lg">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <select v-model="filters.date_preset" id="filter-date-preset" @change="applyDatePresetChange" class="w-full h-10 border border-slate-300 dark:border-white/15 rounded-lg px-3 text-xs font-medium bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer transition hover:border-slate-400 dark:hover:border-white/20 shadow-sm">
                                <option value="">Semua Tanggal</option>
                                <option value="today">Hari ini</option>
                                <option value="yesterday">Kemarin</option>
                                <option value="this_week">Minggu ini</option>
                                <option value="this_month">Bulan ini</option>
                                <option value="this_year">Tahun ini</option>
                                <option value="last_year">Tahun kemarin</option>
                                <option value="last_7">7 hari terakhir</option>
                                <option value="last_30">30 hari terakhir</option>
                                <option value="custom">Custom Range</option>
                            </select>
                        </div>

                        <!-- Custom Date Range (Full Width on Mobile) -->
                        <div id="filter-date-custom" :class="[isCustomDate ? '' : 'hidden', 'mt-2 flex flex-col sm:flex-row gap-2 items-start sm:items-center px-3 py-2.5 bg-blue-50 dark:bg-blue-950/20 rounded-lg border border-blue-200 dark:border-blue-900/30']">
                            <span class="text-xs font-semibold text-blue-700 dark:text-blue-300">Dari:</span>
                            <input v-model="filters.date_from" type="date" id="filter-date-from" @change="applyFilters" class="flex-1 w-full sm:w-auto h-8 border border-blue-300 dark:border-blue-700 rounded px-2 text-xs font-medium bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 text-slate-600 dark:text-slate-300 transition hover:border-blue-400">
                        
                            <span class="text-xs font-semibold text-blue-700 dark:text-blue-300">Hingga:</span>
                            <input v-model="filters.date_to" type="date" id="filter-date-to" @change="applyFilters" class="flex-1 w-full sm:w-auto h-8 border border-blue-300 dark:border-blue-700 rounded px-2 text-xs font-medium bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 text-slate-600 dark:text-slate-300 transition hover:border-blue-400">
                        </div>
                    </div>

                    <!-- Other Filters in a Grid (2 cols on mobile, auto on desktop) -->
                    <div class="grid grid-cols-2 lg:grid-cols-3 gap-2 w-full lg:w-auto lg:flex lg:items-center lg:gap-2">
                    
                        <!-- Status Filter -->
                        <div class="flex items-center gap-1.5 lg:gap-2">
                            <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <select v-model="filters.status" id="filter-status-opt" @change="applyFilters" class="flex-1 h-10 lg:h-10 border border-slate-300 dark:border-white/15 rounded-lg px-2 lg:px-3 py-2 lg:py-2.5 text-xs font-medium text-slate-600 dark:text-slate-300 outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer transition hover:border-slate-400 dark:hover:border-white/20 shadow-sm">
                                <option value="">Status</option>
                                <option value="Baru">Baru</option>
                                <option value="Survey">Survey</option>
                                <option value="Proses">Proses</option>
                                <option value="Selesai">Selesai</option>
                                <option value="Pending">Pending</option>
                                <option value="Batal">Batal</option>
                                <option value="Req_Batal">Req Batal</option>
                            </select>
                        </div>

                        <!-- POP Filter -->
                        <div class="flex items-center gap-1.5 lg:gap-2">
                            <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <select v-model="filters.pop" id="filter-pop-opt" @change="applyFilters" class="flex-1 h-10 lg:h-10 border border-slate-300 dark:border-white/15 rounded-lg px-2 lg:px-3 py-2 lg:py-2.5 text-xs font-medium text-blue-600 dark:text-blue-400 outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer transition hover:border-slate-400 dark:hover:border-white/20 shadow-sm">
                                <option value="">Area</option>
                                <option v-for="p in dropdowns.pops" :key="p" :value="p">{{ p }}</option>
                            </select>
                        </div>

                        <!-- Technician Filter -->
                        <div class="flex items-center gap-1.5 lg:gap-2">
                            <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 12H9m4 5h4m0 0h4m-4 0a4 4 0 100-8 4 4 0 000 8z"/></svg>
                            <select v-model="filters.tech" id="filter-tech-opt" @change="applyFilters" class="flex-1 h-10 lg:h-10 border border-slate-300 dark:border-white/15 rounded-lg px-2 lg:px-3 py-2 lg:py-2.5 text-xs font-medium text-slate-600 dark:text-slate-300 outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer transition hover:border-slate-400 dark:hover:border-white/20 shadow-sm">
                                <option value="">Teknisi</option>
                                <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Reset Button (Full Width on Mobile, Auto on Desktop) -->
                    <button type="button" @click="resetFilters" class="w-full lg:w-auto px-3 lg:px-4 py-2.5 bg-gradient-to-r from-slate-100 to-slate-50 hover:from-slate-200 hover:to-slate-100 dark:from-slate-800 dark:to-slate-900 dark:hover:from-slate-700 dark:hover:to-slate-800 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-semibold transition flex items-center justify-center lg:justify-start gap-2 border border-slate-200 dark:border-white/10 shadow-sm hover:shadow-md hover:scale-105 active:scale-95" title="Reset semua filter">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Reset
                    </button>
                </div>

                <!-- legacy single-date input (hidden) for backward compatibility -->
                <input type="date" id="filter-date" class="hidden">

            </div>

            <div class="overflow-x-auto min-h-[350px]">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 dark:bg-slate-800/50 text-xs uppercase text-slate-500 dark:text-slate-400 font-bold border-b border-slate-100 dark:border-white/5">
                        <tr>
                            <th class="px-4 py-3 text-center w-10">★</th>
                            <th class="px-6 py-3">Tiket & Waktu</th>
                            <th class="px-6 py-3">Pelanggan</th>
                            <th class="px-6 py-3">Area (POP)</th>
                            <th class="px-6 py-3">Teknisi</th>
                            <th class="px-6 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody id="progress-table-body" class="divide-y divide-slate-100 dark:divide-white/5">
                        <tr v-if="loading">
                            <td colspan="7" class="px-6 py-10 text-center">
                                <div class="flex justify-center">
                                    <div class="w-8 h-8 border-4 border-slate-200 border-t-blue-600 rounded-full animate-spin"></div>
                                </div>
                                <p class="mt-2 text-xs text-slate-400">Sedang memuat data...</p>
                            </td>
                        </tr>
                        <tr v-else-if="list.length === 0">
                            <td colspan="7" class="px-6 py-10 text-center text-slate-400 italic text-xs">Tidak ada data ditemukan.</td>
                        </tr>
                        <tr
                            v-else
                            v-for="row in list"
                            :key="row.id"
                            :id="`row-${row.id}`"
                            class="transition border-b border-slate-50 dark:border-white/5 cursor-pointer group"
                            :class="rowOverdue(row) ? 'bg-red-50/30 dark:bg-red-900/10 border-l-4 border-l-red-500' : 'hover:bg-blue-50/50 dark:hover:bg-slate-800/50'"
                            @click="openEditModal(row.id)"
                        >
                            <td class="px-4 py-3 text-center">
                                <button
                                    type="button"
                                    @click.stop="togglePriority(row.id, row.is_priority)"
                                    class="transition-transform p-2"
                                    :class="String(row.is_priority) === '1' ? 'text-yellow-400 hover:scale-110' : 'text-slate-200 hover:text-yellow-400'"
                                >★</button>
                            </td>
                            <td class="px-6 py-3">
                                <div class="flex items-center flex-wrap gap-1">
                                    <div class="font-mono text-[10px] text-blue-500 font-bold group-hover:text-blue-700 transition">#{{ row.ticket_id || '-' }}</div>
                                    <span
                                        v-if="rowDays(row) !== null"
                                        class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-bold"
                                        :class="(rowDays(row) || 0) > 0 ? 'bg-slate-200 text-slate-700' : 'bg-blue-100 text-blue-700'"
                                    >D+{{ Math.max(0, rowDays(row) || 0) }}</span>
                                    <span
                                        v-if="rowOverdue(row)"
                                        class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-black bg-red-100 text-red-700"
                                    >⏰ OVERDUE +{{ rowDays(row) }}d</span>
                                </div>
                                <div class="text-[10px] text-slate-400">{{ formatDateShort(row.installation_date) }}</div>
                            </td>
                            <td class="px-6 py-3">
                                <div class="font-bold text-slate-700 dark:text-slate-200 text-xs">{{ row.customer_name || '-' }}</div>
                                <div class="text-[10px] text-slate-400 truncate max-w-[150px]">{{ row.address || '-' }}</div>
                            </td>
                            <td class="px-6 py-3 text-xs text-slate-600 dark:text-slate-300">
                                <span class="px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 font-bold text-[10px]">{{ row.pop || 'NON-AREA' }}</span>
                            </td>
                            <td class="px-6 py-3 text-xs">
                                <div v-if="row.technician" class="flex items-center gap-1">
                                    <div class="w-2 h-2 rounded-full bg-green-500"></div>
                                    <span>{{ row.technician }}</span>
                                </div>
                                <span v-else class="text-slate-300 italic">Belum assign</span>
                            </td>
                            <td class="px-6 py-3">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold" :class="statusBadgeClass(row.status)">{{ row.status || '-' }}</span>
                            </td>
                        </tr>
    
                    </tbody>
                </table>
            </div>

            <div class="p-4 border-t border-slate-100 dark:border-white/5 bg-slate-50/30 flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="text-xs text-slate-500 font-medium">
                    Data <span id="page-start" class="font-bold text-slate-700 dark:text-white">{{ pageStart }}</span> -
                    <span id="page-end" class="font-bold text-slate-700 dark:text-white">{{ pageEnd }}</span> dari
                    <span id="total-data" class="font-bold text-slate-700 dark:text-white">{{ pagination.total }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="loadData(pagination.page - 1)" :disabled="pagination.page <= 1 || loading" id="btn-prev" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition">Prev</button>
                    <span class="text-xs font-bold text-slate-600 dark:text-slate-300 bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 px-3 py-1.5 rounded-lg">
                        <span id="current-page">{{ pagination.page }}</span> / <span id="total-pages">{{ pagination.total_pages }}</span>
                    </span>
                    <button type="button" @click="loadData(pagination.page + 1)" :disabled="pagination.page >= pagination.total_pages || loading" id="btn-next" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition">Next</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== -->
    <!-- MODAL DETAIL / INPUT -->
    <!-- ===================== -->
    <div id="detail-modal" :class="[modalOpen ? '' : 'hidden', 'fixed inset-0 z-[9999] bg-black/50 p-4 overflow-y-auto']">
        <div class="max-w-5xl mx-auto bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200 dark:border-white/10 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 dark:border-white/10 flex items-center justify-between">
                <div class="min-w-0">
                    <div class="text-[11px] text-slate-400 font-bold" id="modal-ticket-id">{{ modalMode === 'new' ? '#NEW' : ('#' + (form.ticket_id || '-')) }}</div>
                    <h3 class="text-lg font-black text-slate-800 dark:text-white" id="modal-title-text">{{ modalMode === 'new' ? 'Input Baru' : 'Detail Data' }}</h3>
                </div>
                <button type="button" @click="closeModal" class="px-3 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black">
                    Tutup
                </button>
            </div>

            <form id="form-edit-install" class="p-5 space-y-5">
                <input type="hidden" id="edit_id" name="id" :value="form.id || ''">
                <input type="hidden" id="edit_ticket_id" name="ticket_id" :value="form.ticket_id || ''">
                <input type="hidden" id="edit_created_at" name="created_at" value="">

                            <div
                    id="progress-smart-panel"
                    :class="[modalMode === 'new' ? '' : 'hidden', 'bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-100 dark:border-white/10']"
                >
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-xs font-black text-blue-600 uppercase">Smart Input</h4>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500">Otomatis</span>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <div class="space-y-2">
                            <textarea
                                v-model="smartInputRaw"
                                id="progress-smart-input"
                                rows="6"
                                class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 font-mono"
                                placeholder="ASTRI NURJANAH&#10;082312549753&#10;SUMBER SARI 2 BANGUN NEGARA&#10;Teknisi: DARWIN, MAKMUR&#10;Sales: GUNAWAN&#10;POP: BNG&#10;Paket: Standar - 10 Mbps&#10;Harga: 300"
                            ></textarea>
                            <div
                                id="progress-smart-status"
                                class="text-[10px] min-h-[12px]"
                                :class="
                                    smartStatusTone === 'error'
                                        ? 'text-red-500 dark:text-red-400'
                                        : smartStatusTone === 'success'
                                            ? 'text-green-600 dark:text-green-400'
                                            : 'text-slate-400 dark:text-slate-500'
                                "
                            >
                                {{ smartStatusMsg }}
                            </div>
                            <div class="text-[10px] text-slate-400 dark:text-slate-500">
                                Tempel data bebas (per baris atau dipisah koma). Bisa pakai label "Nama:", "WA:", "Alamat:" dll, atau tanpa label
                                (urutan CSV: Nama, WA, Alamat, Sales, POP, Paket, Harga).
                            </div>
                        </div>
                        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-white/10 p-3 space-y-2">
                            <div class="flex items-center justify-between">
                                <div class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">Review Data</div>
                                <div
                                    id="progress-smart-ready"
                                    class="text-[10px] font-bold"
                                    :class="
                                        smartReadyTone === 'ready'
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : smartReadyTone === 'warn'
                                                ? 'text-amber-600 dark:text-amber-400'
                                                : smartReadyTone === 'error'
                                                    ? 'text-red-500 dark:text-red-400'
                                                    : 'text-slate-400 dark:text-slate-500'
                                    "
                                >
                                    {{ smartReadyMsg }}
                                </div>
                            </div>
                            <div id="progress-smart-summary" class="text-[10px] text-slate-400 dark:text-slate-500 min-h-[12px]">{{ smartSummaryText }}</div>
                            <div id="progress-smart-review" class="grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-slate-700 dark:text-slate-200">
                                <template v-if="smartReviewRows.length">
                                    <template v-for="(it, idx) in smartReviewRows" :key="idx">
                                        <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold uppercase">{{ it.label }}</div>
                                        <div class="font-medium">{{ it.value }}</div>
                                    </template>
                                </template>
                                <div v-else class="col-span-2 text-slate-400 dark:text-slate-500 italic">Tempel data untuk melihat review.</div>
                            </div>
                        </div>
                    </div>
                </div>
    

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Pelanggan -->
                    <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-100 dark:border-white/10">
                        <h4 class="text-xs font-black text-slate-700 dark:text-slate-200 mb-3">Data Pelanggan</h4>

                        <label class="block text-[11px] font-bold text-slate-500 mb-1">Nama</label>
                        <input v-model="form.customer_name" id="edit_name" name="customer_name" type="text" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="Nama pelanggan">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">WhatsApp</label>
                                <input v-model="form.customer_phone" id="edit_phone" name="customer_phone" type="text" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="08xxx / 62xxx">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Koordinat</label>
                                <div class="flex gap-2">
                                    <input v-model="form.coordinates" id="edit_coordinates" name="coordinates" type="text" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="-6.2,106.8">
                                    <button type="button" @click="openMaps(form.coordinates)" class="px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-black">
                                        Maps
                                    </button>
                                </div>
                            </div>
                        </div>

                        <label class="block text-[11px] font-bold text-slate-500 mb-1 mt-3">Alamat</label>
                        <textarea v-model="form.address" id="edit_address" name="address" rows="3" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="Alamat lengkap..."></textarea>
                    </div>

                    <!-- Order -->
                    <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-100 dark:border-white/10">
                        <h4 class="text-xs font-black text-slate-700 dark:text-slate-200 mb-3">Data Pemasangan</h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">POP / Area</label>
                                <select v-model="form.pop" id="edit_pop" name="pop" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900">
                                    <option value="">-- Pilih --</option>
                                    <option v-for="p in dropdowns.pops" :key="p" :value="p">{{ p }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Tanggal (installation_date)</label>
                                <input v-model="form.installation_date" id="edit_date" name="installation_date" type="date" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Paket</label>
                                <input v-model="form.plan_name" id="edit_plan" name="plan_name" type="text" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="Nama paket">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Harga</label>
                                <input v-model="form.price" id="edit_price" name="price" type="text" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="Contoh: 250000">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Status</label>
                                <select v-model="form.status" id="edit_status" name="status" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900">
                                    <option value="Baru">Baru</option>
                                    <option value="Survey">Survey</option>
                                    <option value="Proses">Proses</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Selesai">Selesai</option>
                                    <option value="Req_Batal">Req_Batal</option>
                                    <option value="Batal">Batal</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Selesai (finished_at)</label>
                                <input v-model="form.finished_at" id="edit_finished_at" name="finished_at" type="datetime-local" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900">
                            </div>
                        </div>

                        <div class="mt-3 flex items-center gap-2">
                            <input v-model="form.is_priority" id="edit_priority" name="is_priority" type="checkbox" value="1" class="w-4 h-4">
                            <label for="edit_priority" class="text-xs font-black text-slate-700 dark:text-slate-200">Prioritas</label>
                        </div>
                    </div>
                </div>

                <!-- Assign teknisi & sales -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-100 dark:border-white/10">
                        <h4 class="text-xs font-black text-slate-700 dark:text-slate-200 mb-3">Teknisi</h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Teknisi 1</label>
                                <select v-model="form.technician" id="edit_technician" name="technician" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900">
                                    <option value="">-- Pilih --</option>
                                    <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Teknisi 2</label>
                                <select v-model="form.technician_2" id="edit_tech_2" name="technician_2" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900">
                                    <option value="">-- Pilih --</option>
                                    <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Teknisi 3</label>
                                <select v-model="form.technician_3" id="edit_tech_3" name="technician_3" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900">
                                    <option value="">-- Pilih --</option>
                                    <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Teknisi 4</label>
                                <select v-model="form.technician_4" id="edit_tech_4" name="technician_4" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900">
                                    <option value="">-- Pilih --</option>
                                    <option v-for="t in dropdowns.technicians" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-100 dark:border-white/10">
                        <h4 class="text-xs font-black text-slate-700 dark:text-slate-200 mb-3">Sales</h4>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Sales 1</label>
                                <input v-model="form.sales_name" id="edit_sales_1" name="sales_name" type="text" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="Nama sales">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Sales 2</label>
                                <input v-model="form.sales_name_2" id="edit_sales_2" name="sales_name_2" type="text" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="Nama sales">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-1">Sales 3</label>
                                <input v-model="form.sales_name_3" id="edit_sales_3" name="sales_name_3" type="text" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="Nama sales">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-100 dark:border-white/10">
                        <h4 class="text-xs font-black text-slate-700 dark:text-slate-200 mb-3">Riwayat</h4>
                        <textarea v-model="form.notes_old" id="edit_notes_old" name="notes_old" class="hidden"></textarea>
                        <div id="history-container" class="text-xs text-slate-600 dark:text-slate-300 space-y-2 max-h-56 overflow-y-auto">
                            <span v-if="notesLines.length === 0" class="italic text-slate-300">Belum ada riwayat.</span>
                            <template v-else>
                                <div v-for="(l, idx) in notesLines" :key="idx" class="border-b border-slate-100 pb-1">{{ l }}</div>
                            </template>
                        </div>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-100 dark:border-white/10">
                        <h4 class="text-xs font-black text-slate-700 dark:text-slate-200 mb-3">Tambah Catatan</h4>
                        <textarea v-model="form.note_append" id="edit_notes" name="notes_append" rows="6" class="w-full px-3 py-2 rounded-lg text-xs border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900" placeholder="Tulis catatan baru..."></textarea>
                        <p class="mt-2 text-[11px] text-slate-400">Catatan akan otomatis ditambahkan ke riwayat (append).</p>
                    </div>
                </div>

                <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-100 dark:border-white/10">
                    <h4 class="text-xs font-black text-slate-700 dark:text-slate-200 mb-3">Audit Perubahan Data</h4>
                    <div id="change-history-container" class="text-xs text-slate-600 dark:text-slate-300 space-y-2 max-h-56 overflow-y-auto">
                        <span v-if="changeLoading" class="italic text-slate-300">Memuat...</span>
                        <span v-else-if="changeError" class="italic text-red-400">Gagal memuat riwayat perubahan.</span>
                        <span v-else-if="changeHistory.length === 0" class="italic text-slate-300">Belum ada perubahan.</span>
                        <template v-else>
                            <div v-for="(ch, idx) in changeHistory" :key="idx" class="border-b border-slate-100 pb-2">
                                <div class="text-[10px] text-slate-400 mb-1">{{ ch.changed_at || '-' }} - {{ ch.changed_by || '-' }} ({{ ch.changed_by_role || '-' }}) via {{ ch.source || '-' }}</div>
                                <div class="text-xs text-slate-700">
                                    <span class="font-bold">{{ changeFieldLabel(ch.field_name) }}</span>:
                                    <span class="text-slate-500">{{ String(ch.old_value || '').trim() || '-' }}</span>
                                    <span class="text-slate-400 px-1">-&gt;</span>
                                    <span class="text-slate-800 font-bold">{{ String(ch.new_value || '').trim() || '-' }}</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Approval panel -->
                <div id="panel-approval-batal" :class="[modalMode !== 'new' && isReqBatal ? '' : 'hidden', 'bg-red-50 dark:bg-red-900/20 rounded-xl p-4 border border-red-100 dark:border-red-500/20']">
                    <h4 class="text-xs font-black text-red-700 dark:text-red-200 mb-2">Approval Pembatalan</h4>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="decideCancel('approve')" :disabled="modalSaving || detailLoading" class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-black">
                            Approve Batal
                        </button>
                        <button type="button" @click="decideCancel('reject')" :disabled="modalSaving || detailLoading" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-800 text-white text-xs font-black">
                            Tolak (Kembali Pending)
                        </button>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex flex-col sm:flex-row gap-2 justify-end pt-2 border-t border-slate-100 dark:border-white/10">
                    <button type="button" @click="remove" :disabled="modalSaving || detailLoading" :class="[modalMode === 'new' ? 'hidden' : '', 'px-4 py-2 rounded-lg bg-red-50 hover:bg-red-100 text-red-700 text-xs font-black disabled:opacity-70 disabled:cursor-not-allowed']">
                        Hapus
                    </button>
                                    <button
                        id="btn-progress-save"
                        type="button"
                        @click="save"
                        :disabled="modalSaving || detailLoading"
                        class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-black disabled:opacity-70 disabled:cursor-not-allowed"
                    >
                        <span v-if="modalSaving" class="inline-flex items-center gap-2">
                            <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.3" stroke-width="3"></circle>
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                            </svg>
                            Menyimpan...
                        </span>
                        <span v-else>Simpan</span>
                    </button>
    
                </div>
            </form>
        </div>
    </div>
  </AdminLayout>
</template>

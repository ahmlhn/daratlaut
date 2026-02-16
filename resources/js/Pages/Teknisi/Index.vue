
<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, nextTick, watch, inject } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
  techName: { type: String, default: 'Teknisi' },
  techPop: { type: String, default: '' },
  techRole: { type: String, default: 'teknisi' },
  initialFilters: { type: Object, default: () => ({}) },
})

const toast = inject('toast', null)
const API_TEKNISI = '/api/v1/teknisi'
const API_INSTALL = '/api/v1/installations'
const API_MAPS = '/api/v1/maps'
const MANAGER_ROLES = new Set(['admin', 'cs', 'svp lapangan', 'svp_lapangan'])
const LOCATION_TRACKING_ROLES = new Set(['teknisi', 'svp lapangan', 'svp_lapangan'])
const LOCATION_SEND_MIN_INTERVAL_MS = 15000
const LOCATION_HEARTBEAT_INTERVAL_MS = 60000
const LOCATION_MIN_MOVE_METERS = 20

const loading = ref(false)
const allData = ref([])
const popList = ref([])
const techList = ref([])

const activeTab = ref(props.initialFilters.tab || 'all')
const filters = reactive({
  pop: props.initialFilters.pop || '',
  status: props.initialFilters.status || '',
  q: props.initialFilters.q || '',
})

const currentTechName = computed(() => String(props.techName || 'Teknisi').trim())
const defaultPop = computed(() => String(props.techPop || '').trim())
const currentTechRole = computed(() => String(props.techRole || 'teknisi').toLowerCase().trim())
const isManagerRole = computed(() => MANAGER_ROLES.has(currentTechRole.value))
const page = usePage()
const authPermissionSet = computed(() => {
  const list = page.props?.auth?.user?.permissions
  if (!Array.isArray(list)) return new Set()
  return new Set(list.map((x) => String(x || '').trim().toLowerCase()).filter(Boolean))
})
const hasPermission = (perm) => authPermissionSet.value.has(String(perm || '').trim().toLowerCase())
const canEditTeknisi = computed(() => hasPermission('edit teknisi'))
const canPrivilegedTeknisi = computed(() =>
  canEditTeknisi.value && (
    isManagerRole.value
    || hasPermission('edit installations')
    || hasPermission('approve installations')
  )
)
const canTrackLocation = computed(() => LOCATION_TRACKING_ROLES.has(currentTechRole.value))
const locationTracking = reactive({
  enabled: false,
  supported: true,
  secureContextOk: true,
  statusText: 'Lokasi belum aktif',
  tone: 'muted',
  lastSyncedAt: '',
})
const locationStatusClass = computed(() => {
  if (locationTracking.tone === 'success') {
    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
  }
  if (locationTracking.tone === 'warning') {
    return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'
  }
  if (locationTracking.tone === 'error') {
    return 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200'
  }
  return 'border-slate-200 bg-slate-50 text-slate-600 dark:border-white/10 dark:bg-slate-900/60 dark:text-slate-300'
})
const locationStatusDotClass = computed(() => {
  if (locationTracking.tone === 'success') return 'bg-emerald-500'
  if (locationTracking.tone === 'warning') return 'bg-amber-500'
  if (locationTracking.tone === 'error') return 'bg-red-500'
  return 'bg-slate-400'
})
const canRetryLocationTracking = computed(() =>
  canTrackLocation.value
  && locationTracking.supported
  && locationTracking.secureContextOk
  && !locationTracking.enabled
)

let locationWatchId = null
let locationHeartbeatTimer = null
let locationLastPosition = null
let locationLastSent = null
let locationSending = false
let locationFailedCount = 0

const statusColors = {
  Baru: 'bg-emerald-50 text-emerald-700 border border-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-200 dark:border-emerald-500/30',
  Survey: 'bg-purple-50 text-purple-700 border border-purple-100 dark:bg-purple-900/30 dark:text-purple-200 dark:border-purple-500/30',
  Proses: 'bg-blue-50 text-blue-700 border border-blue-100 dark:bg-blue-900/30 dark:text-blue-200 dark:border-blue-500/30',
  Pending: 'bg-orange-50 text-orange-700 border border-orange-100 dark:bg-orange-900/30 dark:text-orange-200 dark:border-orange-500/30',
  Selesai: 'bg-green-50 text-green-700 border border-green-100 dark:bg-green-900/30 dark:text-green-200 dark:border-green-500/30',
  Batal: 'bg-red-50 text-red-700 border border-red-100 dark:bg-red-900/30 dark:text-red-200 dark:border-red-500/30',
  Req_Batal: 'bg-red-50 text-red-700 border border-red-100 dark:bg-red-900/30 dark:text-red-200 dark:border-red-500/30',
}

const normalizeName = (v) => String(v || '').trim().toLowerCase()
const isClosedStatus = (v) => v === 'Selesai' || v === 'Batal'
const isBlankSales = (v) => ['','-','null'].includes(normalizeName(v))
const parseMoney = (raw) => {
  const digits = String(raw || '').replace(/\D/g, '')
  return digits ? parseInt(digits, 10) : 0
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

function formatDateLong(dateString) {
  if (!dateString) return '-'
  const d = new Date(dateString)
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
}

function formatDateShort(dateString) {
  if (!dateString) return '-'
  const d = new Date(dateString)
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })
}

function nowDatetimeLocal() {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function getRecommendedInstallDate() {
  const now = new Date()
  const rec = new Date(now)
  if (now.getHours() >= 17) rec.setDate(now.getDate() + 1)
  const pad = (n) => String(n).padStart(2, '0')
  return `${rec.getFullYear()}-${pad(rec.getMonth() + 1)}-${pad(rec.getDate())}`
}

function normalizePhoneForWa(raw) {
  let ph = String(raw || '').replace(/[^0-9]/g, '')
  if (!ph) return ''
  if (ph.startsWith('0')) ph = `62${ph.slice(1)}`
  else if (ph.startsWith('8')) ph = `62${ph}`
  return ph
}

function buildGreetingMessage(item) {
  const hour = new Date().getHours()
  const sapa = hour >= 18 ? 'Malam' : (hour >= 15 ? 'Sore' : (hour >= 11 ? 'Siang' : 'Pagi'))
  return encodeURIComponent(`${sapa} Kak ${item.customer_name}. Saya ${currentTechName.value}, teknisi WiFi. Mohon shareloc lokasi pasang ya. Terima kasih.`)
}

function notify(message, type = 'success') {
  const msg = String(message || '').trim() || 'Proses selesai'
  if (toast && typeof toast[type] === 'function') return toast[type](msg)
  window.alert(msg)
}

function ensureEditPermission() {
  if (canEditTeknisi.value) return true
  notify('Role ini tidak punya izin edit teknisi.', 'error')
  return false
}

function extractMessage(payload) {
  if (!payload || typeof payload !== 'object') return 'Request gagal'
  return String(payload.msg || payload.message || payload.error || 'Request gagal')
}

async function fetchJson(url, options = {}) {
  const opts = { credentials: 'same-origin', ...options, headers: { ...(options.headers || {}) } }
  if (opts.body && !opts.headers['Content-Type']) opts.headers['Content-Type'] = 'application/json'

  const res = await fetch(url, opts)
  const text = await res.text()
  let json = null
  try { json = text ? JSON.parse(text) : null } catch { json = null }
  if (!json) throw new Error('Response JSON tidak valid')

  const ok = res.ok && (json.success === true || json.status === 'success')
  if (!ok) throw new Error(extractMessage(json))
  return json
}

function setLocationStatus(text, tone = 'muted') {
  locationTracking.statusText = String(text || '').trim() || 'Lokasi belum aktif'
  locationTracking.tone = tone
}

function isLocalHostForGeo() {
  if (typeof window === 'undefined') return false
  const host = String(window.location?.hostname || '').toLowerCase()
  return host === 'localhost' || host === '127.0.0.1' || host === '::1'
}

function canUseGeolocationContext() {
  if (typeof window === 'undefined') return false
  return window.isSecureContext || isLocalHostForGeo()
}

function formatClockTime(dateObj) {
  if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) return ''
  return dateObj.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function toNumber(raw, digits = 6) {
  const num = Number(raw)
  if (!Number.isFinite(num)) return null
  return Number(num.toFixed(digits))
}

function distanceInMeters(aLat, aLng, bLat, bLng) {
  const toRad = (deg) => (deg * Math.PI) / 180
  const r = 6371000
  const dLat = toRad(bLat - aLat)
  const dLng = toRad(bLng - aLng)
  const aa = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(aLat)) * Math.cos(toRad(bLat)) * Math.sin(dLng / 2) ** 2
  return 2 * r * Math.atan2(Math.sqrt(aa), Math.sqrt(1 - aa))
}

function buildLocationPayload(position, eventName = 'live_tracking') {
  const lat = toNumber(position?.coords?.latitude, 6)
  const lng = toNumber(position?.coords?.longitude, 6)
  if (lat == null || lng == null) return null

  const accuracyRaw = Number(position?.coords?.accuracy)
  const speedRaw = Number(position?.coords?.speed)
  const headingRaw = Number(position?.coords?.heading)

  return {
    latitude: lat,
    longitude: lng,
    accuracy: Number.isFinite(accuracyRaw) ? Math.round(accuracyRaw) : null,
    speed: Number.isFinite(speedRaw) ? Number(speedRaw.toFixed(2)) : null,
    heading: Number.isFinite(headingRaw) ? Number(headingRaw.toFixed(2)) : null,
    event_name: String(eventName || 'live_tracking').slice(0, 80),
  }
}

function shouldSendLocation(payload, { force = false } = {}) {
  if (!locationLastSent) return true

  const nowMs = Date.now()
  const elapsed = nowMs - locationLastSent.sentAt
  if (force) return elapsed >= 5000
  if (elapsed < LOCATION_SEND_MIN_INTERVAL_MS) return false
  if (elapsed >= LOCATION_HEARTBEAT_INTERVAL_MS) return true

  const moved = distanceInMeters(
    locationLastSent.latitude,
    locationLastSent.longitude,
    payload.latitude,
    payload.longitude
  )
  return moved >= LOCATION_MIN_MOVE_METERS
}

async function sendLocationPosition(position, { eventName = 'live_tracking', force = false } = {}) {
  if (!canTrackLocation.value) return
  const payload = buildLocationPayload(position, eventName)
  if (!payload || !shouldSendLocation(payload, { force }) || locationSending) return

  locationSending = true
  try {
    await fetchJson(`${API_MAPS}/location`, {
      method: 'POST',
      body: JSON.stringify(payload),
    })
    locationLastSent = {
      latitude: payload.latitude,
      longitude: payload.longitude,
      sentAt: Date.now(),
    }
    locationFailedCount = 0
    locationTracking.lastSyncedAt = formatClockTime(new Date())
    setLocationStatus(`Lokasi aktif • sinkron ${locationTracking.lastSyncedAt}`, 'success')
  } catch (e) {
    locationFailedCount += 1
    setLocationStatus(`Gagal kirim lokasi (${locationFailedCount})`, locationFailedCount >= 3 ? 'error' : 'warning')
    console.warn('Teknisi location tracking error:', e)
  } finally {
    locationSending = false
  }
}

function parseLocationError(err) {
  const code = Number(err?.code || 0)
  if (code === 1) return 'Izin lokasi ditolak browser'
  if (code === 2) return 'Lokasi tidak tersedia saat ini'
  if (code === 3) return 'Timeout saat mengambil lokasi'
  return 'Gagal membaca lokasi perangkat'
}

function stopLocationTracking() {
  if (typeof navigator !== 'undefined' && navigator.geolocation && locationWatchId !== null) {
    navigator.geolocation.clearWatch(locationWatchId)
  }
  locationWatchId = null
  if (locationHeartbeatTimer) clearInterval(locationHeartbeatTimer)
  locationHeartbeatTimer = null
  locationLastPosition = null
  locationSending = false
  locationTracking.enabled = false
}

function handleLocationSuccess(position) {
  locationLastPosition = position
  if (!locationTracking.lastSyncedAt) setLocationStatus('Lokasi terdeteksi, sinkronisasi...', 'muted')
  sendLocationPosition(position, { eventName: 'live_tracking' })
}

function handleLocationError(err) {
  setLocationStatus(parseLocationError(err), Number(err?.code || 0) === 1 ? 'warning' : 'error')
  if (Number(err?.code || 0) === 1) stopLocationTracking()
}

function startLocationTracking() {
  if (!canTrackLocation.value) return
  if (typeof window === 'undefined' || typeof navigator === 'undefined') return
  if (!navigator.geolocation) {
    locationTracking.supported = false
    setLocationStatus('Browser tidak mendukung geolocation', 'warning')
    return
  }

  locationTracking.supported = true
  locationTracking.secureContextOk = canUseGeolocationContext()
  if (!locationTracking.secureContextOk) {
    setLocationStatus('Aktifkan HTTPS/localhost untuk izin lokasi', 'warning')
    return
  }
  if (locationWatchId !== null) return

  setLocationStatus('Meminta izin lokasi...', 'muted')
  locationTracking.enabled = true

  navigator.geolocation.getCurrentPosition(
    handleLocationSuccess,
    handleLocationError,
    { enableHighAccuracy: true, timeout: 25000, maximumAge: 10000 }
  )

  locationWatchId = navigator.geolocation.watchPosition(
    handleLocationSuccess,
    handleLocationError,
    { enableHighAccuracy: true, timeout: 25000, maximumAge: 10000 }
  )

  if (locationHeartbeatTimer) clearInterval(locationHeartbeatTimer)
  locationHeartbeatTimer = setInterval(() => {
    if (!locationLastPosition) return
    sendLocationPosition(locationLastPosition, { eventName: 'heartbeat', force: true })
  }, LOCATION_HEARTBEAT_INTERVAL_MS)
}

function retryLocationTracking() {
  locationLastSent = null
  locationFailedCount = 0
  stopLocationTracking()
  startLocationTracking()
}

function mapTask(raw) {
  const row = raw || {}
  return {
    ...row,
    customer_name: String(row.customer_name ?? row.nama ?? ''),
    customer_phone: String(row.customer_phone ?? row.wa ?? ''),
    address: String(row.address ?? row.alamat ?? ''),
    coordinates: String(row.coordinates ?? row.koordinat ?? ''),
    plan_name: String(row.plan_name ?? row.paket ?? ''),
    price: Number(row.price ?? row.harga ?? 0),
    installation_date: String(row.installation_date ?? row.tanggal ?? ''),
    notes: String(row.notes ?? row.catatan ?? ''),
    technician: String(row.technician ?? row.teknisi_1 ?? ''),
    technician_2: String(row.technician_2 ?? row.teknisi_2 ?? ''),
    technician_3: String(row.technician_3 ?? row.teknisi_3 ?? ''),
    technician_4: String(row.technician_4 ?? row.teknisi_4 ?? ''),
    sales_name: String(row.sales_name ?? row.sales_1 ?? ''),
    sales_name_2: String(row.sales_name_2 ?? row.sales_2 ?? ''),
    sales_name_3: String(row.sales_name_3 ?? row.sales_3 ?? ''),
    is_priority: Number(row.is_priority ?? 0),
  }
}

function isAssignedToCurrent(item) {
  const current = normalizeName(currentTechName.value)
  if (!current) return false
  return [item?.technician, item?.technician_2, item?.technician_3, item?.technician_4].some((name) => normalizeName(name) === current)
}

function cardCanManage(item) {
  return canEditTeknisi.value && (isAssignedToCurrent(item) || canPrivilegedTeknisi.value)
}

function overdueDays(item) {
  if (!item?.installation_date) return 0
  if (['Selesai', 'Batal', 'Req_Batal'].includes(item.status)) return 0
  const d = new Date(item.installation_date)
  if (Number.isNaN(d.getTime())) return 0
  const today = new Date()
  const d0 = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  const d1 = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const diff = Math.floor((d1.getTime() - d0.getTime()) / 86400000)
  return diff > 0 ? diff : 0
}

function applyTabDefaults(tab) {
  if (tab === 'all') filters.pop = defaultPop.value || ''
  else filters.pop = ''
}

function switchTab(tab) {
  activeTab.value = tab
  applyTabDefaults(tab)
}

async function loadDropdowns() {
  try {
    const [popsRes, techRes] = await Promise.all([
      fetchJson(`${API_TEKNISI}/pops`),
      fetchJson(`${API_TEKNISI}/technicians`),
    ])

    popList.value = (Array.isArray(popsRes.data) ? popsRes.data : [])
      .map((x) => String(x?.name ?? x ?? '').trim())
      .filter(Boolean)

    techList.value = (Array.isArray(techRes.data) ? techRes.data : [])
      .map((x) => String(x?.name ?? x ?? '').trim())
      .filter(Boolean)
  } catch (e) {
    popList.value = []
    techList.value = []
    notify(e.message || 'Gagal memuat dropdown.', 'error')
  }
}

async function loadTasks() {
  loading.value = true
  try {
    const params = new URLSearchParams()
    params.set('tab', 'all')
    if (currentTechName.value) params.set('tech_name', currentTechName.value)
    const res = await fetchJson(`${API_TEKNISI}/tasks?${params.toString()}`)
    allData.value = (Array.isArray(res.data) ? res.data : []).map(mapTask)
  } catch (e) {
    allData.value = []
    notify(e.message || 'Gagal memuat tugas.', 'error')
  } finally {
    loading.value = false
  }
}

const countAll = computed(() => allData.value.filter((item) => !isClosedStatus(item.status) && (!filters.pop || String(item.pop || '') === filters.pop)).length)

const countMine = computed(() => allData.value.filter((item) => {
  const mine = isAssignedToCurrent(item) && !['Selesai', 'Batal', 'Baru'].includes(item.status)
  const req = item.status === 'Req_Batal' && canPrivilegedTeknisi.value
  return mine || req
}).length)

const filteredTasks = computed(() => {
  const q = normalizeName(filters.q)
  const status = String(filters.status || '').trim()
  const pop = String(filters.pop || '').trim()

  const sorted = [...allData.value].sort((a, b) => {
    const sa = normalizeName(a.status)
    const sb = normalizeName(b.status)
    const pa = Number(a.is_priority || 0)
    const pb = Number(b.is_priority || 0)
    if (sa === 'req_batal' && sb !== 'req_batal') return -1
    if (sa !== 'req_batal' && sb === 'req_batal') return 1
    if (pa > pb) return -1
    if (pa < pb) return 1
    return Number(a.id || 0) - Number(b.id || 0)
  })

  return sorted.filter((item) => {
    if (isClosedStatus(item.status)) return false
    if (pop && String(item.pop || '') !== pop) return false
    if (q) {
      const m1 = normalizeName(item.customer_name).includes(q)
      const m2 = normalizeName(item.address).includes(q)
      const m3 = normalizeName(item.ticket_id).includes(q)
      if (!m1 && !m2 && !m3) return false
    }
    if (activeTab.value === 'all') {
      if (isAssignedToCurrent(item)) return false
      if (status && item.status !== status) return false
      return true
    }
    if (item.status === 'Req_Batal' && canPrivilegedTeknisi.value) {
      if (status && item.status !== status) return false
      return true
    }
    if (!isAssignedToCurrent(item)) return false
    if (item.status === 'Baru') return false
    if (status && item.status !== status) return false
    return true
  })
})

const showDetailModal = ref(false)
const detailLoading = ref(false)
const currentTask = ref(null)
const auditChanges = ref([])
const auditLoading = ref(false)

const canManageCurrent = computed(() => currentTask.value ? (canEditTeknisi.value && (isAssignedToCurrent(currentTask.value) || canPrivilegedTeknisi.value)) : false)
const canClaimCurrent = computed(() => canEditTeknisi.value && currentTask.value?.status === 'Baru')
const allowActionsCurrent = computed(() => canEditTeknisi.value && (canManageCurrent.value || canClaimCurrent.value))
const showWaButton = computed(() => {
  const item = currentTask.value
  if (!item) return false
  return item.status !== 'Baru' && isAssignedToCurrent(item) && !!normalizePhoneForWa(item.customer_phone)
})
const waHref = computed(() => {
  const item = currentTask.value
  if (!item) return '#'
  const phone = normalizePhoneForWa(item.customer_phone)
  if (!phone) return '#'
  return `https://wa.me/${phone}?text=${buildGreetingMessage(item)}`
})
const detailReadOnlyNote = computed(() => {
  const item = currentTask.value
  if (!item) return ''
  if (!canEditTeknisi.value) return 'Role ini hanya punya akses lihat tugas (tanpa aksi).'
  return !canManageCurrent.value && item.status !== 'Baru' ? 'Hanya lihat detail. Tugas ini sudah diambil teknisi lain.' : ''
})
const currentStatus = computed(() => String(currentTask.value?.status || ''))

function findTaskLocal(id) {
  return allData.value.find((item) => String(item.id) === String(id)) || null
}

async function loadAuditHistory(id) {
  auditLoading.value = true
  auditChanges.value = []
  try {
    const res = await fetchJson(`${API_INSTALL}/${id}/history`)
    auditChanges.value = Array.isArray(res.data) ? res.data : []
  } catch {
    auditChanges.value = []
  } finally {
    auditLoading.value = false
  }
}

async function openDetail(taskOrId) {
  const id = typeof taskOrId === 'object' ? taskOrId?.id : taskOrId
  if (!id) return
  showDetailModal.value = true
  detailLoading.value = true
  try {
    const res = await fetchJson(`${API_INSTALL}/${id}`)
    currentTask.value = mapTask(res.data || {})
    await loadAuditHistory(id)
  } catch (e) {
    currentTask.value = findTaskLocal(id)
    notify(e.message || 'Gagal memuat detail tugas.', 'error')
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  showDetailModal.value = false
  detailLoading.value = false
  currentTask.value = null
  auditChanges.value = []
}

async function refreshTasks(openId = null) {
  await loadTasks()
  if (openId) {
    await nextTick()
    await openDetail(openId)
  }
}

async function resumeProses(id) {
  if (!ensureEditPermission()) return
  const task = findTaskLocal(id)
  if (!task) return
  try {
    await fetchJson(`${API_INSTALL}/${id}`, {
      method: 'PUT',
      body: JSON.stringify({ status: 'Proses', installation_date: task.installation_date || getRecommendedInstallDate() }),
    })
    closeDetail()
    await refreshTasks(id)
    notify('Status diubah ke Proses.')
  } catch (e) {
    notify(e.message || 'Gagal update status.', 'error')
  }
}

async function togglePriority(id, val) {
  if (!ensureEditPermission()) return
  const action = val === 1 ? 'Jadikan PRIORITAS' : 'Hapus Prioritas'
  if (!window.confirm(`Yakin ingin ${action}?`)) return
  try {
    await fetchJson(`${API_INSTALL}/${id}/toggle-priority`, { method: 'POST', body: JSON.stringify({ val }) })
    closeDetail()
    await refreshTasks(id)
    notify('Berhasil update prioritas.')
  } catch (e) {
    notify(e.message || 'Gagal update prioritas.', 'error')
  }
}

const showClaimModal = ref(false)
const claimSubmitting = ref(false)
const claimRecommendationNote = ref('Jadwal pasang direkomendasikan hari ini.')
const claimForm = reactive({ id: null, installation_date: '', technician: '', technician_2: '', technician_3: '', technician_4: '' })
const claimShowTech3 = computed(() => !!claimForm.technician_2)
const claimShowTech4 = computed(() => !!claimForm.technician_3)
const claimDateLabel = computed(() => formatDateLong(claimForm.installation_date))

watch(() => claimForm.technician_2, (val) => {
  if (!val) {
    claimForm.technician_3 = ''
    claimForm.technician_4 = ''
  }
})
watch(() => claimForm.technician_3, (val) => {
  if (!val) claimForm.technician_4 = ''
})

function resetClaimForm() {
  claimForm.id = null
  claimForm.installation_date = ''
  claimForm.technician = ''
  claimForm.technician_2 = ''
  claimForm.technician_3 = ''
  claimForm.technician_4 = ''
}

function openClaimModal(taskOrId) {
  if (!ensureEditPermission()) return
  const task = typeof taskOrId === 'object' ? taskOrId : findTaskLocal(taskOrId)
  const id = typeof taskOrId === 'object' ? taskOrId?.id : taskOrId
  if (!id) return

  resetClaimForm()
  claimForm.id = id
  const now = new Date()
  const afterCutoff = now.getHours() >= 17
  claimForm.installation_date = getRecommendedInstallDate()
  claimRecommendationNote.value = afterCutoff ? 'Jadwal pasang direkomendasikan besok.' : 'Jadwal pasang direkomendasikan hari ini.'
  if (techList.value.includes(currentTechName.value)) claimForm.technician = currentTechName.value
  if (task && task.status === 'Baru') closeDetail()
  showClaimModal.value = true
}

function closeClaimModal() {
  showClaimModal.value = false
  claimSubmitting.value = false
  resetClaimForm()
}

function validateClaimTeam(showMessage = true) {
  if (!claimForm.technician) {
    if (showMessage) notify('Teknisi utama wajib diisi.', 'error')
    return false
  }

  const selected = [claimForm.technician, claimForm.technician_2, claimForm.technician_3, claimForm.technician_4]
    .map((x) => String(x || '').trim())
    .filter(Boolean)
  const normalized = selected.map((x) => normalizeName(x))
  if (new Set(normalized).size !== normalized.length) {
    if (showMessage) notify('Nama teknisi tim tidak boleh duplikat.', 'error')
    return false
  }

  const current = normalizeName(currentTechName.value)
  if (!current) return true

  const isMain = normalizeName(claimForm.technician) === current
  const isSupport = [claimForm.technician_2, claimForm.technician_3, claimForm.technician_4].some((name) => normalizeName(name) === current)
  if (isMain && isSupport) {
    if (showMessage) notify('Jika Anda sebagai tim support, ubah teknisi utama.', 'error')
    return false
  }
  if (!isMain && !isSupport) {
    if (showMessage) notify('Anda harus masuk sebagai teknisi utama atau tim support.', 'error')
    return false
  }
  return true
}

async function submitClaim() {
  if (!ensureEditPermission()) return
  if (claimSubmitting.value) return
  if (!validateClaimTeam(true)) return
  claimSubmitting.value = true
  const id = claimForm.id
  try {
    await fetchJson(`${API_INSTALL}/${id}/claim`, {
      method: 'POST',
      body: JSON.stringify({
        technician: claimForm.technician,
        technician_2: claimForm.technician_2,
        technician_3: claimForm.technician_3,
        technician_4: claimForm.technician_4,
        installation_date: claimForm.installation_date,
      }),
    })
    closeClaimModal()
    switchTab('mine')
    await refreshTasks(id)
    notify('Tugas diambil (Proses).')
  } catch (e) {
    notify(e.message || 'Gagal ambil tugas.', 'error')
  } finally {
    claimSubmitting.value = false
  }
}

const showPendingModal = ref(false)
const pendingSubmitting = ref(false)
const pendingForm = reactive({ id: null, reason: '', date: '' })

function openPendingModal(taskOrId) {
  if (!ensureEditPermission()) return
  const id = typeof taskOrId === 'object' ? taskOrId?.id : taskOrId
  if (!id) return
  pendingForm.id = id
  pendingForm.reason = ''
  pendingForm.date = ''
  closeDetail()
  showPendingModal.value = true
}

function closePendingModal() {
  showPendingModal.value = false
  pendingSubmitting.value = false
  pendingForm.id = null
  pendingForm.reason = ''
  pendingForm.date = ''
}

async function submitPending() {
  if (!ensureEditPermission()) return
  if (pendingSubmitting.value) return
  if (!pendingForm.reason.trim()) return notify('Alasan pending wajib diisi.', 'error')

  const task = findTaskLocal(pendingForm.id)
  if (!task) return notify('Data tugas tidak ditemukan.', 'error')

  pendingSubmitting.value = true
  try {
    const logLine = `[PENDING] ${pendingForm.reason.trim()} - ${new Date().toLocaleString('id-ID')}`
    await fetchJson(`${API_INSTALL}/${pendingForm.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        status: 'Pending',
        installation_date: pendingForm.date || task.installation_date || getRecommendedInstallDate(),
        notes_append: logLine,
      }),
    })
    closePendingModal()
    await refreshTasks(task.id)
    notify('Status berhasil diubah ke Pending.')
  } catch (e) {
    notify(e.message || 'Gagal update pending.', 'error')
  } finally {
    pendingSubmitting.value = false
  }
}

const showCancelReqModal = ref(false)
const cancelReqSubmitting = ref(false)
const cancelReqForm = reactive({ id: null, reason: '' })

function openCancelReqModal(taskOrId) {
  if (!ensureEditPermission()) return
  const id = typeof taskOrId === 'object' ? taskOrId?.id : taskOrId
  if (!id) return
  cancelReqForm.id = id
  cancelReqForm.reason = ''
  closeDetail()
  showCancelReqModal.value = true
}

function closeCancelReqModal() {
  showCancelReqModal.value = false
  cancelReqSubmitting.value = false
  cancelReqForm.id = null
  cancelReqForm.reason = ''
}

async function submitCancelReq() {
  if (!ensureEditPermission()) return
  if (cancelReqSubmitting.value) return
  if (!cancelReqForm.reason.trim()) return notify('Alasan pengajuan batal wajib diisi.', 'error')

  cancelReqSubmitting.value = true
  try {
    await fetchJson(`${API_INSTALL}/${cancelReqForm.id}/request-cancel`, {
      method: 'POST',
      body: JSON.stringify({ reason: cancelReqForm.reason.trim() }),
    })
    closeCancelReqModal()
    await refreshTasks()
    notify('Pengajuan batal sudah dikirim.')
  } catch (e) {
    notify(e.message || 'Gagal mengajukan batal.', 'error')
  } finally {
    cancelReqSubmitting.value = false
  }
}

const showReviewModal = ref(false)
const reviewSubmitting = ref(false)
const reviewTask = ref(null)
const reviewForm = reactive({ id: null, note: '' })

function openReviewModal(taskOrId) {
  if (!ensureEditPermission()) return
  const task = typeof taskOrId === 'object' ? taskOrId : findTaskLocal(taskOrId)
  if (!task) return
  reviewTask.value = task
  reviewForm.id = task.id
  reviewForm.note = ''
  closeDetail()
  showReviewModal.value = true
}

function closeReviewModal() {
  showReviewModal.value = false
  reviewSubmitting.value = false
  reviewTask.value = null
  reviewForm.id = null
  reviewForm.note = ''
}

async function submitReview(decision) {
  if (!ensureEditPermission()) return
  if (reviewSubmitting.value) return
  if (decision === 'reject' && !reviewForm.note.trim()) return notify('Alasan penolakan wajib diisi.', 'error')

  const actionText = decision === 'approve' ? 'ACC BATAL' : 'TOLAK BATAL'
  if (!window.confirm(`Yakin ingin ${actionText}?`)) return

  reviewSubmitting.value = true
  try {
    await fetchJson(`${API_INSTALL}/${reviewForm.id}/decide-cancel`, {
      method: 'POST',
      body: JSON.stringify({ decision, reason: reviewForm.note.trim() }),
    })
    closeReviewModal()
    await refreshTasks()
    notify(`Berhasil: ${actionText}`)
  } catch (e) {
    notify(e.message || 'Gagal memproses keputusan pembatalan.', 'error')
  } finally {
    reviewSubmitting.value = false
  }
}

const showTransferModal = ref(false)
const transferSubmitting = ref(false)
const transferForm = reactive({ id: null, to_tech: '', reason: '' })
const transferTargetOptions = computed(() => techList.value.filter((name) => normalizeName(name) !== normalizeName(currentTechName.value)))

function openTransferModal(taskOrId) {
  if (!ensureEditPermission()) return
  const id = typeof taskOrId === 'object' ? taskOrId?.id : taskOrId
  if (!id) return
  transferForm.id = id
  transferForm.to_tech = ''
  transferForm.reason = ''
  closeDetail()
  showTransferModal.value = true
}

function closeTransferModal() {
  showTransferModal.value = false
  transferSubmitting.value = false
  transferForm.id = null
  transferForm.to_tech = ''
  transferForm.reason = ''
}

async function submitTransfer() {
  if (!ensureEditPermission()) return
  if (transferSubmitting.value) return
  if (!transferForm.to_tech.trim()) return notify('Pilih teknisi tujuan terlebih dahulu.', 'error')

  transferSubmitting.value = true
  try {
    await fetchJson(`${API_INSTALL}/${transferForm.id}/transfer`, {
      method: 'POST',
      body: JSON.stringify({ to_tech: transferForm.to_tech.trim(), reason: transferForm.reason.trim() }),
    })
    closeTransferModal()
    await refreshTasks()
    notify('Berhasil transfer tugas.')
  } catch (e) {
    notify(e.message || 'Gagal transfer tugas.', 'error')
  } finally {
    transferSubmitting.value = false
  }
}

const showFinishModal = ref(false)
const finishSubmitting = ref(false)
const finishSales1Locked = ref(false)
const finishForm = reactive({
  id: null,
  customer_name: '',
  customer_phone: '',
  address: '',
  pop: '',
  plan_name: '',
  price: '',
  notes: '',
  sales_name: '',
  sales_name_2: '',
  sales_name_3: '',
  technician: '',
  technician_2: '',
  technician_3: '',
  technician_4: '',
  coordinates: '',
})

function resetFinishForm() {
  Object.assign(finishForm, {
    id: null, customer_name: '', customer_phone: '', address: '', pop: '', plan_name: '', price: '', notes: '',
    sales_name: '', sales_name_2: '', sales_name_3: '', technician: '', technician_2: '', technician_3: '', technician_4: '', coordinates: '',
  })
}

function openFinishModal(taskOrId) {
  if (!ensureEditPermission()) return
  const task = typeof taskOrId === 'object' ? taskOrId : findTaskLocal(taskOrId)
  if (!task) return

  resetFinishForm()
  finishForm.id = task.id
  finishForm.customer_name = task.customer_name || ''
  finishForm.customer_phone = task.customer_phone || ''
  finishForm.address = task.address || ''
  finishForm.pop = task.pop || ''
  finishForm.plan_name = task.plan_name || ''
  finishForm.price = formatRupiahTyping(String(task.price || ''), 'Rp. ')
  finishForm.notes = task.notes || ''
  finishForm.sales_name = task.sales_name || ''
  finishForm.sales_name_2 = task.sales_name_2 || ''
  finishForm.sales_name_3 = task.sales_name_3 || ''
  finishForm.technician = task.technician || currentTechName.value || ''
  finishForm.technician_2 = task.technician_2 || ''
  finishForm.technician_3 = task.technician_3 || ''
  finishForm.technician_4 = task.technician_4 || ''
  finishForm.coordinates = task.coordinates || ''
  finishSales1Locked.value = !canPrivilegedTeknisi.value && !isBlankSales(task.sales_name)

  closeDetail()
  showFinishModal.value = true
}

function closeFinishModal() {
  showFinishModal.value = false
  finishSubmitting.value = false
  finishSales1Locked.value = false
  resetFinishForm()
}

function onFinishPriceInput() {
  finishForm.price = formatRupiahTyping(finishForm.price, 'Rp. ')
}

async function submitFinish() {
  if (!ensureEditPermission()) return
  if (finishSubmitting.value) return
  if (!finishForm.id) return
  if (!window.confirm('Selesaikan tugas ini?')) return

  finishSubmitting.value = true
  try {
    await fetchJson(`${API_INSTALL}/${finishForm.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        status: 'Selesai',
        customer_name: finishForm.customer_name,
        customer_phone: finishForm.customer_phone,
        address: finishForm.address,
        pop: finishForm.pop,
        plan_name: finishForm.plan_name,
        price: parseMoney(finishForm.price),
        coordinates: finishForm.coordinates,
        notes: finishForm.notes,
        sales_name: finishForm.sales_name,
        sales_name_2: finishForm.sales_name_2,
        sales_name_3: finishForm.sales_name_3,
        technician: finishForm.technician,
        technician_2: finishForm.technician_2,
        technician_3: finishForm.technician_3,
        technician_4: finishForm.technician_4,
        finished_at: nowDatetimeLocal(),
      }),
    })
    closeFinishModal()
    await refreshTasks()
    notify('Tugas diselesaikan.')
  } catch (e) {
    notify(e.message || 'Gagal menyelesaikan tugas.', 'error')
  } finally {
    finishSubmitting.value = false
  }
}

const showNewInstallModal = ref(false)
const newInstallSubmitting = ref(false)
const newInstall = reactive({
  customer_name: '', customer_phone: '', address: '', pop: '', plan_name: '', price: '',
  sales_name: '', sales_name_2: '', sales_name_3: '', technician: '', technician_2: '', technician_3: '', technician_4: '',
  installation_date: getRecommendedInstallDate(), coordinates: '', notes: '',
})

function resetNewInstallForm() {
  Object.assign(newInstall, {
    customer_name: '', customer_phone: '', address: '', pop: defaultPop.value || '', plan_name: '', price: '',
    sales_name: '', sales_name_2: '', sales_name_3: '', technician: '', technician_2: '', technician_3: '', technician_4: '',
    installation_date: getRecommendedInstallDate(), coordinates: '', notes: '',
  })
}

function openNewInstallModal() {
  if (!ensureEditPermission()) return
  resetNewInstallForm()
  closeDetail()
  showNewInstallModal.value = true
}

function closeNewInstallModal() {
  showNewInstallModal.value = false
  newInstallSubmitting.value = false
  resetNewInstallForm()
}

function onNewInstallPriceInput() {
  newInstall.price = formatRupiahTyping(newInstall.price, 'Rp. ')
}

async function saveNewInstall() {
  if (!ensureEditPermission()) return
  if (newInstallSubmitting.value) return

  const name = String(newInstall.customer_name || '').trim()
  const phoneDigits = String(newInstall.customer_phone || '').replace(/\D/g, '')
  const address = String(newInstall.address || '').trim()
  const pop = String(newInstall.pop || '').trim()

  if (!name) return notify('Nama pelanggan wajib diisi.', 'error')
  if (!phoneDigits || phoneDigits.length < 8) return notify('Nomor WhatsApp tidak valid (minimal 8 digit).', 'error')
  if (!address) return notify('Alamat wajib diisi.', 'error')
  if (!pop) return notify('POP wajib diisi.', 'error')

  newInstallSubmitting.value = true
  try {
    await fetchJson(API_INSTALL, {
      method: 'POST',
      body: JSON.stringify({
        customer_name: name,
        customer_phone: phoneDigits,
        address,
        pop,
        plan_name: newInstall.plan_name,
        price: parseMoney(newInstall.price),
        sales_name: newInstall.sales_name,
        sales_name_2: newInstall.sales_name_2,
        sales_name_3: newInstall.sales_name_3,
        technician: newInstall.technician,
        technician_2: newInstall.technician_2,
        technician_3: newInstall.technician_3,
        technician_4: newInstall.technician_4,
        installation_date: newInstall.installation_date || getRecommendedInstallDate(),
        coordinates: newInstall.coordinates,
        notes: newInstall.notes,
        status: 'Baru',
      }),
    })
    closeNewInstallModal()
    await refreshTasks()
    notify('Pasang baru berhasil disimpan.')
  } catch (e) {
    notify(e.message || 'Gagal menyimpan pasang baru.', 'error')
  } finally {
    newInstallSubmitting.value = false
  }
}

function clearSearch() {
  filters.q = ''
}

const isTaskClaimable = (task) => task.status === 'Baru'
const isTaskReqCancel = (task) => task.status === 'Req_Batal'
const isTaskManageableStatus = (task) => ['Proses', 'Pending', 'Survey'].includes(task.status)

function detailStatusClass(status) {
  return statusColors[status] || 'bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-white/10'
}

onMounted(async () => {
  if (!['all', 'mine'].includes(activeTab.value)) activeTab.value = 'all'
  if (activeTab.value === 'all') filters.pop = filters.pop || defaultPop.value || ''
  else filters.pop = ''

  startLocationTracking()
  await loadDropdowns()
  await loadTasks()

  const params = new URLSearchParams(window.location.search)
  const deepId = params.get('task_id') || params.get('id')
  if (deepId) {
    await nextTick()
    openDetail(deepId)
  }
})

onUnmounted(() => {
  stopLocationTracking()
})
</script>

<template>
  <Head title="Tugas Teknisi" />

  <AdminLayout>
    <div id="teknisi-root" class="text-slate-800 dark:text-slate-100 pb-24">
      <div class="sticky top-0 z-30 w-full">
        <div class="absolute inset-x-0 top-0 bottom-0 bg-slate-50/95 dark:bg-slate-900/90 backdrop-blur border-b border-slate-200 dark:border-white/10 pointer-events-none"></div>
        <div class="relative max-w-3xl mx-auto px-3 sm:px-5 pb-2 sm:pb-3 pt-2">
          <div class="grid grid-cols-2 bg-slate-50 dark:bg-slate-900/60 rounded-xl p-1.5 shadow-sm border border-slate-200 dark:border-white/10 gap-1 flex-1">
            <button @click="switchTab('all')" :class="activeTab === 'all' ? 'py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 bg-blue-50 text-blue-600 border border-blue-200 dark:bg-blue-500/20 dark:text-blue-200 dark:border-blue-500/30' : 'py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
              SEMUA TUGAS
              <span class="bg-red-500 text-white text-[10px] px-2 py-0.5 rounded-full">{{ countAll }}</span>
            </button>
            <button @click="switchTab('mine')" :class="activeTab === 'mine' ? 'py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 bg-blue-50 text-blue-600 border border-blue-200 dark:bg-blue-500/20 dark:text-blue-200 dark:border-blue-500/30' : 'py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'">
              TUGAS SAYA
              <span class="bg-blue-600 text-white text-[10px] px-2 py-0.5 rounded-full">{{ countMine }}</span>
            </button>
          </div>
        </div>
      </div>

      <div class="max-w-3xl mx-auto pt-4">
        <div class="px-3 sm:px-5 space-y-3 sm:space-y-4 mt-1">
          <div class="space-y-2 w-full">
            <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
              <select v-model="filters.pop" class="w-full h-12 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 text-xs font-bold rounded-xl px-2 shadow-sm outline-none text-slate-700 dark:text-slate-200 truncate">
                <option value="">Semua Area</option>
                <option v-for="pop in popList" :key="pop" :value="pop">{{ pop }}</option>
              </select>
              <div class="w-28 sm:w-36">
                <select v-model="filters.status" class="w-full h-12 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 text-xs font-bold rounded-xl px-2 shadow-sm outline-none text-slate-700 dark:text-slate-200">
                  <option value="">Status</option>
                  <option value="Proses">Proses</option>
                  <option value="Pending">Pending</option>
                  <option value="Req_Batal">Req Batal</option>
                </select>
              </div>
            </div>

            <div class="flex items-center gap-2 w-full">
              <div class="relative flex-1">
                <div class="relative h-12 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl shadow-sm flex items-center overflow-hidden">
                  <div class="absolute left-0 top-0 bottom-0 w-12 flex items-center justify-center pointer-events-none text-slate-500 dark:text-slate-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                  </div>
                  <input v-model="filters.q" type="text" class="w-full h-full pl-12 pr-10 text-sm font-bold text-slate-700 dark:text-slate-100 bg-transparent border-none outline-none placeholder-slate-400 dark:placeholder-slate-500" placeholder="Cari nama / alamat..." autocomplete="off">
                  <button v-if="filters.q" @click="clearSearch" class="absolute right-0 top-0 bottom-0 w-10 flex items-center justify-center text-slate-400 hover:text-red-500 dark:text-slate-500 dark:hover:text-red-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
                </div>
              </div>

              <button v-if="canEditTeknisi" @click="openNewInstallModal" class="h-12 w-12 flex items-center justify-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl text-blue-600 dark:text-blue-300 shadow-sm hover:bg-blue-50 dark:hover:bg-slate-800 transition active:scale-95" title="Input Pasang Baru">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              </button>
              <button @click="refreshTasks()" class="h-12 w-12 flex items-center justify-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl text-slate-600 dark:text-slate-300 shadow-sm hover:bg-blue-50 dark:hover:bg-slate-800 transition active:scale-95" title="Refresh">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
              </button>
            </div>

            <div v-if="canTrackLocation" :class="['flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-[11px] font-semibold', locationStatusClass]">
              <div class="flex items-center gap-2 min-w-0">
                <span :class="['inline-block h-2.5 w-2.5 rounded-full flex-shrink-0', locationStatusDotClass]"></span>
                <span class="truncate">{{ locationTracking.statusText }}</span>
              </div>
              <button
                v-if="canRetryLocationTracking"
                @click="retryLocationTracking"
                class="h-7 px-2 rounded-lg border border-current/30 text-[10px] font-bold hover:bg-white/40 dark:hover:bg-white/10 transition"
              >
                Coba Lagi
              </button>
            </div>
          </div>

          <div v-if="loading" class="text-center py-10">
            <div class="animate-spin rounded-full h-10 w-10 border-b-4 border-blue-600 mx-auto"></div>
            <p class="text-sm text-slate-400 dark:text-slate-500 mt-3 font-bold">Memuat...</p>
          </div>

          <div v-else-if="filteredTasks.length > 0" class="grid grid-cols-1 gap-3 sm:gap-4 pb-10">
            <div v-for="task in filteredTasks" :key="task.id" :class="['bg-white dark:bg-slate-900 rounded-2xl shadow-sm border p-4 space-y-3', task.is_priority ? 'border-l-8 border-l-yellow-400 border-slate-200 dark:border-white/10' : 'border-slate-200 dark:border-white/10', overdueDays(task) > 3 && !task.is_priority ? 'ring-1 ring-red-200 bg-red-50/30 dark:ring-red-500/30 dark:bg-red-900/20' : '']">
              <div class="flex items-start justify-between gap-2">
                <span class="text-xs font-bold text-slate-400 dark:text-slate-500">#{{ task.ticket_id || task.id }}</span>
                <span :class="['px-2 py-1 rounded text-[10px] font-bold uppercase', detailStatusClass(task.status)]">{{ task.status || '-' }}</span>
              </div>

              <div v-if="task.is_priority" class="flex items-center gap-1 bg-yellow-400 dark:bg-yellow-500/20 text-slate-900 dark:text-yellow-200 px-2 py-1 rounded text-[10px] font-black w-max">PRIORITAS TINGGI</div>
              <div v-if="overdueDays(task) > 0" class="bg-red-100 dark:bg-red-900/20 text-red-600 dark:text-red-200 px-2 py-1 rounded text-[10px] font-bold w-max">TELAT {{ overdueDays(task) }} HARI</div>

              <div>
                <h3 class="font-bold text-slate-800 dark:text-slate-100 text-base">{{ task.customer_name || '-' }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 leading-snug line-clamp-2">{{ task.address || '-' }}</p>
              </div>

              <div class="grid grid-cols-2 gap-2 text-[10px]">
                <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-white/10 rounded-lg px-2 py-1.5">
                  <div class="text-slate-400 dark:text-slate-500 font-bold uppercase">Jadwal</div>
                  <div class="text-slate-700 dark:text-slate-200 font-bold">{{ formatDateShort(task.installation_date) }}</div>
                </div>
                <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-white/10 rounded-lg px-2 py-1.5">
                  <div class="text-slate-400 dark:text-slate-500 font-bold uppercase">POP</div>
                  <div class="text-slate-700 dark:text-slate-200 font-bold">{{ task.pop || '-' }}</div>
                </div>
              </div>

              <div class="flex flex-wrap items-center gap-2">
                <span class="text-[10px] bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-200 border border-blue-100 dark:border-blue-500/30 px-2 py-1 rounded font-bold">{{ task.plan_name || '-' }}</span>
                <span v-if="task.technician && normalizeName(task.technician) !== normalizeName(currentTechName)" class="text-[10px] bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-200 border border-amber-100 dark:border-amber-500/30 px-2 py-1 rounded font-bold">Teknisi: {{ task.technician }}</span>
              </div>

              <div class="mt-4 pt-4 border-t border-slate-100 dark:border-white/10">
                <button v-if="isTaskClaimable(task) && canEditTeknisi" @click="openClaimModal(task)" class="h-12 w-full bg-blue-600 text-white text-sm font-bold rounded-xl shadow hover:bg-blue-700 transition">AMBIL TUGAS</button>
                <button v-else-if="isTaskReqCancel(task) && canPrivilegedTeknisi" @click="openReviewModal(task)" class="h-12 w-full bg-slate-800 text-white text-sm font-bold rounded-xl shadow hover:bg-slate-700 transition">TINJAU & PUTUSKAN</button>
                <button v-else-if="isTaskReqCancel(task) && !canPrivilegedTeknisi && canEditTeknisi" class="h-12 w-full bg-slate-300 dark:bg-slate-800 text-slate-500 dark:text-slate-400 text-sm font-bold rounded-xl cursor-not-allowed" disabled>MENUNGGU ACC</button>
                <button v-else @click="openDetail(task)" :class="cardCanManage(task) ? 'h-12 w-full bg-blue-600 text-white text-sm font-bold rounded-xl shadow hover:bg-blue-700 transition' : 'h-12 w-full bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-sm font-bold rounded-xl hover:bg-slate-300 dark:hover:bg-slate-700 transition'">{{ cardCanManage(task) ? 'LIHAT DETAIL & AKSI' : 'LIHAT DETAIL' }}</button>
              </div>
            </div>
          </div>

          <div v-else class="text-center py-16 sm:py-20">
            <div class="w-20 h-20 bg-slate-200 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400 dark:text-slate-500">
              <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h3 class="text-slate-600 dark:text-slate-300 font-bold text-base">Tidak ada tugas</h3>
          </div>
        </div>
      </div>

      <Teleport to="body">
        <div v-if="showDetailModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeDetail">
          <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh] border border-slate-200 dark:border-white/10">
            <div class="bg-slate-50 dark:bg-slate-900 px-5 py-4 border-b border-slate-200 dark:border-white/10 flex justify-between items-center">
              <h3 class="font-bold text-lg text-slate-800 dark:text-slate-100">Detail & Aksi</h3>
              <div class="flex items-center gap-2">
                <button v-if="currentTask && canPrivilegedTeknisi" @click="togglePriority(currentTask.id, currentTask.is_priority ? 0 : 1)" :class="currentTask.is_priority ? 'p-2 bg-yellow-100 dark:bg-yellow-500/20 text-yellow-600 dark:text-yellow-200 rounded-lg text-[10px] font-bold border border-yellow-200 dark:border-yellow-500/30' : 'p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 rounded-lg text-[10px] font-bold border border-slate-200 dark:border-white/10'">{{ currentTask.is_priority ? 'UN-PRIO' : 'PRIO' }}</button>
                <button v-if="currentTask && canManageCurrent && isTaskManageableStatus(currentTask)" @click="openTransferModal(currentTask)" class="p-2 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-200 rounded-lg text-[10px] font-bold border border-indigo-100 dark:border-indigo-500/30">Transfer</button>
                <button @click="closeDetail" class="h-9 w-9 flex items-center justify-center rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-slate-500 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 hover:text-slate-700 dark:hover:text-white transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
              </div>
            </div>
            <div class="p-5 overflow-y-auto text-sm bg-slate-50/50 dark:bg-slate-900/50 flex-1">
              <div v-if="detailLoading" class="py-10 text-center"><div class="animate-spin rounded-full h-10 w-10 border-b-4 border-blue-600 mx-auto"></div></div>
              <template v-else-if="currentTask">
                <div class="space-y-5">
                  <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm text-xs grid grid-cols-2 gap-3">
                    <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">TIKET</div><div class="text-right font-mono font-bold text-slate-800 dark:text-slate-100">#{{ currentTask.ticket_id || currentTask.id }}</div>
                    <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">STATUS</div><div class="text-right"><span :class="['px-2 py-1 rounded font-bold', detailStatusClass(currentTask.status)]">{{ currentTask.status || '-' }}</span></div>
                    <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">JADWAL</div><div class="text-right text-blue-600 dark:text-blue-300 font-bold">{{ formatDateLong(currentTask.installation_date) }}</div>
                    <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">WHATSAPP</div><div class="text-right font-extrabold text-slate-800 dark:text-slate-100">{{ currentTask.customer_phone || '-' }}</div>
                    <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">MAPS</div><div class="text-right"><a v-if="currentTask.coordinates" :href="`https://www.google.com/maps?q=${encodeURIComponent(currentTask.coordinates)}`" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 underline font-bold">Buka Maps</a><span v-else>-</span></div>
                    <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">POP</div><div class="text-right font-bold text-slate-800 dark:text-slate-100">{{ currentTask.pop || '-' }}</div>
                    <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">PAKET</div><div class="text-right font-bold text-slate-800 dark:text-slate-100">{{ currentTask.plan_name || '-' }}</div>
                  </div>

                  <div v-if="showWaButton" class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                      <div><div class="text-[10px] text-slate-400 font-bold uppercase">Aksi Cepat</div><div class="text-sm font-extrabold text-slate-800 dark:text-slate-100">Hubungi Pelanggan</div></div>
                      <a :href="waHref" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-green-600 text-white font-extrabold text-sm shadow active:scale-95">CHAT WA</a>
                    </div>
                    <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-2">*Tombol WA tampil setelah tugas diambil (status Proses).* </div>
                  </div>

                  <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm text-sm space-y-3">
                    <div><label class="text-[10px] text-slate-400 font-bold uppercase">Nama</label><div class="font-bold text-lg text-slate-800 dark:text-slate-100">{{ currentTask.customer_name || '-' }}</div></div>
                    <div><label class="text-[10px] text-slate-400 font-bold uppercase">Alamat</label><div class="leading-relaxed text-slate-700 dark:text-slate-300">{{ currentTask.address || '-' }}</div></div>
                  </div>

                  <div v-if="detailReadOnlyNote" class="bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-200 px-3 py-2 rounded-lg text-xs font-bold border border-amber-100 dark:border-amber-500/30">{{ detailReadOnlyNote }}</div>

                  <div class="space-y-2">
                    <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Audit Perubahan Data</h4>
                    <div class="bg-slate-50 dark:bg-slate-900/60 p-3 rounded-xl text-xs text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-white/10">
                      <div v-if="auditLoading" class="italic text-slate-400">Memuat...</div>
                      <div v-else-if="auditChanges.length === 0" class="italic text-slate-400">Belum ada perubahan.</div>
                      <div v-else class="space-y-2">
                        <div v-for="(log, idx) in auditChanges" :key="idx" class="border-b border-slate-200 dark:border-white/10 pb-2">
                          <div class="text-[10px] text-slate-400 mb-1">{{ log.changed_at || '-' }} - {{ log.changed_by || '-' }} ({{ log.changed_by_role || '-' }}) via {{ log.source || '-' }}</div>
                          <div class="text-xs text-slate-700 dark:text-slate-200"><span class="font-bold">{{ log.field_name || '-' }}</span>: <span class="text-slate-500 dark:text-slate-400">{{ log.old_value || '-' }}</span> <span class="text-slate-400 px-1">-></span> <span class="text-slate-800 dark:text-slate-100 font-bold">{{ log.new_value || '-' }}</span></div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="space-y-2">
                    <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Catatan / Riwayat</h4>
                    <div class="bg-slate-50 dark:bg-slate-900/60 p-3 rounded-xl text-sm text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-white/10 whitespace-pre-line">{{ currentTask.notes || '-' }}</div>
                  </div>
                </div>
              </template>
            </div>

            <div class="p-5 border-t border-slate-100 dark:border-white/10 bg-white dark:bg-slate-900">
              <div v-if="!currentTask || !allowActionsCurrent" class="grid grid-cols-1 gap-3"><button @click="closeDetail" class="h-12 w-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-xl font-bold">Tutup</button></div>
              <div v-else-if="currentStatus === 'Baru'" class="grid grid-cols-1 gap-3"><button @click="openClaimModal(currentTask)" class="h-12 w-full bg-blue-600 text-white rounded-xl font-bold shadow-lg">AMBIL TUGAS</button></div>
              <div v-else-if="currentStatus === 'Req_Batal'" class="grid grid-cols-1 gap-3">
                <button v-if="canPrivilegedTeknisi" @click="openReviewModal(currentTask)" class="h-12 w-full bg-slate-800 text-white rounded-xl font-bold shadow-lg">TINJAU & PUTUSKAN</button>
                <button v-else disabled class="h-12 w-full bg-slate-200 dark:bg-slate-800 text-slate-500 dark:text-slate-400 rounded-xl font-bold cursor-not-allowed">Menunggu Admin/SVP/CS</button>
              </div>
              <div v-else-if="currentStatus === 'Pending'" class="space-y-3">
                <button @click="resumeProses(currentTask.id)" class="h-12 w-full bg-blue-600 text-white rounded-xl font-bold shadow-lg">LANJUTKAN PROSES</button>
                <div class="grid grid-cols-2 gap-3"><button @click="openPendingModal(currentTask)" class="h-12 border-2 border-orange-200 dark:border-orange-500/30 text-orange-600 dark:text-orange-200 rounded-xl font-bold">TUNDA</button><button @click="openCancelReqModal(currentTask)" class="h-12 border-2 border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-200 rounded-xl font-bold">BATAL</button></div>
              </div>
              <div v-else-if="currentStatus === 'Survey'" class="space-y-3">
                <button @click="resumeProses(currentTask.id)" class="h-12 w-full bg-blue-600 text-white rounded-xl font-bold shadow-lg">JADIKAN PROSES</button>
                <div class="grid grid-cols-2 gap-3"><button @click="openPendingModal(currentTask)" class="h-12 border-2 border-orange-200 dark:border-orange-500/30 text-orange-600 dark:text-orange-200 rounded-xl font-bold">TUNDA</button><button @click="openCancelReqModal(currentTask)" class="h-12 border-2 border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-200 rounded-xl font-bold">BATAL</button></div>
              </div>
              <div v-else-if="currentStatus === 'Proses'" class="space-y-3">
                <button @click="openFinishModal(currentTask)" class="h-12 w-full bg-slate-900 dark:bg-slate-700 text-white rounded-xl font-bold shadow-lg">LAPOR SELESAI</button>
                <div class="grid grid-cols-2 gap-3"><button @click="openPendingModal(currentTask)" class="h-12 border-2 border-orange-200 dark:border-orange-500/30 text-orange-600 dark:text-orange-200 rounded-xl font-bold">TUNDA</button><button @click="openCancelReqModal(currentTask)" class="h-12 border-2 border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-200 rounded-xl font-bold">BATAL</button></div>
              </div>
              <div v-else class="grid grid-cols-1 gap-3"><button @click="closeDetail" class="h-12 w-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 rounded-xl font-bold">Tutup</button></div>
            </div>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="showClaimModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeClaimModal">
          <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 border-t-8 border-blue-600 border border-slate-200 dark:border-white/10">
            <div class="text-center mb-6"><h3 class="font-extrabold text-2xl text-slate-800 dark:text-slate-100">Ambil Tugas?</h3><p class="text-sm text-slate-500 dark:text-slate-400">{{ claimRecommendationNote }}</p></div>
            <div class="space-y-4 mb-6">
              <div class="bg-slate-50 dark:bg-slate-800 border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 py-3 text-center"><div class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">Rekomendasi Jadwal</div><div class="text-lg font-bold text-slate-800 dark:text-slate-100">{{ claimDateLabel }}</div></div>
              <div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Teknisi Utama</label><select v-model="claimForm.technician" class="w-full h-12 text-base font-bold bg-slate-50 dark:bg-slate-800 border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 text-slate-800 dark:text-slate-100"><option value="">-- Pilih --</option><option v-for="tech in techList" :key="`claim-main-${tech}`" :value="tech">{{ tech }}</option></select></div>
              <div class="space-y-2">
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Tim Support (Opsional)</label>
                <select v-model="claimForm.technician_2" class="w-full h-11 text-sm font-bold bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-slate-700 dark:text-slate-100"><option value="">-- Pilih --</option><option v-for="tech in techList" :key="`claim-2-${tech}`" :value="tech">{{ tech }}</option></select>
                <div v-if="claimShowTech3"><select v-model="claimForm.technician_3" class="w-full h-11 text-sm font-bold bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-slate-700 dark:text-slate-100"><option value="">-- Pilih --</option><option v-for="tech in techList" :key="`claim-3-${tech}`" :value="tech">{{ tech }}</option></select></div>
                <div v-if="claimShowTech4"><select v-model="claimForm.technician_4" class="w-full h-11 text-sm font-bold bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-slate-700 dark:text-slate-100"><option value="">-- Pilih --</option><option v-for="tech in techList" :key="`claim-4-${tech}`" :value="tech">{{ tech }}</option></select></div>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <button @click="closeClaimModal" class="h-12 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-xl font-bold">BATAL</button>
              <button @click="submitClaim" :disabled="claimSubmitting" class="h-12 bg-blue-600 text-white rounded-xl font-bold shadow-lg disabled:opacity-60">{{ claimSubmitting ? 'MEMPROSES...' : 'AMBIL' }}</button>
            </div>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="showPendingModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closePendingModal">
          <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 border border-slate-200 dark:border-white/10">
            <div class="text-center mb-6"><h3 class="font-extrabold text-xl text-slate-800 dark:text-slate-100">Pending / Tunda</h3></div>
            <div class="space-y-4 mb-6">
              <div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Alasan</label><textarea v-model="pendingForm.reason" rows="2" class="w-full text-base border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 py-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></textarea></div>
              <div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Jadwal Ulang</label><input v-model="pendingForm.date" type="date" class="w-full h-12 text-base font-bold bg-slate-50 dark:bg-slate-800 border-2 border-slate-300 dark:border-white/10 rounded-2xl px-3 text-center text-slate-800 dark:text-slate-100"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <button @click="closePendingModal" class="h-12 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-xl font-bold">BATAL</button>
              <button @click="submitPending" :disabled="pendingSubmitting" class="h-12 bg-orange-500 text-white rounded-xl font-bold shadow-lg disabled:opacity-60">{{ pendingSubmitting ? 'MENYIMPAN...' : 'SIMPAN' }}</button>
            </div>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="showCancelReqModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeCancelReqModal">
          <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 border-t-8 border-red-500 border border-slate-200 dark:border-white/10">
            <div class="text-center mb-6"><h3 class="font-extrabold text-xl text-slate-800 dark:text-slate-100">Ajukan Batal</h3><p class="text-xs text-slate-500 dark:text-slate-400">Perlu persetujuan admin / SVP / CS.</p></div>
            <div class="mb-6"><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Alasan</label><textarea v-model="cancelReqForm.reason" rows="3" class="w-full text-base border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 py-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></textarea></div>
            <div class="grid grid-cols-2 gap-4">
              <button @click="closeCancelReqModal" class="h-12 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-xl font-bold">KEMBALI</button>
              <button @click="submitCancelReq" :disabled="cancelReqSubmitting" class="h-12 bg-red-600 text-white rounded-xl font-bold shadow-lg disabled:opacity-60">{{ cancelReqSubmitting ? 'MENGAJUKAN...' : 'AJUKAN' }}</button>
            </div>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="showReviewModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeReviewModal">
          <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[95vh] border border-slate-200 dark:border-white/10">
            <div class="bg-slate-900 dark:bg-slate-800 px-5 py-4 border-b border-slate-800 dark:border-white/10 flex justify-between items-center text-white"><div><h3 class="font-bold text-lg">Tinjau Pembatalan</h3><p class="text-[10px] text-slate-300">Keputusan SVP / Admin / CS</p></div><button @click="closeReviewModal" class="h-9 w-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
            <div class="p-5 overflow-y-auto bg-slate-50 dark:bg-slate-900/60 space-y-4">
              <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-white/10 shadow-sm space-y-2"><h4 class="text-[10px] font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-1 mb-2">Data Pelanggan</h4><div><div class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ reviewTask?.customer_name || '-' }}</div><div class="text-xs text-blue-600 font-bold">{{ reviewTask?.plan_name || '-' }} ({{ reviewTask?.pop || '-' }})</div></div><div class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">{{ reviewTask?.address || '-' }}</div></div>
              <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-xl border border-red-100 dark:border-red-500/20 shadow-sm"><h4 class="text-[10px] font-bold text-red-600 uppercase border-b border-red-200 dark:border-red-500/20 pb-1 mb-2">History & Alasan</h4><div class="text-xs text-slate-700 dark:text-slate-200 font-mono whitespace-pre-wrap max-h-32 overflow-y-auto p-2 bg-white/50 dark:bg-slate-800/60 rounded border border-red-100 dark:border-red-500/20">{{ reviewTask?.notes || 'Belum ada catatan.' }}</div></div>
              <div><label class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase block mb-2">Catatan (Wajib jika Tolak)</label><textarea v-model="reviewForm.note" rows="3" class="w-full text-sm border-2 border-slate-300 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Alasan penolakan / tambahan..."></textarea></div>
            </div>
            <div class="p-5 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 grid grid-cols-2 gap-4"><button @click="submitReview('reject')" :disabled="reviewSubmitting" class="h-14 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold rounded-2xl text-sm border-2 border-slate-200 dark:border-white/10 disabled:opacity-60">TOLAK BATAL</button><button @click="submitReview('approve')" :disabled="reviewSubmitting" class="h-14 bg-red-600 text-white font-bold rounded-2xl text-sm shadow-lg disabled:opacity-60">ACC BATAL</button></div>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="showTransferModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeTransferModal">
          <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 border border-slate-200 dark:border-white/10">
            <div class="text-center mb-6"><h3 class="font-extrabold text-xl text-slate-800 dark:text-slate-100">Alihkan Tugas</h3></div>
            <div class="space-y-4 mb-6"><div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Ke Teknisi</label><select v-model="transferForm.to_tech" class="w-full h-12 text-base font-bold bg-slate-50 dark:bg-slate-800 border-2 border-slate-300 dark:border-white/10 rounded-2xl px-3 text-slate-800 dark:text-slate-100"><option value="">-- Pilih --</option><option v-for="name in transferTargetOptions" :key="`transfer-${name}`" :value="name">{{ name }}</option></select></div><div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Alasan</label><textarea v-model="transferForm.reason" rows="2" class="w-full text-base border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 py-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></textarea></div></div>
            <div class="grid grid-cols-2 gap-4"><button @click="closeTransferModal" class="h-12 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-xl font-bold">BATAL</button><button @click="submitTransfer" :disabled="transferSubmitting" class="h-12 bg-indigo-600 text-white rounded-xl font-bold shadow-lg disabled:opacity-60">{{ transferSubmitting ? 'MENGIRIM...' : 'KIRIM' }}</button></div>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="showFinishModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeFinishModal">
          <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[95vh] border border-slate-200 dark:border-white/10">
            <div class="bg-slate-900 dark:bg-slate-800 px-5 py-4 border-b border-slate-800 dark:border-white/10 flex justify-between items-center text-white"><h3 class="font-bold text-lg">Validasi Akhir</h3><button @click="closeFinishModal" class="h-9 w-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
            <div class="p-5 overflow-y-auto bg-slate-50 dark:bg-slate-900/60 space-y-5">
              <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                <h4 class="text-xs font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-2">Data Pelanggan</h4>
                <input v-model="finishForm.customer_name" type="text" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Nama">
                <input v-model="finishForm.customer_phone" type="tel" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="WA">
                <textarea v-model="finishForm.address" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Alamat"></textarea>
                <div class="grid grid-cols-2 gap-3"><select v-model="finishForm.pop" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">POP</option><option v-for="pop in popList" :key="`finish-pop-${pop}`" :value="pop">{{ pop }}</option></select><select v-model="finishForm.plan_name" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">Paket</option><option value="Standar - 10 Mbps">Standar - 10 Mbps</option><option value="Premium - 15 Mbps">Premium - 15 Mbps</option><option value="Wide - 20 Mbps">Wide - 20 Mbps</option><option value="PRO - 50 Mbps">PRO - 50 Mbps</option></select></div>
                <input v-model="finishForm.price" @input="onFinishPriceInput" type="text" class="w-full text-base font-bold text-green-700 dark:text-emerald-200 border-2 border-green-200 dark:border-emerald-700 bg-green-50 dark:bg-emerald-900/30 rounded-xl p-3" placeholder="Rp. 0">
              </div>
              <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                <h4 class="text-xs font-bold text-blue-600 uppercase border-b pb-2">Tim & Sales</h4>
                <input v-model="finishForm.sales_name" :readonly="finishSales1Locked" :class="finishSales1Locked ? 'w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 cursor-not-allowed' : 'w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100'" placeholder="Sales 1">
                <div class="grid grid-cols-2 gap-3"><input v-model="finishForm.sales_name_2" type="text" placeholder="Sales 2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><input v-model="finishForm.sales_name_3" type="text" placeholder="Sales 3" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></div>
                <div class="pt-2 border-t border-dashed border-slate-200 dark:border-white/10"><label class="text-[10px] text-slate-400 font-bold uppercase mb-1 block">Teknisi Utama (Lead)</label><select v-model="finishForm.technician" class="w-full text-sm border border-blue-200 dark:border-blue-500/30 rounded-xl p-3 bg-blue-50 dark:bg-blue-900/30 font-bold text-blue-800 dark:text-blue-200 outline-none"><option value="">-- Pilih --</option><option v-for="tech in techList" :key="`finish-tech-1-${tech}`" :value="tech">{{ tech }}</option></select></div>
                <div><label class="text-[10px] text-slate-400 font-bold uppercase mb-1 block">Tim Support</label><select v-model="finishForm.technician_2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 mb-2"><option value="">-- Tech 2 --</option><option v-for="tech in techList" :key="`finish-tech-2-${tech}`" :value="tech">{{ tech }}</option></select><div class="grid grid-cols-2 gap-3"><select v-model="finishForm.technician_3" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-- Tech 3 --</option><option v-for="tech in techList" :key="`finish-tech-3-${tech}`" :value="tech">{{ tech }}</option></select><select v-model="finishForm.technician_4" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-- Tech 4 --</option><option v-for="tech in techList" :key="`finish-tech-4-${tech}`" :value="tech">{{ tech }}</option></select></div></div>
              </div>
              <textarea v-model="finishForm.notes" rows="3" class="w-full text-sm border-2 border-blue-200 dark:border-blue-500/30 rounded-2xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Laporan..."></textarea>
            </div>
            <div class="p-5 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900"><button @click="submitFinish" :disabled="finishSubmitting" class="h-12 w-full bg-slate-900 text-white shadow-xl rounded-xl font-bold disabled:opacity-60">{{ finishSubmitting ? 'MENYIMPAN...' : 'SIMPAN & SELESAI' }}</button></div>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="showNewInstallModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4 backdrop-blur-sm" @click.self="closeNewInstallModal">
          <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[95vh] border border-slate-200 dark:border-white/10">
            <div class="bg-slate-900 dark:bg-slate-800 px-5 py-4 border-b border-slate-800 dark:border-white/10 flex justify-between items-center text-white"><h3 class="font-bold text-lg">Input Pasang Baru</h3><button @click="closeNewInstallModal" class="h-9 w-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
            <div class="p-5 overflow-y-auto bg-slate-50 dark:bg-slate-900/60 space-y-4">
              <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                <h4 class="text-xs font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-2">Data Pelanggan</h4>
                <input v-model="newInstall.customer_name" type="text" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Nama Pelanggan *">
                <input v-model="newInstall.customer_phone" type="tel" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="WhatsApp (08xxxxxxxxxx) *">
                <textarea v-model="newInstall.address" rows="2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Alamat Lengkap *"></textarea>
                <div class="grid grid-cols-2 gap-3"><select v-model="newInstall.pop" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">POP *</option><option v-for="pop in popList" :key="`new-pop-${pop}`" :value="pop">{{ pop }}</option></select><select v-model="newInstall.plan_name" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">Paket</option><option value="Standar - 10 Mbps">Standar - 10 Mbps</option><option value="Premium - 15 Mbps">Premium - 15 Mbps</option><option value="Wide - 20 Mbps">Wide - 20 Mbps</option><option value="PRO - 50 Mbps">PRO - 50 Mbps</option></select></div>
                <input v-model="newInstall.price" @input="onNewInstallPriceInput" type="text" class="w-full text-base font-bold text-green-700 dark:text-emerald-200 border-2 border-green-200 dark:border-emerald-700 bg-green-50 dark:bg-emerald-900/30 rounded-xl p-3" placeholder="Rp. 0">
              </div>
              <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                <h4 class="text-xs font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-2">Tim & Sales</h4>
                <input v-model="newInstall.sales_name" type="text" placeholder="Sales 1" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                <div class="grid grid-cols-2 gap-3"><input v-model="newInstall.sales_name_2" type="text" placeholder="Sales 2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><input v-model="newInstall.sales_name_3" type="text" placeholder="Sales 3" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block mb-1">Teknisi 1</label><select v-model="newInstall.technician" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-2 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-</option><option v-for="tech in techList" :key="`new-tech-1-${tech}`" :value="tech">{{ tech }}</option></select></div><div><label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block mb-1">Teknisi 2</label><select v-model="newInstall.technician_2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-2 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-</option><option v-for="tech in techList" :key="`new-tech-2-${tech}`" :value="tech">{{ tech }}</option></select></div></div>
                <div class="grid grid-cols-2 gap-3"><div><label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block mb-1">Teknisi 3</label><select v-model="newInstall.technician_3" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-2 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-</option><option v-for="tech in techList" :key="`new-tech-3-${tech}`" :value="tech">{{ tech }}</option></select></div><div><label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block mb-1">Teknisi 4</label><select v-model="newInstall.technician_4" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-2 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-</option><option v-for="tech in techList" :key="`new-tech-4-${tech}`" :value="tech">{{ tech }}</option></select></div></div>
              </div>
              <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                <h4 class="text-xs font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-2">Informasi Tambahan</h4>
                <input v-model="newInstall.installation_date" type="date" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                <input v-model="newInstall.coordinates" type="text" placeholder="Koordinat (lat,lng)" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                <textarea v-model="newInstall.notes" rows="2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Catatan tambahan..."></textarea>
              </div>
            </div>
            <div class="p-5 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 grid grid-cols-2 gap-4"><button @click="closeNewInstallModal" class="h-12 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-xl font-bold">BATAL</button><button @click="saveNewInstall" :disabled="newInstallSubmitting" class="h-12 bg-blue-600 text-white rounded-xl font-bold shadow-lg disabled:opacity-60">{{ newInstallSubmitting ? 'MENYIMPAN...' : 'SIMPAN' }}</button></div>
          </div>
        </div>
      </Teleport>
    </div>
  </AdminLayout>
</template>

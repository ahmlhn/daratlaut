<script setup>
import { ref, computed, onMounted, onUnmounted, watch, inject, nextTick } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const toast = inject('toast')

const props = defineProps({
  tenantId: { type: Number, default: 0 },
  warning: { type: String, default: null },
})

const page = usePage()
const permissions = computed(() => page.props.auth?.user?.permissions || [])
const legacyRoleRaw = computed(() => (page.props.auth?.user?.role || '').toString().toLowerCase().trim())

function normalizeRole(role) {
  const r = (role || '').toString().toLowerCase().trim()
  if (r === 'svp lapangan') return 'svp_lapangan'
  return r
}

function can(permission) {
  const role = normalizeRole(legacyRoleRaw.value)
  if (['admin', 'owner'].includes(role)) return true
  if (permissions.value.includes('manage fiber')) return true
  return permissions.value.includes(permission)
}

const canView = computed(() => can('view fiber'))
const canCreate = computed(() => can('create fiber'))
const canEdit = computed(() => can('edit fiber'))
const canDelete = computed(() => can('delete fiber'))

const loading = ref(false)
const errorMessage = ref('')
const summary = ref({ cables: 0, points: 0, breaks_total: 0, breaks_open: 0 })

const cables = ref([])
const points = ref([])
const breaks = ref([])
const ports = ref([])
const links = ref([])

const activeTab = ref('cables') // cables|points|ports|links|trace|breaks

const showCables = ref(true)
const showPoints = ref(true)
const showBreaks = ref(true)

const selectedCableId = ref(null)
const selectedPointId = ref(null)
const selectedBreakId = ref(null)

const traceResult = ref(null)
const traceUsedCableIds = ref([])
const traceUsedCableSet = computed(() => {
  const s = new Set()
  ;(traceUsedCableIds.value || []).forEach((id) => {
    const n = Number(id)
    if (Number.isFinite(n) && n > 0) s.add(n)
  })
  return s
})

/* ───────────── API helper ───────────── */
async function requestJson(url, options = {}) {
  const res = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(options.headers || {}),
    },
    ...options,
  })

  const text = await res.text()
  let data = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    data = null
  }

  return { ok: res.ok, status: res.status, data, text }
}

async function loadAll() {
  if (!canView.value) return
  loading.value = true
  errorMessage.value = ''

  try {
    const [mapRes, sumRes] = await Promise.all([
      requestJson('/api/v1/fiber/map-data'),
      requestJson('/api/v1/fiber/summary'),
    ])

    if (!mapRes.ok) {
      const msg = mapRes.data?.message || `Gagal memuat data fiber (HTTP ${mapRes.status}).`
      throw new Error(msg)
    }

    if (sumRes.ok) {
      summary.value = sumRes.data?.data || summary.value
    } else {
      summary.value = { cables: 0, points: 0, breaks_total: 0, breaks_open: 0 }
    }

    cables.value = mapRes.data?.data?.cables || []
    points.value = mapRes.data?.data?.points || []
    breaks.value = mapRes.data?.data?.breaks || []
    ports.value = mapRes.data?.data?.ports || []
    links.value = mapRes.data?.data?.links || []

    renderAllLayers()
    fitOnce()
  } catch (e) {
    console.error('Fiber: loadAll failed', e)
    errorMessage.value = (e && e.message) ? e.message : 'Gagal memuat data fiber.'
  } finally {
    loading.value = false
  }
}

/* ───────────── Map setup ───────────── */
const mapContainer = ref(null)
let map = null
let baseLayer = null
let baseLabelLayer = null

let layerCables = null
let layerPoints = null
let layerBreaks = null
let layerDraft = null

let fittedOnce = false

const mapStyle = ref('voyager')
const MAP_STYLES = {
  voyager: {
    label: 'Voyager',
    tile: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    tileOpts: { maxZoom: 19, subdomains: 'abcd' },
    labels: null,
  },
  light: {
    label: 'Light',
    tile: 'https://{s}.basemaps.cartocdn.com/rastertiles/light_all/{z}/{x}/{y}{r}.png',
    tileOpts: { maxZoom: 19, subdomains: 'abcd' },
    labels: null,
  },
  satellite: {
    label: 'Satelit',
    tile: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    tileOpts: { maxZoom: 19 },
    labels: 'https://services.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
    labelOpts: { maxZoom: 19, opacity: 0.85 },
  },
}

function applyMapStyle() {
  if (!map) return
  const cfg = MAP_STYLES[mapStyle.value] || MAP_STYLES.voyager

  if (baseLayer) {
    try { map.removeLayer(baseLayer) } catch {}
    baseLayer = null
  }
  if (baseLabelLayer) {
    try { map.removeLayer(baseLabelLayer) } catch {}
    baseLabelLayer = null
  }

  baseLayer = window.L.tileLayer(cfg.tile, {
    attribution: '&copy; OpenStreetMap contributors',
    ...(cfg.tileOpts || {}),
  })
  baseLayer.addTo(map)

  if (cfg.labels) {
    baseLabelLayer = window.L.tileLayer(cfg.labels, {
      ...(cfg.labelOpts || {}),
    })
    baseLabelLayer.addTo(map)
  }
}

function initMap() {
  if (!window.L || !mapContainer.value) return

  map = window.L.map(mapContainer.value, { zoomControl: true }).setView([-2.5489, 118.0149], 5)
  layerCables = window.L.layerGroup().addTo(map)
  layerPoints = window.L.layerGroup().addTo(map)
  layerBreaks = window.L.layerGroup().addTo(map)
  layerDraft = window.L.layerGroup().addTo(map)

  applyMapStyle()
  map.on('click', onMapClick)
}

function destroyMap() {
  if (!map) return
  try { map.off('click', onMapClick) } catch {}
  try { map.remove() } catch {}
  map = null
  baseLayer = null
  baseLabelLayer = null
  layerCables = null
  layerPoints = null
  layerBreaks = null
  layerDraft = null
}

/* ───────────── Map interaction modes ───────────── */
const mapMode = ref(null) // null | pickPoint | pickBreak | drawCable

function roundCoord(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return null
  return Math.round(v * 1e7) / 1e7
}

function onMapClick(e) {
  if (!e?.latlng) return
  const lat = roundCoord(e.latlng.lat)
  const lng = roundCoord(e.latlng.lng)

  if (mapMode.value === 'pickPoint') {
    pointForm.value.latitude = lat
    pointForm.value.longitude = lng
    mapMode.value = null
    if (toast) toast.success('Lokasi titik dipilih dari peta.')
    renderDraft()
    return
  }

  if (mapMode.value === 'pickBreak') {
    breakForm.value.latitude = lat
    breakForm.value.longitude = lng
    mapMode.value = null
    if (toast) toast.success('Lokasi putus dipilih dari peta.')
    renderDraft()
    return
  }

  if (mapMode.value === 'drawCable') {
    const path = Array.isArray(cableForm.value.path) ? cableForm.value.path : []
    path.push({ lat, lng })
    cableForm.value.path = path
    renderDraft()
  }
}

function stopMapMode() {
  mapMode.value = null
  renderDraft()
}

/* ───────────── Leaflet popups (safe text) ───────────── */
function popupEl(lines) {
  const div = document.createElement('div')
  div.className = 'text-sm'
  ;(lines || []).forEach((t) => {
    const row = document.createElement('div')
    row.textContent = String(t ?? '')
    div.appendChild(row)
  })
  return div
}

function pointNameById(id) {
  const n = Number(id || 0)
  if (!n) return null
  const p = (points.value || []).find((x) => Number(x?.id) === n)
  return p ? String(p.name || '') : null
}

function cableNameById(id) {
  const n = Number(id || 0)
  if (!n) return null
  const c = (cables.value || []).find((x) => Number(x?.id) === n)
  return c ? String(c.name || '') : null
}

function statusColor(status) {
  const s = String(status || '').toUpperCase()
  if (s === 'OPEN') return { stroke: '#dc2626', fill: '#ef4444' }
  if (s === 'IN_PROGRESS') return { stroke: '#b45309', fill: '#f59e0b' }
  if (s === 'FIXED') return { stroke: '#047857', fill: '#10b981' }
  return { stroke: '#64748b', fill: '#94a3b8' }
}

/* ───────────── Render layers ───────────── */
function renderAllLayers() {
  if (!map || !window.L) return
  renderCablesLayer()
  renderPointsLayer()
  renderBreaksLayer()
  renderDraft()
}

function renderCablesLayer() {
  if (!layerCables) return
  layerCables.clearLayers()
  if (!showCables.value) return

  ;(cables.value || []).forEach((c) => {
    const path = Array.isArray(c?.path) ? c.path : null
    if (!path || path.length < 2) return

    const latlngs = path
      .map((p) => [Number(p?.lat), Number(p?.lng)])
      .filter((p) => Number.isFinite(p[0]) && Number.isFinite(p[1]))

    if (latlngs.length < 2) return

    const isSelected = Number(c?.id) === Number(selectedCableId.value || 0)
    const traceActive = traceUsedCableSet.value.size > 0
    const inTrace = traceUsedCableSet.value.has(Number(c?.id))
    const color = (c?.map_color || '#2563eb').toString()

    const weightBase = isSelected ? 6 : 4
    const weight = inTrace ? Math.max(weightBase, 6) : weightBase
    const opacity = traceActive ? (inTrace ? 1 : 0.25) : 0.9

    const poly = window.L.polyline(latlngs, {
      color,
      weight,
      opacity,
    })

    poly.on('click', () => {
      selectedCableId.value = c.id
      selectedPointId.value = null
      selectedBreakId.value = null
      activeTab.value = 'cables'
      renderCablesLayer()
    })

    const fromName = pointNameById(c?.from_point_id) || '-'
    const toName = pointNameById(c?.to_point_id) || '-'
    poly.bindPopup(popupEl([
      `Kabel: ${c?.name || '-'}`,
      c?.code ? `Kode: ${c.code}` : null,
      c?.cable_type ? `Tipe: ${c.cable_type}` : null,
      c?.core_count ? `Core: ${c.core_count}` : null,
      `Dari: ${fromName}`,
      `Ke: ${toName}`,
    ].filter(Boolean)))

    poly.addTo(layerCables)
  })
}

function renderPointsLayer() {
  if (!layerPoints) return
  layerPoints.clearLayers()
  if (!showPoints.value) return

  ;(points.value || []).forEach((p) => {
    const lat = Number(p?.latitude)
    const lng = Number(p?.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return

    const isSelected = Number(p?.id) === Number(selectedPointId.value || 0)
    const marker = window.L.circleMarker([lat, lng], {
      radius: isSelected ? 8 : 6,
      color: '#0f766e',
      weight: 2,
      fillColor: '#14b8a6',
      fillOpacity: 0.9,
    })

    marker.on('click', () => {
      selectedPointId.value = p.id
      selectedCableId.value = null
      selectedBreakId.value = null
      activeTab.value = 'points'
      renderPointsLayer()
    })

    marker.bindPopup(popupEl([
      `Titik: ${p?.name || '-'}`,
      p?.point_type ? `Tipe: ${p.point_type}` : null,
      `Koordinat: ${lat}, ${lng}`,
    ].filter(Boolean)))

    marker.addTo(layerPoints)
  })
}

function renderBreaksLayer() {
  if (!layerBreaks) return
  layerBreaks.clearLayers()
  if (!showBreaks.value) return

  ;(breaks.value || []).forEach((b) => {
    const lat = Number(b?.latitude)
    const lng = Number(b?.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return

    const colors = statusColor(b?.status)
    const isSelected = Number(b?.id) === Number(selectedBreakId.value || 0)

    const marker = window.L.circleMarker([lat, lng], {
      radius: isSelected ? 9 : 7,
      color: colors.stroke,
      weight: 2,
      fillColor: colors.fill,
      fillOpacity: 0.9,
    })

    marker.on('click', () => {
      selectedBreakId.value = b.id
      selectedCableId.value = null
      selectedPointId.value = null
      activeTab.value = 'breaks'
      renderBreaksLayer()
    })

    const cableName = cableNameById(b?.cable_id) || (b?.cable_id ? `#${b.cable_id}` : '-')
    const pName = pointNameById(b?.point_id) || (b?.point_id ? `#${b.point_id}` : '-')
    marker.bindPopup(popupEl([
      `PUTUS: ${String(b?.status || 'OPEN').toUpperCase()}`,
      `Kabel: ${cableName}`,
      `Titik: ${pName}`,
      b?.severity ? `Severity: ${b.severity}` : null,
    ].filter(Boolean)))

    marker.addTo(layerBreaks)
  })
}

function renderDraft() {
  if (!layerDraft || !window.L) return
  layerDraft.clearLayers()

  if (showPointModal.value && pointForm.value.latitude !== null && pointForm.value.longitude !== null) {
    const lat = Number(pointForm.value.latitude)
    const lng = Number(pointForm.value.longitude)
    if (Number.isFinite(lat) && Number.isFinite(lng)) {
      window.L.circleMarker([lat, lng], {
        radius: 7,
        color: '#0ea5e9',
        weight: 2,
        fillColor: '#38bdf8',
        fillOpacity: 0.85,
      }).addTo(layerDraft)
    }
  }

  if (showBreakModal.value && breakForm.value.latitude !== null && breakForm.value.longitude !== null) {
    const lat = Number(breakForm.value.latitude)
    const lng = Number(breakForm.value.longitude)
    if (Number.isFinite(lat) && Number.isFinite(lng)) {
      window.L.circleMarker([lat, lng], {
        radius: 8,
        color: '#f97316',
        weight: 2,
        fillColor: '#fb923c',
        fillOpacity: 0.85,
      }).addTo(layerDraft)
    }
  }

  if (showCableModal.value && Array.isArray(cableForm.value.path) && cableForm.value.path.length > 0) {
    const latlngs = cableForm.value.path
      .map((p) => [Number(p?.lat), Number(p?.lng)])
      .filter((p) => Number.isFinite(p[0]) && Number.isFinite(p[1]))

    latlngs.forEach((ll) => {
      window.L.circleMarker(ll, {
        radius: 4,
        color: '#1d4ed8',
        weight: 2,
        fillColor: '#60a5fa',
        fillOpacity: 0.95,
      }).addTo(layerDraft)
    })

    if (latlngs.length >= 2) {
      window.L.polyline(latlngs, {
        color: '#1d4ed8',
        weight: 4,
        opacity: 0.9,
        dashArray: '6, 8',
      }).addTo(layerDraft)
    }
  }
}

function fitOnce() {
  if (!map || fittedOnce) return

  const bounds = []
  try {
    if (layerCables && showCables.value) layerCables.eachLayer((l) => { if (l.getBounds) bounds.push(l.getBounds()) })
  } catch {}
  try {
    if (layerPoints && showPoints.value) layerPoints.eachLayer((l) => { if (l.getLatLng) bounds.push(window.L.latLngBounds([l.getLatLng(), l.getLatLng()])) })
  } catch {}
  try {
    if (layerBreaks && showBreaks.value) layerBreaks.eachLayer((l) => { if (l.getLatLng) bounds.push(window.L.latLngBounds([l.getLatLng(), l.getLatLng()])) })
  } catch {}

  if (bounds.length === 0) return

  try {
    let b = null
    bounds.forEach((x) => {
      if (!b) b = x
      else b.extend(x)
    })

    if (b && b.isValid && b.isValid()) {
      map.fitBounds(b.pad(0.2))
      fittedOnce = true
    }
  } catch {}
}

/* ───────────── UI helpers ───────────── */
function formatStatus(s) {
  const v = String(s || 'OPEN').toUpperCase()
  if (v === 'IN_PROGRESS') return 'IN PROGRESS'
  return v
}

function toDatetimeLocal(v) {
  if (!v) return ''
  const d = new Date(v)
  if (!Number.isFinite(d.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function focusOnLatLng(lat, lng, zoom = 17) {
  if (!map || !Number.isFinite(lat) || !Number.isFinite(lng)) return
  map.setView([lat, lng], zoom, { animate: true })
}

/* ───────────── Modals: Cable ───────────── */
const showCableModal = ref(false)
const cableModalMode = ref('create') // create|edit
const cableProcessing = ref(false)
const cableErrors = ref({})

const cableForm = ref({
  id: null,
  name: '',
  code: '',
  cable_type: '',
  core_count: '',
  map_color: '#2563eb',
  from_point_id: '',
  to_point_id: '',
  path: [],
  notes: '',
})

function resetCableForm() {
  cableForm.value = {
    id: null,
    name: '',
    code: '',
    cable_type: '',
    core_count: '',
    map_color: '#2563eb',
    from_point_id: '',
    to_point_id: '',
    path: [],
    notes: '',
  }
  cableErrors.value = {}
}

function openCreateCable() {
  if (!canCreate.value) return
  resetCableForm()
  cableModalMode.value = 'create'
  showCableModal.value = true
  mapMode.value = null
  renderDraft()
}

function openEditCable(item) {
  if (!canEdit.value) return
  resetCableForm()
  cableModalMode.value = 'edit'
  cableForm.value = {
    id: item.id,
    name: item.name || '',
    code: item.code || '',
    cable_type: item.cable_type || '',
    core_count: item.core_count ?? '',
    map_color: item.map_color || '#2563eb',
    from_point_id: item.from_point_id ?? '',
    to_point_id: item.to_point_id ?? '',
    path: Array.isArray(item.path) ? item.path.slice() : [],
    notes: item.notes || '',
  }
  showCableModal.value = true
  mapMode.value = null
  renderDraft()

  const p0 = (cableForm.value.path || [])[0]
  if (p0 && Number.isFinite(Number(p0.lat)) && Number.isFinite(Number(p0.lng))) {
    focusOnLatLng(Number(p0.lat), Number(p0.lng), 16)
  }
}

async function saveCable() {
  cableProcessing.value = true
  cableErrors.value = {}

  const payload = {
    name: cableForm.value.name,
    code: cableForm.value.code || null,
    cable_type: cableForm.value.cable_type || null,
    core_count: cableForm.value.core_count !== '' ? Number(cableForm.value.core_count) : null,
    map_color: cableForm.value.map_color || null,
    from_point_id: cableForm.value.from_point_id !== '' ? Number(cableForm.value.from_point_id) : null,
    to_point_id: cableForm.value.to_point_id !== '' ? Number(cableForm.value.to_point_id) : null,
    path: Array.isArray(cableForm.value.path) && cableForm.value.path.length >= 2 ? cableForm.value.path : null,
    notes: cableForm.value.notes || null,
  }

  const isEdit = cableModalMode.value === 'edit' && cableForm.value.id
  const url = isEdit ? `/api/v1/fiber/cables/${cableForm.value.id}` : '/api/v1/fiber/cables'
  const method = isEdit ? 'PUT' : 'POST'

  try {
    const res = await requestJson(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      if (res.status === 422 && res.data?.errors) {
        cableErrors.value = res.data.errors
        throw new Error(res.data?.message || 'Validasi gagal.')
      }
      throw new Error(res.data?.message || `Gagal menyimpan kabel (HTTP ${res.status}).`)
    }

    showCableModal.value = false
    stopMapMode()
    await loadAll()
    if (toast) toast.success('Kabel tersimpan.')
  } catch (e) {
    console.error('Fiber: saveCable failed', e)
    if (toast) toast.error(e?.message || 'Gagal menyimpan kabel.')
  } finally {
    cableProcessing.value = false
  }
}

async function deleteCable(item) {
  if (!canDelete.value) return
  const ok = confirm(`Hapus kabel "${item?.name || ''}"?`)
  if (!ok) return

  try {
    const res = await requestJson(`/api/v1/fiber/cables/${item.id}`, { method: 'DELETE' })
    if (!res.ok) throw new Error(res.data?.message || `Gagal hapus kabel (HTTP ${res.status}).`)

    if (toast) toast.success('Kabel dihapus.')
    if (Number(selectedCableId.value) === Number(item.id)) selectedCableId.value = null
    await loadAll()
  } catch (e) {
    console.error('Fiber: deleteCable failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus kabel.')
  }
}

function startDrawCable() {
  if (!showCableModal.value) return
  mapMode.value = 'drawCable'
  renderDraft()
  if (toast) toast.info('Mode gambar jalur aktif: klik peta untuk menambah titik.')
}

function undoCablePoint() {
  const path = Array.isArray(cableForm.value.path) ? cableForm.value.path : []
  if (path.length === 0) return
  path.pop()
  cableForm.value.path = path
  renderDraft()
}

function clearCablePath() {
  cableForm.value.path = []
  renderDraft()
}

/* ───────────── Modals: Point ───────────── */
const showPointModal = ref(false)
const pointModalMode = ref('create')
const pointProcessing = ref(false)
const pointErrors = ref({})

const pointForm = ref({
  id: null,
  name: '',
  point_type: '',
  latitude: null,
  longitude: null,
  address: '',
  notes: '',
})

function resetPointForm() {
  pointForm.value = {
    id: null,
    name: '',
    point_type: '',
    latitude: null,
    longitude: null,
    address: '',
    notes: '',
  }
  pointErrors.value = {}
}

function openCreatePoint() {
  if (!canCreate.value) return
  resetPointForm()
  pointModalMode.value = 'create'
  showPointModal.value = true
  mapMode.value = null
  renderDraft()
}

function openEditPoint(item) {
  if (!canEdit.value) return
  resetPointForm()
  pointModalMode.value = 'edit'
  pointForm.value = {
    id: item.id,
    name: item.name || '',
    point_type: item.point_type || '',
    latitude: item.latitude ?? null,
    longitude: item.longitude ?? null,
    address: item.address || '',
    notes: item.notes || '',
  }
  showPointModal.value = true
  mapMode.value = null
  renderDraft()

  const lat = Number(pointForm.value.latitude)
  const lng = Number(pointForm.value.longitude)
  if (Number.isFinite(lat) && Number.isFinite(lng)) focusOnLatLng(lat, lng, 17)
}

function pickPointOnMap() {
  if (!showPointModal.value) return
  mapMode.value = 'pickPoint'
  renderDraft()
  if (toast) toast.info('Klik peta untuk memilih lokasi titik.')
}

async function savePoint() {
  pointProcessing.value = true
  pointErrors.value = {}

  const payload = {
    name: pointForm.value.name,
    point_type: pointForm.value.point_type || null,
    latitude: pointForm.value.latitude,
    longitude: pointForm.value.longitude,
    address: pointForm.value.address || null,
    notes: pointForm.value.notes || null,
  }

  const isEdit = pointModalMode.value === 'edit' && pointForm.value.id
  const url = isEdit ? `/api/v1/fiber/points/${pointForm.value.id}` : '/api/v1/fiber/points'
  const method = isEdit ? 'PUT' : 'POST'

  try {
    const res = await requestJson(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      if (res.status === 422 && res.data?.errors) {
        pointErrors.value = res.data.errors
        throw new Error(res.data?.message || 'Validasi gagal.')
      }
      throw new Error(res.data?.message || `Gagal menyimpan titik (HTTP ${res.status}).`)
    }

    showPointModal.value = false
    stopMapMode()
    await loadAll()
    if (toast) toast.success('Titik tersimpan.')
  } catch (e) {
    console.error('Fiber: savePoint failed', e)
    if (toast) toast.error(e?.message || 'Gagal menyimpan titik.')
  } finally {
    pointProcessing.value = false
  }
}

async function deletePoint(item) {
  if (!canDelete.value) return
  const ok = confirm(`Hapus titik "${item?.name || ''}"?`)
  if (!ok) return

  try {
    const res = await requestJson(`/api/v1/fiber/points/${item.id}`, { method: 'DELETE' })
    if (!res.ok) throw new Error(res.data?.message || `Gagal hapus titik (HTTP ${res.status}).`)

    if (toast) toast.success('Titik dihapus.')
    if (Number(selectedPointId.value) === Number(item.id)) selectedPointId.value = null
    await loadAll()
  } catch (e) {
    console.error('Fiber: deletePoint failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus titik.')
  }
}

/* ───────────── Modals: Break ───────────── */
const showBreakModal = ref(false)
const breakModalMode = ref('create')
const breakProcessing = ref(false)
const breakErrors = ref({})

const breakForm = ref({
  id: null,
  cable_id: '',
  point_id: '',
  status: 'OPEN',
  severity: '',
  reported_at: '',
  fixed_at: '',
  latitude: null,
  longitude: null,
  description: '',
})

function resetBreakForm() {
  breakForm.value = {
    id: null,
    cable_id: '',
    point_id: '',
    status: 'OPEN',
    severity: '',
    reported_at: '',
    fixed_at: '',
    latitude: null,
    longitude: null,
    description: '',
  }
  breakErrors.value = {}
}

function openCreateBreak() {
  if (!canCreate.value) return
  resetBreakForm()
  breakModalMode.value = 'create'
  showBreakModal.value = true
  mapMode.value = null
  renderDraft()
}

function openEditBreak(item) {
  if (!canEdit.value) return
  resetBreakForm()
  breakModalMode.value = 'edit'
  breakForm.value = {
    id: item.id,
    cable_id: item.cable_id ?? '',
    point_id: item.point_id ?? '',
    status: (item.status || 'OPEN').toString().toUpperCase(),
    severity: item.severity || '',
    reported_at: toDatetimeLocal(item.reported_at),
    fixed_at: toDatetimeLocal(item.fixed_at),
    latitude: item.latitude ?? null,
    longitude: item.longitude ?? null,
    description: item.description || '',
  }
  showBreakModal.value = true
  mapMode.value = null
  renderDraft()

  const lat = Number(breakForm.value.latitude)
  const lng = Number(breakForm.value.longitude)
  if (Number.isFinite(lat) && Number.isFinite(lng)) focusOnLatLng(lat, lng, 17)
}

function pickBreakOnMap() {
  if (!showBreakModal.value) return
  mapMode.value = 'pickBreak'
  renderDraft()
  if (toast) toast.info('Klik peta untuk memilih lokasi putus.')
}

async function saveBreak() {
  breakProcessing.value = true
  breakErrors.value = {}

  const payload = {
    cable_id: breakForm.value.cable_id !== '' ? Number(breakForm.value.cable_id) : null,
    point_id: breakForm.value.point_id !== '' ? Number(breakForm.value.point_id) : null,
    status: (breakForm.value.status || 'OPEN').toString().toUpperCase(),
    severity: breakForm.value.severity || null,
    reported_at: breakForm.value.reported_at || null,
    fixed_at: breakForm.value.fixed_at || null,
    latitude: breakForm.value.latitude,
    longitude: breakForm.value.longitude,
    description: breakForm.value.description || null,
  }

  const isEdit = breakModalMode.value === 'edit' && breakForm.value.id
  const url = isEdit ? `/api/v1/fiber/breaks/${breakForm.value.id}` : '/api/v1/fiber/breaks'
  const method = isEdit ? 'PUT' : 'POST'

  try {
    const res = await requestJson(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      if (res.status === 422 && res.data?.errors) {
        breakErrors.value = res.data.errors
        throw new Error(res.data?.message || 'Validasi gagal.')
      }
      throw new Error(res.data?.message || `Gagal menyimpan data putus (HTTP ${res.status}).`)
    }

    showBreakModal.value = false
    stopMapMode()
    await loadAll()
    if (toast) toast.success('Data putus tersimpan.')
  } catch (e) {
    console.error('Fiber: saveBreak failed', e)
    if (toast) toast.error(e?.message || 'Gagal menyimpan data putus.')
  } finally {
    breakProcessing.value = false
  }
}

async function deleteBreak(item) {
  if (!canDelete.value) return
  const ok = confirm('Hapus data putus ini?')
  if (!ok) return

  try {
    const res = await requestJson(`/api/v1/fiber/breaks/${item.id}`, { method: 'DELETE' })
    if (!res.ok) throw new Error(res.data?.message || `Gagal hapus data putus (HTTP ${res.status}).`)

    if (toast) toast.success('Data putus dihapus.')
    if (Number(selectedBreakId.value) === Number(item.id)) selectedBreakId.value = null
    await loadAll()
  } catch (e) {
    console.error('Fiber: deleteBreak failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus data putus.')
  }
}

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ Ports (OLT PON / ODP OUT) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
const showPortModal = ref(false)
const portModalMode = ref('create') // create|edit
const portProcessing = ref(false)
const portErrors = ref({})

const portForm = ref({
  id: null,
  point_id: '',
  port_type: 'OLT_PON',
  port_label: '',
  cable_id: '',
  core_no: '',
  notes: '',
})

function resetPortForm() {
  portForm.value = {
    id: null,
    point_id: '',
    port_type: 'OLT_PON',
    port_label: '',
    cable_id: '',
    core_no: '',
    notes: '',
  }
  portErrors.value = {}
}

function openCreatePort() {
  if (!canCreate.value) return
  resetPortForm()
  if (selectedPointId.value) portForm.value.point_id = String(selectedPointId.value)
  portModalMode.value = 'create'
  showPortModal.value = true
}

function openEditPort(item) {
  if (!canEdit.value) return
  resetPortForm()
  portModalMode.value = 'edit'
  portForm.value = {
    id: item.id,
    point_id: item.point_id ?? '',
    port_type: item.port_type || 'OLT_PON',
    port_label: item.port_label || '',
    cable_id: item.cable_id ?? '',
    core_no: item.core_no ?? '',
    notes: item.notes || '',
  }
  showPortModal.value = true
}

async function savePort() {
  portProcessing.value = true
  portErrors.value = {}

  const payload = {
    point_id: portForm.value.point_id !== '' ? Number(portForm.value.point_id) : null,
    port_type: (portForm.value.port_type || 'OLT_PON').toString().toUpperCase(),
    port_label: portForm.value.port_label || '',
    cable_id: portForm.value.cable_id !== '' ? Number(portForm.value.cable_id) : null,
    core_no: portForm.value.core_no !== '' ? Number(portForm.value.core_no) : null,
    notes: portForm.value.notes || null,
  }

  const isEdit = portModalMode.value === 'edit' && portForm.value.id
  const url = isEdit ? `/api/v1/fiber/ports/${portForm.value.id}` : '/api/v1/fiber/ports'
  const method = isEdit ? 'PUT' : 'POST'

  try {
    const res = await requestJson(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      if (res.status === 422 && res.data?.errors) {
        portErrors.value = res.data.errors
        throw new Error(res.data?.message || 'Validasi gagal.')
      }
      throw new Error(res.data?.message || `Gagal menyimpan port (HTTP ${res.status}).`)
    }

    showPortModal.value = false
    await loadAll()
    if (toast) toast.success('Port tersimpan.')
  } catch (e) {
    console.error('Fiber: savePort failed', e)
    if (toast) toast.error(e?.message || 'Gagal menyimpan port.')
  } finally {
    portProcessing.value = false
  }
}

async function deletePortItem(item) {
  if (!canDelete.value) return
  const ok = confirm(`Hapus port "${item?.port_label || ''}"?`)
  if (!ok) return

  try {
    const res = await requestJson(`/api/v1/fiber/ports/${item.id}`, { method: 'DELETE' })
    if (!res.ok) throw new Error(res.data?.message || `Gagal hapus port (HTTP ${res.status}).`)

    if (toast) toast.success('Port dihapus.')
    await loadAll()
  } catch (e) {
    console.error('Fiber: deletePort failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus port.')
  }
}

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ Links (splice/patch/split) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
const showLinkModal = ref(false)
const linkModalMode = ref('create') // create|edit
const linkProcessing = ref(false)
const linkErrors = ref({})
const splitOutputCount = ref(8)

const linkForm = ref({
  id: null,
  point_id: '',
  link_type: 'SPLICE',
  from_cable_id: '',
  from_core_no: '',
  to_cable_id: '',
  to_core_no: '',
  split_group: '',
  outputs: [],
  loss_db: '',
  notes: '',
})

const linkItems = computed(() => {
  const rows = Array.isArray(links.value) ? links.value : []
  const out = []
  const groups = {}

  rows.forEach((ln) => {
    const lt = (ln?.link_type || '').toString().toUpperCase()
    const sg = (ln?.split_group || '').toString()

    if (lt === 'SPLIT' && sg) {
      const key = `${Number(ln.point_id) || 0}|${sg}`
      if (!groups[key]) {
        groups[key] = {
          kind: 'SPLIT_GROUP',
          id: ln.id,
          point_id: ln.point_id,
          link_type: 'SPLIT',
          split_group: sg,
          from_cable_id: ln.from_cable_id,
          from_core_no: ln.from_core_no,
          loss_db: ln.loss_db ?? null,
          notes: ln.notes || '',
          outputs: [],
          link_ids: [],
        }
      }
      groups[key].outputs.push({ to_cable_id: ln.to_cable_id, to_core_no: ln.to_core_no })
      groups[key].link_ids.push(ln.id)
      return
    }

    out.push({ kind: 'LINK', ...(ln || {}) })
  })

  Object.values(groups).forEach((g) => {
    g.outputs = (g.outputs || []).filter((x) => x?.to_cable_id && x?.to_core_no)
    out.push(g)
  })

  out.sort((a, b) => {
    const ap = Number(a?.point_id || 0)
    const bp = Number(b?.point_id || 0)
    if (ap !== bp) return ap - bp
    const at = (a?.link_type || '').toString()
    const bt = (b?.link_type || '').toString()
    return at.localeCompare(bt)
  })

  return out
})

function resetLinkForm() {
  linkForm.value = {
    id: null,
    point_id: '',
    link_type: 'SPLICE',
    from_cable_id: '',
    from_core_no: '',
    to_cable_id: '',
    to_core_no: '',
    split_group: '',
    outputs: [],
    loss_db: '',
    notes: '',
  }
  linkErrors.value = {}
  splitOutputCount.value = 8
}

function ensureSplitOutputs(n) {
  const count = Math.max(1, Math.min(64, Number(n) || 1))
  linkForm.value.outputs = Array.from({ length: count }).map(() => ({ to_cable_id: '', to_core_no: '' }))
}

function addSplitOutputRow() {
  const arr = Array.isArray(linkForm.value.outputs) ? linkForm.value.outputs : []
  arr.push({ to_cable_id: '', to_core_no: '' })
  linkForm.value.outputs = arr
}

function removeSplitOutputRow(idx) {
  const arr = Array.isArray(linkForm.value.outputs) ? linkForm.value.outputs : []
  if (idx < 0 || idx >= arr.length) return
  arr.splice(idx, 1)
  linkForm.value.outputs = arr
}

function openCreateLink() {
  if (!canCreate.value) return
  resetLinkForm()
  if (selectedPointId.value) linkForm.value.point_id = String(selectedPointId.value)
  linkModalMode.value = 'create'
  showLinkModal.value = true
}

function openEditLink(item) {
  if (!canEdit.value) return
  resetLinkForm()
  linkModalMode.value = 'edit'

  if (item?.kind === 'SPLIT_GROUP') {
    linkForm.value = {
      id: item.id,
      point_id: item.point_id ?? '',
      link_type: 'SPLIT',
      from_cable_id: item.from_cable_id ?? '',
      from_core_no: item.from_core_no ?? '',
      to_cable_id: '',
      to_core_no: '',
      split_group: item.split_group || '',
      outputs: (item.outputs || []).map((o) => ({ to_cable_id: o.to_cable_id ?? '', to_core_no: o.to_core_no ?? '' })),
      loss_db: item.loss_db ?? '',
      notes: item.notes || '',
    }
    splitOutputCount.value = (linkForm.value.outputs || []).length || 8
    showLinkModal.value = true
    return
  }

  linkForm.value = {
    id: item.id,
    point_id: item.point_id ?? '',
    link_type: (item.link_type || 'SPLICE').toString().toUpperCase(),
    from_cable_id: item.from_cable_id ?? '',
    from_core_no: item.from_core_no ?? '',
    to_cable_id: item.to_cable_id ?? '',
    to_core_no: item.to_core_no ?? '',
    split_group: item.split_group || '',
    outputs: [],
    loss_db: item.loss_db ?? '',
    notes: item.notes || '',
  }
  showLinkModal.value = true
}

async function saveLink() {
  linkProcessing.value = true
  linkErrors.value = {}

  const linkType = (linkForm.value.link_type || 'SPLICE').toString().toUpperCase()

  const payload = {
    point_id: linkForm.value.point_id !== '' ? Number(linkForm.value.point_id) : null,
    link_type: linkType,
    from_cable_id: linkForm.value.from_cable_id !== '' ? Number(linkForm.value.from_cable_id) : null,
    from_core_no: linkForm.value.from_core_no !== '' ? Number(linkForm.value.from_core_no) : null,
    split_group: linkForm.value.split_group || null,
    loss_db: linkForm.value.loss_db !== '' ? Number(linkForm.value.loss_db) : null,
    notes: linkForm.value.notes || null,
  }

  if (linkType === 'SPLIT') {
    const outs = Array.isArray(linkForm.value.outputs) ? linkForm.value.outputs : []
    payload.outputs = outs
      .map((o) => ({
        to_cable_id: o.to_cable_id !== '' ? Number(o.to_cable_id) : null,
        to_core_no: o.to_core_no !== '' ? Number(o.to_core_no) : null,
      }))
      .filter((o) => o.to_cable_id && o.to_core_no)
  } else {
    payload.to_cable_id = linkForm.value.to_cable_id !== '' ? Number(linkForm.value.to_cable_id) : null
    payload.to_core_no = linkForm.value.to_core_no !== '' ? Number(linkForm.value.to_core_no) : null
  }

  const isEdit = linkModalMode.value === 'edit' && linkForm.value.id
  const url = isEdit ? `/api/v1/fiber/links/${linkForm.value.id}` : '/api/v1/fiber/links'
  const method = isEdit ? 'PUT' : 'POST'

  try {
    const res = await requestJson(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      if (res.status === 422 && res.data?.errors) {
        linkErrors.value = res.data.errors
        throw new Error(res.data?.message || 'Validasi gagal.')
      }
      throw new Error(res.data?.message || `Gagal menyimpan sambungan (HTTP ${res.status}).`)
    }

    showLinkModal.value = false
    await loadAll()
    if (toast) toast.success('Sambungan tersimpan.')
  } catch (e) {
    console.error('Fiber: saveLink failed', e)
    if (toast) toast.error(e?.message || 'Gagal menyimpan sambungan.')
  } finally {
    linkProcessing.value = false
  }
}

async function deleteLinkItem(item) {
  if (!canDelete.value) return
  const title = item?.kind === 'SPLIT_GROUP' ? `splitter "${item?.split_group || ''}"` : 'sambungan ini'
  const ok = confirm(`Hapus ${title}?`)
  if (!ok) return

  try {
    const res = await requestJson(`/api/v1/fiber/links/${item.id}`, { method: 'DELETE' })
    if (!res.ok) throw new Error(res.data?.message || `Gagal hapus sambungan (HTTP ${res.status}).`)

    if (toast) toast.success('Sambungan dihapus.')
    await loadAll()
  } catch (e) {
    console.error('Fiber: deleteLink failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus sambungan.')
  }
}

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ Trace (OLT PON -> ODP OUT) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
const traceStartPortId = ref('')
const traceLoading = ref(false)
const traceError = ref('')
const selectedTraceEndpoint = ref(null)

const oltPonPorts = computed(() => {
  return (ports.value || []).filter((p) => (p?.port_type || '').toString().toUpperCase() === 'OLT_PON')
})

function clearTrace() {
  traceResult.value = null
  traceError.value = ''
  selectedTraceEndpoint.value = null
  traceUsedCableIds.value = []
  renderCablesLayer()
}

async function runTrace() {
  if (!traceStartPortId.value) return
  traceLoading.value = true
  traceError.value = ''
  traceResult.value = null
  selectedTraceEndpoint.value = null
  traceUsedCableIds.value = []

  try {
    const res = await requestJson(`/api/v1/fiber/trace/${Number(traceStartPortId.value)}?stop_at_odp_out=1`)
    if (!res.ok) throw new Error(res.data?.message || `Gagal trace (HTTP ${res.status}).`)

    traceResult.value = res.data?.data || null
    traceUsedCableIds.value = res.data?.data?.used_cable_ids || []

    renderCablesLayer()

    const endpoints = res.data?.data?.endpoints || []
    if (endpoints.length > 0) {
      selectedTraceEndpoint.value = endpoints[0]
    }

    if (toast) toast.success('Trace selesai.')
  } catch (e) {
    console.error('Fiber: runTrace failed', e)
    traceError.value = e?.message || 'Gagal trace.'
    if (toast) toast.error(traceError.value)
  } finally {
    traceLoading.value = false
  }
}

watch([showCables, showPoints, showBreaks], () => {
  renderAllLayers()
})

watch(mapStyle, () => {
  try { localStorage.setItem('fiber_map_style', mapStyle.value) } catch {}
  applyMapStyle()
})

onMounted(() => {
  try {
    const saved = localStorage.getItem('fiber_map_style')
    if (saved && MAP_STYLES[saved]) mapStyle.value = saved
  } catch {}

  const loadLeaflet = () => {
    if (window.L) return Promise.resolve()
    return new Promise((resolve, reject) => {
      const link = document.createElement('link')
      link.rel = 'stylesheet'
      link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'
      document.head.appendChild(link)

      const script = document.createElement('script')
      script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
      script.onload = () => resolve()
      script.onerror = () => reject(new Error('Failed to load Leaflet'))
      document.head.appendChild(script)
    })
  }

  loadLeaflet().then(() => {
    nextTick(() => {
      initMap()
      loadAll()
    })
  }).catch((e) => {
    console.error('Fiber: Leaflet load failed', e)
    errorMessage.value = 'Gagal memuat Leaflet. Cek koneksi internet.'
  })
})

onUnmounted(() => {
  destroyMap()
})
</script>

<template>
  <Head title="Kabel FO" />

  <AdminLayout>
    <div class="space-y-4 animate-fade-in">
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 bg-white dark:bg-dark-800 p-5 rounded-xl shadow-card border border-gray-100 dark:border-dark-700">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Manajemen Kabel FO</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400">Pemetaan jalur kabel, titik sambungan, dan data putus.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <div class="px-3 py-2 rounded-lg bg-gray-50 dark:bg-dark-700 border border-gray-200 dark:border-dark-600 text-xs">
            <span class="font-semibold text-gray-900 dark:text-white">{{ summary.cables }}</span>
            <span class="text-gray-500 dark:text-gray-400 ml-1">kabel</span>
          </div>
          <div class="px-3 py-2 rounded-lg bg-gray-50 dark:bg-dark-700 border border-gray-200 dark:border-dark-600 text-xs">
            <span class="font-semibold text-gray-900 dark:text-white">{{ summary.points }}</span>
            <span class="text-gray-500 dark:text-gray-400 ml-1">titik</span>
          </div>
          <div class="px-3 py-2 rounded-lg bg-red-50/60 dark:bg-red-900/10 border border-red-200/60 dark:border-red-900/30 text-xs">
            <span class="font-semibold text-red-700 dark:text-red-300">{{ summary.breaks_open }}</span>
            <span class="text-red-600/70 dark:text-red-300/70 ml-1">putus aktif</span>
          </div>

          <div class="hidden lg:flex items-center gap-2 ml-2">
            <select v-model="mapStyle" class="input !py-2 !text-xs">
              <option value="voyager">Voyager</option>
              <option value="light">Light</option>
              <option value="satellite">Satelit</option>
            </select>
            <button @click="loadAll" class="btn btn-secondary !py-2 !text-xs" :disabled="loading">Refresh</button>
          </div>
        </div>
      </div>

      <div v-if="props.warning" class="bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-900/30 text-amber-800 dark:text-amber-200 rounded-xl p-4 text-sm">
        {{ props.warning }}
      </div>

      <div v-if="!canView" class="bg-white dark:bg-dark-800 border border-gray-100 dark:border-dark-700 rounded-xl p-6">
        <div class="text-gray-900 dark:text-white font-semibold">Tidak punya akses</div>
        <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          Butuh permission <code class="px-1 py-0.5 bg-gray-100 dark:bg-dark-700 rounded">view fiber</code>.
        </div>
      </div>

      <div v-else class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <!-- Map -->
        <div class="lg:col-span-8 bg-white dark:bg-dark-800 rounded-xl shadow-card border border-gray-100 dark:border-dark-700 overflow-hidden">
          <div class="flex flex-wrap items-center gap-2 p-3 border-b border-gray-100 dark:border-dark-700">
            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
              <input type="checkbox" v-model="showCables" />
              Kabel
            </label>
            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
              <input type="checkbox" v-model="showPoints" />
              Titik
            </label>
            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
              <input type="checkbox" v-model="showBreaks" />
              Putus
            </label>

            <div class="ml-auto flex items-center gap-2 lg:hidden">
              <select v-model="mapStyle" class="input !py-2 !text-xs">
                <option value="voyager">Voyager</option>
                <option value="light">Light</option>
                <option value="satellite">Satelit</option>
              </select>
              <button @click="loadAll" class="btn btn-secondary !py-2 !text-xs" :disabled="loading">Refresh</button>
            </div>
          </div>

          <div class="relative">
            <div ref="mapContainer" class="w-full h-[420px] lg:h-[calc(100vh-13rem)] bg-slate-100 dark:bg-slate-900"></div>

            <div v-if="mapMode" class="absolute bottom-3 left-3 right-3 lg:right-auto lg:max-w-md bg-white/90 dark:bg-dark-900/80 backdrop-blur border border-gray-200 dark:border-white/10 rounded-xl p-3 text-xs text-gray-700 dark:text-gray-200 shadow-lg">
              <div class="font-semibold">
                Mode peta aktif:
                <span class="uppercase">
                  {{ mapMode === 'drawCable' ? 'gambar kabel' : (mapMode === 'pickPoint' ? 'pilih titik' : 'pilih putus') }}
                </span>
              </div>
              <div class="text-gray-600 dark:text-gray-300 mt-1">Klik peta untuk input. Tekan tombol untuk berhenti.</div>
              <div class="mt-2 flex items-center gap-2">
                <button class="btn btn-secondary !py-1 !text-xs" @click="stopMapMode">Selesai</button>
                <button v-if="mapMode === 'drawCable'" class="btn btn-secondary !py-1 !text-xs" @click="undoCablePoint">Undo</button>
                <button v-if="mapMode === 'drawCable'" class="btn btn-secondary !py-1 !text-xs" @click="clearCablePath">Clear</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Panel -->
        <div class="lg:col-span-4 bg-white dark:bg-dark-800 rounded-xl shadow-card border border-gray-100 dark:border-dark-700 overflow-hidden">
          <div class="flex items-center gap-1 border-b border-gray-100 dark:border-dark-700 overflow-x-auto">
            <button
              @click="activeTab='cables'"
              class="px-3 py-3 text-xs font-semibold whitespace-nowrap"
              :class="activeTab==='cables' ? 'text-blue-700 dark:text-blue-300 bg-blue-50/60 dark:bg-blue-900/10' : 'text-gray-600 dark:text-gray-300'"
            >
              Kabel ({{ cables.length }})
            </button>
            <button
              @click="activeTab='points'"
              class="px-3 py-3 text-xs font-semibold whitespace-nowrap"
              :class="activeTab==='points' ? 'text-teal-700 dark:text-teal-300 bg-teal-50/60 dark:bg-teal-900/10' : 'text-gray-600 dark:text-gray-300'"
            >
              Titik ({{ points.length }})
            </button>
            <button
              @click="activeTab='ports'"
              class="px-3 py-3 text-xs font-semibold whitespace-nowrap"
              :class="activeTab==='ports' ? 'text-indigo-700 dark:text-indigo-300 bg-indigo-50/60 dark:bg-indigo-900/10' : 'text-gray-600 dark:text-gray-300'"
            >
              Port ({{ ports.length }})
            </button>
            <button
              @click="activeTab='links'"
              class="px-3 py-3 text-xs font-semibold whitespace-nowrap"
              :class="activeTab==='links' ? 'text-amber-700 dark:text-amber-300 bg-amber-50/60 dark:bg-amber-900/10' : 'text-gray-600 dark:text-gray-300'"
            >
              Sambungan ({{ links.length }})
            </button>
            <button
              @click="activeTab='trace'"
              class="px-3 py-3 text-xs font-semibold whitespace-nowrap"
              :class="activeTab==='trace' ? 'text-slate-700 dark:text-slate-200 bg-slate-50/60 dark:bg-slate-700/30' : 'text-gray-600 dark:text-gray-300'"
            >
              Trace
            </button>
            <button
              @click="activeTab='breaks'"
              class="px-3 py-3 text-xs font-semibold whitespace-nowrap"
              :class="activeTab==='breaks' ? 'text-red-700 dark:text-red-300 bg-red-50/60 dark:bg-red-900/10' : 'text-gray-600 dark:text-gray-300'"
            >
              Putus ({{ breaks.length }})
            </button>
          </div>

          <div class="p-4">
            <div v-if="loading" class="text-sm text-gray-500 dark:text-gray-400">Memuat...</div>
            <div v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-300">{{ errorMessage }}</div>

            <!-- Cables tab -->
            <div v-else-if="activeTab==='cables'" class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Daftar Kabel</div>
                <button v-if="canCreate" class="btn btn-primary !py-2 !text-xs" @click="openCreateCable">Tambah</button>
              </div>

              <div v-if="cables.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Belum ada data kabel.</div>
              <div v-else class="space-y-2 max-h-[420px] lg:max-h-[calc(100vh-18rem)] overflow-auto pr-1">
                <div
                  v-for="c in cables"
                  :key="c.id"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-blue-200 dark:hover:border-blue-900/40 cursor-pointer"
                  :class="Number(selectedCableId) === Number(c.id) ? 'bg-blue-50/60 dark:bg-blue-900/10' : 'bg-white dark:bg-dark-800'"
                  @click="selectedCableId=c.id; selectedPointId=null; selectedBreakId=null; renderCablesLayer()"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ c.name }}</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <span v-if="c.cable_type">{{ c.cable_type }}</span>
                        <span v-if="c.core_count" class="ml-2">• {{ c.core_count }} core</span>
                      </div>
                      <div v-if="c.from_point_id || c.to_point_id" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        <div>Dari: <span class="font-medium text-gray-700 dark:text-gray-200">{{ pointNameById(c.from_point_id) || '-' }}</span></div>
                        <div>Ke: <span class="font-medium text-gray-700 dark:text-gray-200">{{ pointNameById(c.to_point_id) || '-' }}</span></div>
                      </div>
                    </div>

                    <div class="flex items-center gap-1 shrink-0">
                      <button v-if="canEdit" class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openEditCable(c)">Edit</button>
                      <button v-if="canDelete" class="btn btn-danger !py-1 !px-2 !text-xs" @click.stop="deleteCable(c)">Hapus</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Points tab -->
            <div v-else-if="activeTab==='points'" class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Titik Sambungan</div>
                <button v-if="canCreate" class="btn btn-primary !py-2 !text-xs" @click="openCreatePoint">Tambah</button>
              </div>

              <div v-if="points.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Belum ada data titik.</div>
              <div v-else class="space-y-2 max-h-[420px] lg:max-h-[calc(100vh-18rem)] overflow-auto pr-1">
                <div
                  v-for="p in points"
                  :key="p.id"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-teal-200 dark:hover:border-teal-900/40 cursor-pointer"
                  :class="Number(selectedPointId) === Number(p.id) ? 'bg-teal-50/60 dark:bg-teal-900/10' : 'bg-white dark:bg-dark-800'"
                  @click="selectedPointId=p.id; selectedCableId=null; selectedBreakId=null; renderPointsLayer(); focusOnLatLng(Number(p.latitude), Number(p.longitude), 17)"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ p.name }}</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <span v-if="p.point_type">{{ p.point_type }}</span>
                        <span v-if="p.latitude && p.longitude" class="ml-2">• {{ p.latitude }}, {{ p.longitude }}</span>
                      </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <button v-if="canEdit" class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openEditPoint(p)">Edit</button>
                      <button v-if="canDelete" class="btn btn-danger !py-1 !px-2 !text-xs" @click.stop="deletePoint(p)">Hapus</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Ports tab -->
            <div v-else-if="activeTab==='ports'" class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Port (OLT/ODP)</div>
                <button v-if="canCreate" class="btn btn-primary !py-2 !text-xs" @click="openCreatePort">Tambah</button>
              </div>

              <div v-if="ports.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Belum ada data port.</div>
              <div v-else class="space-y-2 max-h-[420px] lg:max-h-[calc(100vh-18rem)] overflow-auto pr-1">
                <div
                  v-for="p in ports"
                  :key="p.id"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-indigo-200 dark:hover:border-indigo-900/40 bg-white dark:bg-dark-800"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div>
                      <div class="text-xs font-bold text-indigo-700 dark:text-indigo-300">{{ String(p.port_type || '').toUpperCase() }}</div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white mt-1">{{ p.port_label || ('#' + p.id) }}</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 space-y-0.5">
                        <div>
                          Titik:
                          <span class="font-medium text-gray-700 dark:text-gray-200">{{ pointNameById(p.point_id) || ('#' + p.point_id) }}</span>
                        </div>
                        <div v-if="p.cable_id && p.core_no">
                          Kabel:
                          <span class="font-medium text-gray-700 dark:text-gray-200">{{ cableNameById(p.cable_id) || ('Kabel #' + p.cable_id) }}</span>
                          <span class="ml-1">â€¢ core {{ p.core_no }}</span>
                        </div>
                      </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <button v-if="canEdit" class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openEditPort(p)">Edit</button>
                      <button v-if="canDelete" class="btn btn-danger !py-1 !px-2 !text-xs" @click.stop="deletePortItem(p)">Hapus</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Links tab -->
            <div v-else-if="activeTab==='links'" class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Sambungan (Splice/Split)</div>
                <button v-if="canCreate" class="btn btn-primary !py-2 !text-xs" @click="openCreateLink">Tambah</button>
              </div>

              <div v-if="linkItems.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Belum ada data sambungan.</div>
              <div v-else class="space-y-2 max-h-[420px] lg:max-h-[calc(100vh-18rem)] overflow-auto pr-1">
                <div
                  v-for="ln in linkItems"
                  :key="(ln.kind === 'SPLIT_GROUP' ? ('g:' + ln.split_group + ':' + ln.point_id) : ('l:' + ln.id))"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-amber-200 dark:hover:border-amber-900/40 bg-white dark:bg-dark-800"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <div class="text-xs font-bold text-amber-700 dark:text-amber-300">
                        {{ ln.kind === 'SPLIT_GROUP' ? 'SPLIT' : String(ln.link_type || '').toUpperCase() }}
                        <span v-if="ln.kind === 'SPLIT_GROUP'" class="ml-1">â€¢ {{ (ln.outputs || []).length }} output</span>
                      </div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Titik:
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ pointNameById(ln.point_id) || ('#' + ln.point_id) }}</span>
                      </div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white mt-1 truncate">
                        {{ cableNameById(ln.from_cable_id) || ('Kabel #' + ln.from_cable_id) }} â€¢ core {{ ln.from_core_no }}
                        <span class="mx-1 text-gray-400">â†’</span>
                        <template v-if="ln.kind === 'SPLIT_GROUP'">
                          <span class="text-gray-700 dark:text-gray-200">splitter</span>
                        </template>
                        <template v-else>
                          {{ cableNameById(ln.to_cable_id) || ('Kabel #' + ln.to_cable_id) }} â€¢ core {{ ln.to_core_no }}
                        </template>
                      </div>
                      <div v-if="ln.kind === 'SPLIT_GROUP' && (ln.outputs || []).length" class="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">
                        Output: {{
                          (ln.outputs || [])
                            .slice(0, 4)
                            .map((o) => `${cableNameById(o.to_cable_id) || ('#' + o.to_cable_id)}:${o.to_core_no}`)
                            .join(', ')
                        }}<span v-if="(ln.outputs || []).length > 4">, ...</span>
                      </div>
                      <div v-if="ln.loss_db !== null && ln.loss_db !== undefined && ln.loss_db !== ''" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Loss: <span class="font-medium text-gray-700 dark:text-gray-200">{{ ln.loss_db }} dB</span>
                      </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <button v-if="canEdit" class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openEditLink(ln)">Edit</button>
                      <button v-if="canDelete" class="btn btn-danger !py-1 !px-2 !text-xs" @click.stop="deleteLinkItem(ln)">Hapus</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Trace tab -->
            <div v-else-if="activeTab==='trace'" class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Tracing End-to-End</div>
                <button class="btn btn-secondary !py-2 !text-xs" @click="clearTrace" :disabled="traceLoading">Clear</button>
              </div>

              <div class="space-y-2">
                <div>
                  <label class="text-xs text-gray-600 dark:text-gray-300">Start Port (OLT_PON)</label>
                  <select v-model="traceStartPortId" class="input w-full">
                    <option value="">(pilih port)</option>
                    <option v-for="p in oltPonPorts" :key="p.id" :value="p.id">
                      {{ pointNameById(p.point_id) || ('#' + p.point_id) }} â€¢ {{ p.port_label }} â€¢ {{ cableNameById(p.cable_id) || ('Kabel #' + p.cable_id) }}:{{ p.core_no }}
                    </option>
                  </select>
                </div>

                <div class="flex items-center gap-2">
                  <button class="btn btn-primary !py-2 !text-xs" @click="runTrace" :disabled="traceLoading || !traceStartPortId">
                    {{ traceLoading ? 'Tracing...' : 'Trace' }}
                  </button>
                  <button class="btn btn-secondary !py-2 !text-xs" @click="loadAll" :disabled="loading || traceLoading">Refresh Data</button>
                </div>

                <div v-if="traceError" class="text-xs text-red-600 dark:text-red-300">{{ traceError }}</div>
              </div>

              <div v-if="traceResult" class="space-y-3">
                <div class="text-xs text-gray-500 dark:text-gray-400">
                  Endpoint: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ (traceResult.endpoints || []).length }}</span>
                  <span class="mx-2">â€¢</span>
                  Visited: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ traceResult.visited_nodes || 0 }}</span>
                  <span v-if="traceResult.truncated" class="ml-2 text-amber-700 dark:text-amber-300">(truncated)</span>
                </div>

                <div v-if="(traceResult.endpoints || []).length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                  Tidak ada endpoint <span class="font-mono">ODP_OUT</span> terjangkau dari port ini.
                </div>

                <div v-else class="space-y-2 max-h-[260px] overflow-auto pr-1">
                  <div
                    v-for="(e, idx) in (traceResult.endpoints || [])"
                    :key="e.port?.id || idx"
                    class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-slate-200 dark:hover:border-slate-700 cursor-pointer"
                    :class="selectedTraceEndpoint === e ? 'bg-slate-50/60 dark:bg-slate-700/30' : 'bg-white dark:bg-dark-800'"
                    @click="selectedTraceEndpoint = e"
                  >
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                      {{ e.point?.name || pointNameById(e.port?.point_id) || '-' }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                      Port: <span class="font-medium text-gray-700 dark:text-gray-200">{{ e.port?.port_label || ('#' + e.port?.id) }}</span>
                      <span class="mx-2">â€¢</span>
                      {{ cableNameById(e.port?.cable_id) || ('Kabel #' + e.port?.cable_id) }}:{{ e.port?.core_no }}
                    </div>
                  </div>
                </div>

                <div v-if="selectedTraceEndpoint?.path" class="rounded-xl border border-gray-100 dark:border-dark-700 p-3 bg-gray-50/40 dark:bg-dark-900/30">
                  <div class="text-xs font-semibold text-gray-900 dark:text-white">Path (ringkas)</div>
                  <div class="mt-2 space-y-1 max-h-[220px] overflow-auto pr-1">
                    <div v-for="(s, i) in selectedTraceEndpoint.path" :key="i" class="text-xs">
                      <div class="font-medium text-gray-900 dark:text-white">
                        {{ i === 0 ? 'START' : (s.edge?.type === 'CABLE' ? 'KABEL' : ('LINK ' + (s.edge?.link_type || ''))) }}
                        <span class="mx-1 text-gray-400">â€¢</span>
                        {{ s.point?.name || ('Titik #' + s.node?.point_id) }}
                      </div>
                      <div class="text-gray-600 dark:text-gray-300">
                        {{ s.cable?.name || ('Kabel #' + s.node?.cable_id) }} â€¢ core {{ s.node?.core_no }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Breaks tab -->
            <div v-else class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Data Putus</div>
                <button v-if="canCreate" class="btn btn-primary !py-2 !text-xs" @click="openCreateBreak">Tambah</button>
              </div>

              <div v-if="breaks.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Belum ada data putus.</div>
              <div v-else class="space-y-2 max-h-[420px] lg:max-h-[calc(100vh-18rem)] overflow-auto pr-1">
                <div
                  v-for="b in breaks"
                  :key="b.id"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-red-200 dark:hover:border-red-900/40 cursor-pointer"
                  :class="Number(selectedBreakId) === Number(b.id) ? 'bg-red-50/60 dark:bg-red-900/10' : 'bg-white dark:bg-dark-800'"
                  @click="selectedBreakId=b.id; selectedCableId=null; selectedPointId=null; renderBreaksLayer(); focusOnLatLng(Number(b.latitude), Number(b.longitude), 17)"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div>
                      <div class="text-xs font-bold">{{ formatStatus(b.status) }}</div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white mt-1">
                        {{ cableNameById(b.cable_id) || (b.cable_id ? ('Kabel #' + b.cable_id) : 'Tanpa kabel') }}
                      </div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <span>Titik: {{ pointNameById(b.point_id) || '-' }}</span>
                        <span v-if="b.severity" class="ml-2">• {{ b.severity }}</span>
                      </div>
                      <div v-if="b.description" class="text-xs text-gray-600 dark:text-gray-300 mt-1 line-clamp-2">{{ b.description }}</div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <button v-if="canEdit" class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openEditBreak(b)">Edit</button>
                      <button v-if="canDelete" class="btn btn-danger !py-1 !px-2 !text-xs" @click.stop="deleteBreak(b)">Hapus</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- Cable Modal -->
    <div v-if="showCableModal" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="showCableModal=false; stopMapMode()"></div>
      <div class="relative w-full max-w-2xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ cableModalMode === 'edit' ? 'Edit Kabel' : 'Tambah Kabel' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showCableModal=false; stopMapMode()">Tutup</button>
        </div>

        <div class="p-4 space-y-3">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Nama</label>
              <input v-model="cableForm.name" class="input w-full" placeholder="Feeder ODC-POP A" />
              <div v-if="cableErrors.name" class="text-xs text-red-600 mt-1">{{ cableErrors.name?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Kode (opsional)</label>
              <input v-model="cableForm.code" class="input w-full" placeholder="FO-001" />
              <div v-if="cableErrors.code" class="text-xs text-red-600 mt-1">{{ cableErrors.code?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Tipe</label>
              <input v-model="cableForm.cable_type" class="input w-full" placeholder="FEEDER / DISTRIBUTION / DROP" />
              <div v-if="cableErrors.cable_type" class="text-xs text-red-600 mt-1">{{ cableErrors.cable_type?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Core</label>
              <input v-model="cableForm.core_count" type="number" min="1" class="input w-full" placeholder="12" />
              <div v-if="cableErrors.core_count" class="text-xs text-red-600 mt-1">{{ cableErrors.core_count?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Dari Titik</label>
              <select v-model="cableForm.from_point_id" class="input w-full">
                <option value="">(kosong)</option>
                <option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Ke Titik</label>
              <select v-model="cableForm.to_point_id" class="input w-full">
                <option value="">(kosong)</option>
                <option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="text-xs text-gray-600 dark:text-gray-300">Warna Jalur</label>
              <div class="flex items-center gap-2">
                <input v-model="cableForm.map_color" type="color" class="h-10 w-14 rounded border border-gray-200 dark:border-dark-700 bg-transparent" />
                <input v-model="cableForm.map_color" class="input flex-1" placeholder="#2563eb" />
              </div>
            </div>
            <div class="md:col-span-2">
              <label class="text-xs text-gray-600 dark:text-gray-300">Catatan</label>
              <textarea v-model="cableForm.notes" class="input w-full min-h-[80px]" placeholder="Keterangan kabel..."></textarea>
            </div>
          </div>

          <div class="rounded-xl border border-gray-100 dark:border-dark-700 p-3">
            <div class="flex items-center justify-between gap-2">
              <div>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Jalur di Peta</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Titik: {{ Array.isArray(cableForm.path) ? cableForm.path.length : 0 }}</div>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <button class="btn btn-secondary !py-2 !text-xs" @click="startDrawCable">Gambar</button>
                <button class="btn btn-secondary !py-2 !text-xs" @click="undoCablePoint">Undo</button>
                <button class="btn btn-secondary !py-2 !text-xs" @click="clearCablePath">Clear</button>
              </div>
            </div>
          </div>
        </div>

        <div class="p-4 border-t border-gray-100 dark:border-dark-700 flex items-center justify-end gap-2">
          <button class="btn btn-secondary" @click="showCableModal=false; stopMapMode()">Batal</button>
          <button class="btn btn-primary" :disabled="cableProcessing" @click="saveCable">{{ cableProcessing ? 'Menyimpan...' : 'Simpan' }}</button>
        </div>
      </div>
    </div>

    <!-- Point Modal -->
    <div v-if="showPointModal" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="showPointModal=false; stopMapMode()"></div>
      <div class="relative w-full max-w-xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ pointModalMode === 'edit' ? 'Edit Titik' : 'Tambah Titik' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showPointModal=false; stopMapMode()">Tutup</button>
        </div>

        <div class="p-4 space-y-3">
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Nama</label>
            <input v-model="pointForm.name" class="input w-full" placeholder="ODP-01 / Joint A" />
            <div v-if="pointErrors.name" class="text-xs text-red-600 mt-1">{{ pointErrors.name?.[0] }}</div>
          </div>
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Tipe</label>
            <input v-model="pointForm.point_type" class="input w-full" placeholder="ODP / ODC / JOINT / HANDHOLE" />
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Latitude</label>
              <input v-model="pointForm.latitude" class="input w-full" placeholder="-6.2" />
              <div v-if="pointErrors.latitude" class="text-xs text-red-600 mt-1">{{ pointErrors.latitude?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Longitude</label>
              <input v-model="pointForm.longitude" class="input w-full" placeholder="106.8" />
              <div v-if="pointErrors.longitude" class="text-xs text-red-600 mt-1">{{ pointErrors.longitude?.[0] }}</div>
            </div>
          </div>

          <div class="flex items-center gap-2">
            <button class="btn btn-secondary !py-2 !text-xs" @click="pickPointOnMap">Pilih dari peta</button>
            <button v-if="pointForm.latitude && pointForm.longitude" class="btn btn-secondary !py-2 !text-xs" @click="focusOnLatLng(Number(pointForm.latitude), Number(pointForm.longitude), 18)">Zoom</button>
          </div>

          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Alamat</label>
            <input v-model="pointForm.address" class="input w-full" placeholder="(opsional)" />
          </div>
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Catatan</label>
            <textarea v-model="pointForm.notes" class="input w-full min-h-[80px]" placeholder="(opsional)"></textarea>
          </div>
        </div>

        <div class="p-4 border-t border-gray-100 dark:border-dark-700 flex items-center justify-end gap-2">
          <button class="btn btn-secondary" @click="showPointModal=false; stopMapMode()">Batal</button>
          <button class="btn btn-primary" :disabled="pointProcessing" @click="savePoint">{{ pointProcessing ? 'Menyimpan...' : 'Simpan' }}</button>
        </div>
      </div>
    </div>

    <!-- Break Modal -->
    <div v-if="showBreakModal" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="showBreakModal=false; stopMapMode()"></div>
      <div class="relative w-full max-w-xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ breakModalMode === 'edit' ? 'Edit Data Putus' : 'Tambah Data Putus' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showBreakModal=false; stopMapMode()">Tutup</button>
        </div>

        <div class="p-4 space-y-3">
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Kabel</label>
            <select v-model="breakForm.cable_id" class="input w-full">
              <option value="">(kosong)</option>
              <option v-for="c in cables" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Titik (opsional)</label>
            <select v-model="breakForm.point_id" class="input w-full">
              <option value="">(kosong)</option>
              <option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Status</label>
              <select v-model="breakForm.status" class="input w-full">
                <option value="OPEN">OPEN</option>
                <option value="IN_PROGRESS">IN_PROGRESS</option>
                <option value="FIXED">FIXED</option>
                <option value="CANCELLED">CANCELLED</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Severity</label>
              <select v-model="breakForm.severity" class="input w-full">
                <option value="">(kosong)</option>
                <option value="MINOR">MINOR</option>
                <option value="MAJOR">MAJOR</option>
                <option value="CRITICAL">CRITICAL</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Dilaporkan</label>
              <input v-model="breakForm.reported_at" type="datetime-local" class="input w-full" />
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Fixed At</label>
              <input v-model="breakForm.fixed_at" type="datetime-local" class="input w-full" :disabled="String(breakForm.status).toUpperCase() !== 'FIXED'" />
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Latitude</label>
              <input v-model="breakForm.latitude" class="input w-full" placeholder="-6.2" />
              <div v-if="breakErrors.latitude" class="text-xs text-red-600 mt-1">{{ breakErrors.latitude?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Longitude</label>
              <input v-model="breakForm.longitude" class="input w-full" placeholder="106.8" />
              <div v-if="breakErrors.longitude" class="text-xs text-red-600 mt-1">{{ breakErrors.longitude?.[0] }}</div>
            </div>
          </div>

          <div class="flex items-center gap-2">
            <button class="btn btn-secondary !py-2 !text-xs" @click="pickBreakOnMap">Pilih dari peta</button>
            <button v-if="breakForm.latitude && breakForm.longitude" class="btn btn-secondary !py-2 !text-xs" @click="focusOnLatLng(Number(breakForm.latitude), Number(breakForm.longitude), 18)">Zoom</button>
          </div>

          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Catatan</label>
            <textarea v-model="breakForm.description" class="input w-full min-h-[90px]" placeholder="Keterangan kejadian putus..."></textarea>
          </div>
        </div>

        <div class="p-4 border-t border-gray-100 dark:border-dark-700 flex items-center justify-end gap-2">
          <button class="btn btn-secondary" @click="showBreakModal=false; stopMapMode()">Batal</button>
          <button class="btn btn-primary" :disabled="breakProcessing" @click="saveBreak">{{ breakProcessing ? 'Menyimpan...' : 'Simpan' }}</button>
        </div>
      </div>
    </div>

    <!-- Port Modal -->
    <div v-if="showPortModal" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="showPortModal=false"></div>
      <div class="relative w-full max-w-xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ portModalMode === 'edit' ? 'Edit Port' : 'Tambah Port' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showPortModal=false">Tutup</button>
        </div>

        <div class="p-4 space-y-3">
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Titik</label>
            <select v-model="portForm.point_id" class="input w-full">
              <option value="">(pilih titik)</option>
              <option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <div v-if="portErrors.point_id" class="text-xs text-red-600 mt-1">{{ portErrors.point_id?.[0] }}</div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Tipe</label>
              <select v-model="portForm.port_type" class="input w-full">
                <option value="OLT_PON">OLT_PON</option>
                <option value="ODP_OUT">ODP_OUT</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Label</label>
              <input v-model="portForm.port_label" class="input w-full" placeholder="PON 1/1/1 / OUT 1" />
              <div v-if="portErrors.port_label" class="text-xs text-red-600 mt-1">{{ portErrors.port_label?.[0] }}</div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Kabel</label>
              <select v-model="portForm.cable_id" class="input w-full">
                <option value="">(pilih kabel)</option>
                <option v-for="c in cables" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Core</label>
              <input v-model="portForm.core_no" type="number" min="1" class="input w-full" placeholder="1" />
            </div>
          </div>

          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Catatan</label>
            <textarea v-model="portForm.notes" class="input w-full min-h-[90px]" placeholder="(opsional)"></textarea>
          </div>
        </div>

        <div class="p-4 border-t border-gray-100 dark:border-dark-700 flex items-center justify-end gap-2">
          <button class="btn btn-secondary" @click="showPortModal=false">Batal</button>
          <button class="btn btn-primary" :disabled="portProcessing" @click="savePort">{{ portProcessing ? 'Menyimpan...' : 'Simpan' }}</button>
        </div>
      </div>
    </div>

    <!-- Link Modal -->
    <div v-if="showLinkModal" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="showLinkModal=false"></div>
      <div class="relative w-full max-w-2xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ linkModalMode === 'edit' ? 'Edit Sambungan' : 'Tambah Sambungan' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showLinkModal=false">Tutup</button>
        </div>

        <div class="p-4 space-y-3">
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Titik</label>
            <select v-model="linkForm.point_id" class="input w-full">
              <option value="">(pilih titik)</option>
              <option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <div v-if="linkErrors.point_id" class="text-xs text-red-600 mt-1">{{ linkErrors.point_id?.[0] }}</div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Tipe</label>
              <select v-model="linkForm.link_type" class="input w-full">
                <option value="SPLICE">SPLICE</option>
                <option value="PATCH">PATCH</option>
                <option value="SPLIT">SPLIT</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Loss (dB)</label>
              <input v-model="linkForm.loss_db" class="input w-full" placeholder="0.20" />
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">From Cable</label>
              <select v-model="linkForm.from_cable_id" class="input w-full">
                <option value="">(pilih kabel)</option>
                <option v-for="c in cables" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <div v-if="linkErrors.from_cable_id" class="text-xs text-red-600 mt-1">{{ linkErrors.from_cable_id?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">From Core</label>
              <input v-model="linkForm.from_core_no" type="number" min="1" class="input w-full" placeholder="1" />
              <div v-if="linkErrors.from_core_no" class="text-xs text-red-600 mt-1">{{ linkErrors.from_core_no?.[0] }}</div>
            </div>
          </div>

          <div v-if="String(linkForm.link_type).toUpperCase() !== 'SPLIT'" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">To Cable</label>
              <select v-model="linkForm.to_cable_id" class="input w-full">
                <option value="">(pilih kabel)</option>
                <option v-for="c in cables" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">To Core</label>
              <input v-model="linkForm.to_core_no" type="number" min="1" class="input w-full" placeholder="1" />
            </div>
          </div>

          <div v-else class="space-y-3 rounded-xl border border-gray-100 dark:border-dark-700 p-3">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="text-xs text-gray-600 dark:text-gray-300">Splitter Group (opsional)</label>
                <input v-model="linkForm.split_group" class="input w-full" placeholder="ODP-01 1:8" />
              </div>
              <div>
                <label class="text-xs text-gray-600 dark:text-gray-300">Jumlah Output</label>
                <div class="flex items-center gap-2">
                  <input v-model="splitOutputCount" type="number" min="1" max="64" class="input w-full" />
                  <button class="btn btn-secondary !py-2 !text-xs" @click="ensureSplitOutputs(splitOutputCount)">Buat</button>
                  <button class="btn btn-secondary !py-2 !text-xs" @click="addSplitOutputRow">Tambah</button>
                </div>
              </div>
            </div>

            <div v-if="(linkForm.outputs || []).length === 0" class="text-xs text-gray-500 dark:text-gray-400">
              Output masih kosong. Klik <span class="font-semibold">Buat</span> untuk generate baris output.
            </div>

            <div v-else class="space-y-2 max-h-[260px] overflow-auto pr-1">
              <div v-for="(o, idx) in linkForm.outputs" :key="idx" class="grid grid-cols-12 gap-2 items-center">
                <div class="col-span-7">
                  <select v-model="o.to_cable_id" class="input w-full">
                    <option value="">(kabel output)</option>
                    <option v-for="c in cables" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                </div>
                <div class="col-span-3">
                  <input v-model="o.to_core_no" type="number" min="1" class="input w-full" placeholder="core" />
                </div>
                <div class="col-span-2 flex items-center justify-end">
                  <button class="btn btn-danger !py-1 !px-2 !text-xs" @click="removeSplitOutputRow(idx)">Hapus</button>
                </div>
              </div>
            </div>
          </div>

          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Catatan</label>
            <textarea v-model="linkForm.notes" class="input w-full min-h-[90px]" placeholder="(opsional)"></textarea>
          </div>
        </div>

        <div class="p-4 border-t border-gray-100 dark:border-dark-700 flex items-center justify-end gap-2">
          <button class="btn btn-secondary" @click="showLinkModal=false">Batal</button>
          <button class="btn btn-primary" :disabled="linkProcessing" @click="saveLink">{{ linkProcessing ? 'Menyimpan...' : 'Simpan' }}</button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

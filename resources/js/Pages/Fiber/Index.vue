<script setup>
import { ref, computed, onMounted, onUnmounted, watch, inject, nextTick } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const toast = inject('toast')

const props = defineProps({
  tenantId: { type: Number, default: 0 },
  warning: { type: String, default: null },
  googleMapsApiKey: { type: String, default: '' },
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

const activeTab = ref('cables') // cables|points|ports|links|cores|trace|breaks

const showCables = ref(true)
const showPoints = ref(true)
const showBreaks = ref(true)

const selectedCableId = ref(null)
const selectedPointId = ref(null)
const selectedBreakId = ref(null)

// UI layout controls:
// - Desktop: allow collapsing the right panel for a full-width map.
// - Mobile: show the panel as a bottom sheet overlay (map-first workflow).
const panelCollapsed = ref(false)
const panelOpen = ref(false)

// Panel search/filter (client-side).
const panelSearch = ref('')
const breakStatusFilter = ref('ALL') // ALL|OPEN|IN_PROGRESS|FIXED|VERIFIED|CANCELLED

const BULK_DELETE_TABS = ['cables', 'points', 'ports', 'links', 'breaks']
const bulkSelected = ref({
  cables: [],
  points: [],
  ports: [],
  links: [],
  breaks: [],
})
const bulkDeleting = ref(false)

// Mobile panel snap: ringkas/sedang/penuh.
const panelSnap = ref('half') // peek|half|full

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

/* ───────────── Map setup (Google Maps) ───────────── */
const mapContainer = ref(null)
let map = null
let infoWindow = null

let layerCables = new Map() // cableId -> google.maps.Polyline
let layerPoints = new Map() // pointId -> google.maps.Marker
let layerBreaks = new Map() // breakId -> google.maps.Marker
let layerDraft = { markers: [], polyline: null } // Draft overlay objects

let fittedOnce = false

const mapLoadError = ref('')

const mapStyle = ref('roadmap')
const MAP_STYLES = {
  roadmap: { label: 'Roadmap', mapTypeId: 'roadmap' },
  satellite: { label: 'Satelit', mapTypeId: 'satellite' },
  hybrid: { label: 'Hybrid', mapTypeId: 'hybrid' },
}
const legendCollapsed = ref(false)
const mapCleanMode = ref(true)

function loadGoogleMaps(apiKey) {
  const key = (apiKey || '').toString().trim()
  if (!key) return Promise.reject(new Error('Google Maps API key belum di-set.'))
  if (window.google?.maps) return Promise.resolve()

  if (window.__fiberGoogleMapsPromise) return window.__fiberGoogleMapsPromise

  window.__fiberGoogleMapsPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    const params = new URLSearchParams({
      key,
      v: 'weekly',
    })
    script.src = `https://maps.googleapis.com/maps/api/js?${params.toString()}`
    script.async = true
    script.defer = true
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('Gagal memuat Google Maps. Cek API key/koneksi.'))
    document.head.appendChild(script)
  })

  return window.__fiberGoogleMapsPromise
}

function applyMapStyle() {
  if (!map || !window.google?.maps) return
  const cfg = MAP_STYLES[mapStyle.value] || MAP_STYLES.roadmap
  try { map.setMapTypeId(cfg.mapTypeId) } catch {}
}

function toggleMapCleanMode() {
  mapCleanMode.value = !mapCleanMode.value
  if (mapCleanMode.value) legendCollapsed.value = true
}

function requestMapResize() {
  if (!map || !window.google?.maps) return
  try {
    window.google.maps.event.trigger(map, 'resize')
  } catch {}
}

function clearDraftLayer() {
  cleanupDraftPathListeners()

  try {
    ;(layerDraft.markers || []).forEach((m) => {
      try { m.setMap(null) } catch {}
    })
  } catch {}
  layerDraft.markers = []

  try { layerDraft.polyline?.setMap(null) } catch {}
  layerDraft.polyline = null
}

function clearLayer(layerMap) {
  try {
    Array.from(layerMap.values()).forEach((obj) => {
      try { obj.setMap(null) } catch {}
    })
  } catch {}
  try { layerMap.clear() } catch {}
}

function initMap() {
  if (!window.google?.maps || !mapContainer.value) return

  const cfg = MAP_STYLES[mapStyle.value] || MAP_STYLES.roadmap
  map = new window.google.maps.Map(mapContainer.value, {
    center: { lat: -2.5489, lng: 118.0149 },
    zoom: 5,
    mapTypeId: cfg.mapTypeId,
    clickableIcons: false,
    fullscreenControl: false,
    streetViewControl: false,
    mapTypeControl: false,
  })

  infoWindow = new window.google.maps.InfoWindow()
  map.addListener('click', onMapClick)
}

function destroyMap() {
  if (!window.google?.maps) {
    map = null
    infoWindow = null
    return
  }

  try { window.google.maps.event.clearInstanceListeners(map) } catch {}
  try { infoWindow?.close() } catch {}

  clearDraftLayer()
  clearLayer(layerCables)
  clearLayer(layerPoints)
  clearLayer(layerBreaks)

  map = null
  infoWindow = null
}

/* ───────────── Map interaction modes ───────────── */
const mapMode = ref(null) // null | pickPoint | pickBreak | drawCable | editCable
const resumeModal = ref(null) // null | 'cable' | 'point' | 'break'

const drawFollowRoad = ref(true) // Use Google Directions to follow road while drawing
const drawBusy = ref(false)
let drawUndoStack = []
let directionsService = null
let draftPathListeners = []

function removeMapsListener(l) {
  if (!l) return
  try {
    if (typeof l.remove === 'function') {
      l.remove()
      return
    }
  } catch {}
  try { window.google?.maps?.event?.removeListener?.(l) } catch {}
}

function cleanupDraftPathListeners() {
  try { (draftPathListeners || []).forEach((l) => removeMapsListener(l)) } catch {}
  draftPathListeners = []
}

function roundCoord(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return null
  return Math.round(v * 1e7) / 1e7
}

function getDirectionsService() {
  if (!window.google?.maps) return null
  if (!directionsService) directionsService = new window.google.maps.DirectionsService()
  return directionsService
}

async function getRoadPath(from, to) {
  const svc = getDirectionsService()
  if (!svc) throw new Error('Google Maps Directions belum siap.')

  const request = {
    origin: from,
    destination: to,
    travelMode: window.google.maps.TravelMode.DRIVING,
  }

  return new Promise((resolve, reject) => {
    try {
      svc.route(request, (result, status) => {
        if (status !== 'OK' || !result?.routes?.[0]) {
          reject(new Error(`Gagal ambil rute jalan (${status || 'UNKNOWN'}).`))
          return
        }

        const overview = result.routes[0].overview_path || []
        const pts = overview
          .map((ll) => ({ lat: roundCoord(ll.lat()), lng: roundCoord(ll.lng()) }))
          .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng))

        resolve(pts)
      })
    } catch (e) {
      reject(e)
    }
  })
}

const SNAP_RADIUS_M = 25
const MID_NODE_MIN_TURN_DEG = 18

function nearestPointForLatLng(lat, lng, maxMeters = SNAP_RADIUS_M) {
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null
  let best = null
  let bestDist = Number.POSITIVE_INFINITY
  ;(points.value || []).forEach((p) => {
    const pLat = Number(p?.latitude)
    const pLng = Number(p?.longitude)
    if (!Number.isFinite(pLat) || !Number.isFinite(pLng)) return
    const d = haversineMeters({ lat, lng }, { lat: pLat, lng: pLng })
    if (d < bestDist) {
      bestDist = d
      best = p
    }
  })

  if (!best || !Number.isFinite(bestDist) || bestDist > Number(maxMeters || SNAP_RADIUS_M)) return null
  return {
    point: best,
    distance_m: Math.round(bestDist),
    lat: Number(best?.latitude),
    lng: Number(best?.longitude),
  }
}

function snapLatLngToKnownPoint(lat, lng, maxMeters = SNAP_RADIUS_M) {
  const near = nearestPointForLatLng(lat, lng, maxMeters)
  if (!near) return { lat, lng, snapped: false, point: null, distance_m: null }
  return {
    lat: roundCoord(near.lat),
    lng: roundCoord(near.lng),
    snapped: true,
    point: near.point,
    distance_m: near.distance_m,
  }
}

async function onMapClick(e) {
  if (!e?.latLng) return
  const rawLat = roundCoord(e.latLng.lat())
  const rawLng = roundCoord(e.latLng.lng())
  const lat = rawLat
  const lng = rawLng

  if (mapMode.value === 'pickPoint') {
    pointForm.value.latitude = lat
    pointForm.value.longitude = lng
    if (toast) toast.success('Lokasi titik dipilih dari peta.')
    stopMapMode()
    return
  }

  if (mapMode.value === 'pickBreak') {
    breakForm.value.latitude = lat
    breakForm.value.longitude = lng
    if (toast) toast.success('Lokasi putus dipilih dari peta.')
    stopMapMode()
    return
  }

  if (mapMode.value === 'drawCable') {
    const path = Array.isArray(cableForm.value.path) ? cableForm.value.path : []
    if (!Number.isFinite(rawLat) || !Number.isFinite(rawLng)) return
    const snap = snapLatLngToKnownPoint(rawLat, rawLng, SNAP_RADIUS_M)
    const nextLat = Number(snap?.lat)
    const nextLng = Number(snap?.lng)
    if (!Number.isFinite(nextLat) || !Number.isFinite(nextLng)) return

    // First point: just set start
    if (path.length < 1 || !drawFollowRoad.value) {
      // Checkpoint length so Undo can revert a whole segment even when follow-road adds many points
      drawUndoStack.push(path.length)
      path.push({ lat: nextLat, lng: nextLng })
      cableForm.value.path = path
      renderDraft()
      if (snap?.snapped && toast) {
        toast.info(`Snap ke titik: ${snap?.point?.name || 'NODE'} (${snap?.distance_m || 0}m)`)
      }
      return
    }

    // Follow road between last point and new point
    if (drawBusy.value) return
    drawUndoStack.push(path.length)
    drawBusy.value = true

    try {
      const last = path[path.length - 1]
      const roadPts = await getRoadPath(
        { lat: Number(last?.lat), lng: Number(last?.lng) },
        { lat: nextLat, lng: nextLng }
      )

      if (Array.isArray(roadPts) && roadPts.length >= 2) {
        const seg = roadPts.slice(1) // skip first (same as last)
        cableForm.value.path = path.concat(seg)
      } else {
        path.push({ lat: nextLat, lng: nextLng })
        cableForm.value.path = path
      }
    } catch (err) {
      // Fallback: straight segment
      path.push({ lat: nextLat, lng: nextLng })
      cableForm.value.path = path
      if (toast) toast.error(err?.message || 'Gagal mengikuti jalan. Pakai garis lurus.')
    } finally {
      drawBusy.value = false
      renderDraft()
      if (snap?.snapped && toast) {
        toast.info(`Snap ke titik: ${snap?.point?.name || 'NODE'} (${snap?.distance_m || 0}m)`)
      }
    }
  }
}

function stopMapMode(options = {}) {
  const reopen = options?.reopen !== false
  const resume = reopen ? resumeModal.value : null

  cleanupDraftPathListeners()
  mapMode.value = null
  resumeModal.value = null

  if (reopen && resume) {
    if (resume === 'cable') showCableModal.value = true
    if (resume === 'point') showPointModal.value = true
    if (resume === 'break') showBreakModal.value = true
  }

  renderDraft()
}

/* ───────────── Map popups (safe text) ───────────── */
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

function showInfoAt(latLng, lines, anchor = null) {
  if (!map || !infoWindow) return

  const items = (lines || []).filter(Boolean)
  if (items.length < 1) return

  try { infoWindow.close() } catch {}
  infoWindow.setContent(popupEl(items))

  try {
    if (anchor) {
      infoWindow.open({ map, anchor })
      return
    }
    if (latLng) infoWindow.setPosition(latLng)
    infoWindow.open({ map })
  } catch {}
}

const pointById = computed(() => {
  const m = new Map()
  ;(points.value || []).forEach((p) => {
    const id = Number(p?.id || 0)
    if (id > 0) m.set(id, p)
  })
  return m
})

const cableById = computed(() => {
  const m = new Map()
  ;(cables.value || []).forEach((c) => {
    const id = Number(c?.id || 0)
    if (id > 0) m.set(id, c)
  })
  return m
})

const breakById = computed(() => {
  const m = new Map()
  ;(breaks.value || []).forEach((b) => {
    const id = Number(b?.id || 0)
    if (id > 0) m.set(id, b)
  })
  return m
})

const selectedCable = computed(() => {
  const id = Number(selectedCableId.value || 0)
  if (!id) return null
  return cableById.value.get(id) || null
})

const selectedPoint = computed(() => {
  const id = Number(selectedPointId.value || 0)
  if (!id) return null
  return pointById.value.get(id) || null
})

const selectedBreak = computed(() => {
  const id = Number(selectedBreakId.value || 0)
  if (!id) return null
  return breakById.value.get(id) || null
})

function formatCoordPair(lat, lng) {
  const a = Number(lat)
  const b = Number(lng)
  if (!Number.isFinite(a) || !Number.isFinite(b)) return '-'
  return `${a.toFixed(6)}, ${b.toFixed(6)}`
}

function formatDateTime(v) {
  if (!v) return '-'
  const d = new Date(v)
  if (!Number.isFinite(d.getTime())) return '-'
  try {
    return d.toLocaleString('id-ID', {
      dateStyle: 'medium',
      timeStyle: 'short',
    })
  } catch {
    return d.toISOString()
  }
}

const selectedDrawerItem = computed(() => {
  if (selectedCable.value) {
    const c = selectedCable.value
    return {
      type: 'cable',
      tone: 'blue',
      badge: 'KABEL',
      title: c.name || `Kabel #${c.id}`,
      subtitle: [c.code, c.cable_type].filter(Boolean).join(' | ') || 'Jalur kabel',
      rows: [
        { label: 'Core', value: c.core_count ? `${c.core_count} core` : '-' },
        { label: 'Panjang', value: formatLength(c.length_m) },
        { label: 'Dari', value: pointNameById(c.from_point_id) || '-' },
        { label: 'Ke', value: pointNameById(c.to_point_id) || '-' },
      ],
      note: c.notes || '',
    }
  }

  if (selectedPoint.value) {
    const p = selectedPoint.value
    const pointId = Number(p?.id || 0)
    const connected = (cables.value || []).filter((c) => {
      return Number(c?.from_point_id || 0) === pointId || Number(c?.to_point_id || 0) === pointId
    }).length
    const pointPorts = (ports.value || []).filter((pt) => Number(pt?.point_id || 0) === pointId).length

    return {
      type: 'point',
      tone: 'teal',
      badge: 'TITIK',
      title: p.name || `Titik #${p.id}`,
      subtitle: p.point_type || 'Tanpa tipe',
      rows: [
        { label: 'Koordinat', value: formatCoordPair(p.latitude, p.longitude) },
        { label: 'Port', value: String(pointPorts) },
        { label: 'Kabel terkait', value: String(connected) },
      ],
      note: p.address || p.notes || '',
    }
  }

  if (selectedBreak.value) {
    const b = selectedBreak.value
    const status = formatStatus(b.status)
    const tone = status === 'OPEN'
      ? 'red'
      : (status === 'IN PROGRESS' ? 'amber' : (['FIXED', 'VERIFIED'].includes(status) ? 'green' : 'slate'))

    return {
      type: 'break',
      tone,
      badge: 'PUTUS',
      title: cableNameById(b.cable_id) || (b.cable_id ? `Kabel #${b.cable_id}` : 'Tanpa kabel'),
      subtitle: status,
      rows: [
        { label: 'Titik', value: pointNameById(b.point_id) || '-' },
        { label: 'Core', value: b.core_no ? String(b.core_no) : '-' },
        { label: 'Severity', value: b.severity || '-' },
        { label: 'Teknisi', value: b.technician_name || '-' },
        { label: 'Koordinat', value: formatCoordPair(b.latitude, b.longitude) },
        { label: 'Dilaporkan', value: formatDateTime(b.reported_at) },
      ],
      note: b.description || '',
    }
  }

  return null
})

const selectedCardClass = computed(() => {
  const tone = selectedDrawerItem.value?.tone
  if (tone === 'blue') return 'border-blue-200/80 dark:border-blue-900/40'
  if (tone === 'teal') return 'border-teal-200/80 dark:border-teal-900/40'
  if (tone === 'red') return 'border-red-200/80 dark:border-red-900/40'
  if (tone === 'amber') return 'border-amber-200/80 dark:border-amber-900/40'
  if (tone === 'green') return 'border-emerald-200/80 dark:border-emerald-900/40'
  return 'border-slate-200/80 dark:border-white/10'
})

const selectedBadgeClass = computed(() => {
  const tone = selectedDrawerItem.value?.tone
  if (tone === 'blue') return 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-200'
  if (tone === 'teal') return 'bg-teal-50 text-teal-700 dark:bg-teal-900/20 dark:text-teal-200'
  if (tone === 'red') return 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-200'
  if (tone === 'amber') return 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-200'
  if (tone === 'green') return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-200'
  return 'bg-slate-100 text-slate-700 dark:bg-slate-700/40 dark:text-slate-200'
})

function clearSelection() {
  selectedCableId.value = null
  selectedPointId.value = null
  selectedBreakId.value = null
  try { infoWindow?.close() } catch {}
  renderCablesLayer()
  renderPointsLayer()
  renderBreaksLayer()
}

function focusSelectedItem() {
  if (selectedCable.value) {
    zoomToCable(selectedCable.value)
    return
  }
  if (selectedPoint.value) {
    focusOnLatLng(Number(selectedPoint.value.latitude), Number(selectedPoint.value.longitude), 18)
    return
  }
  if (selectedBreak.value) {
    focusOnLatLng(Number(selectedBreak.value.latitude), Number(selectedBreak.value.longitude), 18)
  }
}

function openSelectedInPanel() {
  if (selectedCable.value) activeTab.value = 'cables'
  else if (selectedPoint.value) activeTab.value = 'points'
  else if (selectedBreak.value) activeTab.value = 'breaks'
  revealPanelOnMobile('open')
}

function editSelectedItem() {
  if (!canEdit.value) return
  if (selectedCable.value) {
    openEditCable(selectedCable.value)
    return
  }
  if (selectedPoint.value) {
    openEditPoint(selectedPoint.value)
    return
  }
  if (selectedBreak.value) {
    openEditBreak(selectedBreak.value)
  }
}

function pointNameById(id) {
  const n = Number(id || 0)
  if (!n) return null
  const p = pointById.value.get(n)
  return p ? String(p.name || '') : null
}

function pointLatLngById(id) {
  const n = Number(id || 0)
  if (!n) return null
  const p = pointById.value.get(n)
  if (!p) return null
  const lat = roundCoord(Number(p?.latitude))
  const lng = roundCoord(Number(p?.longitude))
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null
  return { lat, lng }
}

function cableNameById(id) {
  const n = Number(id || 0)
  if (!n) return null
  const c = cableById.value.get(n)
  return c ? String(c.name || '') : null
}

function cableAttachedToPointId(cableId, pointId) {
  const cid = Number(cableId || 0)
  const pid = Number(pointId || 0)
  if (!cid || !pid) return false
  const cable = cableById.value.get(cid)
  if (!cable) return false
  return Number(cable?.from_point_id || 0) === pid || Number(cable?.to_point_id || 0) === pid
}

function statusColor(status) {
  const s = String(status || '').toUpperCase()
  if (s === 'OPEN') return { stroke: '#dc2626', fill: '#ef4444' }
  if (s === 'IN_PROGRESS') return { stroke: '#b45309', fill: '#f59e0b' }
  if (s === 'FIXED') return { stroke: '#047857', fill: '#10b981' }
  if (s === 'VERIFIED') return { stroke: '#065f46', fill: '#34d399' }
  return { stroke: '#64748b', fill: '#94a3b8' }
}

/* ───────────── Render layers ───────────── */
function renderAllLayers() {
  if (!map || !window.google?.maps) return
  renderCablesLayer()
  renderPointsLayer()
  renderBreaksLayer()
  renderDraft()
}

function renderCablesLayer() {
  if (!map || !window.google?.maps) return
  clearLayer(layerCables)
  if (!showCables.value) return

  ;(cables.value || []).forEach((c) => {
    const path = Array.isArray(c?.path) ? c.path : null
    if (!path || path.length < 2) return

    const latlngs = path
      .map((p) => ({ lat: Number(p?.lat), lng: Number(p?.lng) }))
      .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng))

    if (latlngs.length < 2) return

    const isSelected = Number(c?.id) === Number(selectedCableId.value || 0)
    const traceActive = traceUsedCableSet.value.size > 0
    const inTrace = traceUsedCableSet.value.has(Number(c?.id))
    const color = (c?.map_color || '#2563eb').toString()

    const weightBase = isSelected ? 6 : 4
    const weight = inTrace ? Math.max(weightBase, 6) : weightBase
    const opacity = traceActive ? (inTrace ? 1 : 0.25) : 0.9

    const poly = new window.google.maps.Polyline({
      path: latlngs,
      strokeColor: color,
      strokeWeight: weight,
      strokeOpacity: opacity,
      clickable: true,
    })
    poly.setMap(map)
    layerCables.set(Number(c?.id) || 0, poly)

    const fromName = pointNameById(c?.from_point_id) || '-'
    const toName = pointNameById(c?.to_point_id) || '-'
    poly.addListener('click', (ev) => {
      selectCable(c, { zoom: false, panel: 'open' })

      showInfoAt(ev?.latLng || null, [
        `Kabel: ${c?.name || '-'}`,
        c?.code ? `Kode: ${c.code}` : null,
        c?.cable_type ? `Tipe: ${c.cable_type}` : null,
        c?.core_count ? `Core: ${c.core_count}` : null,
        `Dari: ${fromName}`,
        `Ke: ${toName}`,
      ])
    })
  })
}

function renderPointsLayer() {
  if (!map || !window.google?.maps) return
  clearLayer(layerPoints)
  if (!showPoints.value) return

  ;(points.value || []).forEach((p) => {
    const lat = Number(p?.latitude)
    const lng = Number(p?.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return

    const isSelected = Number(p?.id) === Number(selectedPointId.value || 0)
    const marker = new window.google.maps.Marker({
      position: { lat, lng },
      map,
      title: (p?.name || '').toString(),
      clickable: true,
      icon: {
        path: window.google.maps.SymbolPath.CIRCLE,
        scale: isSelected ? 7 : 6,
        fillColor: '#14b8a6',
        fillOpacity: 0.9,
        strokeColor: '#0f766e',
        strokeWeight: 2,
      },
    })
    layerPoints.set(Number(p?.id) || 0, marker)

    marker.addListener('click', () => {
      selectPoint(p, { zoom: false, panel: 'open' })

      showInfoAt(marker.getPosition(), [
        `Titik: ${p?.name || '-'}`,
        p?.point_type ? `Tipe: ${p.point_type}` : null,
        `Koordinat: ${lat}, ${lng}`,
      ], marker)
    })
  })
}

function renderBreaksLayer() {
  if (!map || !window.google?.maps) return
  clearLayer(layerBreaks)
  if (!showBreaks.value) return

  ;(breaks.value || []).forEach((b) => {
    const lat = Number(b?.latitude)
    const lng = Number(b?.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return

    const colors = statusColor(b?.status)
    const isSelected = Number(b?.id) === Number(selectedBreakId.value || 0)

    const marker = new window.google.maps.Marker({
      position: { lat, lng },
      map,
      title: `PUTUS: ${String(b?.status || 'OPEN').toUpperCase()}`,
      clickable: true,
      icon: {
        path: window.google.maps.SymbolPath.CIRCLE,
        scale: isSelected ? 8 : 7,
        fillColor: colors.fill,
        fillOpacity: 0.9,
        strokeColor: colors.stroke,
        strokeWeight: 2,
      },
    })
    layerBreaks.set(Number(b?.id) || 0, marker)

    marker.addListener('click', () => {
      selectBreak(b, { zoom: false, panel: 'open' })

      const cableName = cableNameById(b?.cable_id) || (b?.cable_id ? `#${b.cable_id}` : '-')
      const pName = pointNameById(b?.point_id) || (b?.point_id ? `#${b.point_id}` : '-')
      showInfoAt(marker.getPosition(), [
        `PUTUS: ${String(b?.status || 'OPEN').toUpperCase()}`,
        `Kabel: ${cableName}`,
        `Titik: ${pName}`,
        b?.severity ? `Severity: ${b.severity}` : null,
      ], marker)
    })
  })
}

function syncCablePathFromEditablePolyline(poly) {
  try {
    const p = poly?.getPath?.()
    if (!p || typeof p.getLength !== 'function') return

    const pts = []
    for (let i = 0; i < p.getLength(); i += 1) {
      const ll = p.getAt(i)
      const lat = roundCoord(ll?.lat?.())
      const lng = roundCoord(ll?.lng?.())
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue
      pts.push({ lat, lng })
    }

    cableForm.value.path = pts
  } catch {}
}

function bindDraftEditablePolyline(poly) {
  cleanupDraftPathListeners()
  if (!poly || !window.google?.maps) return

  const p = poly.getPath?.()
  if (!p || typeof p.addListener !== 'function') return

  const sync = () => syncCablePathFromEditablePolyline(poly)
  draftPathListeners.push(p.addListener('insert_at', sync))
  draftPathListeners.push(p.addListener('set_at', sync))
  draftPathListeners.push(p.addListener('remove_at', sync))

  sync()
}

function renderDraft() {
  if (!map || !window.google?.maps) return
  clearDraftLayer()

  const addCircle = (lat, lng, opts = {}) => {
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
    const m = new window.google.maps.Marker({
      position: { lat, lng },
      map,
      clickable: false,
      icon: {
        path: window.google.maps.SymbolPath.CIRCLE,
        scale: Number(opts.scale ?? 6),
        fillColor: (opts.fillColor || '#38bdf8').toString(),
        fillOpacity: Number(opts.fillOpacity ?? 0.9),
        strokeColor: (opts.strokeColor || '#0ea5e9').toString(),
        strokeWeight: Number(opts.strokeWeight ?? 2),
      },
    })
    layerDraft.markers.push(m)
  }

  if ((showPointModal.value || mapMode.value === 'pickPoint') && pointForm.value.latitude !== null && pointForm.value.longitude !== null) {
    const lat = Number(pointForm.value.latitude)
    const lng = Number(pointForm.value.longitude)
    if (Number.isFinite(lat) && Number.isFinite(lng)) {
      addCircle(lat, lng, { scale: 7, strokeColor: '#0ea5e9', fillColor: '#38bdf8', fillOpacity: 0.85 })
    }
  }

  if ((showBreakModal.value || mapMode.value === 'pickBreak') && breakForm.value.latitude !== null && breakForm.value.longitude !== null) {
    const lat = Number(breakForm.value.latitude)
    const lng = Number(breakForm.value.longitude)
    if (Number.isFinite(lat) && Number.isFinite(lng)) {
      addCircle(lat, lng, { scale: 8, strokeColor: '#f97316', fillColor: '#fb923c', fillOpacity: 0.85 })
    }
  }

  if ((showCableModal.value || mapMode.value === 'drawCable' || mapMode.value === 'editCable') && Array.isArray(cableForm.value.path) && cableForm.value.path.length > 0) {
    const isEdit = mapMode.value === 'editCable'
    const latlngs = cableForm.value.path
      .map((p) => ({ lat: Number(p?.lat), lng: Number(p?.lng) }))
      .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng))

    if (!isEdit) {
      latlngs.forEach((ll) => {
        addCircle(ll.lat, ll.lng, { scale: 3, strokeColor: '#1d4ed8', fillColor: '#60a5fa', fillOpacity: 0.95 })
      })
    }

    if (latlngs.length >= 2) {
      const lineSymbol = {
        path: 'M 0,-1 0,1',
        strokeOpacity: 1,
        strokeColor: '#1d4ed8',
        scale: 3,
      }
      layerDraft.polyline = new window.google.maps.Polyline({
        path: latlngs,
        strokeColor: '#1d4ed8',
        strokeOpacity: isEdit ? 0.85 : 0,
        strokeWeight: isEdit ? 4 : 0,
        icons: isEdit ? [] : [{ icon: lineSymbol, offset: '0', repeat: '12px' }],
        clickable: isEdit,
        editable: isEdit,
        map,
      })

      if (isEdit) {
        bindDraftEditablePolyline(layerDraft.polyline)
      }
    }
  }
}

function fitOnce() {
  if (!map || fittedOnce || !window.google?.maps) return

  const b = new window.google.maps.LatLngBounds()
  let has = false

  try {
    if (showCables.value) {
      ;(cables.value || []).forEach((c) => {
        const path = Array.isArray(c?.path) ? c.path : null
        if (!path || path.length < 1) return
        path.forEach((p) => {
          const lat = Number(p?.lat)
          const lng = Number(p?.lng)
          if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
          b.extend({ lat, lng })
          has = true
        })
      })
    }
  } catch {}

  try {
    if (showPoints.value) {
      ;(points.value || []).forEach((p) => {
        const lat = Number(p?.latitude)
        const lng = Number(p?.longitude)
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
        b.extend({ lat, lng })
        has = true
      })
    }
  } catch {}

  try {
    if (showBreaks.value) {
      ;(breaks.value || []).forEach((br) => {
        const lat = Number(br?.latitude)
        const lng = Number(br?.longitude)
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
        b.extend({ lat, lng })
        has = true
      })
    }
  } catch {}

  if (!has) return

  try {
    map.fitBounds(b)
    fittedOnce = true
  } catch {}
}

function fitToVisible() {
  fittedOnce = false
  fitOnce()
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
  if (!map || !window.google?.maps || !Number.isFinite(lat) || !Number.isFinite(lng)) return
  try { map.panTo({ lat, lng }) } catch {}
  if (Number.isFinite(Number(zoom))) {
    try { map.setZoom(Number(zoom)) } catch {}
  }
}

function isMobileViewport() {
  try { return window.innerWidth < 1024 } catch { return false }
}

const panelSnapClass = computed(() => {
  const v = String(panelSnap.value || 'half')
  if (v === 'peek') return 'max-h-[38vh]'
  if (v === 'full') return 'max-h-[calc(100vh-4rem)]'
  return 'max-h-[68vh]'
})

function cyclePanelSnap() {
  const curr = String(panelSnap.value || 'half')
  panelSnap.value = curr === 'peek' ? 'half' : (curr === 'half' ? 'full' : 'peek')
}

function revealPanelOnMobile(action) {
  if (!isMobileViewport()) return
  if (action === 'open') panelOpen.value = true
  if (action === 'close') panelOpen.value = false
}

function zoomToCable(itemOrId) {
  if (!map || !window.google?.maps) return

  const c = (typeof itemOrId === 'object' && itemOrId)
    ? itemOrId
    : cableById.value.get(Number(itemOrId || 0))

  const path = Array.isArray(c?.path) ? c.path : null
  if (!path || path.length < 1) return

  const b = new window.google.maps.LatLngBounds()
  let has = false
  path.forEach((p) => {
    const lat = Number(p?.lat)
    const lng = Number(p?.lng)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
    b.extend({ lat, lng })
    has = true
  })

  if (!has) return

  try {
    map.fitBounds(b, 60)
  } catch {
    try { map.fitBounds(b) } catch {}
  }
}

function selectCable(item, opts = {}) {
  selectedCableId.value = item?.id ?? null
  selectedPointId.value = null
  selectedBreakId.value = null
  activeTab.value = 'cables'
  renderCablesLayer()

  const doZoom = opts.zoom !== false
  if (doZoom) zoomToCable(item)

  if (opts.panel) revealPanelOnMobile(opts.panel)
}

function selectPoint(item, opts = {}) {
  selectedPointId.value = item?.id ?? null
  selectedCableId.value = null
  selectedBreakId.value = null
  activeTab.value = 'points'
  renderPointsLayer()

  const doZoom = opts.zoom !== false
  if (doZoom) focusOnLatLng(Number(item?.latitude), Number(item?.longitude), 17)

  if (opts.panel) revealPanelOnMobile(opts.panel)
}

function selectBreak(item, opts = {}) {
  selectedBreakId.value = item?.id ?? null
  selectedCableId.value = null
  selectedPointId.value = null
  activeTab.value = 'breaks'
  renderBreaksLayer()

  const doZoom = opts.zoom !== false
  if (doZoom) focusOnLatLng(Number(item?.latitude), Number(item?.longitude), 17)

  if (opts.panel) revealPanelOnMobile(opts.panel)
}

function haversineMeters(a, b) {
  const lat1 = Number(a?.lat)
  const lon1 = Number(a?.lng)
  const lat2 = Number(b?.lat)
  const lon2 = Number(b?.lng)
  if (!Number.isFinite(lat1) || !Number.isFinite(lon1) || !Number.isFinite(lat2) || !Number.isFinite(lon2)) return 0

  const R = 6371000
  const toRad = (deg) => (Number(deg) * Math.PI) / 180
  const dLat = toRad(lat2 - lat1)
  const dLon = toRad(lon2 - lon1)
  const s1 = Math.sin(dLat / 2)
  const s2 = Math.sin(dLon / 2)
  const x = s1 * s1 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * s2 * s2
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(x)))
}

function calcPathLengthMeters(path) {
  const pts = Array.isArray(path) ? path : []
  if (pts.length < 2) return 0
  let sum = 0
  for (let i = 1; i < pts.length; i += 1) {
    sum += haversineMeters(pts[i - 1], pts[i])
  }
  return sum
}

function formatLength(meters) {
  const m = Number(meters)
  if (!Number.isFinite(m) || m <= 0) return '-'
  if (m < 1000) return `${Math.round(m)} m`
  const km = m / 1000
  return `${km.toFixed(km < 10 ? 2 : 1)} km`
}

function ensureMapReady() {
  if (mapLoadError.value) {
    if (toast) toast.error(mapLoadError.value)
    return false
  }
  if (!map || !window.google?.maps) {
    if (toast) toast.error('Peta belum siap.')
    return false
  }
  return true
}

/* ───────────── Modals: Cable ───────────── */
const showCableModal = ref(false)
const cableModalMode = ref('create') // create|edit
const cableProcessing = ref(false)
const cableErrors = ref({})
const cableLengthAuto = ref(true)
const cableAutoRouteArmed = ref(false)

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
  length_m: '',
  notes: '',
})

const cablePathLenM = computed(() => Math.round(calcPathLengthMeters(cableForm.value.path)))

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
    length_m: '',
    notes: '',
  }
  cableErrors.value = {}
  cableLengthAuto.value = true
  cableAutoRouteArmed.value = false
}

function openCreateCable() {
  if (!canCreate.value) return
  resetCableForm()
  cableModalMode.value = 'create'
  drawUndoStack = []
  cableLengthAuto.value = true
  cableAutoRouteArmed.value = true
  showCableModal.value = true
  resumeModal.value = null
  stopMapMode({ reopen: false })
}

function openEditCable(item) {
  if (!canEdit.value) return
  resetCableForm()
  cableModalMode.value = 'edit'
  drawUndoStack = []
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
    length_m: item.length_m ?? '',
    notes: item.notes || '',
  }
  cableLengthAuto.value = !(cableForm.value.length_m !== '' && cableForm.value.length_m !== null && cableForm.value.length_m !== undefined)
  cableAutoRouteArmed.value = false
  showCableModal.value = true
  resumeModal.value = null
  stopMapMode({ reopen: false })

  const p0 = (cableForm.value.path || [])[0]
  if (p0 && Number.isFinite(Number(p0.lat)) && Number.isFinite(Number(p0.lng))) {
    focusOnLatLng(Number(p0.lat), Number(p0.lng), 16)
  }
}

function fitMapToPath(path) {
  if (!map || !window.google?.maps) return
  const pts = Array.isArray(path) ? path : []
  if (pts.length < 1) return

  const b = new window.google.maps.LatLngBounds()
  let has = false
  pts.forEach((p) => {
    const lat = Number(p?.lat)
    const lng = Number(p?.lng)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
    b.extend({ lat, lng })
    has = true
  })
  if (!has) return

  try { map.fitBounds(b) } catch {}
}

async function autoCablePathFromEndpoints(options = {}) {
  const force = options?.force === true

  const fromId = Number(cableForm.value.from_point_id || 0)
  const toId = Number(cableForm.value.to_point_id || 0)
  if (!fromId || !toId) {
    if (toast) toast.error('Pilih Dari Titik dan Ke Titik dulu.')
    return false
  }
  if (fromId === toId) {
    if (toast) toast.error('Dari Titik dan Ke Titik tidak boleh sama.')
    return false
  }

  const a = pointLatLngById(fromId)
  const b = pointLatLngById(toId)
  if (!a || !b) {
    if (toast) toast.error('Koordinat titik belum lengkap. Isi latitude/longitude di data Titik.')
    return false
  }

  const hasExisting = Array.isArray(cableForm.value.path) && cableForm.value.path.length >= 2
  if (hasExisting && !force) return false
  if (hasExisting && force) {
    const ok = confirm('Jalur sudah ada. Timpa jalur dengan jalur otomatis dari titik?')
    if (!ok) return false
  }

  let newPath = []
  const doFollow = !!drawFollowRoad.value

  if (doFollow && window.google?.maps) {
    try {
      newPath = await getRoadPath(a, b)
    } catch (e) {
      newPath = []
      if (toast) toast.error(e?.message || 'Gagal mengikuti jalan. Pakai garis lurus.')
    }
  }

  if (!Array.isArray(newPath) || newPath.length < 2) {
    newPath = [a, b]
  }

  drawUndoStack = []
  cableForm.value.path = newPath
  renderDraft()
  fitMapToPath(newPath)

  // Default: set length from path (editable later).
  cableLengthAuto.value = true
  cableForm.value.length_m = Math.round(calcPathLengthMeters(newPath))

  if (toast) toast.success(doFollow ? 'Jalur otomatis dibuat (ikut jalan).' : 'Jalur otomatis dibuat.')
  return true
}

function turnAngleDeg(a, b, c) {
  const ax = Number(a?.lng) - Number(b?.lng)
  const ay = Number(a?.lat) - Number(b?.lat)
  const bx = Number(c?.lng) - Number(b?.lng)
  const by = Number(c?.lat) - Number(b?.lat)
  const ma = Math.hypot(ax, ay)
  const mb = Math.hypot(bx, by)
  if (!Number.isFinite(ma) || !Number.isFinite(mb) || ma === 0 || mb === 0) return 0
  const dot = ax * bx + ay * by
  const cos = Math.max(-1, Math.min(1, dot / (ma * mb)))
  const angle = Math.acos(cos) * 180 / Math.PI
  // 180 is straight line, so turning angle = 180-angle
  return Math.max(0, 180 - angle)
}

function detectAutoMidPointCandidates(path) {
  const pts = Array.isArray(path) ? path : []
  if (pts.length < 3) return []
  const out = []
  for (let i = 1; i < pts.length - 1; i += 1) {
    const prev = pts[i - 1]
    const cur = pts[i]
    const next = pts[i + 1]
    const lat = Number(cur?.lat)
    const lng = Number(cur?.lng)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue

    const turn = turnAngleDeg(prev, cur, next)
    if (turn < MID_NODE_MIN_TURN_DEG) continue

    const near = nearestPointForLatLng(lat, lng, SNAP_RADIUS_M)
    if (near) continue

    out.push({ lat: roundCoord(lat), lng: roundCoord(lng), turn_deg: Math.round(turn) })
  }

  // Avoid too many auto points in one save.
  return out.slice(0, 24)
}

async function saveCable() {
  cableProcessing.value = true
  cableErrors.value = {}

  const fromPointId = cableForm.value.from_point_id !== '' ? Number(cableForm.value.from_point_id) : null
  const toPointId = cableForm.value.to_point_id !== '' ? Number(cableForm.value.to_point_id) : null
  if (!fromPointId || !toPointId) {
    cableProcessing.value = false
    if (toast) toast.error('Dari Titik dan Ke Titik wajib diisi.')
    return
  }
  if (Number(fromPointId) === Number(toPointId)) {
    cableProcessing.value = false
    if (toast) toast.error('Dari Titik dan Ke Titik tidak boleh sama.')
    return
  }

  let path = Array.isArray(cableForm.value.path) && cableForm.value.path.length >= 2 ? cableForm.value.path.slice() : null
  const fromPt = pointLatLngById(fromPointId)
  const toPt = pointLatLngById(toPointId)
  if (fromPt && toPt) {
    if (!Array.isArray(path) || path.length < 2) {
      path = [fromPt, toPt]
    } else {
      path[0] = { lat: fromPt.lat, lng: fromPt.lng }
      path[path.length - 1] = { lat: toPt.lat, lng: toPt.lng }
    }
  }
  if (Array.isArray(path)) cableForm.value.path = path

  const midCandidates = detectAutoMidPointCandidates(path || [])
  let autoMidPoints = []
  if (midCandidates.length > 0) {
    const okMid = confirm(`Ditemukan ${midCandidates.length} titik belok yang belum punya node. Buat titik FO otomatis (JOINT_CLOSURE)?`)
    if (okMid) {
      autoMidPoints = midCandidates.map((p) => ({ lat: p.lat, lng: p.lng }))
    } else {
      const continueWithoutAuto = confirm('Titik FO otomatis tidak dibuat. Lanjut simpan kabel tanpa auto node?')
      if (!continueWithoutAuto) {
        cableProcessing.value = false
        if (toast) toast.info('Penyimpanan kabel dibatalkan.')
        return
      }
      if (toast) toast.info('Kabel akan disimpan tanpa pembuatan auto node.')
    }
  }

  const payload = {
    name: cableForm.value.name,
    code: cableForm.value.code || null,
    cable_type: cableForm.value.cable_type || null,
    core_count: cableForm.value.core_count !== '' ? Number(cableForm.value.core_count) : null,
    map_color: cableForm.value.map_color || null,
    from_point_id: fromPointId,
    to_point_id: toPointId,
    path,
    length_m: cableForm.value.length_m !== '' && cableForm.value.length_m !== null && cableForm.value.length_m !== undefined ? Number(cableForm.value.length_m) : null,
    auto_mid_points: autoMidPoints.length > 0 ? autoMidPoints : undefined,
    auto_mid_point_type: autoMidPoints.length > 0 ? 'JOINT_CLOSURE' : undefined,
    auto_mid_point_prefix: autoMidPoints.length > 0 ? 'NODE AUTO' : undefined,
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
    const createdMid = Array.isArray(res.data?.meta?.auto_mid_points_created) ? res.data.meta.auto_mid_points_created : []
    if (createdMid.length > 0 && toast) {
      toast.info(`Node otomatis dibuat: ${createdMid.length} titik.`)
    }
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
    await deleteFiberItemByTab('cables', item)
    if (toast) toast.success('Kabel dihapus.')
    clearMapSelectionAfterDelete('cables', item)
    removeBulkItemSelection('cables', item)
    await loadAll()
  } catch (e) {
    console.error('Fiber: deleteCable failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus kabel.')
  }
}

function startDrawCable() {
  if (!showCableModal.value) return
  if (!ensureMapReady()) return
  resumeModal.value = 'cable'
  showCableModal.value = false
  drawUndoStack = []
  mapMode.value = 'drawCable'
  renderDraft()
  if (toast) toast.info(drawFollowRoad.value
    ? 'Mode gambar jalur aktif (ikut jalan): klik peta untuk set titik berikutnya, lalu klik Selesai untuk kembali.'
    : 'Mode gambar jalur aktif: klik peta untuk menambah titik, lalu klik Selesai untuk kembali.'
  )
}

function startEditCablePath() {
  if (!showCableModal.value) return
  if (!ensureMapReady()) return

  const path = Array.isArray(cableForm.value.path) ? cableForm.value.path : []
  if (path.length < 2) {
    if (toast) toast.error('Jalur belum ada. Klik Gambar dulu minimal 2 titik.')
    return
  }

  resumeModal.value = 'cable'
  showCableModal.value = false
  mapMode.value = 'editCable'
  renderDraft()
  if (toast) toast.info('Mode edit jalur aktif: geser titik/segmen di peta, lalu klik Selesai untuk kembali.')
}

function undoCablePoint() {
  const path = Array.isArray(cableForm.value.path) ? cableForm.value.path : []
  if (path.length === 0) return
  if (drawUndoStack.length > 0) {
    const prevLen = Number(drawUndoStack.pop())
    if (Number.isFinite(prevLen) && prevLen >= 0) {
      cableForm.value.path = path.slice(0, prevLen)
    } else {
      path.pop()
      cableForm.value.path = path
    }
  } else {
    path.pop()
    cableForm.value.path = path
  }
  renderDraft()
}

function clearCablePath() {
  cableForm.value.path = []
  drawUndoStack = []
  renderDraft()
}

function setCableLengthFromPath() {
  const m = Number(cablePathLenM.value || 0)
  if (!Number.isFinite(m) || m <= 0) {
    if (toast) toast.error('Tidak ada jalur untuk dihitung.')
    return
  }

  cableForm.value.length_m = Math.round(m)
  cableLengthAuto.value = true
  if (toast) toast.success('Panjang kabel diisi dari jalur.')
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
  resumeModal.value = null
  stopMapMode({ reopen: false })
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
  resumeModal.value = null
  stopMapMode({ reopen: false })

  const lat = Number(pointForm.value.latitude)
  const lng = Number(pointForm.value.longitude)
  if (Number.isFinite(lat) && Number.isFinite(lng)) focusOnLatLng(lat, lng, 17)
}

function pickPointOnMap() {
  if (!showPointModal.value) return
  if (!ensureMapReady()) return
  resumeModal.value = 'point'
  showPointModal.value = false
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
    await deleteFiberItemByTab('points', item)
    if (toast) toast.success('Titik dihapus.')
    clearMapSelectionAfterDelete('points', item)
    removeBulkItemSelection('points', item)
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
  core_no: '',
  status: 'OPEN',
  severity: '',
  reported_at: '',
  repair_started_at: '',
  fixed_at: '',
  verified_at: '',
  verified_by_name: '',
  technician_name: '',
  repair_photos_text: '',
  repair_materials_text: '',
  closure_point_id: '',
  latitude: null,
  longitude: null,
  description: '',
})

const breakOtdrForm = ref({
  reference_mode: 'endpoint', // endpoint|point
  reference_side: 'from', // from|to
  reference_point_id: '',
  reference_direction: 'auto', // auto|toward_to|toward_from
  distance_m: '',
})

const BREAK_OTDR_REF_MAX_OFFSET_M = 80

const breakSelectedCable = computed(() => {
  const id = Number(breakForm.value.cable_id || 0)
  if (!id) return null
  return cableById.value.get(id) || null
})

const breakOtdrFromLabel = computed(() => {
  const cable = breakSelectedCable.value
  if (!cable) return 'Dari Titik'
  return pointNameById(cable?.from_point_id) || 'Dari Titik'
})

const breakOtdrToLabel = computed(() => {
  const cable = breakSelectedCable.value
  if (!cable) return 'Ke Titik'
  return pointNameById(cable?.to_point_id) || 'Ke Titik'
})

const breakOtdrCableLengthM = computed(() => {
  const path = cablePathForBreakAssist(breakSelectedCable.value)
  return Math.round(calcPathLengthMeters(path))
})

function clampNumber(v, min, max) {
  const n = Number(v)
  if (!Number.isFinite(n)) return Number(min)
  return Math.max(Number(min), Math.min(Number(max), n))
}

function projectPointToPathWithChainage(path, pointLatLng) {
  const pts = Array.isArray(path) ? path : []
  if (pts.length < 1) return null

  const pLat = Number(pointLatLng?.lat)
  const pLng = Number(pointLatLng?.lng)
  if (!Number.isFinite(pLat) || !Number.isFinite(pLng)) return null

  if (pts.length < 2) {
    const only = pts[0]
    const lat = Number(only?.lat)
    const lng = Number(only?.lng)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null
    return {
      lat: roundCoord(lat),
      lng: roundCoord(lng),
      chainage_m: 0,
      offset_m: haversineMeters({ lat: pLat, lng: pLng }, { lat, lng }),
      segment_index: 0,
      segment_ratio: 0,
    }
  }

  let best = null
  let bestOffset = Number.POSITIVE_INFINITY
  let chainageAcc = 0

  for (let i = 1; i < pts.length; i += 1) {
    const a = pts[i - 1]
    const b = pts[i]
    const aLat = Number(a?.lat)
    const aLng = Number(a?.lng)
    const bLat = Number(b?.lat)
    const bLng = Number(b?.lng)
    if (!Number.isFinite(aLat) || !Number.isFinite(aLng) || !Number.isFinite(bLat) || !Number.isFinite(bLng)) continue

    const latRefRad = (Math.PI / 180) * ((aLat + bLat + pLat) / 3)
    const mPerLat = 111320
    const mPerLng = 111320 * Math.cos(latRefRad)

    const vx = (bLng - aLng) * mPerLng
    const vy = (bLat - aLat) * mPerLat
    const wx = (pLng - aLng) * mPerLng
    const wy = (pLat - aLat) * mPerLat
    const segSq = vx * vx + vy * vy
    const segLen = Math.sqrt(segSq)
    if (!Number.isFinite(segLen) || segLen <= 0) {
      chainageAcc += haversineMeters({ lat: aLat, lng: aLng }, { lat: bLat, lng: bLng })
      continue
    }

    const ratio = clampNumber(segSq > 0 ? (wx * vx + wy * vy) / segSq : 0, 0, 1)
    const projLat = aLat + (bLat - aLat) * ratio
    const projLng = aLng + (bLng - aLng) * ratio
    const px = wx - vx * ratio
    const py = wy - vy * ratio
    const offset = Math.sqrt(px * px + py * py)

    if (offset < bestOffset) {
      bestOffset = offset
      best = {
        lat: roundCoord(projLat),
        lng: roundCoord(projLng),
        chainage_m: chainageAcc + segLen * ratio,
        offset_m: offset,
        segment_index: i - 1,
        segment_ratio: ratio,
      }
    }

    chainageAcc += segLen
  }

  return best
}

const breakOtdrReferencePointOptions = computed(() => {
  const cable = breakSelectedCable.value
  const path = cablePathForBreakAssist(cable)
  if (!cable || !Array.isArray(path) || path.length < 2) return []

  const endpointIds = new Set([
    Number(cable?.from_point_id || 0),
    Number(cable?.to_point_id || 0),
  ])

  const out = []
  ;(points.value || []).forEach((p) => {
    const pointId = Number(p?.id || 0)
    if (!pointId) return
    const lat = Number(p?.latitude)
    const lng = Number(p?.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return

    const proj = projectPointToPathWithChainage(path, { lat, lng })
    if (!proj) return

    const isEndpoint = endpointIds.has(pointId)
    if (!isEndpoint && Number(proj.offset_m || 0) > BREAK_OTDR_REF_MAX_OFFSET_M) return

    const pointType = (p?.point_type || '').toString().toUpperCase()
    const offsetM = Math.round(Number(proj.offset_m || 0))
    const chainM = Math.round(Number(proj.chainage_m || 0))
    const label = `${p?.name || ('Titik #' + pointId)}${pointType ? ' | ' + pointType : ''} | chain ${chainM}m | off ${offsetM}m`

    out.push({
      id: pointId,
      name: p?.name || ('Titik #' + pointId),
      point_type: pointType,
      lat,
      lng,
      chainage_m: Number(proj.chainage_m || 0),
      offset_m: Number(proj.offset_m || 0),
      label,
    })
  })

  out.sort((a, b) => Number(a.chainage_m || 0) - Number(b.chainage_m || 0))
  return out
})

const breakOtdrSelectedReferencePoint = computed(() => {
  const id = Number(breakOtdrForm.value.reference_point_id || 0)
  if (!id) return null
  return breakOtdrReferencePointOptions.value.find((p) => Number(p?.id || 0) === id) || null
})

const breakOtdrSelectedReferenceHint = computed(() => {
  const ref = breakOtdrSelectedReferencePoint.value
  if (!ref) return ''
  return `Chain ${Math.round(ref.chainage_m || 0)}m dari awal kabel, offset ${Math.round(ref.offset_m || 0)}m ke jalur.`
})

function nearestBreakOtdrReferenceByChainage(chainageM, excludePointId = null) {
  const rows = breakOtdrReferencePointOptions.value || []
  if (rows.length === 0) return null

  const target = Number(chainageM || 0)
  let best = null
  let bestDelta = Number.POSITIVE_INFINITY
  rows.forEach((row) => {
    const id = Number(row?.id || 0)
    if (excludePointId && id === Number(excludePointId)) return
    const d = Math.abs(Number(row?.chainage_m || 0) - target)
    if (d < bestDelta) {
      bestDelta = d
      best = row
    }
  })

  if (!best) return null
  return {
    point: best,
    along_distance_m: Math.round(bestDelta),
  }
}

function autoPickBreakOtdrReferencePoint(config = {}) {
  const silent = config?.silent === true
  const refs = breakOtdrReferencePointOptions.value || []
  if (refs.length === 0) {
    if (!silent && toast) toast.error('Belum ada titik/JB yang dekat dengan jalur kabel ini.')
    return
  }

  const pointIdFromForm = Number(breakForm.value.point_id || 0)
  if (pointIdFromForm > 0) {
    const byPoint = refs.find((x) => Number(x?.id || 0) === pointIdFromForm)
    if (byPoint) {
      breakOtdrForm.value.reference_point_id = String(byPoint.id)
      breakOtdrForm.value.reference_mode = 'point'
      if (!silent && toast) toast.success(`Referensi OTDR di-set ke ${byPoint.name}.`)
      return
    }
  }

  const lat = Number(breakForm.value.latitude)
  const lng = Number(breakForm.value.longitude)
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    let nearest = null
    let best = Number.POSITIVE_INFINITY
    refs.forEach((x) => {
      const d = haversineMeters({ lat, lng }, { lat: Number(x?.lat), lng: Number(x?.lng) })
      if (d < best) {
        best = d
        nearest = x
      }
    })
    if (nearest) {
      breakOtdrForm.value.reference_point_id = String(nearest.id)
      breakOtdrForm.value.reference_mode = 'point'
      if (!silent && toast) toast.success(`Referensi OTDR dipilih otomatis: ${nearest.name}.`)
      return
    }
  }

  const half = Number(breakOtdrCableLengthM.value || 0) / 2
  let mid = refs[0]
  let bestDelta = Number.POSITIVE_INFINITY
  refs.forEach((x) => {
    const d = Math.abs(Number(x?.chainage_m || 0) - half)
    if (d < bestDelta) {
      bestDelta = d
      mid = x
    }
  })

  breakOtdrForm.value.reference_point_id = String(mid.id)
  breakOtdrForm.value.reference_mode = 'point'
  if (!silent && toast) toast.success(`Referensi OTDR dipilih: ${mid.name}.`)
}

function resetBreakForm() {
  breakForm.value = {
    id: null,
    cable_id: '',
    point_id: '',
    core_no: '',
    status: 'OPEN',
    severity: '',
    reported_at: '',
    repair_started_at: '',
    fixed_at: '',
    verified_at: '',
    verified_by_name: '',
    technician_name: '',
    repair_photos_text: '',
    repair_materials_text: '',
    closure_point_id: '',
    latitude: null,
    longitude: null,
    description: '',
  }
  breakOtdrForm.value = {
    reference_mode: 'endpoint',
    reference_side: 'from',
    reference_point_id: '',
    reference_direction: 'auto',
    distance_m: '',
  }
  breakErrors.value = {}
}

function openCreateBreak() {
  if (!canCreate.value) return
  resetBreakForm()
  breakModalMode.value = 'create'
  showBreakModal.value = true
  resumeModal.value = null
  stopMapMode({ reopen: false })
}

function openEditBreak(item) {
  if (!canEdit.value) return
  resetBreakForm()
  breakModalMode.value = 'edit'
  breakForm.value = {
    id: item.id,
    cable_id: item.cable_id ?? '',
    point_id: item.point_id ?? '',
    core_no: item.core_no ?? '',
    status: (item.status || 'OPEN').toString().toUpperCase(),
    severity: item.severity || '',
    reported_at: toDatetimeLocal(item.reported_at),
    repair_started_at: toDatetimeLocal(item.repair_started_at),
    fixed_at: toDatetimeLocal(item.fixed_at),
    verified_at: toDatetimeLocal(item.verified_at),
    verified_by_name: item.verified_by_name || '',
    technician_name: item.technician_name || '',
    repair_photos_text: Array.isArray(item.repair_photos) ? item.repair_photos.join('\n') : '',
    repair_materials_text: Array.isArray(item.repair_materials) ? item.repair_materials.join('\n') : '',
    closure_point_id: item.closure_point_id ?? '',
    latitude: item.latitude ?? null,
    longitude: item.longitude ?? null,
    description: item.description || '',
  }
  const pointId = Number(breakForm.value.point_id || 0)
  if (pointId > 0) {
    const refPoint = breakOtdrReferencePointOptions.value.find((x) => Number(x?.id || 0) === pointId)
    if (refPoint) {
      breakOtdrForm.value.reference_mode = 'point'
      breakOtdrForm.value.reference_point_id = String(refPoint.id)
    }
  }
  showBreakModal.value = true
  resumeModal.value = null
  stopMapMode({ reopen: false })

  const lat = Number(breakForm.value.latitude)
  const lng = Number(breakForm.value.longitude)
  if (Number.isFinite(lat) && Number.isFinite(lng)) focusOnLatLng(lat, lng, 17)
}

function pickBreakOnMap() {
  if (!showBreakModal.value) return
  if (!ensureMapReady()) return
  resumeModal.value = 'break'
  showBreakModal.value = false
  mapMode.value = 'pickBreak'
  renderDraft()
  if (toast) toast.info('Klik peta untuk memilih lokasi putus.')
}

function cablePathForBreakAssist(cableItem) {
  if (!cableItem) return []

  let path = Array.isArray(cableItem?.path)
    ? cableItem.path
      .map((p) => ({ lat: Number(p?.lat), lng: Number(p?.lng) }))
      .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng))
    : []

  const fromPt = pointLatLngById(cableItem?.from_point_id)
  const toPt = pointLatLngById(cableItem?.to_point_id)
  if (fromPt && toPt) {
    if (path.length < 2) {
      path = [fromPt, toPt]
    } else {
      path = path.slice()
      path[0] = { lat: fromPt.lat, lng: fromPt.lng }
      path[path.length - 1] = { lat: toPt.lat, lng: toPt.lng }
    }
  }

  return path
    .map((p) => ({ lat: roundCoord(Number(p?.lat)), lng: roundCoord(Number(p?.lng)) }))
    .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng))
}

function pointAlongPathByDistance(path, distanceMeters) {
  const pts = Array.isArray(path) ? path : []
  if (pts.length < 1) return null

  const target = Math.max(0, Number(distanceMeters || 0))
  if (pts.length < 2) {
    const only = pts[0]
    return { lat: roundCoord(Number(only?.lat)), lng: roundCoord(Number(only?.lng)) }
  }

  let remain = target
  for (let i = 1; i < pts.length; i += 1) {
    const a = pts[i - 1]
    const b = pts[i]
    const seg = haversineMeters(a, b)
    if (!Number.isFinite(seg) || seg <= 0) continue

    if (remain <= seg) {
      const ratio = Math.max(0, Math.min(1, remain / seg))
      const lat = roundCoord(Number(a?.lat) + (Number(b?.lat) - Number(a?.lat)) * ratio)
      const lng = roundCoord(Number(a?.lng) + (Number(b?.lng) - Number(a?.lng)) * ratio)
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null
      return { lat, lng }
    }

    remain -= seg
  }

  const last = pts[pts.length - 1]
  const lastLat = roundCoord(Number(last?.lat))
  const lastLng = roundCoord(Number(last?.lng))
  if (!Number.isFinite(lastLat) || !Number.isFinite(lastLng)) return null
  return { lat: lastLat, lng: lastLng }
}

function applyBreakLocationFromOtdr() {
  const cable = breakSelectedCable.value
  if (!cable) {
    if (toast) toast.error('Pilih kabel terlebih dahulu.')
    return
  }

  const rawDistance = Number(breakOtdrForm.value.distance_m)
  if (!Number.isFinite(rawDistance) || rawDistance < 0) {
    if (toast) toast.error('Jarak OTDR harus angka >= 0.')
    return
  }

  const path = cablePathForBreakAssist(cable)
  if (!Array.isArray(path) || path.length < 2) {
    if (toast) toast.error('Jalur kabel belum valid. Atur path kabel terlebih dulu.')
    return
  }

  const totalMeters = calcPathLengthMeters(path)
  if (!Number.isFinite(totalMeters) || totalMeters <= 0) {
    if (toast) toast.error('Panjang jalur kabel tidak valid.')
    return
  }

  const mode = String(breakOtdrForm.value.reference_mode || 'endpoint').toLowerCase() === 'point' ? 'point' : 'endpoint'
  let targetChainage = 0
  let referenceText = ''
  let autoAnalysisText = ''
  let clampedByBoundary = false

  if (mode === 'endpoint') {
    const refSide = String(breakOtdrForm.value.reference_side || 'from').toLowerCase() === 'to' ? 'to' : 'from'
    const rawChainage = refSide === 'to'
      ? totalMeters - rawDistance
      : rawDistance
    targetChainage = clampNumber(rawChainage, 0, totalMeters)
    clampedByBoundary = rawChainage !== targetChainage
    referenceText = refSide === 'to' ? breakOtdrToLabel.value : breakOtdrFromLabel.value
  } else {
    const refPoint = breakOtdrSelectedReferencePoint.value
    if (!refPoint) {
      if (toast) toast.error('Pilih titik/JB referensi terlebih dahulu.')
      return
    }

    const direction = (breakOtdrForm.value.reference_direction || 'auto').toString()
    const refChainage = Number(refPoint.chainage_m || 0)

    const candidates = []
    const pushCandidate = (rawChain, dirKey, dirLabel) => {
      const chain = clampNumber(rawChain, 0, totalMeters)
      const nearest = nearestBreakOtdrReferenceByChainage(chain, Number(refPoint.id || 0))
      candidates.push({
        dirKey,
        dirLabel,
        raw_chainage: rawChain,
        chainage: chain,
        clamped: rawChain !== chain,
        nearest,
      })
    }

    if (direction === 'toward_to') {
      pushCandidate(refChainage + rawDistance, 'toward_to', `ke arah ${breakOtdrToLabel.value}`)
    } else if (direction === 'toward_from') {
      pushCandidate(refChainage - rawDistance, 'toward_from', `ke arah ${breakOtdrFromLabel.value}`)
    } else {
      pushCandidate(refChainage + rawDistance, 'toward_to', `ke arah ${breakOtdrToLabel.value}`)
      pushCandidate(refChainage - rawDistance, 'toward_from', `ke arah ${breakOtdrFromLabel.value}`)
    }

    if (candidates.length === 0) {
      if (toast) toast.error('Arah OTDR tidak valid.')
      return
    }

    let chosen = candidates[0]
    if (direction === 'auto' && candidates.length > 1) {
      const scoreOf = (c) => {
        const near = Number(c?.nearest?.along_distance_m)
        const nearScore = Number.isFinite(near) ? near : 1e9
        const clampPenalty = c?.clamped ? 1000 : 0
        return nearScore + clampPenalty
      }
      const a = candidates[0]
      const b = candidates[1]
      chosen = scoreOf(b) < scoreOf(a) ? b : a
      if (chosen?.nearest?.point) {
        autoAnalysisText = `Auto pilih ${chosen.dirLabel}, karena lebih dekat ke titik ${chosen.nearest.point.name} (±${chosen.nearest.along_distance_m}m).`
      } else {
        autoAnalysisText = `Auto pilih ${chosen.dirLabel}.`
      }
    }

    targetChainage = Number(chosen.chainage || 0)
    clampedByBoundary = !!chosen.clamped
    referenceText = `${refPoint.name} (${chosen.dirLabel})`
    if (!autoAnalysisText && chosen?.nearest?.point) {
      autoAnalysisText = `Titik terdekat dari hasil ukur: ${chosen.nearest.point.name} (±${chosen.nearest.along_distance_m}m).`
    }
  }

  const targetMeters = Math.round(targetChainage)
  const targetPoint = pointAlongPathByDistance(path, targetChainage)
  if (!targetPoint) {
    if (toast) toast.error('Gagal menghitung lokasi titik putus.')
    return
  }

  const snap = snapLatLngToKnownPoint(Number(targetPoint.lat), Number(targetPoint.lng), SNAP_RADIUS_M)
  const finalLat = Number(snap?.lat)
  const finalLng = Number(snap?.lng)
  if (!Number.isFinite(finalLat) || !Number.isFinite(finalLng)) {
    if (toast) toast.error('Koordinat hasil OTDR tidak valid.')
    return
  }

  breakForm.value.latitude = finalLat
  breakForm.value.longitude = finalLng
  if (snap?.snapped && !breakForm.value.point_id) {
    const snapPointId = Number(snap?.point?.id || 0)
    if (snapPointId > 0) breakForm.value.point_id = snapPointId
  }

  renderDraft()

  if (clampedByBoundary && toast) {
    toast.info(`Hasil ukur melewati batas jalur (${Math.round(totalMeters)}m). Titik dipasang di batas terdekat.`)
  }
  if (toast) {
    const snapLabel = snap?.snapped ? ` (snap: ${snap?.point?.name || 'titik'})` : ''
    toast.success(`Lokasi putus di-set pada chain ${targetMeters}m dari awal kabel | referensi: ${referenceText}.${snapLabel}`)
  }
  if (autoAnalysisText && toast) {
    toast.info(autoAnalysisText)
  }
}

async function saveBreak() {
  breakProcessing.value = true
  breakErrors.value = {}

  const payload = {
    cable_id: breakForm.value.cable_id !== '' ? Number(breakForm.value.cable_id) : null,
    point_id: breakForm.value.point_id !== '' ? Number(breakForm.value.point_id) : null,
    core_no: breakForm.value.core_no !== '' ? Number(breakForm.value.core_no) : null,
    status: (breakForm.value.status || 'OPEN').toString().toUpperCase(),
    severity: breakForm.value.severity || null,
    reported_at: breakForm.value.reported_at || null,
    repair_started_at: breakForm.value.repair_started_at || null,
    fixed_at: breakForm.value.fixed_at || null,
    verified_at: breakForm.value.verified_at || null,
    verified_by_name: breakForm.value.verified_by_name || null,
    technician_name: breakForm.value.technician_name || null,
    repair_photos: String(breakForm.value.repair_photos_text || '')
      .split('\n')
      .map((s) => s.trim())
      .filter((s) => s.length > 0),
    repair_materials: String(breakForm.value.repair_materials_text || '')
      .split('\n')
      .map((s) => s.trim())
      .filter((s) => s.length > 0),
    closure_point_id: breakForm.value.closure_point_id !== '' ? Number(breakForm.value.closure_point_id) : null,
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
    await deleteFiberItemByTab('breaks', item)
    if (toast) toast.success('Data putus dihapus.')
    clearMapSelectionAfterDelete('breaks', item)
    removeBulkItemSelection('breaks', item)
    await loadAll()
  } catch (e) {
    console.error('Fiber: deleteBreak failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus data putus.')
  }
}

const breakFixing = ref({})
const breakStatusUpdating = ref({})

function isFixingBreak(id) {
  const k = String(Number(id || 0))
  return !!breakFixing.value?.[k]
}

function isUpdatingBreakStatus(id) {
  const k = String(Number(id || 0))
  return !!breakStatusUpdating.value?.[k]
}

async function markBreakInProgress(item) {
  if (!canEdit.value) return
  const status = String(item?.status || 'OPEN').toUpperCase()
  if (!['OPEN', 'IN_PROGRESS'].includes(status)) return

  const k = String(Number(item?.id || 0))
  breakStatusUpdating.value = { ...(breakStatusUpdating.value || {}), [k]: true }
  try {
    const res = await requestJson(`/api/v1/fiber/breaks/${item.id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        status: 'IN_PROGRESS',
        repair_started_at: item?.repair_started_at || null,
      }),
    })
    if (!res.ok) throw new Error(res.data?.message || `Gagal update status (HTTP ${res.status}).`)
    await loadAll()
    if (toast) toast.success('Status putus menjadi IN_PROGRESS.')
  } catch (e) {
    console.error('Fiber: markBreakInProgress failed', e)
    if (toast) toast.error(e?.message || 'Gagal update status putus.')
  } finally {
    const next = { ...(breakStatusUpdating.value || {}) }
    delete next[k]
    breakStatusUpdating.value = next
  }
}

async function verifyBreak(item) {
  if (!canEdit.value) return
  const status = String(item?.status || '').toUpperCase()
  if (!['FIXED', 'VERIFIED'].includes(status)) {
    if (toast) toast.info('Verifikasi hanya untuk status FIXED.')
    return
  }

  const ok = confirm('Verifikasi data putus ini? Status akan menjadi VERIFIED.')
  if (!ok) return

  const k = String(Number(item?.id || 0))
  breakStatusUpdating.value = { ...(breakStatusUpdating.value || {}), [k]: true }

  try {
    const verifier = page.props.auth?.user?.name || page.props.auth?.user?.username || ''
    const res = await requestJson(`/api/v1/fiber/breaks/${item.id}/verify`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        verified_by_name: verifier || null,
      }),
    })
    if (!res.ok) throw new Error(res.data?.message || `Gagal verifikasi (HTTP ${res.status}).`)
    await loadAll()
    if (toast) toast.success('Data putus terverifikasi.')
  } catch (e) {
    console.error('Fiber: verifyBreak failed', e)
    if (toast) toast.error(e?.message || 'Gagal verifikasi data putus.')
  } finally {
    const next = { ...(breakStatusUpdating.value || {}) }
    delete next[k]
    breakStatusUpdating.value = next
  }
}

async function fixBreakWithJoint(item) {
  if (!canEdit.value) return
  if (!canCreate.value) return

  const status = String(item?.status || 'OPEN').toUpperCase()
  if (!['OPEN', 'IN_PROGRESS'].includes(status)) {
    if (toast) toast.info('Status putus sudah bukan OPEN/IN_PROGRESS.')
    return
  }

  const ok = confirm('Selesaikan data putus ini? Sistem akan membuat titik JOINT_CLOSURE di lokasi putus dan mengubah status menjadi FIXED.')
  if (!ok) return

  const k = String(Number(item?.id || 0))
  breakFixing.value = { ...(breakFixing.value || {}), [k]: true }

  try {
    const cName = cableNameById(item?.cable_id) || (item?.cable_id ? `Kabel #${item.cable_id}` : '')
    const jointName = (cName ? `JC - ${cName}` : `Joint Closure - Break #${item?.id || ''}`).slice(0, 160)

    const res = await requestJson(`/api/v1/fiber/breaks/${item.id}/fix`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        joint_name: jointName,
        joint_type: 'JOINT_CLOSURE',
        joint_notes: `Auto dari data putus #${item?.id || ''}`,
      }),
    })

    if (!res.ok) throw new Error(res.data?.message || `Gagal menyelesaikan putus (HTTP ${res.status}).`)

    await loadAll()

    const jp = res.data?.data?.joint_point || null
    const lat = Number(jp?.latitude)
    const lng = Number(jp?.longitude)
    if (Number.isFinite(lat) && Number.isFinite(lng)) focusOnLatLng(lat, lng, 18)

    if (toast) toast.success('Data putus diselesaikan. Titik Joint Closure dibuat.')
  } catch (e) {
    console.error('Fiber: fixBreakWithJoint failed', e)
    if (toast) toast.error(e?.message || 'Gagal menyelesaikan putus.')
  } finally {
    const next = { ...(breakFixing.value || {}) }
    delete next[k]
    breakFixing.value = next
  }
}

const CABLE_SPLIT_POINT_MAX_OFFSET_M = 80
const showCableSplitModal = ref(false)
const cableSplitProcessing = ref(false)
const cableSplitError = ref('')
const cableSplitForm = ref({
  point_id: '',
  source_cable_id: '',
  open_target: 'PORT',
})

const cableSplitPoint = computed(() => {
  const id = Number(cableSplitForm.value.point_id || 0)
  if (!id) return null
  return pointById.value.get(id) || null
})

const cableSplitOpenTargetLabel = computed(() => {
  const t = String(cableSplitForm.value.open_target || 'PORT').toUpperCase()
  return t === 'LINK' ? 'Sambungan' : 'Port ODP'
})

const cableSplitCandidateCables = computed(() => {
  const point = cableSplitPoint.value
  const pointId = Number(point?.id || 0)
  const pointLat = Number(point?.latitude)
  const pointLng = Number(point?.longitude)
  if (!pointId || !Number.isFinite(pointLat) || !Number.isFinite(pointLng)) return []

  const out = []
  ;(cables.value || []).forEach((c) => {
    const cableId = Number(c?.id || 0)
    if (!cableId) return

    const path = cablePathForBreakAssist(c)
    if (!Array.isArray(path) || path.length < 2) return

    const projection = projectPointToPathWithChainage(path, { lat: pointLat, lng: pointLng })
    if (!projection) return

    const isFromEndpoint = Number(c?.from_point_id || 0) === pointId
    const isToEndpoint = Number(c?.to_point_id || 0) === pointId
    const isEndpoint = isFromEndpoint || isToEndpoint
    const offsetM = Number(projection.offset_m || 0)
    if (!isEndpoint && offsetM > CABLE_SPLIT_POINT_MAX_OFFSET_M) return

    const endpointSide = isFromEndpoint ? 'from' : (isToEndpoint ? 'to' : '')
    const chainM = Math.round(Number(projection.chainage_m || 0))
    const endpointTag = isEndpoint ? ` | endpoint-${endpointSide}` : ''
    const label = `${c?.name || ('Kabel #' + cableId)} | chain ${chainM}m | off ${Math.round(offsetM)}m${endpointTag}`

    out.push({
      id: cableId,
      cable: c,
      projection,
      is_endpoint: isEndpoint,
      endpoint_side: endpointSide,
      label,
    })
  })

  out.sort((a, b) => {
    const ae = a?.is_endpoint ? 0 : 1
    const be = b?.is_endpoint ? 0 : 1
    if (ae !== be) return ae - be
    const ad = Number(a?.projection?.offset_m || 0)
    const bd = Number(b?.projection?.offset_m || 0)
    if (ad !== bd) return ad - bd
    return Number(a?.id || 0) - Number(b?.id || 0)
  })

  return out
})

const cableSplitSelectedCandidate = computed(() => {
  const id = Number(cableSplitForm.value.source_cable_id || 0)
  if (!id) return null
  return cableSplitCandidateCables.value.find((x) => Number(x?.id || 0) === id) || null
})

function resetCableSplitForm() {
  cableSplitForm.value = {
    point_id: '',
    source_cable_id: '',
    open_target: 'PORT',
  }
  cableSplitError.value = ''
}

function openCableSplitFlow(pointItem, target = 'PORT') {
  if (!canCreate.value || !canEdit.value) return
  const pointId = Number(pointItem?.id || 0)
  if (!pointId) return

  resetCableSplitForm()
  cableSplitForm.value.point_id = String(pointId)
  cableSplitForm.value.open_target = String(target || 'PORT').toUpperCase() === 'LINK' ? 'LINK' : 'PORT'

  const selected = Number(selectedCableId.value || 0)
  const candidates = cableSplitCandidateCables.value || []
  if (selected > 0 && candidates.some((x) => Number(x?.id || 0) === selected)) {
    cableSplitForm.value.source_cable_id = String(selected)
  } else if (candidates.length > 0) {
    cableSplitForm.value.source_cable_id = String(candidates[0].id)
  }

  showCableSplitModal.value = true
}

function isSamePathPoint(a, b, epsMeters = 0.35) {
  const aLat = Number(a?.lat)
  const aLng = Number(a?.lng)
  const bLat = Number(b?.lat)
  const bLng = Number(b?.lng)
  if (!Number.isFinite(aLat) || !Number.isFinite(aLng) || !Number.isFinite(bLat) || !Number.isFinite(bLng)) return false
  return haversineMeters({ lat: aLat, lng: aLng }, { lat: bLat, lng: bLng }) <= Number(epsMeters || 0.35)
}

function splitPathAtProjection(path, projection) {
  const pts = (Array.isArray(path) ? path : [])
    .map((p) => ({ lat: Number(p?.lat), lng: Number(p?.lng) }))
    .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng))
  if (pts.length < 2) return null

  const segmentIndex = Number(projection?.segment_index)
  const segmentRatio = Number(projection?.segment_ratio)
  if (!Number.isFinite(segmentIndex) || !Number.isFinite(segmentRatio)) return null
  if (segmentIndex < 0 || segmentIndex >= (pts.length - 1)) return null

  const splitPoint = {
    lat: roundCoord(Number(projection?.lat)),
    lng: roundCoord(Number(projection?.lng)),
  }
  if (!Number.isFinite(splitPoint.lat) || !Number.isFinite(splitPoint.lng)) return null

  const eps = 1e-6
  let first = []
  let second = []

  if (segmentRatio <= eps) {
    first = pts.slice(0, segmentIndex + 1)
    second = pts.slice(segmentIndex)
  } else if (segmentRatio >= (1 - eps)) {
    first = pts.slice(0, segmentIndex + 2)
    second = pts.slice(segmentIndex + 1)
  } else {
    first = pts.slice(0, segmentIndex + 1)
    if (!isSamePathPoint(first[first.length - 1], splitPoint)) first.push(splitPoint)
    second = pts.slice(segmentIndex + 1)
    if (!isSamePathPoint(second[0], splitPoint)) second.unshift(splitPoint)
  }

  if (first.length < 2 || second.length < 2) return null
  return { first, second, split_point: splitPoint }
}

function splitSecondCableName(baseName, pointName) {
  const src = (baseName || '').toString().trim() || 'Kabel'
  const p = (pointName || '').toString().trim()
  const raw = p ? `${src} - ${p} (Seg2)` : `${src} (Seg2)`
  return raw.slice(0, 160)
}

function splitSecondCableCode(baseCode) {
  const src = (baseCode || '').toString().trim()
  if (!src) return null
  return `${src}-S2`.slice(0, 64)
}

function openPortAfterCableSplit(pointId, cableId) {
  resetPortForm()
  portModalMode.value = 'create'
  portForm.value.point_id = String(pointId)
  portForm.value.port_type = 'ODP_OUT'
  portForm.value.port_label = 'OUT 1'
  if (Number(cableId || 0) > 0) {
    portForm.value.cable_id = String(cableId)
  }
  showPortModal.value = true
  activeTab.value = 'ports'
}

function openLinkAfterCableSplit(pointId, fromCableId, toCableId = null) {
  resetLinkForm()
  linkModalMode.value = 'create'
  linkForm.value.point_id = String(pointId)
  linkForm.value.link_type = 'SPLICE'
  if (Number(fromCableId || 0) > 0) linkForm.value.from_cable_id = String(fromCableId)
  if (Number(toCableId || 0) > 0) linkForm.value.to_cable_id = String(toCableId)
  if (Number(fromCableId || 0) > 0) linkForm.value.from_core_no = '1'
  if (Number(toCableId || 0) > 0) linkForm.value.to_core_no = '1'
  showLinkModal.value = true
  activeTab.value = 'links'
}

async function splitCableAtPointAndOpenTarget() {
  if (cableSplitProcessing.value) return
  cableSplitError.value = ''

  const point = cableSplitPoint.value
  const pointId = Number(point?.id || 0)
  const candidate = cableSplitSelectedCandidate.value
  const target = String(cableSplitForm.value.open_target || 'PORT').toUpperCase() === 'LINK' ? 'LINK' : 'PORT'
  if (!pointId) {
    cableSplitError.value = 'Titik tidak valid.'
    return
  }
  if (!candidate?.cable) {
    cableSplitError.value = 'Pilih kabel sumber terlebih dahulu.'
    return
  }

  const sourceCable = candidate.cable
  const sourceCableId = Number(sourceCable?.id || 0)
  const fromPointId = Number(sourceCable?.from_point_id || 0)
  const toPointId = Number(sourceCable?.to_point_id || 0)
  if (!sourceCableId || !fromPointId || !toPointId) {
    cableSplitError.value = 'Data kabel tidak lengkap.'
    return
  }

  if (candidate.is_endpoint) {
    showCableSplitModal.value = false
    if (toast) toast.info('Titik sudah menjadi endpoint kabel. Split tidak diperlukan.')
    if (target === 'LINK') {
      openLinkAfterCableSplit(pointId, sourceCableId, null)
    } else {
      openPortAfterCableSplit(pointId, sourceCableId)
    }
    return
  }

  const projection = candidate.projection
  const offsetM = Number(projection?.offset_m || 0)
  if (!Number.isFinite(offsetM) || offsetM > CABLE_SPLIT_POINT_MAX_OFFSET_M) {
    cableSplitError.value = `Titik terlalu jauh dari jalur kabel (>${CABLE_SPLIT_POINT_MAX_OFFSET_M}m).`
    return
  }

  const path = cablePathForBreakAssist(sourceCable)
  const splitPath = splitPathAtProjection(path, projection)
  if (!splitPath) {
    cableSplitError.value = 'Gagal memotong path kabel di titik terpilih.'
    return
  }

  const firstLen = Math.round(calcPathLengthMeters(splitPath.first))
  const secondLen = Math.round(calcPathLengthMeters(splitPath.second))
  if (firstLen < 1 || secondLen < 1) {
    cableSplitError.value = 'Titik terlalu dekat ujung kabel, split dibatalkan.'
    return
  }

  const ok = confirm(`Split kabel "${sourceCable?.name || ('#' + sourceCableId)}" di titik "${point?.name || ('#' + pointId)}" lalu buka form ${target === 'LINK' ? 'Sambungan' : 'Port ODP'}?`)
  if (!ok) return

  cableSplitProcessing.value = true
  let createdCableId = 0

  try {
    const basePayload = {
      name: sourceCable?.name || `Kabel #${sourceCableId}`,
      code: sourceCable?.code || null,
      cable_type: sourceCable?.cable_type || null,
      core_count: sourceCable?.core_count !== '' && sourceCable?.core_count !== null && sourceCable?.core_count !== undefined ? Number(sourceCable.core_count) : null,
      map_color: sourceCable?.map_color || null,
      notes: sourceCable?.notes || null,
    }

    const createPayload = {
      ...basePayload,
      name: splitSecondCableName(sourceCable?.name, point?.name),
      code: splitSecondCableCode(sourceCable?.code),
      from_point_id: pointId,
      to_point_id: toPointId,
      path: splitPath.second,
      length_m: secondLen,
    }

    const createRes = await requestJson('/api/v1/fiber/cables', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(createPayload),
    })
    if (!createRes.ok) throw new Error(createRes.data?.message || `Gagal membuat segmen kabel baru (HTTP ${createRes.status}).`)
    createdCableId = Number(createRes.data?.data?.id || 0)
    if (!createdCableId) throw new Error('Gagal membaca ID kabel hasil split.')

    const updatePayload = {
      ...basePayload,
      from_point_id: fromPointId,
      to_point_id: pointId,
      path: splitPath.first,
      length_m: firstLen,
    }

    const updateRes = await requestJson(`/api/v1/fiber/cables/${sourceCableId}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(updatePayload),
    })
    if (!updateRes.ok) {
      try {
        await requestJson(`/api/v1/fiber/cables/${createdCableId}`, { method: 'DELETE' })
      } catch {}
      throw new Error(updateRes.data?.message || `Gagal update kabel sumber saat split (HTTP ${updateRes.status}).`)
    }

    await loadAll()
    showCableSplitModal.value = false

    if (target === 'LINK') {
      openLinkAfterCableSplit(pointId, sourceCableId, createdCableId)
    } else {
      openPortAfterCableSplit(pointId, createdCableId)
    }
    if (toast) toast.success('Split kabel berhasil. Form lanjutan dibuka.')
  } catch (e) {
    console.error('Fiber: splitCableAtPointAndOpenTarget failed', e)
    cableSplitError.value = e?.message || 'Gagal split kabel.'
    if (toast) toast.error(cableSplitError.value)
  } finally {
    cableSplitProcessing.value = false
  }
}

/* Ports (OLT PON / ODP OUT) */
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
    await deleteFiberItemByTab('ports', item)
    if (toast) toast.success('Port dihapus.')
    removeBulkItemSelection('ports', item)
    await loadAll()
  } catch (e) {
    console.error('Fiber: deletePort failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus port.')
  }
}

/* Links (splice/patch/split) */
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

const linkPointId = computed(() => Number(linkForm.value.point_id || 0))

const linkSelectableCables = computed(() => {
  const rows = Array.isArray(cables.value) ? cables.value : []
  const pointId = linkPointId.value
  if (!pointId) return rows
  const filtered = rows.filter((c) => cableAttachedToPointId(c?.id, pointId))
  return filtered
})

const LINK_CORE_OPTION_FALLBACK_COUNT = 144

function linkCoreSelectableCountForCable(cableId) {
  const cid = Number(cableId || 0)
  if (!cid) return 0
  const cable = cableById.value.get(cid)
  const cc = Math.round(Number(cable?.core_count || 0))
  if (Number.isFinite(cc) && cc > 0) return Math.min(9999, cc)
  return LINK_CORE_OPTION_FALLBACK_COUNT
}

function linkCoreColorOptionsForCable(cableId) {
  const count = linkCoreSelectableCountForCable(cableId)
  if (count < 1) return []
  const out = []
  for (let coreNo = 1; coreNo <= count; coreNo += 1) {
    const meta = coreColorMeta(coreNo)
    out.push({
      value: String(coreNo),
      label: meta?.label || '-',
    })
  }
  return out
}

function linkCoreOptionExists(cableId, coreNo) {
  const core = (coreNo || '').toString().trim()
  if (!core) return false
  return linkCoreColorOptionsForCable(cableId).some((x) => x.value === core)
}

function linkOutputCoreOptions(cableId) {
  return linkCoreColorOptionsForCable(cableId)
}

const linkFromCoreOptions = computed(() => linkCoreColorOptionsForCable(linkForm.value.from_cable_id))
const linkToCoreOptions = computed(() => linkCoreColorOptionsForCable(linkForm.value.to_cable_id))

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

/* Panel: client-side filter/search */
const panelSearchNorm = computed(() => (panelSearch.value || '').toString().toLowerCase().trim())

const searchPlaceholder = computed(() => {
  const tab = String(activeTab.value || 'cables')
  if (tab === 'cables') return 'Cari kabel (nama/kode/tipe/titik)...'
  if (tab === 'points') return 'Cari titik (nama/tipe)...'
  if (tab === 'ports') return 'Cari port (label/tipe/kabel/titik)...'
  if (tab === 'links') return 'Cari sambungan (titik/kabel/core)...'
  if (tab === 'cores') return 'Cari core...'
  if (tab === 'breaks') return 'Cari putus (kabel/titik/status)...'
  return 'Cari...'
})

function includesQ(hay, q) {
  const s = (hay || '').toString().toLowerCase()
  return s.includes(q)
}

const filteredCables = computed(() => {
  const rows = Array.isArray(cables.value) ? cables.value : []
  const q = panelSearchNorm.value
  if (!q) return rows
  return rows.filter((c) => {
    const fromName = pointNameById(c?.from_point_id) || ''
    const toName = pointNameById(c?.to_point_id) || ''
    return (
      includesQ(c?.name, q)
      || includesQ(c?.code, q)
      || includesQ(c?.cable_type, q)
      || includesQ(fromName, q)
      || includesQ(toName, q)
    )
  })
})

const filteredPoints = computed(() => {
  const rows = Array.isArray(points.value) ? points.value : []
  const q = panelSearchNorm.value
  if (!q) return rows
  return rows.filter((p) => {
    return (
      includesQ(p?.name, q)
      || includesQ(p?.point_type, q)
      || includesQ(p?.address, q)
      || includesQ(p?.notes, q)
    )
  })
})

const filteredPorts = computed(() => {
  const rows = Array.isArray(ports.value) ? ports.value : []
  const q = panelSearchNorm.value
  if (!q) return rows
  return rows.filter((p) => {
    const ptName = pointNameById(p?.point_id) || ''
    const cbName = cableNameById(p?.cable_id) || ''
    return (
      includesQ(p?.port_label, q)
      || includesQ(p?.port_type, q)
      || includesQ(ptName, q)
      || includesQ(cbName, q)
      || includesQ(String(p?.core_no ?? ''), q)
    )
  })
})

const filteredLinkItems = computed(() => {
  const rows = Array.isArray(linkItems.value) ? linkItems.value : []
  const q = panelSearchNorm.value
  if (!q) return rows
  return rows.filter((ln) => {
    const ptName = pointNameById(ln?.point_id) || ''
    const fromCable = cableNameById(ln?.from_cable_id) || ''
    const toCable = cableNameById(ln?.to_cable_id) || ''
    const lt = (ln?.link_type || '').toString().toUpperCase()

    let outText = ''
    if (ln?.kind === 'SPLIT_GROUP') {
      outText = (ln?.outputs || [])
        .map((o) => `${cableNameById(o?.to_cable_id) || ''}:${o?.to_core_no ?? ''}`)
        .join(' ')
    }

    return (
      includesQ(lt, q)
      || includesQ(ptName, q)
      || includesQ(fromCable, q)
      || includesQ(toCable, q)
      || includesQ(ln?.split_group, q)
      || includesQ(String(ln?.from_core_no ?? ''), q)
      || includesQ(String(ln?.to_core_no ?? ''), q)
      || includesQ(outText, q)
    )
  })
})

const filteredBreaks = computed(() => {
  const rows = Array.isArray(breaks.value) ? breaks.value : []
  const q = panelSearchNorm.value
  const st = String(breakStatusFilter.value || 'ALL').toUpperCase()

  return rows.filter((b) => {
    const status = String(b?.status || 'OPEN').toUpperCase()
    if (st !== 'ALL' && status !== st) return false
    if (!q) return true

    const cableName = cableNameById(b?.cable_id) || ''
    const ptName = pointNameById(b?.point_id) || ''
    return (
      includesQ(status, q)
      || includesQ(b?.severity, q)
      || includesQ(cableName, q)
      || includesQ(ptName, q)
      || includesQ(b?.description, q)
    )
  })
})

function isBulkDeleteTab(tab) {
  return BULK_DELETE_TABS.includes(String(tab || ''))
}

function bulkRowsForTab(tab, { filtered = false } = {}) {
  const t = String(tab || '')
  if (t === 'cables') return filtered ? filteredCables.value : cables.value
  if (t === 'points') return filtered ? filteredPoints.value : points.value
  if (t === 'ports') return filtered ? filteredPorts.value : ports.value
  if (t === 'links') return filtered ? filteredLinkItems.value : linkItems.value
  if (t === 'breaks') return filtered ? filteredBreaks.value : breaks.value
  return []
}

function bulkKeyForItem(tab, item) {
  const t = String(tab || '')
  if (t === 'links') {
    if (item?.kind === 'SPLIT_GROUP') {
      const pointId = Number(item?.point_id || 0)
      const splitGroup = (item?.split_group || '').toString().trim()
      if (!splitGroup) return ''
      return `g:${pointId}:${splitGroup}`
    }
    const linkId = Number(item?.id || 0)
    return linkId > 0 ? `l:${linkId}` : ''
  }

  const id = Number(item?.id || 0)
  return id > 0 ? String(id) : ''
}

function bulkLabelForTab(tab) {
  const t = String(tab || '')
  if (t === 'cables') return 'kabel'
  if (t === 'points') return 'titik'
  if (t === 'ports') return 'port'
  if (t === 'links') return 'sambungan'
  if (t === 'breaks') return 'data putus'
  return 'data'
}

function bulkSelectedKeys(tab) {
  const t = String(tab || '')
  if (!isBulkDeleteTab(t)) return []
  return Array.isArray(bulkSelected.value?.[t]) ? bulkSelected.value[t] : []
}

function setBulkSelectedKeys(tab, keys) {
  const t = String(tab || '')
  if (!isBulkDeleteTab(t)) return
  const normalized = Array.from(
    new Set(
      (Array.isArray(keys) ? keys : [])
        .map((k) => (k || '').toString())
        .filter((k) => k !== '')
    )
  )
  bulkSelected.value = {
    ...bulkSelected.value,
    [t]: normalized,
  }
}

function bulkVisibleKeys(tab) {
  return bulkRowsForTab(tab, { filtered: true })
    .map((row) => bulkKeyForItem(tab, row))
    .filter((k) => k)
}

function bulkAllKeys(tab) {
  return bulkRowsForTab(tab)
    .map((row) => bulkKeyForItem(tab, row))
    .filter((k) => k)
}

function bulkVisibleCount(tab) {
  return bulkVisibleKeys(tab).length
}

function bulkSelectedCount(tab) {
  const selected = new Set(bulkSelectedKeys(tab))
  let count = 0
  bulkAllKeys(tab).forEach((k) => {
    if (selected.has(k)) count += 1
  })
  return count
}

function bulkVisibleSelectedCount(tab) {
  const selected = new Set(bulkSelectedKeys(tab))
  let count = 0
  bulkVisibleKeys(tab).forEach((k) => {
    if (selected.has(k)) count += 1
  })
  return count
}

function isBulkAllVisibleSelected(tab) {
  const visible = bulkVisibleKeys(tab)
  if (visible.length === 0) return false
  const selected = new Set(bulkSelectedKeys(tab))
  return visible.every((k) => selected.has(k))
}

function isBulkItemSelected(tab, item) {
  const key = bulkKeyForItem(tab, item)
  if (!key) return false
  return bulkSelectedKeys(tab).includes(key)
}

function toggleBulkItem(tab, item) {
  const key = bulkKeyForItem(tab, item)
  if (!key) return
  const current = new Set(bulkSelectedKeys(tab))
  if (current.has(key)) current.delete(key)
  else current.add(key)
  setBulkSelectedKeys(tab, Array.from(current))
}

function toggleBulkSelectAllVisible(tab) {
  const visible = bulkVisibleKeys(tab)
  if (visible.length === 0) return

  const selected = new Set(bulkSelectedKeys(tab))
  const allSelected = visible.every((k) => selected.has(k))
  if (allSelected) {
    visible.forEach((k) => selected.delete(k))
  } else {
    visible.forEach((k) => selected.add(k))
  }
  setBulkSelectedKeys(tab, Array.from(selected))
}

function clearBulkSelection(tab) {
  setBulkSelectedKeys(tab, [])
}

function removeBulkItemSelection(tab, item) {
  const key = bulkKeyForItem(tab, item)
  if (!key) return
  const selected = new Set(bulkSelectedKeys(tab))
  if (!selected.has(key)) return
  selected.delete(key)
  setBulkSelectedKeys(tab, Array.from(selected))
}

function pruneBulkSelection(tab) {
  const valid = new Set(bulkAllKeys(tab))
  const next = bulkSelectedKeys(tab).filter((k) => valid.has(k))
  if (next.length !== bulkSelectedKeys(tab).length) {
    setBulkSelectedKeys(tab, next)
  }
}

function selectedBulkItems(tab) {
  pruneBulkSelection(tab)
  const selected = new Set(bulkSelectedKeys(tab))
  return bulkRowsForTab(tab).filter((row) => {
    const key = bulkKeyForItem(tab, row)
    return key && selected.has(key)
  })
}

function clearMapSelectionAfterDelete(tab, item) {
  const id = Number(item?.id || 0)
  if (!id) return
  if (tab === 'cables' && Number(selectedCableId.value || 0) === id) selectedCableId.value = null
  if (tab === 'points' && Number(selectedPointId.value || 0) === id) selectedPointId.value = null
  if (tab === 'breaks' && Number(selectedBreakId.value || 0) === id) selectedBreakId.value = null
}

async function deleteFiberItemByTab(tab, item) {
  const t = String(tab || '')
  const id = Number(item?.id || 0)
  if (!id || !isBulkDeleteTab(t)) throw new Error('ID data tidak valid.')

  let url = ''
  if (t === 'cables') url = `/api/v1/fiber/cables/${id}`
  else if (t === 'points') url = `/api/v1/fiber/points/${id}`
  else if (t === 'ports') url = `/api/v1/fiber/ports/${id}`
  else if (t === 'links') url = `/api/v1/fiber/links/${id}`
  else if (t === 'breaks') url = `/api/v1/fiber/breaks/${id}`
  else throw new Error('Tipe data tidak didukung untuk hapus.')

  const res = await requestJson(url, { method: 'DELETE' })
  if (!res.ok) {
    throw new Error(res.data?.message || `Gagal hapus ${bulkLabelForTab(t)} (HTTP ${res.status}).`)
  }
}

async function deleteBulkSelected(tab) {
  if (!canDelete.value || bulkDeleting.value) return
  const t = String(tab || '')
  if (!isBulkDeleteTab(t)) return

  const items = selectedBulkItems(t)
  if (items.length === 0) {
    if (toast) toast.info('Belum ada data dipilih.')
    return
  }

  const ok = confirm(`Hapus ${items.length} ${bulkLabelForTab(t)} terpilih?`)
  if (!ok) return

  bulkDeleting.value = true
  let successCount = 0
  let failCount = 0
  let firstError = ''

  try {
    for (const item of items) {
      try {
        await deleteFiberItemByTab(t, item)
        successCount += 1
        clearMapSelectionAfterDelete(t, item)
        removeBulkItemSelection(t, item)
      } catch (e) {
        failCount += 1
        if (!firstError) firstError = e?.message || 'Gagal menghapus data.'
      }
    }

    await loadAll()
  } finally {
    bulkDeleting.value = false
    pruneBulkSelection(t)
  }

  if (successCount > 0 && failCount === 0) {
    if (toast) toast.success(`Berhasil hapus ${successCount} ${bulkLabelForTab(t)}.`)
    return
  }
  if (successCount > 0) {
    if (toast) toast.info(`Hapus massal selesai: ${successCount} berhasil, ${failCount} gagal.`)
    if (toast && firstError) toast.error(firstError)
    return
  }
  if (toast) toast.error(firstError || `Gagal hapus ${bulkLabelForTab(t)}.`)
}

/* Core occupancy */
const coreCableId = ref('')
const coreLoading = ref(false)
const coreSaving = ref(false)
const coreError = ref('')
const coreSummary = ref({ FREE: 0, USED: 0, RESERVED: 0, BROKEN: 0 })
const coreRows = ref([])
const coreReservedDraft = ref([])
const selectedCoreNo = ref(null)

const CORE_COLOR_SEQUENCE = [
  { name: 'Biru', hex: '#2563eb' },
  { name: 'Oranye', hex: '#f97316' },
  { name: 'Hijau', hex: '#16a34a' },
  { name: 'Coklat', hex: '#92400e' },
  { name: 'Abu', hex: '#6b7280' },
  { name: 'Putih', hex: '#f8fafc' },
  { name: 'Merah', hex: '#dc2626' },
  { name: 'Hitam', hex: '#111827' },
  { name: 'Kuning', hex: '#facc15' },
  { name: 'Ungu', hex: '#7c3aed' },
  { name: 'Pink', hex: '#ec4899' },
  { name: 'Aqua', hex: '#06b6d4' },
]

function coreColorMeta(coreNo) {
  const n = Number(coreNo || 0)
  if (!Number.isFinite(n) || n <= 0) return { name: '-', hex: '#9ca3af', cycle: 0, label: '-' }
  const idx = (n - 1) % CORE_COLOR_SEQUENCE.length
  const cycle = Math.floor((n - 1) / CORE_COLOR_SEQUENCE.length) + 1
  const base = CORE_COLOR_SEQUENCE[idx] || { name: '-', hex: '#9ca3af' }
  return {
    ...base,
    cycle,
    label: cycle > 1 ? `${base.name}-${cycle}` : base.name,
  }
}

function coreColorName(coreNo) {
  return coreColorMeta(coreNo).label
}

function coreColorHex(coreNo) {
  return coreColorMeta(coreNo).hex
}

const coreCable = computed(() => {
  const id = Number(coreCableId.value || 0)
  if (!id) return null
  return cableById.value.get(id) || null
})

const selectedCoreRow = computed(() => {
  const n = Number(selectedCoreNo.value || 0)
  if (!n) return null
  return (coreRows.value || []).find((r) => Number(r?.core_no) === n) || null
})

function coreStatusClass(status) {
  const s = String(status || '').toUpperCase()
  if (s === 'BROKEN') return 'border-red-300 bg-red-50 text-red-700 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-200'
  if (s === 'USED') return 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-900/40 dark:bg-blue-900/20 dark:text-blue-200'
  if (s === 'RESERVED') return 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-200'
  return 'border-gray-200 bg-white text-gray-700 dark:border-white/10 dark:bg-dark-800 dark:text-gray-200'
}

function isCoreReservedDraft(coreNo) {
  return (coreReservedDraft.value || []).includes(Number(coreNo))
}

function toggleCoreReservedDraft(coreNo) {
  const n = Number(coreNo || 0)
  if (!n) return
  const curr = new Set((coreReservedDraft.value || []).map((x) => Number(x)))
  if (curr.has(n)) curr.delete(n)
  else curr.add(n)
  coreReservedDraft.value = Array.from(curr).filter((x) => Number.isFinite(x) && x > 0).sort((a, b) => a - b)
}

async function loadCoreOccupancy(cableId) {
  const id = Number(cableId || 0)
  if (!id) return
  coreCableId.value = String(id)
  coreLoading.value = true
  coreError.value = ''
  selectedCoreNo.value = null
  try {
    const res = await requestJson(`/api/v1/fiber/cables/${id}/core-occupancy`)
    if (!res.ok) throw new Error(res.data?.message || `Gagal memuat core occupancy (HTTP ${res.status}).`)

    coreSummary.value = res.data?.data?.summary || { FREE: 0, USED: 0, RESERVED: 0, BROKEN: 0 }
    coreRows.value = Array.isArray(res.data?.data?.cores) ? res.data.data.cores : []
    coreReservedDraft.value = Array.isArray(res.data?.data?.cable?.reserved_cores) ? res.data.data.cable.reserved_cores.map((x) => Number(x)) : []

    if (coreRows.value.length > 0) selectedCoreNo.value = Number(coreRows.value[0]?.core_no || 0)
  } catch (e) {
    console.error('Fiber: loadCoreOccupancy failed', e)
    coreError.value = e?.message || 'Gagal memuat data core.'
  } finally {
    coreLoading.value = false
  }
}

function openCoreTab(cableItem = null) {
  const id = Number(cableItem?.id || selectedCableId.value || 0)
  if (!id) {
    if (toast) toast.info('Pilih kabel dulu untuk melihat core.')
    return
  }
  selectedCableId.value = id
  activeTab.value = 'cores'
  loadCoreOccupancy(id)
}

async function saveCoreReservations() {
  const id = Number(coreCableId.value || 0)
  if (!id) return
  coreSaving.value = true
  coreError.value = ''
  try {
    const res = await requestJson(`/api/v1/fiber/cables/${id}/core-reservations`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        reserved_cores: (coreReservedDraft.value || []).map((x) => Number(x)).filter((x) => Number.isFinite(x) && x > 0),
      }),
    })
    if (!res.ok) throw new Error(res.data?.message || `Gagal menyimpan reservasi core (HTTP ${res.status}).`)
    await loadCoreOccupancy(id)
    await loadAll()
    if (toast) toast.success('Reservasi core tersimpan.')
  } catch (e) {
    console.error('Fiber: saveCoreReservations failed', e)
    coreError.value = e?.message || 'Gagal menyimpan reservasi core.'
    if (toast) toast.error(coreError.value)
  } finally {
    coreSaving.value = false
  }
}

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
      point_id: item.point_id !== null && item.point_id !== undefined ? String(item.point_id) : '',
      link_type: 'SPLIT',
      from_cable_id: item.from_cable_id !== null && item.from_cable_id !== undefined ? String(item.from_cable_id) : '',
      from_core_no: item.from_core_no !== null && item.from_core_no !== undefined ? String(item.from_core_no) : '',
      to_cable_id: '',
      to_core_no: '',
      split_group: item.split_group || '',
      outputs: (item.outputs || []).map((o) => ({
        to_cable_id: o?.to_cable_id !== null && o?.to_cable_id !== undefined ? String(o.to_cable_id) : '',
        to_core_no: o?.to_core_no !== null && o?.to_core_no !== undefined ? String(o.to_core_no) : '',
      })),
      loss_db: item.loss_db ?? '',
      notes: item.notes || '',
    }
    splitOutputCount.value = (linkForm.value.outputs || []).length || 8
    showLinkModal.value = true
    return
  }

  linkForm.value = {
    id: item.id,
    point_id: item.point_id !== null && item.point_id !== undefined ? String(item.point_id) : '',
    link_type: (item.link_type || 'SPLICE').toString().toUpperCase(),
    from_cable_id: item.from_cable_id !== null && item.from_cable_id !== undefined ? String(item.from_cable_id) : '',
    from_core_no: item.from_core_no !== null && item.from_core_no !== undefined ? String(item.from_core_no) : '',
    to_cable_id: item.to_cable_id !== null && item.to_cable_id !== undefined ? String(item.to_cable_id) : '',
    to_core_no: item.to_core_no !== null && item.to_core_no !== undefined ? String(item.to_core_no) : '',
    split_group: item.split_group || '',
    outputs: [],
    loss_db: item.loss_db ?? '',
    notes: item.notes || '',
  }
  showLinkModal.value = true
}

async function saveLink() {
  if (linkProcessing.value) return
  linkProcessing.value = true
  linkErrors.value = {}

  const linkType = (linkForm.value.link_type || 'SPLICE').toString().toUpperCase()
  const pointId = linkForm.value.point_id !== '' ? Number(linkForm.value.point_id) : 0
  const fromCableId = linkForm.value.from_cable_id !== '' ? Number(linkForm.value.from_cable_id) : 0
  const fromCoreNo = linkForm.value.from_core_no !== '' ? Number(linkForm.value.from_core_no) : 0
  const toCableId = linkForm.value.to_cable_id !== '' ? Number(linkForm.value.to_cable_id) : 0
  const toCoreNo = linkForm.value.to_core_no !== '' ? Number(linkForm.value.to_core_no) : 0
  const splitGroupRaw = (linkForm.value.split_group || '').toString().trim()
  const lossDb = linkForm.value.loss_db !== '' ? Number(linkForm.value.loss_db) : null
  const notes = (linkForm.value.notes || '').toString().trim()

  const localErrors = {}
  if (pointId <= 0) localErrors.point_id = ['Titik wajib dipilih.']
  if (fromCableId <= 0) localErrors.from_cable_id = ['From cable wajib dipilih.']
  if (!Number.isFinite(fromCoreNo) || fromCoreNo <= 0) localErrors.from_core_no = ['From core wajib diisi (>= 1).']

  if (pointId > 0 && fromCableId > 0 && !cableAttachedToPointId(fromCableId, pointId)) {
    localErrors.from_cable_id = ['From cable tidak terhubung ke titik terpilih.']
  }
  if (linkForm.value.loss_db !== '' && (!Number.isFinite(lossDb) || lossDb < 0 || lossDb > 99)) {
    localErrors.loss_db = ['Loss harus angka 0 - 99 dB.']
  }

  const payload = {
    point_id: pointId > 0 ? pointId : null,
    link_type: linkType,
    from_cable_id: fromCableId > 0 ? fromCableId : null,
    from_core_no: Number.isFinite(fromCoreNo) && fromCoreNo > 0 ? fromCoreNo : null,
    split_group: splitGroupRaw || null,
    loss_db: linkForm.value.loss_db === '' ? null : lossDb,
    notes: notes || null,
  }

  if (linkType === 'SPLIT') {
    const outs = Array.isArray(linkForm.value.outputs) ? linkForm.value.outputs : []
    const normalizedOutputs = []
    const seenOutputs = new Set()

    for (let i = 0; i < outs.length; i += 1) {
      const row = outs[i] || {}
      const outCableId = row.to_cable_id !== '' ? Number(row.to_cable_id) : 0
      const outCoreNo = row.to_core_no !== '' ? Number(row.to_core_no) : 0
      const hasAny = outCableId > 0 || outCoreNo > 0
      if (!hasAny) continue

      if (outCableId <= 0 || !Number.isFinite(outCoreNo) || outCoreNo <= 0) {
        localErrors.outputs = [`Output baris ${i + 1} belum lengkap.`]
        continue
      }
      if (pointId > 0 && !cableAttachedToPointId(outCableId, pointId)) {
        localErrors.outputs = [`Output baris ${i + 1}: kabel tidak terhubung ke titik terpilih.`]
        continue
      }
      if (fromCableId > 0 && Number.isFinite(fromCoreNo) && fromCoreNo > 0 && outCableId === fromCableId && outCoreNo === fromCoreNo) {
        localErrors.outputs = [`Output baris ${i + 1}: tidak boleh sama dengan input core.`]
        continue
      }

      const key = `${outCableId}:${outCoreNo}`
      if (seenOutputs.has(key)) {
        localErrors.outputs = [`Output baris ${i + 1}: duplikat core output.`]
        continue
      }
      seenOutputs.add(key)
      normalizedOutputs.push({
        to_cable_id: outCableId,
        to_core_no: outCoreNo,
      })
    }

    if (normalizedOutputs.length < 1) {
      localErrors.outputs = localErrors.outputs || ['Isi minimal 1 output splitter.']
    }

    payload.outputs = normalizedOutputs
  } else {
    if (toCableId <= 0) localErrors.to_cable_id = ['To cable wajib dipilih.']
    if (!Number.isFinite(toCoreNo) || toCoreNo <= 0) localErrors.to_core_no = ['To core wajib diisi (>= 1).']
    if (pointId > 0 && toCableId > 0 && !cableAttachedToPointId(toCableId, pointId)) {
      localErrors.to_cable_id = ['To cable tidak terhubung ke titik terpilih.']
    }
    if (
      fromCableId > 0 && toCableId > 0
      && Number.isFinite(fromCoreNo) && fromCoreNo > 0
      && Number.isFinite(toCoreNo) && toCoreNo > 0
      && fromCableId === toCableId && fromCoreNo === toCoreNo
    ) {
      localErrors.to_core_no = ['From dan To core tidak boleh sama.']
    }

    payload.to_cable_id = toCableId > 0 ? toCableId : null
    payload.to_core_no = Number.isFinite(toCoreNo) && toCoreNo > 0 ? toCoreNo : null
    payload.split_group = null
  }

  if (Object.keys(localErrors).length > 0) {
    linkErrors.value = localErrors
    if (toast) toast.error('Periksa field sambungan terlebih dahulu.')
    linkProcessing.value = false
    return
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
    await deleteFiberItemByTab('links', item)
    if (toast) toast.success('Sambungan dihapus.')
    removeBulkItemSelection('links', item)
    await loadAll()
  } catch (e) {
    console.error('Fiber: deleteLink failed', e)
    if (toast) toast.error(e?.message || 'Gagal hapus sambungan.')
  }
}

/* Trace (OLT PON -> ODP OUT) */
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

watch([cables, points, ports, links, breaks], () => {
  BULK_DELETE_TABS.forEach((tab) => pruneBulkSelection(tab))
})

watch(() => breakForm.value.cable_id, () => {
  const currentRefId = Number(breakOtdrForm.value.reference_point_id || 0)
  if (currentRefId > 0) {
    const exists = breakOtdrReferencePointOptions.value.some((x) => Number(x?.id || 0) === currentRefId)
    if (!exists) breakOtdrForm.value.reference_point_id = ''
  }
  if (breakOtdrForm.value.reference_mode === 'point' && !breakOtdrForm.value.reference_point_id) {
    autoPickBreakOtdrReferencePoint({ silent: true })
  }
})

watch(breakOtdrReferencePointOptions, (rows) => {
  const currentRefId = Number(breakOtdrForm.value.reference_point_id || 0)
  if (!currentRefId) return
  const exists = (rows || []).some((x) => Number(x?.id || 0) === currentRefId)
  if (!exists) breakOtdrForm.value.reference_point_id = ''
})

watch(cableSplitCandidateCables, (rows) => {
  if (!showCableSplitModal.value) return
  const current = Number(cableSplitForm.value.source_cable_id || 0)
  const exists = (rows || []).some((x) => Number(x?.id || 0) === current)
  if (exists) return
  cableSplitForm.value.source_cable_id = rows && rows.length > 0 ? String(rows[0].id) : ''
})

watch(() => linkForm.value.point_id, (pointIdRaw) => {
  if (!showLinkModal.value) return
  const pointId = Number(pointIdRaw || 0)
  if (!pointId) return

  if (linkForm.value.from_cable_id && !cableAttachedToPointId(linkForm.value.from_cable_id, pointId)) {
    linkForm.value.from_cable_id = ''
    linkForm.value.from_core_no = ''
  }
  if (linkForm.value.to_cable_id && !cableAttachedToPointId(linkForm.value.to_cable_id, pointId)) {
    linkForm.value.to_cable_id = ''
    linkForm.value.to_core_no = ''
  }

  if (Array.isArray(linkForm.value.outputs) && linkForm.value.outputs.length > 0) {
    linkForm.value.outputs = linkForm.value.outputs.map((o) => {
      const outCableId = o?.to_cable_id
      if (!outCableId || cableAttachedToPointId(outCableId, pointId)) return o
      return { ...(o || {}), to_cable_id: '', to_core_no: '' }
    })
  }
})

watch(() => linkForm.value.from_cable_id, (cableIdRaw) => {
  if (!showLinkModal.value) return
  const cableId = Number(cableIdRaw || 0)
  if (!cableId) {
    linkForm.value.from_core_no = ''
    return
  }
  if (!linkCoreOptionExists(cableId, linkForm.value.from_core_no)) {
    linkForm.value.from_core_no = ''
  }
})

watch(() => linkForm.value.to_cable_id, (cableIdRaw) => {
  if (!showLinkModal.value) return
  const cableId = Number(cableIdRaw || 0)
  if (!cableId) {
    linkForm.value.to_core_no = ''
    return
  }
  if (!linkCoreOptionExists(cableId, linkForm.value.to_core_no)) {
    linkForm.value.to_core_no = ''
  }
})

watch(() => linkForm.value.outputs, (rows) => {
  if (!showLinkModal.value) return
  if (!Array.isArray(rows) || rows.length < 1) return

  let changed = false
  const next = rows.map((row) => {
    const outCableId = Number(row?.to_cable_id || 0)
    const coreRaw = (row?.to_core_no || '').toString()
    if (!outCableId && coreRaw) {
      changed = true
      return { ...(row || {}), to_core_no: '' }
    }
    if (outCableId > 0 && coreRaw && !linkCoreOptionExists(outCableId, coreRaw)) {
      changed = true
      return { ...(row || {}), to_core_no: '' }
    }
    return row
  })

  if (changed) linkForm.value.outputs = next
}, { deep: true })

watch(() => linkForm.value.link_type, (typeRaw) => {
  if (!showLinkModal.value) return
  const type = (typeRaw || 'SPLICE').toString().toUpperCase()
  if (type === 'SPLIT') {
    if (!Array.isArray(linkForm.value.outputs) || linkForm.value.outputs.length === 0) {
      ensureSplitOutputs(splitOutputCount.value || 8)
    }
    return
  }
  linkForm.value.split_group = ''
})

watch(selectedCableId, (id) => {
  if (activeTab.value === 'cores' && Number(id || 0) > 0) {
    loadCoreOccupancy(id)
  }
})

watch(activeTab, (tab) => {
  // Avoid "stuck filters" when switching between modes.
  panelSearch.value = ''
  if (tab === 'cores') {
    const id = Number(coreCableId.value || selectedCableId.value || 0)
    if (id > 0) loadCoreOccupancy(id)
  }
})

watch(mapMode, (v) => {
  // When user is interacting with the map (pick/draw/edit), keep the map unobstructed on mobile.
  if (v) panelOpen.value = false
})

watch(panelCollapsed, () => {
  // Map container width changes on desktop when panel is collapsed/expanded.
  nextTick(() => requestMapResize())
})

watch(showCableModal, (open) => {
  if (!open) {
    cableAutoRouteArmed.value = false
    return
  }
  if (cableModalMode.value === 'create') {
    cableAutoRouteArmed.value = true
  }
})

watch([() => cableForm.value.from_point_id, () => cableForm.value.to_point_id], async () => {
  if (!showCableModal.value) return
  if (cableModalMode.value !== 'create') return
  if (!cableAutoRouteArmed.value) return

  const fromId = Number(cableForm.value.from_point_id || 0)
  const toId = Number(cableForm.value.to_point_id || 0)
  if (!fromId || !toId || fromId === toId) return

  const hasExisting = Array.isArray(cableForm.value.path) && cableForm.value.path.length >= 2
  if (hasExisting) {
    cableAutoRouteArmed.value = false
    return
  }

  const ok = await autoCablePathFromEndpoints({ force: false })
  if (ok) cableAutoRouteArmed.value = false
})

watch(() => cableForm.value.path, () => {
  if (!cableLengthAuto.value) return
  const m = Math.round(calcPathLengthMeters(cableForm.value.path))
  if (Number.isFinite(m) && m > 0) {
    cableForm.value.length_m = m
  }
}, { deep: true })

watch(mapStyle, () => {
  try { localStorage.setItem('fiber_map_style', mapStyle.value) } catch {}
  applyMapStyle()
})

watch(drawFollowRoad, () => {
  try { localStorage.setItem('fiber_follow_road', drawFollowRoad.value ? '1' : '0') } catch {}
})

watch(panelSnap, () => {
  try { localStorage.setItem('fiber_panel_snap', panelSnap.value) } catch {}
})

watch(legendCollapsed, () => {
  try { localStorage.setItem('fiber_legend_collapsed', legendCollapsed.value ? '1' : '0') } catch {}
})

watch(mapCleanMode, () => {
  try { localStorage.setItem('fiber_map_clean', mapCleanMode.value ? '1' : '0') } catch {}
})

onMounted(() => {
  try {
    const saved = localStorage.getItem('fiber_map_style')
    if (saved && MAP_STYLES[saved]) mapStyle.value = saved
  } catch {}

  try {
    const v = localStorage.getItem('fiber_follow_road')
    if (v === '0') drawFollowRoad.value = false
    if (v === '1') drawFollowRoad.value = true
  } catch {}

  try {
    const v = localStorage.getItem('fiber_panel_snap')
    if (['peek', 'half', 'full'].includes(String(v))) panelSnap.value = String(v)
  } catch {}

  try {
    const v = localStorage.getItem('fiber_map_clean')
    if (v === '0') mapCleanMode.value = false
    if (v === '1') mapCleanMode.value = true
  } catch {}

  try {
    const v = localStorage.getItem('fiber_legend_collapsed')
    if (v === '1') legendCollapsed.value = true
    if (v === '0') legendCollapsed.value = false
  } catch {}

  if (mapCleanMode.value) legendCollapsed.value = true

  mapLoadError.value = ''

  const key = (props.googleMapsApiKey || '').toString().trim()
  if (!key) {
    mapLoadError.value = 'Google Maps API key belum di-set. Buka Pengaturan -> System -> Google Maps.'
    loadAll()
    return
  }

  loadGoogleMaps(key).then(() => {
    nextTick(() => {
      initMap()
      requestMapResize()
      loadAll()
    })
  }).catch((e) => {
    console.error('Fiber: Google Maps load failed', e)
    mapLoadError.value = e?.message || 'Gagal memuat Google Maps.'
    loadAll()
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

      <div v-else class="relative">
        <div v-if="panelOpen" class="fixed inset-0 z-[9000] bg-black/35 backdrop-blur-sm lg:hidden" @click="panelOpen=false"></div>

        <div
          v-if="!panelOpen && !mapMode && !showCableModal && !showPointModal && !showBreakModal"
          class="fixed left-3 right-3 bottom-3 z-[8900] lg:hidden"
        >
          <div class="rounded-2xl border border-white/20 bg-white/90 dark:bg-dark-900/90 backdrop-blur shadow-xl p-2">
            <div class="grid grid-cols-4 gap-2">
              <button class="btn btn-primary !py-2 !text-[11px]" @click="panelOpen=true">Data</button>
              <button class="btn btn-secondary !py-2 !text-[11px]" :disabled="!canCreate" @click="openCreateCable">+ Kabel</button>
              <button class="btn btn-secondary !py-2 !text-[11px]" :disabled="!canCreate" @click="openCreatePoint">+ Titik</button>
              <button class="btn btn-secondary !py-2 !text-[11px]" :disabled="!canCreate" @click="openCreateBreak">+ Putus</button>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <!-- Map -->
        <div
          class="bg-white dark:bg-dark-800 rounded-xl shadow-card border border-gray-100 dark:border-dark-700 overflow-hidden flex flex-col lg:h-[calc(100vh-13rem)]"
          :class="panelCollapsed ? 'lg:col-span-12' : 'lg:col-span-8'"
        >
          <div class="relative flex-1 min-h-[420px] lg:min-h-0">
            <div ref="mapContainer" class="absolute inset-0 w-full h-full bg-slate-100 dark:bg-slate-900"></div>

            <div
              class="absolute top-3 left-3 z-20 flex items-center rounded-xl border shadow-lg backdrop-blur transition-all"
              :class="mapCleanMode
                ? 'gap-1.5 bg-white/80 dark:bg-dark-900/75 border-gray-200/90 dark:border-white/10 p-1.5'
                : 'flex-wrap gap-2 bg-white/92 dark:bg-dark-900/82 border-gray-200 dark:border-white/10 p-2'"
            >
              <button
                class="rounded-lg font-semibold border transition"
                :class="[
                  mapCleanMode ? 'px-2 py-1 text-[11px]' : 'px-3 py-1.5 text-xs',
                  showCables
                    ? 'bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-900/20 dark:border-blue-900/30 dark:text-blue-200'
                    : 'bg-white/70 dark:bg-dark-800/70 border-gray-200/70 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'
                ]"
                @click="showCables=!showCables"
              >
                {{ mapCleanMode ? `K:${summary.cables}` : `Kabel (${summary.cables})` }}
              </button>
              <button
                class="rounded-lg font-semibold border transition"
                :class="[
                  mapCleanMode ? 'px-2 py-1 text-[11px]' : 'px-3 py-1.5 text-xs',
                  showPoints
                    ? 'bg-teal-50 border-teal-200 text-teal-700 dark:bg-teal-900/20 dark:border-teal-900/30 dark:text-teal-200'
                    : 'bg-white/70 dark:bg-dark-800/70 border-gray-200/70 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'
                ]"
                @click="showPoints=!showPoints"
              >
                {{ mapCleanMode ? `T:${summary.points}` : `Titik (${summary.points})` }}
              </button>
              <button
                class="rounded-lg font-semibold border transition"
                :class="[
                  mapCleanMode ? 'px-2 py-1 text-[11px]' : 'px-3 py-1.5 text-xs',
                  showBreaks
                    ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/10 dark:border-red-900/30 dark:text-red-300'
                    : 'bg-white/70 dark:bg-dark-800/70 border-gray-200/70 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'
                ]"
                @click="showBreaks=!showBreaks"
              >
                {{ mapCleanMode ? `P:${summary.breaks_open}` : `Putus (${summary.breaks_open})` }}
              </button>
            </div>

            <div
              v-if="!mapCleanMode"
              class="absolute top-16 left-3 z-20 w-64 max-w-[calc(100%-1.5rem)] bg-white/90 dark:bg-dark-900/80 backdrop-blur border border-gray-200 dark:border-white/10 rounded-xl shadow-lg overflow-hidden"
            >
              <div class="flex items-center justify-between px-3 py-2 border-b border-gray-200/70 dark:border-white/10">
                <div class="text-[11px] font-semibold text-gray-700 dark:text-gray-200 uppercase tracking-wide">Legend Peta</div>
                <button class="text-[11px] font-medium text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white" @click="legendCollapsed=!legendCollapsed">
                  {{ legendCollapsed ? 'Tampilkan' : 'Ringkas' }}
                </button>
              </div>
              <div v-if="!legendCollapsed" class="p-3 space-y-2">
                <div class="grid grid-cols-2 gap-2 text-[11px] text-gray-600 dark:text-gray-300">
                  <div class="flex items-center gap-2">
                    <span class="w-5 h-1 rounded-full bg-blue-600"></span>
                    <span>Kabel</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full border-2 border-teal-700 bg-teal-400"></span>
                    <span>Titik</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full border-2 border-red-700 bg-red-500"></span>
                    <span>Putus OPEN</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full border-2 border-amber-700 bg-amber-500"></span>
                    <span>IN PROGRESS</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full border-2 border-emerald-700 bg-emerald-500"></span>
                    <span>FIXED</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="w-5 h-1 rounded-full bg-sky-700"></span>
                    <span>Trace aktif</span>
                  </div>
                </div>
                <div class="text-[11px] text-gray-500 dark:text-gray-400">
                  Klik objek di peta untuk membuka detail cepat.
                </div>
              </div>
            </div>

            <div
              class="absolute top-3 right-3 z-20 flex items-center gap-2 rounded-xl border shadow-lg backdrop-blur transition-all"
              :class="mapCleanMode
                ? 'bg-white/80 dark:bg-dark-900/75 border-gray-200/90 dark:border-white/10 p-1.5'
                : 'bg-white/90 dark:bg-dark-900/80 border-gray-200 dark:border-white/10 p-2'"
            >
              <select v-model="mapStyle" class="input !text-xs !py-1.5 min-w-[104px]">
                <option value="roadmap">Roadmap</option>
                <option value="hybrid">Hybrid</option>
                <option value="satellite">Satelit</option>
              </select>
              <button class="btn btn-secondary !py-1.5 !text-xs" @click="toggleMapCleanMode">
                {{ mapCleanMode ? 'Detail' : 'Bersih' }}
              </button>
              <button @click="fitToVisible" class="btn btn-secondary !py-1.5 !text-xs">Fit</button>
              <button @click="loadAll" class="btn btn-secondary !py-1.5 !text-xs" :disabled="loading">Refresh</button>
              <button class="btn btn-primary !py-1.5 !text-xs lg:hidden" @click="panelOpen=!panelOpen">
                {{ panelOpen ? 'Tutup' : 'Data' }}
              </button>
              <button class="btn btn-secondary !py-1.5 !text-xs hidden lg:inline-flex" @click="panelCollapsed=!panelCollapsed">
                {{ panelCollapsed ? 'Panel' : 'Full Map' }}
              </button>
            </div>

            <div
              v-if="selectedDrawerItem && (!panelOpen || !isMobileViewport())"
              class="absolute z-20 left-3 right-3 sm:left-auto sm:w-80 bg-white/95 dark:bg-dark-900/90 backdrop-blur rounded-2xl border shadow-xl p-3"
              :class="[selectedCardClass, mapMode ? 'bottom-36' : 'bottom-3']"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="inline-flex px-2 py-1 rounded-full text-[10px] font-bold tracking-wide" :class="selectedBadgeClass">{{ selectedDrawerItem.badge }}</div>
                  <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white truncate">{{ selectedDrawerItem.title }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ selectedDrawerItem.subtitle }}</div>
                </div>
                <button class="text-xs text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="clearSelection">Tutup</button>
              </div>

              <div class="mt-3 grid grid-cols-1 gap-1.5">
                <div
                  v-for="(row, idx) in selectedDrawerItem.rows"
                  :key="idx"
                  class="flex items-start justify-between gap-2 text-xs"
                >
                  <span class="text-gray-500 dark:text-gray-400">{{ row.label }}</span>
                  <span class="text-right font-medium text-gray-800 dark:text-gray-200">{{ row.value }}</span>
                </div>
              </div>

              <div v-if="selectedDrawerItem.note" class="mt-2 text-xs text-gray-600 dark:text-gray-300 line-clamp-2">
                {{ selectedDrawerItem.note }}
              </div>

              <div class="mt-3 flex flex-wrap items-center gap-2">
                <button class="btn btn-secondary !py-1.5 !text-xs" @click="focusSelectedItem">Zoom</button>
                <button v-if="selectedDrawerItem.type === 'cable'" class="btn btn-secondary !py-1.5 !text-xs" @click="openCoreTab(selectedCable)">Core</button>
                <button class="btn btn-secondary !py-1.5 !text-xs lg:hidden" @click="openSelectedInPanel">Panel</button>
                <button v-if="canEdit" class="btn btn-primary !py-1.5 !text-xs" @click="editSelectedItem">Edit</button>
              </div>
            </div>

            <div
              v-if="mapLoadError"
              class="absolute inset-0 z-40 flex items-center justify-center p-6 bg-white/85 dark:bg-dark-900/70 backdrop-blur"
            >
              <div class="max-w-md text-center">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Peta tidak dapat dimuat</div>
                <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ mapLoadError }}</div>
              </div>
            </div>

            <div v-if="mapMode" class="absolute bottom-3 left-3 right-3 lg:right-auto lg:max-w-md bg-white/90 dark:bg-dark-900/80 backdrop-blur border border-gray-200 dark:border-white/10 rounded-xl p-3 text-xs text-gray-700 dark:text-gray-200 shadow-lg">
              <div class="font-semibold">
                Mode peta aktif:
                <span class="uppercase">
                  {{ mapMode === 'drawCable' ? 'gambar kabel' : (mapMode === 'editCable' ? 'edit jalur' : (mapMode === 'pickPoint' ? 'pilih titik' : 'pilih putus')) }}
                </span>
              </div>
              <div v-if="mapMode === 'editCable'" class="text-gray-600 dark:text-gray-300 mt-1">
                Geser titik/segmen untuk merapikan jalur, lalu klik tombol Selesai.
              </div>
              <div v-else-if="mapMode === 'drawCable'" class="text-gray-600 dark:text-gray-300 mt-1">
                Klik peta untuk menambah titik. Mode: <span class="font-semibold">{{ drawFollowRoad ? 'Ikuti jalan' : 'Garis lurus' }}</span>.
                <span v-if="drawBusy" class="ml-1 text-blue-700 dark:text-blue-300">Menghitung rute...</span>
              </div>
              <div v-else class="text-gray-600 dark:text-gray-300 mt-1">
                Klik peta untuk memilih lokasi, lalu klik tombol Selesai.
              </div>

              <label v-if="mapMode === 'drawCable'" class="mt-2 flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200">
                <input type="checkbox" v-model="drawFollowRoad" :disabled="drawBusy" />
                Ikuti jalan
              </label>

              <div class="mt-2 flex items-center gap-2">
                <button class="btn btn-secondary !py-1 !text-xs" @click="stopMapMode" :disabled="mapMode === 'drawCable' && drawBusy">Selesai</button>
                <button v-if="mapMode === 'drawCable'" class="btn btn-secondary !py-1 !text-xs" @click="undoCablePoint" :disabled="drawBusy">Undo</button>
                <button v-if="mapMode === 'drawCable'" class="btn btn-secondary !py-1 !text-xs" @click="clearCablePath" :disabled="drawBusy">Clear</button>
                <button v-if="mapMode === 'editCable'" class="btn btn-secondary !py-1 !text-xs" @click="clearCablePath">Clear</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Panel -->
        <div
          class="bg-white dark:bg-dark-800 shadow-card border border-gray-100 dark:border-dark-700 overflow-hidden flex flex-col lg:h-[calc(100vh-13rem)] fixed inset-x-0 bottom-0 z-[9001] lg:static lg:z-auto rounded-t-2xl lg:rounded-xl lg:max-h-none transform transition-transform duration-200 ease-out lg:transform-none"
          :class="[
            panelCollapsed ? 'lg:hidden' : 'lg:col-span-4',
            panelOpen ? 'translate-y-0 pointer-events-auto' : 'translate-y-full pointer-events-none lg:pointer-events-auto lg:translate-y-0',
            panelSnapClass,
          ]"
        >
          <div class="lg:hidden px-4 pt-2 pb-3 border-b border-gray-100 dark:border-dark-700">
            <div class="mx-auto h-1 w-10 rounded-full bg-gray-200 dark:bg-white/10"></div>
            <div class="mt-2 flex items-center justify-between gap-2">
              <div class="text-[11px] text-gray-500 dark:text-gray-400">
                Panel: <span class="font-semibold text-gray-700 dark:text-gray-200 uppercase">{{ panelSnap === 'peek' ? 'Ringkas' : (panelSnap === 'half' ? 'Sedang' : 'Penuh') }}</span>
              </div>
              <button class="btn btn-secondary !py-1 !text-[11px]" @click="cyclePanelSnap">Ubah Mode</button>
            </div>
            <div class="mt-2 flex items-center gap-2">
              <select v-model="activeTab" class="input !py-2 !text-xs flex-1">
                <option value="cables">Kabel ({{ cables.length }})</option>
                <option value="points">Titik ({{ points.length }})</option>
                <option value="ports">Port ({{ ports.length }})</option>
                <option value="links">Sambungan ({{ links.length }})</option>
                <option value="cores">Core</option>
                <option value="trace">Trace</option>
                <option value="breaks">Putus ({{ breaks.length }})</option>
              </select>
              <button class="btn btn-secondary !py-1.5 !text-xs" @click="panelOpen=false">Tutup</button>
            </div>
          </div>

          <div class="hidden lg:flex items-center gap-1 border-b border-gray-100 dark:border-dark-700 overflow-x-auto">
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
              @click="openCoreTab()"
              class="px-3 py-3 text-xs font-semibold whitespace-nowrap"
              :class="activeTab==='cores' ? 'text-emerald-700 dark:text-emerald-300 bg-emerald-50/60 dark:bg-emerald-900/10' : 'text-gray-600 dark:text-gray-300'"
            >
              Core
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

          <div class="p-4 overflow-y-auto flex-1 min-h-0">
            <div v-if="loading" class="text-sm text-gray-500 dark:text-gray-400">Memuat...</div>
            <div v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-300">{{ errorMessage }}</div>

            <!-- Cables tab -->
            <div v-else-if="activeTab==='cables'" class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Daftar Kabel</div>
                <button v-if="canCreate" class="btn btn-primary !py-2 !text-xs" @click="openCreateCable">Tambah</button>
              </div>

              <div class="flex items-center gap-2">
                <input v-model="panelSearch" class="input w-full !py-2 !text-xs" :placeholder="searchPlaceholder" />
                <button v-if="panelSearch" class="btn btn-secondary !py-2 !text-xs" @click="panelSearch=''">Clear</button>
              </div>
              <div v-if="panelSearch" class="text-[11px] text-gray-500 dark:text-gray-400">
                Hasil: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ filteredCables.length }}</span> / {{ cables.length }}
              </div>
              <div v-if="canDelete" class="flex flex-wrap items-center gap-2">
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkVisibleCount('cables') === 0"
                  @click="toggleBulkSelectAllVisible('cables')"
                >
                  {{ isBulkAllVisibleSelected('cables') ? 'Batal Pilih Semua' : `Pilih Semua (${bulkVisibleCount('cables')})` }}
                </button>
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('cables') === 0"
                  @click="clearBulkSelection('cables')"
                >
                  Clear Pilihan
                </button>
                <button
                  class="btn btn-danger !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('cables') === 0"
                  @click="deleteBulkSelected('cables')"
                >
                  {{ bulkDeleting ? 'Menghapus...' : `Hapus Dipilih (${bulkSelectedCount('cables')})` }}
                </button>
              </div>
              <div v-if="canDelete && bulkSelectedCount('cables') > 0" class="text-[11px] text-gray-500 dark:text-gray-400">
                Terpilih: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ bulkSelectedCount('cables') }}</span>
                <span class="mx-1">|</span>
                Terlihat: {{ bulkVisibleSelectedCount('cables') }}/{{ bulkVisibleCount('cables') }}
              </div>

              <div v-if="filteredCables.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                {{ cables.length === 0 ? 'Belum ada data kabel.' : 'Tidak ada hasil.' }}
              </div>
              <div v-else class="space-y-2 pr-1">
                <div
                  v-for="c in filteredCables"
                  :key="c.id"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-blue-200 dark:hover:border-blue-900/40 cursor-pointer"
                  :class="Number(selectedCableId) === Number(c.id) ? 'bg-blue-50/60 dark:bg-blue-900/10' : 'bg-white dark:bg-dark-800'"
                  @click="selectCable(c, { panel: 'close' })"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex items-start gap-2">
                      <label v-if="canDelete" class="mt-0.5 inline-flex items-center shrink-0">
                        <input
                          type="checkbox"
                          :checked="isBulkItemSelected('cables', c)"
                          @click.stop
                          @change.stop="toggleBulkItem('cables', c)"
                        />
                      </label>
                      <div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ c.name }}</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <span v-if="c.cable_type">{{ c.cable_type }}</span>
                        <span v-if="c.core_count" class="ml-2">| {{ c.core_count }} core</span>
                        <span v-if="c.length_m" class="ml-2">| {{ formatLength(c.length_m) }}</span>
                      </div>
                      <div v-if="c.from_point_id || c.to_point_id" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        <div>Dari: <span class="font-medium text-gray-700 dark:text-gray-200">{{ pointNameById(c.from_point_id) || '-' }}</span></div>
                        <div>Ke: <span class="font-medium text-gray-700 dark:text-gray-200">{{ pointNameById(c.to_point_id) || '-' }}</span></div>
                      </div>
                    </div>
                    </div>

                    <div class="flex items-center gap-1 shrink-0">
                      <button class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openCoreTab(c)">Core</button>
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

              <div class="flex items-center gap-2">
                <input v-model="panelSearch" class="input w-full !py-2 !text-xs" :placeholder="searchPlaceholder" />
                <button v-if="panelSearch" class="btn btn-secondary !py-2 !text-xs" @click="panelSearch=''">Clear</button>
              </div>
              <div v-if="panelSearch" class="text-[11px] text-gray-500 dark:text-gray-400">
                Hasil: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ filteredPoints.length }}</span> / {{ points.length }}
              </div>
              <div v-if="canDelete" class="flex flex-wrap items-center gap-2">
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkVisibleCount('points') === 0"
                  @click="toggleBulkSelectAllVisible('points')"
                >
                  {{ isBulkAllVisibleSelected('points') ? 'Batal Pilih Semua' : `Pilih Semua (${bulkVisibleCount('points')})` }}
                </button>
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('points') === 0"
                  @click="clearBulkSelection('points')"
                >
                  Clear Pilihan
                </button>
                <button
                  class="btn btn-danger !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('points') === 0"
                  @click="deleteBulkSelected('points')"
                >
                  {{ bulkDeleting ? 'Menghapus...' : `Hapus Dipilih (${bulkSelectedCount('points')})` }}
                </button>
              </div>
              <div v-if="canDelete && bulkSelectedCount('points') > 0" class="text-[11px] text-gray-500 dark:text-gray-400">
                Terpilih: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ bulkSelectedCount('points') }}</span>
                <span class="mx-1">|</span>
                Terlihat: {{ bulkVisibleSelectedCount('points') }}/{{ bulkVisibleCount('points') }}
              </div>

              <div v-if="filteredPoints.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                {{ points.length === 0 ? 'Belum ada data titik.' : 'Tidak ada hasil.' }}
              </div>
              <div v-else class="space-y-2 pr-1">
                <div
                  v-for="p in filteredPoints"
                  :key="p.id"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-teal-200 dark:hover:border-teal-900/40 cursor-pointer"
                  :class="Number(selectedPointId) === Number(p.id) ? 'bg-teal-50/60 dark:bg-teal-900/10' : 'bg-white dark:bg-dark-800'"
                  @click="selectPoint(p, { panel: 'close' })"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex items-start gap-2">
                      <label v-if="canDelete" class="mt-0.5 inline-flex items-center shrink-0">
                        <input
                          type="checkbox"
                          :checked="isBulkItemSelected('points', p)"
                          @click.stop
                          @change.stop="toggleBulkItem('points', p)"
                        />
                      </label>
                      <div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ p.name }}</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <span v-if="p.point_type">{{ p.point_type }}</span>
                        <span v-if="p.latitude && p.longitude" class="ml-2">| {{ p.latitude }}, {{ p.longitude }}</span>
                      </div>
                    </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <button v-if="canCreate && canEdit" class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openCableSplitFlow(p, 'PORT')">Split+ODP</button>
                      <button v-if="canCreate && canEdit" class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openCableSplitFlow(p, 'LINK')">Split+Link</button>
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

              <div class="flex items-center gap-2">
                <input v-model="panelSearch" class="input w-full !py-2 !text-xs" :placeholder="searchPlaceholder" />
                <button v-if="panelSearch" class="btn btn-secondary !py-2 !text-xs" @click="panelSearch=''">Clear</button>
              </div>
              <div v-if="panelSearch" class="text-[11px] text-gray-500 dark:text-gray-400">
                Hasil: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ filteredPorts.length }}</span> / {{ ports.length }}
              </div>
              <div v-if="canDelete" class="flex flex-wrap items-center gap-2">
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkVisibleCount('ports') === 0"
                  @click="toggleBulkSelectAllVisible('ports')"
                >
                  {{ isBulkAllVisibleSelected('ports') ? 'Batal Pilih Semua' : `Pilih Semua (${bulkVisibleCount('ports')})` }}
                </button>
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('ports') === 0"
                  @click="clearBulkSelection('ports')"
                >
                  Clear Pilihan
                </button>
                <button
                  class="btn btn-danger !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('ports') === 0"
                  @click="deleteBulkSelected('ports')"
                >
                  {{ bulkDeleting ? 'Menghapus...' : `Hapus Dipilih (${bulkSelectedCount('ports')})` }}
                </button>
              </div>
              <div v-if="canDelete && bulkSelectedCount('ports') > 0" class="text-[11px] text-gray-500 dark:text-gray-400">
                Terpilih: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ bulkSelectedCount('ports') }}</span>
                <span class="mx-1">|</span>
                Terlihat: {{ bulkVisibleSelectedCount('ports') }}/{{ bulkVisibleCount('ports') }}
              </div>

              <div v-if="filteredPorts.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                {{ ports.length === 0 ? 'Belum ada data port.' : 'Tidak ada hasil.' }}
              </div>
              <div v-else class="space-y-2 pr-1">
                <div
                  v-for="p in filteredPorts"
                  :key="p.id"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-indigo-200 dark:hover:border-indigo-900/40 bg-white dark:bg-dark-800"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex items-start gap-2">
                      <label v-if="canDelete" class="mt-0.5 inline-flex items-center shrink-0">
                        <input
                          type="checkbox"
                          :checked="isBulkItemSelected('ports', p)"
                          @click.stop
                          @change.stop="toggleBulkItem('ports', p)"
                        />
                      </label>
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
                          <span class="ml-1">- core {{ p.core_no }}</span>
                        </div>
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

              <div class="flex items-center gap-2">
                <input v-model="panelSearch" class="input w-full !py-2 !text-xs" :placeholder="searchPlaceholder" />
                <button v-if="panelSearch" class="btn btn-secondary !py-2 !text-xs" @click="panelSearch=''">Clear</button>
              </div>
              <div v-if="panelSearch" class="text-[11px] text-gray-500 dark:text-gray-400">
                Hasil: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ filteredLinkItems.length }}</span> / {{ linkItems.length }}
              </div>
              <div v-if="canDelete" class="flex flex-wrap items-center gap-2">
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkVisibleCount('links') === 0"
                  @click="toggleBulkSelectAllVisible('links')"
                >
                  {{ isBulkAllVisibleSelected('links') ? 'Batal Pilih Semua' : `Pilih Semua (${bulkVisibleCount('links')})` }}
                </button>
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('links') === 0"
                  @click="clearBulkSelection('links')"
                >
                  Clear Pilihan
                </button>
                <button
                  class="btn btn-danger !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('links') === 0"
                  @click="deleteBulkSelected('links')"
                >
                  {{ bulkDeleting ? 'Menghapus...' : `Hapus Dipilih (${bulkSelectedCount('links')})` }}
                </button>
              </div>
              <div v-if="canDelete && bulkSelectedCount('links') > 0" class="text-[11px] text-gray-500 dark:text-gray-400">
                Terpilih: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ bulkSelectedCount('links') }}</span>
                <span class="mx-1">|</span>
                Terlihat: {{ bulkVisibleSelectedCount('links') }}/{{ bulkVisibleCount('links') }}
              </div>

              <div v-if="filteredLinkItems.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                {{ linkItems.length === 0 ? 'Belum ada data sambungan.' : 'Tidak ada hasil.' }}
              </div>
              <div v-else class="space-y-2 pr-1">
                <div
                  v-for="ln in filteredLinkItems"
                  :key="(ln.kind === 'SPLIT_GROUP' ? ('g:' + ln.split_group + ':' + ln.point_id) : ('l:' + ln.id))"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-amber-200 dark:hover:border-amber-900/40 bg-white dark:bg-dark-800"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex items-start gap-2">
                      <label v-if="canDelete" class="mt-0.5 inline-flex items-center shrink-0">
                        <input
                          type="checkbox"
                          :checked="isBulkItemSelected('links', ln)"
                          @click.stop
                          @change.stop="toggleBulkItem('links', ln)"
                        />
                      </label>
                      <div class="min-w-0">
                      <div class="text-xs font-bold text-amber-700 dark:text-amber-300">
                        {{ ln.kind === 'SPLIT_GROUP' ? 'SPLIT' : String(ln.link_type || '').toUpperCase() }}
                        <span v-if="ln.kind === 'SPLIT_GROUP'" class="ml-1">- {{ (ln.outputs || []).length }} output</span>
                      </div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Titik:
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ pointNameById(ln.point_id) || ('#' + ln.point_id) }}</span>
                      </div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white mt-1 truncate">
                        {{ cableNameById(ln.from_cable_id) || ('Kabel #' + ln.from_cable_id) }} - core {{ ln.from_core_no }}
                        <span class="mx-1 text-gray-400">-&gt;</span>
                        <template v-if="ln.kind === 'SPLIT_GROUP'">
                          <span class="text-gray-700 dark:text-gray-200">splitter</span>
                        </template>
                        <template v-else>
                          {{ cableNameById(ln.to_cable_id) || ('Kabel #' + ln.to_cable_id) }} - core {{ ln.to_core_no }}
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
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <button v-if="canEdit" class="btn btn-secondary !py-1 !px-2 !text-xs" @click.stop="openEditLink(ln)">Edit</button>
                      <button v-if="canDelete" class="btn btn-danger !py-1 !px-2 !text-xs" @click.stop="deleteLinkItem(ln)">Hapus</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Cores tab -->
            <div v-else-if="activeTab==='cores'" class="space-y-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Core Occupancy</div>
                <div class="flex items-center gap-2">
                  <select v-model="coreCableId" class="input !py-2 !text-xs" @change="loadCoreOccupancy(coreCableId)">
                    <option value="">(pilih kabel)</option>
                    <option v-for="c in cables" :key="c.id" :value="String(c.id)">
                      {{ c.name }} ({{ c.core_count || '-' }} core)
                    </option>
                  </select>
                  <button class="btn btn-secondary !py-2 !text-xs" :disabled="coreLoading || !coreCableId" @click="loadCoreOccupancy(coreCableId)">Refresh</button>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-2 text-[11px]">
                <div class="px-2 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-dark-800 text-gray-700 dark:text-gray-200">FREE: <span class="font-semibold">{{ coreSummary.FREE || 0 }}</span></div>
                <div class="px-2 py-1.5 rounded-lg border border-blue-200 dark:border-blue-900/40 bg-blue-50/70 dark:bg-blue-900/20 text-blue-700 dark:text-blue-200">USED: <span class="font-semibold">{{ coreSummary.USED || 0 }}</span></div>
                <div class="px-2 py-1.5 rounded-lg border border-amber-200 dark:border-amber-900/40 bg-amber-50/70 dark:bg-amber-900/20 text-amber-700 dark:text-amber-200">RESERVED: <span class="font-semibold">{{ coreSummary.RESERVED || 0 }}</span></div>
                <div class="px-2 py-1.5 rounded-lg border border-red-200 dark:border-red-900/40 bg-red-50/70 dark:bg-red-900/20 text-red-700 dark:text-red-200">BROKEN: <span class="font-semibold">{{ coreSummary.BROKEN || 0 }}</span></div>
              </div>
              <div class="text-[11px] text-gray-500 dark:text-gray-400">
                Urutan warna core: Biru, Oranye, Hijau, Coklat, Abu, Putih, Merah, Hitam, Kuning, Ungu, Pink, Aqua (berulang per 12 core).
              </div>

              <div v-if="coreLoading" class="text-sm text-gray-500 dark:text-gray-400">Memuat data core...</div>
              <div v-else-if="coreError" class="text-sm text-red-600 dark:text-red-300">{{ coreError }}</div>
              <div v-else-if="!coreCableId" class="text-sm text-gray-500 dark:text-gray-400">Pilih kabel untuk melihat okupansi core.</div>
              <div v-else class="space-y-3">
                <div class="grid grid-cols-4 sm:grid-cols-6 gap-2">
                  <button
                    v-for="row in coreRows"
                    :key="row.core_no"
                    class="rounded-lg border px-2 py-2 text-[11px] text-center transition"
                    :class="[coreStatusClass(row.status), Number(selectedCoreNo) === Number(row.core_no) ? '!ring-2 !ring-slate-900/20 dark:!ring-white/30' : '']"
                    @click="selectedCoreNo = row.core_no"
                  >
                    <div class="font-semibold">Core {{ row.core_no }}</div>
                    <div class="mt-1 flex items-center justify-center gap-1.5">
                      <span class="inline-block h-2.5 w-2.5 rounded-full border border-black/15 dark:border-white/20" :style="{ backgroundColor: coreColorHex(row.core_no) }"></span>
                      <span class="text-[10px] text-gray-600 dark:text-gray-300">{{ coreColorName(row.core_no) }}</span>
                    </div>
                    <div class="mt-0.5 uppercase tracking-wide">{{ row.status }}</div>
                  </button>
                </div>

                <div v-if="selectedCoreRow" class="rounded-xl border border-gray-100 dark:border-dark-700 p-3 bg-gray-50/40 dark:bg-dark-900/30">
                  <div class="flex items-center justify-between gap-2">
                    <div class="text-xs font-semibold text-gray-900 dark:text-white">Detail Core {{ selectedCoreRow.core_no }}</div>
                    <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                      <input
                        type="checkbox"
                        :checked="isCoreReservedDraft(selectedCoreRow.core_no)"
                        @change="toggleCoreReservedDraft(selectedCoreRow.core_no)"
                      />
                      Reserve
                    </label>
                  </div>

                  <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Status saat ini: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ selectedCoreRow.status }}</span>
                  </div>
                  <div class="mt-1 text-xs text-gray-500 dark:text-gray-400 flex items-center gap-2">
                    Warna core:
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg border border-gray-200 dark:border-white/15 bg-white/70 dark:bg-dark-800/70 text-gray-700 dark:text-gray-200">
                      <span class="inline-block h-2.5 w-2.5 rounded-full border border-black/15 dark:border-white/20" :style="{ backgroundColor: coreColorHex(selectedCoreRow.core_no) }"></span>
                      <span class="font-semibold">{{ coreColorName(selectedCoreRow.core_no) }}</span>
                    </span>
                  </div>

                  <div class="mt-2 grid grid-cols-2 gap-2 text-[11px]">
                    <div class="rounded-lg border border-gray-200 dark:border-white/10 px-2 py-1.5">
                      Port: <span class="font-semibold">{{ (selectedCoreRow.details?.ports || []).length }}</span>
                    </div>
                    <div class="rounded-lg border border-gray-200 dark:border-white/10 px-2 py-1.5">
                      Link: <span class="font-semibold">{{ (selectedCoreRow.details?.links_from || []).length + (selectedCoreRow.details?.links_to || []).length }}</span>
                    </div>
                  </div>

                  <div v-if="(selectedCoreRow.details?.ports || []).length" class="mt-2">
                    <div class="text-[11px] font-semibold text-gray-700 dark:text-gray-200">Ports</div>
                    <div class="mt-1 space-y-1">
                      <div v-for="p in (selectedCoreRow.details?.ports || [])" :key="'p'+p.id" class="text-[11px] text-gray-600 dark:text-gray-300">
                        {{ p.point_name || ('#'+p.point_id) }} | {{ p.port_type }} | {{ p.port_label }}
                      </div>
                    </div>
                  </div>

                  <div v-if="(selectedCoreRow.details?.links_from || []).length || (selectedCoreRow.details?.links_to || []).length" class="mt-2">
                    <div class="text-[11px] font-semibold text-gray-700 dark:text-gray-200">Links</div>
                    <div class="mt-1 space-y-1">
                      <div v-for="ln in (selectedCoreRow.details?.links_from || [])" :key="'lf'+ln.id" class="text-[11px] text-gray-600 dark:text-gray-300">
                        OUT {{ ln.link_type }} @ {{ ln.point_name || ('#'+ln.point_id) }} -> Kabel {{ cableNameById(ln.to_cable_id) || ('#'+ln.to_cable_id) }}:{{ ln.to_core_no }}
                      </div>
                      <div v-for="ln in (selectedCoreRow.details?.links_to || [])" :key="'lt'+ln.id" class="text-[11px] text-gray-600 dark:text-gray-300">
                        IN {{ ln.link_type }} @ {{ ln.point_name || ('#'+ln.point_id) }} <- Kabel {{ cableNameById(ln.from_cable_id) || ('#'+ln.from_cable_id) }}:{{ ln.from_core_no }}
                      </div>
                    </div>
                  </div>
                </div>

                <div class="flex items-center justify-end gap-2">
                  <button class="btn btn-secondary !py-2 !text-xs" :disabled="coreSaving || !coreCableId" @click="loadCoreOccupancy(coreCableId)">Undo Draft</button>
                  <button class="btn btn-primary !py-2 !text-xs" :disabled="coreSaving || !coreCableId" @click="saveCoreReservations">
                    {{ coreSaving ? 'Menyimpan...' : 'Simpan Reservasi' }}
                  </button>
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
                      {{ pointNameById(p.point_id) || ('#' + p.point_id) }} | {{ p.port_label }} | {{ cableNameById(p.cable_id) || ('Kabel #' + p.cable_id) }}:{{ p.core_no }}
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
                  <span class="mx-2">|</span>
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
                      <span class="mx-2">|</span>
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
                        <span class="mx-1 text-gray-400">|</span>
                        {{ s.point?.name || ('Titik #' + s.node?.point_id) }}
                      </div>
                      <div class="text-gray-600 dark:text-gray-300">
                        {{ s.cable?.name || ('Kabel #' + s.node?.cable_id) }} - core {{ s.node?.core_no }}
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

              <div class="flex items-center gap-2">
                <input v-model="panelSearch" class="input w-full !py-2 !text-xs" :placeholder="searchPlaceholder" />
                <button v-if="panelSearch" class="btn btn-secondary !py-2 !text-xs" @click="panelSearch=''">Clear</button>
              </div>

              <div class="flex flex-wrap items-center gap-2">
                <button
                  class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
                  :class="breakStatusFilter==='ALL' ? 'bg-slate-900 text-white border-slate-900 dark:bg-white dark:text-slate-900 dark:border-white' : 'bg-white dark:bg-dark-800 border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'"
                  @click="breakStatusFilter='ALL'"
                >
                  Semua
                </button>
                <button
                  class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
                  :class="breakStatusFilter==='OPEN' ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/10 dark:border-red-900/30 dark:text-red-300' : 'bg-white dark:bg-dark-800 border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'"
                  @click="breakStatusFilter='OPEN'"
                >
                  OPEN
                </button>
                <button
                  class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
                  :class="breakStatusFilter==='IN_PROGRESS' ? 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/10 dark:border-amber-900/30 dark:text-amber-200' : 'bg-white dark:bg-dark-800 border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'"
                  @click="breakStatusFilter='IN_PROGRESS'"
                >
                  IN PROGRESS
                </button>
                <button
                  class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
                  :class="breakStatusFilter==='FIXED' ? 'bg-emerald-50 border-emerald-200 text-emerald-700 dark:bg-emerald-900/10 dark:border-emerald-900/30 dark:text-emerald-200' : 'bg-white dark:bg-dark-800 border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'"
                  @click="breakStatusFilter='FIXED'"
                >
                  FIXED
                </button>
                <button
                  class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
                  :class="breakStatusFilter==='VERIFIED' ? 'bg-emerald-100 border-emerald-300 text-emerald-800 dark:bg-emerald-900/20 dark:border-emerald-900/40 dark:text-emerald-100' : 'bg-white dark:bg-dark-800 border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'"
                  @click="breakStatusFilter='VERIFIED'"
                >
                  VERIFIED
                </button>
                <button
                  class="px-3 py-1.5 rounded-full text-xs font-semibold border transition"
                  :class="breakStatusFilter==='CANCELLED' ? 'bg-slate-100 border-slate-200 text-slate-700 dark:bg-slate-700/30 dark:border-white/10 dark:text-slate-200' : 'bg-white dark:bg-dark-800 border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5'"
                  @click="breakStatusFilter='CANCELLED'"
                >
                  CANCELLED
                </button>
              </div>

              <div v-if="panelSearch || breakStatusFilter !== 'ALL'" class="text-[11px] text-gray-500 dark:text-gray-400">
                Hasil: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ filteredBreaks.length }}</span> / {{ breaks.length }}
              </div>
              <div v-if="canDelete" class="flex flex-wrap items-center gap-2">
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkVisibleCount('breaks') === 0"
                  @click="toggleBulkSelectAllVisible('breaks')"
                >
                  {{ isBulkAllVisibleSelected('breaks') ? 'Batal Pilih Semua' : `Pilih Semua (${bulkVisibleCount('breaks')})` }}
                </button>
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('breaks') === 0"
                  @click="clearBulkSelection('breaks')"
                >
                  Clear Pilihan
                </button>
                <button
                  class="btn btn-danger !py-2 !text-xs"
                  :disabled="bulkDeleting || bulkSelectedCount('breaks') === 0"
                  @click="deleteBulkSelected('breaks')"
                >
                  {{ bulkDeleting ? 'Menghapus...' : `Hapus Dipilih (${bulkSelectedCount('breaks')})` }}
                </button>
              </div>
              <div v-if="canDelete && bulkSelectedCount('breaks') > 0" class="text-[11px] text-gray-500 dark:text-gray-400">
                Terpilih: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ bulkSelectedCount('breaks') }}</span>
                <span class="mx-1">|</span>
                Terlihat: {{ bulkVisibleSelectedCount('breaks') }}/{{ bulkVisibleCount('breaks') }}
              </div>

              <div v-if="filteredBreaks.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                {{ breaks.length === 0 ? 'Belum ada data putus.' : 'Tidak ada hasil.' }}
              </div>
              <div v-else class="space-y-2 pr-1">
                <div
                  v-for="b in filteredBreaks"
                  :key="b.id"
                  class="p-3 rounded-xl border border-gray-100 dark:border-dark-700 hover:border-red-200 dark:hover:border-red-900/40 cursor-pointer"
                  :class="Number(selectedBreakId) === Number(b.id) ? 'bg-red-50/60 dark:bg-red-900/10' : 'bg-white dark:bg-dark-800'"
                  @click="selectBreak(b, { panel: 'close' })"
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex items-start gap-2">
                      <label v-if="canDelete" class="mt-0.5 inline-flex items-center shrink-0">
                        <input
                          type="checkbox"
                          :checked="isBulkItemSelected('breaks', b)"
                          @click.stop
                          @change.stop="toggleBulkItem('breaks', b)"
                        />
                      </label>
                      <div>
                      <div class="text-xs font-bold">{{ formatStatus(b.status) }}</div>
                      <div class="text-sm font-semibold text-gray-900 dark:text-white mt-1">
                        {{ cableNameById(b.cable_id) || (b.cable_id ? ('Kabel #' + b.cable_id) : 'Tanpa kabel') }}
                      </div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <span>Titik: {{ pointNameById(b.point_id) || '-' }}</span>
                        <span v-if="b.core_no" class="ml-2">| core {{ b.core_no }}</span>
                        <span v-if="b.severity" class="ml-2">| {{ b.severity }}</span>
                      </div>
                      <div v-if="b.description" class="text-xs text-gray-600 dark:text-gray-300 mt-1 line-clamp-2">{{ b.description }}</div>
                    </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <button
                        v-if="canEdit && String(b.status || '').toUpperCase() === 'OPEN'"
                        class="btn btn-secondary !py-1 !px-2 !text-xs"
                        :disabled="isUpdatingBreakStatus(b.id)"
                        @click.stop="markBreakInProgress(b)"
                      >
                        {{ isUpdatingBreakStatus(b.id) ? 'Memproses...' : 'Proses' }}
                      </button>
                      <button
                        v-if="canCreate && canEdit && ['OPEN','IN_PROGRESS'].includes(String(b.status || 'OPEN').toUpperCase())"
                        class="btn btn-primary !py-1 !px-2 !text-xs"
                        :disabled="isFixingBreak(b.id)"
                        @click.stop="fixBreakWithJoint(b)"
                      >
                        {{ isFixingBreak(b.id) ? 'Memproses...' : 'Selesaikan' }}
                      </button>
                      <button
                        v-if="canEdit && String(b.status || '').toUpperCase() === 'FIXED'"
                        class="btn btn-secondary !py-1 !px-2 !text-xs"
                        :disabled="isUpdatingBreakStatus(b.id)"
                        @click.stop="verifyBreak(b)"
                      >
                        {{ isUpdatingBreakStatus(b.id) ? 'Memproses...' : 'Verifikasi' }}
                      </button>
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
    </div>

    <!-- Cable Modal -->
    <div v-if="showCableModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-4" :class="mapMode ? 'pointer-events-none' : ''">
      <div
        class="absolute inset-0 bg-black/40"
        :class="mapMode ? 'pointer-events-none opacity-20' : ''"
        @click="showCableModal=false; stopMapMode()"
      ></div>
      <div class="relative pointer-events-auto w-full max-w-2xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden max-h-[calc(100vh-2rem)] flex flex-col">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ cableModalMode === 'edit' ? 'Edit Kabel' : 'Tambah Kabel' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showCableModal=false; stopMapMode()">Tutup</button>
        </div>

        <div class="p-4 space-y-3 overflow-y-auto flex-1 min-h-0">
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
            <div class="md:col-span-2">
              <label class="text-xs text-gray-600 dark:text-gray-300">Panjang (meter)</label>
              <div class="flex flex-col sm:flex-row gap-2">
                <input
                  v-model="cableForm.length_m"
                  type="number"
                  min="0"
                  class="input w-full sm:flex-1"
                  placeholder="(opsional)"
                  @input="cableLengthAuto = false"
                />
                <button class="btn btn-secondary shrink-0 !py-2 !text-xs" @click="setCableLengthFromPath">Hitung dari jalur</button>
              </div>
              <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Estimasi dari jalur:
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ formatLength(cablePathLenM) }}</span>
                <span class="ml-2">({{ cablePathLenM }} m)</span>
                <span v-if="cableLengthAuto" class="ml-2">[auto]</span>
              </div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Dari Titik *</label>
              <select v-model="cableForm.from_point_id" class="input w-full">
                <option value="">(pilih titik)</option>
                <option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Ke Titik *</label>
              <select v-model="cableForm.to_point_id" class="input w-full">
                <option value="">(pilih titik)</option>
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
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
              <div>
                <div class="text-sm font-semibold text-gray-900 dark:text-white">Jalur di Peta</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Titik: {{ Array.isArray(cableForm.path) ? cableForm.path.length : 0 }}</div>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200 px-3 py-2 rounded-xl border border-gray-200/70 dark:border-white/10 bg-white/70 dark:bg-dark-900/40">
                  <input type="checkbox" v-model="drawFollowRoad" />
                  Ikuti jalan
                </label>
                <button
                  class="btn btn-secondary !py-2 !text-xs"
                  @click="autoCablePathFromEndpoints({ force: true })"
                  :disabled="!cableForm.from_point_id || !cableForm.to_point_id"
                >
                  Auto
                </button>
                <button class="btn btn-secondary !py-2 !text-xs" @click="startDrawCable">Gambar</button>
                <button class="btn btn-secondary !py-2 !text-xs" @click="startEditCablePath" :disabled="!cableForm.path || cableForm.path.length < 2">Edit</button>
                <button class="btn btn-secondary !py-2 !text-xs" @click="undoCablePoint" :disabled="!cableForm.path || cableForm.path.length === 0">Undo</button>
                <button class="btn btn-secondary !py-2 !text-xs" @click="clearCablePath" :disabled="!cableForm.path || cableForm.path.length === 0">Clear</button>
              </div>
            </div>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
              Tips: mode <span class="font-semibold">Ikuti jalan</span> memakai Google Directions. Jika gagal/limit, otomatis fallback ke garis lurus. Jalur bisa dirapikan lewat tombol <span class="font-semibold">Edit</span>.
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
    <div v-if="showPointModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-4" :class="mapMode ? 'pointer-events-none' : ''">
      <div
        class="absolute inset-0 bg-black/40"
        :class="mapMode ? 'pointer-events-none opacity-20' : ''"
        @click="showPointModal=false; stopMapMode()"
      ></div>
      <div class="relative pointer-events-auto w-full max-w-xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden max-h-[calc(100vh-2rem)] flex flex-col">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ pointModalMode === 'edit' ? 'Edit Titik' : 'Tambah Titik' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showPointModal=false; stopMapMode()">Tutup</button>
        </div>

        <div class="p-4 space-y-3 overflow-y-auto flex-1 min-h-0">
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
    <div v-if="showBreakModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-4" :class="mapMode ? 'pointer-events-none' : ''">
      <div
        class="absolute inset-0 bg-black/40"
        :class="mapMode ? 'pointer-events-none opacity-20' : ''"
        @click="showBreakModal=false; stopMapMode()"
      ></div>
      <div class="relative pointer-events-auto w-full max-w-xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden max-h-[calc(100vh-2rem)] flex flex-col">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ breakModalMode === 'edit' ? 'Edit Data Putus' : 'Tambah Data Putus' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showBreakModal=false; stopMapMode()">Tutup</button>
        </div>

        <div class="p-4 space-y-3 overflow-y-auto flex-1 min-h-0">
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Kabel</label>
            <select v-model="breakForm.cable_id" class="input w-full">
              <option value="">(kosong)</option>
              <option v-for="c in cables" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>

          <div class="rounded-xl border border-amber-200/70 dark:border-amber-900/40 bg-amber-50/50 dark:bg-amber-900/10 p-3 space-y-2">
            <div class="flex items-center justify-between gap-2">
              <div class="text-xs font-semibold text-amber-800 dark:text-amber-200">Estimasi Titik Putus (OTDR)</div>
              <div class="text-[11px] text-amber-700/90 dark:text-amber-300/90">
                Panjang jalur: <span class="font-semibold">{{ breakOtdrCableLengthM > 0 ? formatLength(breakOtdrCableLengthM) : '-' }}</span>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
              <div>
                <label class="text-[11px] text-amber-800 dark:text-amber-200">Mode Referensi</label>
                <select v-model="breakOtdrForm.reference_mode" class="input w-full !py-2 !text-xs">
                  <option value="endpoint">Ujung Kabel (From/To)</option>
                  <option value="point">Titik/JB Tengah</option>
                </select>
              </div>
              <div>
                <label class="text-[11px] text-amber-800 dark:text-amber-200">Jarak OTDR (meter)</label>
                <input
                  v-model="breakOtdrForm.distance_m"
                  type="number"
                  min="0"
                  step="1"
                  class="input w-full !py-2 !text-xs"
                  placeholder="contoh: 1240"
                />
              </div>
              <div class="flex items-end">
                <button
                  class="btn btn-secondary !py-2 !text-xs w-full"
                  :disabled="!breakForm.cable_id"
                  @click="applyBreakLocationFromOtdr"
                >
                  Set Lokasi Dari OTDR
                </button>
              </div>
            </div>

            <div v-if="breakOtdrForm.reference_mode === 'endpoint'" class="grid grid-cols-1 md:grid-cols-2 gap-2">
              <div>
                <label class="text-[11px] text-amber-800 dark:text-amber-200">Referensi Ujung</label>
                <select v-model="breakOtdrForm.reference_side" class="input w-full !py-2 !text-xs">
                  <option value="from">Dari: {{ breakOtdrFromLabel }}</option>
                  <option value="to">Dari: {{ breakOtdrToLabel }}</option>
                </select>
              </div>
            </div>

            <div v-else class="space-y-2">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <div>
                  <label class="text-[11px] text-amber-800 dark:text-amber-200">Titik/JB Referensi</label>
                  <select v-model="breakOtdrForm.reference_point_id" class="input w-full !py-2 !text-xs" :disabled="!breakForm.cable_id">
                    <option value="">(pilih titik di jalur kabel)</option>
                    <option v-for="rp in breakOtdrReferencePointOptions" :key="'otdr-rp-' + rp.id" :value="String(rp.id)">
                      {{ rp.label }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="text-[11px] text-amber-800 dark:text-amber-200">Arah Pengukuran</label>
                  <select v-model="breakOtdrForm.reference_direction" class="input w-full !py-2 !text-xs">
                    <option value="auto">Auto (analisa titik terdekat)</option>
                    <option value="toward_to">Ke arah {{ breakOtdrToLabel }}</option>
                    <option value="toward_from">Ke arah {{ breakOtdrFromLabel }}</option>
                  </select>
                </div>
              </div>

              <div class="flex flex-wrap items-center gap-2">
                <button
                  class="btn btn-secondary !py-1.5 !text-xs"
                  :disabled="!breakForm.cable_id || breakOtdrReferencePointOptions.length === 0"
                  @click="autoPickBreakOtdrReferencePoint"
                >
                  Analisa Titik Terdekat
                </button>
                <div class="text-[11px] text-amber-700/90 dark:text-amber-300/90">
                  {{ breakOtdrSelectedReferenceHint || 'Pilih titik/JB di sepanjang jalur kabel untuk referensi OTDR dari titik tengah.' }}
                </div>
              </div>
            </div>

            <div class="text-[11px] text-amber-700/90 dark:text-amber-300/90">
              Mode titik tengah mendukung OTDR dari JB/point tengah, dengan analisa arah otomatis berdasarkan kedekatan ke titik/JB terdekat di jalur.
            </div>
          </div>

          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Titik (opsional)</label>
            <select v-model="breakForm.point_id" class="input w-full">
              <option value="">(kosong)</option>
              <option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>

          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Core (opsional)</label>
            <input v-model="breakForm.core_no" type="number" min="1" class="input w-full" placeholder="contoh: 1" />
            <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Isi core bila gangguan hanya pada core tertentu.</div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Status</label>
              <select v-model="breakForm.status" class="input w-full">
                <option value="OPEN">OPEN</option>
                <option value="IN_PROGRESS">IN_PROGRESS</option>
                <option value="FIXED">FIXED</option>
                <option value="VERIFIED">VERIFIED</option>
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
              <label class="text-xs text-gray-600 dark:text-gray-300">Teknisi</label>
              <input v-model="breakForm.technician_name" class="input w-full" placeholder="Nama teknisi lapangan" />
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Verifier</label>
              <input v-model="breakForm.verified_by_name" class="input w-full" placeholder="Nama verifier (opsional)" />
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Dilaporkan</label>
              <input v-model="breakForm.reported_at" type="datetime-local" class="input w-full" />
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Mulai Perbaikan</label>
              <input v-model="breakForm.repair_started_at" type="datetime-local" class="input w-full" :disabled="!['IN_PROGRESS','FIXED','VERIFIED'].includes(String(breakForm.status).toUpperCase())" />
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Fixed At</label>
              <input v-model="breakForm.fixed_at" type="datetime-local" class="input w-full" :disabled="!['FIXED','VERIFIED'].includes(String(breakForm.status).toUpperCase())" />
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Verified At</label>
              <input v-model="breakForm.verified_at" type="datetime-local" class="input w-full" :disabled="String(breakForm.status).toUpperCase() !== 'VERIFIED'" />
            </div>
          </div>

          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Closure Point (opsional)</label>
            <select v-model="breakForm.closure_point_id" class="input w-full">
              <option value="">(kosong)</option>
              <option v-for="p in points" :key="'cp-' + p.id" :value="p.id">{{ p.name }}</option>
            </select>
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

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Foto Perbaikan (1 URL/baris)</label>
              <textarea v-model="breakForm.repair_photos_text" class="input w-full min-h-[90px]" placeholder="https://..."></textarea>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Material (1 item/baris)</label>
              <textarea v-model="breakForm.repair_materials_text" class="input w-full min-h-[90px]" placeholder="Joint Closure 24 Core"></textarea>
            </div>
          </div>
        </div>

        <div class="p-4 border-t border-gray-100 dark:border-dark-700 flex items-center justify-end gap-2">
          <button class="btn btn-secondary" @click="showBreakModal=false; stopMapMode()">Batal</button>
          <button class="btn btn-primary" :disabled="breakProcessing" @click="saveBreak">{{ breakProcessing ? 'Menyimpan...' : 'Simpan' }}</button>
        </div>
      </div>
    </div>

    <!-- Split Cable Modal -->
    <div v-if="showCableSplitModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="showCableSplitModal=false"></div>
      <div class="relative w-full max-w-xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden max-h-[calc(100vh-2rem)] flex flex-col">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">Split Kabel Otomatis</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showCableSplitModal=false">Tutup</button>
        </div>

        <div class="p-4 space-y-3 overflow-y-auto flex-1 min-h-0">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Titik Split</label>
              <div class="input w-full !py-2 !text-xs bg-gray-50 dark:bg-dark-900/40">
                {{ cableSplitPoint?.name || ('Titik #' + (cableSplitForm.point_id || '-')) }}
              </div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">Lanjut Ke Form</label>
              <div class="input w-full !py-2 !text-xs bg-gray-50 dark:bg-dark-900/40">
                {{ cableSplitOpenTargetLabel }}
              </div>
            </div>
          </div>

          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Kabel Sumber</label>
            <select v-model="cableSplitForm.source_cable_id" class="input w-full">
              <option value="">(pilih kabel)</option>
              <option v-for="row in cableSplitCandidateCables" :key="'sc-' + row.id" :value="String(row.id)">
                {{ row.label }}
              </option>
            </select>
            <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">
              Kandidat muncul jika titik berada di jalur kabel (offset maks {{ CABLE_SPLIT_POINT_MAX_OFFSET_M }}m) atau sudah endpoint kabel.
            </div>
          </div>

          <div v-if="cableSplitSelectedCandidate" class="rounded-xl border border-gray-100 dark:border-dark-700 p-3 bg-gray-50/40 dark:bg-dark-900/30 text-xs text-gray-600 dark:text-gray-300 space-y-1">
            <div>
              Cable: <span class="font-semibold text-gray-800 dark:text-gray-100">{{ cableSplitSelectedCandidate.cable?.name || ('#' + cableSplitSelectedCandidate.id) }}</span>
            </div>
            <div>
              Chain: <span class="font-semibold text-gray-800 dark:text-gray-100">{{ Math.round(cableSplitSelectedCandidate.projection?.chainage_m || 0) }}m</span>
              <span class="mx-2">|</span>
              Offset: <span class="font-semibold text-gray-800 dark:text-gray-100">{{ Math.round(cableSplitSelectedCandidate.projection?.offset_m || 0) }}m</span>
            </div>
            <div v-if="cableSplitSelectedCandidate.is_endpoint" class="text-amber-700 dark:text-amber-300">
              Titik sudah endpoint kabel, sistem akan langsung buka form tanpa split.
            </div>
          </div>

          <div v-if="cableSplitCandidateCables.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
            Tidak ada kabel yang bisa di-split di titik ini.
          </div>
          <div v-if="cableSplitError" class="text-sm text-red-600 dark:text-red-300">{{ cableSplitError }}</div>
        </div>

        <div class="p-4 border-t border-gray-100 dark:border-dark-700 flex items-center justify-end gap-2">
          <button class="btn btn-secondary" @click="showCableSplitModal=false">Batal</button>
          <button class="btn btn-primary" :disabled="cableSplitProcessing || !cableSplitForm.source_cable_id" @click="splitCableAtPointAndOpenTarget">
            {{ cableSplitProcessing ? 'Memproses...' : `Split & Buka ${cableSplitOpenTargetLabel}` }}
          </button>
        </div>
      </div>
    </div>

    <!-- Port Modal -->
    <div v-if="showPortModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="showPortModal=false"></div>
      <div class="relative w-full max-w-xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden max-h-[calc(100vh-2rem)] flex flex-col">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ portModalMode === 'edit' ? 'Edit Port' : 'Tambah Port' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showPortModal=false">Tutup</button>
        </div>

        <div class="p-4 space-y-3 overflow-y-auto flex-1 min-h-0">
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
    <div v-if="showLinkModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/40" @click="showLinkModal=false"></div>
      <div class="relative w-full max-w-2xl bg-white dark:bg-dark-800 rounded-2xl shadow-xl border border-gray-100 dark:border-dark-700 overflow-hidden max-h-[calc(100vh-2rem)] flex flex-col">
        <div class="p-4 border-b border-gray-100 dark:border-dark-700 flex items-center justify-between">
          <div class="font-semibold text-gray-900 dark:text-white">{{ linkModalMode === 'edit' ? 'Edit Sambungan' : 'Tambah Sambungan' }}</div>
          <button class="text-gray-500 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white" @click="showLinkModal=false">Tutup</button>
        </div>

        <div class="p-4 space-y-3 overflow-y-auto flex-1 min-h-0">
          <div>
            <label class="text-xs text-gray-600 dark:text-gray-300">Titik</label>
            <select v-model="linkForm.point_id" class="input w-full">
              <option value="">(pilih titik)</option>
              <option v-for="p in points" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
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
              <div v-if="linkErrors.loss_db" class="text-xs text-red-600 mt-1">{{ linkErrors.loss_db?.[0] }}</div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">From Cable</label>
              <select v-model="linkForm.from_cable_id" class="input w-full">
                <option value="">(pilih kabel)</option>
                <option v-for="c in linkSelectableCables" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
              </select>
              <div v-if="linkErrors.from_cable_id" class="text-xs text-red-600 mt-1">{{ linkErrors.from_cable_id?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">From Warna Core</label>
              <select v-model="linkForm.from_core_no" class="input w-full">
                <option value="">(pilih warna core)</option>
                <option v-for="o in linkFromCoreOptions" :key="'fc-' + o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <div v-if="linkErrors.from_core_no" class="text-xs text-red-600 mt-1">{{ linkErrors.from_core_no?.[0] }}</div>
            </div>
          </div>
          <div class="text-[11px] text-gray-500 dark:text-gray-400">
            Pilihan warna mengikuti urutan warna core standar 12 warna (berulang).
          </div>

          <div v-if="String(linkForm.link_type).toUpperCase() !== 'SPLIT'" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">To Cable</label>
              <select v-model="linkForm.to_cable_id" class="input w-full">
                <option value="">(pilih kabel)</option>
                <option v-for="c in linkSelectableCables" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
              </select>
              <div v-if="linkErrors.to_cable_id" class="text-xs text-red-600 mt-1">{{ linkErrors.to_cable_id?.[0] }}</div>
            </div>
            <div>
              <label class="text-xs text-gray-600 dark:text-gray-300">To Warna Core</label>
              <select v-model="linkForm.to_core_no" class="input w-full">
                <option value="">(pilih warna core)</option>
                <option v-for="o in linkToCoreOptions" :key="'tc-' + o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <div v-if="linkErrors.to_core_no" class="text-xs text-red-600 mt-1">{{ linkErrors.to_core_no?.[0] }}</div>
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
                    <option v-for="c in linkSelectableCables" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                  </select>
                </div>
                <div class="col-span-3">
                  <select v-model="o.to_core_no" class="input w-full">
                    <option value="">(warna)</option>
                    <option v-for="opt in linkOutputCoreOptions(o.to_cable_id)" :key="'oc-' + idx + '-' + opt.value" :value="opt.value">
                      {{ opt.label }}
                    </option>
                  </select>
                </div>
                <div class="col-span-2 flex items-center justify-end">
                  <button class="btn btn-danger !py-1 !px-2 !text-xs" @click="removeSplitOutputRow(idx)">Hapus</button>
                </div>
              </div>
            </div>
            <div v-if="linkErrors.outputs" class="text-xs text-red-600">{{ linkErrors.outputs?.[0] }}</div>
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

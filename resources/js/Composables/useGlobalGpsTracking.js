import { computed, reactive, unref } from 'vue'

const API_MAPS = '/api/v1/maps'
const LOCATION_TRACKING_ROLES = new Set(['teknisi', 'svp lapangan', 'svp_lapangan'])
const GPS_REQUIRED_ROLES = new Set(['teknisi'])
const LOCATION_SEND_MIN_INTERVAL_MS = 15000
const LOCATION_HEARTBEAT_INTERVAL_MS = 60000
const LOCATION_MIN_MOVE_METERS = 20

const locationTracking = reactive({
  enabled: false,
  supported: true,
  secureContextOk: true,
  statusText: 'Lokasi belum aktif',
  tone: 'muted',
  lastSyncedAt: '',
})

let locationWatchId = null
let locationHeartbeatTimer = null
let locationLastPosition = null
let locationLastSent = null
let locationSending = false
let locationFailedCount = 0

function normalizeRole(role) {
  const value = String(role || '').trim().toLowerCase()
  if (value === 'svp lapangan') return 'svp_lapangan'
  return value
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

async function postLocation(payload) {
  const res = await fetch(`${API_MAPS}/location`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })

  const text = await res.text()
  let json = null
  try {
    json = text ? JSON.parse(text) : null
  } catch {
    json = null
  }

  const ok = res.ok && (json?.success === true || json?.status === 'success')
  if (!ok) {
    throw new Error(String(json?.message || json?.msg || 'Gagal mengirim lokasi'))
  }

  return json
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

export function useGlobalGpsTracking(roleRef) {
  const normalizedRole = computed(() => normalizeRole(unref(roleRef)))
  const canTrackLocation = computed(() => LOCATION_TRACKING_ROLES.has(normalizedRole.value))
  const gpsRequiredForFeature = computed(() => GPS_REQUIRED_ROLES.has(normalizedRole.value))
  const gpsAccessReady = computed(() =>
    !gpsRequiredForFeature.value || (locationTracking.tone === 'success' && !!locationTracking.lastSyncedAt)
  )
  const gpsFeatureLocked = computed(() => gpsRequiredForFeature.value && !gpsAccessReady.value)
  const canRetryLocationTracking = computed(() =>
    canTrackLocation.value
    && locationTracking.supported
    && locationTracking.secureContextOk
    && !locationTracking.enabled
  )
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
  const gpsLockMessage = computed(() => {
    if (!gpsRequiredForFeature.value) return ''
    if (!locationTracking.supported) return 'Perangkat atau browser ini tidak mendukung GPS/geolocation.'
    if (!locationTracking.secureContextOk) return 'Akses GPS butuh HTTPS atau localhost.'
    return locationTracking.statusText || 'Aktifkan GPS agar modul teknisi bisa digunakan.'
  })

  async function sendLocationPosition(position, { eventName = 'live_tracking', force = false } = {}) {
    if (!canTrackLocation.value) return
    const payload = buildLocationPayload(position, eventName)
    if (!payload || !shouldSendLocation(payload, { force }) || locationSending) return

    locationSending = true
    try {
      await postLocation(payload)
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
      console.warn('Global GPS tracking error:', e)
    } finally {
      locationSending = false
    }
  }

  function handleLocationSuccess(position) {
    locationLastPosition = position
    if (!locationTracking.lastSyncedAt) setLocationStatus('Lokasi terdeteksi, sinkronisasi...', 'muted')
    void sendLocationPosition(position, { eventName: 'live_tracking' })
  }

  function handleLocationError(err) {
    setLocationStatus(parseLocationError(err), Number(err?.code || 0) === 1 ? 'warning' : 'error')
    if (Number(err?.code || 0) === 1) stopLocationTracking()
  }

  function startLocationTracking() {
    if (!canTrackLocation.value) {
      stopLocationTracking()
      return
    }
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
      void sendLocationPosition(locationLastPosition, { eventName: 'heartbeat', force: true })
    }, LOCATION_HEARTBEAT_INTERVAL_MS)
  }

  function retryLocationTracking() {
    locationLastSent = null
    locationFailedCount = 0
    stopLocationTracking()
    startLocationTracking()
  }

  return {
    normalizedRole,
    canTrackLocation,
    gpsRequiredForFeature,
    gpsAccessReady,
    gpsFeatureLocked,
    canRetryLocationTracking,
    gpsLockMessage,
    locationTracking,
    locationStatusClass,
    locationStatusDotClass,
    startLocationTracking,
    stopLocationTracking,
    retryLocationTracking,
  }
}

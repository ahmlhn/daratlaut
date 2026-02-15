<script setup>
import { ref, computed, onMounted, inject, nextTick, watch } from 'vue'
import LoadingSpinner from '@/Components/LoadingSpinner.vue'

const props = defineProps({
  embedded: { type: Boolean, default: false },
})

const toast = inject('toast', null)
function notify(type, message) {
  const fn = toast?.[type]
  if (typeof fn === 'function') return fn(message)
  // Fallback if toast provider isn't available.
  alert(message)
}

const statusLoading = ref(true)
const uploading = ref(false)
const uploadPct = ref(0)
const running = ref(false)
const state = ref(null)
const fileRef = ref(null)
const zipFile = ref(null)
const ghBusy = ref(false)
const ghToken = ref('')
const ghCheck = ref(null)

const logBoxRef = ref(null)

// UI state (Option 1: simplified panel)
const sourceMode = ref('zip') // zip | github
const sourceTouched = ref(false)
const detailsOpen = ref(false)
const logOpen = ref(false)
const autoFollow = ref(true)
const dangerOpen = ref(false)
const resetText = ref('')

// Helper: merge incoming state without losing enriched fields (config/github/installed/log_tail)
// that only the full status() endpoint returns.
function applyState(incoming) {
  if (!incoming) return
  const prev = state.value
  if (prev && !incoming.config && prev.config) incoming.config = prev.config
  if (prev && !incoming.github && prev.github) incoming.github = prev.github
  if (prev && !incoming.installed && prev.installed) incoming.installed = prev.installed
  if (prev && !incoming.log_tail && prev.log_tail) incoming.log_tail = prev.log_tail
  state.value = incoming
}

const stage = computed(() => state.value?.stage || 'idle')
const err = computed(() => state.value?.error || '')
const cfg = computed(() => state.value?.config || {})
const github = computed(() => state.value?.github || {})
const installed = computed(() => state.value?.installed || null)
const pkg = computed(() => state.value?.package || null)

function formatDateTime(iso) {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleString()
  } catch (e) {
    return String(iso)
  }
}

function formatBytes(bytes) {
  const n = Number(bytes)
  if (!Number.isFinite(n) || n <= 0) return ''
  const mb = n / 1024 / 1024
  if (mb < 1024) return `${Math.round(mb)} MB`
  return `${(mb / 1024).toFixed(1)} GB`
}

const copyProgress = computed(() => {
  const m = state.value?.manifest
  if (!m || !m.total_files) return null
  const total = Number(m.total_files) || 0
  const idx = Number(m.index) || 0
  const copied = Number(m.copied) || 0
  const skipped = Number(m.skipped) || 0
  const pct = total > 0 ? Math.min(100, Math.round((idx / total) * 100)) : 0
  return { total, idx, copied, skipped, pct }
})

const finalizeProgress = computed(() => {
  const f = state.value?.finalize
  if (!f || !Array.isArray(f.commands)) return null
  const total = f.commands.length
  const idx = Number(f.index) || 0
  const pct = total > 0 ? Math.min(100, Math.round((idx / total) * 100)) : 0
  return { total, idx, pct }
})

const stageLabel = computed(() => {
  const map = {
    idle: 'Idle',
    uploaded: 'Paket sudah diunggah (belum diproses)',
    ready: 'Siap (manifest siap)',
    copying: 'Menyalin file update',
    finalize: 'Finalisasi (artisan tasks)',
    done: 'Selesai',
    error: 'Error',
  }
  return map[stage.value] || stage.value
})

const stagePill = computed(() => {
  const s = stage.value
  if (s === 'done') return { text: 'DONE', klass: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' }
  if (s === 'error') return { text: 'ERROR', klass: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-300' }
  if (['copying', 'finalize'].includes(s)) return { text: 'RUNNING', klass: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-300' }
  if (['ready', 'uploaded'].includes(s)) return { text: 'READY', klass: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' }
  return { text: 'IDLE', klass: 'bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-200' }
})

const isBusy = computed(() => statusLoading.value || uploading.value || running.value || ghBusy.value)

const sectionClass = computed(() => {
  if (props.embedded) {
    return 'rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-dark-900/40 p-5'
  }
  return 'rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-dark-950 shadow-sm p-5'
})

const mutedBoxClass = computed(() => {
  return 'rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-dark-900/40 p-4'
})

const canPrepare = computed(() => {
  if (!cfg.value?.enabled) return false
  if (isBusy.value) return false
  if (!pkg.value) return false
  return ['uploaded', 'error', 'ready', 'copying', 'finalize', 'done'].includes(stage.value)
})

const canApply = computed(() => {
  if (!cfg.value?.enabled) return false
  if (isBusy.value) return false
  return ['uploaded', 'ready', 'copying', 'finalize', 'error'].includes(stage.value)
})

const hasStagedPackage = computed(() => {
  const s = stage.value
  if (['uploaded', 'ready', 'copying', 'finalize'].includes(s)) return true
  if (s === 'error') return !!(pkg.value || state.value?.manifest || state.value?.finalize)
  return false
})

const canPrimary = computed(() => {
  if (!cfg.value?.enabled) return false
  if (isBusy.value) return false
  if (hasStagedPackage.value) return true

  if (sourceMode.value === 'github') {
    return !!(github.value?.enabled && github.value?.configured && github.value?.token_present)
  }
  return !!zipFile.value
})

const primaryLabel = computed(() => {
  if (running.value) return 'Memproses...'
  if (stage.value === 'error') return 'Coba Lagi'
  if (['ready', 'copying', 'finalize'].includes(stage.value)) return 'Lanjutkan'
  if (stage.value === 'done' && sourceMode.value === 'github') return 'Update Lagi'
  return 'Mulai Update'
})

const primaryHint = computed(() => {
  if (!cfg.value?.enabled) return 'Fitur update dinonaktifkan di server.'
  if (isBusy.value) return ''

  if (hasStagedPackage.value) {
    const name = pkg.value?.filename ? `Paket: ${pkg.value.filename}` : 'Ada update yang belum selesai.'
    return `${name} Klik untuk melanjutkan.`
  }

  if (sourceMode.value === 'github') {
    if (!github.value?.enabled) return 'GitHub update dinonaktifkan di server.'
    if (!github.value?.configured) return 'Repo GitHub belum diset di .env server.'
    if (!github.value?.token_present) return 'Token GitHub belum ada. Simpan token dulu.'
    return 'Akan download paket build dari GitHub Release lalu apply otomatis.'
  }

  if (!zipFile.value) {
    if (cfg.value?.allow_download && cfg.value?.package_url_set) return 'Pilih file ZIP, atau gunakan \"Download dari URL\".'
    return 'Pilih file ZIP untuk memulai.'
  }
  return `Siap: ${zipFile.value?.name || 'package.zip'}`
})

function setSourceMode(mode) {
  sourceMode.value = mode
  sourceTouched.value = true
}

function guessDefaultMode() {
  const installedSource = String(installed.value?.source || '').toLowerCase()
  if (installedSource.startsWith('github')) return 'github'

  const pkgSource = String(pkg.value?.source || '').toLowerCase()
  if (pkgSource.startsWith('github')) return 'github'

  if (github.value?.enabled && github.value?.configured && github.value?.token_present) return 'github'
  return 'zip'
}

watch(
  () => [installed.value?.source, pkg.value?.source, github.value?.enabled, github.value?.configured, github.value?.token_present],
  () => {
    if (sourceTouched.value) return
    sourceMode.value = guessDefaultMode()
  },
  { immediate: true }
)

const stepper = computed(() => {
  const steps = [
    { id: 'package', label: 'Paket' },
    { id: 'prepare', label: 'Prepare' },
    { id: 'copy', label: 'Copy' },
    { id: 'finalize', label: 'Finalize' },
    { id: 'done', label: 'Selesai' },
  ]

  const s = stage.value
  let current = 0
  if (s === 'idle') current = 0
  else if (s === 'uploaded') current = 1
  else if (s === 'ready') current = 2
  else if (s === 'copying') current = 2
  else if (s === 'finalize') current = 3
  else if (s === 'done') current = 4
  else if (s === 'error') {
    if (finalizeProgress.value) current = 3
    else if (copyProgress.value || state.value?.manifest) current = 2
    else current = 1
  }

  return steps.map((st, idx) => {
    const isError = s === 'error'
    const status = s === 'done'
      ? 'done'
      : idx < current
        ? 'done'
        : idx === current
          ? (isError ? 'error' : 'current')
          : 'upcoming'
    return { ...st, index: idx + 1, status }
  })
})

async function refreshStatus() {
  statusLoading.value = true
  try {
    const res = await window.axios.get('/system-update/status')
    // Full status response includes config/github/installed — safe to overwrite entirely
    state.value = res?.data?.data?.data || res?.data?.data || null
  } catch (e) {
    state.value = { stage: 'error', error: e?.response?.data?.message || e?.message || 'Failed to load status', config: state.value?.config, github: state.value?.github }
  } finally {
    statusLoading.value = false
  }
}

async function uploadPackage() {
  const file = zipFile.value || fileRef.value?.files?.[0]
  if (!file) return notify('warning', 'Pilih file ZIP dulu.')

  uploading.value = true
  uploadPct.value = 0
  try {
    const form = new FormData()
    form.append('package', file)

    const res = await window.axios.post('/system-update/upload', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (evt) => {
        if (!evt?.total) return
        uploadPct.value = Math.round((evt.loaded / evt.total) * 100)
      },
    })
    applyState(res?.data?.data?.data || res?.data?.data || null)
    uploadPct.value = 100
    notify('success', 'Paket ZIP berhasil diunggah.')
  } catch (e) {
    notify('error', e?.response?.data?.message || e?.message || 'Upload gagal')
  } finally {
    uploading.value = false
    await refreshStatus()
  }
}

async function downloadConfigured() {
  running.value = true
  try {
    const res = await window.axios.post('/system-update/download')
    applyState(res?.data?.data?.data || res?.data?.data || null)
    notify('success', 'Download paket update selesai.')
  } catch (e) {
    notify('error', e?.response?.data?.message || e?.message || 'Download gagal')
  } finally {
    running.value = false
    await refreshStatus()
  }
}

async function prepare() {
  running.value = true
  try {
    const res = await window.axios.post('/system-update/start')
    applyState(res?.data?.data?.data || res?.data?.data || null)
    notify('info', 'Prepare berhasil. Siap jalankan update.')
  } catch (e) {
    notify('error', e?.response?.data?.message || e?.message || 'Prepare gagal')
  } finally {
    running.value = false
    await refreshStatus()
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function runStepLoop() {
  // Step loop: copy chunks then finalize commands.
  let guard = 0
  while (running.value && guard < 2000) {
    guard++
    await refreshStatus()

    if (stage.value === 'done' || stage.value === 'error' || stage.value === 'idle') break
    if (!['ready', 'copying', 'finalize'].includes(stage.value)) break

    const res = await window.axios.post('/system-update/step')
    applyState(res?.data?.data?.data || res?.data?.data || null)

    // Small delay so the UI feels responsive and we don't hammer the server.
    await sleep(200)
  }
}

async function runSteps(opts = {}) {
  const autoPrepare = !!opts.autoPrepare

  running.value = true
  try {
    await refreshStatus()

    // Auto-prepare so the flow can be a single click.
    if (stage.value === 'uploaded' || (stage.value === 'error' && autoPrepare && pkg.value)) {
      if (!autoPrepare && !confirm('Jalankan Prepare sekarang?')) return
      const st = await window.axios.post('/system-update/start')
      applyState(st?.data?.data?.data || st?.data?.data || null)
    }

    await runStepLoop()

    await refreshStatus()
    if (stage.value === 'done') notify('success', 'Update selesai.')
    else if (stage.value === 'error') notify('error', err.value || 'Update gagal.')
  } catch (e) {
    notify('error', e?.response?.data?.message || e?.message || 'Update gagal')
  } finally {
    running.value = false
    await refreshStatus()
  }
}

async function resetUpdate() {
  running.value = true
  try {
    const res = await window.axios.post('/system-update/reset')
    applyState(res?.data?.data?.data || res?.data?.data || null)
    ghCheck.value = null
    resetText.value = ''
    dangerOpen.value = false
    notify('info', 'State update di-reset.')
  } catch (e) {
    notify('error', e?.response?.data?.message || e?.message || 'Reset gagal')
  } finally {
    running.value = false
    await refreshStatus()
  }
}

async function githubSaveToken() {
  const token = (ghToken.value || '').trim()
  if (!token) return notify('warning', 'Isi token dulu.')

  ghBusy.value = true
  try {
    const res = await window.axios.post('/system-update/github/token', { token })
    applyState(res?.data?.data?.data || res?.data?.data || null)
    ghToken.value = ''
    notify('success', 'Token GitHub tersimpan.')
  } catch (e) {
    notify('error', e?.response?.data?.message || e?.message || 'Gagal menyimpan token')
  } finally {
    ghBusy.value = false
    await refreshStatus()
  }
}

async function githubClearToken() {
  if (!confirm('Hapus token GitHub yang disimpan di server (file-based)?')) return
  ghBusy.value = true
  try {
    const res = await window.axios.post('/system-update/github/token/clear')
    applyState(res?.data?.data?.data || res?.data?.data || null)
    notify('info', 'Token GitHub dihapus.')
  } catch (e) {
    notify('error', e?.response?.data?.message || e?.message || 'Gagal menghapus token')
  } finally {
    ghBusy.value = false
    await refreshStatus()
  }
}

async function githubCheckLatest() {
  ghBusy.value = true
  try {
    const res = await window.axios.post('/system-update/github/check')
    ghCheck.value = res?.data?.data || null
  } catch (e) {
    ghCheck.value = null
    notify('error', e?.response?.data?.message || e?.message || 'Gagal cek update GitHub')
  } finally {
    ghBusy.value = false
  }
}

async function githubDownloadAndUpdate(opts = {}) {
  const skipConfirm = !!opts.skipConfirm
  const repo = github.value?.owner && github.value?.repo ? `${github.value.owner}/${github.value.repo}` : '(repo belum diset)'
  const tag = github.value?.release_tag || 'panel-main-latest'
  const asset = github.value?.release_asset || 'update-package.zip'
  if (!skipConfirm) {
    const warn = [
      `Sistem akan download paket build dari GitHub Release (${repo} tag ${tag}).`,
      `Asset: ${asset}`,
      '',
      'Lanjutkan?',
    ].join('\n')
    if (!confirm(warn)) return
  }

  running.value = true
  try {
    const dl = await window.axios.post('/system-update/github/download')
    applyState(dl?.data?.data?.data || dl?.data?.data || null)

    const st = await window.axios.post('/system-update/start')
    applyState(st?.data?.data?.data || st?.data?.data || null)

    await runStepLoop()

    await refreshStatus()
    if (stage.value === 'done') notify('success', 'Update GitHub selesai.')
    else if (stage.value === 'error') notify('error', err.value || 'Update GitHub gagal.')
  } catch (e) {
    notify('error', e?.response?.data?.message || e?.message || 'Update GitHub gagal')
  } finally {
    running.value = false
    await refreshStatus()
  }
}

function scrollLogToBottom() {
  const el = logBoxRef.value
  if (!el) return
  el.scrollTop = el.scrollHeight
}

async function copyLog() {
  try {
    const text = (state.value?.log_tail || []).join('\n')
    if (!text) return notify('warning', 'Log masih kosong.')
    await navigator.clipboard.writeText(text)
    notify('success', 'Log disalin ke clipboard.')
  } catch (e) {
    notify('error', 'Gagal menyalin log (clipboard tidak tersedia).')
  }
}

watch(
  () => (state.value?.log_tail || []).length,
  async () => {
    if (!logOpen.value || !autoFollow.value) return
    await nextTick()
    scrollLogToBottom()
  }
)

watch(
  () => stage.value,
  (s) => {
    if (s === 'error') logOpen.value = true
  }
)

watch(
  () => logOpen.value,
  async (v) => {
    if (!v) return
    await nextTick()
    scrollLogToBottom()
  }
)

function onZipFileChange(e) {
  zipFile.value = e?.target?.files?.[0] || null
}

async function startPrimaryAction() {
  if (!cfg.value?.enabled) return notify('warning', 'Fitur update dinonaktifkan di server.')
  if (isBusy.value) return

  await refreshStatus()

  // If there is a staged update, continue it first (source selection is only for starting fresh).
  if (hasStagedPackage.value) {
    await runSteps({ autoPrepare: true })
    return
  }

  if (sourceMode.value === 'github') {
    if (!github.value?.enabled) return notify('warning', 'GitHub update dinonaktifkan di server.')
    if (!github.value?.configured) return notify('warning', 'Repo GitHub belum diset di server.')
    if (!github.value?.token_present) return notify('warning', 'Token GitHub belum ada. Simpan token dulu.')
    await githubDownloadAndUpdate({ skipConfirm: true })
    return
  }

  const file = zipFile.value || fileRef.value?.files?.[0]
  if (!file) {
    return notify(
      'warning',
      cfg.value?.allow_download && cfg.value?.package_url_set
        ? 'Pilih file ZIP atau gunakan \"Download dari URL\".'
        : 'Pilih file ZIP dulu.'
    )
  }

  await uploadPackage()
  await runSteps({ autoPrepare: true })
}

onMounted(() => {
  refreshStatus()
})
</script>

<template>
  <div class="space-y-6">
    <div class="relative overflow-hidden rounded-2xl border border-gray-200/80 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-dark-950 sm:p-6">
      <div class="pointer-events-none absolute inset-0">
        <div class="absolute -right-16 -top-20 h-56 w-56 rounded-full bg-primary-500/10 blur-3xl"></div>
        <div class="absolute -bottom-20 -left-20 h-56 w-56 rounded-full bg-emerald-500/10 blur-3xl"></div>
      </div>

      <div class="relative space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <h1 v-if="!embedded" class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">Update Sistem</h1>
            <h2 v-else class="text-lg font-black tracking-tight text-gray-900 dark:text-white">Update Sistem</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
              Update panel tanpa git pull/composer. Gunakan paket build agar vendor dan public/build ikut terpasang.
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <span :class="['px-2.5 py-1 rounded-full text-xs font-black tracking-wider', stagePill.klass]">
              {{ stagePill.text }}
            </span>
            <button @click="refreshStatus" class="btn btn-secondary" :disabled="isBusy" type="button">
              <span v-if="statusLoading" class="mr-2"><LoadingSpinner size="sm" /></span>
              Refresh
            </button>
            <button @click="detailsOpen = !detailsOpen" class="btn btn-secondary" type="button">
              {{ detailsOpen ? 'Mode Ringkas' : 'Mode Detail' }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <div class="rounded-xl border border-gray-200/80 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</div>
            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ stageLabel }}</div>
            <div v-if="hasStagedPackage" class="mt-1 text-xs text-amber-700 dark:text-amber-300">Ada proses update yang belum selesai.</div>
          </div>

          <div class="rounded-xl border border-gray-200/80 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sumber Aktif</div>
            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ sourceMode === 'github' ? 'GitHub Release' : 'Paket ZIP' }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              {{ sourceMode === 'github' ? `${github.owner || '-'} / ${github.repo || '-'}` : 'Upload manual atau download URL' }}
            </div>
          </div>

          <div class="rounded-xl border border-gray-200/80 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Paket</div>
            <div class="mt-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ pkg?.filename || 'Belum ada paket staged' }}</div>
            <div v-if="pkg?.size" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ formatBytes(pkg.size) }}</div>
          </div>

          <div class="rounded-xl border border-gray-200/80 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
            <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Terpasang</div>
            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ installed?.source || '-' }}</div>
            <div v-if="installed?.github?.sha" class="mt-1 text-xs font-mono text-gray-500 dark:text-gray-400">
              {{ (installed.github.sha || '').slice(0, 7) }}
            </div>
            <div v-if="installed?.installed_at" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ formatDateTime(installed.installed_at) }}</div>
          </div>
        </div>

        <div class="rounded-xl border border-gray-200/80 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-3">
              <div class="inline-flex rounded-xl border border-gray-200 bg-white/80 p-1 dark:border-white/10 dark:bg-dark-950/60">
                <button
                  type="button"
                  @click="setSourceMode('zip')"
                  :class="[
                    'px-3 py-1.5 text-xs font-bold rounded-lg transition',
                    sourceMode === 'zip' ? 'bg-white dark:bg-dark-950 shadow-sm text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white',
                  ]"
                >
                  ZIP
                </button>
                <button
                  type="button"
                  @click="setSourceMode('github')"
                  :disabled="!github.enabled"
                  :class="[
                    'px-3 py-1.5 text-xs font-bold rounded-lg transition',
                    !github.enabled ? 'opacity-50 cursor-not-allowed' : '',
                    sourceMode === 'github' ? 'bg-white dark:bg-dark-950 shadow-sm text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white',
                  ]"
                >
                  GitHub
                </button>
              </div>
              <p v-if="primaryHint" class="text-xs text-gray-500 dark:text-gray-400">{{ primaryHint }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <button @click="startPrimaryAction" class="btn btn-primary min-w-[170px]" :disabled="!canPrimary" type="button">
                <span v-if="running" class="mr-2"><LoadingSpinner size="sm" color="primary" /></span>
                {{ primaryLabel }}
              </button>
              <button v-if="detailsOpen" @click="prepare" class="btn btn-secondary px-3 py-2 text-xs" :disabled="!canPrepare" type="button">
                Prepare
              </button>
              <button v-if="detailsOpen" @click="runSteps({ autoPrepare: true })" class="btn btn-secondary px-3 py-2 text-xs" :disabled="!canApply" type="button">
                Run Steps
              </button>
            </div>
          </div>
        </div>

        <div
          v-if="!statusLoading && state && !cfg.enabled"
          class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
        >
          Fitur update dinonaktifkan di server. Set <span class="font-mono font-semibold">SYSTEM_UPDATE_ENABLED=true</span>.
        </div>

        <div
          v-if="err"
          class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900 whitespace-pre-line dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200"
        >
          {{ err }}
        </div>

        <div v-if="copyProgress || finalizeProgress" class="grid grid-cols-1 gap-3 lg:grid-cols-2">
          <div v-if="copyProgress" class="rounded-xl border border-gray-200/80 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
              <span>Copy {{ copyProgress.idx }}/{{ copyProgress.total }}</span>
              <span>{{ copyProgress.pct }}%</span>
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              copied {{ copyProgress.copied }} | skipped {{ copyProgress.skipped }}
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
              <div class="h-full bg-gradient-to-r from-primary-600 to-primary-500" :style="{ width: `${copyProgress.pct}%` }"></div>
            </div>
          </div>

          <div v-if="finalizeProgress" class="rounded-xl border border-gray-200/80 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
              <span>Finalize {{ finalizeProgress.idx }}/{{ finalizeProgress.total }}</span>
              <span>{{ finalizeProgress.pct }}%</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
              <div class="h-full bg-gradient-to-r from-emerald-600 to-emerald-500" :style="{ width: `${finalizeProgress.pct}%` }"></div>
            </div>
          </div>
        </div>

        <div v-if="detailsOpen" class="rounded-xl border border-gray-200/80 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
          <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Alur Update</div>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div
              v-for="st in stepper"
              :key="st.id"
              class="flex items-center gap-3 sm:flex-col sm:items-center sm:gap-2"
            >
              <div
                :class="[
                  'h-9 w-9 rounded-full flex items-center justify-center text-sm font-black border',
                  st.status === 'done' ? 'bg-emerald-600 text-white border-emerald-600' : '',
                  st.status === 'current' ? 'bg-primary-600 text-white border-primary-600' : '',
                  st.status === 'upcoming' ? 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-white/5 dark:text-gray-200 dark:border-white/10' : '',
                  st.status === 'error' ? 'bg-red-600 text-white border-red-600' : '',
                ]"
              >
                <svg v-if="st.status === 'done'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <svg v-else-if="st.status === 'error'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86l-8.02 13.9A2 2 0 004 20h16a2 2 0 001.73-3.24l-8.02-13.9a2 2 0 00-3.46 0z" />
                </svg>
                <span v-else>{{ st.index }}</span>
              </div>
              <div class="sm:text-center">
                <div class="text-sm sm:text-xs font-semibold text-gray-900 dark:text-white">{{ st.label }}</div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="detailsOpen && state?.manifest" :class="mutedBoxClass">
          <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
            <div class="flex items-center justify-between sm:block">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">vendor</div>
              <div
                class="mt-1 font-semibold"
                :class="state.manifest.vendor_included ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'"
              >
                {{ state.manifest.vendor_included ? 'included' : 'NOT included' }}
              </div>
            </div>
            <div class="flex items-center justify-between sm:block">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">public/build</div>
              <div
                class="mt-1 font-semibold"
                :class="state.manifest.build_included ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'"
              >
                {{ state.manifest.build_included ? 'included' : 'NOT included' }}
              </div>
            </div>
            <div class="flex items-center justify-between sm:block">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">excluded</div>
              <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ state.manifest.excluded_count || 0 }}</div>
            </div>
          </div>
          <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Tidak menimpa: <span class="font-semibold">.env</span>, <span class="font-semibold">storage/</span>,
            <span class="font-semibold">bootstrap/cache</span>, <span class="font-semibold">public/storage</span>, <span class="font-semibold">public/uploads</span>.
          </div>
          <div v-if="!state.manifest.vendor_included" class="mt-2 text-xs text-amber-700 dark:text-amber-300">
            Paket tidak mengandung <span class="font-semibold">vendor/</span>. Jika ada perubahan dependency, update bisa gagal.
          </div>
          <div v-if="!state.manifest.build_included" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
            Paket tidak mengandung <span class="font-semibold">public/build</span>. Jika ada perubahan frontend, UI bisa tidak update.
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
      <div class="space-y-6 xl:col-span-8">
        <div v-if="sourceMode === 'zip'" :class="sectionClass">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h3 class="text-base font-semibold text-gray-900 dark:text-white">Paket ZIP</h3>
              <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Upload paket update manual untuk hosting tanpa akses git/composer.
              </p>
            </div>
            <button
              v-if="cfg.allow_download && cfg.package_url_set"
              @click="downloadConfigured"
              class="btn btn-secondary"
              :disabled="isBusy || !cfg.enabled"
              type="button"
            >
              Download dari URL
            </button>
          </div>

          <div class="mt-4 space-y-3">
            <input
              ref="fileRef"
              type="file"
              accept=".zip"
              class="block w-full text-sm text-gray-700 dark:text-gray-300"
              @change="onZipFileChange"
            />

            <div v-if="zipFile?.name" class="text-xs text-gray-500 dark:text-gray-400">
              Dipilih: <span class="font-semibold">{{ zipFile.name }}</span>
              <span v-if="zipFile.size" class="ml-2">({{ formatBytes(zipFile.size) }})</span>
            </div>

            <div class="flex flex-wrap gap-2">
              <button v-if="detailsOpen" @click="uploadPackage" class="btn btn-secondary" :disabled="uploading || isBusy || !cfg.enabled" type="button">
                <span v-if="uploading" class="mr-2"><LoadingSpinner size="sm" /></span>
                {{ uploading ? `Uploading ${uploadPct}%` : 'Upload saja' }}
              </button>
            </div>

            <div v-if="uploading" class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
              <div class="h-full bg-gradient-to-r from-primary-600 to-primary-500" :style="{ width: `${uploadPct}%` }"></div>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-dark-900/40 p-3 text-xs text-gray-500 dark:text-gray-400">
              Tidak menimpa: <span class="font-semibold">.env</span>, <span class="font-semibold">storage/</span>,
              <span class="font-semibold">bootstrap/cache</span>, <span class="font-semibold">public/storage</span>, <span class="font-semibold">public/uploads</span>.
            </div>
          </div>
        </div>

        <div v-else :class="sectionClass">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h3 class="text-base font-semibold text-gray-900 dark:text-white">GitHub Release</h3>
              <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Download paket build terbaru dari GitHub lalu apply update otomatis.
              </p>
            </div>
            <button
              @click="githubCheckLatest"
              class="btn btn-secondary px-3 py-2 text-xs"
              :disabled="isBusy || !github.enabled || !github.configured || !github.token_present"
              type="button"
            >
              Cek Latest
            </button>
          </div>

          <div
            v-if="!github.enabled"
            class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
          >
            GitHub update dinonaktifkan (server config).
          </div>

          <div class="mt-4 grid grid-cols-1 gap-3 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-2">
            <div :class="mutedBoxClass">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Repo</div>
              <div class="mt-1 font-semibold">
                <span v-if="github.configured">{{ github.owner }}/{{ github.repo }}</span>
                <span v-else class="text-amber-700 dark:text-amber-300">Belum diset di .env</span>
              </div>
              <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Branch: <span class="font-mono font-semibold">{{ github.branch || 'main' }}</span>
              </div>
              <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Tag: <span class="font-mono font-semibold">{{ github.release_tag || 'panel-main-latest' }}</span>
                <span class="mx-2">|</span>
                Asset: <span class="font-mono font-semibold">{{ github.release_asset || 'update-package.zip' }}</span>
              </div>
            </div>

            <div :class="mutedBoxClass">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Token</div>
              <div class="mt-1 font-semibold">
                <span v-if="github.token_present" class="text-emerald-700 dark:text-emerald-300">Ada ({{ github.token_hint || '****' }})</span>
                <span v-else class="text-amber-700 dark:text-amber-300">Belum ada</span>
              </div>
              <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Saran: Fine-grained PAT dengan akses <span class="font-semibold">Contents: Read</span>.
              </div>
            </div>
          </div>

          <div v-if="detailsOpen || !github.token_present" class="mt-4" :class="mutedBoxClass">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Simpan Token</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  Token disimpan di server (file-based, encrypted).
                </div>
              </div>
              <button v-if="detailsOpen && github.token_present" @click="githubClearToken" class="btn btn-danger px-3 py-2 text-xs" :disabled="isBusy || ghBusy || !github.enabled" type="button">
                Hapus
              </button>
            </div>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
              <input v-model="ghToken" type="password" class="input sm:col-span-2" placeholder="Fine-grained PAT (read-only)" />
              <button @click="githubSaveToken" class="btn btn-secondary px-3 py-2 text-xs" :disabled="isBusy || ghBusy || !github.enabled" type="button">
                Simpan
              </button>
            </div>
          </div>

          <div v-if="ghCheck?.latest" class="mt-4" :class="mutedBoxClass">
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Latest</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                  <span class="font-mono">{{ ghCheck.latest.short }}</span>
                  <span class="ml-2 truncate text-gray-600 dark:text-gray-300">{{ ghCheck.latest.message }}</span>
                </div>
                <div v-if="ghCheck.latest.date" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ ghCheck.latest.date }}</div>

                <div v-if="ghCheck.built" class="mt-3 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                  <div>
                    <span class="font-semibold">tag:</span>
                    <span class="font-mono">{{ ghCheck.built.release?.tag || '-' }}</span>
                  </div>
                  <div v-if="ghCheck.built.meta?.short">
                    <span class="font-semibold">sha:</span>
                    <span class="font-mono">{{ ghCheck.built.meta.short }}</span>
                    <span v-if="ghCheck.built.meta.built_at" class="ml-2 text-gray-500 dark:text-gray-400">{{ ghCheck.built.meta.built_at }}</span>
                  </div>
                  <div v-else class="text-amber-700 dark:text-amber-300">
                    Metadata build tidak terbaca.
                  </div>
                  <div v-if="ghCheck.built.asset?.name">
                    <span class="font-semibold">asset:</span>
                    <span class="font-mono">{{ ghCheck.built.asset.name }}</span>
                    <span v-if="typeof ghCheck.built.asset.size === 'number'" class="ml-2 text-gray-500 dark:text-gray-400">
                      ({{ Math.round(ghCheck.built.asset.size / 1024 / 1024) }} MB)
                    </span>
                  </div>
                </div>
              </div>

              <div class="space-y-1 text-right text-xs whitespace-nowrap">
                <div v-if="ghCheck.build_ready === true" class="font-bold text-emerald-700 dark:text-emerald-300">BUILD READY</div>
                <div v-else-if="ghCheck.build_ready === false" class="font-bold text-amber-700 dark:text-amber-300">BUILD PENDING</div>
                <div v-if="ghCheck.update_available === false" class="font-bold text-emerald-700 dark:text-emerald-300">UP TO DATE</div>
                <div v-else-if="ghCheck.update_available === true" class="font-bold text-amber-700 dark:text-amber-300">UPDATE AVAILABLE</div>
                <div v-else class="font-bold text-gray-500 dark:text-gray-400">UNKNOWN</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-6 xl:col-span-4">
        <div :class="sectionClass">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Log Update</h3>
            <div class="flex flex-wrap items-center gap-2">
              <button @click="logOpen = !logOpen" class="btn btn-secondary px-3 py-2 text-xs" type="button">
                {{ logOpen ? 'Tutup' : 'Lihat' }}
              </button>
              <button @click="copyLog" class="btn btn-secondary px-3 py-2 text-xs" type="button">
                Copy
              </button>
              <button v-if="logOpen && !autoFollow" @click="scrollLogToBottom" class="btn btn-secondary px-3 py-2 text-xs" type="button">
                Ke bawah
              </button>
            </div>
          </div>

          <label class="mt-3 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 select-none">
            <input v-model="autoFollow" type="checkbox" class="rounded border-gray-300 dark:border-white/20" />
            Auto-follow log
          </label>

          <div v-if="logOpen" ref="logBoxRef" class="mt-3 h-80 overflow-auto rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-black/20 p-3">
            <pre class="text-[11px] leading-relaxed text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ (state?.log_tail || []).join('\n') }}</pre>
          </div>
          <div v-else class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Log disembunyikan. Akan terbuka otomatis saat terjadi error.
          </div>
        </div>

        <div v-if="detailsOpen" :class="sectionClass">
          <h3 class="text-base font-semibold text-gray-900 dark:text-white">Konfigurasi Server</h3>
          <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div :class="mutedBoxClass">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Enabled</div>
              <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ cfg.enabled ? 'YES' : 'NO' }}</div>
            </div>
            <div :class="mutedBoxClass">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Chunk size</div>
              <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ cfg.chunk_size }}</div>
            </div>
            <div :class="mutedBoxClass">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Max package</div>
              <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ cfg.max_package_mb }} MB</div>
            </div>
            <div :class="mutedBoxClass">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Download</div>
              <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ cfg.allow_download ? 'YES' : 'NO' }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-500/20 dark:bg-red-500/10">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-base font-semibold text-red-900 dark:text-red-200">Danger Zone</h3>
              <p class="mt-1 text-xs text-red-800 dark:text-red-200">
                Reset hanya menghapus state/workdir update (paket ZIP tetap ada). Gunakan jika update macet atau state corrupt.
              </p>
            </div>
            <button @click="dangerOpen = !dangerOpen" class="btn btn-secondary px-3 py-2 text-xs" type="button">
              {{ dangerOpen ? 'Tutup' : 'Buka' }}
            </button>
          </div>

          <div v-if="dangerOpen" class="mt-3 space-y-2">
            <div class="text-xs text-red-800 dark:text-red-200">Ketik RESET untuk mengaktifkan tombol reset.</div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
              <input v-model="resetText" class="input sm:col-span-2" placeholder="Ketik RESET" />
              <button @click="resetUpdate" class="btn btn-danger" :disabled="isBusy || resetText !== 'RESET'" type="button">
                Reset Update
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>


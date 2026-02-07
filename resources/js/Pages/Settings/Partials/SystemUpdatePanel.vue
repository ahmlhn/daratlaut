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
const ghBusy = ref(false)
const ghToken = ref('')
const ghCheck = ref(null)

const logBoxRef = ref(null)

const stage = computed(() => state.value?.stage || 'idle')
const err = computed(() => state.value?.error || '')
const cfg = computed(() => state.value?.config || {})
const github = computed(() => state.value?.github || {})
const installed = computed(() => state.value?.installed || null)

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
  const base = 'rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-dark-950'
  const shadow = props.embedded ? '' : 'shadow-sm'
  return `${base} ${shadow} p-5`
})

const mutedBoxClass = computed(() => {
  return 'rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-dark-900/40 p-4'
})

const canPrepare = computed(() => {
  if (!cfg.value?.enabled) return false
  return !isBusy.value && ['uploaded', 'error', 'idle', 'ready', 'copying', 'finalize', 'done'].includes(stage.value)
})

const canApply = computed(() => {
  if (!cfg.value?.enabled) return false
  return !isBusy.value && ['uploaded', 'ready', 'copying', 'finalize'].includes(stage.value)
})

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
    state.value = res?.data?.data?.data || res?.data?.data || null
  } catch (e) {
    state.value = { stage: 'error', error: e?.response?.data?.message || e?.message || 'Failed to load status' }
  } finally {
    statusLoading.value = false
  }
}

async function uploadPackage() {
  const file = fileRef.value?.files?.[0]
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
    state.value = res?.data?.data?.data || res?.data?.data || null
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
  if (!confirm('Download paket update dari URL yang sudah diset di server?')) return
  running.value = true
  try {
    const res = await window.axios.post('/system-update/download')
    state.value = res?.data?.data?.data || res?.data?.data || null
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
    state.value = res?.data?.data?.data || res?.data?.data || null
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

async function runSteps() {
  running.value = true
  try {
    await refreshStatus()
    if (stage.value === 'uploaded') {
      const ok = confirm('Paket sudah diunggah tapi belum diproses. Jalankan Prepare sekarang?')
      if (!ok) return
      await prepare()
    }

    // Step loop: copy chunks then finalize commands.
    let guard = 0
    while (running.value && guard < 2000) {
      guard++
      await refreshStatus()

      if (stage.value === 'done' || stage.value === 'error' || stage.value === 'idle') break
      if (!['ready', 'copying', 'finalize'].includes(stage.value)) break

      const res = await window.axios.post('/system-update/step')
      state.value = res?.data?.data?.data || res?.data?.data || null

      // Small delay so the UI feels responsive and we don't hammer the server.
      await sleep(200)
    }

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
  if (!confirm('Reset state update? Ini tidak menghapus paket ZIP yang sudah diunggah, hanya state/workdir.')) return
  running.value = true
  try {
    const res = await window.axios.post('/system-update/reset')
    state.value = res?.data?.data?.data || res?.data?.data || null
    ghCheck.value = null
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
    state.value = res?.data?.data?.data || res?.data?.data || state.value
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
    state.value = res?.data?.data?.data || res?.data?.data || state.value
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

async function githubDownloadAndUpdate() {
  const repo = github.value?.owner && github.value?.repo ? `${github.value.owner}/${github.value.repo}` : '(repo belum diset)'
  const tag = github.value?.release_tag || 'panel-main-latest'
  const asset = github.value?.release_asset || 'update-package.zip'
  const warn = [
    `Sistem akan download paket build dari GitHub Release (${repo} tag ${tag}).`,
    `Asset: ${asset}`,
    '',
    'Catatan penting:',
    '- Paket ini di-build oleh GitHub Actions setiap push ke main (termasuk vendor/ + public/build).',
    '- Jika build belum selesai, sistem akan menolak update sampai artifact siap.',
    '',
    'Lanjutkan?',
  ].join('\n')
  if (!confirm(warn)) return

  running.value = true
  try {
    const dl = await window.axios.post('/system-update/github/download')
    state.value = dl?.data?.data?.data || dl?.data?.data || state.value

    const st = await window.axios.post('/system-update/start')
    state.value = st?.data?.data?.data || st?.data?.data || state.value

    // Step loop: copy chunks then finalize commands.
    let guard = 0
    while (running.value && guard < 2000) {
      guard++
      if (stage.value === 'done' || stage.value === 'error' || stage.value === 'idle') break
      if (!['ready', 'copying', 'finalize'].includes(stage.value)) {
        await refreshStatus()
        if (!['ready', 'copying', 'finalize'].includes(stage.value)) break
      }

      const stepRes = await window.axios.post('/system-update/step')
      state.value = stepRes?.data?.data?.data || stepRes?.data?.data || state.value
      await sleep(200)
    }

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
    await nextTick()
    scrollLogToBottom()
  }
)

onMounted(() => {
  refreshStatus()
})
</script>

<template>
  <div :class="embedded ? 'card p-6' : ''">
    <div class="space-y-6">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <h2 v-if="embedded" class="text-lg font-semibold text-gray-900 dark:text-white">Update Sistem</h2>
          <h1 v-else class="text-2xl font-black text-gray-900 dark:text-white">Update Sistem</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Update lewat panel tanpa <span class="font-semibold">git pull</span> / <span class="font-semibold">composer</span>.
            Saran: gunakan paket build (sudah termasuk <span class="font-semibold">vendor</span> dan <span class="font-semibold">public/build</span>).
          </p>
          <div v-if="installed?.source" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Installed: <span class="font-semibold">{{ installed.source }}</span>
            <span v-if="installed.github?.sha" class="ml-2">
              sha <span class="font-mono font-semibold">{{ (installed.github.sha || '').slice(0, 7) }}</span>
            </span>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <span :class="['px-2.5 py-1 rounded-full text-xs font-black tracking-wider', stagePill.klass]">
            {{ stagePill.text }}
          </span>
          <button @click="refreshStatus" class="btn btn-secondary" :disabled="isBusy">
            <span v-if="statusLoading" class="mr-2"><LoadingSpinner size="sm" /></span>
            Refresh
          </button>
          <button @click="resetUpdate" class="btn btn-danger" :disabled="isBusy">
            Reset
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
          <div :class="sectionClass">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Progress</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                  <span class="font-semibold">Stage:</span> {{ stageLabel }}
                </p>

                <div
                  v-if="!cfg.enabled"
                  class="mt-3 text-sm rounded-xl border border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200 p-3"
                >
                  Fitur update dinonaktifkan di server. Set <span class="font-mono font-semibold">SYSTEM_UPDATE_ENABLED=true</span>.
                </div>

                <div
                  v-if="err"
                  class="mt-3 text-sm rounded-xl border border-red-200 bg-red-50 text-red-900 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200 p-3 whitespace-pre-line"
                >
                  {{ err }}
                </div>
              </div>

              <div class="flex items-center gap-2">
                <button @click="prepare" class="btn btn-secondary" :disabled="!canPrepare">
                  Prepare
                </button>
                <button @click="runSteps" class="btn btn-primary" :disabled="!canApply">
                  <span v-if="running" class="mr-2"><LoadingSpinner size="sm" color="primary" /></span>
                  {{ running ? 'Memproses...' : (stage === 'copying' || stage === 'finalize' ? 'Lanjutkan' : 'Apply Update') }}
                </button>
              </div>
            </div>

            <div class="mt-5 grid grid-cols-2 sm:grid-cols-5 gap-3">
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

            <div v-if="copyProgress" class="mt-4">
              <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>Copy {{ copyProgress.idx }}/{{ copyProgress.total }} (copied {{ copyProgress.copied }}, skipped {{ copyProgress.skipped }})</span>
                <span>{{ copyProgress.pct }}%</span>
              </div>
              <div class="h-2 bg-gray-100 dark:bg-white/10 rounded-full overflow-hidden mt-2">
                <div class="h-full bg-gradient-to-r from-primary-600 to-primary-500" :style="{ width: `${copyProgress.pct}%` }"></div>
              </div>
            </div>

            <div v-if="finalizeProgress" class="mt-4">
              <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>Finalize {{ finalizeProgress.idx }}/{{ finalizeProgress.total }}</span>
                <span>{{ finalizeProgress.pct }}%</span>
              </div>
              <div class="h-2 bg-gray-100 dark:bg-white/10 rounded-full overflow-hidden mt-2">
                <div class="h-full bg-gradient-to-r from-emerald-600 to-emerald-500" :style="{ width: `${finalizeProgress.pct}%` }"></div>
              </div>
            </div>

            <div v-if="state?.manifest" class="mt-5" :class="mutedBoxClass">
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <div class="flex items-center justify-between sm:block">
                  <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">vendor</div>
                  <div
                    class="mt-1 font-semibold"
                    :class="state.manifest.vendor_included ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'"
                  >
                    {{ state.manifest.vendor_included ? 'included' : 'NOT included' }}
                  </div>
                </div>
                <div class="flex items-center justify-between sm:block">
                  <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">public/build</div>
                  <div
                    class="mt-1 font-semibold"
                    :class="state.manifest.build_included ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'"
                  >
                    {{ state.manifest.build_included ? 'included' : 'NOT included' }}
                  </div>
                </div>
                <div class="flex items-center justify-between sm:block">
                  <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">excluded</div>
                  <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ state.manifest.excluded_count || 0 }}</div>
                </div>
              </div>
              <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Tidak menimpa: <span class="font-semibold">.env</span>, <span class="font-semibold">storage/</span>,
                <span class="font-semibold">bootstrap/cache</span>, <span class="font-semibold">public/storage</span>, <span class="font-semibold">public/uploads</span>.
              </div>
              <div v-if="!state.manifest.vendor_included" class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                Paket tidak mengandung <span class="font-semibold">vendor/</span>. Sistem mempertahankan vendor yang ada.
                Jika ada perubahan dependency, update bisa gagal.
              </div>
              <div v-if="!state.manifest.build_included" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                Paket tidak mengandung <span class="font-semibold">public/build</span>. Sistem mempertahankan build yang ada.
                Jika ada perubahan frontend, UI bisa tidak update.
              </div>
            </div>
          </div>

          <div :class="sectionClass">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">GitHub (main)</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                  Ambil paket build dari GitHub Release, lalu apply otomatis.
                </p>
              </div>
              <div class="flex items-center gap-2">
                <button @click="githubCheckLatest" class="btn btn-secondary px-3 py-2 text-xs" :disabled="isBusy || !github.enabled">
                  Cek
                </button>
                <button
                  @click="githubDownloadAndUpdate"
                  class="btn btn-primary px-3 py-2 text-xs"
                  :disabled="isBusy || !github.enabled || !github.configured || !github.token_present"
                >
                  Update
                </button>
              </div>
            </div>

            <div
              v-if="!github.enabled"
              class="mt-4 text-sm rounded-xl border border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200 p-3"
            >
              GitHub update dinonaktifkan (server config).
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-gray-700 dark:text-gray-300">
              <div :class="mutedBoxClass">
                <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Repo</div>
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
                <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Token</div>
                <div class="mt-1 font-semibold">
                  <span v-if="github.token_present" class="text-emerald-700 dark:text-emerald-300">
                    Ada ({{ github.token_hint || '****' }})
                  </span>
                  <span v-else class="text-amber-700 dark:text-amber-300">Belum ada</span>
                </div>
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                  Saran: Fine-grained PAT, akses <span class="font-semibold">Contents: Read</span> untuk repo ini saja.
                </div>
              </div>
            </div>

            <div class="mt-4" :class="mutedBoxClass">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Simpan Token</div>
                  <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Token disimpan di server (file-based, encrypted).
                  </div>
                </div>
                <button @click="githubClearToken" class="btn btn-danger px-3 py-2 text-xs" :disabled="isBusy || !github.enabled">
                  Hapus
                </button>
              </div>
              <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input v-model="ghToken" type="password" class="input sm:col-span-2" placeholder="Fine-grained PAT (read-only)" />
                <button @click="githubSaveToken" class="btn btn-secondary px-3 py-2 text-xs" :disabled="isBusy || !github.enabled">
                  Simpan
                </button>
              </div>
            </div>

            <div v-if="ghCheck?.latest" class="mt-4" :class="mutedBoxClass">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Latest</div>
                  <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    <span class="font-mono">{{ ghCheck.latest.short }}</span>
                    <span class="ml-2 text-gray-600 dark:text-gray-300 truncate">{{ ghCheck.latest.message }}</span>
                  </div>
                  <div v-if="ghCheck.latest.date" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ ghCheck.latest.date }}</div>

                  <div v-if="ghCheck.built" class="mt-3">
                    <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Built Artifact</div>
                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-300 space-y-1">
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
                        Metadata build tidak terbaca (body release tidak berisi JSON meta).
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
                </div>
                <div class="text-right text-xs whitespace-nowrap">
                  <div v-if="ghCheck.build_ready === true" class="font-bold text-emerald-700 dark:text-emerald-300">BUILD READY</div>
                  <div v-else-if="ghCheck.build_ready === false" class="font-bold text-amber-700 dark:text-amber-300">BUILD PENDING</div>
                  <div v-if="ghCheck.update_available === false" class="font-bold text-emerald-700 dark:text-emerald-300">UP TO DATE</div>
                  <div v-else-if="ghCheck.update_available === true" class="font-bold text-amber-700 dark:text-amber-300">UPDATE AVAILABLE</div>
                  <div v-else class="font-bold text-gray-500 dark:text-gray-400">UNKNOWN</div>
                </div>
              </div>
            </div>
          </div>

          <div :class="sectionClass">
            <div>
              <h3 class="text-base font-semibold text-gray-900 dark:text-white">Paket ZIP</h3>
              <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                Upload paket update manual (ZIP). Cocok untuk hosting tanpa akses git/composer.
              </p>
            </div>

            <div class="mt-4 space-y-3">
              <input ref="fileRef" type="file" accept=".zip" class="block w-full text-sm text-gray-700 dark:text-gray-300" />

              <div class="flex flex-wrap gap-2">
                <button @click="uploadPackage" class="btn btn-primary" :disabled="uploading || isBusy || !cfg.enabled">
                  <span v-if="uploading" class="mr-2"><LoadingSpinner size="sm" color="primary" /></span>
                  {{ uploading ? `Uploading ${uploadPct}%` : 'Upload ZIP' }}
                </button>
                <button
                  v-if="cfg.allow_download && cfg.package_url_set"
                  @click="downloadConfigured"
                  class="btn btn-secondary"
                  :disabled="isBusy || !cfg.enabled"
                >
                  Download Dari URL
                </button>
              </div>

              <div v-if="uploading" class="h-2 bg-gray-100 dark:bg-white/10 rounded-full overflow-hidden">
                <div class="h-full bg-gradient-to-r from-primary-600 to-primary-500" :style="{ width: `${uploadPct}%` }"></div>
              </div>

              <div :class="mutedBoxClass">
                <div class="text-xs text-gray-500 dark:text-gray-400">
                  Tidak menimpa: <span class="font-semibold">.env</span>, <span class="font-semibold">storage/</span>,
                  <span class="font-semibold">bootstrap/cache</span>, <span class="font-semibold">public/storage</span>,
                  <span class="font-semibold">public/uploads</span>.
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-6">
          <div :class="sectionClass">
            <div class="flex items-center justify-between gap-3">
              <h3 class="text-base font-semibold text-gray-900 dark:text-white">Log</h3>
              <div class="flex items-center gap-2">
                <button @click="copyLog" class="btn btn-secondary px-3 py-2 text-xs" type="button" :disabled="isBusy">
                  Copy
                </button>
                <button @click="scrollLogToBottom" class="btn btn-secondary px-3 py-2 text-xs" type="button">
                  Ke bawah
                </button>
              </div>
            </div>
            <div ref="logBoxRef" class="mt-3 h-80 overflow-auto rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-black/20 p-3">
              <pre class="text-[11px] leading-relaxed text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ (state?.log_tail || []).join('\n') }}</pre>
            </div>
          </div>

          <div :class="sectionClass">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Konfigurasi</h3>
            <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div :class="mutedBoxClass">
                <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Enabled</div>
                <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ cfg.enabled ? 'YES' : 'NO' }}</div>
              </div>
              <div :class="mutedBoxClass">
                <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Chunk size</div>
                <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ cfg.chunk_size }}</div>
              </div>
              <div :class="mutedBoxClass">
                <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Max package</div>
                <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ cfg.max_package_mb }} MB</div>
              </div>
              <div :class="mutedBoxClass">
                <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Download</div>
                <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ cfg.allow_download ? 'YES' : 'NO' }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, inject, nextTick, watch } from 'vue'

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

const panelClass = computed(() => {
  if (!props.embedded) return 'card p-5'
  return 'rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-dark-950 p-5'
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
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 v-if="embedded" class="text-lg font-semibold text-gray-900 dark:text-white">Update Sistem</h2>
          <h1 v-else class="text-2xl font-black text-gray-900 dark:text-white">Update Sistem</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Update lewat panel tanpa <span class="font-semibold">git pull</span> / <span class="font-semibold">composer</span>.
            Disarankan pakai paket ZIP <span class="font-semibold">sudah build</span> (termasuk <span class="font-semibold">vendor</span> + <span class="font-semibold">public/build</span>).
          </p>
        </div>
        <div class="flex items-center gap-2">
          <span :class="['px-2.5 py-1 rounded-full text-xs font-black tracking-wider', stagePill.klass]">
            {{ stagePill.text }}
          </span>
          <button @click="refreshStatus" class="btn btn-secondary" :disabled="statusLoading || uploading || running">
            Refresh
          </button>
          <button @click="resetUpdate" class="btn btn-danger" :disabled="statusLoading || uploading || running">
            Reset
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
          <div :class="panelClass">
            <div class="flex items-center justify-between gap-4">
              <div>
                <h3 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">Status</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                  <span class="font-semibold">Stage:</span> {{ stageLabel }}
                </p>
                <p v-if="installed?.source" class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                  Installed: <span class="font-semibold">{{ installed.source }}</span>
                  <span v-if="installed.github?.sha" class="ml-2">sha <span class="font-mono font-semibold">{{ (installed.github.sha || '').slice(0, 7) }}</span></span>
                </p>
                <p v-if="err" class="text-sm text-red-600 dark:text-red-400 mt-2 whitespace-pre-line">{{ err }}</p>
              </div>
              <div class="flex items-center gap-2">
                <button
                  @click="prepare"
                  class="btn btn-secondary"
                  :disabled="uploading || running || !['uploaded','error','idle','ready','copying','finalize','done'].includes(stage)"
                >
                  Prepare
                </button>
                <button
                  @click="runSteps"
                  class="btn btn-primary"
                  :disabled="uploading || running || !['uploaded','ready','copying','finalize'].includes(stage)"
                >
                  {{ running ? 'Memproses...' : 'Jalankan Update' }}
                </button>
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

            <div v-if="state?.manifest" class="mt-4 text-xs text-gray-500 dark:text-gray-400">
              <div class="flex flex-wrap gap-x-6 gap-y-1">
                <span>vendor: <span class="font-semibold" :class="state.manifest.vendor_included ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ state.manifest.vendor_included ? 'included' : 'NOT included' }}</span></span>
                <span>build: <span class="font-semibold" :class="state.manifest.build_included ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ state.manifest.build_included ? 'included' : 'NOT included' }}</span></span>
                <span>excluded: <span class="font-semibold">{{ state.manifest.excluded_count || 0 }}</span></span>
              </div>
              <p class="mt-2 text-gray-500 dark:text-gray-400">
                Tidak menimpa: <span class="font-semibold">.env</span>, <span class="font-semibold">storage/</span>,
                <span class="font-semibold">bootstrap/cache</span>, <span class="font-semibold">public/storage</span>, <span class="font-semibold">public/uploads</span>.
              </p>
              <p v-if="!state.manifest.vendor_included" class="mt-2 text-amber-700 dark:text-amber-300">
                Paket tidak mengandung <span class="font-semibold">vendor/</span>. Sistem akan mempertahankan vendor yang ada.
                Jika ada perubahan dependency, update bisa gagal.
              </p>
              <p v-if="!state.manifest.build_included" class="mt-1 text-amber-700 dark:text-amber-300">
                Paket tidak mengandung <span class="font-semibold">public/build</span>. Sistem akan mempertahankan build yang ada.
                Jika ada perubahan frontend, UI bisa tidak update.
              </p>
            </div>
          </div>

          <div :class="panelClass">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h3 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">GitHub (main)</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                  Cek update terbaru dari GitHub branch <span class="font-semibold">{{ github.branch || 'main' }}</span> dan apply otomatis.
                </p>
              </div>
              <div class="flex items-center gap-2">
                <button @click="githubCheckLatest" class="btn btn-secondary" :disabled="ghBusy || running || uploading || !github.enabled">
                  Cek Update
                </button>
                <button
                  @click="githubDownloadAndUpdate"
                  class="btn btn-primary"
                  :disabled="ghBusy || running || uploading || !github.enabled || !github.configured || !github.token_present"
                >
                  Download & Update
                </button>
              </div>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-gray-700 dark:text-gray-300">
              <div>
                <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Repo</div>
                <div class="mt-1 font-semibold">
                  <span v-if="github.configured">{{ github.owner }}/{{ github.repo }}</span>
                  <span v-else class="text-amber-700 dark:text-amber-300">Belum diset (isi `SYSTEM_UPDATE_GITHUB_OWNER/REPO` di .env)</span>
                </div>
              </div>
              <div>
                <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Token</div>
                <div class="mt-1 font-semibold">
                  <span v-if="github.token_present" class="text-emerald-700 dark:text-emerald-300">Ada ({{ github.token_hint || '****' }})</span>
                  <span v-else class="text-amber-700 dark:text-amber-300">Belum ada</span>
                </div>
              </div>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
              <input v-model="ghToken" type="password" class="input sm:col-span-2" placeholder="GitHub token (read-only contents)" />
              <div class="flex items-center gap-2">
                <button @click="githubSaveToken" class="btn btn-secondary flex-1" :disabled="ghBusy || running || uploading || !github.enabled">Simpan</button>
                <button @click="githubClearToken" class="btn btn-danger" :disabled="ghBusy || running || uploading || !github.enabled">Hapus</button>
              </div>
            </div>

            <div class="mt-4 text-xs text-gray-500 dark:text-gray-400">
              <p>
                Disarankan pakai <span class="font-semibold">Fine-grained PAT</span> dengan akses <span class="font-semibold">Contents: Read</span> ke repo ini saja.
              </p>
            </div>

            <div v-if="ghCheck?.latest" class="mt-4 rounded-xl border border-gray-200 dark:border-white/10 p-4 bg-white dark:bg-dark-950">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <div class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Latest</div>
                  <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    <span class="font-mono">{{ ghCheck.latest.short }}</span>
                    <span class="ml-2 text-gray-600 dark:text-gray-300">{{ ghCheck.latest.message }}</span>
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
                <div class="text-right text-xs">
                  <div v-if="ghCheck.build_ready === true" class="font-bold text-emerald-700 dark:text-emerald-300">BUILD READY</div>
                  <div v-else-if="ghCheck.build_ready === false" class="font-bold text-amber-700 dark:text-amber-300">BUILD PENDING</div>
                  <div v-if="ghCheck.update_available === false" class="font-bold text-emerald-700 dark:text-emerald-300">UP TO DATE</div>
                  <div v-else-if="ghCheck.update_available === true" class="font-bold text-amber-700 dark:text-amber-300">UPDATE AVAILABLE</div>
                  <div v-else class="font-bold text-gray-500 dark:text-gray-400">UNKNOWN</div>
                </div>
              </div>
            </div>
          </div>

          <div :class="panelClass">
            <h3 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">Paket Update (ZIP)</h3>
            <div class="mt-4 flex flex-col sm:flex-row gap-3 items-start sm:items-center">
              <input ref="fileRef" type="file" accept=".zip" class="block w-full text-sm text-gray-700 dark:text-gray-300" />
              <button @click="uploadPackage" class="btn btn-primary" :disabled="uploading || running">
                {{ uploading ? `Uploading ${uploadPct}%` : 'Upload ZIP' }}
              </button>
              <button
                v-if="cfg.allow_download && cfg.package_url_set"
                @click="downloadConfigured"
                class="btn btn-secondary"
                :disabled="uploading || running"
              >
                Download Dari URL
              </button>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
              Catatan: update tidak menimpa <span class="font-semibold">.env</span>, <span class="font-semibold">storage/</span>,
              <span class="font-semibold">bootstrap/cache</span>, <span class="font-semibold">public/storage</span>, <span class="font-semibold">public/uploads</span>.
            </p>
          </div>
        </div>

        <div class="space-y-6">
          <div :class="panelClass">
            <div class="flex items-center justify-between gap-3">
              <h3 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">Log (tail)</h3>
              <button @click="scrollLogToBottom" class="btn btn-secondary btn-sm text-xs" type="button">Ke bawah</button>
            </div>
            <div ref="logBoxRef" class="mt-3 h-80 overflow-auto rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-dark-950 p-3">
              <pre class="text-[11px] leading-relaxed text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ (state?.log_tail || []).join('\n') }}</pre>
            </div>
          </div>

          <div :class="panelClass">
            <h3 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">Konfigurasi</h3>
            <div class="mt-3 text-sm text-gray-600 dark:text-gray-300 space-y-2">
              <p><span class="font-semibold">Enabled:</span> {{ cfg.enabled ? 'YES' : 'NO' }}</p>
              <p><span class="font-semibold">Chunk size:</span> {{ cfg.chunk_size }}</p>
              <p><span class="font-semibold">Max package:</span> {{ cfg.max_package_mb }} MB</p>
              <p><span class="font-semibold">Download:</span> {{ cfg.allow_download ? 'YES' : 'NO' }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>


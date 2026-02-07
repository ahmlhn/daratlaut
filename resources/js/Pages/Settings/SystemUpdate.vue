<script setup>
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const statusLoading = ref(true)
const uploading = ref(false)
const uploadPct = ref(0)
const running = ref(false)
const state = ref(null)
const fileRef = ref(null)

const stage = computed(() => state.value?.stage || 'idle')
const err = computed(() => state.value?.error || '')
const cfg = computed(() => state.value?.config || {})

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
  if (!file) return alert('Pilih file ZIP dulu.')

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
  } catch (e) {
    alert(e?.response?.data?.message || e?.message || 'Upload gagal')
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
  } catch (e) {
    alert(e?.response?.data?.message || e?.message || 'Download gagal')
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
  } catch (e) {
    alert(e?.response?.data?.message || e?.message || 'Prepare gagal')
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
  } catch (e) {
    alert(e?.response?.data?.message || e?.message || 'Update gagal')
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
  } catch (e) {
    alert(e?.response?.data?.message || e?.message || 'Reset gagal')
  } finally {
    running.value = false
    await refreshStatus()
  }
}

onMounted(() => {
  refreshStatus()
})
</script>

<template>
  <Head title="Update Sistem" />
  <AdminLayout>
    <div class="space-y-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl font-black text-gray-900 dark:text-white">Update Sistem</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Update lewat panel tanpa <span class="font-semibold">git pull</span> / <span class="font-semibold">composer</span>.
            Disarankan upload paket ZIP <span class="font-semibold">sudah build</span> (termasuk <span class="font-semibold">vendor</span> + <span class="font-semibold">public/build</span>).
          </p>
        </div>
        <div class="flex items-center gap-2">
          <button
            @click="refreshStatus"
            class="btn btn-secondary"
            :disabled="statusLoading || uploading || running"
          >
            Refresh
          </button>
          <button
            @click="resetUpdate"
            class="btn btn-danger"
            :disabled="statusLoading || uploading || running"
          >
            Reset
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
          <div class="card p-5">
            <div class="flex items-center justify-between gap-4">
              <div>
                <h2 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">Status</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                  <span class="font-semibold">Stage:</span> {{ stageLabel }}
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
                  Jalankan Update
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

          <div class="card p-5">
            <h2 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">Paket Update (ZIP)</h2>
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
              Catatan: Update tidak akan menimpa <span class="font-semibold">.env</span>, <span class="font-semibold">storage/</span>,
              <span class="font-semibold">bootstrap/cache</span>, <span class="font-semibold">public/storage</span>, <span class="font-semibold">public/uploads</span>.
            </p>
          </div>
        </div>

        <div class="space-y-6">
          <div class="card p-5">
            <h2 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">Log (tail)</h2>
            <div class="mt-3 h-80 overflow-auto rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-dark-950 p-3">
              <pre class="text-[11px] leading-relaxed text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ (state?.log_tail || []).join('\n') }}</pre>
            </div>
          </div>

          <div class="card p-5">
            <h2 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">Konfigurasi</h2>
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
  </AdminLayout>
</template>


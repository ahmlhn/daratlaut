<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({
  featureCatalog: {
    type: Array,
    default: () => [],
  },
})

const API_BASE = '/api/v1/superadmin'

const loading = ref(true)
const savingTenant = ref(false)
const savingFeatures = ref(false)
const tenants = ref([])
const featureCatalog = ref(props.featureCatalog || [])
const activeTenantId = ref(null)
const search = ref('')

const tenantForm = ref({
  name: '',
  status: 'active',
})

const featureDraft = ref({})

function normalizeStatus(status) {
  return (status || '').toString().toLowerCase() === 'inactive' ? 'inactive' : 'active'
}

function normalizeFeatureMap(features = {}) {
  const out = {}
  ;(featureCatalog.value || []).forEach((f) => {
    out[f.key] = features?.[f.key] !== false
  })
  return out
}

const filteredTenants = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return tenants.value
  return (tenants.value || []).filter((tenant) => {
    return [tenant.name, tenant.slug, `#${tenant.id}`]
      .join(' ')
      .toLowerCase()
      .includes(q)
  })
})

const activeTenant = computed(() => {
  const id = String(activeTenantId.value || '')
  return (tenants.value || []).find((t) => String(t.id) === id) || null
})

const enabledFeatureCount = computed(() => {
  return Object.values(featureDraft.value || {}).filter(Boolean).length
})

const isTenantDirty = computed(() => {
  if (!activeTenant.value) return false
  return (
    (tenantForm.value.name || '').trim() !== (activeTenant.value.name || '').trim()
    || normalizeStatus(tenantForm.value.status) !== normalizeStatus(activeTenant.value.status)
  )
})

const isFeatureDirty = computed(() => {
  if (!activeTenant.value) return false
  return (
    JSON.stringify(normalizeFeatureMap(featureDraft.value || {}))
    !== JSON.stringify(normalizeFeatureMap(activeTenant.value.features || {}))
  )
})

watch(
  activeTenant,
  (tenant) => {
    if (!tenant) return
    tenantForm.value = {
      name: tenant.name || '',
      status: normalizeStatus(tenant.status),
    }
    featureDraft.value = normalizeFeatureMap(tenant.features || {})
  },
  { immediate: true }
)

function selectTenant(tenant) {
  if (!tenant) return
  activeTenantId.value = tenant.id
}

function toggleFeature(key) {
  featureDraft.value = {
    ...featureDraft.value,
    [key]: !(featureDraft.value?.[key] !== false),
  }
}

async function loadData() {
  loading.value = true
  try {
    const response = await fetch(`${API_BASE}/tenants`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })

    const result = await response.json().catch(() => null)

    if (!response.ok) {
      alert(result?.message || 'Gagal memuat tenant.')
      tenants.value = []
      return
    }

    featureCatalog.value = Array.isArray(result?.feature_catalog) && result.feature_catalog.length > 0
      ? result.feature_catalog
      : (props.featureCatalog || [])

    tenants.value = Array.isArray(result?.data) ? result.data : []

    const stillExists = activeTenantId.value && tenants.value.some((t) => String(t.id) === String(activeTenantId.value))
    if (!stillExists) {
      activeTenantId.value = tenants.value[0]?.id || null
    }
  } catch (error) {
    console.error('loadData error', error)
    alert('Terjadi kesalahan saat memuat data tenant.')
    tenants.value = []
  } finally {
    loading.value = false
  }
}

async function saveTenant() {
  if (!activeTenant.value || !isTenantDirty.value) return

  const payload = {
    name: (tenantForm.value.name || '').trim(),
    status: normalizeStatus(tenantForm.value.status),
  }

  if (!payload.name) {
    alert('Nama tenant wajib diisi.')
    return
  }

  savingTenant.value = true
  try {
    const response = await fetch(`${API_BASE}/tenants/${activeTenant.value.id}`, {
      method: 'PUT',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
    })

    const result = await response.json().catch(() => null)

    if (!response.ok) {
      alert(result?.message || 'Gagal menyimpan perubahan tenant.')
      return
    }

    const idx = tenants.value.findIndex((t) => String(t.id) === String(activeTenant.value.id))
    if (idx !== -1) {
      tenants.value[idx] = {
        ...tenants.value[idx],
        ...(result?.data || payload),
      }
    }

    alert(result?.message || 'Tenant berhasil diperbarui.')
  } catch (error) {
    console.error('saveTenant error', error)
    alert('Terjadi kesalahan saat menyimpan tenant.')
  } finally {
    savingTenant.value = false
  }
}

async function saveFeatures() {
  if (!activeTenant.value || !isFeatureDirty.value) return

  savingFeatures.value = true
  try {
    const response = await fetch(`${API_BASE}/tenants/${activeTenant.value.id}/features`, {
      method: 'PUT',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        features: normalizeFeatureMap(featureDraft.value),
      }),
    })

    const result = await response.json().catch(() => null)

    if (!response.ok) {
      alert(result?.message || 'Gagal menyimpan fitur tenant.')
      return
    }

    const idx = tenants.value.findIndex((t) => String(t.id) === String(activeTenant.value.id))
    if (idx !== -1) {
      tenants.value[idx] = {
        ...tenants.value[idx],
        features: result?.data?.features || normalizeFeatureMap(featureDraft.value),
      }
    }

    featureDraft.value = normalizeFeatureMap(result?.data?.features || featureDraft.value)
    alert(result?.message || 'Fitur tenant berhasil disimpan.')
  } catch (error) {
    console.error('saveFeatures error', error)
    alert('Terjadi kesalahan saat menyimpan fitur.')
  } finally {
    savingFeatures.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <Head title="Superadmin Tenant Control" />

  <AdminLayout>
    <div class="space-y-6">
      <section class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-900 p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-gray-900 dark:text-white">
              Superadmin Tenant Control
            </h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
              Kelola status tenant dan aktif/nonaktifkan fitur yang bisa dipakai tenant.
            </p>
          </div>
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="loading"
            @click="loadData"
          >
            {{ loading ? 'Memuat...' : 'Refresh Data' }}
          </button>
        </div>
      </section>

      <div v-if="loading" class="rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-900 p-10 flex items-center justify-center gap-3">
        <div class="h-8 w-8 rounded-full border-2 border-primary-600 border-b-transparent animate-spin"></div>
        <span class="text-sm text-gray-600 dark:text-gray-400">Memuat data tenant...</span>
      </div>

      <div v-else class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <section class="xl:col-span-1 rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-900 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-200/70 dark:border-white/10">
            <h2 class="text-base font-black tracking-tight text-gray-900 dark:text-white">Daftar Tenant</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Pilih tenant untuk mengatur akses fiturnya.</p>
            <input
              v-model="search"
              type="text"
              placeholder="Cari tenant..."
              class="mt-3 input w-full text-sm"
            >
          </div>
          <div class="max-h-[560px] overflow-y-auto custom-scrollbar">
            <button
              v-for="tenant in filteredTenants"
              :key="tenant.id"
              type="button"
              @click="selectTenant(tenant)"
              :class="[
                'w-full text-left px-5 py-4 border-b border-gray-100 dark:border-white/5 transition',
                String(activeTenantId) === String(tenant.id)
                  ? 'bg-primary-50/80 dark:bg-primary-900/20'
                  : 'hover:bg-gray-50 dark:hover:bg-white/5',
              ]"
            >
              <div class="flex items-center justify-between gap-2">
                <div class="min-w-0">
                  <p class="font-semibold text-sm text-gray-900 dark:text-white truncate">{{ tenant.name }}</p>
                  <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ tenant.slug || '-' }}</p>
                </div>
                <span
                  :class="[
                    'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase',
                    (tenant.status || '').toLowerCase() === 'active'
                      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
                      : 'bg-gray-100 text-gray-700 dark:bg-gray-700/40 dark:text-gray-300',
                  ]"
                >
                  {{ tenant.status || 'active' }}
                </span>
              </div>
              <div class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                ID: #{{ tenant.id }}
              </div>
            </button>
            <div v-if="filteredTenants.length === 0" class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
              Tenant tidak ditemukan.
            </div>
          </div>
        </section>

        <section class="xl:col-span-2 rounded-2xl border border-gray-200/70 dark:border-white/10 bg-white dark:bg-dark-900 overflow-hidden">
          <div v-if="!activeTenant" class="p-10 text-center text-gray-500 dark:text-gray-400">
            Pilih tenant dari panel kiri.
          </div>

          <template v-else>
            <div class="px-6 py-5 border-b border-gray-200/70 dark:border-white/10 bg-gray-50/70 dark:bg-dark-800/40">
              <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                  <h2 class="text-xl font-black tracking-tight text-gray-900 dark:text-white">
                    {{ activeTenant.name }}
                  </h2>
                  <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Slug: <span class="font-mono">{{ activeTenant.slug || '-' }}</span>
                    • Tenant ID: <span class="font-mono">#{{ activeTenant.id }}</span>
                  </p>
                </div>
                <div class="text-xs text-gray-600 dark:text-gray-400">
                  Fitur aktif: <span class="font-black text-primary-600 dark:text-primary-400">{{ enabledFeatureCount }}</span> / {{ featureCatalog.length }}
                </div>
              </div>
            </div>

            <div class="p-6 space-y-8">
              <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 p-5">
                <h3 class="text-sm font-black text-gray-900 dark:text-white">Info Tenant</h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Atur nama tenant dan status aktif/inaktif.</p>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Nama Tenant</label>
                    <input v-model="tenantForm.name" type="text" class="input w-full" placeholder="Nama tenant">
                  </div>
                  <div>
                    <label class="block text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Status</label>
                    <select v-model="tenantForm.status" class="input w-full">
                      <option value="active">Active</option>
                      <option value="inactive">Inactive</option>
                    </select>
                  </div>
                </div>

                <div class="mt-4 flex justify-end">
                  <button
                    type="button"
                    @click="saveTenant"
                    :disabled="savingTenant || !isTenantDirty"
                    class="btn btn-primary disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {{ savingTenant ? 'Menyimpan...' : 'Simpan Tenant' }}
                  </button>
                </div>
              </div>

              <div class="rounded-2xl border border-gray-200/70 dark:border-white/10 p-5">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                  <div>
                    <h3 class="text-sm font-black text-gray-900 dark:text-white">Kontrol Fitur Tenant</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                      Matikan fitur untuk mencegah akses menu dan endpoint API terkait tenant ini.
                    </p>
                  </div>
                  <button
                    type="button"
                    @click="saveFeatures"
                    :disabled="savingFeatures || !isFeatureDirty"
                    class="btn btn-primary disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {{ savingFeatures ? 'Menyimpan...' : 'Simpan Fitur' }}
                  </button>
                </div>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label
                    v-for="feature in featureCatalog"
                    :key="feature.key"
                    class="rounded-xl border border-gray-200/70 dark:border-white/10 px-4 py-3 bg-white dark:bg-dark-950 hover:bg-gray-50 dark:hover:bg-dark-900 cursor-pointer transition"
                  >
                    <div class="flex items-start gap-3">
                      <input
                        type="checkbox"
                        class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                        :checked="featureDraft[feature.key] !== false"
                        @change="toggleFeature(feature.key)"
                      >
                      <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ feature.name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ feature.description }}</p>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1 font-mono">{{ feature.key }}</p>
                      </div>
                    </div>
                  </label>
                </div>
              </div>
            </div>
          </template>
        </section>
      </div>
    </div>
  </AdminLayout>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const API_BASE = '/api/v1'

const roles = ref([])
const permissions = ref([])
const loadingRoles = ref(false)
const loadingPermissions = ref(false)
const processing = ref(false)

const permSearchQuery = ref('')

const showAdvanced = ref(false)

const mode = ref('none') // none | create | edit
const activeRoleId = ref(null)

const form = ref({
  id: null,
  name: '',
  permissions: [],
})

const originalForm = ref(null)
const originalSnapshot = ref('')

const SYSTEM_ROLE_NAMES = ['admin', 'owner']

const MATRIX_COLUMNS = [
  { key: 'view', label: 'Lihat' },
  { key: 'create', label: 'Tambah' },
  { key: 'edit', label: 'Edit' },
  { key: 'delete', label: 'Hapus' },
  { key: 'approve', label: 'Approve' },
  { key: 'export', label: 'Export' },
  { key: 'manage', label: 'Manage' },
]

const MODULE_MATRIX = [
  {
    key: 'dashboard',
    label: 'Dashboard',
    description: 'Ringkasan & statistik.',
    perms: { view: 'view dashboard' },
  },
  {
    key: 'installations',
    label: 'Pasang Baru',
    description: 'Instalasi, monitoring, approval.',
    perms: {
      view: 'view installations',
      create: 'create installations',
      edit: 'edit installations',
      delete: 'delete installations',
      approve: 'approve installations',
    },
    extras: [
      { label: 'Kirim rekap', perm: 'send installations recap' },
      { label: 'Riwayat', perm: 'view riwayat installations' },
    ],
  },
  {
    key: 'team',
    label: 'Tim',
    description: 'Teknisi/sales/staff.',
    perms: {
      view: 'view team',
      create: 'create team',
      edit: 'edit team',
      delete: 'delete team',
      manage: 'manage team',
    },
  },
  {
    key: 'teknisi',
    label: 'Teknisi',
    description: 'Tugas, riwayat, rekap.',
    perms: {
      view: 'view teknisi',
      edit: 'edit teknisi',
    },
    extras: [{ label: 'Kirim rekap', perm: 'send teknisi recap' }],
  },
  {
    key: 'maps',
    label: 'Maps',
    description: 'Tracking teknisi.',
    perms: {
      view: 'view maps',
      manage: 'manage maps',
    },
  },
  {
    key: 'chat',
    label: 'Chat',
    description: 'Chat admin.',
    perms: {
      view: 'view chat',
      create: 'send chat',
      edit: 'edit chat',
      delete: 'delete chat',
    },
  },
  {
    key: 'leads',
    label: 'Leads',
    description: 'Prospek & konversi.',
    perms: {
      view: 'view leads',
      create: 'create leads',
      edit: 'edit leads',
      delete: 'delete leads',
    },
    extras: [
      { label: 'Convert', perm: 'convert leads' },
      { label: 'Bulk', perm: 'bulk leads' },
    ],
  },
  {
    key: 'customers',
    label: 'Pelanggan',
    description: 'Data pelanggan.',
    perms: {
      view: 'view customers',
      create: 'create customers',
      edit: 'edit customers',
      delete: 'delete customers',
    },
  },
  {
    key: 'plans',
    label: 'Paket Layanan',
    description: 'Produk/paket.',
    perms: {
      view: 'view plans',
      create: 'create plans',
      edit: 'edit plans',
      delete: 'delete plans',
    },
  },
  {
    key: 'invoices',
    label: 'Invoice',
    description: 'Tagihan.',
    perms: {
      view: 'view invoices',
      create: 'create invoices',
      edit: 'edit invoices',
      delete: 'delete invoices',
    },
  },
  {
    key: 'payments',
    label: 'Pembayaran',
    description: 'Transaksi.',
    perms: {
      view: 'view payments',
      create: 'create payments',
      edit: 'edit payments',
      delete: 'delete payments',
    },
  },
  {
    key: 'reports',
    label: 'Laporan',
    description: 'Analitik & export.',
    perms: {
      view: 'view reports',
      export: 'export reports',
    },
  },
  {
    key: 'finance',
    label: 'Keuangan',
    description: 'Transaksi & approval.',
    perms: {
      view: 'view finance',
      create: 'create finance',
      edit: 'edit finance',
      delete: 'delete finance',
      approve: 'approve finance',
      export: 'export finance',
      manage: 'manage finance',
    },
  },
  {
    key: 'fiber',
    label: 'Kabel FO',
    description: 'Pemetaan jalur kabel, titik sambungan, dan data putus.',
    perms: {
      view: 'view fiber',
      create: 'create fiber',
      edit: 'edit fiber',
      delete: 'delete fiber',
      manage: 'manage fiber',
    },
  },
  {
    key: 'olts',
    label: 'OLT',
    description: 'Provisioning OLT/ONU.',
    perms: {
      view: 'view olts',
      create: 'create olts',
      edit: 'edit olts',
      delete: 'delete olts',
      manage: 'manage olt',
    },
  },
  {
    key: 'isolir',
    label: 'Isolir',
    description: 'Suspend/unsuspend.',
    perms: {
      manage: 'manage isolir',
    },
  },
  {
    key: 'settings',
    label: 'Pengaturan Sistem',
    description: 'Settings, roles, update.',
    perms: {
      manage: 'manage settings',
    },
    extras: [
      { label: 'Kelola role', perm: 'manage roles' },
      { label: 'Update sistem', perm: 'manage system update' },
    ],
  },
]

function prettyRoleName(name) {
  return (name || '').toString().replace(/_/g, ' ').trim()
}

function isSystemRole(name) {
  const n = (name || '').toString().toLowerCase().trim()
  return SYSTEM_ROLE_NAMES.includes(n)
}

function deepClone(obj) {
  return JSON.parse(JSON.stringify(obj))
}

function uniq(arr) {
  const out = []
  const seen = new Set()
  ;(arr || []).forEach((v) => {
    if (!v || seen.has(v)) return
    seen.add(v)
    out.push(v)
  })
  return out
}

function normalizeSnapshot(v) {
  return {
    name: (v?.name || '').toString().trim(),
    permissions: uniq(v?.permissions || []).slice().sort(),
  }
}

function setOriginalFromForm() {
  originalForm.value = deepClone(form.value)
  originalSnapshot.value = JSON.stringify(normalizeSnapshot(form.value))
}

const isDirty = computed(() => {
  return JSON.stringify(normalizeSnapshot(form.value)) !== originalSnapshot.value
})

function confirmDiscardChanges() {
  if (!isDirty.value) return true
  return confirm('Perubahan belum disimpan. Lanjut dan buang perubahan?')
}

const activeRole = computed(() => {
  if (!activeRoleId.value) return null
  return (roles.value || []).find((r) => String(r.id) === String(activeRoleId.value)) || null
})

const isEditing = computed(() => mode.value === 'edit')
const isCreating = computed(() => mode.value === 'create')

const rolesSorted = computed(() => {
  const list = (roles.value || []).slice()
  list.sort((a, b) => {
    const an = (a?.name || '').toString().toLowerCase()
    const bn = (b?.name || '').toString().toLowerCase()
    const asys = isSystemRole(an)
    const bsys = isSystemRole(bn)
    if (asys !== bsys) return asys ? -1 : 1
    return an.localeCompare(bn)
  })
  return list
})

const systemRoles = computed(() => rolesSorted.value.filter((r) => isSystemRole(r?.name)))
const customRoles = computed(() => rolesSorted.value.filter((r) => !isSystemRole(r?.name)))

function pickRoleById(roleId) {
  const id = (roleId ?? '').toString().trim()
  if (!id) return
  const role = (roles.value || []).find((r) => String(r.id) === id) || null
  if (!role) return
  selectRole(role)
}

async function loadRoles() {
  loadingRoles.value = true
  try {
    const response = await fetch(`${API_BASE}/roles`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    const data = await response.json().catch(() => [])
    roles.value = Array.isArray(data) ? data : []

    // Auto-select a role for first-time load (unless the user is in create mode).
    if (mode.value !== 'create') {
      const stillExists = activeRoleId.value && roles.value.some((r) => String(r.id) === String(activeRoleId.value))
      if (!stillExists) {
        const first = rolesSorted.value[0]
        if (first) {
          selectRole(first, { force: true })
        } else {
          mode.value = 'none'
          activeRoleId.value = null
        }
      }
    }
  } catch (error) {
    console.error('Error loading roles:', error)
    roles.value = []
  } finally {
    loadingRoles.value = false
  }
}

async function loadPermissions() {
  loadingPermissions.value = true
  try {
    const response = await fetch(`${API_BASE}/permissions`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    const data = await response.json().catch(() => [])
    permissions.value = Array.isArray(data) ? data : []
  } catch (error) {
    console.error('Error loading permissions:', error)
    permissions.value = []
  } finally {
    loadingPermissions.value = false
  }
}

function startCreateRole() {
  if (!confirmDiscardChanges()) return

  mode.value = 'create'
  activeRoleId.value = null
  form.value = { id: null, name: '', permissions: [] }
  permSearchQuery.value = ''
  showAdvanced.value = false
  setOriginalFromForm()
}

function selectRole(role, opts = {}) {
  const force = !!opts.force

  if (!force && !confirmDiscardChanges()) return

  mode.value = 'edit'
  activeRoleId.value = role?.id ?? null
  form.value = {
    id: role?.id ?? null,
    name: role?.name ?? '',
    permissions: (role?.permissions || []).map((p) => p.name),
  }
  permSearchQuery.value = ''
  showAdvanced.value = false
  setOriginalFromForm()
}

function duplicateActiveRole() {
  const role = activeRole.value
  if (!role) return
  if (!confirmDiscardChanges()) return

  mode.value = 'create'
  activeRoleId.value = null
  form.value = {
    id: null,
    name: `${role.name}_copy`,
    permissions: (role.permissions || []).map((p) => p.name),
  }
  permSearchQuery.value = ''
  showAdvanced.value = false
  setOriginalFromForm()
}

function resetChanges() {
  if (!originalForm.value) return
  form.value = deepClone(originalForm.value)
}

async function saveRole() {
  const name = (form.value.name || '').toString().trim()
  if (!name) return

  processing.value = true
  try {
    const url = isEditing.value ? `${API_BASE}/roles/${form.value.id}` : `${API_BASE}/roles`
    const method = isEditing.value ? 'PUT' : 'POST'

    const payload = {
      name,
      permissions: uniq(form.value.permissions || []),
    }

    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    })

    const result = await response.json().catch(() => null)

    if (!response.ok) {
      alert((result && (result.message || result.msg)) ? (result.message || result.msg) : 'Gagal menyimpan role')
      return
    }

    // Refresh list and select saved role.
    await loadRoles()
    const savedId = result?.id
    const saved = savedId ? roles.value.find((r) => String(r.id) === String(savedId)) : null
    if (saved) {
      selectRole(saved, { force: true })
    } else if (mode.value !== 'create' && activeRole.value) {
      selectRole(activeRole.value, { force: true })
    }
  } catch (error) {
    console.error('Error saving role:', error)
    alert('Terjadi kesalahan sistem')
  } finally {
    processing.value = false
  }
}

async function deleteActiveRole() {
  const role = activeRole.value
  if (!role) return
  if (isSystemRole(role.name)) return

  if (!confirm(`Yakin ingin menghapus role "${role.name}"? Tindakan ini tidak dapat dibatalkan.`)) return

  processing.value = true
  try {
    const response = await fetch(`${API_BASE}/roles/${role.id}`, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })

    const result = await response.json().catch(() => null)

    if (!response.ok) {
      alert((result && (result.message || result.msg)) ? (result.message || result.msg) : 'Gagal menghapus role')
      return
    }

    mode.value = 'none'
    activeRoleId.value = null
    form.value = { id: null, name: '', permissions: [] }
    permSearchQuery.value = ''
    showAdvanced.value = false
    setOriginalFromForm()

    await loadRoles()
  } catch (error) {
    console.error('Error deleting role:', error)
    alert('Terjadi kesalahan sistem')
  } finally {
    processing.value = false
  }
}

const groupedPermissions = computed(() => {
  const groups = {}
  const query = permSearchQuery.value.toLowerCase().trim()

  ;(permissions.value || []).forEach((p) => {
    if (!p?.name) return
    const pname = p.name.toLowerCase()
    if (query && !pname.includes(query)) return

    const parts = p.name.split(' ')
    const entity = parts.length > 1 ? parts.slice(1).join(' ') : 'System'
    const groupName = entity.charAt(0).toUpperCase() + entity.slice(1)

    if (!groups[groupName]) groups[groupName] = []
    groups[groupName].push(p)
  })

  return Object.keys(groups)
    .sort()
    .reduce((acc, key) => {
      acc[key] = groups[key]
      return acc
    }, {})
})

function togglePermission(name) {
  const list = form.value.permissions || []
  const idx = list.indexOf(name)
  if (idx === -1) list.push(name)
  else list.splice(idx, 1)
  form.value.permissions = list
}

function toggleGroup(perms) {
  const allSelected = perms.every((p) => form.value.permissions.includes(p.name))
  if (allSelected) {
    const remove = new Set(perms.map((p) => p.name))
    form.value.permissions = (form.value.permissions || []).filter((p) => !remove.has(p))
    return
  }

  const next = new Set(form.value.permissions || [])
  perms.forEach((p) => next.add(p.name))
  form.value.permissions = Array.from(next)
}

const permissionNameSet = computed(() => new Set((permissions.value || []).map((p) => p.name)))

function permExists(name) {
  if (!name) return false
  const set = permissionNameSet.value
  if (!set || set.size === 0) return true
  return set.has(name)
}

function matrixPerm(module, colKey) {
  return module?.perms?.[colKey] || null
}

function moduleRowPerms(module) {
  const out = []
  ;(MATRIX_COLUMNS || []).forEach((c) => {
    const p = matrixPerm(module, c.key)
    if (p) out.push(p)
  })
  ;(module?.extras || []).forEach((e) => {
    if (e?.perm) out.push(e.perm)
  })
  const names = uniq(out)
  const set = permissionNameSet.value
  if (!set || set.size === 0) return names
  return names.filter((p) => set.has(p))
}

function rowPermCount(module) {
  return moduleRowPerms(module).length
}

function rowSelectedCount(module) {
  const perms = moduleRowPerms(module)
  if (perms.length === 0) return 0
  const selected = new Set(form.value.permissions || [])
  return perms.filter((p) => selected.has(p)).length
}

function isRowAllSelected(module) {
  const perms = moduleRowPerms(module)
  if (perms.length === 0) return false
  const selected = new Set(form.value.permissions || [])
  return perms.every((p) => selected.has(p))
}

function toggleRowAll(module) {
  const perms = moduleRowPerms(module)
  if (perms.length === 0) return

  const selected = new Set(form.value.permissions || [])
  const allSelected = perms.every((p) => selected.has(p))
  if (allSelected) perms.forEach((p) => selected.delete(p))
  else perms.forEach((p) => selected.add(p))

  form.value.permissions = Array.from(selected)
}

onMounted(() => {
  loadRoles()
  loadPermissions()
  setOriginalFromForm()
})
</script>

<template>
  <Head title="Kelola Role" />

  <AdminLayout>
    <div class="space-y-6 animate-fade-in">
      <!-- Header -->
      <div
        class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white dark:bg-dark-800 p-6 rounded-2xl shadow-card border border-gray-100 dark:border-dark-700"
      >
        <div>
          <h1
            class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-primary-600 to-primary-400"
          >
            Role & Permissions
          </h1>
          <p class="text-gray-500 dark:text-gray-400 mt-1">Atur akses dan keamanan sistem dengan cepat.</p>
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            @click="startCreateRole"
            class="group relative inline-flex items-center justify-center px-5 py-3 text-sm font-medium text-white transition-all duration-200 bg-primary-600 border border-transparent rounded-xl hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 shadow-glow-blue hover:shadow-glow-blue-lg overflow-hidden"
          >
            <span class="absolute inset-0 w-full h-full -mt-1 rounded-lg opacity-30 bg-gradient-to-b from-transparent via-transparent to-black"></span>
            <svg class="w-5 h-5 mr-2 -ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            <span>Role Baru</span>
          </button>
        </div>
      </div>

      <!-- Single Panel -->
      <section class="bg-white dark:bg-dark-800 rounded-2xl border border-gray-100 dark:border-dark-700 shadow-card overflow-hidden">
        <div class="p-5 border-b border-gray-100 dark:border-dark-700 bg-gradient-to-b from-gray-50/80 to-white dark:from-dark-800/60 dark:to-dark-800">
          <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div class="min-w-0">
              <label class="block text-[11px] font-extrabold text-gray-600 dark:text-gray-300 uppercase tracking-wider">
                Role
              </label>

              <div class="mt-1 flex flex-col sm:flex-row sm:items-center gap-2">
                <select
                  :value="activeRoleId ? String(activeRoleId) : ''"
                  @change="pickRoleById($event.target.value)"
                  :disabled="loadingRoles"
                  class="w-full sm:w-[360px] rounded-xl border border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-900 text-gray-900 dark:text-white text-sm px-3 py-2 focus:ring-primary-500 focus:border-primary-500 disabled:opacity-60 disabled:cursor-not-allowed"
                >
                  <option value="" disabled>
                    {{ loadingRoles ? 'Memuat role...' : 'Pilih role...' }}
                  </option>
                  <optgroup v-if="systemRoles.length > 0" label="Sistem">
                    <option v-for="r in systemRoles" :key="r.id" :value="r.id">
                      {{ prettyRoleName(r.name) }} ({{ (r.permissions || []).length }})
                    </option>
                  </optgroup>
                  <optgroup v-if="customRoles.length > 0" label="Custom">
                    <option v-for="r in customRoles" :key="r.id" :value="r.id">
                      {{ prettyRoleName(r.name) }} ({{ (r.permissions || []).length }})
                    </option>
                  </optgroup>
                </select>

                <span
                  v-if="isDirty"
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border border-amber-200/60 dark:border-amber-900/40"
                >
                  Belum disimpan
                </span>
              </div>

              <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                <span v-if="isCreating">Mode: buat role baru.</span>
                <span v-else-if="activeRole">Edit: {{ prettyRoleName(activeRole.name) }}.</span>
                <span v-else>Pilih role dari dropdown untuk mulai mengatur akses.</span>
                <span class="ml-2">Dipilih: {{ form.permissions.length }} permission</span>
                <span v-if="loadingPermissions" class="ml-2">(memuat katalog...)</span>
              </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
              <button
                v-if="isEditing"
                type="button"
                @click="duplicateActiveRole"
                class="hidden sm:inline-flex items-center justify-center px-3 py-2 text-xs font-semibold rounded-xl border border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-900 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-dark-800 transition-colors"
              >
                Duplikat
              </button>

              <button
                v-if="isDirty"
                type="button"
                @click="resetChanges"
                class="hidden sm:inline-flex items-center justify-center px-3 py-2 text-xs font-semibold rounded-xl border border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-900 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-dark-800 transition-colors"
              >
                Reset
              </button>

              <button
                type="button"
                @click="saveRole"
                :disabled="processing || !form.name.trim()"
                class="inline-flex items-center justify-center px-4 py-2 text-xs font-semibold rounded-xl bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-60 disabled:cursor-not-allowed shadow-glow-blue"
              >
                <svg v-if="processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Simpan
              </button>
            </div>
          </div>
        </div>

          <div v-if="mode === 'none'" class="p-10 text-center">
            <div class="max-w-md mx-auto">
              <div class="text-lg font-extrabold text-gray-900 dark:text-white">Pilih role</div>
              <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Pilih role dari dropdown di atas untuk mengubah akses, atau buat role baru.
              </div>
              <button
                type="button"
                @click="startCreateRole"
                class="mt-5 inline-flex items-center justify-center px-5 py-3 text-sm font-semibold rounded-xl bg-primary-600 text-white hover:bg-primary-700 shadow-glow-blue"
              >
                Buat Role Baru
              </button>
            </div>
          </div>

          <div v-else class="p-6 space-y-6">
            <!-- Name -->
            <div>
              <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                Nama Identitas Role <span class="text-red-500">*</span>
              </label>
              <div class="relative rounded-md shadow-sm">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                  </svg>
                </div>
                <input
                  v-model="form.name"
                  type="text"
                  class="input w-full pl-10 py-2.5 border-gray-300 dark:border-dark-600 focus:ring-primary-500 focus:border-primary-500 rounded-lg dark:bg-dark-900 dark:text-white"
                  placeholder="Contoh: staff_admin, supervisor_teknisi"
                  :disabled="isEditing && isSystemRole(activeRole?.name)"
                />
              </div>
              <p
                v-if="isEditing && isSystemRole(activeRole?.name)"
                class="text-xs text-amber-600 mt-2 flex items-center"
              >
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                Role sistem tidak dapat diubah namanya.
              </p>
            </div>

            <!-- Presets -->
            <div>
              <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-3 gap-3">
                <div>
                  <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Matrix Akses per Modul</label>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    Centang permission per aksi. Untuk permission khusus, aktifkan mode lanjutan.
                  </p>
                </div>

                <button
                  type="button"
                  @click="showAdvanced = !showAdvanced"
                  class="inline-flex items-center justify-center px-3 py-2 text-xs font-semibold rounded-xl border border-gray-200 dark:border-dark-700 bg-white dark:bg-dark-900 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-dark-800 transition-colors"
                >
                  <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  {{ showAdvanced ? 'Sembunyikan Mode Lanjutan' : 'Tampilkan Mode Lanjutan' }}
                </button>
              </div>

              <div class="border border-gray-200 dark:border-dark-700 rounded-xl bg-gray-50/50 dark:bg-dark-900/20 overflow-hidden">
                <div class="overflow-x-auto">
                  <table class="min-w-[1040px] w-full text-sm">
                    <thead class="bg-white/60 dark:bg-dark-900/60">
                      <tr class="text-xs font-extrabold text-gray-600 dark:text-gray-300">
                        <th
                          class="sticky left-0 z-10 text-left px-4 py-3 bg-white/60 dark:bg-dark-900/60 border-b border-gray-200 dark:border-dark-700"
                        >
                          Modul
                        </th>
                        <th
                          v-for="col in MATRIX_COLUMNS"
                          :key="col.key"
                          class="px-3 py-3 text-center border-b border-gray-200 dark:border-dark-700 whitespace-nowrap"
                        >
                          {{ col.label }}
                        </th>
                        <th class="px-4 py-3 text-left border-b border-gray-200 dark:border-dark-700">Extra</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-dark-700">
                      <tr
                        v-for="m in MODULE_MATRIX"
                        :key="m.key"
                        class="bg-white dark:bg-dark-900 hover:bg-gray-50 dark:hover:bg-dark-800/60 transition-colors"
                      >
                        <td
                          class="sticky left-0 z-10 px-4 py-3 bg-white dark:bg-dark-900 border-r border-gray-100 dark:border-dark-700"
                        >
                          <div class="flex items-start justify-between gap-3">
                            <div>
                              <div class="text-sm font-bold text-gray-900 dark:text-white">
                                {{ m.label }}
                              </div>
                              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ m.description }}
                              </div>
                              <div class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                                Terpilih: {{ rowSelectedCount(m) }}/{{ rowPermCount(m) }}
                              </div>
                            </div>

                            <label
                              class="inline-flex items-center gap-2 text-[11px] font-semibold text-gray-600 dark:text-gray-300 select-none"
                            >
                              <input
                                type="checkbox"
                                :checked="isRowAllSelected(m)"
                                :disabled="loadingPermissions || rowPermCount(m) === 0"
                                @change="toggleRowAll(m)"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer disabled:cursor-not-allowed"
                              />
                              Semua
                            </label>
                          </div>
                        </td>

                        <td v-for="col in MATRIX_COLUMNS" :key="col.key" class="px-3 py-3 text-center">
                          <template v-if="matrixPerm(m, col.key)">
                            <input
                              type="checkbox"
                              :checked="form.permissions.includes(matrixPerm(m, col.key))"
                              :disabled="loadingPermissions || !permExists(matrixPerm(m, col.key))"
                              @change="togglePermission(matrixPerm(m, col.key))"
                              class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer disabled:cursor-not-allowed"
                            />
                          </template>
                          <span v-else class="text-xs text-gray-300 dark:text-dark-600 select-none">-</span>
                        </td>

                        <td class="px-4 py-3">
                          <div v-if="(m.extras || []).length > 0" class="flex flex-wrap gap-2">
                            <label
                              v-for="ex in m.extras"
                              :key="ex.perm"
                              class="inline-flex items-center gap-2 px-2 py-1 rounded-lg border border-gray-200 dark:border-dark-700 bg-gray-50 dark:bg-dark-800 text-[11px] font-semibold text-gray-700 dark:text-gray-200 select-none"
                            >
                              <input
                                type="checkbox"
                                :checked="form.permissions.includes(ex.perm)"
                                :disabled="loadingPermissions || !permExists(ex.perm)"
                                @change="togglePermission(ex.perm)"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer disabled:cursor-not-allowed"
                              />
                              <span>{{ ex.label }}</span>
                            </label>
                          </div>
                          <div v-else class="text-xs text-gray-400 italic">Tidak ada.</div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-200 dark:border-dark-700">
                  Tips: Gunakan "Semua" untuk toggle satu modul. Untuk permission yang tidak muncul di matrix, gunakan Mode Lanjutan.
                </div>
              </div>
            </div>

            <!-- Advanced -->
            <div v-if="showAdvanced" class="space-y-3">
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Mode Lanjutan: Checklist Permission
                </label>
                <div class="relative w-full sm:w-80">
                  <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                  </div>
                  <input
                    v-model="permSearchQuery"
                    type="text"
                    class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 dark:border-dark-700 rounded-xl focus:ring-primary-500 focus:border-primary-500 dark:bg-dark-900 dark:text-white"
                    placeholder="Cari permission..."
                  />
                </div>
              </div>

              <div class="h-[60vh] sm:h-[420px] overflow-y-auto custom-scrollbar border border-gray-200 dark:border-dark-700 rounded-xl bg-gray-50/50 dark:bg-dark-900/20 p-4 space-y-4">
                <div
                  v-for="(perms, group) in groupedPermissions"
                  :key="group"
                  class="bg-white dark:bg-dark-900 rounded-lg border border-gray-100 dark:border-dark-700 p-4 shadow-sm"
                >
                  <div class="flex items-center justify-between mb-3 border-b border-gray-100 dark:border-dark-700 pb-2">
                    <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider flex items-center">
                      <span class="w-1.5 h-4 bg-primary-500 rounded-full mr-2"></span>
                      {{ group }}
                    </h4>
                    <button
                      type="button"
                      @click="toggleGroup(perms)"
                      class="text-xs text-primary-600 hover:text-primary-700 font-semibold hover:underline"
                    >
                      Toggle Semua
                    </button>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <label
                      v-for="perm in perms"
                      :key="perm.id"
                      class="group flex items-start space-x-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-dark-800 cursor-pointer transition-colors border border-transparent hover:border-gray-200 dark:hover:border-dark-700"
                    >
                      <input
                        type="checkbox"
                        :checked="form.permissions.includes(perm.name)"
                        @change="togglePermission(perm.name)"
                        class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer"
                      />
                      <span class="text-sm text-gray-600 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white transition-colors select-none">
                        {{ perm.name }}
                      </span>
                    </label>
                  </div>
                </div>

                <div v-if="Object.keys(groupedPermissions).length === 0" class="text-center py-12 text-gray-500">
                  <svg class="w-12 h-12 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <p>Tidak ada permission yang cocok dengan pencarian.</p>
                </div>
              </div>

              <div class="flex justify-between items-center text-xs text-gray-500">
                <span>Total Terpilih: {{ form.permissions.length }}</span>
                <span>Total Tersedia: {{ permissions.length }}</span>
              </div>
            </div>

            <!-- Danger -->
            <div v-if="isEditing && !isSystemRole(activeRole?.name)" class="pt-4 border-t border-gray-100 dark:border-dark-700">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <div class="text-sm font-extrabold text-gray-900 dark:text-white">Danger Zone</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Hapus role ini dari sistem.</div>
                </div>
                <button
                  type="button"
                  @click="deleteActiveRole"
                  class="inline-flex justify-center items-center px-4 py-2 text-xs font-semibold text-red-600 bg-red-50 dark:bg-red-900/20 border border-transparent rounded-xl hover:bg-red-100 dark:hover:bg-red-900/30 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                  Hapus Role
                </button>
              </div>
            </div>
          </div>
        </section>
    </div>
  </AdminLayout>
</template>

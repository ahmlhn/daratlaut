<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import axios from 'axios'
import AdminLayout from '../../Layouts/AdminLayout.vue'

const props = defineProps({
  tenantId: { type: Number, default: 1 }
})

const customers = ref([])
const plans = ref([])
const stats = ref({ total: 0, active: 0, suspended: 0, inactive: 0 })
const loading = ref(true)
const search = ref('')
const statusFilter = ref('')
const planFilter = ref('')
const popFilter = ref('')
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 })

// Get initial status from URL query params
onMounted(() => {
  const urlParams = new URLSearchParams(window.location.search)
  const urlStatus = urlParams.get('status')
  if (urlStatus) {
    statusFilter.value = urlStatus
  }
  loadCustomers()
  loadStats()
  loadPlans()
})

// Modal state
const showModal = ref(false)
const modalMode = ref('create') // create | edit
const saving = ref(false)
const formErrors = ref({})

// Form data
const form = ref({
  id: null,
  customer_code: '',
  full_name: '',
  phone: '',
  email: '',
  address: '',
  plan_id: '',
  pop_name: '',
  odp_name: '',
  billing_day: 1,
  grace_days: 7,
  service_status: 'AKTIF',
  notes: '',
})

const resetForm = () => {
  form.value = {
    id: null,
    customer_code: '',
    full_name: '',
    phone: '',
    email: '',
    address: '',
    plan_id: '',
    pop_name: '',
    odp_name: '',
    billing_day: 1,
    grace_days: 7,
    service_status: 'AKTIF',
    notes: '',
  }
  formErrors.value = {}
}

const statusOptions = [
  { value: '', label: 'Semua Status' },
  { value: 'AKTIF', label: 'Aktif' },
  { value: 'SUSPEND', label: 'Suspend' },
  { value: 'NONAKTIF', label: 'Nonaktif' },
]

// Page title based on filter
const pageTitle = computed(() => {
  if (statusFilter.value === 'AKTIF') return 'Pelanggan Aktif'
  if (statusFilter.value === 'SUSPEND') return 'Pelanggan Suspend'
  if (statusFilter.value === 'NONAKTIF') return 'Pelanggan Nonaktif'
  return 'Semua Pelanggan'
})

const loadCustomers = async (page = 1) => {
  loading.value = true
  try {
    const params = { page, per_page: 20, tenant_id: props.tenantId }
    if (search.value) params.q = search.value
    if (statusFilter.value) params.status = statusFilter.value
    if (planFilter.value) params.plan_id = planFilter.value
    if (popFilter.value) params.pop_name = popFilter.value
    
    const res = await axios.get('/api/v1/customers', { params })
    customers.value = res.data.data || []
    pagination.value = {
      current_page: res.data.meta?.current_page || 1,
      last_page: res.data.meta?.last_page || 1,
      total: res.data.meta?.total || 0,
      from: ((res.data.meta?.current_page - 1) * res.data.meta?.per_page) + 1,
      to: Math.min(res.data.meta?.current_page * res.data.meta?.per_page, res.data.meta?.total),
    }
  } catch (err) {
    console.error('Failed to load customers:', err)
  } finally {
    loading.value = false
  }
}

const loadStats = async () => {
  try {
    const res = await axios.get('/api/v1/customers/stats', { params: { tenant_id: props.tenantId } })
    stats.value = {
      total: res.data.total || 0,
      active: res.data.active || 0,
      suspended: res.data.suspended || 0,
      inactive: res.data.inactive || 0,
    }
  } catch (err) {
    console.error('Failed to load customer stats:', err)
  }
}

const loadPlans = async () => {
  try {
    const res = await axios.get('/api/v1/plans', { params: { tenant_id: props.tenantId, active: 1 } })
    plans.value = res.data.data || []
  } catch (err) {
    console.error('Failed to load plans:', err)
    // Fallback to empty
    plans.value = []
  }
}

const doSearch = () => loadCustomers(1)
const changePage = (page) => loadCustomers(page)

// Open create modal
const openCreate = () => {
  resetForm()
  modalMode.value = 'create'
  // Generate customer code
  const timestamp = Date.now().toString().slice(-6)
  form.value.customer_code = `CUST-${timestamp}`
  showModal.value = true
}

// Open edit modal
const openEdit = (customer) => {
  resetForm()
  modalMode.value = 'edit'
  form.value = {
    id: customer.id,
    customer_code: customer.customer_code,
    full_name: customer.full_name,
    phone: customer.phone || '',
    email: customer.email || '',
    address: customer.address || '',
    plan_id: customer.plan_id || '',
    pop_name: customer.pop_name || '',
    odp_name: customer.odp_name || '',
    billing_day: customer.billing_day || 1,
    grace_days: customer.grace_days || 7,
    service_status: customer.service_status || 'AKTIF',
    notes: customer.notes || '',
  }
  showModal.value = true
}

// Save customer
const saveCustomer = async () => {
  saving.value = true
  formErrors.value = {}
  
  try {
    const payload = {
      ...form.value,
      tenant_id: props.tenantId,
      plan_id: form.value.plan_id || null,
    }
    
    if (modalMode.value === 'create') {
      await axios.post('/api/v1/customers', payload)
    } else {
      await axios.put(`/api/v1/customers/${form.value.id}`, payload)
    }
    
    showModal.value = false
    loadCustomers(pagination.value.current_page)
    loadStats()
  } catch (err) {
    if (err.response?.status === 422) {
      formErrors.value = err.response.data.errors || {}
    } else {
      alert('Gagal menyimpan: ' + (err.response?.data?.message || err.message))
    }
  } finally {
    saving.value = false
  }
}

// Delete customer
const deleteCustomer = async (customer) => {
  if (!confirm(`Hapus pelanggan "${customer.full_name}"?`)) return
  
  try {
    await axios.delete(`/api/v1/customers/${customer.id}?tenant_id=${props.tenantId}`)
    loadCustomers(pagination.value.current_page)
    loadStats()
  } catch (err) {
    alert('Gagal menghapus: ' + (err.response?.data?.message || err.message))
  }
}

const statusClass = (status) => {
  switch (status) {
    case 'AKTIF': return 'bg-emerald-100 text-emerald-700'
    case 'SUSPEND': return 'bg-amber-100 text-amber-700'
    case 'NONAKTIF': return 'bg-slate-200 text-slate-700'
    default: return 'bg-gray-100 text-gray-700'
  }
}

const modalTitle = computed(() => modalMode.value === 'create' ? 'Tambah Customer' : 'Edit Customer')
</script>

<template>
  <AdminLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900">{{ pageTitle }}</h1>
          <p class="mt-1 text-sm text-gray-500">Kelola data pelanggan</p>
        </div>
        <button @click="openCreate" class="btn btn-primary">
          <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          Tambah Customer
        </button>
      </div>

      <!-- Stats cards -->
      <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div class="card flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-600 dark:bg-primary-500/20 dark:text-primary-300">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197" />
            </svg>
          </div>
          <div>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Total User</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ stats.total }}</p>
          </div>
        </div>
        <div class="card flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <div>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">User Aktif</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ stats.active }}</p>
          </div>
        </div>
        <div class="card flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3" />
            </svg>
          </div>
          <div>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">User Suspend</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ stats.suspended }}</p>
          </div>
        </div>
        <div class="card flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
          <div>
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">User Nonaktif</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ stats.inactive }}</p>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="card">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
          <div class="md:col-span-2">
            <input
              v-model="search"
              @keyup.enter="doSearch"
              type="search"
              placeholder="Cari nama, kode, profile, POP..."
              class="input"
            />
          </div>
          <select v-model="statusFilter" @change="doSearch" class="input">
            <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
          <select v-model="planFilter" @change="doSearch" class="input">
            <option value="">Semua Paket</option>
            <option v-for="plan in plans" :key="plan.id" :value="plan.id">
              {{ plan.name }}
            </option>
          </select>
          <input
            v-model="popFilter"
            @keyup.enter="doSearch"
            type="text"
            placeholder="POP"
            class="input"
          />
          <button @click="doSearch" class="btn btn-primary">Cari</button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="card text-center py-8">
        <div class="animate-spin h-8 w-8 border-4 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
        <p class="mt-2 text-gray-500">Memuat data...</p>
      </div>

      <!-- Table -->
      <div v-else class="card overflow-hidden !p-0">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50 dark:bg-dark-800">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Kode</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Nama</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Paket</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">POP</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tgl Invoice</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
              <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-white/10 bg-white dark:bg-dark-900">
            <tr v-if="customers.length === 0">
              <td colspan="7" class="px-6 py-8 text-center text-gray-500">Belum ada data customer.</td>
            </tr>
            <tr v-for="row in customers" :key="row.id" class="hover:bg-gray-50 dark:hover:bg-dark-800">
              <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-primary-600">{{ row.customer_code }}</td>
              <td class="whitespace-nowrap px-6 py-4">
                <div class="text-sm text-gray-900">{{ row.full_name }}</div>
                <div v-if="row.phone" class="text-xs text-gray-500">{{ row.phone }}</div>
              </td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ row.plan?.name || '-' }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ row.pop_name || '-' }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">Tgl {{ row.billing_day || 1 }}</td>
              <td class="whitespace-nowrap px-6 py-4">
                <span :class="['inline-flex rounded-full px-2 py-1 text-xs font-medium', statusClass(row.service_status)]">
                  {{ row.service_status }}
                </span>
              </td>
              <td class="whitespace-nowrap px-6 py-4 text-right text-sm space-x-2">
                <button @click="openEdit(row)" class="text-primary-600 hover:text-primary-900">Edit</button>
                <button @click="deleteCustomer(row)" class="text-red-600 hover:text-red-900">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-gray-200 dark:border-white/10 bg-white dark:bg-dark-900 px-4 py-3 sm:px-6">
          <div class="text-sm text-gray-700 dark:text-gray-300">
            Menampilkan {{ pagination.from }}-{{ pagination.to }} dari {{ pagination.total }} data
          </div>
          <div class="flex gap-2">
            <button 
              @click="changePage(pagination.current_page - 1)"
              :disabled="pagination.current_page === 1"
              class="btn btn-secondary text-sm disabled:opacity-50"
            >Prev</button>
            <span class="px-3 py-2 text-sm">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
            <button 
              @click="changePage(pagination.current_page + 1)"
              :disabled="pagination.current_page === pagination.last_page"
              class="btn btn-secondary text-sm disabled:opacity-50"
            >Next</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-screen items-center justify-center p-4">
          <!-- Backdrop -->
          <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
          
          <!-- Modal content -->
          <div class="relative w-full max-w-2xl rounded-lg bg-white dark:bg-dark-900 shadow-xl">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-gray-200 dark:border-white/10 px-6 py-4">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ modalTitle }}</h3>
              <button @click="showModal = false" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            
            <!-- Body -->
            <form @submit.prevent="saveCustomer" class="p-6">
              <div class="grid gap-4 sm:grid-cols-2">
                <!-- Customer Code -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Kode Customer *</label>
                  <input v-model="form.customer_code" type="text" class="input mt-1" required />
                  <p v-if="formErrors.customer_code" class="mt-1 text-sm text-red-600">{{ formErrors.customer_code[0] }}</p>
                </div>
                
                <!-- Full Name -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Nama Lengkap *</label>
                  <input v-model="form.full_name" type="text" class="input mt-1" required />
                  <p v-if="formErrors.full_name" class="mt-1 text-sm text-red-600">{{ formErrors.full_name[0] }}</p>
                </div>
                
                <!-- Phone -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">No. Telepon</label>
                  <input v-model="form.phone" type="text" class="input mt-1" placeholder="08xxxxxxxxxx" />
                </div>
                
                <!-- Email -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Email</label>
                  <input v-model="form.email" type="email" class="input mt-1" />
                  <p v-if="formErrors.email" class="mt-1 text-sm text-red-600">{{ formErrors.email[0] }}</p>
                </div>
                
                <!-- Plan -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Paket Layanan</label>
                  <select v-model="form.plan_id" class="input mt-1">
                    <option value="">-- Pilih Paket --</option>
                    <option v-for="plan in plans" :key="plan.id" :value="plan.id">
                      {{ plan.name }} - Rp {{ plan.price.toLocaleString('id-ID') }}
                    </option>
                  </select>
                </div>
                
                <!-- Status -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Status</label>
                  <select v-model="form.service_status" class="input mt-1">
                    <option value="AKTIF">Aktif</option>
                    <option value="SUSPEND">Suspend</option>
                    <option value="NONAKTIF">Nonaktif</option>
                  </select>
                </div>
                
                <!-- POP -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">POP</label>
                  <input v-model="form.pop_name" type="text" class="input mt-1" placeholder="Nama POP" />
                </div>
                
                <!-- ODP -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">ODP</label>
                  <input v-model="form.odp_name" type="text" class="input mt-1" placeholder="Nama ODP" />
                </div>
                
                <!-- Billing Day -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Tanggal Invoice</label>
                  <input v-model.number="form.billing_day" type="number" min="1" max="28" class="input mt-1" />
                </div>
                
                <!-- Grace Days -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Masa Tenggang (hari)</label>
                  <input v-model.number="form.grace_days" type="number" min="0" class="input mt-1" />
                </div>
                
                <!-- Address -->
                <div class="sm:col-span-2">
                  <label class="block text-sm font-medium text-gray-700">Alamat</label>
                  <textarea v-model="form.address" rows="2" class="input mt-1"></textarea>
                </div>
                
                <!-- Notes -->
                <div class="sm:col-span-2">
                  <label class="block text-sm font-medium text-gray-700">Catatan</label>
                  <textarea v-model="form.notes" rows="2" class="input mt-1"></textarea>
                </div>
              </div>
              
              <!-- Footer -->
              <div class="mt-6 flex justify-end gap-3">
                <button type="button" @click="showModal = false" class="btn btn-secondary">Batal</button>
                <button type="submit" :disabled="saving" class="btn btn-primary">
                  <span v-if="saving">Menyimpan...</span>
                  <span v-else>{{ modalMode === 'create' ? 'Simpan' : 'Update' }}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </Teleport>
  </AdminLayout>
</template>

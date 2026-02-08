<script setup>
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import { Head } from '@inertiajs/vue3'
import AdminLayout from '../../Layouts/AdminLayout.vue'

const props = defineProps({
  tenantId: { type: Number, default: 1 }
})

const plans = ref([])
const loading = ref(true)

// Modal state
const showModal = ref(false)
const modalMode = ref('create')
const saving = ref(false)
const formErrors = ref({})

// Form data
const form = ref({
  id: null,
  code: '',
  name: '',
  price: 0,
  billing_cycle: 'monthly',
  is_active: true,
})

const resetForm = () => {
  form.value = {
    id: null,
    code: '',
    name: '',
    price: 0,
    billing_cycle: 'monthly',
    is_active: true,
  }
  formErrors.value = {}
}

const loadPlans = async () => {
  loading.value = true
  try {
    const res = await axios.get('/api/v1/plans', { params: { tenant_id: props.tenantId } })
    plans.value = res.data.data || []
  } catch (err) {
    console.error('Failed to load plans:', err)
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  resetForm()
  // Generate code
  form.value.code = `PLAN-${Date.now().toString().slice(-6)}`
  modalMode.value = 'create'
  showModal.value = true
}

const openEdit = (plan) => {
  resetForm()
  modalMode.value = 'edit'
  form.value = {
    id: plan.id,
    code: plan.code,
    name: plan.name,
    price: plan.price,
    billing_cycle: plan.billing_cycle || 'monthly',
    is_active: plan.is_active,
  }
  showModal.value = true
}

const savePlan = async () => {
  saving.value = true
  formErrors.value = {}
  
  try {
    const payload = {
      ...form.value,
      tenant_id: props.tenantId,
    }
    
    if (modalMode.value === 'create') {
      await axios.post('/api/v1/plans', payload)
    } else {
      await axios.put(`/api/v1/plans/${form.value.id}`, payload)
    }
    
    showModal.value = false
    loadPlans()
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

const deletePlan = async (plan) => {
  if (!confirm(`Hapus paket "${plan.name}"?`)) return
  
  try {
    await axios.delete(`/api/v1/plans/${plan.id}?tenant_id=${props.tenantId}`)
    loadPlans()
  } catch (err) {
    alert('Gagal menghapus: ' + (err.response?.data?.message || err.message))
  }
}

const toggleActive = async (plan) => {
  try {
    await axios.put(`/api/v1/plans/${plan.id}`, {
      tenant_id: props.tenantId,
      is_active: !plan.is_active,
    })
    loadPlans()
  } catch (err) {
    alert('Gagal mengubah status: ' + (err.response?.data?.message || err.message))
  }
}

onMounted(() => loadPlans())

const formatCurrency = (val) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(val)
const modalTitle = computed(() => modalMode.value === 'create' ? 'Tambah Paket' : 'Edit Paket')
</script>

<template>
  <Head title="Paket Layanan" />
  <AdminLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900">Paket Layanan</h1>
          <p class="mt-1 text-sm text-gray-500">Kelola paket internet pelanggan</p>
        </div>
        <button @click="openCreate" class="btn btn-primary">
          <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          Tambah Paket
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="card text-center py-8">
        <div class="animate-spin h-8 w-8 border-4 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
        <p class="mt-2 text-gray-500">Memuat data...</p>
      </div>

      <!-- Plans grid -->
      <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div 
          v-for="plan in plans" 
          :key="plan.id"
          :class="[
            'card relative border-2 transition-shadow hover:shadow-md',
            plan.is_active ? 'border-primary-200' : 'border-gray-200 opacity-60'
          ]"
        >
          <!-- Status badge -->
          <span 
            :class="[
              'absolute top-3 right-3 text-xs font-medium px-2 py-1 rounded-full',
              plan.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
            ]"
          >
            {{ plan.is_active ? 'Aktif' : 'Nonaktif' }}
          </span>
          
          <!-- Content -->
          <div class="pr-16">
            <p class="text-xs text-gray-400 font-mono">{{ plan.code }}</p>
            <h3 class="text-lg font-semibold text-gray-900 mt-1">{{ plan.name }}</h3>
            <p class="text-2xl font-bold text-primary-600 mt-2">{{ formatCurrency(plan.price) }}</p>
            <p class="text-xs text-gray-500 mt-1">per {{ plan.billing_cycle || 'bulan' }}</p>
          </div>
          
          <!-- Actions -->
          <div class="flex gap-2 mt-4 pt-4 border-t">
            <button @click="openEdit(plan)" class="text-sm text-primary-600 hover:text-primary-800">Edit</button>
            <button @click="toggleActive(plan)" class="text-sm text-gray-500 hover:text-gray-700">
              {{ plan.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
            </button>
            <button @click="deletePlan(plan)" class="text-sm text-red-500 hover:text-red-700">Hapus</button>
          </div>
        </div>
        
        <!-- Empty state -->
        <div v-if="plans.length === 0" class="col-span-full card text-center py-8 text-gray-500">
          Belum ada paket layanan. Klik tombol di atas untuk menambah.
        </div>
      </div>
    </div>

    <!-- Modal -->
    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-screen items-center justify-center p-4">
          <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
          
          <div class="relative w-full max-w-md rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b px-6 py-4">
              <h3 class="text-lg font-semibold text-gray-900">{{ modalTitle }}</h3>
              <button @click="showModal = false" class="text-gray-400 hover:text-gray-500">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            
            <form @submit.prevent="savePlan" class="p-6 space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700">Kode Paket *</label>
                <input v-model="form.code" type="text" class="input mt-1" required />
                <p v-if="formErrors.code" class="mt-1 text-sm text-red-600">{{ formErrors.code[0] }}</p>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700">Nama Paket *</label>
                <input v-model="form.name" type="text" placeholder="Contoh: 20 Mbps Unlimited" class="input mt-1" required />
                <p v-if="formErrors.name" class="mt-1 text-sm text-red-600">{{ formErrors.name[0] }}</p>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700">Harga (Rp) *</label>
                <input v-model.number="form.price" type="number" min="0" step="1000" class="input mt-1" required />
                <p v-if="formErrors.price" class="mt-1 text-sm text-red-600">{{ formErrors.price[0] }}</p>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700">Siklus Billing</label>
                <select v-model="form.billing_cycle" class="input mt-1">
                  <option value="monthly">Bulanan</option>
                  <option value="quarterly">3 Bulan</option>
                  <option value="yearly">Tahunan</option>
                </select>
              </div>
              
              <div class="flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox" id="is_active" class="h-4 w-4 rounded border-gray-300 text-primary-600" />
                <label for="is_active" class="text-sm text-gray-700">Paket aktif</label>
              </div>
              
              <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" @click="showModal = false" class="btn btn-secondary">Batal</button>
                <button type="submit" :disabled="saving" class="btn btn-primary">
                  <span v-if="saving">Menyimpan...</span>
                  <span v-else>Simpan</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </Teleport>
  </AdminLayout>
</template>

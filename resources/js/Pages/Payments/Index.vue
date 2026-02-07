<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import axios from 'axios'
import AdminLayout from '../../Layouts/AdminLayout.vue'

const props = defineProps({
  tenantId: { type: Number, default: 1 }
})

const payments = ref([])
const invoices = ref([])
const stats = ref({ total_amount: 0, count: 0 })
const loading = ref(true)
const search = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })

// Modal state
const showModal = ref(false)
const saving = ref(false)
const formErrors = ref({})
const selectedInvoice = ref(null)
const invoiceSearch = ref('')

// Form data
const form = ref({
  invoice_id: '',
  amount: 0,
  payment_date: '',
  payment_method: 'CASH',
  reference_no: '',
  notes: '',
})

const paymentMethods = [
  { value: 'CASH', label: 'Tunai' },
  { value: 'TRANSFER', label: 'Transfer Bank' },
  { value: 'QRIS', label: 'QRIS' },
  { value: 'EWALLET', label: 'E-Wallet' },
  { value: 'OTHER', label: 'Lainnya' },
]

const resetForm = () => {
  const today = new Date().toISOString().split('T')[0]
  form.value = {
    invoice_id: '',
    amount: 0,
    payment_date: today,
    payment_method: 'CASH',
    reference_no: '',
    notes: '',
  }
  selectedInvoice.value = null
  invoiceSearch.value = ''
  formErrors.value = {}
}

const loadPayments = async (page = 1) => {
  loading.value = true
  try {
    const params = { page, per_page: 15, tenant_id: props.tenantId }
    if (search.value) params.q = search.value
    if (dateFrom.value) params.date_from = dateFrom.value
    if (dateTo.value) params.date_to = dateTo.value
    
    const res = await axios.get('/api/v1/payments', { params })
    payments.value = res.data.data || []
    pagination.value = {
      current_page: res.data.meta?.current_page || 1,
      last_page: res.data.meta?.last_page || 1,
      total: res.data.meta?.total || 0
    }
  } catch (err) {
    console.error('Failed to load payments:', err)
  } finally {
    loading.value = false
  }
}

const loadStats = async () => {
  try {
    const res = await axios.get('/api/v1/payments/stats', { params: { tenant_id: props.tenantId } })
    stats.value = res.data.data || { total_amount: 0, count: 0 }
  } catch (err) {
    console.error('Failed to load stats:', err)
  }
}

const loadInvoices = async () => {
  try {
    // Load unpaid/partial invoices for dropdown
    const res = await axios.get('/api/v1/invoices', { 
      params: { per_page: 500, status: 'OPEN', tenant_id: props.tenantId } 
    })
    invoices.value = res.data.data || []
  } catch (err) {
    console.error('Failed to load invoices:', err)
  }
}

const doSearch = () => loadPayments(1)
const changePage = (page) => loadPayments(page)

// Open create modal
const openCreate = () => {
  resetForm()
  showModal.value = true
}

// Select invoice
const selectInvoice = (inv) => {
  selectedInvoice.value = inv
  form.value.invoice_id = inv.id
  form.value.amount = inv.total_amount - inv.paid_amount
  invoiceSearch.value = ''
}

// Clear selected invoice
const clearInvoice = () => {
  selectedInvoice.value = null
  form.value.invoice_id = ''
  form.value.amount = 0
}

// Filtered invoices for search
const filteredInvoices = computed(() => {
  if (!invoiceSearch.value) return invoices.value.slice(0, 10)
  const q = invoiceSearch.value.toLowerCase()
  return invoices.value.filter(inv => 
    inv.invoice_no?.toLowerCase().includes(q) || 
    inv.customer?.full_name?.toLowerCase().includes(q)
  ).slice(0, 10)
})

// Remaining balance for selected invoice
const remainingBalance = computed(() => {
  if (!selectedInvoice.value) return 0
  return selectedInvoice.value.total_amount - selectedInvoice.value.paid_amount
})

// Save payment
const savePayment = async () => {
  saving.value = true
  formErrors.value = {}
  
  try {
    const payload = {
      ...form.value,
      tenant_id: props.tenantId,
    }
    
    await axios.post('/api/v1/payments', payload)
    showModal.value = false
    loadPayments(1)
    loadStats()
    loadInvoices() // Refresh unpaid list
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

// Delete payment
const deletePayment = async (payment) => {
  if (!confirm(`Hapus pembayaran PAY-${String(payment.id).padStart(4, '0')}?`)) return
  
  try {
    await axios.delete(`/api/v1/payments/${payment.id}?tenant_id=${props.tenantId}`)
    loadPayments(pagination.value.current_page)
    loadStats()
    loadInvoices()
  } catch (err) {
    alert('Gagal menghapus: ' + (err.response?.data?.message || err.message))
  }
}

onMounted(() => {
  loadPayments()
  loadStats()
  loadInvoices()
})

const formatCurrency = (val) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(val || 0)
const formatDate = (val) => val ? new Date(val).toLocaleDateString('id-ID') : '-'
const avgPayment = computed(() => stats.value.count > 0 ? stats.value.total_amount / stats.value.count : 0)
</script>

<template>
  <AdminLayout>
    <div class="space-y-6">
      <!-- Page header -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900">Payments</h1>
          <p class="mt-1 text-sm text-gray-500">Riwayat pembayaran pelanggan</p>
        </div>
        <button @click="openCreate" class="btn btn-primary">
          <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          Catat Pembayaran
        </button>
      </div>

      <!-- Stats -->
      <div class="grid gap-4 sm:grid-cols-3">
        <div class="card bg-gradient-to-br from-teal-500 to-teal-600 text-white">
          <p class="text-sm font-medium text-teal-100">Total Bulan Ini</p>
          <p class="mt-1 text-2xl font-bold">{{ formatCurrency(stats.total_amount) }}</p>
        </div>
        <div class="card">
          <p class="text-sm font-medium text-gray-500">Transaksi</p>
          <p class="mt-1 text-2xl font-semibold text-gray-900">{{ stats.count }}</p>
        </div>
        <div class="card">
          <p class="text-sm font-medium text-gray-500">Rata-rata</p>
          <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatCurrency(avgPayment) }}</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="card">
        <div class="flex flex-col gap-4 sm:flex-row">
          <div class="flex-1">
            <input
              v-model="search"
              @keyup.enter="doSearch"
              type="search"
              placeholder="Cari pembayaran..."
              class="input"
            />
          </div>
          <input v-model="dateFrom" @change="doSearch" type="date" class="input sm:w-40" title="Dari tanggal" />
          <input v-model="dateTo" @change="doSearch" type="date" class="input sm:w-40" title="Sampai tanggal" />
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
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">ID</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Invoice</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Pelanggan</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Jumlah</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Metode</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tanggal</th>
              <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 bg-white">
            <tr v-if="payments.length === 0">
              <td colspan="7" class="px-6 py-8 text-center text-gray-500">Tidak ada pembayaran ditemukan</td>
            </tr>
            <tr v-for="pay in payments" :key="pay.id" class="hover:bg-gray-50">
              <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">PAY-{{ String(pay.id).padStart(4, '0') }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-primary-600">{{ pay.invoice?.invoice_no || '-' }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ pay.customer?.full_name || '-' }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-teal-600">{{ formatCurrency(pay.amount) }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ pay.payment_method || '-' }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ formatDate(pay.payment_date) }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                <button @click="deletePayment(pay)" class="text-red-600 hover:text-red-900">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6">
          <div class="text-sm text-gray-700">
            Total <span class="font-medium">{{ pagination.total }}</span> pembayaran
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

    <!-- Record Payment Modal -->
    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-screen items-center justify-center p-4">
          <!-- Backdrop -->
          <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
          
          <!-- Modal content -->
          <div class="relative w-full max-w-lg rounded-lg bg-white shadow-xl">
            <!-- Header -->
            <div class="flex items-center justify-between border-b px-6 py-4">
              <h3 class="text-lg font-semibold text-gray-900">Catat Pembayaran</h3>
              <button @click="showModal = false" class="text-gray-400 hover:text-gray-500">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            
            <!-- Body -->
            <form @submit.prevent="savePayment" class="p-6 space-y-4">
              <!-- Invoice Selection -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Pilih Invoice *</label>
                <div v-if="selectedInvoice" class="mt-1 flex items-center gap-2 p-3 bg-teal-50 border border-teal-200 rounded-lg">
                  <div class="flex-1">
                    <p class="font-medium text-teal-700">{{ selectedInvoice.invoice_no }}</p>
                    <p class="text-sm text-teal-600">{{ selectedInvoice.customer?.full_name }}</p>
                    <p class="text-sm text-gray-500">Sisa: {{ formatCurrency(remainingBalance) }}</p>
                  </div>
                  <button type="button" @click="clearInvoice" class="text-gray-400 hover:text-red-500">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
                <div v-else class="mt-1">
                  <input 
                    v-model="invoiceSearch" 
                    type="text" 
                    placeholder="Cari nomor invoice atau nama pelanggan..." 
                    class="input"
                  />
                  <div v-if="filteredInvoices.length" class="mt-2 max-h-48 overflow-y-auto border rounded-lg divide-y">
                    <button 
                      v-for="inv in filteredInvoices" 
                      :key="inv.id"
                      type="button"
                      @click="selectInvoice(inv)"
                      class="w-full text-left px-3 py-2 hover:bg-gray-50"
                    >
                      <span class="font-medium text-primary-600">{{ inv.invoice_no }}</span>
                      <span class="text-gray-500 ml-2">{{ inv.customer?.full_name }}</span>
                      <span class="float-right text-sm text-gray-500">{{ formatCurrency(inv.total_amount - inv.paid_amount) }}</span>
                    </button>
                  </div>
                  <p v-else-if="invoiceSearch && !filteredInvoices.length" class="mt-2 text-sm text-gray-500">Tidak ada invoice ditemukan</p>
                </div>
                <p v-if="formErrors.invoice_id" class="mt-1 text-sm text-red-600">{{ formErrors.invoice_id[0] }}</p>
              </div>
              
              <!-- Amount -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Jumlah Bayar *</label>
                <input v-model.number="form.amount" type="number" min="0" class="input mt-1" required />
                <p v-if="formErrors.amount" class="mt-1 text-sm text-red-600">{{ formErrors.amount[0] }}</p>
              </div>
              
              <!-- Payment Date -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Tanggal Bayar *</label>
                <input v-model="form.payment_date" type="date" class="input mt-1" required />
              </div>
              
              <!-- Payment Method -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Metode Pembayaran *</label>
                <select v-model="form.payment_method" class="input mt-1" required>
                  <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
              </div>
              
              <!-- Reference Number -->
              <div>
                <label class="block text-sm font-medium text-gray-700">No. Referensi</label>
                <input v-model="form.reference_no" type="text" placeholder="No. transfer / kode bukti" class="input mt-1" />
              </div>
              
              <!-- Notes -->
              <div>
                <label class="block text-sm font-medium text-gray-700">Catatan</label>
                <textarea v-model="form.notes" rows="2" class="input mt-1"></textarea>
              </div>
              
              <!-- Footer -->
              <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" @click="showModal = false" class="btn btn-secondary">Batal</button>
                <button type="submit" :disabled="saving || !form.invoice_id" class="btn btn-primary disabled:opacity-50">
                  <span v-if="saving">Menyimpan...</span>
                  <span v-else>Simpan Pembayaran</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </Teleport>
  </AdminLayout>
</template>

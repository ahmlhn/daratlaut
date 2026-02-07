<script setup>
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import AdminLayout from '../../Layouts/AdminLayout.vue'

const props = defineProps({
  tenantId: { type: Number, default: 1 }
})

const invoices = ref([])
const customers = ref([])
const loading = ref(true)
const search = ref('')
const statusFilter = ref('')
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })

// Modal state
const showModal = ref(false)
const saving = ref(false)
const formErrors = ref({})
const showGatewayModal = ref(false)
const gatewayLoading = ref(false)
const gatewayError = ref('')
const gatewayMode = ref('link')
const gatewayData = ref({ payment_url: '', qr_string: '', order_id: '', expiry_time: '' })
const selectedInvoice = ref(null)

// Form data
const form = ref({
  customer_id: '',
  period_key: '',
  issue_date: '',
  due_date: '',
  items: [{ description: '', quantity: 1, unit_price: 0, item_type: 'PLAN' }],
  discount_amount: 0,
  tax_amount: 0,
  notes: '',
})

const resetForm = () => {
  const today = new Date()
  const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, today.getDate())
  form.value = {
    customer_id: '',
    period_key: `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`,
    issue_date: today.toISOString().split('T')[0],
    due_date: nextMonth.toISOString().split('T')[0],
    items: [{ description: 'Biaya Langganan', quantity: 1, unit_price: 0, item_type: 'PLAN' }],
    discount_amount: 0,
    tax_amount: 0,
    notes: '',
  }
  formErrors.value = {}
}

// Computed subtotal
const subtotal = computed(() => {
  return form.value.items.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0)
})

const total = computed(() => {
  return subtotal.value - form.value.discount_amount + form.value.tax_amount
})

const loadInvoices = async (page = 1) => {
  loading.value = true
  try {
    const params = { page, per_page: 15, tenant_id: props.tenantId }
    if (search.value) params.q = search.value
    if (statusFilter.value) params.status = statusFilter.value
    
    const res = await axios.get('/api/v1/invoices', { params })
    invoices.value = res.data.data || []
    pagination.value = {
      current_page: res.data.meta?.current_page || 1,
      last_page: res.data.meta?.last_page || 1,
      total: res.data.meta?.total || 0
    }
  } catch (err) {
    console.error('Failed to load invoices:', err)
  } finally {
    loading.value = false
  }
}

const loadCustomers = async () => {
  try {
    const res = await axios.get('/api/v1/customers', { params: { per_page: 1000, tenant_id: props.tenantId } })
    customers.value = res.data.data || []
  } catch (err) {
    console.error('Failed to load customers:', err)
  }
}

const doSearch = () => loadInvoices(1)
const changePage = (page) => loadInvoices(page)

// Open create modal
const openCreate = () => {
  resetForm()
  showModal.value = true
}

// Add item row
const addItem = () => {
  form.value.items.push({ description: '', quantity: 1, unit_price: 0, item_type: 'OTHER' })
}

// Remove item row
const removeItem = (index) => {
  if (form.value.items.length > 1) {
    form.value.items.splice(index, 1)
  }
}

// Save invoice
const saveInvoice = async () => {
  saving.value = true
  formErrors.value = {}
  
  try {
    const payload = {
      ...form.value,
      tenant_id: props.tenantId,
    }
    
    await axios.post('/api/v1/invoices', payload)
    showModal.value = false
    loadInvoices(1)
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

// Delete invoice
const deleteInvoice = async (invoice) => {
  if (!confirm(`Hapus invoice "${invoice.invoice_no}"?`)) return
  
  try {
    await axios.delete(`/api/v1/invoices/${invoice.id}?tenant_id=${props.tenantId}`)
    loadInvoices(pagination.value.current_page)
  } catch (err) {
    alert('Gagal menghapus: ' + (err.response?.data?.message || err.message))
  }
}

const openGatewayModal = (invoice, mode) => {
  selectedInvoice.value = invoice
  gatewayMode.value = mode
  gatewayError.value = ''
  gatewayData.value = { payment_url: '', qr_string: '', order_id: '', expiry_time: '' }
  showGatewayModal.value = true
  if (mode === 'link') {
    createPaymentLink(invoice)
  } else {
    createQris(invoice)
  }
}

const createPaymentLink = async (invoice) => {
  gatewayLoading.value = true
  gatewayError.value = ''
  try {
    const res = await axios.post(`/api/v1/invoices/${invoice.id}/pay`, { tenant_id: props.tenantId })
    if (res.data?.success) {
      gatewayData.value.payment_url = res.data.payment_url
      gatewayData.value.order_id = res.data.order_id
    } else {
      gatewayError.value = res.data?.error || 'Gagal membuat payment link'
    }
  } catch (err) {
    gatewayError.value = err.response?.data?.error || err.message || 'Gagal membuat payment link'
  } finally {
    gatewayLoading.value = false
  }
}

const createQris = async (invoice) => {
  gatewayLoading.value = true
  gatewayError.value = ''
  try {
    const res = await axios.post(`/api/v1/invoices/${invoice.id}/qris`, { tenant_id: props.tenantId })
    if (res.data?.success) {
      gatewayData.value.qr_string = res.data.qr_string
      gatewayData.value.order_id = res.data.order_id
      gatewayData.value.expiry_time = res.data.expiry_time
    } else {
      gatewayError.value = res.data?.error || 'Gagal membuat QRIS'
    }
  } catch (err) {
    gatewayError.value = err.response?.data?.error || err.message || 'Gagal membuat QRIS'
  } finally {
    gatewayLoading.value = false
  }
}

const closeGatewayModal = () => {
  showGatewayModal.value = false
  gatewayLoading.value = false
  gatewayError.value = ''
  selectedInvoice.value = null
  gatewayData.value = { payment_url: '', qr_string: '', order_id: '', expiry_time: '' }
}

const canUseGateway = (status) => ['OPEN', 'OVERDUE', 'PARTIAL'].includes(status)

// Get initial status from URL query params
onMounted(() => {
  const urlParams = new URLSearchParams(window.location.search)
  const urlStatus = urlParams.get('status')
  if (urlStatus) {
    statusFilter.value = urlStatus
  }
  loadInvoices()
  loadCustomers()
})

// Page title based on filter
const pageTitle = computed(() => {
  if (statusFilter.value === 'OPEN') return 'Invoice Belum Bayar'
  if (statusFilter.value === 'PAID') return 'Invoice Lunas'
  if (statusFilter.value === 'OVERDUE') return 'Invoice Overdue'
  return 'Semua Invoice'
})

const statusClass = (status) => {
  switch (status) {
    case 'PAID': return 'bg-green-100 text-green-700'
    case 'OPEN': return 'bg-yellow-100 text-yellow-700'
    case 'PARTIAL': return 'bg-blue-100 text-blue-700'
    case 'OVERDUE': return 'bg-red-100 text-red-700'
    case 'VOID': return 'bg-gray-100 text-gray-700'
    default: return 'bg-gray-100 text-gray-700'
  }
}

const formatCurrency = (val) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(val)
const formatDate = (val) => val ? new Date(val).toLocaleDateString('id-ID') : '-'
</script>

<template>
  <AdminLayout>
    <div class="space-y-6">
      <!-- Page header -->
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900">{{ pageTitle }}</h1>
          <p class="mt-1 text-sm text-gray-500">Kelola invoice pelanggan</p>
        </div>
        <button @click="openCreate" class="btn btn-primary">
          <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          Buat Invoice
        </button>
      </div>

      <!-- Quick filters -->
      <div class="flex flex-wrap gap-2">
        <button 
          @click="statusFilter = ''; doSearch()" 
          :class="['px-4 py-2 rounded-full text-sm font-medium transition', statusFilter === '' ? 'bg-primary-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200']"
        >
          Semua
        </button>
        <button 
          @click="statusFilter = 'OPEN'; doSearch()" 
          :class="['px-4 py-2 rounded-full text-sm font-medium transition', statusFilter === 'OPEN' ? 'bg-yellow-500 text-white' : 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200']"
        >
          Belum Bayar
        </button>
        <button 
          @click="statusFilter = 'OVERDUE'; doSearch()" 
          :class="['px-4 py-2 rounded-full text-sm font-medium transition', statusFilter === 'OVERDUE' ? 'bg-red-500 text-white' : 'bg-red-100 text-red-700 hover:bg-red-200']"
        >
          Overdue
        </button>
        <button 
          @click="statusFilter = 'PAID'; doSearch()" 
          :class="['px-4 py-2 rounded-full text-sm font-medium transition', statusFilter === 'PAID' ? 'bg-green-500 text-white' : 'bg-green-100 text-green-700 hover:bg-green-200']"
        >
          Lunas
        </button>
      </div>

      <!-- Search -->
      <div class="card">
        <div class="flex flex-col gap-4 sm:flex-row">
          <div class="flex-1">
            <input
              v-model="search"
              @keyup.enter="doSearch"
              type="search"
              placeholder="Cari invoice atau pelanggan..."
              class="input"
            />
          </div>
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
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Invoice</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Pelanggan</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Jumlah</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Terbayar</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Jatuh Tempo</th>
              <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
              <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 bg-white">
            <tr v-if="invoices.length === 0">
              <td colspan="7" class="px-6 py-8 text-center text-gray-500">Tidak ada invoice ditemukan</td>
            </tr>
            <tr v-for="inv in invoices" :key="inv.id" class="hover:bg-gray-50">
              <td class="whitespace-nowrap px-6 py-4">
                <div class="text-sm font-medium text-primary-600">{{ inv.invoice_no }}</div>
                <div class="text-xs text-gray-500">{{ inv.period_key }}</div>
              </td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ inv.customer?.full_name || '-' }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">{{ formatCurrency(inv.total_amount) }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ formatCurrency(inv.paid_amount) }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ formatDate(inv.due_date) }}</td>
              <td class="whitespace-nowrap px-6 py-4">
                <span :class="['inline-flex rounded-full px-2 py-1 text-xs font-medium', statusClass(inv.status)]">
                  {{ inv.status }}
                </span>
              </td>
              <td class="whitespace-nowrap px-6 py-4 text-right text-sm space-x-2">
                <a :href="`/payments?invoice_id=${inv.id}`" class="text-teal-600 hover:text-teal-900">Bayar</a>
                <button
                  v-if="canUseGateway(inv.status)"
                  @click="openGatewayModal(inv, 'link')"
                  class="text-blue-600 hover:text-blue-900"
                >Link</button>
                <button
                  v-if="canUseGateway(inv.status)"
                  @click="openGatewayModal(inv, 'qris')"
                  class="text-indigo-600 hover:text-indigo-900"
                >QRIS</button>
                <button @click="deleteInvoice(inv)" class="text-red-600 hover:text-red-900">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6">
          <div class="text-sm text-gray-700">
            Total <span class="font-medium">{{ pagination.total }}</span> invoice
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

    <!-- Create Invoice Modal -->
    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-screen items-center justify-center p-4">
          <!-- Backdrop -->
          <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
          
          <!-- Modal content -->
          <div class="relative w-full max-w-3xl rounded-lg bg-white shadow-xl">
            <!-- Header -->
            <div class="flex items-center justify-between border-b px-6 py-4">
              <h3 class="text-lg font-semibold text-gray-900">Buat Invoice Baru</h3>
              <button @click="showModal = false" class="text-gray-400 hover:text-gray-500">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            
            <!-- Body -->
            <form @submit.prevent="saveInvoice" class="p-6">
              <div class="grid gap-4 sm:grid-cols-2">
                <!-- Customer -->
                <div class="sm:col-span-2">
                  <label class="block text-sm font-medium text-gray-700">Pelanggan *</label>
                  <select v-model="form.customer_id" class="input mt-1" required>
                    <option value="">-- Pilih Pelanggan --</option>
                    <option v-for="cust in customers" :key="cust.id" :value="cust.id">
                      {{ cust.customer_code }} - {{ cust.full_name }}
                    </option>
                  </select>
                  <p v-if="formErrors.customer_id" class="mt-1 text-sm text-red-600">{{ formErrors.customer_id[0] }}</p>
                </div>
                
                <!-- Period -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Periode *</label>
                  <input v-model="form.period_key" type="month" class="input mt-1" required />
                </div>
                
                <!-- Issue Date -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Tanggal Invoice *</label>
                  <input v-model="form.issue_date" type="date" class="input mt-1" required />
                </div>
                
                <!-- Due Date -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Jatuh Tempo *</label>
                  <input v-model="form.due_date" type="date" class="input mt-1" required />
                </div>
              </div>
              
              <!-- Items -->
              <div class="mt-6">
                <div class="flex items-center justify-between mb-2">
                  <label class="text-sm font-medium text-gray-700">Item Invoice</label>
                  <button type="button" @click="addItem" class="text-sm text-primary-600 hover:text-primary-700">+ Tambah Item</button>
                </div>
                <div class="space-y-2">
                  <div v-for="(item, index) in form.items" :key="index" class="flex gap-2">
                    <input v-model="item.description" type="text" placeholder="Deskripsi" class="input flex-1" required />
                    <input v-model.number="item.quantity" type="number" min="1" placeholder="Qty" class="input w-20" />
                    <input v-model.number="item.unit_price" type="number" min="0" placeholder="Harga" class="input w-32" />
                    <button type="button" @click="removeItem(index)" :disabled="form.items.length === 1" class="text-red-500 hover:text-red-700 disabled:opacity-30">
                      <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
              
              <!-- Totals -->
              <div class="mt-6 border-t pt-4">
                <div class="flex justify-end">
                  <div class="w-64 space-y-2 text-sm">
                    <div class="flex justify-between">
                      <span class="text-gray-500">Subtotal</span>
                      <span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                      <span class="text-gray-500">Diskon</span>
                      <input v-model.number="form.discount_amount" type="number" min="0" class="input w-28 text-right" />
                    </div>
                    <div class="flex items-center justify-between">
                      <span class="text-gray-500">Pajak</span>
                      <input v-model.number="form.tax_amount" type="number" min="0" class="input w-28 text-right" />
                    </div>
                    <div class="flex justify-between border-t pt-2 text-lg font-semibold">
                      <span>Total</span>
                      <span class="text-primary-600">{{ formatCurrency(total) }}</span>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Notes -->
              <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700">Catatan</label>
                <textarea v-model="form.notes" rows="2" class="input mt-1"></textarea>
              </div>
              
              <!-- Footer -->
              <div class="mt-6 flex justify-end gap-3">
                <button type="button" @click="showModal = false" class="btn btn-secondary">Batal</button>
                <button type="submit" :disabled="saving" class="btn btn-primary">
                  <span v-if="saving">Menyimpan...</span>
                  <span v-else>Simpan Invoice</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Payment Gateway Modal -->
    <Teleport to="body">
      <div v-if="showGatewayModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-screen items-center justify-center p-4">
          <div class="fixed inset-0 bg-black/50" @click="closeGatewayModal"></div>
          <div class="relative w-full max-w-lg rounded-lg bg-white dark:bg-dark-900 shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 dark:border-white/10 px-6 py-4">
              <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                  {{ gatewayMode === 'link' ? 'Payment Link' : 'QRIS' }}
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                  {{ selectedInvoice?.invoice_no }}
                </p>
              </div>
              <button @click="closeGatewayModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <div class="p-6 space-y-4">
              <div v-if="gatewayLoading" class="flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                <span class="h-4 w-4 animate-spin rounded-full border-2 border-primary-500 border-t-transparent"></span>
                Memproses payment gateway...
              </div>

              <div v-if="gatewayError" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                {{ gatewayError }}
              </div>

              <div v-if="gatewayMode === 'link' && gatewayData.payment_url" class="space-y-2">
                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Payment URL</label>
                <div class="flex items-center gap-2">
                  <input :value="gatewayData.payment_url" readonly class="input" />
                  <a :href="gatewayData.payment_url" target="_blank" class="btn btn-secondary">Buka</a>
                </div>
                <div v-if="gatewayData.order_id" class="text-xs text-gray-500 dark:text-gray-400">Order ID: {{ gatewayData.order_id }}</div>
              </div>

              <div v-if="gatewayMode === 'qris' && gatewayData.qr_string" class="space-y-3">
                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">QR String</label>
                <textarea :value="gatewayData.qr_string" readonly rows="4" class="input"></textarea>
                <div class="text-xs text-gray-500 dark:text-gray-400">Order ID: {{ gatewayData.order_id }}</div>
                <div v-if="gatewayData.expiry_time" class="text-xs text-gray-500 dark:text-gray-400">Expiry: {{ gatewayData.expiry_time }}</div>
              </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-200 dark:border-white/10 px-6 py-4">
              <button @click="closeGatewayModal" class="btn btn-secondary">Tutup</button>
              <button
                v-if="gatewayMode === 'link'"
                @click="createPaymentLink(selectedInvoice)"
                class="btn btn-primary"
              >Refresh Link</button>
              <button
                v-if="gatewayMode === 'qris'"
                @click="createQris(selectedInvoice)"
                class="btn btn-primary"
              >Refresh QRIS</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </AdminLayout>
</template>

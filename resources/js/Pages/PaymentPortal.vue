<template>
  <div class="min-h-screen bg-gray-100 py-8 px-4">
    <div class="max-w-lg mx-auto">
      <!-- Header -->
      <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-gray-800">Portal Pembayaran</h1>
        <p class="text-gray-600 mt-2">{{ companyName }}</p>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="card p-8 text-center">
        <div class="animate-spin w-12 h-12 border-4 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
        <p class="mt-4 text-gray-600">Memuat data invoice...</p>
      </div>

      <!-- Error -->
      <div v-else-if="error" class="card p-6 bg-red-50 border border-red-200">
        <div class="text-red-600 text-center">
          <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <p class="font-medium">{{ error }}</p>
        </div>
      </div>

      <!-- Invoice Details -->
      <div v-else-if="invoice" class="space-y-6">
        <!-- Customer Info -->
        <div class="card p-6">
          <h2 class="text-lg font-semibold text-gray-800 mb-4">Informasi Pelanggan</h2>
          <dl class="space-y-2">
            <div class="flex justify-between">
              <dt class="text-gray-600">Nama</dt>
              <dd class="font-medium">{{ invoice.customer?.name }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-gray-600">ID Pelanggan</dt>
              <dd class="font-medium">{{ invoice.customer?.customer_id }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-gray-600">Alamat</dt>
              <dd class="font-medium text-right max-w-[200px]">{{ invoice.customer?.address }}</dd>
            </div>
          </dl>
        </div>

        <!-- Invoice Info -->
        <div class="card p-6">
          <h2 class="text-lg font-semibold text-gray-800 mb-4">Detail Invoice</h2>
          <dl class="space-y-2">
            <div class="flex justify-between">
              <dt class="text-gray-600">No. Invoice</dt>
              <dd class="font-medium">{{ invoice.invoice_number }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-gray-600">Periode</dt>
              <dd class="font-medium">{{ invoice.period }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-gray-600">Jatuh Tempo</dt>
              <dd class="font-medium" :class="isOverdue ? 'text-red-600' : ''">
                {{ formatDate(invoice.due_date) }}
                <span v-if="isOverdue" class="text-xs ml-1">(Lewat)</span>
              </dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-gray-600">Status</dt>
              <dd>
                <span :class="statusClass">{{ invoice.status }}</span>
              </dd>
            </div>
          </dl>
        </div>

        <!-- Items -->
        <div class="card p-6">
          <h2 class="text-lg font-semibold text-gray-800 mb-4">Rincian Tagihan</h2>
          <div class="space-y-3">
            <div v-for="item in invoice.items" :key="item.id" class="flex justify-between py-2 border-b border-gray-100 last:border-0">
              <div>
                <p class="font-medium">{{ item.description }}</p>
                <p v-if="item.qty > 1" class="text-sm text-gray-500">{{ item.qty }} x {{ formatCurrency(item.price) }}</p>
              </div>
              <p class="font-medium">{{ formatCurrency(item.subtotal) }}</p>
            </div>
          </div>
          
          <!-- Total -->
          <div class="mt-4 pt-4 border-t-2 border-gray-200">
            <div class="flex justify-between items-center">
              <span class="text-lg font-bold text-gray-800">Total</span>
              <span class="text-2xl font-bold text-primary-600">{{ formatCurrency(invoice.total) }}</span>
            </div>
            <div v-if="invoice.paid_amount > 0" class="flex justify-between mt-2 text-sm">
              <span class="text-gray-600">Sudah Dibayar</span>
              <span class="text-green-600">- {{ formatCurrency(invoice.paid_amount) }}</span>
            </div>
            <div v-if="remainingAmount > 0 && invoice.paid_amount > 0" class="flex justify-between mt-2 font-medium">
              <span class="text-gray-800">Sisa Tagihan</span>
              <span class="text-red-600">{{ formatCurrency(remainingAmount) }}</span>
            </div>
          </div>
        </div>

        <!-- Payment Button -->
        <div v-if="invoice.status !== 'PAID' && invoice.status !== 'CANCELLED'" class="space-y-3">
          <!-- Payment Method Selection -->
          <div class="card p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Metode Pembayaran</h2>
            <div class="grid grid-cols-2 gap-3">
              <button 
                @click="selectedMethod = 'all'"
                :class="['p-4 border-2 rounded-lg transition-all', selectedMethod === 'all' ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300']"
              >
                <div class="text-center">
                  <svg class="w-8 h-8 mx-auto mb-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                  </svg>
                  <p class="font-medium text-sm">Semua Metode</p>
                  <p class="text-xs text-gray-500">VA, CC, GoPay, dll</p>
                </div>
              </button>
              <button 
                @click="selectedMethod = 'qris'"
                :class="['p-4 border-2 rounded-lg transition-all', selectedMethod === 'qris' ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300']"
              >
                <div class="text-center">
                  <svg class="w-8 h-8 mx-auto mb-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                  </svg>
                  <p class="font-medium text-sm">QRIS</p>
                  <p class="text-xs text-gray-500">Scan & Bayar</p>
                </div>
              </button>
            </div>
          </div>

          <!-- Pay Button -->
          <button 
            @click="pay"
            :disabled="paying"
            class="btn btn-primary w-full py-4 text-lg font-semibold"
          >
            <span v-if="paying" class="flex items-center justify-center">
              <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Memproses...
            </span>
            <span v-else>
              Bayar Sekarang - {{ formatCurrency(remainingAmount) }}
            </span>
          </button>
        </div>

        <!-- Already Paid -->
        <div v-else-if="invoice.status === 'PAID'" class="card p-6 bg-green-50 border border-green-200">
          <div class="text-center text-green-600">
            <svg class="w-16 h-16 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-xl font-bold">Invoice Sudah Lunas</p>
            <p class="mt-2">Terima kasih atas pembayaran Anda</p>
          </div>
        </div>

        <!-- Cancelled -->
        <div v-else-if="invoice.status === 'CANCELLED'" class="card p-6 bg-gray-50 border border-gray-200">
          <div class="text-center text-gray-600">
            <svg class="w-16 h-16 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-xl font-bold">Invoice Dibatalkan</p>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="mt-8 text-center text-sm text-gray-500">
        <p>Butuh bantuan? Hubungi kami di WhatsApp</p>
        <p class="mt-1">Powered by {{ companyName }}</p>
      </div>
    </div>

    <!-- QRIS Modal -->
    <div v-if="qrisData" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-2xl max-w-sm w-full p-6">
        <h3 class="text-lg font-bold text-center mb-4">Scan QRIS untuk Bayar</h3>
        <div class="bg-white p-4 rounded-lg border">
          <img :src="qrisData.qr_url" alt="QRIS" class="w-full">
        </div>
        <p class="text-center mt-4 text-2xl font-bold text-primary-600">{{ formatCurrency(remainingAmount) }}</p>
        <p class="text-center text-sm text-gray-500 mt-2">Berlaku hingga {{ qrisData.expiry }}</p>
        <button @click="qrisData = null" class="btn btn-secondary w-full mt-4">Tutup</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps({
  invoiceId: String,
  token: String
})

const loading = ref(true)
const error = ref(null)
const invoice = ref(null)
const selectedMethod = ref('all')
const paying = ref(false)
const qrisData = ref(null)
const companyName = ref('ISP Billing')

const isOverdue = computed(() => {
  if (!invoice.value?.due_date) return false
  return new Date(invoice.value.due_date) < new Date()
})

const remainingAmount = computed(() => {
  if (!invoice.value) return 0
  return invoice.value.total - (invoice.value.paid_amount || 0)
})

const statusClass = computed(() => {
  const classes = {
    'DRAFT': 'px-2 py-1 bg-gray-100 text-gray-700 rounded text-sm',
    'SENT': 'px-2 py-1 bg-blue-100 text-blue-700 rounded text-sm',
    'PAID': 'px-2 py-1 bg-green-100 text-green-700 rounded text-sm',
    'PARTIAL': 'px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-sm',
    'OVERDUE': 'px-2 py-1 bg-red-100 text-red-700 rounded text-sm',
    'CANCELLED': 'px-2 py-1 bg-gray-100 text-gray-500 rounded text-sm',
  }
  return classes[invoice.value?.status] || classes['DRAFT']
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0
  }).format(value || 0)
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric'
  })
}

const loadInvoice = async () => {
  try {
    loading.value = true
    const response = await axios.get(`/api/v1/invoices/${props.invoiceId}`)
    invoice.value = response.data?.data || null
  } catch (e) {
    error.value = 'Invoice tidak ditemukan atau sudah tidak berlaku'
  } finally {
    loading.value = false
  }
}

const pay = async () => {
  try {
    paying.value = true
    
    if (selectedMethod.value === 'qris') {
      // Create QRIS payment
      const response = await axios.post(`/api/v1/invoices/${props.invoiceId}/qris`)
      qrisData.value = response.data || null
    } else {
      // Create payment link and redirect
      const response = await axios.post(`/api/v1/invoices/${props.invoiceId}/pay`)
      if (response.data?.redirect_url) {
        window.location.href = response.data.redirect_url
      }
    }
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal memproses pembayaran')
  } finally {
    paying.value = false
  }
}

onMounted(() => {
  loadInvoice()
})
</script>

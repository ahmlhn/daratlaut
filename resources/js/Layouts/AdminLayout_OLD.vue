<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'

const sidebarOpen = ref(false)
const userMenuOpen = ref(false)
const serverTime = ref(new Date())

// Server clock
let clockInterval = null
onMounted(() => {
  clockInterval = setInterval(() => {
    serverTime.value = new Date()
  }, 1000)
})
onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval)
})

const formattedTime = computed(() => {
  return serverTime.value.toLocaleString('id-ID', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  })
})

const page = usePage()
const currentPath = computed(() => page.url)
const user = computed(() => page.props.auth?.user || { name: 'Guest', email: '' })

// Grouped navigation like FRRADIUS
const navigationGroups = [
  {
    name: null, // No group header
    items: [
      { name: 'Dashboard', href: '/dashboard', icon: 'home' },
    ]
  },
  {
    name: 'OPERASIONAL',
    items: [
      { name: 'Pasang Baru', href: '/installations', icon: 'wifi' },
      { name: 'Tim', href: '/team', icon: 'user-group' },
      { name: 'POP', href: '/pops', icon: 'map-pin' },
      { name: 'OLT', href: '/olts', icon: 'server' },
    ]
  },
  {
    name: 'TEKNISI',
    items: [
      { name: 'Tugas Saya', href: '/teknisi', icon: 'clipboard' },
      { name: 'Riwayat', href: '/teknisi/riwayat', icon: 'clock' },
      { name: 'Rekap Harian', href: '/teknisi/rekap', icon: 'document-report' },
      { name: 'Maps Teknisi', href: '/maps', icon: 'map' },
    ]
  },
  {
    name: 'PELANGGAN',
    items: [
      { name: 'Leads', href: '/leads', icon: 'phone' },
      { name: 'Semua Pelanggan', href: '/customers', icon: 'users' },
      { name: 'Pelanggan Aktif', href: '/customers?status=AKTIF', icon: 'user-check' },
      { name: 'Pelanggan Suspend', href: '/customers?status=SUSPEND', icon: 'user-x' },
    ]
  },
  {
    name: 'BILLING',
    items: [
      { name: 'Invoice Belum Bayar', href: '/invoices?status=OPEN', icon: 'document-text' },
      { name: 'Invoice Lunas', href: '/invoices?status=PAID', icon: 'document-check' },
      { name: 'Riwayat Pembayaran', href: '/payments', icon: 'credit-card' },
    ]
  },
  {
    name: 'PENGATURAN',
    items: [
      { name: 'Paket Layanan', href: '/plans', icon: 'tag' },
      { name: 'Pengaturan', href: '/settings', icon: 'cog' },
      { name: 'Profile Saya', href: '/profile', icon: 'user' },
    ]
  },
  {
    name: 'KEUANGAN',
    items: [
      { name: 'Keuangan', href: '/finance', icon: 'cash' },
      { name: 'Laporan', href: '/reports', icon: 'chart' },
    ]
  },
  {
    name: 'LAINNYA',
    items: [
      { name: 'Dashboard Utama', href: '/', icon: 'external', external: true },
    ]
  }
]

const isActive = (href) => {
  const path = currentPath.value.split('?')[0]
  const hrefPath = href.split('?')[0]
  return path === hrefPath || (href !== '/dashboard' && currentPath.value.startsWith(hrefPath))
}

const logout = () => {
  router.post('/logout')
}

// Breadcrumb
const breadcrumbs = computed(() => {
  const path = currentPath.value.split('?')[0]
  const parts = path.split('/').filter(Boolean)
  const crumbs = [{ name: 'Home', href: '/dashboard' }]
  
  let href = ''
  parts.forEach(part => {
    href += '/' + part
    crumbs.push({
      name: part.charAt(0).toUpperCase() + part.slice(1),
      href
    })
  })
  
  return crumbs
})
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Mobile sidebar backdrop -->
    <div 
      v-if="sidebarOpen" 
      class="fixed inset-0 z-40 bg-gray-600/75 lg:hidden"
      @click="sidebarOpen = false"
    />

    <!-- Sidebar -->
    <aside 
      :class="[
        'fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-gray-200 transform transition-transform duration-200 ease-in-out lg:translate-x-0',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full'
      ]"
    >
      <!-- Logo -->
      <div class="flex h-16 items-center gap-3 border-b border-gray-200 px-6">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-600">
          <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
        </div>
        <span class="text-lg font-semibold text-gray-900">Billing Admin</span>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 overflow-y-auto px-3 py-4">
        <div v-for="(group, gIdx) in navigationGroups" :key="gIdx" :class="gIdx > 0 ? 'mt-6' : ''">
          <!-- Group header -->
          <p v-if="group.name" class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-gray-400">
            {{ group.name }}
          </p>
          
          <!-- Group items -->
          <div class="space-y-1">
            <component
              :is="item.external ? 'a' : Link"
              v-for="item in group.items"
              :key="item.name"
              :href="item.href"
              :target="item.external ? '_blank' : undefined"
              :class="[
                'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                isActive(item.href)
                  ? 'bg-primary-50 text-primary-700'
                  : 'text-gray-700 hover:bg-gray-100'
              ]"
            >
              <!-- Home icon -->
              <svg v-if="item.icon === 'home'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
              </svg>
              <!-- WiFi icon (Pasang Baru) -->
              <svg v-else-if="item.icon === 'wifi'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
              <!-- User group icon (Tim) -->
              <svg v-else-if="item.icon === 'user-group'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
              <!-- Map pin icon (POP) -->
              <svg v-else-if="item.icon === 'map-pin'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <!-- Users icon -->
              <svg v-else-if="item.icon === 'users'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
              <!-- Phone icon (Leads) -->
              <svg v-else-if="item.icon === 'phone'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
              </svg>
              <!-- User check -->
              <svg v-else-if="item.icon === 'user-check'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11l2 2 4-4" />
              </svg>
              <!-- User X -->
              <svg v-else-if="item.icon === 'user-x'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 12l-4 4m0-4l4 4" />
              </svg>
              <!-- Document text -->
              <svg v-else-if="item.icon === 'document-text'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <!-- Document check -->
              <svg v-else-if="item.icon === 'document-check'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <!-- Credit card icon -->
              <svg v-else-if="item.icon === 'credit-card'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
              </svg>
              <!-- Tag icon -->
              <svg v-else-if="item.icon === 'tag'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
              </svg>
              <!-- Cog icon (Settings) -->
              <svg v-else-if="item.icon === 'cog'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <!-- Server icon (OLT) -->
              <svg v-else-if="item.icon === 'server'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
              </svg>
              <!-- Clipboard icon (Tugas) -->
              <svg v-else-if="item.icon === 'clipboard'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
              </svg>
              <!-- Clock icon (Riwayat) -->
              <svg v-else-if="item.icon === 'clock'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <!-- Document report icon (Rekap) -->
              <svg v-else-if="item.icon === 'document-report'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <!-- Map icon (Maps) -->
              <svg v-else-if="item.icon === 'map'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
              </svg>
              <!-- Cash icon (Keuangan) -->
              <svg v-else-if="item.icon === 'cash'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
              <!-- Chart icon (Reports) -->
              <svg v-else-if="item.icon === 'chart'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
              <!-- External link -->
              <svg v-else-if="item.icon === 'external'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
              {{ item.name }}
              <svg v-if="item.external" class="ml-auto h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
            </component>
          </div>
        </div>
      </nav>
    </aside>

    <!-- Main content -->
    <div class="lg:pl-64">
      <!-- Top header -->
      <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-gray-200 bg-white px-4 sm:px-6">
        <!-- Mobile menu button -->
        <button
          type="button"
          class="lg:hidden -m-2.5 p-2.5 text-gray-700"
          @click="sidebarOpen = true"
        >
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>

        <!-- Breadcrumb -->
        <nav class="hidden sm:flex items-center gap-2 text-sm">
          <template v-for="(crumb, idx) in breadcrumbs" :key="crumb.href">
            <span v-if="idx > 0" class="text-gray-300">/</span>
            <Link 
              :href="crumb.href"
              :class="idx === breadcrumbs.length - 1 ? 'text-gray-900 font-medium' : 'text-gray-500 hover:text-gray-700'"
            >
              {{ crumb.name }}
            </Link>
          </template>
        </nav>

        <div class="flex flex-1 items-center justify-end gap-4">
          <!-- Server clock -->
          <div class="hidden md:flex items-center gap-2 text-sm text-gray-500">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ formattedTime }}</span>
          </div>

          <!-- User menu -->
          <div class="relative flex items-center gap-3">
            <button 
              @click="userMenuOpen = !userMenuOpen"
              class="flex items-center gap-3 rounded-lg p-1 hover:bg-gray-100"
            >
              <div class="hidden sm:block text-right">
                <p class="text-sm font-medium text-gray-900">{{ user.name }}</p>
                <p class="text-xs text-gray-500">{{ user.email }}</p>
              </div>
              <div class="h-9 w-9 rounded-full bg-primary-600 flex items-center justify-center">
                <span class="text-sm font-medium text-white">{{ user.name?.charAt(0)?.toUpperCase() || 'U' }}</span>
              </div>
            </button>
            
            <!-- Dropdown -->
            <div 
              v-if="userMenuOpen"
              class="absolute right-0 top-full mt-2 w-48 rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-200"
            >
              <button
                @click="logout"
                class="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                Logout
              </button>
            </div>
          </div>
        </div>
      </header>

      <!-- Page content -->
      <main class="p-4 sm:p-6 lg:p-8">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import ToastContainer from '@/Components/ToastContainer.vue'

const sidebarOpen = ref(false)
const sidebarCollapsed = ref(false)
const userMenuOpen = ref(false)
const darkMode = ref(false)
const themeMode = ref('system')
const serverTime = ref(new Date())
const toastContainer = ref(null)

// Expose toast methods globally
const showToast = {
  success: (message, duration) => toastContainer.value?.success(message, duration),
  error: (message, duration) => toastContainer.value?.error(message, duration),
  warning: (message, duration) => toastContainer.value?.warning(message, duration),
  info: (message, duration) => toastContainer.value?.info(message, duration),
}

// Make toast available to child components via provide
import { provide } from 'vue'
provide('toast', showToast)

// Server clock
let clockInterval = null
let themeMediaQuery = null
const applyThemeMode = (mode, persist = true) => {
  themeMode.value = mode
  if (persist) {
    localStorage.setItem('theme', mode)
  }
  const prefersDark = themeMediaQuery?.matches ?? false
  darkMode.value = mode === 'dark' || (mode === 'system' && prefersDark)
  document.documentElement.classList.toggle('dark', darkMode.value)
}

const handleSystemThemeChange = (event) => {
  if (themeMode.value === 'system') {
    darkMode.value = event.matches
    document.documentElement.classList.toggle('dark', darkMode.value)
  }
}

onMounted(() => {
  themeMediaQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null
  const storedTheme = localStorage.getItem('theme')
  const initialTheme = ['light', 'dark', 'system'].includes(storedTheme) ? storedTheme : 'system'
  applyThemeMode(initialTheme, false)

  if (themeMediaQuery) {
    if (themeMediaQuery.addEventListener) {
      themeMediaQuery.addEventListener('change', handleSystemThemeChange)
    } else if (themeMediaQuery.addListener) {
      themeMediaQuery.addListener(handleSystemThemeChange)
    }
  }
  
  clockInterval = setInterval(() => {
    serverTime.value = new Date()
  }, 1000)
  
  // Keyboard shortcuts
  window.addEventListener('keydown', handleKeyboardShortcuts)
})

onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval)
  window.removeEventListener('keydown', handleKeyboardShortcuts)
  if (themeMediaQuery) {
    if (themeMediaQuery.removeEventListener) {
      themeMediaQuery.removeEventListener('change', handleSystemThemeChange)
    } else if (themeMediaQuery.removeListener) {
      themeMediaQuery.removeListener(handleSystemThemeChange)
    }
  }
})

const setThemeMode = (mode) => {
  applyThemeMode(mode, true)
}

const cycleThemeMode = () => {
  const order = ['light', 'dark', 'system']
  const currentIndex = order.indexOf(themeMode.value)
  const nextMode = order[(currentIndex + 1) % order.length]
  setThemeMode(nextMode)
}

const toggleDarkMode = () => {
  setThemeMode(darkMode.value ? 'light' : 'dark')
}

const handleKeyboardShortcuts = (e) => {
  // Ctrl/Cmd + K: Toggle sidebar (desktop only)
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
    e.preventDefault()
    if (window.innerWidth >= 1024) {
      sidebarCollapsed.value = !sidebarCollapsed.value
      localStorage.setItem('sidebarCollapsed', sidebarCollapsed.value)
    }
  }
  
  // Ctrl/Cmd + D: Toggle dark mode
  if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
    e.preventDefault()
    toggleDarkMode()
  }
  
  // Escape: Close mobile sidebar or user menu
  if (e.key === 'Escape') {
    sidebarOpen.value = false
    userMenuOpen.value = false
  }
}

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
const layoutOptions = computed(() => page.props.layoutOptions || {})
const tenantFeatures = computed(() => page.props.tenantFeatures || {})

const userPermissions = computed(() => user.value?.permissions || [])
const isSuperAdmin = computed(() => !!user.value?.is_superadmin)

function canAny(perms) {
  const list = (userPermissions.value || []).map((p) => String(p || '').trim().toLowerCase())
  const need = (perms || []).map((p) => String(p || '').trim().toLowerCase()).filter(Boolean)
  if (!need.length) return true
  return need.some((p) => list.includes(p))
}

function isFeatureEnabled(featureKey) {
  if (!featureKey) return true
  return tenantFeatures.value?.[featureKey] !== false
}

function isNavItemAllowed(item) {
  if (!item) return false
  if (item.superadminOnly && !isSuperAdmin.value) return false
  if (!isFeatureEnabled(item.featureKey)) return false
  if (item.permissionsAny && item.permissionsAny.length > 0) return canAny(item.permissionsAny)
  if (item.permission) return canAny([item.permission])
  return true
}

const baseNavigationGroups = [
  {
    name: null,
    items: [
      { name: 'Dashboard', href: '/dashboard', icon: 'home', featureKey: 'dashboard', permissionsAny: ['view dashboard'] },
    ]
  },
  {
    name: 'CHAT',
    items: [
      { name: 'Chat Admin', href: '/chat', icon: 'chat', featureKey: 'chat', permissionsAny: ['view chat'] },
    ]
  },
  {
    name: 'OPERASIONAL',
    items: [
      { name: 'Pasang Baru', href: '/installations', icon: 'wifi', featureKey: 'installations', permissionsAny: ['view installations'] },
      { name: 'Tim', href: '/team', icon: 'user-group', featureKey: 'team', permissionsAny: ['view team', 'manage team'] },
    ]
  },
  {
    name: 'TEKNISI',
    items: [
      { name: 'Tugas Saya', href: '/teknisi', icon: 'clipboard', featureKey: 'teknisi', permissionsAny: ['view teknisi'] },
      { name: 'Riwayat', href: '/teknisi/riwayat', icon: 'clock', featureKey: 'teknisi', permissionsAny: ['view teknisi'] },
      { name: 'Rekap Harian', href: '/teknisi/rekap', icon: 'document-report', featureKey: 'teknisi', permissionsAny: ['view teknisi'] },
      { name: 'Maps Teknisi', href: '/maps', icon: 'map', featureKey: 'maps', permissionsAny: ['view maps', 'manage maps'] },
    ]
  },
  {
    name: 'JARINGAN',
    items: [
      { name: 'Kabel FO', href: '/fiber', icon: 'map-pin', featureKey: 'fiber', permissionsAny: ['view fiber', 'manage fiber'] },
      { name: 'OLT', href: '/olts', icon: 'server', featureKey: 'olts', permissionsAny: ['view olts', 'manage olt'] },
    ]
  },
  {
    name: 'PELANGGAN',
    items: [
      { name: 'Semua Pelanggan', href: '/customers', icon: 'users', featureKey: 'customers', permissionsAny: ['view customers'] },
      { name: 'Pelanggan Aktif', href: '/customers?status=AKTIF', icon: 'user-check', featureKey: 'customers', permissionsAny: ['view customers'] },
      { name: 'Pelanggan Suspend', href: '/customers?status=SUSPEND', icon: 'user-x', featureKey: 'customers', permissionsAny: ['view customers'] },
    ]
  },
  {
    name: 'BILLING',
    items: [
      { name: 'Invoice Belum Bayar', href: '/invoices?status=OPEN', icon: 'document-text', featureKey: 'invoices', permissionsAny: ['view invoices'] },
      { name: 'Invoice Lunas', href: '/invoices?status=PAID', icon: 'document-check', featureKey: 'invoices', permissionsAny: ['view invoices'] },
      { name: 'Riwayat Pembayaran', href: '/payments', icon: 'credit-card', featureKey: 'payments', permissionsAny: ['view payments'] },
    ]
  },
  {
    name: 'PENGATURAN',
    items: [
      { name: 'Paket Layanan', href: '/plans', icon: 'tag', featureKey: 'plans', permissionsAny: ['view plans'] },
      { name: 'Pengaturan', href: '/settings', icon: 'cog', featureKey: 'settings', permissionsAny: ['manage settings', 'manage roles'] },
      { name: 'Kelola Role', href: '/settings/roles', icon: 'shield-check', featureKey: 'settings', permissionsAny: ['manage settings', 'manage roles'] },
    ]
  },
  {
    name: 'KEUANGAN',
    items: [
      { name: 'Keuangan', href: '/finance', icon: 'cash', featureKey: 'finance', permissionsAny: ['view finance', 'manage finance'] },
      { name: 'Laporan', href: '/reports', icon: 'chart', featureKey: 'reports', permissionsAny: ['view reports'] },
    ]
  },
  {
    name: 'SUPERADMIN',
    items: [
      { name: 'Kelola Tenant', href: '/superadmin/tenants', icon: 'shield-check', superadminOnly: true },
    ]
  },
]

const navigationGroups = computed(() => {
  // Hide menu items based on permissions so users don't see links they can't access.
  const groups = baseNavigationGroups.map(group => ({
    ...group,
    items: (group.items || []).filter(item => {
      return isNavItemAllowed(item)
    })
  }))

  return groups.filter(group => (group.items || []).length > 0)
})

const allNavItems = computed(() => {
  return navigationGroups.value.flatMap(group => group.items)
})

const activeHref = computed(() => {
  // Prefer the most specific match:
  // - query-aware (href with ?status=...) beats plain path
  // - exact path beats prefix match
  // - longer path beats shorter path
  const current = new URL(currentPath.value, window.location.origin)
  const currentPathname = (current.pathname || '/').replace(/\/+$/, '') || '/'

  let best = { href: null, score: -1 }

  for (const item of allNavItems.value) {
    if (!item?.href || item.external) continue

    const target = new URL(item.href, window.location.origin)
    const targetPathname = (target.pathname || '/').replace(/\/+$/, '') || '/'

    const pathMatch = (currentPathname === targetPathname) || currentPathname.startsWith(`${targetPathname}/`)
    if (!pathMatch) continue

    // href query params must be satisfied by current url (subset match).
    let queryMatch = true
    for (const [k, v] of target.searchParams.entries()) {
      if (current.searchParams.get(k) !== v) {
        queryMatch = false
        break
      }
    }
    if (!queryMatch) continue

    let score = 0
    score += targetPathname.length // deeper paths win
    if (currentPathname === targetPathname) score += 10000 // exact path beats prefix
    const qCount = Array.from(target.searchParams.keys()).length
    if (qCount > 0) score += 2000 + qCount // query-specific beats base path

    if (score > best.score) best = { href: item.href, score }
  }

  return best.href
})

const isActive = (href) => {
  return href === activeHref.value
}

const logout = () => {
  router.post('/logout')
}

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
  <div class="min-h-screen bg-gray-50 dark:bg-dark-950">
    <!-- Skip to main content (accessibility) -->
    <a 
      href="#main-content" 
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary-600 focus:px-4 focus:py-2 focus:text-white focus:shadow-lg"
    >
      Skip to main content
    </a>
    
    <!-- Mobile sidebar backdrop -->
    <div 
      v-if="sidebarOpen && !layoutOptions.hideSidebar" 
      class="fixed inset-0 z-40 bg-gray-900/75 backdrop-blur-sm lg:hidden animate-fade-in"
      @click="sidebarOpen = false"
    />

    <!-- Sidebar -->
    <aside
      v-if="!layoutOptions.hideSidebar"
      :class="[
        'fixed inset-y-0 left-0 z-50 bg-white dark:bg-dark-900 border-r border-gray-200 dark:border-white/10 transform transition-all duration-300 ease-in-out lg:translate-x-0 flex flex-col shadow-xl lg:shadow-none',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full',
        sidebarCollapsed ? 'w-20' : 'w-72'
      ]"
    >
      <!-- Logo Header -->
      <div class="h-20 flex items-center justify-between px-6 border-b border-gray-200 dark:border-white/10 shrink-0">
        <Link href="/dashboard" class="flex items-center gap-3 group">
          <div class="relative">
            <div class="absolute inset-0 bg-primary-500 blur-lg opacity-20 group-hover:opacity-40 transition"></div>
            <img
              src="/assets/favicon.svg"
              alt="Logo"
              class="relative w-8 h-8 drop-shadow-md group-hover:scale-110 transition-transform duration-300"
              onerror="this.style.display='none'"
            />
          </div>
          <div v-if="!sidebarCollapsed" class="flex flex-col">
            <h1 class="text-lg font-black text-gray-800 dark:text-white tracking-tight leading-none">
              DARAT<span class="text-primary-600 dark:text-primary-500">LAUT</span>
            </h1>
            <span class="text-[9px] text-gray-400 dark:text-gray-500 font-bold tracking-widest mt-0.5 uppercase">Network System</span>
          </div>
        </Link>

        <button
          @click="sidebarCollapsed = !sidebarCollapsed"
          class="hidden lg:flex p-2 text-gray-400 hover:text-gray-700 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/10 rounded-lg transition"
        >
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path v-if="sidebarCollapsed" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
          </svg>
        </button>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-1 min-h-0">
        <div v-for="(group, gIdx) in navigationGroups" :key="gIdx" :class="gIdx > 0 ? 'mt-6' : ''">
          <!-- Group header -->
          <p v-if="group.name && !sidebarCollapsed" class="px-3 mb-2 text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">
            {{ group.name }}
          </p>
          
          <!-- Separator for collapsed -->
          <div v-if="group.name && sidebarCollapsed" class="h-px bg-gray-200 dark:bg-white/10 my-2"></div>
          
          <!-- Group items -->
          <div class="space-y-1">
            <component
              :is="item.external ? 'a' : Link"
              v-for="item in group.items"
              :key="item.name"
              :href="item.href"
              :target="item.external ? '_blank' : undefined"
              :title="sidebarCollapsed ? item.name : ''"
              :class="[
                'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-300',
                isActive(item.href)
                  ? 'bg-gradient-to-r from-primary-600 to-primary-700 text-white shadow-lg shadow-primary-500/30'
                  : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white',
                sidebarCollapsed && 'justify-center'
              ]"
            >
              <!-- Icons (keeping your existing icon logic) -->
              <svg v-if="item.icon === 'home'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
              </svg>
              <svg v-else-if="item.icon === 'wifi'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
              <svg v-else-if="item.icon === 'check-circle'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <svg v-else-if="item.icon === 'user-group'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
              <svg v-else-if="item.icon === 'map-pin'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
              </svg>
              <svg v-else-if="item.icon === 'users'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
              <svg v-else-if="item.icon === 'phone'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
              </svg>
              <svg v-else-if="item.icon === 'user-check'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
              </svg>
              <svg v-else-if="item.icon === 'user-x'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 8l-6 6m0-6l6 6" />
              </svg>
              <svg v-else-if="item.icon === 'document-text'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <svg v-else-if="item.icon === 'document-check'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <svg v-else-if="item.icon === 'credit-card'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
              </svg>
              <svg v-else-if="item.icon === 'tag'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
              </svg>
              <svg v-else-if="item.icon === 'cog'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <svg v-else-if="item.icon === 'refresh'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v6h6M20 20v-6h-6" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 14a8 8 0 00-14.828-3M4 10a8 8 0 0014.828 3" />
              </svg>
              <svg v-else-if="item.icon === 'shield-check'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
              <svg v-else-if="item.icon === 'server'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
              </svg>
              <svg v-else-if="item.icon === 'clipboard'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
              </svg>
              <svg v-else-if="item.icon === 'clock'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <svg v-else-if="item.icon === 'document-report'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <svg v-else-if="item.icon === 'map'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
              </svg>
              <svg v-else-if="item.icon === 'cash'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
              <svg v-else-if="item.icon === 'chart'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
              <svg v-else-if="item.icon === 'external'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
              <svg v-else-if="item.icon === 'user'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              <svg v-else-if="item.icon === 'chat'" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8M8 14h5m-7 6l2.5-2.5A9 9 0 113 12v8z" />
              </svg>
              
              <span v-if="!sidebarCollapsed" class="flex-1">{{ item.name }}</span>
              
              <svg v-if="item.external && !sidebarCollapsed" class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
            </component>
          </div>
        </div>
      </nav>

      <!-- Sidebar footer: minimal icon strip -->
      <div class="border-t border-gray-200 dark:border-white/10 shrink-0 p-2">
        <div :class="[sidebarCollapsed ? 'flex flex-col items-center gap-1' : 'flex items-center justify-between']">
          <!-- Left: avatar link -->
          <Link href="/profile" class="group flex items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-white/5 transition" :title="sidebarCollapsed ? user.name : 'Profil'">
            <div class="h-8 w-8 rounded-full bg-gradient-to-br from-primary-600 to-primary-700 flex items-center justify-center shadow-md shadow-primary-500/20 group-hover:shadow-lg transition-shadow shrink-0">
              <span class="text-xs font-bold text-white">{{ user.name?.charAt(0)?.toUpperCase() || 'U' }}</span>
            </div>
            <span v-if="!sidebarCollapsed" class="text-sm font-semibold text-gray-700 dark:text-gray-200 truncate">{{ user.name }}</span>
          </Link>
          <!-- Right: action icons -->
          <div :class="[sidebarCollapsed ? 'flex flex-col gap-0.5' : 'flex items-center gap-0.5']">
            <button @click="cycleThemeMode" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/10 transition" :title="themeMode === 'light' ? 'Terang' : themeMode === 'dark' ? 'Gelap' : 'Sistem'">
              <svg v-if="themeMode === 'light'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
              <svg v-else-if="themeMode === 'dark'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
              <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17h4.5m-6 3h7.5M3 7.5A2.5 2.5 0 015.5 5h13A2.5 2.5 0 0121 7.5v6A2.5 2.5 0 0118.5 16h-13A2.5 2.5 0 013 13.5v-6z" /></svg>
            </button>
            <button @click="logout" class="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="Logout">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
            </button>
          </div>
        </div>
      </div>
    </aside>

    <!-- Main content -->
    <div :class="['transition-all duration-300', layoutOptions.hideSidebar ? '' : (sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-72')]">
      <!-- Mobile-only header (desktop has no header = more space) -->
      <header v-if="!layoutOptions.hideHeader" class="sticky top-0 z-30 flex lg:hidden h-14 items-center justify-between border-b border-gray-200 dark:border-white/10 bg-white/80 dark:bg-dark-900/80 backdrop-blur-xl px-4 shadow-sm">
        <!-- Hamburger -->
        <button v-if="!layoutOptions.hideSidebar" type="button" class="-m-2 p-2 text-gray-600 dark:text-gray-300" @click="sidebarOpen = true">
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        <!-- Logo -->
        <Link href="/dashboard" class="flex items-center gap-2">
          <div class="relative">
            <div class="absolute inset-0 bg-primary-500 blur-lg opacity-20"></div>
            <img
              src="/assets/favicon.svg"
              alt="Logo"
              class="relative w-8 h-8 drop-shadow-sm"
              onerror="this.style.display='none'"
            />
          </div>
          <span class="text-sm font-black text-gray-800 dark:text-white">DARAT<span class="text-primary-600 dark:text-primary-500">LAUT</span></span>
        </Link>
        <!-- User avatar + dropdown -->
        <div class="relative">
          <button @click="userMenuOpen = !userMenuOpen" class="-m-1 p-1">
            <div class="h-8 w-8 rounded-full bg-gradient-to-br from-primary-600 to-primary-700 flex items-center justify-center shadow-md">
              <span class="text-xs font-bold text-white">{{ user.name?.charAt(0)?.toUpperCase() || 'U' }}</span>
            </div>
          </button>
          <div v-if="userMenuOpen" class="absolute right-0 top-full mt-2 w-52 rounded-xl bg-white dark:bg-dark-800 py-1 shadow-xl border border-gray-200 dark:border-white/10 animate-scale-in">
            <div class="px-4 py-2.5 border-b border-gray-100 dark:border-white/5">
              <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ user.name }}</p>
              <p class="text-[11px] text-gray-400 truncate">{{ user.email }}</p>
            </div>
            <Link href="/profile" class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-dark-700 transition-colors">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
              Profil
            </Link>
            <button @click="cycleThemeMode" class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-dark-700 transition-colors">
              <svg v-if="themeMode === 'light'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
              <svg v-else-if="themeMode === 'dark'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
              <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17h4.5m-6 3h7.5M3 7.5A2.5 2.5 0 015.5 5h13A2.5 2.5 0 0121 7.5v6A2.5 2.5 0 0118.5 16h-13A2.5 2.5 0 013 13.5v-6z" /></svg>
              {{ themeMode === 'light' ? 'Tema: Terang' : themeMode === 'dark' ? 'Tema: Gelap' : 'Tema: Sistem' }}
            </button>
            <button @click="logout" class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
              Logout
            </button>
          </div>
        </div>
      </header>

      <!-- Page content -->
      <main :class="layoutOptions.fullBleed ? 'p-0' : 'p-4 sm:p-6 lg:p-8'" id="main-content" role="main">
        <slot />
      </main>
    </div>

    <!-- Toast Container -->
    <ToastContainer ref="toastContainer" />
  </div>
</template>

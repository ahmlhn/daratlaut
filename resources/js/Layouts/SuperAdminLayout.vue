<script setup>
import { computed, onMounted } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const page = usePage()
const user = computed(() => page.props.auth?.user || { name: 'Superadmin', email: '' })
const currentPath = computed(() => (page.url || '/').split('?')[0] || '/')

const navItems = [
  { name: 'Kontrol Tenant', href: '/superadmin/tenants' },
]

function isActive(href) {
  return currentPath.value === href || currentPath.value.startsWith(`${href}/`)
}

function logout() {
  router.post('/logout')
}

onMounted(() => {
  try {
    const mode = localStorage.getItem('theme')
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
    const isDark = mode === 'dark' || (mode !== 'light' && prefersDark)
    document.documentElement.classList.toggle('dark', !!isDark)
  } catch (e) {
    // Ignore localStorage access errors.
  }
})
</script>

<template>
  <div class="min-h-screen bg-slate-100 dark:bg-slate-950">
    <header class="sticky top-0 z-30 border-b border-slate-200 dark:border-white/10 bg-white/95 dark:bg-slate-900/95 backdrop-blur">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
        <div class="min-w-0">
          <p class="text-[10px] font-black tracking-[0.2em] uppercase text-primary-600 dark:text-primary-400">Core Access</p>
          <h1 class="text-sm sm:text-base font-black tracking-tight text-slate-900 dark:text-white truncate">
            Superadmin Control Center
          </h1>
        </div>

        <div class="flex items-center gap-2 sm:gap-3 shrink-0">
          <div class="hidden md:block text-right">
            <p class="text-xs font-semibold text-slate-800 dark:text-slate-100 truncate">{{ user.name || 'Superadmin' }}</p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate">{{ user.email || '-' }}</p>
          </div>

          <button
            type="button"
            @click="logout"
            class="inline-flex items-center rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500 transition"
          >
            Logout
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">
      <nav class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 p-2 flex flex-wrap gap-2">
        <Link
          v-for="item in navItems"
          :key="item.href"
          :href="item.href"
          :class="[
            'inline-flex items-center rounded-xl px-4 py-2 text-sm font-bold transition',
            isActive(item.href)
              ? 'bg-primary-600 text-white shadow-sm shadow-primary-500/30'
              : 'text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800'
          ]"
        >
          {{ item.name }}
        </Link>
      </nav>

      <main class="mt-6" id="main-content" role="main">
        <slot />
      </main>
    </div>
  </div>
</template>

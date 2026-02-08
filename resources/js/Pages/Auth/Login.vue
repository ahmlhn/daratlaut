<script setup>
import { ref, onMounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const page = usePage()

const form = ref({
  username: '',
  password: '',
  remember: false,
})

const errors = ref({})
const loading = ref(false)
const showPassword = ref(false)
const isDark = ref(false)
const themeMode = ref('system')
let themeMediaQuery = null

const applyThemeMode = (mode, persist = true) => {
  themeMode.value = mode
  if (persist) {
    localStorage.setItem('theme', mode)
  }
  const prefersDark = themeMediaQuery?.matches ?? false
  isDark.value = mode === 'dark' || (mode === 'system' && prefersDark)
  document.documentElement.classList.toggle('dark', isDark.value)
}

const handleSystemThemeChange = (event) => {
  if (themeMode.value === 'system') {
    isDark.value = event.matches
    document.documentElement.classList.toggle('dark', isDark.value)
  }
}

const setThemeMode = (mode) => {
  applyThemeMode(mode, true)
}

const cycleThemeMode = () => {
  const order = ['light', 'dark', 'system']
  const currentIndex = order.indexOf(themeMode.value)
  const nextMode = order[(currentIndex + 1) % order.length]
  setThemeMode(nextMode)
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
})

const submit = () => {
  loading.value = true
  errors.value = {}
  
  router.post('/login', form.value, {
    onError: (err) => {
      errors.value = err
    },
    onFinish: () => {
      loading.value = false
    },
  })
}
</script>

<template>
  <div class="bg-slate-50 dark:bg-[#0b1120] text-slate-800 dark:text-white min-h-screen w-full flex items-center justify-center relative overflow-hidden selection:bg-blue-500 selection:text-white transition-colors duration-300">
    
    <!-- Compact Theme Switcher -->
    <button
      @click="cycleThemeMode"
      class="absolute top-6 right-6 z-50 flex items-center justify-center rounded-xl bg-white/80 dark:bg-white/10 backdrop-blur-md shadow-lg border border-slate-200 dark:border-white/10 p-2 text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition"
      :title="`Tema: ${themeMode}`"
    >
      <svg v-if="themeMode === 'light'" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
      </svg>
      <svg v-else-if="themeMode === 'dark'" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
      </svg>
      <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17h4.5m-6 3h7.5M3 7.5A2.5 2.5 0 015.5 5h13A2.5 2.5 0 0121 7.5v6A2.5 2.5 0 0118.5 16h-13A2.5 2.5 0 013 13.5v-6z" />
      </svg>
    </button>

    <!-- Background Blobs -->
    <div class="absolute top-[-10%] left-[-10%] w-[500px] h-[500px] bg-blue-500/30 rounded-full blur-[100px] opacity-50 pointer-events-none mix-blend-multiply dark:mix-blend-screen animate-pulse"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-[500px] h-[500px] bg-purple-500/30 rounded-full blur-[100px] opacity-50 pointer-events-none mix-blend-multiply dark:mix-blend-screen animate-pulse" style="animation-delay: 2s"></div>

    <!-- Login Card -->
    <div class="w-full max-w-[400px] p-4 relative z-10">
      <div class="bg-white/90 dark:bg-slate-900/90 backdrop-blur-xl border border-slate-200 dark:border-white/10 rounded-3xl shadow-2xl p-8 md:p-10 relative overflow-hidden transform transition hover:scale-[1.01] duration-500">
        
        <!-- Top Gradient Bar -->
        <div class="absolute top-0 left-0 w-full h-1.5 bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500"></div>

        <!-- Logo & Title -->
        <div class="text-center mb-8">
          <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-blue-50 dark:bg-white/5 mb-4 shadow-inner ring-1 ring-slate-100 dark:ring-white/10">
            <img src="/assets/favicon.svg" alt="Logo" class="w-10 h-10 drop-shadow-md" onerror="this.style.display='none'">
          </div>
          <h1 class="text-2xl font-black text-slate-900 dark:text-white tracking-tight">
            DARAT<span class="text-blue-600 dark:text-blue-500">LAUT</span>
          </h1>
          <p class="text-xs text-slate-500 dark:text-slate-400 font-bold tracking-widest uppercase mt-1">Network System</p>
        </div>

        <!-- Error Message -->
        <div v-if="errors.username || errors.password || errors.error" class="mb-6 p-3 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 flex items-center gap-3">
          <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span class="text-sm font-bold text-red-600 dark:text-red-400">
            {{ errors.username || errors.password || errors.error }}
          </span>
        </div>

        <!-- Login Form -->
        <form @submit.prevent="submit" class="space-y-5">
          
          <!-- Username Field -->
          <div class="space-y-1.5">
            <label class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400 ml-1">Username</label>
            <div class="relative group">
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg class="w-5 h-5 text-slate-400 group-focus-within:text-blue-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
              </div>
              <input 
                v-model="form.username"
                type="text" 
                required 
                autofocus
                placeholder="Masukkan username" 
                class="w-full bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white rounded-xl py-3 pl-11 pr-4 focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 transition placeholder-slate-400 font-medium text-sm"
                :class="{ 'border-red-500': errors.username }"
              />
            </div>
          </div>

          <!-- Password Field -->
          <div class="space-y-1.5">
            <label class="text-xs font-bold uppercase text-slate-500 dark:text-slate-400 ml-1">Password</label>
            <div class="relative group">
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg class="w-5 h-5 text-slate-400 group-focus-within:text-blue-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
              </div>
              <input 
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'" 
                required 
                placeholder="Masukkan password" 
                class="w-full bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white rounded-xl py-3 pl-11 pr-12 focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 transition placeholder-slate-400 font-medium text-sm"
                :class="{ 'border-red-500': errors.password }"
              />
              
              <!-- Toggle Password Visibility -->
              <button 
                type="button" 
                @click="showPassword = !showPassword" 
                class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition focus:outline-none"
              >
                <svg v-if="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.05 10.05 0 011.577-2.9m3.1-2.1a9.985 9.985 0 014.865-1.002C16.478 5 20.268 7.943 21.542 12c-.63 2.006-1.803 3.73-3.23 4.965M3 3l18 18"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Remember Me -->
          <div class="flex items-center">
            <label class="flex items-center cursor-pointer">
              <input
                v-model="form.remember"
                type="checkbox"
                class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500 bg-slate-50 dark:bg-slate-800"
              />
              <span class="ml-2 text-sm text-slate-600 dark:text-slate-400">Ingat saya</span>
            </label>
          </div>

          <!-- Submit Button -->
          <button 
            type="submit" 
            :disabled="loading"
            class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 disabled:from-slate-400 disabled:to-slate-500 text-white font-bold py-3.5 rounded-xl shadow-lg shadow-blue-500/30 transform hover:scale-[1.02] active:scale-[0.98] disabled:scale-100 transition-all duration-200 mt-2 flex items-center justify-center gap-2"
          >
            <svg v-if="loading" class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>{{ loading ? 'Memproses...' : 'Masuk Sekarang' }}</span>
          </button>
        </form>

        <!-- Footer -->
        <div class="mt-8 text-center">
          <p class="text-[10px] text-slate-400 font-medium">
            &copy; {{ new Date().getFullYear() }} ISP Admin Panel<br/>
            Billing System v2.0
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Ensure dark mode transitions smoothly */
:deep(.dark) {
  color-scheme: dark;
}
</style>

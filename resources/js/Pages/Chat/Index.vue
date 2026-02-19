<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const page = usePage()

const adminName = computed(() => page.props.auth?.user?.name || page.props.auth?.user?.username || 'Admin')
const adminRole = computed(() => page.props.auth?.user?.role || 'Staff')
const adminFirst = computed(() => (String(adminName.value || 'Admin').trim().split(/\s+/)[0]) || 'Admin')
const legacyChatVersion = computed(() => page.props.legacyChatVersion || '')

function loadScriptOnce(src, id) {
  return new Promise((resolve, reject) => {
    if (document.getElementById(id)) return resolve()
    const s = document.createElement('script')
    s.id = id
    const v = legacyChatVersion.value
    s.src = v ? (src + (src.includes('?') ? '&' : '?') + 'v=' + encodeURIComponent(v)) : src
    s.async = true
    s.onload = () => resolve()
    s.onerror = (e) => reject(e)
    document.body.appendChild(s)
  })
}

function applyAdminPlaceholders() {
  const letter = (adminName.value || 'Admin').trim().charAt(0).toUpperCase() || 'A'
  const elLetter = document.getElementById('adm-menu-letter')
  const elName = document.getElementById('adm-menu-name')
  const elRole = document.getElementById('adm-menu-role')
  const elFirst = document.getElementById('adm-first-name')
  if (elLetter) elLetter.textContent = letter
  if (elName) elName.textContent = adminName.value || 'Admin'
  if (elRole) elRole.textContent = adminRole.value || 'Staff'
  if (elFirst) elFirst.textContent = adminFirst.value || 'Admin'
}

onMounted(async () => {
  // Prevent page scroll bleed while using legacy chat (independent scroll panes).
  document.documentElement.classList.add('chat-admin-lock')
  document.body.classList.add('chat-admin-lock')

  // Clean previous intervals when navigating back to chat.
  window.__chatDispose?.()

  await loadScriptOnce('/legacy-chat/inline.js', 'legacy-chat-inline')
  await loadScriptOnce('/legacy-chat/app.js', 'legacy-chat-app')
  await loadScriptOnce('/legacy-chat/game.js', 'legacy-chat-game')

  applyAdminPlaceholders()
  window.__chatBoot?.()
  loadLeadStats() // preload for badge count
})

onBeforeUnmount(() => {
  window.__chatDispose?.()
  document.documentElement.classList.remove('chat-admin-lock')
  document.body.classList.remove('chat-admin-lock')
})

// ===== LEADS INTEGRATION =====
const activeTab = ref('chat')
const leads = ref([])
const leadStats = ref({ total: 0, new: 0, contacted: 0, interested: 0, converted: 0, lost: 0 })
const leadLoading = ref(false)
const leadFilter = ref({ search: '', status: '' })
const leadStatuses = ref({})
const showLeadDetail = ref(false)
const showLeadForm = ref(false)
const editingLead = ref(null)
const selectedLead = ref(null)
const leadForm = ref({ customer_name: '', customer_phone: '', customer_address: '', notes: '', status: 'NEW', source: '' })
const LEADS_API = '/api/v1/leads'

async function loadLeads() {
  leadLoading.value = true
  try {
    const p = new URLSearchParams()
    if (leadFilter.value.search) p.append('search', leadFilter.value.search)
    if (leadFilter.value.status) p.append('status', leadFilter.value.status)
    p.append('per_page', '50')
    const r = await fetch(`${LEADS_API}?${p}`)
    const d = await r.json()
    if (d.status === 'ok') leads.value = d.data
  } catch (e) { console.error('Leads:', e) }
  leadLoading.value = false
}

async function loadLeadStats() {
  try { const r = await fetch(`${LEADS_API}/stats`); const d = await r.json(); if (d.status === 'ok') leadStats.value = d.data } catch {}
}

async function loadLeadStatuses() {
  try { const r = await fetch(`${LEADS_API}/statuses`); const d = await r.json(); if (d.status === 'ok') leadStatuses.value = d.data } catch {}
}

function switchTab(tab) {
  activeTab.value = tab
  if (tab === 'leads' && !leads.value.length && !leadLoading.value) { loadLeadStatuses(); loadLeadStats(); loadLeads() }
  if (tab === 'chat') { selectedLead.value = null; showLeadDetail.value = false }
}

function openLeadForm(lead = null) {
  editingLead.value = lead
  leadForm.value = lead
    ? { customer_name: lead.customer_name, customer_phone: lead.customer_phone, customer_address: lead.customer_address || '', notes: lead.notes || '', status: lead.status, source: lead.source || '' }
    : { customer_name: '', customer_phone: '', customer_address: '', notes: '', status: 'NEW', source: 'chat' }
  showLeadForm.value = true
  showLeadDetail.value = false
}

async function saveLead() {
  try {
    const url = editingLead.value ? `${LEADS_API}/${editingLead.value.id}` : LEADS_API
    const r = await fetch(url, { method: editingLead.value ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(leadForm.value) })
    const d = await r.json()
    if (d.status === 'ok') { showLeadForm.value = false; loadLeads(); loadLeadStats() }
    else alert(d.message || 'Gagal menyimpan')
  } catch (e) { alert('Error: ' + e.message) }
}

async function deleteLead(lead) {
  if (!confirm(`Hapus lead "${lead.customer_name}"?`)) return
  try {
    const r = await fetch(`${LEADS_API}/${lead.id}`, { method: 'DELETE' })
    const d = await r.json()
    if (d.status === 'ok') { showLeadDetail.value = false; selectedLead.value = null; loadLeads(); loadLeadStats() }
  } catch (e) { alert('Error: ' + e.message) }
}

async function convertLead(lead) {
  if (!confirm(`Konversi "${lead.customer_name}" ke pasang baru?`)) return
  try {
    const r = await fetch(`${LEADS_API}/${lead.id}/convert`, { method: 'POST' })
    const d = await r.json()
    if (d.status === 'ok') { alert('Lead dikonversi ke pasang baru'); showLeadDetail.value = false; loadLeads(); loadLeadStats() }
  } catch (e) { alert('Error: ' + e.message) }
}

function selectLead(lead) { selectedLead.value = lead; showLeadDetail.value = true }

function leadBadge(s) {
  return { NEW: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300', CONTACTED: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300', INTERESTED: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300', CONVERTED: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300', LOST: 'bg-slate-200 text-slate-600 dark:bg-slate-700/30 dark:text-slate-400' }[s] || 'bg-slate-100 text-slate-600'
}

function fmtLeadDate(d) { return d ? new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) : '-' }
</script>

<template>
  <Head title="Chat Admin">
    <!-- Ensure legacy relative URLs (admin_api.php, uploads/) resolve like the native /chat/ app -->
    <base href="/chat/" />
  </Head>
  <AdminLayout>
    <div id="legacy-chat-root" class="h-[calc(100vh-3.5rem)] lg:h-screen w-full overflow-hidden bg-[#f8fafc] text-slate-600 dark:bg-darkbg dark:text-slate-300 transition-colors duration-300">
<audio id="notif-sound" src="/assets/ding.mp3"></audio>

    <div id="main-app">

        <div id="panel-list" class="w-full md:w-80 flex-shrink-0 flex flex-col border-r border-slate-200 dark:border-white/10 bg-white dark:bg-darkpanel h-full z-20 relative shadow-sm transition-colors">
            <!-- Tab Bar: Chat / Leads -->
            <div class="flex shrink-0 border-b border-slate-200 dark:border-white/10">
              <button @click="switchTab('chat')" :class="activeTab === 'chat' ? 'text-blue-600 dark:text-green-400 border-blue-600 dark:border-green-400 bg-blue-50/50 dark:bg-green-900/10' : 'text-slate-400 dark:text-slate-500 border-transparent hover:text-slate-600'" class="flex-1 flex items-center justify-center gap-1.5 py-2.5 text-[11px] font-bold uppercase tracking-wider border-b-2 transition-all">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                Chat
              </button>
              <button @click="switchTab('leads')" :class="activeTab === 'leads' ? 'text-blue-600 dark:text-green-400 border-blue-600 dark:border-green-400 bg-blue-50/50 dark:bg-green-900/10' : 'text-slate-400 dark:text-slate-500 border-transparent hover:text-slate-600'" class="flex-1 flex items-center justify-center gap-1.5 py-2.5 text-[11px] font-bold uppercase tracking-wider border-b-2 transition-all relative">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Leads
                <span v-if="leadStats.new > 0" class="bg-red-500 text-white text-[9px] rounded-full px-1 min-w-[16px] h-4 flex items-center justify-center font-bold leading-none">{{ leadStats.new }}</span>
              </button>
            </div>

            <!-- Chat Tab Content -->
            <div v-show="activeTab === 'chat'" class="flex flex-col flex-1 min-h-0">
            <!-- Chat tools (moved from legacy header; navigation stays in admin sidebar) -->
            <div class="flex items-center justify-end gap-2 px-3 pt-3 pb-1 shrink-0">
                <button type="button" onclick="openTplManager()" class="h-9 w-9 flex items-center justify-center rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-[#202c33] text-slate-600 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-white/5 transition" title="Atur Balas Cepat">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </button>
                <button type="button" onclick="openSettings()" class="h-9 w-9 flex items-center justify-center rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-[#202c33] text-slate-600 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-white/5 transition" title="Jadwal & WA">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M5 11h14M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </button>
                <button type="button" onclick="openAdminProfile()" class="h-9 w-9 flex items-center justify-center rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-[#202c33] text-slate-600 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-white/5 transition" title="Pengaturan Profil">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21a9 9 0 100-18 9 9 0 000 18z" />
                    </svg>
                </button>
            </div>
            <div class="px-3 pt-3 pb-2 border-b border-slate-200 dark:border-white/10 bg-slate-50/50 dark:bg-transparent shrink-0 space-y-2">
                <form action="#" onsubmit="return false;" autocomplete="off" class="relative group">
                    <input type="text" id="search-user" onkeyup="filterUsers()" placeholder="Cari pelanggan..." autocomplete="off" class="w-full bg-white dark:bg-[#202c33] border border-slate-300 dark:border-transparent text-sm rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:border-blue-500 dark:focus:bg-[#2a3942] dark:text-white transition text-slate-700 shadow-sm placeholder-slate-400 dark:placeholder-slate-500">
                    <svg class="w-4 h-4 absolute left-3 top-2.5 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </form>
                <div id="filter-container" class="flex gap-2 overflow-x-auto custom-scrollbar pb-1 min-h-[30px]">
                    <div class="text-[10px] text-slate-400 italic px-2">Memuat filter...</div>
                </div>
            </div>

            <div id="user-list" class="flex-1 overflow-y-auto custom-scrollbar bg-white dark:bg-darkpanel space-y-0.5">
                <div class="flex flex-col items-center justify-center h-40 text-slate-400 text-sm animate-pulse">Memuat...</div>
            </div>
            </div><!-- /chat tab -->

            <!-- Leads Tab Content -->
            <div v-show="activeTab === 'leads'" class="flex flex-col flex-1 min-h-0">
              <!-- Header -->
              <div class="flex items-center justify-between px-3 pt-3 pb-1 shrink-0">
                <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider">{{ leadStats.total }} leads</span>
                <button @click="openLeadForm()" class="h-7 w-7 flex items-center justify-center rounded-lg bg-blue-600 dark:bg-green-600 text-white hover:bg-blue-700 dark:hover:bg-green-700 transition shadow-sm" title="Tambah Lead">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                </button>
              </div>
              <!-- Status Filter Pills -->
              <div class="flex gap-1 px-3 pb-2 overflow-x-auto custom-scrollbar shrink-0">
                <button @click="leadFilter.status=''; loadLeads()" :class="!leadFilter.status ? 'bg-blue-600 text-white dark:bg-green-600' : 'bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-white/10'" class="text-[10px] px-2 py-0.5 rounded-full font-semibold whitespace-nowrap transition">Semua {{ leadStats.total }}</button>
                <button @click="leadFilter.status='NEW'; loadLeads()" :class="leadFilter.status === 'NEW' ? 'bg-blue-600 text-white' : 'bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900/30'" class="text-[10px] px-2 py-0.5 rounded-full font-semibold whitespace-nowrap transition">Baru {{ leadStats.new }}</button>
                <button @click="leadFilter.status='CONTACTED'; loadLeads()" :class="leadFilter.status === 'CONTACTED' ? 'bg-yellow-500 text-white' : 'bg-yellow-50 text-yellow-600 dark:bg-yellow-900/20 dark:text-yellow-300 hover:bg-yellow-100'" class="text-[10px] px-2 py-0.5 rounded-full font-semibold whitespace-nowrap transition">Kontak {{ leadStats.contacted }}</button>
                <button @click="leadFilter.status='INTERESTED'; loadLeads()" :class="leadFilter.status === 'INTERESTED' ? 'bg-purple-600 text-white' : 'bg-purple-50 text-purple-600 dark:bg-purple-900/20 dark:text-purple-300 hover:bg-purple-100'" class="text-[10px] px-2 py-0.5 rounded-full font-semibold whitespace-nowrap transition">Minat {{ leadStats.interested }}</button>
                <button @click="leadFilter.status='CONVERTED'; loadLeads()" :class="leadFilter.status === 'CONVERTED' ? 'bg-green-600 text-white' : 'bg-green-50 text-green-600 dark:bg-green-900/20 dark:text-green-300 hover:bg-green-100'" class="text-[10px] px-2 py-0.5 rounded-full font-semibold whitespace-nowrap transition">Jadi {{ leadStats.converted }}</button>
                <button @click="leadFilter.status='LOST'; loadLeads()" :class="leadFilter.status === 'LOST' ? 'bg-slate-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400 hover:bg-slate-200'" class="text-[10px] px-2 py-0.5 rounded-full font-semibold whitespace-nowrap transition">Hilang {{ leadStats.lost }}</button>
              </div>
              <!-- Search -->
              <div class="px-3 pb-2 border-b border-slate-200 dark:border-white/10 shrink-0">
                <div class="relative">
                  <input v-model="leadFilter.search" @keyup.enter="loadLeads()" type="text" placeholder="Cari nama/HP..." class="w-full bg-white dark:bg-[#202c33] border border-slate-300 dark:border-transparent text-sm rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:border-blue-500 dark:focus:bg-[#2a3942] dark:text-white transition shadow-sm placeholder-slate-400">
                  <svg class="w-4 h-4 absolute left-3 top-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
              </div>
              <!-- Lead Cards -->
              <div class="flex-1 overflow-y-auto custom-scrollbar">
                <div v-if="leadLoading" class="flex items-center justify-center h-32 text-slate-400 text-sm animate-pulse">Memuat...</div>
                <div v-else-if="!leads.length" class="flex flex-col items-center justify-center h-32 text-slate-400 text-sm gap-2">
                  <svg class="w-8 h-8 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                  Tidak ada leads
                </div>
                <div v-for="lead in leads" :key="lead.id" @click="selectLead(lead)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer border-b border-slate-100 dark:border-white/5 hover:bg-slate-50 dark:hover:bg-white/5 transition group">
                  <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold shrink-0" :class="leadBadge(lead.status)">
                    {{ (lead.customer_name||'?').charAt(0).toUpperCase() }}
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-1">
                      <span class="text-sm font-semibold text-slate-800 dark:text-white truncate">{{ lead.customer_name }}</span>
                      <span class="text-[10px] text-slate-400 dark:text-slate-500 whitespace-nowrap">{{ fmtLeadDate(lead.created_at) }}</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-0.5">
                      <span class="text-[11px] text-slate-500 dark:text-slate-400 truncate">{{ lead.customer_phone || '-' }}</span>
                      <span class="text-[9px] px-1.5 py-px rounded-full font-semibold shrink-0" :class="leadBadge(lead.status)">{{ leadStatuses[lead.status] || lead.status }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- /leads tab -->
        </div>

        <div id="panel-chat" class="flex-1 flex flex-col h-full bg-[#f0f2f5] dark:bg-darkbg relative mobile-hidden w-full overflow-hidden transition-colors">
            
            <div id="empty-state" class="absolute inset-0 flex flex-col items-center justify-center bg-[#f8fafc] dark:bg-darkbg z-0 overflow-hidden group select-none transition-opacity duration-300">
                <div class="absolute inset-0 bg-[url('/assets/favicon.svg')] bg-repeat opacity-[0.05] dark:opacity-[0.02] grayscale animate-[pulse_8s_infinite] pointer-events-none"></div>
                <div class="relative z-10 w-full max-w-5xl px-4 md:px-8 py-8 animate-in fade-in zoom-in duration-700">
                    <div class="text-center">
                        <div class="relative inline-block mb-6 group-hover:scale-110 transition-transform duration-500">
                            <div class="absolute inset-0 bg-blue-200 dark:bg-green-900/30 blur-3xl opacity-40 animate-pulse rounded-full"></div>
                            <img src="/assets/favicon.svg" alt="Empty" class="w-20 h-20 md:w-24 md:h-24 opacity-90 animate-[bounce_3s_infinite] drop-shadow-xl relative z-10 filter drop-shadow-md">
                        </div>
                        <h3 class="text-3xl font-black text-slate-800 dark:text-slate-200 mb-2 tracking-tight">Halo, <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-blue-800 dark:from-green-400 dark:to-emerald-600"><span id="adm-first-name">Admin</span></span>!</h3>
                        <p class="text-slate-500 dark:text-slate-400 text-sm max-w-xs mx-auto leading-relaxed mb-5">Pilih percakapan dari panel kiri.</p>
                        <button onclick="openGame()" class="group relative px-6 py-3 bg-white dark:bg-[#233138] border border-slate-200 dark:border-white/10 rounded-full text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-blue-600 dark:hover:text-green-400 hover:border-blue-300 dark:hover:border-green-500/50 shadow-sm hover:shadow-md transition-all flex items-center gap-2 mx-auto overflow-hidden">
                            <span class="absolute inset-0 bg-blue-50 dark:bg-green-900/20 translate-y-full group-hover:translate-y-0 transition-transform duration-300"></span><span class="relative z-10 text-lg">🐍</span><span class="relative z-10">Gabut? Main Snake</span>
                        </button>
                    </div>

                    <div id="customer-overview-panel" class="mt-7 bg-white/90 dark:bg-[#111b21]/95 backdrop-blur border border-slate-200 dark:border-white/10 rounded-2xl shadow-sm p-4 md:p-5 text-left">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4">
                            <div>
                                <h4 class="text-sm font-extrabold tracking-wide text-slate-800 dark:text-slate-100">Data Pelanggan</h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">Ringkasan realtime aktivitas pelanggan dari dashboard native.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <select id="customer-overview-period" class="h-9 px-3 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-[#202c33] text-xs font-bold text-slate-700 dark:text-slate-200 outline-none focus:border-blue-500 dark:focus:border-green-500">
                                    <option value="today">Hari Ini</option>
                                    <option value="yesterday">Kemarin</option>
                                    <option value="7days">7 Hari</option>
                                    <option value="30days">30 Hari</option>
                                </select>
                                <button id="customer-overview-refresh" type="button" class="h-9 px-3 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-[#202c33] text-xs font-bold text-slate-600 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-white/5 transition">Refresh</button>
                                <span id="customer-overview-live-pill" class="inline-flex items-center gap-1.5 px-2.5 h-9 rounded-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-[#202c33] text-[11px] font-bold text-slate-600 dark:text-slate-300">
                                    <span class="relative flex h-2 w-2">
                                        <span id="customer-overview-live-ping" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                        <span id="customer-overview-live-dot" class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                                    </span>
                                    <span id="customer-overview-live-text">LIVE</span>
                                </span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 mb-4">
                            <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-[#202c33] p-3">
                                <div class="text-[10px] uppercase font-bold tracking-wider text-slate-400">Sedang Online</div>
                                <div id="customer-overview-online" class="text-xl font-black text-slate-800 dark:text-white mt-1">0</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-[#202c33] p-3">
                                <div class="text-[10px] uppercase font-bold tracking-wider text-slate-400">Pengunjung Unik</div>
                                <div id="customer-overview-unique" class="text-xl font-black text-slate-800 dark:text-white mt-1">0</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-[#202c33] p-3">
                                <div class="text-[10px] uppercase font-bold tracking-wider text-slate-400">Leads Baru</div>
                                <div id="customer-overview-leads" class="text-xl font-black text-slate-800 dark:text-white mt-1">0</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-[#202c33] p-3">
                                <div class="text-[10px] uppercase font-bold tracking-wider text-slate-400">Total Hits</div>
                                <div id="customer-overview-hits" class="text-xl font-black text-slate-800 dark:text-white mt-1">0</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-5 gap-3">
                            <div class="lg:col-span-3 rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-[#202c33] overflow-hidden">
                                <div class="px-3 py-2 border-b border-slate-200 dark:border-white/10 text-[10px] uppercase font-bold tracking-wider text-slate-500 dark:text-slate-400">Log Pelanggan</div>
                                <div id="customer-overview-logs" class="max-h-56 overflow-y-auto custom-scrollbar divide-y divide-slate-100 dark:divide-white/5">
                                    <div class="px-3 py-6 text-center text-xs text-slate-400">Memuat data pelanggan...</div>
                                </div>
                            </div>
                            <div class="lg:col-span-2 rounded-xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-[#202c33] overflow-hidden">
                                <div class="px-3 py-2 border-b border-slate-200 dark:border-white/10 text-[10px] uppercase font-bold tracking-wider text-slate-500 dark:text-slate-400">Tren Kunjungan Isolir</div>
                                <div class="p-2.5">
                                    <div id="customer-overview-chart" class="h-56"></div>
                                    <div id="customer-overview-chart-empty" class="hidden px-2 py-8 text-center text-xs text-slate-400">Belum ada data chart untuk periode ini.</div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 text-[10px] text-slate-400 dark:text-slate-500">
                            Update terakhir: <span id="customer-overview-updated">-</span>
                        </div>
                    </div>
                </div>
            </div>

            <div id="game-interface" class="hidden absolute inset-0 flex-col bg-[#efeae2] dark:bg-[#0b141a] z-10 transition-colors duration-300">
                <div class="h-16 border-b border-slate-200 dark:border-white/10 bg-white dark:bg-[#202c33] flex items-center justify-between px-6 shrink-0 shadow-sm relative z-20 transition-colors">
                    <div class="flex items-center gap-4">
                        <div class="p-2 bg-green-100 dark:bg-green-500/10 rounded-lg border border-green-200 dark:border-green-500/20 text-2xl animate-pulse">🐍</div>
                        <div>
                            <h3 class="font-black text-slate-800 dark:text-white text-lg tracking-wider">SNAKE GAME</h3>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400 font-mono">Keyboard: Arrow Keys / WASD</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="hidden sm:block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider text-right">High Score<br><span id="high-score-display" class="text-orange-500 text-sm">0</span></div>
                        <div class="h-8 w-px bg-slate-200 dark:bg-white/10 mx-1 hidden sm:block"></div>
                        <div class="bg-slate-100 dark:bg-[#111b21] px-4 py-1.5 rounded-full border border-slate-200 dark:border-white/5 text-slate-600 dark:text-slate-300 font-mono text-sm shadow-inner transition-colors">Score: <span id="game-score" class="text-blue-600 dark:text-green-400 font-bold text-lg ml-1">0</span></div>
                        <button onclick="closeGame()" class="p-2 bg-slate-50 dark:bg-white/5 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full text-slate-400 hover:text-red-500 transition border border-slate-200 dark:border-white/5" title="Tutup Game"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </div>
                </div>
                <div class="flex-1 flex items-center justify-center relative bg-[#efeae2] dark:bg-[#0b141a] overflow-hidden transition-colors">
                    <div class="absolute inset-0 opacity-[0.4] dark:opacity-[0.05]" style="background-image: radial-gradient(#cbd5e1 1px, transparent 1px); background-size: 20px 20px;"></div>
                    <canvas id="gameCanvas" width="600" height="400" class="bg-[#efeae2] dark:bg-[#0b141a] border-4 border-white dark:border-[#202c33] rounded-xl shadow-2xl relative z-10 max-w-[95%] max-h-[90%] outline-none transition-all duration-300"></canvas>
                    <div id="game-over-msg" class="hidden absolute inset-0 z-20 bg-white/90 dark:bg-black/90 backdrop-blur-sm flex flex-col items-center justify-center text-slate-800 dark:text-white animate-in fade-in zoom-in duration-300 p-6">
                        <div class="mb-6 relative animate-shatter">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" class="w-24 h-24">
                                <defs><mask id="starHoleRed"><rect x="0" y="0" width="128" height="128" fill="white" /><path d="M64 36 C78 50, 78 50, 92 64 C78 78, 78 78, 64 92 C50 78, 50 78, 36 64 C50 50, 50 50, 64 36 Z" fill="black" /></mask></defs>
                                <path d="M64 0 C96 32, 96 32, 128 64 C96 96, 96 96, 64 128 C32 96, 32 96, 0 64 C32 32, 32 32, 64 0 Z" fill="currentColor" mask="url(#starHoleRed)" />
                            </svg>
                        </div>
                        <h3 class="text-2xl font-black mb-1 tracking-widest text-red-500">GAME OVER</h3>
                        <div class="text-center mb-6">
                            <span class="text-xs text-slate-500 dark:text-slate-400 uppercase font-bold tracking-wider">Skor Kamu</span>
                            <div id="final-score" class="font-black text-4xl text-slate-900 dark:text-white">0</div>
                            <div id="new-record-msg" class="hidden mt-1 text-[10px] font-bold text-orange-500 uppercase tracking-widest animate-pulse">🏆 Rekor Pribadi Baru!</div>
                        </div>
                        <div class="w-full max-w-[280px] bg-slate-100 dark:bg-white/5 rounded-xl border border-slate-200 dark:border-white/10 p-3 mb-6">
                            <h4 class="text-[10px] uppercase font-bold text-slate-400 mb-2 text-center tracking-widest">👑 Top 5 Admin</h4>
                            <div id="leaderboard-list" class="space-y-1.5"><div class="text-center text-[10px] text-slate-400 animate-pulse">Memuat ranking...</div></div>
                        </div>
                        <div class="flex gap-3 w-full max-w-[280px]">
                            <button onclick="closeGame()" class="flex-1 py-3 rounded-xl border border-slate-300 dark:border-white/20 bg-white dark:bg-white/5 hover:bg-slate-100 dark:hover:bg-white/10 text-xs font-bold transition text-slate-600 dark:text-slate-200">Keluar</button>
                            <button onclick="startGame()" class="flex-1 py-3 rounded-xl bg-blue-600 dark:bg-green-600 hover:bg-blue-500 dark:hover:bg-green-500 text-white text-xs font-bold shadow-lg transition transform hover:scale-105 active:scale-95">Main Lagi (Spasi)</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="chat-interface" class="hidden w-full h-full bg-[#efeae2] dark:bg-darkbg z-20 relative">
                <div class="flex-1 flex flex-col min-w-0 h-full relative border-r border-slate-200 dark:border-white/10">
                    <div class="absolute top-0 left-0 right-0 h-16 border-b border-slate-200 dark:border-white/10 bg-white/95 dark:bg-darkpanel/95 backdrop-blur-md flex items-center justify-between px-3 md:px-4 z-50 shadow-sm transition-colors">
                        <div class="flex items-center gap-3 overflow-hidden">
                            <button onclick="closeChatMobile()" class="md:hidden p-2 -ml-2 text-slate-500 hover:text-blue-600 dark:text-slate-400 dark:hover:text-white transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>
                            <div id="h-avatar" class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 dark:from-green-600 dark:to-emerald-700 flex items-center justify-center font-bold text-white shadow-md shrink-0 text-sm border border-white dark:border-white/10">U</div>
                            <div class="min-w-0 flex flex-col justify-center">
                                <div class="flex items-center gap-2 cursor-pointer group" onclick="toggleUserSidebar()" title="Lihat Detail"><h3 id="h-name" class="info-name font-bold text-slate-800 dark:text-white text-sm truncate leading-none max-w-[150px] md:max-w-[200px] group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">User</h3><svg class="w-3 h-3 text-slate-400 opacity-0 group-hover:opacity-100 transition-opacity transform group-hover:rotate-90 duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div>
                                <div class="flex items-center gap-2 mt-0.5"><div id="h-status" class="text-[11px] text-slate-500 dark:text-slate-400 truncate">Loading...</div><span class="text-slate-300 dark:text-slate-600 text-[10px]">|</span><span id="h-id" class="text-[11px] font-mono text-blue-600 dark:text-green-400 bg-blue-50 dark:bg-white/5 px-1 rounded">#ID</span></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <a id="wa-link" href="#" target="_blank" class="p-2 text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-white/10 rounded-lg transition" title="WhatsApp"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.891-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg></a>
                            <div class="relative" id="dropdown-chat-container">
                                <button onclick="toggleMenu()" class="p-2 text-slate-500 dark:text-slate-400 hover:text-blue-600 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 rounded-lg transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg></button>
                                <div id="chat-menu" class="hidden absolute right-0 top-full mt-2 w-56 bg-white dark:bg-[#233138] border border-slate-200 dark:border-white/10 rounded-xl shadow-xl py-1 z-50 animate-zoomIn ring-1 ring-black/5">
                                    <a href="#" id="menu-toggle-session" onclick="openEndModal()" class="block px-4 py-2.5 text-sm hover:bg-green-50 dark:hover:bg-white/5 text-green-600 dark:text-green-400 border-b border-slate-100 dark:border-white/10 font-bold">Selesaikan Sesi</a>
                                    <a href="#" onclick="toggleUserSidebar()" class="block px-4 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-white/5 text-slate-700 dark:text-slate-200 border-b border-slate-100 dark:border-white/10">Detail Pelanggan</a>
                                    <a href="#" onclick="openEditUser()" class="block px-4 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-white/5 text-blue-600 dark:text-blue-400">Edit Data</a>
                                    <a href="#" onclick="deleteSession()" class="block px-4 py-2.5 text-sm hover:bg-red-50 dark:hover:bg-white/5 text-red-600 dark:text-red-400 border-t border-slate-100 dark:border-white/10">Hapus Chat</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="messages" class="flex-1 overflow-y-auto pt-24 md:pt-28 p-4 md:p-5 custom-scrollbar space-y-3 bg-[#efeae2] dark:bg-[#0b141a] bg-opacity-30 dark:bg-opacity-100 transition-colors" style="background-image: radial-gradient(#d1d5db 1px, transparent 1px); background-size: 20px 20px;"></div>
                    
                    <div class="flex-none px-3 md:px-4 pt-2 pb-3 md:pt-2.5 md:pb-4 bg-[#f0f2f5] dark:bg-[#202c33] border-t border-slate-200/80 dark:border-white/[0.06] relative z-20 shrink-0 pb-safe transition-colors">
                        <div id="footer-active" class="flex items-end gap-2 w-full">
                            <!-- Template button -->
                            <div class="relative flex-shrink-0" id="dropdown-tpl-container">
                                <button onclick="toggleTpl()" class="h-10 w-10 flex items-center justify-center text-slate-500 dark:text-slate-400 rounded-full hover:bg-black/5 dark:hover:bg-white/5 hover:text-slate-700 dark:hover:text-slate-200 transition-colors mb-0.5" title="Template (/)"><svg class="w-[22px] h-[22px]" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></button>
                                <div id="tpl-popup" class="hidden absolute bottom-full left-0 mb-3 w-64 bg-white dark:bg-[#233138] border border-slate-200 dark:border-white/10 rounded-xl shadow-2xl overflow-hidden z-50 flex flex-col max-h-60 animate-zoomIn ring-1 ring-black/5">
                                    <div class="bg-slate-50 dark:bg-white/5 px-3 py-2 border-b border-slate-200 dark:border-white/10 flex justify-between items-center"><span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Balas Cepat (/)</span><button onclick="openTplManager()" class="text-[10px] text-blue-600 dark:text-blue-400 font-bold">ATUR</button></div><div id="tpl-list" class="overflow-y-auto custom-scrollbar flex-1 dark:text-slate-200"></div>
                                </div>
                            </div>
                            <!-- Unified input bar -->
                            <div class="relative flex-1 flex items-end bg-white dark:bg-[#2a3942] rounded-2xl border border-slate-200/70 dark:border-white/[0.06] focus-within:border-blue-400/50 dark:focus-within:border-green-500/40 transition-colors min-h-[44px]">
                                <input type="file" id="admin-img-input" accept="image/*" style="display: none;" onchange="sendImageAdmin()">
                                <button onclick="document.getElementById('admin-img-input').click()" class="flex-shrink-0 p-2 ml-1 mb-1 text-slate-400 dark:text-slate-500 hover:text-blue-600 dark:hover:text-green-400 rounded-full hover:bg-black/5 dark:hover:bg-white/5 transition-colors" title="Upload gambar"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg></button>
                                <textarea id="msg-input" class="flex-1 min-h-[44px] max-h-[120px] bg-transparent text-slate-800 dark:text-white text-[15px] leading-[22px] pl-1 pr-3 py-[11px] border-none ring-0 focus:ring-0 focus:outline-none focus:border-none outline-none shadow-none resize-none custom-scrollbar placeholder-slate-400 dark:placeholder-slate-500" rows="1" placeholder="Ketik pesan..." style="border:none !important; box-shadow:none !important;"></textarea>
                            </div>
                            <!-- Send button -->
                            <button onclick="sendMessage()" class="flex-shrink-0 h-10 w-10 flex items-center justify-center bg-blue-500 dark:bg-[#00a884] text-white rounded-full hover:bg-blue-600 dark:hover:bg-[#06cf9c] active:scale-95 transition-all mb-0.5 shadow-sm" title="Kirim"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>
                        </div>
                        <div id="footer-locked" class="hidden w-full items-center justify-between bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-xl p-3 px-4 border-dashed">
                            <div class="flex items-center gap-2"><div class="w-2 h-2 rounded-full bg-green-500"></div><span class="text-xs text-slate-500 dark:text-slate-400 font-bold uppercase tracking-wider">Tiket Selesai</span></div>
                            <button onclick="reopenSession()" class="flex items-center gap-2 px-4 py-2 bg-white dark:bg-[#202c33] hover:bg-slate-50 dark:hover:bg-[#2a3942] text-slate-600 dark:text-white text-xs font-bold rounded-lg transition border border-slate-300 dark:border-white/10 shadow-sm">Buka Kembali</button>
                        </div>
                    </div>
                </div>

                <div id="user-sidebar-backdrop" onclick="toggleUserSidebar()" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[55] hidden lg:hidden transition-opacity"></div>
                <div id="user-detail-sidebar" class="fixed inset-y-0 right-0 z-[60] w-80 bg-white dark:bg-darkpanel border-l border-slate-200 dark:border-white/10 transform transition-transform duration-300 translate-x-full lg:translate-x-0 lg:static lg:flex flex-col flex-shrink-0 overflow-y-auto custom-scrollbar shadow-2xl lg:shadow-sm">
                    <div class="flex lg:hidden items-center justify-between p-4 border-b border-slate-100 dark:border-white/10"><h3 class="font-bold text-slate-800 dark:text-white">Info Pelanggan</h3><button onclick="toggleUserSidebar()" class="p-2 bg-slate-100 dark:bg-white/10 rounded-full text-slate-500 dark:text-white"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div><div class="p-5 border-b border-slate-100 dark:border-white/10"><div class="flex justify-between items-center mb-4"><h4 class="text-[10px] uppercase font-bold text-slate-400 tracking-widest">Detail Pelanggan</h4><button onclick="openEditUser()" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 transition p-1.5 bg-blue-50 dark:bg-white/5 rounded-lg hover:bg-blue-100 text-xs"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg></button></div><div class="bg-slate-50 dark:bg-[#202c33] rounded-xl border border-slate-200 dark:border-white/5 p-4 space-y-5"><div class="flex items-center gap-3"><div class="info-name font-bold text-slate-800 dark:text-white text-sm leading-tight">-</div></div><div class="space-y-4"><div><div class="text-[10px] text-slate-400 font-bold uppercase mb-1">WhatsApp</div><div class="info-phone text-sm font-mono text-blue-600 dark:text-green-400 select-all border-b border-dashed border-slate-200 dark:border-white/10 pb-1 cursor-pointer">-</div></div><div><div class="text-[10px] text-slate-400 font-bold uppercase mb-1">Lokasi & IP</div><div class="info-location text-sm text-slate-600 dark:text-slate-300 leading-snug mb-1">-</div><div class="info-ip text-[10px] font-mono text-slate-500 dark:text-slate-400 bg-white dark:bg-black/20 inline-block px-1.5 py-0.5 rounded border border-slate-200 dark:border-white/10">-</div></div><div><div class="text-[10px] text-slate-400 font-bold uppercase mb-1">Alamat</div><div class="info-address text-sm text-slate-600 dark:text-slate-300 leading-relaxed bg-white dark:bg-black/20 p-2 rounded border border-slate-200 dark:border-white/10">-</div></div></div></div></div><div class="flex-1 p-5 flex flex-col min-h-[150px]"><div class="flex flex-col h-full bg-slate-50 dark:bg-[#202c33] rounded-xl border border-slate-200 dark:border-white/10 p-1 hover:border-slate-300 dark:hover:border-white/20 transition relative focus-within:border-blue-200 dark:focus-within:border-green-700"><label class="px-3 pt-3 pb-1 text-[10px] uppercase font-bold text-slate-400 tracking-widest flex justify-between items-center">Catatan Internal <span id="note-status" class="text-[9px] font-normal text-slate-400 transition-colors opacity-0">Saved</span></label><textarea id="customer-note" class="flex-1 w-full bg-transparent border-0 text-sm text-slate-700 dark:text-white focus:ring-0 p-3 pt-1 resize-none custom-scrollbar placeholder-slate-400 leading-relaxed" placeholder="Catatan teknis (admin only)..."></textarea></div></div><div class="p-5 border-t border-slate-100 dark:border-white/10 mt-auto bg-white dark:bg-darkpanel"><h4 class="text-[10px] uppercase font-bold text-slate-400 mb-3 tracking-widest">Aksi Cepat</h4><div class="grid grid-cols-2 gap-3"><button id="btn-end-session" onclick="openEndModal()" class="py-3 px-3 bg-green-50 dark:bg-green-900/20 hover:bg-green-100 dark:hover:bg-green-900/30 text-green-700 dark:text-green-400 text-xs font-bold rounded-xl border border-green-200 dark:border-green-900/50 flex items-center justify-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Selesai</button><button id="btn-reopen-session" onclick="reopenSession()" class="hidden py-3 px-3 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 text-blue-700 dark:text-blue-400 text-xs font-bold rounded-xl border border-blue-200 dark:border-blue-900/50 flex items-center justify-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Proses</button><button onclick="deleteSession()" class="py-3 px-3 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/30 text-red-700 dark:text-red-400 text-xs font-bold rounded-xl border border-red-200 dark:border-red-900/50 flex items-center justify-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Hapus</button></div></div>
                </div>
            </div>
        </div>
    </div>

    <div id="modal-edit-user" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4"><div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm z-10 transition-opacity" onclick="document.getElementById('modal-edit-user').classList.add('hidden')"></div><div class="bg-white dark:bg-[#233138] border border-slate-200 dark:border-white/10 w-full max-w-sm rounded-2xl p-6 shadow-2xl relative z-20 animate-zoomIn"><h3 class="font-bold text-slate-800 dark:text-white mb-5 flex items-center gap-2 text-lg">✏️ Edit Pelanggan</h3><div class="space-y-4"><input type="hidden" id="edit-visit-id"><div><label class="text-xs text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Nama</label><input type="text" id="edit-name" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2.5 text-slate-800 dark:text-white outline-none focus:border-blue-500"></div><div><label class="text-xs text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Nomor HP</label><input type="text" id="edit-phone" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2.5 text-slate-800 dark:text-white outline-none focus:border-blue-500"></div><div><label class="text-xs text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Alamat</label><textarea id="edit-addr" rows="3" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2.5 text-slate-800 dark:text-white outline-none resize-none"></textarea></div></div><div class="grid grid-cols-2 gap-3 pt-6"><button onclick="document.getElementById('modal-edit-user').classList.add('hidden')" class="py-2.5 bg-white dark:bg-transparent text-slate-600 dark:text-slate-300 rounded-xl hover:bg-slate-50 border border-slate-300 dark:border-white/20">Batal</button><button onclick="saveEditUser()" class="py-2.5 bg-blue-600 text-white rounded-xl hover:bg-blue-700 shadow-md">Simpan</button></div></div></div>
    
    <div id="img-viewer" class="fixed inset-0 z-[100] bg-black/90 hidden flex flex-col justify-center items-center p-2 backdrop-blur-sm transition-opacity duration-300"><div class="absolute top-4 right-4 flex gap-4 z-20"><a id="img-download-btn" href="#" download class="text-slate-300 hover:text-white p-3 bg-white/10 rounded-full hover:bg-white/20"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg></a><button onclick="closeImageViewer()" class="text-slate-300 hover:text-red-500 p-3 bg-white/10 rounded-full hover:bg-white/20"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></div><img id="img-viewer-src" src="" class="max-w-full max-h-[90dvh] rounded-lg shadow-2xl object-contain animate-zoomIn border border-white/10"><div onclick="closeImageViewer()" class="absolute inset-0 -z-10 cursor-zoom-out"></div></div>
    
    <div id="modal-tpl-manager" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4"><div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm z-10" onclick="document.getElementById('modal-tpl-manager').classList.add('hidden')"></div><div class="bg-white dark:bg-[#233138] border dark:border-white/10 w-full max-w-md rounded-2xl p-0 shadow-2xl relative flex flex-col max-h-[85vh] z-20"><div class="p-5 border-b border-slate-100 dark:border-white/10 flex justify-between items-center shrink-0"><h3 class="font-bold text-slate-800 dark:text-white flex items-center gap-2">⚡ Atur Balas Cepat</h3><button onclick="document.getElementById('modal-tpl-manager').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button></div><div id="manager-list" class="p-5 overflow-y-auto custom-scrollbar space-y-3 flex-1 dark:text-white"></div><div class="p-5 border-t border-slate-100 dark:border-white/10 bg-slate-50 dark:bg-white/5 shrink-0"><button onclick="openFormTpl()" class="w-full py-3 bg-blue-600 text-white font-bold rounded-xl text-sm flex items-center justify-center gap-2">Tambah Template Baru</button></div></div></div>
    
    <div id="modal-form-tpl" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4"><div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm z-10" onclick="document.getElementById('modal-form-tpl').classList.add('hidden')"></div><div class="bg-white dark:bg-[#233138] border dark:border-white/10 w-full max-w-sm rounded-2xl p-6 shadow-2xl relative z-20"><h3 class="font-bold text-slate-800 dark:text-white mb-4">Tambah Template</h3><div class="space-y-4"><div><label class="text-xs text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Judul (/Slash)</label><input type="text" id="tpl-label" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2 text-slate-800 dark:text-white outline-none"></div><div><label class="text-xs text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Isi Pesan</label><textarea id="tpl-msg" rows="4" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2 text-slate-800 dark:text-white outline-none resize-none"></textarea></div></div><div class="grid grid-cols-2 gap-3 pt-4"><button onclick="document.getElementById('modal-form-tpl').classList.add('hidden'); document.getElementById('modal-tpl-manager').classList.remove('hidden');" class="py-2.5 bg-white dark:bg-transparent text-slate-600 dark:text-slate-300 rounded-xl border border-slate-300 dark:border-white/20">Batal</button><button onclick="saveTemplate()" class="py-2.5 bg-green-600 text-white rounded-xl">Simpan</button></div></div></div>
    
    <div id="modal-admin-profile" class="hidden fixed inset-0 z-[80] flex items-center justify-center p-4"><div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm z-10" onclick="document.getElementById('modal-admin-profile').classList.add('hidden')"></div><div class="bg-white dark:bg-[#233138] border dark:border-white/10 w-full max-w-sm rounded-2xl p-6 shadow-2xl relative z-20"><h3 class="font-bold text-slate-800 dark:text-white mb-5">Pengaturan Profil</h3><div class="space-y-4"><div><label class="text-xs text-slate-500 uppercase font-bold mb-1 block">Nama</label><input type="text" id="adm-name" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2.5 text-slate-800 dark:text-white outline-none"></div><div><label class="text-xs text-slate-500 uppercase font-bold mb-1 block">Username</label><input type="text" id="adm-username" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2.5 text-slate-800 dark:text-white outline-none"></div><div><label class="text-xs text-yellow-600 uppercase font-bold mb-1 block">Password Baru</label><input type="password" id="adm-password" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2.5 text-slate-800 dark:text-white outline-none"></div></div><div class="grid grid-cols-2 gap-3 pt-6"><button onclick="document.getElementById('modal-admin-profile').classList.add('hidden')" class="py-2.5 bg-white dark:bg-transparent text-slate-600 dark:text-slate-300 rounded-xl border border-slate-300 dark:border-white/20">Batal</button><button onclick="saveAdminProfile()" class="py-2.5 bg-blue-600 text-white rounded-xl">Simpan</button></div></div></div>

    <div id="modal-settings" class="modal-overlay">
        <div class="modal-box dark:bg-[#233138] dark:text-white">
            <button class="btn-close-modal" onclick="document.getElementById('modal-settings').style.display='none'">&times;</button>
            <h3 style="margin-top:0; margin-bottom:20px; color:#0f172a;" class="dark:text-white">Pengaturan Chat & WA</h3>
            
            <div class="form-group">
                <label class="form-label dark:text-slate-300">Mode Operasional:</label>
                <select id="set-mode" class="form-input dark:bg-white/5 dark:border-white/10 dark:text-white" onchange="toggleScheduleInput()">
                    <option value="manual_on">🟢 Selalu ONLINE (Chat Lokal)</option>
                    <option value="manual_off">🔴 Selalu OFFLINE (Redirect WA)</option>
                    <option value="scheduled">🕒 Jadwal Otomatis (Jam Kerja)</option>
                </select>
            </div>

            <div id="box-schedule" style="display:none; background:#f8fafc; padding:15px; border-radius:8px; border:1px dashed #94a3b8; margin-bottom:15px;" class="dark:bg-white/5 dark:border-white/20">
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <label class="form-label dark:text-slate-300" style="font-size:0.8rem;">Buka:</label>
                        <input type="time" id="set-start" class="form-input dark:bg-black/20 dark:border-white/10 dark:text-white">
                    </div>
                    <div style="flex:1;">
                        <label class="form-label dark:text-slate-300" style="font-size:0.8rem;">Tutup:</label>
                        <input type="time" id="set-end" class="form-input dark:bg-black/20 dark:border-white/10 dark:text-white">
                    </div>
                </div>
                <p style="font-size:0.75rem; color:#64748b; margin:8px 0 0;" class="dark:text-slate-400">Di luar jam ini, pelanggan diarahkan ke WA.</p>
            </div>

            <div class="form-group">
                <label class="form-label dark:text-slate-300">Nomor WhatsApp Admin:</label>
                <input type="text" id="set-wa" class="form-input dark:bg-white/5 dark:border-white/10 dark:text-white" placeholder="Contoh: 08123456789">
            </div>

            <div class="form-group">
                <label class="form-label dark:text-slate-300">Pesan Otomatis WA:</label>
                <textarea id="set-msg" rows="3" class="form-input dark:bg-white/5 dark:border-white/10 dark:text-white" placeholder="Halo Admin..."></textarea>
            </div>

            <button onclick="saveSettings()" class="btn-save hover:bg-blue-700">Simpan Perubahan</button>
        </div>
    </div>
    </div>

    <!-- Lead Detail Modal -->
    <div v-if="showLeadDetail && selectedLead" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showLeadDetail = false"></div>
      <div class="bg-white dark:bg-[#233138] border dark:border-white/10 w-full max-w-sm rounded-2xl shadow-2xl relative z-10 animate-zoomIn overflow-hidden">
        <!-- Header -->
        <div class="p-4 border-b border-slate-100 dark:border-white/10 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold" :class="leadBadge(selectedLead.status)">{{ (selectedLead.customer_name||'?').charAt(0).toUpperCase() }}</div>
            <div>
              <h3 class="font-bold text-slate-800 dark:text-white text-sm">{{ selectedLead.customer_name }}</h3>
              <span class="text-[10px] px-1.5 py-px rounded-full font-semibold" :class="leadBadge(selectedLead.status)">{{ leadStatuses[selectedLead.status] || selectedLead.status }}</span>
            </div>
          </div>
          <button @click="showLeadDetail = false" class="p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-white rounded-lg hover:bg-slate-100 dark:hover:bg-white/10 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <!-- Info -->
        <div class="p-4 space-y-3">
          <div>
            <div class="text-[10px] text-slate-400 font-bold uppercase mb-0.5">WhatsApp</div>
            <a :href="'https://wa.me/' + selectedLead.customer_phone" target="_blank" class="text-sm text-green-600 dark:text-green-400 font-mono hover:underline">{{ selectedLead.customer_phone || '-' }}</a>
          </div>
          <div v-if="selectedLead.customer_address">
            <div class="text-[10px] text-slate-400 font-bold uppercase mb-0.5">Alamat</div>
            <div class="text-sm text-slate-700 dark:text-slate-300">{{ selectedLead.customer_address }}</div>
          </div>
          <div class="flex gap-4">
            <div v-if="selectedLead.source"><div class="text-[10px] text-slate-400 font-bold uppercase mb-0.5">Sumber</div><div class="text-sm text-slate-700 dark:text-slate-300">{{ selectedLead.source }}</div></div>
            <div><div class="text-[10px] text-slate-400 font-bold uppercase mb-0.5">Ditambahkan</div><div class="text-sm text-slate-700 dark:text-slate-300">{{ fmtLeadDate(selectedLead.created_at) }}</div></div>
          </div>
          <div v-if="selectedLead.notes">
            <div class="text-[10px] text-slate-400 font-bold uppercase mb-0.5">Catatan</div>
            <div class="text-sm text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-white/5 p-2 rounded-lg border border-slate-200 dark:border-white/10">{{ selectedLead.notes }}</div>
          </div>
        </div>
        <!-- Actions -->
        <div class="p-4 border-t border-slate-100 dark:border-white/10 grid grid-cols-3 gap-2">
          <button @click="openLeadForm(selectedLead)" class="py-2 text-xs font-bold rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/30 border border-blue-200 dark:border-blue-900/50 transition">Edit</button>
          <button v-if="selectedLead.status !== 'CONVERTED'" @click="convertLead(selectedLead)" class="py-2 text-xs font-bold rounded-lg bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-900/30 border border-green-200 dark:border-green-900/50 transition">Konversi</button>
          <button @click="deleteLead(selectedLead)" class="py-2 text-xs font-bold rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 border border-red-200 dark:border-red-900/50 transition">Hapus</button>
        </div>
      </div>
    </div>

    <!-- Lead Form Modal -->
    <div v-if="showLeadForm" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showLeadForm = false"></div>
      <div class="bg-white dark:bg-[#233138] border dark:border-white/10 w-full max-w-sm rounded-2xl p-5 shadow-2xl relative z-10 animate-zoomIn">
        <h3 class="font-bold text-slate-800 dark:text-white mb-4 text-sm">{{ editingLead ? 'Edit Lead' : 'Tambah Lead Baru' }}</h3>
        <div class="space-y-3">
          <div>
            <label class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Nama *</label>
            <input v-model="leadForm.customer_name" type="text" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-white outline-none focus:border-blue-500">
          </div>
          <div>
            <label class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">No. HP *</label>
            <input v-model="leadForm.customer_phone" type="text" placeholder="08xx..." class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-white outline-none focus:border-blue-500">
          </div>
          <div>
            <label class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Alamat</label>
            <textarea v-model="leadForm.customer_address" rows="2" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-white outline-none resize-none focus:border-blue-500"></textarea>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Status</label>
              <select v-model="leadForm.status" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-white outline-none">
                <option v-for="(label, key) in leadStatuses" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
            <div>
              <label class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Sumber</label>
              <input v-model="leadForm.source" type="text" placeholder="WA, Chat..." class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-white outline-none">
            </div>
          </div>
          <div>
            <label class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-bold mb-1 block">Catatan</label>
            <textarea v-model="leadForm.notes" rows="2" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-300 dark:border-white/10 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-white outline-none resize-none"></textarea>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3 pt-4">
          <button @click="showLeadForm = false" class="py-2 bg-white dark:bg-transparent text-slate-600 dark:text-slate-300 rounded-xl border border-slate-300 dark:border-white/20 text-sm font-semibold hover:bg-slate-50">Batal</button>
          <button @click="saveLead" class="py-2 bg-blue-600 dark:bg-green-600 text-white rounded-xl text-sm font-semibold hover:bg-blue-700 dark:hover:bg-green-700 shadow-md">Simpan</button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<style>
/* Scoped-to-page (prefixed) CSS from native chat/index.php */
html.chat-admin-lock,
body.chat-admin-lock {
  height: 100%;
  overflow: hidden;
}

#legacy-chat-root {
  /* Keep chat fully contained inside the AdminLayout viewport.
     Mobile: header h-14 (3.5rem). Desktop: no header (full viewport). */
  overflow: hidden;
  overscroll-behavior: contain;
}

#legacy-chat-root #main-app {
  display: flex;
  width: 100%;
  height: 100%;
  position: relative;
  overflow: hidden;
}

/* Prevent scroll chaining between panels and the page. */
#legacy-chat-root #panel-list,
#legacy-chat-root #panel-chat {
  min-height: 0;
}

#legacy-chat-root #user-list,
#legacy-chat-root #messages,
#legacy-chat-root #user-detail-sidebar,
#legacy-chat-root #tpl-list,
#legacy-chat-root #manager-list {
  overscroll-behavior: contain;
}

#legacy-chat-root .mobile-hidden { display: none !important; }
@media (min-width: 768px) {
  #legacy-chat-root .mobile-hidden { display: flex !important; }
}

@keyframes zoomIn {
  from { transform: scale(0.95); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
#legacy-chat-root .animate-zoomIn { animation: zoomIn 0.2s ease-out forwards; }

#legacy-chat-root .user-item-active { background-color: #eff6ff !important; border-left-color: #3b82f6 !important; }
.dark #legacy-chat-root .user-item-active { background-color: #202c33 !important; border-left-color: #00a884 !important; }

#legacy-chat-root .pb-safe { padding-bottom: max(16px, env(safe-area-inset-bottom, 16px)); }

#legacy-chat-root .filter-btn-active { background-color: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
.dark #legacy-chat-root .filter-btn-active { background-color: #005c4b; color: #fff; border-color: #00a884; }

@keyframes shatter-impact {
  0% { transform: scale(0.5) rotate(0deg); opacity: 0; filter: hue-rotate(0deg); }
  10% { transform: scale(1.2) rotate(-5deg) translate(-5px, -5px); opacity: 1; filter: hue-rotate(90deg) contrast(2); }
  20% { transform: scale(0.9) rotate(5deg) translate(5px, 5px); filter: hue-rotate(-90deg) contrast(2); }
  30% { transform: scale(1.05) rotate(-3deg) translate(-3px, 3px); }
  40% { transform: scale(0.95) rotate(3deg) translate(3px, -3px); }
  50% { transform: scale(1) rotate(-1deg) translate(-1px, 1px); }
  60% { transform: scale(1) rotate(1deg) translate(1px, -1px); }
  100% { transform: scale(1) rotate(0deg) translate(0, 0); opacity: 1; }
}

#legacy-chat-root .animate-shatter {
  animation: shatter-impact 0.6s cubic-bezier(.36,.07,.19,.97) both;
  color: #dc2626;
  filter: drop-shadow(0 0 15px rgba(220, 38, 38, 0.8));
}

#legacy-chat-root .animate-shatter::before,
#legacy-chat-root .animate-shatter::after {
  content: '';
  position: absolute;
  inset: 0;
  background: inherit;
  pointer-events: none;
  opacity: 0.5;
}

#legacy-chat-root .animate-shatter::before {
  animation: shatter-impact 0.6s cubic-bezier(.36,.07,.19,.97) both reverse;
  transform: translate(-2px, -2px);
  color: #ef4444;
  mix-blend-mode: hard-light;
}

/* Modal-settings CSS (from native inline <style>) */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; }
        .modal-box { background:white; width:90%; max-width:450px; padding:25px; border-radius:16px; box-shadow:0 20px 50px rgba(0,0,0,0.3); animation: popIn 0.3s ease; }
        @keyframes popIn { from { transform:scale(0.9); opacity:0; } to { transform:scale(1); opacity:1; } }
        .form-group { margin-bottom:15px; }
        .form-label { display:block; font-weight:600; margin-bottom:5px; font-size:0.9rem; color:#334155; }
        .form-input { width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.95rem; box-sizing: border-box; }
        .btn-save { background:#2563eb; color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer; width:100%; margin-top:10px; }
        .btn-close-modal { background:transparent; border:none; color:#ef4444; cursor:pointer; float:right; font-size:1.2rem; font-weight:bold; }

</style>

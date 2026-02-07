// FILE: isolir/chat/app.js (Full Update dengan Smart Sidebar)

let activeVisitId = null;
let lastMsgCount = 0;
let pollingInterval = null;
let usersData = []; // Master Data (Memory)
let isFetching = false;
let noteTimeout = null;
let lastDataJson = ""; 
let editingMsgId = null; 
let lastGlobalUnread = -1; 
let isDeepLinkChecked = false;
let currentFilter = 'all'; 

// [SMART SYNC VARS]
let lastSyncTime = '';       // Untuk Chat Room
let lastContactSync = '';    // Untuk Sidebar List
let isSearching = false;     // Mode Pencarian

const audioNotif = document.getElementById('notif-sound');

// In SPA (Inertia), DOMContentLoaded might have already fired before this script loads.
// Expose boot/dispose so the Vue page can re-init / cleanup when navigating.
function __chatBoot() {
    if (window.__chatBooting) return;
    window.__chatBooting = true;

    document.body.addEventListener('click', () => { if(audioNotif) { audioNotif.muted = false; } }, { once: true });
    injectSmartModal(); 
    injectEditIndicator(); 
    ensureListStateForDeepLink();
    setupMobileBackHandler();
    
    // Initial Load
    loadContacts(true); 
    
    // Polling Sidebar tiap 3 detik
    if (window.__chatContactsPoll) clearInterval(window.__chatContactsPoll);
    window.__chatContactsPoll = setInterval(() => loadContacts(false), 3000);

    const msgInput = document.getElementById('msg-input');
    if (msgInput) {
        msgInput.addEventListener('keydown', function(e) {
            const isMobile = window.innerWidth < 768;
            if ((e.key === 'Enter' || e.keyCode === 13) && !e.shiftKey) { 
                if (isMobile) { return; } 
                else { e.preventDefault(); sendMessage(); }
            }
        });
        msgInput.addEventListener('input', function(e) { 
            this.style.height = 'auto'; 
            this.style.height = (this.scrollHeight) + 'px'; 
            const val = e.target.value; 
            if (val.startsWith('/')) showTemplatePopup(val.substring(1)); 
            else document.getElementById('tpl-popup').classList.add('hidden'); 
        });
    }

    const noteInput = document.getElementById('customer-note');
    if (noteInput) {
        noteInput.addEventListener('input', function() {
            const statusEl = document.getElementById('note-status');
            if (statusEl) { statusEl.innerText = 'Saving...'; statusEl.className = 'text-[9px] text-yellow-600 font-bold opacity-100'; }
            clearTimeout(noteTimeout); noteTimeout = setTimeout(saveNote, 1000);
        });
    }
    loadTemplates(); 
    setupShortcuts();

    window.__chatBooting = false;
}

window.__chatBoot = __chatBoot;
window.__chatDispose = function () {
    try {
        if (window.__chatContactsPoll) { clearInterval(window.__chatContactsPoll); window.__chatContactsPoll = null; }
        if (pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; }
        activeVisitId = null;
        lastSyncTime = '';
        lastContactSync = '';
        isFetching = false;
    } catch (e) {
        // best-effort cleanup
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', __chatBoot, { once: true });
} else {
    __chatBoot();
}

// --- CORE FUNCTIONS ---

function ensureListStateForDeepLink() {
    if (window.innerWidth >= 768) return;
    const url = new URL(window.location);
    const id = url.searchParams.get('id');
    if (!id) return;
    const baseUrl = new URL(window.location);
    baseUrl.searchParams.delete('id');
    window.history.replaceState({ view: 'list' }, '', baseUrl);
    window.history.pushState({ view: 'chat', id: String(id) }, '', url);
}

function setupMobileBackHandler() {
    if (window.__chatBackHandlerSetup) return;
    window.__chatBackHandlerSetup = true;
    window.addEventListener('popstate', () => {
        if (window.innerWidth < 768 && activeVisitId) {
            closeChatMobile();
        }
    });
}

function pushChatState(visitId) {
    const url = new URL(window.location);
    url.searchParams.set('id', visitId);
    const state = { view: 'chat', id: String(visitId) };
    if (window.innerWidth < 768) {
        if (window.history.state && window.history.state.view === 'chat') {
            window.history.replaceState(state, '', url);
        } else {
            window.history.pushState(state, '', url);
        }
    } else {
        window.history.replaceState(state, '', url);
    }
}

function closeChatMobile() {
    if (window.innerWidth >= 768) {
        closeActiveChat();
        return;
    }
    if (window.history.state && window.history.state.view === 'chat') {
        window.history.back();
        return;
    }
    const panelList = document.getElementById('panel-list');
    const panelChat = document.getElementById('panel-chat');
    if (panelList) panelList.classList.remove('hidden');
    if (panelChat) panelChat.classList.add('mobile-hidden');
    const sidebar = document.getElementById('user-detail-sidebar');
    const backdrop = document.getElementById('user-sidebar-backdrop');
    if (sidebar && !sidebar.classList.contains('translate-x-full')) {
        sidebar.classList.remove('translate-x-0');
        sidebar.classList.add('translate-x-full');
    }
    if (backdrop) backdrop.classList.add('hidden');
    const url = new URL(window.location);
    url.searchParams.delete('id');
    window.history.replaceState({ view: 'list' }, '', url);
}

// [SMART SIDEBAR] Load Contacts (Delta Updates)
function loadContacts(isFirstLoad = false) { 
    // Jangan polling jika sedang mode search (user sedang mengetik/mencari)
    if (isSearching && !isFirstLoad) return;

    let url = 'admin_api.php?action=get_contacts';
    
    // Jika bukan load pertama dan bukan search, kirim last_sync
    if (!isFirstLoad && lastContactSync && !isSearching) {
        url += `&last_sync=${lastContactSync}`;
    }

    fetch(url)
    .then(r => r.json())
    .then(res => { 
        if (res.status === 'error') return; 
        
        const newContacts = res.data || [];
        const serverTime = res.server_time;
        
        if (serverTime) lastContactSync = serverTime;

        // Jika ada data baru atau ini load pertama
        if (newContacts.length > 0 || isFirstLoad) {
            mergeUserData(newContacts, isFirstLoad); // Gabungkan data baru ke memory
            
            // Cek Deep Link (hanya sekali)
            if (!isDeepLinkChecked) {
                const urlParams = new URLSearchParams(window.location.search);
                const linkId = urlParams.get('id');
                if (linkId) openChat(linkId);
                isDeepLinkChecked = true; 
            }

            // Update info user yang sedang aktif (Realtime update status/nama)
            if (activeVisitId) {
                const currentUser = usersData.find(u => String(u.visit_id) === String(activeVisitId));
                if (currentUser) {
                    setText('.info-name', currentUser.name);
                    setText('.info-phone', currentUser.phone || '-');
                    setText('.info-address', currentUser.address || '-');
                    const hStatus = document.getElementById('h-status'); 
                    if(hStatus) hStatus.innerHTML = getStatus(currentUser.last_seen);
                    
                    if (currentUser.status === 'Selesai') {
                         document.getElementById('footer-active').classList.add('hidden');
                         document.getElementById('footer-locked').classList.remove('hidden');
                         document.getElementById('footer-locked').classList.add('flex');
                    } else {
                         document.getElementById('footer-active').classList.remove('hidden');
                         document.getElementById('footer-locked').classList.add('hidden');
                    }
                }
            }
        } else {
            // Update filter counts walaupun tidak ada list baru
            renderFilterButtons(usersData);
        }
    }).catch(e => { console.error("Polling Error:", e); }); 
}

// [SMART SIDEBAR] Merge Logic
function mergeUserData(newItems, reset = false) {
    if (reset) {
        usersData = newItems;
    } else {
        newItems.forEach(newItem => {
            const index = usersData.findIndex(u => String(u.visit_id) === String(newItem.visit_id));
            if (index > -1) {
                // Update data lama
                usersData[index] = newItem;
            } else {
                // Data baru, masukkan (nanti disortir)
                usersData.push(newItem);
            }
        });
    }

    // Sortir: Terakhir dilihat (last_seen) paling atas
    usersData.sort((a, b) => {
        const timeA = new Date(a.last_seen || 0);
        const timeB = new Date(b.last_seen || 0);
        return timeB - timeA;
    });

    renderUserList(usersData);
    renderFilterButtons(usersData);
}

// [SMART SIDEBAR] Search (Server Side)
function filterUsers() { 
    const q = document.getElementById('search-user').value.trim();
    const listContainer = document.getElementById('user-list');
    
    if (q.length === 0) {
        isSearching = false;
        lastContactSync = ''; // Reset sync agar load ulang default list
        loadContacts(true); // Load ulang list normal
        return;
    }

    isSearching = true;
    listContainer.innerHTML = `<div class="flex h-20 items-center justify-center text-xs text-slate-400 animate-pulse">Mencari '${escapeHtml(q)}'...</div>`;

    // Request Search ke Server
    fetch(`admin_api.php?action=get_contacts&search=${encodeURIComponent(q)}`)
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            // Render hasil pencarian langsung (jangan merge ke usersData utama agar tidak kotor)
            renderUserList(res.data, true); 
        }
    });
}


function renderUserList(data, force = false) {
    const listContainer = document.getElementById('user-list');
    if (!listContainer) return;

    // Filter Client-Side (kecuali mode search)
    let filteredData = data;
    if (!isSearching) {
        filteredData = data.filter(user => {
            if (currentFilter === 'all') return true;
            if (currentFilter === 'unread') return parseInt(user.unread) > 0;
            return user.status === currentFilter;
        });
    }

    if (filteredData.length === 0) {
        let emptyMsg = 'Data tidak ditemukan.';
        if (currentFilter === 'unread') emptyMsg = 'Tidak ada pesan belum dibaca.';
        listContainer.innerHTML = `<div class="flex flex-col items-center justify-center h-40 text-slate-400 text-xs text-center"><p>${escapeHtml(emptyMsg)}</p></div>`;
        updateTitleNotification(0);
        return;
    }

    const currentScroll = listContainer.scrollTop;
    let html = '';

    filteredData.forEach(user => {
        const currentUnread = parseInt(user.unread) || 0;
        const visitIdStr = String(user.visit_id || '');
        const isActive = (String(activeVisitId) === visitIdStr);

        const bgClass = isActive ? 'user-item-active bg-blue-50 border-l-4 border-blue-600' : 'hover:bg-slate-50 border-l-4 border-transparent';
        const avatarColor = isActive ? 'from-blue-600 to-blue-700 text-white' : 'from-blue-100 to-blue-200 text-blue-600';

        let badge = '';
        if (currentUnread > 0) {
            badge = `<span class="ml-auto bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center shadow-md shadow-red-200 animate-pulse">${currentUnread}</span>`;
        }

        // Safe strings (anti-XSS)
        const uNameRaw = (user.name || 'Tanpa Nama');
        const uName = escapeHtml(uNameRaw);
        const shortIdRaw = visitIdStr.length > 4 ? visitIdStr.substring(visitIdStr.length - 4) : visitIdStr;
        const shortId = escapeHtml(shortIdRaw);

        let prevMsgRaw = user.last_msg || '...';
        if (user.msg_type === 'image') prevMsgRaw = '📷 Gambar';
        const prevMsg = escapeHtml(String(prevMsgRaw)).substring(0, 30);

        const displayTime = escapeHtml(String(user.display_time || ''));
        const statusIcon = (user.status === 'Selesai')
            ? '<span class="text-[9px] text-green-600 font-bold ml-1">✔</span>'
            : '';

        html += `
            <div class="user-item flex items-center gap-3 p-3 cursor-pointer transition border-b border-slate-50 dark:border-white/5 ${bgClass} relative select-none"
                 data-visit-id="${escapeHtml(visitIdStr)}"
                 id="user-item-${escapeHtml(visitIdStr)}">
                <div class="relative">
                    <div class="user-avatar w-10 h-10 rounded-full bg-gradient-to-br ${avatarColor} flex items-center justify-center font-bold shrink-0 text-sm shadow-sm transition-all">
                        ${uName.charAt(0).toUpperCase()}
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between items-center mb-0.5">
                        <h4 class="text-slate-800 dark:text-white font-bold text-sm truncate flex items-center gap-1">
                            ${uName}
                            <span class="text-[10px] text-slate-400 font-normal">#${shortId}</span>
                            ${statusIcon}
                        </h4>
                        <span class="text-[10px] text-slate-400 font-mono">${displayTime}</span>
                    </div>
                    <div class="flex items-center text-xs text-slate-500 dark:text-slate-400">
                        <p class="truncate flex-1 opacity-80">${prevMsg}</p>
                        ${badge}
                    </div>
                </div>
            </div>`;
    });

    // Play Sound jika ada unread global bertambah
    const globalUnread = data.reduce((sum, u) => sum + (parseInt(u.unread) || 0), 0);
    if (lastGlobalUnread !== -1 && globalUnread > lastGlobalUnread) { playSound(); }
    lastGlobalUnread = globalUnread;
    updateTitleNotification(globalUnread);

    listContainer.innerHTML = html;

    // Click handler (hindari inline onclick)
    listContainer.querySelectorAll('.user-item').forEach(el => {
        el.addEventListener('click', () => {
            const vid = el.getAttribute('data-visit-id');
            if (vid) openChat(vid);
        });
    });

    // Pertahankan posisi scroll kecuali dipaksa (misal search)
    if (!force) listContainer.scrollTop = currentScroll;
}

function renderFilterButtons(data) {
    const container = document.getElementById('filter-container');
    if (!container) return;

    let unreadCount = 0;
    const statusCounts = {};

    data.forEach(u => {
        if (parseInt(u.unread) > 0) unreadCount++;
        const st = u.status || 'Baru'; 
        if (!statusCounts[st]) statusCounts[st] = 0;
        statusCounts[st]++;
    });

    let filters = [
        { key: 'all', label: 'Semua', count: data.length },
        { key: 'unread', label: 'Belum Dibaca', count: unreadCount }
    ];

    Object.keys(statusCounts).forEach(st => {
        filters.push({ key: st, label: st, count: statusCounts[st] });
    });

    let html = '';
    filters.forEach(f => {
        if (f.key !== 'all' && f.count === 0 && f.key !== 'unread') return;
        const isActive = (currentFilter === f.key);
        const activeClass = isActive 
            ? 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-green-900/30 dark:text-green-400 dark:border-green-800' 
            : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50 dark:bg-white/5 dark:text-slate-400 dark:border-white/10 dark:hover:bg-white/10';
        const countBadge = f.count > 0 
            ? `<span class="ml-1.5 text-[9px] px-1.5 py-0.5 rounded-full ${isActive ? 'bg-blue-200 dark:bg-green-900 text-blue-800 dark:text-green-300' : 'bg-slate-100 dark:bg-black/30 text-slate-500 dark:text-slate-400'}">${f.count}</span>` 
            : '';
        html += `<button onclick="setFilter('${f.key}')" class="flex-shrink-0 px-3 py-1.5 text-[11px] font-bold rounded-full border transition-all flex items-center ${activeClass}">${f.label} ${countBadge}</button>`;
    });
    container.innerHTML = html;
}

function setFilter(type) {
    currentFilter = type;
    renderUserList(usersData, true);
    renderFilterButtons(usersData); 
}

// ... (Fungsi setupShortcuts, handleEscapeKey, toggleUserSidebar, closeActiveChat, openChat, dll tetap SAMA seperti sebelumnya. Pastikan fungsi sendMessage dan sendImageAdmin menggunakan versi FIX dari langkah sebelumnya).
// ... Copy-Paste fungsi-fungsi di bawah ini dari file app.js versi sebelumnya (yang sudah di-fix) ...

function setupShortcuts() {
    if (window.__chatShortcutsSetup) return;
    window.__chatShortcutsSetup = true;
    document.addEventListener('keydown', (e) => {
        const smartModal = document.getElementById('smart-modal');
        if (smartModal && !smartModal.classList.contains('hidden')) {
            const btnCancel = document.getElementById('smart-btn-cancel'); const btnConfirm = document.getElementById('smart-btn-confirm'); const btnOk = document.getElementById('smart-btn-ok'); const btnSave = document.getElementById('smart-btn-save');
            if (e.key === 'ArrowLeft') { e.preventDefault(); if (btnCancel) btnCancel.focus(); }
            if (e.key === 'ArrowRight') { e.preventDefault(); if (btnConfirm) btnConfirm.focus(); if (btnSave) btnSave.focus(); }
            if (e.key === 'Enter') { 
                e.preventDefault(); 
                if (document.activeElement === btnCancel) btnCancel.click(); 
                else if (document.activeElement === btnConfirm) btnConfirm.click(); 
                else if (document.activeElement === btnOk) btnOk.click(); 
                else if (document.activeElement === btnSave) btnSave.click();
            }
            if (e.key === 'Escape') { e.preventDefault(); handleEscapeKey(); } return; 
        }
        if (e.key === 'Escape') { e.preventDefault(); handleEscapeKey(); }
    });
}

function handleEscapeKey() {
    const imgViewer = document.getElementById('img-viewer'); if (imgViewer && !imgViewer.classList.contains('hidden')) { closeImageViewer(); return; }
    const tplPopup = document.getElementById('tpl-popup'); if (tplPopup && !tplPopup.classList.contains('hidden')) { tplPopup.classList.add('hidden'); return; }
    if (editingMsgId) { cancelEditMode(); return; }
    const smartModal = document.getElementById('smart-modal'); if (smartModal && !smartModal.classList.contains('hidden')) { closeSmartModal(); return; }
    const sidebar = document.getElementById('user-detail-sidebar');
    if (sidebar && !sidebar.classList.contains('translate-x-full') && window.innerWidth < 1024) { toggleUserSidebar(); return; }
    const activeModals = ['modal-edit-user', 'modal-detail', 'modal-tpl-manager', 'modal-form-tpl', 'modal-admin-profile', 'chat-menu', 'sidebar-menu'];
    let modalClosed = false;
    activeModals.forEach(id => { const el = document.getElementById(id); if (el && !el.classList.contains('hidden')) { el.classList.add('hidden'); modalClosed = true; } });
    if (modalClosed) return;
    if (activeVisitId) closeActiveChat();
}

function toggleUserSidebar() {
    const sidebar = document.getElementById('user-detail-sidebar');
    const backdrop = document.getElementById('user-sidebar-backdrop');
    if (sidebar.classList.contains('translate-x-full')) {
        sidebar.classList.remove('translate-x-full'); sidebar.classList.add('translate-x-0');
        if(backdrop) backdrop.classList.remove('hidden');
    } else {
        sidebar.classList.remove('translate-x-0'); sidebar.classList.add('translate-x-full');
        if(backdrop) backdrop.classList.add('hidden');
    }
}

function closeActiveChat() {
    if (pollingInterval) clearInterval(pollingInterval);
    activeVisitId = null; lastSyncTime = ''; 
    cancelEditMode(); 
    document.getElementById('chat-interface').classList.add('hidden'); 
    document.getElementById('chat-interface').classList.remove('flex');
    document.getElementById('empty-state').classList.remove('hidden'); 
    document.getElementById('panel-list').classList.remove('hidden');
    document.getElementById('panel-chat').classList.add('mobile-hidden'); 
    document.title = "Chat Admin | Isolir";
    const url = new URL(window.location); url.searchParams.delete('id'); window.history.replaceState({}, '', url);
    document.querySelectorAll('.user-item-active').forEach(el => {
        el.classList.remove('user-item-active', 'bg-blue-50', 'border-blue-600');
        el.classList.add('hover:bg-slate-50', 'border-transparent');
        const avatar = el.querySelector('.user-avatar');
        if(avatar) { avatar.classList.remove('from-blue-600', 'to-blue-700', 'text-white'); avatar.classList.add('from-blue-100', 'to-blue-200', 'text-blue-600'); }
    });
}

function openChat(visitId) {
    if (typeof closeGame === 'function') closeGame();
    document.getElementById('empty-state').classList.add('hidden');
    const chatInterface = document.getElementById('chat-interface'); chatInterface.classList.remove('hidden'); chatInterface.classList.add('flex');
    if (window.innerWidth < 768) { document.getElementById('panel-list').classList.add('hidden'); document.getElementById('panel-chat').classList.remove('mobile-hidden'); }
    pushChatState(visitId);
    setTimeout(() => { const input = document.getElementById('msg-input'); if (input) input.focus(); }, 50);
    
    // Reset Polling & Sync
    if (pollingInterval) clearInterval(pollingInterval); 
    isFetching = false; 
    cancelEditMode();
    lastSyncTime = ''; 
    
    if (activeVisitId) {
        const prevItem = document.getElementById(`user-item-${activeVisitId}`);
        if (prevItem) {
            prevItem.classList.remove('user-item-active', 'bg-blue-50', 'border-blue-600');
            prevItem.classList.add('hover:bg-slate-50', 'border-transparent');
            const av = prevItem.querySelector('.user-avatar');
            if(av) { av.classList.remove('from-blue-600', 'to-blue-700', 'text-white'); av.classList.add('from-blue-100', 'to-blue-200', 'text-blue-600'); }
        }
    }
    const newItem = document.getElementById(`user-item-${visitId}`);
    if (newItem) {
        newItem.classList.add('user-item-active', 'bg-blue-50', 'border-blue-600'); 
        newItem.classList.remove('hover:bg-slate-50', 'border-transparent');
        const av = newItem.querySelector('.user-avatar');
        if(av) { av.classList.remove('from-blue-100', 'to-blue-200', 'text-blue-600'); av.classList.add('from-blue-600', 'to-blue-700', 'text-white'); }
        const badge = newItem.querySelector('.bg-red-500'); if(badge) badge.remove();
        newItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    activeVisitId = visitId; lastMsgCount = 0; 
    
    try {
        const msgContainer = document.getElementById('messages');
        msgContainer.innerHTML = `<div id="chat-loader" class="flex flex-col items-center justify-center h-full space-y-3 animate-pulse"><div class="w-8 h-8 rounded-full border-2 border-slate-300 border-t-transparent animate-spin"></div><div class="text-xs text-slate-400">Memuat riwayat...</div></div>`;
        
        const user = usersData.find(u => String(u.visit_id) === String(visitId));
        if (user) {
            setText('.info-name', user.name); setText('.info-phone', user.phone || '-'); setText('.info-address', user.address || '-'); setText('.info-id', '#' + user.visit_id); setText('.info-location', user.location_info || '-'); setText('.info-ip', user.ip_address || '-');
            const noteInput = document.getElementById('customer-note'); if(noteInput) { noteInput.value = user.notes || ''; const st = document.getElementById('note-status'); if(st) { st.innerText = 'Saved'; st.className = 'text-[9px] text-slate-400 font-normal opacity-0'; } }
            const hName = document.getElementById('h-name'); if(hName) hName.innerText = user.name;
            const hAvatar = document.getElementById('h-avatar'); if(hAvatar) hAvatar.innerText = (user.name||'U').charAt(0).toUpperCase();
            const hStatus = document.getElementById('h-status'); if(hStatus) hStatus.innerHTML = getStatus(user.last_seen);
            const hId = document.getElementById('h-id'); if(hId) hId.innerText = '#' + user.visit_id;
            
            const waLink = document.getElementById('wa-link'); 
            if (user.phone && user.phone.length > 5 && !user.phone.includes('Unknown')) { 
                let clean = user.phone.replace(/\D/g, ''); 
                if (clean.startsWith('0')) clean = '62' + clean.substring(1); 
                else if (!clean.startsWith('62')) clean = '62' + clean; 
                waLink.href = `https://wa.me/${clean}`; waLink.classList.remove('hidden'); 
            } else { waLink.classList.add('hidden'); }
            
            const menuToggle = document.getElementById('menu-toggle-session'); 
            const footerActive = document.getElementById('footer-active'); 
            const footerLocked = document.getElementById('footer-locked'); 
            const btnEnd = document.getElementById('btn-end-session'); 
            const btnReopen = document.getElementById('btn-reopen-session');

            if (user.status === 'Selesai') {
                if(menuToggle) { menuToggle.innerHTML = 'Buka Kembali Sesi'; menuToggle.setAttribute('onclick', 'reopenSession()'); menuToggle.className = 'block px-4 py-2.5 text-sm hover:bg-blue-50 dark:hover:bg-white/5 text-blue-600 dark:text-blue-400 border-b border-slate-100 dark:border-white/10 font-bold'; }
                if(footerActive) footerActive.classList.add('hidden'); if(footerLocked) { footerLocked.classList.remove('hidden'); footerLocked.classList.add('flex'); }
                if(btnEnd) btnEnd.classList.add('hidden'); if(btnReopen) btnReopen.classList.remove('hidden');
            } else {
                if(menuToggle) { menuToggle.innerHTML = 'Selesaikan Sesi'; menuToggle.setAttribute('onclick', 'openEndModal()'); menuToggle.className = 'block px-4 py-2.5 text-sm hover:bg-green-50 dark:hover:bg-white/5 text-green-600 dark:text-green-400 border-b border-slate-100 dark:border-white/10 font-bold'; }
                if(footerActive) footerActive.classList.remove('hidden'); if(footerLocked) footerLocked.classList.add('hidden');
                if(btnEnd) btnEnd.classList.remove('hidden'); if(btnReopen) btnReopen.classList.add('hidden');
            }
        }
    } catch (e) {}
    
    loadMessages(true); 
    pollingInterval = setInterval(() => loadMessages(false), 3000);
}

function updateTitleNotification(totalUnread) { document.title = totalUnread > 0 ? `(${totalUnread}) Chat Admin` : "Chat Admin | Isolir"; }

function loadMessages(isFirstLoad = false) {
    if (!activeVisitId || isFetching) return; 
    if (!isFirstLoad) isFetching = true;

    let url = `admin_api.php?action=get_messages&visit_id=${activeVisitId}&viewer=admin`;
    if (lastSyncTime && !isFirstLoad) {
        url += `&last_sync=${lastSyncTime}`;
    }

    fetch(url)
    .then(r => r.json())
    .then(data => {
        isFetching = false;
        
        if (data.status === 'error' && data.msg === 'Unauthorized') { 
            window.location.href = '../login.php'; return; 
        }

        const messages = data.messages || [];
        const serverTime = data.server_time;

        if (isFirstLoad) {
            const container = document.getElementById('messages');
            container.innerHTML = '';
            if (messages.length === 0) {
                 container.innerHTML = '<div id="chat-empty" class="flex h-full items-center justify-center text-xs text-slate-400">Belum ada riwayat chat.</div>'; 
            }
        }

        if (messages.length > 0) {
            processMessageUpdates(messages);
            const lastM = messages[messages.length - 1];
            if (isFirstLoad || lastM.sender === 'user' || lastM.sender === 'admin') {
                scrollToBottom();
                if(!isFirstLoad && lastM.sender === 'user') playSound();
            }
        }
        
        if (serverTime) { lastSyncTime = serverTime; }
    })
    .catch(e => { isFetching = false; });
}

function processMessageUpdates(data) {
    const container = document.getElementById('messages');
    const loader = document.getElementById('chat-loader');
    const emptyState = document.getElementById('chat-empty');

    if (loader || emptyState) {
        container.innerHTML = '';
    }

    data.forEach(msg => {
        const existingEl = document.getElementById(`msg-wrapper-${msg.id}`);
        if (msg.status === 'deleted') {
            if (existingEl) existingEl.remove();
            return;
        }

        const bubbleHtml = createBubbleHtml(msg);

        if (existingEl) {
            existingEl.innerHTML = bubbleHtml;
        } else {
            const wrapper = document.createElement('div');
            wrapper.id = `msg-wrapper-${msg.id}`;
            wrapper.className = `flex ${msg.sender === 'admin' ? 'justify-end' : 'justify-start'} mb-2 shrink-0 group animate-in fade-in zoom-in duration-200`;
            wrapper.innerHTML = bubbleHtml;
            container.appendChild(wrapper);
        }
    });
}

function createBubbleHtml(msg) {
    const isMe = (msg.sender === 'admin'); 
    const isImg = (msg.type === 'image'); 
    const bg = isMe ? 'bg-[#d9fdd3] dark:bg-darkme text-gray-900 dark:text-white rounded-br-none shadow-sm' : 'bg-white dark:bg-darkbubble text-gray-900 dark:text-white rounded-bl-none shadow-sm border border-gray-100 dark:border-transparent'; 
    const padClass = isImg ? 'p-1' : 'px-3 py-2'; 
    
    let content = ''; 
    if (isImg) { 
        content = `<div class="cursor-pointer group relative" onclick="openImageViewer('uploads/${msg.message}')"><img src="uploads/${msg.message}" class="block rounded-lg max-w-[250px] max-h-[300px] w-auto h-auto shadow-sm bg-slate-50 dark:bg-black/20 object-contain border border-slate-200 dark:border-white/10" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'p-3 bg-red-50 text-[10px] text-red-500 rounded border border-red-100 italic\\'>Gagal muat gambar</div>';"><div class="absolute inset-0 bg-black/0 group-hover:bg-black/5 transition rounded-lg"></div></div>`; 
    } else { 
        content = linkify(msg.message); 
    }
    
    let statusIcon = ''; 
    if(isMe) statusIcon = `<span class="text-blue-500 dark:text-blue-300 font-bold ml-1">✓</span>`; 
    const editedLabel = (msg.is_edited == 1) ? `<span class="text-[9px] italic opacity-60 mx-1">(diedit)</span>` : '';
    
    let menuBtn = ''; 
    if (isMe) { 
        const canEdit = !isImg ? `<button onclick="editMessage('${msg.id}')" class="block w-full text-left px-4 py-2 text-xs hover:bg-slate-50 dark:hover:bg-white/5 text-slate-700 dark:text-slate-200 font-medium">✏️ Edit</button>` : ''; 
        menuBtn = `<div class="relative group/menu flex items-center self-center opacity-0 group-hover:opacity-100 transition-opacity mx-2"><button class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 text-xs font-bold px-1 tracking-widest">•••</button><div class="hidden group-hover/menu:block absolute right-0 top-4 bg-white dark:bg-[#233138] border border-slate-200 dark:border-white/10 shadow-xl rounded-lg z-50 w-24 overflow-hidden ring-1 ring-black/5">${canEdit}<button onclick="deleteMessage('${msg.id}')" class="block w-full text-left px-4 py-2 text-xs hover:bg-red-50 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400 font-medium border-t border-slate-100 dark:border-white/10">🗑️ Hapus</button></div></div>`; 
    }

    let smartActions = '';
    if (!isMe && !isImg) { smartActions = detectSmartData(msg.message, msg.id); }

    const bubbleInner = `<div id="msg-${msg.id}" class="${bg} ${padClass} rounded-lg text-[13.5px] leading-relaxed relative break-words shadow-sm"><span class="msg-text">${content}</span><div class="msg-time text-[9px] opacity-50 text-right mt-1 font-mono tracking-wide flex justify-end items-center gap-1 select-none">${editedLabel} ${msg.time} ${statusIcon}</div></div>`;

    if (isMe) {
        return `<div class="max-w-[85%] md:max-w-[75%] flex items-start">${menuBtn}${bubbleInner}</div>`;
    } else {
        return `<div class="flex flex-col items-start max-w-[85%] md:max-w-[75%]">${bubbleInner}${smartActions}</div>`;
    }
}

function sendImageAdmin() {
    const fileInput = document.getElementById('admin-img-input');
    if (fileInput.files.length === 0 || !activeVisitId) return;
    
    const file = fileInput.files[0];
    
    if(file.size > 5*1024*1024) {
        showSmartAlert("File Besar", "Maksimal 5MB.", "warning");
        fileInput.value='';
        return;
    }

    // ANIMASI LOADING UPLOAD
    const container = document.getElementById('messages');
    const tempId = 'loading-' + Date.now();
    const loaderHtml = `<div id="${tempId}" class="flex justify-end mb-2 shrink-0 animate-pulse"><div class="bg-blue-50 dark:bg-white/10 px-4 py-3 rounded-lg rounded-br-none border border-blue-100 dark:border-white/5 flex items-center gap-2 shadow-sm"><svg class="animate-spin h-4 w-4 text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="text-xs font-bold text-blue-700 dark:text-blue-300">Mengupload gambar...</span></div></div>`;
    container.insertAdjacentHTML('beforeend', loaderHtml);
    scrollToBottom();

    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('visit_id', activeVisitId);
    fd.append('sender', 'admin');
    fd.append('image', file);
    
    fileInput.value = '';

    fetch('admin_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            const loaderEl = document.getElementById(tempId);
            if (loaderEl) loaderEl.remove();

            if (res.status === 'success') {
                loadMessages(false);
            } else {
                showSmartAlert("Gagal", res.msg || "Upload gagal.", "error");
            }
        })
        .catch(err => {
            const loaderEl = document.getElementById(tempId);
            if (loaderEl) loaderEl.remove();
            showSmartAlert("Error", "Gagal menghubungi server.", "error");
        });
}

function deleteMessage(id) { showSmartConfirm("Hapus Pesan?", "Hapus permanen?", "danger", "Hapus").then(yes => { if(yes) { const fd = new FormData(); fd.append('action', 'delete_message'); fd.append('id', id); fetch('admin_api.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res => { if(res.status === 'success') loadMessages(false); }); } }); }

function editMessage(id) { const msgDiv = document.getElementById(`msg-${id}`); if (!msgDiv) { alert("Pesan tidak ditemukan."); return; } const bubbleText = msgDiv.querySelector('.msg-text'); if(!bubbleText) return; editingMsgId = id; const currentText = bubbleText.innerText; const input = document.getElementById('msg-input'); const indicator = document.getElementById('edit-indicator'); if (input) { input.value = currentText; input.focus(); input.style.height = 'auto'; input.style.height = (input.scrollHeight) + 'px'; input.parentElement.classList.add('ring-2', 'ring-blue-500', 'bg-blue-50'); } if (indicator) { indicator.classList.remove('hidden'); indicator.classList.add('flex'); } }

function cancelEditMode() { editingMsgId = null; const input = document.getElementById('msg-input'); const indicator = document.getElementById('edit-indicator'); if (input) { input.value = ''; input.style.height = 'auto'; input.parentElement.classList.remove('ring-2', 'ring-blue-500', 'bg-blue-50'); input.blur(); } if (indicator) { indicator.classList.add('hidden'); indicator.classList.remove('flex'); } }

function sendMessage() {
    if (!activeVisitId) return;
    const input = document.getElementById('msg-input');
    const text = input.value.trim();
    if (!text) return;

    if (editingMsgId) {
        const tempId = editingMsgId;
        const tempText = text;
        const fd = new FormData();
        fd.append('action', 'edit_message');
        fd.append('id', tempId);
        fd.append('message', tempText);
        fetch('admin_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if(res.status !== 'success') loadMessages(false); });
        cancelEditMode();
    } else {
        input.value = '';
        input.style.height = 'auto';
        input.focus();
        const fd = new FormData();
        fd.append('action', 'send');
        fd.append('visit_id', activeVisitId);
        fd.append('sender', 'admin');
        fd.append('message', text);
        fetch('admin_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { loadMessages(false); });
    }
}

// ... (Paste sisa fungsi: injectEditIndicator, openAdminProfile, saveAdminProfile, setText, getStatus, linkify, scrollToBottom, playSound, openImageViewer, closeImageViewer, toggleSidebarMenu, toggleMenu, showDetail, closeDetail, openEditUser, loadTemplates, showTemplatePopup, useTemplate, toggleTpl, openTplManager, renderTemplateManager, openFormTpl, saveTemplate, deleteTemplate, saveEditUser, openEndModal, confirmEndSession, reopenSession, deleteSession, injectSmartModal, showSmartAlert, showSmartConfirm, setupModal, openSmartModal, closeSmartModal, saveNote, detectSmartData, toTitleCase, showSmartPrompt, quickUpdateUser)
// Pastikan tidak ada duplikasi fungsi. Copy dari file app.js sebelumnya untuk fungsi-fungsi helper ini.

function injectEditIndicator() { const footer = document.getElementById('footer-active'); if(footer) { const div = document.createElement('div'); div.id = 'edit-indicator'; div.className = 'hidden w-full bg-blue-50 dark:bg-white/10 border-t border-x border-blue-200 dark:border-white/10 rounded-t-xl px-4 py-2 flex justify-between items-center text-xs text-blue-700 dark:text-blue-300 absolute bottom-full left-0 mb-[-5px] z-0 shadow-sm'; div.innerHTML = `<div class="flex items-center gap-2"><span class="font-bold">✏️ Edit Mode</span></div><button onclick="cancelEditMode()" class="text-slate-400 hover:text-red-500 font-bold px-2 uppercase text-[10px]">Batal (ESC)</button>`; footer.parentElement.style.position = 'relative'; footer.parentElement.insertBefore(div, footer.parentElement.firstChild); } }

function openAdminProfile() { fetch('admin_api.php?action=get_admin_profile').then(r => r.json()).then(res => { if (res.status === 'success') { const d = res.data; document.getElementById('adm-name').value = d.name || ''; document.getElementById('adm-username').value = d.username || ''; document.getElementById('adm-password').value = ''; document.getElementById('sidebar-menu').classList.add('hidden'); document.getElementById('modal-admin-profile').classList.remove('hidden'); } else { showSmartAlert("Gagal", res.msg || "Gagal.", "error"); } }); }

function saveAdminProfile() { const name = document.getElementById('adm-name').value.trim(); const username = document.getElementById('adm-username').value.trim(); const password = document.getElementById('adm-password').value; if (!name || !username) { showSmartAlert("Gagal", "Lengkapi data.", "warning"); return; } const fd = new FormData(); fd.append('action', 'update_admin_profile'); fd.append('name', name); fd.append('username', username); if (password) fd.append('password', password); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if (res.status === 'success') { document.getElementById('modal-admin-profile').classList.add('hidden'); showSmartAlert("Berhasil", "Profil disimpan.", "success").then(() => location.reload()); } else { showSmartAlert("Gagal", res.msg || "Error.", "error"); } }); }

function setText(selector, text) { document.querySelectorAll(selector).forEach(el => el.innerText = text || '-'); }

function getStatus(lastSeen) { if (!lastSeen) return '<span class="text-slate-400 dark:text-slate-500 text-xs">Offline</span>'; const seenDate = new Date(lastSeen.replace(/-/g, "/")); const diffMins = Math.floor((new Date() - seenDate) / 60000); if (diffMins < 5) return `<span class="text-green-600 dark:text-green-400 text-xs font-bold">Online</span>`; return `<span class="text-slate-500 dark:text-slate-400 text-xs">Seen ${seenDate.getHours()}:${seenDate.getMinutes().toString().padStart(2,'0')}</span>`; }

function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function linkify(text) {
    if (!text) return '';
    // Escape dulu agar aman dari XSS, lalu baru format newline dan URL.
    let formatted = escapeHtml(text).replace(/\n/g, '<br>');
    return formatted.replace(
        /(\b(https?):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/ig,
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline break-all">$1</a>',
    );
}

function scrollToBottom() { const el = document.getElementById('messages'); el.scrollTop = el.scrollHeight; }

function playSound() { if(audioNotif) { audioNotif.currentTime = 0; const playPromise = audioNotif.play(); if (playPromise !== undefined) { playPromise.catch(error => { console.log("Audio autoplay prevented."); }); } } }

function openImageViewer(src) { document.getElementById('img-viewer-src').src = src; document.getElementById('img-download-btn').href = src; document.getElementById('img-viewer').classList.remove('hidden'); document.getElementById('img-viewer').classList.add('flex'); }

function closeImageViewer() { document.getElementById('img-viewer').classList.add('hidden'); document.getElementById('img-viewer').classList.remove('flex'); }

function toggleSidebarMenu() { const s = document.getElementById('sidebar-menu'); if(s) s.classList.toggle('hidden'); }

function toggleMenu() { const c = document.getElementById('chat-menu'); if(c) c.classList.toggle('hidden'); }

function showDetail() { toggleUserSidebar(); toggleMenu(); }

function closeDetail() { document.getElementById('modal-detail').classList.add('hidden'); }

function openEditUser() { if (!activeVisitId) return; const user = usersData.find(u => u.visit_id == activeVisitId); if (!user) return; document.getElementById('edit-visit-id').value = user.visit_id; document.getElementById('edit-name').value = user.name || ''; document.getElementById('edit-phone').value = user.phone || ''; document.getElementById('edit-addr').value = user.address || ''; document.getElementById('modal-edit-user').classList.remove('hidden'); toggleMenu(); }

window.chatTemplates = []; 
function loadTemplates() { fetch('admin_api.php?action=get_templates').then(r => r.json()).then(data => { if(Array.isArray(data)) { window.chatTemplates = data; renderTemplateManager(); } }).catch(e => console.error("Gagal muat template:", e)); }

function showTemplatePopup(k) { const p = document.getElementById('tpl-popup'); const l = document.getElementById('tpl-list'); const m = window.chatTemplates.filter(t => (t.label || '').toLowerCase().includes(k.toLowerCase())); if(m.length === 0) { p.classList.add('hidden'); return; } l.innerHTML = m.map(t => { const rawMsg = String(t.message ?? ''); const jsMsg = rawMsg.replace(/\\/g, "\\\\").replace(/'/g,"\\'"); const safeLabel = escapeHtml(t.label ?? ''); const safeMsg = escapeHtml(rawMsg); return `<div onclick="useTemplate('${jsMsg}')" class="px-3 py-2 hover:bg-slate-100 dark:hover:bg-white/5 cursor-pointer border-b border-slate-100 dark:border-white/10 text-xs"><div class="text-blue-600 dark:text-blue-400 font-bold mb-0.5">/${safeLabel}</div><div class="text-slate-500 dark:text-slate-400 truncate">${safeMsg}</div></div>`; }).join(''); p.classList.remove('hidden'); }

function useTemplate(t) { const i=document.getElementById('msg-input'); i.value=t; i.dispatchEvent(new Event('input')); document.getElementById('tpl-popup').classList.add('hidden'); i.focus(); }

function toggleTpl() { const p=document.getElementById('tpl-popup'); if(p.classList.contains('hidden')) showTemplatePopup(''); else p.classList.add('hidden'); }

function openTplManager() { loadTemplates(); document.getElementById('modal-tpl-manager').classList.remove('hidden'); }

function renderTemplateManager() { const c = document.getElementById('manager-list'); if(c) { if(window.chatTemplates.length === 0) { c.innerHTML = '<div class="text-center text-slate-400 text-xs py-5">Belum ada template.</div>'; return; } c.innerHTML = window.chatTemplates.map(t => { const safeLabel = escapeHtml(t.label ?? ''); const safeMsg = escapeHtml(String(t.message ?? '')); const jsId = String(t.id ?? '').replace(/\\/g, "\\\\").replace(/'/g, "\\'"); return `<div class="bg-slate-50 dark:bg-white/5 p-3 rounded-xl border border-slate-200 dark:border-white/10 flex justify-between gap-3 items-start"><div class="overflow-hidden"><div class="text-blue-600 dark:text-blue-400 text-xs font-bold mb-1">/${safeLabel}</div><div class="text-xs text-slate-500 dark:text-slate-400 line-clamp-2">${safeMsg}</div></div><button onclick="deleteTemplate('${jsId}')" class="text-slate-400 hover:text-red-500 p-1 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button></div>`; }).join(''); } }

function openFormTpl() { document.getElementById('tpl-label').value=''; document.getElementById('tpl-msg').value=''; document.getElementById('modal-tpl-manager').classList.add('hidden'); document.getElementById('modal-form-tpl').classList.remove('hidden'); }

function saveTemplate() { const l = document.getElementById('tpl-label').value.trim(); const m = document.getElementById('tpl-msg').value.trim(); if(l && m) { const fd = new FormData(); fd.append('action', 'save_template'); fd.append('label', l); fd.append('message', m); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if(res.status === 'success') { loadTemplates(); openTplManager(); document.getElementById('modal-form-tpl').classList.add('hidden'); showSmartAlert("Berhasil", "Template disimpan.", "success"); } else { showSmartAlert("Gagal", "Error.", "error"); } }); } else { showSmartAlert("Validasi", "Wajib diisi.", "warning"); } }

function deleteTemplate(id) { showSmartConfirm('Hapus Template', 'Hapus permanen?', 'warning').then(ok=>{ if(ok){ const fd = new FormData(); fd.append('action', 'delete_template'); fd.append('id', id); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if(res.status === 'success') { loadTemplates(); } else { showSmartAlert("Gagal", "Error.", "error"); } }); } }); }

function saveEditUser() { const id = document.getElementById('edit-visit-id').value; const name = document.getElementById('edit-name').value.trim(); const phone = document.getElementById('edit-phone').value.trim(); const addr = document.getElementById('edit-addr').value.trim(); if (!id || !name) { showSmartAlert("Validasi", "Nama wajib diisi.", "warning"); return; } document.getElementById('modal-edit-user').classList.add('hidden'); const fd = new FormData(); fd.append('action', 'update_customer'); fd.append('visit_id', id); fd.append('name', name); fd.append('phone', phone); fd.append('address', addr); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if (res.status === 'success') { const userIndex = usersData.findIndex(u => u.visit_id == id); if (userIndex !== -1) { usersData[userIndex].name = name; usersData[userIndex].phone = phone; usersData[userIndex].address = addr; } openChat(id); showSmartAlert("Berhasil", "Data disimpan.", "success"); } else { showSmartAlert("Gagal", 'Error.', "error"); } }); }

function openEndModal() { if (!activeVisitId) return; toggleMenu(); showSmartConfirm("Selesaikan Sesi?", "Sesi akan ditutup.", "success", "Ya, Selesaikan").then((isConfirmed) => { if (isConfirmed) confirmEndSession(); }); }

function confirmEndSession() { if (!activeVisitId) return; const fd = new FormData(); fd.append('action', 'end_session'); fd.append('visit_id', activeVisitId); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if (res.status === 'success') { const userIndex = usersData.findIndex(u => u.visit_id == activeVisitId); if (userIndex !== -1) { usersData[userIndex].status = 'Selesai'; } openChat(activeVisitId); loadContacts(false); showSmartAlert("Berhasil", "Sesi selesai.", "success"); } else { showSmartAlert("Error", "Gagal.", "error"); } }); }

function reopenSession() { if (!activeVisitId) return; const fd = new FormData(); fd.append('action', 'reopen_session'); fd.append('visit_id', activeVisitId); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { if (res.status === 'success') { loadContacts(false); const userIndex = usersData.findIndex(u => u.visit_id == activeVisitId); if(userIndex !== -1) usersData[userIndex].status = 'Proses'; openChat(activeVisitId); showSmartAlert("Berhasil", "Sesi dibuka.", "success"); } else { showSmartAlert("Error", "Gagal.", "error"); } }); }

function deleteSession() { 
    if (!activeVisitId) return; 
    showSmartConfirm("Hapus Chat?", "Data akan hilang permanen.", "danger", "Hapus").then((isConfirmed) => { 
        if (isConfirmed) { 
            const fd = new FormData(); 
            fd.append('action', 'delete_session'); 
            fd.append('visit_id', activeVisitId); 
            fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { 
                if (res.status === 'success') { 
                    closeActiveChat(); 
                    loadContacts(false); 
                    showSmartAlert("Terhapus", "Data chat berhasil dihapus.", "success"); 
                } else { 
                    showSmartAlert("Gagal", "Error.", "error"); 
                } 
            }); 
        } 
    }); 
}

function injectSmartModal() { if (document.getElementById('smart-modal')) return; const modalHtml = `<div id="smart-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0" id="smart-modal-bg"></div><div class="relative bg-white dark:bg-[#233138] rounded-2xl shadow-2xl max-w-sm w-full p-6 transform scale-95 opacity-0 transition-all duration-300 border border-slate-200 dark:border-white/10" id="smart-modal-panel"><div class="text-center"><h3 class="text-lg font-bold text-slate-800 dark:text-white tracking-wide" id="smart-title">Notification</h3><div class="mt-2"><p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed" id="smart-msg">Message goes here...</p></div></div><div class="mt-6 flex gap-3 justify-center" id="smart-actions"></div></div></div>`; document.body.insertAdjacentHTML('beforeend', modalHtml); }

function showSmartAlert(title, message, type = 'info') { return new Promise((resolve) => { setupModal(title, message, type); const btnArea = document.getElementById('smart-actions'); btnArea.innerHTML = `<button id="smart-btn-ok" class="w-full justify-center rounded-xl bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 text-sm font-bold shadow-lg shadow-blue-200 dark:shadow-none transition-all outline-none focus:ring-4 focus:ring-blue-300 focus:ring-offset-2">Oke</button>`; const btn = document.getElementById('smart-btn-ok'); btn.onclick = () => { closeSmartModal(); resolve(true); }; openSmartModal(); setTimeout(() => btn.focus(), 50); }); }

function showSmartConfirm(title, message, type = 'warning', confirmText = 'Ya') { return new Promise((resolve) => { setupModal(title, message, type); const btnArea = document.getElementById('smart-actions'); let confirmColor = 'bg-blue-600 hover:bg-blue-700 shadow-blue-200 focus:ring-blue-300'; if (type === 'danger') { confirmColor = 'bg-red-600 hover:bg-red-700 shadow-red-200 focus:ring-red-300'; } if (type === 'success') { confirmColor = 'bg-green-600 hover:bg-green-700 shadow-green-200 focus:ring-green-300'; } btnArea.innerHTML = `<button id="smart-btn-cancel" class="flex-1 justify-center rounded-xl bg-white dark:bg-transparent hover:bg-slate-50 dark:hover:bg-white/5 text-slate-600 dark:text-slate-300 px-5 py-2.5 text-sm font-bold transition-all border border-slate-200 dark:border-white/20 outline-none focus:ring-4 focus:ring-slate-100 focus:ring-offset-2">Batal</button><button id="smart-btn-confirm" class="flex-1 justify-center rounded-xl ${confirmColor} text-white px-5 py-2.5 text-sm font-bold shadow-lg dark:shadow-none transition-all outline-none focus:ring-4 focus:ring-offset-2">${confirmText}</button>`; const btnCancel = document.getElementById('smart-btn-cancel'); const btnConfirm = document.getElementById('smart-btn-confirm'); btnConfirm.addEventListener('keydown', (e) => { if (e.key === 'ArrowLeft') { e.preventDefault(); btnCancel.focus(); } }); btnCancel.addEventListener('keydown', (e) => { if (e.key === 'ArrowRight') { e.preventDefault(); btnConfirm.focus(); } }); btnCancel.onclick = () => { closeSmartModal(); resolve(false); }; btnConfirm.onclick = () => { closeSmartModal(); resolve(true); }; openSmartModal(); setTimeout(() => btnConfirm.focus(), 50); }); }

function setupModal(title, message, type) { document.getElementById('smart-title').innerText = title; document.getElementById('smart-msg').innerHTML = message; }

function openSmartModal() { const modal = document.getElementById('smart-modal'); const bg = document.getElementById('smart-modal-bg'); const panel = document.getElementById('smart-modal-panel'); modal.classList.remove('hidden'); modal.classList.add('flex'); setTimeout(() => { bg.classList.remove('opacity-0'); panel.classList.remove('opacity-0', 'scale-95'); panel.classList.add('opacity-100', 'scale-100'); }, 10); }

function closeSmartModal() { const bg = document.getElementById('smart-modal-bg'); const panel = document.getElementById('smart-modal-panel'); bg.classList.add('opacity-0'); panel.classList.remove('opacity-100', 'scale-100'); panel.classList.add('opacity-0', 'scale-95'); setTimeout(() => { document.getElementById('smart-modal').classList.add('hidden'); document.getElementById('smart-modal').classList.remove('flex'); }, 300); }

function saveNote() { if (!activeVisitId) return; const noteInput = document.getElementById('customer-note'); if (!noteInput) return; const noteVal = noteInput.value; const fd = new FormData(); fd.append('action', 'save_note'); fd.append('visit_id', activeVisitId); fd.append('note', noteVal); fetch('admin_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => { const statusEl = document.getElementById('note-status'); if (res.status === 'success') { if (statusEl) { statusEl.innerText = 'Saved'; statusEl.className = 'text-[9px] text-green-600 font-bold opacity-100 transition-opacity duration-300'; setTimeout(() => { if(statusEl.innerText === 'Saved') { statusEl.className = 'text-[9px] text-green-600 font-bold opacity-0 transition-opacity duration-500'; } }, 2000); } const userIndex = usersData.findIndex(u => String(u.visit_id) === String(activeVisitId)); if (userIndex !== -1) { usersData[userIndex].notes = noteVal; } } else { if (statusEl) { statusEl.innerText = 'Gagal'; statusEl.className = 'text-[9px] text-red-500 font-bold opacity-100'; } } }).catch(err => { console.error("Save note error:", err); const statusEl = document.getElementById('note-status'); if (statusEl) { statusEl.innerText = 'Error'; statusEl.className = 'text-[9px] text-red-500 font-bold opacity-100'; } }); }

function detectSmartData(msgText, msgId) {
    if (!msgText) return '';
    let suggestions = '';
    const namePattern = /(?:^|\n|\.|,)\s*(?:nama|nm|user|an\.?|a\/n|atas\s*nama)\s*(?:lengkap|panggilan|saya|asli|cust|customer|pelanggan)?\s*(?:[:=]|\s+)(?!(?:saya|aku|kamu|dia|mereka|kita|anda|adalah|itu|ini|yang|mau|ingin|tolong|bisa|sudah)\b)([a-zA-Z\s\.\']{3,30})/i;
    const introPattern = /(?:saya|aku|panggil)\s+(?:adalah|nama|panggil)?\s*([a-zA-Z\s\.\']{3,20})/i;
    let detectedName = null;
    let matchName = msgText.match(namePattern);
    if (matchName && matchName[1]) {
        detectedName = matchName[1];
    } else {
        let matchIntro = msgText.match(introPattern);
        if (matchIntro && matchIntro[1]) {
             const temp = matchIntro[1].trim();
             const firstWord = temp.split(' ')[0].toLowerCase();
             const blacklist = ['mau','ingin','tolong','admin','kak','halo','ada','butuh','perlu','mohon','bisa','sudah','belum'];
             if (!blacklist.includes(firstWord)) {
                 detectedName = temp;
             }
        }
    }
    if (detectedName) {
        detectedName = detectedName.replace(/\b(loh|dong|sih|min|kak|gan|sis|pak|bu)\b/gi, '').trim();
        if (detectedName.length > 2) {
            const safeName = detectedName.replace(/'/g, "\\'");
            suggestions += `<button onclick="quickUpdateUser('name', '${safeName}', '${msgId}')" class="mt-1 mr-1 px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 text-[10px] rounded-full border border-blue-200 font-bold transition-colors flex items-center gap-1">💾 Simpan Nama: ${detectedName}</button>`;
        }
    }
    const hpPattern = /(?:\+?62|0)8[0-9\-\s]{8,15}/;
    const matchHP = msgText.match(hpPattern);
    if (matchHP) {
        let cleanHP = matchHP[0].replace(/[^0-9]/g, '');
        if (cleanHP.startsWith('0')) cleanHP = '62' + cleanHP.substring(1);
        suggestions += `<button onclick="quickUpdateUser('phone', '${cleanHP}', '${msgId}')" class="mt-1 mr-1 px-2 py-1 bg-green-100 hover:bg-green-200 text-green-700 text-[10px] rounded-full border border-green-200 font-bold transition-colors flex items-center gap-1">💾 Simpan HP: ${cleanHP}</button>`;
    }
    const addrKeyword = /(?:al?a?mat|lokasi|posisi|domisili|hunian|t?empat\s*tinggal|rumah|rmh|kost|kediaman|ber?a?lamat(?:\s*di)?|tinggal\s*di)\s*[:=]?\s*([a-zA-Z0-9\s\.\,\-\/]+)/i;
    const addrDirect = /(?:jln|jl\.?|jalan|dusun|dsn?\.?|kp\.?|kampung|gang|gg\.?|blok|perum|komplek|rt\/rw|kel\.?|kec\.?|kab\.?|kota)\s+([a-zA-Z0-9\s\.\,\-\/]+)/i;
    let matchAddr = msgText.match(addrKeyword) || msgText.match(addrDirect);
    if (matchAddr) {
        let cleanAddr = matchAddr[1] ? matchAddr[1] : matchAddr[0];
        cleanAddr = cleanAddr.trim();
        if (cleanAddr.length > 100) cleanAddr = cleanAddr.substring(0, 97) + '...';
        if (cleanAddr.length > 4) {
            const safeAddr = cleanAddr.replace(/'/g, "\\'");
            suggestions += `<button onclick="quickUpdateUser('address', '${safeAddr}', '${msgId}')" class="mt-1 mr-1 px-2 py-1 bg-orange-100 hover:bg-orange-200 text-orange-700 text-[10px] rounded-full border border-orange-200 font-bold transition-colors flex items-center gap-1">💾 Simpan Alamat</button>`;
        }
    }
    return suggestions ? `<div class="flex flex-wrap mt-1 animate-in fade-in slide-in-from-top-1">${suggestions}</div>` : '';
}

function toTitleCase(str) { return str.replace(/\w\S*/g, function(txt){ return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase(); }); }

function showSmartPrompt(title, label, value) {
    return new Promise((resolve) => {
        setupModal(title, '', 'info');
        const msgArea = document.getElementById('smart-msg');
        msgArea.innerHTML = `<div class="text-left"><label class="block text-xs font-bold text-slate-500 mb-1">${label}</label><input type="text" id="smart-prompt-input" class="w-full px-3 py-2 border border-slate-300 dark:border-white/20 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-slate-50 dark:bg-black/20 dark:text-white" value="${value}"></div>`;
        const btnArea = document.getElementById('smart-actions');
        btnArea.innerHTML = `<button id="smart-btn-cancel" class="flex-1 justify-center rounded-xl bg-white dark:bg-transparent hover:bg-slate-50 dark:hover:bg-white/5 text-slate-600 dark:text-slate-300 px-5 py-2.5 text-sm font-bold border border-slate-200 dark:border-white/20">Batal</button><button id="smart-btn-save" class="flex-1 justify-center rounded-xl bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 text-sm font-bold shadow-lg shadow-blue-200 dark:shadow-none">Simpan</button>`;
        const input = document.getElementById('smart-prompt-input');
        const btnCancel = document.getElementById('smart-btn-cancel');
        const btnSave = document.getElementById('smart-btn-save');
        setTimeout(() => { input.focus(); input.select(); }, 100);
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') btnSave.click(); });
        btnCancel.onclick = () => { closeSmartModal(); resolve(false); };
        btnSave.onclick = () => { const finalVal = input.value.trim(); closeSmartModal(); resolve(finalVal); };
        openSmartModal();
    });
}

async function quickUpdateUser(field, value, msgId) {
    if (!activeVisitId) return;
    let formattedValue = value;
    if (field === 'name') formattedValue = toTitleCase(value);
    let label = "Pastikan ejaan benar:";
    if (field === 'phone') label = "Pastikan format nomor HP (62...):";
    if (field === 'address') label = "Edit alamat agar lebih rapi:";
    const finalValue = await showSmartPrompt(`Validasi ${field}`, label, formattedValue);
    if (finalValue === false || finalValue === '') return;
    const currentUser = usersData.find(u => u.visit_id == activeVisitId);
    let newName = (currentUser && currentUser.name) ? currentUser.name : '';
    let newPhone = (currentUser && currentUser.phone) ? currentUser.phone : '';
    let newAddr = (currentUser && currentUser.address) ? currentUser.address : '';
    if (field === 'name') newName = finalValue;
    if (field === 'phone') newPhone = finalValue;
    if (field === 'address') newAddr = finalValue;
    showSmartAlert("Menyimpan...", "Mohon tunggu...", "info");
    const fd = new FormData();
    fd.append('action', 'update_customer');
    fd.append('visit_id', activeVisitId);
    fd.append('name', newName);
    fd.append('phone', newPhone);
    fd.append('address', newAddr);
    fetch('admin_api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        const smartModal = document.getElementById('smart-modal');
        if(smartModal) smartModal.classList.add('hidden'); 
        if (res.status === 'success') {
            if (currentUser) {
                currentUser.name = newName;
                currentUser.phone = newPhone;
                currentUser.address = newAddr;
            }
            openChat(activeVisitId); 
            showSmartAlert("Sukses", "Data berhasil diperbarui.", "success");
        } else {
            showSmartAlert("Gagal", "Update gagal.", "error");
        }
    })
    .catch(err => {
        showSmartAlert("Error", "Koneksi bermasalah.", "error");
    });
}

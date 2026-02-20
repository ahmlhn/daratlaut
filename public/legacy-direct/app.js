// FILE: dashboard/direct/app.js
// VERSI: SUPPORT ENTER (NEWLINE) + FINAL FIX

let visitId = localStorage.getItem('noci_visit_id');
let checkInterval = null;
let isChatOpen = false;
let unreadCount = 0;
let lastSyncTime = ''; // Smart sync cursor (server_time)
let messagesCache = []; // Full message list in memory (for rendering)
let messagePos = {}; // id -> index in messagesCache
let isSessionInitialized = false; 
let sessionInitPromise = null;
let sessionLoadingBubble = null;
let dynamicWelcome = "Halo kak, ada yang bisa kami bantu?"; // Default

const TENANT_TOKEN = document.body ? (document.body.dataset.tenantToken || '') : '';
const API_URL = TENANT_TOKEN ? `api.php?t=${encodeURIComponent(TENANT_TOKEN)}` : 'api.php';
const LOG_URL = TENANT_TOKEN ? `../log.php?t=${encodeURIComponent(TENANT_TOKEN)}` : '../log.php';
// Use same-origin uploads so it works both on localhost and production domains.
const UPLOAD_URL = (() => {
    try { return `${window.location.origin}/chat/uploads/`; } catch (e) { return '/chat/uploads/'; }
})(); 

document.addEventListener('DOMContentLoaded', () => {
    // 1. Generate ID Lokal
    if (!visitId) { 
        visitId = Math.floor(100000000 + Math.random() * 900000000).toString(); 
        localStorage.setItem('noci_visit_id', visitId); 
    }
    const dispId = document.getElementById('disp-id'); 
    if(dispId) dispId.innerText = visitId;
    
    // 2. Cek History Lokal
    const savedName = localStorage.getItem('noci_user_name');
    if (savedName) {
        ensureSessionInitialized();
    }
    
    checkChatSystemStatus();
    logVisit('view_halaman');

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const imgViewer = document.getElementById('img-viewer-client');
            if (imgViewer && imgViewer.style.display === 'flex') { closeImageViewer(); return; }
            if (isChatOpen) closeLocalChat();
        }
    });
});

function checkChatSystemStatus() {
    const fd = new FormData();
    fd.append('action', 'check_status');
    fd.append('visit_id', visitId);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            // Update Pesan Sambutan dari Database
            if (data.welcome_msg) {
                dynamicWelcome = data.welcome_msg;
            }

            const mainBtn = document.querySelector('.btn-chat-lokal');
            const statusBadge = document.querySelector('.status-badge'); 
            const footerNote = document.getElementById('footer-note');
            const iconDiv = mainBtn ? mainBtn.querySelector('.btn-icon') : null;
            
            if (mainBtn && data.status === 'offline') {
                mainBtn.style.background = 'linear-gradient(135deg, #25D366 0%, #128C7E 100%)';
                mainBtn.style.boxShadow = '0 8px 20px -5px rgba(37, 211, 102, 0.4)';
                mainBtn.style.animation = 'none'; 
                
                const textContainer = mainBtn.querySelector('div:first-child');
                if (textContainer) {
                    const title = textContainer.querySelector('div:nth-child(1)');
                    const sub = textContainer.querySelector('div:nth-child(2)');
                    if(title) title.innerText = "Chat WhatsApp Admin";
                    if(sub) sub.style.display = 'none'; 
                }
                
                if(iconDiv) {
                    iconDiv.innerHTML = `
                    <svg style="width:24px;height:24px;fill:white" viewBox="0 0 24 24">
                        <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.03 14.69 2 12.04 2ZM12.05 20.16C10.58 20.16 9.11 19.76 7.8 18.99L7.52 18.82L4.41 19.64L5.24 16.6L5.05 16.3C4.19 14.93 3.8 13.37 3.8 11.91C3.8 7.37 7.5 3.67 12.05 3.67C14.25 3.67 16.32 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.16 12.05 20.16Z"/>
                    </svg>`;
                }

                mainBtn.onclick = function(e) {
                    e.preventDefault(); 
                    e.stopPropagation();
                    window.open(data.wa_link, '_blank');
                    logVisit('klik_wa_offline');
                };

                if(statusBadge) {
                    statusBadge.innerText = "Layanan Offline";
                    statusBadge.style.color = "#64748b";
                    statusBadge.style.background = "#f1f5f9";
                    statusBadge.style.borderColor = "#cbd5e1";
                }
                if(footerNote) {
                    footerNote.innerHTML = "<strong>Catatan:</strong> Menghubungi via WhatsApp membutuhkan koneksi internet atau kuota data pribadi.";
                    footerNote.style.background = "#fff7ed";
                    footerNote.style.borderColor = "#fdba74";
                    footerNote.style.color = "#c2410c";
                }

            } else {
                mainBtn.style.background = ''; 
                mainBtn.style.boxShadow = ''; 
                mainBtn.style.animation = '';
                
                const textContainer = mainBtn.querySelector('div:first-child');
                if (textContainer) {
                    const title = textContainer.querySelector('div:nth-child(1)');
                    const sub = textContainer.querySelector('div:nth-child(2)');
                    if(title) title.innerText = "Klik Disini Bantuan & Konfirmasi";
                    if(sub) {
                        sub.innerText = "Hubungi Admin (Bebas Kuota)";
                        sub.style.display = 'block'; 
                    }
                }

                if(iconDiv) {
                    iconDiv.innerHTML = '<svg style="width: 24px; height: 24px; fill: white;" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>';
                }

                mainBtn.onclick = openLocalChat;

                if(footerNote) {
                    footerNote.innerHTML = "<strong>Catatan:</strong> Tombol chat di atas dapat diakses meskipun kuota internet Anda habis.";
                    footerNote.style.background = ""; 
                    footerNote.style.borderColor = "";
                    footerNote.style.color = "";
                }
            }
        })
        .catch(err => console.error("Gagal cek status chat:", err));
}

function logVisit(action) {
    const fd = new FormData(); 
    fd.append('action', action); 
    fd.append('visit_id', visitId);
    if (TENANT_TOKEN) fd.append('t', TENANT_TOKEN);
    if (navigator.sendBeacon) navigator.sendBeacon(LOG_URL, fd);
    else fetch(LOG_URL, { method: 'POST', body: fd }).catch(() => {});
}

function getAutoName() {
    const savedName = localStorage.getItem('noci_user_name');
    if (savedName) return savedName;
    const urlParams = new URLSearchParams(window.location.search);
    const urlName = urlParams.get('nama'); 
    return urlName ? decodeURIComponent(urlName) : ("Pelanggan " + visitId.slice(-4));
}

function setComposerBusy(isBusy) {
    const input = document.getElementById('chat-input');
    const sendBtn = document.querySelector('.btn-send');
    const attachBtn = document.querySelector('.btn-attach-inner');

    if (input) {
        if (isBusy) {
            if (!input.dataset.prevPlaceholder) {
                input.dataset.prevPlaceholder = input.placeholder || 'Ketik pesan...';
            }
            input.placeholder = 'Membuka sesi chat...';
        } else {
            input.placeholder = input.dataset.prevPlaceholder || 'Ketik pesan...';
            delete input.dataset.prevPlaceholder;
        }
        input.disabled = isBusy;
    }
    if (sendBtn) sendBtn.disabled = isBusy;
    if (attachBtn) attachBtn.disabled = isBusy;
}

function ensureSessionLoadingStyle() {
    if (document.getElementById('direct-session-loading-style')) return;
    const style = document.createElement('style');
    style.id = 'direct-session-loading-style';
    style.textContent = '@keyframes directSessionTyping{0%,80%,100%{transform:scale(0.45);opacity:.55;}40%{transform:scale(1);opacity:1;}}';
    document.head.appendChild(style);
}

function showSessionLoadingBubble() {
    const container = document.getElementById('chat-messages');
    if (!container) return;

    ensureSessionLoadingStyle();

    if (sessionLoadingBubble && sessionLoadingBubble.isConnected) {
        scrollToBottom();
        return;
    }

    sessionLoadingBubble = document.createElement('div');
    sessionLoadingBubble.className = 'msg msg-admin';
    sessionLoadingBubble.dataset.role = 'session-loading';
    sessionLoadingBubble.style.opacity = '0.85';
    sessionLoadingBubble.innerHTML = `
        <div style="display:flex; align-items:center; gap:4px; padding:4px 0;">
            <span style="width:6px; height:6px; background:#94a3b8; border-radius:50%; animation:directSessionTyping 1.2s infinite ease-in-out both;"></span>
            <span style="width:6px; height:6px; background:#94a3b8; border-radius:50%; animation:directSessionTyping 1.2s infinite ease-in-out both; animation-delay:0.2s;"></span>
            <span style="width:6px; height:6px; background:#94a3b8; border-radius:50%; animation:directSessionTyping 1.2s infinite ease-in-out both; animation-delay:0.4s;"></span>
            <span style="font-size:10px; color:#64748b; margin-left:6px;">Sedang membuka sesi...</span>
        </div>
    `;
    container.appendChild(sessionLoadingBubble);
    scrollToBottom();
}

function hideSessionLoadingBubble() {
    if (sessionLoadingBubble && sessionLoadingBubble.parentNode) {
        sessionLoadingBubble.parentNode.removeChild(sessionLoadingBubble);
    }
    sessionLoadingBubble = null;
}

function setSessionOpeningState(isBusy) {
    setComposerBusy(isBusy);
    if (isBusy) showSessionLoadingBubble();
    else hideSessionLoadingBubble();
}

function ensureSessionInitialized() {
    if (isSessionInitialized) return Promise.resolve(true);
    if (sessionInitPromise) {
        setSessionOpeningState(true);
        return sessionInitPromise;
    }

    const autoName = getAutoName();
    localStorage.setItem('noci_user_name', autoName);

    const fd = new FormData();
    fd.append('action', 'start_session');
    fd.append('visit_id', visitId);
    fd.append('name', autoName);

    setSessionOpeningState(true);

    sessionInitPromise = fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(async d => {
            if (d.status === 'success') {
                isSessionInitialized = true;
                await startPolling();
                return true;
            }
            return false;
        })
        .catch(() => false)
        .finally(() => {
            setSessionOpeningState(false);
            sessionInitPromise = null;
        });

    return sessionInitPromise;
}

function markChatStarted() {
    const key = 'noci_chat_started';
    if (localStorage.getItem(key) !== visitId) {
        localStorage.setItem(key, visitId);
        logVisit('mulai_chat');
    }
}

function openLocalChat() {
    isChatOpen = true; 
    document.getElementById('chat-overlay').classList.add('active'); 
    logVisit('buka_chat_direct'); 
    
    const mainBtn = document.querySelector('.btn-chat-lokal'); 
    const badge = document.getElementById('chat-badge');
    if(mainBtn) mainBtn.classList.remove('btn-notify'); 
    unreadCount = 0; 
    if(badge) { badge.classList.remove('show'); badge.innerText = '0'; }
    
    scrollToBottom();
    
    if (!isSessionInitialized && document.getElementById('chat-messages').children.length === 0) {
        showSessionLoadingBubble();
    }

    ensureSessionInitialized().finally(() => {
        const input = document.getElementById('chat-input');
        setTimeout(() => { if(input) input.focus(); }, 100);
    });
}

function closeLocalChat() { 
    isChatOpen = false; 
    document.getElementById('chat-overlay').classList.remove('active'); 
}

function registerAndSend(pendingMsg = null, pendingFile = null) {
    ensureSessionInitialized()
        .then(ok => {
            if (!ok) {
                alert("Gagal terhubung ke server. Coba lagi.");
                return;
            }
            if (pendingMsg) doSendMsg(pendingMsg);
            if (pendingFile) doSendImage(pendingFile);
        })
        .catch(() => alert("Gagal terhubung ke server. Coba lagi."));
}

function sendMsg() {
    const input = document.getElementById('chat-input'); 
    const msg = input.value.trim(); 
    if(!msg) return;

    markChatStarted();
    
    appendBubble(msg, 'user', 'Baru saja', 'text'); 
    input.value = ''; 
    input.style.height = 'auto'; 
    scrollToBottom();

    if (!isSessionInitialized) {
        registerAndSend(msg, null);
    } else {
        doSendMsg(msg);
    }
}

function doSendMsg(msg) {
    const fd = new FormData(); 
    fd.append('action', 'send'); 
    fd.append('visit_id', visitId); 
    fd.append('message', msg); 
    fd.append('sender', 'user');
    
    fetch(API_URL, { method: 'POST', body: fd }).then(res => res.json()).catch(err => {});
}

async function sendImageClient() {
    const fileInput = document.getElementById('img-input'); 
    if (fileInput.files.length === 0) return;
    const file = fileInput.files[0];
    appendBubble('Mengirim gambar...', 'user', '...', 'text'); 
    scrollToBottom();

    markChatStarted();
    if (!isSessionInitialized) {
        registerAndSend(null, file);
        fileInput.value = ''; 
    } else {
        doSendImage(file);
        fileInput.value = ''; 
    }
}

async function doSendImage(originalFile) {
    let fileToSend = originalFile;
    let fileName = originalFile.name;
    try { 
        const compressedBlob = await compressImage(originalFile); 
        fileToSend = compressedBlob; 
        fileName = 'image.jpg'; 
    } catch (err) { }
    
    const fd = new FormData(); 
    fd.append('action', 'send'); 
    fd.append('visit_id', visitId); 
    fd.append('sender', 'user'); 
    fd.append('image', fileToSend, fileName);
    
    fetch(API_URL, { method: 'POST', body: fd }).then(res => res.json()).then(json => { 
        if(json.status === 'success') { loadMessages(); }
    }).catch(e => {});
}

function compressImage(file, quality = 0.7, maxWidth = 1000) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader(); reader.readAsDataURL(file);
        reader.onload = event => {
            const img = new Image(); img.src = event.target.result;
            img.onload = () => {
                let width = img.width; let height = img.height;
                if (width > maxWidth) { height = Math.round(height * (maxWidth / width)); width = maxWidth; }
                const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
                const ctx = canvas.getContext('2d'); ctx.drawImage(img, 0, 0, width, height);
                canvas.toBlob(blob => { if(blob) resolve(blob); else reject(new Error("Canvas blob failed")); }, 'image/jpeg', quality);
            }; img.onerror = error => reject(error);
        }; reader.onerror = error => reject(error);
    });
}

function handleEnter(e) { if(e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); } }

function resolveDisplayWelcomeMessage() {
    const fallback = 'Halo kak, ada yang bisa kami bantu?';
    const base = String(dynamicWelcome || fallback).trim() || fallback;
    const name = String(localStorage.getItem('noci_user_name') || getAutoName() || '').trim();
    if (!name) return base;
    return base.replace(/\{name\}|\{customer_name\}/gi, name);
}

function getRenderableMessages() {
    const renderList = Array.isArray(messagesCache) ? messagesCache.slice() : [];
    if (renderList.length > 0) return renderList;

    renderList.push({
        id: 'local-welcome',
        sender: 'admin',
        message: resolveDisplayWelcomeMessage(),
        type: 'text',
        is_edited: 0,
        time: '',
    });

    return renderList;
}

async function startPolling() { 
    const firstLoadOk = await loadMessages(true); 
    if(checkInterval) clearInterval(checkInterval); 
    checkInterval = setInterval(() => loadMessages(false), 3000);
    return firstLoadOk;
}

function loadMessages(isFirstLoad = false) {
    if(!isSessionInitialized) return Promise.resolve(false);

    let url = `${API_URL}${API_URL.includes('?') ? '&' : '?'}action=get_messages&visit_id=${encodeURIComponent(visitId)}`;
    if (!isFirstLoad && lastSyncTime) {
        url += `&last_sync=${encodeURIComponent(lastSyncTime)}`;
    }

    return fetch(url).then(r => r.json()).then(res => {
        let msgs = [];
        let serverTime = '';
        let isDelta = false;

        if (Array.isArray(res)) {
            // Legacy compatibility (shouldn't happen for Laravel direct).
            msgs = res;
        } else if (res && res.status === 'success') {
            msgs = res.data || [];
            serverTime = res.server_time || '';
            isDelta = !!res.is_delta;
        }

        if (serverTime) lastSyncTime = serverTime;

        // Initial/full load: replace cache.
        if (isFirstLoad || !isDelta || !lastSyncTime) {
            messagesCache = Array.isArray(msgs) ? msgs.slice() : [];
            messagePos = {};
            for (let i = 0; i < messagesCache.length; i++) {
                const id = String(messagesCache[i]?.id ?? '');
                if (id) messagePos[id] = i;
            }

            const container = document.getElementById('chat-messages');
            if (container) {
                container.innerHTML = '';
                getRenderableMessages().forEach(m => {
                    appendBubble(m.message, m.sender, m.time, m.type || 'text', m.is_edited);
                });
                scrollToBottom();
            }

            return true;
        }

        // Delta update: merge new/updated messages into cache.
        if (!Array.isArray(msgs) || msgs.length === 0) return true;

        const beforeCount = messagesCache.length;
        let hasNewAdminMsg = false;
        let changed = false;

        msgs.forEach(m => {
            const id = String(m?.id ?? '');
            if (!id) return;

            const idx = messagePos[id];
            if (idx === undefined) {
                // New message
                messagePos[id] = messagesCache.length;
                messagesCache.push(m);
                changed = true;
                if (m.sender === 'admin') hasNewAdminMsg = true;
                return;
            }

            // Updated message (edit/delete). Replace in-place.
            messagesCache[idx] = m;
            changed = true;
        });

        if (!changed) return true;

        if (hasNewAdminMsg && beforeCount > 0) {
            playSound();
            if (!isChatOpen) {
                unreadCount++;
                showToast("Pesan baru dari Admin!");
                updateFrontBadge();
            }
        }

        const container = document.getElementById('chat-messages');
        if (container) {
            container.innerHTML = '';
            // Ensure stable order by id (delta may include edits of older messages).
            messagesCache.sort((a, b) => (parseInt(a?.id || 0, 10) - parseInt(b?.id || 0, 10)));
            messagePos = {};
            for (let i = 0; i < messagesCache.length; i++) {
                const id = String(messagesCache[i]?.id ?? '');
                if (id) messagePos[id] = i;
            }
            getRenderableMessages().forEach((m) => {
                appendBubble(m.message, m.sender, m.time, m.type || 'text', m.is_edited);
            });
            scrollToBottom();
        }
        return true;
    }).catch(() => false);
}

function appendBubble(content, sender, time, type = 'text', isEdited = 0) {
    const messagesEl = document.getElementById('chat-messages');
    const div = document.createElement('div');

    // --- TAMPILAN PESAN SYSTEM (AMAN: textContent) ---
    if (sender === 'system') {
        div.style.cssText = "display:flex; justify-content:center; margin:15px 0; width:100%; animation:fadeIn 0.3s;";
        const inner = document.createElement('div');
        inner.style.cssText = "background:#f1f5f9; color:#64748b; padding:6px 14px; border-radius:20px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; border:1px solid #e2e8f0; text-align:center; max-width:85%;";
        inner.textContent = String(content ?? '');
        div.appendChild(inner);
        if (messagesEl) messagesEl.appendChild(div);
        return div;
    }

    div.className = `msg ${sender === 'user' ? 'msg-user' : 'msg-admin'}`;

    const safeTime = escapeHtml(time ?? '');
    const editedHtml = (isEdited == 1)
        ? `<span style="font-style:italic; opacity:0.6; margin-right:4px; font-size:10px;">(diedit)</span>`
        : '';

    if (type === 'image') {
        // Bangun URL gambar secara aman
        const raw = String(content ?? '').trim();
        let imgSrc = '';
        if (/^https?:\/\//i.test(raw)) {
            imgSrc = raw;
        } else {
            // hindari traversal
            const file = raw.replace(/(\.\.[\/\\])/g, '');
            imgSrc = UPLOAD_URL + encodeURIComponent(file);
        }

        const wrapper = document.createElement('div');
        wrapper.style.cssText = "cursor:pointer; position:relative;";
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.style.cssText = "max-width:100%; border-radius:8px; display:block;";
        img.src = imgSrc;

        wrapper.addEventListener('click', () => openImageViewer(imgSrc));
        wrapper.appendChild(img);
        div.appendChild(wrapper);
    } else {
        const copyIcon = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="copy-svg"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';

        div.innerHTML = `
            ${linkify(content ?? '')}
            <div style="display:flex; justify-content:flex-end; align-items:center; gap:6px; margin-top:4px; opacity:0.8;">
                <span class="msg-time" style="margin:0;">${editedHtml} ${safeTime}</span>
                <button class="btn-copy-msg" style="background:transparent; border:none; padding:4px; color:inherit; cursor:pointer; display:flex; border-radius:4px; transition:background 0.2s;" title="Salin Pesan">
                    ${copyIcon}
                </button>
            </div>
        `;

        const btn = div.querySelector('.btn-copy-msg');
        if (btn) {
            btn.onclick = function(e) {
                e.stopPropagation();
                copyToClipboard(String(content ?? ''));
                this.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                setTimeout(() => { this.innerHTML = copyIcon; }, 1500);
            };
        }
    }

    if (messagesEl) messagesEl.appendChild(div);
    return div;
}

function copyToClipboard(text) {
    const textArea = document.createElement("textarea"); 
    textArea.value = text; 
    textArea.style.position = "fixed"; 
    textArea.style.left = "-9999px"; 
    textArea.style.top = "0"; 
    document.body.appendChild(textArea); 
    textArea.focus(); 
    textArea.select();
    
    try { 
        const successful = document.execCommand('copy'); 
        if (successful) showToast("Pesan disalin!"); 
        else showToast("Gagal menyalin otomatis."); 
    } catch (err) { 
        showToast("Error salin: " + err); 
    } 
    document.body.removeChild(textArea);
}

function updateFrontBadge() { 
    const mainBtn = document.querySelector('.btn-chat-lokal'); 
    const badge = document.getElementById('chat-badge'); 
    if(unreadCount > 0) { 
        if(mainBtn) mainBtn.classList.add('btn-notify'); 
        if(badge) { badge.innerText = unreadCount; badge.classList.add('show'); } 
    } 
}

function openImageViewer(src) { 
    const v = document.getElementById('img-viewer-client'); 
    const img = document.getElementById('img-view-src'); 
    const dl = document.getElementById('img-dl-btn'); 
    if(v && img) { img.src = src; if(dl) dl.href = src; v.style.display = 'flex'; } 
}

function closeImageViewer() { 
    const v = document.getElementById('img-viewer-client'); 
    const img = document.getElementById('img-view-src'); 
    if(v) v.style.display = 'none'; 
    if(img) img.src = ''; 
}

function scrollToBottom() { 
    const body = document.getElementById('chat-messages'); 
    if(body) body.scrollTop = body.scrollHeight; 
}

function playSound() { 
    const audio = document.getElementById('sfx-in'); 
    if(audio) { 
        audio.currentTime = 0; 
        audio.play().catch(e => {}); 
    } 
}

function showToast(msg) { 
    const t = document.getElementById('cust-toast'); 
    if(t) { 
        t.querySelector('span').innerText = msg; 
        t.classList.add('show'); 
        setTimeout(() => t.classList.remove('show'), 2000); 
    } 
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function linkify(text) {
    // Escape HTML dulu (anti XSS), baru linkify URL & newline
    const safe = escapeHtml(text ?? '');

    // Match URL (http/https) sampai sebelum spasi atau karakter penutup tag
    const withLinks = safe.replace(/\bhttps?:\/\/[^\s<]+/gi, (url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" style="color:inherit; text-decoration:underline;">${url}</a>`;
    });

    return withLinks.replace(/\n/g, '<br>');
}

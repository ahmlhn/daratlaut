// Legacy functions that originally lived inline in chat/index.php.
// Kept as-is so legacy chat/app.js continues to work without UI refactors.

function __chatLogout() {
    // Laravel logout expects POST.
    fetch('/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .finally(() => { window.location.href = '/login'; });
}

function openSettings() {
    const modal = document.getElementById('modal-settings');
    if (modal) modal.style.display = 'flex';

    // Load existing settings
    fetch('admin_api.php?action=get_settings')
        .then(r => r.json())
        .then(d => {
            if (!d) return;
            const mode = document.getElementById('set-mode');
            const wa = document.getElementById('set-wa');
            const msg = document.getElementById('set-msg');
            const start = document.getElementById('set-start');
            const end = document.getElementById('set-end');
            if (mode) mode.value = d.mode;
            if (wa) wa.value = d.wa_number;
            if (msg) msg.value = d.wa_message;
            if (start) start.value = d.start_hour;
            if (end) end.value = d.end_hour;
            toggleScheduleInput();
        });
}

function toggleScheduleInput() {
    const mode = document.getElementById('set-mode')?.value;
    const box = document.getElementById('box-schedule');
    if (box) box.style.display = (mode === 'scheduled') ? 'block' : 'none';
}

function saveSettings() {
    const fd = new FormData();
    fd.append('action', 'save_settings');
    fd.append('mode', document.getElementById('set-mode')?.value || 'manual_on');
    fd.append('wa_number', document.getElementById('set-wa')?.value || '');
    fd.append('wa_message', document.getElementById('set-msg')?.value || '');
    fd.append('start_hour', document.getElementById('set-start')?.value || '08:00');
    fd.append('end_hour', document.getElementById('set-end')?.value || '17:00');

    const btn = document.querySelector('.btn-save');
    const oldText = btn ? btn.innerText : '';
    if (btn) btn.innerText = 'Menyimpan...';

    fetch('admin_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (btn) btn.innerText = oldText;
            if (d && d.status === 'success') {
                alert('Berhasil disimpan!');
                const modal = document.getElementById('modal-settings');
                if (modal) modal.style.display = 'none';
            } else {
                alert('Gagal: ' + ((d && d.msg) ? d.msg : 'Unknown error'));
            }
        })
        .catch(() => {
            if (btn) btn.innerText = oldText;
            alert('Gagal: koneksi bermasalah.');
        });
}

// Global click handlers to close dropdown popovers (native behavior).
if (!window.__chatGlobalClickSetup) {
    window.__chatGlobalClickSetup = true;
    window.addEventListener('click', function (event) {
        if (!event.target.closest('#dropdown-sidebar-container')) {
            const s = document.getElementById('sidebar-menu');
            if (s && !s.classList.contains('hidden')) s.classList.add('hidden');
        }
        if (!event.target.closest('#dropdown-chat-container')) {
            const c = document.getElementById('chat-menu');
            if (c && !c.classList.contains('hidden')) c.classList.add('hidden');
        }
        if (!event.target.closest('#dropdown-tpl-container')) {
            const t = document.getElementById('tpl-popup');
            if (t && !t.classList.contains('hidden')) t.classList.add('hidden');
        }
    });
}

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, interactive-widget=resizes-content">
    <title>Layanan Terhenti - Hubungi Admin</title>
    <base href="/direct/">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ef4444' stroke-width='2'%3E%3Cline x1='1' y1='1' x2='23' y2='23'%3E%3C/line%3E%3C/svg%3E">
    <style>
        /* BASE STYLES */
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; color: #334155; }

        .card { background: #ffffff; border-radius: 24px; box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.1); padding: 35px 30px; max-width: 420px; width: 100%; text-align: center; border: 1px solid #e2e8f0; position: relative; z-index: 1; }
        .brand-logo { max-width: 180px; height: auto; margin: 0 auto 20px; display: block; }
        h2 { font-size: 1.5rem; margin-bottom: 8px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; }
        .status-badge { display: inline-flex; align-items: center; background: #fef2f2; color: #ef4444; padding: 6px 14px; border-radius: 99px; font-size: 0.7rem; font-weight: 700; margin-bottom: 24px; text-transform: uppercase; border: 1px solid #fecaca; letter-spacing: 0.5px; }
        .info-box { background: #f8fafc; border: 1px dashed #cbd5e1; padding: 20px; border-radius: 16px; margin-bottom: 25px; text-align: left; font-size: 0.9rem; color: #64748b; line-height: 1.6; }
        .info-box p { margin: 0; }
        .info-box .info-main { text-align: center; }
        .info-box .info-note { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #cbd5e1; color: #475569; text-align: justify; text-justify: inter-word; text-align-last: left; }

        /* BUTTONS */
        .btn-chat-lokal { position: relative; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; padding: 18px 24px; border-radius: 16px; box-shadow: 0 8px 20px -5px rgba(37, 99, 235, 0.4); cursor: pointer; border: none; width: 100%; text-align: left; margin-bottom: 20px; transition: all 0.3s; animation: pulse-glow 3s infinite; }
        .btn-chat-lokal.btn-notify { background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%); box-shadow: 0 0 20px rgba(234, 88, 12, 0.6); animation: wiggle 0.5s ease-in-out infinite alternate; }
        .btn-chat-lokal:active { transform: scale(0.95); animation: none; }
        
        .chat-badge { position: absolute; top: -8px; right: -8px; background: #ef4444; color: white; min-width: 26px; height: 26px; border-radius: 50%; font-size: 12px; display: flex; align-items: center; justify-content: center; font-weight: 800; border: 3px solid #ffffff; opacity: 0; transform: scale(0); transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); z-index: 10; box-shadow: 0 4px 6px rgba(0,0,0,0.2); }
        .chat-badge.show { opacity: 1; transform: scale(1); }

        @keyframes pulse-glow { 0% { transform: scale(1); } 50% { transform: scale(1.02); } 100% { transform: scale(1); } }
        @keyframes wiggle { 0% { transform: rotate(-2deg); } 100% { transform: rotate(2deg); } }

        .footer-note { font-size: 0.8rem; color: #166534; margin-top: 10px; line-height: 1.5; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 12px; border-radius: 12px; }
        .visit-id { margin-top: 30px; font-size: 0.65rem; color: #cbd5e1; font-family: monospace; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.7; }
        .copyright { margin-top: 10px; padding-top: 15px; border-top: 1px solid #f1f5f9; font-size: 0.7rem; color: #94a3b8; font-weight: 600; }

        /* CHAT WINDOW */
        .chat-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; display: none; align-items: flex-end; justify-content: center; backdrop-filter: blur(5px); }
        .chat-overlay.active { display: flex; }
        
        .chat-window { 
            background: #f1f5f9; 
            width: 100%; 
            max-width: 100%; 
            height: 100%; 
            max-height: 100dvh; 
            border-radius: 20px 20px 0 0; 
            display: flex; 
            flex-direction: column; 
            overflow: hidden; 
            box-shadow: 0 -10px 40px rgba(0,0,0,0.3); 
            animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1); 
        }

        @media screen and (min-width: 640px) { 
            .chat-overlay { align-items: center !important; padding: 20px !important; }
            .chat-window { width: 380px !important; height: 80vh !important; max-height: 600px !important; min-height: 400px !important; border-radius: 18px !important; margin: auto !important; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important; animation: zoomIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) !important; } 
        }

        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        @keyframes zoomIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .chat-header { background: #ffffff; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; border-bottom: 1px solid #e2e8f0; }
        .chat-title h3 { font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0; }
        .chat-title p { font-size: 0.7rem; color: #22c55e; margin: 0; font-weight: 500; display: flex; align-items: center; gap: 4px; }
        .btn-close { cursor: pointer; background: #f1f5f9; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 18px; transition: all 0.2s; }
        .btn-close:hover { background: #fee2e2; color: #ef4444; }
        
        #view-room { flex: 1; display: flex; flex-direction: column; height: 100%; background: #e2e8f0; background-image: radial-gradient(#cbd5e1 1px, transparent 1px); background-size: 20px 20px; overflow: hidden; }
        
        .chat-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 15px; display: flex; flex-direction: column; gap: 8px; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        
        .msg { flex-shrink: 0; max-width: 80%; padding: 10px 14px; border-radius: 12px; font-size: 13px; line-height: 1.4; position: relative; word-wrap: break-word; box-shadow: 0 1px 2px rgba(0,0,0,0.08); animation: fadeIn 0.2s ease-out; }
        
        .msg-admin { align-self: flex-start; background: #ffffff; color: #1e293b; border-bottom-left-radius: 2px; }
        .msg-user { align-self: flex-end; background: #2563eb; color: #ffffff; border-bottom-right-radius: 2px; }
        .msg-time { display: block; font-size: 10px; margin-top: 4px; text-align: right; opacity: 0.7; font-weight: 500; }
        .msg-user .msg-time { color: rgba(255,255,255,0.8); }

        .chat-input-area { padding: 12px 16px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; align-items: flex-end; flex-shrink: 0; }
        .input-wrapper { position: relative; flex: 1; display: flex; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 20px; transition: all 0.2s; }
        .input-wrapper:focus-within { border-color: #2563eb; background: #ffffff; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        .msg-input { width: 100%; padding: 12px 14px 12px 48px; border: none; background: transparent; outline: none; font-size: 14px; line-height: 1.4; resize: none; overflow-y: hidden; min-height: 44px; max-height: 120px; color: #1e293b; border-radius: 20px; }
        .btn-attach-inner { position: absolute; left: 6px; bottom: 6px; width: 32px; height: 32px; border: none; background: transparent; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #64748b; cursor: pointer; transition: color 0.2s; }
        .btn-attach-inner:hover { color: #2563eb; background: rgba(37, 99, 235, 0.05); }
        .btn-send { background: #2563eb; color: white; border: none; width: 44px; height: 44px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3); transition: transform 0.1s; flex-shrink: 0; }
        .btn-send:active { transform: scale(0.95); }
        .btn-send:disabled, .btn-attach-inner:disabled { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
        .msg-input:disabled { opacity: 0.75; cursor: not-allowed; }

        #cust-toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-150%); background: #1e293b; color: white; padding: 12px 20px; border-radius: 50px; z-index: 2000; display: flex; align-items: center; gap: 12px; transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); cursor: pointer; width: 90%; max-width: 380px; font-weight: 500; font-size: 13px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); }
        #cust-toast.show { transform: translateX(-50%) translateY(0); }
    </style>
</head>
<body data-tenant-token="{{ $token }}">
    <audio id="sfx-in" src="assets/ding.mp3"></audio>
    <audio id="sfx-out" src="assets/pop.mp3"></audio>
    
    <div id="cust-toast" onclick="openLocalChat()">
        <div style="background:#22c55e; width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;"></div>
        <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Pesan baru dari Admin!</span>
    </div>

    <div class="card">
        <img src="logo billing.png" alt="Logo" class="brand-logo" onerror="this.style.display='none'">
        <h2 id="greeting">Akses Internet Dihentikan</h2>
        <div class="status-badge">Menunggu Pembayaran</div>
        <div class="info-box">
            <p class="info-main">Akses internet Anda saat ini dinonaktifkan sementara. Hubungi admin untuk konfirmasi pembayaran atau bantuan teknis.</p>
            <p class="info-note"><strong>Catatan:</strong> Jika sudah melakukan pembayaran dan internet belum aktif kembali, silahkan restart modem dengan cara cabut colok adaptor.</p>
        </div>
        
        <button class="btn-chat-lokal" onclick="openLocalChat()">
            <div>
                <div style="font-size: 1.1rem; font-weight: 800; line-height: 1.2; margin-bottom: 4px;">Klik Disini Bantuan & Konfirmasi</div>
                <div style="font-size: 0.8rem; font-weight: 500; opacity: 0.9;">Hubungi Admin (Bebas Kuota)</div>
            </div>
            <div class="btn-icon" style="background: rgba(255,255,255,0.2); width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                <svg style="width: 24px; height: 24px; fill: white;" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
            </div>
            <span id="chat-badge" class="chat-badge">0</span> 
        </button>

        <div id="footer-note" class="footer-note"><strong>Catatan:</strong> Tombol chat di atas dapat diakses meskipun kuota internet Anda habis.</div>
        
        <div class="visit-id">ID: <span id="disp-id">...</span></div>
        <div class="copyright">&copy; {{ date('Y') }} ISP System. All Rights Reserved.</div>
    </div>

    <div class="chat-overlay" id="chat-overlay">
        <div class="chat-window">
            <div class="chat-header">
                <div class="chat-title">
                    <h3>Layanan Pelanggan</h3>
                    <p><span style="width:8px; height:8px; background:#22c55e; border-radius:50%; display:inline-block;"></span> Online & Siap Membantu</p>
                </div>
                <button class="btn-close" type="button" onclick="closeLocalChat()" title="Sembunyikan chat">x</button>
            </div>
            
            <div id="view-room">
                <div class="chat-body" id="chat-messages"></div>
                <div class="chat-input-area">
                    <div class="input-wrapper">
                        <input type="file" id="img-input" accept="image/*" style="display: none;" onchange="sendImageClient()">
                        <button onclick="document.getElementById('img-input').click()" class="btn-attach-inner" title="Kirim Gambar">
                            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </button>
                        <textarea id="chat-input" class="msg-input" rows="1" placeholder="Ketik pesan..." onkeydown="handleEnter(event)" oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"></textarea>
                    </div>
                    <button class="btn-send" onclick="sendMsg()">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="img-viewer-client" style="position:fixed; inset:0; z-index:10000; background:rgba(0,0,0,0.95); display:none; flex-direction:column; justify-content:center; align-items:center; padding:10px; backdrop-filter:blur(5px);">
        <div style="position:absolute; top:20px; right:20px; display:flex; gap:15px; z-index:20;">
            <a id="img-dl-btn" href="#" download style="color:#cbd5e1; background:rgba(255,255,255,0.1); padding:8px; border-radius:50%;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            </a>
            <button onclick="closeImageViewer()" style="color:#fff; background:rgba(255,255,255,0.1); border:none; padding:8px; border-radius:50%; cursor:pointer;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <img id="img-view-src" src="" style="max-width:100%; max-height:90vh; border-radius:8px; object-fit:contain; box-shadow:0 10px 40px rgba(0,0,0,0.5);">
        <div onclick="closeImageViewer()" style="position:absolute; inset:0; z-index:10;"></div>
    </div>
    
    <script src="app.js?v={{ time() }}"></script>

</body>
</html>

from pathlib import Path
import re


def main() -> None:
    src = Path("chat/index.php").read_text(encoding="utf-8")

    start = src.find('<audio id="notif-sound"')
    if start == -1:
        raise SystemExit("cannot find notif-sound audio tag")

    end = src.find("<script", start)
    if end == -1:
        raise SystemExit("cannot find trailing script tag")

    body = src[start:end].rstrip()

    # Pull out the modal-settings inline <style> block (keep CSS but move to SFC style).
    modal_style_css = ""
    style_m = re.search(r"\n\s*<style>\s*(.*?)\s*</style>\s*\n", body, flags=re.S)
    if style_m:
        modal_style_css = style_m.group(1).strip() + "\n"
        body = body[: style_m.start()] + "\n\n" + body[style_m.end() :]

    # Normalize paths for Laravel public/
    body = body.replace("../assets/", "/assets/")
    body = body.replace("../dashboard.php", "/dashboard")
    body = body.replace("../login.php?action=logout", '#" onclick="__chatLogout()')

    # Replace PHP dynamic blocks with placeholders + IDs for Vue to fill.
    body = body.replace(
        "<?php echo strtoupper(substr($admin_name, 0, 1)); ?>",
        '<span id="adm-menu-letter">A</span>',
    )

    body = re.sub(
        r'(<div class="text-slate-800 dark:text-white font-bold truncate text-sm">)\s*<\?php echo htmlspecialchars\(\$admin_name\); \?>\s*(</div>)',
        r'\1<span id="adm-menu-name">Admin</span>\2',
        body,
    )

    body = re.sub(
        r'(<div class="text-\[10px\][^"]*">)\s*<\?php echo htmlspecialchars\(\$admin_role\); \?>\s*(</div>)',
        r'\1<span id="adm-menu-role">Staff</span>\2',
        body,
    )

    body = body.replace(
        "<?php echo htmlspecialchars($admin_first_name); ?>",
        '<span id="adm-first-name">Admin</span>',
    )

    # Remove any remaining php blocks just in case
    body = re.sub(r"<\?php.*?\?>", "", body, flags=re.S)

    sfc = f"""<script setup>
import {{ computed, onMounted, onBeforeUnmount }} from 'vue'
import {{ Head, usePage }} from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const page = usePage()

const adminName = computed(() => page.props.auth?.user?.name || page.props.auth?.user?.username || 'Admin')
const adminRole = computed(() => page.props.auth?.user?.role || 'Staff')
const adminFirst = computed(() => (String(adminName.value || 'Admin').trim().split(/\\s+/)[0]) || 'Admin')

function loadScriptOnce(src, id) {{
  return new Promise((resolve, reject) => {{
    if (document.getElementById(id)) return resolve()
    const s = document.createElement('script')
    s.id = id
    s.src = src + (src.includes('?') ? '&' : '?') + 'v=' + Date.now()
    s.async = true
    s.onload = () => resolve()
    s.onerror = (e) => reject(e)
    document.body.appendChild(s)
  }})
}}

function applyAdminPlaceholders() {{
  const letter = (adminName.value || 'Admin').trim().charAt(0).toUpperCase() || 'A'
  const elLetter = document.getElementById('adm-menu-letter')
  const elName = document.getElementById('adm-menu-name')
  const elRole = document.getElementById('adm-menu-role')
  const elFirst = document.getElementById('adm-first-name')
  if (elLetter) elLetter.textContent = letter
  if (elName) elName.textContent = adminName.value || 'Admin'
  if (elRole) elRole.textContent = adminRole.value || 'Staff'
  if (elFirst) elFirst.textContent = adminFirst.value || 'Admin'
}}

onMounted(async () => {{
  // Clean previous intervals when navigating back to chat.
  window.__chatDispose?.()

  await loadScriptOnce('/chat/inline.js', 'legacy-chat-inline')
  await loadScriptOnce('/chat/app.js', 'legacy-chat-app')
  await loadScriptOnce('/chat/game.js', 'legacy-chat-game')

  applyAdminPlaceholders()
  window.__chatBoot?.()
}})

onBeforeUnmount(() => {{
  window.__chatDispose?.()
}})
</script>

<template>
  <Head title="Chat Admin" />
  <AdminLayout>
    <div id="legacy-chat-root" class="h-[calc(100vh-0px)] w-full overflow-hidden bg-[#f8fafc] text-slate-600 dark:bg-darkbg dark:text-slate-300 transition-colors duration-300">
{body}
    </div>
  </AdminLayout>
</template>

<style>
/* Scoped-to-page (prefixed) CSS from native chat/index.php */
#legacy-chat-root {{
  height: 100%;
}}

#legacy-chat-root #main-app {{
  display: flex;
  width: 100%;
  height: 100%;
  position: relative;
  overflow: hidden;
}}

#legacy-chat-root .mobile-hidden {{ display: none !important; }}
@media (min-width: 768px) {{
  #legacy-chat-root .mobile-hidden {{ display: flex !important; }}
}}

@keyframes zoomIn {{
  from {{ transform: scale(0.95); opacity: 0; }}
  to {{ transform: scale(1); opacity: 1; }}
}}
#legacy-chat-root .animate-zoomIn {{ animation: zoomIn 0.2s ease-out forwards; }}

#legacy-chat-root .user-item-active {{ background-color: #eff6ff !important; border-left-color: #3b82f6 !important; }}
.dark #legacy-chat-root .user-item-active {{ background-color: #202c33 !important; border-left-color: #00a884 !important; }}

#legacy-chat-root .pb-safe {{ padding-bottom: env(safe-area-inset-bottom); }}

#legacy-chat-root .filter-btn-active {{ background-color: #eff6ff; color: #2563eb; border-color: #bfdbfe; }}
.dark #legacy-chat-root .filter-btn-active {{ background-color: #005c4b; color: #fff; border-color: #00a884; }}

@keyframes shatter-impact {{
  0% {{ transform: scale(0.5) rotate(0deg); opacity: 0; filter: hue-rotate(0deg); }}
  10% {{ transform: scale(1.2) rotate(-5deg) translate(-5px, -5px); opacity: 1; filter: hue-rotate(90deg) contrast(2); }}
  20% {{ transform: scale(0.9) rotate(5deg) translate(5px, 5px); filter: hue-rotate(-90deg) contrast(2); }}
  30% {{ transform: scale(1.05) rotate(-3deg) translate(-3px, 3px); }}
  40% {{ transform: scale(0.95) rotate(3deg) translate(3px, -3px); }}
  50% {{ transform: scale(1) rotate(-1deg) translate(-1px, 1px); }}
  60% {{ transform: scale(1) rotate(1deg) translate(1px, -1px); }}
  100% {{ transform: scale(1) rotate(0deg) translate(0, 0); opacity: 1; }}
}}

#legacy-chat-root .animate-shatter {{
  animation: shatter-impact 0.6s cubic-bezier(.36,.07,.19,.97) both;
  color: #dc2626;
  filter: drop-shadow(0 0 15px rgba(220, 38, 38, 0.8));
}}

#legacy-chat-root .animate-shatter::before,
#legacy-chat-root .animate-shatter::after {{
  content: '';
  position: absolute;
  inset: 0;
  background: inherit;
  pointer-events: none;
  opacity: 0.5;
}}

#legacy-chat-root .animate-shatter::before {{
  animation: shatter-impact 0.6s cubic-bezier(.36,.07,.19,.97) both reverse;
  transform: translate(-2px, -2px);
  color: #ef4444;
  mix-blend-mode: hard-light;
}}

/* Modal-settings CSS (from native inline <style>) */
{modal_style_css}
</style>
"""

    Path("backend-laravel/resources/js/Pages/Chat/Index.vue").write_text(sfc, encoding="utf-8")
    print("wrote backend-laravel/resources/js/Pages/Chat/Index.vue")


if __name__ == "__main__":
    main()


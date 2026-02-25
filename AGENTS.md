# AGENTS.md

This file is the always-read context for AI sessions inside `backend-laravel`.

Last updated: 2026-02-25
Last verified: 2026-02-25

## Update policy (MUST)
- After any code change in `backend-laravel`, update this file and related docs in the same task.
- Add a short entry in `Change log` with date and summary.
- If the change is trivial and no doc update is needed, write `docs: N/A`.
- Keep this file concise and practical.

## Project scope
- Laravel admin panel and bridge for native apps (chat/direct/ops modules).
- Multi-tenant model using shared DB with `tenant_id` scoping.

## Stack
- PHP `^8.2`, Laravel `^11`, Sanctum, Inertia Laravel.
- Frontend: Vue 3 + Inertia + Vite + Tailwind.
- Main permissions package: `spatie/laravel-permission`.

## Local run
1. `cd backend-laravel`
2. `composer install`
3. `cp .env.example .env` and fill DB/app settings.
4. `php artisan key:generate`
5. `php artisan migrate` (if needed).
6. `npm install`
7. `npm run dev` (or `npm run build` for production assets).
8. `php artisan serve`

## Key entry points
- Web routes: `routes/web.php`
- API routes: `routes/api.php`
- Web controllers: `app/Http/Controllers/Web/*`
- API controllers: `app/Http/Controllers/Api/V1/*`
- Inertia pages: `resources/js/Pages/*`
- Legacy bridges: `public/legacy-chat/*`, `public/legacy-direct/*`

## Chat Admin bridge quick notes
- Endpoint compatibility: `GET/POST /chat/admin_api.php`
- Router target: `App\Http\Controllers\Web\ChatAdminApiController@handle`
- UI shell: `resources/js/Pages/Chat/Index.vue`
- Legacy client logic: `public/legacy-chat/app.js`
- Polling intervals in bridge script:
  - Customer overview: `8000ms`
  - Contacts/messages: `3000ms`

## Validation baseline
- Run `php -l` for changed PHP files.
- Run `node --check` for changed legacy JS files.
- Run `php artisan test` when touching business logic where feasible.

## Change log
- 2026-02-25: Scheduler cron OLT kini mengikuti timezone aplikasi dari env bawaan `APP_TIMEZONE` (via `config('app.timezone')`) di `routes/console.php`; event tenant tetap memakai jam `olt_time` dari Cron Scheduler, dan fallback global tetap memakai `OLT_DAILY_SYNC_TIME` dengan timezone yang sama. `OLT_SCHEDULE_TIMEZONE` dihapus dari `.env.example`. Docs: N/A.
- 2026-02-25: Perbaikan reliabilitas cron OLT untuk penyimpanan Rx history. Command `olt:queue-daily-sync` menambah opsi `--sync` (eksekusi langsung tanpa queue worker), scheduler OLT tenant/fallback di `routes/console.php` kini menjalankan command dengan `--sync`, dan logging cron membedakan mode `sync` vs `queued` beserta jumlah sukses/gagal per tenant. Docs: `docs/OLT.md`, `docs/RUNBOOK.md`, `docs/CODEMAP.md`.
- 2026-02-25: Modal detail ONU OLT (`resources/js/Pages/Olts/Index.vue`) kini memakai layout `header + body scroll + footer tetap`; tombol aksi (`Edit Nama`, `Refresh`, `Restart`, `Hapus ONU`) dipindah ke footer modal agar tidak ikut terscroll saat isi detail/histori panjang. Docs: N/A.
- 2026-02-25: Histori Rx pada modal detail ONU OLT (`resources/js/Pages/Olts/Index.vue`) disederhanakan menjadi tampilan tabel saja (tanpa card mobile), ditambah pagination `Prev/Next` dan dropdown jumlah baris (`10/20/50/100`) untuk kontrol data yang ditampilkan per halaman. Docs: `docs/OLT.md`.
- 2026-02-25: Fix modal detail ONU OLT agar benar-benar berdiri sendiri dari scroll halaman: modal kini dirender via `Teleport` ke `body` di `resources/js/Pages/Olts/Index.vue`, sehingga `position: fixed` tidak lagi ikut konteks container `fade-in` (transform). Docs: N/A.
- 2026-02-25: Penyesuaian modal detail ONU OLT (`resources/js/Pages/Olts/Index.vue`): hilangkan backdrop hitam transparan (overlay jadi transparan) dan posisi modal dibuat center-screen konsisten di mobile/desktop agar tidak bergeser ke bawah. Docs: N/A.
- 2026-02-25: UI detail ONU pada tabel registered OLT (`resources/js/Pages/Olts/Index.vue`) diubah dari inline expand menjadi popup modal. Klik baris ONU kini membuka modal detail (info ONU, histori Rx, edit nama, refresh/restart/hapus), dan modal ditutup saat ganti filter/FSP/search/OLT agar state tetap konsisten. Docs: `docs/OLT.md`.
- 2026-02-25: UI detail ONU OLT (`resources/js/Pages/Olts/Index.vue`) memperbaiki responsivitas card Histori Rx di mobile: tombol periode jadi grid 3 kolom, ringkasan metrik jadi chip grid, dan daftar histori memakai kartu vertikal pada layar kecil (tabel tetap untuk desktop). Docs: N/A.
- 2026-02-25: Load manual data OLT (`GET /api/v1/olts/{id}/registered?fsp=...` dan `POST /api/v1/olts/{id}/registered-all`) kini ikut menyimpan Rx ke DB: refresh cache `noci_olt_onu.rx_power` dan insert snapshot histori ke `noci_olt_rx_logs` saat hasil telnet tersedia. Docs: `docs/OLT.md`.
- 2026-02-25: Optimasi performa load `Semua SFP` di halaman OLT. `resources/js/Pages/Olts/Index.vue` kini melakukan merge data per-FSP secara batch (single assignment) untuk mengurangi recompute/sort berulang pada data ONU besar, dan `app/Http/Controllers/Api/OltController.php` mengganti agregasi `array_merge` berulang dengan append loop pada endpoint `registered-all`. Docs: N/A.
- 2026-02-25: Scheduler OLT `olt:queue-daily-sync` diubah jadi harian (1x sehari) mengikuti `olt_time` tenant (jam server), termasuk fallback env `OLT_DAILY_SYNC_TIME`; UI Cron Scheduler diperbarui agar menjelaskan eksekusi harian. Mode log job disesuaikan ke `scheduled_daily`. Docs: `AGENTS.md`, `docs/OLT.md`, `docs/RUNBOOK.md`, `docs/CODEMAP.md`.
- 2026-02-25: Scheduler OLT `olt:queue-daily-sync` sekarang menyamakan sinkron Rx dan sinkron nama ONU: setiap eksekusi periodik 6 jam (`olt_time`) job tidak hanya simpan snapshot Rx tetapi juga deep refresh nama/detail ONU dari OLT (`show gpon onu detail-info`) sehingga cache nama ikut terbarui 4x/hari. Log OLT job menambah metrik `name_sync_*`. Docs: `AGENTS.md`, `docs/OLT.md`, `docs/RUNBOOK.md`.
- 2026-02-25: Cron Scheduler OLT Laravel di `Settings -> Cron Scheduler` kini kembali memakai `olt_time` sebagai jam start sinkron periodik 6 jam (jam server). `routes/console.php` menghitung cron per-tenant dari `noci_cron_settings.olt_time` (fallback env `OLT_DAILY_SYNC_TIME`), dan UI `resources/js/Pages/Settings/Index.vue` mengaktifkan input jam OLT + preview slot 6 jam. Docs: `AGENTS.md`, `docs/OLT.md`, `docs/RUNBOOK.md`, `docs/CODEMAP.md`.
- 2026-02-25: OLT Laravel menambahkan histori Rx power ONU: migration tabel `noci_olt_rx_logs`, sinkronisasi scheduler kini menyimpan snapshot Rx + update `noci_olt_onu.rx_power`, endpoint baru `GET /api/v1/olts/{id}/onu-rx-history`, UI detail ONU menambah tombol periode (`24 Jam/7 Hari/30 Hari`) + tabel histori Rx, serta jadwal OLT scheduler di `routes/console.php` diubah fixed jam server `00/06/12/18` dengan retensi otomatis 90 hari (1x/hari/tenant). Docs: `AGENTS.md`, `docs/OLT.md`, `docs/RUNBOOK.md`, `docs/CODEMAP.md`.
- 2026-02-19: Created `backend-laravel/AGENTS.md` as local AI working context and maintenance policy. Docs: AGENTS.md.
- 2026-02-19: Audited live finance schema, aligned finance controller/models to DB baseline (`noci_fin_*`), and added backend finance permission enforcement. Docs: `docs/finance-schema-audit-2026-02-19.md`.
- 2026-02-19: Updated Chat Admin customer overview desktop layout to 50:50 columns for `Log Pelanggan` and `Tren Kunjungan Isolir`. Docs: N/A.
- 2026-02-19: Upgraded Vite toolchain (`vite` to v7 and `laravel-vite-plugin` to v2) and validated production build. Docs: N/A.
- 2026-02-19: Upgraded related frontend packages (`@inertiajs/vue3`, `vue`, `axios`, `tailwindcss`, `@tailwindcss/postcss`) and validated production build. Docs: N/A.
- 2026-02-19: Fixed Vite build warning for `/assets/favicon.svg` by moving Chat Admin empty-state background image URL out of Tailwind arbitrary CSS URL. Docs: N/A.
- 2026-02-20: Fixed first-message WA chat notification path in Direct API by using `WaGatewaySender` (no `mysqli` dependency), added safer legacy fallback, and aligned HTTP SSL behavior with legacy gateway compatibility. Docs: N/A.
- 2026-02-20: Preserved `Selesai` status during Direct `start_session` so customer messages after closed sessions trigger WA admin notification, then transition to `Menunggu` on send. Docs: N/A.
- 2026-02-20: Added `balesotomatis` personal-send support in `WaGatewaySender` and updated Direct WA notify flow to try active providers (`mpwa` then `balesotomatis`) before legacy fallback, fixing missed WA alerts on reopened sessions. Docs: N/A.
- 2026-02-20: Adjusted Chat Admin desktop detail-sidebar behavior so it is hidden by default and only shown when toggled, preventing customer detail panel from occupying/covering conversation area by default. Docs: N/A.
- 2026-02-19: Made Chat Admin detail sidebar width responsive (`clamp` + flex-basis) so it adapts when main admin sidebar expands/collapses instead of staying fixed width. Docs: N/A.
- 2026-02-19: Fixed Chat Admin session delete UX/data sync by clearing deleted contact from local caches, forcing full contacts refresh after delete, and invalidating backend contacts cache on `delete_session`. Docs: N/A.
- 2026-02-19: Fixed Quick Action `Hapus` reliability in Chat Admin by binding sidebar delete button via JS listener and adding robust visit-id fallback/guard in `deleteSession`. Docs: N/A.
- 2026-02-20: Changed Direct welcome-message flow: no DB insert on `start_session`, render welcome client-side while chat empty, and persist welcome into `noci_chat` only when customer sends first message. Docs: N/A.
- 2026-02-20: Normalized Finance page date formatting with resilient frontend parser (`YYYY-MM-DD` and ISO), rendered transaction dates as `dd/MM/yyyy`, and aligned date inputs to `lang=id-ID`. Docs: N/A.
- 2026-02-20: Updated Finance transaction modal amount inputs (`Debit`/`Credit`) to live-format thousand separators (`10.000`, `100.000`) while preserving numeric values for totals and API payload. Docs: N/A.
- 2026-02-20: Updated Finance transaction modal so `Metode` uses dropdown options and `Pihak Terkait` shows live suggestion list on focus/type from existing tenant transaction lines. Docs: N/A.
- 2026-02-20: Updated Finance transaction list UX: removed inline action column, made rows clickable to open transaction detail modal, and moved `Edit`/`Hapus` actions into detail modal. Docs: N/A.
- 2026-02-20: Normalized Finance transaction detail `Cabang` display to handle object/JSON-string payloads and always render readable branch name/code. Docs: N/A.
- 2026-02-20: Hidden empty chat sessions in Chat Admin contacts by requiring at least one active `noci_chat` row before listing a customer (prevents start-session-only visits from appearing as blank conversations). Docs: N/A.
- 2026-02-20: Added Direct chat presence heartbeat (`action=heartbeat`) from customer page and tightened admin online badge threshold to 30 seconds inactivity (otherwise offline). Docs: N/A.
- 2026-02-20: Set Chat Admin customer detail sidebar to open by default on desktop boot while keeping mobile default hidden. Docs: N/A.
- 2026-02-20: Changed Chat Admin `Leads Baru` overview metric to count customers with at least one user-sent chat message (first user message in selected period), not page visitors/`last_seen`. Docs: N/A.
- 2026-02-20: Improved Chat Admin customer-overview update animations; animate only changed stats/log rows/chart payloads and skip animation/re-render when data snapshot is unchanged. Docs: N/A.
- 2026-02-20: Widened Chat Admin `Tren Kunjungan Isolir` overview column on desktop by changing log/trend grid ratio from equal split to weighted width. Docs: N/A.

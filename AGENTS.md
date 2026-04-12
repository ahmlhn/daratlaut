# AGENTS.md

This file is the always-read context for AI sessions inside `backend-laravel`.

Last updated: 2026-04-12
Last verified: 2026-04-12

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
- 2026-04-13: Preview redaman manual register kini memakai `onu_id` asli dari hasil `show gpon onu uncfg`; `app/Services/OltService.php` memperkaya parser `scanUnconfigured()` dengan `onu_id/interface`, endpoint preview redaman di `app/Http/Controllers/Api/OltController.php` menerima `onu_id`, dan `resources/js/Pages/Olts/Index.vue` mengirim `onu_id` hasil scan saat membuka modal registrasi manual sehingga tidak perlu lagi menghitung kandidat ID kosong untuk cek redaman. Docs: N/A.
- 2026-04-13: Modal registrasi manual ONU kini menampilkan preview redaman; `app/Services/OltService.php` menambah parser command `show pon power attenuation gpon-onu_{fsp}:{onu_id}`, `app/Http/Controllers/Api/OltController.php` + `routes/api.php` menambah endpoint preview redaman unregistered berbasis kandidat `onu_id`, dan `resources/js/Pages/Olts/Index.vue` memuat serta menampilkan nilai upstream/downstream attenuation saat modal manual register dibuka. Docs: N/A.
- 2026-04-13: Label kecil `Registrasi Manual` di header modal registrasi ONU dihapus agar modal lebih bersih; `resources/js/Pages/Olts/Index.vue` kini hanya menampilkan judul utama `Registrasi ONU`. Docs: N/A.
- 2026-04-13: Registrasi manual ONU di halaman OLT kini memakai popup modal; `resources/js/Pages/Olts/Index.vue` mengubah aksi pilih/baris `ONU Unregistered` menjadi pembuka modal registrasi, memindahkan input nama ONU dan progres registrasi ke modal, dan menghapus panel inline registrasi dari card utama. Docs: N/A.
- 2026-04-13: Alur `Auto Register` di halaman OLT tidak lagi memakai popup browser `confirm/prompt`; `resources/js/Pages/Olts/Index.vue` menambah modal modern untuk konfirmasi dan input prefix nama ONU sebelum memulai queue auto-register, lalu tetap melanjutkan ke modal progres yang sudah ada. Docs: N/A.
- 2026-04-13: Auto register OLT kini memakai hasil scan terakhir yang tampil di UI, bukan scan ulang ke OLT; `resources/js/Pages/Olts/Index.vue` mengirim daftar `uncfg` aktif ke endpoint queue, dan `app/Http/Controllers/Api/OltController.php` menerima payload `items` untuk langsung dipakai sebagai sumber batch auto-register dengan fallback scan hanya bila payload tidak tersedia. Docs: N/A.
- 2026-04-13: UI `Pilih OLT` dirapikan agar lebih efisien; `resources/js/Pages/Olts/Index.vue` menaruh dropdown dan tombol edit ikon dalam satu baris, menonaktifkan edit saat belum ada OLT terpilih, menyimpan OLT terakhir ke `localStorage`, menyederhanakan info OLT terpilih, dan tetap menyembunyikan area aksi sampai OLT aktif dipilih. Docs: N/A.
- 2026-04-13: Label judul `OLT Aktif` pada panel pilihan OLT dihapus untuk merapikan header card; `resources/js/Pages/Olts/Index.vue` kini hanya menampilkan info OLT terpilih tanpa heading tambahan. Docs: N/A.
- 2026-04-13: Workspace OLT disederhanakan lagi ke gaya admin yang lebih ringkas; `resources/js/Pages/Olts/Index.vue` menghapus helper text, subtitle, dan petunjuk non-esensial pada area aksi dan daftar `ONU Unregistered`, sehingga yang tersisa hanya kontrol inti, status proses, form registrasi, dan tabel. Docs: N/A.
- 2026-04-13: Ringkasan tile `ONU Unregistered / Mode / Status` di workspace OLT dihapus agar card utama lebih ringkas; `resources/js/Pages/Olts/Index.vue` menggantinya dengan helper text singkat di area aksi tanpa mengubah alur scan/register/write. Docs: N/A.
- 2026-04-13: Layout OLT disederhanakan menjadi satu card utama; `resources/js/Pages/Olts/Index.vue` tidak lagi memisahkan workspace OLT dan daftar `ONU Unregistered` ke dua card terpisah, melainkan menyusun info OLT aktif, tombol aksi, status, registrasi manual, dan daftar ONU dalam satu alur card yang lebih ringkas. Docs: N/A.
- 2026-04-13: Card `Pilih OLT` di halaman OLT diperluas menjadi `OLT Active Workspace`; `resources/js/Pages/Olts/Index.vue` memindahkan aksi `Scan ONU Baru`, `Auto Register`, `Write/Simpan Config`, ringkasan jumlah ONU unregistered, dan status proses ke card atas, sementara card `ONU Unregistered` kini difokuskan sebagai area hasil scan dan registrasi manual. Docs: N/A.
- 2026-04-13: UI aksi OLT di halaman provisioning disederhanakan dengan pola `simple admin`; `resources/js/Pages/Olts/Index.vue` memindahkan `Tambah OLT` menjadi CTA utama di header halaman dan menjadikan `Edit OLT Aktif` sebagai aksi sekunder kontekstual pada panel OLT terpilih. Docs: N/A.
- 2026-04-12: Modal tambah/edit OLT di halaman OLT dirapikan untuk desktop; `resources/js/Pages/Olts/Index.vue` kini merender modal via `Teleport` ke `body`, memakai wrapper fullscreen flex-center, panel max-height dengan body scroll sendiri, serta header/footer yang tetap rapi saat form lebih panjang. Docs: N/A.
- 2026-04-11: Selesai auto register ONU kini memakai opsi tanpa auto-refresh tabel `ONU Registered`; `resources/js/Pages/Olts/Index.vue` tidak lagi memaksa filter ke `all` atau memuat ulang semua FSP setelah proses selesai, sehingga hasil registered tetap menunggu refresh manual user. Docs: N/A.
- 2026-04-11: Popup auto register ONU kini mempertahankan status akhir di modal sampai ditutup manual user; `resources/js/Pages/Olts/Index.vue` menambah mode akhir `success/info/error`, tombol `Tutup`, dan menampilkan hasil akhir seperti sukses, sebagian gagal, atau `Tidak ada ONU unregistered` tetap di popup. Docs: N/A.
- 2026-04-11: Layout popup progres auto register ONU dirapikan untuk desktop dengan render via `Teleport` ke `body` dan panel center-screen berbasis flex/fullscreen agar tidak ikut konteks layout halaman; `resources/js/Pages/Olts/Index.vue` juga memperbarui spacing, ukuran panel, dan progress card popup. Docs: N/A.
- 2026-04-11: Progres auto register ONU queue di halaman OLT kini tampil sebagai popup modal agar status proses lebih jelas; `resources/js/Pages/Olts/Index.vue` menambah modal progres khusus auto-register, update persen/batch/sisa ONU saat polling, dan menutup modal saat run selesai/gagal atau saat ganti halaman/OLT. Docs: N/A.
- 2026-04-11: Auto register ONU unregistered kini pindah ke queue worker agar request HTTP tidak putus: `POST /api/v1/olts/{id}/auto-register` sekarang hanya enqueue batch 10 ONU per job, job baru `app/Jobs/AutoRegisterOnuBatchJob.php` reconnect ke OLT di tiap batch dan update progres pada `noci_olt_logs`, `GET /api/v1/olts/{id}/auto-register-status` dipakai polling, `resources/js/Pages/Olts/Index.vue` menampilkan progres queue + resume polling dari log aktif, dan queue memakai env worker OLT yang sudah ada (`OLT_DAILY_SYNC_QUEUE_CONNECTION` / `OLT_DAILY_SYNC_QUEUE`). Docs: N/A.
- 2026-04-11: Auto register ONU unregistered di halaman OLT kini diproses bertahap per 10 ONU untuk menghindari timeout saat jumlah besar; `app/Http/Controllers/Api/OltController.php` menerima batch `items`/`batch_size` dan `resources/js/Pages/Olts/Index.vue` menjalankan loop batch berurutan dengan progres serta pengurangan daftar ONU per batch. Docs: N/A.
- 2026-04-07: Queue Ops dan OLT menambah retry otomatis: job OLT/reminder/closing memakai `tries=3` dengan backoff 5 menit lalu 15 menit, command Ops mengembalikan exit code gagal jika seluruh kandidat pesan gagal agar queue worker melakukan retry, dan Settings menampilkan command worker dengan `--tries=3`. Docs: N/A.
- 2026-04-07: Cron Ops (`ops:send-reminders` dan `ops:nightly-closing`) dipindahkan ke queue worker: scheduler kini memanggil command dispatcher `ops:queue-reminders` / `ops:queue-nightly-closing`, job `RunOpsRemindersJob` dan `RunNightlyOpsJob` menjalankan command lama di queue `ops-cron`, Settings menampilkan command worker Ops, `CronExecutionLogger` mendukung status `queued`, dan `.env.example` menambah `OPS_CRON_QUEUE_CONNECTION` + `OPS_CRON_QUEUE`. Docs: N/A.
- 2026-04-07: Dispatch sinkron OLT terjadwal kini men-skip enqueue duplikat jika masih ada job `SyncOltRegisteredDailyJob` pending/processing untuk `tenant_id + olt_id` yang sama di queue database tujuan, sehingga uji coba atau jadwal berulang tidak menumpuk lebih dari satu job per OLT. Docs: N/A.
- 2026-04-06: Log cron mode queue untuk sinkron OLT kini memakai status `queued` saat dispatch berhasil penuh, bukan `success`: `app/Console/Commands/QueueDailyOltSync.php` mengubah status log tenant queued, `app/Http/Controllers/Api/V1/SettingsController.php` menambah dukungan status/statistik `queued` pada data Cron Scheduler, dan `resources/js/Pages/Settings/Index.vue` menampilkan filter, badge, dan kartu statistik `queued` di panel log cron. Docs: N/A.
- 2026-04-06: Panel Cron Scheduler di Settings kini juga menampilkan snapshot queue worker OLT saat ini: `app/Http/Controllers/Api/V1/SettingsController.php` menambah `olt_sync_queue_items` dan `olt_sync_queue_stats` dari tabel `jobs` (status `queued/processing` berdasarkan `reserved_at`), dan `resources/js/Pages/Settings/Index.vue` menampilkan ringkasan serta tabel antrian worker OLT di panel yang sama dengan hasil final `sync_daily`. Docs: N/A.
- 2026-04-06: Cron Scheduler di halaman Settings kini menampilkan hasil job sinkron OLT per OLT: `app/Http/Controllers/Api/V1/SettingsController.php` menambah data/endpoint `cron_olt_options` dan `olt_sync_logs` dari `noci_olt_logs` (`action=sync_daily`) beserta stats `done/error`, `routes/api.php` menambah route `GET /api/v1/settings/cron/olt-sync-logs`, dan `resources/js/Pages/Settings/Index.vue` menambah tabel/filter khusus hasil `sync_daily` per OLT di panel Cron Scheduler. Docs: N/A.
- 2026-04-06: Job sinkron OLT terjadwal kini dipaksa bergantian di level queue worker: `app/Jobs/SyncOltRegisteredDailyJob.php` menambah middleware `WithoutOverlapping` dengan lock global bersama agar hanya satu job sync OLT aktif pada satu waktu walau ada lebih dari satu worker pada queue `olt-sync`. Docs: N/A.
- 2026-04-04: Tambah command repair `olt:rebind-history` untuk audit dan memindahkan histori/log OLT dari `olt_id` lama ke `olt_id` baru setelah OLT dihapus lalu dibuat ulang; command menampilkan hitungan histori, overlap SN, dan job queue stale dalam mode dry-run sebelum eksekusi. Docs: N/A.
- 2026-04-04: Sinkron OLT terjadwal direfaktor agar benar-benar lewat queue worker: `routes/console.php` tidak lagi menjadwalkan `--sync` dan kini meneruskan `--connection` (default env `OLT_DAILY_SYNC_QUEUE_CONNECTION`, fallback `database`); `app/Console/Commands/QueueDailyOltSync.php` menambah opsi `--connection`, memaksa dispatch ke connection async non-`sync`, dan memvalidasi koneksi/queue table sebelum enqueue; `app/Http/Controllers/Api/V1/SettingsController.php` + `resources/js/Pages/Settings/Index.vue` menampilkan instruksi command queue worker OLT; `.env.example` menambah `OLT_DAILY_SYNC_QUEUE_CONNECTION=database`. Docs: N/A.
- 2026-04-04: Fitur sinkron OLT saat akses halaman dihapus dari codebase: alias middleware `olt.daily.sync`, `app/Http/Middleware/TriggerOltDailySyncOnAccess.php`, dan `app/Services/OltDailySyncDispatcher.php` dihapus; `routes/console.php` kini hanya memakai `OLT_DAILY_SYNC_SCHEDULE_ENABLED` untuk fallback global scheduler; `.env.example` menghapus env `OLT_DAILY_SYNC_ON_ACCESS`. Docs: N/A.
- 2026-04-04: Modal detail ONU OLT tidak lagi langsung menampilkan histori Rx saat dibuka; `resources/js/Pages/Olts/Index.vue` kini default menutup panel histori dan hanya memuat/menampilkan data saat tombol `Riwayat Rx` diklik, serta refresh detail hanya me-refresh histori bila panel tersebut sedang terbuka. Docs: N/A.
- 2026-04-03: Detail ONU OLT kini ikut menyimpan snapshot Rx ke histori saat endpoint `GET /api/v1/olts/{id}/onu-detail` dipanggil: `app/Services/OltService.php` menambah penyimpanan sample tunggal ke `noci_olt_rx_logs` setelah Rx berhasil dibaca, dan `resources/js/Pages/Olts/Index.vue` memaksa refresh detail + histori setiap modal detail ONU dibuka agar sample baru benar-benar tersimpan dan langsung terlihat. Docs: N/A.
- 2026-03-17: Group Rekap kini terhubung ke POP teknisi: `app/Http/Controllers/Api/V1/SettingsController.php` mewajibkan `pop_id` saat menyimpan group rekap (jika kolom tersedia), menjaga satu default group per POP, dan sinkron ke `noci_pops.group_id`; `resources/js/Pages/Settings/Index.vue` menambah dropdown POP pada form Group Rekap serta kolom POP di tabel; `app/Http/Controllers/Api/V1/TeknisiController.php` menentukan default group laporan berdasarkan POP teknisi (`noci_team.pop_id`/`noci_users.default_pop` -> mapping POP) sebelum fallback ke `noci_conf_wa`; `resources/js/Pages/Teknisi/Rekap.vue` menampilkan label POP pada opsi grup kirim laporan. Docs: N/A.
- 2026-03-17: Rekap Teknisi kini mendukung auto-select grup laporan berbasis pengaturan default: endpoint `GET /api/v1/teknisi/rekap` mengembalikan `default_group_input` dari `noci_conf_wa.recap_group_id/group_id`, dan `resources/js/Pages/Teknisi/Rekap.vue` memprioritaskan default ini saat modal kirim laporan dibuka serta memakainya sebagai fallback kirim bila grup belum dipilih manual. Docs: N/A.
- 2026-03-17: Fitur kirim laporan grup pada Rekap Teknisi diperkuat: route Settings untuk `GET/POST /api/v1/settings/recap-groups` ditambahkan agar manajemen grup rekap berfungsi, endpoint `POST /api/v1/teknisi/rekap/send` kini mendukung fallback group dari `noci_conf_wa` (`recap_group_id`/`group_id`) + normalisasi format ID grup (`@g.us`) + akses permission `send teknisi recap` atau `edit teknisi`, dan modal kirim di `resources/js/Pages/Teknisi/Rekap.vue` auto-pilih grup pertama serta menyediakan input manual saat daftar grup kosong. Docs: N/A.
- 2026-03-04: Perbaikan modul System Update: `app/Http/Controllers/Web/SystemUpdateController.php` kini menghormati toggle `SYSTEM_UPDATE_ENABLED` yang aman untuk config cache dan mengizinkan akses berbasis permission (`manage system update` / `manage settings` / `manage roles`) selain role legacy; `app/Services/SystemUpdateService.php` menonaktifkan batas waktu eksekusi untuk tahap update berat (`download/start/step/finalize`) agar prepare/copy paket besar tidak mudah timeout. Docs: N/A.
- 2026-03-04: Laravel Pulse ditambahkan ke sistem: dependency `laravel/pulse` + migration Pulse tables dipublish, route `/pulse` diamankan dengan `auth` dan gate `viewPulse` berbasis role/permission user `NociUser`, sidebar admin menambah menu Pulse full-page, dan dashboard Pulse dikustom ringan dengan layout full-width + tombol kembali ke dashboard. Docs: `docs/PULSE.md`.
- 2026-03-04: Kompatibilitas dropdown POP diperbaiki untuk schema lama yang belum punya kolom `noci_pops.is_active`; `app/Models/Pop.php` kini hanya memfilter aktif jika kolom tersedia, dan `app/Http/Controllers/Api/V1/PopController.php` mengembalikan fallback `is_active=1` agar edit Tim tetap bisa memuat POP teknisi dari DB lama. Docs: N/A.
- 2026-03-04: Halaman Tim kini memuat dropdown POP edit dari DB dengan daftar POP lengkap termasuk POP nonaktif; `resources/js/Pages/Team/Index.vue` menunggu opsi POP termuat dan menormalisasi `pop_id`, sedangkan `app/Http/Controllers/Api/V1/PopController.php` menambah dukungan query `include_inactive=1` pada endpoint dropdown. Docs: N/A.
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

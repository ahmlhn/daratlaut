# Pulse

## Ringkas
- Package: `laravel/pulse`
- Dashboard: `/pulse`
- Auth: user login + gate `viewPulse`
- Akses default: legacy role `admin` / `owner`, atau permission `manage settings` / `manage roles`

## Yang Dipasang
- Dependency Composer `laravel/pulse`
- Config `config/pulse.php`
- Migration Pulse tables di `database/migrations/*create_pulse_tables.php`
- Dashboard override di `resources/views/vendor/pulse/dashboard.blade.php`

## Operasional
- Jalankan migration Pulse:
  - `php artisan migrate --path=database/migrations/2026_03_04_233634_create_pulse_tables.php`
- Untuk metrik server (`CPU`, `RAM`, `disk`), jalankan daemon:
  - `php artisan pulse:check`
- Saat deploy dan ada proses `pulse:check` aktif, restart dengan:
  - `php artisan pulse:restart`

## Catatan
- Recorder lain seperti slow requests, slow queries, queues, dan exceptions akan mulai mengumpulkan data tanpa konfigurasi tambahan.
- Kartu `Servers` akan kosong jika `pulse:check` belum dijalankan di server aplikasi.

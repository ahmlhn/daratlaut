# AGENTS.md

This file is the always-read context for AI sessions inside `backend-laravel`.

Last updated: 2026-02-19
Last verified: 2026-02-19

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

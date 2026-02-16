<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\PublicRedirectLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicRedirectController extends Controller
{
    public function link(Request $request, string $code): RedirectResponse
    {
        if (!$this->canUseRedirectTables()) {
            abort(404);
        }

        $tenantId = $this->resolveTenantIdByHost($request);
        if ($tenantId <= 0) {
            abort(404);
        }

        return $this->redirectByTenantAndCode($request, $tenantId, $code);
    }

    public function linkWithToken(Request $request, string $tenantToken, string $code): RedirectResponse
    {
        if (!$this->canUseRedirectTables()) {
            abort(404);
        }

        $tenantId = $this->resolveTenantIdByToken($tenantToken);
        if ($tenantId <= 0) {
            abort(404);
        }

        return $this->redirectByTenantAndCode($request, $tenantId, $code);
    }

    // Legacy path support: /go/{tenantToken}/{code}
    public function go(Request $request, string $tenantToken, string $code): RedirectResponse
    {
        return $this->linkWithToken($request, $tenantToken, $code);
    }

    private function canUseRedirectTables(): bool
    {
        return Schema::hasTable('tenants') && Schema::hasTable('noci_public_redirect_links');
    }

    private function resolveTenantIdByToken(string $tenantToken): int
    {
        $tenantToken = trim($tenantToken);
        if ($tenantToken === '') {
            return 0;
        }

        $tenant = DB::table('tenants')
            ->where('public_token', $tenantToken)
            ->where('status', 'active')
            ->first(['id']);

        return (int) ($tenant->id ?? 0);
    }

    private function resolveTenantIdByHost(Request $request): int
    {
        $host = strtolower(trim((string) ($request->getHost() ?? '')));
        if ($host === '') {
            return 0;
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (Schema::hasColumn('tenants', 'domain')) {
            $tenant = DB::table('tenants')
                ->where('status', 'active')
                ->whereRaw('LOWER(domain) = ?', [$host])
                ->first(['id']);
            $tenantId = (int) ($tenant->id ?? 0);
            if ($tenantId > 0) {
                return $tenantId;
            }
        }

        if (!Schema::hasColumn('tenants', 'slug')) {
            return 0;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return 0;
        }

        $parts = explode('.', $host);
        $slug = strtolower(trim((string) ($parts[0] ?? '')));
        if ($slug === '' || in_array($slug, ['www', 'localhost'], true)) {
            return 0;
        }

        $tenant = DB::table('tenants')
            ->where('status', 'active')
            ->whereRaw('LOWER(slug) = ?', [$slug])
            ->first(['id']);

        return (int) ($tenant->id ?? 0);
    }

    private function redirectByTenantAndCode(Request $request, int $tenantId, string $code): RedirectResponse
    {
        $code = PublicRedirectLink::normalizeCode($code);
        if ($tenantId <= 0 || $code === '') {
            abort(404);
        }

        $link = DB::table('noci_public_redirect_links')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$link) {
            abort(404);
        }

        // Count click first on every valid link hit, independent from redirect success.
        DB::table('noci_public_redirect_links')
            ->where('id', (int) $link->id)
            ->update([
                'click_count' => DB::raw('click_count + 1'),
                'last_clicked_at' => now(),
                'updated_at' => now(),
            ]);
        $this->logEvent($request, $tenantId, (int) $link->id, $code, 'click', null, null, null);

        $targetUrl = PublicRedirectLink::buildTargetUrl(
            (string) ($link->type ?? ''),
            (string) ($link->wa_number ?? ''),
            (string) ($link->wa_message ?? ''),
            (string) ($link->target_url ?? '')
        );

        if ($targetUrl === '') {
            $this->logEvent($request, $tenantId, (int) $link->id, $code, 'redirect_failed', null, 422, 'Target redirect tidak valid.');
            abort(404);
        }

        DB::table('noci_public_redirect_links')
            ->where('id', (int) $link->id)
            ->update([
                'redirect_success_count' => DB::raw('redirect_success_count + 1'),
                'last_redirect_success_at' => now(),
                'updated_at' => now(),
            ]);

        $this->logEvent($request, $tenantId, (int) $link->id, $code, 'redirect_success', $targetUrl, 302, null);

        return redirect()->away($targetUrl, 302);
    }

    private function logEvent(
        Request $request,
        int $tenantId,
        int $linkId,
        string $code,
        string $eventType,
        ?string $targetUrl,
        ?int $httpStatus,
        ?string $error
    ): void {
        if (!Schema::hasTable('noci_public_redirect_events')) {
            return;
        }

        try {
            DB::table('noci_public_redirect_events')->insert([
                'tenant_id' => $tenantId,
                'redirect_link_id' => $linkId,
                'code' => $code,
                'event_type' => $eventType,
                'target_url' => $targetUrl,
                'http_status' => $httpStatus,
                'error_message' => $error ? substr($error, 0, 255) : null,
                'ip_address' => substr((string) ($request->ip() ?? ''), 0, 45),
                'user_agent' => substr((string) ($request->userAgent() ?? ''), 0, 500),
                'referer' => substr((string) ($request->headers->get('referer', '') ?? ''), 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Ignore logging errors, redirect flow should still continue.
        }
    }
}

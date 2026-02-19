<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy-compatible logging endpoint.
 *
 * Native `direct/app.js` sends beacons to `../log.php?t=...` with FormData:
 * - visit_id
 * - action
 *
 * Keep payload + behavior close to `dashboard/log.php` (no JSON response).
 */
class LogController extends Controller
{
    public function handle(Request $request)
    {
        if ($request->isMethod('options')) {
            return response('', 204, $this->corsHeaders());
        }

        date_default_timezone_set('Asia/Jakarta');

        $token = (string) ($request->query('t') ?? $request->input('t') ?? '');
        $tenantId = 0;

        if ($token !== '') {
            try {
                $tenantId = (int) (DB::table('tenants')
                    ->where('public_token', $token)
                    ->where('status', 'active')
                    ->value('id') ?? 0);
            } catch (\Throwable) {
                $tenantId = 0;
            }
        } else {
            $tenantId = (int) (session('tenant_id') ?? 0);
        }

        if ($tenantId <= 0) {
            return response('', 204, $this->corsHeaders());
        }

        $visitId = (string) ($request->input('visit_id') ?? '');
        $action = (string) ($request->input('action') ?? 'view_halaman');
        if ($visitId === '') {
            return response('', 204, $this->corsHeaders());
        }

        $ip = (string) ($request->server('REMOTE_ADDR') ?? $request->ip() ?? '');
        $ua = (string) ($request->server('HTTP_USER_AGENT') ?? '');
        $now = date('Y-m-d H:i:s');

        // Device type (match native regex).
        $deviceType = 'Desktop';
        if (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $ua)) {
            $deviceType = 'Mobile';
        }

        // OS / platform.
        $platform = 'Unknown OS';
        if (preg_match('/android/i', $ua)) {
            $platform = 'Android';
        } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
            $platform = 'iOS';
        } elseif (preg_match('/windows|win32/i', $ua)) {
            $platform = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $platform = 'Mac';
        } elseif (preg_match('/linux/i', $ua)) {
            $platform = 'Linux';
        }

        // Brand.
        $brand = 'Generic';
        if (preg_match('/(iPhone|iPad)/i', $ua)) $brand = 'Apple';
        elseif (preg_match('/(Samsung|SM-|GT-)/i', $ua)) $brand = 'Samsung';
        elseif (preg_match('/(Oppo|CPH|R7|F1)/i', $ua)) $brand = 'Oppo';
        elseif (preg_match('/(Vivo|V2)/i', $ua)) $brand = 'Vivo';
        elseif (preg_match('/(Xiaomi|Redmi|Poco|Mi )/i', $ua)) $brand = 'Xiaomi';
        elseif (preg_match('/(Realme|RMX)/i', $ua)) $brand = 'Realme';
        elseif (preg_match('/(Infinix|X6)/i', $ua)) $brand = 'Infinix';
        elseif (preg_match('/(Asus|Zenfone)/i', $ua)) $brand = 'Asus';

        // Browser.
        $browserName = 'Unknown';
        if (strpos($ua, 'Firefox') !== false) $browserName = 'Firefox';
        elseif (strpos($ua, 'Chrome') !== false) $browserName = 'Chrome';
        elseif (strpos($ua, 'Safari') !== false) $browserName = 'Safari';
        elseif (strpos($ua, 'Edge') !== false) $browserName = 'Edge';
        elseif (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) $browserName = 'Opera';
        elseif (strpos($ua, 'UCBrowser') !== false) $browserName = 'UC Browser';

        // Geo/IP (whitelist + API fallback).
        $city = 'Unknown';
        $country = 'ID';
        $isp = '-';

        $whitelist = [
            '103.173.138.183' => 'Tanjung Setia',
            '103.173.138.167' => 'Tanjung Setia',
            '103.173.138.153' => 'Bangkunat',
            '103.173.138.157' => 'Bangkunat',
        ];

        if (isset($whitelist[$ip])) {
            $city = $whitelist[$ip];
            $country = 'ID';
            $isp = 'Local ISP';
        } else {
            $geo = $this->getGeoIP($ip);
            if (is_array($geo) && ($geo['status'] ?? '') === 'success') {
                $city = (string) ($geo['city'] ?? 'Unknown');
                $country = (string) ($geo['countryCode'] ?? 'ID');
                $isp = (string) ($geo['isp'] ?? '-');
            }
        }

        try {
            if (!Schema::hasTable('noci_logs')) {
                return response('', 204, $this->corsHeaders());
            }

            $payload = [
                'visit_id' => $visitId,
                'ip_address' => $ip,
                'device' => $deviceType,
                'platform' => $platform,
                'brand' => $brand,
                'city' => $city,
                'country' => $country,
                'isp' => $isp,
                'event_action' => $action,
                'timestamp' => $now,
                'browser' => $browserName,
            ];

            if (Schema::hasColumn('noci_logs', 'tenant_id')) {
                $payload = ['tenant_id' => $tenantId] + $payload;
            }

            DB::table('noci_logs')->insert($payload);
        } catch (\Throwable) {
            // keep silent (native behavior)
        }

        return response('', 204, $this->corsHeaders());
    }

    private function getGeoIP(string $ip): ?array
    {
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') return null;

        $url = "http://ip-api.com/json/{$ip}?fields=status,message,countryCode,city,isp";

        try {
            if (!function_exists('curl_init')) return null;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $result = curl_exec($ch);
            curl_close($ch);
            if (!$result) return null;
            $decoded = json_decode($result, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS',
        ];
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class WaGatewaySender
{
    private array $hasTableCache = [];
    private array $hasColumnCache = [];

    public function sendGroup(int $tenantId, string $groupId, string $message, array $options = []): array
    {
        return $this->send($tenantId, 'group', $groupId, $message, $options);
    }

    public function sendPersonal(int $tenantId, string $target, string $message, array $options = []): array
    {
        return $this->send($tenantId, 'personal', $target, $message, $options);
    }

    public function sendGroupMedia(int $tenantId, string $groupId, string $message, string $mediaUrl, array $options = []): array
    {
        $options['media_url'] = $mediaUrl;
        return $this->sendMedia($tenantId, 'group', $groupId, $message, $options);
    }

    private function send(int $tenantId, string $channel, string $target, string $message, array $options = []): array
    {
        $channel = $channel === 'group' ? 'group' : 'personal';
        $target = trim((string) $target);
        $message = (string) $message;
        $forceProvider = strtolower(trim((string) ($options['force_provider'] ?? '')));
        $forceFailover = !empty($options['force_failover']);
        $platform = trim((string) ($options['log_platform'] ?? ($channel === 'group' ? 'WA Group' : 'WhatsApp')));

        if ($tenantId <= 0) {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Tenant tidak valid');
            return ['status' => 'failed', 'error' => 'Tenant tidak valid'];
        }

        if ($target === '') {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Target kosong');
            return ['status' => 'failed', 'error' => 'Target kosong'];
        }

        $gateways = $this->activeGateways($tenantId, $forceProvider);
        if (empty($gateways)) {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Gateway WA tidak aktif');
            return ['status' => 'failed', 'error' => 'Gateway WA tidak aktif'];
        }

        $mode = strtolower(trim((string) ($gateways[0]['failover_mode'] ?? 'manual')));
        $mode = $mode === 'auto' ? 'auto' : 'manual';
        $selected = ($mode === 'auto' || $forceFailover) ? $gateways : [$gateways[0]];

        $lastError = 'Gagal kirim WA';
        foreach ($selected as $gateway) {
            $result = $this->sendWithRetry($gateway, $channel, $target, $message);
            if (!empty($result['ok'])) {
                $this->logNotif($tenantId, $platform, $target, $message, 'sent', (string) ($result['raw'] ?? 'OK'), (string) ($gateway['provider_code'] ?? ''));
                return [
                    'status' => 'sent',
                    'gateway' => $gateway,
                    'raw' => (string) ($result['raw'] ?? ''),
                ];
            }
            $lastError = (string) ($result['error'] ?? $lastError);
        }

        $this->logNotif($tenantId, $platform, $target, $message, 'failed', $lastError);
        return ['status' => 'failed', 'error' => $lastError];
    }

    private function sendMedia(int $tenantId, string $channel, string $target, string $message, array $options = []): array
    {
        $channel = $channel === 'group' ? 'group' : 'personal';
        $target = trim((string) $target);
        $message = (string) $message;
        $mediaUrl = trim((string) ($options['media_url'] ?? ''));
        $forceProvider = strtolower(trim((string) ($options['force_provider'] ?? '')));
        $forceFailover = !empty($options['force_failover']);
        $platform = trim((string) ($options['log_platform'] ?? ($channel === 'group' ? 'WA Group Media' : 'WhatsApp Media')));

        if ($channel !== 'group') {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Media hanya didukung untuk group');
            return ['status' => 'failed', 'error' => 'Media hanya didukung untuk group'];
        }
        if ($tenantId <= 0) {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Tenant tidak valid');
            return ['status' => 'failed', 'error' => 'Tenant tidak valid'];
        }
        if ($target === '') {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Target kosong');
            return ['status' => 'failed', 'error' => 'Target kosong'];
        }
        if ($mediaUrl === '') {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Media URL kosong');
            return ['status' => 'failed', 'error' => 'Media URL kosong'];
        }

        $gateways = $this->activeGateways($tenantId, $forceProvider);
        if (empty($gateways)) {
            $this->logNotif($tenantId, $platform, $target, $message, 'failed', 'Gateway WA tidak aktif');
            return ['status' => 'failed', 'error' => 'Gateway WA tidak aktif'];
        }

        $mode = strtolower(trim((string) ($gateways[0]['failover_mode'] ?? 'manual')));
        $mode = $mode === 'auto' ? 'auto' : 'manual';
        $selected = ($mode === 'auto' || $forceFailover) ? $gateways : [$gateways[0]];

        $lastError = 'Gagal kirim media WA';
        foreach ($selected as $gateway) {
            $result = $this->sendMediaWithRetry($gateway, $channel, $target, $message, $mediaUrl, $options);
            if (!empty($result['ok'])) {
                $this->logNotif($tenantId, $platform, $target, $message, 'sent', (string) ($result['raw'] ?? 'OK'), (string) ($gateway['provider_code'] ?? ''));
                return [
                    'status' => 'sent',
                    'gateway' => $gateway,
                    'raw' => (string) ($result['raw'] ?? ''),
                ];
            }
            $lastError = (string) ($result['error'] ?? $lastError);
        }

        $this->logNotif($tenantId, $platform, $target, $message, 'failed', $lastError);
        return ['status' => 'failed', 'error' => $lastError];
    }

    private function sendWithRetry(array $gateway, string $channel, string $target, string $message): array
    {
        $retryMax = (int) ($gateway['retry_max'] ?? 2);
        $retryDelaySec = (int) ($gateway['retry_delay_sec'] ?? 0);
        if ($retryMax < 0) $retryMax = 0;
        if ($retryDelaySec < 0) $retryDelaySec = 0;

        $mode = strtolower(trim((string) ($gateway['failover_mode'] ?? 'manual')));
        if ($mode === 'auto') {
            // Native parity: mode auto tidak retry per gateway, langsung failover.
            $retryMax = 0;
        }

        $attempts = $retryMax + 1;
        $last = ['ok' => false, 'error' => 'Gagal kirim WA'];
        for ($i = 0; $i < $attempts; $i++) {
            $last = $this->sendOnce($gateway, $channel, $target, $message);
            if (!empty($last['ok'])) {
                return $last;
            }
            if ($i < $attempts - 1 && $retryDelaySec > 0) {
                usleep($retryDelaySec * 1000000);
            }
        }

        return $last;
    }

    private function sendMediaWithRetry(
        array $gateway,
        string $channel,
        string $target,
        string $message,
        string $mediaUrl,
        array $options = []
    ): array {
        $retryMax = (int) ($gateway['retry_max'] ?? 2);
        $retryDelaySec = (int) ($gateway['retry_delay_sec'] ?? 0);
        if ($retryMax < 0) $retryMax = 0;
        if ($retryDelaySec < 0) $retryDelaySec = 0;

        $mode = strtolower(trim((string) ($gateway['failover_mode'] ?? 'manual')));
        if ($mode === 'auto') {
            $retryMax = 0;
        }

        $attempts = $retryMax + 1;
        $last = ['ok' => false, 'error' => 'Gagal kirim media WA'];
        for ($i = 0; $i < $attempts; $i++) {
            $last = $this->sendMediaOnce($gateway, $channel, $target, $message, $mediaUrl, $options);
            if (!empty($last['ok'])) {
                return $last;
            }
            if ($i < $attempts - 1 && $retryDelaySec > 0) {
                usleep($retryDelaySec * 1000000);
            }
        }

        return $last;
    }

    private function sendOnce(array $gateway, string $channel, string $target, string $message): array
    {
        $provider = strtolower(trim((string) ($gateway['provider_code'] ?? '')));
        if ($provider === 'mpwa') {
            return $this->sendMpwa($gateway, $channel, $target, $message);
        }

        return $this->sendBalesotomatis($gateway, $channel, $target, $message);
    }

    private function sendMediaOnce(
        array $gateway,
        string $channel,
        string $target,
        string $message,
        string $mediaUrl,
        array $options = []
    ): array {
        $provider = strtolower(trim((string) ($gateway['provider_code'] ?? '')));
        if ($provider === 'mpwa') {
            return $this->sendMpwaMedia($gateway, $channel, $target, $message, $mediaUrl, $options);
        }

        return $this->sendBalesotomatisMedia($gateway, $channel, $target, $message, $mediaUrl, $options);
    }

    private function sendBalesotomatis(array $gateway, string $channel, string $target, string $message): array
    {
        $token = trim((string) ($gateway['token'] ?? ''));
        $sender = trim((string) ($gateway['sender_number'] ?? ''));
        if ($token === '' || $sender === '') {
            return ['ok' => false, 'error' => 'Config WA tidak lengkap'];
        }

        $timeout = (int) ($gateway['timeout_sec'] ?? 10);
        if ($timeout <= 0) $timeout = 10;

        if ($channel === 'group') {
            $groupUrl = trim((string) ($gateway['group_url'] ?? ''));
            if ($groupUrl === '') {
                return ['ok' => false, 'error' => 'Group URL kosong'];
            }
            $payload = [
                'api_key' => $token,
                'number_id' => $sender,
                'group_id' => $target,
                'message' => $message,
            ];
            return $this->httpJson($groupUrl, $payload, $timeout, 'balesotomatis');
        }

        $endpoint = trim((string) ($gateway['base_url'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://api.balesotomatis.id/public/v1/send_personal_message';
        }

        $phoneNo = $this->phoneNoBales($target);
        if ($phoneNo === '') {
            return ['ok' => false, 'error' => 'Nomor tujuan kosong'];
        }

        $payload = [
            'api_key' => $token,
            'number_id' => $sender,
            'enable_typing' => '1',
            'method_send' => 'async',
            'phone_no' => $phoneNo,
            'country_code' => '62',
            'message' => $message,
        ];

        return $this->httpJson($endpoint, $payload, $timeout, 'balesotomatis');
    }

    private function sendBalesotomatisMedia(
        array $gateway,
        string $channel,
        string $target,
        string $message,
        string $mediaUrl,
        array $options = []
    ): array {
        $token = trim((string) ($gateway['token'] ?? ''));
        $sender = trim((string) ($gateway['sender_number'] ?? ''));
        if ($token === '' || $sender === '') {
            return ['ok' => false, 'error' => 'Config WA tidak lengkap'];
        }
        if ($channel !== 'group') {
            return ['ok' => false, 'error' => 'Media hanya didukung untuk group'];
        }

        $timeout = (int) ($gateway['timeout_sec'] ?? 10);
        if ($timeout <= 0) $timeout = 10;

        $groupUrl = trim((string) ($gateway['group_url'] ?? ''));
        if ($groupUrl === '') {
            return ['ok' => false, 'error' => 'Group URL kosong'];
        }

        $kind = $this->detectMediaKind($mediaUrl, $options);
        $sendAsCaption = (int) ($options['send_as_caption'] ?? 1);
        if ($sendAsCaption !== 0) $sendAsCaption = 1;

        $payload = [
            'api_key' => $token,
            'number_id' => $sender,
            'enable_typing' => '1',
            'method_send' => 'async',
            'group_id' => $target,
            'message' => $message,
        ];

        $endpoint = $groupUrl;
        if ($kind === 'image') {
            $payload['image_url'] = $mediaUrl;
            $payload['send_as_caption'] = (string) $sendAsCaption;
            return $this->httpJson($endpoint, $payload, $timeout, 'balesotomatis');
        }

        $endpointFile = trim((string) ($options['group_file_url'] ?? ''));
        if ($endpointFile === '') {
            $replaced = str_ireplace('send_group_message', 'send_group_file', $groupUrl);
            if ($replaced !== $groupUrl) {
                $endpointFile = $replaced;
            }
        }
        if ($endpointFile === '') {
            $base = trim((string) ($gateway['base_url'] ?? ''));
            $seed = $base !== '' ? $base : $groupUrl;
            $endpointFile = $this->balesEndpointFromSeed($seed, '/public/v1/send_group_file');
        }
        if ($endpointFile === '') {
            return ['ok' => false, 'error' => 'URL endpoint send_group_file tidak ditemukan'];
        }

        $payload['file_url'] = $mediaUrl;
        return $this->httpJson($endpointFile, $payload, $timeout, 'balesotomatis');
    }

    private function sendMpwa(array $gateway, string $channel, string $target, string $message): array
    {
        $token = trim((string) ($gateway['token'] ?? ''));
        $sender = trim((string) ($gateway['sender_number'] ?? ''));
        if ($token === '' || $sender === '') {
            return ['ok' => false, 'error' => 'Config MPWA tidak lengkap'];
        }

        $timeout = (int) ($gateway['timeout_sec'] ?? 10);
        if ($timeout <= 0) $timeout = 10;

        $endpoint = trim((string) ($gateway['base_url'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://app.mpwa.net/send-message';
        }

        $number = $target;
        if ($channel === 'personal' && strpos($number, '@') === false) {
            $number = $this->phoneNo62($target);
        }
        if ($number === '') {
            return ['ok' => false, 'error' => 'Nomor tujuan kosong'];
        }

        $payload = [
            'api_key' => $token,
            'sender' => $sender,
            'number' => $number,
            'message' => $message,
        ];

        $extra = is_array($gateway['extra'] ?? null) ? $gateway['extra'] : [];
        $footer = trim((string) ($extra['footer'] ?? ''));
        if ($footer !== '') {
            $payload['footer'] = $footer;
        }

        return $this->httpJson($endpoint, $payload, $timeout, 'mpwa');
    }

    private function sendMpwaMedia(
        array $gateway,
        string $channel,
        string $target,
        string $message,
        string $mediaUrl,
        array $options = []
    ): array {
        $token = trim((string) ($gateway['token'] ?? ''));
        $sender = trim((string) ($gateway['sender_number'] ?? ''));
        if ($token === '' || $sender === '') {
            return ['ok' => false, 'error' => 'Config MPWA tidak lengkap'];
        }
        if ($channel !== 'group') {
            return ['ok' => false, 'error' => 'Media hanya didukung untuk group'];
        }

        $timeout = (int) ($gateway['timeout_sec'] ?? 10);
        if ($timeout <= 0) $timeout = 10;

        $endpoint = trim((string) ($gateway['base_url'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://app.mpwa.net/send-message';
        }

        $payload = [
            'api_key' => $token,
            'sender' => $sender,
            'number' => $target,
            'message' => $message,
            'is_group' => true,
        ];

        $kind = $this->detectMediaKind($mediaUrl, $options);
        if ($kind === 'image') {
            $payload['image_url'] = $mediaUrl;
            $payload['media_url'] = $mediaUrl;
            $payload['send_as_caption'] = 1;
            $payload['messageType'] = 'image';
        } else {
            $payload['file_url'] = $mediaUrl;
            $payload['document_url'] = $mediaUrl;
            $payload['media_url'] = $mediaUrl;
            $payload['messageType'] = 'document';
        }

        $extra = is_array($gateway['extra'] ?? null) ? $gateway['extra'] : [];
        $footer = trim((string) ($extra['footer'] ?? ''));
        if ($footer !== '') {
            $payload['footer'] = $footer;
        }

        return $this->httpJson($endpoint, $payload, $timeout, 'mpwa');
    }

    private function httpJson(string $url, array $payload, int $timeoutSec, string $provider): array
    {
        try {
            $response = Http::timeout($timeoutSec)->asJson()->post($url, $payload);
            $body = (string) $response->body();
            $httpCode = (int) $response->status();
            $trim = trim($body);
            $decoded = $trim !== '' ? json_decode($trim, true) : null;

            if ($provider === 'balesotomatis') {
                $ok = ($httpCode === 200);
                if (is_array($decoded)) {
                    if (array_key_exists('status', $decoded)) {
                        $ok = $ok && (bool) $decoded['status'];
                    }
                    if (array_key_exists('code', $decoded)) {
                        $ok = $ok && ((int) $decoded['code'] === 200);
                    }
                } elseif ($trim !== '' && preg_match('/"code"\s*:\s*"?(\d{3})"?/i', $trim, $m)) {
                    $ok = $ok && ((int) $m[1] === 200);
                }
            } else {
                $ok = $response->successful();
                if ($ok && is_array($decoded)) {
                    if (array_key_exists('status', $decoded)) {
                        $ok = (bool) $decoded['status'];
                    } elseif (array_key_exists('success', $decoded)) {
                        $ok = (bool) $decoded['success'];
                    }
                }
            }

            if ($ok) {
                return ['ok' => true, 'raw' => $body, 'http_code' => $httpCode];
            }

            $error = 'HTTP ' . $httpCode;
            if ($trim !== '') {
                $error .= ': ' . $trim;
            }
            return ['ok' => false, 'error' => $error, 'raw' => $body, 'http_code' => $httpCode];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function activeGateways(int $tenantId, string $forceProvider = ''): array
    {
        $forceProvider = strtolower(trim($forceProvider));
        $legacy = $this->legacyConfig($tenantId);
        $rows = [];

        if ($this->hasTable('noci_wa_tenant_gateways')) {
            $query = DB::table('noci_wa_tenant_gateways as twg')
                ->where('twg.tenant_id', $tenantId);

            if ($this->hasColumn('noci_wa_tenant_gateways', 'is_active')) {
                $query->where('twg.is_active', 1);
            }

            $needsJoinGateway = !$this->hasColumn('noci_wa_tenant_gateways', 'provider_code')
                && $this->hasColumn('noci_wa_tenant_gateways', 'gateway_id')
                && $this->hasTable('noci_wa_gateways');

            if ($needsJoinGateway) {
                $query->leftJoin('noci_wa_gateways as gw', 'twg.gateway_id', '=', 'gw.id');
            }

            if ($forceProvider !== '' && $this->hasColumn('noci_wa_tenant_gateways', 'provider_code')) {
                $query->where('twg.provider_code', $forceProvider);
            }

            if ($this->hasColumn('noci_wa_tenant_gateways', 'priority')) {
                $query->orderBy('twg.priority');
            }
            $query->orderBy('twg.id');

            $selects = ['twg.*'];
            if ($needsJoinGateway) {
                if ($this->hasColumn('noci_wa_gateways', 'type')) $selects[] = 'gw.type as gw_type';
                if ($this->hasColumn('noci_wa_gateways', 'code')) $selects[] = 'gw.code as gw_code';
                if ($this->hasColumn('noci_wa_gateways', 'name')) $selects[] = 'gw.name as gw_name';
                if ($this->hasColumn('noci_wa_gateways', 'base_url')) $selects[] = 'gw.base_url as gw_base_url';
                if ($this->hasColumn('noci_wa_gateways', 'token')) $selects[] = 'gw.token as gw_token';
                if ($this->hasColumn('noci_wa_gateways', 'extra_config')) $selects[] = 'gw.extra_config as gw_extra_config';
                if ($this->hasColumn('noci_wa_gateways', 'extra_json')) $selects[] = 'gw.extra_json as gw_extra_json';
            }

            $rows = $query->get($selects)->map(function ($row) use ($tenantId, $legacy) {
                return $this->normalizeGateway((array) $row, $tenantId, $legacy);
            })->filter(function (array $gateway) {
                return !empty($gateway['is_active']);
            })->values()->all();
        }

        if ($forceProvider !== '') {
            $rows = array_values(array_filter($rows, function (array $gateway) use ($forceProvider) {
                return strtolower((string) ($gateway['provider_code'] ?? '')) === $forceProvider;
            }));
        }

        if (!empty($rows)) {
            return $rows;
        }

        if ($legacy === null) {
            return [];
        }

        $fallback = [
            'id' => 0,
            'tenant_id' => $tenantId,
            'provider_code' => 'balesotomatis',
            'label' => 'Legacy',
            'base_url' => trim((string) ($legacy['base_url'] ?? '')),
            'group_url' => trim((string) ($legacy['group_url'] ?? '')),
            'token' => trim((string) ($legacy['token'] ?? '')),
            'sender_number' => trim((string) ($legacy['sender_number'] ?? '')),
            'group_id' => trim((string) ($legacy['group_id'] ?? '')),
            'is_active' => 1,
            'priority' => 1,
            'failover_mode' => 'manual',
            'timeout_sec' => 10,
            'retry_max' => 2,
            'retry_delay_sec' => 0,
            'extra' => [],
        ];

        if ($forceProvider !== '' && $forceProvider !== 'balesotomatis') {
            return [];
        }

        return [$fallback];
    }

    private function normalizeGateway(array $raw, int $tenantId, ?array $legacy): array
    {
        $extraRaw = $raw['extra_json'] ?? $raw['gw_extra_json'] ?? $raw['gw_extra_config'] ?? $raw['extra_config'] ?? null;
        $extra = [];
        if (is_string($extraRaw) && trim($extraRaw) !== '') {
            $decoded = json_decode($extraRaw, true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        } elseif (is_array($extraRaw)) {
            $extra = $extraRaw;
        }

        $provider = strtolower(trim((string) ($raw['provider_code'] ?? $raw['gw_code'] ?? $raw['gw_type'] ?? $raw['type'] ?? '')));
        if ($provider === '') {
            $provider = 'balesotomatis';
        }

        $baseUrl = trim((string) ($raw['base_url'] ?? $raw['gw_base_url'] ?? ''));
        $groupUrl = trim((string) ($raw['group_url'] ?? ''));
        $token = trim((string) ($raw['token'] ?? $raw['gw_token'] ?? ''));
        $sender = trim((string) ($raw['sender_number'] ?? ''));
        $groupId = trim((string) ($raw['group_id'] ?? ''));

        if ($legacy !== null) {
            if ($baseUrl === '') $baseUrl = trim((string) ($legacy['base_url'] ?? ''));
            if ($groupUrl === '') $groupUrl = trim((string) ($legacy['group_url'] ?? ''));
            if ($token === '') $token = trim((string) ($legacy['token'] ?? ''));
            if ($sender === '') $sender = trim((string) ($legacy['sender_number'] ?? ''));
            if ($groupId === '') $groupId = trim((string) ($legacy['group_id'] ?? ''));
        }

        if ($groupUrl === '' && $provider === 'mpwa' && $baseUrl !== '') {
            $groupUrl = $baseUrl;
        }

        $failoverMode = strtolower(trim((string) ($raw['failover_mode'] ?? 'manual')));
        $failoverMode = $failoverMode === 'auto' ? 'auto' : 'manual';

        $timeout = (int) ($raw['timeout_sec'] ?? 10);
        if ($timeout <= 0) $timeout = 10;

        $retryMax = (int) ($raw['retry_max'] ?? 2);
        if ($retryMax < 0) $retryMax = 0;

        $retryDelay = (int) ($raw['retry_delay_sec'] ?? 0);
        if ($retryDelay < 0) $retryDelay = 0;

        return [
            'id' => (int) ($raw['id'] ?? 0),
            'tenant_id' => $tenantId,
            'provider_code' => $provider,
            'label' => trim((string) ($raw['label'] ?? $raw['gw_name'] ?? ucfirst($provider))),
            'base_url' => $baseUrl,
            'group_url' => $groupUrl,
            'token' => $token,
            'sender_number' => $sender,
            'group_id' => $groupId,
            'is_active' => (int) ($raw['is_active'] ?? 1),
            'priority' => (int) ($raw['priority'] ?? 1),
            'failover_mode' => $failoverMode,
            'timeout_sec' => $timeout,
            'retry_max' => $retryMax,
            'retry_delay_sec' => $retryDelay,
            'extra' => $extra,
        ];
    }

    private function legacyConfig(int $tenantId): ?array
    {
        if (!$this->hasTable('noci_conf_wa')) {
            return null;
        }

        try {
            $query = DB::table('noci_conf_wa')->where('tenant_id', $tenantId);
            if ($this->hasColumn('noci_conf_wa', 'is_active')) {
                $query->where('is_active', 1);
            }
            $row = $query->first();
            return $row ? (array) $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function phoneNoBales(string $raw): string
    {
        $clean = preg_replace('/\D/', '', $raw);
        if ($clean === null || $clean === '') return '';
        if (str_starts_with($clean, '62')) return substr($clean, 2);
        if (str_starts_with($clean, '0')) return substr($clean, 1);
        return $clean;
    }

    private function phoneNo62(string $raw): string
    {
        $clean = preg_replace('/\D/', '', $raw);
        if ($clean === null || $clean === '') return '';
        if (str_starts_with($clean, '62')) return $clean;
        if (str_starts_with($clean, '0')) return '62' . substr($clean, 1);
        return '62' . $clean;
    }

    private function detectMediaKind(string $mediaUrl, array $options = []): string
    {
        $kind = strtolower(trim((string) ($options['media_kind'] ?? '')));
        if (in_array($kind, ['image', 'file'], true)) {
            return $kind;
        }

        $mime = strtolower(trim((string) ($options['media_mime'] ?? '')));
        if ($mime !== '' && str_starts_with($mime, 'image/')) {
            return 'image';
        }

        $ext = strtolower(trim((string) ($options['media_ext'] ?? '')));
        if ($ext === '') {
            $path = (string) (parse_url($mediaUrl, PHP_URL_PATH) ?? '');
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return 'image';
        }

        return 'file';
    }

    private function balesEndpointFromSeed(string $seedUrl, string $path): string
    {
        $seedUrl = trim($seedUrl);
        if ($seedUrl === '') return '';

        if (preg_match('~^(https?://[^/]+)/public/v1/~i', $seedUrl, $m)) {
            return rtrim((string) $m[1], '/') . $path;
        }
        if (preg_match('~^(https?://[^/]+)~i', $seedUrl, $m)) {
            return rtrim((string) $m[1], '/') . $path;
        }

        return '';
    }

    private function logNotif(
        int $tenantId,
        string $platform,
        string $target,
        string $message,
        string $status,
        string $response,
        string $gatewayCode = ''
    ): void {
        if (!$this->hasTable('noci_notif_logs')) {
            return;
        }

        try {
            $payload = [];
            if ($this->hasColumn('noci_notif_logs', 'tenant_id')) $payload['tenant_id'] = $tenantId;
            if ($this->hasColumn('noci_notif_logs', 'platform')) $payload['platform'] = $platform;
            if ($this->hasColumn('noci_notif_logs', 'channel')) $payload['channel'] = $platform;
            if ($this->hasColumn('noci_notif_logs', 'target')) $payload['target'] = $target;
            if ($this->hasColumn('noci_notif_logs', 'message')) $payload['message'] = substr($message, 0, 100);
            if ($this->hasColumn('noci_notif_logs', 'status')) $payload['status'] = $status;
            if ($this->hasColumn('noci_notif_logs', 'response_log')) $payload['response_log'] = substr($response, 0, 255);
            if ($this->hasColumn('noci_notif_logs', 'response')) $payload['response'] = substr($response, 0, 255);
            if ($this->hasColumn('noci_notif_logs', 'gateway_code')) $payload['gateway_code'] = $gatewayCode;
            if ($this->hasColumn('noci_notif_logs', 'error') && $status !== 'sent') $payload['error'] = substr($response, 0, 255);

            $now = now();
            if ($this->hasColumn('noci_notif_logs', 'timestamp')) $payload['timestamp'] = $now;
            if ($this->hasColumn('noci_notif_logs', 'created_at')) $payload['created_at'] = $now;
            if ($this->hasColumn('noci_notif_logs', 'updated_at')) $payload['updated_at'] = $now;

            if (!empty($payload)) {
                DB::table('noci_notif_logs')->insert($payload);
            }
        } catch (\Throwable) {
            // Best effort logging only.
        }
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->hasTableCache)) {
            return $this->hasTableCache[$table];
        }

        try {
            $exists = Schema::hasTable($table);
        } catch (\Throwable) {
            $exists = false;
        }

        $this->hasTableCache[$table] = $exists;
        return $exists;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . ':' . $column;
        if (array_key_exists($key, $this->hasColumnCache)) {
            return $this->hasColumnCache[$key];
        }

        if (!$this->hasTable($table)) {
            $this->hasColumnCache[$key] = false;
            return false;
        }

        try {
            $exists = Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            $exists = false;
        }

        $this->hasColumnCache[$key] = $exists;
        return $exists;
    }
}

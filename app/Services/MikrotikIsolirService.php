<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MikrotikIsolirService
{
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected bool $enabled;

    public function __construct()
    {
        $this->baseUrl = config('services.mikrotik.api_url', '');
        $this->username = config('services.mikrotik.username', '');
        $this->password = config('services.mikrotik.password', '');
        $this->enabled = config('services.mikrotik.enabled', false);
    }

    /**
     * Check if MikroTik integration is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->baseUrl);
    }

    /**
     * Suspend/isolir a customer's PPPoE
     * Disables PPPoE secret so customer can't connect
     */
    public function suspend(string $username): array
    {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'message' => 'MikroTik integration not enabled'
            ];
        }

        try {
            // First find the secret ID
            $secretId = $this->findPppoeSecret($username);
            
            if (!$secretId) {
                return [
                    'success' => false,
                    'message' => "PPPoE secret not found: {$username}"
                ];
            }

            // Disable the secret
            $response = $this->apiRequest('/ppp/secret/set', [
                '.id' => $secretId,
                'disabled' => 'true'
            ]);

            // Also kick active session if exists
            $this->kickActiveSession($username);

            Log::info("MikroTik: Suspended user {$username}");

            return [
                'success' => true,
                'message' => "User {$username} suspended"
            ];

        } catch (\Exception $e) {
            Log::error("MikroTik suspend error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Unsuspend/aktivasi a customer's PPPoE
     * Re-enables PPPoE secret
     */
    public function unsuspend(string $username): array
    {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'message' => 'MikroTik integration not enabled'
            ];
        }

        try {
            $secretId = $this->findPppoeSecret($username);
            
            if (!$secretId) {
                return [
                    'success' => false,
                    'message' => "PPPoE secret not found: {$username}"
                ];
            }

            // Enable the secret
            $response = $this->apiRequest('/ppp/secret/set', [
                '.id' => $secretId,
                'disabled' => 'false'
            ]);

            Log::info("MikroTik: Unsuspended user {$username}");

            return [
                'success' => true,
                'message' => "User {$username} activated"
            ];

        } catch (\Exception $e) {
            Log::error("MikroTik unsuspend error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Kick active PPPoE session
     */
    public function kickActiveSession(string $username): bool
    {
        try {
            // Find active session
            $response = $this->apiRequest('/ppp/active/print', [
                '?name' => $username
            ]);

            if (!empty($response) && isset($response[0]['.id'])) {
                // Remove active session
                $this->apiRequest('/ppp/active/remove', [
                    '.id' => $response[0]['.id']
                ]);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::warning("MikroTik kick session error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user is currently online
     */
    public function isOnline(string $username): array
    {
        if (!$this->isEnabled()) {
            return ['online' => false, 'data' => null];
        }

        try {
            $response = $this->apiRequest('/ppp/active/print', [
                '?name' => $username
            ]);

            if (!empty($response) && isset($response[0])) {
                return [
                    'online' => true,
                    'data' => [
                        'address' => $response[0]['address'] ?? null,
                        'uptime' => $response[0]['uptime'] ?? null,
                        'caller_id' => $response[0]['caller-id'] ?? null,
                        'service' => $response[0]['service'] ?? null,
                    ]
                ];
            }

            return ['online' => false, 'data' => null];

        } catch (\Exception $e) {
            return ['online' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get PPPoE secret status
     */
    public function getSecretStatus(string $username): ?array
    {
        try {
            $response = $this->apiRequest('/ppp/secret/print', [
                '?name' => $username
            ]);

            if (!empty($response) && isset($response[0])) {
                return [
                    'name' => $response[0]['name'] ?? null,
                    'profile' => $response[0]['profile'] ?? null,
                    'disabled' => ($response[0]['disabled'] ?? 'false') === 'true',
                    'comment' => $response[0]['comment'] ?? null,
                ];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find PPPoE secret ID by username
     */
    protected function findPppoeSecret(string $username): ?string
    {
        $response = $this->apiRequest('/ppp/secret/print', [
            '?name' => $username
        ]);

        if (!empty($response) && isset($response[0]['.id'])) {
            return $response[0]['.id'];
        }

        return null;
    }

    /**
     * Make API request to MikroTik
     */
    protected function apiRequest(string $endpoint, array $params = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/rest' . $endpoint;

        $response = Http::withBasicAuth($this->username, $this->password)
            ->timeout(10)
            ->post($url, $params);

        if (!$response->successful()) {
            throw new \Exception("MikroTik API error: " . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Bulk suspend multiple users
     */
    public function bulkSuspend(array $usernames): array
    {
        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($usernames as $username) {
            $result = $this->suspend($username);
            if ($result['success']) {
                $results['success'][] = $username;
            } else {
                $results['failed'][] = [
                    'username' => $username,
                    'message' => $result['message']
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk unsuspend multiple users
     */
    public function bulkUnsuspend(array $usernames): array
    {
        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($usernames as $username) {
            $result = $this->unsuspend($username);
            if ($result['success']) {
                $results['success'][] = $username;
            } else {
                $results['failed'][] = [
                    'username' => $username,
                    'message' => $result['message']
                ];
            }
        }

        return $results;
    }
}

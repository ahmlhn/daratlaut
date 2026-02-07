<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OltLog;
use RuntimeException;

/**
 * OLT Service for ZTE C320 OLT Management
 * Handles Telnet communication and ONU provisioning
 */
class OltService
{
    const DEFAULT_TCONT_PROFILE = 'pppoe';
    const DEFAULT_ONU_TYPE = 'ALL-ONT';
    const DEFAULT_VLAN = 200;
    const DEFAULT_SERVICE_PORT_ID = 1;
    const ONU_ID_MAX = 128;
    const FSP_CACHE_TTL = 86400;

    // Regex patterns
    const UNCFG_LINE_RE = '/^\s*(\d+\/\d+\/\d+)\s+([A-Za-z0-9]+)\s*$/';
    const FSP_TOKEN_RE = '/^\d+\/\d+\/\d+$/';
    const GPON_ONU_RE = '/gpon-onu_(\d+\/\d+\/\d+):\d+/i';
    const GPON_ONU_ID_RE = '/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i';
    const SN_STRICT_RE = '/^[A-Za-z]{4}[A-Fa-f0-9]{8}$/';
    const SN_TOKEN_RE = '/^(?=.{8,20}$)(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9]+$/';
    const ONU_STATE_ID_RE = '/^\s*(\d+)\s+/';
    const ONU_STATE_FSP_ID_RE = '/^\s*(\d+\/\d+\/\d+)\s+(\d+)\b/';
    const ONU_STATE_FSP_COLON_ID_RE = '/^\s*(\d+\/\d+\/\d+):(\d+)\b/';
    const ERROR_RE = '/(?:% ?Error|Error:|Invalid|Unknown command|Fail)/i';
    const OK_RE = '/(?:already exists|entry is existed|re-create operation)/i';
    const CONFIRM_RE = '/(?:are you sure|confirm|continue\\?|proceed\\?|\\(y\\/n\\)|\\[y\\/n\\]|yes\\/no|y\\s*\\/\\s*n)/i';

    private ?TelnetClient $client = null;
    private ?Olt $olt = null;
    private int $tenantId;

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Connect to OLT via Telnet
     */
    public function connect(Olt $olt, int $timeout = 12): bool
    {
        $this->olt = $olt;
        
        try {
            $this->client = new TelnetClient($olt->host, $olt->port ?? 23, $timeout);
            
            // Perform login
            $this->login($olt->username, $olt->password, $timeout);
            
            // Disable paging
            $this->disablePaging();
            
            return true;
        } catch (RuntimeException $e) {
            $this->logAction('connect', 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Login to OLT
     */
    private function login(string $user, string $pwd, int $timeout = 12): void
    {
        $end = microtime(true) + $timeout;
        $buf = '';
        $sentUser = false;
        $sentPwd = false;

        while (microtime(true) < $end) {
            $chunk = $this->client->readUntilPrompt(1);
            if ($chunk !== '') {
                $buf .= $chunk;

                if (preg_match(TelnetClient::PROMPT_RE, $buf)) {
                    return; // Successfully logged in
                }
                if (!$sentUser && preg_match(TelnetClient::LOGIN_RE, $buf)) {
                    $this->client->write($user . "\n");
                    $buf = '';
                    $sentUser = true;
                    continue;
                }
                if (!$sentPwd && preg_match(TelnetClient::PASSWORD_RE, $buf)) {
                    $this->client->write($pwd . "\n");
                    $buf = '';
                    $sentPwd = true;
                    continue;
                }
                if (stripos($buf, 'Login incorrect') !== false || stripos($buf, 'Authentication failed') !== false) {
                    $this->disconnect();
                    throw new RuntimeException('Login failed: invalid username/password.');
                }
            } else {
                usleep(150000);
            }
        }
        $this->disconnect();
        throw new RuntimeException('Login timeout: check IP/port or login prompt.');
    }

    /**
     * Disable paging on OLT
     */
    private function disablePaging(): void
    {
        $cmds = [
            'terminal length 0',
            'terminal page 0',
            'scroll 0',
            'screen-length 0 temporary',
            'screen-length 0 permanent'
        ];
        foreach ($cmds as $cmd) {
            $this->sendCommand($cmd, 5);
        }
    }

    /**
     * Send command to OLT
     */
    public function sendCommand(string $cmd, int $timeout = 18): string
    {
        $this->client->write($cmd . "\n");
        $raw = $this->client->readUntilPrompt($timeout);
        return str_replace("\r", "", $raw);
    }

    /**
     * Send command with confirmation (y/n)
     */
    public function sendCommandConfirm(string $cmd, int $timeout = 18, int $maxConfirm = 2): string
    {
        $this->client->write($cmd . "\n");
        $raw = $this->client->readUntilPrompt($timeout);
        $out = str_replace("\r", "", $raw);
        $tries = 0;
        while ($tries < $maxConfirm && preg_match(self::CONFIRM_RE, $out)) {
            $this->client->write("y\n");
            $rawMore = $this->client->readUntilPrompt($timeout);
            $out .= "\n" . str_replace("\r", "", $rawMore);
            $tries++;
        }
        return $out;
    }

    /**
     * Disconnect from OLT
     */
    public function disconnect(): void
    {
        if ($this->client) {
            $this->client->close();
            $this->client = null;
        }
    }

    /**
     * Scan unconfigured ONUs
     */
    public function scanUnconfigured(): array
    {
        $out = $this->sendCommand('show gpon onu uncfg');
        return $this->parseUncfgOutput($out);
    }

    /**
     * Get FSP list from OLT
     */
    public function listFsp(): array
    {
        $out = $this->sendCommand('show gpon onu state');
        return $this->parseFspList($out);
    }

    /**
     * Load registered ONUs for an FSP
     */
    public function loadRegisteredFsp(string $fsp): array
    {
        // Get baseinfo with status
        $out = $this->sendCommand("show gpon onu baseinfo gpon-olt_{$fsp}");
        $items = $this->parseBaseinfoOutput($out, $fsp);
        
        // Get RX power
        $rxOut = $this->sendCommand("show gpon onu optical-info gpon-olt_{$fsp}");
        $rxMap = $this->parseRxOutput($rxOut);
        
        foreach ($items as &$item) {
            $key = $item['fsp'] . ':' . $item['onu_id'];
            if (isset($rxMap[$key])) {
                $item['rx'] = $rxMap[$key];
            }
        }
        
        return $items;
    }

    /**
     * Get ONU detail info
     */
    public function getOnuDetail(string $fsp, int $onuId): array
    {
        $out = $this->sendCommand("show gpon onu detail-info gpon-onu_{$fsp}:{$onuId}");
        return $this->parseOnuDetail($out);
    }

    /**
     * Register ONU
     */
    public function registerOnu(string $fsp, string $sn, string $name, ?int $onuId = null): array
    {
        // Sanitize name (no spaces)
        $name = preg_replace('/\s+/', '', $name);
        
        // Find available ONU ID if not specified
        if ($onuId === null) {
            $onuId = $this->findAvailableOnuId($fsp);
        }
        
        // Enter config mode
        $this->sendCommand('configure terminal');
        $this->sendCommand("interface gpon-olt_{$fsp}");
        
        // Register ONU
        $onuType = $this->olt->onu_type_default ?? self::DEFAULT_ONU_TYPE;
        $cmd = "onu {$onuId} type {$onuType} sn {$sn}";
        $out = $this->sendCommandConfirm($cmd);
        
        // Check for errors
        if (preg_match(self::ERROR_RE, $out) && !preg_match(self::OK_RE, $out)) {
            $this->sendCommand('exit'); // Exit interface
            $this->sendCommand('exit'); // Exit config
            throw new RuntimeException("ONU registration failed: $out");
        }
        
        // Set ONU name
        $this->sendCommand("onu {$onuId} name {$name}");
        
        $this->sendCommand('exit'); // Exit interface
        
        // Configure service
        $this->configureOnuService($fsp, $onuId);
        
        $this->sendCommand('exit'); // Exit config
        
        // Save to database
        $this->saveOnuToDb($fsp, $onuId, $sn, $name);
        
        // Log action
        $this->logAction('register', 'success', "ONU {$fsp}:{$onuId} ({$sn}) registered as '{$name}'");
        
        return [
            'fsp' => $fsp,
            'onu_id' => $onuId,
            'sn' => $sn,
            'name' => $name,
        ];
    }

    /**
     * Configure ONU service (PPPoE)
     */
    private function configureOnuService(string $fsp, int $onuId): void
    {
        $tcont = $this->olt->tcont_default ?? self::DEFAULT_TCONT_PROFILE;
        $vlan = $this->olt->vlan_default ?? self::DEFAULT_VLAN;
        
        // Configure ONU interface
        $this->sendCommand("interface gpon-onu_{$fsp}:{$onuId}");
        $this->sendCommand("tcont 1 profile {$tcont}");
        $this->sendCommand("gemport 1 tcont 1");
        $this->sendCommand("service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}");
        $this->sendCommand('exit');
        
        // Configure PON side
        $this->sendCommand("pon-onu-mng gpon-onu_{$fsp}:{$onuId}");
        $this->sendCommand('service 1 gemport 1 vlan ' . $vlan);
        $this->sendCommand('exit');
    }

    /**
     * Update ONU name
     */
    public function updateOnuName(string $fsp, int $onuId, string $name): bool
    {
        // Sanitize name
        $name = preg_replace('/\s+/', '', $name);
        
        $this->sendCommand('configure terminal');
        $this->sendCommand("interface gpon-olt_{$fsp}");
        $out = $this->sendCommand("onu {$onuId} name {$name}");
        $this->sendCommand('exit');
        $this->sendCommand('exit');
        
        if (preg_match(self::ERROR_RE, $out)) {
            throw new RuntimeException("Failed to update ONU name: $out");
        }
        
        // Update database
        OltOnu::where('olt_id', $this->olt->id)
            ->where('fsp', $fsp)
            ->where('onu_id', $onuId)
            ->update(['name' => $name]);
        
        $this->logAction('update_name', 'success', "ONU {$fsp}:{$onuId} renamed to '{$name}'");
        
        return true;
    }

    /**
     * Delete ONU
     */
    public function deleteOnu(string $fsp, int $onuId): bool
    {
        $this->sendCommand('configure terminal');
        $this->sendCommand("interface gpon-olt_{$fsp}");
        $out = $this->sendCommandConfirm("no onu {$onuId}");
        $this->sendCommand('exit');
        $this->sendCommand('exit');
        
        if (preg_match(self::ERROR_RE, $out) && !preg_match(self::OK_RE, $out)) {
            throw new RuntimeException("Failed to delete ONU: $out");
        }
        
        // Delete from database
        OltOnu::where('olt_id', $this->olt->id)
            ->where('fsp', $fsp)
            ->where('onu_id', $onuId)
            ->delete();
        
        $this->logAction('delete', 'success', "ONU {$fsp}:{$onuId} deleted");
        
        return true;
    }

    /**
     * Restart ONU
     */
    public function restartOnu(string $fsp, int $onuId): bool
    {
        $this->sendCommand('configure terminal');
        $this->sendCommand("pon-onu-mng gpon-onu_{$fsp}:{$onuId}");
        $out = $this->sendCommand('reboot');
        $this->sendCommand('exit');
        $this->sendCommand('exit');
        
        $this->logAction('restart', 'success', "ONU {$fsp}:{$onuId} restarted");
        
        return true;
    }

    /**
     * Write config to memory
     */
    public function writeConfig(): bool
    {
        $out = $this->sendCommand('write');
        $this->logAction('write_config', 'success', 'Configuration saved');
        return true;
    }

    /**
     * Find available ONU ID for FSP
     */
    private function findAvailableOnuId(string $fsp): int
    {
        $out = $this->sendCommand("show gpon onu state gpon-olt_{$fsp}");
        $usedIds = [];
        
        $lines = preg_split('/\r?\n/', $out);
        foreach ($lines as $line) {
            if (preg_match(self::GPON_ONU_ID_RE, $line, $m)) {
                $usedIds[(int)$m[2]] = true;
            } elseif (preg_match(self::ONU_STATE_FSP_COLON_ID_RE, $line, $m)) {
                $usedIds[(int)$m[2]] = true;
            } elseif (preg_match(self::ONU_STATE_ID_RE, $line, $m)) {
                $usedIds[(int)$m[1]] = true;
            }
        }
        
        for ($i = 1; $i <= self::ONU_ID_MAX; $i++) {
            if (!isset($usedIds[$i])) {
                return $i;
            }
        }
        
        throw new RuntimeException('No available ONU ID (max 128)');
    }

    /**
     * Save ONU to database
     */
    private function saveOnuToDb(string $fsp, int $onuId, string $sn, string $name): void
    {
        OltOnu::updateOrCreate(
            [
                'olt_id' => $this->olt->id,
                'fsp' => $fsp,
                'onu_id' => $onuId,
            ],
            [
                'tenant_id' => $this->tenantId,
                'sn' => $sn,
                'name' => $name,
                'status' => 'online',
                'synced_at' => now(),
            ]
        );
    }

    /**
     * Log OLT action
     */
    private function logAction(string $action, string $status, string $message): void
    {
        if (!$this->olt) return;
        
        OltLog::create([
            'tenant_id' => $this->tenantId,
            'olt_id' => $this->olt->id,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'user_id' => auth()->id(),
        ]);
    }

    // ========== Parser Methods ==========

    private function parseUncfgOutput(string $out): array
    {
        $items = [];
        $lines = preg_split('/\r?\n/', $out);
        foreach ($lines as $line) {
            if (!trim($line)) continue;
            if (preg_match(self::UNCFG_LINE_RE, $line, $m)) {
                $items[] = ['fsp' => $m[1], 'sn' => $m[2]];
                continue;
            }
            $tokens = preg_split('/\s+/', trim($line));
            if (!$tokens) continue;
            $fsp = '';
            foreach ($tokens as $t) {
                if (preg_match(self::FSP_TOKEN_RE, $t)) { $fsp = $t; break; }
                if (preg_match(self::GPON_ONU_RE, $t, $m2)) { $fsp = $m2[1]; break; }
            }
            if (!$fsp) continue;
            $sn = '';
            for ($i = count($tokens) - 1; $i >= 0; $i--) {
                if (preg_match(self::SN_STRICT_RE, $tokens[$i])) { $sn = $tokens[$i]; break; }
            }
            if (!$sn) {
                for ($i = count($tokens) - 1; $i >= 0; $i--) {
                    if (preg_match(self::SN_TOKEN_RE, $tokens[$i])) { $sn = $tokens[$i]; break; }
                }
            }
            if ($sn) $items[] = ['fsp' => $fsp, 'sn' => $sn];
        }
        return $items;
    }

    private function parseFspList(string $out): array
    {
        $list = [];
        $seen = [];
        $lines = preg_split('/\r?\n/', $out);
        foreach ($lines as $line) {
            $raw = trim($line);
            if ($raw === '') continue;
            if (preg_match('/\b(\d+\/\d+\/\d+):\d+\b/', $raw, $m)) {
                $fsp = $m[1];
            } elseif (preg_match('/gpon-onu_(\d+\/\d+\/\d+)/i', $raw, $m)) {
                $fsp = $m[1];
            } else {
                continue;
            }
            if (isset($seen[$fsp])) continue;
            $seen[$fsp] = true;
            $list[] = $fsp;
        }
        
        // Sort FSP list
        usort($list, function($a, $b) {
            $pa = array_map('intval', explode('/', $a));
            $pb = array_map('intval', explode('/', $b));
            if ($pa[0] !== $pb[0]) return $pa[0] <=> $pb[0];
            if ($pa[1] !== $pb[1]) return $pa[1] <=> $pb[1];
            return $pa[2] <=> $pb[2];
        });
        
        return $list;
    }

    private function parseBaseinfoOutput(string $out, string $fsp): array
    {
        $items = [];
        $lines = preg_split('/\r?\n/', $out);
        
        foreach ($lines as $line) {
            $raw = trim($line);
            if ($raw === '' || preg_match('/^\s*OnuIndex|^\s*-{3,}|^\s*F\/S\/P/i', $raw)) continue;
            
            // Parse line: ONU_ID, SN, STATUS, etc.
            if (preg_match('/^\s*(\d+)\s+/', $raw, $m)) {
                $onuId = (int)$m[1];
                $tokens = preg_split('/\s+/', $raw);
                
                $sn = '';
                $status = 'offline';
                $name = '';
                
                foreach ($tokens as $t) {
                    if (preg_match(self::SN_STRICT_RE, $t)) $sn = $t;
                    if (preg_match('/^(ready|working|online)$/i', $t)) $status = 'online';
                    if (preg_match('/^(offline|los|oos|dying)$/i', $t)) $status = 'offline';
                }
                
                // Get name from last non-status token
                for ($i = count($tokens) - 1; $i >= 0; $i--) {
                    $t = $tokens[$i];
                    if (!preg_match('/^(\d+|ready|working|online|offline|los|oos|dying|[A-Z]{4}[A-F0-9]{8})$/i', $t)) {
                        $name = $t;
                        break;
                    }
                }
                
                $items[] = [
                    'fsp' => $fsp,
                    'onu_id' => $onuId,
                    'sn' => $sn,
                    'status' => $status,
                    'name' => $name,
                    'rx' => null,
                ];
            }
        }
        
        return $items;
    }

    private function parseRxOutput(string $out): array
    {
        $rxMap = [];
        $lines = preg_split('/\r?\n/', $out);
        $currentFsp = '';
        
        foreach ($lines as $line) {
            if (preg_match('/gpon-olt_(\d+\/\d+\/\d+)/i', $line, $m)) {
                $currentFsp = $m[1];
            }
            
            if (preg_match('/^\s*(\d+)\s+/', $line, $m)) {
                $onuId = (int)$m[1];
                // Look for RX power (negative dBm value)
                if (preg_match('/-?\d+\.\d+/', $line, $rxMatch)) {
                    $key = $currentFsp . ':' . $onuId;
                    $rxMap[$key] = floatval($rxMatch[0]);
                }
            }
        }
        
        return $rxMap;
    }

    private function parseOnuDetail(string $out): array
    {
        $detail = [
            'sn' => '',
            'name' => '',
            'status' => 'offline',
            'distance' => '',
            'uptime' => '',
            'rx_power' => '',
            'tx_power' => '',
            'temperature' => '',
            'voltage' => '',
        ];
        
        $lines = preg_split('/\r?\n/', $out);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/ONU\s*Name\s*[:=]\s*(.+)/i', $line, $m)) {
                $detail['name'] = trim($m[1]);
            }
            if (preg_match('/SN\s*[:=]\s*([A-Z0-9]+)/i', $line, $m)) {
                $detail['sn'] = $m[1];
            }
            if (preg_match('/State\s*[:=]\s*(\w+)/i', $line, $m)) {
                $state = strtolower($m[1]);
                $detail['status'] = in_array($state, ['ready', 'working', 'online']) ? 'online' : 'offline';
            }
            if (preg_match('/Distance\s*[:=]\s*([\d.]+)/i', $line, $m)) {
                $detail['distance'] = $m[1] . ' m';
            }
            if (preg_match('/Rx\s*(?:optical\s*)?power\s*[:=]\s*(-?[\d.]+)/i', $line, $m)) {
                $detail['rx_power'] = $m[1] . ' dBm';
            }
            if (preg_match('/Tx\s*(?:optical\s*)?power\s*[:=]\s*(-?[\d.]+)/i', $line, $m)) {
                $detail['tx_power'] = $m[1] . ' dBm';
            }
            if (preg_match('/Temperature\s*[:=]\s*(-?[\d.]+)/i', $line, $m)) {
                $detail['temperature'] = $m[1] . ' °C';
            }
            if (preg_match('/Voltage\s*[:=]\s*([\d.]+)/i', $line, $m)) {
                $detail['voltage'] = $m[1] . ' V';
            }
            if (preg_match('/Uptime\s*[:=]\s*(.+)/i', $line, $m)) {
                $detail['uptime'] = trim($m[1]);
            }
        }
        
        return $detail;
    }

    /**
     * Sync all ONUs to database
     */
    public function syncAllOnusToDb(): int
    {
        $fspList = $this->listFsp();
        $count = 0;
        
        foreach ($fspList as $fsp) {
            $onus = $this->loadRegisteredFsp($fsp);
            foreach ($onus as $onu) {
                OltOnu::updateOrCreate(
                    [
                        'olt_id' => $this->olt->id,
                        'fsp' => $onu['fsp'],
                        'onu_id' => $onu['onu_id'],
                    ],
                    [
                        'tenant_id' => $this->tenantId,
                        'sn' => $onu['sn'] ?? '',
                        'name' => $onu['name'] ?? '',
                        'status' => $onu['status'] ?? 'offline',
                        'rx' => $onu['rx'] ?? null,
                        'synced_at' => now(),
                    ]
                );
                $count++;
            }
        }
        
        // Update FSP cache
        $this->olt->updateFspCache($fspList);
        
        $this->logAction('sync_all', 'success', "Synced {$count} ONUs");
        
        return $count;
    }
}

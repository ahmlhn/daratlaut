<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OltLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

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

    // When enabled by controller, we suppress per-method logging and/or collect a command transcript.
    private bool $suppressActionLog = false;
    private bool $traceEnabled = false;
    private array $trace = [];

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function setSuppressActionLog(bool $flag): void
    {
        $this->suppressActionLog = $flag;
    }

    public function startTrace(): void
    {
        $this->traceEnabled = true;
        $this->trace = [];
    }

    public function stopTrace(): void
    {
        $this->traceEnabled = false;
    }

    public function getTraceText(int $max = 20000): string
    {
        if (empty($this->trace)) {
            return '';
        }

        $parts = [];
        foreach ($this->trace as $it) {
            $cmd = (string) ($it['cmd'] ?? '');
            $out = trim((string) ($it['out'] ?? ''));
            if ($cmd === '') continue;
            $parts[] = ">>> {$cmd}\n{$out}";
        }

        $text = trim(implode("\n\n", $parts));
        if ($text === '') return '';
        if (strlen($text) <= $max) return $text;
        return substr($text, 0, $max) . "\n...[TRUNCATED]...";
    }

    private function traceAdd(string $cmd, string $out): void
    {
        if (!$this->traceEnabled) return;

        $c = strtolower(trim((string) $cmd));
        if ($c === '') return;

        // Native parity: keep logs focused on config-changing commands.
        // Skip "show ..." commands unless they contain an error (usually short and useful).
        if (str_starts_with($c, 'show ')) {
            if (!preg_match(self::ERROR_RE, $out) || preg_match(self::OK_RE, $out)) {
                return;
            }
        }

        // Avoid noise from paging/terminal tweaks.
        if (in_array($c, [
            'terminal length 0',
            'terminal page 0',
            'scroll 0',
            'screen-length 0 temporary',
            'screen-length 0 permanent',
        ], true)) {
            return;
        }

        $this->trace[] = [
            'cmd' => $cmd,
            'out' => $out,
        ];
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
            if (!$this->suppressActionLog) {
                $this->logAction('connect', 'failed', $e->getMessage());
            }
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
        $out = str_replace("\r", "", $raw);
        $this->traceAdd($cmd, $out);
        return $out;
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
        $this->traceAdd($cmd, $out);
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
        // Try "show card" first (native parity + faster on some firmwares).
        $cardOut = $this->sendCommand('show card');
        $list = $this->parseFspFromCardOutput($cardOut);
        if (!empty($list)) {
            return $list;
        }

        // Fallback to "show gpon onu state".
        $out = $this->sendCommand('show gpon onu state');
        return $this->parseFspList($out);
    }

    /**
     * Load registered ONUs for an FSP
     */
    public function loadRegisteredFsp(string $fsp): array
    {
        // Baseinfo (status + SN) - output format is firmware-sensitive; parser must handle native C320 shapes.
        $out = $this->sendCommand("show gpon onu baseinfo gpon-olt_{$fsp}");
        $items = $this->parseBaseinfoOutput($out, $fsp);

        // RX power (native parity): try "show pon power onu-rx" first, then fallback to optical-info.
        $rxMap = [];
        try {
            $rxOut = $this->sendCommand("show pon power onu-rx gpon-olt_{$fsp}");
            $rxMap = $this->parseRxOutput($rxOut, $fsp);
        } catch (Throwable $e) {
            // ignore
        }

        if (empty($rxMap)) {
            $rxOut = $this->sendCommand("show gpon onu optical-info gpon-olt_{$fsp}");
            $rxMap = $this->parseRxOutput($rxOut, $fsp);
        }

        foreach ($items as &$item) {
            $key = (string)($item['fsp'] ?? '') . ':' . (string)($item['onu_id'] ?? '');
            if (isset($rxMap[$key])) {
                $item['rx'] = $rxMap[$key];
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Get ONU detail info
     */
    public function getOnuDetail(string $fsp, int $onuId): array
    {
        $out = $this->sendCommand("show gpon onu detail-info gpon-onu_{$fsp}:{$onuId}");
        $detail = $this->parseOnuDetail($out);

        // Refresh single ONU attenuation (Rx) directly from OLT.
        $rx = null;
        $rxKey = $fsp . ':' . $onuId;
        try {
            $rxOut = $this->sendCommand("show pon power onu-rx gpon-olt_{$fsp}");
            $rxMap = $this->parseRxOutput($rxOut, $fsp);
            if (isset($rxMap[$rxKey])) {
                $rx = (float) $rxMap[$rxKey];
            }
        } catch (Throwable $e) {
            // ignore and try fallback command below
        }

        if ($rx === null) {
            try {
                $rxOut = $this->sendCommand("show gpon onu optical-info gpon-olt_{$fsp}");
                $rxMap = $this->parseRxOutput($rxOut, $fsp);
                if (isset($rxMap[$rxKey])) {
                    $rx = (float) $rxMap[$rxKey];
                }
            } catch (Throwable $e) {
                // ignore and try fallback parse from detail-info text
            }
        }

        if ($rx === null) {
            $rxPower = (string) ($detail['rx_power'] ?? '');
            if (preg_match('/-?\d+(?:\.\d+)?/', $rxPower, $m)) {
                $rx = (float) $m[0];
            }
        }

        if ($rx !== null) {
            $detail['rx'] = $rx;
        }

        // Native parity: save detail (name + online_duration) into DB cache when possible.
        $sn = trim((string)($detail['sn'] ?? $detail['serial'] ?? ''));
        if ($sn !== '') {
            $this->saveOnuDetailToDb($sn, $fsp, $onuId, $detail);
        }

        return $detail;
    }

    /**
     * Register ONU
     */
    public function registerOnu(string $fsp, string $sn, string $name, ?int $onuId = null): array
    {
        // Native parity + safety: remove whitespace and unsafe CLI chars; keep it short.
        $name = $this->sanitizeOnuName($name);
        if ($name === '') {
            throw new RuntimeException('Nama ONU tidak valid (kosong setelah sanitasi).');
        }

        $sn = preg_replace('/[^A-Za-z0-9]/', '', (string) $sn);
        if ($sn === '') {
            throw new RuntimeException('SN ONU tidak valid.');
        }

        // Find available ONU ID if not specified
        if ($onuId === null) {
            $onuId = $this->findAvailableOnuId($fsp);
        }

        $tcont = $this->sanitizeCliToken((string) ($this->olt->tcont_default ?? self::DEFAULT_TCONT_PROFILE));
        if ($tcont === '') $tcont = self::DEFAULT_TCONT_PROFILE;

        $onuType = $this->sanitizeCliToken((string) ($this->olt->onu_type_default ?? self::DEFAULT_ONU_TYPE));
        if ($onuType === '') $onuType = self::DEFAULT_ONU_TYPE;

        $vlan = (int) ($this->olt->vlan_default ?? self::DEFAULT_VLAN);
        if ($vlan < 1 || $vlan > 4094) $vlan = self::DEFAULT_VLAN;

        $spid = (int) ($this->olt->service_port_id_default ?? self::DEFAULT_SERVICE_PORT_ID);
        if ($spid < 1 || $spid > 65535) $spid = self::DEFAULT_SERVICE_PORT_ID;

        $description = substr("AUTO-PROV-{$sn}", 0, 64);
        $nameApplied = false;

        // Native command parity with api_olt.php (manual + auto use the same provisioning sequence).
        $this->enterConfigMode();
        try {
            $this->sendCommandStrict("interface gpon-olt_{$fsp}");
            $this->sendCommandStrict("onu {$onuId} type {$onuType} sn {$sn}", 25, true);
            $this->sendCommandStrict('exit');

            $this->sendCommandStrict("interface gpon-onu_{$fsp}:{$onuId}");
            $this->sendCommandStrict("name {$name}");
            $this->sendCommandStrict("description {$description}");
            $this->sendCommandStrict('sn-bind enable sn');
            $this->sendCommandStrict("tcont 1 name T1 profile {$tcont}");
            $this->sendCommandStrict('gemport 1 name G1 tcont 1');
            $this->sendCommandStrict('encrypt 1 enable downstream');
            $this->applyServicePortWithFallback($fsp, $onuId, $spid, $vlan);
            $this->sendCommandStrict("service-port {$spid} description Internet");
            $this->sendCommandStrict('exit');

            $this->sendCommandStrict("pon-onu-mng gpon-onu_{$fsp}:{$onuId}");
            $this->sendCommandStrict("service internet gemport 1 vlan {$vlan}");
            $this->sendCommandStrict('exit');
            $nameApplied = true;
        } finally {
            // Always return to exec mode so subsequent actions stay predictable.
            $this->sendCommand('end');
        }

        // Save to database
        $this->saveOnuToDb($fsp, $onuId, $sn, $name);

        // Log action
        if (!$this->suppressActionLog) {
            $this->logAction('register', 'success', "ONU {$fsp}:{$onuId} ({$sn}) registered as '{$name}'");
        }

        return [
            'fsp' => $fsp,
            'onu_id' => $onuId,
            'sn' => $sn,
            'name' => $name,
            'name_applied' => $nameApplied,
        ];
    }

    private function sanitizeOnuName(string $name): string
    {
        $name = trim((string) $name);
        $name = preg_replace('/\s+/', '', $name);
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '', $name);
        return substr($name, 0, 32);
    }

    private function sanitizeCliToken(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', trim($value));
    }

    private function outputHasError(string $out): bool
    {
        return (bool) preg_match(self::ERROR_RE, $out) && !preg_match(self::OK_RE, $out);
    }

    private function commandException(string $cmd, string $out): RuntimeException
    {
        $flat = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $out)));
        if ($flat === '') $flat = 'unknown error';
        if (strlen($flat) > 260) $flat = substr($flat, 0, 260) . '...';
        return new RuntimeException("Command gagal ({$cmd}): {$flat}");
    }

    private function sendCommandStrict(string $cmd, int $timeout = 18, bool $confirm = false): string
    {
        $out = $confirm
            ? $this->sendCommandConfirm($cmd, $timeout)
            : $this->sendCommand($cmd, $timeout);

        if ($this->outputHasError($out)) {
            throw $this->commandException($cmd, $out);
        }

        return $out;
    }

    private function enterConfigMode(): void
    {
        $out = $this->sendCommand('conf t');
        if (!$this->outputHasError($out)) {
            return;
        }

        $outAlt = $this->sendCommand('configure terminal');
        if ($this->outputHasError($outAlt)) {
            throw $this->commandException('conf t|configure terminal', $out . "\n" . $outAlt);
        }
    }

    private function buildServicePortCommandVariants(string $fsp, int $onuId, int $spid, int $vlan): array
    {
        $parts = explode('/', $fsp);
        $f = (int) ($parts[0] ?? 0);
        $s = (int) ($parts[1] ?? 0);
        $p = (int) ($parts[2] ?? 0);

        return [
            "service-port {$spid} vport 1 user-vlan {$vlan} vlan {$vlan}",
            "service-port {$spid} vlan {$vlan} gpon {$f}/{$s}/{$p} onu {$onuId} gemport 1 multi-service user-vlan {$vlan} tag-transform translate",
            "service-port {$spid} vlan {$vlan} gpon {$f}/{$s}/{$p} onu {$onuId} gemport 1 multi-service user-vlan {$vlan}",
            "service-port {$spid} vlan {$vlan} gpon {$f}/{$s}/{$p} onu {$onuId} gemport 1 multi-service",
        ];
    }

    private function applyServicePortWithFallback(string $fsp, int $onuId, int $spid, int $vlan): void
    {
        $lastOut = '';
        $lastCmd = 'service-port';
        foreach ($this->buildServicePortCommandVariants($fsp, $onuId, $spid, $vlan) as $cmd) {
            $out = $this->sendCommand($cmd, 25);
            if (!$this->outputHasError($out)) {
                return;
            }
            $lastCmd = $cmd;
            $lastOut = $out;
        }
        throw $this->commandException($lastCmd, $lastOut);
    }

    /**
     * Update ONU name
     */
    public function updateOnuName(string $fsp, int $onuId, string $name): bool
    {
        // Native parity + safety: remove whitespace and unsafe CLI chars; keep it short.
        $name = trim((string) $name);
        $name = preg_replace('/\s+/', '', $name);
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '', $name);
        $name = substr($name, 0, 32);
        if ($name === '') {
            throw new RuntimeException('Nama ONU tidak valid (kosong setelah sanitasi).');
        }

        // Native parity: prefer "interface gpon-onu_...; name ..."
        $this->sendCommand('configure terminal');
        $out1 = $this->sendCommand("interface gpon-onu_{$fsp}:{$onuId}");
        $out2 = $this->sendCommand("name {$name}");
        $out3 = $this->sendCommand('end');
        $out = $out1 . "\n" . $out2 . "\n" . $out3;

        // Fallback for some firmwares: "interface gpon-olt_...; onu {id} name ..."
        if (preg_match(self::ERROR_RE, $out)) {
            $this->sendCommand('configure terminal');
            $this->sendCommand("interface gpon-olt_{$fsp}");
            $outAlt = $this->sendCommand("onu {$onuId} name {$name}");
            $this->sendCommand('end');
            $out .= "\n" . $outAlt;

            if (preg_match(self::ERROR_RE, $outAlt)) {
                throw new RuntimeException("Failed to update ONU name: $outAlt");
            }
        }

        // Update database cache (best-effort; schema differs across deployments).
        try {
            $table = (new OltOnu())->getTable();
            if (Schema::hasTable($table)) {
                $updates = [];
                if (Schema::hasColumn($table, 'onu_name')) $updates['onu_name'] = $name;
                elseif (Schema::hasColumn($table, 'name')) $updates['name'] = $name;

                $now = now();
                if (Schema::hasColumn($table, 'last_detail_at')) $updates['last_detail_at'] = $now;
                if (Schema::hasColumn($table, 'last_seen_at')) $updates['last_seen_at'] = $now;

                if (!empty($updates)) {
                    $q = OltOnu::where('olt_id', $this->olt->id)
                        ->where('fsp', $fsp)
                        ->where('onu_id', $onuId);

                    if (Schema::hasColumn($table, 'tenant_id')) {
                        $q->where('tenant_id', $this->tenantId);
                    }

                    $q->update($updates);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        if (!$this->suppressActionLog) {
            $this->logAction('update_onu_detail', 'done', "ONU {$fsp}:{$onuId} renamed to '{$name}'");
        }
        
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
        
        // Delete from database cache (best-effort; schema differs across deployments).
        try {
            $table = (new OltOnu())->getTable();
            if (Schema::hasTable($table)) {
                $q = OltOnu::where('olt_id', $this->olt->id)
                    ->where('fsp', $fsp)
                    ->where('onu_id', $onuId);

                if (Schema::hasColumn($table, 'tenant_id')) {
                    $q->where('tenant_id', $this->tenantId);
                }

                $row = $q->first();
                $sn = (string)($row?->sn ?? $row?->getAttribute('serial_number') ?? '');

                $q->delete();

                if ($sn !== '') {
                    $q2 = OltOnu::where('olt_id', $this->olt->id);
                    if (Schema::hasColumn($table, 'tenant_id')) {
                        $q2->where('tenant_id', $this->tenantId);
                    }

                    if (Schema::hasColumn($table, 'sn')) {
                        $q2->where('sn', $sn)->delete();
                    } elseif (Schema::hasColumn($table, 'serial_number')) {
                        $q2->where('serial_number', $sn)->delete();
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        if (!$this->suppressActionLog) {
            $this->logAction('delete_onu', 'done', "ONU {$fsp}:{$onuId} deleted");
        }
        
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
        
        if (!$this->suppressActionLog) {
            $this->logAction('restart_onu', 'done', "ONU {$fsp}:{$onuId} restarted");
        }
        
        return true;
    }

    /**
     * Write config to memory
     */
    public function writeConfig(): bool
    {
        // "write" may take a while on some OLTs; use a longer timeout (native uses ~60s).
        $out = $this->sendCommand('write', 60);
        if (!$this->suppressActionLog) {
            $this->logAction('write', 'done', 'Configuration saved');
        }
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
    private function saveOnuToDb(
        string $fsp,
        int $onuId,
        string $sn,
        string $name,
        bool $setRegisteredAt = true,
        bool $preserveRegisteredAt = true,
        ?float $rxPower = null
    ): void
    {
        if (!$this->olt) return;

        $table = (new OltOnu())->getTable();
        static $tableChecked = null;
        if ($tableChecked === null) {
            $tableChecked = Schema::hasTable($table);
        }
        if (!$tableChecked) return;

        static $snCol = null;
        if ($snCol === null) {
            if (Schema::hasColumn($table, 'sn')) $snCol = 'sn';
            elseif (Schema::hasColumn($table, 'serial_number')) $snCol = 'serial_number';
            else $snCol = 'sn';
        }

        static $nameCol = null;
        if ($nameCol === null) {
            if (Schema::hasColumn($table, 'onu_name')) $nameCol = 'onu_name';
            elseif (Schema::hasColumn($table, 'name')) $nameCol = 'name';
            else $nameCol = null;
        }

        static $hasLastSeenAt = null;
        if ($hasLastSeenAt === null) {
            $hasLastSeenAt = Schema::hasColumn($table, 'last_seen_at');
        }

        static $hasRegisteredAt = null;
        if ($hasRegisteredAt === null) {
            $hasRegisteredAt = Schema::hasColumn($table, 'registered_at');
        }

        static $hasRxPower = null;
        if ($hasRxPower === null) {
            $hasRxPower = Schema::hasColumn($table, 'rx_power');
        }

        $key = [
            'tenant_id' => $this->tenantId,
            'olt_id' => $this->olt->id,
            $snCol => $sn,
        ];

        $now = now();

        $values = [
            'tenant_id' => $this->tenantId,
            'olt_id' => $this->olt->id,
            'fsp' => $fsp,
            'onu_id' => $onuId,
        ];

        if ($nameCol && $name !== '') {
            $values[$nameCol] = $name;
        }

        if ($hasLastSeenAt) {
            $values['last_seen_at'] = $now;
        }

        // Preserve existing registered_at when present (native parity).
        $shouldSetRegisteredAt = false;
        if ($setRegisteredAt && $hasRegisteredAt) {
            if (!$preserveRegisteredAt) {
                $shouldSetRegisteredAt = true;
            } else {
                try {
                    $exists = DB::table($table)->where($key)->exists();
                    $shouldSetRegisteredAt = !$exists;
                } catch (Throwable $e) {
                    $shouldSetRegisteredAt = true;
                }
            }
        }
        if ($shouldSetRegisteredAt) {
            $values['registered_at'] = $now;
        }

        if ($hasRxPower && $rxPower !== null) {
            $values['rx_power'] = round((float) $rxPower, 2);
        }

        try {
            DB::table($table)->updateOrInsert($key, $values);
        } catch (Throwable $e) {
            // Best-effort cache write; avoid breaking telnet flow.
        }
    }

    private function saveOnuDetailToDb(string $sn, string $fsp, int $onuId, array $detail): void
    {
        if (!$this->olt) return;

        $table = (new OltOnu())->getTable();
        static $tableChecked = null;
        if ($tableChecked === null) {
            $tableChecked = Schema::hasTable($table);
        }
        if (!$tableChecked) return;

        static $snCol = null;
        if ($snCol === null) {
            if (Schema::hasColumn($table, 'sn')) $snCol = 'sn';
            elseif (Schema::hasColumn($table, 'serial_number')) $snCol = 'serial_number';
            else $snCol = 'sn';
        }

        static $nameCol = null;
        if ($nameCol === null) {
            if (Schema::hasColumn($table, 'onu_name')) $nameCol = 'onu_name';
            elseif (Schema::hasColumn($table, 'name')) $nameCol = 'name';
            else $nameCol = null;
        }

        static $hasOnlineDuration = null;
        if ($hasOnlineDuration === null) {
            $hasOnlineDuration = Schema::hasColumn($table, 'online_duration');
        }

        static $hasLastDetailAt = null;
        if ($hasLastDetailAt === null) {
            $hasLastDetailAt = Schema::hasColumn($table, 'last_detail_at');
        }

        static $hasLastSeenAt = null;
        if ($hasLastSeenAt === null) {
            $hasLastSeenAt = Schema::hasColumn($table, 'last_seen_at');
        }

        static $hasRxPower = null;
        if ($hasRxPower === null) {
            $hasRxPower = Schema::hasColumn($table, 'rx_power');
        }

        $key = [
            'tenant_id' => $this->tenantId,
            'olt_id' => $this->olt->id,
            $snCol => $sn,
        ];

        $values = [
            'tenant_id' => $this->tenantId,
            'olt_id' => $this->olt->id,
            'fsp' => $fsp,
            'onu_id' => $onuId,
        ];

        $onuName = trim((string)($detail['name'] ?? ''));
        if ($nameCol && $onuName !== '') {
            $values[$nameCol] = $onuName;
        }

        if ($hasOnlineDuration) {
            $od = trim((string)($detail['online_duration'] ?? ''));
            if ($od !== '') {
                $values['online_duration'] = substr($od, 0, 32);
            }
        }

        $now = now();
        if ($hasLastDetailAt) $values['last_detail_at'] = $now;
        if ($hasLastSeenAt) $values['last_seen_at'] = $now;
        if ($hasRxPower) {
            $rx = $this->normalizeRxValue($detail['rx'] ?? ($detail['rx_power'] ?? null));
            if ($rx !== null) {
                $values['rx_power'] = round($rx, 2);
            }
        }

        try {
            DB::table($table)->updateOrInsert($key, $values);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function normalizeRxValue(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_float($raw) || is_int($raw)) {
            return is_finite((float) $raw) ? (float) $raw : null;
        }

        if (is_string($raw) && preg_match('/-?\d+(?:\.\d+)?/', $raw, $m)) {
            $val = (float) $m[0];
            return is_finite($val) ? $val : null;
        }

        return null;
    }

    private function storeRxHistoryBatch(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        static $historyTableChecked = null;
        if ($historyTableChecked === null) {
            $historyTableChecked = Schema::hasTable('noci_olt_rx_logs');
        }
        if (!$historyTableChecked) {
            return 0;
        }

        $saved = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            try {
                DB::table('noci_olt_rx_logs')->insert($chunk);
                $saved += count($chunk);
            } catch (Throwable $e) {
                // Best effort; skip failed chunk and continue.
            }
        }

        return $saved;
    }

    /**
     * Log OLT action
     */
    private function logAction(string $action, string $status, string $message): void
    {
        if (!$this->olt) return;

        $actor = null;
        try {
            $u = auth()->user();
            if ($u) {
                $actor = $u->name ?? $u->username ?? $u->email ?? null;
            }
        } catch (Throwable $e) {
            // ignore
        }

        try {
            OltLog::logAction(
                $this->tenantId,
                $this->olt->id,
                $this->olt->nama_olt,
                $action,
                $status,
                ['message' => $message],
                $message,
                $actor
            );
        } catch (Throwable $e) {
            // Best-effort logging.
        }
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

    private function isActiveCardStatus(string $status): bool
    {
        $status = strtoupper(trim($status));
        if ($status === '') return false;
        return in_array($status, ['INSERVICE', 'ACTIVE', 'WORKING', 'NORMAL', 'RUNNING'], true);
    }

    private function resolveCardPonPorts(string $cfgType, string $realType): int
    {
        $type = strtoupper(trim($realType));
        if ($type === '') $type = strtoupper(trim($cfgType));
        if (str_starts_with($type, 'GTGH')) return 16;
        if (str_starts_with($type, 'GTGO')) return 8;
        return 0;
    }

    private function pickFspFrame(int $rack, int $shelf): int
    {
        if ($rack > 1) return $rack;
        if ($shelf > 0) return $shelf;
        return $rack;
    }

    private function parseFspFromCardOutput(string $out): array
    {
        $list = [];
        $seen = [];
        $lines = preg_split('/\r?\n/', $out);

        foreach ($lines as $line) {
            $raw = trim($line);
            if ($raw === '') continue;
            if (stripos($raw, 'Rack') !== false && stripos($raw, 'Shelf') !== false && stripos($raw, 'Slot') !== false) continue;
            if (preg_match('/^-{3,}/', $raw)) continue;

            $tokens = preg_split('/\s+/', $raw);
            if (count($tokens) < 4) continue;

            $rack = (int)($tokens[0] ?? 0);
            $shelf = (int)($tokens[1] ?? 0);
            $slot = (int)($tokens[2] ?? 0);
            $cfgType = (string)($tokens[3] ?? '');
            $status = (string)($tokens[count($tokens) - 1] ?? '');

            if (!$this->isActiveCardStatus($status)) continue;

            $realType = '';
            if (isset($tokens[4])) {
                $candidate = (string)$tokens[4];
                $upper = strtoupper($candidate);
                if ($candidate !== '' && !ctype_digit($candidate) && !str_starts_with($upper, 'V') && $upper !== strtoupper($status)) {
                    $realType = $candidate;
                }
            }

            $ponCount = $this->resolveCardPonPorts($cfgType, $realType);
            if ($ponCount <= 0) continue;

            $frame = $this->pickFspFrame($rack, $shelf);
            if ($frame <= 0 || $slot <= 0) continue;

            for ($p = 1; $p <= $ponCount; $p++) {
                $fsp = $frame . '/' . $slot . '/' . $p;
                if (isset($seen[$fsp])) continue;
                $seen[$fsp] = true;
                $list[] = $fsp;
            }
        }

        usort($list, function ($a, $b) {
            $pa = array_map('intval', explode('/', $a));
            $pb = array_map('intval', explode('/', $b));
            if (($pa[0] ?? 0) !== ($pb[0] ?? 0)) return ($pa[0] ?? 0) <=> ($pb[0] ?? 0);
            if (($pa[1] ?? 0) !== ($pb[1] ?? 0)) return ($pa[1] ?? 0) <=> ($pb[1] ?? 0);
            return ($pa[2] ?? 0) <=> ($pb[2] ?? 0);
        });

        return $list;
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

    private function parseBaseinfoOutput(string $out, string $fallbackFsp): array
    {
        // Native C320 baseinfo output typically includes `gpon-onu_{fsp}:{onu_id}` per row.
        // Some firmwares/table views may start with ONU-ID only. Support both.
        $items = [];
        $seen = [];
        $lines = preg_split('/\r?\n/', $out);

        foreach ($lines as $line) {
            $raw = trim($line);
            if ($raw === '') continue;
            if (stripos($raw, 'OnuIndex') !== false) continue;
            if (preg_match('/^-{3,}/', $raw)) continue;
            if (preg_match('/^\s*F\/S\/P/i', $raw)) continue;

            $fsp = '';
            $onuId = null;

            if (preg_match(self::GPON_ONU_ID_RE, $raw, $m)) {
                $fsp = $m[1];
                $onuId = (int) $m[2];
            } elseif (preg_match(self::ONU_STATE_FSP_COLON_ID_RE, $raw, $m)) {
                $fsp = $m[1];
                $onuId = (int) $m[2];
            } elseif (preg_match(self::ONU_STATE_FSP_ID_RE, $raw, $m)) {
                $fsp = $m[1];
                $onuId = (int) $m[2];
            } elseif (preg_match('/^\s*(\d+)\s+/', $raw, $m)) {
                // Fallback: first column is ONU-ID, use provided FSP.
                $fsp = $fallbackFsp;
                $onuId = (int) $m[1];
            }

            if ($fsp === '' || !$onuId) continue;

            $key = $fsp . ':' . $onuId;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $sn = '';
            if (preg_match('/SN:([A-Za-z0-9]+)/i', $raw, $m2)) {
                $sn = $m2[1];
            } elseif (preg_match(self::SN_STRICT_RE, $raw, $m2)) {
                $sn = $m2[0];
            }

            $tokens = preg_split('/\s+/', $raw);
            $state = '';
            if (!empty($tokens)) {
                $last = $tokens[count($tokens) - 1];
                if (!preg_match('/^SN:/i', $last)) {
                    $state = $last;
                }
            }

            if ($state === '') {
                foreach ($tokens as $t) {
                    $tl = strtolower($t);
                    if (in_array($tl, ['ready', 'working', 'online', 'offline', 'los', 'oos', 'dying'], true)) {
                        $state = $t;
                        break;
                    }
                }
            }

            $status = in_array(strtolower(trim((string) $state)), ['ready', 'working', 'online'], true) ? 'online' : 'offline';

            $items[] = [
                'fsp' => $fsp,
                'onu_id' => $onuId,
                'interface' => "gpon-onu_{$fsp}:{$onuId}",
                'fsp_onu' => "{$fsp}:{$onuId}",
                'sn' => $sn,
                'state' => (string) $state,
                'status' => $status,
                'rx' => null,
                'name' => '',
                'online_duration' => '',
                'vlan' => 0,
            ];
        }

        return $items;
    }

    private function parseRxOutput(string $out, string $fallbackFsp = ''): array
    {
        // Return map keyed by "{fsp}:{onu_id}" to keep consumers stable across RX command variants.
        $rxMap = [];
        $lines = preg_split('/\r?\n/', $out);
        $currentFsp = $fallbackFsp;

        foreach ($lines as $line) {
            $raw = trim($line);
            if ($raw === '') continue;
            if (stripos($raw, 'Rx power') !== false) continue;
            if (preg_match('/^-{3,}/', $raw)) continue;

            if (preg_match('/gpon-olt_(\d+\/\d+\/\d+)/i', $raw, $m)) {
                $currentFsp = $m[1];
                continue;
            }

            // Native "show pon power onu-rx ..." output: `gpon-onu_{fsp}:{onu_id}  -23.1(dbm)`
            if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)\s+(-?\d+(?:\.\d+)?)/i', $raw, $m)) {
                $fsp = $m[1];
                $onuId = (int) $m[2];
                $val = (float) $m[3];
                $rxMap[$fsp . ':' . $onuId] = $val;
                continue;
            }

            // Some table formats: first column is ONU-ID; best-effort parse using currentFsp.
            if ($currentFsp !== '' && preg_match('/^\s*(\d+)\s+/', $raw, $m)) {
                $onuId = (int) $m[1];
                if (preg_match('/-?\d+(?:\.\d+)?/', $raw, $rxMatch)) {
                    $rxMap[$currentFsp . ':' . $onuId] = (float) $rxMatch[0];
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
            'online_duration' => '',
            'uptime' => '',
            'rx_power' => '',
            'tx_power' => '',
            'temperature' => '',
            'voltage' => '',
        ];
        
        $lines = preg_split('/\r?\n/', $out);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\s*ONU\s*Name\s*[:=]\s*(.+)$/i', $line, $m)) {
                $detail['name'] = trim($m[1]);
            }
            if (preg_match('/^\s*Name\s*[:=]\s*(.+)$/i', $line, $m)) {
                // Some firmwares use "Name:".
                $detail['name'] = trim($m[1]);
            }
            if (preg_match('/^\s*Serial\\s*number\\s*[:=]\\s*([A-Za-z0-9]+)/i', $line, $m)) {
                $detail['sn'] = trim($m[1]);
            }
            if (preg_match('/^\s*SN\\s*[:=]\\s*([A-Za-z0-9]+)/i', $line, $m)) {
                $detail['sn'] = $m[1];
            }
            if (preg_match('/State\s*[:=]\s*(\w+)/i', $line, $m)) {
                $state = strtolower($m[1]);
                $detail['status'] = in_array($state, ['ready', 'working', 'online']) ? 'online' : 'offline';
            }
            if (preg_match('/Distance\s*[:=]\s*([\d.]+)/i', $line, $m)) {
                $detail['distance'] = $m[1] . ' m';
            }
            if (preg_match('/^\s*Online\\s*Duration\\s*[:=]\\s*(.+)$/i', $line, $m)) {
                $detail['online_duration'] = trim($m[1]);
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
     * Find ONU by SN using telnet ("show gpon onu by sn {sn}")
     * Returns null when not found.
     */
    public function findOnuBySn(string $sn): ?array
    {
        $sn = preg_replace('/[^A-Za-z0-9]/', '', (string) $sn);
        if ($sn === '') {
            return null;
        }

        $out = $this->sendCommand("show gpon onu by sn {$sn}", 25);
        if (preg_match(self::ERROR_RE, $out) && !preg_match(self::OK_RE, $out)) {
            return null;
        }

        if (!preg_match(self::GPON_ONU_ID_RE, $out, $m)) {
            return null;
        }

        $fsp = (string) ($m[1] ?? '');
        $onuId = (int) ($m[2] ?? 0);
        if ($fsp === '' || $onuId <= 0) {
            return null;
        }

        return [
            'fsp' => $fsp,
            'onu_id' => $onuId,
            'interface' => "gpon-onu_{$fsp}:{$onuId}",
        ];
    }

    /**
     * Deep sync ONU names/detail into DB cache in chunks (native parity).
     *
     * Must be connected to the OLT before calling this method.
     */
    public function syncOnuNamesChunk(string $fsp, int $offset = 0, int $limit = 20, bool $onlyMissing = true): array
    {
        $fsp = trim((string) $fsp);
        if (!preg_match(self::FSP_TOKEN_RE, $fsp)) {
            throw new RuntimeException('FSP tidak valid.');
        }
        if ($offset < 0) {
            $offset = 0;
        }
        if ($limit <= 0) {
            $limit = 20;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $baseOut = $this->sendCommand("show gpon onu baseinfo gpon-olt_{$fsp}", 35);
        if (preg_match(self::ERROR_RE, $baseOut) && !preg_match(self::OK_RE, $baseOut)) {
            throw new RuntimeException('Gagal mengambil baseinfo.');
        }

        $items = $this->parseBaseinfoOutput($baseOut, $fsp);
        $total = count($items);

        if ($total === 0) {
            return [
                'fsp' => $fsp,
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
                'processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'next_offset' => null,
                'done' => true,
            ];
        }

        if ($offset >= $total) {
            return [
                'fsp' => $fsp,
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
                'processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'next_offset' => null,
                'done' => true,
            ];
        }

        $slice = array_slice($items, $offset, $limit);

        // Build a quick name map from DB when we only want to fetch missing names.
        $existingNameBySn = [];
        if ($onlyMissing && $this->olt) {
            $table = (new OltOnu())->getTable();
            if (Schema::hasTable($table)) {
                static $snCol = null;
                if ($snCol === null) {
                    if (Schema::hasColumn($table, 'sn')) $snCol = 'sn';
                    elseif (Schema::hasColumn($table, 'serial_number')) $snCol = 'serial_number';
                    else $snCol = 'sn';
                }
                static $nameCol = null;
                if ($nameCol === null) {
                    if (Schema::hasColumn($table, 'onu_name')) $nameCol = 'onu_name';
                    elseif (Schema::hasColumn($table, 'name')) $nameCol = 'name';
                    else $nameCol = null;
                }

                if ($nameCol) {
                    $snList = [];
                    foreach ($slice as $it) {
                        $sn2 = preg_replace('/[^A-Za-z0-9]/', '', (string) ($it['sn'] ?? ''));
                        if ($sn2 !== '') $snList[$sn2] = true;
                    }
                    $sns = array_keys($snList);
                    if (!empty($sns)) {
                        try {
                            $rows = DB::table($table)
                                ->where('tenant_id', $this->tenantId)
                                ->where('olt_id', $this->olt->id)
                                ->whereIn($snCol, $sns)
                                ->get([$snCol, $nameCol]);
                            foreach ($rows as $row) {
                                $sn3 = (string) ($row->{$snCol} ?? '');
                                if ($sn3 === '') continue;
                                $existingNameBySn[$sn3] = trim((string) ($row->{$nameCol} ?? ''));
                            }
                        } catch (Throwable $e) {
                            // ignore
                        }
                    }
                }
            }
        }

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($slice as $it) {
            $processed++;
            $sn4 = preg_replace('/[^A-Za-z0-9]/', '', (string) ($it['sn'] ?? ''));
            $onuId = (int) ($it['onu_id'] ?? 0);
            $existsName = $sn4 !== '' ? (string) ($existingNameBySn[$sn4] ?? '') : '';

            if ($onlyMissing && $existsName !== '' && $onuId > 0 && $sn4 !== '') {
                // Ensure registration fields are up to date without fetching detail-info again.
                $this->saveOnuToDb($fsp, $onuId, $sn4, $existsName, true, true);
                $skipped++;
                continue;
            }

            if ($onuId <= 0) {
                $errors++;
                continue;
            }

            $detailOut = $this->sendCommand("show gpon onu detail-info gpon-onu_{$fsp}:{$onuId}", 35);
            if (preg_match(self::ERROR_RE, $detailOut) && !preg_match(self::OK_RE, $detailOut)) {
                $errors++;
                continue;
            }

            $detail = $this->parseOnuDetail($detailOut);
            $snDetail = trim((string) ($detail['sn'] ?? $detail['serial'] ?? ''));
            $snDetail = preg_replace('/[^A-Za-z0-9]/', '', $snDetail);
            if ($snDetail === '') {
                $snDetail = $sn4;
            }
            if ($snDetail === '') {
                $errors++;
                continue;
            }

            $this->saveOnuDetailToDb($snDetail, $fsp, $onuId, $detail);
            $updated++;
            usleep(150000);
        }

        $nextOffset = $offset + count($slice);
        $done = $nextOffset >= $total;

        return [
            'fsp' => $fsp,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'processed' => $processed,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'next_offset' => $done ? null : $nextOffset,
            'done' => $done,
        ];
    }

    /**
     * Sync all ONUs to database
     */
    private function syncAllOnusToDbInternal(bool $captureRxHistory = false): array
    {
        $fspList = $this->listFsp();
        $count = 0;
        $rxCacheUpdated = 0;
        $rxSamplesSaved = 0;
        $historyRows = [];
        $sampledAt = now();
        $createdAt = now();

        foreach ($fspList as $fsp) {
            $onus = $this->loadRegisteredFsp($fsp);
            foreach ($onus as $onu) {
                $sn = trim((string)($onu['sn'] ?? ''));
                if ($sn === '') continue;

                $onuId = (int)($onu['onu_id'] ?? 0);
                if ($onuId <= 0) continue;

                $name = trim((string)($onu['name'] ?? ''));
                $rx = $this->normalizeRxValue($onu['rx'] ?? null);
                // For bulk sync we avoid per-row existence checks.
                $this->saveOnuToDb($fsp, $onuId, $sn, $name, false, false, $rx);
                if ($rx !== null) {
                    $rxCacheUpdated++;
                    if ($captureRxHistory) {
                        $historyRows[] = [
                            'tenant_id' => $this->tenantId,
                            'olt_id' => (int) $this->olt->id,
                            'fsp' => $fsp,
                            'onu_id' => $onuId,
                            'sn' => $sn,
                            'rx_power' => round($rx, 2),
                            'sampled_at' => $sampledAt,
                            'created_at' => $createdAt,
                        ];
                    }
                }
                $count++;
            }

            if ($captureRxHistory && count($historyRows) >= 500) {
                $rxSamplesSaved += $this->storeRxHistoryBatch($historyRows);
                $historyRows = [];
            }
        }

        if ($captureRxHistory && !empty($historyRows)) {
            $rxSamplesSaved += $this->storeRxHistoryBatch($historyRows);
        }

        // Update FSP cache
        $this->olt->updateFspCache($fspList);

        return [
            'synced_count' => $count,
            'fsp_count' => count($fspList),
            'rx_cache_updated' => $rxCacheUpdated,
            'rx_samples_saved' => $rxSamplesSaved,
        ];
    }

    /**
     * Sync all ONUs to database.
     */
    public function syncAllOnusToDb(): int
    {
        $res = $this->syncAllOnusToDbInternal(false);
        $count = (int) ($res['synced_count'] ?? 0);

        if (!$this->suppressActionLog) {
            $this->logAction('sync_registered_all', 'done', "Synced {$count} ONUs");
        }

        return $count;
    }

    /**
     * Scheduled OLT sync mode:
     * - Sync ONU cache data
     * - Refresh cached `rx_power`
     * - Save Rx snapshot history rows
     */
    public function syncAllOnusToDbScheduled(): array
    {
        $res = $this->syncAllOnusToDbInternal(true);

        if (!$this->suppressActionLog) {
            $count = (int) ($res['synced_count'] ?? 0);
            $rxCount = (int) ($res['rx_samples_saved'] ?? 0);
            $this->logAction('sync_registered_all_scheduled', 'done', "Synced {$count} ONUs; Rx samples {$rxCount}");
        }

        return $res;
    }
}

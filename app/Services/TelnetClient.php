<?php

namespace App\Services;

use RuntimeException;

/**
 * TelnetClient for OLT Communication
 * Handles Telnet protocol negotiation and command execution
 */
class TelnetClient
{
    private $fp = null;
    private $buffer = '';

    const PROMPT_RE = '/(?:^|[\r\n])[^\r\n]*[>#]\s?$/m';
    const LOGIN_RE = '/(?:login|username|user name|user)[: ]*$/i';
    const PASSWORD_RE = '/password[: ]*$/i';
    const MORE_RE = '/(?:--\s*More\s*--|----\s*More\s*----|Press any key(?: to continue)?|Press <SPACE>|Press <Enter>|Press Enter|Press ENTER|Press\s+\'?Q|More\s+\(\d+%\)|Press\s+.*to continue)/i';
    const MORE_ENTER_RE = '/(?:press\s+<*enter>*|press\s+enter|press\s+return)/i';

    public function __construct(string $host, int $port = 23, int $timeout = 10)
    {
        $this->fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$this->fp) {
            throw new RuntimeException("Connection failed: $errstr ($errno)");
        }
        stream_set_blocking($this->fp, false);
        stream_set_timeout($this->fp, $timeout);
    }

    public function close(): void
    {
        if ($this->fp) {
            fclose($this->fp);
            $this->fp = null;
        }
    }

    private function sendIac(int $cmd, int $opt): void
    {
        if (!$this->fp) return;
        fwrite($this->fp, chr(255) . chr($cmd) . chr($opt));
    }

    private function handleTelnetNegotiation(string $data): string
    {
        $out = '';
        $len = strlen($data);
        $i = 0;
        while ($i < $len) {
            $c = ord($data[$i]);
            if ($c === 255) { // IAC
                if ($i + 1 >= $len) break;
                $cmd = ord($data[$i + 1]);
                if ($cmd === 255) { // escaped 255
                    $out .= chr(255);
                    $i += 2;
                    continue;
                }
                if ($i + 2 < $len) {
                    $opt = ord($data[$i + 2]);
                    if ($cmd === 251 || $cmd === 252) {
                        $this->sendIac(254, $opt); // DONT
                    } elseif ($cmd === 253 || $cmd === 254) {
                        $this->sendIac(252, $opt); // WONT
                    }
                    $i += 3;
                    continue;
                }
                $i += 2;
                continue;
            }
            $out .= $data[$i];
            $i += 1;
        }
        return $out;
    }

    private function readChunk(): string
    {
        if (!$this->fp) return '';
        $data = fread($this->fp, 4096);
        if ($data === false || $data === '') return '';
        return $this->handleTelnetNegotiation($data);
    }

    public function readUntilPrompt(int $timeout = 20): string
    {
        $end = microtime(true) + $timeout;
        $buf = '';
        while (microtime(true) < $end) {
            $chunk = $this->readChunk();
            if ($chunk !== '') {
                $buf .= $chunk;
                if (preg_match(self::MORE_RE, $buf, $m)) {
                    $token = strtolower($m[0]);
                    if (preg_match(self::MORE_ENTER_RE, $token)) $this->write("\n");
                    else $this->write(" ");
                    $buf = preg_replace(self::MORE_RE, '', $buf, 1);
                    continue;
                }
                if (preg_match(self::PROMPT_RE, $buf)) {
                    return $buf;
                }
            } else {
                usleep(150000);
            }
        }
        return $buf;
    }

    public function write(string $cmd): void
    {
        if (!$this->fp) return;
        fwrite($this->fp, $cmd);
    }

    public function isConnected(): bool
    {
        return $this->fp !== null;
    }
}

<?php

namespace App\Support;

class PublicRedirectLink
{
    public const TYPE_WHATSAPP = 'whatsapp';
    public const TYPE_CUSTOM = 'custom';

    public static function normalizeCode(string $raw): string
    {
        $code = strtolower(trim($raw));
        if ($code === '') {
            return '';
        }

        $code = preg_replace('/[^a-z0-9\-_]+/', '-', $code);
        $code = preg_replace('/-+/', '-', $code);
        $code = trim((string) $code, '-_');
        if ($code === '') {
            return '';
        }

        if (strlen($code) > 120) {
            $code = substr($code, 0, 120);
            $code = rtrim($code, '-_');
        }

        return $code;
    }

    public static function normalizePhone(?string $raw): string
    {
        $phone = preg_replace('/\D/', '', (string) $raw);
        if ($phone === '') {
            return '';
        }
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '62' . $phone;
        }

        return $phone;
    }

    public static function normalizeCustomUrl(?string $raw): string
    {
        $url = trim((string) $raw);
        if ($url === '') {
            return '';
        }

        if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        if (empty($parts['host'])) {
            return '';
        }

        return $url;
    }

    public static function buildTargetUrl(string $type, ?string $waNumber, ?string $waMessage, ?string $customUrl): string
    {
        $type = strtolower(trim($type));
        if ($type === self::TYPE_WHATSAPP) {
            $phone = self::normalizePhone($waNumber);
            if ($phone === '') {
                return '';
            }

            $message = trim((string) $waMessage);
            if ($message === '') {
                return 'https://wa.me/' . $phone;
            }

            return 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);
        }

        if ($type === self::TYPE_CUSTOM) {
            return self::normalizeCustomUrl($customUrl);
        }

        return '';
    }
}

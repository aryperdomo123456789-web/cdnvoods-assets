<?php

final class TotpAuth
{
    public static function base32Encode(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $len = strlen($binary);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }
        $chunks = str_split($bits, 5);
        $out = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }
        return rtrim($out, '=');
    }

    public static function base32Decode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        $bits = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $idx = strpos($alphabet, $value[$i]);
            if ($idx === false) {
                continue;
            }
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $bytes = [];
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes[] = chr(bindec($byte));
            }
        }
        return implode('', $bytes);
    }

    public static function randomSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(10, $bytes)));
    }

    public static function provisioningUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer)
        );
    }

    public static function verify(string $secret, string $code, ?int $window = 1): bool
    {
        $secret = trim($secret);
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($secret === '' || strlen($code) !== 6) {
            return false;
        }
        $bin = self::base32Decode($secret);
        if ($bin === '') {
            return false;
        }
        $timestep = 30;
        $counter = intdiv(time(), $timestep);
        $window = max(0, (int) $window);
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::totpAt($bin, $counter + $i, 6);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    private static function totpAt(string $key, int $counter, int $digits): string
    {
        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7fffffff;
        $mod = 10 ** $digits;
        return str_pad((string) ($value % $mod), $digits, '0', STR_PAD_LEFT);
    }
}

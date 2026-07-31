<?php

/**
 * Criptografia simétrica das credenciais SSH dos LBs.
 *
 * Nunca guardamos senha root em claro no SQLite. A chave deriva do
 * `app_secret` do painel (settings). Se o segredo mudar, a senha antiga
 * simplesmente não decifra e o admin recadastra — melhor do que texto puro.
 */
final class LbCrypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $secret = (string) SettingsRepository::get('app_secret', '');
        if ($secret === '') {
            $secret = 'proxy-mago-fallback-' . (string) Config::get('app_name', 'proxy');
        }
        return hash('sha256', 'lb::' . $secret, true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Falha ao cifrar credencial do LB.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, 'v1:')) {
            return '';
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
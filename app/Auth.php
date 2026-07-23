<?php

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(Config::get('session_name'));
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'path' => '/',
        ]);

        session_start();
    }

    public static function login(string $username, string $password): bool
    {
        $storedUser = (string) SettingsRepository::get('admin_user', '');
        $storedHash = (string) SettingsRepository::get('admin_password_hash', '');

        if ($storedUser === '' || $storedHash === '') {
            return false;
        }

        if (!hash_equals($storedUser, $username)) {
            return false;
        }

        if (!password_verify($password, $storedHash)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['proxy_mago_auth'] = true;
        $_SESSION['proxy_mago_user'] = $storedUser;
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['proxy_mago_auth']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

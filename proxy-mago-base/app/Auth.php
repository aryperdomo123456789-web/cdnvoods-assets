<?php

final class Auth
{
    private const LOGIN_WINDOW_SECONDS = 900;
    private const LOGIN_MAX_FAILURES = 5;
    private const LOGIN_LOCK_SECONDS = 900;

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

    public static function attemptLogin(string $username, string $password, string $totpCode = ''): array
    {
        $storedUser = (string) SettingsRepository::get('admin_user', '');
        $storedHash = (string) SettingsRepository::get('admin_password_hash', '');
        $clientIp = self::clientIp();
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '-');
        $now = time();

        if (self::isLocked($storedUser, $clientIp, $now)) {
            Audit::log('login_locked', 'Admin login locked out', $clientIp, $userAgent);
            return ['ok' => false, 'reason' => 'locked'];
        }

        if ($storedUser === '' || $storedHash === '') {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        if (!hash_equals($storedUser, $username)) {
            self::registerFailure($username, $clientIp, $userAgent, 'user_mismatch');
            return ['ok' => false, 'reason' => 'invalid'];
        }

        if (!password_verify($password, $storedHash)) {
            self::registerFailure($username, $clientIp, $userAgent, 'bad_password');
            return ['ok' => false, 'reason' => 'invalid'];
        }

        self::clearFailures($username, $clientIp);

        session_regenerate_id(true);
        $_SESSION['proxy_mago_auth'] = true;
        $_SESSION['proxy_mago_user'] = $storedUser;
        unset($_SESSION['proxy_mago_pending_login']);
        return ['ok' => true, 'reason' => 'ok'];
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
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $params = session_get_cookie_params();
        $name = session_name();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            setcookie($name, '', time() - 3600, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
        }

        session_regenerate_id(true);
        session_destroy();
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
    }

    public static function admin2faSecret(): string
    {
        return (string) SettingsRepository::get('admin_2fa_secret', '');
    }

    public static function admin2faEnabled(): bool
    {
        return (int) SettingsRepository::get('admin_2fa_enabled', 0) === 1;
    }

    private static function isLocked(string $username, string $clientIp, int $now): bool
    {
        if ($username === '' || $clientIp === '-') {
            return false;
        }
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT locked_until
                   FROM admin_login_attempts
                  WHERE username = :u AND client_ip = :ip
                  ORDER BY window_start DESC
                  LIMIT 1'
            );
            $stmt->execute([':u' => $username, ':ip' => $clientIp]);
            $lockedUntil = (int) ($stmt->fetchColumn() ?: 0);
            return $lockedUntil > $now;
        } catch (Throwable $e) {
            error_log('[auth] lockout check fail-open: ' . $e->getMessage());
            return false;
        }
    }

    private static function registerFailure(string $username, string $clientIp, string $userAgent, string $reason): void
    {
        if ($username === '' || $clientIp === '-') {
            return;
        }
        $window = intdiv(time(), self::LOGIN_WINDOW_SECONDS);
        try {
            Database::write(static function (PDO $pdo) use ($username, $clientIp, $userAgent, $window): void {
            $stmt = $pdo->prepare(
                'INSERT INTO admin_login_attempts
                    (username, client_ip, window_start, failures, locked_until, last_failure_at, last_user_agent)
                 VALUES (:u, :ip, :w, 1, 0, :at, :ua)
                 ON CONFLICT(username, client_ip, window_start) DO UPDATE SET
                    failures = admin_login_attempts.failures + 1,
                    locked_until = CASE
                        WHEN admin_login_attempts.failures + 1 >= :max_failures
                        THEN :lock_until
                        ELSE admin_login_attempts.locked_until
                    END,
                    last_failure_at = :at,
                    last_user_agent = :ua'
            );
            $stmt->execute([
                ':u' => $username,
                ':ip' => $clientIp,
                ':w' => $window,
                ':at' => date('c'),
                ':ua' => $userAgent,
                ':max_failures' => self::LOGIN_MAX_FAILURES,
                ':lock_until' => time() + self::LOGIN_LOCK_SECONDS,
            ]);
            }, 'admin_login_failure');
        } catch (Throwable $e) {
            error_log('[auth] registerFailure fail-open: ' . $e->getMessage());
        }

        Audit::log('login_fail', 'Admin login failed: ' . $reason, $clientIp, $userAgent);
        if (self::currentFailures($username, $clientIp) >= self::LOGIN_MAX_FAILURES) {
            Audit::log('login_lockout', 'Admin login temporarily locked', $clientIp, $userAgent);
        }
    }

    private static function currentFailures(string $username, string $clientIp): int
    {
        if ($username === '' || $clientIp === '-') {
            return 0;
        }
        try {
            $window = intdiv(time(), self::LOGIN_WINDOW_SECONDS);
            $stmt = Database::pdo()->prepare(
                'SELECT failures
                   FROM admin_login_attempts
                  WHERE username = :u AND client_ip = :ip AND window_start = :w
                  LIMIT 1'
            );
            $stmt->execute([':u' => $username, ':ip' => $clientIp, ':w' => $window]);
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function clearFailures(string $username, string $clientIp): void
    {
        if ($username === '' || $clientIp === '-') {
            return;
        }
        try {
            Database::run(
                'DELETE FROM admin_login_attempts WHERE username = :u AND client_ip = :ip',
                [':u' => $username, ':ip' => $clientIp],
                'admin_login_clear'
            );
        } catch (Throwable $e) {
            // fail-open: a falta da tabela não pode impedir o login do painel.
        }
    }
}

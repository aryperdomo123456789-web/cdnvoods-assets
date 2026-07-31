<?php
/**
 * Pré-condição comum dos smokes da trilha quente.
 *
 * Se `cdn_sessions_enabled=0` (toggle deixado pelo painel), CdnSession::touch()
 * devolve '' e NADA é gravado em cdn_sessions. Nesse caso os smokes de LB,
 * limite e uptime falham por falta de pré-condição, não por regressão real.
 *
 * Este helper força o toggle em 1 só durante o teste e restaura exatamente o
 * valor anterior (inclusive a AUSÊNCIA da chave) no fim do processo.
 */

if (!function_exists('smoke_sessions_force_enabled')) {
    function smoke_sessions_force_enabled(): callable
    {
        $pdo = Database::pdo();
        $row = $pdo->query("SELECT value FROM settings WHERE key = 'cdn_sessions_enabled' LIMIT 1")->fetch();

        $restore = static function () use ($pdo, $row): void {
            if ($row === false) {
                $pdo->exec("DELETE FROM settings WHERE key = 'cdn_sessions_enabled'");
            } else {
                $pdo->prepare(
                    'INSERT INTO settings (key, value, updated_at) VALUES (:k,:v,:u)
                     ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at'
                )->execute([':k' => 'cdn_sessions_enabled', ':v' => (string) $row['value'], ':u' => date('c')]);
            }
            Cache::flush();
        };

        $done = false;
        $restoreOnce = static function () use (&$done, $restore): void {
            if ($done) { return; }
            $done = true;
            $restore();
        };
        register_shutdown_function($restoreOnce);

        SettingsRepository::set('cdn_sessions_enabled', 1);
        Cache::flush();

        return $restoreOnce;
    }
}

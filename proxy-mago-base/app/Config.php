<?php

final class Config
{
    private static ?array $base = null;

    /**
     * Override por AMBIENTE (S2-P0-5 — ensaio de corte PostgreSQL).
     *
     * O corte para Postgres precisa ser testável sem editar arquivo de config
     * em produção (e sem risco de deixar o painel apontando pro banco errado
     * depois de um ensaio). Estas variáveis só existem no processo que as
     * exporta:
     *   PROXY_MAGO_DB_DRIVER=pgsql PROXY_MAGO_DB_HOST=... php bin/pg-cut.php
     */
    private const ENV_MAP = [
        'PROXY_MAGO_DB_DRIVER'  => 'db_driver',
        'PROXY_MAGO_DB_PATH'    => 'db_path',
        'PROXY_MAGO_DB_HOST'    => 'db_host',
        'PROXY_MAGO_DB_PORT'    => 'db_port',
        'PROXY_MAGO_DB_NAME'    => 'db_name',
        'PROXY_MAGO_DB_USER'    => 'db_user',
        'PROXY_MAGO_DB_PASS'    => 'db_pass',
        'PROXY_MAGO_DB_SSLMODE' => 'db_sslmode',
    ];

    public static function all(): array
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $defaults = require dirname(__DIR__) . '/config/app.php';
        $override = [];
        $localPath = dirname(__DIR__) . '/storage/local.config.php';

        if (is_file($localPath)) {
            $loaded = require $localPath;
            if (is_array($loaded)) {
                $override = $loaded;
            }
        }

        $env = [];
        foreach (self::ENV_MAP as $var => $key) {
            $value = getenv($var);
            if ($value === false || $value === '') {
                continue;
            }
            $env[$key] = $key === 'db_port' ? (int) $value : $value;
        }

        self::$base = array_replace($defaults, $override, $env);
        return self::$base;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}

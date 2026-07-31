<?php

final class HealthCheck
{
    private const TTL = 30;

    /** Resultado com cache curto: cada origem faz I/O de rede (até 3s). */
    public static function run(bool $fresh = false): array
    {
        $file = dirname(__DIR__) . '/storage/cache/health.json';
        if (!$fresh && is_file($file) && (time() - (int) @filemtime($file)) < self::TTL) {
            $cached = json_decode((string) @file_get_contents($file), true);
            if (is_array($cached) && $cached) {
                return $cached;
            }
        }

        $checks = self::collect();
        @file_put_contents($file, json_encode($checks, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $checks;
    }

    private static function collect(): array
    {
        $checks = [];
        $origins = OriginRepository::all();
        $activeOrigins = array_filter($origins, static fn (array $origin): bool => (int) $origin['active'] === 1);
        $activeAliases = array_filter(AliasRepository::all(), static fn (array $alias): bool => (int) $alias['active'] === 1);
        $checks[] = [
            'id' => 'configuration',
            'label' => 'Configuração do proxy',
            'ok' => count($activeOrigins) > 0 && count($activeAliases) > 0,
            'detail' => sprintf('%d origem(ns) e %d alias(es) ativos', count($activeOrigins), count($activeAliases)),
            'ms' => 0,
        ];
        $checks[] = self::check('database', 'SQLite', static function (): string {
            Database::pdo()->query('SELECT 1')->fetchColumn();
            return 'conectado';
        });
        $checks[] = self::check('storage', 'Storage', static function (): string {
            $path = dirname(__DIR__) . '/storage';
            if (!is_writable($path)) {
                throw new RuntimeException('sem permissão de escrita');
            }
            return 'gravável';
        });
        $checks[] = self::check('php_fpm', 'PHP-FPM', static function (): string {
            $socket = (string) Config::get('php_fpm_socket');
            if (!is_readable($socket) && !file_exists($socket)) {
                throw new RuntimeException('socket ausente');
            }
            return basename($socket);
        });

        foreach ($activeOrigins as $origin) {
            if ((int) $origin['active'] !== 1) {
                continue;
            }
            $checks[] = self::check('origin_' . $origin['id'], 'Origem: ' . $origin['name'], static function () use ($origin): string {
                $target = sprintf('%s://%s:%d/', $origin['scheme'], $origin['host'], $origin['port']);
                $context = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 3, 'ignore_errors' => true]]);
                $handle = @fopen($target, 'r', false, $context);
                if ($handle === false) {
                    throw new RuntimeException('sem resposta em 3s');
                }
                fclose($handle);
                return 'alcançável';
            });
        }

        return $checks;
    }

    private static function check(string $id, string $label, callable $callback): array
    {
        $start = microtime(true);
        try {
            $detail = $callback();
            return ['id' => $id, 'label' => $label, 'ok' => true, 'detail' => $detail, 'ms' => (int) ((microtime(true) - $start) * 1000)];
        } catch (Throwable $e) {
            return ['id' => $id, 'label' => $label, 'ok' => false, 'detail' => $e->getMessage(), 'ms' => (int) ((microtime(true) - $start) * 1000)];
        }
    }
}

<?php

/**
 * Micro-cache de arquivo para agregações do painel.
 *
 * O painel faz polling: várias abas abertas multiplicam a MESMA query pesada
 * (COUNT/GROUP BY sobre centenas de milhares de linhas). Com TTL de poucos
 * segundos o número na tela continua "ao vivo" para o operador, mas o SQLite
 * calcula uma vez só por janela — foi o que derrubou o tempo do restream-data
 * de ~2s para milissegundos.
 *
 * Nunca guardar aqui nada sensível (senha, token, chave): é arquivo em disco.
 */
final class Cache
{
    private static array $mem = [];

    private static function dir(): string
    {
        $dir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function path(string $key): string
    {
        return self::dir() . '/agg-' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.json';
    }

    /** @param callable():mixed $producer */
    public static function remember(string $key, int $ttl, callable $producer)
    {
        $now = microtime(true);
        // Dentro do MESMO request (summary() chama kpis(), por exemplo) nem
        // toca no disco.
        if (isset(self::$mem[$key]) && self::$mem[$key]['exp'] > $now) {
            return self::$mem[$key]['v'];
        }

        $file = self::path($key);
        if ($ttl > 0 && is_file($file) && (time() - (int) @filemtime($file)) < $ttl) {
            $raw = @file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data) && array_key_exists('v', $data)) {
                    self::$mem[$key] = ['exp' => $now + min($ttl, 2), 'v' => $data['v']];
                    return $data['v'];
                }
            }
        }

        $value = $producer();
        self::$mem[$key] = ['exp' => $now + min(max($ttl, 1), 2), 'v' => $value];
        if ($ttl > 0) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(['v' => $value])) !== false) {
                @rename($tmp, $file);
            }
        }
        return $value;
    }

    /** Invalida tudo (deploy) ou um prefixo. */
    public static function flush(string $prefix = ''): int
    {
        self::$mem = [];
        $n = 0;
        foreach ((array) glob(self::dir() . '/agg-' . ($prefix !== '' ? $prefix . '*' : '*') . '.json') as $f) {
            if (@unlink($f)) { $n++; }
        }
        return $n;
    }
}
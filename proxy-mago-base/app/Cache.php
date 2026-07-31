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

    /**
     * Janela extra em que um valor JÁ vencido continua servível enquanto
     * OUTRO processo recalcula. É o que evita o "cache stampede": com o
     * painel em polling e o rollup atrasado, 20 requests venciam o TTL no
     * mesmo segundo e TODOS caíam na recontagem pesada ao mesmo tempo —
     * exatamente o cenário que gerava "database is locked".
     */
    private const STALE_GRACE = 60;

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

    /** @return array{v:mixed,age:int}|null */
    private static function readFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $age = max(0, time() - (int) @filemtime($file));
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('v', $data)) {
            return null;
        }
        return ['v' => $data['v'], 'age' => $age];
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
        $hit = $ttl > 0 ? self::readFile($file) : null;
        if ($hit !== null && $hit['age'] < $ttl) {
            self::$mem[$key] = ['exp' => $now + min($ttl, 2), 'v' => $hit['v']];
            return $hit['v'];
        }

        // Single-flight: só UM processo recalcula por janela. Quem não pegou o
        // lock devolve o valor vencido (se ainda estiver na janela de graça)
        // em vez de duplicar a query pesada.
        $lock = null;
        if ($ttl > 0) {
            $lock = @fopen($file . '.lock', 'c');
            if ($lock !== false && !@flock($lock, LOCK_EX | LOCK_NB)) {
                @fclose($lock);
                if ($hit !== null && $hit['age'] < ($ttl + self::STALE_GRACE)) {
                    self::$mem[$key] = ['exp' => $now + min(max($ttl, 1), 2), 'v' => $hit['v']];
                    return $hit['v'];
                }
                // Sem valor servível: espera o vencedor terminar e reaproveita.
                $wait = @fopen($file . '.lock', 'c');
                if ($wait !== false) {
                    @flock($wait, LOCK_EX);
                    @flock($wait, LOCK_UN);
                    @fclose($wait);
                }
                $fresh = self::readFile($file);
                if ($fresh !== null) {
                    self::$mem[$key] = ['exp' => $now + min(max($ttl, 1), 2), 'v' => $fresh['v']];
                    return $fresh['v'];
                }
                $lock = null;
            }
        }

        try {
            $value = $producer();
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
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
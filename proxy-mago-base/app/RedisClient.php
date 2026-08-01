<?php

declare(strict_types=1);

/**
 * Cliente Redis MÍNIMO em PHP puro (RESP2), sem phpredis e sem Composer.
 *
 * Por que não phpredis: a VPS de produção (Ubuntu 22.04 / PHP 8.1) não pode
 * depender de extensão compilada para a Fase 2 subir. `sockets`/`fsockopen`
 * já bastam e o protocolo RESP2 é trivial. Se um dia phpredis existir, este
 * cliente continua funcionando — não há conflito.
 *
 * Regras de caminho quente:
 *  - conexão persistente por processo (pconnect quando disponível);
 *  - timeout curto: Redis fora do ar NUNCA pode segurar um segmento .ts;
 *  - toda falha vira exceção RedisClientException para o StateStore decidir
 *    o fallback (degradado) em vez de estourar 500 no player.
 */
final class RedisClientException extends RuntimeException
{
}

final class RedisClient
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
        private string $password = '',
        private int $db = 0,
        private float $timeout = 1.0,
        private bool $persistent = true
    ) {
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    /** @return resource */
    private function socket()
    {
        if (is_resource($this->sock) && !feof($this->sock)) {
            return $this->sock;
        }

        $errno = 0;
        $errstr = '';
        $target = 'tcp://' . $this->host . ':' . $this->port;
        $flags = $this->persistent ? STREAM_CLIENT_PERSISTENT | STREAM_CLIENT_CONNECT : STREAM_CLIENT_CONNECT;
        $sock = @stream_socket_client($target, $errno, $errstr, $this->timeout, $flags);

        if ($sock === false) {
            throw new RedisClientException('redis indisponível (' . $errno . ' ' . $errstr . ')');
        }

        stream_set_timeout($sock, (int) max(1, (int) $this->timeout), (int) (fmod($this->timeout, 1.0) * 1000000));
        $this->sock = $sock;

        // Socket persistente reaproveitado já está autenticado/selecionado.
        if (ftell($sock) === 0 || !$this->persistent) {
            if ($this->password !== '') {
                $this->command(['AUTH', $this->password]);
            }
            if ($this->db > 0) {
                $this->command(['SELECT', (string) $this->db]);
            }
        }

        return $sock;
    }

    /**
     * Executa um comando e devolve a resposta decodificada.
     *
     * @param array<int,string|int> $args
     */
    public function command(array $args): mixed
    {
        $sock = $this->sock;
        if (!is_resource($sock) || feof($sock)) {
            $sock = $this->socket();
        }

        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        if (@fwrite($sock, $payload) === false) {
            $this->close();
            throw new RedisClientException('falha ao escrever no redis');
        }

        return $this->readReply($sock);
    }

    /**
     * Pipeline: manda tudo de uma vez e lê N respostas. Um round-trip só.
     *
     * @param array<int,array<int,string|int>> $commands
     * @return array<int,mixed>
     */
    public function pipeline(array $commands): array
    {
        if ($commands === []) {
            return [];
        }

        $sock = $this->socket();
        $payload = '';
        foreach ($commands as $args) {
            $payload .= '*' . count($args) . "\r\n";
            foreach ($args as $arg) {
                $arg = (string) $arg;
                $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }
        }

        if (@fwrite($sock, $payload) === false) {
            $this->close();
            throw new RedisClientException('falha ao escrever pipeline no redis');
        }

        $out = [];
        foreach ($commands as $_) {
            $out[] = $this->readReply($sock);
        }
        return $out;
    }

    /** @param resource $sock */
    private function readReply($sock): mixed
    {
        $line = @fgets($sock);
        if ($line === false || $line === '') {
            $this->close();
            throw new RedisClientException('redis fechou a conexão');
        }

        $type = $line[0];
        $value = substr(rtrim($line, "\r\n"), 1);

        switch ($type) {
            case '+':
                return $value;
            case '-':
                throw new RedisClientException('redis: ' . $value);
            case ':':
                return (int) $value;
            case '$':
                $len = (int) $value;
                if ($len === -1) {
                    return null;
                }
                $data = '';
                $need = $len + 2;
                while (strlen($data) < $need) {
                    $chunk = @fread($sock, $need - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        $this->close();
                        throw new RedisClientException('redis truncou bulk string');
                    }
                    $data .= $chunk;
                }
                return substr($data, 0, $len);
            case '*':
                $count = (int) $value;
                if ($count === -1) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->readReply($sock);
                }
                return $items;
            default:
                $this->close();
                throw new RedisClientException('resposta RESP desconhecida: ' . $type);
        }
    }
}
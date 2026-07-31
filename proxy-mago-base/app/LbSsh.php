<?php

/**
 * Camada SSH do cérebro para os músculos.
 *
 * Ordem de preferência: CHAVE Ed25519 do cérebro; senha root só como
 * onboarding/fallback. Regras duras:
 *  - a senha NUNCA vai na linha de comando (usa SSHPASS via env);
 *  - a senha NUNCA entra em log — tudo passa por redact();
 *  - a chave privada nunca é impressa nem copiada para o LB;
 *  - toda execução tem timeout, para não travar o painel.
 */
final class LbSsh
{
    public static function available(): bool
    {
        if (self::binary('ssh') === '') {
            return false;
        }
        return self::binary('sshpass') !== '' || LbKeyring::hasKey();
    }

    public static function missingHint(): string
    {
        $miss = [];
        if (self::binary('ssh') === '') { $miss[] = 'openssh-client'; }
        if (self::binary('sshpass') === '' && !LbKeyring::hasKey()) { $miss[] = 'sshpass'; }
        if (!$miss) { return ''; }
        return 'Dependência ausente na VPS. Rode: apt-get install -y ' . implode(' ', $miss);
    }

    public static function passwordAvailable(): bool
    {
        return self::binary('sshpass') !== '';
    }

    private static function binary(string $name): string
    {
        // Memo em processo + cache em disco: `command -v` gera fork/exec e o
        // painel chamava isso em toda renderização de /lb.php.
        static $memo = [];
        if (array_key_exists($name, $memo)) {
            return $memo[$name];
        }

        $file = dirname(__DIR__) . '/storage/cache/bin-' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '.txt';
        if (is_file($file) && (time() - (int) @filemtime($file)) < 300) {
            return $memo[$name] = trim((string) @file_get_contents($file));
        }

        $out = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        $path = $code === 0 && !empty($out[0]) ? trim((string) $out[0]) : '';
        @file_put_contents($file, $path, LOCK_EX);
        return $memo[$name] = $path;
    }

    /**
     * Ordem de autenticação para este nó.
     *
     * @return string[] lista com 'key' e/ou 'password'
     */
    public static function authOrder(array $node, string $prefer = 'auto'): array
    {
        $hasKey = LbKeyring::hasKey();
        $keyReady = $hasKey && ((int) ($node['key_installed'] ?? 0) === 1 || (string) ($node['auth_mode'] ?? '') === 'key');
        $hasPassword = LbCrypto::decrypt((string) ($node['ssh_password_enc'] ?? '')) !== '' && self::passwordAvailable();

        if ($prefer === 'password') {
            return $hasPassword ? ['password'] : ($keyReady ? ['key'] : []);
        }
        if ($prefer === 'key') {
            return $hasKey ? ['key'] : [];
        }

        $order = [];
        if ($keyReady) { $order[] = 'key'; }
        if ($hasPassword) { $order[] = 'password'; }
        if (!$order && $hasKey) { $order[] = 'key'; }
        return $order;
    }

    /**
     * @return array{ok:bool,code:int,stdout:string,stderr:string,duration_ms:int,auth_mode:string,fallback:bool}
     */
    public static function run(array $node, string $command, int $timeout = 120, string $stdin = '', string $prefer = 'auto'): array
    {
        $order = self::authOrder($node, $prefer);
        if (!$order) {
            return self::fail('Nenhum método de autenticação disponível para este LB (sem chave instalada e sem senha).');
        }

        $last = self::fail('SSH não executado.');
        $fallback = false;
        foreach ($order as $i => $mode) {
            $last = self::attempt($node, $command, $timeout, $stdin, $mode);
            $last['fallback'] = $fallback;
            if ($last['ok']) {
                if ($i > 0) {
                    Audit::log('lb_ssh_fallback', sprintf('LB #%d autenticou por %s após falha do método anterior', (int) ($node['id'] ?? 0), $mode));
                }
                return $last;
            }
            $fallback = true;
        }
        return $last;
    }

    private static function fail(string $message, string $mode = ''): array
    {
        return ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => $message,
                'duration_ms' => 0, 'auth_mode' => $mode, 'fallback' => false];
    }

    private static function attempt(array $node, string $command, int $timeout, string $stdin, string $mode): array
    {
        $password = $mode === 'password' ? LbCrypto::decrypt((string) ($node['ssh_password_enc'] ?? '')) : '';
        $host = (string) ($node['ssh_host'] ?? $node['public_ip'] ?? '');
        $user = (string) ($node['ssh_user'] ?? 'root');
        $port = (int) ($node['ssh_port'] ?? 22);

        if ($host === '') {
            return self::fail('Host SSH vazio.', $mode);
        }
        if (self::binary('ssh') === '') {
            return self::fail(self::missingHint(), $mode);
        }
        if ($mode === 'password' && ($password === '' || !self::passwordAvailable())) {
            return self::fail('Autenticação por senha indisponível.', $mode);
        }
        if ($mode === 'key' && !LbKeyring::hasKey()) {
            return self::fail('Chave do cérebro ainda não gerada.', $mode);
        }

        $base = [
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=10',
            '-o', 'ServerAliveInterval=15',
            '-o', 'LogLevel=ERROR',
            '-p', (string) $port,
        ];

        if ($mode === 'key') {
            $args = array_merge(['ssh'], $base, [
                '-o', 'BatchMode=yes',
                '-o', 'IdentitiesOnly=yes',
                '-o', 'PreferredAuthentications=publickey',
                '-i', LbKeyring::privatePath(),
            ]);
        } else {
            $args = array_merge(['sshpass', '-e', 'ssh'], $base, [
                '-o', 'BatchMode=no',
                '-o', 'PreferredAuthentications=password,keyboard-interactive',
                '-o', 'PubkeyAuthentication=no',
            ]);
        }

        $args = array_merge($args, [
            $user . '@' . $host,
            'bash -lc ' . escapeshellarg($command),
        ]);

        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin', 'LANG' => 'C'];
        if ($mode === 'password') {
            $env['SSHPASS'] = $password;
        }

        $start = microtime(true);
        $proc = @proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            return self::fail('Não foi possível abrir o processo SSH.', $mode);
        }

        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(5, $timeout);
        $code = -1;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $stderr .= "\n[timeout] comando excedeu {$timeout}s.";
                $code = 124;
                break;
            }
            usleep(120000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [
            'ok' => $code === 0,
            'code' => $code,
            'stdout' => self::redact($stdout, $password),
            'stderr' => self::redact($stderr, $password),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'auth_mode' => $mode,
            'fallback' => false,
        ];
    }

    /** Envia um arquivo por stdin (sem scp, sem arquivo temporário no cérebro). */
    public static function putFile(array $node, string $remotePath, string $content, int $timeout = 180, string $prefer = 'auto'): array
    {
        $dir = dirname($remotePath);
        $cmd = 'mkdir -p ' . escapeshellarg($dir) . ' && cat > ' . escapeshellarg($remotePath) . ' && chmod 0640 ' . escapeshellarg($remotePath);
        return self::run($node, $cmd, $timeout, $content, $prefer);
    }

    public static function redact(string $text, string $password = ''): string
    {
        if ($password !== '') {
            $text = str_replace($password, '***', $text);
        }
        $text = preg_replace('/(password|passwd|senha|SSHPASS)\s*[=:]\s*\S+/i', '$1=***', $text) ?? $text;
        $text = preg_replace('/-----BEGIN[^-]*PRIVATE KEY-----.*?-----END[^-]*PRIVATE KEY-----/s', '[chave privada omitida]', $text) ?? $text;
        return trim($text);
    }
}
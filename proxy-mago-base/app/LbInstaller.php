<?php

/**
 * Instalação remota do LB por clique no painel.
 *
 * Etapas (todas gravadas em lb_installs, com log ao vivo no painel):
 *   1. validate       — dados locais do cadastro
 *   2. keygen         — par Ed25519 do cérebro (gera uma vez, reusa sempre)
 *   3. handshake      — SSH conecta (senha root só no onboarding)
 *   4. install_key    — chave pública no authorized_keys do músculo
 *   5. key_smoke      — confirma acesso SÓ por chave (sem senha)
 *   6. detect         — SO, CPU, RAM, disco, rede
 *   7. support        — Ubuntu 22..25
 *   8. bootstrap      — nginx + php-fpm + utilitários
 *   9. package        — envia o pacote mínimo do proxy
 *  10. configure      — tuning por perfil + vhost + agente
 *  11. smoke          — health + proxy respondendo
 */
final class LbInstaller
{
    public const STEPS = [
        'validate', 'keygen', 'handshake', 'install_key', 'key_smoke',
        'detect', 'support', 'bootstrap', 'package', 'configure', 'smoke',
    ];

    /** Dispara install/sync em background (nohup) para não travar o PHP-FPM. */
    public static function spawn(int $lbId, string $action = 'install'): bool
    {
        $action = in_array($action, ['install', 'sync'], true) ? $action : 'install';
        $php = self::cliPhpBinary();
        $script = dirname(__DIR__) . '/bin/lb-install-run.php';
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $cmd = sprintf(
            'nohup %s %s --lb=%d --action=%s >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            $lbId,
            escapeshellarg($action),
            escapeshellarg($logDir . '/lb-' . $lbId . '.log')
        );
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        return $code === 0;
    }

    private static function cliPhpBinary(): string
    {
        $candidates = [];
        if (defined('PHP_BINARY') && PHP_BINARY) {
            $candidates[] = (string) PHP_BINARY;
        }
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';

        foreach ($candidates as $candidate) {
            if ($candidate === '' || !is_file($candidate) || !is_executable($candidate)) {
                continue;
            }
            $base = strtolower(basename($candidate));
            if (str_contains($base, 'php-fpm')) {
                continue;
            }
            return $candidate;
        }

        $out = [];
        $code = 1;
        @exec('command -v php 2>/dev/null', $out, $code);
        if ($code === 0 && !empty($out[0])) {
            return trim((string) $out[0]);
        }
        return '/usr/bin/php';
    }

    public static function install(int $lbId): array
    {
        $node = LbNode::find($lbId);
        if (!$node) {
            throw new InvalidArgumentException('LB não encontrado.');
        }

        $runId = 'lbinst-' . bin2hex(random_bytes(6));
        LbNode::update($lbId, ['install_status' => 'running', 'install_run_id' => $runId, 'install_step' => 'validate']);
        Audit::log('lb_install_start', sprintf('LB #%d %s run=%s', $lbId, $node['public_ip'], $runId));

        try {
            self::step($lbId, $runId, 'validate', 'ok', 'Cadastro válido: ' . $node['ssh_user'] . '@' . $node['public_ip'] . ':' . $node['ssh_port']);

            if (!LbSsh::available()) {
                throw new RuntimeException(LbSsh::missingHint());
            }

            $key = LbKeyring::ensure();
            if (!$key['ok']) {
                throw new RuntimeException('Chave do cérebro indisponível: ' . (string) $key['message']);
            }
            self::step($lbId, $runId, 'keygen', 'ok', ($key['generated'] ? 'Par Ed25519 gerado agora' : 'Par Ed25519 já existente')
                . ' — ' . (string) $key['fingerprint']);

            $r = LbSsh::run($node, 'echo lb-handshake-ok', 25);
            if (!$r['ok'] || !str_contains($r['stdout'], 'lb-handshake-ok')) {
                throw new RuntimeException('SSH falhou: ' . ($r['stderr'] ?: 'sem resposta'));
            }
            self::step($lbId, $runId, 'handshake', 'ok', sprintf(
                'SSH conectado por %s em %dms',
                $r['auth_mode'] === 'key' ? 'chave' : 'senha root (onboarding)',
                $r['duration_ms']
            ), $r['duration_ms']);

            $promo = self::promoteKey($lbId, $runId);
            if (!$promo['ok']) {
                throw new RuntimeException($promo['message']);
            }
            $node = LbNode::find($lbId);

            $facts = self::detect($node);
            LbNode::update($lbId, [
                'os_name' => $facts['os_name'],
                'os_version' => $facts['os_version'],
                'cpu_cores' => $facts['cpu_cores'],
                'ram_mb' => $facts['ram_mb'],
                'disk_total_gb' => $facts['disk_total_gb'],
                'disk_free_gb' => $facts['disk_free_gb'],
            ]);
            self::step($lbId, $runId, 'detect', 'ok', sprintf(
                '%s %s — %d vCPU, %d MB RAM, %d GB livres',
                $facts['os_name'], $facts['os_version'], $facts['cpu_cores'], $facts['ram_mb'], $facts['disk_free_gb']
            ));

            if (!self::supported($facts)) {
                throw new RuntimeException('SO não suportado: ' . $facts['os_name'] . ' ' . $facts['os_version'] . ' (aceito: Ubuntu 22 a 25).');
            }
            $profile = LbNode::profileFor($facts['cpu_cores'], $facts['ram_mb']);
            LbNode::update($lbId, ['profile' => $profile]);
            self::step($lbId, $runId, 'support', 'ok', 'SO suportado. Perfil automático: ' . $profile);

            $node = LbNode::find($lbId);
            $installer = self::renderInstaller($profile, $facts['cpu_cores']);
            $put = LbSsh::putFile($node, '/root/proxy-mago-lb-install.sh', $installer, 90);
            if (!$put['ok']) {
                throw new RuntimeException('Falha ao enviar instalador: ' . $put['stderr']);
            }
            $r = LbSsh::run($node, 'chmod +x /root/proxy-mago-lb-install.sh && /root/proxy-mago-lb-install.sh bootstrap 2>&1 | tail -n 40', 900);
            self::step($lbId, $runId, 'bootstrap', $r['ok'] ? 'ok' : 'error', $r['stdout'] ?: $r['stderr'], $r['duration_ms']);
            if (!$r['ok']) {
                throw new RuntimeException('Bootstrap remoto falhou.');
            }

            $pkg = LbPackageBuilder::build();
            $put = LbSsh::putFile($node, '/root/proxy-mago-lb-package.b64', $pkg['tar_b64'], 300);
            if (!$put['ok']) {
                throw new RuntimeException('Falha ao enviar pacote: ' . $put['stderr']);
            }
            $r = LbSsh::run($node, '/root/proxy-mago-lb-install.sh package 2>&1 | tail -n 30', 300);
            self::step($lbId, $runId, 'package', $r['ok'] ? 'ok' : 'error', sprintf(
                '%d arquivos (%d KB) — %s', $pkg['files'], (int) round($pkg['bytes'] / 1024), $r['stdout'] ?: $r['stderr']
            ), $r['duration_ms']);
            if (!$r['ok']) {
                throw new RuntimeException('Deploy do pacote mínimo falhou.');
            }

            $conf = self::renderRuntimeConfig($node);
            $put = LbSsh::putFile($node, '/opt/proxy-mago-lb/storage/local.config.php', $conf, 90);
            if (!$put['ok']) {
                throw new RuntimeException('Falha ao aplicar configuração: ' . $put['stderr']);
            }
            $r = LbSsh::run($node, '/root/proxy-mago-lb-install.sh configure 2>&1 | tail -n 30', 300);
            self::step($lbId, $runId, 'configure', $r['ok'] ? 'ok' : 'error', $r['stdout'] ?: $r['stderr'], $r['duration_ms']);
            if (!$r['ok']) {
                throw new RuntimeException('Configuração remota falhou.');
            }

            $smoke = self::smoke($node);
            self::step($lbId, $runId, 'smoke', $smoke['ok'] ? 'ok' : 'error', $smoke['message'], $smoke['duration_ms']);

            LbNode::update($lbId, [
                'install_status' => $smoke['ok'] ? 'installed' : 'degraded',
                'install_step' => 'smoke',
                'health_status' => $smoke['ok'] ? 'ok' : 'degraded',
                'health_message' => $smoke['message'],
                'last_seen_epoch' => time(),
            ]);
            Audit::log('lb_install_done', sprintf('LB #%d run=%s status=%s', $lbId, $runId, $smoke['ok'] ? 'installed' : 'degraded'));

            return ['ok' => $smoke['ok'], 'run_id' => $runId, 'message' => $smoke['message']];
        } catch (Throwable $e) {
            $msg = LbSsh::redact($e->getMessage());
            self::step($lbId, $runId, LbNode::find($lbId)['install_step'] ?? 'error', 'error', $msg);
            LbNode::update($lbId, ['install_status' => 'error', 'health_status' => 'error', 'health_message' => $msg]);
            Audit::log('lb_install_error', sprintf('LB #%d run=%s erro=%s', $lbId, $runId, $msg));
            return ['ok' => false, 'run_id' => $runId, 'message' => $msg];
        }
    }

    /** Teste de conexão sem instalar nada. */
    public static function testConnection(int $lbId): array
    {
        $node = LbNode::find($lbId);
        if (!$node) {
            throw new InvalidArgumentException('LB não encontrado.');
        }
        if (!LbSsh::available()) {
            return ['ok' => false, 'message' => LbSsh::missingHint()];
        }
        $r = LbSsh::run($node, 'echo lb-ping-ok; hostnamectl 2>/dev/null | head -n 3', 25);
        $ok = $r['ok'] && str_contains($r['stdout'], 'lb-ping-ok');
        LbNode::update($lbId, [
            'health_status' => $ok ? 'ok' : 'error',
            'health_message' => $ok
                ? sprintf('SSH ok por %s em %dms', $r['auth_mode'] === 'key' ? 'chave' : 'senha', $r['duration_ms'])
                : ($r['stderr'] ?: 'sem resposta'),
            'last_probe_epoch' => time(),
        ]);
        return ['ok' => $ok, 'message' => $ok ? $r['stdout'] : ($r['stderr'] ?: 'SSH não respondeu.')];
    }

    /**
     * Promove o nó de senha root para chave Ed25519.
     * Idempotente: se a chave já responde, só confirma e segue.
     */
    public static function promoteKey(int $lbId, string $runId): array
    {
        $node = LbNode::find($lbId);
        if (!$node) {
            return ['ok' => false, 'message' => 'LB não encontrado.'];
        }

        $key = LbKeyring::ensure();
        if (!$key['ok']) {
            return ['ok' => false, 'message' => 'Chave do cérebro indisponível: ' . (string) $key['message']];
        }

        $script = LbKeyring::installScript((string) ($node['ssh_user'] ?? 'root'));
        $install = LbSsh::run($node, $script, 60);
        if (!$install['ok'] || !str_contains($install['stdout'], 'lb-key-installed')) {
            $msg = 'Falha ao instalar a chave pública: ' . ($install['stderr'] ?: 'sem resposta');
            self::step($lbId, $runId, 'install_key', 'error', $msg, $install['duration_ms']);
            return ['ok' => false, 'message' => $msg];
        }
        self::step($lbId, $runId, 'install_key', 'ok', 'Chave pública aplicada no authorized_keys ('
            . (string) $key['fingerprint'] . ')', $install['duration_ms']);

        LbNode::update($lbId, ['password_bootstrap_done' => 1]);
        $node = LbNode::find($lbId);

        $probe = LbSsh::run($node, 'echo lb-key-ok', 25, '', 'key');
        if (!$probe['ok'] || !str_contains($probe['stdout'], 'lb-key-ok')) {
            $msg = 'Chave instalada, mas o acesso por chave não respondeu: ' . ($probe['stderr'] ?: 'sem resposta')
                 . ' — o LB segue acessível por senha (fallback seguro).';
            self::step($lbId, $runId, 'key_smoke', 'warn', $msg, $probe['duration_ms']);
            return ['ok' => true, 'message' => $msg, 'auth_mode' => 'password'];
        }

        LbNode::promoteToKey($lbId, (string) $key['fingerprint']);
        self::step($lbId, $runId, 'key_smoke', 'ok', 'Acesso por chave confirmado sem senha em '
            . $probe['duration_ms'] . 'ms. Senha root passa a ser apenas fallback.', $probe['duration_ms']);
        self::event($lbId, 'key_promote', 'ok', ['fingerprint' => (string) $key['fingerprint']]);

        return ['ok' => true, 'message' => 'Autenticação promovida para chave Ed25519.', 'auth_mode' => 'key'];
    }

    /** Reenvia apenas configuração + pacote (sem apt), para atualizar um LB já instalado. */
    public static function sync(int $lbId): array
    {
        $node = LbNode::find($lbId);
        if (!$node) {
            throw new InvalidArgumentException('LB não encontrado.');
        }
        $runId = 'lbsync-' . bin2hex(random_bytes(6));
        try {
            $pkg = LbPackageBuilder::build();
            $put = LbSsh::putFile($node, '/root/proxy-mago-lb-package.b64', $pkg['tar_b64'], 300);
            if (!$put['ok']) {
                throw new RuntimeException($put['stderr']);
            }
            $conf = self::renderRuntimeConfig($node);
            LbSsh::putFile($node, '/opt/proxy-mago-lb/storage/local.config.php', $conf, 90);
            $r = LbSsh::run($node, '/root/proxy-mago-lb-install.sh package && /root/proxy-mago-lb-install.sh configure 2>&1 | tail -n 20', 300);
            self::step($lbId, $runId, 'sync', $r['ok'] ? 'ok' : 'error', $r['stdout'] ?: $r['stderr'], $r['duration_ms']);
            self::event($lbId, 'sync', $r['ok'] ? 'ok' : 'error', ['files' => $pkg['files']]);
            LbNode::update($lbId, ['install_run_id' => $runId, 'last_seen_epoch' => time()]);
            return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'Pacote e configuração sincronizados.' : ($r['stderr'] ?: 'Falha no sync.')];
        } catch (Throwable $e) {
            $msg = LbSsh::redact($e->getMessage());
            self::step($lbId, $runId, 'sync', 'error', $msg);
            return ['ok' => false, 'message' => $msg];
        }
    }

    public static function detect(array $node): array
    {
        $cmd = 'echo "OSID=$(. /etc/os-release; echo $ID)"; echo "OSVER=$(. /etc/os-release; echo $VERSION_ID)";'
             . ' echo "CORES=$(nproc)"; echo "RAM=$(free -m | awk \'/Mem:/{print $2}\')";'
             . ' echo "DISKT=$(df -BG --output=size / | tail -n1 | tr -dc 0-9)";'
             . ' echo "DISKF=$(df -BG --output=avail / | tail -n1 | tr -dc 0-9)";'
             . ' echo "IFACE=$(ip route get 1.1.1.1 2>/dev/null | awk \'{print $5; exit}\')"';
        $r = LbSsh::run($node, $cmd, 45);
        $facts = ['os_name' => '', 'os_version' => '', 'cpu_cores' => 0, 'ram_mb' => 0, 'disk_total_gb' => 0, 'disk_free_gb' => 0, 'iface' => ''];
        foreach (explode("\n", $r['stdout']) as $line) {
            [$k, $v] = array_pad(explode('=', trim($line), 2), 2, '');
            switch ($k) {
                case 'OSID': $facts['os_name'] = strtolower(trim($v)); break;
                case 'OSVER': $facts['os_version'] = trim($v); break;
                case 'CORES': $facts['cpu_cores'] = (int) $v; break;
                case 'RAM': $facts['ram_mb'] = (int) $v; break;
                case 'DISKT': $facts['disk_total_gb'] = (int) $v; break;
                case 'DISKF': $facts['disk_free_gb'] = (int) $v; break;
                case 'IFACE': $facts['iface'] = trim($v); break;
            }
        }
        if ($facts['cpu_cores'] === 0) {
            throw new RuntimeException('Não foi possível detectar o hardware do LB: ' . ($r['stderr'] ?: 'saída vazia'));
        }
        return $facts;
    }

    public static function supported(array $facts): bool
    {
        if ($facts['os_name'] !== 'ubuntu') {
            return false;
        }
        $major = (int) explode('.', (string) $facts['os_version'])[0];
        return $major >= 22 && $major <= 25;
    }

    private static function smoke(array $node): array
    {
        $cmd = 'set -e; systemctl is-active nginx; systemctl is-active "php*-fpm" 2>/dev/null || systemctl list-units --type=service --state=running | grep -c fpm;'
             . ' curl -s -o /dev/null -w "health=%{http_code}\n" http://127.0.0.1/__lb_health;'
             . ' curl -s -o /dev/null -w "proxy=%{http_code}\n" "http://127.0.0.1/player_api.php?username=smoke&password=smoke"';
        $r = LbSsh::run($node, $cmd, 90);
        $out = $r['stdout'];
        $healthOk = str_contains($out, 'health=200');
        return [
            'ok' => $healthOk,
            'message' => LbSsh::redact($out . ($r['stderr'] !== '' ? ' | ' . $r['stderr'] : '')),
            'duration_ms' => $r['duration_ms'],
        ];
    }

    /** Configuração runtime do músculo (origem XUI mascarada + endpoint do cérebro). */
    public static function renderRuntimeConfig(array $node): string
    {
        $origin = XuiOrigin::get();
        $brainHost = trim((string) SettingsRepository::get('brain_ingest_host', ''));
        if ($brainHost === '') {
            $brainHost = trim((string) SettingsRepository::get('panel_domain', '')) ?: '45.140.192.237';
        }

        $payload = [
            'app_name' => 'Proxy Mago LB',
            'role' => 'lb',
            'lb_id' => (int) $node['id'],
            'lb_label' => (string) $node['label'],
            'db_path' => '/opt/proxy-mago-lb/storage/lb.sqlite',
            'panel_path' => '/opt/proxy-mago-lb',
            'force_https' => false,
            'token_ttl' => (int) Config::get('token_ttl', 21600),
            'rate_limit_per_minute' => (int) Config::get('rate_limit_per_minute', 240),
            'brain_ingest_url' => 'http://' . $brainHost . '/lb-ingest.php',
            'agent_token' => (string) $node['agent_token'],
            'xui_origin' => $origin ? [
                'host' => (string) $origin['host'],
                'port' => (int) $origin['port'],
                'scheme' => (string) $origin['scheme'],
                'host_header' => (string) ($origin['host_header'] ?? ''),
            ] : null,
        ];

        return "<?php\n// Gerado pelo cérebro em " . date('c') . " — não editar à mão.\nreturn "
            . var_export($payload, true) . ";\n";
    }

    public static function renderInstaller(string $profile, int $cores): string
    {
        $t = LbNode::tuning($profile, $cores);
        $script = (string) file_get_contents(dirname(__DIR__) . '/bin/lb-install.sh');
        return strtr($script, [
            '__PROFILE__' => $profile,
            '__WORKERS__' => (string) $t['workers'],
            '__CONNECTIONS__' => (string) $t['connections'],
            '__FPM_CHILDREN__' => (string) $t['fpm_children'],
            '__FPM_START__' => (string) $t['fpm_start'],
            '__FPM_MIN__' => (string) $t['fpm_min'],
            '__FPM_MAX__' => (string) $t['fpm_max'],
        ]);
    }

    public static function step(int $lbId, string $runId, string $step, string $status, string $message, int $durationMs = 0): void
    {
        self::dbRetry(static function () use ($lbId, $runId, $step, $status, $message, $durationMs): void {
            $pdo = Database::pdo();
            $seq = (int) $pdo->query('SELECT COALESCE(MAX(seq),0) FROM lb_installs WHERE lb_id = ' . $lbId . ' AND run_id = ' . $pdo->quote($runId))->fetchColumn();
            $pdo->prepare(
                'INSERT INTO lb_installs (lb_id, run_id, seq, step, status, message, ts_epoch, duration_ms)
                 VALUES (:l,:r,:s,:st,:sa,:m,:t,:d)'
            )->execute([
                ':l' => $lbId, ':r' => $runId, ':s' => $seq + 1, ':st' => $step, ':sa' => $status,
                ':m' => substr(LbSsh::redact($message), 0, 4000), ':t' => time(), ':d' => $durationMs,
            ]);
        });
        LbNode::update($lbId, ['install_step' => $step]);
    }

    public static function event(int $lbId, string $type, string $status, array $payload = []): void
    {
        self::dbRetry(static function () use ($lbId, $type, $status, $payload): void {
            Database::pdo()->prepare(
                'INSERT INTO lb_sync_events (lb_id, event_type, status, payload_json, created_at) VALUES (:l,:t,:s,:p,:c)'
            )->execute([
                ':l' => $lbId, ':t' => $type, ':s' => $status,
                ':p' => json_encode($payload, JSON_UNESCAPED_SLASHES), ':c' => date('c'),
            ]);
        });
    }

    public static function log(int $lbId, string $runId = '', int $limit = 80): array
    {
        $pdo = Database::pdo();
        if ($runId === '') {
            $node = LbNode::find($lbId);
            $runId = (string) ($node['install_run_id'] ?? '');
        }
        $stmt = $pdo->prepare('SELECT * FROM lb_installs WHERE lb_id = :l AND run_id = :r ORDER BY seq ASC LIMIT :lim');
        $stmt->bindValue(':l', $lbId, PDO::PARAM_INT);
        $stmt->bindValue(':r', $runId);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    private static function dbRetry(callable $fn, int $attempts = 8, int $sleepUs = 250000): void
    {
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $fn();
                return;
            } catch (Throwable $e) {
                $last = $e;
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'database is locked') && !str_contains($msg, 'database table is locked')) {
                    throw $e;
                }
                usleep($sleepUs);
            }
        }
        if ($last) {
            throw $last;
        }
    }
}

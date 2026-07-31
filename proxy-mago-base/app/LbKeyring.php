<?php

/**
 * Chaveiro SSH do cérebro (par Ed25519 único do painel).
 *
 * Regras duras:
 *  - a chave privada mora em storage/ssh/lb_ed25519 (0600, fora do git);
 *  - a chave privada NUNCA é exibida no painel nem gravada em log;
 *  - a chave pública fica em settings para exibição/auditoria;
 *  - a senha root do LB só é usada no onboarding, até a chave entrar.
 */
final class LbKeyring
{
    public const COMMENT = 'proxy-mago-cerebro';

    public static function dir(): string
    {
        return dirname(__DIR__) . '/storage/ssh';
    }

    public static function privatePath(): string
    {
        return self::dir() . '/lb_ed25519';
    }

    public static function publicPath(): string
    {
        return self::privatePath() . '.pub';
    }

    public static function keygenBinary(): string
    {
        $out = [];
        $code = 1;
        @exec('command -v ssh-keygen 2>/dev/null', $out, $code);
        return $code === 0 && !empty($out[0]) ? trim((string) $out[0]) : '';
    }

    public static function hasKey(): bool
    {
        return is_file(self::privatePath()) && filesize(self::privatePath()) > 0 && is_file(self::publicPath());
    }

    /** Gera o par se ainda não existir. Idempotente. */
    public static function ensure(bool $rotate = false): array
    {
        if (self::hasKey() && !$rotate) {
            return ['ok' => true, 'generated' => false] + self::info();
        }

        $bin = self::keygenBinary();
        if ($bin === '') {
            return [
                'ok' => false,
                'generated' => false,
                'message' => 'ssh-keygen ausente. Rode: apt-get install -y openssh-client',
                'public_key' => '',
                'fingerprint' => '',
                'created_at' => '',
            ];
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return ['ok' => false, 'generated' => false, 'message' => 'Não foi possível criar ' . $dir,
                    'public_key' => '', 'fingerprint' => '', 'created_at' => ''];
        }
        @chmod($dir, 0700);

        @unlink(self::privatePath());
        @unlink(self::publicPath());

        $cmd = escapeshellarg($bin) . ' -t ed25519 -a 100 -N "" -C ' . escapeshellarg(self::COMMENT)
             . ' -f ' . escapeshellarg(self::privatePath()) . ' 2>&1';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);

        if ($code !== 0 || !self::hasKey()) {
            return ['ok' => false, 'generated' => false,
                    'message' => 'ssh-keygen falhou: ' . substr(implode(' ', $out), 0, 200),
                    'public_key' => '', 'fingerprint' => '', 'created_at' => ''];
        }

        @chmod(self::privatePath(), 0600);
        @chmod(self::publicPath(), 0644);

        $info = self::readPublic();
        SettingsRepository::set('lb_ssh_public_key', $info['public_key']);
        SettingsRepository::set('lb_ssh_fingerprint', $info['fingerprint']);
        SettingsRepository::set('lb_ssh_created_at', date('c'));
        Audit::log('lb_keygen', 'Par Ed25519 do cérebro gerado: ' . $info['fingerprint']);

        return ['ok' => true, 'generated' => true, 'message' => 'Par Ed25519 gerado.'] + self::info();
    }

    public static function info(): array
    {
        $pub = self::readPublic();
        return [
            'exists' => self::hasKey(),
            'public_key' => $pub['public_key'],
            'fingerprint' => $pub['fingerprint'] ?: (string) SettingsRepository::get('lb_ssh_fingerprint', ''),
            'created_at' => (string) SettingsRepository::get('lb_ssh_created_at', ''),
            'keygen' => self::keygenBinary() !== '',
            'message' => '',
        ];
    }

    public static function publicKey(): string
    {
        return self::readPublic()['public_key'];
    }

    private static function readPublic(): array
    {
        if (!self::hasKey()) {
            return ['public_key' => '', 'fingerprint' => ''];
        }
        $pub = trim((string) @file_get_contents(self::publicPath()));
        $fp = '';
        $bin = self::keygenBinary();
        if ($bin !== '') {
            $out = [];
            $code = 1;
            @exec(escapeshellarg($bin) . ' -lf ' . escapeshellarg(self::publicPath()) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $parts = preg_split('/\s+/', trim((string) $out[0]));
                $fp = $parts[1] ?? '';
            }
        }
        if ($fp === '' && $pub !== '') {
            $parts = preg_split('/\s+/', $pub);
            $raw = base64_decode((string) ($parts[1] ?? ''), true);
            if ($raw !== false && $raw !== '') {
                $fp = 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, true)), '=');
            }
        }
        return ['public_key' => $pub, 'fingerprint' => $fp];
    }

    /**
     * Script idempotente que instala a chave pública no authorized_keys do LB
     * e endurece o SSH do músculo sem derrubar a sessão atual.
     */
    public static function installScript(string $sshUser = 'root'): string
    {
        $pub = self::publicKey();
        if ($pub === '') {
            throw new RuntimeException('Chave pública do cérebro indisponível.');
        }
        $home = $sshUser === 'root' ? '/root' : '/home/' . $sshUser;
        $q = escapeshellarg($pub);
        $ak = escapeshellarg($home . '/.ssh/authorized_keys');

        return 'set -e; mkdir -p ' . escapeshellarg($home . '/.ssh') . '; chmod 700 ' . escapeshellarg($home . '/.ssh') . ';'
             . ' touch ' . $ak . '; chmod 600 ' . $ak . ';'
             . ' grep -qxF ' . $q . ' ' . $ak . ' || echo ' . $q . ' >> ' . $ak . ';'
             . ' chown -R ' . escapeshellarg($sshUser) . ' ' . escapeshellarg($home . '/.ssh') . ' 2>/dev/null || true;'
             . ' grep -q "^PubkeyAuthentication yes" /etc/ssh/sshd_config || echo "PubkeyAuthentication yes" >> /etc/ssh/sshd_config;'
             . ' (systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true);'
             . ' echo lb-key-installed';
    }
}
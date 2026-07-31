<?php

/**
 * Monta o PACOTE MÍNIMO que roda no músculo.
 *
 * O LB não recebe painel, jobs, sync do XUI nem dashboards. Só o caminho
 * público do proxy e as classes que ele realmente usa. Menos código = menos
 * RAM, menos CPU e menos superfície de ataque.
 */
final class LbPackageBuilder
{
    public const FILES = [
        'public/proxy.php',
        'app/proxy-bootstrap.php',
        'app/Config.php',
        'app/Database.php',
        'app/SettingsRepository.php',
        'app/Audit.php',
        'app/OriginRepository.php',
        'app/AliasRepository.php',
        'app/Cache.php',
        'app/XuiOrigin.php',
        'app/Tokens.php',
        'app/AccessGuard.php',
        'app/RequestContext.php',
        'app/RequestLog.php',
        'app/CredentialGuard.php',
        'app/CdnSession.php',
        'app/AuditTimeline.php',
        'app/LbRouter.php',
        'app/DirectSourceParser.php',
        'app/DirectCatalog.php',
        'app/DirectSource.php',
        'app/Divergence.php',
        'app/PlaylistRewriter.php',
        'app/StreamProxy.php',
        'config/app.php',
    ];

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /** @return array{tar_b64:string,files:int,bytes:int,missing:array} */
    public static function build(): array
    {
        $root = self::root();
        $tmp = tempnam(sys_get_temp_dir(), 'lbpkg');
        if ($tmp === false) {
            throw new RuntimeException('Não foi possível criar o pacote do LB.');
        }
        @unlink($tmp);
        $tarPath = $tmp . '.tar';

        $present = [];
        $missing = [];
        foreach (self::FILES as $rel) {
            if (is_file($root . '/' . $rel)) {
                $present[] = $rel;
            } else {
                $missing[] = $rel;
            }
        }

        if ($missing) {
            throw new RuntimeException('Pacote do LB incompleto: faltando ' . implode(', ', $missing));
        }

        $phar = new PharData($tarPath);
        foreach ($present as $rel) {
            $phar->addFile($root . '/' . $rel, $rel);
        }
        $phar->addFromString('public/health.php', self::healthStub());
        $phar->addFromString('LB_PACKAGE.txt', 'proxy-mago LB minimal package ' . date('c') . "\n");
        unset($phar);

        $raw = (string) file_get_contents($tarPath);
        @unlink($tarPath);

        return [
            'tar_b64' => base64_encode(gzencode($raw, 6)),
            'files' => count($present) + 2,
            'bytes' => strlen($raw),
            'missing' => $missing,
        ];
    }

    private static function healthStub(): string
    {
        return <<<'PHP'
<?php
// Health local do LB — leve de propósito: sem SQLite, sem XUI, sem painel.
header('Content-Type: application/json');
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
echo json_encode([
    'role' => 'lb',
    'ok' => true,
    'ts' => time(),
    'load1' => round((float) ($load[0] ?? 0), 2),
    'php' => PHP_VERSION,
], JSON_UNESCAPED_SLASHES);
PHP;
    }
}

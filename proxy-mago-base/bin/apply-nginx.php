<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Este comando deve ser executado como root via CLI.\n");
    exit(1);
}

$base = dirname(__DIR__);
$source = $base . '/storage/nginx.pending.conf';
$target = '/etc/nginx/sites-available/proxy-mago.conf';
$link = '/etc/nginx/sites-enabled/proxy-mago.conf';
$temp = $target . '.new';
$backup = $target . '.backup';

try {
    clearstatcache(true, $source);
    if (!is_file($source) || fileowner($source) !== 33 || filesize($source) > 65536) {
        throw new RuntimeException('Configuração pendente ausente ou insegura.');
    }
    $config = file_get_contents($source);
    if ($config === false || !str_starts_with($config, '# Gerado pelo painel Proxy Mago.')) {
        throw new RuntimeException('Configuração pendente inválida.');
    }
    if (file_put_contents($temp, $config, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar a configuração temporária.');
    }
    chmod($temp, 0644);
    if (is_file($target)) {
        copy($target, $backup);
    }
    rename($temp, $target);
    if (!is_link($link)) {
        @unlink($link);
        symlink($target, $link);
    }
    exec('/usr/sbin/nginx -t 2>&1', $testOutput, $testCode);
    if ($testCode !== 0) {
        if (is_file($backup)) {
            copy($backup, $target);
        }
        throw new RuntimeException("nginx -t falhou; configuração anterior restaurada:\n" . implode("\n", $testOutput));
    }
    exec('/usr/bin/systemctl reload nginx 2>&1', $reloadOutput, $reloadCode);
    if ($reloadCode !== 0) {
        if (is_file($backup)) {
            copy($backup, $target);
            exec('/usr/bin/systemctl reload nginx 2>&1');
        }
        throw new RuntimeException('Falha ao recarregar Nginx: ' . implode("\n", $reloadOutput));
    }
    unlink($source);
    echo "Configuração aplicada e Nginx recarregado.\n";
} catch (Throwable $e) {
    @unlink($temp);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

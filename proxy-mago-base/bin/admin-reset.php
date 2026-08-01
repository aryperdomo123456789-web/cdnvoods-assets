<?php

declare(strict_types=1);

/**
 * Reset de acesso ao painel.
 *
 * Uso:
 *   php bin/admin-reset.php --user=admin --pass='NovaSenhaForte'
 *   php bin/admin-reset.php --user=admin            (gera senha aleatória)
 *
 * A senha é guardada apenas como hash bcrypt em settings.admin_password_hash.
 * Não existe forma de "descobrir" a senha antiga — só redefinir.
 */

require_once dirname(__DIR__) . '/app/bootstrap-cli.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? '1';
    }
}

$user = trim((string) ($args['user'] ?? SettingsRepository::get('admin_user', 'admin')));
if ($user === '') {
    $user = 'admin';
}

$pass = (string) ($args['pass'] ?? '');
$generated = false;
if ($pass === '') {
    $pass = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Aa'), '=');
    $generated = true;
}

if (strlen($pass) < 8) {
    fwrite(STDERR, "senha precisa de pelo menos 8 caracteres\n");
    exit(1);
}

SettingsRepository::set('admin_user', $user);
SettingsRepository::set('admin_password_hash', password_hash($pass, PASSWORD_BCRYPT));

Audit::log('admin_reset', 'Credencial do painel redefinida via CLI', 'cli', 'bin/admin-reset.php');

echo "usuario: {$user}\n";
echo 'senha  : ' . ($generated ? $pass . " (gerada agora)\n" : "definida conforme --pass\n");
echo "login  : /login.php\n";

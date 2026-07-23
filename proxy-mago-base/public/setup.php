<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (SettingsRepository::seeded()) {
    header('Location: /login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $panelDomain = trim((string) ($_POST['panel_domain'] ?? ''));
    $originHost = trim((string) ($_POST['origin_host'] ?? ''));
    $originPort = (int) ($_POST['origin_port'] ?? 80);
    $appSecret = trim((string) ($_POST['app_secret'] ?? ''));

    if ($adminUser === '' || $adminPass === '' || $originHost === '' || $originPort < 1) {
        $error = 'Preencha os campos obrigatórios.';
    } else {
        if ($appSecret === '') {
            $appSecret = bin2hex(random_bytes(32));
        }

        SettingsRepository::set('admin_user', $adminUser);
        SettingsRepository::set('admin_password_hash', password_hash($adminPass, PASSWORD_DEFAULT));
        SettingsRepository::set('panel_domain', $panelDomain);
        SettingsRepository::set('origin_host', $originHost);
        SettingsRepository::set('origin_port', $originPort);
        SettingsRepository::set('app_secret', $appSecret);
        SettingsRepository::set('allowed_user_agent', Config::get('allowed_user_agent'));
        SettingsRepository::set('token_ttl', Config::get('token_ttl'));
        SettingsRepository::set('created_at', date('c'));

        Audit::log('setup', 'Initial setup completed', $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');

        header('Location: /login.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup | Proxy Mago</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="page-bg">
<main class="card">
    <h1>Primeira configuração</h1>
    <p>Crie o admin, o domínio oficial do main e a origem inicial do proxy.</p>
    <?php if ($error): ?><div class="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <label>Usuário admin</label>
        <input name="admin_user" required>
        <label>Senha admin</label>
        <input name="admin_pass" type="password" required>
        <label>Domínio oficial do main</label>
        <input name="panel_domain" placeholder="cdnvoods.vr766.com">
        <small>Esse será o endereço público principal. Para mascarar o IP da VPS, mantenha este host atrás da Cloudflare com proxy ativo.</small>
        <label>IP ou host da origem XUI</label>
        <input name="origin_host" placeholder="38.190.176.170" required>
        <label>Porta da origem XUI</label>
        <input name="origin_port" type="number" min="1" max="65535" value="80" required>
        <label>Segredo interno do proxy</label>
        <input name="app_secret" placeholder="opcional, gerado automaticamente">
        <button type="submit">Salvar e iniciar</button>
    </form>
</main>
</body>
</html>

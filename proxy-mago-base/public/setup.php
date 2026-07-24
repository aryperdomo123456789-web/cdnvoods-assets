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
    $panelDomain = strtolower(trim((string) ($_POST['panel_domain'] ?? '')));
    $originHost = trim((string) ($_POST['origin_host'] ?? ''));
    $originPort = (int) ($_POST['origin_port'] ?? 80);
    $originType = ($_POST['origin_type'] ?? 'a') === 'cname' ? 'cname' : 'a';
    $originHostHeader = trim((string) ($_POST['origin_host_header'] ?? ''));
    $appSecret = trim((string) ($_POST['app_secret'] ?? ''));

    if ($adminUser === '' || $adminPass === '' || $originHost === '' || $originPort < 1 || $panelDomain === '') {
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
        SettingsRepository::set('allowed_user_agent', (string) Config::get('allowed_user_agent', ''));
        SettingsRepository::set('token_ttl', (int) Config::get('token_ttl', 3600));
        SettingsRepository::set('created_at', date('c'));

        // Semeia a origem inicial e o alias primário (o domínio público do painel).
        $originId = OriginRepository::create([
            'name' => 'Origem principal',
            'host' => $originHost,
            'port' => $originPort,
            'scheme' => 'http',
            'base_path' => '',
            'auth_user' => '',
            'auth_pass' => '',
            'active' => 1,
            'type' => $originType,
            'host_header' => $originHostHeader,
        ]);

        AliasRepository::create([
            'hostname' => $panelDomain,
            'origin_id' => $originId,
            'is_primary' => 1,
            'active' => 1,
        ]);

        Audit::log('setup', 'Initial setup completed (origin+alias seeded)', $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');

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
        <input name="panel_domain" placeholder="cdnvoods.vr766.com" required>
        <small>Endereço público principal. Mantenha atrás da Cloudflare com proxy laranja para esconder o IP da VPS.</small>
        <label>IP ou host da origem XUI</label>
        <input name="origin_host" placeholder="38.190.176.170" required>
        <label>Porta da origem XUI</label>
        <input name="origin_port" type="number" min="1" max="65535" value="80" required>
        <label>Tipo de apontamento</label>
        <select name="origin_type">
            <option value="a">A (IP direto)</option>
            <option value="cname">CNAME (hostname)</option>
        </select>
        <label>Host header enviado ao XUI (opcional)</label>
        <input name="origin_host_header" placeholder="ex: painel.provedor.com">
        <small>Deixe vazio para usar o host da origem. Útil quando o XUI só responde para um domínio específico.</small>
        <label>Segredo interno do proxy</label>
        <input name="app_secret" placeholder="opcional, gerado automaticamente">
        <button type="submit">Salvar e iniciar</button>
    </form>
</main>
</body>
</html>

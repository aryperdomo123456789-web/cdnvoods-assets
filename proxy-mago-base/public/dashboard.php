<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$settings = SettingsRepository::all();
$nginxConfig = NginxGenerator::render($settings);
$recentLogs = Database::pdo()->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 10')->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Proxy Mago</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="page-bg">
<header class="topbar">
    <div>
        <strong>Proxy Mago</strong>
        <span>Painel leve para uma única origem</span>
    </div>
    <nav>
        <a href="/export-config.php">Exportar Nginx</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <section class="card">
        <h2>Configuração atual</h2>
        <p><strong>Fluxo oficial:</strong> o main público aponta para a VPS via Cloudflare, e a origem XUI fica apenas cadastrada internamente.</p>
        <form method="post" action="/save.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Usuário admin</label>
            <input name="admin_user" value="<?php echo htmlspecialchars((string) ($settings['admin_user'] ?? '')); ?>" required>
            <label>Nova senha admin</label>
            <input name="admin_pass" type="password" placeholder="Deixe em branco para manter">
            <label>Domínio oficial do main</label>
            <input name="panel_domain" value="<?php echo htmlspecialchars((string) ($settings['panel_domain'] ?? '')); ?>">
            <label>IP ou host da origem XUI</label>
            <input name="origin_host" value="<?php echo htmlspecialchars((string) ($settings['origin_host'] ?? '')); ?>" required>
            <label>Porta da origem XUI</label>
            <input name="origin_port" type="number" min="1" max="65535" value="<?php echo htmlspecialchars((string) ($settings['origin_port'] ?? 80)); ?>" required>
            <label>User-Agent permitido</label>
            <input name="allowed_user_agent" value="<?php echo htmlspecialchars((string) ($settings['allowed_user_agent'] ?? Config::get('allowed_user_agent'))); ?>">
            <label>TTL do token</label>
            <input name="token_ttl" type="number" min="60" value="<?php echo htmlspecialchars((string) ($settings['token_ttl'] ?? Config::get('token_ttl'))); ?>">
            <label>Segredo interno do proxy</label>
            <input name="app_secret" value="<?php echo htmlspecialchars((string) ($settings['app_secret'] ?? '')); ?>">
            <button type="submit">Salvar</button>
        </form>
    </section>

    <section class="card">
        <h2>Config do Nginx</h2>
        <p>Este snippet é o ponto de partida para o proxy reverso. O main público deve ficar atrás da Cloudflare para não expor o IP da VPS.</p>
        <textarea readonly rows="22"><?php echo htmlspecialchars($nginxConfig); ?></textarea>
    </section>

    <section class="card">
        <h2>Fluxo operacional</h2>
        <ul>
            <li><strong>Main oficial:</strong> <code>cdnvoods.vr766.com</code></li>
            <li><strong>Origem protegida:</strong> <code>38.190.176.170</code></li>
            <li><strong>O que entra no painel:</strong> IP/host da origem XUI e domínio oficial do main</li>
            <li><strong>O que não deve aparecer publicamente:</strong> o IP da VPS e o IP da origem</li>
            <li><strong>CNAMEs extras:</strong> apontam para o main oficial e continuam atrás da Cloudflare</li>
        </ul>
    </section>

    <section class="card full">
        <h2>Últimos eventos</h2>
        <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>IP</th>
                    <th>Mensagem</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLogs as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['event_type']); ?></td>
                        <td><?php echo htmlspecialchars($row['client_ip']); ?></td>
                        <td><?php echo htmlspecialchars($row['message']); ?></td>
                        <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>

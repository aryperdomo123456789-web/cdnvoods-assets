<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$errors = [
    'host'      => 'Informe um IP ou domínio válido para o servidor XUI.',
    'porta'     => 'Porta inválida (1 a 65535, ou 0 para autodescobrir).',
    'app_host'  => 'Informe um DNS de app válido (ex: assistservpd.phpd77.com).',
    'sem_xui'   => 'Cadastre primeiro a origem padrão do XUI no painel de domínios.',
];
$err = isset($_GET['err']) ? ($errors[$_GET['err']] ?? 'Não foi possível salvar.') : null;

$servers  = AppCode::servers();
$stats    = AppCode::stats();
$appHosts = AppCode::hosts();
$q        = trim((string) ($_GET['q'] ?? ''));
$routes   = AppCode::recentRoutes(60, $q);
$edit     = isset($_GET['edit']) ? AppCode::server((int) $_GET['edit']) : null;
$vpsIp    = (string) SettingsRepository::get('vps_ip', '45.140.192.237');
$mainDom  = (string) SettingsRepository::get('panel_domain', 'cdnvoods.vr766.com');
$csrf     = csrf_token();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — Código de App</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="page-bg">
<header class="topbar">
    <div>
        <strong>CDN Voods</strong>
        <span>Código de App — 1 código, vários XUIs, sem embaralhar usuário</span>
    </div>
    <nav>
        <a href="/dashboard.php">Domínios</a>
        <a href="/app-code.php">Código de App</a>
        <a href="/restream.php">Restreamento ao vivo</a>
        <a href="/jobs.php">Jobs</a>
        <a href="/lb.php">LB</a>
        <a href="/avancado.php">Avançado</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <?php if ($err): ?><section class="card full"><div class="alert"><?php echo htmlspecialchars($err); ?></div></section><?php endif; ?>
    <?php if ($flash): ?><section class="card full"><div class="alert success"><?php echo htmlspecialchars($flash); ?></div></section><?php endif; ?>

    <section class="card full">
        <h2>Como funciona</h2>
        <p>O app tem <strong>um DNS fixo</strong> compilado dentro dele. Esse DNS aponta para esta CDN.
           Quando um assinante faz login, a CDN descobre em qual XUI aquele <code>username</code> existe,
           <strong>gruda</strong> o usuário naquele XUI e nunca mais troca. É essa grudação que impede
           embaralhar usuário, lista de reprodução e EPG.</p>
        <table>
            <thead><tr><th>Tipo</th><th>Nome</th><th>Conteúdo</th></tr></thead>
            <tbody>
                <tr><td>CNAME</td><td><code>assistservpd.phpd77.com</code></td><td><code><?php echo htmlspecialchars($mainDom); ?></code></td></tr>
                <tr><td>A</td><td><code>assistservpd.phpd77.com</code></td><td><code><?php echo htmlspecialchars($vpsIp); ?></code></td></tr>
            </tbody>
        </table>
        <p><small>Descoberta só roda em login/playlist. Segmento de vídeo (.ts) usa só o cache grudado — custo zero para a CDN.</small></p>
    </section>

    <section class="card full">
        <h2>1. DNS do app e chave geral</h2>
        <form method="post" action="/save-app-code.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="config">
            <label>
                <input type="checkbox" name="enabled" value="1" <?php echo AppCode::enabled() ? 'checked' : ''; ?>>
                Ativar roteamento multi-XUI por código de app
            </label>
            <label>DNS que estão dentro do app (um por linha)</label>
            <textarea name="hosts" rows="3" autocomplete="off"
                      placeholder="assistservpd.phpd77.com"><?php echo htmlspecialchars(implode("\n", $appHosts)); ?></textarea>
            <label>
                <input type="checkbox" name="fallback" value="1" <?php echo AppCode::fallbackToDefault() ? 'checked' : ''; ?>>
                Se nenhum XUI reconhecer o usuário, cair na origem padrão (recomendado — a CDN nunca para)
            </label>
            <button type="submit">Salvar configuração</button>
        </form>
        <p><small>Salvar já registra automaticamente cada DNS como domínio protegido, então o proxy passa a atender esse host.</small></p>
    </section>

    <section class="card full">
        <h2>2. <?php echo $edit ? 'Editar servidor XUI #' . (int) $edit['id'] : 'Adicionar servidor XUI'; ?></h2>
        <form method="post" action="/save-app-code.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="server">
            <input type="hidden" name="id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">
            <label>Nome de referência</label>
            <input name="name" placeholder="XUI Wave" autocomplete="off" value="<?php echo htmlspecialchars((string) ($edit['name'] ?? '')); ?>">
            <label>IP ou domínio do XUI (interno — nunca sai para o público)</label>
            <input name="host" required placeholder="38.46.221.36 ou dafonte.uk" autocomplete="off"
                   value="<?php echo htmlspecialchars((string) ($edit['host'] ?? '')); ?>">
            <label>Porta (0 = autodescobrir portas padrão do XUI)</label>
            <input name="port" type="number" min="0" max="65535" value="<?php echo (int) ($edit['port'] ?? 80); ?>">
            <label>Protocolo</label>
            <select name="scheme">
                <option value="http"  <?php echo ($edit['scheme'] ?? 'http') === 'http'  ? 'selected' : ''; ?>>http</option>
                <option value="https" <?php echo ($edit['scheme'] ?? '')     === 'https' ? 'selected' : ''; ?>>https</option>
            </select>
            <label>Host header (só se o XUI responde por vhost específico)</label>
            <input name="host_header" placeholder="opcional" autocomplete="off"
                   value="<?php echo htmlspecialchars((string) ($edit['host_header'] ?? '')); ?>">
            <label>Hosts extras a mascarar (CDN interna do XUI, separados por vírgula)</label>
            <input name="extra_hosts" placeholder="opcional" autocomplete="off"
                   value="<?php echo htmlspecialchars((string) ($edit['extra_hosts'] ?? '')); ?>">
            <label>Prioridade na descoberta (menor testa primeiro)</label>
            <input name="priority" type="number" min="1" max="999" value="<?php echo (int) ($edit['priority'] ?? 100); ?>">
            <label>
                <input type="checkbox" name="active" value="1" <?php echo (!$edit || (int) $edit['active'] === 1) ? 'checked' : ''; ?>>
                Servidor ativo
            </label>
            <button type="submit"><?php echo $edit ? 'Salvar alterações' : 'Adicionar servidor'; ?></button>
            <?php if ($edit): ?><p><a href="/app-code.php">Cancelar edição</a></p><?php endif; ?>
        </form>
    </section>

    <section class="card full">
        <h2>Servidores cadastrados</h2>
        <?php if (!$servers): ?>
            <p><em>Nenhum XUI cadastrado. Adicione pelo menos um acima.</em></p>
        <?php else: ?>
        <table>
            <thead><tr><th>Nome</th><th>Destino</th><th>Prioridade</th><th>Usuários grudados</th><th>Requests</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php
            $byId = [];
            foreach ($stats['servidores'] as $row) { $byId[(int) $row['id']] = $row; }
            foreach ($servers as $s):
                $st = $byId[(int) $s['id']] ?? ['usuarios' => 0, 'hits' => 0]; ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) $s['name']); ?></td>
                    <td><code><?php echo htmlspecialchars($s['scheme'] . '://' . $s['host'] . ((int) $s['port'] > 0 ? ':' . (int) $s['port'] : ' (auto)')); ?></code></td>
                    <td><?php echo (int) $s['priority']; ?></td>
                    <td><?php echo (int) $st['usuarios']; ?></td>
                    <td><?php echo (int) $st['hits']; ?></td>
                    <td><span class="status <?php echo (int) $s['active'] === 1 ? 'ok' : 'fail'; ?>"><?php echo (int) $s['active'] === 1 ? 'ATIVO' : 'PARADO'; ?></span></td>
                    <td>
                        <a href="/app-code.php?edit=<?php echo (int) $s['id']; ?>">Editar</a>
                        <form method="post" action="/save-app-code.php" class="inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="action" value="test">
                            <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                            <button type="submit">Testar</button>
                        </form>
                        <form method="post" action="/save-app-code.php" class="inline" onsubmit="return confirm('Remover este XUI? Os usuários grudados nele serão redescobertos.')">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                            <button type="submit" class="danger">Remover</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section class="card full">
        <h2>Usuários roteados</h2>
        <p>
            Grudados: <strong><?php echo (int) $stats['total']; ?></strong> ·
            Saudáveis: <strong><?php echo (int) $stats['ok']; ?></strong> ·
            Com falha: <strong><?php echo (int) $stats['com_falha']; ?></strong> ·
            Requests atendidos: <strong><?php echo (int) $stats['hits']; ?></strong> ·
            Em cache negativo: <strong><?php echo (int) $stats['negativos']; ?></strong>
        </p>
        <form method="get" action="/app-code.php" class="inline">
            <input name="q" placeholder="buscar username" value="<?php echo htmlspecialchars($q); ?>" autocomplete="off">
            <button type="submit">Buscar</button>
        </form>
        <?php if (!$routes): ?>
            <p><em>Nenhum usuário roteado ainda.</em></p>
        <?php else: ?>
        <table>
            <thead><tr><th>Username</th><th>XUI dono</th><th>Requests</th><th>Falhas</th><th>Último acesso</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($routes as $r): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars((string) $r['username']); ?></code></td>
                    <td><?php echo htmlspecialchars((string) ($r['server_name'] ?: $r['host'])); ?>
                        <small><code><?php echo htmlspecialchars($r['scheme'] . '://' . $r['host'] . ':' . (int) $r['port']); ?></code></small></td>
                    <td><?php echo (int) $r['hits']; ?></td>
                    <td><?php echo (int) $r['failures']; ?></td>
                    <td><?php echo (int) $r['last_epoch'] > 0 ? date('d/m H:i:s', (int) $r['last_epoch']) : '-'; ?></td>
                    <td>
                        <form method="post" action="/save-app-code.php" class="inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="action" value="unpin">
                            <input type="hidden" name="username" value="<?php echo htmlspecialchars((string) $r['username']); ?>">
                            <button type="submit" class="danger">Desgrudar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><small>Desgrudar força a próxima entrada do usuário a redescobrir o XUI dono. Útil quando o assinante é migrado de servidor.</small></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>

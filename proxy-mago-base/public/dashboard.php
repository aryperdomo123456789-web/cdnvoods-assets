<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$settings   = SettingsRepository::all();
$vpsIp      = (string) ($settings['vps_ip'] ?? '45.140.192.237');
$mainDomain = (string) ($settings['panel_domain'] ?? 'cdnvoods.vr766.com');

$aliases = AliasRepository::all();
$xui     = XuiOrigin::get();
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$errors = [
    'dominio'  => 'Informe um domínio público válido (ex: meudominio.com).',
    'destino'  => 'Destino inválido para o tipo escolhido.',
    'ip'       => 'No tipo A o destino precisa ser um IP (ex: 38.190.176.170).',
    'host'     => 'No tipo CNAME o destino precisa ser um domínio (ex: dafonte.uk).',
    'porta'    => 'Porta inválida.',
    'duplicado'=> 'Esse domínio público já está cadastrado.',
    'sem_xui'  => 'Cadastre primeiro a origem do XUI (passo 1).',
];
$err = isset($_GET['err']) ? ($errors[$_GET['err']] ?? 'Não foi possível salvar.') : null;

$xuiType   = strtolower((string) ($xui['type'] ?? 'a'));
$xuiTarget = (string) ($xui['host'] ?? '');
$xuiPort   = (int) ($xui['port'] ?? 80);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — Domínios</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="page-bg">
<header class="topbar">
    <div>
        <strong>CDN Voods</strong>
        <span>Proteção de origem XUI — 1 domínio público, 1 destino</span>
    </div>
    <nav>
        <a href="/auditoria.php">Auditoria</a>
        <a href="/restream.php">Restreamento ao vivo</a>
        <a href="/jobs.php">Jobs</a>
        <a href="/lb.php">LB</a>
        <a href="/avancado.php">Avançado</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <section class="card full">
        <?php if ($err): ?><div class="alert"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
        <?php if ($flash): ?><div class="alert success"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
        <h2>1. Origem do XUI (interno — nunca sai para o público)</h2>
        <p>Este painel protege <strong>um</strong> XUI. Cadastre a origem uma única vez;
           todos os domínios de proteção usam ela. Este dado fica apenas no banco local,
           nunca vira DNS público e nunca aparece em playlist, player_api ou EPG.</p>
        <form method="post" action="/save-xui.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Tipo de origem</label>
            <select name="type">
                <option value="cname" <?php echo $xuiType === 'cname' ? 'selected' : ''; ?>>CNAME — o main do XUI tem DNS (ex: dafonte.uk)</option>
                <option value="a" <?php echo $xuiType === 'a' ? 'selected' : ''; ?>>A — o main do XUI é só IP (ex: 38.190.176.170)</option>
            </select>
            <label>Destino do XUI</label>
            <input name="target" required placeholder="dafonte.uk ou 38.190.176.170" autocomplete="off"
                   value="<?php echo htmlspecialchars($xuiTarget); ?>">
            <label>Porta do XUI</label>
            <input name="port" type="number" min="1" max="65535" required value="<?php echo $xuiPort ?: 80; ?>">
            <button type="submit">Salvar origem</button>
        </form>
        <?php if ($xui): ?>
            <p><small>Origem ativa: <code><?php echo strtoupper($xuiType) . ' · ' . htmlspecialchars($xuiTarget . ':' . $xuiPort); ?></code></small></p>
        <?php endif; ?>
    </section>

    <section class="card full">
        <h2>2. Aponte seus domínios na Cloudflare (nuvem cinza / DNS only)</h2>
        <table>
            <thead><tr><th>Tipo</th><th>Nome</th><th>Conteúdo</th></tr></thead>
            <tbody>
                <tr><td>CNAME</td><td><code>meudominio.com</code></td><td><code><?php echo htmlspecialchars($mainDomain); ?></code></td></tr>
                <tr><td>A</td><td><code>meudominio.com</code></td><td><code><?php echo htmlspecialchars($vpsIp); ?></code></td></tr>
            </tbody>
        </table>
        <p>Sempre para a VPS da CDN — <strong>nunca</strong> para o IP ou DNS do XUI.
           Pode apontar quantos domínios quiser.</p>
    </section>

    <section class="card full">
        <h2>3. Cadastre os domínios de proteção</h2>
        <form method="post" action="/save-domain.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Domínios que você apontou para a VPS (um por linha)</label>
            <textarea name="hostname" rows="4" required autocomplete="off"
                      placeholder="meudominio.com&#10;outrodominio.com&#10;cdn3.meudominio.com"></textarea>
            <button type="submit" <?php echo $xui ? '' : 'disabled'; ?>>Proteger domínios</button>
        </form>
        <?php if (!$xui): ?><p><small>Salve a origem do XUI no passo 1 para liberar o cadastro.</small></p><?php endif; ?>
    </section>

    <section class="card full">
        <h2>Domínios protegidos</h2>
        <?php if (!$aliases): ?>
            <p><em>Nenhum domínio protegido ainda.</em></p>
        <?php else: ?>
        <table>
            <thead><tr><th>Domínio público (entregue ao cliente)</th><th>Origem XUI (oculta)</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($aliases as $a):
                $o = OriginRepository::find((int) $a['origin_id']); ?>
                <tr>
                    <td><code>http(s)://<?php echo htmlspecialchars($a['hostname']); ?>/get.php?username=…&amp;password=…&amp;type=m3u_plus</code></td>
                    <td><?php echo strtoupper((string) ($o['type'] ?? 'a')); ?> · <code><?php echo htmlspecialchars(($o['host'] ?? '?') . ':' . ($o['port'] ?? '')); ?></code></td>
                    <td><span class="status <?php echo (int) $a['active'] === 1 ? 'ok' : 'fail'; ?>"><?php echo (int) $a['active'] === 1 ? 'ATIVO' : 'PARADO'; ?></span></td>
                    <td>
                        <form method="post" action="/delete-domain.php" class="inline" onsubmit="return confirm('Remover este domínio?')">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                            <button type="submit" class="danger">Remover</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><small>O IP, o DNS e o conteúdo Direct Source do XUI nunca aparecem na resposta: playlists, player_api e EPG são reescritos para o domínio público.</small></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>

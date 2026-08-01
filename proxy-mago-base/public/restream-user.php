<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$username = trim((string) ($_GET['username'] ?? ''));
$d = $username !== '' ? RestreamRuntime::userDetail($username) : null;

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function bytes_fmt($b): string {
    $b = (float) $b; $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return round($b, $i ? 1 : 0) . $u[$i];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restreamento — <?php echo h($username); ?></title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        table{width:100%;font-size:13px}td,th{padding:5px 6px;vertical-align:top}
        .muted{opacity:.65}.tag{padding:1px 6px;border-radius:4px;font-size:11px}
        .bad{background:#7f1d1d}.ok{background:#064e3b}.warn{background:#78350f}.info{background:#1e3a8a}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>Detalhe do assinante</span></div>
    <nav>
        <a href="/auditoria.php">Auditoria</a>
        <a href="/restream.php">Ao vivo</a>
        <a href="/xui.php">XUI</a>
        <a href="/jobs.php">Jobs</a>
        <a href="/lb.php">LB</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
<?php if (!$d): ?>
    <section class="card full">
        <h2>Buscar usuário</h2>
        <form method="get" class="filters">
            <input name="username" placeholder="username do XUI" required>
            <button type="submit">Abrir</button>
        </form>
    </section>
<?php else:
    $rt = $d['runtime'] ?? null;
    $xu = $d['xui_user'] ?? null;
?>
    <section class="card full">
        <h2><?php echo h($username); ?></h2>
        <p class="muted">
            Conexões pela CDN: <strong><?php echo (int) ($rt['cdn_connections_now'] ?? 0); ?></strong>
            · pelo XUI: <strong><?php echo (int) ($rt['xui_connections_now'] ?? 0); ?></strong>
            · fonte do contador: <strong><?php echo h($rt['count_source'] ?? 'cdn_local'); ?></strong>
            / limite XUI: <strong><?php echo $xu ? (int) $xu['max_connections'] : '—'; ?></strong>
            · status: <strong><?php echo h($rt['health_status'] ?? 'sem atividade'); ?></strong>
            · última atividade: <?php echo h($rt['last_activity_at'] ?? '—'); ?>
        </p>
        <?php if ($xu): ?>
        <p class="muted">
            XUI id <?php echo (int) $xu['user_id']; ?> ·
            ativo: <?php echo (int) $xu['enabled'] ? 'sim' : 'não'; ?> ·
            trial: <?php echo (int) $xu['is_trial'] ? 'sim' : 'não'; ?> ·
            restreamer: <?php echo (int) $xu['is_restreamer'] ? 'sim' : 'não'; ?> ·
            expira: <?php echo h($xu['exp_date'] ?: 'ilimitado'); ?> ·
            senha nunca é armazenada em claro (fingerprint <?php echo h(substr((string) $xu['credential_fingerprint'], 0, 12)); ?>)
        </p>
        <?php else: ?>
        <p class="muted">Usuário ainda não espelhado do XUI — rode o sync ou verifique a conexão read-only.</p>
        <?php endif; ?>
    </section>

    <section class="card full">
        <h2>Conexões ao vivo <span class="muted" id="live-age">agora</span></h2>
        <p class="muted">
            Cada linha é UMA conexão real contada pela própria CDN, antes do XUI:
            o que está vendo (canal, filme ou série), há quanto tempo está online e por onde sai.
            Atualiza sozinho a cada 2s, sem recarregar a página.
        </p>
        <p id="live-summary" class="muted">carregando…</p>
        <table>
            <thead><tr>
                <th>Vendo agora</th><th>Tipo</th><th>Uptime</th><th>IP final</th><th>App</th>
                <th>Saída</th><th>Entrega</th><th>Reqs</th><th>Bytes</th><th>Estado</th>
            </tr></thead>
            <tbody id="live-rows"><tr><td colspan="10" class="muted">carregando…</td></tr></tbody>
        </table>
        <p class="muted" id="live-iplock"></p>
    </section>

    <?php if ($d['open_divergences']): ?>
    <section class="card full">
        <h2>Divergências abertas</h2>
        <table>
            <thead><tr><th>Tipo</th><th>Sev.</th><th>CDN</th><th>XUI</th><th>Limite</th><th>Causa provável</th><th>Ocorrências</th></tr></thead>
            <tbody>
            <?php foreach ($d['open_divergences'] as $x): ?>
                <tr>
                    <td><?php echo h($x['kind']); ?></td>
                    <td><span class="tag <?php echo $x['severity'] === 'critical' ? 'bad' : ($x['severity'] === 'warn' ? 'warn' : 'info'); ?>"><?php echo h($x['severity']); ?></span></td>
                    <td><?php echo (int) $x['cdn_count']; ?></td>
                    <td><?php echo (int) $x['xui_count']; ?></td>
                    <td><?php echo (int) $x['max_connections'] ?: '-'; ?></td>
                    <td class="muted"><?php echo h($x['probable_cause']); ?></td>
                    <td><?php echo (int) $x['occurrences']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($d['direct_hops']): ?>
    <section class="card full">
        <h2>Direct source — trilha interna</h2>
        <p class="muted">O player só falou com o domínio público; estes hosts foram acessados por dentro pela VPS.</p>
        <table>
            <thead><tr><th>Hora</th><th>Hop</th><th>De</th><th>Para</th><th>Host (DB do XUI)</th><th>Host final</th><th>Modo</th><th>Status</th><th>Resultado</th></tr></thead>
            <tbody>
            <?php foreach ($d['direct_hops'] as $hp): ?>
                <tr>
                    <td class="muted"><?php echo h(substr((string) $hp['ts'], 0, 19)); ?></td>
                    <td><?php echo (int) $hp['hop_no']; ?></td>
                    <td class="muted"><?php echo h($hp['from_host']); ?></td>
                    <td class="muted"><?php echo h($hp['to_host']); ?></td>
                    <td class="muted"><?php echo h(($hp['host_from_db'] ?? '') ?: '-'); ?></td>
                    <td><?php echo h($hp['final_host'] ?: '-'); ?></td>
                    <td><span class="tag info"><?php echo h(($hp['direct_mode'] ?? '') ?: 'runtime'); ?></span></td>
                    <td><?php echo (int) $hp['status']; ?></td>
                    <td><span class="tag <?php echo $hp['outcome'] === 'followed' ? 'ok' : 'bad'; ?>"><?php echo h($hp['outcome']); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <section class="card full">
        <h2>Sessões ativas no XUI</h2>
        <table>
            <thead><tr><th>Stream</th><th>Container</th><th>IP</th><th>Player</th><th>Início</th><th>Último HLS</th></tr></thead>
            <tbody>
            <?php if (!$d['sessions']): ?><tr><td colspan="6" class="muted">nenhuma sessão ativa</td></tr><?php endif; ?>
            <?php foreach ($d['sessions'] as $s): ?>
                <tr>
                    <td><?php echo h($s['stream_display_name'] ?: $s['stream_id']); ?></td>
                    <td><?php echo h($s['container']); ?></td>
                    <td><?php echo h($s['user_ip']); ?></td>
                    <td class="muted"><?php echo h(substr((string) $s['user_agent'], 0, 50)); ?></td>
                    <td class="muted"><?php echo h($s['date_start']); ?></td>
                    <td class="muted"><?php echo h($s['hls_last_read']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Domínios públicos usados</h2>
        <table><tbody>
        <?php foreach ($d['hosts'] as $r): ?>
            <tr><td><?php echo h($r['k']); ?></td><td><?php echo (int) $r['c']; ?></td><td class="muted"><?php echo h($r['last']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>

    <section class="card">
        <h2>IPs usados</h2>
        <table><tbody>
        <?php foreach ($d['ips'] as $r): ?>
            <tr><td><?php echo h($r['k']); ?></td><td><?php echo (int) $r['c']; ?></td><td class="muted"><?php echo h($r['last']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>

    <section class="card">
        <h2>Players</h2>
        <table><tbody>
        <?php foreach ($d['players'] as $r): ?>
            <tr><td class="muted"><?php echo h(substr((string) $r['k'], 0, 60)); ?></td><td><?php echo (int) $r['c']; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>

    <?php if ($d['divergences']): ?>
    <section class="card full">
        <h2>Divergências detectadas</h2>
        <table>
            <thead><tr><th>Hora</th><th>request_id</th><th>Rota</th><th>Tipo</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($d['divergences'] as $e): ?>
                <tr>
                    <td class="muted"><?php echo h($e['ts']); ?></td>
                    <td class="muted"><?php echo h($e['request_id']); ?></td>
                    <td class="muted"><?php echo h($e['path']); ?></td>
                    <td><span class="tag bad"><?php echo h($e['inconsistency']); ?></span></td>
                    <td><?php echo (int) $e['status']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <section class="card full">
        <h2>Últimos requests do proxy</h2>
        <table>
            <thead><tr><th>Hora</th><th>Host</th><th>IP</th><th>Rota</th><th>Tipo</th><th>Status</th><th>Bytes</th><th>ms</th><th>Match</th></tr></thead>
            <tbody>
            <?php foreach ($d['events'] as $e): ?>
                <tr>
                    <td class="muted"><?php echo h(substr((string) $e['ts'], 0, 19)); ?></td>
                    <td><?php echo h($e['public_host']); ?></td>
                    <td><?php echo h($e['client_ip']); ?></td>
                    <td class="muted"><?php echo h($e['path']); ?></td>
                    <td><?php echo h($e['route_kind']); ?></td>
                    <td><?php echo (int) $e['status']; ?></td>
                    <td><?php echo bytes_fmt($e['bytes']); ?></td>
                    <td><?php echo (int) $e['duration_ms']; ?></td>
                    <td><?php echo h($e['match_confidence']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
</main>
</body>
</html>

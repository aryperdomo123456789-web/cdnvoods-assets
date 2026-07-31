<?php

/**
 * PAINEL DE AUDITORIA — trilha única (fase 3/6 do plano de rastreabilidade).
 *
 * Uma tela responde tudo que o operador pergunta na hora do problema:
 *  "quem é, de onde veio, por qual DNS público entrou, por qual músculo passou,
 *   foi direct source, deu erro, deu swap de credencial, quantos bytes."
 *
 * Sem cruzar 5 tabelas na mão: a linha do tempo já vem consolidada.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
session_write_close();

function ah($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function abytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $b;
    while ($v >= 1024 && $i < count($u) - 1) { $v /= 1024; $i++; }
    return round($v, $i === 0 ? 0 : 1) . $u[$i];
}

$filters = [
    'username' => trim((string) ($_GET['username'] ?? '')),
    'ip' => trim((string) ($_GET['ip'] ?? '')),
    'host' => trim((string) ($_GET['host'] ?? '')),
    'player' => trim((string) ($_GET['player'] ?? '')),
    'request_id' => trim((string) ($_GET['request_id'] ?? '')),
    'kind' => trim((string) ($_GET['kind'] ?? '')),
    'direct' => !empty($_GET['direct']),
    'only_problems' => !empty($_GET['only_problems']),
    'since_minutes' => max(5, min(1440, (int) ($_GET['since'] ?? 60))),
];
$liveFilters = $filters;
$liveFilters['since_minutes'] = min($liveFilters['since_minutes'], 5);
$liveRows = AuditTimeline::search($liveFilters, 120);
$rows = AuditTimeline::search($filters, 300);
$summary = AuditTimeline::summary();
$db = Database::healthSnapshot();
$qs = http_build_query(array_filter($filters, static fn ($v) => $v !== '' && $v !== false));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — Auditoria ao vivo</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        table{width:100%;font-size:12.5px}td,th{padding:5px 6px;vertical-align:top}
        .muted{opacity:.65}.tag{padding:1px 6px;border-radius:4px;font-size:11px}
        .ok{background:#064e3b}.warn{background:#78350f}.bad{background:#7f1d1d}.info{background:#1e3a8a}
        .kpis{display:flex;flex-wrap:wrap;gap:10px}
        .kpi{background:#111827;border-radius:8px;padding:8px 12px;min-width:120px}
        .kpi b{display:block;font-size:18px}
        form.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:end}
        form.filters label{font-size:11px;opacity:.7;display:block}
        code{font-size:11.5px}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>Auditoria — trilha única</span></div>
    <nav>
        <a href="/restream.php">Ao vivo</a>
        <a href="/auditoria.php">Auditoria</a>
        <a href="/jobs.php">Jobs</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/lb.php">LB</a>
        <a href="/avancado.php">Avançado</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <section class="card full">
        <h2>Últimos 5 minutos</h2>
        <div class="kpis">
            <div class="kpi"><span class="muted">sessões</span><b><?php echo (int) $summary['sessions']; ?></b></div>
            <div class="kpi"><span class="muted">usuários</span><b><?php echo (int) $summary['users']; ?></b></div>
            <div class="kpi"><span class="muted">tráfego</span><b><?php echo abytes((int) $summary['bytes']); ?></b></div>
            <div class="kpi"><span class="muted">erros</span><b><?php echo (int) $summary['errors']; ?></b></div>
            <div class="kpi"><span class="muted">direct source</span><b><?php echo (int) $summary['direct']; ?></b></div>
            <div class="kpi"><span class="muted">via LB</span><b><?php echo (int) $summary['via_lb']; ?></b></div>
            <div class="kpi"><span class="muted">divergentes</span><b><?php echo (int) $summary['inconsistent']; ?></b></div>
            <div class="kpi"><span class="muted">WAL</span><b><?php echo abytes((int) $db['wal_bytes']); ?></b></div>
            <div class="kpi"><span class="muted">retries de lock</span><b><?php echo (int) $db['lock_retries']; ?></b></div>
        </div>
        <p class="muted">Trilha total guardada: <?php echo (int) $summary['total_rows']; ?> sessão(ões).
           Banco: <?php echo ah($db['journal_mode']); ?>, espera de lock <?php echo (int) $db['busy_timeout_ms']; ?>ms,
           arquivo <?php echo abytes((int) $db['db_bytes']); ?>.</p>
    </section>

    <section class="card full">
        <h2>Buscar na trilha</h2>
        <form class="filters" method="get" action="/auditoria.php">
            <div><label>usuário</label><input name="username" value="<?php echo ah($filters['username']); ?>" placeholder="login do XUI"></div>
            <div><label>IP do cliente</label><input name="ip" value="<?php echo ah($filters['ip']); ?>"></div>
            <div><label>DNS público</label><input name="host" value="<?php echo ah($filters['host']); ?>"></div>
            <div><label>player</label><input name="player" value="<?php echo ah($filters['player']); ?>"></div>
            <div><label>request_id</label><input name="request_id" value="<?php echo ah($filters['request_id']); ?>"></div>
            <div><label>tipo</label>
                <select name="kind">
                    <option value="">todos</option>
                    <?php foreach (['live', 'movie', 'series', 'hls', 'playlist', 'api', 'other'] as $k): ?>
                        <option value="<?php echo $k; ?>" <?php echo $filters['kind'] === $k ? 'selected' : ''; ?>><?php echo $k; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>janela (min)</label><input name="since" type="number" min="5" max="1440" value="<?php echo (int) $filters['since_minutes']; ?>"></div>
            <div><label><input type="checkbox" name="direct" value="1" <?php echo $filters['direct'] ? 'checked' : ''; ?>> só direct source</label></div>
            <div><label><input type="checkbox" name="only_problems" value="1" <?php echo $filters['only_problems'] ? 'checked' : ''; ?>> só problemas</label></div>
            <div><button type="submit">Filtrar</button></div>
            <div><a href="/auditoria.php">limpar</a></div>
        </form>
    </section>

    <section class="card full">
        <h2>Ao Vivo Agora (<?php echo count($liveRows); ?> sessão(ões))</h2>
        <table>
            <thead><tr>
                <th>Última atividade</th><th>Usuário</th><th>IP</th><th>DNS público</th><th>Tipo</th>
                <th>Stream</th><th>Destino</th><th>Direct</th><th>Reqs</th><th>Bytes</th><th>Status</th><th>Problema</th>
            </tr></thead>
            <tbody>
            <?php if (!$liveRows): ?>
                <tr><td colspan="12" class="muted">nenhuma sessão viva na janela atual</td></tr>
            <?php endif; ?>
            <?php foreach ($liveRows as $r):
                $problem = (string) $r['inconsistency'];
                $cls = $problem !== '' ? 'bad' : ((int) $r['errors'] > 0 ? 'warn' : 'ok');
                $dest = (string) $r['lb_target'] === 'lb'
                    ? ('LB ' . ah((string) ($r['lb_label'] ?: $r['lb_ip'] ?: $r['lb_id'])))
                    : 'cérebro';
            ?>
                <tr>
                    <td class="muted"><?php echo ah(date('d/m H:i:s', (int) $r['last_epoch'])); ?></td>
                    <td><a href="/restream-user.php?username=<?php echo urlencode((string) $r['username']); ?>"><?php echo ah($r['username'] ?: '-'); ?></a></td>
                    <td class="muted"><?php echo ah($r['client_ip']); ?></td>
                    <td class="muted"><?php echo ah($r['public_host']); ?></td>
                    <td><span class="tag info"><?php echo ah($r['session_kind']); ?></span></td>
                    <td><?php echo (int) $r['stream_id'] ?: '-'; ?></td>
                    <td><?php echo $dest; ?></td>
                    <td class="muted"><?php echo (int) $r['direct_source'] === 1 ? ah($r['direct_host'] ?: 'sim') : '-'; ?></td>
                    <td><?php echo (int) $r['requests']; ?></td>
                    <td><?php echo abytes((int) $r['bytes']); ?></td>
                    <td><span class="tag <?php echo $cls; ?>"><?php echo (int) $r['last_status']; ?></span></td>
                    <td class="muted"><?php echo ah($problem ?: ($r['last_reason'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Histórico / Linha do tempo (<?php echo count($rows); ?> sessão(ões))</h2>
        <table>
            <thead><tr>
                <th>Última atividade</th><th>Usuário</th><th>IP</th><th>DNS público</th><th>Tipo</th>
                <th>Stream</th><th>Caminho</th><th>Destino</th><th>Direct</th><th>Reqs</th>
                <th>Bytes</th><th>Erros</th><th>Status</th><th>Problema</th><th>request_id</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="15" class="muted">nada na janela escolhida</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $problem = (string) $r['inconsistency'];
                $cls = $problem !== '' ? 'bad' : ((int) $r['errors'] > 0 ? 'warn' : 'ok');
                $dest = (string) $r['lb_target'] === 'lb'
                    ? ('LB ' . ah((string) ($r['lb_label'] ?: $r['lb_ip'] ?: $r['lb_id'])))
                    : 'cérebro';
            ?>
                <tr>
                    <td class="muted"><?php echo ah(date('d/m H:i:s', (int) $r['last_epoch'])); ?></td>
                    <td><a href="/restream-user.php?username=<?php echo urlencode((string) $r['username']); ?>"><?php echo ah($r['username'] ?: '-'); ?></a></td>
                    <td class="muted"><?php echo ah($r['client_ip']); ?></td>
                    <td class="muted"><?php echo ah($r['public_host']); ?></td>
                    <td><span class="tag info"><?php echo ah($r['session_kind']); ?></span></td>
                    <td><?php echo (int) $r['stream_id'] ?: '-'; ?></td>
                    <td class="muted"><code><?php echo ah($r['last_path']); ?></code></td>
                    <td><?php echo $dest; ?><?php if ((string) $r['lb_reason'] !== ''): ?> <span class="muted">(<?php echo ah($r['lb_reason']); ?>)</span><?php endif; ?></td>
                    <td class="muted"><?php echo (int) $r['direct_source'] === 1 ? ah($r['direct_host'] ?: 'sim') : '-'; ?></td>
                    <td><?php echo (int) $r['requests']; ?></td>
                    <td><?php echo abytes((int) $r['bytes']); ?></td>
                    <td><?php echo (int) $r['errors']; ?></td>
                    <td><span class="tag <?php echo $cls; ?>"><?php echo (int) $r['last_status']; ?></span></td>
                    <td class="muted"><?php echo ah($problem ?: ($r['last_reason'] ?? '')); ?></td>
                    <td class="muted"><code><?php echo ah($r['last_request_id']); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">A mesma consulta em JSON (para script/automação):
           <code>/restream-data.php?view=timeline&amp;<?php echo ah($qs); ?></code></p>
    </section>
</main>
</body>
</html>

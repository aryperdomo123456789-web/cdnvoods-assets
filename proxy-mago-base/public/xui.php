<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$cfg = XuiSyncConfig::get();
$ping = XuiReadOnly::available() && XuiAdmin::configured() ? XuiAdmin::ping() : ['ok' => false, 'ms' => 0, 'error' => 'configuração incompleta'];
$summary = ['lines_total' => 0, 'lines_enabled' => 0, 'bouquets_total' => 0];
$bouquets = [];
$lines = [];
$loadError = '';

if (XuiReadOnly::available() && XuiAdmin::configured()) {
    try {
        $summary = XuiAdmin::summary();
        $bouquets = XuiAdmin::bouquets();
        $lines = XuiAdmin::recentLines(120);
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}

function xh($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — Gerência XUI</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
        .kpi{background:#111827;border:1px solid #1f2937;border-radius:10px;padding:12px 14px}
        .kpi b{display:block;font-size:28px;line-height:1.1;margin-top:4px}
        .cols{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
        .muted{opacity:.72}
        .status-pill{padding:2px 8px;border-radius:999px;font-size:12px;display:inline-block}
        .ok{background:#064e3b}.bad{background:#7f1d1d}.info{background:#1e3a8a}
        .bouquets{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;max-height:260px;overflow:auto;padding:10px;border:1px solid #1f2937;border-radius:10px;background:#0b1220}
        .bouquets label{display:flex;gap:8px;align-items:flex-start}
        .toolbar{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0}
        .toolbar input,.toolbar select{padding:8px 10px;min-width:180px}
        table{width:100%;font-size:13px}
        td,th{padding:7px 8px;vertical-align:top}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        @media (max-width: 1180px){.cols{grid-template-columns:1fr}}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>Gerência XUI leve</span></div>
    <nav>
        <a href="/restream.php">Ao vivo</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/jobs.php">Jobs</a>
        <a href="/lb.php">LB</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <?php if ($flash): ?><section class="card full"><div class="alert success"><?php echo xh($flash); ?></div></section><?php endif; ?>
    <?php if ($loadError !== ''): ?><section class="card full"><div class="alert"><?php echo xh($loadError); ?></div></section><?php endif; ?>

    <section class="card full">
        <h1 style="margin-top:0">Gerência XUI</h1>
        <p class="muted">Aqui fica a conexão do XUI e a operação leve do painel: criar usuário, escolher bouquets, conexões, vencimento e acompanhar rapidamente o espelho do XUI sem abrir o painel original.</p>
        <div class="kpis">
            <div class="kpi"><span class="muted">Linhas no XUI</span><b><?php echo (int) $summary['lines_total']; ?></b></div>
            <div class="kpi"><span class="muted">Linhas ativas</span><b><?php echo (int) $summary['lines_enabled']; ?></b></div>
            <div class="kpi"><span class="muted">Bouquets</span><b><?php echo (int) $summary['bouquets_total']; ?></b></div>
            <div class="kpi"><span class="muted">Banco XUI</span><b><?php echo $ping['ok'] ? 'OK' : 'OFF'; ?></b></div>
        </div>
        <p class="muted" style="margin-top:10px">
            Status:
            <span class="status-pill <?php echo $ping['ok'] ? 'ok' : 'bad'; ?>"><?php echo $ping['ok'] ? 'conectado em ' . (int) $ping['ms'] . 'ms' : 'falha'; ?></span>
            <?php if (!$ping['ok'] && $ping['error'] !== ''): ?> · <?php echo xh($ping['error']); ?><?php endif; ?>
        </p>
    </section>

    <section class="card full">
        <h2>1. Conexão do XUI</h2>
        <form method="post" action="/save-xui-sync.php">
            <input type="hidden" name="csrf_token" value="<?php echo xh(csrf_token()); ?>">
            <div class="cols">
                <div>
                    <label>IP/host do banco do XUI</label>
                    <input name="host" required value="<?php echo xh($cfg['host'] ?? ''); ?>" placeholder="38.190.176.170">
                    <label>Porta do banco</label>
                    <input name="port" type="number" min="1" max="65535" value="<?php echo (int) ($cfg['port'] ?? 3306); ?>">
                    <label>Nome do banco</label>
                    <input name="database_name" required value="<?php echo xh($cfg['database_name'] ?? 'xtream_iptvpro'); ?>">
                    <label>Usuário do banco</label>
                    <input name="username" required value="<?php echo xh($cfg['username'] ?? ''); ?>">
                    <label>Senha do banco</label>
                    <input name="password" type="password" placeholder="deixe em branco para manter">
                </div>
                <div>
                    <label>API URL do XUI</label>
                    <input name="api_url" value="<?php echo xh($cfg['api_url'] ?? ''); ?>" placeholder="http://IP/CODIGO">
                    <label>API token</label>
                    <input name="api_token" value="<?php echo xh($cfg['api_token'] ?? ''); ?>" placeholder="token/chave">
                    <label><input type="checkbox" name="sync_enabled" value="1" <?php echo !empty($cfg['sync_enabled']) ? 'checked' : ''; ?>> habilitar sync read-only</label>
                    <label><input type="checkbox" name="use_tls" value="1" <?php echo !empty($cfg['use_tls']) ? 'checked' : ''; ?>> usar TLS na conexão MySQL</label>
                    <label>Intervalo geral de sync (s)</label>
                    <input name="sync_interval_seconds" type="number" min="2" value="<?php echo (int) ($cfg['sync_interval_seconds'] ?? 5); ?>">
                    <label>Sync de usuários (s)</label>
                    <input name="users_interval_seconds" type="number" min="15" value="<?php echo (int) ($cfg['users_interval_seconds'] ?? 60); ?>">
                    <label>Sync de streams (s)</label>
                    <input name="streams_interval_seconds" type="number" min="30" value="<?php echo (int) ($cfg['streams_interval_seconds'] ?? 300); ?>">
                </div>
            </div>
            <div class="cols">
                <div>
                    <label>Timeout de conexão (s)</label>
                    <input name="connect_timeout_seconds" type="number" min="1" value="<?php echo (int) ($cfg['connect_timeout_seconds'] ?? 3); ?>">
                </div>
                <div>
                    <label>Timeout de leitura (s)</label>
                    <input name="read_timeout_seconds" type="number" min="1" value="<?php echo (int) ($cfg['read_timeout_seconds'] ?? 5); ?>">
                </div>
            </div>
            <button type="submit">Salvar conexão do XUI</button>
        </form>
    </section>

    <section class="card full">
        <h2>2. Criar usuário no XUI</h2>
        <form method="post" action="/save-xui-user.php">
            <input type="hidden" name="csrf_token" value="<?php echo xh(csrf_token()); ?>">
            <div class="cols">
                <div>
                    <label>Username</label>
                    <input name="username" required autocomplete="off">
                    <label>Senha</label>
                    <input name="password" required autocomplete="off">
                    <label>Conexões</label>
                    <input name="max_connections" type="number" min="1" value="1">
                    <label>Expiração</label>
                    <input name="exp_date" type="date">
                    <label>Member ID</label>
                    <input name="member_id" type="number" min="0" value="0">
                    <label>Force Server ID</label>
                    <input name="force_server_id" type="number" min="0" value="0">
                </div>
                <div>
                    <label>IPs permitidos</label>
                    <textarea name="allowed_ips" rows="2" placeholder="opcional"></textarea>
                    <label>User-Agent permitido</label>
                    <textarea name="allowed_ua" rows="2" placeholder="opcional"></textarea>
                    <label>Notas administrativas</label>
                    <textarea name="admin_notes" rows="4" placeholder="opcional"></textarea>
                    <p class="muted">Saídas padrão marcadas: TS, HLS e MP4. Esse formato já segue o padrão real das suas linhas atuais.</p>
                </div>
            </div>
            <div class="bouquets">
                <?php foreach ($bouquets as $b): ?>
                    <label>
                        <input type="checkbox" name="bouquets[]" value="<?php echo (int) $b['id']; ?>">
                        <span>#<?php echo (int) $b['id']; ?> · <?php echo xh($b['bouquet_name']); ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if (!$bouquets): ?><span class="muted">configure a conexão para carregar bouquets reais do XUI</span><?php endif; ?>
            </div>
            <p style="margin-top:10px">
                <label><input type="checkbox" name="allowed_outputs[]" value="1" checked> TS</label>
                <label><input type="checkbox" name="allowed_outputs[]" value="2" checked> HLS</label>
                <label><input type="checkbox" name="allowed_outputs[]" value="3" checked> MP4</label>
            </p>
            <p>
                <label><input type="checkbox" name="enabled" value="1" checked> enabled</label>
                <label><input type="checkbox" name="admin_enabled" value="1" checked> admin enabled</label>
                <label><input type="checkbox" name="is_trial" value="1"> trial</label>
                <label><input type="checkbox" name="is_restreamer" value="1" checked> restreamer</label>
            </p>
            <button type="submit" <?php echo $bouquets ? '' : 'disabled'; ?>>Criar usuário no XUI</button>
        </form>
    </section>

    <section class="card full">
        <h2>3. Linhas recentes do XUI</h2>
        <div class="toolbar">
            <input id="lineSearch" placeholder="buscar usuário">
            <select id="lineStatus">
                <option value="all">todos</option>
                <option value="enabled">ativos</option>
                <option value="disabled">desativados</option>
            </select>
        </div>
        <table>
            <thead><tr><th>ID</th><th>Usuário</th><th>Conexões</th><th>Bouquets</th><th>Expira</th><th>Último IP</th><th>Última atividade</th><th>Status</th><th></th></tr></thead>
            <tbody id="linesBody">
            <?php if (!$lines): ?><tr><td colspan="7" class="muted">nenhuma linha carregada ainda</td></tr><?php endif; ?>
            <?php foreach ($lines as $line): ?>
                <tr data-user="<?php echo xh(strtolower((string) $line['username'])); ?>" data-enabled="<?php echo ((int) $line['enabled'] === 1 && (int) $line['admin_enabled'] === 1) ? '1' : '0'; ?>">
                    <td><?php echo (int) $line['id']; ?></td>
                    <td><a href="/xui-user.php?id=<?php echo (int) $line['id']; ?>"><?php echo xh($line['username']); ?></a></td>
                    <td><?php echo (int) $line['max_connections']; ?></td>
                    <td><?php echo (int) $line['bouquet_count']; ?></td>
                    <td><?php echo xh($line['exp_date_label']); ?></td>
                    <td class="mono"><?php echo xh($line['last_ip'] ?: '-'); ?></td>
                    <td class="muted"><?php echo xh($line['last_activity_label']); ?></td>
                    <td>
                        <span class="status-pill <?php echo ((int) $line['enabled'] === 1 && (int) $line['admin_enabled'] === 1) ? 'ok' : 'bad'; ?>">
                            <?php echo ((int) $line['enabled'] === 1 && (int) $line['admin_enabled'] === 1) ? 'ativo' : 'desativado'; ?>
                        </span>
                    </td>
                    <td><a href="/xui-user.php?id=<?php echo (int) $line['id']; ?>">gerenciar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
<script>
const q = document.getElementById('lineSearch');
const st = document.getElementById('lineStatus');
const tbody = document.getElementById('linesBody');
function filterLines() {
  if (!tbody) return;
  const query = (q.value || '').trim().toLowerCase();
  const mode = st.value || 'all';
  for (const row of tbody.querySelectorAll('tr[data-user]')) {
    const user = row.getAttribute('data-user') || '';
    const enabled = row.getAttribute('data-enabled') || '0';
    const okQuery = query === '' || user.includes(query);
    const okMode = mode === 'all' || (mode === 'enabled' ? enabled === '1' : enabled === '0');
    row.style.display = okQuery && okMode ? '' : 'none';
  }
}
q?.addEventListener('input', filterLines);
st?.addEventListener('change', filterLines);
</script>
</body>
</html>

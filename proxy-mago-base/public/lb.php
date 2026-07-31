<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function lh($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$nodes = LbNode::all();
$edit = null;
if (!empty($_GET['edit'])) { $edit = LbNode::find((int) $_GET['edit']); }
$selected = (int) ($_GET['lb'] ?? ($nodes[0]['id'] ?? 0));
$totals = LbRouter::totals();
$sshReady = LbSsh::available();
$csrf = csrf_token();
$xuiUsersTotal = (int) Database::pdo()->query('SELECT COUNT(*) FROM xui_users_cache WHERE enabled = 1')->fetchColumn();
$routes = LbRouter::routes(200);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — LB (single XUI)</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        table{width:100%;font-size:13px}td,th{padding:5px 6px;vertical-align:top}
        .muted{opacity:.65}.tag{padding:1px 6px;border-radius:4px;font-size:11px}
        .ok{background:#064e3b}.warn{background:#78350f}.bad{background:#7f1d1d}
        .kpi{display:flex;gap:18px;flex-wrap:wrap}
        .kpi div{background:#111827;padding:10px 14px;border-radius:8px;min-width:110px}
        .kpi b{display:block;font-size:20px}
        #lblog{background:#0b1220;color:#d1fae5;font:12px/1.5 ui-monospace,monospace;
               padding:10px;border-radius:8px;height:280px;overflow:auto;white-space:pre-wrap}
        .row{display:flex;gap:10px;flex-wrap:wrap}
        .row label{flex:1 1 160px}
        .inline{display:inline}
        .compact-actions form,.compact-actions a{display:inline-block;margin:2px 4px 2px 0}
        .compact-actions button{min-width:110px}
        .simple-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
        .mini-card{background:#111827;padding:14px;border-radius:10px}
        .mini-card h3{margin:0 0 8px;font-size:16px}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>LB simples — instalar, monitorar e mandar usuários</span></div>
    <nav>
        <a href="/auditoria.php">Auditoria</a>
        <a href="/restream.php">Ao vivo</a>
        <a href="/xui.php">XUI</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/jobs.php">Jobs</a>
        <a href="/avancado.php">Avançado</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <section class="card full">
        <div id="lbfresh" class="alert">Verificando frescor da telemetria dos LBs…</div>
    </section>
    <?php if ($flash): ?><section class="card full"><div class="alert success"><?php echo lh($flash); ?></div></section><?php endif; ?>
    <?php if (!$sshReady): ?>
        <section class="card full"><div class="alert"><?php echo lh(LbSsh::missingHint()); ?></div></section>
    <?php endif; ?>

    <section class="card full">
        <h2>Painel simples do LB</h2>
        <div class="kpi">
            <div><span class="muted">LBs</span><b><?php echo (int) $totals['nodes']; ?></b></div>
            <div><span class="muted">Instalados</span><b><?php echo (int) $totals['installed']; ?></b></div>
            <div><span class="muted">Saudáveis</span><b><?php echo (int) $totals['healthy']; ?></b></div>
            <div><span class="muted">TX agregado</span><b><?php echo lh($totals['tx_mbps']); ?> Mbps</b></div>
            <div><span class="muted">Usuários no LB</span><b><?php echo (int) $totals['routes_forced']; ?></b></div>
            <div><span class="muted">Usuários XUI</span><b><?php echo $xuiUsersTotal; ?></b></div>
        </div>
        <p class="muted">Fluxo que importa aqui: cadastrar o LB, instalar, acompanhar a saúde
           e escolher se o tráfego fica no <code>main</code> ou vai para o LB.</p>
    </section>

    <section class="card full">
        <h2>1. Cadastrar LB</h2>
        <p class="muted">Preencha só o necessário para instalar.</p>
        <form method="post" action="/save-lb.php">
            <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">
            <div class="row">
                <label>Nome
                    <input name="label" value="<?php echo lh($edit['label'] ?? ''); ?>" placeholder="LB-01">
                </label>
                <label>IP do LB
                    <input name="public_ip" required value="<?php echo lh($edit['public_ip'] ?? ''); ?>" placeholder="143.14.168.78">
                </label>
                <label>Porta SSH
                    <input name="ssh_port" type="number" value="<?php echo (int) ($edit['ssh_port'] ?? 22); ?>">
                </label>
                <label>Usuário root
                    <input name="ssh_user" value="<?php echo lh($edit['ssh_user'] ?? 'root'); ?>" placeholder="root">
                </label>
                <label>Senha root
                    <input name="ssh_password" type="password" placeholder="<?php echo $edit ? 'deixe em branco para manter' : ''; ?>">
                </label>
            </div>
            <input type="hidden" name="ssh_host" value="<?php echo lh($edit['ssh_host'] ?? ''); ?>">
            <input type="hidden" name="declared_bandwidth_mbps" value="<?php echo (int) ($edit['declared_bandwidth_mbps'] ?? 10000); ?>">
            <input type="hidden" name="weight" value="<?php echo (int) ($edit['weight'] ?? 100); ?>">
            <input type="hidden" name="max_users_soft" value="<?php echo (int) ($edit['max_users_soft'] ?? 0); ?>">
            <input type="hidden" name="max_users_hard" value="<?php echo (int) ($edit['max_users_hard'] ?? 0); ?>">
            <input type="hidden" name="max_mbps_soft" value="<?php echo (int) ($edit['max_mbps_soft'] ?? 0); ?>">
            <input type="hidden" name="max_mbps_hard" value="<?php echo (int) ($edit['max_mbps_hard'] ?? 0); ?>">
            <p>
                <label><input type="checkbox" name="enabled" <?php echo (!$edit || (int) $edit['enabled'] === 1) ? 'checked' : ''; ?>> ativo</label>
                <label><input type="checkbox" name="auto_install" <?php echo (!$edit || (int) ($edit['auto_install'] ?? 1) === 1) ? 'checked' : ''; ?>> instalar automaticamente ao salvar</label>
            </p>
            <button type="submit"><?php echo $edit ? 'Salvar alterações' : 'Salvar LB'; ?></button>
            <?php if ($edit): ?> <a href="/lb.php">cancelar edição</a><?php endif; ?>
        </form>
    </section>

    <section class="card full">
        <h2>2. LBs cadastrados</h2>
        <table>
            <thead><tr>
                <th>LB</th><th>Instalação</th><th>Saúde</th><th>Telemetria</th><th>Ações</th>
            </tr></thead>
            <tbody id="lbrows">
            <?php if (!$nodes): ?>
                <tr><td colspan="5" class="muted">Nenhum LB cadastrado ainda.</td></tr>
            <?php endif; ?>
            <?php foreach ($nodes as $n):
                $m = LbTelemetry::latest((int) $n['id']);
                $health = (string) $n['health_status'];
                $hc = $health === 'ok' ? 'ok' : ($health === 'down' ? 'bad' : 'warn');
                $inst = (string) $n['install_status'];
                $ic = $inst === 'installed' ? 'ok' : ($inst === 'error' ? 'bad' : 'warn');
            ?>
                <tr>
                    <td><strong><?php echo lh($n['label']); ?></strong>
                        <?php if ((int) $n['drain_mode'] === 1): ?><span class="tag warn">drenando</span><?php endif; ?>
                        <?php if ((int) $n['enabled'] !== 1): ?><span class="tag bad">off</span><?php endif; ?>
                        <div class="muted"><?php echo lh($n['public_ip']); ?> · <?php echo lh($n['ssh_user'] . ':' . $n['ssh_port']); ?></div>
                        <div class="muted"><?php echo lh(trim($n['os_name'] . ' ' . $n['os_version']) ?: 'SO pendente'); ?></div>
                    </td>
                    <td><span class="tag <?php echo $ic; ?>"><?php echo lh($inst); ?></span>
                        <div class="muted"><?php echo lh($n['install_step']); ?></div></td>
                    <td><span class="tag <?php echo $hc; ?>"><?php echo lh($health ?: 'unknown'); ?></span>
                        <div class="muted"><?php echo lh($n['health_message'] ?? ''); ?></div></td>
                    <td>
                        <div>CPU: <strong><?php echo lh($m['cpu_pct'] ?? 0); ?>%</strong></div>
                        <div>RAM livre: <strong><?php echo lh($m['ram_free_mb'] ?? 0); ?> MB</strong></div>
                        <div>Disco livre: <strong><?php echo lh($m['disk_free_gb'] ?? 0); ?> GB</strong></div>
                        <div>RX/TX: <strong><?php echo lh($m['rx_mbps'] ?? 0); ?>/<?php echo lh($m['tx_mbps'] ?? 0); ?> Mbps</strong></div>
                        <div>Sessões: <strong><?php echo lh($m['sessions_active'] ?? 0); ?></strong></div>
                    </td>
                    <td class="compact-actions">
                        <form method="post" action="/lb-action.php" class="inline">
                            <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
                            <input type="hidden" name="lb_id" value="<?php echo (int) $n['id']; ?>">
                            <button name="action" value="test">Testar</button>
                            <button name="action" value="install" onclick="return confirm('Instalar o pacote de LB nesta VPS?')">Instalar</button>
                            <button name="action" value="sync">Sincronizar</button>
                            <button name="action" value="<?php echo (int) $n['enabled'] === 1 ? 'disable' : 'enable'; ?>">
                                <?php echo (int) $n['enabled'] === 1 ? 'Desativar' : 'Ativar'; ?>
                            </button>
                            <button name="action" value="delete" onclick="return confirm('Remover este LB do inventário?')">Remover</button>
                        </form>
                        <a href="/lb.php?lb=<?php echo (int) $n['id']; ?>">ver log</a> ·
                        <a href="/lb.php?edit=<?php echo (int) $n['id']; ?>">editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>3. Log da instalação<?php echo $selected ? ' — LB #' . (int) $selected : ''; ?></h2>
        <p class="muted">Cada etapa (validate → handshake → detect → support → bootstrap → package → configure → smoke)
           grava início, fim, duração e resultado. Segredos são mascarados.</p>
        <div id="lblog">selecione um LB…</div>
    </section>

    <section class="card full">
        <h2>4. Mandar usuários para o LB</h2>
        <form method="post" action="/save-lb-route.php">
            <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
            <div class="row">
                <label>Escopo
                    <select name="scope">
                        <option value="selected">Somente os usuários digitados</option>
                        <option value="all">Todos os usuários ativos do XUI</option>
                    </select></label>
                <label>Usuário(s)
                    <textarea name="usernames" rows="3" placeholder="digite um usuário por linha ou separados por vírgula"></textarea></label>
                <label>Ação
                    <select name="mode">
                        <option value="forced">Mandar para este LB</option>
                        <option value="main_only">Voltar para o main</option>
                        <option value="auto">Automático</option>
                    </select></label>
                <label>LB de destino
                    <select name="lb_id">
                        <option value="0">—</option>
                        <?php foreach ($nodes as $n): ?>
                            <option value="<?php echo (int) $n['id']; ?>"><?php echo lh($n['label'] . ' (' . $n['public_ip'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select></label>
            </div>
            <button type="submit">Aplicar</button>
        </form>

        <table>
            <thead><tr><th>Usuário</th><th>Status</th><th>LB</th><th>Atualizado</th></tr></thead>
            <tbody>
            <?php if (!$routes): ?><tr><td colspan="4" class="muted">Nenhuma rota salva. Se nada for configurado, os usuários continuam no main.</td></tr><?php endif; ?>
            <?php foreach ($routes as $r): ?>
                <tr>
                    <td><?php echo lh($r['username']); ?></td>
                    <td><span class="tag <?php echo $r['mode'] === 'main_only' ? 'warn' : 'ok'; ?>"><?php echo lh($r['mode'] === 'forced' ? 'lb' : ($r['mode'] === 'main_only' ? 'main' : $r['mode'])); ?></span></td>
                    <td><?php echo lh($r['lb_label'] ?? '—'); ?> <span class="muted"><?php echo lh($r['lb_ip'] ?? ''); ?></span></td>
                    <td class="muted"><?php echo lh($r['updated_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>5. O que esse painel faz</h2>
        <div class="simple-grid">
            <div class="mini-card">
                <h3>Instalação</h3>
                <div class="muted">Cadastra por IP, porta, usuário root e senha root.</div>
            </div>
            <div class="mini-card">
                <h3>Telemetria</h3>
                <div class="muted">Mostra saúde, CPU, RAM, disco, tráfego e sessões do servidor.</div>
            </div>
            <div class="mini-card">
                <h3>Roteamento</h3>
                <div class="muted">Você pode mandar um usuário específico ou todos os usuários do XUI para um LB.</div>
            </div>
        </div>
    </section>
</main>

<script>
const LB_ID = <?php echo (int) $selected; ?>;
let lbBusy = true;
async function tick() {
    if (!LB_ID) return;
    try {
        const r = await fetch('/lb-data.php?view=log&lb_id=' + LB_ID, {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        if (!j.ok) return;
        lbBusy = ['queued', 'running', 'installing'].includes(String(j.install_status || ''));
        const box = document.getElementById('lblog');
        const head = '# status=' + j.install_status + ' step=' + j.install_step + '\n';
        box.textContent = head + (j.items || []).map(i =>
            '[' + (i.status || '') + '] ' + (i.step || '') + ' (' + (i.duration_ms || 0) + 'ms) ' + (i.message || '')
        ).join('\n');
        box.scrollTop = box.scrollHeight;
    } catch (e) { /* silencioso: painel não pode quebrar por polling */ }
}
// Log ao vivo só enquanto há instalação em andamento; depois cai para 15s e
// para de vez com a aba em segundo plano.
async function lbLoop() {
    if (!document.hidden) { await tick(); }
    setTimeout(lbLoop, lbBusy ? 3000 : 15000);
}
lbLoop();
</script>
</body>
</html>

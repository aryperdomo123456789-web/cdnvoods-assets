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
$keyring = LbKeyring::info();
$csrf = csrf_token();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — LB (músculos)</title>
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
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>LB — cérebro decide, músculo entrega</span></div>
    <nav>
        <a href="/auditoria.php">Auditoria</a>
        <a href="/restream.php">Ao vivo</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/jobs.php">Jobs</a>
        <a href="/avancado.php">Avançado</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <?php if ($flash): ?><section class="card full"><div class="alert success"><?php echo lh($flash); ?></div></section><?php endif; ?>
    <?php if (!$sshReady): ?>
        <section class="card full"><div class="alert"><?php echo lh(LbSsh::missingHint()); ?></div></section>
    <?php endif; ?>

    <section class="card full">
        <h2>Visão geral</h2>
        <div class="kpi">
            <div><span class="muted">LBs</span><b><?php echo (int) $totals['nodes']; ?></b></div>
            <div><span class="muted">Instalados</span><b><?php echo (int) $totals['installed']; ?></b></div>
            <div><span class="muted">Saudáveis</span><b><?php echo (int) $totals['healthy']; ?></b></div>
            <div><span class="muted">TX agregado</span><b><?php echo lh($totals['tx_mbps']); ?> Mbps</b></div>
            <div><span class="muted">Rotas fixas</span><b><?php echo (int) $totals['routes_forced']; ?></b></div>
            <div><span class="muted">Rotas auto</span><b><?php echo (int) $totals['routes_auto']; ?></b></div>
        </div>
        <p class="muted">O cérebro (<code>45.140.192.237</code>) mantém painel, banco e rastreabilidade.
           Os músculos só recebem o pacote mínimo de proxy e devolvem telemetria.</p>
    </section>

    <section class="card full">
        <h2>1. Inventário de LBs</h2>
        <table>
            <thead><tr>
                <th>Nome</th><th>IP</th><th>Auth</th><th>SO</th><th>CPU/RAM</th><th>Perfil</th>
                <th>Banda decl./medida</th><th>Instalação</th><th>Saúde</th><th>Score</th><th>Ações</th>
            </tr></thead>
            <tbody id="lbrows">
            <?php if (!$nodes): ?>
                <tr><td colspan="11" class="muted">Nenhum LB cadastrado. Use o formulário abaixo.</td></tr>
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
                    </td>
                    <td><?php echo lh($n['public_ip']); ?><div class="muted"><?php echo lh($n['ssh_user'] . ':' . $n['ssh_port']); ?></div></td>
                    <td>
                        <?php $keyOn = (int) ($n['key_installed'] ?? 0) === 1; ?>
                        <span class="tag <?php echo $keyOn ? 'ok' : 'warn'; ?>"><?php echo $keyOn ? 'chave' : 'senha'; ?></span>
                        <div class="muted"><?php echo $keyOn
                            ? lh(substr((string) ($n['key_fingerprint'] ?? ''), 0, 22) . '…')
                            : 'onboarding por senha root'; ?></div>
                    </td>
                    <td><?php echo lh(trim($n['os_name'] . ' ' . $n['os_version'])) ?: '<span class="muted">—</span>'; ?></td>
                    <td><?php echo (int) $n['cpu_cores']; ?> vCPU / <?php echo round(((int) $n['ram_mb']) / 1024, 1); ?> GB</td>
                    <td><?php echo lh($n['profile'] ?? ''); ?></td>
                    <td><?php echo (int) $n['declared_bandwidth_mbps']; ?> / <?php echo (int) $n['measured_bandwidth_mbps']; ?> Mbps</td>
                    <td><span class="tag <?php echo $ic; ?>"><?php echo lh($inst); ?></span>
                        <div class="muted"><?php echo lh($n['install_step']); ?></div></td>
                    <td><span class="tag <?php echo $hc; ?>"><?php echo lh($health ?: 'unknown'); ?></span>
                        <div class="muted">cpu <?php echo lh($m['cpu_pct'] ?? 0); ?>% · tx <?php echo lh($m['tx_mbps'] ?? 0); ?> Mbps</div></td>
                    <td><?php echo round(LbRouter::score($n), 1); ?></td>
                    <td>
                        <form method="post" action="/lb-action.php" class="inline">
                            <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
                            <input type="hidden" name="lb_id" value="<?php echo (int) $n['id']; ?>">
                            <button name="action" value="test">Testar</button>
                            <button name="action" value="promote_key">Instalar chave</button>
                            <button name="action" value="install" onclick="return confirm('Instalar o pacote de LB nesta VPS?')">Instalar</button>
                            <button name="action" value="sync">Sincronizar</button>
                            <?php if ((int) ($n['key_installed'] ?? 0) === 1 && (string) ($n['ssh_password_enc'] ?? '') !== ''): ?>
                                <button name="action" value="forget_password" onclick="return confirm('Descartar a senha root? O acesso ficará apenas por chave.')">Descartar senha</button>
                            <?php endif; ?>
                            <button name="action" value="<?php echo (int) $n['enabled'] === 1 ? 'disable' : 'enable'; ?>">
                                <?php echo (int) $n['enabled'] === 1 ? 'Desativar' : 'Ativar'; ?>
                            </button>
                            <button name="action" value="drain" formmethod="post">Drenar</button>
                            <input type="hidden" name="value" value="<?php echo (int) $n['drain_mode'] === 1 ? '0' : '1'; ?>">
                            <button name="action" value="delete" onclick="return confirm('Remover este LB do inventário?')">Remover</button>
                        </form>
                        <a href="/lb.php?lb=<?php echo (int) $n['id']; ?>">log</a> ·
                        <a href="/lb.php?edit=<?php echo (int) $n['id']; ?>">editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>1b. Chaveiro SSH do cérebro</h2>
        <p class="muted">Um único par Ed25519 atende todos os músculos. A senha root serve só para o
           primeiro acesso: assim que a chave entra no <code>authorized_keys</code>, tudo passa a rodar por chave.
           A chave privada fica em <code>storage/ssh/lb_ed25519</code> (0600) e nunca aparece no painel nem em log.</p>
        <?php if (!$keyring['keygen']): ?>
            <div class="alert">ssh-keygen ausente. Rode: <code>apt-get install -y openssh-client</code></div>
        <?php endif; ?>
        <p>
            Status:
            <span class="tag <?php echo $keyring['exists'] ? 'ok' : 'warn'; ?>">
                <?php echo $keyring['exists'] ? 'chave pronta' : 'sem chave'; ?>
            </span>
            <span class="muted">
                <?php echo lh($keyring['fingerprint'] ?: '—'); ?>
                <?php echo $keyring['created_at'] ? ' · criada em ' . lh($keyring['created_at']) : ''; ?>
            </span>
        </p>
        <?php if ($keyring['public_key'] !== ''): ?>
            <p><textarea rows="2" readonly style="width:100%"><?php echo lh($keyring['public_key']); ?></textarea></p>
        <?php endif; ?>
        <form method="post" action="/lb-action.php" class="inline">
            <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
            <input type="hidden" name="lb_id" value="0">
            <button name="action" value="keygen"><?php echo $keyring['exists'] ? 'Verificar chaveiro' : 'Gerar chave Ed25519'; ?></button>
        </form>
        <form method="post" action="/lb-action.php" class="inline">
            <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
            <input type="hidden" name="lb_id" value="0">
            <input type="hidden" name="value" value="rotate">
            <button name="action" value="keygen" onclick="return confirm('Rotacionar a chave? Cada LB precisará receber a nova chave (botão Instalar chave).')">Rotacionar chave</button>
        </form>
    </section>

    <section class="card full">
        <h2>2. Cadastro / edição de LB</h2>
        <p class="muted">A senha root é gravada criptografada e usada apenas no onboarding, até a chave
           Ed25519 do cérebro assumir. Nenhum log guarda credencial.</p>
        <form method="post" action="/save-lb.php">
            <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">
            <div class="row">
                <label>Nome do LB<input name="label" required value="<?php echo lh($edit['label'] ?? ''); ?>" placeholder="LB-01"></label>
                <label>IP público<input name="public_ip" required value="<?php echo lh($edit['public_ip'] ?? ''); ?>" placeholder="143.14.168.78"></label>
                <label>Host SSH (opcional)<input name="ssh_host" value="<?php echo lh($edit['ssh_host'] ?? ''); ?>"></label>
                <label>Porta SSH<input name="ssh_port" type="number" value="<?php echo (int) ($edit['ssh_port'] ?? 22); ?>"></label>
                <label>Usuário SSH<input name="ssh_user" value="<?php echo lh($edit['ssh_user'] ?? 'root'); ?>"></label>
                <label>Senha root<input name="ssh_password" type="password" placeholder="<?php echo $edit ? 'manter a atual' : ''; ?>"></label>
            </div>
            <div class="row">
                <label>Banda declarada (Mbps)<input name="declared_bandwidth_mbps" type="number" value="<?php echo (int) ($edit['declared_bandwidth_mbps'] ?? 10000); ?>"></label>
                <label>Peso inicial<input name="weight" type="number" value="<?php echo (int) ($edit['weight'] ?? 100); ?>"></label>
                <label>Máx. usuários (soft)<input name="max_users_soft" type="number" value="<?php echo (int) ($edit['max_users_soft'] ?? 0); ?>"></label>
                <label>Máx. usuários (hard)<input name="max_users_hard" type="number" value="<?php echo (int) ($edit['max_users_hard'] ?? 0); ?>"></label>
                <label>Máx. Mbps (soft)<input name="max_mbps_soft" type="number" value="<?php echo (int) ($edit['max_mbps_soft'] ?? 0); ?>"></label>
                <label>Máx. Mbps (hard)<input name="max_mbps_hard" type="number" value="<?php echo (int) ($edit['max_mbps_hard'] ?? 0); ?>"></label>
            </div>
            <p>
                <label><input type="checkbox" name="enabled" <?php echo (!$edit || (int) $edit['enabled'] === 1) ? 'checked' : ''; ?>> habilitado</label>
                <label><input type="checkbox" name="drain_mode" <?php echo ($edit && (int) $edit['drain_mode'] === 1) ? 'checked' : ''; ?>> drenando</label>
                <label><input type="checkbox" name="auto_install" <?php echo (!$edit || (int) ($edit['auto_install'] ?? 1) === 1) ? 'checked' : ''; ?>>
                    instalar automaticamente ao salvar (chave + pacote + configuração)</label>
            </p>
            <button type="submit">Salvar LB</button>
            <?php if ($edit): ?> <a href="/lb.php">cancelar edição</a><?php endif; ?>
        </form>
    </section>

    <section class="card full">
        <h2>3. Log ao vivo da instalação<?php echo $selected ? ' — LB #' . (int) $selected : ''; ?></h2>
        <p class="muted">Cada etapa (validate → handshake → detect → support → bootstrap → package → configure → smoke)
           grava início, fim, duração e resultado. Segredos são mascarados.</p>
        <div id="lblog">selecione um LB…</div>
    </section>

    <section class="card full">
        <h2>4. Balanceamento por usuário do XUI</h2>
        <form method="post" action="/save-lb-route.php">
            <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
            <div class="row">
                <label>Usuários (um por linha, vírgula ou espaço)
                    <textarea name="usernames" rows="3" placeholder="P2on2325154215633"></textarea></label>
                <label>Modo
                    <select name="mode">
                        <option value="main_only">main_only — fica no cérebro</option>
                        <option value="forced">forced — sempre neste LB</option>
                        <option value="auto">auto — cérebro escolhe por score</option>
                    </select></label>
                <label>LB de destino (para forced)
                    <select name="lb_id">
                        <option value="0">—</option>
                        <?php foreach ($nodes as $n): ?>
                            <option value="<?php echo (int) $n['id']; ?>"><?php echo lh($n['label'] . ' (' . $n['public_ip'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select></label>
            </div>
            <button type="submit">Aplicar roteamento</button>
        </form>

        <table>
            <thead><tr><th>Usuário</th><th>Modo</th><th>LB</th><th>Motivo</th><th>Atualizado</th></tr></thead>
            <tbody>
            <?php $routes = LbRouter::routes(200); ?>
            <?php if (!$routes): ?><tr><td colspan="5" class="muted">Nenhuma rota — todos os usuários continuam no cérebro.</td></tr><?php endif; ?>
            <?php foreach ($routes as $r): ?>
                <tr>
                    <td><?php echo lh($r['username']); ?></td>
                    <td><span class="tag <?php echo $r['mode'] === 'main_only' ? 'warn' : 'ok'; ?>"><?php echo lh($r['mode']); ?></span></td>
                    <td><?php echo lh($r['lb_label'] ?? '—'); ?> <span class="muted"><?php echo lh($r['lb_ip'] ?? ''); ?></span></td>
                    <td class="muted"><?php echo lh($r['reason']); ?></td>
                    <td class="muted"><?php echo lh($r['updated_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>5. Rotinas do LB</h2>
        <?php foreach (['lb_probe', 'lb_rebalance', 'lb_cleanup'] as $job): ?>
            <form method="post" action="/run-job.php" class="inline">
                <input type="hidden" name="csrf_token" value="<?php echo lh($csrf); ?>">
                <input type="hidden" name="back" value="lb">
                <input type="hidden" name="job" value="<?php echo lh($job); ?>">
                <button type="submit"><?php echo lh($job); ?></button>
            </form>
        <?php endforeach; ?>
        <p class="muted">Em produção o cron do <code>bin/jobs-run.php</code> já dispara estas rotinas sozinho.</p>
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

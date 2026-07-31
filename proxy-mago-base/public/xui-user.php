<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$id = max(0, (int) ($_GET['id'] ?? 0));
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$line = null;
$bouquets = [];
$ipLock = ['allowed_ips' => '', 'notes' => '', 'ips' => []];
$loadError = '';
try {
    $line = $id > 0 ? XuiAdmin::findLine($id) : null;
    $bouquets = XuiAdmin::bouquets();
    if ($line) {
        $ipLock = UserIpLock::get((string) $line['username']);
    }
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

function xuh($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
$cdnIpLockActive = !empty($ipLock['ips']);
$cdnIpLockCount = count($ipLock['ips']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — Usuário XUI</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .cols{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
        .muted{opacity:.72}
        .bouquets{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;max-height:280px;overflow:auto;padding:10px;border:1px solid #1f2937;border-radius:10px;background:#0b1220}
        .bouquets label{display:flex;gap:8px;align-items:flex-start}
        .toolbar{display:flex;flex-wrap:wrap;gap:10px}
        .toolbar form{display:inline-block}
        .status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
        .status-box{border:1px solid #243047;border-radius:14px;padding:16px;background:#0f1728}
        .status-box.active{border-color:#14532d;background:rgba(20,83,45,.22)}
        .status-box.inactive{border-color:#3f3f46;background:rgba(39,39,42,.18)}
        .status-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}
        .status-chip.active{background:#166534;color:#ecfdf5}
        .status-chip.inactive{background:#374151;color:#e5e7eb}
        .rule-list{margin:10px 0 0;padding-left:18px;font-size:13px;opacity:.88}
        .quick-lock-form{margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.08)}
        .quick-lock-form textarea{width:100%}
        .quick-lock-form button{margin-top:10px}
        table{width:100%;font-size:13px}
        td,th{padding:7px 8px;vertical-align:top}
        @media (max-width: 1180px){.cols{grid-template-columns:1fr}}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>Gerência completa da linha XUI</span></div>
    <nav>
        <a href="/xui.php">XUI</a>
        <a href="/restream.php">Ao vivo</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <?php if ($flash): ?><section class="card full"><div class="alert success"><?php echo xuh($flash); ?></div></section><?php endif; ?>
    <?php if ($loadError !== ''): ?><section class="card full"><div class="alert"><?php echo xuh($loadError); ?></div></section><?php endif; ?>

    <?php if (!$line): ?>
        <section class="card full"><p class="muted">Linha do XUI não encontrada.</p></section>
    <?php else: ?>
        <section class="card full">
            <h1 style="margin-top:0"><?php echo xuh($line['username']); ?></h1>
            <p class="muted">
                ID <?php echo (int) $line['id']; ?> ·
                criado em <?php echo xuh($line['created_at_label']); ?> ·
                última atividade <?php echo xuh($line['last_activity_label']); ?> ·
                último IP <?php echo xuh($line['last_ip'] ?: '-'); ?>
            </p>
            <div class="toolbar">
                <form method="post" action="/save-xui-line.php">
                    <input type="hidden" name="csrf_token" value="<?php echo xuh(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $line['id']; ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="enabled" value="<?php echo (int) $line['enabled'] === 1 ? 0 : 1; ?>">
                    <button type="submit"><?php echo (int) $line['enabled'] === 1 ? 'Desativar' : 'Ativar'; ?></button>
                </form>
                <form method="post" action="/save-xui-line.php" onsubmit="return confirm('Remover esta linha do XUI?');">
                    <input type="hidden" name="csrf_token" value="<?php echo xuh(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $line['id']; ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="danger">Excluir</button>
                </form>
            </div>
        </section>

        <section class="card full">
            <h2>Trava CDN por IP</h2>
            <div class="status-grid">
                <div class="status-box <?php echo $cdnIpLockActive ? 'active' : 'inactive'; ?>">
                    <span class="status-chip <?php echo $cdnIpLockActive ? 'active' : 'inactive'; ?>">
                        <?php echo $cdnIpLockActive ? 'ATIVA' : 'INATIVA'; ?>
                    </span>
                    <p style="margin:12px 0 6px"><strong><?php echo $cdnIpLockActive ? $cdnIpLockCount . ' regra(s) aplicada(s)' : 'sem trava configurada'; ?></strong></p>
                    <p class="muted" style="margin:0">A CDN valida o IP antes do XUI. Se não bater, a request morre no cérebro/LB e não chega ao painel original.</p>
                    <?php if ($cdnIpLockActive): ?>
                        <ul class="rule-list">
                            <?php foreach ($ipLock['ips'] as $rule): ?>
                                <li><?php echo xuh($rule); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="muted" style="margin:10px 0 0">Última atualização: <?php echo xuh($ipLock['updated_at'] ?: '-'); ?></p>
                    <form method="post" action="/save-xui-line.php" class="quick-lock-form">
                        <input type="hidden" name="csrf_token" value="<?php echo xuh(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $line['id']; ?>">
                        <input type="hidden" name="action" value="ip_lock">
                        <label>Trocar IPs do main/LBs deste cliente</label>
                        <textarea name="cdn_allowed_ips" rows="6" placeholder="um IP/regra por linha&#10;45.140.192.237&#10;143.14.168.78&#10;45.140.192.0/24&#10;45.140.192.10-45.140.192.30&#10;45.140.192.*"><?php echo xuh((string) $ipLock['allowed_ips']); ?></textarea>
                        <label>Notas da trava CDN</label>
                        <textarea name="cdn_ip_notes" rows="2" placeholder="main do cliente + LBs autorizados"><?php echo xuh((string) $ipLock['notes']); ?></textarea>
                        <button type="submit">Salvar trava CDN</button>
                    </form>
                </div>
                <div class="status-box">
                    <p style="margin:0 0 10px"><strong>Formatos aceitos</strong></p>
                    <p class="muted" style="margin:0">IP único: <code>45.140.192.237</code></p>
                    <p class="muted" style="margin:6px 0 0">CIDR: <code>45.140.192.0/24</code></p>
                    <p class="muted" style="margin:6px 0 0">Faixa: <code>45.140.192.10-45.140.192.30</code></p>
                    <p class="muted" style="margin:6px 0 0">Curinga: <code>45.140.192.*</code></p>
                </div>
            </div>
        </section>

        <section class="card full">
            <h2>Editar linha</h2>
            <form method="post" action="/save-xui-line.php">
                <input type="hidden" name="csrf_token" value="<?php echo xuh(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $line['id']; ?>">
                <input type="hidden" name="action" value="update">
                <div class="cols">
                    <div>
                        <label>Username</label>
                        <input name="username" required value="<?php echo xuh($line['username']); ?>">
                        <label>Senha</label>
                        <input name="password" placeholder="deixe em branco para manter">
                        <label>Conexões</label>
                        <input name="max_connections" type="number" min="1" value="<?php echo (int) $line['max_connections']; ?>">
                        <label>Expiração</label>
                        <input name="exp_date" type="date" value="<?php echo xuh($line['exp_date_input']); ?>">
                        <label>Member ID</label>
                        <input name="member_id" type="number" min="0" value="<?php echo (int) $line['member_id']; ?>">
                        <label>Force Server ID</label>
                        <input name="force_server_id" type="number" min="0" value="<?php echo (int) $line['force_server_id']; ?>">
                    </div>
                    <div>
                        <label>IPs permitidos</label>
                        <textarea name="allowed_ips" rows="2"><?php echo xuh($line['allowed_ips']); ?></textarea>
                        <label>User-Agent permitido</label>
                        <textarea name="allowed_ua" rows="2"><?php echo xuh($line['allowed_ua']); ?></textarea>
                        <label>Notas administrativas</label>
                        <textarea name="admin_notes" rows="4"><?php echo xuh($line['admin_notes']); ?></textarea>
                        <label>IPs permitidos na CDN</label>
                        <textarea name="cdn_allowed_ips" rows="5" placeholder="um IP/regra por linha&#10;45.140.192.237&#10;45.140.192.0/24&#10;45.140.192.10-45.140.192.30&#10;45.140.192.*"><?php echo xuh((string) $ipLock['allowed_ips']); ?></textarea>
                        <label>Notas da trava CDN</label>
                        <textarea name="cdn_ip_notes" rows="2" placeholder="main/LB autorizados para este usuário"><?php echo xuh((string) $ipLock['notes']); ?></textarea>
                        <p>
                            <label><input type="checkbox" name="allowed_outputs[]" value="1" <?php echo in_array(1, $line['allowed_output_ids'], true) ? 'checked' : ''; ?>> TS</label>
                            <label><input type="checkbox" name="allowed_outputs[]" value="2" <?php echo in_array(2, $line['allowed_output_ids'], true) ? 'checked' : ''; ?>> HLS</label>
                            <label><input type="checkbox" name="allowed_outputs[]" value="3" <?php echo in_array(3, $line['allowed_output_ids'], true) ? 'checked' : ''; ?>> MP4</label>
                        </p>
                        <p>
                            <label><input type="checkbox" name="enabled" value="1" <?php echo (int) $line['enabled'] === 1 ? 'checked' : ''; ?>> enabled</label>
                            <label><input type="checkbox" name="admin_enabled" value="1" <?php echo (int) $line['admin_enabled'] === 1 ? 'checked' : ''; ?>> admin enabled</label>
                            <label><input type="checkbox" name="is_trial" value="1" <?php echo (int) $line['is_trial'] === 1 ? 'checked' : ''; ?>> trial</label>
                            <label><input type="checkbox" name="is_restreamer" value="1" <?php echo (int) $line['is_restreamer'] === 1 ? 'checked' : ''; ?>> restreamer</label>
                        </p>
                        <p class="muted">Se preencher IPs aqui, a CDN só aceita requests desse usuário vindos desses IPs/regras específicos. O bloqueio acontece antes do XUI.</p>
                    </div>
                </div>
                <div class="bouquets">
                    <?php foreach ($bouquets as $b): ?>
                        <label>
                            <input type="checkbox" name="bouquets[]" value="<?php echo (int) $b['id']; ?>" <?php echo in_array((int) $b['id'], $line['bouquet_ids'], true) ? 'checked' : ''; ?>>
                            <span>#<?php echo (int) $b['id']; ?> · <?php echo xuh($b['bouquet_name']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit">Salvar alterações</button>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>

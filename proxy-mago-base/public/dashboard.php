<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$settings = SettingsRepository::all();
$nginxConfig = NginxGenerator::render($settings);
$recentLogs = Database::pdo()->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 10')->fetchAll();
$origins = OriginRepository::all();
$aliases = AliasRepository::all();
$lastToken = $_SESSION['last_issued_token'] ?? null;
unset($_SESSION['last_issued_token']);
$primaryAlias = AliasRepository::primary();
$healthChecks = HealthCheck::run();
$applyResult = $_SESSION['nginx_apply_result'] ?? null;
unset($_SESSION['nginx_apply_result']);
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
        <span>Painel leve — origem protegida, aliases via Cloudflare</span>
    </div>
    <nav>
        <a href="#origins">Origens</a>
        <a href="#aliases">Aliases</a>
        <a href="#tokens">Tokens</a>
        <a href="#health">Saúde</a>
        <a href="/export-config.php">Exportar Nginx</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <section class="card">
        <h2>Configuração geral</h2>
        <p>Estas configurações valem para todo o painel. As origens (com IP/porta/credenciais do XUI) ficam apenas em SQLite, nunca em DNS público.</p>
        <form method="post" action="/save.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Usuário admin</label>
            <input name="admin_user" value="<?php echo htmlspecialchars((string) ($settings['admin_user'] ?? '')); ?>" required>
            <label>Nova senha admin</label>
            <input name="admin_pass" type="password" placeholder="Deixe em branco para manter">
            <label>Domínio oficial do main</label>
            <input name="panel_domain" value="<?php echo htmlspecialchars((string) ($settings['panel_domain'] ?? '')); ?>" placeholder="cdnvoods.vr766.com">
            <label>User-Agent permitido (opcional)</label>
            <input name="allowed_user_agent" value="<?php echo htmlspecialchars((string) ($settings['allowed_user_agent'] ?? '')); ?>">
            <label>TTL padrão do token (segundos)</label>
            <input name="token_ttl" type="number" min="60" value="<?php echo htmlspecialchars((string) ($settings['token_ttl'] ?? Config::get('token_ttl'))); ?>">
            <label>Rate limit (req/min por IP)</label>
            <input name="rate_limit_per_minute" type="number" min="0" value="<?php echo htmlspecialchars((string) ($settings['rate_limit_per_minute'] ?? Config::get('rate_limit_per_minute'))); ?>">
            <label>Segredo interno do proxy</label>
            <input name="app_secret" value="<?php echo htmlspecialchars((string) ($settings['app_secret'] ?? '')); ?>">
            <input type="hidden" name="origin_host" value="<?php echo htmlspecialchars((string) ($settings['origin_host'] ?? '127.0.0.1')); ?>">
            <input type="hidden" name="origin_port" value="<?php echo htmlspecialchars((string) ($settings['origin_port'] ?? 80)); ?>">
            <button type="submit">Salvar</button>
        </form>
    </section>

    <section class="card" id="origins">
        <h2>Origens protegidas</h2>
        <p>Cadastre aqui o XUI real (host, porta e credenciais). Nada disso aparece em DNS, header público ou log de acesso.</p>
        <?php if ($origins): ?>
        <table>
            <thead><tr><th>#</th><th>Nome</th><th>Host:Porta</th><th>Auth</th><th>Ativo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($origins as $o): ?>
                <tr>
                    <td><?php echo (int) $o['id']; ?></td>
                    <td><?php echo htmlspecialchars($o['name']); ?></td>
                    <td><code><?php echo htmlspecialchars($o['scheme'] . '://' . $o['host'] . ':' . $o['port']); ?></code></td>
                    <td><?php echo $o['auth_user'] !== '' ? 'sim' : 'não'; ?></td>
                    <td><?php echo (int) $o['active'] === 1 ? 'sim' : 'não'; ?></td>
                    <td>
                        <details>
                            <summary>editar</summary>
                            <form method="post" action="/save-origin.php" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>">
                                <label>Nome</label>
                                <input name="name" value="<?php echo htmlspecialchars($o['name']); ?>" required>
                                <label>Scheme</label>
                                <select name="scheme">
                                    <option value="http" <?php echo $o['scheme'] === 'http' ? 'selected' : ''; ?>>http</option>
                                    <option value="https" <?php echo $o['scheme'] === 'https' ? 'selected' : ''; ?>>https</option>
                                </select>
                                <label>Host</label>
                                <input name="host" value="<?php echo htmlspecialchars($o['host']); ?>" required>
                                <label>Porta</label>
                                <input name="port" type="number" min="1" max="65535" value="<?php echo (int) $o['port']; ?>" required>
                                <label>Base path (opcional)</label>
                                <input name="base_path" value="<?php echo htmlspecialchars($o['base_path']); ?>">
                                <label>Usuário XUI</label>
                                <input name="auth_user" placeholder="Deixe em branco para manter" autocomplete="off">
                                <label>Senha XUI</label>
                                <input name="auth_pass" type="password" placeholder="Deixe em branco para manter" autocomplete="off">
                                <label><input type="checkbox" name="active" value="1" <?php echo (int) $o['active'] === 1 ? 'checked' : ''; ?>> Ativa</label>
                                <button type="submit">Salvar</button>
                            </form>
                            <form method="post" action="/delete-origin.php" class="inline" onsubmit="return confirm('Excluir esta origem?')">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>">
                                <button type="submit" class="danger">Excluir</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p><em>Nenhuma origem cadastrada ainda.</em></p>
        <?php endif; ?>

        <h3>Nova origem</h3>
        <form method="post" action="/save-origin.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Nome</label>
            <input name="name" required placeholder="Origem XUI principal">
            <label>Scheme</label>
            <select name="scheme"><option>http</option><option>https</option></select>
            <label>Host</label>
            <input name="host" required placeholder="38.190.176.170">
            <label>Porta</label>
            <input name="port" type="number" min="1" max="65535" value="80" required>
            <label>Base path (opcional)</label>
            <input name="base_path" placeholder="/">
            <label>Usuário XUI</label>
            <input name="auth_user" autocomplete="off">
            <label>Senha XUI</label>
            <input name="auth_pass" type="password" autocomplete="off">
            <label><input type="checkbox" name="active" value="1" checked> Ativa</label>
            <button type="submit">Adicionar origem</button>
        </form>
    </section>

    <section class="card" id="aliases">
        <h2>Aliases públicos</h2>
        <p>O <strong>main oficial</strong> é o alias marcado como primário. CNAMEs extras podem apontar para ele via Cloudflare (sempre com proxy laranja ligado, para não vazar o IP da VPS).</p>
        <?php if ($aliases): ?>
        <table>
            <thead><tr><th>#</th><th>Hostname</th><th>Origem</th><th>Primário</th><th>Ativo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($aliases as $a): ?>
                <tr>
                    <td><?php echo (int) $a['id']; ?></td>
                    <td><code><?php echo htmlspecialchars($a['hostname']); ?></code></td>
                    <td><?php echo htmlspecialchars($a['origin_name'] ?? '?'); ?></td>
                    <td><?php echo (int) $a['is_primary'] === 1 ? 'sim' : ''; ?></td>
                    <td><?php echo (int) $a['active'] === 1 ? 'sim' : 'não'; ?></td>
                    <td>
                        <details>
                            <summary>editar</summary>
                            <form method="post" action="/save-alias.php" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                <label>Hostname</label>
                                <input name="hostname" value="<?php echo htmlspecialchars($a['hostname']); ?>" required>
                                <label>Origem</label>
                                <select name="origin_id" required>
                                    <?php foreach ($origins as $o): ?>
                                        <option value="<?php echo (int) $o['id']; ?>" <?php echo (int) $a['origin_id'] === (int) $o['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($o['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label><input type="checkbox" name="is_primary" value="1" <?php echo (int) $a['is_primary'] === 1 ? 'checked' : ''; ?>> Marcar como main oficial</label>
                                <label><input type="checkbox" name="active" value="1" <?php echo (int) $a['active'] === 1 ? 'checked' : ''; ?>> Ativo</label>
                                <button type="submit">Salvar</button>
                            </form>
                            <form method="post" action="/delete-alias.php" class="inline" onsubmit="return confirm('Excluir este alias?')">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                <button type="submit" class="danger">Excluir</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p><em>Nenhum alias cadastrado. Cadastre pelo menos um (o main oficial).</em></p>
        <?php endif; ?>

        <h3>Novo alias</h3>
        <?php if (!$origins): ?>
            <p><em>Cadastre uma origem antes.</em></p>
        <?php else: ?>
        <form method="post" action="/save-alias.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Hostname público</label>
            <input name="hostname" required placeholder="cdnvoods.vr766.com">
            <label>Origem</label>
            <select name="origin_id" required>
                <?php foreach ($origins as $o): ?>
                    <option value="<?php echo (int) $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label><input type="checkbox" name="is_primary" value="1"> Main oficial</label>
            <label><input type="checkbox" name="active" value="1" checked> Ativo</label>
            <button type="submit">Adicionar alias</button>
        </form>
        <?php endif; ?>
    </section>

    <section class="card" id="tokens">
        <h2>Tokens</h2>
        <p>Emita um token para um alias e entregue ao player. O token é assinado internamente contra o alias+IP e expira sozinho.</p>
        <?php if ($lastToken): ?>
            <div class="alert success">
                <strong>Token emitido para <?php echo htmlspecialchars($lastToken['alias_hostname']); ?>:</strong><br>
                <code><?php echo htmlspecialchars($lastToken['token']); ?></code><br>
                <small>expira em <?php echo htmlspecialchars($lastToken['expires_at']); ?></small><br>
                <small>URL do player: <code>https://<?php echo htmlspecialchars($lastToken['alias_hostname']); ?>/get.php?t=<?php echo htmlspecialchars($lastToken['token']); ?></code></small>
            </div>
        <?php endif; ?>
        <?php if ($aliases): ?>
        <form method="post" action="/issue-token.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Alias</label>
            <select name="alias_id" required>
                <?php foreach ($aliases as $a): ?>
                    <option value="<?php echo (int) $a['id']; ?>"><?php echo htmlspecialchars($a['hostname']); ?></option>
                <?php endforeach; ?>
            </select>
            <label>IP permitido (opcional, use .0 final para /24)</label>
            <input name="allowed_ip" placeholder="ex: 187.45.10.0">
            <label>TTL (segundos, 0 = padrão)</label>
            <input name="ttl" type="number" min="0" value="0">
            <button type="submit">Emitir token</button>
        </form>
        <?php else: ?>
            <p><em>Cadastre um alias primeiro.</em></p>
        <?php endif; ?>
    </section>

    <section class="card" id="nginx">
        <h2>Config Nginx (preview)</h2>
        <p>Este snippet é o ponto de partida para o proxy reverso. O main público deve ficar atrás da Cloudflare para não expor o IP da VPS.</p>
        <?php if ($applyResult): ?>
            <div class="alert <?php echo $applyResult['ok'] ? 'success' : ''; ?>">
                <?php echo nl2br(htmlspecialchars($applyResult['message'])); ?>
            </div>
        <?php endif; ?>
        <form method="post" action="/apply-config.php" onsubmit="return confirm('Validar, instalar e recarregar o Nginx agora?')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <button type="submit">Aplicar no Nginx</button>
        </form>
        <p><small>O processo cria backup, executa <code>nginx -t</code> e restaura a configuração anterior se a validação falhar.</small></p>
        <textarea readonly rows="18"><?php echo htmlspecialchars($nginxConfig); ?></textarea>
    </section>

    <section class="card" id="health">
        <h2>Health checks</h2>
        <p>Diagnóstico local atualizado ao abrir o dashboard. A verificação de origem tem timeout de 3 segundos.</p>
        <table>
            <thead><tr><th>Componente</th><th>Status</th><th>Detalhe</th><th>Tempo</th></tr></thead>
            <tbody>
            <?php foreach ($healthChecks as $check): ?>
                <tr>
                    <td><?php echo htmlspecialchars($check['label']); ?></td>
                    <td><span class="status <?php echo $check['ok'] ? 'ok' : 'fail'; ?>"><?php echo $check['ok'] ? 'OK' : 'FALHA'; ?></span></td>
                    <td><?php echo htmlspecialchars($check['detail']); ?></td>
                    <td><?php echo (int) $check['ms']; ?> ms</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><a href="/health.php" target="_blank" rel="noopener">Ver diagnóstico JSON</a></p>
    </section>

    <section class="card">
        <h2>Fluxo operacional</h2>
        <ul>
            <li><strong>Main oficial:</strong> <code><?php echo htmlspecialchars($primaryAlias['hostname'] ?? '(não definido)'); ?></code></li>
            <li><strong>Origem protegida:</strong> apenas em SQLite, nunca em DNS, header ou log público</li>
            <li><strong>CNAMEs extras:</strong> apontam para o main oficial e continuam atrás da Cloudflare</li>
            <li><strong>Tokens:</strong> emitidos por alias, opcionalmente presos ao IP do cliente</li>
        </ul>
    </section>

    <section class="card full">
        <h2>Últimos eventos</h2>
        <table>
            <thead>
                <tr><th>Tipo</th><th>IP</th><th>Mensagem</th><th>Data</th></tr>
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

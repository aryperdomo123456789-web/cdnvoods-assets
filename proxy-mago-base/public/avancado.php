<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$settings = SettingsRepository::all();
$nginxError = '';
try {
    $nginxConfig = NginxGenerator::render($settings);
} catch (Throwable $e) {
    // Configuração incompleta não pode derrubar a aba inteira.
    $nginxConfig = '';
    $nginxError = $e->getMessage();
}
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
        <a href="#escala">Escala</a>
        <a href="/usuario.php">Usuário</a>
        <a href="/lb.php">LB</a>
        <a href="/export-config.php">Exportar Nginx</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <section class="card">
        <h2>Configuração geral</h2>
        <p>Estas configurações valem para todo o painel. As origens (com IP/porta/credenciais do XUI) ficam apenas em SQLite, nunca em DNS público.</p>
        <p><strong>Acesso do painel:</strong> use esta área para trocar o usuário e a senha do admin quando o projeto estiver concluído.</p>
        <form method="post" action="/save.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Usuário admin</label>
            <input name="admin_user" value="<?php echo htmlspecialchars((string) ($settings['admin_user'] ?? '')); ?>" required>
            <label>Nova senha admin</label>
            <input name="admin_pass" type="password" placeholder="Deixe em branco para manter">
            <small>Se você preencher uma nova senha e salvar, o login do painel passa a usar a nova credencial imediatamente.</small>
            <label><input type="checkbox" name="force_https" value="1" <?php echo ((int) ($settings['force_https'] ?? Config::get('force_https', 1)) === 1) ? 'checked' : ''; ?>> Forçar HTTPS no painel quando houver certificado</label>
            <small>Isso preserva o painel no TLS sem tocar no tráfego público dos players.</small>
            <label><input type="checkbox" name="admin_2fa_enabled" value="1" <?php echo ((int) ($settings['admin_2fa_enabled'] ?? 0) === 1) ? 'checked' : ''; ?>> Ativar 2FA no admin (Google Authenticator)</label>
            <label>Segredo 2FA Base32</label>
            <input name="admin_2fa_secret" value="<?php echo htmlspecialchars((string) ($settings['admin_2fa_secret'] ?? '')); ?>" placeholder="Gerar ou preencher manualmente">
            <small>Google Authenticator, 1Password, Aegis e similares aceitam esse formato.</small>
            <label>Domínio oficial do main</label>
            <input name="panel_domain" value="<?php echo htmlspecialchars((string) ($settings['panel_domain'] ?? '')); ?>" placeholder="cdnvoods.vr766.com">
            <label>User-Agent permitido (opcional, desligado por padrão)</label>
            <input name="allowed_user_agent" value="<?php echo htmlspecialchars((string) ($settings['allowed_user_agent'] ?? '')); ?>">
            <label><input type="checkbox" name="ua_filter_enabled" value="1" <?php echo ((int) ($settings['ua_filter_enabled'] ?? 0) === 1) ? 'checked' : ''; ?>> Ativar filtro de User-Agent</label>
            <small>Deixe desligado: XCIPTV, IBO Player, IPTV Smarters, TiviMate e VLC usam User-Agents diferentes e o filtro derruba todos eles.</small>
            <label><input type="checkbox" name="log_segments" value="1" <?php echo ((int) ($settings['log_segments'] ?? 0) === 1) ? 'checked' : ''; ?>> Registrar cada segmento .ts no log</label>
            <small>Ligue só para depurar: um INSERT por segmento pesa muito na VPS.</small>
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

    <?php
    $stateHealth = StateStore::health();
    $lbTotals = LbRouter::totals();
    ?>
    <section class="card" id="escala">
        <h2>Escala — estado vivo e cérebro puro</h2>
        <p>
            Estado vivo agora: <strong><?php echo htmlspecialchars($stateHealth['driver']); ?></strong>
            (configurado: <?php echo htmlspecialchars($stateHealth['configured']); ?><?php
                echo $stateHealth['degraded'] ? ' — degradado: ' . htmlspecialchars($stateHealth['reason']) : ''; ?>).
            LBs instalados: <strong><?php echo (int) $lbTotals['installed']; ?></strong>,
            saudáveis: <strong><?php echo (int) $lbTotals['healthy']; ?></strong>.
        </p>
        <form method="post" action="/save-scale.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>Driver do estado vivo (sessão, heartbeat, contador, trava de IP)</label>
            <select name="state_driver">
                <?php foreach (StateStore::DRIVERS as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $stateHealth['configured'] === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
            </select>
            <small>Se o Redis não responder na hora de salvar, o painel volta sozinho para SQLite — nenhum player cai.</small>
            <label>Redis host</label>
            <input name="redis_host" value="<?php echo htmlspecialchars((string) SettingsRepository::get('redis_host', (string) Config::get('redis_host', '127.0.0.1'))); ?>">
            <label>Redis porta</label>
            <input name="redis_port" type="number" min="1" max="65535" value="<?php echo (int) SettingsRepository::get('redis_port', (string) Config::get('redis_port', 6379)); ?>">
            <label>Redis DB</label>
            <input name="redis_db" type="number" min="0" max="15" value="<?php echo (int) SettingsRepository::get('redis_db', (string) Config::get('redis_db', 0)); ?>">
            <label>Redis senha</label>
            <input name="redis_pass" type="password" placeholder="Deixe em branco para manter" autocomplete="off">
            <label>Modo padrão de rota para usuário novo</label>
            <select name="lb_default_mode">
                <?php foreach (LbRouter::MODES as $m): ?>
                <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $lbTotals['default_mode'] === $m ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
                <?php endforeach; ?>
            </select>
            <small>Com <code>auto</code>, todo usuário do XUI passa a sair pelo melhor músculo — o job <code>lb_autoroute</code> materializa as rotas.</small>
            <label><input type="checkbox" name="lb_require_delivery" value="1" <?php echo $lbTotals['require_delivery'] ? 'checked' : ''; ?>> Entrega obrigatória pelo LB (cérebro puro)</label>
            <small>Com isso ligado, o main NÃO entrega stream: usuário sem músculo apto recebe 503. Só é aceito quando existe LB instalado e saudável.</small>
            <button type="submit">Salvar escala</button>
        </form>
    </section>

    <section class="card" id="origins">
        <h2>Origens protegidas</h2>
        <p>Cadastre aqui o XUI real (host, porta e credenciais). Nada disso aparece em DNS, header público ou log de acesso.</p>
        <?php if ($origins): ?>
        <table>
            <thead><tr><th>#</th><th>Nome</th><th>Tipo</th><th>Host:Porta</th><th>Host header</th><th>Auth</th><th>Ativo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($origins as $o): ?>
                <tr>
                    <td><?php echo (int) $o['id']; ?></td>
                    <td><?php echo htmlspecialchars($o['name']); ?></td>
                    <td><?php echo strtoupper((string) ($o['type'] ?? 'a')); ?></td>
                    <td><code><?php echo htmlspecialchars($o['scheme'] . '://' . $o['host'] . ':' . $o['port']); ?></code></td>
                    <td><?php echo htmlspecialchars((string) ($o['host_header'] ?? '')) ?: '<em>—</em>'; ?></td>
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
                                <label>Tipo de apontamento</label>
                                <select name="type">
                                    <option value="a" <?php echo (($o['type'] ?? 'a') === 'a') ? 'selected' : ''; ?>>A (IP direto do XUI)</option>
                                    <option value="cname" <?php echo (($o['type'] ?? 'a') === 'cname') ? 'selected' : ''; ?>>CNAME (domínio interno do XUI)</option>
                                </select>
                                <label>Host header enviado ao XUI (opcional)</label>
                                <input name="host_header" value="<?php echo htmlspecialchars((string) ($o['host_header'] ?? '')); ?>" placeholder="ex: painel.xui.interno" autocomplete="off">
                                <label>Hosts extras da origem (sanitizados do corpo)</label>
                                <input name="extra_hosts" value="<?php echo htmlspecialchars((string) ($o['extra_hosts'] ?? '')); ?>" placeholder="ex: main2.origem.tld, 10.0.0.5" autocomplete="off">
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
            <label>Tipo de apontamento</label>
            <select name="type">
                <option value="a">A (IP direto do XUI)</option>
                <option value="cname">CNAME (domínio interno do XUI)</option>
            </select>
            <label>Host header enviado ao XUI (opcional)</label>
            <input name="host_header" placeholder="ex: painel.xui.interno" autocomplete="off">
            <label>Hosts extras da origem (sanitizados do corpo)</label>
            <input name="extra_hosts" placeholder="ex: main2.origem.tld, 10.0.0.5" autocomplete="off">
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
                                <label><input type="checkbox" name="require_token" value="1" <?php echo (int) ($a['require_token'] ?? 0) === 1 ? 'checked' : ''; ?>> Exigir token (desliga o fluxo XUI username/password)</label>
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
            <label><input type="checkbox" name="require_token" value="1"> Exigir token (desliga o fluxo XUI username/password)</label>
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
        <?php if ($nginxError !== ''): ?>
            <div class="alert">Não foi possível gerar o snippet: <?php echo htmlspecialchars($nginxError); ?></div>
        <?php endif; ?>
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
                <?php foreach ($rows = $recentLogs as $row): ?>
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

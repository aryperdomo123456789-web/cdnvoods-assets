<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = csrf_token();
session_write_close();
$cfg = XuiSyncConfig::get();
$limitMode = Divergence::mode();
$tolerance = Divergence::tolerance();
$sessionsOn = (int) SettingsRepository::get('cdn_sessions_enabled', 1) === 1;
$traceOn = (int) SettingsRepository::get('direct_source_trace', 1) === 1;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — Restreamento ao vivo</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .kpis{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px}
        .kpi{background:#111827;border:1px solid #1f2937;border-radius:8px;padding:10px 14px;min-width:130px}
        .kpi b{display:block;font-size:20px}
        .filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
        .filters input,.filters select{padding:6px 8px}
        table{width:100%;font-size:13px}
        td,th{padding:5px 6px;vertical-align:top}
        .tag{padding:1px 6px;border-radius:4px;font-size:11px}
        .ok{background:#064e3b}.warn{background:#78350f}.bad{background:#7f1d1d}
        .info{background:#1e3a8a}
        .muted{opacity:.65}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>Restreamento em tempo real</span></div>
    <nav>
        <a href="/dashboard.php">Domínios</a>
        <a href="/jobs.php">Jobs</a>
        <a href="/lb.php">LB</a>
        <a href="/avancado.php">Avançado</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <?php if ($flash): ?><section class="card full"><div class="alert success"><?php echo htmlspecialchars($flash); ?></div></section><?php endif; ?>

    <section class="card full">
        <h2>Integração read-only com o XUI</h2>
        <p class="muted">Somente leitura. O stream <strong>nunca</strong> depende desta conexão — se o MySQL do XUI cair,
           o painel exibe o último snapshot em estado degradado e as rotas públicas continuam funcionando.</p>
        <form method="post" action="/save-xui-sync.php" class="filters">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input name="host" placeholder="host MySQL do XUI" value="<?php echo htmlspecialchars((string) $cfg['host']); ?>">
            <input name="port" type="number" style="width:90px" value="<?php echo (int) $cfg['port']; ?>">
            <input name="database_name" placeholder="database" value="<?php echo htmlspecialchars((string) $cfg['database_name']); ?>">
            <input name="username" placeholder="usuário read-only" value="<?php echo htmlspecialchars((string) $cfg['username']); ?>">
            <input name="password" type="password" placeholder="senha (deixe vazio p/ manter)">
            <label><input type="checkbox" name="sync_enabled" value="1" <?php echo (int) $cfg['sync_enabled'] === 1 ? 'checked' : ''; ?>> sync ativo</label>
            <button type="submit">Salvar</button>
        </form>
        <div id="syncbox" class="muted">carregando…</div>
        <form method="post" action="/run-job.php" style="margin-top:8px">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="job" value="xui_sync_activity">
            <button type="submit">Rodar sync agora</button>
        </form>
    </section>

    <section class="card full">
        <h2>Regras da CDN (contagem e limite)</h2>
        <p class="muted">A CDN conta conexões por <strong>sessão lógica local</strong>, não por request. Isso resolve
           HLS (vários segmentos = 1 conexão) e <strong>direct source</strong> (o XUI não enxerga, a CDN enxerga).</p>
        <form method="post" action="/save-intelligence.php" class="filters">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <label>Estouro de limite:
                <select name="limit_mode">
                    <option value="alert" <?php echo $limitMode === 'alert' ? 'selected' : ''; ?>>só alertar</option>
                    <option value="mark" <?php echo $limitMode === 'mark' ? 'selected' : ''; ?>>alertar + marcar risco</option>
                    <option value="block" <?php echo $limitMode === 'block' ? 'selected' : ''; ?>>bloquear acima do limite</option>
                </select>
            </label>
            <label>Tolerância p/ reconexão (s):
                <input type="number" name="limit_tolerance_seconds" min="0" max="600" style="width:90px"
                       value="<?php echo $tolerance; ?>">
            </label>
            <label><input type="checkbox" name="cdn_sessions_enabled" value="1" <?php echo $sessionsOn ? 'checked' : ''; ?>> contador local de sessões</label>
            <label><input type="checkbox" name="direct_source_trace" value="1" <?php echo $traceOn ? 'checked' : ''; ?>> rastrear direct source</label>
            <button type="submit">Salvar regras</button>
        </form>
    </section>

    <section class="card full">
        <h2>Visão ao vivo</h2>
        <div class="kpis" id="kpis"></div>
        <div class="filters">
            <input id="f_user" placeholder="usuário">
            <input id="f_host" placeholder="domínio público">
            <input id="f_ip" placeholder="IP do cliente">
            <input id="f_player" placeholder="player / User-Agent">
            <select id="f_kind">
                <option value="">todo consumo</option>
                <option value="m3u">m3u</option><option value="api">api</option>
                <option value="live">live</option><option value="movie">movie</option>
                <option value="series">series</option><option value="hls">hls</option>
                <option value="segment">segment</option>
            </select>
            <select id="f_status">
                <option value="">qualquer status</option>
                <option value="ok">ok</option><option value="over_limit">acima do limite</option>
                <option value="inconsistent">inconsistente</option><option value="unknown_user">usuário desconhecido</option>
            </select>
            <label><input type="checkbox" id="f_over"> só estourando limite</label>
            <label><input type="checkbox" id="f_pause"> pausar polling</label>
        </div>
        <table>
            <thead><tr>
                <th>Usuário</th><th>Host público</th><th>IP</th><th>Player</th>
                <th>CDN</th><th>XUI</th><th>Δ</th><th>Fonte</th><th>Limite</th><th>Consumo</th><th>Stream</th>
                <th>Última atividade</th><th>Status</th><th></th>
            </tr></thead>
            <tbody id="livebody"><tr><td colspan="14">carregando…</td></tr></tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Sessões locais da CDN (conexões reais)</h2>

    </section>

    <section class="card full">
        <h2>Usuários do XUI — conexões contratadas x em uso</h2>
        <p class="muted">Lista o parque inteiro de assinantes espelhado do XUI (aparece mesmo sem consumo).
           <strong>Em uso</strong> = maior valor entre o contador local da CDN e o <code>user_activity_now</code> do XUI —
           direct source só a CDN enxerga. <strong>Playlist/API</strong> (download de m3u, player_api) não ocupa slot do plano.</p>
        <div class="kpis" id="ukpis"></div>
        <div class="filters">
            <input id="u_q" placeholder="buscar usuário">
            <label><input type="checkbox" id="u_active" checked> só quem está online</label>
            <label><input type="checkbox" id="u_enabled"> só habilitados</label>
            <label><input type="checkbox" id="u_over"> só acima do limite</label>
        </div>
        <table>
            <thead><tr>
                <th>Usuário</th><th>Em uso</th><th>Limite</th><th>Livres</th><th>Uso</th>
                <th>CDN</th><th>XUI</th><th>Δ</th><th>Direct</th><th>Playlist/API</th>
                <th>Host público</th><th>IP</th><th>Player</th><th>Visto</th><th>Status</th><th></th>
            </tr></thead>
            <tbody id="userbody"><tr><td colspan="16">carregando…</td></tr></tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Sessões locais detalhadas</h2>
        <div class="filters">
            <select id="s_kind">
                <option value="">todos os tipos</option>
                <option value="live">live</option><option value="movie">movie</option>
                <option value="series">series</option><option value="hls">hls</option>
                <option value="playlist">playlist</option><option value="api">api</option>
            </select>
            <label><input type="checkbox" id="s_direct"> só direct source</label>
        </div>
        <table>
            <thead><tr>
                <th>Usuário</th><th>Tipo</th><th>IP</th><th>Player</th><th>Stream</th>
                <th>Início</th><th>Ativa há</th><th>Reqs</th><th>Bytes</th><th>Direct</th><th>Match</th>
            </tr></thead>
            <tbody id="sessbody"><tr><td colspan="11">carregando…</td></tr></tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Divergências operacionais</h2>
        <div class="filters">
            <select id="d_sev">
                <option value="">todas as severidades</option>
                <option value="critical">critical</option><option value="warn">warn</option><option value="info">info</option>
            </select>
            <span class="muted" id="d_counters"></span>
        </div>
        <table>
            <thead><tr>
                <th>Usuário</th><th>Tipo</th><th>Sev.</th><th>CDN</th><th>XUI</th><th>Limite</th>
                <th>Causa provável</th><th>Ocorrências</th><th>Desde</th>
            </tr></thead>
            <tbody id="divbody"><tr><td colspan="9">carregando…</td></tr></tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Direct source (DB do XUI + runtime da CDN)</h2>
        <p class="muted">Duas verdades: o XUI marca <code>streams.direct_source</code> e guarda a URL externa em
           <code>stream_source</code>; a CDN observa o host que o cliente realmente consumiu por dentro.
           O host efetivo é sempre o do runtime quando existe. O player nunca vê nada disso.</p>
        <div id="directbox" class="muted">carregando…</div>
        <div class="filters">
            <select id="dr_mode">
                <option value="">todos os modos</option>
                <option value="db_runtime">db_runtime (cadastrado e confirmado)</option>
                <option value="db_only">db_only (cadastrado, sem consumo)</option>
                <option value="runtime_only">runtime_only (redirect sem flag)</option>
            </select>
            <select id="dr_cons">
                <option value="">todas as consistências</option>
                <option value="consistent">consistent</option>
                <option value="mismatch">mismatch</option>
                <option value="host_missing">host_missing</option>
                <option value="parse_error">parse_error</option>
                <option value="runtime_only">runtime_only</option>
            </select>
            <input id="dr_host" placeholder="host final">
        </div>
        <h3>Catálogo consolidado por stream</h3>
        <table>
            <thead><tr>
                <th>Stream</th><th>Tipo</th><th>Modo</th><th>Host (DB)</th><th>Host (runtime)</th>
                <th>Host efetivo</th><th>Consistência</th><th>Parse</th><th>Hits</th><th>Falhas</th><th>Último</th>
            </tr></thead>
            <tbody id="dstreambody"><tr><td colspan="11">carregando…</td></tr></tbody>
        </table>
        <h3>Top hosts finais (15min)</h3>
        <table>
            <thead><tr><th>Host</th><th>Fonte</th><th>Hits</th><th>Falhas</th><th>Usuários</th><th>Streams</th></tr></thead>
            <tbody id="directbody"><tr><td colspan="6">carregando…</td></tr></tbody>
        </table>
        <h3>Falhas por host final (1h)</h3>
        <table>
            <thead><tr><th>Host</th><th>Falhas</th><th>Usuários</th><th>Streams</th><th>Última</th></tr></thead>
            <tbody id="dfailbody"><tr><td colspan="5">carregando…</td></tr></tbody>
        </table>
        <h3>Usuários com direct source ativo agora</h3>
        <table>
            <thead><tr><th>Usuário</th><th>Sessões</th><th>Modos</th><th>Hosts</th><th>Falhas</th><th>Visto</th></tr></thead>
            <tbody id="duserbody"><tr><td colspan="6">carregando…</td></tr></tbody>
        </table>
        <h3>Divergências de direct source</h3>
        <table>
            <thead><tr><th>Tipo</th><th>Sev.</th><th>Escopo</th><th>Causa provável</th><th>Ocorrências</th><th>Desde</th></tr></thead>
            <tbody id="ddivbody"><tr><td colspan="6">carregando…</td></tr></tbody>
        </table>
        <h3>Hops bloqueados / falhos (1h)</h3>
        <table>
            <thead><tr><th>Hora</th><th>Usuário</th><th>De</th><th>Para</th><th>Modo</th><th>Motivo</th></tr></thead>
            <tbody id="blockedbody"><tr><td colspan="6">carregando…</td></tr></tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Trilha de requests (auditoria)</h2>
        <label class="muted"><input type="checkbox" id="f_current" checked> só janela viva (5 min)</label>
        <label class="muted"><input type="checkbox" id="f_problems"> só erros e divergências</label>
        <table>
            <thead><tr>
                <th>Hora</th><th>request_id</th><th>Usuário</th><th>Host</th><th>IP</th>
                <th>Rota</th><th>Tipo</th><th>Status</th><th>Bytes</th><th>ms</th><th>Match</th><th>Divergência</th>
            </tr></thead>
            <tbody id="eventbody"><tr><td colspan="12">carregando…</td></tr></tbody>
        </table>
    </section>
</main>

<script>
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
const fmtBytes = (b) => { b = Number(b||0); const u=['B','KB','MB','GB','TB']; let i=0; while(b>=1024&&i<u.length-1){b/=1024;i++;} return b.toFixed(i?1:0)+u[i]; };

function filters() {
  const p = new URLSearchParams();
  if ($('f_user').value) p.set('username', $('f_user').value);
  if ($('f_host').value) p.set('host', $('f_host').value);
  if ($('f_ip').value) p.set('ip', $('f_ip').value);
  if ($('f_player').value) p.set('player', $('f_player').value);
  if ($('f_kind').value) p.set('kind', $('f_kind').value);
  if ($('f_status').value) p.set('status', $('f_status').value);
  if ($('f_over').checked) p.set('over', '1');
  return p;
}

function statusTag(s) {
  const cls = s === 'ok' ? 'ok' : (s === 'over_limit' || s === 'inconsistent' ? 'bad' : (s === 'divergent' ? 'info' : 'warn'));
  return `<span class="tag ${cls}">${esc(s)}</span>`;
}

const ago = (epoch) => {
  const s = Math.max(0, Math.floor(Date.now()/1000) - Number(epoch||0));
  if (s < 60) return s + 's';
  if (s < 3600) return Math.floor(s/60) + 'm' + (s%60) + 's';
  return Math.floor(s/3600) + 'h' + Math.floor((s%3600)/60) + 'm';
};

async function tickLive() {
  if ($('f_pause').checked) return;
  const p = filters(); p.set('view', 'live');
  const r = await fetch('/restream-data.php?' + p.toString(), {credentials:'same-origin'});
  if (!r.ok) return;
  const d = await r.json();
  const s = d.summary || {};
  const k = s.kpis || {};
  const dv = s.divergences || {};
  const dvo = k.divergences_operational || dv;
  $('kpis').innerHTML = [
    ['Conexões (CDN)', k.connections_now], ['Playlist/API abertas', k.fetch_now],
    ['Sessões locais', k.sessions_now], ['Sessões no XUI', s.active_sessions_xui],
    ['Usuários ativos', k.users_now], ['Média por usuário', k.avg_per_user],
    ['Pico 5min', k.peak_5m], ['Pico 1h', k.peak_1h],
    ['Direct source agora', k.direct_now], ['Requests 5min', s.requests_5m],
    ['Tráfego 5min', fmtBytes(s.bytes_5m)], ['Erros 5min', s.errors_5m],
    ['Acima do limite', s.over_limit], ['Match high/med/low',
      `${(k.match||{}).high||0}/${(k.match||{}).medium||0}/${(k.match||{}).low||0}`],
    ['Divergências operacionais', `${dvo.critical||0}c ${dvo.warn||0}w ${dvo.info||0}i`],
    ['Swaps 1h', k.swaps_1h], ['Jobs atrasados', k.jobs_late],
    ['Modo de limite', esc(k.limit_mode)], ['Sync XUI', esc(s.sync_status)]
  ].map(([k,v]) => `<div class="kpi"><span class="muted">${k}</span><b>${esc(v)}</b></div>`).join('');

  const rows = d.rows || [];
  $('livebody').innerHTML = rows.length ? rows.map(u => `<tr>
    <td><a href="/restream-user.php?username=${encodeURIComponent(u.username)}">${esc(u.username)}</a></td>
    <td>${esc(u.public_host_last_seen)}</td><td>${esc(u.client_ip_last_seen)}</td>
    <td class="muted">${esc((u.user_agent_last_seen||'').slice(0,42))}</td>
    <td><b>${esc(u.cdn_connections_now)}</b></td><td>${esc(u.xui_connections_now)}</td>
    <td>${Number(u.divergence)!==0 ? '<span class="tag warn">'+esc(u.divergence)+'</span>' : '0'}</td>
    <td class="muted">${esc(u.count_source)}</td><td>${esc(u.max_connections||'-')}</td>
    <td>${esc(u.last_route_kind)}</td><td>${esc(u.last_stream_name || u.last_stream_id || '-')}</td>
    <td class="muted">${esc(u.last_activity_at)}</td><td>${statusTag(u.health_status)}</td>
    <td><a href="/restream-user.php?username=${encodeURIComponent(u.username)}">detalhe</a></td>
  </tr>`).join('') : '<tr><td colspan="14" class="muted">nenhuma atividade na janela atual</td></tr>';
}

async function tickSessions() {
  if ($('f_pause').checked) return;
  const p = new URLSearchParams({view: 'sessions', limit: '120'});
  if ($('f_user').value) p.set('username', $('f_user').value);
  if ($('s_kind').value) p.set('kind', $('s_kind').value);
  if ($('s_direct').checked) p.set('direct', '1');
  const r = await fetch('/restream-data.php?' + p.toString(), {credentials:'same-origin'});
  if (!r.ok) return;
  const d = await r.json();
  const rows = d.rows || [];
  $('sessbody').innerHTML = rows.length ? rows.map(s => `<tr>
    <td><a href="/restream-user.php?username=${encodeURIComponent(s.username)}">${esc(s.username)}</a></td>
    <td>${esc(s.session_kind)}</td><td>${esc(s.client_ip)}</td>
    <td class="muted">${esc((s.user_agent||'').slice(0,38))}</td>
    <td>${esc(s.stream_id || '-')}</td>
    <td class="muted">${esc((s.started_at||'').replace('T',' ').slice(11,19))}</td>
    <td>${ago(s.started_epoch)}</td><td>${esc(s.requests)}</td><td>${fmtBytes(s.bytes)}</td>
    <td>${Number(s.direct_source) ? '<span class="tag warn">'+esc(s.direct_host||'direct')+'</span>' : '-'}</td>
    <td><span class="tag ${s.match_confidence==='high'?'ok':(s.match_confidence==='low'?'bad':'warn')}">${esc(s.match_confidence)}</span>
        <span class="muted">${esc((s.match_reason||'').slice(0,28))}</span></td>
  </tr>`).join('') : '<tr><td colspan="11" class="muted">nenhuma sessão local ativa</td></tr>';
}

async function tickDivergences() {
  if ($('f_pause').checked) return;
  const p = new URLSearchParams({view: 'divergences', limit: '100'});
  if ($('d_sev').value) p.set('severity', $('d_sev').value);
  const r = await fetch('/restream-data.php?' + p.toString(), {credentials:'same-origin'});
  if (!r.ok) return;
  const d = await r.json();
  const c = d.counters || {};
  $('d_counters').textContent = `modo ${d.mode} · tolerância ${d.tolerance}s · abertas: ${c.critical||0} críticas, ${c.warn||0} avisos, ${c.info||0} informativas`;
  const rows = d.rows || [];
  $('divbody').innerHTML = rows.length ? rows.map(x => `<tr>
    <td>${x.username && x.username !== '-' ? `<a href="/restream-user.php?username=${encodeURIComponent(x.username)}">${esc(x.username)}</a>` : '-'}</td>
    <td>${esc(x.kind)}</td>
    <td><span class="tag ${x.severity==='critical'?'bad':(x.severity==='warn'?'warn':'info')}">${esc(x.severity)}</span></td>
    <td>${esc(x.cdn_count)}</td><td>${esc(x.xui_count)}</td><td>${esc(x.max_connections||'-')}</td>
    <td class="muted">${esc(x.probable_cause)}</td><td>${esc(x.occurrences)}</td>
    <td class="muted">${ago(x.opened_epoch)}</td>
  </tr>`).join('') : '<tr><td colspan="9" class="muted">nenhuma divergência aberta</td></tr>';
}

async function tickDirect() {
  if ($('f_pause').checked) return;
  const p = new URLSearchParams({view: 'direct', limit: '100'});
  if ($('dr_mode').value) p.set('mode', $('dr_mode').value);
  if ($('dr_cons').value) p.set('consistency', $('dr_cons').value);
  if ($('dr_host').value.trim()) p.set('host', $('dr_host').value.trim());
  const r = await fetch('/restream-data.php?' + p.toString(), {credentials:'same-origin'});
  if (!r.ok) return;
  const d = await r.json();
  const sm = d.summary || {};
  $('directbox').innerHTML = `sessões direct ativas agora: <strong>${esc(d.active)}</strong> · `
    + `streams direct no XUI: ${esc(sm.streams_db||0)} (host ok: ${esc(sm.streams_parsed||0)}, `
    + `parse com erro: ${esc(sm.parse_errors||0)}) · db_runtime: ${esc(sm.db_runtime||0)} · `
    + `db_only: ${esc(sm.db_only||0)} · runtime_only: ${esc(sm.runtime_only||0)} · `
    + `mismatch: ${esc(sm.mismatch||0)} · hosts efetivos: ${esc(sm.hosts_effective||0)}`;

  const consTag = (c) => c === 'consistent' ? 'ok' : (c === 'mismatch' || c === 'parse_error' ? 'bad' : 'warn');
  const streams = d.streams || [];
  $('dstreambody').innerHTML = streams.length ? streams.map(x => `<tr>
    <td>${esc(x.stream_name || ('#' + x.stream_id))}</td><td class="muted">${esc(x.stream_type||'-')}</td>
    <td><span class="tag info">${esc(x.direct_origin_mode)}</span></td>
    <td class="muted">${esc(x.direct_host_from_db||'-')}</td>
    <td class="muted">${esc(x.direct_host_runtime||'-')}</td>
    <td><strong>${esc(x.direct_host_effective||'-')}</strong></td>
    <td><span class="tag ${consTag(x.direct_consistency)}">${esc(x.direct_consistency)}</span></td>
    <td class="muted">${esc(x.parse_status)}</td>
    <td>${esc(x.runtime_hits)}</td><td>${esc(x.runtime_failures)}</td>
    <td class="muted">${x.runtime_last_epoch ? ago(x.runtime_last_epoch) : '-'}</td>
  </tr>`).join('') : '<tr><td colspan="11" class="muted">nenhum stream direct source no catálogo (rode o job direct_enrich)</td></tr>';

  const hosts = d.top_hosts || [];
  $('directbody').innerHTML = hosts.length ? hosts.map(h => `<tr>
    <td>${esc(h.host)}</td><td><span class="tag info">${esc(h.direct_mode)}</span></td>
    <td>${esc(h.hits)}</td><td>${esc(h.failures)}</td><td>${esc(h.users)}</td><td>${esc(h.streams)}</td>
  </tr>`).join('') : '<tr><td colspan="6" class="muted">nenhum host direct nos últimos 15min</td></tr>';

  const fails = d.failures || [];
  $('dfailbody').innerHTML = fails.length ? fails.map(f => `<tr>
    <td>${esc(f.host||'-')}</td><td><span class="tag bad">${esc(f.failures)}</span></td>
    <td>${esc(f.users)}</td><td>${esc(f.streams)}</td>
    <td class="muted">${esc((f.last_seen||'').replace('T',' ').slice(0,19))}</td>
  </tr>`).join('') : '<tr><td colspan="5" class="muted">nenhuma falha de host final na última hora</td></tr>';

  const users = d.users || [];
  $('duserbody').innerHTML = users.length ? users.map(u => `<tr>
    <td><a href="/restream-user.php?username=${encodeURIComponent(u.username)}">${esc(u.username)}</a></td>
    <td>${esc(u.sessions)}</td><td class="muted">${esc(u.modes||'-')}</td>
    <td class="muted">${esc(u.hosts||'-')}</td><td>${esc(u.failures||0)}</td>
    <td class="muted">${u.last_epoch ? ago(u.last_epoch) : '-'}</td>
  </tr>`).join('') : '<tr><td colspan="6" class="muted">ninguém consumindo direct source agora</td></tr>';

  const divs = d.divergences || [];
  $('ddivbody').innerHTML = divs.length ? divs.map(x => `<tr>
    <td>${esc(x.kind)}</td>
    <td><span class="tag ${x.severity==='critical'?'bad':(x.severity==='warn'?'warn':'info')}">${esc(x.severity)}</span></td>
    <td class="muted">${esc(x.scope||x.username||'-')}</td>
    <td class="muted">${esc(x.probable_cause)}</td><td>${esc(x.occurrences)}</td>
    <td class="muted">${ago(x.opened_epoch)}</td>
  </tr>`).join('') : '<tr><td colspan="6" class="muted">nenhuma divergência de direct source aberta</td></tr>';

  const blocked = d.blocked || [];
  $('blockedbody').innerHTML = blocked.length ? blocked.map(b => `<tr>
    <td class="muted">${esc((b.ts||'').replace('T',' ').slice(11,19))}</td><td>${esc(b.username||'-')}</td>
    <td class="muted">${esc(b.from_host)}</td><td class="muted">${esc(b.to_host)}</td>
    <td class="muted">${esc(b.direct_mode||'runtime')}</td>
    <td><span class="tag bad">${esc(b.outcome)}</span></td>
  </tr>`).join('') : '<tr><td colspan="6" class="muted">nenhum hop bloqueado na última hora</td></tr>';
}

async function tickEvents() {
  if ($('f_pause').checked) return;
  const p = filters(); p.set('view', 'events'); p.set('limit', '80');
  if ($('f_current').checked) p.set('current_only', '1');
  if ($('f_problems').checked) p.set('only_problems', '1');
  const r = await fetch('/restream-data.php?' + p.toString(), {credentials:'same-origin'});
  if (!r.ok) return;
  const d = await r.json();
  const rows = d.events || [];
  $('eventbody').innerHTML = rows.length ? rows.map(e => `<tr>
    <td class="muted">${esc((e.ts||'').replace('T',' ').slice(0,19))}</td>
    <td class="muted">${esc((e.request_id||'').slice(0,12))}</td>
    <td>${esc(e.username||'-')}</td><td>${esc(e.public_host)}</td><td>${esc(e.client_ip)}</td>
    <td class="muted">${esc(e.path)}</td><td>${esc(e.route_kind)}</td>
    <td>${esc(e.status)}</td><td>${fmtBytes(e.bytes)}</td><td>${esc(e.duration_ms)}</td>
    <td>${esc(e.match_confidence)}</td>
    <td>${e.inconsistency ? '<span class="tag bad">'+esc(e.inconsistency)+'</span>' : ''}</td>
  </tr>`).join('') : '<tr><td colspan="12" class="muted">sem eventos</td></tr>';
}

async function tickSync() {
  const r = await fetch('/restream-data.php?view=sync', {credentials:'same-origin'});
  if (!r.ok) return;
  const d = await r.json();
  const c = d.config || {};
  $('syncbox').innerHTML = `driver pdo_mysql: <b>${d.driver_pdo_mysql ? 'ok' : 'AUSENTE (apt-get install php8.1-mysql)'}</b> ·
    ping: <b>${d.ping && d.ping.ok ? 'ok ('+d.ping.ms+'ms)' : 'falhou'}</b> ${esc(d.ping && d.ping.error || '')} ·
    último sync: <b>${esc(c.last_sync_at || 'nunca')}</b> (${esc(c.last_sync_status)}) ${esc(c.last_sync_error||'')} ·
    cache: ${d.cache.users} users / ${d.cache.streams} streams / ${d.cache.sessions} sessões`;
}

function userStatusTag(s) {
  const cls = (s === 'streaming') ? 'ok'
    : (s === 'over_limit' ? 'bad'
    : (s === 'full' || s === 'expired' || s === 'disabled' ? 'warn'
    : (s === 'fetching' ? 'info' : 'muted')));
  return `<span class="tag ${cls}">${esc(s)}</span>`;
}

async function tickUsers() {
  if ($('f_pause').checked) return;
  const p = new URLSearchParams({view: 'users', limit: '300'});
  if ($('u_q').value) p.set('q', $('u_q').value);
  if ($('u_active').checked) p.set('only_active', '1');
  if ($('u_enabled').checked) p.set('enabled_only', '1');
  if ($('u_over').checked) p.set('over', '1');
  const r = await fetch('/restream-data.php?' + p.toString(), {credentials:'same-origin'});
  if (!r.ok) return;
  const d = await r.json();
  const t = d.totals || {};
  $('ukpis').innerHTML = [
    ['Usuários no XUI', t.users_total], ['Habilitados', t.users_enabled],
    ['Online agora', t.users_online], ['Conexões de vídeo', t.connections_video],
    ['Playlist/API abertas', t.sessions_fetch], ['Sessões totais', t.sessions_total],
    ['Sessões no XUI', t.xui_connections], ['Slots vendidos', t.slots_sold],
    ['Ocupação dos slots', (t.slots_used_pct || 0) + '%'], ['Acima do limite', t.over_limit]
  ].map(([k,v]) => `<div class="kpi"><span class="muted">${k}</span><b>${esc(v)}</b></div>`).join('');

  const rows = d.rows || [];
  $('userbody').innerHTML = rows.length ? rows.map(u => `<tr>
    <td><a href="/restream-user.php?username=${encodeURIComponent(u.username)}">${esc(u.username)}</a></td>
    <td><b>${esc(u.connections_used)}</b></td>
    <td>${Number(u.max_connections) > 0 ? esc(u.max_connections) : '∞'}</td>
    <td>${u.connections_free === null ? '∞' : esc(u.connections_free)}</td>
    <td>${Number(u.max_connections) > 0 ? esc(u.usage_pct) + '%' : '-'}</td>
    <td>${esc(u.cdn_connections_now)}</td><td>${esc(u.xui_connections_now)}</td>
    <td>${Number(u.divergence) !== 0 ? '<span class="tag warn">'+esc(u.divergence)+'</span>' : '0'}</td>
    <td>${Number(u.direct_sessions_now) ? '<span class="tag info">'+esc(u.direct_sessions_now)+'</span>' : '-'}</td>
    <td class="muted">${esc(u.fetch_sessions_now)}</td>
    <td>${esc(u.last_host || '-')}</td><td>${esc(u.last_ip || '-')}</td>
    <td class="muted">${esc((u.last_player||'').slice(0,32) || '-')}</td>
    <td class="muted">${Number(u.last_epoch) ? ago(u.last_epoch) : (Number(u.session_epoch) ? ago(u.session_epoch) : '-')}</td>
    <td>${userStatusTag(u.status)}</td>
    <td><a href="/restream-user.php?username=${encodeURIComponent(u.username)}">detalhe</a></td>
  </tr>`).join('') : '<tr><td colspan="16" class="muted">nenhum usuário no filtro atual (rode o sync do XUI se a lista estiver vazia)</td></tr>';
}

function boot() {
  // Polling econômico: nada roda com a aba em segundo plano nem para blocos
  // fora da tela, e cada ciclo só reagenda depois que a resposta chega
  // (sem empilhar requisições quando o servidor está lento).
  function visible(id) {
    const el = document.getElementById(id);
    if (!el) return true;
    const r = el.getBoundingClientRect();
    return r.bottom > -200 && r.top < (window.innerHeight + 200);
  }
  function schedule(fn, ms, anchor) {
    let delay = ms;
    const loop = async () => {
      if (!document.hidden && visible(anchor)) {
        const t0 = Date.now();
        try { await fn(); delay = ms; }
        catch (e) { delay = Math.min(delay * 2, ms * 8); }
        // se o servidor demorar, respeita o tempo de resposta antes de insistir
        delay = Math.max(delay, Math.min(Date.now() - t0, ms * 4));
      }
      setTimeout(loop, delay);
    };
    loop();
  }
  schedule(tickLive, 4000, 'livebody');
  schedule(tickEvents, 8000, 'eventbody');
  schedule(tickSync, 60000, 'syncbox');
  schedule(tickSessions, 5000, 'sessbody');
  schedule(tickDivergences, 10000, 'divbody');
  schedule(tickDirect, 20000, 'directbody');
  schedule(tickUsers, 10000, 'userbody');
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) { tickLive(); tickSessions(); }
  });
  ['f_user','f_host','f_ip','f_player','f_kind','f_status','f_over','f_current','f_problems','s_kind','s_direct','d_sev',
   'dr_mode','dr_cons','dr_host','u_q','u_active','u_enabled','u_over']
    .forEach(id => $(id).addEventListener('change', () => { tickLive(); tickEvents(); tickSessions(); tickDivergences(); tickUsers(); }));
}
boot();
</script>
</body>
</html>

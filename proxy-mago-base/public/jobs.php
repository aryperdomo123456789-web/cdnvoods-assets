<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = csrf_token();
session_write_close();

function jh($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$states = JobRunner::states();
$byName = [];
foreach ($states as $s) { $byName[$s['job_name']] = $s; }
$history = JobRunner::history(60, (string) ($_GET['job'] ?? ''));
$runId = (string) ($_GET['run_id'] ?? '');
$steps = $runId !== '' ? JobRunner::steps($runId) : [];
$db = Database::healthSnapshot();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — Jobs internos</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        table{width:100%;font-size:13px}td,th{padding:5px 6px;vertical-align:top}
        .muted{opacity:.65}.tag{padding:1px 6px;border-radius:4px;font-size:11px}
        .ok{background:#064e3b}.warn{background:#78350f}.bad{background:#7f1d1d}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>Jobs internos e auditoria</span></div>
    <nav>
        <a href="/auditoria.php">Auditoria</a>
        <a href="/restream.php">Ao vivo</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/lb.php">LB</a>
        <a href="/avancado.php">Avançado</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <?php if ($flash): ?><section class="card full"><div class="alert success"><?php echo jh($flash); ?></div></section><?php endif; ?>

    <section class="card full">
        <h2>Saúde do cérebro</h2>
        <p class="muted">SQLite <code><?php echo jh($db['journal_mode']); ?></code>,
           espera de lock <?php echo (int) $db['busy_timeout_ms']; ?>ms,
           WAL <?php echo round(((int) $db['wal_bytes']) / 1048576, 1); ?>MB,
           banco <?php echo round(((int) $db['db_bytes']) / 1048576, 1); ?>MB,
           retries de lock neste request: <?php echo (int) $db['lock_retries']; ?>.
           Um job só roda uma vez por vez (lock em arquivo) e falhas repetidas abrem o disjuntor.</p>
    </section>

    <section class="card full">
        <h2>Catálogo de rotinas</h2>
        <p class="muted">Tudo que roda por dentro está listado aqui: para que serve, de quanto em quanto tempo,
           quando rodou pela última vez, quanto demorou e se falhou. Nada roda escondido.</p>
        <table>
            <thead><tr>
                <th>Job</th><th>Para que serve</th><th>Intervalo</th><th>Último run</th>
                <th>Status</th><th>Passo atual</th><th>Duração</th><th>Pico</th><th>Proc.</th>
                <th>Falhas seq.</th><th>Atraso</th><th>Pulados</th><th>Erro</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach (JobRunner::CATALOG as $name => [$purpose, $interval]):
                $s = $byName[$name] ?? [];
                $status = (string) ($s['last_status'] ?? 'never');
                $open = !empty($s['circuit_open']);
                $cls = $open ? 'bad' : ($status === 'ok' ? 'ok' : ($status === 'error' ? 'bad' : 'warn'));
                $label = $open ? 'circuito aberto' : ((int) ($s['running'] ?? 0) === 1 ? 'rodando' : $status);
            ?>
                <tr>
                    <td><a href="?job=<?php echo urlencode($name); ?>"><?php echo jh($name); ?></a></td>
                    <td class="muted"><?php echo jh($purpose); ?></td>
                    <td><?php echo (int) $interval; ?>s</td>
                    <td class="muted"><?php echo jh(substr((string) ($s['last_run_at'] ?? ''), 0, 19) ?: 'nunca'); ?></td>
                    <td><span class="tag <?php echo $cls; ?>"><?php echo jh($label); ?></span></td>
                    <td class="muted"><?php echo jh($s['current_step'] ?? ''); ?></td>
                    <td><?php echo (int) ($s['last_duration_ms'] ?? 0); ?>ms</td>
                    <td class="muted"><?php echo (int) ($s['max_duration_ms'] ?? 0); ?>ms</td>
                    <td><?php echo (int) ($s['last_processed'] ?? 0); ?></td>
                    <td><?php echo (int) ($s['consecutive_failures'] ?? 0); ?></td>
                    <td class="muted"><?php echo (int) ($s['late_seconds'] ?? 0); ?>s</td>
                    <td class="muted"><?php echo (int) ($s['skipped_runs'] ?? 0); ?></td>
                    <td class="muted"><?php echo jh(substr((string) ($open ? ($s['circuit_reason'] ?? '') : ($s['last_error'] ?? '')), 0, 120)); ?></td>
                    <td>
                        <form method="post" action="/run-job.php">
                            <input type="hidden" name="csrf_token" value="<?php echo jh($csrf); ?>">
                            <input type="hidden" name="job" value="<?php echo jh($name); ?>">
                            <input type="hidden" name="back" value="jobs">
                            <button type="submit">Rodar</button>
                        </form>
                        <?php if ($open): ?>
                        <form method="post" action="/run-job.php">
                            <input type="hidden" name="csrf_token" value="<?php echo jh($csrf); ?>">
                            <input type="hidden" name="job" value="<?php echo jh($name); ?>">
                            <input type="hidden" name="action" value="reset_circuit">
                            <input type="hidden" name="back" value="jobs">
                            <button type="submit">Fechar disjuntor</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card full">
        <h2>Histórico de execuções <?php echo !empty($_GET['job']) ? '— ' . jh((string) $_GET['job']) : ''; ?></h2>
        <?php if (!empty($_GET['job'])): ?><p><a href="/jobs.php">ver todos</a></p><?php endif; ?>
        <table>
            <thead><tr><th>Início</th><th>Job</th><th>Origem</th><th>Status</th><th>Duração</th><th>Proc.</th><th>Falhas</th><th>run_id</th><th>Passos</th><th>Erro</th></tr></thead>
            <tbody>
            <?php if (!$history): ?><tr><td colspan="10" class="muted">nenhuma execução registrada ainda</td></tr><?php endif; ?>
            <?php foreach ($history as $r):
                $cls = $r['status'] === 'ok' ? 'ok' : ($r['status'] === 'error' ? 'bad' : 'warn'); ?>
                <tr>
                    <td class="muted"><?php echo jh(substr((string) $r['started_at'], 0, 19)); ?></td>
                    <td><?php echo jh($r['job_name']); ?></td>
                    <td class="muted"><?php echo jh($r['trigger_source']); ?></td>
                    <td><span class="tag <?php echo $cls; ?>"><?php echo jh($r['status']); ?></span></td>
                    <td><?php echo (int) $r['duration_ms']; ?>ms</td>
                    <td><?php echo (int) $r['processed']; ?></td>
                    <td><?php echo (int) $r['failed']; ?></td>
                    <td class="muted"><?php echo jh($r['run_id']); ?></td>
                    <td><a href="?job=<?php echo urlencode((string) $r['job_name']); ?>&amp;run_id=<?php echo urlencode((string) $r['run_id']); ?>">passos</a></td>
                    <td class="muted"><?php echo jh(substr((string) $r['error'], 0, 140)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ($runId !== ''): ?>
    <section class="card full">
        <h2>Passos da execução <code><?php echo jh($runId); ?></code></h2>
        <table>
            <thead><tr><th>Quando</th><th>Passo</th><th>Detalhe</th><th>Duração</th></tr></thead>
            <tbody>
            <?php if (!$steps): ?><tr><td colspan="4" class="muted">esta execução não registrou passos</td></tr><?php endif; ?>
            <?php foreach ($steps as $st): ?>
                <tr>
                    <td class="muted"><?php echo jh(date('d/m H:i:s', (int) $st['ts_epoch'])); ?></td>
                    <td><?php echo jh($st['step']); ?></td>
                    <td class="muted"><?php echo jh($st['detail']); ?></td>
                    <td><?php echo (int) $st['duration_ms']; ?>ms</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <section class="card full">
        <h2>Agendamento na VPS</h2>
        <p class="muted">Na VPS <code>45.140.192.237</code>, path <code>/opt/proxy-mago/proxy-mago-base</code>:</p>
        <pre>* * * * * www-data /usr/bin/php /opt/proxy-mago/proxy-mago-base/bin/jobs-run.php >> /opt/proxy-mago/proxy-mago-base/storage/logs/jobs.log 2>&amp;1</pre>
        <p class="muted">O tick roda ~55s por execução e respeita o intervalo de cada job, dando granularidade de 5s
           para sessões ativas usando apenas cron de 1 minuto.</p>
    </section>
</main>
</body>
</html>

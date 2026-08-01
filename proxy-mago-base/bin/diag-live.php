<?php

declare(strict_types=1);

/**
 * Diagnóstico read-only de "painel mostrando zero / dado atrasado".
 * Uso: php bin/diag-live.php
 */

require_once dirname(__DIR__) . '/app/bootstrap-cli.php';

$root = dirname(__DIR__);
$now = time();
$problems = [];
$line = static function (string $k, string $v): void {
    printf("%-28s %s\n", $k, $v);
};

echo "== 1. Jobs (cron do painel)\n";
$late = [];
foreach (JobRunner::states() as $s) {
    $lateS = (int) ($s['late_seconds'] ?? 0);
    if ($lateS > 120 || (string) $s['last_status'] === 'never') {
        $late[] = $s['job_name'] . ' (' . ($s['last_status'] === 'never' ? 'nunca rodou' : $lateS . 's atrasado') . ')';
    }
}
$line('jobs atrasados', $late ? implode(', ', $late) : 'nenhum');
if ($late) {
    $problems[] = 'jobs atrasados/nunca executados: o tick de cron não está rodando como www-data (ver /etc/cron.d/proxy-mago-jobs)';
}

$cron = '/etc/cron.d/proxy-mago-jobs';
$line('arquivo de cron', is_file($cron) ? 'presente' : 'AUSENTE (' . $cron . ')');
if (!is_file($cron)) {
    $problems[] = 'cron ausente: rode sudo bash bin/deploy.sh (ou recrie /etc/cron.d/proxy-mago-jobs)';
}
foreach (['jobs-fast.log', 'jobs-heavy.log'] as $log) {
    $p = $root . '/storage/logs/' . $log;
    $line($log, is_file($p) ? (int) (($now - filemtime($p)) / 1) . 's desde a última escrita' : 'inexistente');
    if (is_file($p) && ($now - filemtime($p)) > 300) {
        $problems[] = $log . ' parado há ' . ($now - filemtime($p)) . 's — o cron não está executando';
    }
}

echo "\n== 2. Contabilização de sessões\n";
$line('cdn_sessions_enabled', CdnSession::enabled() ? 'ligado' : 'DESLIGADO');
if (!CdnSession::enabled()) {
    $problems[] = 'cdn_sessions_enabled=0: a CDN não registra conexão nenhuma, o painel fica zerado por definição';
}
$line('conta loopback', CdnSession::countsLoopback() ? 'sim' : 'não (127.0.0.1 é ignorado)');
$pdo = Database::pdo();
$total = (int) $pdo->query('SELECT COUNT(*) c FROM cdn_sessions')->fetch()['c'];
$active = (int) $pdo->query('SELECT COUNT(*) c FROM cdn_sessions WHERE ' . CdnSession::activeWhereSql($now))->fetch()['c'];
$line('cdn_sessions (total)', (string) $total);
$line('cdn_sessions (ativas)', (string) $active);
if ($total === 0) {
    $problems[] = 'zero sessões gravadas: nenhum player passou pela CDN ainda (DNS/domínio ainda aponta direto no XUI, ou os clientes usam URL antiga)';
}

echo "\n== 3. Tráfego que chegou na CDN\n";
foreach ([300 => '5min', 3600 => '1h', 86400 => '24h'] as $win => $label) {
    $st = $pdo->prepare('SELECT COUNT(*) c FROM proxy_request_events WHERE ts_epoch >= :t');
    $st->execute([':t' => $now - $win]);
    $line('requests ' . $label, (string) (int) $st->fetch()['c']);
}

echo "\n== 4. Modo de entrega / LB\n";
$line('lb_require_delivery', SettingsRepository::get('lb_require_delivery', 0) ? 'ligado (main não entrega)' : 'desligado');
$line('lb_default_mode', (string) SettingsRepository::get('lb_default_mode', 'off'));
$nodes = LbNode::all();
$okNodes = array_filter($nodes, static fn ($n) => (int) $n['enabled'] === 1 && (string) $n['health_status'] === 'ok');
$line('LBs cadastrados', count($nodes) . ' (saudáveis: ' . count($okNodes) . ')');
if (SettingsRepository::get('lb_require_delivery', 0) && !$okNodes) {
    $problems[] = 'lb_require_delivery=1 sem LB saudável: nenhuma entrega acontece e o painel nunca vê sessão';
}
if ($nodes && !$okNodes) {
    $problems[] = 'nenhum LB com health=ok: rode o job lb_probe e confira /__lb_health no músculo';
}

echo "\n== 5. Espelho do XUI\n";
foreach (['xui_users_cache', 'xui_streams_cache', 'xui_series_cache', 'xui_episodes_cache'] as $t) {
    if (!Database::tableExists($t)) { $line($t, 'tabela ausente (migração pendente)'); $problems[] = $t . ' ausente: schema não migrou'; continue; }
    $line($t, (string) (int) $pdo->query('SELECT COUNT(*) c FROM ' . $t)->fetch()['c'] . ' linhas');
}

echo "\n== 6. Veredito\n";
if (!$problems) {
    echo "nenhuma causa óbvia encontrada; leia storage/logs/php-error.log e jobs-fast.log\n";
    exit(0);
}
foreach ($problems as $i => $p) {
    echo '  ' . ($i + 1) . ') ' . $p . "\n";
}
exit(1);

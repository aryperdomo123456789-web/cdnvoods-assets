<?php
/**
 * Smoke do uptime real por sessão (S2-P0-3).
 *
 * Prova que o uptime representa uso real:
 *   - burst de requests não reinicia o uptime
 *   - pausa curta (buffer/reconexão) mantém o uptime
 *   - abandono longo abre uptime novo
 *   - troca de conteúdo abre sessão nova (e mata a anterior por supersede)
 *   - direct source longo mantém o uptime desde o primeiro redirect
 */
require __DIR__ . '/../app/bootstrap-cli.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [ok]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label\n"; }
}

$user = 'smoke_uptime_user';
$pdo = Database::pdo();
$clean = static function () use ($pdo, $user): void {
    $pdo->prepare('DELETE FROM cdn_sessions WHERE username = :u')->execute([':u' => $user]);
};
$clean();

$touch = static function (string $path, string $ip = '203.0.113.41', string $ua = 'SmokeTV/1.0'): string {
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    $key = CdnSession::touch(RequestContext::build('smoke.local', $ip, $path, []));
    CdnSession::record($key, 200, 2048);
    return $key;
};
$row = static function (string $key) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM cdn_sessions WHERE session_key = :k');
    $st->execute([':k' => $key]);
    return $st->fetch() ?: [];
};
/** Simula tempo passado sem depender de sleep real. */
$rewind = static function (string $key, int $seconds, string $status = 'active') use ($pdo): void {
    $pdo->prepare(
        'UPDATE cdn_sessions
            SET last_seen_epoch = last_seen_epoch - :s,
                uptime_start_epoch = CASE WHEN uptime_start_epoch > 0 THEN uptime_start_epoch - :s ELSE 0 END,
                started_epoch = started_epoch - :s,
                direct_first_epoch = CASE WHEN direct_first_epoch > 0 THEN direct_first_epoch - :s ELSE 0 END,
                status = :st,
                active_requests = 0
          WHERE session_key = :k'
    )->execute([':s' => $seconds, ':st' => $status, ':k' => $key]);
};

echo "\n== 1. burst de requests mantém o mesmo uptime\n";
$k = $touch("/movie/$user/pass/7001.mp4");
$first = (int) $row($k)['uptime_start_epoch'];
for ($i = 0; $i < 5; $i++) { $touch("/movie/$user/pass/7001.mp4"); }
$r = $row($k);
check('mesma sessão para o burst', (int) $r['requests'] >= 6);
check('uptime não reiniciou no burst', (int) $r['uptime_start_epoch'] === $first);

echo "\n== 2. pausa curta de VOD (buffer/reconexão) mantém o uptime\n";
$rewind($k, 900, 'closed');           // 15 min pausado, dentro da graça de 30 min
$expected = (int) $row($k)['uptime_start_epoch'];
$touch("/movie/$user/pass/7001.mp4"); // player voltou
$r = $row($k);
check('sessão voltou para active', $r['status'] === 'active');
check('uptime continuou de onde estava', (int) $r['uptime_start_epoch'] === $expected);
check('uptime já acumula os 15 min', (time() - (int) $r['uptime_start_epoch']) >= 900);

echo "\n== 3. abandono longo abre uptime novo\n";
$rewind($k, 7200, 'closed');          // 2h fora, acima da graça
$touch("/movie/$user/pass/7001.mp4");
$r = $row($k);
check('uptime reiniciou depois do abandono', (time() - (int) $r['uptime_start_epoch']) < 60);

echo "\n== 4. live: pausa curta não reseta, zapping longo reseta\n";
$kl = $touch("/live/$user/pass/8001.ts");
$rewind($kl, 90, 'closed');           // 90s, dentro da graça de 120s do live
$expLive = (int) $row($kl)['uptime_start_epoch'];
$touch("/live/$user/pass/8001.ts");
check('live mantém uptime em pausa curta', (int) $row($kl)['uptime_start_epoch'] === $expLive);
$rewind($kl, 1800, 'closed');
$touch("/live/$user/pass/8001.ts");
check('live reinicia após ficar meia hora fora', (time() - (int) $row($kl)['uptime_start_epoch']) < 60);

echo "\n== 5. troca de conteúdo: sessão nova, anterior superseded\n";
$k2 = $touch("/movie/$user/pass/7002.mp4");
check('nova session_key para o novo filme', $k2 !== $k);
$prev = $row($k);
check('sessão anterior encerrada como superseded', ($prev['close_reason'] ?? '') === 'superseded');
check('uptime do novo conteúdo começa agora', (time() - (int) $row($k2)['uptime_start_epoch']) < 60);

echo "\n== 6. direct source longo mantém o uptime desde o primeiro redirect\n";
$k3 = $touch("/movie/$user/pass/7003.mp4");
CdnSession::record($k3, 200, 4096, 'cdn-direct-host.example');
$before = (int) $row($k3)['uptime_start_epoch'];
$rewind($k3, 5400, 'active');         // 1h30 de filme em direct
CdnSession::heartbeat($k3, 'cdn-direct-host.example');
$r = $row($k3);
check('direct host registrado na sessão', ($r['direct_host_effective'] ?? '') !== '');
check('uptime de direct não resetou em 1h30', (int) $r['uptime_start_epoch'] < $before);
check('uptime reflete o tempo real de filme', (time() - (int) $r['uptime_start_epoch']) >= 5400);

$clean();
printf("\nresultado: %d ok / %d falhas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);

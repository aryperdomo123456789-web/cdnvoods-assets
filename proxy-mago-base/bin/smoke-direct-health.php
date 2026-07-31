<?php
require_once dirname(__DIR__) . '/app/bootstrap-cli.php';

$ok = 0; $fail = 0;
$check = static function (string $name, bool $cond, string $got = '') use (&$ok, &$fail): void {
    if ($cond) { $ok++; printf("  ok   %s\n", $name); }
    else { $fail++; printf("  FAIL %s %s\n", $name, $got); }
};

$pdo = Database::pdo();
$tag = 'smoke-dh-' . bin2hex(random_bytes(4));
$now = time();
$ins = $pdo->prepare(
    'INSERT INTO direct_source_hops
       (request_id, session_key, username, hop_no, from_host, to_host, off_origin,
        outcome, status, ts, ts_epoch, stream_id, final_host)
     VALUES (:rid, :sk, :u, 1, "origin.local", :to, 1, :out, :st, :ts, :tse, :sid, :fh)'
);
$add = static function (string $host, string $outcome, int $status, int $n, int $sid) use ($ins, $tag, $now): void {
    for ($i = 0; $i < $n; $i++) {
        $ins->execute([
            ':rid' => $tag . '-' . $host . '-' . $i, ':sk' => $tag, ':u' => 'smokeuser',
            ':to' => $host, ':out' => $outcome, ':st' => $status,
            ':ts' => date('c', $now - $i), ':tse' => $now - $i, ':sid' => $sid, ':fh' => $host,
        ]);
    }
};

$hGood = $tag . '.entrega.test';
$hBlock = $tag . '.barra.test';
$hDead = $tag . '.semresposta.test';
$hStale = $tag . '.catalogovelho.test';

$add($hGood, 'followed', 200, 10, 900001);
$add($hBlock, 'blocked', 403, 8, 900002);
$add($hDead, 'error', 0, 8, 900003);
$add($hStale, 'error', 404, 8, 900004);

Cache::flush();
$rows = [];
foreach (DirectHostHealth::hosts(60, 200) as $r) { $rows[(string) $r['host']] = $r; }

echo "== veredito por host final ==\n";
$check('host que entrega => ok', ($rows[$hGood]['verdict'] ?? '') === 'ok', (string) ($rows[$hGood]['verdict'] ?? 'ausente'));
$check('host 403 => blocked', ($rows[$hBlock]['verdict'] ?? '') === 'blocked', (string) ($rows[$hBlock]['verdict'] ?? 'ausente'));
$check('host 403 => culpa host_final', ($rows[$hBlock]['blame'] ?? '') === 'host_final');
$check('host sem resposta => unreachable', ($rows[$hDead]['verdict'] ?? '') === 'unreachable', (string) ($rows[$hDead]['verdict'] ?? 'ausente'));
$check('host 404 => catalog_stale', ($rows[$hStale]['verdict'] ?? '') === 'catalog_stale', (string) ($rows[$hStale]['verdict'] ?? 'ausente'));
$check('host 404 => culpa catalogo_api', ($rows[$hStale]['blame'] ?? '') === 'catalogo_api');

$sum = DirectHostHealth::summary(60);
$check('resumo conta blocked >= 1', (int) ($sum['blocked'] ?? 0) >= 1);

$tri = DirectHostHealth::triageStream(900002);
$check('triagem de stream aponta culpa', in_array((string) $tri['blame'], ['host_final', 'catalogo_api', 'sessao'], true), (string) $tri['blame']);

$check('nenhum segredo de origem no veredito',
    strpos(json_encode($rows), 'password') === false && strpos(json_encode($rows), 'auth_pass') === false);

$pdo->prepare('DELETE FROM direct_source_hops WHERE session_key = :sk')->execute([':sk' => $tag]);
Cache::flush();

printf("\n== resultado: %d ok / %d falhas ==\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);

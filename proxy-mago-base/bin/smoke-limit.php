<?php
/**
 * Smoke do enforcement de limite pela CDN (S2-P0-2).
 *
 * Prova, sem tráfego de cliente real:
 *   - usuário de 1 conexão abrindo 2 telas => bloqueia depois da tolerância
 *   - playlist/API nunca é bloqueada (baixar m3u não ocupa slot do plano)
 *   - fechar uma tela devolve o acesso e limpa o estado
 *   - modo `alert` não bloqueia ninguém (padrão seguro de produção)
 *   - o estouro fica visível no painel (divergência `above_limit`)
 */
require __DIR__ . '/../app/bootstrap-cli.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [ok]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label\n"; }
}

$user = 'smoke_limit_user';
$pass = 'smoke_limit_pass';
$pdo = Database::pdo();

$prevMode = SettingsRepository::get('limit_mode', 'alert');
$prevTol = SettingsRepository::get('limit_tolerance_seconds', 45);
$prevLoopback = (string) SettingsRepository::get('lab_count_loopback', '');

$cleanup = static function () use ($pdo, $user, $prevMode, $prevTol, $prevLoopback): void {
    $pdo->prepare('DELETE FROM xui_users_cache WHERE username = :u')->execute([':u' => $user]);
    $pdo->prepare('DELETE FROM cdn_sessions WHERE username = :u')->execute([':u' => $user]);
    $pdo->prepare('DELETE FROM user_limit_state WHERE username = :u')->execute([':u' => $user]);
    $pdo->prepare('DELETE FROM cdn_divergences WHERE username = :u')->execute([':u' => $user]);
    SettingsRepository::set('limit_mode', $prevMode);
    SettingsRepository::set('limit_tolerance_seconds', $prevTol);
    if ($prevLoopback === '') {
        $pdo->prepare('DELETE FROM settings WHERE key = :k')->execute([':k' => 'lab_count_loopback']);
    }
    Cache::flush();
};
$cleanup();

// Usuário espelhado do XUI com plano de 1 conexão.
$pdo->prepare(
    'INSERT INTO xui_users_cache (username, password, user_id, max_connections, enabled, exp_date, synced_at, synced_epoch)
     VALUES (:u,:p,999001,1,1,0,:at,:ae)
     ON CONFLICT(username) DO UPDATE SET max_connections=1, enabled=1, password=excluded.password'
)->execute([':u' => $user, ':p' => $pass, ':at' => date('c'), ':ae' => time()]);

$touch = static function (string $ip, int $streamId, string $path, string $ua): string {
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    $ctx = RequestContext::build('smoke.local', $ip, $path, []);
    $key = CdnSession::touch($ctx);
    CdnSession::record($key, 200, 4096);
    return $key;
};

echo "\n== 1. modo alert (padrão) nunca bloqueia\n";
SettingsRepository::set('limit_mode', 'alert');
SettingsRepository::set('limit_tolerance_seconds', 0);
Cache::flush();
$k1 = $touch('203.0.113.31', 5001, "/live/$user/$pass/5001.ts", 'SmokeTV/1.0');
$k2 = $touch('203.0.113.32', 5002, "/live/$user/$pass/5002.ts", 'SmokeTV/2.0');
check('2 telas abertas contam 2 conexões', CdnSession::activeCount($user) === 2);
check('modo alert não bloqueia', !Divergence::shouldBlock($user, 'live'));

echo "\n== 2. modo block: segunda tela é recusada pela CDN\n";
SettingsRepository::set('limit_mode', 'block');
Cache::flush();
check('acima do limite => bloqueia', Divergence::shouldBlock($user, 'live'));
$st = $pdo->prepare('SELECT over_limit_since_epoch FROM user_limit_state WHERE username = :u');
$st->execute([':u' => $user]);
check('estado de estouro registrado', (int) ($st->fetchColumn() ?: 0) > 0);

echo "\n== 3. playlist/API não ocupa slot do plano\n";
check('playlist liberada mesmo acima do limite', !Divergence::shouldBlock($user, 'playlist'));
check('api liberada mesmo acima do limite', !Divergence::shouldBlock($user, 'api'));

echo "\n== 4. tolerância de reconexão protege o cliente honesto\n";
SettingsRepository::set('limit_tolerance_seconds', 600);
$pdo->prepare('DELETE FROM user_limit_state WHERE username = :u')->execute([':u' => $user]);
Cache::flush();
check('dentro da tolerância ainda passa', !Divergence::shouldBlock($user, 'live'));
SettingsRepository::set('limit_tolerance_seconds', 0);
Cache::flush();

echo "\n== 5. fechar uma tela devolve o acesso\n";
CdnSession::reject($k2, 'smoke_closed');
Cache::flush();
check('voltou para 1 conexão', CdnSession::activeCount($user) === 1);
check('dentro do limite não bloqueia', !Divergence::shouldBlock($user, 'live'));
$st->execute([':u' => $user]);
check('estado de estouro foi limpo', (int) ($st->fetchColumn() ?: 0) === 0);

echo "\n== 6. estouro visível no painel (divergência above_limit)\n";
Divergence::raise($user, 'above_limit', 'warning', 'smoke: 2 de 1 conexão', ['cdn' => 2, 'xui' => 0, 'max' => 1]);
$open = array_filter(Divergence::open([], 200), static fn (array $d): bool => $d['username'] === $user && $d['kind'] === 'above_limit');
check('divergência above_limit aberta para o painel', $open !== []);

echo "\n== 7. usuário sem plano espelhado não é punido\n";
$pdo->prepare('UPDATE xui_users_cache SET max_connections = 0 WHERE username = :u')->execute([':u' => $user]);
Cache::flush();
check('max_connections=0 => sem bloqueio', !Divergence::shouldBlock($user, 'live'));

$cleanup();
printf("\nresultado: %d ok / %d falhas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);

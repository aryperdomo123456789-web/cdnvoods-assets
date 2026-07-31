<?php
/** Smoke da trava CDN por IP — regras, bloqueio, liberação e fail-open. */
require __DIR__ . '/../app/bootstrap-cli.php';

$ok = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [ok]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label\n"; }
}

$user = 'smoke_ip_lock_user';

echo "\n== 1. usuário sem trava passa (fail-open proposital)\n";
Database::pdo()->prepare('DELETE FROM cdn_user_ip_lock WHERE username = :u')->execute([':u' => $user]);
$v = UserIpLock::explain($user, '8.8.8.8');
check('sem trava = liberado', $v['allowed'] && !$v['active'] && $v['reason'] === 'no_lock');

echo "\n== 2. regras válidas aceitas e inválidas recusadas\n";
$res = UserIpLock::save($user, "45.140.192.237\n45.140.192.0/24\n10.0.0.10-10.0.0.20\n143.14.*\n2001:db8::/32\n999.1.1.1\n10.*.5.*\nnao-e-ip\n1.2.3.4/99", 'smoke');
check('5 regras válidas', count($res['valid']) === 5);
check('4 regras recusadas', count($res['invalid']) === 4);
check('trava ativa para o usuário', UserIpLock::enabledFor($user));

echo "\n== 3. IP exato\n";
check('exato permitido', UserIpLock::matches($user, '45.140.192.237'));
check('IP fora de tudo bloqueado', !UserIpLock::matches($user, '203.0.113.9'));

echo "\n== 4. CIDR IPv4\n";
check('dentro do /24', UserIpLock::matches($user, '45.140.192.9'));
check('fora do /24', !UserIpLock::matches($user, '45.140.193.9'));

echo "\n== 5. faixa IPv4\n";
check('inicio da faixa', UserIpLock::matches($user, '10.0.0.10'));
check('meio da faixa', UserIpLock::matches($user, '10.0.0.15'));
check('fim da faixa', UserIpLock::matches($user, '10.0.0.20'));
check('acima da faixa', !UserIpLock::matches($user, '10.0.0.21'));

echo "\n== 6. curinga por octeto\n";
check('143.14.* pega 143.14.168.78', UserIpLock::matches($user, '143.14.168.78'));
check('143.14.* nao pega 143.140.1.1', !UserIpLock::matches($user, '143.140.1.1'));

echo "\n== 7. IPv6 em CIDR\n";
check('dentro do 2001:db8::/32', UserIpLock::matches($user, '2001:db8:1234::99'));
check('fora do 2001:db8::/32', !UserIpLock::matches($user, '2001:dead::1'));

echo "\n== 8. bordas perigosas\n";
UserIpLock::save($user, '0.0.0.0/0');
check('/0 libera qualquer IPv4', UserIpLock::matches($user, '198.51.100.7'));
check('/0 nao libera IPv6', !UserIpLock::matches($user, '2001:db8::1'));
UserIpLock::save($user, '45.140.192.0/24');
$bad = UserIpLock::explain($user, 'nao-e-ip');
check('IP de cliente corrompido bloqueia', !$bad['allowed'] && $bad['reason'] === 'client_ip_invalid');
$deny = UserIpLock::explain($user, '1.1.1.1');
check('veredito explicavel no bloqueio', $deny['active'] && $deny['reason'] === 'no_rule_match' && $deny['rules'] === 1);
$allow = UserIpLock::explain($user, '45.140.192.55');
check('veredito informa a regra que liberou', $allow['rule'] === '45.140.192.0/24');

echo "\n== 9. lista vazia destrava o usuário\n";
UserIpLock::save($user, '');
check('sem regra volta a liberar', UserIpLock::matches($user, '1.1.1.1') && !UserIpLock::enabledFor($user));

Database::pdo()->prepare('DELETE FROM cdn_user_ip_lock WHERE username = :u')->execute([':u' => $user]);
printf("\nresultado: %d ok / %d falhas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);

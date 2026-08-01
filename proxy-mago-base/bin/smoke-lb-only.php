<?php

declare(strict_types=1);

/**
 * SMOKE do cérebro puro (lb_require_delivery) + rota padrão automática.
 *
 * Prova, sem HTTP, que:
 *   1) usuário sem rota respeita `lb_default_mode`;
 *   2) em `auto`, o caminho quente usa o melhor músculo PUBLICADO pelo job
 *      (nenhum score, nenhuma telemetria, nenhum SSH no stream);
 *   3) sem músculo apto, a decisão cai no cérebro com motivo rastreável;
 *   4) o contrato v1 exporta as duas flags para o músculo.
 *
 * Uso: php bin/smoke-lb-only.php
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/LbContract.php';

$fail = 0;
$ok = 0;

function check(string $name, bool $cond, string $extra = ''): void
{
    global $fail, $ok;
    if ($cond) {
        $ok++;
        echo "  ok   {$name}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$name}" . ($extra !== '' ? " -> {$extra}" : '') . "\n";
}

// Estado anterior — este smoke NUNCA pode deixar produção em cérebro puro.
$prev = [
    'lb_default_mode' => SettingsRepository::get('lb_default_mode', 'main_only'),
    'lb_require_delivery' => SettingsRepository::get('lb_require_delivery', '0'),
];
$restore = static function () use ($prev): void {
    SettingsRepository::set('lb_default_mode', (string) $prev['lb_default_mode']);
    SettingsRepository::set('lb_require_delivery', (string) (int) $prev['lb_require_delivery']);
    LbRouter::publishBest(null);
};
register_shutdown_function($restore);

$user = 'smoke_lbonly_' . bin2hex(random_bytes(3));

echo "\n== compatibilidade PHP 8.1 (produção) ==\n";
foreach (['app/StateStore.php', 'app/LbRouter.php', 'app/LbContract.php', 'public/proxy.php', 'public/save-scale.php'] as $rel) {
    $src = (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
    check($rel . ': sem tipo autônomo null/true/false (8.2+)', !preg_match("/function\s+\w+\s*\([^)]*\)\s*:\s*(null|true|false)\s*[{;]/", $src));
}

echo "\n== rota padrão ==\n";
SettingsRepository::set('lb_default_mode', 'main_only');
SettingsRepository::set('lb_require_delivery', '0');
check('default mode = main_only', LbRouter::defaultMode() === 'main_only');
check('cérebro puro desligado', LbRouter::requireDelivery() === false);

$d = LbRouter::decide($user, 'smoke');
check('usuário sem rota fica no cérebro', $d['target'] === 'main', json_encode($d));

SettingsRepository::set('lb_default_mode', 'auto');
LbRouter::publishBest(null);
$d = LbRouter::decide($user, 'smoke');
check('auto sem músculo publicado cai no cérebro', $d['target'] === 'main' && $d['reason'] === 'sem_lb_apto_cerebro', json_encode($d));

LbRouter::publishBest(['id' => 4242, 'public_ip' => '203.0.113.10'], 987.5);
$best = LbRouter::bestPinned();
check('melhor músculo publicado é lido de volta', $best !== null && $best['id'] === 4242 && $best['host'] === '203.0.113.10', json_encode($best));

$d = LbRouter::decide($user, 'smoke');
check('auto usa o músculo publicado', $d['target'] === 'lb' && $d['lb_id'] === 4242 && $d['reason'] === 'auto_default', json_encode($d));

LbRouter::publishBest(null);
check('publishBest(null) limpa o pino', LbRouter::bestPinned() === null);

echo "\n== cérebro puro ==\n";
SettingsRepository::set('lb_require_delivery', '1');
check('flag de entrega obrigatória lida', LbRouter::requireDelivery() === true);
$d = LbRouter::decide($user, 'smoke');
check('sem músculo, decisão é main com motivo rastreável', $d['target'] === 'main' && $d['reason'] !== '', json_encode($d));
$totals = LbRouter::totals();
check('totais expõem require_delivery', ($totals['require_delivery'] ?? null) === true, json_encode($totals['require_delivery'] ?? null));
check('totais expõem default_mode', ($totals['default_mode'] ?? '') === 'auto');

echo "\n== contrato v1 ==\n";
$snap = LbContract::snapshot(['id' => 1, 'label' => 'LB-smoke', 'public_ip' => '203.0.113.10']);
check('runtime traz lb_require_delivery', ($snap['runtime']['lb_require_delivery'] ?? null) === true);
check('runtime traz lb_default_mode', ($snap['runtime']['lb_default_mode'] ?? '') === 'auto');
check('snapshot é serializável', json_encode($snap) !== false);

echo "\n== rota materializada (job lb_autoroute) ==\n";
$stats = ['processed' => 0, 'failed' => 0, 'details' => []];
SettingsRepository::set('lb_default_mode', 'main_only');
LbRouter::autoroute($stats);
check('autoroute não age em main_only', isset($stats['details']['skipped']), json_encode($stats['details']));

SettingsRepository::set('lb_default_mode', 'auto');
$stats = ['processed' => 0, 'failed' => 0, 'details' => []];
LbRouter::autoroute($stats);
check('autoroute roda em auto sem erro', ($stats['details']['mode'] ?? '') === 'auto', json_encode($stats['details']));
$stats2 = ['processed' => 0, 'failed' => 0, 'details' => []];
LbRouter::autoroute($stats2);
check('autoroute é idempotente', (int) ($stats2['details']['criadas'] ?? -1) === 0, json_encode($stats2['details']));

LbRouter::remove($user);
$restore();

echo "\n=== RESULTADO: {$ok} ok / {$fail} falhas ===\n";
exit($fail === 0 ? 0 : 1);
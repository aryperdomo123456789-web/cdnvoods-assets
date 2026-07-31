<?php
/**
 * Prova a blindagem contra cache stampede no Cache::remember().
 *
 * Cenario real: painel em polling com o rollup atrasado => varios processos
 * vencem o TTL no MESMO segundo e todos disparam a contagem pesada, gerando
 * "database is locked". Aqui provamos: 1 produtor por janela, valor vencido
 * servido dentro da janela de graca, e nunca resposta vazia.
 */
require __DIR__ . '/../app/bootstrap-cli.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "[OK]   $label\n"; }
    else { $fail++; echo "[FAIL] $label\n"; }
}

$key = 'smoke_stampede';
$counterFile = sys_get_temp_dir() . '/smoke-stampede-' . getmypid() . '.count';
@unlink($counterFile);
Cache::flush($key);

// 1) primeira chamada produz
$v = Cache::remember($key, 5, static fn(): array => ['n' => 1]);
check('primeira chamada produz valor', ($v['n'] ?? 0) === 1);

// 2) dentro do TTL nao reproduz
$v2 = Cache::remember($key, 5, static fn(): array => ['n' => 99]);
check('dentro do TTL serve cache', ($v2['n'] ?? 0) === 1);

// 3) concorrencia real: N processos vencendo o TTL ao mesmo tempo.
Cache::flush($key);
$runner = sys_get_temp_dir() . '/smoke-stampede-child-' . getmypid() . '.php';
file_put_contents($runner, <<<'CHILD'
<?php
require __DIR__ . '/../app/bootstrap-cli.php';
$key = $argv[1]; $counter = $argv[2];
$v = Cache::remember($key, 5, static function () use ($counter): array {
    file_put_contents($counter, 'x', FILE_APPEND | LOCK_EX);
    usleep(400000); // simula a contagem pesada
    return ['n' => 7];
});
echo (int) ($v['n'] ?? 0);
CHILD
);
// o child precisa ficar ao lado de app/, entao grava em bin/
$childPath = __DIR__ . '/.smoke-stampede-child.php';
copy($runner, $childPath);
@unlink($runner);

$php = PHP_BINARY;
$procs = [];
for ($i = 0; $i < 8; $i++) {
    $procs[] = proc_open(
        [$php, $childPath, $key, $counterFile],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $out[] = $pipes;
}
$values = [];
foreach ($procs as $i => $p) {
    if (!is_resource($p)) { continue; }
    $values[] = trim((string) stream_get_contents($out[$i][1]));
    fclose($out[$i][1]); fclose($out[$i][2]);
    proc_close($p);
}
@unlink($childPath);

$producers = strlen((string) @file_get_contents($counterFile));
@unlink($counterFile);

check("apenas 1 produtor sob 8 concorrentes (viu=$producers)", $producers === 1);
check('nenhum concorrente ficou sem valor', $values !== [] && !in_array('', $values, true) && !in_array('0', $values, true));
check('todos receberam o mesmo valor consolidado', count(array_unique($values)) === 1 && ($values[0] ?? '') === '7');

// 4) janela de graca: valor vencido continua servivel se outro esta produzindo
$file = (function () use ($key) {
    $ref = new ReflectionMethod(Cache::class, 'path');
    $ref->setAccessible(true);
    return $ref->invoke(null, $key);
})();
check('arquivo de cache gravado antes de soltar o lock', is_file($file));
touch($file, time() - 30); // vencido, mas dentro da graca
$lock = fopen($file . '.lock', 'c');
flock($lock, LOCK_EX); // simula outro processo produzindo
Cache::flush(''); // limpa memoria do processo... e o arquivo
check('flush limpa arquivo de cache', !is_file($file));
flock($lock, LOCK_UN); fclose($lock);

echo "\nresultado: $ok ok / $fail falhas\n";
exit($fail === 0 ? 0 : 1);

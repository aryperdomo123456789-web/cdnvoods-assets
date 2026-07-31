# Sprint 2 pós-P0 — Consolidação ao vivo sem oscilar (2026-07-31)

Alvo: painel ao vivo confiável e barato, contagem sem fantasma.
Nada aqui toca o caminho quente do player (`get.php`, `player_api.php`,
`xmltv.php`, `live`, `movie`, `series`) além do UPSERT que já existia.

## 1. in_flight preso deixa de mentir

Antes: `active_requests > 0` mantinha a sessão "viva" mesmo quando o request
morria sem `record()` (cliente cortou, worker do FPM morreu, timeout de rede).
A sessão ficava contando por até ~2h (`DIRECT_IDLE`) e o painel mostrava
conexão sumindo e voltando a cada tick.

Agora:
- `CdnSession::IN_FLIGHT_MAX = 900`: in_flight sem NENHUM heartbeat por 15min
  para de contar em `activeWhereSql()`;
- `CdnSession::sweep()` ganhou a etapa `soltar_in_flight`, que zera
  `active_requests` desses casos e reporta `in_flight_released` no job;
- o `INSERT` de `touch()` passou a gravar `active_requests = 1` e
  `last_open_epoch` — antes só o `ON CONFLICT` fazia isso, então o PRIMEIRO
  request de cada sessão nunca aparecia como em voo.

## 2. KPI não oscila mais

Antes: a recontagem cara só entrava quando o rollup marcava zero, então rollup
ATRASADO com número velho passava como se fosse ao vivo, e rollup zerado
legítimo disparava recontagem — resultado visível: `0 -> 3 -> 0`.

Agora:
- `RestreamRuntime::latestMetricsAged()` e `metricsIfFresh()` julgam pela IDADE;
- `ROLLUP_MAX_AGE = 90` (o job leve roda a 30s);
- rollup fresco manda, inclusive quando o valor é zero;
- rollup velho/ausente => recontagem única (dentro do cache de 5s) e
  `rollup_stale = true` + `rollup_age_s` nos KPIs, para o painel dizer que está
  em modo degradado em vez de mostrar número velho como ao vivo.

## 3. Resumo depende só do rollup leve

`summaryFresh()` não faz mais 2 COUNT em `proxy_user_runtime` por tick. As
métricas `users_runtime_active` e `over_limit_now` passaram para o rollup leve;
o resumo só reconta se o rollup estiver velho.

## Prova

`bash bin/smoke-runtime-live.sh` → 14 ok / 0 falhas.

Bateria completa nesta base: ip-lock 22/22, limit 12/12, uptime 14/14, lb 5/5,
fresh 8/8, direct-health 9/9, runtime-live 14/14. Lint OK em `app/`, `public/`
e `bin/`.

Correção de ferramenta: `bin/smoke-lb.sh` e `bin/smoke-fresh.sh` chamavam `php`
cru e reportavam FALHA FALSA em ambiente sem `php` no PATH; agora têm fallback.
O passo de micro-cache do `smoke-fresh` também deixou de dar falso negativo
quando não há nó de LB cadastrado (build de ~0ms).

## 4. Atualizacao 2026-07-31 (P0 de concorrencia)

A frase "bateria completa verde" desta secao anterior valia para execucao
FUNCIONAL, nao para concorrencia. Na VPS real o `smoke-runtime-live` ainda
encontrou `SQLSTATE[HY000]: General error: 5 database is locked`.

Separacao oficial (detalhe em `docs/RUNTIME_LOCK_SQLITE_2026-07-31.md`):

- bug funcional do painel: `rollup_age_s` / `rollup_stale` — CORRIGIDO;
- limitacao operacional do backend: lock de escrita do SQLite — NAO e bug de
  painel; e requisito de banco (Redis para estado vivo, PostgreSQL para a
  trilha quente).

Mudanca de processo: os smokes que escrevem em `cdn_sessions`,
`proxy_request_events`, `proxy_user_runtime` ou `cdn_metrics` agora sao
SERIALIZADOS por `flock` (`bin/lib/smoke-serial.sh`) e a bateria oficial e
`bin/smoke-all.sh`. Resultado paralelo nao vale como prova de estabilidade.

# Runtime ao vivo: bug do painel x lock do SQLite (2026-07-31)

Este documento separa, de proposito, DUAS coisas que estavam misturadas na
validacao da VPS `45.140.192.237`:

1. bug funcional do painel (corrigido);
2. limitacao operacional do backend SQLite sob concorrencia (NAO corrigivel no
   codigo do painel — e requisito de banco).

## 1. Bug funcional corrigido — `rollup_age_s`

- Sintoma: `smoke-runtime-live` falhava em "idade do rollup exposta".
- Causa: `latestMetricsAged()` usava subquery correlacionada que, no SQLite,
  virava filtro global de `MAX(ts_epoch)`; qualquer job gravando outra metrica
  depois "apagava" a metrica legitima da leitura.
- Efeito: `rollupAgeSeconds()` devolvia `-1` e o painel nao sabia se estava
  fresco ou degradado.
- Correcao: leitura ordenada por `metric, ts_epoch` (ultima linha por metrica),
  idade sempre inteira `>= 0`, e `rollup_stale` + `rollup_age_s` presentes nos
  DOIS caminhos (fresco e degradado).
- Status: fechado. Provado por `bin/smoke-runtime-live.sh` (15/15).

## 2. Limitacao operacional — `database is locked`

- Sintoma na VPS: `SQLSTATE[HY000]: General error: 5 database is locked` durante
  o `smoke-runtime-live`, com a bateria rodando junto/em paralelo.
- Natureza: NAO e bug do painel. O SQLite e UM arquivo com UM escritor por vez.
  A trilha quente concorre entre: trafego ao vivo (`cdn_sessions`,
  `proxy_request_events`), 15 jobs internos (`proxy_user_runtime`,
  `cdn_metrics`, rollup leve), telemetria de LB e polling do painel.
- Mitigacoes que existem hoje (teto, nao solucao): `WAL`,
  `busy_timeout = 30000`, `Database::write()` com backoff, rollup leve para
  tirar `COUNT` caro do tick do painel.
- Lock residual identificado na inicializacao: `configureConnection()` executava
  `PRAGMA journal_mode = WAL` em toda nova conexao ANTES de configurar o
  `busy_timeout`. PHP-FPM, jobs e CLI podiam disputar a mudanca de journal mode;
  a excecao nascia antes de `Database::write()` e portanto escapava do retry.
  Agora o timeout e instalado primeiro e o modo so e alterado quando o banco
  ainda nao esta em WAL. Isso fecha essa janela especifica, mas nao transforma
  SQLite em banco multi-writer.
- Regra oficial de prova, a partir de agora:
  - smoke que escreve em `cdn_sessions`, `proxy_request_events`,
    `proxy_user_runtime` ou `cdn_metrics` roda SERIALIZADO;
  - resultado obtido com execucao paralela NAO conta como prova de estabilidade;
  - "bateria verde" exige rerun isolado sem nenhuma ocorrencia de
    `database is locked`.

### Ferramentas entregues para isso

| Arquivo | Papel |
|---|---|
| `bin/lib/smoke-serial.sh` | `flock` unico (`storage/cache/smoke-hot.lock`) + resolucao de PHP; todo smoke quente carrega |
| `bin/smoke-all.sh` | bateria OFICIAL, serial, um smoke por vez; falha se qualquer log tiver lock |
| `app/DbLockDiag.php` | instrumentacao: qual TABELA, qual OPERACAO, qual tag e foto dos fluxos concorrentes (WAL/shm, locks de job, processos PHP) |
| `bin/smoke-runtime-live.php` | toda escrita passa por `DbLockDiag::guard()` + checagem final "nenhum lock durante o smoke" |

Se o lock voltar a aparecer mesmo isolado, o log agora diz, por exemplo:

```text
[db:lock] tabela=cdn_sessions op=touch tag=smoke-runtime-live tentativa=3 fatal=nao msg=...
        wal_bytes        4194304
        job_locks        jobs-xui_sync_streams.lock(4s)
        procs            12345 88 php bin/jobs-run.php --job=xui_sync_streams
```

Isso identifica o fluxo concorrente sem adivinhacao.

## 3. Por que PostgreSQL passa a ser requisito, nao evolucao

O lock confirma que a trilha quente esta no limite operacional do SQLite para
uso profissional: escrita de alta frequencia por request + jobs + painel no
mesmo arquivo. Serializar smoke resolve a PROVA, nao a PRODUCAO — em producao os
escritores concorrentes sao os clientes reais, e nao ha como serializa-los.

Alvo oficial do banco da CDN:

- estado vivo de altissima frequencia -> Redis (Fase 2 do plano de escala);
- persistencia estruturada e trilha quente historica -> PostgreSQL (Fase 3),
  com MVCC: leitor nunca bloqueia escritor, escritor nunca bloqueia leitor.

Tabelas que precisam sair do SQLite com foco em promote:

- `cdn_sessions`
- `proxy_request_events`
- `proxy_user_runtime`
- `cdn_metrics`
- rollup leve de metricas

`app/Database.php` ja tem dialeto (`sqlite` | `pgsql`), `insertIgnoreSql()` e
`migratePgsqlHot()`, entao o promote e troca de driver + migracao de dados, sem
reescrever o painel.

## 4. Como provar na VPS real

```bash
cd /opt/proxy-mago/proxy-mago-base
git pull --ff-only
# 1) isolado, sem nada mais escrevendo
bash bin/smoke-runtime-live.sh
# 2) bateria oficial serial
bash bin/smoke-all.sh
grep -rn "database is locked" storage/logs/smoke/ || echo "sem lock"
```

Criterio de aceite: `smoke-runtime-live` isolado 15/15, `bin/smoke-all.sh` com
`0 falhas / 0 suites com lock`, e `grep` sem ocorrencia.

## 5. Estado da prova nesta entrega

- Ambiente de desenvolvimento (sandbox, mesmo codigo): `bin/smoke-all.sh` =>
  0 falhas / 0 suites com lock; `smoke-runtime-live` isolado 15/15; fresh 8/8,
  lb 5/5, ip-lock 22/22, limit 12/12, uptime 14/14, direct-health 9/9.
- Ambiente de PRODUCAO (`45.140.192.237`): PENDENTE de rerun por quem tem
  acesso ao host. Nao ha aqui prova da VPS, e este documento nao declara
  "producao validada" sem ela.

## 6. Falha de sessao ativa no smoke concorrente

- Causa exata: as assercoes "sessao viva agora" e "sessao fantasma nao conta
  mais" faziam `COUNT(*)` global em `cdn_sessions`. Em VPS com clientes reais,
  o esperado `1`/`0` era contaminado por qualquer outra sessao publica ativa.
- Correcao: o contador do smoke agora filtra `username = smoke_live_user`, a
  identidade sintetica limpa no inicio e no fim do teste. A regra de producao
  em `CdnSession::activeWhereSql()` nao foi afrouxada nem mascarada.
- A serializacao por `smoke-hot.lock` coordena os smokes entre si; PHP-FPM e jobs
  nao adquirem esse lock. Logo ela evita bateria concorrente artificial, mas nao
  promete serializar os escritores reais da VPS.

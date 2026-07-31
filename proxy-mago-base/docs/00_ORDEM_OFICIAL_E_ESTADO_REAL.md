# CDN Voods — Ordem Oficial de Leitura + Estado Real (auditado)

Data: 2026-07-31
Ambiente alvo: VPS `45.140.192.237`, Ubuntu 22.04, path `/opt/proxy-mago/proxy-mago-base`
Repo de publicação: `aryperdomo123456789-web/cdnvoods-assets` (branches `main` e `backup` idênticas em `f8cd998`)

Este é o documento de entrada. Leia sempre daqui.

## 1. Ordem oficial (não pular etapa, não misturar visão com execução)

1. Contexto/arquitetura: `LOVABLE_PROJECT_BRIEF.md`, `ARQUITETURA_SINGLE_XUI_2026-07-31.md`, `PLANO_PRODUCAO_LB_CEREBRO_MUSCULOS.md`, `PROXY_FLOW.md`
2. Estado real: `ESTADO_ATUAL_PROJETO_2026-07-31.md`, `ESTADO_ATUAL_RASTREAMENTO_2026-07-31.md`, `ESTADO_2026-07-31.md`, `CORRECOES_VPS_2026-07-31.md`, `SYNC_2026-07-31.md`, `FECHAMENTO_2026-07-31.md`
3. Alvo de produção: `PLANO_PRODUCAO_100_FUNCIONAL.md`, `PLANO_ESCALA_SUPREMA_MAIN_LB_2026-07-31.md`, `PLANO_ESPECIALISTA_RASTREABILIDADE_TOTAL_2026-07-31.md`, `PLANO_RESTREAMENTO_TEMPO_REAL.md`, `DIRECT_SOURCE_PRODUCAO.md`, `RESTREAMENTO_OBSERVABILIDADE.md`
4. Checklist mestre: `CHECKLIST_IMPLEMENTACAO_SUPREMA_2026-07-31.md`, `PLANO_MESTRE_OPERACIONAL_RASTREAVEL_2026-07-31.md`
5. Execução: `SPRINTS_EXECUCAO_PROJETO_2026-07-31.md`
6. Direct source: `PLANO_ESPECIALISTA_DIRECT_SOURCE_PERFEITO.md`, `LOVABLE_PROMPT_DIRECT_SOURCE_PERFEITO.md`, `FALTAS_RESTREAMENTO_INTELIGENTE.md`, `LAB-CONEXOES-REAIS.md`
7. Infra/deploy: `DEPLOY_VPS.md`, `LB_AUTO_SSH_KEY_PRODUCAO.md`, `GITHUB_BACKUP_SETUP.md`, `CLOUDFLARE_SETUP.md`, `ARQUITETURA_LEVE_SEM_NUVEM_LARANJA.md`

Regras: single XUI manda; documento `2026-07-31` vence documento antigo; execução (sprints + plano mestre) vence visão; material multi-XUI (`CODIGO_DE_APP_MULTI_XUI.md`, `RELATORIO_CODIGO_DE_APP.md`, `_isolated/app-code-module/`) é legado congelado e não orienta mudança.

## 2. Estado real auditado no código (não em teoria)

### Pronto
- Proteção de origem: `AccessGuard`, `StreamProxy`, `PlaylistRewriter` (rewrite de m3u/API/xmltv, redirect saneado, host efetivo mascarado).
- Caminho quente do proxy resiliente: telemetria/auditoria em best-effort; stream não morre por falha de log.
- Banco endurecido: WAL, `busy_timeout`, `Database::write()` com backoff, migrações idempotentes.
- Jobs auditáveis: perfis `fast`/`heavy`, lock por perfil, `job_step_history`, disjuntor (circuit breaker) + reset no painel.
- Trilha única: `cdn_audit_timeline` (uma linha por sessão lógica) + `public/auditoria.php`.
- Multi-LB: `lb_nodes`, `lb_user_routes`, `lb_route_history`, score, decisão `main_only`/`auto`/`forced`/`drain`, fallback para o cérebro.
- LB simples de operar: cadastro por IP + porta + user root + senha root, auto-instalação, log ao vivo por etapa, telemetria (CPU/RAM/disco/RX/TX/sessões), envio de usuário único ou de todos para um LB.
- Segredos: senha SSH via `SSHPASS` (nunca em linha de comando), `LbCrypto` AES-256-GCM, redaction em todo log.

### Parcial
- (RESOLVIDO 2026-07-31) Contagem `direct source` em troca de filme: `CdnSession::supersedePrevious()` fecha a sessão anterior de `movie`/`series` do mesmo `username|fingerprint + IP + app` como `close_reason='superseded'`. Provado por `bin/smoke-lb.sh` (etapa 4): contador fica em 1 na troca de filme. Live continua contando por tela, de propósito.
- Frescor do painel: `restream-data.php` tem `_meta` (idade, query_ms, lock retries); `lb-data.php` só tem `ts`, sem `data_age_ms` nem aviso de modo degradado.
- `xui_sync_streams`: volume real (483k+ streams) exige confirmação de estabilidade sob cron no ambiente real.
- Trilha quente ainda em SQLite na prática, MAS o SQL já é portável e o espelho pgsql tem paridade de schema (`S2-P0-4` feito). O que falta é o ensaio de corte com dados reais.
- (RESOLVIDO 2026-07-31) Smoke de LB: `bin/smoke-lb.sh` cobre score dos nós, decisão de rota, queda de LB com fallback para o cérebro e supersede de VOD.

### Fechado em 2026-07-31 (Sprint 2 — P0)
- `S2-P0-1` trava CDN por IP endurecida: octeto inválido recusado, `/0` correto em 32 bits, curinga em qualquer octeto final, IPv6 exato e em CIDR, regra inválida reportada no painel, veredito explicável (`UserIpLock::explain()`) no log de bloqueio. Prova: `bin/smoke-ip-lock.sh` (22 ok / 0 falhas).
- `S2-P0-2` enforcement de limite provado ponta a ponta: `alert` não bloqueia, `block` recusa acima do plano, playlist/API nunca ocupa slot, tolerância protege reconexão, fechar tela devolve acesso, estouro visível como divergência `above_limit`. Prova: `bin/smoke-limit.sh` (12 ok / 0 falhas).
- `S2-P0-3` uptime real: graça de retomada por tipo de consumo (não só direct), válida também para sessão ativa com buraco de atividade, e `record()` preenchendo host efetivo de direct. Prova: `bin/smoke-uptime.sh` (14 ok / 0 falhas).
- Detalhe técnico: `docs/S2_P0_ENFORCEMENT_2026-07-31.md`.

### Fechado em 2026-07-31 (Sprint 2 — pós-P0: runtime ao vivo)
- `in_flight` preso não conta mais como conexão viva (`CdnSession::IN_FLIGHT_MAX` + etapa `soltar_in_flight` no sweep); primeiro request da sessão agora nasce como em voo (o `INSERT` de `touch()` não gravava `active_requests`).
- KPI sem oscilação: rollup vale por IDADE (`ROLLUP_MAX_AGE`), zero fresco é zero, rollup velho vira modo degradado explícito (`rollup_stale`, `rollup_age_s`) com recontagem única.
- Resumo do painel depende só do rollup leve (`users_runtime_active`, `over_limit_now`); sem COUNT em `proxy_user_runtime` por tick.
- Prova: `bin/smoke-runtime-live.sh` (15 ok / 0 falhas, execução ISOLADA). Detalhe: `docs/S2_POS_P0_RUNTIME_2026-07-31.md`.
- (P0 2026-07-31) Concorrência: os smokes da trilha quente (`cdn_sessions`, `proxy_request_events`, `proxy_user_runtime`, `cdn_metrics`) agora rodam SERIALIZADOS por `flock` (`bin/lib/smoke-serial.sh`); bateria oficial = `bin/smoke-all.sh`. Lock de escrita do SQLite está documentado como LIMITAÇÃO DE BACKEND (não bug de painel) em `docs/RUNTIME_LOCK_SQLITE_2026-07-31.md`, com PostgreSQL como alvo oficial da trilha quente. Resultado paralelo não vale como prova; prova na VPS `45.140.192.237` está PENDENTE de rerun isolado.

### Crítico / não iniciado
- Runtime quente ainda 100% em SQLite (sessão, requests, auditoria, jobs). Sprint 2 define PostgreSQL como destino oficial; hoje não há camada de abstração — `Database.php` é o único ponto com menção a Postgres.
- Enforcement como fonte de verdade (limite de conexão + trava por IP) depende da contagem estar perfeita; com a inflação de `movie` acima, bloqueio pode ficar injusto.

## 3. Próxima ação técnica segura (ordem)

1. ~~`S1-P0` — supersede de sessão em `movie`/`series`~~ FEITO em `app/CdnSession.php`.
2. ~~`S1-P1` — `bin/smoke-lb.sh`~~ FEITO (5 ok / 0 falhas neste ambiente, sem LB cadastrado).
3. ~~`S1-P2` — `_meta` com `data_age_ms` + aviso de modo degradado~~ FEITO em `app/Freshness.php`, `public/lb-data.php`, `public/restream-data.php`, `public/lb.php`, `public/restream.php` (`bin/smoke-fresh.sh`: 8 ok / 0 falhas).
4. ~~`S2-P0-1`/`S2-P0-2`/`S2-P0-3` — trava por IP, limite e uptime~~ FEITO (`docs/S2_P0_ENFORCEMENT_2026-07-31.md`).
5. `S1-P3` — instalar `LB-02` real pelo painel e registrar o baseline medido em `docs/BASELINE_CARGA_LB.md`.
6. ~~`S2-P0-4` — abstração de persistência antes de PostgreSQL~~ FEITO em `app/Sql.php` + paridade pgsql das 12 tabelas quentes em `Database::migratePgsqlHot()` (`bin/smoke-portability.sh`: 12 ok / 0 falhas). Detalhe: `docs/S2_P0_4_PORTABILIDADE_2026-07-31.md`. Falta o ENSAIO DE CORTE contra um PostgreSQL real (Fase 3).

Nada de migrar banco antes de fechar 1 e 2: contagem errada em banco novo continua contagem errada.


## 4. Plano de escala oficial

Visão: `docs/PLANO_ESCALA_SUPREMA_MAIN_LB_2026-07-31.md`
Execução em fases (é este que se segue no dia a dia): `docs/PLANO_EXECUCAO_ESCALA_FASES_2026-07-31.md`

Fase 1 (agora): 1.1 supersede FEITO, 1.2 smoke de LB FEITO, 1.3 frescor de dados
FEITO, 1.4 polling adaptativo FEITO, 1.5 jobs em perfis `fast`/`heavy` com lock e
disjuntor FEITO, 1.6 pacote padrão de LB FEITO (`bin/lb-install.sh` +
`app/LbPackageBuilder.php`, instalação automática ao salvar). Resta 1.7: rodar o
baseline de carga no `LB-02` real e preencher os números em
`docs/BASELINE_CARGA_LB.md`. Redis (Fase 2), PostgreSQL (Fase 3) e motor Go
(Fase 4) só depois disso.

## 5. Triagem de falha de série (2026-07-31) — não é tudo bug do proxy

`app/DirectHostHealth.php` fecha o furo de diagnóstico: toda falha de série era
tratada como falha do proxy. Agora o veredito é por HOST FINAL, com culpa
explícita, e vive em `?view=direct_health` (e no card "Host final do direct
source" em `public/restream.php`, polling de 30s):

| veredito | culpa | leitura operacional |
|---|---|---|
| `ok` | nenhum | host externo aceita o fetch da CDN |
| `flaky` | host_final | entrega, mas falha parte das vezes |
| `blocked` | host_final | 401/403/451 — host externo barra a CDN; não mexer no proxy |
| `unreachable` | host_final | sem resposta (timeout/DNS/rota) |
| `degraded` | host_final | 5xx do lado do host externo |
| `catalog_stale` | catalogo_api | 404/410 — conteúdo saiu de lá, corrigir catálogo no XUI |
| `unknown` | nenhum | amostra pequena na janela |

Triagem de um conteúdo específico: `?view=direct_stream&stream_id=<id>` devolve
`triage.blame` em `catalogo_api` | `host_final` | `sessao`.

Compatibilidade de `get_series_info`: `app/XuiSeriesCompat` já reconstrói
`seasons` a partir de `episodes` quando a origem devolve `seasons` vazio
(chamado em `public/proxy.php`), então catálogo torto de temporada não derruba
mais o app.

Provas: `bin/smoke-direct-health.sh` → 9 ok / 0 falhas.
Nada disso toca o caminho quente do player: é leitura de painel/job sobre
`direct_source_hops` e `direct_stream_state`.

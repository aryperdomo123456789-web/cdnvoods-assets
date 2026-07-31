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
- Contagem `direct source` em troca de filme: `CdnSession::touch()` cria chave por `stream_id`, e **não existe supersede** da sessão anterior do mesmo dispositivo em `movie`/`series`. Resultado: durante a janela de idle direct (até 7200s) a contagem pode inflar. Documento antigo diz "corrigido" — no código de hoje não está.
- Frescor do painel: `restream-data.php` tem `_meta` (idade, query_ms, lock retries); `lb-data.php` só tem `ts`, sem `data_age_ms` nem aviso de modo degradado.
- `xui_sync_streams`: volume real (483k+ streams) exige confirmação de estabilidade sob cron no ambiente real.
- Smoke: existe `smoke-test.sh`, `smoke-restream.sh`, `smoke-intelligence.sh`. Não existe smoke de LB (troca de LB, queda de LB, fallback pro cérebro).

### Crítico / não iniciado
- Runtime quente ainda 100% em SQLite (sessão, requests, auditoria, jobs). Sprint 2 define PostgreSQL como destino oficial; hoje não há camada de abstração — `Database.php` é o único ponto com menção a Postgres.
- Enforcement como fonte de verdade (limite de conexão + trava por IP) depende da contagem estar perfeita; com a inflação de `movie` acima, bloqueio pode ficar injusto.

## 3. Próxima ação técnica segura (ordem)

1. `S1-P0` — supersede de sessão em `movie`/`series`: ao abrir novo `stream_id` para o mesmo `username + client_ip + app`, fechar a sessão anterior com `close_reason='superseded'`. Escopo cirúrgico em `app/CdnSession.php`, validado por `bin/smoke-intelligence.sh` + teste real de troca de filme.
2. `S1-P1` — `bin/smoke-lb.sh`: prova de troca de LB, queda de LB e fallback para o cérebro.
3. `S1-P2` — `_meta` com `data_age_ms` + aviso de modo degradado em `lb-data.php` e nos cards do painel.
4. `S2-P0-4` — abstração de persistência (config / runtime quente / auditoria / espelho XUI) antes de qualquer migração para PostgreSQL.

Nada de migrar banco antes de fechar 1 e 2: contagem errada em banco novo continua contagem errada.

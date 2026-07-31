# Sprints de Execução — Projeto CDN Voods

Data de referencia: `2026-07-31`

Este documento e a versao mais pratica do plano-mestre.

Objetivo:

- transformar o projeto em roteiro semanal de execucao
- deixar claro o que fazer primeiro
- apontar arquivo por arquivo
- separar prioridade em `P0`, `P1`, `P2`
- manter foco em:
  - protecao total do XUI
  - protecao total do `direct source`
  - leveza
  - fluidez
  - rastreabilidade total
  - preparo para mais LBs

Legenda:

- `P0`: essencial, mexe em risco operacional ou seguranca
- `P1`: importante, melhora estabilidade e escalabilidade
- `P2`: refinamento, acabamento ou preparo estrutural

## 1. Norte fixo do projeto

Todas as sprints abaixo obedecem esta arquitetura:

- `main` = cerebro
- `LB` = entrega
- `single XUI`
- `PostgreSQL` = banco central oficial da CDN no alvo de producao
- `SQLite` = camada temporaria de compatibilidade durante a transicao
- nenhuma origem do XUI pode vazar
- nenhum host real de `direct source` pode vazar
- o player deve abrir rapido
- a telemetria nao pode pesar mais que o stream

Regra estrutural a partir deste plano:

- nao abrir migracao ampla para `Go` antes de tirar o runtime quente do `SQLite`
- nao tratar `SQLite` como banco final de producao para sessoes, auditoria e jobs quentes
- manter compatibilidade com `SQLite` somente enquanto a transicao para `PostgreSQL` nao terminar

## 2. Sprint 1 — Estabilização do Runtime Atual

Meta da sprint:

- parar de perder estabilidade no ambiente atual
- blindar o caminho quente do proxy
- reduzir o impacto do banco e do painel
- consolidar o `LB-01` como saida real sem quebrar os apps

Prazo sugerido:

- `5 a 7 dias`

Definicao de pronto da sprint:

- app abre `get.php` e `movie` de forma consistente
- painel para de pressionar o runtime de forma excessiva
- jobs pesados deixam de disputar com o stream
- nenhum ajuste de telemetria derruba reproducao

### Sprint 1 — Tarefas P0

#### S1-P0-1. Blindar definitivamente o caminho quente do proxy

Arquivos:

- `public/proxy.php`
- `app/proxy-bootstrap.php`
- `app/StreamProxy.php`
- `app/AccessGuard.php`
- `app/RequestLog.php`
- `app/CdnSession.php`
- `app/DirectSource.php`
- `app/AuditTimeline.php`

Fazer:

- revisar tudo que roda antes, durante e depois do stream
- manter somente o essencial sincrono
- deixar telemetria secundaria em best-effort
- revisar qualquer trecho que ainda possa gerar fatal ou lock perceptivel
- garantir que o proxy nunca dependa de classe nao carregada no bootstrap minimo

Validacao:

- `curl -I` em `get.php`
- `curl -I` em `movie/...`
- teste real em app com duas telas

Critério de pronto:

- stream continua tocando mesmo com falha de log, auditoria ou consolidacao

#### S1-P0-2. Reduzir polling pesado do painel ao vivo

Arquivos:

- `public/restream.php`
- `public/restream-data.php`
- `app/RestreamRuntime.php`
- `app/UserIntelligence.php`
- `app/Cache.php`

Fazer:

- mapear quais views estao mais pesadas
- reduzir frequencia de polling nas views de usuarios e sessoes
- aplicar cache curto onde for seguro
- reduzir `limit` padrao exagerado
- separar endpoints de leitura leve e leitura pesada

Validacao:

- abrir painel e observar tempos de resposta
- acompanhar `php8.1-fpm.access.log`

Critério de pronto:

- painel continua util sem derrubar o runtime

#### S1-P0-3. Desacoplar jobs pesados do loop rapido

Arquivos:

- `bin/jobs-run.php`
- `app/JobRunner.php`
- `app/RestreamRuntime.php`
- `app/DirectCatalog.php`
- `app/XuiSyncService.php`

Fazer:

- separar operacao real entre perfil `fast` e `heavy`
- deixar loop rapido somente com jobs leves
- reduzir execucao concorrente de consolidacoes pesadas
- revisar `direct_consolidate`
- revisar `metrics_rollup`
- revisar `detect_inconsistency`
- revisar `cleanup`

Evidencia atual:

- `direct_consolidate` esta muito pesado
- `metrics_rollup` esta pesado
- `xui_sync_streams` esta em erro

Validacao:

- `php bin/jobs-run.php --list`
- execucao forcada por job
- leitura de `job_state`

Critério de pronto:

- job pesado nao disputa o mesmo tempo do player

#### S1-P0-4. Auditar e estabilizar `xui_sync_streams`

Arquivos:

- `app/XuiSyncService.php`
- `app/XuiSyncConfig.php`
- `bin/xui-sync.php`
- `bin/jobs-run.php`

Fazer:

- localizar a causa exata do erro atual do job
- revisar consulta, paginação, tempo e volume do sync de streams
- decidir se ele fica:
  - manual
  - agendado mais espaçado
  - ou incremental

Validacao:

- rodar `php bin/jobs-run.php --job=xui_sync_streams --force`
- revisar `job_state`
- revisar impacto no banco local

Critério de pronto:

- `xui_sync_streams` deixa de falhar e deixa de ser peso descontrolado

### Sprint 1 — Tarefas P1

#### S1-P1-1. Estabilizar rastreabilidade ao vivo de duas telas

Arquivos:

- `app/CdnSession.php`
- `app/RequestContext.php`
- `app/RestreamRuntime.php`
- `app/UserIntelligence.php`
- `public/restream.php`
- `public/restream-data.php`

Fazer:

- revisar agrupamento por `stream_id`, IP e app
- revisar troca de conteudo
- revisar pausa e play
- revisar deduplicacao de bursts

Validacao:

- teste real com duas telas em locais diferentes
- troca de filmes/series

Critério de pronto:

- painel nao some e volta de forma falsa

#### S1-P1-2. Fechar smoke tests pendurados

Arquivos:

- `bin/smoke-restream.sh`
- `bin/smoke-intelligence.sh`

Fazer:

- colocar timeouts claros
- garantir que bloco opcional nao deixa script preso
- separar resultado estrutural de resultado opcional

Validacao:

- executar ambos os scripts de ponta a ponta

Critério de pronto:

- smoke test sempre termina com saida confiavel

### Sprint 1 — Tarefas P2

#### S1-P2-1. Enxugar ainda mais a tela operacional

Arquivos:

- `public/restream.php`
- `public/restream-user.php`

Fazer:

- reduzir ruido visual
- focar em KPI e tabela util
- melhorar leitura de uptime e saida LB

Critério de pronto:

- operador encontra a resposta sem rolar muito

## 3. Sprint 2 — Segurança e Rastreabilidade Total

Meta da sprint:

- transformar a CDN na fonte de verdade de enforcement
- consolidar trava por IP
- consolidar limite de conexoes
- deixar o painel responder exatamente quem esta vendo o que
- iniciar a virada segura da persistencia quente para um desenho compativel com `PostgreSQL`

Prazo sugerido:

- `5 a 8 dias`

Definicao de pronto da sprint:

- acesso invalido morre na CDN antes do XUI
- excesso de conexao e bloqueado pela CDN
- painel mostra usuario, app, IP, conteudo, uptime e saida real

### Sprint 2 — Tarefas P0

#### S2-P0-1. Fechar trava CDN por IP com validacao forte

Arquivos:

- `app/UserIpLock.php`
- `public/xui-user.php`
- `public/save-xui-line.php`
- `public/proxy.php`

Fazer:

- revisar parser de IP/CIDR/faixa/curinga
- validar UX da tela de usuario
- mostrar status claro de ativo/inativo
- testar casos reais de bloqueio e liberacao

Validacao:

- testar usuario com IP permitido
- testar usuario com IP bloqueado
- testar CIDR e faixa

Critério de pronto:

- regra e aplicada antes do XUI e fica visivel no painel

#### S2-P0-2. Fechar enforcement de limite pela CDN

Arquivos:

- `app/CdnSession.php`
- `app/Divergence.php`
- `app/UserIntelligence.php`
- `public/proxy.php`
- `public/restream.php`

Fazer:

- usar a sessao da CDN como verdade operacional
- validar usuario de 1 conexao
- validar burst, pausa, resume e troca de conteudo
- registrar no painel quando estourar limite

Validacao:

- criar usuario de 1 conexao
- abrir em duas telas
- confirmar bloqueio e rastreio

Critério de pronto:

- a CDN bloqueia antes do XUI e o painel mostra isso

#### S2-P0-3. Consolidar uptime real por sessao

Arquivos:

- `app/CdnSession.php`
- `app/DirectSource.php`
- `app/RequestLog.php`
- `app/AuditTimeline.php`
- `public/restream-data.php`

Fazer:

- revisar `uptime_start_epoch`
- garantir continuidade em pausas curtas
- manter uptime em direct source longo
- exibir uptime de forma estavel no painel

Validacao:

- abrir conteudo por longo periodo
- pausar
- retomar
- trocar conteudo

Critério de pronto:

- uptime representa uso real, sem resets falsos

#### S2-P0-4. Preparar compatibilidade de persistencia para PostgreSQL

Arquivos:

- `app/Database.php`
- `app/RestreamRuntime.php`
- `app/UserIntelligence.php`
- `app/CdnSession.php`
- `app/JobRunner.php`
- `app/RequestLog.php`
- `bin/jobs-run.php`

Fazer:

- separar no codigo o que e:
  - configuracao local
  - runtime quente
  - auditoria
  - espelho do XUI
- preparar camada compativel com:
  - `SQLite` como legado temporario
  - `PostgreSQL` como destino oficial
- remover pontos onde SQL e detalhes do `SQLite` estao acoplados sem abstracao
- mapear tabelas que precisam sair primeiro do `SQLite`

Validacao:

- o projeto continua operando em `SQLite`
- a camada de persistencia passa a aceitar evolucao para `PostgreSQL` sem reescrever a regra de negocio

Critério de pronto:

- o projeto ganha trilha tecnica segura para sair do `SQLite`

### Sprint 2 — Tarefas P1

#### S2-P1-1. Fechar verdade operacional do painel

Arquivos:

- `app/UserIntelligence.php`
- `app/RestreamRuntime.php`
- `public/restream.php`
- `public/restream-data.php`

Fazer:

- garantir colunas:
  - usuario
  - plano
  - em uso
  - livres
  - direct
  - IP final
  - app
  - ultimo conteudo
  - saida atual
  - rota do cerebro
  - uptime

Critério de pronto:

- uma unica tela responde toda a operacao

#### S2-P1-2. Fechar auditoria consolidada por sessao

Arquivos:

- `app/AuditTimeline.php`
- `public/auditoria.php`
- `public/restream-user.php`

Fazer:

- garantir historico por sessao
- facilitar leitura de divergencia, saida e host efetivo
- manter trilha leve

Critério de pronto:

- operador consegue auditar um usuario sem cruzar 5 tabelas na mao

### Sprint 2 — Tarefas P2

#### S2-P2-1. Refinar paginas de usuario XUI

Arquivos:

- `public/xui.php`
- `public/xui-user.php`
- `public/save-xui-user.php`

Fazer:

- deixar gerenciamento mais claro
- evidenciar trava CDN por IP
- preparar campos para operacao segura

Critério de pronto:

- o operador consegue gerenciar usuario e trava no mesmo fluxo

## 4. Sprint 3 — Padronização de Escala com LBs

Meta da sprint:

- transformar `LB-01` em modelo repetivel
- preparar `LB-02` e proximos sem improviso
- consolidar o `main` como cerebro de varios LBs
- deixar a arquitetura pronta para LBs reportarem para um banco central da CDN

Prazo sugerido:

- `5 a 7 dias`

Definicao de pronto da sprint:

- onboarding de novo LB vira processo repetivel
- health, rota e telemetria de LB ficam claros
- usuarios podem ser movidos para novos LBs com seguranca

### Sprint 3 — Tarefas P0

#### S3-P0-1. Fechar receita oficial de onboarding de LB

Arquivos:

- `app/LbInstaller.php`
- `app/LbPackageBuilder.php`
- `app/LbTelemetry.php`
- `public/lb.php`
- `public/lb-data.php`
- `public/lb-action.php`
- `public/save-lb.php`
- `bin/lb-install.sh`
- `bin/lb-install-run.php`

Fazer:

- padronizar instalacao
- padronizar retorno de status
- padronizar health
- padronizar log de instalacao

Critério de pronto:

- `LB-02` entra seguindo a mesma receita do `LB-01`

#### S3-P0-2. Fechar roteamento operacional por usuario

Arquivos:

- `app/LbRouter.php`
- `app/CdnSession.php`
- `public/save-lb-route.php`
- `public/restream.php`

Fazer:

- revisar `main_only`, `lb_auto`, `lb_forced`
- garantir que o painel mostre `saida atual` e `rota do cerebro`
- validar troca controlada de usuario entre LBs

Critério de pronto:

- o operador sabe por qual LB o usuario esta saindo

#### S3-P0-3. Fechar health e telemetria minima dos LBs

Arquivos:

- `app/LbTelemetry.php`
- `public/lb-data.php`
- `public/lb.php`
- `bin/jobs-run.php`

Fazer:

- validar `lb_probe`
- revisar CPU, RAM, banda, health e ultimo seen
- tornar o painel de LB util para operacao diaria

Critério de pronto:

- cada LB tem saude rastreavel e visivel

### Sprint 3 — Tarefas P1

#### S3-P1-1. Teste de carga progressivo por LB

Arquivos:

- `docs/`
- scripts de smoke

Fazer:

- criar procedimento de teste real:
  - `100`
  - `300`
  - `500`
  - `800`
  - `1200`
- medir comportamento do `LB-01`
- registrar resultado em documento operacional

Critério de pronto:

- capacidade do LB deixa de ser estimada e passa a ser medida

#### S3-P1-2. Reduzir dependencia do `main` no fluxo de entrega

Arquivos:

- `app/LbRouter.php`
- `public/proxy.php`
- configuracoes de dominio/LB

Fazer:

- garantir que o cliente toque preferencialmente pelo LB
- evitar proxy duplo quando nao for necessario

Critério de pronto:

- banda pesada fica concentrada nos LBs

#### S3-P1-3. Preparar fluxo LB -> banco central PostgreSQL

Arquivos:

- `app/LbTelemetry.php`
- `app/LbRouter.php`
- `app/CdnSession.php`
- `app/AuditTimeline.php`
- `docs/PLANO_PRODUCAO_LB_CEREBRO_MUSCULOS.md`

Fazer:

- desenhar contrato de envio de eventos e sessoes dos LBs para o cerebro
- separar leitura local de entrega e persistencia central
- preparar modelo em que o `main` consulta `PostgreSQL` como verdade consolidada

Critério de pronto:

- novos LBs nascem ja compativeis com o banco central da CDN

### Sprint 3 — Tarefas P2

#### S3-P2-1. Documentar oficialmente a receita de producao LB

Arquivos:

- `docs/PLANO_PRODUCAO_LB_CEREBRO_MUSCULOS.md`
- novo documento de operacao se necessario

Fazer:

- atualizar com o fluxo real que ficou funcionando

Critério de pronto:

- onboarding de LB fica documentado e repetivel

## 5. Sprint 4 — Base de Escala Alta

Meta da sprint:

- preparar o projeto para sair do limite estrutural atual
- separar runtime vivo de historico
- abrir caminho para mais LBs e futuro `main` melhor

Prazo sugerido:

- `7 a 12 dias`

Definicao de pronto da sprint:

- o projeto deixa de depender do `SQLite` como banco operacional central
- estado vivo fica mais leve
- a troca de `main` futuro fica facilitada

### Sprint 4 — Tarefas P0

#### S4-P0-1. Introduzir PostgreSQL como persistencia principal da CDN

Arquivos:

- nova camada de persistencia
- `app/Database.php`
- `app/RestreamRuntime.php`
- `app/UserIntelligence.php`
- `app/CdnSession.php`
- `app/RequestLog.php`
- `app/AuditTimeline.php`
- `app/JobRunner.php`
- `bin/`

Fazer:

- mover para `PostgreSQL`:
  - `cdn_sessions`
  - `proxy_request_events`
  - `proxy_user_runtime`
  - `job_state`
  - `job_runs`
  - trilhas de auditoria e tabelas de operacao
- manter `SQLite` apenas como compatibilidade temporaria e configuracao local residual
- validar leitura e escrita real no banco novo
- definir corte de promote:
  - leitura dual
  - escrita dual
  - promote de leitura principal
  - desligamento gradual do legado

Critério de pronto:

- `PostgreSQL` vira a persistencia principal da CDN
- `SQLite` deixa de ser ponto central de lock operacional

#### S4-P0-2. Introduzir Redis para estado vivo

Arquivos:

- nova camada de runtime
- `app/CdnSession.php`
- `app/UserIntelligence.php`
- `app/RestreamRuntime.php`
- `app/AccessGuard.php`

Fazer:

- mover para `Redis`:
  - sessoes
  - heartbeat
  - contadores
  - limite
  - rate limit
  - presence

Critério de pronto:

- estado vivo deixa de pressionar o banco relacional

### Sprint 4 — Tarefas P1

#### S4-P1-1. Criar API interna estavel do cerebro

Arquivos:

- novas rotas/API
- integracao com LBs

Fazer:

- expor endpoints internos para:
  - regras
  - health
  - alocacao
  - telemetria
  - bloqueios

Critério de pronto:

- futuro `main` mais forte pode assumir sem reescrever tudo

### Sprint 4 — Tarefas P2

#### S4-P2-1. Preparar desenho do motor quente em Go

Arquivos:

- `docs/`
- novo modulo futuro

Fazer:

- definir contrato do runtime em `Go`
- decidir o que sai do PHP primeiro

Critério de pronto:

- migracao do caminho quente fica planejada e sem improviso

## 6. Ordem profissional recomendada

Se for para executar com seguranca, a ordem correta e:

1. `Sprint 1`
2. `Sprint 2`
3. `Sprint 3`
4. `Sprint 4`

Regra:

- nao abrir expansao forte de LBs antes de fechar a estabilidade do runtime
- nao atacar motor em `Go` antes de fechar enforcement e rastreabilidade atual

## 7. Definição de sucesso por sprint

### Sucesso Sprint 1

- proxy estavel
- painel mais leve
- jobs pesados controlados
- `xui_sync_streams` estabilizado ou rebaixado com segurança

### Sucesso Sprint 2

- trava por IP confiavel
- limite por conexao confiavel
- uptime e sessao confiaveis
- verdade operacional no painel

### Sucesso Sprint 3

- onboarding de `LB-02` repetivel
- health de LB confiavel
- roteamento por usuario visivel

### Sucesso Sprint 4

- base pronta para escala maior
- runtime vivo fora do gargalo atual
- projeto preparado para futuro `main` mais forte

## 8. Conclusão prática

Este e o roteiro de obra do projeto.

Ele responde:

- o que fazer primeiro
- em que arquivo mexer
- o que e `P0`, `P1`, `P2`
- como validar cada entrega

O ponto mais forte agora e simples:

- fechar `Sprint 1` com excelencia

Porque ela destrava:

- estabilidade
- seguranca
- rastreabilidade
- a entrada profissional dos proximos LBs
- e a virada segura para `PostgreSQL`

# Checklist Especialista — Implementação Suprema

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data de referencia: `2026-07-31`

Objetivo deste roteiro:

- transformar o projeto atual em uma CDN/proxy profissional para `1` XUI
- proteger totalmente o IP e os sinais do XUI
- proteger `direct source`
- manter o sistema leve, fluido, rapido e seguro
- abrir conteudo de forma instantanea
- preparar a operacao para mais LBs
- preparar o projeto para um futuro `main` mais forte sem refazer tudo

Este checklist foi montado cruzando:

- codigo real em `/opt/proxy-mago/proxy-mago-base`
- jobs reais em `bin/jobs-run.php`
- healthcheck real em `app/HealthCheck.php`
- smoke tests reais em `bin/smoke-restream.sh` e `bin/smoke-intelligence.sh`
- configuracao e dados reais do ambiente atual

## 0. Foto real do ambiente hoje

Infra atual:

- `main`: `2 vCPU`, `6 GB RAM`, rede `1/1`
- `LB-01`: `3 vCPU`, `8 GB RAM`, rede `10/10`

Projeto atual:

- `role=main`
- `SQLite` local em `storage/app.sqlite`
- tamanho atual do banco: cerca de `5.24 GB`
- `follow_external_redirects = 1`
- `direct_source_trace = 1`
- `log_segments = 0`
- `xui_sync_seconds = 5`
- `cdn_sessions_enabled = 1`

Validacoes reais feitas neste ambiente:

- `php bin/jobs-run.php --list` responde e lista os jobs
- `HealthCheck::run()` respondeu `ok` para:
  - configuracao
  - SQLite
  - storage
  - socket do `php8.1-fpm`
  - origem XUI
- `bin/smoke-restream.sh` confirmou ao menos:
  - schema local existente
  - catalogo de jobs funcional
  - `consolidate_runtime` funcional

Leitura honesta:

- a base do sistema esta viva
- a arquitetura de cerebro + LB ja existe
- o principal risco atual continua sendo concorrencia no runtime com `SQLite`

## 1. Regra de ouro do projeto

Antes de qualquer implementacao nova, fixar esta regra:

- o `main` e cerebro
- o `LB` e entrega
- o `main` nao deve carregar stream pesado de producao
- o cliente deve tocar conteudo pelo LB
- o `main` deve centralizar regra, auditoria e controle

Critério de pronto:

- toda nova funcionalidade precisa responder: isso pertence ao `main`, ao `LB`
  ou a ambos?

## 2. Objetivos tecnicos obrigatorios

O sistema final deve garantir:

- o cliente nunca ve IP do XUI
- o cliente nunca ve host real do `direct source`
- a CDN valida o acesso antes de chegar no XUI
- a CDN aplica trava por IP/CIDR/faixa antes do XUI
- a CDN conta conexoes melhor que o XUI
- a CDN bloqueia excesso de conexoes antes do XUI
- o painel mostra:
  - usuario
  - conexoes em uso
  - conexoes livres
  - IP final
  - app
  - ultimo conteudo
  - LB de saida
  - uptime da sessao

Critério de pronto:

- nenhuma rota publica pode vazar host/origem no corpo, header ou redirect

## 3. Checklist Fase 1 — Congelar arquitetura correta

### 3.1. Confirmar escopo oficial

Fazer:

- manter `single XUI`
- considerar `multi-XUI` como legado/experimental
- manter `main` apenas como cerebro

Codigo e docs que precisam ficar coerentes:

- `docs/ARQUITETURA_SINGLE_XUI_2026-07-31.md`
- `docs/PLANO_ESCALA_SUPREMA_MAIN_LB_2026-07-31.md`
- `public/xui.php`
- `public/lb.php`

Critério de pronto:

- nenhuma tela principal deve induzir o uso como `multi-XUI`

### 3.2. Definir contrato oficial do LB

Fazer:

- documentar exatamente o que o LB precisa receber do cerebro
- documentar exatamente o que o LB deve devolver ao cerebro

Minimo obrigatorio:

- regras por usuario
- rota para `main_only`, `lb_auto`, `lb_forced`
- trava CDN por IP
- estado de saude do LB
- telemetria minima por sessao

Critério de pronto:

- o cadastro do `LB-02` precisa seguir o mesmo contrato do `LB-01`

## 4. Checklist Fase 2 — Blindar o runtime atual

### 4.1. Blindar o caminho publico do proxy

Arquivos principais:

- `public/proxy.php`
- `app/proxy-bootstrap.php`
- `app/StreamProxy.php`
- `app/AccessGuard.php`
- `app/UserIpLock.php`

Fazer:

- garantir que nenhuma dependencia de painel/admin entre no caminho quente
- manter `proxy-bootstrap.php` enxuto
- todo log no caminho do player deve ser best-effort
- nenhuma falha de telemetria pode derrubar stream
- revisar todas as chamadas pesadas pos-stream

Teste:

- `curl -I` em:
  - `get.php`
  - `movie/...`
  - `player_api.php`
- teste real em app

Critério de pronto:

- app continua tocando mesmo com falha de log/auditoria

### 4.2. Blindar contra vazamento de origem

Arquivos principais:

- `app/PlaylistRewriter.php`
- `app/StreamProxy.php`
- `app/CredentialGuard.php`
- `public/proxy.php`

Fazer:

- revisar reescrita de playlist
- revisar redirect manual
- revisar `follow_external_redirects`
- revisar `host_header`
- revisar mascaramento de `username/password`
- revisar `CredentialGuard`

Teste:

- usar `bin/smoke-intelligence.sh <host> <user> <pass>`
- inspecionar playlist retornada
- buscar host do XUI no corpo
- buscar host de `direct source` no corpo

Critério de pronto:

- corpo da resposta publica nunca mostra origem nem direct real

### 4.3. Blindar rotas binarias e redirects externos

Arquivos principais:

- `app/StreamProxy.php`
- `app/DirectSource.php`

Fazer:

- validar comportamento de `movie`, `series`, `live`
- manter allowlist de redirect seguro
- bloquear host privado, loopback, schema invalido
- manter propagacao de `User-Agent` real do cliente

Teste:

- `curl -H 'User-Agent: libmpv' -H 'Range: bytes=0-1' ...movie...`
- `curl -H 'User-Agent: VLC/3.0.20 LibVLC/3.0.20' -H 'Range: bytes=0-1' ...movie...`

Critério de pronto:

- conteudo binario responde `206` nos cenarios validos
- host externo nao aparece para o cliente

## 5. Checklist Fase 3 — Tirar peso do painel

### 5.1. Reduzir polling agressivo

Arquivos principais:

- `public/restream.php`
- `public/restream-data.php`
- `app/RestreamRuntime.php`
- `app/UserIntelligence.php`

Fazer:

- mapear todas as views que fazem polling
- aumentar cache curto onde couber
- reduzir frequencia das views pesadas
- separar endpoints leves de endpoints pesados
- evitar `limit` desnecessariamente alto

Evidencia atual:

- havia polling frequente em `restream-data.php?view=users&limit=400`
- havia polling frequente em `restream-data.php?view=sessions&limit=120`

Critério de pronto:

- painel ao vivo continua util, mas sem pressionar o runtime a cada poucos segundos

### 5.2. Simplificar a tela operacional

Fazer:

- manter no topo so KPIs essenciais
- manter tabela de usuarios enxuta
- manter bloco separado para:
  - direct agora
  - sessoes ao vivo
  - alerta de limite
- remover o que nao agrega no uso diario

Critério de pronto:

- operador encontra a verdade operacional sem depender de varias seções

## 6. Checklist Fase 4 — Controlar o peso dos jobs

Arquivos principais:

- `bin/jobs-run.php`
- `app/JobRunner.php`
- `app/XuiSyncService.php`
- `app/RestreamRuntime.php`
- `app/DirectCatalog.php`

Catalogo real atual:

- `xui_sync_activity`
- `xui_sync_users`
- `xui_sync_streams`
- `direct_enrich`
- `direct_consolidate`
- `match_sessions`
- `session_sweep`
- `consolidate_runtime`
- `detect_inconsistency`
- `metrics_rollup`
- `cleanup`
- `repair_retry`
- `lb_probe`
- `lb_rebalance`
- `lb_cleanup`

### 6.1. Separar jobs leves de jobs pesados

Fazer:

- manter loop rapido so para jobs leves
- mover jobs pesados para perfil separado
- revisar uso de `--profile=fast` e `--profile=heavy`

Critério de pronto:

- jobs pesados nao concorrem com o runtime vivo a todo momento

### 6.2. Reduzir sync pesado do XUI

Fazer:

- manter `xui_sync_activity` curto
- manter `xui_sync_users` moderado
- deixar `xui_sync_streams` manual ou bem mais espaçado

Estado real atual:

- `xui_sync_streams = 300s`

Critério de pronto:

- sync de streams nunca deve travar painel nem player

### 6.3. Garantir observabilidade dos jobs

Fazer:

- usar `job_state`
- usar `job_runs`
- usar passos do `JobRunner`
- expor atrasos e circuito aberto no painel

Teste:

- `php bin/jobs-run.php --list`
- `php bin/jobs-run.php --job=<nome> --force`

Critério de pronto:

- nenhum job importante roda invisivel

## 7. Checklist Fase 5 — Preparar mais LBs

### 7.1. Padronizar onboarding de LB

Arquivos principais:

- `public/lb.php`
- `public/lb-data.php`
- `public/lb-action.php`
- `public/save-lb.php`
- `public/save-lb-route.php`
- `public/lb-ingest.php`
- `app/LbInstaller.php`
- `app/LbPackageBuilder.php`
- `app/LbTelemetry.php`
- `app/LbRouter.php`
- `bin/lb-install.sh`
- `bin/lb-install-run.php`

Fazer:

- deixar cadastro do LB com fluxo unico
- validar instalacao remota
- validar health do LB
- validar recebimento de telemetria
- validar rota forcada por usuario

Critério de pronto:

- cadastrar `LB-02` nao pode exigir improviso manual

### 7.2. Garantir que o main nao vire ponto de banda

Fazer:

- dominio do usuario deve apontar para o LB
- o `main` so decide e audita
- evitar proxy duplo `main -> lb -> destino` no fluxo normal

Critério de pronto:

- conteudo real do usuario deve sair do LB, nao do `main`

### 7.3. Teste padrão para cada novo LB

Executar:

- teste de playlist
- teste de `movie`
- teste de `series`
- teste de `player_api`
- teste de direct source
- teste de telemetria de sessao
- teste de usuario forcado para o LB

Critério de pronto:

- painel mostra saida correta do usuario e o app toca sem interrupcao

## 8. Checklist Fase 6 — Enforcar limite e trava antes do XUI

### 8.1. Trava CDN por IP

Arquivos principais:

- `app/UserIpLock.php`
- `public/xui-user.php`
- `public/save-xui-line.php`
- `public/proxy.php`

Fazer:

- manter tela clara de ativo/inativo
- suportar:
  - IP unico
  - CIDR
  - faixa
  - curinga
- validar antes do XUI

Critério de pronto:

- request invalido morre na CDN e nao toca no XUI

### 8.2. Limite de conexoes

Arquivos principais:

- `app/CdnSession.php`
- `app/Divergence.php`
- `app/UserIntelligence.php`
- `public/proxy.php`

Fazer:

- consolidar contagem de sessoes por usuario
- bloquear excesso no nivel da CDN
- suportar pausa, resume, reconexao e microqueda sem baguncar uptime

Critério de pronto:

- o usuario nao passa do limite mesmo se o XUI estiver atrasado

## 9. Checklist Fase 7 — Uptime e rastreabilidade total

### 9.1. Sessao viva consolidada

Arquivos principais:

- `app/CdnSession.php`
- `app/RequestLog.php`
- `app/AuditTimeline.php`
- `app/DirectSource.php`

Fazer:

- manter `uptime_start_epoch` consistente
- deduplicar bursts de HLS
- separar telas por `stream_id`
- manter heartbeats em stream longo
- preservar uptime em pausas curtas

Critério de pronto:

- o painel nao “pisca” falso enquanto o app segue tocando

### 9.2. Verdade operacional no painel

Fazer:

- por usuario mostrar:
  - plano
  - em uso
  - livres
  - direct
  - IP final
  - app
  - ultimo conteudo
  - saida atual
  - uptime
- por sessao mostrar:
  - usuario
  - tipo
  - IP
  - app
  - conteudo
  - LB

Critério de pronto:

- operador consegue responder “quem esta vendo o que, por onde e ha quanto tempo”

## 10. Checklist Fase 8 — Banco e persistencia

### 10.1. Curto prazo

Fazer:

- reduzir escrita inutil no `SQLite`
- revisar indices
- revisar retencao
- revisar `cleanup`
- separar o maximo possivel de runtime vivo e historico

Critério de pronto:

- `database is locked` deixa de ser evento comum

### 10.2. Medio prazo

Fazer:

- introduzir `Redis` para estado vivo:
  - sessoes
  - heartbeat
  - limite
  - presence
  - rate limit
- introduzir `PostgreSQL` para:
  - configuracao
  - auditoria
  - historico
  - regras

Critério de pronto:

- runtime vivo para de depender do `SQLite`

## 11. Checklist Fase 9 — Futuro motor em Go

Fazer quando as fases anteriores estiverem estaveis.

O motor em `Go` deve assumir:

- proxy de playlist
- proxy binario
- follow de direct source
- enforcement de trava por IP
- enforcement de limite
- sessao viva
- emissao de eventos ao cerebro

Critério de pronto:

- o caminho quente do player deixa de depender de `PHP-FPM`

## 12. Checklist de testes obrigatorios por etapa

### 12.1. Testes de saude

Executar:

- `php bin/jobs-run.php --list`
- `php -r 'require "app/bootstrap-cli.php"; echo json_encode(HealthCheck::run(), JSON_PRETTY_PRINT);'`

Esperado:

- health de configuracao, banco, storage, `php-fpm` e origem `ok`

### 12.2. Testes de restream

Executar:

- `bash bin/smoke-restream.sh`

Esperado:

- schema local ok
- jobs basicos ok
- caminho de stream nao depende do MySQL do XUI

Observacao real de hoje:

- o smoke estrutural confirmou schema, jobs e `consolidate_runtime`
- o script precisa ficar mais robusto para nao ficar pendurado em bloco opcional

### 12.3. Testes de inteligencia

Executar:

- `bash bin/smoke-intelligence.sh <dominio> <user> <pass>`

Esperado:

- agrupamento de sessoes
- contador local independente do XUI
- direct source rastreado
- sem vazamento de origem

### 12.4. Testes reais de app

Executar sempre:

- 2 telas ao mesmo tempo
- troca de conteudo
- pausa e play
- app real com `libmpv` ou equivalente
- validacao de `movie`, `series`, `player_api`, `get.php`

Esperado:

- painel acompanha sem reset falso
- conteudo abre rapido
- sem vazamento

## 13. Definicao de pronto do projeto

O projeto so pode ser tratado como pronto para expansao forte quando cumprir:

- `main` atua como cerebro de verdade
- `LB` entrega stream real
- app abre rapido e estavel
- zero vazamento de XUI e de direct source
- painel mostra a verdade operacional
- jobs ficam visiveis e controlados
- trava por IP e limite funcionam antes do XUI
- runtime nao sofre com lock recorrente
- onboarding de novo LB e repetivel

## 14. Ordem exata de execucao recomendada

Executar nesta ordem:

1. congelar arquitetura `single XUI + cerebro + musculos`
2. blindar `public/proxy.php` e `app/StreamProxy.php`
3. eliminar qualquer vazamento de origem e direct
4. reduzir polling do painel
5. separar jobs leves e pesados
6. estabilizar `LB-01` como saida real
7. padronizar onboarding do `LB-02`
8. endurecer trava CDN por IP e limite por usuario
9. consolidar uptime e rastreabilidade total
10. tirar runtime vivo do `SQLite`
11. introduzir `Redis`
12. introduzir `PostgreSQL`
13. migrar o motor quente para `Go`
14. promover um `main` mais forte quando os LBs pedirem isso

## 15. Conclusao direta

O projeto ja tem muita coisa valiosa pronta.

O que falta agora nao e inventar mais recurso solto.
O que falta e executar em ordem, com criterio de pronto e teste objetivo.

Este checklist existe para isso:

- evitar adivinhacao
- evitar retrabalho
- evitar mudar coisa errada na hora errada
- e levar o projeto para um padrao realmente profissional de protecao, fluidez,
  leveza e escala

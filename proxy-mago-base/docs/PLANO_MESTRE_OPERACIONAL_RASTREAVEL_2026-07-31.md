# Plano-Mestre Operacional — Sistema Rastreável

Data de referencia: `2026-07-31`

Este documento e a versao operacional do projeto.

Ele existe para virar a receita oficial de execucao, com:

- status por item
- ordem exata de implementacao
- criterio de pronto
- validacao
- risco
- evidencias reais do ambiente

Status usados:

- `feito`
- `parcial`
- `pendente`
- `critico`

## 1. Objetivo final do sistema

O sistema final precisa entregar ao mesmo tempo:

- protecao total do XUI
- protecao total do `direct source`
- abertura rapida e fluida dos conteudos
- baixo peso no `main`
- entrega real pelos LBs
- rastreabilidade forte por usuario
- bloqueio de limite antes do XUI
- trava CDN por IP/CIDR/faixa antes do XUI
- preparo para mais LBs
- preparo para um futuro `main` melhor

Diretriz estrutural oficial:

- `PostgreSQL` e o alvo oficial do banco da CDN em producao
- `SQLite` fica como compatibilidade temporaria de transicao
- o runtime quente nao deve permanecer dependente do `SQLite` como desenho final

## 2. Evidencias reais usadas neste plano

Evidencias confirmadas no ambiente atual:

- `HealthCheck::run()` respondeu `ok` para:
  - configuracao
  - SQLite
  - storage
  - socket do `php8.1-fpm`
  - origem XUI
- `php bin/jobs-run.php --list` respondeu e listou o catalogo de jobs
- `bin/smoke-restream.sh` confirmou:
  - schema local existente
  - jobs basicos existentes
  - `consolidate_runtime` funcional
- `get.php` voltou a responder `200`
- `movie` voltou a responder `206` com `User-Agent` de player real
- o fatal de `UserIpLock` no proxy foi corrigido

Evidencias de limite/risco real:

- banco local em `storage/app.sqlite` com cerca de `5.24 GB`
- historico de `database is locked`
- consultas simples no banco ja podem ficar penduradas sob pressao
- `xui_sync_streams` esta em `error`
- `direct_consolidate` esta muito pesado
- `metrics_rollup`, `detect_inconsistency` e `cleanup` tambem tem duracoes altas

Duracoes reais observadas em `job_state`:

- `direct_consolidate`: ~`292353 ms`
- `metrics_rollup`: ~`48110 ms`
- `detect_inconsistency`: ~`19303 ms`
- `cleanup`: ~`40991 ms`
- `xui_sync_streams`: `error`

Leitura direta:

- o projeto esta funcional
- o projeto ja entrega valor real
- o gargalo atual esta no runtime de persistencia e nos jobs pesados

## 3. Receita final desenhada

Arquitetura final desejada:

```text
Painel/Admin (main)
  -> regras
  -> usuarios
  -> auditoria
  -> cadastro de LBs
  -> API interna
  -> PostgreSQL central da CDN

LBs
  -> playlist
  -> movie
  -> live
  -> series
  -> direct source
  -> enforcement antes do XUI

Estado vivo
  -> sessoes
  -> heartbeat
  -> limite
  -> trava por IP
  -> uptime
  -> compatibilidade temporaria com SQLite enquanto a migracao nao termina

Auditoria consolidada
  -> quem
  -> IP
  -> app
  -> conteudo
  -> saida
  -> tempo
  -> divergencia
```

## 4. Plano-mestre por bloco

## Bloco A — Congelar a arquitetura correta

### A.1. Projeto oficialmente `single XUI`

Status: `feito`

Evidencia:

- existe `docs/ARQUITETURA_SINGLE_XUI_2026-07-31.md`
- o escopo principal ja foi redirecionado para `1` XUI

Objetivo:

- impedir que o projeto volte a se desviar para `multi-XUI`

Critério de pronto:

- toda nova implementacao principal respeita `single XUI`

### A.2. `main` como cerebro e `LB` como musculo

Status: `parcial`

Evidencia:

- existe `LbRouter`
- existe tela `LB`
- existe `LB-01`
- usuarios ja podem ser roteados para LB
- ainda ha pontos em que o `main` continua pesado demais

Fazer:

- garantir que o dominio do cliente toque pelo LB
- reduzir o papel do `main` no caminho quente
- consolidar o LB como saida real em todos os fluxos

Critério de pronto:

- o `main` decide
- o `LB` entrega

## Bloco B — Blindar o proxy publico

### B.1. Caminho quente sem dependencias desnecessarias

Status: `parcial`

Evidencia:

- `app/proxy-bootstrap.php` existe e e enxuto
- o fatal de `UserIpLock` foi resolvido nele
- o stream voltou a responder

Fazer:

- revisar toda dependencia carregada no proxy
- garantir que nada de painel/admin contamine o caminho quente
- manter logs no modo best-effort

Critério de pronto:

- falha de telemetria nao derruba app

### B.2. Protecao contra vazamento de origem

Status: `parcial`

Evidencia:

- existem `PlaylistRewriter`, `CredentialGuard` e redirect manual
- o projeto ja tenta mascarar origem e `direct source`
- houve validacao real de `get.php` e `movie`

Fazer:

- repetir testes automatizados de vazamento em playlist
- validar `player_api`
- validar `series`
- validar todos os redirects de `direct source`

Critério de pronto:

- nenhuma origem aparece em body, header ou redirect

### B.3. Rotas binarias estaveis

Status: `parcial`

Evidencia:

- `movie/...` respondeu `206` com `User-Agent` real
- o fluxo binario voltou a abrir

Fazer:

- repetir teste real com:
  - `movie`
  - `series`
  - `live`
- validar uso real de Range
- validar app por mais tempo

Critério de pronto:

- stream binario abre rapido e permanece estavel

## Bloco C — Rastreabilidade viva

### C.1. Sessao local da CDN

Status: `parcial`

Evidencia:

- existem `CdnSession`, `RequestLog`, `AuditTimeline`
- existe `uptime_start_epoch`
- existe heartbeat em `StreamProxy`

Fazer:

- validar pausa e play
- validar troca de conteudo
- validar duas telas simultaneas
- validar microqueda sem reset falso de uptime

Critério de pronto:

- painel nao pisca falso com conteudo ainda aberto

### C.2. Direct source totalmente rastreado

Status: `parcial`

Evidencia:

- existem `DirectSource`, `DirectCatalog`, `direct_source_hops`
- houve registros reais de host final seguido
- o sistema ja detecta `readyondemand.click` e outros hosts

Fazer:

- consolidar verdade de runtime + catalogo do XUI
- reduzir atraso de consolidacao pesada
- mostrar host efetivo e LB de saida de forma estavel

Critério de pronto:

- operador ve com clareza o que esta em direct agora

### C.3. Tela operacional como verdade unica

Status: `parcial`

Evidencia:

- `restream.php` ja foi simplificado
- ha blocos de usuarios, direct e sessoes
- usuario quer mais estabilidade e menos oscilacao

Fazer:

- manter apenas KPIs essenciais
- estabilizar a leitura ao vivo
- adicionar uptime claro
- manter foco em:
  - usuario
  - em uso
  - livres
  - IP final
  - app
  - conteudo
  - saida
  - uptime

Critério de pronto:

- uma tela responde o que esta acontecendo agora

## Bloco D — Trava e enforcement antes do XUI

### D.1. Trava CDN por IP

Status: `parcial`

Evidencia:

- existe `UserIpLock`
- existe UI em `xui-user.php`
- existe persistencia em `save-xui-line.php`
- ja suporta IP, CIDR, faixa e curinga

Fazer:

- validar com mais de um usuario real
- validar troca de IPs na tela de usuario
- validar refleto correto no proxy

Critério de pronto:

- acesso invalido morre na CDN sem tocar o XUI

### D.2. Limite de conexao pela CDN

Status: `parcial`

Evidencia:

- existem `Divergence`, `CdnSession`, `UserIntelligence`
- existe bloqueio por `above_limit_blocked`

Fazer:

- validar bloqueio real com usuario de 1 conexao
- validar duas telas no mesmo usuario
- validar que o sistema bloqueia e rastreia corretamente

Critério de pronto:

- a CDN passa a ser a fonte de verdade do limite

## Bloco E — Jobs e operacao interna

### E.1. Catalogo de jobs e observabilidade

Status: `feito`

Evidencia:

- `php bin/jobs-run.php --list` funciona
- `JobRunner` tem:
  - lock
  - step
  - circuit breaker
  - `job_runs`
  - `job_state`

Critério de pronto:

- job nenhum roda invisivel

### E.2. Jobs leves e pesados ainda competindo

Status: `critico`

Evidencia:

- `direct_consolidate` ~`292353 ms`
- `metrics_rollup` ~`48110 ms`
- `cleanup` ~`40991 ms`
- `detect_inconsistency` ~`19303 ms`

Risco:

- pressao no `SQLite`
- atraso do painel
- lock recorrente
- perda de fluidez do runtime

Fazer:

- separar perfil `fast` e `heavy` na operacao real
- tirar jobs pesados do loop frequente
- reduzir acoplamento com o runtime vivo

Critério de pronto:

- jobs pesados nao atrapalham stream e painel ao vivo

### E.3. `xui_sync_streams` instavel

Status: `critico`

Evidencia:

- `xui_sync_streams` esta com `last_status = error`

Fazer:

- auditar erro real do job
- revisar leitura do catalogo de streams do XUI
- considerar tornar esse sync manual ou ainda mais espacado ate estabilizar

Critério de pronto:

- `xui_sync_streams` deixa de falhar e deixa de pressionar o sistema

## Bloco F — Banco e persistencia

### F.1. SQLite ainda sustenta o projeto

Status: `parcial`

Evidencia:

- banco conecta
- schema existe
- sistema continua operando

Critério de pronto:

- pode seguir como base temporaria

### F.2. SQLite como gargalo operacional

Status: `critico`

Evidencia:

- tamanho atual ~`5.24 GB`
- historico de `database is locked`
- ate consultas simples podem ficar penduradas sob pressao
- jobs pesados ainda pressionam o runtime

Fazer:

- reduzir escrita inutil
- revisar indices e retencao
- reduzir polling do painel
- preparar separacao entre estado vivo e historico

Critério de pronto:

- lock deixa de ser risco recorrente

### F.3. Migracao futura para runtime melhor

Status: `pendente`

Fazer:

- introduzir `PostgreSQL` para:
  - `cdn_sessions`
  - `proxy_request_events`
  - `proxy_user_runtime`
  - `job_state`
  - `job_runs`
  - auditoria
  - historico
  - regras centrais
- manter `SQLite` somente como camada temporaria de compatibilidade
- introduzir `Redis` depois para:
  - sessoes
  - heartbeat
  - contadores
  - limite
  - presence

Critério de pronto:

- `PostgreSQL` vira persistencia principal da CDN
- `SQLite` deixa de ser gargalo central do operacional

## Bloco G — Painel e polling

### G.1. Painel operacional existe

Status: `feito`

Evidencia:

- existem `restream.php`, `restream-data.php`, `restream-user.php`

### G.2. Polling ainda pesado

Status: `critico`

Evidencia:

- historico real de polling frequente em:
  - `view=users&limit=400`
  - `view=sessions&limit=120`
- impacto percebido no ambiente

Fazer:

- reduzir frequencia
- usar cache curto
- separar endpoint leve de pesado
- revisar limites padrao

Critério de pronto:

- painel fica leve sem perder valor operacional

## Bloco H — LB e expansao

### H.1. Estrutura base de LB existe

Status: `feito`

Evidencia:

- existem:
  - `LbRouter`
  - `LbInstaller`
  - `LbTelemetry`
  - `lb.php`
  - `lb-data.php`
  - `lb-action.php`
  - `lb-ingest.php`

### H.2. `LB-01` ja e realidade

Status: `parcial`

Evidencia:

- ha um LB real
- usuarios ja foram roteados para ele
- playlist e conteudo real ja passaram pelo fluxo dele

Fazer:

- estabilizar totalmente o `LB-01`
- repetir testes em 2 ou 3 usuarios
- padronizar fluxo para `LB-02`

Critério de pronto:

- novo LB entra sem improviso

### H.3. Onboarding profissional de novos LBs

Status: `parcial`

Fazer:

- padronizar instalacao
- padronizar healthcheck
- padronizar telemetria
- padronizar teste de entrada em producao

Critério de pronto:

- `LB-02` e `LB-03` podem ser adicionados com receita repetivel

## Bloco I — Futuro motor quente

### I.1. `PHP-FPM` ainda no caminho quente

Status: `parcial`

Evidencia:

- `public/proxy.php` ainda e o caminho central
- `StreamProxy.php` ainda carrega stream no runtime atual

### I.2. Motor quente em Go

Status: `pendente`

Fazer:

- projetar engine de stream em `Go`
- mover:
  - playlist
  - binario
  - direct source
  - enforcement
  - sessao viva

Critério de pronto:

- player deixa de depender do `PHP-FPM` para alta escala

## 5. Receita mastigada de execucao

Executar exatamente nesta ordem:

### Etapa 1

Status atual: `parcial`

Fazer:

- congelar o projeto como `single XUI`
- consolidar `main = cerebro`
- consolidar `LB = entrega`

Resultado esperado:

- nenhuma duvida de arquitetura

### Etapa 2

Status atual: `parcial`

Fazer:

- blindar o proxy publico
- revisar `proxy-bootstrap.php`
- revisar `proxy.php`
- revisar `StreamProxy.php`

Resultado esperado:

- stream nao cai por falha lateral

### Etapa 3

Status atual: `parcial`

Fazer:

- validar que nada do XUI vaza
- validar que nada do direct vaza
- automatizar smoke de vazamento

Resultado esperado:

- cliente nunca ve origem

### Etapa 4

Status atual: `critico`

Fazer:

- aliviar polling do painel
- aliviar jobs pesados
- estabilizar `xui_sync_streams`

Resultado esperado:

- sistema leve e fluido no hardware atual

### Etapa 5

Status atual: `parcial`

Fazer:

- estabilizar `LB-01`
- repetir testes reais com mais usuarios
- padronizar onboarding de `LB-02`

Resultado esperado:

- projeto pronto para mais LBs

### Etapa 6

Status atual: `parcial`

Fazer:

- endurecer trava CDN por IP
- endurecer limite por conexao
- validar enforcement antes do XUI

Resultado esperado:

- seguranca real na borda

### Etapa 7

Status atual: `parcial`

Fazer:

- consolidar uptime real
- consolidar sessoes
- consolidar direct source agora

Resultado esperado:

- painel responde a verdade operacional

### Etapa 8

Status atual: `critico`

Fazer:

- tirar peso do `SQLite`
- separar runtime vivo e historico
- preparar promote seguro para `PostgreSQL`

Resultado esperado:

- sistema para de sofrer com lock recorrente

### Etapa 9

Status atual: `pendente`

Fazer:

- introduzir `PostgreSQL` como persistencia principal
- manter compatibilidade controlada com `SQLite`
- introduzir `Redis` na etapa seguinte

Resultado esperado:

- base pronta para mais escala

### Etapa 10

Status atual: `pendente`

Fazer:

- migrar motor quente para `Go`
- preparar `main` novo no futuro

Resultado esperado:

- caminho profissional de escala alta

## 6. O que hoje ja pode ser tratado como pronto

- arquitetura principal focada em `1` XUI
- painel/admin funcional
- healthcheck funcional
- estrutura de jobs funcional
- base de roteamento para LB existente
- base de trava por IP existente
- base de rastreabilidade existente
- fluxo publico de `get.php` e `movie` funcional

## 7. O que hoje exige intervencao prioritaria

Itens mais urgentes:

- `SQLite` como gargalo de runtime
- polling do painel
- jobs pesados
- `xui_sync_streams` com erro
- estabilizacao final do uptime/rastreabilidade ao vivo
- padronizacao definitiva para novos LBs

## 8. Definicao oficial de pronto

O sistema so pode ser considerado pronto para expansao forte quando estes itens
estiverem no minimo como `feito` ou `parcial controlado`:

- zero vazamento de XUI
- zero vazamento de `direct source`
- app abre rapido e estavel
- painel mostra a verdade de usuario, app, conteudo, IP e saida
- limite e trava por IP acontecem antes do XUI
- `LB-02` pode ser implantado com receita repetivel
- jobs pesados nao atrapalham o runtime
- banco nao fica mais travando o operacional

## 9. Conclusao operacional

Este documento e a receita mastigada do projeto.

Ele mostra:

- o que ja esta pronto
- o que esta no meio do caminho
- o que esta pendente
- e o que e critico agora

O ponto principal e simples:

- a base do sistema ja existe
- a protecao central ja comecou a existir
- a escala profissional depende agora de executar em ordem
- e a prioridade numero `1` e aliviar o runtime atual sem perder rastreabilidade

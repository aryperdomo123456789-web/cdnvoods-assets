# Plano Especialista — Rastreabilidade Total, Multi-LB e Painel Vivo

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data: 2026-07-31  
Ambiente real alvo: VPS `45.140.192.237`  
Sistema operacional alvo: `Ubuntu 22.04`  
Path real do projeto: `/opt/proxy-mago/proxy-mago-base`  

Este documento descreve o estado real atual do projeto nesta VPS, os testes executados, por que o painel parece travado e tudo que ainda falta para o sistema chegar no nível pedido:

- 100% rastreável
- tudo auditável
- jobs internos rastreáveis
- múltiplos LBs
- balanceamento por usuário do XUI
- visão ao vivo confiável
- operação leve o suficiente para cérebro + músculos

## 1. Resumo Executivo

O projeto já tem a arquitetura-base correta:

- cérebro na VPS principal
- proxy público leve
- espelho local do XUI
- rastreamento por sessão
- trilha de direct source
- módulo de LB em andamento

Mas ele ainda não está no estado final desejado porque há quatro lacunas estruturais abertas:

1. o banco local SQLite sofre contenção de escrita em produção
2. alguns jobs internos quebram ou degradam sob carga e sob schema divergente
3. o módulo de LB ainda não fechou o smoke final do músculo
4. a rastreabilidade total ainda não está consolidada em uma trilha única de verdade operacional

Em linguagem direta: o sistema já enxerga muita coisa, mas ainda não consegue garantir, de forma estável e auditável, que tudo que passou por ele será sempre registrado, reconciliado e exibido ao vivo sem distorção.

## 2. O que foi testado neste turno

Testes feitos diretamente nesta VPS e neste path:

- leitura do estado vivo do SQLite local
- contagem de sessões ativas e eventos recentes
- inspeção de `php-error.log`
- inspeção de `jobs.log`
- inspeção do `jobs-run.php`
- inspeção do estado dos jobs em `job_state`
- tentativa de medir latência real de consultas do painel por CLI
- validação do fluxo real de instalação do LB

Resultados observados:

- `cdn_sessions` ativos: 16
- `proxy_request_events` nos últimos 5 minutos: 111
- `lb_installs`: 12 linhas
- `lb_sync_events`: 3 linhas

Também foi observado que o comando de medição direta de consultas do painel por CLI excedeu `15s` e foi encerrado por timeout, o que confirma sintoma real de travamento/lentidão, não apenas impressão visual.

## 3. Diagnóstico: por que o painel parece travado

O painel parece travado por combinação de três fatores, e não por um único bug isolado.

### 3.1. Contenção real de escrita no SQLite

Os logs mostram repetidamente:

- `database is locked` em `requestlog`
- `database is locked` em `accessguard`
- `database is locked` em `cdnsession`
- `database is locked` em `heartbeat`
- `database is locked` em `rate_limit`

Isso significa que o mesmo SQLite local está recebendo gravação concorrente de:

- requests públicos
- heartbeat de sessão
- contador de conexões
- jobs internos
- rastreamento de inconsistência
- módulo de LB

Quando esse volume coincide, partes importantes do painel ficam sem atualização ou aguardando lock.

### 3.2. Jobs pesados rodando no mesmo banco quente

O `jobs-run.php` roda por loop por até ~55s, disparando jobs frequentes:

- `xui_sync_activity` a cada 5s
- `match_sessions` a cada 10s
- `session_sweep` a cada 10s
- `consolidate_runtime` a cada 10s
- `detect_inconsistency` a cada 30s
- `metrics_rollup` a cada 30s

Além disso, alguns jobs maiores já mostraram duração alta:

- `direct_consolidate`: `123788 ms`
- `cleanup`: `11451 ms`
- `detect_inconsistency`: `18616 ms`
- `xui_sync_streams`: `13900 ms`

Mesmo quando o status final aparece como `ok`, esse padrão de frequência + duração cria janelas de contenção contra o tráfego público e contra o painel.

### 3.3. Falhas intermitentes e históricas na camada de jobs

Os logs mostram histórico de problemas que fragilizam o estado do painel:

- database de XUI incorreta em momentos anteriores: `Unknown database 'xtream_iptvpro'`
- tabela ausente: `xui.user_activity_now`
- coluna divergente: `Unknown column 'max_connections'`
- migração não idempotente em `Database.php`: `duplicate column name: direct_proxy`
- colisão de integridade em `cdn_divergences`
- falha de lockfile em `jobs-run.lock`: `Permission denied`

Mesmo quando parte disso já foi parcialmente ajustada depois, o histórico mostra que o pipeline de jobs ainda não está endurecido o suficiente para produção pesada.

## 4. Estado atual do módulo LB

Foi possível validar avanço real do LB `143.14.168.78`:

- `validate` ok
- `keygen` ok
- `handshake` ok
- `install_key` ok
- `key_smoke` ok
- `detect` ok
- `support` ok
- `bootstrap` ok
- `package` ok
- `configure` ok

Hardware detectado no LB:

- Ubuntu `22.04`
- `3 vCPU`
- `7872 MB RAM`
- `23 GB` livres

Ponto ainda aberto:

- `smoke` final do LB falhou com `health=404` e `proxy=404`

Interpretação:

- o onboarding por chave SSH já funciona
- o nó foi provisionado
- o cérebro já consegue falar com o músculo
- mas o vhost final do Nginx no LB ainda não está servindo o proxy mínimo como deveria

Conclusão do LB:

- o módulo multi-LB está avançado, mas ainda não está pronto para receber usuários reais com segurança operacional

## 5. O que já existe de rastreabilidade

O projeto já possui blocos relevantes:

- `request_id` por request
- `session_key` por sessão lógica
- `cdn_sessions`
- `proxy_request_events`
- `proxy_user_runtime`
- `direct_source_hops`
- `xui_users_cache`
- `xui_streams_cache`
- `xui_activity_now_cache`
- `cdn_divergences`
- `job_state`
- `lb_nodes`
- `lb_installs`
- `lb_sync_events`

Isso é uma base boa. O problema não é “falta total de rastreamento”; o problema é consistência, tolerância a falha e consolidação.

## 6. O que falta para ficar 100% rastreável

### 6.1. Trilho único de auditoria operacional

Hoje a informação está espalhada em várias tabelas. Falta uma visão consolidada que permita responder, sem ambiguidade:

- quem foi o usuário
- em qual domínio entrou
- por qual LB passou
- qual stream pediu
- qual host final foi alcançado
- quantas conexões ele consumiu
- qual regra o roteou
- se houve fallback
- qual job consolidou esse estado
- qual divergência foi aberta ou fechada

Implementação recomendada:

- criar uma trilha consolidada de sessão/rota, por exemplo `traffic_audit_timeline`
- gravar:
  - `request_id`
  - `session_key`
  - `username`
  - `public_host`
  - `client_ip`
  - `user_agent`
  - `route_kind`
  - `stream_id`
  - `stream_name`
  - `lb_id`
  - `lb_label`
  - `origin_id`
  - `direct_source`
  - `direct_host_effective`
  - `decision_source`
  - `decision_reason`
  - `status`
  - `bytes`
  - `opened_at`
  - `last_seen_at`
  - `closed_at`

### 6.2. Auditoria total de jobs

O pedido exige rastrear “todos os jobs”. Para isso, falta padronizar:

- `job_run_id` global por execução
- início/fim
- duração
- lock adquirido ou não
- quantidade processada
- quantidade falhada
- erro sanitizado
- tabela afetada
- checkpoint do job

Implementação recomendada:

- expandir `job_state`
- criar `job_run_history`
- criar `job_step_history` quando o job tiver múltiplas fases
- garantir que todo job escreva:
  - começo
  - fim
  - partial
  - retry
  - timeout
  - locked

### 6.3. Contador de conexões realmente autoritativo

Hoje o sistema já conta bastante coisa localmente, mas para virar fonte de verdade final precisa:

- tratar reconnect curto sem criar sessão falsa
- tratar direct source sem inflar
- tratar live/HLS por sessão lógica, não por segmento
- tratar queda de player e troca de stream em tempo real
- marcar fonte da contagem:
  - `cdn_local`
  - `xui_activity`
  - `merged`
  - `degraded`

### 6.4. Roteamento real por usuário para múltiplos LBs

O objetivo final é definir por qual LB cada usuário vai passar.

Faltam estas garantias:

- rota fixa por usuário
- modo automático por score
- fallback para cérebro se LB cair
- histórico de mudança de rota
- prova em painel de “quem passou por qual LB e por quê”

Implementação recomendada:

- consolidar `lb_user_routes`
- adicionar histórico `lb_route_history`
- toda decisão de rota precisa registrar:
  - `username`
  - `route_mode`
  - `chosen_lb_id`
  - `score_snapshot`
  - `reason`
  - `fallback_used`
  - `ts`

### 6.5. Telemetria contínua dos LBs

Cada LB precisa devolver para o cérebro:

- CPU
- RAM
- tráfego atual
- sessões locais
- erros de proxy
- status do Nginx
- status do PHP-FPM
- versão do pacote
- timestamp do último heartbeat

Sem isso, o cérebro não consegue balancear com segurança.

### 6.6. Painel realmente “ao vivo”

Para o operador confiar no painel, faltam:

- carimbo `last_refresh_at`
- carimbo `data_age_ms`
- origem do dado em cada card
- aviso quando o painel está em modo degradado
- aviso quando o XUI sync está quebrado
- aviso quando a contagem está vindo só da CDN
- aviso quando um LB está degradado

## 7. Causa raiz mais provável do “painel travado”

Com base nos testes feitos neste turno, a causa raiz mais provável é:

1. um único SQLite local sustentando tráfego vivo + jobs + auditoria + LB
2. jobs longos e frequentes disputando escrita
3. falhas de jobs em loop ampliando a pressão no banco
4. alguns caminhos do painel executando agregações sob contenção

Em outras palavras: o painel não está travado por frontend; ele está travando porque o backend de estado e auditoria está sobrecarregado e com contenção.

## 8. Plano Especialista de Implementação

### Fase 0 — Estabilização do cérebro

Objetivo: parar de perder rastreamento e impedir travamento do painel.

Entregas:

- corrigir todas as migrações não idempotentes em `Database.php`
- revisar todos os `CREATE INDEX` e `ALTER TABLE`
- remover falhas de `duplicate column`
- remover colisões recorrentes em `cdn_divergences`
- endurecer `jobs-run` contra lock e contra schema divergente
- garantir lockfile estável
- revisar owner/permissão de `storage/`
- padronizar retry com backoff para toda escrita crítica do SQLite

Saída esperada:

- zero erro recorrente de migração
- zero erro recorrente de `jobs-run.lock`
- forte redução de `database is locked`

### Fase 1 — Separação de carga operacional

Objetivo: fazer o painel parar de competir com o tráfego vivo.

Entregas:

- mover trilha quente de request/session para armazenamento mais robusto
- opções:
  - SQLite separado por domínio de escrita
  - ou Redis para sessão/heartbeat/rate-limit
  - ou MySQL/PostgreSQL para trilha operacional
- manter SQLite apenas para configuração leve, se desejado

Recomendação especialista:

- Redis para:
  - heartbeat
  - sessão viva
  - rate limit
  - cache do painel
- banco relacional robusto para:
  - auditoria
  - timeline
  - histórico de rota
  - jobs
  - divergências

Sem essa separação, a meta de “100% ao vivo” tende a sofrer em horários de pico.

### Fase 2 — Consolidação da rastreabilidade total

Objetivo: qualquer tráfego ser reconstruível ponta a ponta.

Entregas:

- `traffic_audit_timeline`
- `job_run_history`
- `job_step_history`
- `lb_route_history`
- `session_decision_log`
- `realtime_presence_state`

Cada request relevante precisa conseguir responder:

- quem
- quando
- como entrou
- por onde passou
- o que consumiu
- qual host final atingiu
- quantas conexões ocupou
- qual job consolidou o estado

### Fase 3 — Multi-LB real

Objetivo: aceitar muitos LBs e escolher qual usuário passa por qual LB.

Entregas:

- cadastro múltiplo de LBs
- heartbeat de cada LB
- score por:
  - CPU
  - RAM
  - tráfego
  - sessões abertas
  - erros recentes
  - latência de heartbeat
- modos de rota:
  - `main_only`
  - `auto`
  - `forced_lb`
  - `drain`
  - `disabled`

Também precisa:

- balanceamento em lote por lista de usuários
- rota fixa por usuário
- fallback para cérebro
- histórico de rebalance

### Fase 4 — Painel operacional elite

Objetivo: o operador enxergar tudo ao vivo sem dúvida.

Blocos necessários:

- visão ao vivo consolidada
- usuários do XUI
- sessões locais
- requests recentes
- timeline por usuário
- timeline por LB
- jobs internos
- divergências
- saúde do cérebro
- saúde dos músculos

Filtros mínimos:

- usuário
- domínio
- LB
- IP
- player
- tipo de consumo
- direct source
- status
- acima do limite

### Fase 5 — Smoke tests e aceite de produção

Objetivo: provar que o sistema está pronto.

Checklist:

- get.php via cérebro
- live via cérebro
- movie direct via cérebro
- same user em dois players
- troca de filme
- troca de LB
- queda de LB
- fallback pro cérebro
- reconciliação de contagem
- painel atualizando sem travar
- jobs executando sem erro

## 9. O que precisa ser implementado em código

### Banco e consistência

- abstração de retry para todas as escritas críticas
- revisão completa das migrações idempotentes
- deduplicação segura em `cdn_divergences`
- store separado para trilha quente

### Jobs

- `job_run_history`
- estado por etapa
- retry controlado
- timeout explícito
- circuit breaker para job que falha em loop
- pause automático de jobs não críticos sob contenção

### Sessões e requests

- estado em memória/Redis para heartbeat
- flush assíncrono para trilha consolidada
- encerramento determinístico
- reconciliação de sessão fantasma

### LB

- fechar smoke do vhost
- heartbeat real do músculo
- agente de telemetria estável
- histórico de rota por usuário
- fallback automático

### Painel

- cards com idade do dado
- degrade mode explícito
- auto refresh resiliente
- telas separadas por domínio:
  - tráfego
  - jobs
  - LBs
  - divergências
  - auditoria

## 10. Prioridade recomendada

Ordem sugerida de execução:

1. estabilizar o cérebro e o banco
2. corrigir jobs e migrações
3. fechar LB-01 até smoke verde
4. separar trilha quente do SQLite
5. consolidar rastreabilidade total
6. liberar multi-LB real
7. fechar painel elite ao vivo

## 11. Veredito atual

Estado real hoje:

- arquitetura boa
- rastreabilidade parcial já útil
- módulo LB bem avançado
- painel com dados reais
- mas o sistema ainda não é “100% rastreável” no padrão pedido

Motivos:

- contenção do SQLite
- jobs instáveis sob carga e sob divergência de schema
- LB ainda não saudável no smoke final
- ausência de trilha consolidada única de auditoria

## 12. Definição de pronto

Considerar o sistema “como o usuário quer” apenas quando:

- o painel atualizar continuamente sem travar
- nenhum job crítico falhar em loop
- cada request relevante for auditável ponta a ponta
- cada sessão mostrar usuário, domínio, LB, IP, player e consumo
- a contagem local da CDN for fonte de verdade confiável
- múltiplos LBs puderem ser cadastrados e usados de verdade
- o operador puder fixar ou deixar automático por usuário
- o fallback entre cérebro e músculos funcionar
- toda automação interna tiver log, status, duração e erro rastreável

## 13. Próximo passo recomendado

Próximo passo técnico ideal:

1. corrigir backend de estado antes de expandir funcionalidade
2. fechar o smoke do `LB-01`
3. migrar sessão/heartbeat/rate-limit para camada menos contenciosa
4. implementar trilha consolidada de auditoria
5. só então ampliar para muitos LBs e rebalanceamento real em produção


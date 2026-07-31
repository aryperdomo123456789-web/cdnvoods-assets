# Plano de Produção — Restreamento em Tempo Real

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data: 2026-07-31

## Escopo obrigatório de ambiente

Este plano vale para **este projeto rodando nesta VPS real**:

- VPS: `45.140.192.237`
- OS: `Ubuntu 22.04`
- Projeto ativo: `/opt/proxy-mago/proxy-mago-base`
- GitHub alvo: `aryperdomo123456789-web/cdnvoods-assets`

Não interpretar este documento como um plano genérico para o ambiente da
Lovable. O objetivo é ela implementar, testar no repositório dela e entregar
via GitHub algo que venha para esta VPS com o mínimo possível de ajuste manual.

## Objetivo

Adicionar ao painel da CDN uma área de **restreamento em tempo real** mostrando:

- usuário
- domínio público usado
- IP do cliente
- player / User-Agent
- quantas conexões ativas ele está usando agora
- limite de conexões do usuário no XUI
- última atividade
- tipo de consumo: `live`, `movie`, `series`, `m3u`, `api`

Além disso, o sistema deve ser auditável o bastante para provar que:

- o proxy não embaralha usuários
- o `username/password` que entrou é o mesmo que saiu nas URLs reescritas
- cada consumo público consegue ser rastreado até uma sessão ativa
- o painel local mostra o estado atual sem depender de inspeção manual no XUI

## Conclusão técnica

Sim, dá para fazer isso sem transformar esta VPS em algo pesado.

O melhor desenho **não** é consultar o banco do XUI em toda requisição pública.
O desenho correto é:

1. o proxy continua leve e síncrono no caminho crítico
2. o XUI entra como **fonte read-only de verdade operacional**
3. o painel local mantém uma visão espelho em SQLite para leitura rápida
4. a atualização “em tempo real” é feita por polling curto no painel e por sync
   frequente do XUI para SQLite

## Evidências técnicas levantadas

### Projeto local atual

O projeto já tem:

- SQLite local via [app/Database.php](/opt/proxy-mago/proxy-mago-base/app/Database.php:1)
- log local de proxy em `access_log`
- captura de `client_ip`, `host`, `path`, `status`, `bytes` e `reason`
- origem XUI interna no SQLite local
- proxy público por [public/proxy.php](/opt/proxy-mago/proxy-mago-base/public/proxy.php:1)
- painel atual por [public/dashboard.php](/opt/proxy-mago/proxy-mago-base/public/dashboard.php:1)

### O que já existe no SQLite local

Tabelas atuais:

- `origins`
- `aliases`
- `tokens`
- `access_log`
- `settings`
- `rate_limit`
- `audit_logs`

### O que o schema XUI oferece

No schema público de referência do Xtream/XUI, aparecem:

- tabela `users` com `username`, `password`, `max_connections`, `is_restreamer`
- tabela `user_activity_now` com `user_id`, `stream_id`, `user_agent`,
  `user_ip`, `container`, `date_start`, `date_end`, `hls_last_read`
- tabela `user_activity` com histórico semelhante
- tabela `streams` com `id`, `type`, `direct_source`, `stream_display_name`

Referências usadas:

- `users`, `max_connections`, `user_activity`, `user_activity_now` e `streams`
  no schema público: [database.sql](https://github.com/ProTechEx/xtream-codes-decoded-v2.9/blob/master/database.sql)
- uso operacional de `user_activity_now` em código Xtream UI:
  [extend.php](https://github.com/gear259/XtreamUI/blob/master/extend.php)

## O problema real a resolver

Hoje o proxy local sabe:

- de qual IP veio a requisição
- em qual domínio público ela entrou
- qual rota foi acessada
- quanto saiu
- quando aconteceu

Mas ele **não sabe ainda**, de forma estruturada:

- qual usuário XUI foi autenticado na origem
- quantas conexões ativas esse usuário tem no XUI neste instante
- se um determinado `.ts`, `.m3u8`, `/movie/`, `/series/` ou `/live/` pertence a
  uma sessão já conhecida
- qual é o limite oficial de conexões do usuário na origem

## Arquitetura recomendada

### Regra principal

Não usar MySQL do XUI no caminho quente de `get.php`, `player_api.php`, `xmltv.php`
ou segmentos de mídia.

### Desenho alvo

Fluxo:

1. request público entra no proxy local
2. proxy registra evento local estruturado em SQLite
3. painel consulta SQLite local para tela rápida
4. sincronizador read-only consulta MySQL do XUI em intervalo curto
5. sincronizador atualiza tabelas espelho no SQLite local
6. dashboard de restreamento cruza:
   - eventos do proxy
   - sessões ativas no XUI
   - usuários e limites do XUI

### Por que esse desenho é o melhor

- mantém o stream leve
- evita estourar conexões MySQL no XUI
- reduz latência do painel
- permite auditoria histórica mesmo se o XUI limpar a sessão
- deixa o estado “tempo real” suficientemente próximo do real

## Modelo de dados local recomendado

Adicionar ao SQLite local:

### 1. `xui_sync_config`

Guarda conexão read-only do XUI:

- `id`
- `host`
- `port`
- `database_name`
- `username`
- `password`
- `use_tls`
- `sync_enabled`
- `sync_interval_seconds`
- `connect_timeout_seconds`
- `read_timeout_seconds`
- `last_sync_at`
- `last_sync_status`
- `last_sync_error`

### 2. `xui_users_cache`

Espelho mínimo dos usuários XUI:

- `user_id`
- `username`
- `password_hash_or_mask`
- `max_connections`
- `enabled`
- `exp_date`
- `is_trial`
- `is_restreamer`
- `allowed_ips`
- `allowed_ua`
- `updated_at`
- `synced_at`

Observação:

- não guardar senha em claro se não for estritamente necessário
- se o painel precisar localizar por `username/password`, guardar:
  - `username`
  - `password_masked`
  - `credential_fingerprint`

### 3. `xui_streams_cache`

Espelho mínimo de streams:

- `stream_id`
- `type`
- `stream_display_name`
- `category_id`
- `direct_source`
- `target_container`
- `updated_at`
- `synced_at`

### 4. `xui_activity_now_cache`

Espelho das sessões ativas:

- `activity_id`
- `user_id`
- `stream_id`
- `server_id`
- `user_agent`
- `user_ip`
- `container`
- `date_start`
- `date_end`
- `hls_last_read`
- `hls_end`
- `updated_at`
- `synced_at`

### 5. `proxy_request_events`

Log estruturado por request do proxy público:

- `id`
- `request_id`
- `ts`
- `client_ip`
- `public_host`
- `path`
- `route_kind`
- `username`
- `credential_fingerprint`
- `token_id`
- `origin_id`
- `status`
- `bytes`
- `user_agent`
- `referer`
- `method`
- `query_masked`
- `match_confidence`

### 6. `proxy_session_links`

Tabela de vínculo entre request local e sessão XUI:

- `id`
- `request_id`
- `activity_id`
- `user_id`
- `stream_id`
- `matched_by`
- `matched_at`

### 7. `proxy_user_runtime`

View materializada ou tabela de leitura rápida para o painel:

- `username`
- `public_host_last_seen`
- `client_ip_last_seen`
- `user_agent_last_seen`
- `active_connections_now`
- `max_connections`
- `last_activity_at`
- `last_route_kind`
- `last_stream_id`
- `last_stream_name`
- `health_status`

## Classificação do tipo de consumo

O painel deve mostrar:

- `m3u`: `get.php`
- `api`: `player_api.php`, `xmltv.php`, `panel_api.php`
- `live`: path começando com `/live/`
- `movie`: path começando com `/movie/`
- `series`: path começando com `/series/`
- `hls`: `.m3u8`, `output=hls`, `/hls/`

Regra:

- se houver conflito, vale o tipo mais específico de stream
- `get.php` conta como `m3u`, não como `api`

## Como impedir “embaralhamento” de usuário

Esse é o ponto mais importante.

### Obrigatório no proxy

Em toda request pública com `username/password`, o sistema deve:

1. extrair `username` e `password`
2. gerar `credential_fingerprint = sha256(username + ":" + password)`
3. registrar isso em `proxy_request_events`
4. quando a resposta textual for reescrita, validar amostra de linhas
5. confirmar que as URLs públicas geradas ainda correspondem ao mesmo par

### Regra de defesa

Se entrar:

`username=P2on...&password=P2on...`

e a playlist reescrita indicar outro par, o sistema deve:

- abortar a entrega
- devolver `502`
- registrar evento crítico em `audit_logs`
- marcar `match_confidence = invalid_credentials_swap`

### Observação

O estado atual do código já repassa as credenciais públicas na montagem da URL
de origem. O que falta é registrar e auditar isso formalmente.

## Estratégia de integração com o XUI

### Modo obrigatório

Somente `read-only`.

### Princípios

- nunca escrever no banco do XUI
- nunca consultar o MySQL do XUI por request de player
- usar pool pequeno: `1` ou `2` conexões máximas
- timeout curto
- fallback total para o painel se o XUI estiver temporariamente indisponível

### Frequência de sync

Recomendação:

- `xui_users_cache`: a cada `60s`
- `xui_streams_cache`: a cada `300s`
- `xui_activity_now_cache`: a cada `3s` ou `5s`
- `user_activity` histórico: sob demanda ou job a cada `60s`

## UX do painel

Criar nova área administrativa:

- `Restreamento`

Seções:

### 1. Visão ao vivo

Tabela principal:

- usuário
- host público
- IP cliente
- player / User-Agent
- conexões ativas agora
- limite no XUI
- última atividade
- tipo de consumo
- stream atual
- status

### 2. Filtros

- por usuário
- por domínio público
- por IP
- por tipo de consumo
- por status
- por “estourando limite”

### 3. Detalhe do usuário

Ao abrir:

- dados do `users` do XUI
- sessões ativas em `user_activity_now`
- últimas requests do proxy
- domínios usados
- IPs usados
- players usados
- divergências detectadas

### 4. Indicadores

- total de usuários ativos agora
- total de conexões ativas agora
- top usuários por conexões
- top domínios públicos por uso
- top apps por User-Agent
- usuários acima do limite

## API local recomendada

Endpoints sugeridos:

- `GET /admin/restream/runtime`
- `GET /admin/restream/runtime?username=...`
- `GET /admin/restream/runtime?host=...`
- `GET /admin/restream/user/{username}`
- `GET /admin/restream/summary`
- `POST /admin/restream/sync/run`
- `GET /admin/restream/sync/status`

### Atualização em tempo real

Não usar websocket como primeira escolha.

Usar:

- polling de `3s` a `5s` no dashboard

Razão:

- mais simples
- mais barato
- suficiente para o caso
- combina com PHP puro

Se depois for necessário:

- SSE pode ser considerada

## Mudanças necessárias no código atual

### Banco local

Expandir [app/Database.php](/opt/proxy-mago/proxy-mago-base/app/Database.php:1)
com as novas tabelas e índices.

### Guard e logging

Expandir [app/AccessGuard.php](/opt/proxy-mago/proxy-mago-base/app/AccessGuard.php:1)
para:

- capturar `username/password` da query
- classificar `route_kind`
- gravar `user_agent`
- gerar `request_id`
- gravar `credential_fingerprint`

### Proxy

Expandir [public/proxy.php](/opt/proxy-mago/proxy-mago-base/public/proxy.php:1)
e [app/StreamProxy.php](/opt/proxy-mago/proxy-mago-base/app/StreamProxy.php:1)
para:

- emitir telemetria estruturada
- validar consistência do rewriter nas rotas textuais
- vincular rotas de mídia a um usuário quando o path contiver credenciais

### Novo módulo XUI

Criar:

- `app/XuiSyncConfig.php`
- `app/XuiReadOnly.php`
- `app/XuiSyncService.php`
- `app/RestreamRuntime.php`

### Painel

Criar:

- `public/restream.php`
- `public/restream-data.php`
- `public/restream-user.php`
- link de navegação no dashboard

## Dependências de sistema

Hoje esta VPS tem:

- `pdo_sqlite`
- `sqlite3`
- `curl`

Ainda **não** aparece `pdo_mysql` no PHP local.

Para a integração read-only com XUI, será necessário instalar nesta Ubuntu 22.04:

```bash
apt-get update
apt-get install -y php8.1-mysql
phpenmod pdo_mysql mysqli
systemctl reload php8.1-fpm
```

## Consultas mínimas do XUI

### Usuários

```sql
SELECT
  id,
  username,
  password,
  max_connections,
  enabled,
  exp_date,
  is_trial,
  is_restreamer,
  allowed_ips,
  allowed_ua
FROM users;
```

### Sessões ativas

```sql
SELECT
  activity_id,
  user_id,
  stream_id,
  server_id,
  user_agent,
  user_ip,
  container,
  date_start,
  date_end,
  hls_last_read,
  hls_end
FROM user_activity_now;
```

### Streams

```sql
SELECT
  id,
  type,
  stream_display_name,
  category_id,
  direct_source,
  target_container
FROM streams;
```

## Estratégia de matching entre proxy e XUI

### Match primário

Por:

- `username` local
- `client_ip`
- janela de tempo curta

### Match secundário

Usar:

- `user_agent`
- `container`
- `stream_id` derivado do path quando disponível

### Confiança

O sistema deve marcar:

- `high`
- `medium`
- `low`
- `invalid`

## Testes obrigatórios

### 1. Consistência por usuário

Casos:

- usuário A pede playlist A
- usuário B pede playlist B
- garantir que A não recebe URLs de B
- garantir que B não recebe URLs de A

### 2. Matching de sessão

Casos:

- abrir playlist
- abrir live
- abrir movie
- abrir series
- confirmar vínculo entre request local e sessão XUI ativa

### 3. Compatibilidade de players

Validar:

- XCIPTV
- IBO Player Pro
- IPTV Smarters
- TiviMate
- VLC

### 4. Painel em tempo real

Validar:

- usuário aparece até `5s` após conexão
- contador de conexões sobe
- última atividade atualiza
- status cai após encerramento da sessão

### 5. Falha do XUI

Desligar acesso ao MySQL do XUI e confirmar:

- stream público continua funcionando
- dashboard mostra último snapshot + estado degradado
- nenhuma rota pública quebra

### 6. Carga

Executar ao menos:

- `5` usuários simultâneos
- `20` usuários simultâneos
- `50` usuários simultâneos

Medir:

- CPU
- RAM
- banda
- tempo médio do painel ao vivo
- atraso médio do sync

## Critérios de aceite

Só considerar concluído quando:

- a integração com XUI é read-only
- o stream continua independente do MySQL do XUI
- o painel mostra conexões ativas por usuário
- o limite de conexões do XUI aparece no painel
- o tipo de consumo aparece corretamente
- o sistema detecta e registra qualquer inconsistência de credencial
- a Lovable entregar tudo no GitHub com documentação e smoke tests
- o pull para esta VPS exigir no máximo ajuste de credenciais do XUI

## O que a Lovable deve entregar no GitHub

No branch de trabalho e depois em `main`, ela deve entregar:

- código completo das tabelas novas
- classes de sync read-only do XUI
- tela de restreamento
- endpoints do painel
- documentação operacional
- smoke test do restreamento
- troubleshooting

## Sequência ideal de entrega

1. modelagem de SQLite local
2. conector read-only XUI
3. job de sync manual
4. painel com dados de snapshot
5. polling ao vivo
6. matching com requests do proxy
7. testes de consistência e carga
8. documentação final

## Riscos e limites

- consultar o MySQL do XUI em toda request pública é erro de arquitetura
- polling abaixo de `2s` pode ser exagero para PHP puro
- guardar senha pura do usuário localmente aumenta risco desnecessário
- tentar “tempo real absoluto” via stream de eventos complexo vai pesar mais
  do que o problema exige

## Recomendação final

Implementar **restreamento observável** e não “painel pesado de billing”.

O foco é:

- visão operacional em tempo real
- rastreabilidade forte
- prova de consistência
- zero impacto relevante no caminho do stream


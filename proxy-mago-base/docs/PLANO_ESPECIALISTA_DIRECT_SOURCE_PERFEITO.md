# Plano Especialista — Direct Source Perfeito na CDN Inteligente

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data: 2026-07-31

## Escopo obrigatório de ambiente

Este documento se refere ao projeto rodando diretamente nesta VPS real:

- VPS: `45.140.192.237`
- OS: `Ubuntu 22.04`
- Path ativo: `/opt/proxy-mago/proxy-mago-base`
- Repositório GitHub: `aryperdomo123456789-web/cdnvoods-assets`
- Banco XUI real estudado: `xui` em `38.190.176.170:3306`

Este plano não é abstrato. Ele foi escrito para o XUI real já analisado e para
o código real já ativo nesta VPS.

## Objetivo

Levar a CDN ao ponto em que o rastreamento de `direct source` fique realmente
perfeito, auditável e útil operacionalmente, sem depender do XUI como fonte
principal de verdade.

O objetivo final é que a CDN saiba, com clareza:

- quando um conteúdo é `direct source`
- se ele veio por redirect em runtime ou por URL já cadastrada no stream
- qual host final foi consumido
- qual usuário abriu esse conteúdo
- por qual domínio público
- por qual IP e player
- por qual sessão local da CDN
- se houve erro, retry, abandono ou bloqueio

## Descobertas reais no XUI estudado

### 1. O schema não é o Xtream clássico puro

Neste XUI real, as tabelas relevantes são:

- `lines`
- `lines_live`
- `streams`

E não:

- `user_activity_now`
- `users`

O projeto local já foi adaptado para isso no sync.

### 2. O `direct source` não existe só como redirect 302

Foi confirmado no banco `xui` que há muitos streams com:

- `streams.direct_source = 1`
- `streams.direct_proxy = 0`
- `streams.stream_source` contendo URL externa já pronta

Exemplo real observado:

```text
https://slackewn.click/series/.../2.mp4
```

Isso muda a arquitetura do rastreio:

- nem todo `direct source` vai aparecer apenas como hop de redirect
- parte do `direct source` já nasce no cadastro do stream

### 3. A CDN já rastreia redirects internos

Hoje o projeto já faz parte do trabalho:

- segue redirect por dentro
- grava hops em `direct_source_hops`
- expõe leitura via `DirectSource.php`

Isso é bom, mas ainda é só metade da história.

## O problema que ainda falta resolver

Hoje a CDN consegue rastrear melhor o `direct source` quando ele ocorre como:

- redirect da origem para host de terceiros

Mas ainda não fecha perfeitamente o caso em que:

- o stream já tem `stream_source` externo cadastrado no banco do XUI

Nesse cenário, a CDN precisa “entender” o direct antes mesmo de qualquer hop,
porque o host final já é conhecido pelo cadastro do stream.

## O que ainda falta no código

### 1. Enriquecer o cache local de streams

Hoje `xui_streams_cache` armazena o básico:

- `stream_id`
- `type`
- `stream_display_name`
- `category_id`
- `target_container`

Falta incluir:

- `direct_source`
- `direct_proxy`
- `stream_source_raw`
- `direct_host_detected`
- `source_mode`

Definições:

- `source_mode = direct_from_db`
  quando `direct_source = 1` e o host final vem do `stream_source`
- `source_mode = redirect_runtime`
  quando o host final só aparece no hop seguido pelo proxy
- `source_mode = mixed`
  quando o DB já indica external source e ainda há redirect

### 2. Fazer parse real de `stream_source`

No XUI estudado, `stream_source` vem como JSON textual em muitos casos:

```text
["https://slackewn.click/..."]
```

Falta no código:

- decodificar JSON quando aplicável
- aceitar string simples quando não for JSON
- extrair host final
- extrair scheme
- marcar quando houver múltiplas URLs
- registrar erro de parsing quando vier formato inesperado

### 3. Unificar as duas origens de verdade do direct source

Hoje existem duas fontes diferentes:

1. banco do XUI (`streams.stream_source`)
2. runtime do proxy (`direct_source_hops`)

Falta no código:

- consolidar isso numa visão única por sessão
- decidir qual fonte prevalece quando ambas existem
- registrar:
  - `direct_origin_mode`
  - `direct_host_from_db`
  - `direct_host_runtime`
  - `direct_host_effective`

### 4. Amarrar direct source à sessão local da CDN

Hoje já existe sessão local, mas falta enriquecer com:

- `direct_source = 1/0`
- `direct_host`
- `direct_mode`
- `direct_first_seen_at`
- `direct_last_seen_at`
- `direct_error_count`
- `direct_blocked_count`

Isso precisa aparecer em `cdn_sessions` e refletir no painel.

### 5. Melhorar o painel de restreamento

Falta mostrar explicitamente:

- conteúdo é `direct source` ou não
- se veio do DB ou de redirect
- host final do direct
- top hosts direct
- falhas por host direct
- usuários usando direct neste momento
- sessões diretas por tipo:
  - `movie`
  - `series`
  - `live`

### 6. Criar divergências próprias de direct source

Hoje o quadro de divergências é geral.

Falta incluir tipos dedicados:

- `direct_db_runtime_mismatch`
- `direct_host_missing`
- `direct_parse_error`
- `direct_orphan_session`
- `direct_runtime_without_db_flag`
- `direct_db_flag_without_runtime`

### 7. Melhorar os jobs internos

Falta incluir no ciclo de jobs:

- enriquecimento de `xui_streams_cache` com `direct_source`
- rollup de métricas de direct
- detecção de inconsistência entre DB e runtime
- retry de parsing falho

### 8. Melhorar os KPIs

Faltam KPIs específicos:

- `direct_active_now`
- `direct_top_hosts_15m`
- `direct_failures_15m`
- `direct_blocked_15m`
- `direct_db_vs_runtime_mismatch`
- `direct_by_content_type`

### 9. Testes reais obrigatórios

Falta validar com casos reais:

- stream `direct_source = 1` vindo do DB
- stream `direct_source` com redirect em runtime
- stream que falha no host final
- stream com múltiplas URLs em `stream_source`
- player reconectando em conteúdo direct
- same user com vários conteúdos direct
- painel refletindo isso em tempo quase real

## Arquitetura recomendada

### Regra principal

A fonte de verdade do `direct source` deve ser a CDN.

O banco do XUI entra como:

- pista antecipada
- enriquecimento
- contexto

Mas quem confirma o que de fato foi consumido é o runtime da CDN.

### Fluxo ideal

1. sync do XUI espelha `streams`
2. parser extrai `direct_source`, `direct_proxy`, host e modo
3. request público entra na CDN
4. sessão local é aberta/atualizada
5. se houver redirect, hops são gravados
6. consolidator decide o `direct_host_effective`
7. painel mostra:
   - sessão
   - host final
   - modo
   - divergência, se houver

## Mudanças concretas recomendadas no código

### `app/XuiSyncService.php`

Adicionar no sync de streams:

- leitura de `direct_source`
- leitura de `direct_proxy`
- leitura de `stream_source`
- parse e extração do host

### `app/Database.php`

Expandir `xui_streams_cache` com colunas novas:

- `direct_source`
- `direct_proxy`
- `stream_source_raw`
- `direct_host_detected`
- `source_mode`
- `parse_status`

### `app/DirectSource.php`

Adicionar métodos para:

- resolver host efetivo por request/sessão
- comparar DB vs runtime
- listar divergências específicas
- expor KPIs por host

### `app/RestreamRuntime.php`

Adicionar ao runtime:

- `direct_mode`
- `direct_host_effective`
- `direct_db_host`
- `direct_runtime_host`
- `direct_consistency`

### `public/restream.php`

Adicionar:

- cards de KPIs direct
- tabela de hosts direct
- filtros por direct
- coluna “modo direct”

### `public/restream-user.php`

Adicionar:

- histórico de consumos direct
- hosts finais usados
- divergências direct do usuário

### `bin/smoke-intelligence.sh`

Expandir para:

- validar host direct vindo do DB
- validar host direct vindo de runtime
- validar consistência DB vs runtime

## Critério de aceite perfeito

Só considerar o rastreamento de `direct source` perfeito quando:

- o stream cadastrado com `direct_source = 1` for reconhecido no cache local
- o host final puder ser mostrado no painel
- redirects em runtime continuarem sendo rastreados
- DB e runtime forem consolidados numa visão única
- divergências específicas aparecerem no painel
- métricas específicas de direct existirem
- o histórico de usuário mostrar consumo direct com clareza

## O que a Lovable deve implementar

Em termos objetivos:

1. parse de `stream_source`
2. enriquecimento de `xui_streams_cache`
3. consolidação DB + runtime
4. divergências de direct
5. KPIs de direct
6. testes reais de direct
7. documentação final de produção para esta VPS

## Resumo executivo

Hoje a CDN já está boa em `direct source` por hop de runtime.

O que falta para ficar perfeita é:

- entender o `direct source` já no cadastro do stream
- unir o que o banco diz com o que a CDN realmente serviu
- mostrar isso no painel de forma clara, auditável e operacional


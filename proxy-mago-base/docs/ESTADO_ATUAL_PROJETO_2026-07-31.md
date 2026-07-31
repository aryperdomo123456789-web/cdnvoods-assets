# Estado Atual do Projeto — 2026-07-31

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data de referencia: `2026-07-31`

Ambiente real deste projeto:

- VPS principal: `45.140.192.237`
- SO: `Ubuntu 22.04`
- path ativo: `/opt/proxy-mago/proxy-mago-base`
- repositório de publicacao: `aryperdomo123456789-web/cdnvoods-assets`

Este documento registra o estado **real atual** do projeto nesta VPS. Ele nao
deve ser lido como teoria nem como estado de outro ambiente.

## 1. Objetivo atual do projeto

O projeto evoluiu para uma CDN/proxy de protecao para XUI com estes objetivos:

- mascarar IP e DNS da origem XUI
- mascarar `direct source`
- entregar `get.php`, `player_api.php`, `xmltv.php`, `movie`, `live`, `series`
- manter rastreabilidade detalhada de usuarios, sessoes e requests
- operar de forma leve
- deixar a VPS principal como cerebro do sistema
- preparar o futuro suporte a LBs dedicados de trafego

## 2. Arquitetura atual

Hoje a arquitetura ativa e:

- Nginx publico
- PHP-FPM
- SQLite local
- painel admin autenticado
- proxy publico em `public/proxy.php`
- sync read-only com MySQL do XUI
- rastreamento de sessoes da CDN
- rastreamento de `direct source`
- painel de restreamento ao vivo

Componentes principais:

- `app/AccessGuard.php`
- `app/StreamProxy.php`
- `app/PlaylistRewriter.php`
- `app/CdnSession.php`
- `app/DirectSource.php`
- `app/DirectCatalog.php`
- `app/RestreamRuntime.php`
- `app/UserIntelligence.php`
- `app/XuiReadOnly.php`
- `app/XuiSyncService.php`

## 3. Estado funcional atual

## 3.1. O que esta funcionando

- painel admin abre em `cdnvoods.vr766.com`
- login do painel esta funcionando
- protecao por dominios publicos esta funcionando
- `get.php` respondeu `200` em smoke test local
- `player_api.php` respondeu `200` em smoke test local
- `xmltv.php` respondeu `200` em smoke test local
- `alias` invalido respondeu `404`
- painel publico continua oculto no dominio de stream
- sync com o XUI real responde:
  - host: `38.190.176.170`
  - banco: `xui`
  - tabela de usuarios real: `lines`
  - tabela de atividade real: `lines_live`
- espelho atual do XUI confirmado nesta VPS:
  - `15` usuarios
  - `483869` streams

## 3.2. O que esta parcialmente funcionando

- rastreamento de usuarios ao vivo
- painel `restream.php`
- contagem de sessoes locais da CDN
- rastreamento de `direct source`
- consolidacao `CDN x XUI`

Essas partes ja entregam valor real, mas ainda exigem endurecimento para
chegar ao comportamento "100% ao vivo" desejado.

## 3.3. Problema importante ainda em aberto

No estado atual, a contagem de conexoes `direct source` em troca de filme ainda
nao esta consolidada como perfeita.

O bug observado foi:

- o usuario fecha um filme
- abre outro
- ao inves de sumir uma conexao e entrar outra
- o numero pode crescer

Ja foi aplicada uma correcao estrutural no codigo:

- sessoes antigas do mesmo dispositivo/app em `movie/series` agora devem virar
  `superseded`
- a contagem voltou para `status = active` como criterio principal

Mas a validacao final ainda depende de repeticao em uso real com o player e sem
contenção do SQLite.

## 4. Estado do rastreamento real observado

O usuario real usado em testes foi:

- `P2on2325154215633`

Dominio publico em uso pela CDN:

- `http://voods.suafontee.com/get.php?username=P2on2325154215633&password=P2on2325154215633&type=m3u_plus&output=hls`

O sistema ja registrou:

- dominio publico usado
- IP do cliente
- player/User-Agent
- sessoes `playlist`, `api` e `movie`
- `direct source`
- host final de `direct source`
- requests e eventos por `request_id`

Tambem ja foi observado consumo real por:

- `libmpv`
- `Ibo Player Pro`
- `XCIPTV-v7.0-2000`
- `Dalvik/... Android 16 ...`

## 5. Estado do banco e telemetria local

Banco local:

- SQLite em `storage/app.sqlite`

Telemetria atual do projeto:

- `proxy_request_events`
- `cdn_sessions`
- `proxy_user_runtime`
- `cdn_divergences`
- `xui_users_cache`
- `xui_streams_cache`
- `xui_activity_now_cache`
- `direct_source_hops`
- `direct_stream_state`
- `direct_host_rollup`
- `job_runs`
- `job_state`

## 6. Jobs internos existentes

Jobs relevantes hoje:

- `xui_sync_users`
- `xui_sync_streams`
- `xui_sync_activity`
- `direct_enrich`
- `direct_consolidate`
- `match_sessions`
- `session_sweep`
- `consolidate_runtime`
- `detect_inconsistency`
- `metrics_rollup`
- `cleanup`
- `repair_retry`

Esses jobs ja compoem a base de inteligencia e observabilidade do projeto.

## 7. Limitacao operacional atual

O principal risco tecnico ainda visivel no estado atual e:

- contenção do SQLite (`database is locked`)

Isso impacta especialmente:

- consultas do painel ao vivo
- limpeza manual de sessoes
- consolidacao rapida de estado

O projeto ja recebeu endurecimentos para reduzir o impacto disso no caminho
publico do stream, mas o painel e a auditoria ainda precisam de mais robustez.

## 8. Estado do suporte a LB

Foi criado o plano de producao para arquitetura:

- cerebro = VPS principal
- musculos = LBs dedicados

Documento criado:

- `docs/PLANO_PRODUCAO_LB_CEREBRO_MUSCULOS.md`

Esse plano usa como LB real estudado:

- IP: `143.14.168.78`
- Ubuntu `22.04.5`
- `3` vCPU
- `7.7 GiB` RAM

## 9. Documentos principais hoje

Documentos importantes que fazem parte do estado atual:

- `docs/AUDITORIA.md`
- `docs/ESTADO_2026-07-31.md`
- `docs/FECHAMENTO_2026-07-31.md`
- `docs/LAB-CONEXOES-REAIS.md`
- `docs/CDN_INTELIGENTE.md`
- `docs/RESTREAMENTO_OBSERVABILIDADE.md`
- `docs/PLANO_PRODUCAO_100_FUNCIONAL.md`
- `docs/PLANO_PRODUCAO_LB_CEREBRO_MUSCULOS.md`

## 10. Conclusao honesta do estado atual

O projeto **nao esta em estado inicial**. Ele ja tem:

- proxy publico funcionando
- painel funcionando
- protecao de origem
- sync real com XUI
- rastreamento local
- trilha de `direct source`
- documentacao extensa

Mas ainda **nao deve ser chamado de 100% fechado** porque faltam:

- consolidacao perfeita de conexao ativa em troca de filme/direct source
- reducao adicional de `database is locked`
- validacao longa de producao sob uso real continuo

## 11. Objetivo desta publicacao

Esta publicacao para o GitHub serve para:

- espelhar o estado atual real desta VPS
- deixar o `backup` atualizado
- promover esse mesmo estado para o `main`
- garantir que a Lovable ou qualquer proxima etapa leia o estado atual correto

E nao um estado antigo ou teorico.

# Estado Atual do Rastreamento Ao Vivo

Data de referência: 2026-07-31

Ambiente real deste projeto:

- VPS principal: `45.140.192.237`
- Sistema operacional: `Ubuntu 22.04`
- Path real do projeto: `/opt/proxy-mago/proxy-mago-base`
- Banco local de observabilidade: `storage/app.sqlite`
- Painel de rastreamento: `https://cdnvoods.vr766.com/restream.php`

## Objetivo desta correção

Fechar o problema em que o usuário estava assistindo filme pela CDN, o
conteúdo passava normalmente, mas o painel ao vivo zerava:

- `Conexões (CDN) = 0`
- `Sessões locais = 0`
- `Usuários ativos = 0`

mesmo com consumo real em andamento via `movie/direct source`.

## Sintoma confirmado em produção

O fluxo público estava funcional, mas a trilha interna de sessão local não se
mantinha viva de forma confiável durante consumo de filme:

- requests reais apareciam em `proxy_request_events`
- o stream entregava `206 Partial Content`
- a sessão em `cdn_sessions` era encerrada cedo demais
- o painel passava a mostrar zero uso ao vivo

## Causa raiz encontrada

Foram encontradas duas causas principais:

1. contenção de escrita no SQLite (`database is locked`) em pontos quentes do
   caminho do request e dos jobs internos
2. rotina de `session_sweep` ainda vulnerável a fechar sessões direct antes da
   hora certa no cenário real de `movie/direct source`

Também foi confirmado que:

- o stream de filme real passava pela CDN
- o host final de direct source estava sendo rastreado
- o problema era de persistência/vida útil da sessão local, não de proxy de mídia

## Correções aplicadas neste turno

### 1. Heartbeat de sessão direct endurecido

Arquivo:

- `app/CdnSession.php`

Mudança:

- `heartbeat()` agora também promove o `idle_timeout` para a janela longa de
  `DIRECT_IDLE` quando o request pertence a `movie`, `series` ou outro consumo
  direct

Efeito:

- a sessão continua viva no painel enquanto o consumo segue ativo

### 2. Fechamento de sessão refeito para cálculo explícito

Arquivo:

- `app/CdnSession.php`

Mudança:

- `session_sweep` deixou de depender do fechamento em lote baseado em SQL
- o job agora lê as sessões `active`, calcula o `expiry` em PHP e fecha apenas
  quem realmente expirou

Efeito:

- elimina fechamento indevido de sessão direct ainda válida
- elimina o comportamento incorreto em que `close_reason` aparecia com valor
  numérico em vez de motivo textual

### 3. Job pesado aliviado para proteger o SQLite

Arquivo:

- `app/JobRunner.php`

Mudança:

- `direct_consolidate` passou de `30s` para `300s`

Motivo:

- no XUI real existem `483.869` streams espelhados
- consolidar direct source com frequência curta aumentava muito a contenção
  no SQLite

Efeito:

- reduz briga entre job pesado e gravações de request/sessão
- melhora a chance de o painel refletir o consumo ao vivo de forma estável

## Validação real executada

Foi executado teste real no mesmo host público usado pelo usuário:

- host: `voods.suafontee.com`
- user de teste real: `P2on2325154215633`
- rota validada: `/movie/P2on2325154215633/P2on2325154215633/803591.mp4`

Resultado do teste:

- resposta HTTP: `206 Partial Content`
- amostra baixada: `1.048.576 bytes`
- `X-Request-Id` emitido corretamente
- `proxy_request_events` recebeu a nova linha do request
- `cdn_sessions` manteve sessão `active`
- `idle_timeout = 7200`
- `direct_source = 1`
- `direct_host` permaneceu rastreado

## Estado atual confirmado após correção

Após o novo ciclo de jobs já com a correção carregada:

- `session_sweep` reportou `closed = 0`
- `session_sweep` reportou `active = 2`
- a sessão nova do filme ficou `active`
- o painel voltou a ter base correta para mostrar consumo ao vivo

Observação importante:

- durante a validação local houve uma conexão adicional de teste no mesmo
  usuário, então pode aparecer mais de uma sessão ativa temporariamente

## O que isso significa na prática

Neste momento o sistema:

- identifica request real de filme passando pela CDN
- correlaciona o request com `session_key`
- mantém a sessão local viva em `movie/direct source`
- preserva `direct_host` para rastreamento interno
- deixa o painel `restream.php` apto a mostrar o uso ao vivo real

## Pendências ainda abertas

O projeto avançou, mas ainda não deve ser tratado como encerrado em nível
absoluto. Ainda existem pontos a observar:

1. ainda existem registros históricos de `database is locked` no log PHP
2. a estabilidade precisa continuar sendo validada com uso real prolongado em
   `IBO Player Pro`, `XCIPTV` e outros players
3. a limpeza de sessões antigas reaproveitadas pelo mesmo `session_key` deve
   continuar sendo observada em produção real
4. a documentação de produção precisa sempre considerar que tudo roda nesta
   VPS Ubuntu 22.04 e não em ambiente da Lovable

## Arquivos alterados neste turno

- `app/CdnSession.php`
- `app/JobRunner.php`
- `docs/ESTADO_ATUAL_RASTREAMENTO_2026-07-31.md`

## Resumo executivo

O problema de "filme tocando mas painel zerado" foi reproduzido, estudado e
corrigido no projeto real desta VPS.

O estado atual é:

- proxy de mídia funcional
- request real rastreado
- sessão direct mantida ativa
- painel com base correta para exibir consumo ao vivo
- sincronização pronta para ser enviada ao branch `backup` e depois promovida
  para `main`

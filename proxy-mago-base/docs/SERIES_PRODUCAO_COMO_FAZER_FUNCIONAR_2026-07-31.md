# Series em Produção — por que só filme abre e como fazer série funcionar

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.

Data de referência: `2026-07-31`

## Resposta curta

O problema não está no reconhecimento da rota `/series/` dentro da CDN.

O problema real é este:

- os **filmes** deste XUI estão apontando majoritariamente para `readyondemand.click`
- as **séries** deste XUI estão apontando majoritariamente para `slackewn.click`
- `readyondemand.click` aceita o fetch feito pela CDN/LB
- `slackewn.click` está devolvendo `403` para a VPS/CDN

Resultado:

- `movie/...` abre
- `series/...` quebra ou fica travando

## O que foi validado no ambiente real

Ambiente real testado:

- VPS/CDN principal: `45.140.192.237`
- projeto: `/opt/proxy-mago/proxy-mago-base`
- XUI cadastrado: `38.190.176.170`
- banco do XUI: `xui`
- usuário real validado: `P2on2325154215633`

## Provas coletadas

### 1. O XUI tem episódios de série reais e o sync local já enxerga isso

No cache local da CDN (`xui_streams_cache`) existem episódios como:

- `stream_id = 467480`
- `stream_display_name = 8 Dias S1 E2`
- `type = 5`
- `direct_source = 1`
- `direct_host_detected = slackewn.click`
- `stream_source_raw = ["https://slackewn.click/series/tvsF4asfrg3fcva/s234f0w2E309g4n0fo3/2.mp4"]`

Ou seja:

- a CDN sabe que é série
- a CDN sabe qual é o host final
- a URL final do episódio já vem pronta do banco do XUI

### 2. A origem XUI não entrega o arquivo; ela redireciona

Teste real:

```bash
curl -v -H 'User-Agent: VLC/3.0.20 LibVLC/3.0.20' \
  -H 'Range: bytes=0-1' \
  'http://38.190.176.170/series/P2on2325154215633/P2on2325154215633/467480.mp4'
```

Resposta real:

- `HTTP/1.1 302 Found`
- `Location: https://slackewn.click/series/.../2.mp4`

Então a origem XUI não está servindo o episódio por conta própria.
Ela manda a CDN seguir o redirect para o host final da série.

### 3. O host final da série bloqueia a VPS da CDN

Teste real:

```bash
curl -v -k -H 'User-Agent: VLC/3.0.20 LibVLC/3.0.20' \
  -H 'Range: bytes=0-1' \
  'https://slackewn.click/series/tvsF4asfrg3fcva/s234f0w2E309g4n0fo3/2.mp4'
```

Resposta real:

- `HTTP/2 403`
- `server: cloudflare`
- corpo de erro com ~`4566` bytes

Isso prova que:

- a rota de série existe
- o redirect existe
- o bloqueio acontece no host final do direct source

### 4. O filme funciona porque o host final é outro

No cache local, filmes direct estão indo para:

- `readyondemand.click`

Teste real do filme público:

```bash
curl -I -H 'User-Agent: VLC/3.0.20 LibVLC/3.0.20' \
  -H 'Range: bytes=0-1' \
  'http://voods.suafontee.com/movie/P2on2325154215633/P2on2325154215633/784216.mp4'
```

Resposta real:

- `HTTP/1.1 206 Partial Content`

Então o filme funciona porque o host final do filme aceita o fetch da CDN.

### 5. A trilha local da CDN confirma o mesmo padrão

Na tabela `proxy_request_events`, requests de série do domínio público já apareceram assim:

- `route_kind = series`
- `direct_host = slackewn.click`
- `status = 403`
- `reason = stream`

Ou seja: a própria trilha da CDN já mostra que a série está chegando no host final, mas o host final está negando.

## Diagnóstico final

Hoje o cenário real é este:

1. o player pede `/series/<user>/<pass>/<stream_id>.mp4`
2. a CDN reconhece corretamente como `series`
3. a CDN fala com o XUI
4. o XUI devolve `302` para `slackewn.click`
5. a CDN segue o redirect
6. `slackewn.click` responde `403`
7. o episódio não toca

Portanto:

- não é um bug primário de roteamento `/series/`
- não é falta de série no banco
- não é falta de reconhecimento de `stream_id`
- não é falha do cache local do XUI

É um bloqueio do **host final das séries** contra a VPS/LB da CDN.

## Como fazer série funcionar de verdade

Existem 3 caminhos profissionais.

## Caminho 1 — o melhor: liberar os IPs da CDN/LBs no host das séries

Se você controla o lado que serve `slackewn.click`, faça allowlist de:

- IP do cérebro/main
- IP de cada LB que vai entregar tráfego

No cenário atual, pelo menos:

- `45.140.192.237`
- `143.14.168.78`

Objetivo:

- o host final da série precisa aceitar requests vindos da CDN
- sem isso, a série sempre vai morrer no hop externo

Depois de liberar, testar:

```bash
curl -I -k -H 'User-Agent: VLC/3.0.20 LibVLC/3.0.20' \
  -H 'Range: bytes=0-1' \
  'https://slackewn.click/series/tvsF4asfrg3fcva/s234f0w2E309g4n0fo3/2.mp4'
```

Resultado esperado:

- `200` ou `206`
- nunca `403`

## Caminho 2 — mudar a origem das séries para um host que aceite a CDN

Se `slackewn.click` não puder ser liberado, então as séries precisam sair por outro host.

Exemplos de caminho:

- trocar `stream_source` das séries no XUI
- trocar a origem de direct source das séries para um host que aceite fetch do servidor
- usar um host semelhante ao dos filmes, se esse host já estiver aceitando a CDN

Meta:

- séries precisam apontar para um host final compatível com fetch server-to-server

## Caminho 3 — evitar direct source externo nas séries

Se o fornecedor de série não permite fetch pela VPS/CDN:

- desabilitar o direct source externo para essas séries
- ou servir a série por um caminho proxyado/compatível no próprio ecossistema do XUI

Esse caminho é menos bonito, mas é o único quando o host externo bloqueia datacenter e você não tem governança sobre ele.

## O que não resolve

Estas ações, sozinhas, não resolvem:

- mexer só no painel
- mexer só na tabela local da CDN
- aumentar timeout do player
- aumentar timeout do cURL
- mudar apenas o tracking
- mudar apenas o idle_timeout

Nada disso remove um `403` do host final.

## Receita operacional de validação

Use esta ordem sempre que série não abrir:

### Etapa 1 — validar episódio no cache local

```sql
SELECT stream_id, stream_display_name, direct_source, direct_host_detected, stream_source_raw
FROM xui_streams_cache
WHERE type = '5'
ORDER BY stream_id DESC
LIMIT 20;
```

Confirmar:

- `type = 5`
- `direct_source = 1`
- `direct_host_detected` preenchido

### Etapa 2 — validar redirect da origem XUI

```bash
curl -I -H 'User-Agent: VLC/3.0.20 LibVLC/3.0.20' \
  -H 'Range: bytes=0-1' \
  'http://XUI/series/USER/PASS/STREAM_ID.mp4'
```

Se vier:

- `302 Location: https://host-final/...`

Então o episódio depende do host final externo.

### Etapa 3 — validar host final direto

```bash
curl -I -k -H 'User-Agent: VLC/3.0.20 LibVLC/3.0.20' \
  -H 'Range: bytes=0-1' \
  'https://HOST-FINAL/...'
```

Se vier:

- `403`

o problema está no fornecedor/host final, não no roteamento local da CDN.

### Etapa 4 — validar domínio público da CDN

```bash
curl -I -H 'User-Agent: VLC/3.0.20 LibVLC/3.0.20' \
  -H 'Range: bytes=0-1' \
  'http://SEU-DNS/series/USER/PASS/STREAM_ID.mp4'
```

Se a trilha local mostrar:

- `direct_host = host-final`
- `status = 403`

você confirmou ponta a ponta que a CDN está funcionando e quem está negando é o host de série.

## Conclusão objetiva para o Lovable

Se o objetivo é “fazer séries reproduzirem igual filme”, a regra correta é:

- não tratar isso como bug simples de frontend
- não tratar isso como falta de suporte da rota `/series/`
- tratar como compatibilidade operacional do `direct source` de séries

O produto precisa assumir que:

- filme e série podem cair em hosts finais diferentes
- cada host final pode ter política própria
- a CDN só funciona 100% quando esses hosts aceitam fetch do cérebro/LBs

## Próxima ação recomendada

A próxima ação mais forte e correta é esta:

1. mapear os hosts finais mais usados das séries
2. separar quais respondem `200/206` e quais respondem `403`
3. marcar `slackewn.click` como host incompatível com a CDN atual
4. decidir entre:
   - liberar IPs do main/LBs no host da série
   - trocar `stream_source` das séries
   - remover direct source externo para séries incompatíveis

Sem isso, o sistema pode estar perfeito no código e ainda assim a série continuará quebrando em produção.

# Plano de Producao — 100% funcional, leve e compativel

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data: 31/07/2026

## Escopo obrigatorio de ambiente

Este plano descreve o caminho para finalizar **este projeto rodando diretamente
nesta VPS real**:

- VPS: `45.140.192.237`
- OS: `Ubuntu 22.04`
- Projeto ativo: `/opt/proxy-mago/proxy-mago-base`

Nao interpretar este documento como um plano abstrato para "qualquer ambiente".
Todas as referencias operacionais, testes, nginx, PHP-FPM, SQLite, DNS e painel
se referem a este host Ubuntu 22.04.

## Objetivo real

Levar o projeto a um estado de producao em que:

- funcione com o maximo de apps possivel:
  - XCIPTV
  - IBO Player Pro
  - IPTV Smarters
  - TiviMate
  - VLC
  - apps genericos de playlist XUI
- esconda completamente a origem XUI:
  - main DNS
  - IP
  - credenciais internas
  - direct source
- nao force processamento pesado na VPS `45.140.192.237`
- use a VPS apenas como mascara/proxy de protecao
- opere sem Cloudflare laranja
- funcione em DNS only / nuvem cinza

## Conclusao tecnica atual

Hoje o projeto esta **proximo da arquitetura correta**, mas ainda **nao esta pronto para uso real**.

Os testes reais mostraram:

- a origem responde bem
- o rewriter mascara a origem com sucesso
- o dominio publico ainda esta bloqueado por configuracao e por desenho operacional
- a VPS ainda faz trabalho demais no caminho textual grande

## Testes reais executados em 31/07/2026

### Origem XUI real

Entradas testadas:

- `http://dafonte.uk:80/get.php?username=4Jknjjujtsuper&password=4Jknjjujtsuper&type=m3u_plus&output=mpegts`
- `http://dafonte.uk:80/get.php?username=P2on2325154215633&password=P2on2325154215633&type=m3u_plus&output=hls`
- `http://dafonte.uk:80/get.php?username=Magoopdokjm32000&password=Magoopdiiiokjm32&type=m3u_plus&output=m3u8`

Resultados:

- `mpegts`
  - HTTP `200`
  - `92.125.159` bytes
  - `699.668` linhas
  - `19,53s`
- `hls`
  - HTTP `200`
  - `128.012.102` bytes
  - `967.740` linhas
  - `27,28s`
- `m3u8`
  - HTTP `200`
  - `93.524.491` bytes
  - `699.668` linhas
  - `19,41s`

### Rewrite local da playlist

Resultados do `PlaylistRewriter::rewrite()`:

- `mpegts`
  - entrada `92.125.159`
  - saida `94.573.990`
  - tempo `0,69s`
  - `0` ocorrencias finais de `dafonte.uk`
  - `0` ocorrencias finais de `38.190.176.170`
- `hls`
  - entrada `128.012.102`
  - saida `131.399.185`
  - tempo `0,90s`
  - `0` ocorrencias finais de `dafonte.uk`
  - `0` ocorrencias finais de `38.190.176.170`
- `m3u8`
  - entrada `93.524.491`
  - saida `95.973.322`
  - tempo `0,61s`
  - `0` ocorrencias finais de `dafonte.uk`
  - `0` ocorrencias finais de `38.190.176.170`

### Dominio publico atual

Entradas testadas:

- `http://voods.suafontee.com:80/get.php?...`
- `http://voods.suafontee.com:80/player_api.php?...`
- `http://voods.suafontee.com:80/xmltv.php?...`

Resultados:

- todos os requests HTTP no alias publico recebem `301` para HTTPS
- sem user-agent permitido, o proxy responde `403 Denied`
- com `User-Agent: MagoPlayer/1.0`, `get.php`, `player_api.php` e `xmltv.php` passaram do bloqueio inicial mas responderam `500`

### Painel e ambiente local reais

Resultados confirmados nesta VPS:

- `https://cdnvoods.vr766.com/login.php` responde
- login do painel funciona com as credenciais documentadas
- `https://cdnvoods.vr766.com/dashboard.php` abre autenticado
- `voods.suafontee.com` resolve para esta VPS
- nginx e php-fpm estao ativos nesta VPS Ubuntu 22.04

### Estado da rede local

- IP publico da VPS: `45.140.192.237`
- `voods.suafontee.com` resolve para `45.140.192.237`
- `cdnvoods.vr766.com` resolve para `45.140.192.237`
- `dafonte.uk` resolve em IPv6 via Cloudflare

## Diagnostico final

### O que ja esta bom

- origem XUI unica e interna
- multiplos dominios publicos
- alias publico separado da origem
- reescrita textual consegue mascarar origem real
- redirects sao tratados com allowlist
- origem nao aparece no output reescrito local

### O que impede producao agora

1. `allowed_user_agent` obrigatorio em `settings`
2. alias publico forcando `80 -> 443`
3. caminho publico ainda gerando `500` apos passar do guard
4. playlists gigantes sendo reescritas em memoria
5. painel e alias de stream ainda compartilham o mesmo comportamento HTTP/HTTPS

## Verdade operacional sobre banda

Se a VPS vai mascarar e entregar o stream:

- **vai usar banda**, sim
- isso e inevitavel

O que precisa ser evitado e:

- consumo alto de CPU
- consumo alto de RAM
- buffering desnecessario
- processamento de video

Entao a meta correta nao e "sem banda".

A meta correta e:

- **com banda**
- **sem peso de processamento**

## Checklist para chegar em 100%

## Fase 1 — compatibilidade total de apps

Objetivo:

- fazer funcionar em XCIPTV, IBO Player Pro, Smarters, TiviMate, VLC e similares

Checklist:

- [ ] zerar `allowed_user_agent` no SQLite e no fluxo do painel
- [ ] garantir que o default de projeto seja sem whitelist de UA
- [ ] manter no maximo blacklist/rate-limit, nunca whitelist obrigatoria
- [ ] testar:
  - [ ] XCIPTV
  - [ ] IBO Player Pro
  - [ ] IPTV Smarters
  - [ ] TiviMate
  - [ ] VLC

Critério de aceite:

- qualquer app comum deve conseguir abrir `get.php?username=...&password=...`
- o teste precisa ser validado contra esta VPS, nao so em ambiente teorico

## Fase 2 — parar de quebrar dominios publicos

Objetivo:

- o alias publico precisa funcionar em `http://dominio:80`

Checklist:

- [ ] remover `301` obrigatorio de `80 -> 443` para aliases publicos
- [ ] manter HTTPS obrigatorio apenas no dominio do painel, se desejado
- [ ] separar comportamento do `panel_domain` e dos aliases publicos
- [ ] garantir que `voods.suafontee.com:80/get.php?...` responda direto

Critério de aceite:

- `curl http://alias:80/get.php?...` nao pode redirecionar
- o comportamento precisa ser verificado na VPS `45.140.192.237`

## Fase 3 — corrigir os `500`

Objetivo:

- o fluxo publico precisa responder `200`, nao `500`

Checklist:

- [ ] capturar o erro PHP real do `500`
- [ ] validar `get.php` com user-agent livre
- [ ] validar `player_api.php`
- [ ] validar `xmltv.php`
- [ ] registrar causa raiz em documento tecnico

Critério de aceite:

- `get.php`, `player_api.php` e `xmltv.php` respondem `200` no alias publico
- os logs e erros observados devem vir desta VPS Ubuntu 22.04

## Fase 4 — rewrite streaming para playlist grande

Objetivo:

- mascarar playlists enormes sem forcar a VPS

Checklist:

- [ ] substituir rewrite em memoria por rewrite linha a linha
- [ ] devolver output chunkado
- [ ] evitar concatenacao integral de strings gigantes
- [ ] medir memoria e tempo apos mudanca

Critério de aceite:

- playlist de `128 MB` reescrita sem fatal de memoria
- tempo de reescrita aceitavel
- uso de RAM controlado
- validacao feita no runtime real desta VPS

## Fase 5 — stream binario minimo

Objetivo:

- a VPS so encaminha, sem processar video

Checklist:

- [ ] manter `.ts`, `.m3u8`, `/live/`, `/movie/`, `/series/` no caminho mais simples possivel
- [ ] sem recodificacao
- [ ] sem cache pesado local
- [ ] sem buffering bruto desnecessario
- [ ] sem logs de query string

Critério de aceite:

- CPU e RAM da VPS permanecem baixas durante reproducoes reais
- medicao feita nesta VPS, nao assumida por estimativa generica

## Fase 6 — separar painel e stream

Objetivo:

- dominio do painel nao pode impor comportamento ao dominio do cliente

Checklist:

- [ ] dominio do painel separado
- [ ] aliases publicos sem login/admin/redirect desnecessario
- [ ] `health` e `admin` fora dos aliases publicos, quando possivel

Critério de aceite:

- o alias publico entrega stream
- o painel continua acessivel e isolado
- painel e aliases validados nesta VPS

## Fase 7 — testes de aceite reais

Objetivo:

- provar que a protecao funciona mesmo

Checklist:

- [ ] testar os 3 users reais em `mpegts`
- [ ] testar os 3 users reais em `hls`
- [ ] testar os 3 users reais em `m3u8`
- [ ] validar `player_api.php`
- [ ] validar `xmltv.php`
- [ ] validar um segmento real `.ts`
- [ ] buscar e confirmar ausencia de:
  - [ ] `dafonte.uk`
  - [ ] `38.190.176.170`
  - [ ] credenciais internas da origem
- [ ] registrar o resultado dos testes diretamente a partir desta VPS Ubuntu 22.04

Critério de aceite:

- nenhum vazamento textual da origem
- playback real em apps comuns

## Fase 8 — carga controlada

Objetivo:

- saber se vai "bombar" sem matar a VPS

Checklist:

- [ ] medir CPU, RAM e rede com 5 usuarios
- [ ] medir CPU, RAM e rede com 20 usuarios
- [ ] medir CPU, RAM e rede com 50 usuarios
- [ ] registrar gargalos reais

Critério de aceite:

- uso de CPU/RAM permanece compativel com o tamanho da VPS
- gargalo principal fica em banda, nao em processamento

## Parametros de sucesso

Para considerar o sistema 100% funcional:

- [ ] funciona sem UA obrigatorio
- [ ] funciona em HTTP `:80` para os dominios publicos
- [ ] `get.php` responde `200`
- [ ] `player_api.php` responde `200`
- [ ] `xmltv.php` responde `200`
- [ ] playlists nao vazam origem
- [ ] stream binario nao vaza origem
- [ ] apps comuns conectam sem gambiarra
- [ ] VPS nao recodifica nada
- [ ] VPS gasta banda, mas nao pesa CPU/RAM de forma anormal

## Recomendacao final

O caminho elite para este projeto e:

- DNS only
- VPS exposta
- origem XUI escondida
- sem whitelist de user-agent
- sem redirecionar aliases para HTTPS
- rewrite textual em streaming
- binario no caminho mais curto possivel

Esse e o desenho mais compativel com:

- todos os apps
- pouco peso na VPS
- mascaramento real da origem
- operacao simples

## Documento mestre para a Lovable

Se a Lovable precisar de uma ordem de leitura para nao se confundir sobre o
ambiente e o estado atual, seguir nesta sequencia:

1. `docs/LOVABLE_PROJECT_BRIEF.md`
2. `docs/SYNC_2026-07-31.md`
3. `docs/AUDITORIA.md`
4. `docs/ARQUITETURA_LEVE_SEM_NUVEM_LARANJA.md`
5. `docs/PLANO_PRODUCAO_100_FUNCIONAL.md`

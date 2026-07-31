# Auditoria técnica — proxy-mago-base (31/07/2026)

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


> **Atualização 31/07/2026 — Fase A implementada.** Ver §12 para o delta.
> As seções 1–11 descrevem o estado **antes** da Fase A e são mantidas como
> registro da auditoria.

Escopo: `/opt/proxy-mago/proxy-mago-base` (source of truth).
Objetivo alvo: esconder **apenas a origem XUI** (IP `38.190.176.170`, DNS `dafonte.uk`,
credenciais, URLs diretas), mantendo domínio público na VPS `45.140.192.237`, com
URLs públicas do tipo `http://meudominio.com/get.php?username=X&password=Y&type=...`
e múltiplos usuários simultâneos.

---

## 1. Resumo executivo

O projeto tem a espinha dorsal certa (Nginx → `public/proxy.php` → cURL para origem,
SQLite como única fonte da origem), mas **hoje ele não atende ao fluxo pedido**.
Três bloqueios são fatais:

1. **Token obrigatório** (`AccessGuard::check`, linha 49) rejeita com 401 qualquer
   `/get.php?username=...&password=...` sem `?t=`. O fluxo público exigido é
   exatamente esse. Regra atual e objetivo são incompatíveis.
2. **O rewriter apaga as credenciais do assinante** (`PlaylistRewriter`,
   `username=` / `password=` viram vazios). A playlist entregue quebra: os
   segmentos voltam sem credencial e o XUI recusa.
3. **A reescrita só cobre `scheme://host:port` da origem cadastrada.** Se o XUI
   devolver URLs com o próprio main (`http://dafonte.uk/...`), com outra porta ou
   em https, **o IP/DNS da origem vaza direto na playlist**. Esse é o risco de
   vazamento nº 1.

Maturidade estimada frente ao objetivo: **~55%**. Streaming binário, isolamento de
headers, rate limit e painel CRUD estão bons; camada de identidade (multi-usuário)
e reescrita de conteúdo estão erradas para o caso de uso.

---

## 2. Arquitetura observada

```
Player ──HTTP:80──► meudominio.com (VPS 45.140.192.237)
                      └── Nginx (NginxGenerator::render)
                            ├── /  → painel PHP (setup/login/dashboard)
                            └── /get.php, /player_api.php, /xmltv.php,
                                *.m3u8, *.ts, /live/ /movie/ /series/ /hls/
                                     → @stream_proxy → public/proxy.php
                                            ├── AccessGuard::check()
                                            ├── StreamProxy::fetchBuffered()  (playlist)
                                            │      └── PlaylistRewriter::rewrite()
                                            └── StreamProxy::stream()         (ts/binário)
                                                   └── cURL → origem XUI (SQLite)
```

Tabelas SQLite: `settings`, `origins`, `aliases`, `tokens`, `access_log`,
`rate_limit`, `audit_logs`.

---

## 3. Auditoria por componente

| Componente | Estado | Observação |
| --- | --- | --- |
| `app/Database.php` | **pronto** | Migração idempotente, WAL, FK on. `origins.auth_pass` em texto claro. |
| `app/OriginRepository` / `AliasRepository` | **pronto** | `findByHostname` já devolve `origin_type` e `origin_host_header`. |
| `app/StreamProxy::stream()` | **pronto** | Chunked, allowlist de headers de resposta (`content-type`, `content-length`, `content-range`, `accept-ranges`, `cache-control`, `last-modified`). Não vaza `Server`, `Location`, `X-Powered-By`. |
| `app/StreamProxy::fetchBuffered()` | **parcial** | `CURLOPT_TIMEOUT 20` mata `player_api.php` de catálogo grande. Não repassa UA/Range do cliente. |
| `app/PlaylistRewriter` | **arriscado** | Ver §5.1 e §5.2. Só cobre 2 variantes de host; destrói credenciais. |
| `app/AccessGuard` | **parcial/arriscado** | Validação CF por CIDR está correta. Token obrigatório conflita com o objetivo. `ipMatches` só entende /24 com último octeto `0`, e ignora IPv6. Rate limit por IP puro derruba CGNAT/operadora. |
| `app/Tokens` | **parcial** | Token é por *alias*, não por assinante. Não há vínculo user/pass ↔ sessão. |
| `public/proxy.php` | **parcial** | Roteia playlist vs binário só por regex de path; `player_api.php` e `xmltv.php` caem no ramo binário **sem reescrita** → vazam origem no JSON/XML. |
| `public/setup.php` | **pronto** | Já semeia `origins` + alias primário. |
| `public/dashboard.php` | **parcial** | CRUD de origem/alias/token OK. Falta troca de origem "quente" com validação e teste antes de aplicar. |
| `app/NginxGenerator` | **parcial** | Cobre as rotas XUI, `access_log off`, sem HTTPS. `add_header X-Frame-Options DENY` é inócuo aqui. `fastcgi_buffering off` correto. |
| `nginx/site.conf.example` | **legado/arriscado** | Contém `proxy_pass http://45.140.192.237:80` e `Host` fixo — inconsistente com o gerador e com a doc. Deve ser removido. |
| `app/HealthCheck` | **parcial** | `fopen()` para a origem sem `host_header` e sem `Host` custom; mensagens de erro podem citar o alvo. Só admin vê. |
| `docs/*` | **inconsistente** | `PROXY_FLOW.md` diz "rate limit padrão 240" e "novo token a cada playlist"; o código exige token e usa `Config` 240 vs fallback 120 em `AccessGuard`. `CLOUDFLARE_SETUP.md` prega esconder a VPS — objetivo mudou. |

---

## 4. Falhas e gaps (por prioridade)

### P0 — bloqueiam o objetivo
1. Token obrigatório impede o padrão `/get.php?username=&password=`.
2. `PlaylistRewriter` zera `username`/`password` → playlist inutilizável.
3. Reescrita não cobre o main DNS do XUI (`dafonte.uk`), https, porta implícita,
   nem hosts alternativos que o XUI emite → vazamento direto.
4. `player_api.php` e `xmltv.php` não passam por reescrita nenhuma.
5. Não existe conceito de "assinante" (par user/pass) — sem sessão, sem
   contadores, sem bloqueio por conta.

### P1 — risco alto
6. `CURLOPT_FOLLOWLOCATION = true` sem allowlist: um `Location` do XUI para outro
   host faz o proxy seguir e servir conteúdo de terceiro; e no ramo buffered o
   corpo final pode conter o host de destino.
7. Redirect 3xx não seguido no ramo stream não repassa `Location` (allowlist), o
   player simplesmente quebra sem diagnóstico.
8. `access_log` grava `path` (bom), mas `Audit` e `php-error.log` podem conter
   stack traces com URL montada (`buildOriginUrl`) se cURL/PDO lançar.
9. `origins.auth_pass` em texto claro no SQLite; `storage/` em 775.
10. Logs antigos no servidor ainda contêm URLs com credenciais XUI (apontado na
    auditoria anterior) — precisam de purga.

### P2 — robustez/limpeza
11. `fetchBuffered` sem streaming e com timeout 20s para catálogos grandes.
12. Rate limit sem dimensão por conta e sem allowlist.
13. `nginx/site.conf.example` legado e contraditório.
14. Sem HTTPS no template (aceitável se o alvo é porta 80, mas então
    `PlaylistRewriter` **não pode** forçar `https://` no `publicBase` — hoje força).
15. `ipMatches` incompleto (sem CIDR real, sem IPv6).

---

## 5. Riscos de vazamento da origem

### 5.1 Playlist M3U/M3U8 — **ALTO**
`rewrite()` só substitui `http://38.190.176.170:80` e `http://38.190.176.170`.
Não substitui: `https://…`, `http://dafonte.uk…`, `//38.190.176.170/…`,
host em maiúsculas, ou qualquer domínio secundário do painel XUI.
Correção: reescrever por parsing de URL (todas as URLs absolutas do corpo),
com allowlist de hosts da origem (host + `host_header` + lista de domínios
internos cadastrados) e, no default, **reescrever tudo que for absoluto**.

### 5.2 Credenciais — **ALTO**
Hoje as credenciais do assinante são apagadas do corpo (quebra funcional) mas
continuam trafegando na URL pública (inevitável no padrão XUI). As credenciais da
*origem* (`auth_user`/`auth_pass`) são injetadas por `buildOriginUrl` e **nunca**
devem aparecer no corpo reescrito — hoje isso é garantido só pelo regex genérico;
precisa de substituição explícita por valor.

### 5.3 `player_api.php` / `xmltv.php` — **ALTO**
Respostas JSON/XML contêm `server_info.url`, `port`, `https_port`, e URLs de EPG
com o host real. Passam pelo ramo binário sem tratamento → vazam origem.

### 5.4 Redirects — **MÉDIO/ALTO**
`FOLLOWLOCATION` cego. Se o destino final for CDN de terceiro, o corpo pode conter
esse host; e no buffered não há verificação de `CURLINFO_EFFECTIVE_URL`.

### 5.5 Headers — **BAIXO** (ok hoje)
Allowlist no `HEADERFUNCTION` está correta. Manter e adicionar teste de regressão.

### 5.6 Erros — **MÉDIO**
`display_errors=0` ok. Mas `HealthCheck` e `save-origin` devolvem mensagens que
podem citar host/porta (área admin apenas). `proxy.php` responde só "Denied" — bom.

### 5.7 Nginx/PHP — **MÉDIO**
`nginx/site.conf.example` versionado com IP; `export-config.php` imprime o
`app_secret` no corpo do arquivo (admin logado, mas o segredo circula em texto).

### 5.8 Logs — **MÉDIO**
`access_log off` no Nginx é correto. Falta rotação/purga de `storage/logs/*` e
redaction no `php-error.log`.

---

## 6. Cobertura de rotas

| Rota | Nginx roteia | PHP trata corretamente |
| --- | --- | --- |
| `/get.php` | sim | **parcial** (token obrigatório + creds apagadas) |
| `/player_api.php` | sim | **não** (sem reescrita de JSON) |
| `/xmltv.php` | sim | **não** (sem reescrita de XML) |
| `/live/` | sim | sim (stream) |
| `/movie/` | sim | sim (stream) |
| `/series/` | sim | sim (stream) |
| `.m3u8` | sim | parcial (§5.1) |
| `.ts` | sim | sim |
| `/hls/` | sim | sim |

---

## 7. Capacidades do painel hoje

| Requisito | Estado |
| --- | --- |
| Trocar origem XUI por IP ou DNS internamente | **pronto** (`origins.type` + `host_header`) |
| Origem só em SQLite, sem DNS público | **pronto** |
| Domínio público fixo com aliases | **pronto** (`aliases`) |
| Vários user/pass simultâneos via query string | **faltando** (bloqueado pelo token) |
| Reescrever playlist sem vazar origem | **parcial/arriscado** |
| Trocar origem sem downtime, com teste prévio | **faltando** |

---

## 8. Correções obrigatórias

1. `AccessGuard`: tornar o token **opcional por alias** (`aliases.require_token`).
   Modo padrão do objetivo: sem token, autenticação = par `username`/`password`
   repassado ao XUI (pass-through), validado por cache curto de resultado.
2. `PlaylistRewriter`: reescrever por URL parseada; **preservar** `username`/`password`
   do assinante; redigir apenas os valores de `origin.auth_user/auth_pass`;
   `publicBase` deve seguir o esquema real da requisição (`http` na porta 80).
3. `proxy.php`: classificar por `Content-Type` da resposta (não só por path), e
   aplicar reescrita a `application/json` (`player_api.php`) e `xml` (`xmltv.php`).
4. `StreamProxy`: `CURLOPT_FOLLOWLOCATION` com validação de host de destino contra
   a allowlist da origem; timeout maior para `player_api.php`; repassar `Range` e
   opcionalmente o UA do cliente.
5. SQLite: nova tabela `subscribers` (username, password_hash, alias_id, ativo,
   max_conexoes) + `sessions` para conexões simultâneas; índice em `access_log(token_id)`.
6. Nginx: apagar `nginx/site.conf.example`; deixar só o gerado. Manter
   `access_log off` e `fastcgi_buffering off`.
7. Docs: alinhar `PROXY_FLOW.md` e `CLOUDFLARE_SETUP.md` ao objetivo real
   (esconder XUI, não a VPS) e ao rate limit efetivo.
8. Higiene: purgar logs antigos com credenciais, `storage/` em 750,
   `origins.auth_pass` cifrado com `app_secret`.

---

## 9. Excesso / legado / simplificável

- `nginx/site.conf.example` — remover.
- `docs/GITHUB_BACKUP_SETUP.md`, `docs/LOVABLE_PROJECT_BRIEF.md`,
  `docs/MAIN_FLOW_EXAMPLE.md` — consolidar em um único `docs/OPERACAO.md`.
- Tabela `settings` duplica `origin_host`/`origin_port` que já vivem em `origins` —
  fonte de divergência; manter só em `origins`.
- `Audit` + `access_log` + `php-error.log`: três trilhas; manter duas.
- Chave SSH e qualquer material do pacote legado `dnsmain.site` devem ser revogados.

---

## 10. Roadmap por fases

**Fase A — desbloqueio funcional (P0, ~1 ciclo)**
- `aliases.require_token` (default 0) e ajuste em `AccessGuard`.
- `PlaylistRewriter` v2: parsing de URL, preserva credenciais do assinante,
  esquema dinâmico, allowlist de hosts de origem.
- `proxy.php`: reescrita por content-type (m3u, json, xml).

**Fase B — anti-vazamento (P1)**
- Validação de redirect por host.
- Redaction obrigatória de `auth_user`/`auth_pass` em todo corpo.
- Purga/rotação de logs; `storage/` 750; cifra de `auth_pass`.
- Teste automatizado: fixtures de m3u8/json com `dafonte.uk` e IP; asserção de
  ausência total da origem no corpo, headers e logs.

**Fase C — multiusuário real**
- Tabelas `subscribers` e `sessions`; limite de conexões simultâneas.
- Rate limit por (ip, subscriber).
- Painel: listagem de conexões ativas por assinante.

**Fase D — operação**
- Troca de origem com "testar antes de aplicar" no painel.
- HealthCheck usando o mesmo caminho do proxy (com `host_header`).
- Docs consolidados; remoção do legado.

---

## 11. Conclusão

A base arquitetural está correta e leve; o que falta não é reescrever, é corrigir a
camada de identidade (token vs credencial de assinante) e endurecer a reescrita de
conteúdo. Com a Fase A o fluxo pedido passa a funcionar de ponta a ponta; com a
Fase B a promessa de "não vaza a origem" deixa de ser parcial. Hoje, qualquer
playlist em que o XUI emita `dafonte.uk` entrega a origem ao cliente — essa é a
correção mais urgente.
---

## 12. Delta Fase A — implementado em 31/07/2026

### 12.1 Identidade: fluxo XUI clássico liberado
- `aliases.require_token` (migração idempotente, default **0**).
- `AccessGuard::check()` só exige token quando o alias tem `require_token = 1`.
  Com o default, `/get.php?username=...&password=...&type=m3u_plus&output=hls`
  funciona sem `?t=`. Se um token for enviado, continua sendo validado
  (existência, expiração, alias, IP).
- Painel: checkbox "Exigir token" na criação e edição de alias.

### 12.2 Credenciais
- `StreamProxy::buildOriginUrl()` **repassa** `username`/`password` do assinante.
  As credenciais da origem (`origins.auth_user/auth_pass`) só entram quando o
  assinante não envia nenhuma (origem de conta única).
- `PlaylistRewriter` **não apaga mais** `username`/`password` — apaga apenas os
  valores literais de `auth_user`/`auth_pass` da origem, por substituição direta.

### 12.3 Sanitização de conteúdo (v2)
`PlaylistRewriter::rewrite($body, $origin, $publicHost, $token, $publicScheme)`:
- allowlist de hosts da origem = `host` + `host_header` + novo campo
  `origins.extra_hosts` (lista separada por vírgula, editável no painel);
- substitui `scheme://host[:porta]`, `//host[:porta]`, a forma com barras
  escapadas de JSON (`http:\/\/host`) e o host "cru" restante (EPG, campos
  `server_url`/`url` do `player_api.php`);
- `publicScheme` agora vem da requisição real (`http` na porta 80, `https` via
  `X-Forwarded-Proto` quando o peer é Cloudflare) — antes forçava `https`;
- o token só é anexado às linhas quando o alias opera em modo token.

Teste manual executado com fixture contendo `dafonte.uk:80`, IP da origem e JSON
com barras escapadas: saída sem nenhuma referência à origem e com
`username/password` do assinante intactos.

### 12.4 Rotas textuais
`public/proxy.php` agora manda para o ramo **buffered + reescrita**:
`get.php`, `player_api.php`, `xmltv.php`, `panel_api.php`, `*.m3u`, `*.m3u8`,
`*.xml`, `*.json`. Antes só `get.php` e `.m3u8` — `player_api.php` e `xmltv.php`
vazavam a origem no corpo. Content-Type do upstream é preservado.

### 12.5 Redirects
- `CURLOPT_FOLLOWLOCATION` desligado nos dois caminhos (buffered e stream).
- Redirect é resolvido manualmente, no máximo 3 saltos, e **só** quando o destino
  é relativo ou aponta para um host da allowlist da origem. Destino externo → 502.
- O header `Location` da origem nunca é repassado ao cliente.
- Timeout do buffered subiu de 20s para 60s (catálogo grande de `player_api.php`).

### 12.6 Logs
`AccessGuard::logAccess()` mascara credenciais no path antes de gravar:
`/live/<user>/<pass>/1.ts` → `/live/*/*/1.ts`, e `username|password|token|t` em
query string → `***`.

### 12.7 Cobertura de rotas após a Fase A

| Rota | Estado |
| --- | --- |
| `/get.php` | **pronto** (sem token, creds preservadas, corpo sanitizado) |
| `/player_api.php` | **pronto** (JSON sanitizado, incl. barras escapadas) |
| `/xmltv.php` | **pronto** (XML sanitizado) |
| `/live/` `/movie/` `/series/` `/hls/` | **pronto** (stream + allowlist de headers) |
| `.m3u8` | **pronto** |
| `.ts` | **pronto** |

### 12.8 O que continua aberto (Fases B–D)
- `origins.auth_pass` em texto claro no SQLite; `storage/` em 775.
- Sem tabela `subscribers`/`sessions`: não há limite de conexões simultâneas por
  conta nem rate limit por assinante (só por IP).
- Purga dos logs antigos do servidor que ainda contêm URLs com credenciais.
- `nginx/site.conf.example` legado com IP versionado — remover.
- Docs `PROXY_FLOW.md` e `CLOUDFLARE_SETUP.md` ainda descrevem "esconder a VPS".
- Sem teste automatizado de regressão anti-vazamento.

### 12.9 Maturidade
Antes da Fase A: ~55%. Depois: **~80%** do objetivo funcional. O fluxo público
pedido roda de ponta a ponta e as quatro vias de vazamento de corpo (playlist,
JSON, XML, redirect) estão fechadas. O que falta é endurecimento (Fase B) e
controle real de assinante/conexões (Fase C).

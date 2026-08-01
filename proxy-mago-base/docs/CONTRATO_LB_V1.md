# CONTRATO CÉREBRO <-> MÚSCULO — v1.0

Status: **implementado e testado** (`bash bin/smoke-statestore.sh`, 61 ok / 0 falhas)
Arquivos: `app/LbContract.php`, `public/lb-contract.php`, `public/lb-events.php`,
`app/StateStore.php`, `app/RedisClient.php`.

## Por que existe

Antes disto, o LB só funcionava porque `LbPackageBuilder` empacotava as classes
PHP do próprio proxy (Database, AccessGuard, CdnSession, PlaylistRewriter,
StreamProxy). Ou seja: o músculo era o mesmo runtime do cérebro. Isso impede
trocar o caminho quente por outra linguagem (Go) sem reescrever regra de
negócio no escuro.

O contrato v1 quebra esse acoplamento. A partir daqui o músculo pode ser
**qualquer coisa** que fale JSON: PHP hoje, Go depois.

## Versionamento

- `contract_version` é obrigatório nas duas direções.
- **Major igual** = compatível. Campo desconhecido é ignorado, nunca fatal.
- **Major diferente** = o cérebro responde `409` e o músculo entra em modo
  conservador (continua servindo com o último snapshot válido, sem aplicar
  regra nova).

## 1. SNAPSHOT — cérebro -> músculo

```
GET /lb-contract.php?contract_version=1.0
X-LB-Token: <token do nó em lb_nodes>
```

O músculo busca a cada `ttl` (30s) e decide sozinho no caminho quente — zero
ida ao cérebro por segmento `.ts`.

```json
{
  "ok": true,
  "contract": "cdnvoods.lb",
  "contract_version": "1.0",
  "generated_epoch": 1785000000,
  "ttl": 30,
  "lb":      { "id": 2, "label": "LB-02", "public_ip": "...", "enabled": true, "drain": false },
  "state":   { "driver": "redis", "effective_driver": "redis", "degraded": false,
               "namespace": "cdnv:", "redis": { "host": "...", "port": 6379, "db": 0, "has_password": true } },
  "runtime": { "sessions_enabled": true, "enforce_ip_lock": true, "enforce_connection_limit": true,
               "follow_direct_source": true, "require_token": false, "allowed_user_agent": "",
               "rate_limit_per_minute": 240, "session_ttl_live": 120, "session_ttl_vod": 1800,
               "log_requests": true },
  "origins": [ { "id": 1, "scheme": "http", "host": "...", "port": 80, "host_header": "...", "active": true } ],
  "aliases": [ { "id": 1, "hostname": "voods.suafontee.com", "origin_id": 1, "active": true } ],
  "users":   [ { "username": "joao", "max_connections": 2, "exp_date": "...",
                 "allowed_ips": ["45.140.192.0/24"], "ip_locked": true } ],
  "brain":   { "events_url": "/lb-events.php", "snapshot_url": "/lb-contract.php",
               "heartbeat_url": "/lb-ingest.php", "events_max_batch": 500, "auth_header": "X-LB-Token" }
}
```

### Segurança do snapshot

O snapshot **contém host/porta da origem XUI** — o músculo precisa disso para
falar com a origem. Portanto:

- sem `X-LB-Token` válido → `401`, corpo vazio de dados;
- a **senha do Redis nunca viaja** no snapshot; ela é instalada no músculo pelo
  instalador (`bin/lb-install.sh`);
- o snapshot só deve trafegar em canal TLS ou rede privada entre nós.

## 2. EVENTOS — músculo -> cérebro

```
POST /lb-events.php
X-LB-Token: <token>
Content-Type: application/json

{ "contract_version": "1.0", "events": [ ... ] }
```

Lote de até **500 eventos** (2 MB). Resposta:

```json
{ "ok": true, "contract_version": "1.0", "accepted": 498, "rejected": 2, "errors": ["#3 tipo desconhecido: xpto"] }
```

### Tipos de evento

| type | efeito no cérebro |
|---|---|
| `session_open` / `session_touch` | `CdnSession::touch()` + `tagLb()` |
| `request` | `CdnSession::record()` + trilha `RequestLog::open/close` (`reason=lb_served`) |
| `session_close` | `CdnSession::heartbeat()` + `StateStore::sessionClose()` |
| `session_reject` | `CdnSession::reject()` com motivo |
| `heartbeat` | `LbTelemetry::record(source=contract)` |

Campos aceitos: `host`, `client_ip`, `path`, `query`, `username`, `password`,
`user_agent`, `request_id`, `session_key`, `status`, `bytes`, `direct_host`,
`hops`, `origin_id`, `inconsistency`, `reason`, métricas de heartbeat.

### Regra dura: quem manda é o cérebro

O músculo envia `path` e `query` crus. **Classificação de rota, fingerprint de
credencial e mascaramento são refeitos no cérebro** (`RequestContext::build`).
Nunca confiamos na classificação do músculo. Assim a regra de negócio existe em
UM lugar só e o motor Go pode ser burro e rápido.

## 3. Estado vivo compartilhado (Fase 2)

`app/StateStore.php` — dois drivers, mesmo comportamento (paridade provada no
smoke):

- `sqlite` (padrão): tabelas próprias `state_kv` e `state_members`, criadas sob
  demanda. Zero janela de migração.
- `redis`: destino oficial da Fase 2. Cliente RESP2 em PHP puro
  (`app/RedisClient.php`) — **não exige phpredis**, para o corte não depender de
  extensão compilada na VPS.

### Chave de corte

```
state_driver = sqlite | redis     # settings, config/app.php, ou PROXY_MAGO_STATE_DRIVER
redis_host / redis_port / redis_pass / redis_db / redis_timeout
```

Troca de driver **não exige deploy**.

### Layout de chaves (CONTRATO — o Go usa as mesmas)

| chave | tipo | conteúdo |
|---|---|---|
| `cdnv:sess:<session_key>` | string JSON + TTL | sessão viva |
| `cdnv:user:<username>` | set | índice de sessões do usuário |
| `cdnv:lb:<lb_id>` | string JSON + TTL | presence/heartbeat do nó |
| `cdnv:<livre>` | string | contadores (`INCRBY`), caches curtos |

O índice por usuário é **auto-podado na leitura**: sessão expirada sai do
conjunto sem job de limpeza. Isso é o que permite `userCount()` virar o
enforcement de limite de conexão sem varredura em SQLite.

### Degradação (regra inegociável)

Redis fora do ar **não derruba player**. Qualquer falha:

1. marca o Redis como fora no processo;
2. cai automaticamente para o driver `sqlite`;
3. expõe o motivo em `StateStore::health()` → `{driver, configured, degraded, reason}`.

O smoke prova isso apontando para uma porta morta: escrita e leitura continuam
funcionando, com `degraded=true`.

## 4. Como validar

```bash
# só sqlite
bash bin/smoke-statestore.sh

# paridade real sqlite x redis (exige redis local)
PROXY_MAGO_REDIS_HOST=127.0.0.1 php bin/smoke-statestore.php

# bateria oficial completa (já inclui este smoke)
bash bin/smoke-all.sh
```

## 5. O que vem depois

1. **Corte real do estado vivo para Redis na VPS** `45.140.192.237`
   (`state_driver=redis`, observar `degraded` no painel por 24h).
2. Corte real do banco frio para PostgreSQL (ensaio já fechado:
   `docs/S2_P0_5_ENSAIO_CORTE_POSTGRES_2026-07-31.md`).
3. ~~Motor Go no músculo~~ — **feito**: `lb-go/` consome este contrato
   (playlist, HLS, direct source, trava de IP com CIDR, limite de conexão,
   sessão/uptime e envio de eventos em lote). Build: `bin/lb-go-build.sh`;
   canário em 1 nó: `bin/lb-go-deploy.sh`. Ordem de produção em
   `docs/CHECKLIST_FINAL_PRODUCAO_100_2026-08-01.md`.

### Campos de origem no snapshot (v1.0)

Além de `scheme/host/port/host_header`, o snapshot entrega `extra_hosts`,
`base_path`, `auth_user` e `auth_pass`. O músculo precisa deles para **mascarar**
o corpo (todo host da origem) e para falar com origem de conta única. Por isso o
snapshot é servido SOMENTE com `X-LB-Token` válido e por canal privado/TLS.
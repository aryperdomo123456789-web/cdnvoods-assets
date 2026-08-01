# lb-go — músculo do CDN Voods em Go

Motor do caminho quente. Não tem banco, não tem PHP, não tem regra própria:
lê o snapshot do contrato v1 (`docs/CONTRATO_LB_V1.md`), entrega o byte e
devolve eventos para o cérebro.

## O que ele faz

| caminho quente | implementado em |
|---|---|
| playlist textual (`get.php`, `.m3u`, `.m3u8`, `player_api.php`, `xmltv.php`) | `internal/proxy/handler.go` + `rewrite.go`, linha a linha, memória constante |
| binário/HLS (`.ts`, segmentos, `Range`) | passthrough com buffer de 64 KB, sem timeout de escrita |
| direct source (302 para CDN de terceiros) | seguido POR DENTRO, host novo entra no mascaramento |
| trava por IP (com CIDR) | `contract.User.IPAllowed` |
| limite de conexão | `state.Store.UserCount` (índice auto-podado em `cdnv:user:<u>`) |
| sessão / uptime | `cdnv:sess:<key>` com TTL live/vod do snapshot |
| eventos para o cérebro | `internal/events`, lote de 500, fora do caminho quente |
| heartbeat / presence | `internal/telemetry` → `cdnv:lb:<id>` + evento `heartbeat` |

## Invariantes

1. **Host, porta e credencial da origem nunca saem** — nem no corpo, nem em
   cabeçalho (`Server`, `Location`, `Set-Cookie` são descartados).
2. **Nada derruba player**: cérebro fora do ar → segue com o último snapshot;
   Redis fora do ar → limite de conexão é liberado e a degradação aparece em
   `/healthz`; fila de eventos cheia → descarta telemetria, nunca o stream.
3. **Regra vive no cérebro**: o Go manda `path`/`query` crus, e o PHP refaz
   classificação, fingerprint e mascaramento em `RequestContext::build`.
4. **Major do contrato diferente** → modo conservador, sem aplicar regra nova.

## Build e deploy

```bash
bash bin/lb-go-build.sh                                     # vet + testes + binário estático
bash bin/lb-go-deploy.sh <ip-lb> <token> https://painel ... # canário em 1 nó
```

O deploy só ativa o serviço depois de `lb-go -check` validar o snapshot: se o
contrato não responder, nada é trocado no nó.

## Configuração (`/etc/cdnvoods-lb.env`)

```
LB_LISTEN=127.0.0.1:8081
LB_BRAIN_URL=https://painel.exemplo.com
LB_TOKEN=<lb_nodes.token>
LB_REDIS_HOST=... LB_REDIS_PORT=6379 LB_REDIS_PASS=... LB_REDIS_DB=0
LB_PUBLIC_SCHEME=http
```

A senha do Redis **não vem no snapshot** — ela é instalada aqui, no nó.

## Canário (PHP x Go no mesmo LB)

```bash
curl -s http://127.0.0.1:8081/healthz | jq
journalctl -u lb-go -f
```

Compare no painel (`/restream.php`) o mesmo usuário servido pelo nó PHP e pelo
nó Go: sessão, uptime, bytes, `direct_host` e hops têm que bater. Divergência =
não promove.
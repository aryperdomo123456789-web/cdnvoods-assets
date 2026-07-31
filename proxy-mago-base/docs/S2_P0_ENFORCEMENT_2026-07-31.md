# Sprint 2 — P0 fechado: trava por IP, limite de conexão e uptime real

Data: 2026-07-31
Alvo: VPS `45.140.192.237`, Ubuntu 22.04, `/opt/proxy-mago/proxy-mago-base`
Entrada oficial de estado: `docs/00_ORDEM_OFICIAL_E_ESTADO_REAL.md`

Nada de banco novo, nada de dependência nova: só endurecimento do que já roda,
com prova em smoke rodável na VPS.

## S2-P0-1 — Trava CDN por IP (`app/UserIpLock.php`)

Bugs reais corrigidos (todos furavam ou travavam injustamente em produção):

| Antes | Agora |
|---|---|
| `999.1.1.1` e `1.2.3.4` com octeto > 255 eram aceitos como regra | validação por `filter_var`, octeto inválido é recusado |
| `/0` calculava máscara com `-1 << 32` e dava veredito errado | máscara em 32 bits (`0xFFFFFFFF`), `/0` libera IPv4 e **não** libera IPv6 |
| curinga só funcionava em `a.b.c.*` | `45.140.192.*`, `45.140.*` e `45.*` (curinga só no fim) |
| IPv6 só por igualdade exata | IPv6 exato **e** CIDR (`2001:db8::/32`) |
| regra inválida era descartada em silêncio | `save()` devolve `valid`/`invalid` e o painel avisa o que foi recusado |
| bloqueio no log sem motivo | `explain()` devolve `reason` (`no_lock`, `rule_match`, `no_rule_match`, `client_ip_invalid`) e a regra que liberou |

Regra de produto mantida: lista vazia = usuário liberado (trava é opt-in por
usuário; ninguém perde stream por falta de cadastro). IP de cliente corrompido
com trava ativa = bloqueio.

Aplicação: `public/proxy.php` bloqueia com 403 antes de qualquer request ao XUI
e registra `cdn_ip_lock_blocked` com motivo e quantidade de regras.

Prova: `bash bin/smoke-ip-lock.sh` — 22 ok / 0 falhas.

## S2-P0-2 — Enforcement de limite pela CDN

Sem mudança de comportamento (o motor já existia em `Divergence::shouldBlock()`
+ `CdnSession::activeCount()`); o que faltava era **prova**:

- `limit_mode=alert` (padrão) nunca bloqueia — produção segue segura;
- `limit_mode=block` recusa a conexão acima do plano com HTTP 429;
- `playlist`/`api` nunca ocupam slot (baixar m3u de 128 MB não gasta conexão);
- `limit_tolerance_seconds` protege reconexão honesta;
- fechar uma tela devolve o acesso e limpa `user_limit_state`;
- estouro aparece no painel como divergência `above_limit`;
- usuário sem plano espelhado (`max_connections=0`) nunca é punido.

Prova: `bash bin/smoke-limit.sh` — 12 ok / 0 falhas.

## S2-P0-3 — Uptime real por sessão (`app/CdnSession.php`)

Furo corrigido: a tolerância de retomada do uptime só valia para sessão com
`direct_source = 1`. Filme servido pela própria CDN e canal ao vivo resetavam o
uptime a cada pausa acima do idle — o painel mostrava "assistindo há 4s" para
quem estava duas horas no mesmo conteúdo.

- graça de retomada agora é por TIPO de consumo, com ou sem direct:
  `movie`/`series` 1800s, `live`/`hls` 120s, `other` 600s;
- a graça vale também para sessão ainda `active` com buraco de atividade
  (antes só se a sessão tivesse sido fechada);
- `CdnSession::record()` passou a preencher `direct_host_runtime`,
  `direct_host_effective`, `direct_first_epoch` e `direct_last_epoch` — sessão
  direct não fica mais sem host final para a triagem de `DirectHostHealth`.

Comportamento preservado de propósito: abandono longo abre uptime novo e troca
de filme abre sessão nova (a anterior morre como `superseded`).

Prova: `bash bin/smoke-uptime.sh` — 14 ok / 0 falhas.

## Como validar na VPS depois do pull

```bash
cd /opt/proxy-mago/proxy-mago-base
git pull
bash bin/smoke-ip-lock.sh
bash bin/smoke-limit.sh
bash bin/smoke-uptime.sh
bash bin/smoke-lb.sh
bash bin/smoke-fresh.sh
bash bin/smoke-direct-health.sh
```

Todos os smokes limpam o que criam (usuário `smoke_*`) e restauram
`limit_mode` / `limit_tolerance_seconds` ao valor anterior.

## Próximo passo (ordem oficial)

1. `S1-P3` — instalar `LB-02` real pelo painel e preencher `docs/BASELINE_CARGA_LB.md`.
2. `S2-P0-4` / Fase 2.1 — camada `app/StateStore.php` (driver `sqlite` atual +
   `redis`) antes de qualquer migração de banco.

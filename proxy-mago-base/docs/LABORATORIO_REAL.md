# Laboratório real — validar aqui antes de rodar na VPS

Um comando só. Sem mock, sem XUI falso: banco real, origem real, domínio real.

## Como rodar

Credenciais **nunca** entram no repositório. Exporte e rode:

```bash
export LAB_DB_HOST=38.190.176.170 LAB_DB_PORT=3306 LAB_DB_NAME=xui
export LAB_DB_USER=... LAB_DB_PASS=...
export LAB_ORIGIN=38.190.176.170 LAB_PUBLIC=voods.suafontee.com
export LAB_USER=... LAB_PASS=...
export LAB_BASE=http://127.0.0.1:8080 LAB_STREAMS=3

bash bin/lab/run-lab.sh          # usa $PHP_BIN, ou php do sistema
```

Na VPS `45.140.192.237` o mesmo script roda apontando `LAB_BASE=http://127.0.0.1`
(Nginx local) — o teste é idêntico, só muda a porta de entrada.

## O que ele prova (11 etapas, 18 checagens)

| Etapa | Prova |
|---|---|
| 0-1 | MySQL do XUI responde read-only; usuários, catálogo e `user_activity_now` espelham |
| 2 | origem XUI mapeada no domínio público, sem expor IP/DNS do main |
| 3 | `get.php` real: HTTP 200, **baixa como arquivo** (`Content-Disposition`), headers sem vazar origem |
| 4 | `player_api.php` real com porta pública reescrita |
| 5 | `/live/...` em streams distintos do catálogo real = 1 conexão por stream |
| 6 | burst HLS no mesmo stream **não** infla conexão |
| 7 | contador inteligente: em uso x contratado, playlist/api fora do plano |
| 8 | jobs `consolidate_runtime` + `metrics_rollup_light` (o painel lê KPI consolidado) |
| 9 | KPI do painel bate com o tráfego disparado |
| 10 | veredito de saúde do host final do direct source (`ok`/`blocked`/...) |
| 11 | nós de LB registrados |

## Furos reais encontrados e corrigidos por este laboratório

1. **KPI cego para o laboratório** — `CdnSession::publicClientWhereSql()` descarta
   loopback (correto em produção: health check e cron não são cliente). Isso fazia
   todo tráfego de teste virar zero no painel. Agora existe o interruptor explícito
   `CDN_LAB_COUNT_LOOPBACK=1` / setting `lab_count_loopback`, ligado **só** pelo lab.
   Produção segue ignorando loopback.
2. **`get.php` sem `Content-Disposition`** — a correção antiga tinha se perdido:
   `StreamProxy::streamTextual()` não aceitava headers extras. Voltou a sair como
   download (`playlist.m3u`), então navegador/player não tenta reproduzir a lista.
3. **KPI zerado com sessão aberta** — não era bug: `UserIntelligence::totals()` lê
   `cdn_metrics` consolidado por job. O lab agora roda os jobs e valida o número final.

## Leitura do 403 no `/live/...`

No lab real, `467480` e vizinhos saem **403 `Access denied`**. A origem `38.190.176.170`
responde **302** para `readyondemand.click`; quem nega é o **host final** do direct source.
Veredito automático: `blocked`, culpa `host_final`. Mexer no proxy nesse caso é perder
tempo — o caminho é liberar o IP de saída no host final ou trocar a fonte.

## Números medidos (execução real)

- ping MySQL do XUI: ~1,1 s
- usuários espelhados: 16 | catálogo: 483.869 streams
- `get.php` m3u_plus: HTTP 200, 128.012.102 bytes, RAM estável (linha a linha)
- 3 streams distintos = 3 conexões; burst de 5 = mesmo total
- usuário `P2on2325154215633`: 3 em uso de 1000 (997 livres), fonte `cdn_local`
- KPI consolidado: vídeo 3, playlist/api 2, online 1
- resultado: **18 ok / 0 falhas**

# CDN Inteligente — sessões locais, direct source e divergências

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data: 2026-07-31
VPS: `45.140.192.237` · Ubuntu 22.04 · path `/opt/proxy-mago/proxy-mago-base`
Repositório: `aryperdomo123456789-web/cdnvoods-assets` (branch `backup` → depois `main`)

Este documento fecha o que estava listado em `docs/FALTAS_RESTREAMENTO_INTELIGENTE.md`.

## O que mudou na prática

Antes a CDN contava **requests**. Agora ela conta **conexões**.

- request = uma linha de log
- conexão = uma sessão lógica local, com início, atividade e fim

Sem isso, 10 segmentos HLS pareciam 10 conexões e um `direct source` parecia zero.

## 1. Sessão local da CDN (`cdn_sessions`)

Cada request é agrupado numa sessão por chave estável:

```
username + client_ip + fingerprint(user_agent) + tipo + stream_id
```

Tipos e timeout de ociosidade (quando a sessão é considerada encerrada):

| tipo       | timeout | observação                                   |
|------------|---------|----------------------------------------------|
| `playlist` | 30s     | download de m3u, não é consumo contínuo       |
| `api`      | 30s     | `player_api.php`, `xmltv.php`                 |
| `hls`      | 45s     | bursts de segmentos fundidos na mesma sessão  |
| `live`     | 90s     | canal ao vivo                                 |
| `movie`    | 180s    | VOD tolera pausa maior                        |
| `series`   | 180s    | idem                                          |
| `segment`  | 45s     | `.ts` solto                                   |

Regras aplicadas:

- bursts de HLS não inflam a contagem — vários requests tocam a mesma sessão
- reconexão rápida reaproveita a sessão dentro da tolerância configurada
- `session_sweep` encerra sessões ociosas e mantém o contador honesto

O contador local é **independente do XUI**: funciona mesmo com o MySQL do XUI fora do ar.

## 2. Matching com o XUI (score explícito)

Cada sessão local recebe `match_confidence` e `match_reason`:

- `high` — usuário + IP + player + janela de tempo + stream batem
- `medium` — usuário + IP + janela batem, stream ou player divergem
- `low` — só o usuário bate
- `invalid` — sessão sem correspondência aceitável

O motivo do match fica salvo e aparece no painel. Match fraco é destacado —
é aí que normalmente mora o problema real.

## 3. Direct source rastreado por dentro (`direct_source_hops`)

Quando a origem responde com redirect, a VPS **não repassa o Location**:
ela segue o hop por dentro e serve o conteúdo. Cada hop grava:

- índice do hop, host de origem, host de destino, host final
- status HTTP, resultado (`ok`, `blocked`, `failed`, `abandoned`)
- `request_id` e `session_key` correlacionados

O painel separa claramente:

- request público (o que o player viu)
- origem XUI (o que a CDN pediu)
- host final do direct (o que só a CDN sabe)

O player nunca enxerga esses hosts. A fonte de verdade do `direct source`
é a CDN, não o XUI.

## 4. Estouro de limite — modos de operação

Configurável em `/restream.php` → *Regras da CDN*:

- `alert` — só registra a divergência
- `mark` — registra e marca o usuário como risco no painel
- `block` — nega conexões acima do limite do plano

A **tolerância para reconexão** (padrão 45s) evita bloquear quem só trocou de
canal ou reabriu o app. Cada decisão registra a origem do contador:
`cdn_local`, `xui_activity_now` ou `merged`.

## 5. Divergências visíveis (`cdn_divergences`)

Quadro dedicado no painel, com severidade e causa provável:

| tipo                       | severidade | leitura típica                        |
|----------------------------|------------|---------------------------------------|
| `above_limit`              | critical   | consumo acima do plano                 |
| `invalid_credentials_swap` | critical   | resposta com credencial trocada        |
| `count_mismatch`           | warn       | CDN vê 3, XUI vê 2                     |
| `orphan_request`           | warn       | request sem sessão no XUI (direct?)    |
| `orphan_activity`          | warn       | sessão no XUI sem request local        |
| `unknown_user`             | warn       | usuário não espelhado                  |
| `sync_stale`               | warn       | espelho do XUI desatualizado           |

Filtro rápido por severidade e contadores no topo.

## 6. KPIs operacionais

No topo de `/restream.php`:

- conexão: ativos agora, pico 5 min, pico 1 h, média por usuário
- qualidade: erros, swaps bloqueados, redirects direct, jobs atrasados
- consistência: matches `high/medium/low`, divergências CDN vs XUI

Histórico consolidado em `cdn_metrics` pelo job `metrics_rollup`.

## 7. Jobs internos

Nada roda invisível — tudo passa pelo `JobRunner` e aparece em `/jobs.php`:

| job                    | intervalo |
|------------------------|-----------|
| `xui_sync_activity`    | 5s        |
| `match_sessions`       | 10s       |
| `consolidate_runtime`  | 10s       |
| `session_sweep`        | 15s       |
| `detect_inconsistency` | 30s       |
| `metrics_rollup`       | 60s       |
| `xui_sync_users`       | 60s       |
| `repair_retry`         | 300s      |
| `cleanup`              | 3600s     |

## Deploy nesta VPS

```bash
cd /opt/proxy-mago/proxy-mago-base
git pull origin main
bash bin/deploy.sh
php bin/jobs-run.php --tick
```

A migração de schema roda sozinha no primeiro acesso (`Database::migrate()`).

## Validação

```bash
bash bin/smoke-intelligence.sh voods.suafontee.com <username> <password>
```

Cobre: agrupamento de sessão, burst HLS, contador local, direct source,
vazamento de origem no corpo e jobs atrasados.

## Critério de aceite

- [x] contagem local de conexões com precisão, independente do XUI
- [x] painel cruzando CDN × XUI com fonte explícita do contador
- [x] `direct source` rastreado de ponta a ponta, inclusive host final
- [x] divergências visíveis, com severidade e causa provável
- [x] histórico suficiente para auditoria (`cdn_metrics`, `job_runs`)
- [x] smoke test cobrindo cenários multiusuário

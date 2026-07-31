# Baseline de carga por LB (Fase 1.7)

Objetivo: ter um numero REAL de simultaneas por musculo antes de decidir
Redis (Fase 2) ou motor Go (Fase 4). Sem baseline, escala e chute.

## Como medir (rodar no LB, nao no main)

1. LB instalado pelo painel (`/lb.php`), status `installed`, health `ok`.
2. No main, garantir jobs vivos: `php bin/jobs-run.php --profile=fast`.
3. Medicao de entrega (do seu terminal, contra o DNS publico apontado no LB):

```
# 1 usuario, 1 playlist: valida caminho e tempo de resposta
curl -o /dev/null -s -w 'code=%{http_code} tempo=%{time_total}s tamanho=%{size_download}\n' \
  'http://SEU_DNS/get.php?username=USER&password=SENHA&type=m3u_plus&output=ts'

# carga: 50 playlists simultaneas
seq 1 50 | xargs -P50 -I{} curl -o /dev/null -s -w '%{http_code} %{time_total}\n' \
  'http://SEU_DNS/get.php?username=USER&password=SENHA&type=m3u_plus&output=ts'
```

4. Durante a carga, telemetria no painel de LB (CPU, RAM, TX, sessoes) e, no LB:

```
ss -Htn state established | wc -l
uptime
```

5. Smokes locais (nao substituem carga real, mas travam regressao):

```
bash bin/smoke-lb.sh        # rota, queda de LB, fallback, supersede
bash bin/smoke-fresh.sh     # frescor + polling adaptativo + micro-cache
bash bin/smoke-restream.sh  # painel ao vivo
```

## Tabela de baseline (preencher com medicao real)

| LB | vCPU | RAM | Banda | Simultaneas OK | CPU no pico | TX no pico | Onde comecou a degradar |
|----|------|-----|-------|----------------|-------------|------------|-------------------------|
| LB-01 | | | | | | | |
| LB-02 | | | | | | | |

## Criterio de aceite da Fase 1.7

- Simultaneas por LB medidas com playlist + binario, nao so playlist.
- Telemetria do painel batendo com `ss -Htn` no LB (margem de +-10%).
- Contador de conexao por usuario igual ao do XUI durante a carga.
- Ponto de degradacao anotado (CPU >85%, TX no teto da NIC ou erro 5xx > 1%).

## Limites conhecidos do ambiente de desenvolvimento

Este ambiente nao e a VPS: nao tem NIC dedicada, nem o XUI real sob carga, nem
`php8.1-fpm` com o mesmo `pm.max_children`. Numero medido aqui NAO vale como
baseline; serve apenas para provar que o codigo nao quebrou.

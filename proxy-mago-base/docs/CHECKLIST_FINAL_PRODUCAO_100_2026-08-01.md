# Checklist final para "produção 100%" — 1 de agosto de 2026

Resposta direta à auditoria: o motor Go **agora existe** (`lb-go/`, compilado,
testado, com deploy canário). O que falta é execução na VPS, na ordem abaixo.
Cada bloco só começa quando o anterior fecha.

## Bloco 1 — antes do Redis (só no cérebro, `45.140.192.237`)

```bash
bash bin/deploy.sh
bash bin/smoke-all.sh        # exige: 0 falhas / 0 locks
```

- [ ] `php -v` = 8.1.x e nenhum fatal no `journalctl -u php8.1-fpm`.
- [ ] `/lb.php` com pelo menos 1 nó `installed` + `healthy`.
- [ ] SQLite do painel sob controle (o arquivo de 8,99 GB é log frio: rodar a
      retenção antes do corte, senão o ensaio mede disco, não Redis).

## Bloco 2 — corte do estado vivo para Redis

```bash
apt-get install -y redis-server
# redis.conf: maxmemory-policy volatile-lru (allkeys-* apagaria sessão)
php bin/redis-cut.php            # ensaio, não altera nada
php bin/redis-cut.php --apply    # corte
```

- [ ] `/avancado.php#escala` mostra `driver efetivo = redis`, `degradado = não`.
- [ ] 24 h sem `database is locked` no log de runtime.
- [ ] Rollback testado uma vez: `php bin/redis-cut.php --rollback`.

## Bloco 3 — todos os usuários saindo por LB

- [ ] `lb_default_mode = auto` em `/avancado.php#escala`.
- [ ] Job `lb_autoroute` executado e sem usuário ativo sem rota.
- [ ] `/restream.php` mostrando tráfego com `served_by = lb`.
- [ ] Só então `lb_require_delivery = 1` (o painel recusa ligar sem LB saudável).

## Bloco 4 — motor Go no músculo (canário)

```bash
bash bin/lb-go-build.sh
bash bin/lb-go-deploy.sh <ip-do-LB-02> <token> https://painel.exemplo.com 8081
# no nó: upstream do Nginx aponta para 127.0.0.1:8081
curl -s http://127.0.0.1:8081/healthz
```

- [ ] `lb-go -check` valida o snapshot antes de ativar (o script já faz isso).
- [ ] Paridade funcional contra o nó PHP, com o MESMO usuário:
      playlist, HLS/`.ts`, direct source, trava de IP, limite de conexão,
      sessão/uptime, bytes, `direct_host`, hops.
- [ ] Nenhum vazamento: `curl -sI` do público não traz `Server` da origem, e o
      corpo da playlist não contém host/porta/credencial do XUI.
- [ ] 24 h de canário em 1 nó antes de promover para os demais.

## Bloco 5 — banco frio no PostgreSQL

```bash
PROXY_MAGO_DB_DRIVER=pgsql php bin/pg-migrate.php
bash bin/smoke-pg-cut.sh
php bin/pg-cut.php --apply
```

- [ ] Paridade de contagem tabela por tabela (o script já compara).
- [ ] Painel e jobs lendo do Postgres sem regressão por 24 h.

## Bloco 6 — chamar de "produção 100%"

- [ ] 48 h com Redis (estado vivo) sem degradação.
- [ ] 48 h com 100% do tráfego saindo por LB, `lb_require_delivery=1`.
- [ ] 6 a 8 LBs com heartbeat confiável, score/rebalance estável e baseline de
      capacidade real por nó (`docs/BASELINE_CARGA_LB.md`).
- [ ] Zero `database is locked`, zero usuário no main, zero regressão em player.

## O que ficou fora do que eu consigo fazer daqui

Nada disso é código: é execução na VPS (instalar Redis, aplicar o corte,
promover o canário, observar 24/48 h). O código dos 6 blocos está no repositório
e validado por `bash bin/smoke-all.sh`.
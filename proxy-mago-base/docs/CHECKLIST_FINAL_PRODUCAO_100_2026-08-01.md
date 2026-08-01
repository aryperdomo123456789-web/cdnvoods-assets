# Checklist final para "produção 100%" — 1 de agosto de 2026

Resposta direta à auditoria: o motor Go **agora existe** (`lb-go/`, compilado,
testado, com deploy canário). Em `1 de agosto de 2026`, a execução na VPS
ficou neste estado real:

- [x] Go `1.26.5` instalado no cérebro.
- [x] Redis instalado e o `state_driver` efetivo do cérebro está em `redis`.
- [x] `lb_require_delivery=1` e `lb_default_mode=auto`.
- [x] Um LB (`143.14.168.78`) está ativo, saudável e servindo pelo `lb-go`
      atrás do Nginx do nó.
- [x] `GET /lb-contract.php` e `POST /lb-events.php` responderam `200 OK`.
- [x] `get.php` no LB devolveu playlist real pelo motor Go.
- [ ] `pg-cut` ainda está copiando o volume histórico grande de
      `cdn_divergences` para o PostgreSQL.
- [ ] A janela de observação de `24h/48h` ainda não pode ser marcada como
      concluída antes de o tempo realmente passar.

O que falta, portanto, já não é ausência de código. É fechamento da migração
fria do banco e observação operacional.

## Bloco 1 — antes do Redis (só no cérebro, `45.140.192.237`)

```bash
bash bin/deploy.sh
bash bin/smoke-all.sh        # exige: 0 falhas / 0 locks
```

- [x] `php -v` = 8.1.x.
- [x] `/lb.php` com pelo menos 1 nó `installed` + `healthy`.
- [ ] SQLite do painel sob controle (o arquivo de 8,99 GB é log frio: rodar a
      retenção antes do corte, senão o ensaio mede disco, não Redis).

## Bloco 2 — corte do estado vivo para Redis

```bash
apt-get install -y redis-server
# redis.conf: maxmemory-policy volatile-lru (allkeys-* apagaria sessão)
php bin/redis-cut.php            # ensaio, não altera nada
php bin/redis-cut.php --apply    # corte
```

- [x] `/avancado.php#escala` mostra `driver efetivo = redis`, `degradado = não`.
- [ ] 24 h sem `database is locked` no log de runtime.
- [ ] Rollback testado uma vez: `php bin/redis-cut.php --rollback`.

## Bloco 3 — todos os usuários saindo por LB

- [x] `lb_default_mode = auto` em `/avancado.php#escala`.
- [ ] Job `lb_autoroute` executado e sem usuário ativo sem rota.
- [ ] `/restream.php` mostrando tráfego com `served_by = lb`.
- [x] `lb_require_delivery = 1` (o painel recusa ligar sem LB saudável).

## Bloco 4 — motor Go no músculo (canário)

```bash
bash bin/lb-go-build.sh
bash bin/lb-go-deploy.sh <ip-do-LB-02> <token> https://painel.exemplo.com 8081
# no nó: upstream do Nginx aponta para 127.0.0.1:8081
curl -s http://127.0.0.1:8081/healthz
```

- [x] `lb-go -check` valida o snapshot antes de ativar (o script já faz isso).
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

- [ ] Paridade de contagem tabela por tabela (o `pg-cut` ainda está rodando no
      volume histórico grande).
- [ ] Painel e jobs lendo do Postgres sem regressão por 24 h.

### Comandos exatos de observação desta fase

```bash
ps -fp $(pgrep -f 'php .*bin/pg-cut.php')
sudo -u postgres psql -d proxy_mago -Atqc "SELECT count(*) FROM cdn_divergences;"
sqlite3 storage/app.sqlite "SELECT count(*) FROM cdn_divergences;"
```

## Bloco 6 — chamar de "produção 100%"

- [ ] 48 h com Redis (estado vivo) sem degradação.
- [ ] 48 h com 100% do tráfego saindo por LB, `lb_require_delivery=1`.
- [ ] 6 a 8 LBs com heartbeat confiável, score/rebalance estável e baseline de
      capacidade real por nó (`docs/BASELINE_CARGA_LB.md`).
- [ ] Zero `database is locked`, zero usuário no main, zero regressão em player.

## O que ficou fora do que eu consigo fazer daqui

O código dos 6 blocos está no repositório. O que resta daqui para frente é:

- esperar o `pg-cut` terminar e fechar a paridade;
- observar `24h/48h` com os serviços no ar;
- só então chamar de `produção 100%`.

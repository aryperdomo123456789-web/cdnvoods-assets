# Cérebro puro + corte do estado vivo para Redis

Estado desta entrega: pronto para produção (Ubuntu 22.04, PHP 8.1.2, Nginx,
SQLite hoje / Redis no corte). Tudo é opt-in: instalar esta versão **não muda
nada** no comportamento atual até você ligar as chaves.

## 1. Correção obrigatória de PHP 8.1

`app/StateStore.php` declarava `private static function demote(Throwable $e): null`.
O tipo autônomo `null` só existe no PHP 8.2 — no PHP 8.1.2 da VPS isso é
**parse error fatal**, e como o arquivo entra no `proxy-bootstrap.php`, derrubava
todo request do proxy. Agora o retorno é `mixed`. O smoke `bin/smoke-lb-only.php`
cobra essa regra por arquivo (`) : null|true|false` proibido), então a regressão
não volta sem falhar a bateria.

## 2. Cérebro puro (`lb_require_delivery`)

O main é registro + controle; músculo entrega byte.

- Chave: `lb_require_delivery` (settings, default `0`).
- Com `1`, request cujo roteamento **não** terminou em LB recebe `503`, com
  sessão marcada `lb_required_no_muscle` e linha na auditoria.
- Trava de segurança: o painel **recusa** ligar a chave quando não existe LB
  `installed` + `healthy`. Sem isso, um clique derrubaria 100% dos players.
- O contrato v1 exporta a flag em `runtime.lb_require_delivery`, então o músculo
  (PHP hoje, Go depois) sabe que não deve devolver o player para o main.

## 3. Todo mundo no músculo (`lb_default_mode` + `lb_autoroute`)

Antes, usuário sem linha em `lb_user_routes` ficava no cérebro para sempre e a
adoção do LB dependia de cadastro manual.

- Chave `lb_default_mode` (`main_only` default, `auto` para escala).
- `LbRouter::decide()` sem rota aplica o modo padrão. Em `auto`, o caminho quente
  lê o melhor músculo **já publicado** no estado vivo (`cdnv:lb:best`, TTL 180s)
  pelo job `lb_rebalance`: zero score, zero telemetria, zero SSH no stream.
- Job novo `lb_autoroute` (perfil heavy, 120s) materializa rota para todo usuário
  ativo do `xui_users_cache` sem rota, em lote de até 5000, idempotente
  (`Sql::insertIgnore`, portável SQLite/Postgres).

## 4. Corte do estado vivo para Redis

```bash
php bin/redis-cut.php              # ensaio: não altera settings
php bin/redis-cut.php --apply      # ensaio + corte
php bin/redis-cut.php --rollback   # volta para sqlite na hora
```

O ensaio valida PING, SETEX/TTL, pipeline, `maxmemory-policy` (recusa
`allkeys-*`, que apagaria sessão sob pressão de memória) e **paridade real de
contador** entre `sqlite` e `redis` via `StateStore`. Qualquer falha aborta o
corte e deixa o estado vivo em SQLite. Depois do corte, se o Redis cair, o
`StateStore` degrada sozinho para SQLite — player não cai.

## 5. Painel

`/avancado.php#escala`: driver do estado vivo, coordenadas do Redis, modo padrão
de rota e cérebro puro — com o diagnóstico ao vivo (driver efetivo, degradação,
LBs instalados/saudáveis). Salva por `/save-scale.php`, com auditoria
`scale_settings_update` guardando valor anterior e novo.

## 6. Ordem de execução em produção

1. `bash bin/deploy.sh` (git pull + permissões + `php -l` + reload).
2. `bash bin/smoke-all.sh` — precisa fechar `0 falhas / 0 locks`.
3. Instalar/validar o LB até ficar `installed` + `healthy` em `/lb.php`.
4. `php bin/redis-cut.php` (ensaio) e depois `--apply`.
5. `/avancado.php#escala`: `lb_default_mode = auto`, rodar `lb_autoroute`.
6. Acompanhar `/restream.php` até ver tráfego saindo pelo músculo.
7. Só então ligar **cérebro puro**. Rollback é desligar a caixa (efeito imediato,
   nenhum restart).
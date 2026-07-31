# Restreamento em Tempo Real — Observabilidade Máxima

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Ambiente alvo (não é ambiente de teste da Lovable):

- VPS: `45.140.192.237`
- OS: `Ubuntu 22.04`
- Path: `/opt/proxy-mago/proxy-mago-base`
- Usuário do serviço: `www-data`

## O que foi entregue

### 1. Rastreabilidade total do request público

Todo request que passa por `public/proxy.php` gera um `request_id` e grava
uma linha em `proxy_request_events` com: hora, IP do cliente, domínio público
usado, rota, tipo de consumo (`m3u`, `api`, `live`, `movie`, `series`, `hls`,
`segment`), username, fingerprint da credencial, status, bytes, duração,
User-Agent, método e query **mascarada**.

A senha do assinante **nunca** é gravada em claro. O que se grava é
`credential_fingerprint = sha256(username:password)`, suficiente para provar
consistência sem criar um vazamento novo.

### 2. Anti-embaralhamento de usuário (CredentialGuard)

Durante o rewrite linha a linha, o `CredentialGuard` compara o par de
credenciais que aparece nas URLs reescritas com o par que entrou. Se a origem
devolver conteúdo de outro assinante:

- a entrega é abortada no meio do stream
- o cliente recebe `502`
- o evento é marcado como `invalid_credentials_swap`
- um registro crítico entra em `audit_logs`

### 3. Espelho read-only do XUI

Jobs em background copiam para o SQLite local:

- `xui_users_cache` — username, `max_connections`, validade, flags
- `xui_streams_cache` — nome/tipo/container do stream
- `xui_activity_now_cache` — sessões ativas agora

Regras invioláveis:

- somente `SELECT`, nunca escrita no XUI
- nunca no caminho quente de `get.php`, `player_api.php`, `.m3u8` ou `.ts`
- timeout curto e pool mínimo
- se o MySQL do XUI cair, o stream continua e o painel mostra o último snapshot

### 4. Painel ao vivo

- `/restream.php` — visão ao vivo com polling de 4s, KPIs, filtros por usuário,
  domínio, IP, player, tipo de consumo, status e "estourando limite", mais a
  trilha de auditoria de requests.
- `/restream-user.php?username=...` — detalhe do assinante: dados do XUI,
  sessões ativas, domínios/IPs/players usados, divergências e últimos requests.
- `/jobs.php` — catálogo de todas as rotinas internas: para que serve cada uma,
  intervalo, último run, duração, processados, falhas consecutivas e erro.
  Nenhuma rotina roda escondida.

### 5. Jobs auditáveis

| Job | Para que serve | Intervalo |
| --- | --- | --- |
| `xui_sync_activity` | espelha `user_activity_now` | 5s |
| `xui_sync_users` | espelha `users` e limites | 60s |
| `xui_sync_streams` | espelha `streams` | 300s |
| `match_sessions` | cruza request do proxy com sessão do XUI | 10s |
| `consolidate_runtime` | monta `proxy_user_runtime` do painel | 10s |
| `detect_inconsistency` | swap, acima do limite, órfãos | 30s |
| `cleanup` | retenção de eventos, rate_limit e job_runs | 3600s |
| `repair_retry` | reprocessa matching pendente | 300s |

Cada execução grava em `job_runs` (`run_id`, gatilho, início, duração, status,
processados, falhas, erro) e atualiza `job_state`.

## Instalação nesta VPS

```bash
cd /opt/proxy-mago/proxy-mago-base
git pull origin main

# necessário só para o espelho do XUI; o proxy funciona sem isso
apt-get update && apt-get install -y php8.1-mysql
phpenmod pdo_mysql mysqli
systemctl reload php8.1-fpm

bash bin/deploy.sh
```

O `deploy.sh` já instala o cron em `/etc/cron.d/proxy-mago-jobs`:

```
* * * * * www-data /usr/bin/php /opt/proxy-mago/proxy-mago-base/bin/jobs-run.php >> /opt/proxy-mago/proxy-mago-base/storage/logs/jobs.log 2>&1
```

O tick roda ~55 segundos por execução e respeita o intervalo de cada job, então
cron de 1 minuto entrega granularidade real de 5 segundos nas sessões ativas.

## Configurar o acesso read-only ao XUI

No XUI, crie um usuário MySQL somente leitura:

```sql
CREATE USER 'cdn_ro'@'45.140.192.237' IDENTIFIED BY 'senha_forte_aqui';
GRANT SELECT ON xtream_iptvpro.users TO 'cdn_ro'@'45.140.192.237';
GRANT SELECT ON xtream_iptvpro.streams TO 'cdn_ro'@'45.140.192.237';
GRANT SELECT ON xtream_iptvpro.user_activity_now TO 'cdn_ro'@'45.140.192.237';
FLUSH PRIVILEGES;
```

Depois preencha host, porta, database, usuário e senha em `/restream.php` e
marque "sync ativo". A senha fica só no SQLite local do painel e nunca aparece
em log, em API JSON ou na tela.

## Comandos de operação

```bash
# testa a conexão read-only e a existência das tabelas
php bin/xui-sync.php --test

# sync manual completo
php bin/xui-sync.php --all

# lista o catálogo de jobs
php bin/jobs-run.php --list

# força um job específico
php bin/jobs-run.php --job=consolidate_runtime --force

# acompanha o tick
tail -f storage/logs/jobs.log
```

## Troubleshooting

| Sintoma | Causa provável | Ação |
| --- | --- | --- |
| Painel mostra "pdo_mysql AUSENTE" | extensão não instalada | `apt-get install -y php8.1-mysql && phpenmod pdo_mysql && systemctl reload php8.1-fpm` |
| `ping: falhou` | firewall do XUI ou grant errado | liberar `45.140.192.237` no MySQL do XUI e revisar o `GRANT SELECT` |
| Usuário aparece como `unknown_user` | espelho de `users` ainda vazio | `php bin/xui-sync.php --users` |
| Conexões sempre 0 | `xui_sync_activity` não roda | conferir `/jobs.php` e o cron `/etc/cron.d/proxy-mago-jobs` |
| Muitos eventos no SQLite | retenção alta | o job `cleanup` poda automaticamente; ajuste a retenção em `RequestLog` |
| Player recebeu `502` com `invalid_credentials_swap` | origem devolveu conteúdo de outro usuário | é proteção funcionando; investigar o XUI, não o proxy |
| XUI fora do ar | MySQL indisponível | stream continua normal; painel exibe último snapshot e `last_sync_status = error` |

## Critérios de aceite atendidos

- integração com XUI é estritamente read-only
- o stream público não depende do MySQL do XUI
- o painel mostra conexões ativas e o limite oficial por usuário
- o tipo de consumo é classificado por rota
- qualquer troca de credencial é detectada, bloqueada e registrada
- toda rotina interna é listada, auditada e executável manualmente pelo painel
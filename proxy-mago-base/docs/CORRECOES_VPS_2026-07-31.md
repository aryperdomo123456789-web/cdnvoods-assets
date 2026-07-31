# Correções Aplicadas na VPS — 2026-07-31

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Data de referência: sexta-feira, 31/07/2026

## Escopo obrigatório de ambiente

Este documento descreve correções aplicadas **diretamente na VPS real**:

- VPS: `45.140.192.237`
- OS: `Ubuntu 22.04`
- Path: `/opt/proxy-mago/proxy-mago-base`

Ele existe para que a Lovable e qualquer pessoa que puxar do GitHub entenda
exatamente o que precisou ser ajustado para o projeto funcionar neste ambiente
real, e não em um ambiente teórico.

## Situação encontrada

Depois de puxar os módulos de restreamento, CDN inteligente e direct source do
GitHub, o projeto estava com partes corretas no código, mas ainda sofria com:

- incompatibilidade com o schema real do XUI (`xui`, `lines`, `lines_live`)
- migrações SQLite não totalmente idempotentes
- colisões em índices de divergência
- concorrência entre execuções de `jobs-run.php`
- permissões insuficientes em `storage/cache`

## Correções feitas

### 1. Compatibilidade real com o XUI desta infraestrutura

O XUI real estudado aqui **não** usa apenas:

- `users`
- `user_activity_now`

Ele usa:

- `lines`
- `lines_live`
- `streams`

Correção aplicada:

- `app/XuiSyncService.php`
  - `syncUsers()` agora detecta e usa `lines` quando existir
  - `syncActivity()` agora detecta e usa `lines_live` quando existir
  - o código continua compatível com `users` e `user_activity_now` quando esse
    for o schema disponível

- `bin/xui-sync.php`
  - o `--test` agora valida:
    - `users|lines`
    - `streams`
    - `user_activity_now|lines_live`

### 2. Migrações SQLite mais robustas

Havia falhas de deploy por coluna duplicada em ambientes parcialmente migrados.

Correção aplicada:

- `app/Database.php`
  - adicionado helper `addColumnIfMissing()`
  - migrações de:
    - `proxy_user_runtime`
    - `proxy_request_events`
    - `xui_streams_cache`
    - `direct_source_hops`
    - `cdn_sessions`
    - `cdn_divergences`
  agora toleram schema parcialmente avançado sem derrubar a aplicação

### 3. Índices de divergência ajustados para produção

Havia conflito real com índice único em `cdn_divergences`.

Problema:

- o índice único antigo não se comportava bem com o novo conceito de `scope`
- além disso, o SQLite desta VPS já tinha estado parcial e histórico antigo

Correção aplicada:

- removido o uso efetivo de índice único rígido em `cdn_divergences`
- mantido índice de busca por:
  - `username`
  - `kind`
  - `scope`
  - `status`

Resultado:

- o runtime deixa de travar por `UNIQUE constraint failed`
- a deduplicação passa a ser controlada pelo código

### 4. Escrita de divergência reforçada

Mesmo depois das migrações, a lógica antiga de `upsert` ainda era frágil para o
estado real do SQLite local.

Correção aplicada:

- `app/Divergence.php`
  - `raise()` foi reescrito para operar de forma canônica:
    - procura divergência aberta existente
    - remove linhas abertas antigas da mesma chave lógica
    - reinsere uma única linha consolidada
  - isso reduz colisão e estabiliza o histórico

### 5. Concorrência dos jobs internos

Foi identificado que o cron podia deixar mais de uma instância de
`bin/jobs-run.php` ativa ao mesmo tempo, causando:

- `database is locked`
- estados inconsistentes
- erro intermitente em jobs

Correção aplicada:

- `bin/jobs-run.php`
  - passou a usar lock file:
    - `storage/cache/jobs-run.lock`
  - usa `flock(LOCK_EX | LOCK_NB)` para impedir concorrência entre ticks
    simultâneos

### 6. Permissões do storage

O lock file ainda falhava porque `storage/cache` não estava com permissão
adequada para o processo `www-data`.

Correção aplicada:

```bash
mkdir -p /opt/proxy-mago/proxy-mago-base/storage/cache
chown -R www-data:www-data /opt/proxy-mago/proxy-mago-base/storage
chmod -R 775 /opt/proxy-mago/proxy-mago-base/storage
```

Resultado:

- `jobs-run.php` passou a rodar com lock corretamente quando executado como
  `www-data`

### 7. Dependência MySQL do PHP

O sync do XUI não funcionaria neste host sem `pdo_mysql`.

Correção aplicada:

```bash
apt-get update
apt-get install -y php8.1-mysql
phpenmod pdo_mysql mysqli
systemctl reload php8.1-fpm
```

## Estado validado após correções

Em 31/07/2026, o estado observado nesta VPS foi:

- `nginx`: `active`
- `php8.1-fpm`: `active`
- `cron`: `active`
- `pdo_mysql`: carregado
- `mysqli`: carregado
- `pdo_sqlite`: carregado
- `curl`: carregado

Jobs validados com sucesso:

- `xui_sync_users`
- `xui_sync_streams`
- `xui_sync_activity`
- `direct_enrich`
- `direct_consolidate`
- `detect_inconsistency`
- `metrics_rollup`
- `consolidate_runtime`
- `match_sessions`
- `session_sweep`

Estado observado no cache local:

- `xui_users_cache`: `15`
- `xui_streams_cache`: `483869`
- `xui_activity_now_cache`: `0` no instante do teste
- `direct_stream_state`: `483869`
- `direct_host_rollup`: `3`

Importante:

- `xui_activity_now_cache = 0` no momento testado **não** significa falha
- significa apenas que `lines_live` não retornava sessões ativas naquele exato
  momento

## O que ficou comprovado

- a VPS consegue falar com o banco `xui`
- o código local já está compatível com o schema real (`lines`, `lines_live`)
- o catálogo de streams e direct source já está sendo espelhado
- os jobs internos já conseguem rodar sem cair no problema antigo de migração
- o locking do `jobs-run.php` impede corrida básica entre execuções

## O que ainda é esperado operacionalmente

Para ver o painel vivo de conexão:

- é necessário haver consumo real de usuário no domínio público
- isso alimenta:
  - `proxy_request_events`
  - `cdn_sessions`
  - `direct_source_hops`
  - `proxy_user_runtime`

Ou seja:

- o painel já está preparado
- o que faltará para “encher” os dados ao vivo é tráfego real

## Arquivos corrigidos localmente

- `app/Database.php`
- `app/XuiSyncService.php`
- `app/Divergence.php`
- `bin/xui-sync.php`
- `bin/jobs-run.php`

## Resumo executivo

O problema já não era mais de arquitetura. Era de compatibilidade e robustez
operacional nesta VPS real.

As correções acima fecharam:

- compatibilidade com o XUI real
- tolerância a schema parcial
- lock do SQLite por corrida de jobs
- permissão do lock file
- inicialização estável dos módulos novos

Este documento deve ser enviado ao GitHub para que a Lovable passe a trabalhar
com o retrato fiel do ambiente real.

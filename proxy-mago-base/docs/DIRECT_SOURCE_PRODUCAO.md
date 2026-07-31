# DIRECT SOURCE — fechamento de produção

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


**VPS:** `45.140.192.237` · **SO:** Ubuntu 22.04 · **Path:** `/opt/proxy-mago/proxy-mago-base`
**Data de referência:** sexta-feira, 31/07/2026
**XUI de origem:** banco `xui` em `38.190.176.170` (leitura, complementar)

---

## 1. Como ESTE XUI trata direct source

Este XUI não usa o schema clássico puro. O que existe de fato:

| Tabela        | Papel real                                                        |
|---------------|-------------------------------------------------------------------|
| `lines`       | assinantes                                                         |
| `lines_live`  | sessões/atividade ao vivo                                          |
| `streams`     | catálogo, com `direct_source`, `direct_proxy` e `stream_source`    |

Consequências operacionais:

1. Boa parte do conteúdo já nasce direct source: `streams.direct_source = 1`
   e a URL externa vem pronta em `streams.stream_source`.
2. Nesses casos **não existe redirect em runtime** — o consumo vai direto para
   o host externo, e o XUI simplesmente some da contagem.
3. Existe também o caso clássico: o XUI devolve `302` para uma CDN de terceiros
   (ex.: `readyondemand.click`) em tempo de execução.

Ou seja: direct source tem **duas verdades**, e ignorar qualquer uma delas
deixa a contagem e a auditoria erradas.

`stream_source` aparece em formatos diferentes no mesmo banco:

- JSON array: `["http://cdn/live.ts","http://bkp/live.ts"]`
- JSON objeto: `{"0":"http:\/\/cdn\/live.ts"}`
- string simples: `http://cdn/live.ts`
- várias URLs por vírgula/quebra de linha
- lixo/legado: vazio, `0`, `null`, caminho local

---

## 2. Como a CDN passa a tratar

O painel da CDN é a **fonte principal de verdade operacional**. O MySQL do XUI
é complementar e nunca é consultado no caminho crítico do stream.

### Fonte DB (job, a cada 5 min)

`xui_sync_streams` espelha `streams` incluindo `direct_source`, `direct_proxy` e
`stream_source` (já mascarado: nenhum `user:pass` de origem é gravado em claro).
`direct_enrich` roda `DirectSourceParser` e grava em `xui_streams_cache`:

| Coluna                 | Significado                                          |
|------------------------|------------------------------------------------------|
| `direct_source`        | flag crua do XUI                                     |
| `direct_proxy`         | flag crua do XUI                                     |
| `stream_source_raw`    | valor original mascarado                             |
| `direct_host_detected` | host final extraído do `stream_source`               |
| `direct_hosts_json`    | todos os hosts encontrados (multi-URL)               |
| `urls_count`           | quantas URLs válidas                                 |
| `source_mode`          | `db_source` \| `db_flag` \| `local`                  |
| `parse_status`         | `ok` \| `empty` \| `no_host` \| `bad_json` \| `unsupported` |
| `parse_error`          | motivo legível quando o parse falha                  |

### Fonte RUNTIME (caminho do stream, best-effort)

`StreamProxy` registra cada hop seguido ou bloqueado. `DirectSource::persist()`
grava em `direct_source_hops` com contexto completo: `stream_id`, `public_host`,
`client_ip`, `player` (user agent), `route_kind`, `final_host`, `direct_mode`,
`host_from_db`, `error`. O cliente nunca vê nada disso.

A mesma chamada marca a sessão local (`cdn_sessions`): `direct_source`,
`direct_mode`, `direct_host_db`, `direct_host_runtime`, `direct_host_effective`,
`direct_first_epoch`, `direct_last_epoch`, `direct_failures`, `direct_blocked`.

### Consolidação (job `direct_consolidate`, a cada 30s)

Escreve `direct_stream_state`, a verdade efetiva por stream:

| Campo                   | Regra                                                        |
|-------------------------|--------------------------------------------------------------|
| `direct_host_from_db`   | host extraído do `stream_source`                              |
| `direct_host_runtime`   | último host realmente consumido (janela de 1h)                |
| `direct_host_effective` | **runtime manda**; sem runtime, cai para o host do DB         |
| `direct_origin_mode`    | `db_only` \| `runtime_only` \| `db_runtime` \| `none`         |
| `direct_consistency`    | `consistent` \| `mismatch` \| `host_missing` \| `parse_error` \| `runtime_only` |

Também gera `direct_host_rollup` (buckets de 5 min por host, separando fonte
`runtime` de fonte `db`), faz backfill de sessões ativas de streams direct
cadastrados no DB (consumo que nunca gera redirect) e retém 7 dias de rollup.

---

## 3. Interpretando divergência

| Divergência                      | O que significa                                          | Ação sugerida |
|----------------------------------|----------------------------------------------------------|---------------|
| `direct_db_runtime_mismatch`     | DB aponta um host, o cliente consumiu outro               | origem mudou o CDN, ou o espelho está velho: rode `xui_sync_streams` e compare de novo |
| `direct_host_missing`            | `direct_source = 1` sem host em nenhuma fonte             | conteúdo direct quebrado no XUI, ou `stream_source` vazio |
| `direct_parse_error`             | `stream_source` em formato não suportado                  | veja `parse_error` em `xui_streams_cache` e ajuste o cadastro no XUI |
| `direct_orphan_session`          | sessão direct ativa sem stream no catálogo                | sync de streams atrasado, ou rota sem `stream_id` |
| `direct_runtime_without_db_flag` | redirect externo sem `direct_source = 1`                  | informativo: origem começou a redirecionar sem avisar o catálogo |
| `direct_db_flag_without_runtime` | direct cadastrado sem consumo na última hora              | informativo: catálogo maior que a demanda real |

Severidade: `mismatch`, `host_missing` e `parse_error` entram como **warn**;
os dois últimos como **info**. Divergências de direct usam escopo por stream
(`scope = stream:<id>`), então não competem com as divergências por usuário.

---

## 4. Validando no painel

`https://<seu-dominio-de-painel>/restream.php` → seção **Direct source (DB do XUI + runtime da CDN)**:

- linha de resumo: sessões direct agora, streams direct no XUI, quantos têm
  host, parse com erro, `db_runtime` / `db_only` / `runtime_only`, mismatch
- **catálogo consolidado por stream** com filtros de modo, consistência e host
- **top hosts finais (15min)** separando fonte `runtime` de fonte `db`
- **falhas por host final (1h)**
- **usuários com direct source ativo agora** (link para a página do usuário)
- **divergências de direct source**
- **hops bloqueados/falhos (1h)** com modo e motivo

Na página do usuário (`restream-user.php`) a trilha interna mostra hop, host do
DB, host final, modo e resultado.

---

## 5. Validando via CLI nesta VPS

```bash
cd /opt/proxy-mago/proxy-mago-base

# 1. espelhar streams do XUI (inclui direct_source e stream_source)
sudo -u www-data php bin/jobs-run.php --job=xui_sync_streams --force

# 2. parsear stream_source e detectar host
sudo -u www-data php bin/jobs-run.php --job=direct_enrich --force

# 3. cruzar DB + runtime
sudo -u www-data php bin/jobs-run.php --job=direct_consolidate --force

# 4. conferir o catálogo consolidado
sudo -u www-data sqlite3 storage/app.sqlite \
  "SELECT stream_id, direct_origin_mode, direct_consistency,
          direct_host_from_db, direct_host_runtime, direct_host_effective
     FROM direct_stream_state ORDER BY runtime_last_epoch DESC LIMIT 20;"

# 5. parse com problema
sudo -u www-data sqlite3 storage/app.sqlite \
  "SELECT stream_id, parse_status, parse_error, substr(stream_source_raw,1,80)
     FROM xui_streams_cache WHERE direct_source = 1 AND parse_status NOT IN ('ok','empty') LIMIT 20;"

# 6. divergências de direct abertas
sudo -u www-data sqlite3 storage/app.sqlite \
  "SELECT kind, scope, probable_cause, occurrences FROM cdn_divergences
    WHERE status='open' AND kind LIKE 'direct_%';"
```

Smoke completo (usa domínio público real, nunca a origem):

```bash
bash bin/smoke-intelligence.sh voods.suafontee.com <usuario> <senha>
```

Cobre: direct vindo do DB, direct vindo de redirect, mismatch DB×runtime,
parsing múltiplo, falha no host final e sessões órfãs.

---

## 6. Jobs e intervalos

| Job                  | Intervalo | Papel |
|----------------------|-----------|-------|
| `xui_sync_streams`   | 300s      | espelha catálogo + flags de direct |
| `direct_enrich`      | 300s      | parse do `stream_source`, host e `parse_status` |
| `direct_consolidate` | 30s       | DB × runtime, host efetivo, divergências, rollup |
| `metrics_rollup`     | 30s       | KPIs de direct (`direct_streams_db`, `direct_db_runtime`, `direct_runtime_only`, `direct_mismatch`, `direct_parse_errors`) |

Cron já instalado pelo `bin/deploy.sh` (tick de 1 min com loop interno):

```
* * * * * www-data /usr/bin/php /opt/proxy-mago/proxy-mago-base/bin/jobs-run.php >> /opt/proxy-mago/proxy-mago-base/storage/logs/jobs.log 2>&1
```

Rodar um job pelo painel: **Jobs → executar** (`public/run-job.php`, com CSRF e login).

---

## 7. Troubleshooting

| Sintoma | Causa provável | Correção |
|---|---|---|
| catálogo direct vazio | `xui_sync_streams` nunca rodou ou sync desligado | ligue o sync e rode o job com `--force` |
| `pdo_mysql ausente` | extensão faltando | `apt-get install -y php8.1-mysql && phpenmod pdo_mysql && systemctl reload php8.1-fpm` |
| tudo em `db_only` | ninguém consumiu ainda, ou `stream_id` não é extraído da rota | confira `RequestContext::extractStreamId` para a rota usada |
| muitos `parse_error` | `stream_source` com formato novo | veja `parse_error`; o parser aceita JSON, string e listas |
| `mismatch` em massa | origem trocou de CDN | rode `xui_sync_streams` + `direct_enrich`; se persistir, o XUI está desatualizado |
| `direct_orphan_session` | sessão direct sem stream no catálogo | sync atrasado ou rota sem `stream_id` |
| stream pesado / lento | consulta ao MySQL no caminho quente | proibido: o hot path só faz `SELECT` por PK no SQLite local |

---

## 8. Checklist de produção

- [ ] `git pull` em `/opt/proxy-mago/proxy-mago-base` e `bash bin/deploy.sh`
- [ ] migração aplicada (`direct_stream_state` e `direct_host_rollup` existem)
- [ ] `xui_sync_streams` traz `direct_source` e `stream_source`
- [ ] `direct_enrich` com `parse_status = ok` na maioria dos direct
- [ ] `direct_consolidate` populando `direct_stream_state`
- [ ] painel mostrando host efetivo e divergências de direct
- [ ] `get.php`, `player_api.php`, `xmltv.php`, `.m3u8`, `.ts`, `/movie/`, `/series/`, `/live/` funcionando em players reais
- [ ] nenhuma origem do XUI (`38.190.176.170`, DNS do main) vazando no corpo — validado pelo passo 5 do smoke
- [ ] `storage/` gravável por `www-data`, cron ativo, `storage/logs/jobs.log` sem erro

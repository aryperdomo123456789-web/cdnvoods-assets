# S2-P0-5 — Ensaio de corte da trilha quente: SQLite -> PostgreSQL

Data: 2026-07-31. Estado: **ensaio validado em laboratório**. Corte real em produção: PENDENTE (janela agendada).

## Por que ensaiar antes

A trilha quente (sessão, request, runtime, jobs, auditoria, métricas) é UM arquivo SQLite
servindo tráfego ao vivo, 15 jobs e o polling do painel. O plano-mestre já decidiu Postgres
para esse dado. O que faltava era prova de que o corte atravessa **sem perder coluna e sem
perder linha**, e quanto tempo a janela custa.

## Ferramentas novas

| Arquivo | Função |
|---|---|
| `app/Config.php` | override por ambiente (`PROXY_MAGO_DB_DRIVER/HOST/PORT/NAME/USER/PASS/SSLMODE/PATH`) — ensaio sem editar config de produção |
| `bin/pg-migrate.php` | aplica a DDL quente no Postgres alvo usando o mesmo caminho do painel (`migratePgsqlHot`) e prova as 12 tabelas |
| `bin/pg-cut.php` | ensaio/execução do corte: `--dry-run`, `--fresh`, `--tables=a,b`, `--chunk=N`, `--allow-drift` |
| `bin/smoke-pg-cut.sh` | smoke serial: schema -> plano -> corte -> repetição idempotente. **Sem Postgres configurado, sai como `skip`** (não inventa prova) |

Rodando o ensaio:

```bash
export PROXY_MAGO_DB_DRIVER=pgsql PROXY_MAGO_DB_HOST=127.0.0.1 PROXY_MAGO_DB_PORT=5432 \
       PROXY_MAGO_DB_NAME=proxy_mago PROXY_MAGO_DB_USER=proxy_mago PROXY_MAGO_DB_PASS=... \
       PROXY_MAGO_DB_SSLMODE=disable
bash bin/smoke-pg-cut.sh
```

O painel **não muda de driver** por causa disso: as variáveis valem só no processo que as exporta.

## Achado real do ensaio (o motivo dele existir)

O primeiro ensaio acusou **24 colunas presentes no SQLite e ausentes no espelho pgsql**:

- `proxy_request_events`: `direct_host`, `hops`, `direct_mode`, `direct_host_db`, `lb_id`
- `direct_source_hops`: `stream_id`, `public_host`, `client_ip`, `player`, `route_kind`, `final_host`, `direct_mode`, `host_from_db`, `error`
- `job_runs`: `last_step`, `steps_done`, `lock_retries`, `host`
- `job_state`: `current_step`, `last_run_id`, `circuit_open_until`, `circuit_reason`, `skipped_runs`, `max_duration_ms`

Causa: no SQLite essas colunas nasceram por `ALTER TABLE` ao longo das fases; o caminho pgsql
só tinha o `CREATE TABLE` original. Um corte feito ontem teria perdido **exatamente a trilha de
rastreabilidade** (direct source, roteamento por LB, etapa de job). Corrigido em
`Database::migratePgsqlHot()` com defaults em aspas simples (no Postgres `DEFAULT ""` é
identificador, não string).

Regra nova: **drift de coluna é FALHA**, não aviso. `bin/pg-cut.php` aborta a tabela quando a
origem tem coluna que o destino não tem (`--allow-drift` só para investigação).

## Decisões técnicas

- **Paginação keyset** (`WHERE id > :last ORDER BY id`) em vez de `LIMIT/OFFSET`. OFFSET no
  SQLite relê as linhas puladas: em tabela de milhões de eventos o corte vira O(n²).
  Medido: 200k linhas em **4.6s com OFFSET** vs **2.4s com keyset** (~83k linhas/s).
- **Lote transacional** de N linhas (`--chunk`, padrão 500) com `ON CONFLICT DO NOTHING`:
  repetir o corte não duplica linha.
- **Sequência** de `id` ajustada com `setval` após a cópia (id explícito não avança BIGSERIAL;
  sem isso o primeiro INSERT do app em produção estouraria chave duplicada).
- Origem é aberta **somente para leitura lógica** (nenhum `ALTER`/`DELETE` no SQLite).

## Medição de janela

| Volume | Tempo | Throughput |
|---|---|---|
| 4 linhas (lab atual) | 75–90 ms | — |
| 200.000 linhas (`cdn_metrics` sintético) | 2.4 s | ~83k linhas/s |

Extrapolação: 1 milhão de linhas quentes ≈ **12–15 s** de cópia. A janela real de corte é isso
mais o stop/start do PHP-FPM e a troca de driver.

## Roteiro do corte real (a executar na VPS 45.140.192.237)

1. `bash bin/smoke-all.sh` verde no SQLite (baseline).
2. Provisionar Postgres, criar role/db, exportar `PROXY_MAGO_DB_*`.
3. `php bin/pg-migrate.php` -> 12/12 tabelas.
4. `php bin/pg-cut.php --dry-run` -> conferir volume e zero drift.
5. Janela: parar jobs (`jobs-run`), congelar escrita.
6. `php bin/pg-cut.php --fresh` -> paridade de linhas em todas as tabelas.
7. Gravar `db_driver => 'pgsql'` em `storage/local.config.php`, recarregar PHP-FPM.
8. `bash bin/smoke-all.sh` no novo backend. Rollback = remover o override do driver
   (o SQLite fica intacto e ainda é a fonte).

## Limitação conhecida

Nem toda tabela quente tem chave única declarada; `ON CONFLICT DO NOTHING` sem alvo depende
das constraints existentes. Por isso o corte oficial é `--fresh` (TRUNCATE + cópia), com
escrita congelada — cópia incremental a quente não é suportada e não deve ser tentada.

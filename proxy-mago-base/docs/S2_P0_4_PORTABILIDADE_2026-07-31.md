# S2-P0-4 — Abstração/portabilidade da trilha quente (SQLite -> PostgreSQL)

Data: 2026-07-31 · Estado: implementado e provado LOCALMENTE (VPS pendente de rerun)

## Por que isso vem antes da migração

Migrar banco com SQL dialetal é apostar a produção num sábado. O SQLite aceita
coisas que o PostgreSQL recusa, e o erro só aparece na hora do corte:

| dialeto SQLite | PostgreSQL | onde estava |
|---|---|---|
| `INSERT OR REPLACE` | erro de sintaxe | `XuiSyncService::syncActivity()` |
| `DEFAULT ""` | `""` é IDENTIFICADOR, não string | toda DDL do caminho SQLite |
| `AUTOINCREMENT` | não existe (`BIGSERIAL`) | idem |
| `PRAGMA ...` | não existe | `RestreamRuntime` (já protegido por `Database::isSqlite()`) |

## O que foi feito

1. `app/Sql.php` (novo) — tradutor único de dialeto:
   - `Sql::upsert($tabela, $colunas, $conflito, $update?)` gera
     `ON CONFLICT(...) DO UPDATE SET x = excluded.x`, válido nos DOIS backends;
   - `Sql::insertIgnore()` para "grava se não existir";
   - `Sql::HOT_TABLES` — lista oficial das 12 tabelas da trilha quente
     (sessão, request, runtime, links, métricas, divergência, timeline, limite,
     hops, jobs).
2. `app/XuiSyncService.php` — o último `INSERT OR REPLACE` do projeto virou
   `Sql::upsert()`. Mesmo efeito, zero dialeto.
3. `app/Database.php` — `migratePgsqlHot()` tinha só 4 tabelas; a migração
   morreria no primeiro rollup. Agora cobre as 12 de `Sql::HOT_TABLES`
   (`cdn_metrics`, `cdn_divergences`, `user_limit_state`, `direct_source_hops`,
   `cdn_audit_timeline`, `job_runs`, `job_state`, `job_step_history`), com
   `BIGSERIAL`, `DEFAULT ''` e os mesmos índices do caminho SQLite.
4. `app/bootstrap.php`, `app/proxy-bootstrap.php`, `app/bootstrap-cli.php` —
   `Sql` carregado em todos os caminhos (web, proxy quente e CLI).

## Prova

`bin/smoke-portability.sh` (12 ok / 0 falhas), dentro da bateria serial
`bin/smoke-all.sh` (0 falhas / 0 suites com lock). O smoke é REGRESSIVO: falha
se alguém reintroduzir dialeto SQLite em `app/`, se uma tabela quente ficar sem
DDL no caminho pgsql, ou se a DDL pgsql voltar a usar `DEFAULT ""`/`AUTOINCREMENT`.
Prova funcional inclusa: 3 upserts na mesma chave = 1 linha com o último valor.

## O que ainda NÃO está feito (não vender fechado)

- Nenhum dado foi migrado. `db_driver` continua `sqlite` em produção.
- Falta ensaio de corte real contra um PostgreSQL de verdade (dump + carga +
  comparação de contadores) — esse é o passo da Fase 3.
- Tabelas frias (origens, aliases, tokens, LB, caches XUI) seguem só em SQLite;
  a paridade cobre a TRILHA QUENTE, que é onde o lock dói.

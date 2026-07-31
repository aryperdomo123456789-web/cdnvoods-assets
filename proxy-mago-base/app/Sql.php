<?php

/**
 * S2-P0-4 — camada de portabilidade de SQL da trilha quente.
 *
 * Antes de migrar a trilha quente para PostgreSQL (Fase 3), todo SQL de
 * escrita precisa falar UMA língua só. O SQLite aceita dialeto próprio
 * (`INSERT OR REPLACE`, `DEFAULT ""`, `AUTOINCREMENT`) que o Postgres recusa,
 * e esse tipo de coisa só aparece no dia da migração — em produção, no pior
 * horário. Esta classe centraliza o SQL que precisa ser válido nos dois.
 *
 * Regra do projeto: escrita nova na trilha quente usa `Sql::upsert()`.
 * Nada de `INSERT OR REPLACE` / `INSERT OR IGNORE` solto em app/.
 */
final class Sql
{
    /**
     * Tabelas da TRILHA QUENTE (sessão, request, runtime, auditoria, jobs,
     * métricas). São exatamente as que precisam existir nos dois backends —
     * `bin/smoke-portability.php` cobra paridade com esta lista.
     */
    public const HOT_TABLES = [
        'proxy_request_events',
        'proxy_user_runtime',
        'proxy_session_links',
        'cdn_sessions',
        'cdn_metrics',
        'cdn_divergences',
        'cdn_audit_timeline',
        'user_limit_state',
        'direct_source_hops',
        'job_runs',
        'job_state',
        'job_step_history',
    ];

    /**
     * UPSERT portável (SQLite >= 3.24 e PostgreSQL >= 9.5 falam o mesmo
     * `ON CONFLICT ... DO UPDATE SET x = excluded.x`).
     *
     * @param list<string> $columns   colunas inseridas (o placeholder é :coluna)
     * @param list<string> $conflict  colunas da chave de conflito
     * @param list<string>|null $update colunas atualizadas (padrão: todas menos a chave)
     */
    public static function upsert(string $table, array $columns, array $conflict, ?array $update = null): string
    {
        $columns = array_values(array_unique($columns));
        if ($columns === [] || $conflict === []) {
            throw new InvalidArgumentException('Sql::upsert exige colunas e chave de conflito.');
        }
        $update ??= array_values(array_diff($columns, $conflict));

        $cols = implode(', ', $columns);
        $vals = implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns));
        $sql = 'INSERT INTO ' . $table . ' (' . $cols . ') VALUES (' . $vals . ')'
            . ' ON CONFLICT(' . implode(', ', $conflict) . ')';

        if ($update === []) {
            return $sql . ' DO NOTHING';
        }
        $set = implode(', ', array_map(
            static fn(string $c): string => $c . ' = excluded.' . $c,
            $update
        ));
        return $sql . ' DO UPDATE SET ' . $set;
    }

    /** INSERT que ignora duplicata, portável nos dois backends. */
    public static function insertIgnore(string $table, array $columns, array $conflict = []): string
    {
        return self::upsert($table, $columns, $conflict !== [] ? $conflict : [$columns[0]], []);
    }

    /** Concatenação portável (`||` funciona nos dois; `CONCAT()` não no SQLite antigo). */
    public static function concat(string ...$parts): string
    {
        return implode(' || ', $parts);
    }
}
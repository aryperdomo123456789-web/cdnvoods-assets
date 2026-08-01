<?php

/**
 * Decide por qual músculo cada usuário do XUI trafega.
 *
 * Modos:
 *   main_only  — continua 100% no cérebro (padrão, não muda nada de hoje)
 *   forced     — vai sempre para um LB específico
 *   auto       — o cérebro escolhe pelo score (headroom de banda/RAM/CPU)
 */
final class LbRouter
{
    public const MODES = ['main_only', 'forced', 'auto', 'disabled'];

    /** Chave do melhor músculo já mastigado pelo job (lida no caminho quente). */
    private const BEST_KEY = 'lb:best';

    /**
     * Cérebro puro: com 1, o main não entrega conteúdo do XUI. Se o usuário não
     * tem músculo apto, o request é recusado em vez de afundar o cérebro.
     */
    public static function requireDelivery(): bool
    {
        return (int) SettingsRepository::get(
            'lb_require_delivery',
            (string) (int) Config::get('lb_require_delivery', 0)
        ) === 1;
    }

    /** Modo aplicado a usuário que ainda não tem linha em lb_user_routes. */
    public static function defaultMode(): string
    {
        $mode = (string) SettingsRepository::get(
            'lb_default_mode',
            (string) Config::get('lb_default_mode', 'main_only')
        );
        return in_array($mode, self::MODES, true) ? $mode : 'main_only';
    }

    /**
     * Decisão do caminho QUENTE (chamada pelo proxy).
     *
     * Aqui NÃO existe score, NÃO existe telemetria e NÃO existe SSH: é 1 SELECT
     * indexado por username. Quem calcula score é o job `lb_rebalance`, que já
     * deixa `lb_id` mastigado em lb_user_routes. Assim o roteamento por usuário
     * não adiciona peso nenhum ao stream.
     *
     * @return array{target:string,lb_id:int,host:string,reason:string,mode:string}
     */
    public static function decide(string $username, string $trigger = 'proxy'): array
    {
        $main = ['target' => 'main', 'lb_id' => 0, 'host' => '', 'reason' => 'main_only', 'mode' => 'main_only'];
        if ($username === '') {
            return $main;
        }
        $st = Database::pdo()->prepare(
            'SELECT r.lb_id, r.mode, n.public_ip, n.enabled, n.drain_mode, n.install_status, n.health_status
               FROM lb_user_routes r LEFT JOIN lb_nodes n ON n.id = r.lb_id
              WHERE r.username = :u LIMIT 1'
        );
        $st->execute([':u' => $username]);
        $row = $st->fetch();
        if (!$row) {
            return self::defaultDecision();
        }
        $mode = (string) $row['mode'];
        $lbId = (int) $row['lb_id'];
        if ($mode === 'main_only' || $mode === 'disabled' || $lbId === 0) {
            $main['mode'] = $mode;
            $main['reason'] = $mode === 'disabled' ? 'route_disabled' : 'main_only';
            return $main;
        }
        $apto = (int) ($row['enabled'] ?? 0) === 1
            && (int) ($row['drain_mode'] ?? 0) === 0
            && (string) ($row['install_status'] ?? '') === 'installed'
            && (string) ($row['health_status'] ?? '') !== 'down';
        if (!$apto) {
            // Fallback silencioso para o cérebro: o usuário NUNCA fica sem rota.
            self::markFallback($username, $lbId, $mode, $trigger);
            return ['target' => 'main', 'lb_id' => 0, 'host' => '',
                    'reason' => $mode . '_indisponivel', 'mode' => $mode];
        }
        return ['target' => 'lb', 'lb_id' => $lbId, 'host' => (string) $row['public_ip'],
                'reason' => $mode === 'forced' ? 'forced' : 'auto_pinned', 'mode' => $mode];
    }

    /**
     * Usuário sem rota gravada. Em `auto` por padrão, o caminho quente NÃO
     * pontua nada: usa o melhor músculo já publicado pelo job `lb_rebalance`
     * no estado vivo (Redis/SQLite), que é uma leitura só por request textual.
     *
     * @return array{target:string,lb_id:int,host:string,reason:string,mode:string}
     */
    public static function defaultDecision(): array
    {
        $mode = self::defaultMode();
        $main = ['target' => 'main', 'lb_id' => 0, 'host' => '', 'reason' => 'sem_rota_' . $mode, 'mode' => $mode];
        if ($mode !== 'auto') {
            return $main;
        }

        $best = self::bestPinned();
        if ($best === null) {
            $main['reason'] = 'sem_lb_apto_cerebro';
            return $main;
        }
        return ['target' => 'lb', 'lb_id' => (int) $best['id'], 'host' => (string) $best['host'],
                'reason' => 'auto_default', 'mode' => 'auto'];
    }

    /** Melhor músculo publicado pelo job. @return array{id:int,host:string}|null */
    public static function bestPinned(): ?array
    {
        $best = StateStore::kvGet(self::BEST_KEY);
        if (!is_array($best) || (int) ($best['id'] ?? 0) <= 0 || (string) ($best['host'] ?? '') === '') {
            return null;
        }
        return ['id' => (int) $best['id'], 'host' => (string) $best['host']];
    }

    /** Publica (ou apaga) o melhor músculo para o caminho quente. */
    public static function publishBest(?array $node, float $score = 0.0): void
    {
        if ($node === null || (string) ($node['public_ip'] ?? '') === '') {
            StateStore::kvDel(self::BEST_KEY);
            return;
        }
        StateStore::kvSet(self::BEST_KEY, [
            'id' => (int) $node['id'],
            'host' => (string) $node['public_ip'],
            'score' => round($score, 1),
            'ts' => time(),
        ], 180);
    }

    /** Registra que a rota do usuário caiu no cérebro (1 UPDATE, com retry). */
    private static function markFallback(string $username, int $lbId, string $mode, string $trigger): void
    {
        Database::run(
            'UPDATE lb_user_routes SET fallback_used = fallback_used + 1, last_lb_id = :l, updated_at = :u
              WHERE username = :us',
            [':l' => $lbId, ':u' => date('c'), ':us' => $username],
            'lb_route.fallback'
        );
        self::history($username, $lbId, 0, $mode, 'fallback_para_cerebro', 0.0, $trigger);
    }

    /** Trilha de toda troca de músculo por usuário. */
    public static function history(
        string $username,
        int $fromLb,
        int $toLb,
        string $mode,
        string $reason,
        float $score,
        string $trigger
    ): void {
        Database::run(
            'INSERT INTO lb_route_history (username, from_lb_id, to_lb_id, mode, reason, score, trigger_source, ts_epoch, created_at)
             VALUES (:u,:f,:t,:m,:r,:s,:tr,:ts,:c)',
            [
                ':u' => $username, ':f' => $fromLb, ':t' => $toLb, ':m' => $mode,
                ':r' => substr($reason, 0, 200), ':s' => $score, ':tr' => $trigger,
                ':ts' => time(), ':c' => date('c'),
            ],
            'lb_route.history'
        );
    }

    /** @return array<int,array<string,mixed>> histórico recente de decisões */
    public static function historyRows(string $username = '', int $limit = 100): array
    {
        $sql = 'SELECT h.*, n.label AS to_label FROM lb_route_history h
                  LEFT JOIN lb_nodes n ON n.id = h.to_lb_id';
        $params = [];
        if ($username !== '') { $sql .= ' WHERE h.username = :u'; $params[':u'] = $username; }
        $sql .= ' ORDER BY h.id DESC LIMIT ' . max(1, min(500, $limit));
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    }

    public static function routes(int $limit = 500): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, n.label AS lb_label, n.public_ip AS lb_ip, n.health_status AS lb_health
             FROM lb_user_routes r LEFT JOIN lb_nodes n ON n.id = r.lb_id
             ORDER BY r.updated_at DESC LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function forUser(string $username): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM lb_user_routes WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return $stmt->fetch() ?: ['username' => $username, 'lb_id' => 0, 'mode' => 'main_only', 'reason' => 'default'];
    }

    public static function assign(string $username, string $mode, int $lbId = 0, string $reason = 'manual'): void
    {
        $username = trim($username);
        if ($username === '') {
            throw new InvalidArgumentException('Usuário vazio.');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Modo de roteamento inválido.');
        }
        if ($mode === 'forced') {
            $node = LbNode::find($lbId);
            if (!$node) {
                throw new InvalidArgumentException('LB de destino não existe.');
            }
            if ((int) $node['enabled'] !== 1) {
                throw new InvalidArgumentException('LB de destino está desativado.');
            }
        }
        if ($mode !== 'forced') {
            $lbId = 0;
        }

        $now = date('c');
        $before = self::forUser($username);
        Database::run(
            'INSERT INTO lb_user_routes (username, lb_id, mode, reason, created_at, updated_at, changed_epoch, changes)
             VALUES (:u,:l,:m,:r,:c,:up,:ce,1)
             ON CONFLICT(username) DO UPDATE SET lb_id=excluded.lb_id, mode=excluded.mode,
               reason=excluded.reason, updated_at=excluded.updated_at,
               last_lb_id=lb_user_routes.lb_id, changed_epoch=excluded.changed_epoch,
               changes=lb_user_routes.changes + 1',
            [':u' => $username, ':l' => $lbId, ':m' => $mode, ':r' => substr($reason, 0, 200),
             ':c' => $now, ':up' => $now, ':ce' => time()],
            'lb_route.assign'
        );
        self::history($username, (int) ($before['lb_id'] ?? 0), $lbId, $mode, $reason, 0.0, 'painel');

        Audit::log('lb_route', sprintf('%s -> mode=%s lb=%d (%s)', $username, $mode, $lbId, $reason));
    }

    public static function remove(string $username): void
    {
        Database::pdo()->prepare('DELETE FROM lb_user_routes WHERE username = :u')->execute([':u' => $username]);
    }

    /** Score do LB: quanto maior, mais folga tem para receber usuário novo. */
    public static function score(array $node): float
    {
        if ((int) $node['enabled'] !== 1 || (int) $node['drain_mode'] === 1) {
            return -1.0;
        }
        if ((string) $node['install_status'] !== 'installed') {
            return -1.0;
        }
        if ((int) $node['last_seen_epoch'] > 0 && time() - (int) $node['last_seen_epoch'] > 300) {
            return -1.0;
        }

        $m = LbTelemetry::latest((int) $node['id']);
        $declared = max(1, (int) $node['declared_bandwidth_mbps']);
        $tx = (float) ($m['tx_mbps'] ?? 0);
        $ramTotal = max(1, (int) $node['ram_mb']);
        $ramFree = (float) ($m['ram_free_mb'] ?? $ramTotal);
        $cpu = (float) ($m['cpu_pct'] ?? 0);
        $users = (int) ($m['users_active'] ?? 0);
        $errors = (int) ($m['errors_5m'] ?? 0);

        $headBw = max(0.0, 1 - ($tx / $declared));
        $headRam = max(0.0, $ramFree / $ramTotal);
        $headCpu = max(0.0, 1 - ($cpu / 100));

        $score = ((int) $node['weight'] * 1.0)
            + ($headBw * 500)
            + ($headRam * 200)
            + ($headCpu * 200)
            - ($users * 0.5)
            - ($errors * 10);

        $softUsers = (int) $node['max_users_soft'];
        $hardUsers = (int) $node['max_users_hard'];
        if ($hardUsers > 0 && $users >= $hardUsers) {
            return -1.0;
        }
        if ($softUsers > 0 && $users >= $softUsers) {
            $score -= 300;
        }

        $hardMbps = (int) $node['max_mbps_hard'];
        if ($hardMbps > 0 && $tx >= $hardMbps) {
            return -1.0;
        }

        return $score;
    }

    public static function bestNode(): ?array
    {
        $best = null;
        $bestScore = 0.0;
        foreach (LbNode::all() as $node) {
            $s = self::score($node);
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $node;
            }
        }
        return $best;
    }

    /**
     * Resolve o destino efetivo. Se não houver LB apto, cai no cérebro —
     * nunca deixa usuário sem rota (não quebra o fluxo atual).
     */
    public static function resolve(string $username): array
    {
        $route = self::forUser($username);
        $mode = (string) $route['mode'];

        if ($mode === 'forced') {
            $node = LbNode::find((int) $route['lb_id']);
            if ($node && (int) $node['enabled'] === 1 && (int) $node['drain_mode'] === 0) {
                return ['target' => 'lb', 'lb_id' => (int) $node['id'], 'host' => (string) $node['public_ip'], 'reason' => 'forced'];
            }
            return ['target' => 'main', 'lb_id' => 0, 'host' => '', 'reason' => 'forced_indisponivel'];
        }

        if ($mode === 'auto') {
            $node = self::bestNode();
            if ($node) {
                return ['target' => 'lb', 'lb_id' => (int) $node['id'], 'host' => (string) $node['public_ip'], 'reason' => 'auto_score'];
            }
        }

        return ['target' => 'main', 'lb_id' => 0, 'host' => '', 'reason' => 'main_only'];
    }

    /** Job: reavalia quem está em auto e registra a decisão. */
    public static function rebalance(array &$stats): void
    {
        JobRunner::step('carregar_rotas_auto');
        $rows = Database::pdo()->query(
            'SELECT username, lb_id FROM lb_user_routes WHERE mode = "auto"'
        )->fetchAll() ?: [];

        JobRunner::step('pontuar_nodes', count($rows) . ' usuário(s) em auto');
        // Um score por tick, não um por usuário: a telemetria é a mesma para
        // todos e ler N vezes por rodada era desperdício puro de SQLite.
        $best = self::bestNode();
        $bestId = $best ? (int) $best['id'] : 0;
        $bestScore = $best ? self::score($best) : 0.0;

        JobRunner::step('aplicar_decisoes');
        $moved = 0;
        foreach ($rows as $row) {
            $username = (string) $row['username'];
            $from = (int) $row['lb_id'];
            $reason = $bestId > 0 ? 'auto_score' : 'sem_lb_apto_cerebro';
            if ($from !== $bestId) {
                self::history($username, $from, $bestId, 'auto', $reason, $bestScore, 'job');
                $moved++;
            }
            Database::run(
                'UPDATE lb_user_routes SET lb_id = :l, last_lb_id = :old, reason = :r, score_snapshot = :s,
                    updated_at = :u, changed_epoch = CASE WHEN :l2 <> :old2 THEN :ce ELSE changed_epoch END,
                    changes = changes + CASE WHEN :l3 <> :old3 THEN 1 ELSE 0 END
                  WHERE username = :us',
                [':l' => $bestId, ':old' => $from, ':r' => $reason, ':s' => $bestScore, ':u' => date('c'),
                 ':l2' => $bestId, ':old2' => $from, ':ce' => time(),
                 ':l3' => $bestId, ':old3' => $from, ':us' => $username],
                'lb_route.rebalance'
            );
            $stats['processed']++;
        }
        $stats['details'] = ['best_lb_id' => $bestId, 'best_score' => round($bestScore, 1), 'moved' => $moved];
    }

    /** KPIs do bloco de balanceamento no painel. */
    public static function totals(): array
    {
        $pdo = Database::pdo();
        $nodes = LbNode::all();
        $installed = 0;
        $healthy = 0;
        $tx = 0.0;
        foreach ($nodes as $n) {
            if ((string) $n['install_status'] === 'installed') { $installed++; }
            if ((string) $n['health_status'] === 'ok') { $healthy++; }
            $m = LbTelemetry::latest((int) $n['id']);
            $tx += (float) ($m['tx_mbps'] ?? 0);
        }
        return [
            'nodes' => count($nodes),
            'installed' => $installed,
            'healthy' => $healthy,
            'tx_mbps' => round($tx, 1),
            'routes_forced' => (int) $pdo->query('SELECT COUNT(*) FROM lb_user_routes WHERE mode = "forced"')->fetchColumn(),
            'routes_auto' => (int) $pdo->query('SELECT COUNT(*) FROM lb_user_routes WHERE mode = "auto"')->fetchColumn(),
        ];
    }
}
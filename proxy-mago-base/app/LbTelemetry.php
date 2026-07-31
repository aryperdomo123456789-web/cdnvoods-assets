<?php

/**
 * Telemetria dos músculos.
 *
 * Duas entradas:
 *   - probe (cérebro puxa por SSH, barato, a cada ciclo do job lb_probe)
 *   - ingest (o LB empurra heartbeat via /lb-ingest.php com token de agente)
 */
final class LbTelemetry
{
    public static function probe(int $lbId): array
    {
        $node = LbNode::find($lbId);
        if (!$node) {
            throw new InvalidArgumentException('LB não encontrado.');
        }
        if (!LbSsh::available()) {
            return ['ok' => false, 'message' => LbSsh::missingHint()];
        }

        $cmd = 'IF=$(ip route get 1.1.1.1 2>/dev/null | awk \'{print $5; exit}\');'
             . ' R1=$(cat /sys/class/net/$IF/statistics/rx_bytes 2>/dev/null || echo 0);'
             . ' T1=$(cat /sys/class/net/$IF/statistics/tx_bytes 2>/dev/null || echo 0);'
             . ' C1=$(awk \'/^cpu /{print $2+$3+$4+$5+$6+$7+$8, $5}\' /proc/stat); sleep 1;'
             . ' R2=$(cat /sys/class/net/$IF/statistics/rx_bytes 2>/dev/null || echo 0);'
             . ' T2=$(cat /sys/class/net/$IF/statistics/tx_bytes 2>/dev/null || echo 0);'
             . ' C2=$(awk \'/^cpu /{print $2+$3+$4+$5+$6+$7+$8, $5}\' /proc/stat);'
             . ' echo "RX=$(( (R2-R1)*8/1000000 ))"; echo "TX=$(( (T2-T1)*8/1000000 ))";'
             . ' echo "CPU=$(echo $C1 $C2 | awk \'{t=$3-$1; i=$4-$2; if(t<=0){print 0}else{printf "%.1f", (1-i/t)*100}}\')";'
             . ' echo "RAMU=$(free -m | awk \'/Mem:/{print $3}\')"; echo "RAMF=$(free -m | awk \'/Mem:/{print $7}\')";'
             . ' echo "DISKF=$(df -BG --output=avail / | tail -n1 | tr -dc 0-9)";'
             . ' echo "CONNS=$(ss -Htn state established 2>/dev/null | wc -l)";'
             . ' echo "HEALTH=$(curl -s -o /dev/null -w %{http_code} http://127.0.0.1/__lb_health)"';

        $r = LbSsh::run($node, $cmd, 60);
        if (!$r['ok']) {
            LbNode::update($lbId, [
                'health_status' => 'error',
                'health_message' => substr($r['stderr'] ?: 'probe falhou', 0, 300),
                'last_probe_epoch' => time(),
            ]);
            return ['ok' => false, 'message' => $r['stderr'] ?: 'probe falhou'];
        }

        $m = ['RX' => 0, 'TX' => 0, 'CPU' => 0, 'RAMU' => 0, 'RAMF' => 0, 'DISKF' => 0, 'CONNS' => 0, 'HEALTH' => '000'];
        foreach (explode("\n", $r['stdout']) as $line) {
            [$k, $v] = array_pad(explode('=', trim($line), 2), 2, '');
            if (array_key_exists($k, $m)) {
                $m[$k] = $k === 'HEALTH' ? trim($v) : (float) $v;
            }
        }

        self::record($lbId, [
            'cpu_pct' => (float) $m['CPU'],
            'ram_used_mb' => (int) $m['RAMU'],
            'ram_free_mb' => (int) $m['RAMF'],
            'disk_free_gb' => (int) $m['DISKF'],
            'rx_mbps' => (float) $m['RX'],
            'tx_mbps' => (float) $m['TX'],
            'sessions_active' => (int) $m['CONNS'],
            'users_active' => 0,
            'errors_5m' => 0,
        ], 'probe');

        $healthy = $m['HEALTH'] === '200';
        LbNode::update($lbId, [
            'health_status' => $healthy ? 'ok' : 'degraded',
            'health_message' => 'health=' . $m['HEALTH'] . ' cpu=' . $m['CPU'] . '% tx=' . $m['TX'] . 'Mbps',
            'disk_free_gb' => (int) $m['DISKF'],
            'measured_bandwidth_mbps' => (int) max((float) $m['TX'], (float) ($node['measured_bandwidth_mbps'] ?? 0)),
            'last_probe_epoch' => time(),
            'last_seen_epoch' => time(),
        ]);

        return ['ok' => $healthy, 'metrics' => $m];
    }

    public static function record(int $lbId, array $m, string $source = 'probe'): void
    {
        Database::pdo()->prepare(
            'INSERT INTO lb_metrics (lb_id, ts_epoch, cpu_pct, ram_used_mb, ram_free_mb, disk_free_gb,
             rx_mbps, tx_mbps, sessions_active, users_active, errors_5m, source)
             VALUES (:l,:t,:c,:ru,:rf,:d,:rx,:tx,:s,:u,:e,:src)'
        )->execute([
            ':l' => $lbId, ':t' => time(),
            ':c' => (float) ($m['cpu_pct'] ?? 0),
            ':ru' => (int) ($m['ram_used_mb'] ?? 0),
            ':rf' => (int) ($m['ram_free_mb'] ?? 0),
            ':d' => (int) ($m['disk_free_gb'] ?? 0),
            ':rx' => (float) ($m['rx_mbps'] ?? 0),
            ':tx' => (float) ($m['tx_mbps'] ?? 0),
            ':s' => (int) ($m['sessions_active'] ?? 0),
            ':u' => (int) ($m['users_active'] ?? 0),
            ':e' => (int) ($m['errors_5m'] ?? 0),
            ':src' => $source,
        ]);
    }

    public static function latest(int $lbId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM lb_metrics WHERE lb_id = :l ORDER BY ts_epoch DESC LIMIT 1');
        $stmt->execute([':l' => $lbId]);
        return $stmt->fetch() ?: [];
    }

    /** Job: varre todos os LBs habilitados. */
    public static function probeAll(array &$stats): void
    {
        foreach (LbNode::all() as $node) {
            if ((int) $node['enabled'] !== 1 || (string) $node['install_status'] !== 'installed') {
                continue;
            }
            try {
                $r = self::probe((int) $node['id']);
                $stats['processed']++;
                if (empty($r['ok'])) {
                    $stats['failed']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['details'][] = 'lb#' . $node['id'] . ': ' . $e->getMessage();
            }
        }
    }

    public static function cleanup(array &$stats): void
    {
        $cut = time() - 86400 * 3;
        $stmt = Database::pdo()->prepare('DELETE FROM lb_metrics WHERE ts_epoch < :c');
        $stmt->execute([':c' => $cut]);
        $stats['processed'] += $stmt->rowCount();
    }
}
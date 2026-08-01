<?php

/**
 * Catálogo de conteúdo resolvido PELA CDN, antes de qualquer pergunta ao XUI.
 *
 * A telemetria precisa responder "o que esta conexão está vendo AGORA" sem
 * depender de `user_activity_now` (que não enxerga direct source) nem de
 * chamada ao painel original no caminho quente. A resposta sai do espelho
 * local: `xui_streams_cache` + `xui_series_cache` + `xui_episodes_cache`.
 *
 * Discriminador de tipo nesta base (confirmado no XUI real):
 *
 *   type = 1 -> live (canal)
 *   type = 2 -> movie (filme VOD)
 *   type = 5 -> series (episódio)  <- NÃO é 3 nesta base
 *
 * O `3` continua aceito como sinônimo defensivo porque bases antigas de
 * Xtream clássico usam esse valor para episódio.
 */
final class StreamCatalog
{
    public const TYPE_LIVE = 1;
    public const TYPE_MOVIE = 2;
    public const TYPE_SERIES = 5;

    /** Rótulo humano por tipo de conteúdo. */
    public const LABELS = [
        'live' => 'canal',
        'movie' => 'filme',
        'series' => 'série',
        'other' => 'desconhecido',
    ];

    /** Mapa numérico do XUI -> tipo de conteúdo da CDN. */
    public static function kindOfType(int $type): string
    {
        switch ($type) {
            case self::TYPE_LIVE:   return 'live';
            case self::TYPE_MOVIE:  return 'movie';
            case 3:                 // compat: Xtream clássico usa 3 em episódio
            case self::TYPE_SERIES: return 'series';
        }
        return 'other';
    }

    /** Estrutura vazia (stream fora do espelho local). */
    private static function unknown(int $streamId): array
    {
        return [
            'stream_id' => $streamId,
            'known' => 0,
            'type_raw' => '',
            'content_kind' => 'other',
            'content_kind_label' => self::LABELS['other'],
            'content_name' => $streamId > 0 ? ('stream #' . $streamId) : '-',
            'content_label' => $streamId > 0 ? ('stream #' . $streamId) : '-',
            'series_id' => 0,
            'series_title' => '',
            'season_num' => 0,
            'episode_num' => 0,
            'episode_ref' => '',
            'container' => '',
            'direct_source' => 0,
            'direct_proxy' => 0,
            'direct_host' => '',
            'delivery_mode' => 'unknown',
        ];
    }

    /**
     * Resolve vários streams de uma vez (o painel lista dezenas de conexões).
     *
     * @param int[] $streamIds
     * @return array<int,array<string,mixed>> indexado por stream_id
     */
    public static function resolveMany(array $streamIds): array
    {
        $ids = [];
        foreach ($streamIds as $id) {
            $id = (int) $id;
            if ($id > 0) { $ids[$id] = $id; }
        }
        if ($ids === []) { return []; }
        sort($ids);
        $key = 'catalog_' . md5(implode(',', $ids));

        return Cache::remember($key, 15, static function () use ($ids): array {
            $pdo = Database::pdo();
            $in = implode(',', array_map('intval', $ids));
            $hasEpisodes = Database::tableExists('xui_episodes_cache');
            $hasSeries = Database::tableExists('xui_series_cache');

            $episodeJoin = $hasEpisodes
                ? 'LEFT JOIN xui_episodes_cache e ON e.stream_id = s.stream_id'
                : '';
            $seriesJoin = ($hasEpisodes && $hasSeries)
                ? 'LEFT JOIN xui_series_cache se ON se.series_id = e.series_id'
                : '';
            $episodeCols = $hasEpisodes
                ? 'COALESCE(e.series_id, 0) AS series_id,
                   COALESCE(e.season_num, 0) AS season_num,
                   COALESCE(e.episode_num, 0) AS episode_num'
                : '0 AS series_id, 0 AS season_num, 0 AS episode_num';
            $seriesCols = ($hasEpisodes && $hasSeries)
                ? "COALESCE(se.title, '') AS series_title"
                : "'' AS series_title";

            $sql = 'SELECT s.stream_id, s.type, s.stream_display_name, s.target_container,
                           COALESCE(s.direct_source, 0) AS direct_source,
                           COALESCE(s.direct_proxy, 0) AS direct_proxy,
                           ' . (Database::tableHasColumn('xui_streams_cache', 'direct_host_detected')
                                ? "COALESCE(s.direct_host_detected, '')"
                                : "''") . ' AS direct_host,
                           ' . $episodeCols . ',
                           ' . $seriesCols . '
                      FROM xui_streams_cache s
                      ' . $episodeJoin . '
                      ' . $seriesJoin . '
                     WHERE s.stream_id IN (' . $in . ')';

            $rows = $pdo->query($sql)->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[(int) $r['stream_id']] = self::shape($r);
            }
            return $out;
        });
    }

    public static function resolve(int $streamId): array
    {
        if ($streamId <= 0) { return self::unknown($streamId); }
        $all = self::resolveMany([$streamId]);
        return $all[$streamId] ?? self::unknown($streamId);
    }

    /** @param array<string,mixed> $r linha crua do espelho */
    private static function shape(array $r): array
    {
        $streamId = (int) $r['stream_id'];
        $typeRaw = trim((string) ($r['type'] ?? ''));
        $kind = self::kindOfType((int) $typeRaw);
        $name = trim((string) ($r['stream_display_name'] ?? ''));
        $seriesTitle = trim((string) ($r['series_title'] ?? ''));
        $season = (int) ($r['season_num'] ?? 0);
        $episode = (int) ($r['episode_num'] ?? 0);

        // Episódio sem flag de série no catálogo mas com vínculo em
        // streams_episodes ainda é série: o vínculo manda.
        if ($kind === 'other' && (int) ($r['series_id'] ?? 0) > 0) { $kind = 'series'; }

        $ref = '';
        if ($kind === 'series' && ($season > 0 || $episode > 0)) {
            $ref = sprintf('S%02dE%02d', max(0, $season), max(0, $episode));
        }

        $label = $name !== '' ? $name : ('stream #' . $streamId);
        if ($kind === 'series') {
            $parts = [];
            if ($seriesTitle !== '') { $parts[] = $seriesTitle; }
            if ($ref !== '') { $parts[] = $ref; }
            if ($name !== '' && $name !== $seriesTitle) { $parts[] = $name; }
            if ($parts !== []) { $label = implode(' · ', $parts); }
        }

        $direct = (int) ($r['direct_source'] ?? 0) === 1;
        $proxy = (int) ($r['direct_proxy'] ?? 0) === 1;

        return [
            'stream_id' => $streamId,
            'known' => 1,
            'type_raw' => $typeRaw,
            'content_kind' => $kind,
            'content_kind_label' => self::LABELS[$kind] ?? self::LABELS['other'],
            'content_name' => $name !== '' ? $name : ('stream #' . $streamId),
            'content_label' => $label,
            'series_id' => (int) ($r['series_id'] ?? 0),
            'series_title' => $seriesTitle,
            'season_num' => $season,
            'episode_num' => $episode,
            'episode_ref' => $ref,
            'container' => trim((string) ($r['target_container'] ?? '')),
            'direct_source' => $direct ? 1 : 0,
            'direct_proxy' => $proxy ? 1 : 0,
            'direct_host' => trim((string) ($r['direct_host'] ?? '')),
            // Os 4 modos de entrega do XUI, decididos pelo trio de flags.
            // `on_demand` vive em streams_servers e ainda não é espelhado, por
            // isso não aparece aqui: só afirmamos o que temos no espelho.
            'delivery_mode' => $direct ? 'direct_source' : ($proxy ? 'direct_proxy' : 'restream'),
        ];
    }

    /**
     * Enriquece linhas de sessão com o conteúdo resolvido localmente.
     *
     * Quando o catálogo não conhece o stream, o tipo da ROTA (live/movie/series
     * na URL pública) continua valendo — a CDN nunca fica sem classificar.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function enrichSessions(array $rows, string $streamKey = 'stream_id'): array
    {
        if ($rows === []) { return $rows; }
        $ids = [];
        foreach ($rows as $r) { $ids[] = (int) ($r[$streamKey] ?? 0); }
        $catalog = self::resolveMany($ids);

        foreach ($rows as &$r) {
            $sid = (int) ($r[$streamKey] ?? 0);
            $info = $catalog[$sid] ?? self::unknown($sid);
            if ((int) $info['known'] !== 1) {
                $routeKind = strtolower(trim((string) ($r['session_kind'] ?? $r['last_route_kind'] ?? '')));
                if (in_array($routeKind, ['live', 'movie', 'series'], true)) {
                    $info['content_kind'] = $routeKind;
                    $info['content_kind_label'] = self::LABELS[$routeKind];
                    $info['content_source'] = 'route';
                } else {
                    $info['content_source'] = 'unknown';
                }
            } else {
                $info['content_source'] = 'catalog';
            }
            foreach ($info as $k => $v) {
                if ($k === 'stream_id') { continue; }
                $r[$k] = $v;
            }
        }
        unset($r);
        return $rows;
    }
}
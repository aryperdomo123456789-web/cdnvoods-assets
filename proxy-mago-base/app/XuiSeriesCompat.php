<?php

final class XuiSeriesCompat
{
    public static function shouldHandle(string $path, array $query): bool
    {
        return stripos($path, 'player_api.php') !== false
            && strtolower(trim((string) ($query['action'] ?? ''))) === 'get_series_info';
    }

    public static function normalizeBody(string $body): string
    {
        if ($body === '') {
            return $body;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $body;
        }
        $episodes = $data['episodes'] ?? null;
        $seasons = $data['seasons'] ?? null;
        if (!is_array($episodes)) {
            return $body;
        }
        if (is_array($seasons) && $seasons !== []) {
            return $body;
        }

        $info = is_array($data['info'] ?? null) ? $data['info'] : [];
        $built = self::buildSeasons($episodes, $info);
        if ($built === []) {
            return $body;
        }
        $data['seasons'] = $built;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) && $json !== '' ? $json : $body;
    }

    /**
     * @param array<string|int,mixed> $episodes
     * @param array<string,mixed> $info
     * @return array<int,array<string,mixed>>
     */
    private static function buildSeasons(array $episodes, array $info): array
    {
        $cover = (string) ($info['cover'] ?? '');
        $coverBig = (string) ($info['cover_big'] ?? ($info['cover'] ?? ''));
        $plot = (string) ($info['plot'] ?? '');
        $title = trim((string) ($info['name'] ?? ($info['title'] ?? 'Serie')));
        $airDate = (string) ($info['release_date'] ?? ($info['releaseDate'] ?? ''));
        $backdrop = $info['backdrop_path'] ?? [];
        $trailer = (string) ($info['youtube_trailer'] ?? '');

        $out = [];
        foreach ($episodes as $seasonKey => $rows) {
            if (!is_array($rows) || $rows === []) {
                continue;
            }
            $seasonNum = (int) (is_numeric((string) $seasonKey) ? $seasonKey : 0);
            $first = is_array($rows[0] ?? null) ? $rows[0] : [];
            $episodeInfo = is_array($first['info'] ?? null) ? $first['info'] : [];
            if ($seasonNum <= 0) {
                $seasonNum = (int) ($episodeInfo['season'] ?? 0);
            }
            if ($seasonNum <= 0) {
                continue;
            }
            $out[] = [
                'air_date' => (string) ($episodeInfo['releasedate'] ?? $airDate),
                'episode_count' => count($rows),
                'id' => $seasonNum,
                'name' => $title . ' - Temporada ' . $seasonNum,
                'overview' => $plot,
                'season_number' => $seasonNum,
                'cover' => $cover,
                'cover_big' => (string) ($episodeInfo['cover_big'] ?? $coverBig),
                'backdrop_path' => $backdrop,
                'youtube_trailer' => $trailer,
            ];
        }

        usort($out, static fn(array $a, array $b): int => (int) $a['season_number'] <=> (int) $b['season_number']);
        return $out;
    }
}

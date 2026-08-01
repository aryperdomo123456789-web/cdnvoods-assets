<?php

final class PlayerApiLocal
{
    public static function shouldHandle(string $path, array $query): bool
    {
        if (stripos($path, 'player_api.php') === false) {
            return false;
        }
        $action = strtolower(trim((string) ($query['action'] ?? '')));
        return in_array($action, ['get_vod_streams', 'get_series'], true);
    }

    /**
     * @return array{status:int,bytes:int}
     */
    public static function serve(array $query): array
    {
        $action = strtolower(trim((string) ($query['action'] ?? '')));
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $bytes = 0;
        $send = static function (string $chunk) use (&$bytes): void {
            echo $chunk;
            $bytes += strlen($chunk);
        };

        if ($action === 'get_vod_streams') {
            self::streamVod($query, $send);
        } elseif ($action === 'get_series') {
            self::streamSeries($query, $send);
        } else {
            $send('[]');
        }
        @ob_flush();
        @flush();
        return ['status' => 200, 'bytes' => $bytes];
    }

    private static function streamVod(array $query, callable $send): void
    {
        $category = trim((string) ($query['category_id'] ?? ''));
        $sql = 'SELECT stream_id, stream_display_name, category_id, target_container,
                       stream_icon, added_epoch, rating_text
                  FROM xui_streams_cache
                 WHERE type = :type';
        $params = [':type' => '2'];
        if ($category !== '' && $category !== '0') {
            $sql .= ' AND (category_id = :cat OR category_id = :cat_json)';
            $params[':cat'] = $category;
            $params[':cat_json'] = '[' . $category . ']';
        }
        $sql .= ' ORDER BY added_epoch DESC, stream_id DESC';

        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $send('[');
        $first = true;
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = [
                'num' => (int) $r['stream_id'],
                'name' => (string) $r['stream_display_name'],
                'title' => (string) $r['stream_display_name'],
                'stream_type' => 'movie',
                'stream_id' => (int) $r['stream_id'],
                'stream_icon' => (string) ($r['stream_icon'] ?? ''),
                'rating' => (string) ($r['rating_text'] ?? ''),
                'rating_5based' => self::rating5((string) ($r['rating_text'] ?? '')),
                'added' => (string) ((int) ($r['added_epoch'] ?? 0)),
                'category_id' => self::normalizeCategoryId((string) ($r['category_id'] ?? '')),
                'container_extension' => (string) ($r['target_container'] ?? 'mp4'),
                'custom_sid' => (string) $r['stream_id'],
            ];
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                continue;
            }
            if (!$first) {
                $send(',');
            }
            $send($json);
            $first = false;
        }
        $send(']');
    }

    private static function streamSeries(array $query, callable $send): void
    {
        $category = trim((string) ($query['category_id'] ?? ''));
        $sql = 'SELECT series_id, title, category_id, cover, cover_big, genre, plot, cast_text,
                       rating_text, director, release_date, last_modified_epoch, tmdb_id,
                       episode_run_time, backdrop_path, youtube_trailer
                  FROM xui_series_cache';
        $params = [];
        if ($category !== '' && $category !== '0') {
            $sql .= ' WHERE (category_id = :cat OR category_id = :cat_json)';
            $params[':cat'] = $category;
            $params[':cat_json'] = '[' . $category . ']';
        }
        $sql .= ' ORDER BY series_id ASC';

        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $send('[');
        $first = true;
        $num = 0;
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $num++;
            $row = [
                'num' => $num,
                'name' => (string) $r['title'],
                'title' => (string) $r['title'],
                'year' => null,
                'stream_type' => 'series',
                'series_id' => (int) $r['series_id'],
                'cover' => (string) (($r['cover_big'] ?? '') !== '' ? $r['cover_big'] : ($r['cover'] ?? '')),
                'plot' => (string) ($r['plot'] ?? ''),
                'cast' => (string) ($r['cast_text'] ?? ''),
                'director' => (string) ($r['director'] ?? ''),
                'genre' => (string) ($r['genre'] ?? ''),
                'release_date' => (string) ($r['release_date'] ?? ''),
                'releaseDate' => (string) ($r['release_date'] ?? ''),
                'last_modified' => (string) ((int) ($r['last_modified_epoch'] ?? 0)),
                'rating' => (string) ($r['rating_text'] ?? ''),
                'rating_5based' => self::rating5((string) ($r['rating_text'] ?? '')),
                'backdrop_path' => self::decodeArray((string) ($r['backdrop_path'] ?? '')),
                'youtube_trailer' => (string) ($r['youtube_trailer'] ?? ''),
                'episode_run_time' => (string) ($r['episode_run_time'] ?? ''),
                'category_id' => self::normalizeCategoryId((string) ($r['category_id'] ?? '')),
                'category_ids' => self::categoryIds((string) ($r['category_id'] ?? '')),
                'tmdb_id' => (string) ($r['tmdb_id'] ?? ''),
            ];
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                continue;
            }
            if (!$first) {
                $send(',');
            }
            $send($json);
            $first = false;
        }
        $send(']');
    }

    private static function normalizeCategoryId(string $raw): string
    {
        $ids = self::categoryIds($raw);
        return $ids !== [] ? (string) $ids[0] : trim($raw);
    }

    /** @return int[] */
    private static function categoryIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_map('intval', array_filter($decoded, static fn($v): bool => (int) $v > 0)));
            }
        }
        return ctype_digit($raw) ? [(int) $raw] : [];
    }

    /** @return array<int,string> */
    private static function decodeArray(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_scalar($item) && (string) $item !== '') {
                $out[] = (string) $item;
            }
        }
        return $out;
    }

    private static function rating5(string $raw): float
    {
        $n = (float) str_replace(',', '.', trim($raw));
        if ($n <= 0) {
            return 0.0;
        }
        return round(min(5.0, $n / 2.0), 1);
    }
}

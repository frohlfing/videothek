<?php

/**
 * Filmsuche in TMDB für den Import-Dialog (siehe api/lib/tmdb.php: tmdb_search()).
 *
 * GET /api/search.php?query=Die%20Goonies&year=1985   (year optional)
 * GET /api/search.php?query=tt0089218                 (IMDb-ID)
 *   -> {"results": [{"tmdb": 9340, "title": ..., "originalTitle": ..., "year": ..., "overview": ..., "poster": ...}],
 *       "existing": {"9340": "die-goonies-1985"}}
 *   "existing" ordnet Treffer, die schon in der Videothek sind, ihrem Slug zu (Schlüssel: TMDB-ID des Treffers),
 *   damit das Frontend sie markieren kann. Erkannt über die TMDB-ID oder – für Filme ohne TMDB-ID, z. B. von Hand
 *   angelegt – über Titel + Jahr, wie bei der Duplikatprüfung in create_movie() (crud.php: title_exists()).
 *   Kein Token nötig (rein lesend); Schutz vor öffentlichem Zugriff übernimmt die HTTP Basic Auth.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/tmdb.php';

$query = trim((string) ($_GET['query'] ?? ''));
if ($query === '') {
    json_error(400, 'query ist erforderlich');
}
$year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1880, 'max_range' => 2100]]);

try {
    $results = tmdb_search($query, $year === false ? null : $year);
} catch (RuntimeException $e) {
    json_error(502, $e->getMessage());
}

$byTmdb = [];
$byTitleYear = [];
$titleYearKey = fn(string $title, ?int $year) => mb_strtolower(trim($title)) . '|' . ($year ?? '');
foreach (list_movie_slugs() as $slug) {
    $movie = read_movie_json($slug);
    if ($movie === null) {
        continue;
    }
    $tmdb = $movie['uniqueids']['tmdb'] ?? null;
    if ($tmdb !== null) {
        $byTmdb[(string) $tmdb] = $slug;
    }
    $byTitleYear[$titleYearKey((string) $movie['title'], $movie['year'] ?? null)] = $slug;
}

$existing = [];
foreach ($results as $r) {
    $slug = $byTmdb[(string) $r['tmdb']] ?? $byTitleYear[$titleYearKey($r['title'], $r['year'])] ?? null;
    if ($slug !== null) {
        $existing[(string) $r['tmdb']] = $slug;
    }
}

json_response(200, ['results' => $results, 'existing' => (object) $existing]);

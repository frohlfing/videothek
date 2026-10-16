<?php

/**
 * Filmsuche in TMDB für den Import-Dialog (siehe api/lib/tmdb.php: tmdb_search()).
 *
 * GET /api/search.php?query=Die%20Goonies&year=1985   (year optional)
 * GET /api/search.php?query=tt0089218                 (IMDb-ID)
 *   -> {"results": [{"tmdb": 9340, "title": ..., "originalTitle": ..., "year": ..., "overview": ..., "poster": ...}],
 *       "existing": {"9340": "die-goonies-1985"}}
 *   "existing" ordnet bereits vorhandene TMDB-IDs ihrem Slug zu, damit das Frontend sie markieren kann.
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

$existing = [];
foreach (list_movie_slugs() as $slug) {
    $tmdb = read_movie_json($slug)['uniqueids']['tmdb'] ?? null;
    if ($tmdb !== null) {
        $existing[(string) $tmdb] = $slug;
    }
}

json_response(200, ['results' => $results, 'existing' => (object) $existing]);

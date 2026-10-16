<?php

/**
 * Suche nach Filmen und Serien in TMDB für den Import-Dialog (siehe api/lib/tmdb.php: tmdb_search()).
 *
 * GET /api/search.php?query=Die%20Goonies&year=1985   (year optional, bei Serien das Jahr der Erstausstrahlung)
 * GET /api/search.php?query=tt0089218                 (IMDb-ID)
 *   -> {"results": [{"type": "movie", "tmdb": 9340, "title": ..., "originalTitle": ..., "year": ...,
 *                    "overview": ..., "poster": ..., "existing": "die-goonies-1985"}, ...]}
 *   "type" ist "movie" oder "series". "existing" ist der Slug, falls der Treffer schon in der Videothek ist
 *   (sonst null), damit das Frontend ihn markieren kann. Erkannt über Art + TMDB-ID oder – für Einträge ohne
 *   TMDB-ID, z. B. von Hand angelegt – über Art + Titel + Jahr, wie bei der Duplikatprüfung in create_movie()
 *   (crud.php: title_exists()).
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
$titleYearKey = fn(string $type, string $title, ?int $year) => $type . '|' . mb_strtolower(trim($title)) . '|' . ($year ?? '');
foreach (list_movie_slugs() as $slug) {
    $movie = read_movie_json($slug);
    if ($movie === null) {
        continue;
    }
    $tmdb = $movie['uniqueids']['tmdb'] ?? null;
    if ($tmdb !== null) {
        $byTmdb[$movie['type'] . '|' . $tmdb] = $slug;
    }
    $byTitleYear[$titleYearKey($movie['type'], (string) $movie['title'], $movie['year'] ?? null)] = $slug;
}

foreach ($results as &$r) {
    $r['existing'] = $byTmdb[$r['type'] . '|' . $r['tmdb']] ?? $byTitleYear[$titleYearKey($r['type'], $r['title'], $r['year'])] ?? null;
}
unset($r);

json_response(200, ['results' => $results]);

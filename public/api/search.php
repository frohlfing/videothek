<?php

/**
 * Suche nach Filmen und Serien in TMDB für den Import-Dialog (siehe api/lib/tmdb.php: tmdb_search()).
 *
 * GET /api/search.php?query=Die%20Goonies&year=1985   (year optional, bei Serien das Jahr der Erstausstrahlung)
 * GET /api/search.php?query=tt0089218                 (IMDb-ID)
 * GET /api/search.php?query=4012909509665             (Barcode/EAN einer DVD, siehe api/lib/ean.php)
 *   -> {"results": [{"type": "movie", "tmdb": 9340, "title": ..., "originalTitle": ..., "year": ...,
 *                    "overview": ..., "poster": ..., "existing": "die-goonies-1985"}, ...]}
 *   "type" ist "movie" oder "series". "existing" ist der Slug, falls der Treffer schon in der Videothek ist
 *   (sonst null), damit das Frontend ihn markieren kann. Erkannt über TMDB-ID oder – für Filme ohne TMDB-ID, z. B.
 *   von Hand angelegt – über Titel + Jahr, wie bei der Duplikatprüfung in create_movie() (crud.php: title_exists()).
 *   Bei Serien ist "existing" immer null, dafür zählt "existingSeasons" die schon vorhandenen Staffeln (der Import
 *   ergänzt die fehlenden).
 *   Bei einer EAN enthält die Antwort zusätzlich "ean" und "query": den aus der Websuche gewonnenen Suchbegriff
 *   (z. B. "The Transporter", null wenn sich keiner ableiten ließ), den das Frontend ins Suchfeld übernimmt.
 *   Kein Token nötig (rein lesend); Schutz vor öffentlichem Zugriff übernimmt die HTTP Basic Auth.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/tmdb.php';
require_once __DIR__ . '/lib/ean.php';

$query = trim((string) ($_GET['query'] ?? ''));
if ($query === '') {
    json_error(400, 'query ist erforderlich');
}
$year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1880, 'max_range' => 2100]]);
$year = $year === false ? null : $year;

$extra = [];
try {
    if (is_valid_ean($query)) {
        if (!ean_search_configured()) {
            json_error(501, 'Für die Suche per Barcode ist keine Websuche eingerichtet '
                . '(EAN_SEARCH_PROVIDER und EAN_SEARCH_API_KEY in config.php, siehe README)');
        }
        $found = ean_tmdb_search(ean_web_titles($query), fn(string $q) => tmdb_search($q, $year));
        $results = $found['results'];
        $extra = ['ean' => $query, 'query' => $found['query']];
    } else {
        $results = tmdb_search($query, $year);
    }
} catch (RuntimeException $e) {
    json_error(502, $e->getMessage());
}

$byTmdb = [];
$byTitleYear = [];
$seasonCount = [];
$titleYearKey = fn(string $title, ?int $year) => mb_strtolower(trim($title)) . '|' . ($year ?? '');
foreach (list_movie_slugs() as $slug) {
    $movie = read_movie_json($slug);
    if ($movie === null) {
        continue;
    }
    if ($movie['type'] === TYPE_SEASON) {
        if (isset($movie['series']['tmdb'])) {
            $seasonCount[$movie['series']['tmdb']] = ($seasonCount[$movie['series']['tmdb']] ?? 0) + 1;
        }
        continue;
    }
    if (isset($movie['uniqueids']['tmdb'])) {
        $byTmdb[$movie['uniqueids']['tmdb']] = $slug;
    }
    $byTitleYear[$titleYearKey((string) $movie['title'], $movie['year'] ?? null)] = $slug;
}

foreach ($results as &$r) {
    if ($r['type'] === TYPE_SERIES) {
        $r['existing'] = null;
        $r['existingSeasons'] = $seasonCount[(string) $r['tmdb']] ?? 0;
    } else {
        $r['existing'] = $byTmdb[(string) $r['tmdb']] ?? $byTitleYear[$titleYearKey($r['title'], $r['year'])] ?? null;
    }
}
unset($r);

json_response(200, ['results' => $results] + $extra);

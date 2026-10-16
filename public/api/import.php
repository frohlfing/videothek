<?php

/**
 * Importiert einen Film oder eine Serie aus TMDB (The Movie Database), Ablauf angelehnt an den Kodi-Scraper
 * "The Movie Database Python": Details in TMDB_LANGUAGE (siehe api/lib/tmdb.php, bei Serien samt aller Staffeln und
 * Folgen), danach Poster und Hintergrundbild (fanart) herunterladen. Gespeichert wird über dieselbe
 * Validierungs-/Anlege-Logik wie POST /api/movies.php (create_movie() aus crud.php). Schlägt nur ein Bild-Download
 * fehl, bleibt der Eintrag trotzdem gespeichert (Antwort enthält dann zusätzlich "import_warning").
 * Die Darstellerfotos lädt das Frontend direkt danach über api/actors.php (mehrere Requests, damit auch Einträge mit
 * vielen Darstellern nicht ins PHP-Zeitlimit laufen).
 *
 * POST /api/import.php
 *   Body: {"tmdb": 9340, "type": "movie"} bzw. {"tmdb": 1396, "type": "series"} (aus GET /api/search.php;
 *   ohne type: Film). Token nötig (Header X-API-Token).
 *   201 mit dem neu angelegten Eintrag bei Erfolg, 400, wenn er schon vorhanden ist.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/image_store.php';
require_once __DIR__ . '/lib/tmdb.php';

// TMDB kennt das Format des eigenen Exemplars nicht; die meisten Filme der Sammlung sind DVDs.
// Abweichendes (Blu-ray, ...) nach dem Import im Formular ändern. Bei Serien bekommen es alle regulären
// Staffeln (nicht die Specials, Staffel 0); fehlende Staffeln dann im Formular abwählen.
const IMPORT_DEFAULT_MEDIUM = 'DVD';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Nur POST erlaubt');
}

require_token();

$body = read_json_body();
$tmdbId = filter_var($body['tmdb'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($tmdbId === false) {
    json_error(400, 'Gültige TMDB-ID (tmdb) ist erforderlich');
}

$type = $body['type'] ?? TYPE_MOVIE;
if (!in_array($type, ENTRY_TYPES, true)) {
    json_error(400, 'type muss "' . implode('" oder "', ENTRY_TYPES) . '" sein');
}

if (($existing = find_slug_by_tmdb_id((string) $tmdbId, $type)) !== null) {
    json_error(400, type_label($type) . " bereits vorhanden ($existing)");
}

try {
    $details = $type === TYPE_SERIES ? tmdb_series($tmdbId) : tmdb_movie($tmdbId);
} catch (RuntimeException $e) {
    json_error(422, 'Import fehlgeschlagen: ' . $e->getMessage());
}

if ($type === TYPE_SERIES) {
    foreach ($details['seasons'] as &$season) {
        $season['medium'] = $season['number'] > 0 ? [IMPORT_DEFAULT_MEDIUM] : [];
    }
    unset($season);
} else {
    $details['medium'] = IMPORT_DEFAULT_MEDIUM;
}

try {
    $movie = create_movie($details, $details['uniqueids']);
} catch (ValidationException $e) {
    json_response(400, ['error' => 'Validierung fehlgeschlagen', 'details' => $e->errors]);
}

$warnings = [];
foreach (['poster' => 'Poster', 'fanart' => 'Hintergrundbild'] as $kind => $label) {
    $url = $details[$kind . '_url'];
    if ($url === null) {
        continue;
    }
    $tmpFile = tempnam(sys_get_temp_dir(), 'vtimport_');
    try {
        file_put_contents($tmpFile, http_get($url));
        $movie = store_movie_image($movie['slug'], $tmpFile, $kind);
    } catch (Throwable $e) {
        $warnings[] = "$label konnte nicht geladen werden: " . $e->getMessage();
    }
    @unlink($tmpFile);
}

json_response(201, $warnings
    ? array_merge($movie, ['import_warning' => type_label($type) . ' importiert, aber: ' . implode('; ', $warnings)])
    : $movie);

<?php

/**
 * Importiert einen Film aus TMDB (The Movie Database), Ablauf angelehnt an den Kodi-Scraper
 * "The Movie Database Python": Details in TMDB_LANGUAGE (siehe api/lib/tmdb.php), danach Poster
 * und Hintergrundbild (fanart) herunterladen. Gespeichert wird über dieselbe Validierungs-/Anlege-
 * Logik wie POST /api/movies.php (create_movie() aus crud.php). Schlägt nur ein Bild-Download fehl,
 * bleibt der Film trotzdem gespeichert (Antwort enthält dann zusätzlich "import_warning").
 * Die Darstellerfotos lädt das Frontend direkt danach über api/actors.php (mehrere Requests, damit
 * auch Filme mit vielen Darstellern nicht ins PHP-Zeitlimit laufen).
 *
 * POST /api/import.php
 *   Body: {"tmdb": 9340} (TMDB-Film-ID aus GET /api/search.php). Token nötig (Header X-API-Token).
 *   201 mit dem neu angelegten Film bei Erfolg, 400 wenn er schon vorhanden ist.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/image_store.php';
require_once __DIR__ . '/lib/tmdb.php';

// TMDB kennt das Format des eigenen Exemplars nicht; die meisten Filme der Sammlung sind DVDs.
// Abweichendes (Blu-ray, ...) nach dem Import im Formular ändern.
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

if (($existing = find_slug_by_tmdb_id((string) $tmdbId)) !== null) {
    json_error(400, "Film bereits vorhanden ($existing)");
}

try {
    $details = tmdb_movie($tmdbId);
} catch (RuntimeException $e) {
    json_error(422, 'Import fehlgeschlagen: ' . $e->getMessage());
}

try {
    $movie = create_movie(['medium' => IMPORT_DEFAULT_MEDIUM] + $details, $details['uniqueids']);
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
    ? array_merge($movie, ['import_warning' => 'Film importiert, aber: ' . implode('; ', $warnings)])
    : $movie);

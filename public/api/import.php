<?php

/**
 * Importiert einen Film oder eine Serie aus TMDB (The Movie Database), Ablauf angelehnt an den Kodi-Scraper
 * "The Movie Database Python": Details in TMDB_LANGUAGE (siehe api/lib/tmdb.php), danach Poster und Hintergrundbild
 * (fanart) herunterladen. Gespeichert wird über dieselbe Validierungs-/Anlege-Logik wie POST /api/movies.php
 * (create_movie() aus crud.php). Schlägt nur ein Bild-Download fehl, bleibt der Eintrag trotzdem gespeichert
 * (Antwort enthält dann zusätzlich "import_warning").
 *
 * Eine Serie wird zu einem Eintrag pro Staffel (type "season", siehe tmdb_series()), zusammengehalten über das Feld
 * "series". Importiert werden nur die angegebenen Staffeln (die eigenen DVDs); weitere lassen sich später genauso
 * ergänzen. Staffeln, die es schon gibt (gleiche TMDB-ID der Serie und Nummer), bleiben unverändert. Hat eine
 * vorhandene Staffel der Serie einen anderen Seriennamen (von Hand geändert), bekommen die neuen ihn auch.
 *
 * Nebenbei wird festgehalten, welche Teile bzw. Staffeln es laut TMDB gibt (data/collections.json bzw. series.json,
 * siehe lib/catalog.php): bei Serien aus dem ohnehin geladenen Abruf, bei einem Film einer Reihe mit einem
 * zusätzlichen Abruf. Scheitert das, bleibt der Import trotzdem gültig (nur "import_warning").
 *
 * Die Darstellerfotos lädt das Frontend direkt danach über api/actors.php (mehrere Requests, damit auch Einträge mit
 * vielen Darstellern nicht ins PHP-Zeitlimit laufen).
 *
 * POST /api/import.php   Token nötig (Header X-API-Token)
 *   Body: {"tmdb": 9340, "type": "movie"} (aus GET /api/search.php; ohne type: Film)
 *     -> 201 mit dem neu angelegten Film
 *   Body: {"tmdb": 1396, "type": "series", "seasons": [1, 2]}
 *     seasons: Nummern der zu importierenden Staffeln (0 = Specials), sie bekommen wie ein Film das Medium aus import_media()
 *     -> 201 mit {"entries": [neue Staffeln], "skipped": [angegebene, schon vorhandene Staffeln]}
 *   400, wenn der Film bzw. alle angegebenen Staffeln schon vorhanden sind oder TMDB eine Staffel nicht kennt.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/image_store.php';
require_once __DIR__ . '/lib/tmdb.php';

// TMDB kennt das Format des eigenen Exemplars nicht; die meisten Filme der Sammlung sind DVDs.
// Abweichendes (Blu-ray, ...) nach dem Import im Formular ändern.
const IMPORT_DEFAULT_MEDIUM = 'DVD';

/**
 * Das importierte Exemplar: IMPORT_DEFAULT_MEDIUM des Eigentümers (owner_of()) des angemeldeten Benutzers, ohne
 * Anmeldung des ersten aus users.json.
 */
function import_media(): array
{
    $user = current_user() ?? first_user();
    return $user !== null ? [['owner' => owner_of($user), 'type' => IMPORT_DEFAULT_MEDIUM]] : [];
}

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
if (!in_array($type, [TYPE_MOVIE, TYPE_SERIES], true)) {
    json_error(400, 'type muss "movie" oder "series" sein');
}

if ($type === TYPE_MOVIE) {
    if (($existing = find_slug_by_tmdb_id((string) $tmdbId)) !== null) {
        json_error(400, "Film bereits vorhanden ($existing)");
    }
    try {
        $details = tmdb_movie($tmdbId);
    } catch (RuntimeException $e) {
        json_error(422, 'Import fehlgeschlagen: ' . $e->getMessage());
    }
    $details['media'] = import_media();
    // Gibt es schon Teile derselben Reihe, dann deren Namen übernehmen (er kann im Formular geändert worden sein); die
    // Teilnummer gehört zum anderen Film und bleibt leer.
    if ($details['collection'] !== null) {
        $known = find_collection_by_tmdb_id($details['collection']['tmdb']);
        $details['collection']['name'] = $known['name'] ?? $details['collection']['name'];
    }
    [$movie, $warnings] = import_entry($details);
    if ($details['collection'] !== null) {
        try {
            $cid = $details['collection']['tmdb'];
            save_catalog_entry(CATALOG_COLLECTIONS, $cid, tmdb_collection_catalog((int) $cid));
        } catch (Throwable $e) {
            $warnings[] = 'Teile der Reihe konnten nicht abgerufen werden: ' . $e->getMessage();
        }
    }
    json_response(201, $warnings
        ? array_merge($movie, ['import_warning' => 'Film importiert, aber: ' . implode('; ', $warnings)])
        : $movie);
}

$wanted = $body['seasons'] ?? null;
if (!is_array($wanted) || !$wanted
    || array_filter($wanted, fn($n) => filter_var($n, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false)) {
    json_error(400, 'seasons muss eine Liste von Staffelnummern sein');
}
$wanted = array_values(array_unique(array_map('intval', $wanted)));

$existing = seasons_by_series_tmdb_id((string) $tmdbId);
$skipped = array_values(array_filter($wanted, fn(int $n) => isset($existing[$n])));
$wanted = array_values(array_diff($wanted, $skipped));
if (!$wanted) {
    json_error(400, count($skipped) > 1 ? 'Diese Staffeln sind bereits vorhanden' : 'Diese Staffel ist bereits vorhanden');
}
try {
    $details = tmdb_series($tmdbId, $wanted);
} catch (RuntimeException $e) {
    json_error(422, 'Import fehlgeschlagen: ' . $e->getMessage());
}
$unknown = array_diff($wanted, array_column($details['seasons'], 'season'));
if ($unknown) {
    json_error(400, 'Diese Staffel gibt es bei TMDB nicht: ' . implode(', ', $unknown));
}
$new = $details['seasons'];

set_time_limit(300); // je Staffel Cover und Hintergrundbild
$series = find_series_by_tmdb_id((string) $tmdbId) ?? $details['series'];
$entries = [];
$warnings = [];
try {
    save_catalog_entry(CATALOG_SERIES, (string) $tmdbId, $details['catalog']);
} catch (Throwable $e) {
    $warnings[] = 'Staffelliste konnte nicht gespeichert werden: ' . $e->getMessage();
}
foreach ($new as $season) {
    if ($series['name'] !== $season['series']['name'] && str_starts_with($season['title'], $season['series']['name'])) {
        $season['title'] = $series['name'] . substr($season['title'], strlen($season['series']['name']));
    }
    $season['series'] = $series;
    $season['media'] = import_media();
    try {
        [$entry, $entryWarnings] = import_entry($season);
    } catch (ValidationException $e) {
        $warnings[] = "{$season['title']}: " . implode('; ', $e->errors);
        continue;
    }
    $entries[] = $entry;
    foreach ($entryWarnings as $w) {
        $warnings[] = "{$season['title']}: $w";
    }
}
if (!$entries) {
    json_response(400, ['error' => 'Validierung fehlgeschlagen', 'details' => $warnings]);
}

$response = ['entries' => $entries, 'skipped' => $skipped];
if ($warnings) {
    $response['import_warning'] = 'Serie importiert, aber: ' . implode('; ', $warnings);
}
json_response(201, $response);

/**
 * Legt einen Eintrag aus den TMDB-Details an und lädt Poster und Hintergrundbild. Bilder werden je Request nur
 * einmal heruntergeladen (die Staffeln einer Serie teilen sich meist das Hintergrundbild).
 * @return array{0: array, 1: string[]} der Eintrag und Warnungen zu fehlgeschlagenen Bildern
 * @throws ValidationException
 */
function import_entry(array $details): array
{
    static $downloaded = [];
    $movie = create_movie($details, $details['uniqueids']);
    $warnings = [];
    foreach (['poster' => 'Poster', 'fanart' => 'Hintergrundbild'] as $kind => $label) {
        $url = $details[$kind . '_url'] ?? null;
        if ($url === null) {
            continue;
        }
        try {
            if (!isset($downloaded[$url])) {
                $tmpFile = tempnam(sys_get_temp_dir(), 'vtimport_');
                register_shutdown_function(fn() => @unlink($tmpFile));
                file_put_contents($tmpFile, http_get($url));
                $downloaded[$url] = $tmpFile;
            }
            $movie = store_movie_image($movie['slug'], $downloaded[$url], $kind);
        } catch (Throwable $e) {
            $warnings[] = "$label konnte nicht geladen werden: " . $e->getMessage();
        }
    }
    return [$movie, $warnings];
}

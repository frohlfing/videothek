<?php

/**
 * Wo sich die DVDs und Blu-rays befinden (data/locations.json, siehe lib/locations.php).
 *
 * GET /api/locations.php -> {"locations": {<slug>: {"<eigentümer>/<art>": {at, since, by}}}}. Kein Token nötig.
 * PUT /api/locations.php -> Body {"slug", "owner", "type", "at"}: at = bei wem das Medium jetzt ist, ein Benutzer
 *                           (Login, gespeichert wird sein Haushalt) bzw. Haushalt (Eigentümer) oder ein beliebiger
 *                           Name ("Oma Erna"); leer oder der Eigentümer = zurück beim
 *                           Eigentümer. "since" und "by" setzt der Server. Token nötig.
 *                           -> {"slug", "locations": {…des Eintrags}}
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

/** Höchstlänge eines frei eingegebenen Namens bei der Ausleihe. */
const LOCATION_NAME_MAX = 60;

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    json_response(200, ['locations' => (object) read_locations()]);
}
if ($method !== 'PUT') {
    json_error(405, "Methode '$method' nicht erlaubt");
}

require_token();
$body = read_json_body();
$slug = (string) ($body['slug'] ?? '');
$owner = (string) ($body['owner'] ?? '');
$type = (string) ($body['type'] ?? '');
$at = trim(preg_replace('/\s+/u', ' ', (string) ($body['at'] ?? ''))) ?: null;

$movie = read_movie_json($slug);
if ($movie === null) {
    json_error(404, "Eintrag '$slug' nicht gefunden");
}
$exists = array_filter($movie['media'] ?? [], fn(array $m) => $m['owner'] === $owner && $m['type'] === $type);
if (!$exists) {
    json_error(404, "Medium $owner/$type gibt es bei diesem Eintrag nicht");
}
if (in_array($type, DIGITAL_MEDIA, true)) {
    json_error(400, "$type ist eine Datei und hat keinen Standort");
}
if ($at !== null && (mb_strlen($at) > LOCATION_NAME_MAX || preg_match('/[\x00-\x1f]/', $at))) {
    json_error(400, 'Der Name darf höchstens ' . LOCATION_NAME_MAX . ' Zeichen lang sein');
}

try {
    $locations = set_location($slug, $owner, $type, $at, current_user());
} catch (RuntimeException $e) {
    json_error(500, $e->getMessage());
}
json_response(200, ['slug' => $slug, 'locations' => (object) $locations]);

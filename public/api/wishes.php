<?php

/**
 * Wunschliste: wer welchen Eintrag sehen möchte (data/wishes.json, siehe lib/wishes.php).
 *
 * GET /api/wishes.php -> {"wishes": {<slug>: {<login>: <zeitpunkt>}}}. Kein Token nötig.
 * PUT /api/wishes.php -> Body {"slug", "wish": true|false}: Wunsch des angemeldeten Benutzers setzen bzw. entfernen.
 *                        Token und Anmeldung (Basic Auth) nötig.
 *                        -> {"slug", "wishes": {…des Eintrags}}
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    json_response(200, ['wishes' => (object) read_wishes()]);
}
if ($method !== 'PUT') {
    json_error(405, "Methode '$method' nicht erlaubt");
}

require_token();
$user = current_user();
if ($user === null) {
    json_error(403, 'Die Wunschliste geht nur mit Anmeldung');
}
$body = read_json_body();
$slug = (string) ($body['slug'] ?? '');
if ($slug === '' || slugify($slug) !== $slug || read_movie_json($slug) === null) {
    json_error(404, "Eintrag '$slug' nicht gefunden");
}

try {
    $wishes = set_wish($slug, $user, (bool) ($body['wish'] ?? false));
} catch (RuntimeException $e) {
    json_error(500, $e->getMessage());
}
json_response(200, ['slug' => $slug, 'wishes' => (object) $wishes]);

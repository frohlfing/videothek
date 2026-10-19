<?php

/**
 * Bewertungen: Daumen hoch bzw. runter (data/ratings.json, siehe lib/ratings.php).
 *
 * GET /api/ratings.php -> {"ratings": {<slug>: {<login>: 1|-1}}}. Kein Token nötig.
 * PUT /api/ratings.php -> Body {"slug", "rating": 1|-1|0}: Bewertung des angemeldeten Benutzers setzen bzw. (0)
 *                         entfernen. Die erste Bewertung nimmt den Eintrag von seiner Wunschliste (gesehen);
 *                         wieder wünschen geht trotzdem, eine geänderte Bewertung lässt den Wunsch dann stehen.
 *                         Token und Anmeldung (Basic Auth) nötig.
 *                         -> {"slug", "ratings": {…des Eintrags}, "wishes": {…des Eintrags}}
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    json_response(200, ['ratings' => (object) read_ratings()]);
}
if ($method !== 'PUT') {
    json_error(405, "Methode '$method' nicht erlaubt");
}

require_token();
$user = current_user();
if ($user === null) {
    json_error(403, 'Bewerten geht nur mit Anmeldung');
}
$body = read_json_body();
$slug = (string) ($body['slug'] ?? '');
if ($slug === '' || slugify($slug) !== $slug || read_movie_json($slug) === null) {
    json_error(404, "Eintrag '$slug' nicht gefunden");
}
$rating = $body['rating'] ?? null;
if (!in_array($rating, [1, -1, 0], true)) {
    json_error(400, 'Bewertung muss 1, -1 oder 0 sein');
}

$firstRating = !isset(read_ratings()[$slug][$user]);
try {
    $ratings = set_rating($slug, $user, $rating);
    $wishes = $rating !== 0 && $firstRating ? set_wish($slug, $user, false) : (read_wishes()[$slug] ?? []);
} catch (RuntimeException $e) {
    json_error(500, $e->getMessage());
}
json_response(200, ['slug' => $slug, 'ratings' => (object) $ratings, 'wishes' => (object) $wishes]);

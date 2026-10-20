<?php

/**
 * Bewertungen in vier Stufen (data/ratings.json, siehe lib/ratings.php).
 *
 * GET /api/ratings.php -> {"ratings": {<slug>: {<login>: -1|0|1|2}}}. Kein Token nötig.
 * PUT /api/ratings.php -> Body {"slug", "rating": -1|0|1|2|null}: Bewertung des angemeldeten Benutzers setzen bzw.
 *                         (null) entfernen. Die erste Bewertung nimmt den Eintrag von seiner Wunschliste (gesehen);
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
if (!array_key_exists('rating', $body) || !in_array($body['rating'], [...RATING_VALUES, null], true)) {
    json_error(400, 'Bewertung muss ' . implode(', ', RATING_VALUES) . ' oder null (entfernen) sein');
}
$rating = $body['rating'];

$firstRating = !isset(read_ratings()[$slug][$user]);
try {
    $ratings = set_rating($slug, $user, $rating);
    $wishes = $rating !== null && $firstRating ? set_wish($slug, $user, false) : (read_wishes()[$slug] ?? []);
} catch (RuntimeException $e) {
    json_error(500, $e->getMessage());
}
json_response(200, ['slug' => $slug, 'ratings' => (object) $ratings, 'wishes' => (object) $wishes]);

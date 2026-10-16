<?php

/**
 * Prüft beim Öffnen der Detailansicht den Trailer eines Films und repariert ihn bei Bedarf (siehe
 * api/lib/trailer_check.php: ensure_trailer()). Höchstens alle TRAILER_CHECK_MAX_AGE_DAYS Tage wird
 * tatsächlich bei YouTube/TMDB nachgefragt, sonst antwortet der Endpunkt sofort aus movie.json.
 *
 * GET /api/trailer.php?slug=xyz -> {"trailer": "https://www.youtube.com/watch?v=..." | null, "status": "..."}
 *
 * Bewusst ohne API-Token, obwohl movie.json geändert werden kann: Das Öffnen eines Films soll nicht nach
 * dem Token fragen, und geändert wird ausschließlich der Trailer, und zwar nur auf einen, den TMDB für
 * genau diesen Film liefert. Vor fremdem Zugriff schützt wie bei allen GET-Endpunkten die HTTP Basic Auth.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/trailer_check.php';

$slug = (string) ($_GET['slug'] ?? '');
$movie = read_movie_json($slug);
if ($movie === null) {
    json_error(404, "Film '$slug' nicht gefunden");
}

$result = ensure_trailer($slug, $movie);
json_response(200, ['trailer' => $result['movie']['trailer'] ?? null, 'status' => $result['status']]);

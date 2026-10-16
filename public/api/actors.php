<?php

/**
 * Lädt die Darstellerfotos eines Films von TMDB herunter (siehe api/lib/actors.php). Wird vom Frontend
 * direkt nach dem Import so lange wiederholt aufgerufen, bis "remaining" 0 ist – jeder Aufruf arbeitet
 * höchstens ~10 s, damit auch Filme mit 50+ Darstellern nicht ins PHP-Zeitlimit laufen.
 *
 * POST /api/actors.php
 *   Body: {"slug": "die-goonies-1985"}. Token nötig (Header X-API-Token).
 *   -> {"movie": {...}, "loaded": 12, "failed": 0, "remaining": 26}
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/actors.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Nur POST erlaubt');
}

require_token();

$body = read_json_body();
$slug = (string) ($body['slug'] ?? '');
if ($slug === '') {
    json_error(400, 'slug ist erforderlich');
}

try {
    json_response(200, download_actor_photos($slug));
} catch (NotFoundException $e) {
    json_error(404, $e->getMessage());
}

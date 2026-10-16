<?php

/**
 * Liefert Bilder eines Films aus: Poster, Thumbnail, Hintergrundbild (fanart) oder ein Darstellerfoto.
 * Nötig, weil DATA_DIR bewusst außerhalb des Document Root liegt und daher nicht
 * direkt per statischer URL erreichbar ist (siehe config.php).
 *
 * GET /api/image.php?slug=xyz&type=poster|thumb|fanart
 * GET /api/image.php?slug=xyz&actor=Mark%20Strong
 *   Darstellerfotos liegen wie bei Kodi als <Vorname>_<Nachname>.jpg, aber nicht in ".actors",
 *   sondern in data/movies/<slug>/actors/ (heruntergeladen von api/actors.php, Dateiname in cast[].thumb).
 *
 * Antwort: die Bilddatei mit passendem Content-Type, 404 falls Film/Bild fehlt.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$slug = (string) ($_GET['slug'] ?? '');
$actor = isset($_GET['actor']) ? (string) $_GET['actor'] : null;
$type = (string) ($_GET['type'] ?? 'poster');

if ($actor === null && !in_array($type, ['poster', 'thumb', 'fanart'], true)) {
    json_error(400, 'type muss poster, thumb oder fanart sein');
}

$movie = get_movie($slug);
if ($movie === null) {
    http_response_code(404);
    exit;
}

if ($actor !== null) {
    $path = null;
    foreach ($movie['cast'] ?? [] as $c) {
        if ($c['name'] === $actor && !empty($c['thumb'])) {
            $path = movie_dir($slug) . '/actors/' . basename($c['thumb']);
            break;
        }
    }
} else {
    $path = !empty($movie[$type]) ? movie_dir($slug) . '/' . $movie[$type] : null;
}
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ALLOWED_IMAGE_TYPES[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
// "private" statt "public": Die Seite ist per HTTP Basic Auth geschützt (siehe public/.htaccess),
// "public" würde Browsern/Proxys erlauben, die Antwort auch außerhalb der Anmeldung zu
// cachen/weiterzugeben und damit den Zugriffsschutz für dieses Bild faktisch aushebeln.
header('Cache-Control: private, max-age=604800'); // 1 Woche; ändert sich nur bei erneutem Upload
header('Content-Length: ' . filesize($path));
readfile($path);

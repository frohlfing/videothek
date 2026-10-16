<?php

/**
 * Nimmt das Poster oder das Hintergrundbild (fanart) für einen bestehenden Film entgegen. Zum Poster
 * wird automatisch ein Thumbnail generiert (siehe api/lib/thumbnail.php). Die eigentliche Speicher-/Validierungslogik
 * steckt in api/lib/image_store.php (store_movie_image()), gemeinsam genutzt mit import.php.
 *
 * POST /api/upload.php (multipart/form-data)
 *   Felder: slug (Pflicht, muss einen existierenden Film referenzieren), image (Pflicht, Datei),
 *   kind (optional: "poster" (Default) oder "fanart").
 *   Token nötig (Header X-API-Token). Antwort: das komplette, aktualisierte Film-Objekt.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/image_store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Nur POST erlaubt');
}

require_token();

$slug = (string) ($_POST['slug'] ?? '');
if ($slug === '') {
    json_error(400, 'slug ist erforderlich');
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_error(400, 'Kein gültiges Bild im Feld "image" übermittelt');
}

$kind = (string) ($_POST['kind'] ?? 'poster');
if (!in_array($kind, ['poster', 'fanart'], true)) {
    json_error(400, 'kind muss poster oder fanart sein');
}

$file = $_FILES['image'];
if ($file['size'] > MAX_UPLOAD_BYTES) {
    json_error(400, 'Bild ist zu groß (Limit: ' . (int) (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB)');
}

// move_uploaded_file() prüft zusätzlich, dass die Datei tatsächlich per HTTP-Upload kam;
// store_movie_image() akzeptiert danach jeden lokalen Pfad (auch von import.php genutzt).
$safeTmp = tempnam(sys_get_temp_dir(), 'vtupload_');
if (!move_uploaded_file($file['tmp_name'], $safeTmp)) {
    json_error(500, 'Bild konnte nicht zwischengespeichert werden');
}

// Kein finally: json_error() beendet das Skript per exit(), das würde ein finally-Block überspringen.
try {
    $movie = store_movie_image($slug, $safeTmp, $kind);
} catch (ImageValidationException $e) {
    @unlink($safeTmp);
    json_error(400, $e->getMessage());
} catch (NotFoundException $e) {
    @unlink($safeTmp);
    json_error(404, $e->getMessage());
}
@unlink($safeTmp);

json_response(200, $movie);

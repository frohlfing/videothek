<?php

/**
 * Setzt bei einem Medium mit Dateien die Angaben zu den Dateien: Pfade, Bild und Ton.
 *
 * Alles andere am Eintrag (und am Medium, z. B. "quality") bleibt, anders als bei `PUT /api/movies.php`, das den
 * ganzen Eintrag ersetzt. Gedacht für `bin/convert.php`, das neue Filme umwandelt und hier einträgt.
 *
 * PUT /api/media.php   Token nötig (Header X-API-Token)
 *   Body {"slug", "owner", "type": "USB", "paths": ["kick-ass/kick-ass-2010.mp4"], "video": {…}, "audio": {…}}
 *     paths relativ zum USB-Ordner (validate_paths_input()), video/audio wie normalize_video()/normalize_audio();
 *     fehlt video bzw. audio, wird es entfernt
 *   -> 200 {"slug", "medium": {…}}; 404, wenn Eintrag oder Medium fehlt; 400 bei ungültigen Angaben
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'PUT') {
    json_error(405, "Methode '$method' nicht erlaubt");
}

require_token();
$body = read_json_body();
$slug = (string) ($body['slug'] ?? '');
$owner = (string) ($body['owner'] ?? '');
$type = (string) ($body['type'] ?? '');
if (!in_array($type, DIGITAL_MEDIA, true)) {
    json_error(400, 'type muss eines von ' . implode(', ', DIGITAL_MEDIA) . ' sein');
}
$errors = validate_paths_input($body['paths'] ?? null, 'paths');
$paths = normalize_media_paths(is_array($body['paths'] ?? null) ? $body['paths'] : []);
if (!$paths) {
    $errors[] = 'paths darf nicht leer sein';
}
if ($errors) {
    json_response(400, ['error' => 'Validierung fehlgeschlagen', 'details' => $errors]);
}

$movie = $slug !== '' ? read_movie_json($slug) : null;
if ($movie === null) {
    json_error(404, "Eintrag '$slug' nicht gefunden");
}
$index = null;
foreach ($movie['media'] ?? [] as $i => $md) {
    if ($md['owner'] === $owner && $md['type'] === $type) {
        $index = $i;
        break;
    }
}
if ($index === null) {
    json_error(404, "Medium $type von '$owner' fehlt bei '$slug'");
}

$medium = $movie['media'][$index];
unset($medium['files'], $medium['video'], $medium['audio']);
$medium['paths'] = $paths;
$medium += array_filter(['video' => normalize_video($body['video'] ?? null), 'audio' => normalize_audio($body['audio'] ?? null)]);
$movie['media'][$index] = $medium;
write_movie_json($slug, $movie);
json_response(200, ['slug' => $slug, 'medium' => $medium]);

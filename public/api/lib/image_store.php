<?php

use Random\RandomException;

require_once __DIR__ . '/thumbnail.php';

/** Die Bilddatei entspricht nicht ALLOWED_IMAGE_TYPES (geprüft am tatsächlichen Dateiinhalt, nicht an Endung/Content-Type). */
class ImageValidationException extends RuntimeException
{
}

/**
 * Installiert die lokale Bilddatei unter $localPath als Poster bzw. Hintergrundbild (Kodi: "fanart")
 * von Film $slug: validiert den tatsächlichen Bildtyp, entfernt alte Dateien derselben Art (auch bei
 * Formatwechsel), kopiert das Bild als poster.<ext> bzw. fanart.<ext> und aktualisiert movie.json.
 * Zum Poster wird zusätzlich ein Thumbnail (thumb.<ext>) fürs Kartenraster generiert.
 * $localPath wird dabei nur gelesen (kopiert), nicht verschoben oder gelöscht – Aufräumen bleibt Sache
 * des Aufrufers. Gemeinsam genutzt von upload.php (Browser-Upload) und import.php (TMDB-Download).
 *
 * @param string $slug Ordnername des Eintrags unter MOVIES_DIR
 * @param string $localPath Pfad zur bereits auf dem Server liegenden Bilddatei
 * @param 'poster'|'fanart' $kind
 * @return array Der aktualisierte Eintrag (movie.json) in kanonischer Feldreihenfolge
 * @throws ImageValidationException|NotFoundException
 */
function store_movie_image(string $slug, string $localPath, string $kind = 'poster'): array
{
    if (!in_array($kind, ['poster', 'fanart'], true)) {
        throw new InvalidArgumentException("Unbekannte Bildart '$kind'");
    }
    $movie = read_movie_json($slug);
    if ($movie === null) {
        throw new NotFoundException("Film '$slug' nicht gefunden");
    }

    $imageInfo = @getimagesize($localPath);
    $extByImageType = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    $ext = $imageInfo !== false ? ($extByImageType[$imageInfo[2]] ?? null) : null;
    if ($ext === null || !isset(ALLOWED_IMAGE_TYPES[$ext])) {
        throw new ImageValidationException('Nicht unterstützter Bildtyp (erlaubt: ' . implode(', ', array_keys(ALLOWED_IMAGE_TYPES)) . ')');
    }

    $dir = movie_dir($slug);
    $oldPatterns = $kind === 'poster' ? ['poster.*', 'thumb.*'] : ['fanart.*'];
    foreach ($oldPatterns as $pattern) {
        foreach (glob($dir . '/' . $pattern) ?: [] as $old) {
            unlink($old);
        }
    }

    $imageName = $kind . '.' . $ext;
    if (!copy($localPath, $dir . '/' . $imageName)) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden');
    }
    $movie[$kind] = $imageName;

    if ($kind === 'poster') {
        $thumbName = 'thumb.' . $ext;
        $thumbOk = generate_thumbnail($dir . '/' . $imageName, $dir . '/' . $thumbName, $ext, THUMB_WIDTH, THUMB_HEIGHT);
        $movie['thumb'] = $thumbOk ? $thumbName : null;
    }

    write_movie_json($slug, $movie);
    return order_movie_fields($movie);
}

/**
 * Bildtyp (Endung) einer lokalen Datei nach ihrem Inhalt.
 *
 * @param string $path Pfad der lokalen Bilddatei
 * @return string Die Endung (jpg, png oder webp)
 * @throws ImageValidationException wenn es kein erlaubtes Bild ist
 */
function image_file_ext(string $path): string
{
    $imageInfo = @getimagesize($path);
    $extByImageType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $ext = $imageInfo !== false ? ($extByImageType[$imageInfo[2]] ?? null) : null;
    if ($ext === null || !isset(ALLOWED_IMAGE_TYPES[$ext])) {
        throw new ImageValidationException('Nicht unterstützter Bildtyp (erlaubt: ' . implode(', ', array_keys(ALLOWED_IMAGE_TYPES)) . ')');
    }
    return $ext;
}

/**
 * Entfernt Poster (samt Thumbnail) bzw. Hintergrundbild eines Eintrags.
 * @param string $slug Ordnername des Eintrags unter MOVIES_DIR
 * @param 'poster'|'fanart' $kind
 * @return array Der aktualisierte Eintrag (movie.json) in kanonischer Feldreihenfolge
 * @throws NotFoundException
 */
function remove_movie_image(string $slug, string $kind): array
{
    $movie = read_movie_json($slug);
    if ($movie === null) {
        throw new NotFoundException("Film '$slug' nicht gefunden");
    }
    foreach ($kind === 'poster' ? ['poster', 'thumb'] : ['fanart'] as $field) {
        if (!empty($movie[$field])) {
            @unlink(movie_dir($slug) . '/' . basename($movie[$field]));
        }
        $movie[$field] = null;
    }
    write_movie_json($slug, $movie);
    return order_movie_fields($movie);
}

/**
 * Setzt das Foto des Darstellers $name (actors/<Name>.<ext>, auf 300 × 450 zugeschnitten wie die TMDB-Porträts).
 * Das TMDB-Porträt (profile) wird dabei verworfen, damit api/actors.php das eigene Foto nicht überschreibt.
 *
 * @param string $slug Ordnername des Films unter MOVIES_DIR
 * @param string $name Name des Darstellers (wie in cast[].name)
 * @param string $localPath Pfad der bereits auf dem Server liegenden Bilddatei
 * @return array Der aktualisierte Eintrag (movie.json) in kanonischer Feldreihenfolge
 * @throws ImageValidationException|NotFoundException|RuntimeException|RandomException
 */
function store_actor_photo(string $slug, string $name, string $localPath): array
{
    $movie = read_movie_json($slug);
    $index = $movie === null ? false : array_search($name, array_column($movie['cast'] ?? [], 'name'), true);
    if ($index === false) {
        throw new NotFoundException("Darsteller '$name' nicht gefunden");
    }
    $ext = image_file_ext($localPath);
    $dir = movie_dir($slug) . '/actors';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Ordner actors/ konnte nicht angelegt werden');
    }
    // Neuer Name mit Zufallsteil: Der Browser hat das alte Foto unter derselben URL eine Woche im Cache.
    $file = preg_replace('/\.jpg$/', '', actor_file_name($name)) . '-' . bin2hex(random_bytes(3)) . ".$ext";
    if (!generate_thumbnail($localPath, "$dir/$file", $ext, 300, 450) && !copy($localPath, "$dir/$file")) {
        throw new RuntimeException('Foto konnte nicht gespeichert werden');
    }
    $movie['cast'][$index]['thumb'] = $file;
    $movie['cast'][$index]['profile'] = null;
    write_movie_json($slug, $movie);
    remove_unused_actor_photos($slug, $movie);
    return order_movie_fields($movie);
}

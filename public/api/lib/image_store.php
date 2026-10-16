<?php

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
 * @param 'poster'|'fanart' $kind
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

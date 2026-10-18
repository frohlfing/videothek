<?php

/**
 * Nimmt Bilder eines bestehenden Eintrags entgegen: Poster (mit automatischem Thumbnail, siehe
 * api/lib/thumbnail.php), Hintergrundbild (fanart) oder Foto eines Darstellers. Poster und Hintergrundbild lassen
 * sich auch entfernen; Darstellerfotos entfernt das Formular selbst (es schickt den Dateinamen nicht mehr mit,
 * update_movie() räumt die Datei auf). Die Speicher-/Validierungslogik
 * steckt in api/lib/image_store.php, gemeinsam genutzt mit import.php.
 *
 * POST /api/upload.php (multipart/form-data)
 *   Felder: slug (Pflicht, muss einen existierenden Eintrag referenzieren), image (Datei, Pflicht außer bei remove),
 *   kind: "poster" (Default), "fanart" oder "actor" (dazu actor = Name),
 *   remove = "1": Poster bzw. Hintergrundbild entfernen statt hochladen.
 *   Token nötig (Header X-API-Token). Antwort: der komplette, aktualisierte Eintrag.
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

$kind = (string) ($_POST['kind'] ?? 'poster');
if (!in_array($kind, ['poster', 'fanart', 'actor'], true)) {
    json_error(400, 'kind muss poster, fanart oder actor sein');
}

if (($_POST['remove'] ?? '') === '1') {
    if (!in_array($kind, ['poster', 'fanart'], true)) {
        json_error(400, 'Entfernen geht hier nur für poster und fanart');
    }
    try {
        json_response(200, remove_movie_image($slug, $kind));
    } catch (NotFoundException $e) {
        json_error(404, $e->getMessage());
    }
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_error(400, 'Kein gültiges Bild im Feld "image" übermittelt');
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
    $movie = $kind === 'actor'
        ? store_actor_photo($slug, (string) ($_POST['actor'] ?? ''), $safeTmp)
        : store_movie_image($slug, $safeTmp, $kind);
} catch (ImageValidationException $e) {
    @unlink($safeTmp);
    json_error(400, $e->getMessage());
} catch (NotFoundException $e) {
    @unlink($safeTmp);
    json_error(404, $e->getMessage());
} catch (RuntimeException $e) {
    @unlink($safeTmp);
    json_error(500, $e->getMessage());
}

@unlink($safeTmp);
json_response(200, $movie);

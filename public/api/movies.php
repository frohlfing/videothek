<?php

/**
 * JSON-API für Filme und Serien (Feld "type": "movie" | "series"): Listen/Lesen, Anlegen, Bearbeiten (inkl.
 * Rename bei Titel-/Jahresänderung) und Löschen. Poster- und Hintergrundbild-Upload laufen separat über upload.php.
 *
 * GET    /api/movies.php            -> {"movies": [...]}, alle Filme und Serien, alphabetisch nach Titel sortiert.
 *                                       Kein Token nötig.
 * GET    /api/movies.php?slug=xyz   -> einzelner Eintrag. Kein Token nötig. 404, falls unbekannt.
 * POST   /api/movies.php            -> legt einen neuen Eintrag an. Body: JSON-Objekt mit title (Pflicht), type,
 *                                       originalTitle, year, runtime, fsk, genres, plot, trailer, cast, notes;
 *                                       Filme zusätzlich director, medium, files; Serien yearEnd, creator, seasons.
 *                                       Token nötig. 201 bei Erfolg.
 * PUT    /api/movies.php?slug=xyz   -> aktualisiert einen Eintrag (gleicher Body wie POST; ohne `type` bleibt die Art).
 *                                       Ändert sich der Slug (Titel/Jahr), wird der Ordner umbenannt. Token nötig.
 * DELETE /api/movies.php?slug=xyz   -> löscht den Eintrag samt Ordner (Bilder inklusive). Token nötig.
 *
 * Token wird per Header X-API-Token erwartet (siehe api/lib/http.php: require_token()).
 * Die eigentliche Lese-/Schreib-/Rename-/Delete-Logik steckt in api/lib/crud.php.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$method = $_SERVER['REQUEST_METHOD'];
$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;

try {
    switch ($method) {
        case 'GET':
            if ($slug !== null) {
                $movie = get_movie($slug);
                if ($movie === null) {
                    json_error(404, "Film '$slug' nicht gefunden");
                }
                json_response(200, $movie);
            }
            json_response(200, ['movies' => list_movies()]);

        case 'POST':
            require_token();
            $data = read_json_body();
            $movie = create_movie($data);
            json_response(201, $movie);

        case 'PUT':
            require_token();
            if ($slug === null) {
                json_error(400, 'slug-Parameter erforderlich');
            }
            $data = read_json_body();
            $movie = update_movie($slug, $data);
            json_response(200, $movie);

        case 'DELETE':
            require_token();
            if ($slug === null) {
                json_error(400, 'slug-Parameter erforderlich');
            }
            delete_movie($slug);
            json_response(200, ['deleted' => $slug]);

        default:
            json_error(405, "Methode '$method' nicht erlaubt");
    }
} catch (ValidationException $e) {
    json_response(400, ['error' => 'Validierung fehlgeschlagen', 'details' => $e->errors]);
} catch (NotFoundException $e) {
    json_error(404, $e->getMessage());
} catch (ConflictException $e) {
    json_error(409, $e->getMessage());
} catch (RuntimeException $e) {
    // Fängt u. a. fehlgeschlagenes mkdir()/rename() (create_movie()/update_movie()) und
    // fehlgeschlagenes unlink()/rmdir() (delete_movie()) ab, statt eines rohen PHP-Fatals mit
    // kaputtem JSON-Body (siehe Kommentar an remove_directory_recursive() in crud.php).
    json_error(500, $e->getMessage());
}

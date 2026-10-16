<?php

require_once __DIR__ . '/crud.php';
require_once __DIR__ . '/tmdb.php';

/**
 * Lädt fehlende Darstellerfotos eines Films von TMDB nach data/movies/<slug>/actors/<Vorname>_<Nachname>.jpg
 * (Kodi-Namensschema, aber nicht im versteckten ".actors") und trägt den Dateinamen als cast[].thumb in
 * movie.json ein. Arbeitet höchstens $timeBudget Sekunden, damit ein Request bei 50+ Darstellern nicht ins
 * PHP-Zeitlimit läuft – der Aufrufer wiederholt einfach, bis "remaining" 0 ist (siehe api/actors.php).
 * Darsteller ohne TMDB-Porträt (cast[].profile) werden übersprungen; ein fehlgeschlagener Download zählt
 * als "failed" und wird beim nächsten Aufruf nicht erneut versucht.
 *
 * @return array{movie: array, loaded: int, failed: int, remaining: int}
 * @throws NotFoundException
 */
function download_actor_photos(string $slug, float $timeBudget = 10.0): array
{
    $movie = read_movie_json($slug);
    if ($movie === null) {
        throw new NotFoundException("Film '$slug' nicht gefunden");
    }

    $dir = movie_dir($slug) . '/actors';
    $start = microtime(true);
    $loaded = 0;
    $failed = 0;

    foreach ($movie['cast'] as $i => $c) {
        if (!actor_photo_missing($c)) {
            continue;
        }
        if (microtime(true) - $start > $timeBudget) {
            break;
        }

        $fileName = actor_file_name($c['name']);
        if (!is_file($dir . '/' . $fileName)) {
            try {
                $data = http_get(tmdb_image_url($c['profile'], 'w185'));
                if (@getimagesizefromstring($data) === false) {
                    throw new RuntimeException('keine Bilddatei');
                }
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new RuntimeException('Ordner actors/ konnte nicht angelegt werden');
                }
                file_put_contents($dir . '/' . $fileName, $data);
            } catch (RuntimeException) {
                // Nicht nochmal versuchen: `profile` entfernen, sonst bliebe "remaining" ewig > 0.
                $movie['cast'][$i]['profile'] = null;
                $failed++;
                continue;
            }
        }
        $movie['cast'][$i]['thumb'] = $fileName;
        $loaded++;
    }

    write_movie_json($slug, $movie);

    return [
        'movie' => $movie,
        'loaded' => $loaded,
        'failed' => $failed,
        'remaining' => count(array_filter($movie['cast'], 'actor_photo_missing')),
    ];
}

/** Hat der Darsteller ein TMDB-Porträt, das noch nicht heruntergeladen wurde? */
function actor_photo_missing(array $c): bool
{
    return !empty($c['profile']) && empty($c['thumb']);
}

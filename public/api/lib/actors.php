<?php

require_once __DIR__ . '/crud.php';
require_once __DIR__ . '/tmdb.php';

/**
 * Lädt fehlende Darstellerfotos eines Films von TMDB nach data/movies/<slug>/actors/<Vorname>_<Nachname>.jpg
 * (Kodi-Namensschema, aber nicht im versteckten ".actors") und trägt den Dateinamen als cast[].thumb in
 * movie.json ein. Arbeitet höchstens $timeBudget Sekunden, damit ein Request bei 50+ Darstellern nicht ins
 * PHP-Zeitlimit läuft – der Aufrufer wiederholt einfach, bis "remaining" 0 ist (siehe api/actors.php).
 * Darsteller ohne TMDB-Porträt (cast[].profile) werden übersprungen; ein fehlgeschlagener Download zählt
 * als "failed" und wird beim nächsten Aufruf nicht erneut versucht. Bei einer Staffel wird ein Foto, das eine andere
 * Staffel derselben Serie schon hat, nur kopiert (nach dem Import einer Serie also nur einmal heruntergeladen).
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
    $siblings = $movie['type'] === TYPE_SEASON && isset($movie['series']['tmdb'])
        ? array_diff(seasons_by_series_tmdb_id($movie['series']['tmdb']), [$slug]) : [];
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
                if (copy_actor_photo_from($siblings, $fileName, $dir)) {
                    $movie['cast'][$i]['thumb'] = $fileName;
                    $loaded++;
                    continue;
                }
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

/** Kopiert actors/$fileName aus dem ersten der Einträge $slugs, der es hat, nach $dir. */
function copy_actor_photo_from(array $slugs, string $fileName, string $dir): bool
{
    foreach ($slugs as $other) {
        $src = movie_dir($other) . '/actors/' . $fileName;
        if (is_file($src)) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Ordner actors/ konnte nicht angelegt werden');
            }
            return copy($src, $dir . '/' . $fileName);
        }
    }
    return false;
}

/** Hat der Darsteller ein TMDB-Porträt, das noch nicht heruntergeladen wurde? */
function actor_photo_missing(array $c): bool
{
    return !empty($c['profile']) && empty($c['thumb']);
}

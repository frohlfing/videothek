<?php

/**
 * Wartungsskript: prüft die Trailer aller Filme bei YouTube (oEmbed, siehe api/lib/youtube.php) und
 * repariert tote Links. YouTube-Videos verschwinden immer wieder – daher gelegentlich laufen lassen.
 *
 *   php bin/check_trailers.php [--dry-run] [--only=<slug>]
 *
 * Pro Film:
 *   - Trailer vorhanden und abspielbar          -> bleibt
 *   - Trailer nicht abspielbar oder fehlt ganz  -> aktuellen Trailer von TMDB holen (über uniqueids.tmdb),
 *                                                  ebenfalls prüfen und eintragen; gibt es keinen
 *                                                  abspielbaren, wird ein toter Trailer entfernt
 *   - YouTube/TMDB nicht erreichbar             -> nichts ändern, im Bericht als "unklar"
 * Regionale Sperren erkennt oEmbed nicht (siehe youtube.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../public/api/lib/crud.php';
require_once __DIR__ . '/../public/api/lib/tmdb.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$only = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, strlen('--only='));
    }
}

$stats = ['ok' => 0, 'ersetzt' => 0, 'ergänzt' => 0, 'entfernt' => 0, 'weiter ohne' => 0, 'unklar' => 0];
$slugs = array_values(array_filter(list_movie_slugs(), fn($s) => $only === null || $s === $only));
sort($slugs);

foreach ($slugs as $i => $slug) {
    $movie = read_movie_json($slug);
    if ($movie === null) {
        continue;
    }
    $label = sprintf('[%3d/%d] %s (%s)', $i + 1, count($slugs), $movie['title'], $movie['year'] ?? '-');
    $old = $movie['trailer'] ?? null;

    if ($old !== null) {
        $available = youtube_video_available($old);
        if ($available === true) {
            $stats['ok']++;
            continue;
        }
        if ($available === null) {
            $stats['unklar']++;
            echo "$label: unklar (YouTube nicht erreichbar), unverändert\n";
            continue;
        }
    }

    $tmdbId = $movie['uniqueids']['tmdb'] ?? null;
    $new = null;
    if ($tmdbId !== null) {
        try {
            $new = tmdb_movie_trailer((int) $tmdbId);
        } catch (RuntimeException $e) {
            $stats['unklar']++;
            echo "$label: unklar (TMDB: {$e->getMessage()}), unverändert\n";
            continue;
        }
    }

    if ($new !== null && $new !== $old) {
        $key = $old !== null ? 'ersetzt' : 'ergänzt';
        echo "$label: $key -> $new\n";
    } elseif ($old !== null) {
        $key = 'entfernt';
        echo "$label: toter Trailer entfernt, kein abspielbarer bei TMDB" . ($tmdbId === null ? ' (keine TMDB-ID)' : '') . "\n";
    } else {
        $stats['weiter ohne']++;
        continue; // hatte keinen und bekommt keinen – kein Rauschen im Bericht
    }
    $stats[$key]++;

    if (!$dryRun) {
        $movie['trailer'] = $new;
        write_movie_json($slug, $movie);
    }
}

echo "\n== " . ($dryRun ? 'Probelauf' : 'Ergebnis') . " ==\n";
foreach ($stats as $k => $v) {
    echo mb_str_pad($k, 12) . ": $v\n";
}

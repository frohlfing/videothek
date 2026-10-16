<?php

require_once __DIR__ . '/crud.php';
require_once __DIR__ . '/tmdb.php';

/** Erneute Prüfung eines Trailers frühestens nach so vielen Tagen (siehe ensure_trailer()). */
const TRAILER_CHECK_MAX_AGE_DAYS = 7;

/**
 * Stellt sicher, dass der Trailer eines Films abspielbar ist, und repariert ihn bei Bedarf "on the fly":
 *   - zuletzt vor weniger als $maxAgeDays geprüft          -> nichts tun
 *   - Trailer vorhanden und laut YouTube abspielbar         -> nur Prüfdatum setzen
 *   - Trailer tot oder fehlt, Film hat eine TMDB-ID         -> aktuellen abspielbaren Trailer von TMDB eintragen
 *                                                              (gibt es keinen, wird ein toter entfernt)
 *   - YouTube/TMDB nicht erreichbar                        -> nichts ändern, beim nächsten Mal erneut versuchen
 * Das Prüfdatum steht als "trailerChecked" (YYYY-MM-DD) in movie.json. $maxAgeDays = 0 erzwingt die Prüfung,
 * $write = false prüft nur (Probelauf von bin/check_trailers.php).
 *
 * @return array{movie: array, status: string} status: fresh|ok|replaced|added|removed|none|unknown
 */
function ensure_trailer(string $slug, array $movie, int $maxAgeDays = TRAILER_CHECK_MAX_AGE_DAYS, bool $write = true): array
{
    $checked = $movie['trailerChecked'] ?? null;
    if ($maxAgeDays > 0 && $checked !== null && strtotime($checked) > strtotime("-$maxAgeDays days")) {
        return ['movie' => $movie, 'status' => 'fresh'];
    }

    $old = $movie['trailer'] ?? null;
    if ($old !== null) {
        $available = youtube_video_available($old);
        if ($available === null) {
            return ['movie' => $movie, 'status' => 'unknown'];
        }
        if ($available) {
            return ['movie' => save_trailer($slug, $movie, $old, $write), 'status' => 'ok'];
        }
    }

    $new = null;
    $tmdbId = $movie['uniqueids']['tmdb'] ?? null;
    if ($tmdbId !== null) {
        try {
            $new = tmdb_movie_trailer((int) $tmdbId);
        } catch (RuntimeException $e) {
            // 404: TMDB kennt die ID nicht (mehr) – endgültig, also wie "kein Trailer" behandeln, damit nicht bei
            // jedem Öffnen erneut nachgefragt wird. Alles andere (Netz, Drosselung) gilt als vorübergehend.
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                return ['movie' => $movie, 'status' => 'unknown'];
            }
        }
    }

    $status = match (true) {
        $new !== null && $old !== null => 'replaced',
        $new !== null => 'added',
        $old !== null => 'removed',
        default => 'none',
    };
    return ['movie' => save_trailer($slug, $movie, $new, $write), 'status' => $status];
}

/** Trailer + heutiges Prüfdatum setzen (und bei $write speichern); liefert den aktualisierten Film. */
function save_trailer(string $slug, array $movie, ?string $trailer, bool $write = true): array
{
    $movie['trailer'] = $trailer;
    $movie['trailerChecked'] = date('Y-m-d');
    if ($write) {
        write_movie_json($slug, $movie);
    }
    return order_movie_fields($movie);
}

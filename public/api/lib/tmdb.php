<?php

/**
 * Zugriff auf die TMDB-API (The Movie Database), angelehnt an den Kodi-Scraper
 * "The Movie Database Python" (xbmc-Repo: addons/metadata.themoviedb.org.python):
 * Suche per Titel (+ Jahr) oder IMDb-ID, danach Details in TMDB_LANGUAGE mit englischem
 * Fallback für eine fehlende Handlung.
 */

require_once __DIR__ . '/fetch.php';
require_once __DIR__ . '/youtube.php';

const TMDB_API_URL = 'https://api.themoviedb.org/3/';

// Kodi holt die Bild-Basis-URL über /configuration und cached sie 30 Tage; sie ist seit Jahren
// unverändert, daher hier fest verdrahtet. Größen siehe https://developer.themoviedb.org/docs/image-basics
const TMDB_IMAGE_URL = 'https://image.tmdb.org/t/p/';

/** "de-DE" -> "de" (Sprache für Bilder/Videos, die TMDB nur ohne Region kennt). */
function tmdb_language_code(): string
{
    return strtolower(explode('-', TMDB_LANGUAGE)[0]);
}

/** "de-DE" -> "DE" (Land für die Altersfreigabe). */
function tmdb_country_code(): string
{
    $parts = explode('-', TMDB_LANGUAGE);
    return strtoupper($parts[1] ?? $parts[0]);
}

/** Bild-URL in der gewünschten TMDB-Größe (z. B. "w185", "w780", "original"). */
function tmdb_image_url(string $path, string $size): string
{
    return TMDB_IMAGE_URL . $size . $path;
}

/**
 * GET auf einen TMDB-Endpunkt, dekodiertes JSON.
 * @throws RuntimeException bei Netzwerk-/HTTP-Fehler oder ungültigem JSON
 */
function tmdb_get(string $path, array $params = []): array
{
    $url = TMDB_API_URL . $path . '?' . http_build_query(['api_key' => TMDB_API_KEY] + $params);
    try {
        $body = http_get($url);
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'HTTP 401')) {
            throw new RuntimeException('TMDB hat den API-Key abgelehnt (TMDB_API_KEY in config.php prüfen)');
        }
        throw new RuntimeException('TMDB-Abruf fehlgeschlagen: ' . $e->getMessage());
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('TMDB lieferte keine gültige Antwort');
    }
    return $data;
}

/**
 * Sucht Filme. Eine IMDb-ID ("tt0089218") wird wie bei Kodi direkt über /find aufgelöst,
 * sonst Titelsuche (optional auf ein Jahr eingeschränkt).
 *
 * @return array<array{tmdb: int, title: string, originalTitle: ?string, year: ?int, overview: ?string, poster: ?string}>
 */
function tmdb_search(string $query, ?int $year = null): array
{
    $query = trim($query);
    if (preg_match('/^tt\d+$/i', $query)) {
        $data = tmdb_get('find/' . strtolower($query), ['external_source' => 'imdb_id', 'language' => TMDB_LANGUAGE]);
        $results = $data['movie_results'] ?? [];
    } else {
        $params = ['query' => $query, 'language' => TMDB_LANGUAGE, 'include_adult' => 'false'];
        if ($year !== null) {
            $params['year'] = $year;
        }
        $results = tmdb_get('search/movie', $params)['results'] ?? [];
    }

    // Wie bei Kodi: exakte Titeltreffer (und ggf. passendes Jahr) nach vorn, darin nach Popularität.
    $needle = mb_strtolower($query);
    $isBest = fn(array $r) => mb_strtolower((string) ($r['title'] ?? '')) === $needle
        || mb_strtolower((string) ($r['original_title'] ?? '')) === $needle;
    usort($results, fn(array $a, array $b) => [$isBest($b), $b['popularity'] ?? 0] <=> [$isBest($a), $a['popularity'] ?? 0]);

    return array_map(fn(array $r) => [
        'tmdb' => (int) $r['id'],
        'title' => (string) ($r['title'] ?? ''),
        'originalTitle' => ($r['original_title'] ?? '') !== ($r['title'] ?? '') ? ($r['original_title'] ?? null) : null,
        'year' => tmdb_year($r['release_date'] ?? null),
        'overview' => ($r['overview'] ?? '') !== '' ? $r['overview'] : null,
        'poster' => !empty($r['poster_path']) ? tmdb_image_url($r['poster_path'], 'w185') : null,
    ], $results);
}

/**
 * Lädt alle Details eines Films und bringt sie in unser movie.json-Format (ohne slug/created/Bilder).
 * Zusätzlich: poster_url/fanart_url zum Herunterladen.
 *
 * @throws RuntimeException
 */
function tmdb_movie(int $tmdbId): array
{
    $lang = tmdb_language_code();
    $m = tmdb_get("movie/$tmdbId", [
        'language' => TMDB_LANGUAGE,
        'append_to_response' => 'release_dates,credits,videos,images',
        // Ohne diese Parameter liefert TMDB nur Bilder/Videos in TMDB_LANGUAGE – für den englischen
        // Fallback (und textlose Hintergründe: "null") müssen sie explizit angefordert werden.
        'include_image_language' => "$lang,en,null",
        'include_video_language' => "$lang,en",
    ]);

    $plot = trim((string) ($m['overview'] ?? ''));
    if ($plot === '' && $lang !== 'en') {
        // Wie bei Kodi: fehlt die Übersetzung, die englische Handlung nehmen statt gar keiner.
        $plot = trim((string) (tmdb_get("movie/$tmdbId", ['language' => 'en-US'])['overview'] ?? ''));
    }

    $credits = $m['credits'] ?? [];
    $directors = array_values(array_unique(array_column(
        array_filter($credits['crew'] ?? [], fn($c) => ($c['job'] ?? '') === 'Director'),
        'name'
    )));
    $cast = $credits['cast'] ?? [];
    usort($cast, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

    $uniqueids = ['tmdb' => (string) $m['id']];
    if (!empty($m['imdb_id'])) {
        $uniqueids['imdb'] = $m['imdb_id'];
    }

    return [
        'title' => (string) $m['title'],
        'originalTitle' => ($m['original_title'] ?? '') !== $m['title'] ? ($m['original_title'] ?? null) : null,
        'year' => tmdb_year($m['release_date'] ?? null),
        'genres' => array_column($m['genres'] ?? [], 'name'),
        'runtime' => !empty($m['runtime']) ? (int) $m['runtime'] : null,
        'fsk' => tmdb_certification($m['release_dates']['results'] ?? []),
        'director' => $directors ? implode(', ', $directors) : null,
        'cast' => array_map(fn($c) => [
            'name' => (string) $c['name'],
            'role' => ($c['character'] ?? '') !== '' ? $c['character'] : null,
            // TMDB-Pfad des Porträts; heruntergeladen wird es danach von api/actors.php (-> thumb).
            'profile' => $c['profile_path'] ?? null,
        ], $cast),
        'plot' => $plot !== '' ? $plot : null,
        'trailer' => tmdb_trailer($m['videos']['results'] ?? []),
        'uniqueids' => $uniqueids,
        'poster_url' => tmdb_pick_image($m['images']['posters'] ?? [], [$lang, 'en', null], $m['poster_path'] ?? null, 'w780'),
        // Hintergrundbild bevorzugt ohne Text ("null"), wie Kodis "fanart".
        'fanart_url' => tmdb_pick_image($m['images']['backdrops'] ?? [], [null, $lang, 'en'], $m['backdrop_path'] ?? null, 'w1280'),
    ];
}

function tmdb_year(?string $date): ?int
{
    return ($date && preg_match('/^(\d{4})/', $date, $m)) ? (int) $m[1] : null;
}

/** Erste nicht-leere Altersfreigabe für das aus TMDB_LANGUAGE abgeleitete Land (DE -> FSK). */
function tmdb_certification(array $releaseDates): ?string
{
    foreach ($releaseDates as $country) {
        if (($country['iso_3166_1'] ?? '') !== tmdb_country_code()) {
            continue;
        }
        foreach ($country['release_dates'] ?? [] as $release) {
            $cert = trim((string) ($release['certification'] ?? ''));
            if ($cert !== '') {
                return $cert;
            }
        }
    }
    return null;
}

/**
 * YouTube-Trailer als URLs, beste zuerst: bevorzugt in TMDB_LANGUAGE, sonst englisch; "Trailer" vor
 * "Teaser"; innerhalb gleicher Priorität der offizielle bzw. zuerst gelistete.
 * @return string[]
 */
function tmdb_trailer_candidates(array $videos): array
{
    $scored = [];
    foreach ($videos as $i => $v) {
        if (($v['site'] ?? '') !== 'YouTube' || !in_array($v['type'] ?? '', ['Trailer', 'Teaser'], true)) {
            continue;
        }
        $score = (($v['iso_639_1'] ?? '') === tmdb_language_code() ? 4 : 0)
            + (($v['type'] ?? '') === 'Trailer' ? 2 : 0)
            + (!empty($v['official']) ? 1 : 0);
        $scored[] = [$score, $i, 'https://www.youtube.com/watch?v=' . $v['key']];
    }
    usort($scored, fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
    return array_values(array_unique(array_column($scored, 2)));
}

/**
 * Bester Trailer, der laut YouTube auch tatsächlich abspielbar ist (TMDB listet oft längst gelöschte
 * Videos). Null, wenn keiner verfügbar ist.
 */
function tmdb_trailer(array $videos): ?string
{
    return youtube_first_available(tmdb_trailer_candidates($videos));
}

/** Nur die Videos eines Films (für den Trailer-Abgleich ohne kompletten Detail-Abruf). */
function tmdb_movie_trailer(int $tmdbId): ?string
{
    $data = tmdb_get("movie/$tmdbId/videos", [
        'language' => TMDB_LANGUAGE,
        'include_video_language' => tmdb_language_code() . ',en',
    ]);
    return tmdb_trailer($data['results'] ?? []);
}

/** Erstes Bild in der Reihenfolge der gewünschten Sprachen (null = ohne Text), sonst $fallbackPath. */
function tmdb_pick_image(array $images, array $languages, ?string $fallbackPath, string $size): ?string
{
    foreach ($languages as $lang) {
        foreach ($images as $img) {
            if (($img['iso_639_1'] ?? null) === $lang && !str_ends_with($img['file_path'], '.svg')) {
                return tmdb_image_url($img['file_path'], $size);
            }
        }
    }
    return $fallbackPath ? tmdb_image_url($fallbackPath, $size) : null;
}

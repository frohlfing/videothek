<?php

/**
 * Zugriff auf die TMDB-API (The Movie Database), angelehnt an den Kodi-Scraper
 * "The Movie Database Python" (xbmc-Repo: addons/metadata.themoviedb.org.python) bzw. dessen Serien-Gegenstück:
 * Suche nach Filmen und Serien per Titel (+ Jahr) oder IMDb-ID, danach Details in TMDB_LANGUAGE mit englischem
 * Fallback für eine fehlende Handlung.
 */

require_once __DIR__ . '/crud.php'; // TYPE_MOVIE / TYPE_SERIES
require_once __DIR__ . '/fetch.php';
require_once __DIR__ . '/youtube.php';

/**
 * Basis-URL der TMDB-API (Version 3).
 *
 * @var string
 */
const TMDB_API_URL = 'https://api.themoviedb.org/3/';

/**
 * Basis-URL für Bilder. Kodi holt sie über /configuration und cached sie 30 Tage; sie ist seit Jahren
 * unverändert, daher hier fest verdrahtet. Größen siehe https://developer.themoviedb.org/docs/image-basics
 *
 * @var string
 */
const TMDB_IMAGE_URL = 'https://image.tmdb.org/t/p/';

/**
 * "de-DE" -> "de" (Sprache für Bilder/Videos, die TMDB nur ohne Region kennt).
 *
 * @return string Der zweistellige Sprachcode, z. B. "de"
 */
function tmdb_language_code(): string
{
    return strtolower(explode('-', TMDB_LANGUAGE)[0]);
}

/**
 * "de-DE" -> "DE" (Land für die Altersfreigabe).
 *
 * @return string Der Ländercode, z. B. "DE"
 */
function tmdb_country_code(): string
{
    $parts = explode('-', TMDB_LANGUAGE);
    return strtoupper($parts[1] ?? $parts[0]);
}

/**
 * Bild-URL in der gewünschten TMDB-Größe (z. B. "w185", "w780", "original").
 *
 * @param string $path Bildpfad aus der TMDB-Antwort (mit führendem "/")
 * @param string $size TMDB-Bildgröße
 * @return string Die vollständige URL
 */
function tmdb_image_url(string $path, string $size): string
{
    return TMDB_IMAGE_URL . $size . $path;
}

/**
 * GET auf einen TMDB-Endpunkt, dekodiertes JSON.
 *
 * @param string $path Endpunkt relativ zu TMDB_API_URL
 * @param array $params Zusätzliche Query-Parameter (der API-Key wird ergänzt)
 * @return array Die dekodierte JSON-Antwort
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
 * Sucht Filme und Serien. Eine IMDb-ID ("tt0089218", "tt0903747") wird wie bei Kodi direkt über /find
 * aufgelöst, sonst Titelsuche in beiden Katalogen (optional auf ein Jahr eingeschränkt, bei Serien das Jahr
 * der Erstausstrahlung).
 *
 * @param string $query Titel oder IMDb-ID
 * @param int|null $year Optionales Jahr zur Einschränkung der Titelsuche
 * @return array<array{type: string, tmdb: int, title: string, originalTitle: ?string, year: ?int, overview: ?string, poster: ?string}>
 */
function tmdb_search(string $query, ?int $year = null): array
{
    $query = trim($query);
    if (preg_match('/^tt\d+$/i', $query)) {
        $data = tmdb_get('find/' . strtolower($query), ['external_source' => 'imdb_id', 'language' => TMDB_LANGUAGE]);
        $found = [TYPE_MOVIE => $data['movie_results'] ?? [], TYPE_SERIES => $data['tv_results'] ?? []];
    } else {
        $params = ['query' => $query, 'language' => TMDB_LANGUAGE, 'include_adult' => 'false'];
        $found = [
            TYPE_MOVIE => tmdb_get('search/movie', $params + ($year !== null ? ['year' => $year] : []))['results'] ?? [],
            TYPE_SERIES => tmdb_get('search/tv', $params + ($year !== null ? ['first_air_date_year' => $year] : []))['results'] ?? [],
        ];
    }

    // Serien heißen bei TMDB "name"/"first_air_date" statt "title"/"release_date".
    $results = [];
    foreach ($found as $type => $list) {
        foreach ($list as $r) {
            $title = (string) ($r['title'] ?? $r['name'] ?? '');
            $original = $r['original_title'] ?? $r['original_name'] ?? null;
            $results[] = [
                'type' => $type,
                'tmdb' => (int) $r['id'],
                'title' => $title,
                'originalTitle' => $original !== null && $original !== $title ? $original : null,
                'year' => tmdb_year($r['release_date'] ?? $r['first_air_date'] ?? null),
                'overview' => ($r['overview'] ?? '') !== '' ? $r['overview'] : null,
                'poster' => !empty($r['poster_path']) ? tmdb_image_url($r['poster_path'], 'w185') : null,
                'popularity' => (float) ($r['popularity'] ?? 0),
            ];
        }
    }

    // Wie bei Kodi: exakte Titeltreffer nach vorn, darin (Filme und Serien gemischt) nach Popularität.
    $needle = mb_strtolower($query);
    $isBest = fn(array $r) => mb_strtolower($r['title']) === $needle || mb_strtolower((string) $r['originalTitle']) === $needle;
    usort($results, fn(array $a, array $b) => [$isBest($b), $b['popularity']] <=> [$isBest($a), $a['popularity']]);

    return array_map(function (array $r) {
        unset($r['popularity']);
        return $r;
    }, $results);
}

/**
 * Lädt alle Details eines Films und bringt sie in unser movie.json-Format (ohne slug/created/Bilder).
 * Zusätzlich: poster_url/fanart_url zum Herunterladen.
 *
 * @param int $tmdbId TMDB-ID des Films
 * @return array Der Film im movie.json-Format, zusätzlich mit poster_url und fanart_url
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
        // Wie bei Kodi: Fehlt die Übersetzung, die englische Handlung nehmen, statt gar keiner.
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
        'collection' => tmdb_collection($m),
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

/**
 * Reihe eines Films aus den TMDB-Details (belongs_to_collection), sonst null. Den Zusatz "Filmreihe", den TMDB an
 * fast jedem Namen hängt ("Millennium Filmreihe"), lassen wir weg: Reihe "Millennium".
 *
 * @param array $details TMDB-Details eines Films
 * @return ?array{name: string, tmdb: string}
 */
function tmdb_collection(array $details): ?array
{
    $c = $details['belongs_to_collection'] ?? null;
    $name = trim(preg_replace('/\s+Filmreihe$/iu', '', trim((string) ($c['name'] ?? ''))));
    if (!is_array($c) || empty($c['id']) || $name === '') {
        return null;
    }
    return ['name' => $name, 'tmdb' => (string) $c['id']];
}

/**
 * Darsteller einer Serie, höchstens so viele (die Hauptbesetzung zuerst). Lange Serien haben über die Staffeln
 * hinweg Hunderte Gastdarsteller (Breaking Bad: 348), deren Fotos niemand braucht.
 *
 * @var int
 */
const TMDB_SERIES_CAST_LIMIT = 50;

/**
 * TMDB erlaubt höchstens 20 Anhänge pro Abruf (append_to_response), die Staffeln werden daher gestückelt.
 *
 * @var int
 */
const TMDB_APPEND_LIMIT = 20;

/**
 * Titel, den TMDB bei fehlender Übersetzung einer Folge einsetzt ("Folge 3", englisch "Episode 3").
 *
 * @var string Regulärer Ausdruck
 */
const TMDB_PLACEHOLDER_EPISODE_TITLE = '/^(Folge|Episode|Épisode|Episodio|Aflevering) \d+$/u';

/**
 * Lädt alle Details einer Serie samt Staffeln und Folgen (Titel, Handlung) und bringt jede Staffel in unser
 * movie.json-Format (type "season", ohne slug/created/Bilder/Medium): {series: {name, tmdb}, seasons: [...]}.
 * Jede Staffel bekommt ihr eigenes Cover, ihre Beschreibung und ihr Jahr, soweit TMDB sie hat, sonst die der Serie;
 * Genres, Freigabe, Idee, Darsteller und Trailer sind die der Serie. Je Staffel zusätzlich poster_url/fanart_url
 * zum Herunterladen.
 *
 * @param int $tmdbId TMDB-ID der Serie
 * @param ?int[] $only nur diese Staffeln laden und liefern (null = alle); unbekannte Nummern fehlen im Ergebnis
 * @return array{series: array{name: string, tmdb: string}, seasons: array[]}
 * @throws RuntimeException
 */
function tmdb_series(int $tmdbId, ?array $only = null): array
{
    $lang = tmdb_language_code();
    $t = tmdb_get("tv/$tmdbId", [
        'language' => TMDB_LANGUAGE,
        'append_to_response' => 'content_ratings,aggregate_credits,videos,images,external_ids',
        'include_image_language' => "$lang,en,null",
        'include_video_language' => "$lang,en",
    ]);

    $plot = trim((string) ($t['overview'] ?? ''));
    if ($plot === '' && $lang !== 'en') {
        $plot = trim((string) (tmdb_get("tv/$tmdbId", ['language' => 'en-US'])['overview'] ?? ''));
    }

    $allNumbers = array_column($t['seasons'] ?? [], 'season_number');
    $numbers = $only === null ? $allNumbers : array_values(array_intersect($allNumbers, $only));
    $seasonData = tmdb_series_seasons($tmdbId, $numbers, TMDB_LANGUAGE);

    // Wie bei der Handlung: Fehlt die Übersetzung einer Folge, liefert TMDB den Platzhalter "Folge 3" und keine
    // Handlung – dann den englischen Titel bzw. die englische Handlung nehmen (nur für betroffene Staffeln).
    $placeholder = fn(?string $name) => (bool) preg_match(TMDB_PLACEHOLDER_EPISODE_TITLE, (string) $name);
    $untranslated = fn(array $e) => $placeholder($e['name'] ?? null) || trim((string) ($e['overview'] ?? '')) === '';
    $incomplete = array_values(array_filter($numbers, fn($n) => array_filter($seasonData[$n]['episodes'] ?? [], $untranslated)));
    if ($incomplete && $lang !== 'en') {
        $english = tmdb_series_seasons($tmdbId, $incomplete, 'en-US');
        foreach ($incomplete as $n) {
            $en = array_column($english[$n]['episodes'] ?? [], null, 'episode_number');
            foreach ($seasonData[$n]['episodes'] as &$e) {
                $alt = $en[$e['episode_number']] ?? [];
                if ($placeholder($e['name'] ?? null) && ($alt['name'] ?? '') !== '' && !$placeholder($alt['name'])) {
                    $e['name'] = $alt['name'];
                }
                if (trim((string) ($e['overview'] ?? '')) === '') {
                    $e['overview'] = $alt['overview'] ?? '';
                }
            }
            unset($e);
        }
    }

    // Laufzeit einer Folge: TMDB führt `episode_run_time` kaum noch, daher sonst der Median der Folgen.
    $median = function (array $values): ?int {
        sort($values);
        return $values ? $values[intdiv(count($values), 2)] : null;
    };
    $runtimes = [];
    foreach ($seasonData as $n => $data) {
        foreach ($data['episodes'] ?? [] as $e) {
            if (!empty($e['runtime'])) {
                $runtimes[$n][] = (int) $e['runtime'];
            }
        }
    }
    $seriesRuntime = !empty($t['episode_run_time'][0]) ? (int) $t['episode_run_time'][0]
        : $median(array_merge(...array_values(array_diff_key($runtimes, [0 => true]))));

    $cast = $t['aggregate_credits']['cast'] ?? [];
    usort($cast, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    $cast = array_map(function ($c) {
        // Hauptrolle = die mit den meisten Folgen.
        $roles = $c['roles'] ?? [];
        usort($roles, fn($a, $b) => ($b['episode_count'] ?? 0) <=> ($a['episode_count'] ?? 0));
        $role = trim((string) ($roles[0]['character'] ?? ''));
        return [
            'name' => (string) $c['name'],
            'role' => $role !== '' ? $role : null,
            'profile' => $c['profile_path'] ?? null,
        ];
    }, array_slice($cast, 0, TMDB_SERIES_CAST_LIMIT));

    $uniqueids = ['tmdb' => (string) $t['id']];
    if (!empty($t['external_ids']['imdb_id'])) {
        $uniqueids['imdb'] = $t['external_ids']['imdb_id'];
    }
    if (!empty($t['external_ids']['tvdb_id'])) {
        $uniqueids['tvdb'] = (string) $t['external_ids']['tvdb_id'];
    }
    $creators = array_values(array_unique(array_column($t['created_by'] ?? [], 'name')));
    $series = ['name' => (string) $t['name'], 'tmdb' => (string) $t['id']];
    $posterUrl = tmdb_pick_image($t['images']['posters'] ?? [], [$lang, 'en', null], $t['poster_path'] ?? null, 'w780');
    $fanartUrl = tmdb_pick_image($t['images']['backdrops'] ?? [], [null, $lang, 'en'], $t['backdrop_path'] ?? null, 'w1280');
    $regular = count(array_filter($allNumbers, fn($n) => $n > 0));
    $trailer = tmdb_trailer($t['videos']['results'] ?? []);

    $seasons = [];
    foreach ($t['seasons'] ?? [] as $s) {
        $n = (int) $s['season_number'];
        if (!in_array($n, $numbers, true)) {
            continue;
        }
        $episodes = [];
        foreach ($seasonData[$n]['episodes'] ?? [] as $e) {
            $episodes[] = [
                'number' => (int) $e['episode_number'],
                'title' => ($e['name'] ?? '') !== '' ? $e['name'] : null,
                'plot' => trim((string) ($e['overview'] ?? '')) !== '' ? trim($e['overview']) : null,
            ];
        }
        // Ohne Folgendetails (selten) wenigstens die Anzahl als unbenannte Folgen.
        if (!$episodes) {
            for ($i = 1; $i <= (int) ($s['episode_count'] ?? 0); $i++) {
                $episodes[] = ['number' => $i, 'title' => null, 'plot' => null];
            }
        }
        $overview = trim((string) ($seasonData[$n]['overview'] ?? $s['overview'] ?? ''));
        $seasons[] = [
            'type' => TYPE_SEASON,
            'title' => season_entry_title($series['name'], $s['name'] ?? null, $n, $regular === 1),
            'originalTitle' => ($t['original_name'] ?? '') !== $t['name'] ? ($t['original_name'] ?? null) : null,
            'year' => tmdb_year($s['air_date'] ?? null) ?? tmdb_year($t['first_air_date'] ?? null),
            'genres' => array_column($t['genres'] ?? [], 'name'),
            'runtime' => isset($runtimes[$n]) ? $median($runtimes[$n]) : $seriesRuntime,
            'fsk' => tmdb_tv_rating($t['content_ratings']['results'] ?? []),
            'series' => $series,
            'season' => $n,
            'creator' => $creators ? implode(', ', $creators) : null,
            'episodes' => $episodes,
            'cast' => $cast,
            'plot' => $overview !== '' ? $overview : ($plot !== '' ? $plot : null),
            'trailer' => $trailer,
            'uniqueids' => $uniqueids,
            'poster_url' => !empty($s['poster_path']) ? tmdb_image_url($s['poster_path'], 'w780') : $posterUrl,
            'fanart_url' => $fanartUrl,
        ];
    }

    return ['series' => $series, 'seasons' => $seasons, 'catalog' => tmdb_series_catalog($t)];
}

/**
 * Alle regulären Staffeln einer Serie laut TMDB (ohne Specials) für data/series.json, aus der Antwort von
 * tv/{id}, die tmdb_series() ohnehin abruft (kein zusätzlicher Abruf). `date` leer bzw. in der Zukunft = angekündigt.
 *
 * @param array $t Antwort von tv/{id}
 * @return array{name: string, seasons: array<array{season: int, title: ?string, year: ?int, date: ?string, episodes: ?int}>}
 */
function tmdb_series_catalog(array $t): array
{
    $seasons = [];
    foreach ($t['seasons'] ?? [] as $s) {
        $n = (int) ($s['season_number'] ?? 0);
        if ($n < 1) {
            continue;
        }
        $seasons[] = [
            'season' => $n,
            'title' => ($s['name'] ?? '') !== '' ? $s['name'] : null,
            'year' => tmdb_year($s['air_date'] ?? null),
            'date' => ($s['air_date'] ?? '') !== '' ? $s['air_date'] : null,
            'episodes' => isset($s['episode_count']) ? (int) $s['episode_count'] : null,
        ];
    }
    usort($seasons, fn($a, $b) => $a['season'] <=> $b['season']);
    return ['name' => (string) $t['name'], 'seasons' => $seasons];
}

/**
 * Alle Filme einer TMDB-Reihe (collection/{id}) für data/collections.json, nach Erscheinungsdatum (ohne Datum ans
 * Ende). `date` leer bzw. in der Zukunft = angekündigt.
 *
 * @param int $collectionId TMDB-ID der Reihe
 * @return array{name: string, parts: array<array{tmdb: string, title: string, year: ?int, date: ?string}>}
 * @throws RuntimeException
 */
function tmdb_collection_catalog(int $collectionId): array
{
    $c = tmdb_get("collection/$collectionId", ['language' => TMDB_LANGUAGE]);
    $parts = array_map(fn($p) => [
        'tmdb' => (string) $p['id'],
        'title' => (string) ($p['title'] ?? $p['original_title'] ?? ''),
        'year' => tmdb_year($p['release_date'] ?? null),
        'date' => ($p['release_date'] ?? '') !== '' ? $p['release_date'] : null,
    ], $c['parts'] ?? []);
    usort($parts, fn($a, $b) => [$a['date'] === null, $a['date']] <=> [$b['date'] === null, $b['date']]);
    $name = tmdb_collection(['belongs_to_collection' => ['id' => $collectionId, 'name' => $c['name'] ?? '']]);
    return ['name' => $name['name'] ?? (string) ($c['name'] ?? ''), 'parts' => $parts];
}

/**
 * Staffeldetails (inkl. Folgen) einer Serie in $language, je Abruf bis zu TMDB_APPEND_LIMIT Staffeln als
 * "season/<n>" angehängt statt eines Abrufs pro Staffel.
 *
 * @param int $tmdbId TMDB-ID der Serie
 * @param int[] $numbers Staffelnummern
 * @param string $language Sprachcode der Abfrage (z. B. "de-DE")
 * @return array<int, array> Staffelnummer => TMDB-Staffelobjekt (leer, falls TMDB es nicht liefert)
 */
function tmdb_series_seasons(int $tmdbId, array $numbers, string $language): array
{
    $result = [];
    foreach (array_chunk($numbers, TMDB_APPEND_LIMIT) as $chunk) {
        $data = tmdb_get("tv/$tmdbId", [
            'language' => $language,
            'append_to_response' => implode(',', array_map(fn($n) => "season/$n", $chunk)),
        ]);
        foreach ($chunk as $n) {
            $result[$n] = $data["season/$n"] ?? [];
        }
    }
    return $result;
}

/**
 * Altersfreigabe einer Serie für das aus TMDB_LANGUAGE abgeleitete Land (DE -> FSK).
 *
 * @param array $contentRatings content_ratings.results einer Serie
 * @return string|null Die Freigabe (z. B. "FSK 16"); null, wenn es keine gibt
 */
function tmdb_tv_rating(array $contentRatings): ?string
{
    foreach ($contentRatings as $r) {
        if (($r['iso_3166_1'] ?? '') === tmdb_country_code() && trim((string) ($r['rating'] ?? '')) !== '') {
            return trim($r['rating']);
        }
    }
    return null;
}

/**
 * Jahr eines TMDB-Datums.
 *
 * @param string|null $date Datum im Format "YYYY-MM-DD"
 * @return int|null Das Jahr; null, wenn das Datum fehlt oder ungültig ist
 */
function tmdb_year(?string $date): ?int
{
    return ($date && preg_match('/^(\d{4})/', $date, $m)) ? (int) $m[1] : null;
}

/**
 * Erste nicht-leere Altersfreigabe für das aus TMDB_LANGUAGE abgeleitete Land (DE -> FSK).
 *
 * @param array $releaseDates release_dates.results eines Films
 * @return string|null Die Freigabe (z. B. "16"); null, wenn es keine gibt
 */
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
 *
 * @param array $videos videos.results des Films bzw. der Serie
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
 *
 * @param array $videos videos.results des Films bzw. der Serie
 * @return string|null Die URL des Trailers; null, wenn keiner verfügbar ist
 */
function tmdb_trailer(array $videos): ?string
{
    return youtube_first_available(tmdb_trailer_candidates($videos));
}

/**
 * Erstes Bild in der Reihenfolge der gewünschten Sprachen (null = ohne Text), sonst $fallbackPath.
 *
 * @param array $images Bildobjekte aus der TMDB-Antwort (mit iso_639_1 und file_path)
 * @param array $languages Sprachcodes in Vorzugsreihenfolge (null = ohne Text)
 * @param string|null $fallbackPath Bildpfad, falls kein passendes Bild gefunden wird
 * @param string $size TMDB-Bildgröße
 * @return string|null Die Bild-URL; null, wenn es weder ein passendes Bild noch einen Fallback gibt
 */
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

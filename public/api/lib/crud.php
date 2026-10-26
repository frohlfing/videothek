<?php

require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/thumbnail.php';
require_once __DIR__ . '/image_store.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/locations.php';
require_once __DIR__ . '/wishes.php';
require_once __DIR__ . '/ratings.php';

class ValidationException extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}

/** Art eines Eintrags (Feld "type" in movie.json). Fehlt das Feld (ältere Dateien), ist es ein Film. */
const TYPE_MOVIE = 'movie';
/**
 * Staffel einer Serie: ein Eintrag wie ein Film (eigenes Cover, Medien, Handlung, Darsteller), dazu die
 * Serie (series, gruppiert wie die Reihe eines Films), die Staffelnummer (season), "Idee" (creator) und die Folgen.
 * Bei Staffeln ist year das Jahr der Staffel, runtime die Länge einer Folge, und uniqueids sind die IDs der Serie.
 */
const TYPE_SEASON = 'season';
const ENTRY_TYPES = [TYPE_MOVIE, TYPE_SEASON];
/**
 * Serie bei TMDB (Suche, Import). Außerdem die Art der alten Serien-Einträge mit allen Staffeln in seasons[]; die
 * Übersicht zeigt sie nicht mehr an (neu importieren und den alten Ordner löschen).
 */
const TYPE_SERIES = 'series';

/**
 * Medien, die Dateien sind (auf dem eigenen USB-Speicher): Sie haben "paths" (Dateien) statt eines Standorts und
 * werden nicht verliehen. Alle anderen (DVD, Blu-ray, …) sind Scheiben mit Standort (lib/locations.php).
 */
const DIGITAL_MEDIA = ['USB'];

/**
 * Eigene Einschätzung des Bilds bei DIGITAL_MEDIA ("quality" am Medium); hat in der App Vorrang vor der Beurteilung aus
 * "video" (app.js: mediumQuality()). Fehlt sie, beurteilt die App automatisch.
 */
const MEDIA_QUALITY = ['gut', 'mittel', 'schlecht'];

/**
 * Felder, die nur eine der beiden Arten hat. Wechselt ein Eintrag die Art, fallen die Felder der anderen Art beim
 * Speichern weg.
 */
const TYPE_ONLY_FIELDS = [
    TYPE_MOVIE => ['collection', 'director'],
    TYPE_SEASON => ['series', 'season', 'creator', 'episodes'],
];

/** Film-Slug existiert nicht. */
class NotFoundException extends RuntimeException
{
}

/** Ziel-Slug beim Rename ist bereits belegt. */
class ConflictException extends RuntimeException
{
}

/** Absoluter Pfad zum Ordner eines Films (existiert nicht zwingend). */
function movie_dir(string $slug): string
{
    return MOVIES_DIR . '/' . $slug;
}

/**
 * Liest und dekodiert movie.json eines Films bzw. einer Staffel. Null, wenn Ordner/Datei fehlt oder JSON ungültig ist.
 * Ältere Dateien kennen noch kein "type" (= Film) und statt "media" nur "medium" und "files" (Exemplare ohne
 * Eigentümer); beides wird hier ergänzt bzw. umgewandelt (legacy_media()), damit alle Aufrufer nur das aktuelle
 * Format sehen. Gespeichert wird es so beim nächsten Schreiben. Medien mit "files" (relativ zum Ordner des Eintrags,
 * bis Oktober 2026) bleiben, wie sie sind: Sie haben noch keine "paths".
 */
function read_movie_json(string $slug): ?array
{
    $path = movie_dir($slug) . '/movie.json';
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    if (!in_array($data['type'] ?? null, [...ENTRY_TYPES, TYPE_SERIES], true)) {
        $data = ['type' => TYPE_MOVIE] + $data;
    }
    if (!isset($data['media']) && (array_key_exists('medium', $data) || array_key_exists('files', $data))) {
        $data['media'] = legacy_media($data, owner_of(first_user() ?? current_user() ?? ''));
    }
    unset($data['medium'], $data['files']);
    return $data;
}

/**
 * Feldreihenfolge in movie.json: wie im Bearbeiten-Formular, danach die Verwaltungsfelder.
 * Bilder stehen dort, wo im Formular die Bildfelder sind.
 */
const MOVIE_FIELD_ORDER = [
    'type', 'title', 'originalTitle', 'year', 'runtime', 'fsk', 'genres', 'collection', 'series', 'season', 'director',
    'creator', 'media', 'episodes', 'poster', 'thumb', 'fanart', 'plot', 'trailer', 'cast', 'notes',
    'uniqueids', 'slug', 'created',
];

/**
 * Bringt die Felder in MOVIE_FIELD_ORDER. Felder, die nicht (mehr) zum Schema gehören (z. B. das
 * entfernte "location"), fallen dabei weg.
 */
function order_movie_fields(array $movie): array
{
    $ordered = [];
    foreach (MOVIE_FIELD_ORDER as $field) {
        if (array_key_exists($field, $movie)) {
            $ordered[$field] = $movie[$field];
        }
    }
    return $ordered;
}

/** Schreibt $movie als movie.json (überschreibt eine vorhandene Datei vollständig, Felder in MOVIE_FIELD_ORDER). */
function write_movie_json(string $slug, array $movie): void
{
    file_put_contents(
        movie_dir($slug) . '/movie.json',
        json_encode(order_movie_fields($movie), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
    );
}

/**
 * Alle Unterordner von MOVIES_DIR (= alle Slugs), unsortiert. Leeres Array, falls MOVIES_DIR
 * noch gar nicht existiert (frische Installation, bevor der erste Film angelegt wurde).
 * @return string[]
 */
function list_movie_slugs(): array
{
    if (!is_dir(MOVIES_DIR)) {
        return [];
    }
    $slugs = [];
    foreach (scandir(MOVIES_DIR) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..' && is_dir(MOVIES_DIR . '/' . $entry)) {
            $slugs[] = $entry;
        }
    }
    return $slugs;
}

/** Liest einen einzelnen Film und stellt dabei sicher, dass sein Thumbnail existiert (Lazy-Fallback). Null, wenn nicht gefunden. */
function get_movie(string $slug): ?array
{
    $movie = read_movie_json($slug);
    if ($movie === null) {
        return null;
    }
    return ensure_thumbnail(movie_dir($slug), $movie);
}

/**
 * @return array[] Alphabetisch nach Titel sortiert (das Frontend sortiert clientseitig ggf. um, siehe app.js).
 * Zusätzlich "thumbHash" (Prüfsumme des Thumbnails, nicht in movie.json): Damit zeigt die Kachel einer Serie ein
 * Cover, das mehrere Staffeln gemeinsam haben, nur einmal.
 */
function list_movies(): array
{
    $movies = [];
    foreach (list_movie_slugs() as $slug) {
        $movie = get_movie($slug);
        if ($movie !== null) {
            $thumb = !empty($movie['thumb']) ? movie_dir($slug) . '/' . $movie['thumb'] : null;
            $movie['thumbHash'] = $thumb && is_file($thumb) ? substr(md5_file($thumb), 0, 12) : null;
            $movies[] = $movie;
        }
    }
    usort($movies, fn(array $a, array $b) => strcasecmp((string) $a['title'], (string) $b['title']));
    return $movies;
}

/**
 * Prüft, ob es schon einen Eintrag derselben Art mit gleichem Titel (case-insensitiv) UND gleichem Jahr gibt.
 * Anders als beim Titel allein dürfen so Remakes ("Ben Hur" 1959 und 2016) nebeneinander existieren, ebenso ein
 * Film und eine gleichnamige Staffel. $excludeSlug schließt den eigenen Eintrag beim Update aus.
 */
function title_exists(string $title, ?int $year, string $type = TYPE_MOVIE, ?string $excludeSlug = null): bool
{
    $needle = mb_strtolower(trim($title));
    foreach (list_movie_slugs() as $slug) {
        if ($slug === $excludeSlug) {
            continue;
        }
        $m = read_movie_json($slug);
        if ($m !== null && $m['type'] === $type && mb_strtolower(trim($m['title'])) === $needle
            && ($m['year'] ?? null) === $year) {
            return true;
        }
    }
    return false;
}

/**
 * Reihe (collection), die schon einen Eintrag mit dieser TMDB-Collection-ID hat, oder null. Damit ein weiterer Teil
 * beim Import bzw. Nachtrag denselben (ggf. von Hand geänderten) Namen bekommt wie die vorhandenen.
 * @return ?array{name: string, tmdb: ?string}
 */
function find_collection_by_tmdb_id(string $tmdbId): ?array
{
    foreach (list_movie_slugs() as $slug) {
        $collection = read_movie_json($slug)['collection'] ?? null;
        if (is_array($collection) && ($collection['tmdb'] ?? null) === $tmdbId) {
            return $collection;
        }
    }
    return null;
}

/** Serie, die schon eine Staffel mit dieser TMDB-ID der Serie hat (Name ggf. von Hand geändert), oder null. */
function find_series_by_tmdb_id(string $tmdbId): ?array
{
    foreach (list_movie_slugs() as $slug) {
        $series = read_movie_json($slug)['series'] ?? null;
        if (is_array($series) && ($series['tmdb'] ?? null) === $tmdbId) {
            return $series;
        }
    }
    return null;
}

/**
 * Vorhandene Staffeln der Serie mit dieser TMDB-ID: [Staffelnummer => Slug].
 * @return array<int, string>
 */
function seasons_by_series_tmdb_id(string $tmdbId): array
{
    $result = [];
    foreach (list_movie_slugs() as $slug) {
        $m = read_movie_json($slug);
        if ($m !== null && $m['type'] === TYPE_SEASON && ($m['series']['tmdb'] ?? null) === $tmdbId && isset($m['season'])) {
            $result[$m['season']] = $slug;
        }
    }
    return $result;
}

/**
 * Slug einer anderen Staffel derselben Serie mit derselben Nummer oder null. Ohne Serie oder Nummer gibt es keine
 * Dopplung. Haben beide eine TMDB-ID der Serie, entscheidet sie: So dürfen DVDs, die TMDB als eigene Serien mit je
 * einer "Staffel 1" führt, unter einem gemeinsamen Seriennamen stehen (z. B. "Shaun das Schaf"). Sonst entscheidet
 * der Name (ohne Groß-/Kleinschreibung).
 */
function find_season_slug(array $fields, ?string $excludeSlug = null): ?string
{
    if (empty($fields['series']['name']) || ($fields['season'] ?? null) === null) {
        return null;
    }
    $name = mb_strtolower($fields['series']['name']);
    $tmdb = $fields['series']['tmdb'] ?? null;
    foreach (list_movie_slugs() as $slug) {
        if ($slug === $excludeSlug) {
            continue;
        }
        $m = read_movie_json($slug);
        if ($m === null || $m['type'] !== TYPE_SEASON || ($m['season'] ?? null) !== $fields['season']) {
            continue;
        }
        $otherTmdb = $m['series']['tmdb'] ?? null;
        $same = $tmdb !== null && $otherTmdb !== null
            ? $otherTmdb === $tmdb
            : mb_strtolower(trim($m['series']['name'] ?? '')) === $name;
        if ($same) {
            return $slug;
        }
    }
    return null;
}

/**
 * Slug des Eintrags mit dieser TMDB-ID oder null. TMDB zählt Filme und Serien getrennt (Film 1396 ist nicht
 * Serie 1396), deshalb gehört die Art dazu. Für Staffeln siehe find_season_slug(). $excludeSlug schließt den eigenen Eintrag beim Update aus.
 */
function find_slug_by_tmdb_id(string $tmdbId, string $type = TYPE_MOVIE, ?string $excludeSlug = null): ?string
{
    foreach (list_movie_slugs() as $slug) {
        if ($slug === $excludeSlug) {
            continue;
        }
        $m = read_movie_json($slug);
        if ($m !== null && $m['type'] === $type && ($m['uniqueids']['tmdb'] ?? '') === $tmdbId) {
            return $slug;
        }
    }
    return null;
}

/** "Film", "Staffel" bzw. "Serie" für Meldungen. */
function type_label(string $type): string
{
    return match ($type) {
        TYPE_SEASON => 'Staffel',
        TYPE_SERIES => 'Serie',
        default => 'Film',
    };
}

/** @return string[] Fehlermeldungen, leer wenn valide. */
function validate_movie_input(array $data): array
{
    $errors = [];

    if (trim((string) ($data['title'] ?? '')) === '') {
        $errors[] = 'title ist erforderlich';
    }
    if (isset($data['type']) && !in_array($data['type'], ENTRY_TYPES, true)) {
        $errors[] = 'type muss "' . implode('" oder "', ENTRY_TYPES) . '" sein';
    }
    array_push($errors, ...validate_media_input($data['media'] ?? null));
    foreach (['genres', 'cast'] as $field) {
        if (isset($data[$field]) && !is_array($data[$field])) {
            $errors[] = "$field muss ein Array sein";
        }
    }
    foreach ($data['cast'] ?? [] as $c) {
        if (!is_array($c) || trim((string) ($c['name'] ?? '')) === '') {
            $errors[] = 'cast: jeder Eintrag braucht ein Feld name';
            break;
        }
    }
    foreach (['collection', 'series'] as $field) {
        $group = $data[$field] ?? null;
        if ($group !== null && !is_string($group)
            && (!is_array($group) || !is_string($group['name'] ?? '') || !is_scalar($group['tmdb'] ?? ''))) {
            $errors[] = "$field muss ein Text oder ein Objekt {name, tmdb} sein";
        }
    }
    if (is_array($data['collection'] ?? null) && !valid_optional_int($data['collection']['part'] ?? null, 1, 999)) {
        $errors[] = 'collection.part (Teil) muss eine ganze Zahl zwischen 1 und 999 sein';
    }
    if (!empty($data['trailer']) && !preg_match('#^https?://#i', (string) $data['trailer'])) {
        $errors[] = 'trailer muss eine http(s)-URL sein';
    }
    if (isset($data['episodes'])) {
        array_push($errors, ...validate_episodes_input($data['episodes']));
    }
    foreach (['plot', 'notes'] as $field) {
        if (isset($data[$field]) && !is_string($data[$field])) {
            $errors[] = "$field muss ein Text sein";
        }
    }
    foreach (['year' => [1880, 2100], 'runtime' => [1, 1000], 'season' => [0, 999]] as $field => [$min, $max]) {
        if (!valid_optional_int($data[$field] ?? null, $min, $max)) {
            $errors[] = "$field muss eine ganze Zahl zwischen $min und $max sein";
        }
    }

    return $errors;
}

/** Leer/null oder eine ganze Zahl im Bereich $min..$max? */
function valid_optional_int(mixed $value, int $min, int $max): bool
{
    return $value === null || $value === ''
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) !== false;
}

/**
 * Prüft die Medien: Liste von {owner, type, paths?}, je Eigentümer und pro Art höchstens einmal. Dass bei USB
 * Dateien eingetragen sind, prüft das Formular (app.js: saveMovie()), nicht die API: Ein Import bzw. ein Freund kann
 * das Medium auch erst eintragen und die Dateien später.
 * @return string[]
 */
function validate_media_input(mixed $media): array
{
    if ($media === null) {
        return [];
    }
    if (!is_array($media) || !array_is_list($media)) {
        return ['media muss ein Array sein'];
    }
    $errors = [];
    $seen = [];
    foreach ($media as $m) {
        if (!is_array($m) || !is_string($m['owner'] ?? null) || !is_string($m['type'] ?? null) || trim($m['type']) === '') {
            $errors[] = 'Jedes Medium braucht die Form {owner, type, paths}';
            break;
        }
        $owner = trim($m['owner']);
        if (!preg_match(USER_LOGIN_PATTERN, $owner)) {
            $errors[] = "Eigentümer '$owner' ist kein gültiger Benutzername";
        }
        $key = medium_key($owner, trim($m['type']));
        if (isset($seen[$key])) {
            $errors[] = "Medium $key ist doppelt eingetragen";
        }
        $seen[$key] = true;
        array_push($errors, ...validate_paths_input($m['paths'] ?? null, "$key: paths"));
    }
    return $errors;
}

/**
 * Prüft eine Dateiliste (paths eines Mediums): Array relativer Pfade ohne Laufwerk und "..".
 * @return string[]
 */
function validate_paths_input(mixed $paths, string $label): array
{
    if ($paths === null) {
        return [];
    }
    if (!is_array($paths)) {
        return ["$label muss ein Array sein"];
    }
    $errors = [];
    foreach (normalize_media_paths($paths) as $f) {
        if (preg_match('#^([a-z]:|/)#i', $f) || in_array('..', explode('/', $f), true)) {
            $errors[] = "Datei '$f' muss relativ zum USB-Ordner angegeben werden (ohne Laufwerk und ohne '..')";
        }
    }
    return $errors;
}

/**
 * Prüft die Folgen einer Staffel: Liste von {number?, title?, plot?}, jede Nummer höchstens einmal.
 * @return string[]
 */
function validate_episodes_input(mixed $episodes): array
{
    if (!is_array($episodes) || !array_is_list($episodes)) {
        return ['episodes muss ein Array sein'];
    }
    $errors = [];
    $numbers = [];
    foreach ($episodes as $e) {
        if (!is_array($e) || !valid_optional_int($e['number'] ?? null, 0, 9999)
            || (isset($e['title']) && !is_string($e['title'])) || (isset($e['plot']) && !is_string($e['plot']))) {
            $errors[] = 'Jede Folge braucht die Form {number, title, plot}';
            break;
        }
        if (($e['number'] ?? '') !== '' && $e['number'] !== null) {
            if (in_array((int) $e['number'], $numbers, true)) {
                $errors[] = "Folge {$e['number']} ist doppelt vorhanden";
            }
            $numbers[] = (int) $e['number'];
        }
    }
    return $errors;
}

/**
 * Dateien eines Mediums USB relativ zum USB-Ordner, z. B. ["hangover/hangover-2009.mp4"]; bei mehrteiligen Filmen
 * mehrere in Abspielreihenfolge, bei Staffeln die Folgen. Die Struktur ist frei; der Vorschlag fürs Formular und für
 * `bin/convert.php` macht `usb_base_path()`. Gespeichert immer mit "/" (app.js: usbPath() wandelt für Windows um).
 * @return string[]
 */
function normalize_media_paths(array $paths): array
{
    return array_values(array_filter(array_map(
        fn($f) => trim(str_replace('\\', '/', trim((string) $f)), '/'),
        $paths
    ), 'strlen'));
}

/**
 * Vorgeschlagener Pfad (relativ zum USB-Ordner, ohne Endung) für die Datei eines Eintrags, gleiche Regel wie
 * suggestFiles() in app.js:
 *   Film ohne Reihe   <titel>/<slug>                            bruchreif/bruchreif-2009
 *   Film einer Reihe  <reihe>/<slug>                            hangover/hangover-2009
 *   Folge             <serie>/<slug>/<serie>-s<staffel>e<folge>  scrubs-die-anfaenger/scrubs-die-anfaenger-staffel-2-2002/scrubs-die-anfaenger-s02e01
 *
 * Die erste Ebene (Titel, Reihe, Serie) sortiert im Dateiexplorer Filme und Serien gemeinsam alphabetisch. Mehrere
 * Dateien eines Films bzw. einer Folge bekommen -cd1, -cd2, … angehängt. "S02E01" erkennen Player und Mediaserver
 * als Folge, file_episode_number() als Folgennummer.
 */
function usb_base_path(array $movie, ?int $episode = null): string
{
    $slug = $movie['slug'];
    if (($movie['type'] ?? TYPE_MOVIE) !== TYPE_SEASON) {
        $folder = slugify($movie['collection']['name'] ?? '') ?: slugify($movie['title'] ?? '') ?: $slug;
        return "$folder/$slug";
    }
    $series = slugify($movie['series']['name'] ?? '') ?: $slug;
    $season = $movie['season'] ?? null;
    $code = ($season !== null ? sprintf('s%02d', $season) : '') . sprintf('e%02d', $episode ?? 1);
    return "$series/$slug/$series-$code";
}

/**
 * Medien eines Eintrags: Wem welches Exemplar gehört, z. B. [{"owner": "lilie", "type": "DVD"}, {"owner": "frank",
 * "type": "USB", "paths": ["bruchreif/bruchreif-2009.mp4"], "video": {…}, "audio": {…}}]. Leer = nicht im Bestand.
 * Dateien (und video, audio, siehe normalize_video(), normalize_audio()) gibt es nur bei DIGITAL_MEDIA; doppelte Medien
 * (gleicher Eigentümer und gleiche Art) fallen weg.
 * @return array<array{owner: string, type: string, paths?: string[], video?: array, audio?: array}>
 */
function normalize_media(array $media): array
{
    $result = [];
    foreach ($media as $m) {
        $owner = trim((string) ($m['owner'] ?? ''));
        $type = trim((string) ($m['type'] ?? ''));
        $key = medium_key($owner, $type);
        if ($owner === '' || $type === '' || isset($result[$key])) {
            continue;
        }
        $result[$key] = in_array($type, DIGITAL_MEDIA, true)
            ? ['owner' => $owner, 'type' => $type, 'paths' => normalize_media_paths($m['paths'] ?? [])]
                + array_filter(['video' => normalize_video($m['video'] ?? null), 'audio' => normalize_audio($m['audio'] ?? null),
                    'quality' => in_array($m['quality'] ?? null, MEDIA_QUALITY, true) ? $m['quality'] : null])
            : ['owner' => $owner, 'type' => $type];
    }
    return array_values($result);
}

/**
 * Technische Angaben zum Bild der Datei auf dem USB-Speicher (bei mehreren Dateien die erste), eingetragen von
 * bin/convert.php: {codec, width, height, sar, fps, kbps}, z. B. {"codec": "h264", "width": 672, "height": 272,
 * "sar": "1:1", "fps": 25, "kbps": 635}. codec ist der Name bei ffprobe (h264, hevc, mpeg4 = XviD/DivX, …), sar das
 * Seitenverhältnis eines Bildpunkts, kbps die Video-Bitrate. Die Beurteilung macht die App (app.js: videoQuality()).
 * Ungültig bzw. ohne Breite und Höhe: null.
 * @return ?array{codec: string, width: int, height: int, sar: string, fps: ?float, kbps: ?int}
 */
function normalize_video(mixed $video): ?array
{
    if (!is_array($video) || (int) ($video['width'] ?? 0) <= 0 || (int) ($video['height'] ?? 0) <= 0) {
        return null;
    }
    $sar = preg_match('/^\d+:\d+$/', (string) ($video['sar'] ?? '')) && !str_starts_with((string) $video['sar'], '0') ? $video['sar'] : '1:1';
    return [
        'codec' => strtolower(trim((string) ($video['codec'] ?? ''))),
        'width' => (int) $video['width'],
        'height' => (int) $video['height'],
        'sar' => $sar,
        'fps' => is_numeric($video['fps'] ?? null) && $video['fps'] > 0 ? round((float) $video['fps'], 3) : null,
        'kbps' => is_numeric($video['kbps'] ?? null) && $video['kbps'] > 0 ? (int) $video['kbps'] : null,
    ];
}

/**
 * Technische Angaben zur (ersten) Tonspur der Datei auf dem USB-Speicher, eingetragen von bin/convert.php:
 * {codec, channels, kbps, lang}, z. B. {"codec": "aac", "channels": 6, "kbps": 384, "lang": "ger"}. codec ist der Name
 * bei ffprobe (aac, mp3, …), lang der Sprachcode (ger, eng, und = unbekannt). Ungültig: null.
 * @return ?array{codec: string, channels: ?int, kbps: ?int, lang: string}
 */
function normalize_audio(mixed $audio): ?array
{
    if (!is_array($audio) || trim((string) ($audio['codec'] ?? '')) === '') {
        return null;
    }
    return [
        'codec' => strtolower(trim((string) $audio['codec'])),
        'channels' => is_numeric($audio['channels'] ?? null) && $audio['channels'] > 0 ? (int) $audio['channels'] : null,
        'kbps' => is_numeric($audio['kbps'] ?? null) && $audio['kbps'] > 0 ? (int) $audio['kbps'] : null,
        'lang' => strtolower(trim((string) ($audio['lang'] ?? ''))) ?: 'und',
    ];
}

/**
 * Das Formular kennt "video" und "audio" nicht: Beim Speichern bleiben sie von einem Medium mit gleichem Eigentümer,
 * gleicher Art und gleichen Dateien erhalten.
 */
function keep_media_facts(array $media, array $before): array
{
    foreach ($media as &$m) {
        foreach ($before as $old) {
            if (isset($m['paths']) && $old['owner'] === $m['owner'] && $old['type'] === $m['type']
                && ($old['paths'] ?? null) === $m['paths']) {
                foreach (['video', 'audio'] as $key) {
                    if (!isset($m[$key]) && isset($old[$key])) {
                        $m[$key] = $old[$key];
                    }
                }
            }
        }
    }
    return $media;
}

/**
 * Medien aus dem alten Format: "medium" (Liste oder Text, auch kommagetrennt "DVD, USB") und "files" relativ zum
 * USB-Ordner ("<slug>/movie.avi"), alles von $owner.
 */
function legacy_media(array $movie, string $owner): array
{
    $types = $movie['medium'] ?? [];
    $types = is_array($types) ? $types : explode(',', (string) $types);
    $paths = normalize_media_paths($movie['files'] ?? []);
    $media = array_map(fn($t) => ['owner' => $owner, 'type' => trim((string) $t), 'paths' => $paths], $types);
    return normalize_media($media);
}

/**
 * Reihe, zu der ein Film gehört (z. B. die Matrix-Filme): {name, tmdb, part} mit der TMDB-Collection-ID (nur bei
 * Import aus TMDB, sonst null) und der Nummer des Films in der Reihe (part, von Hand, sonst null: dann zählt das
 * Erscheinungsjahr). Ebenso die Serie einer Staffel ($withPart = false: {name, tmdb} mit der TMDB-ID der Serie, die
 * Nummer steht dort in "season"). Akzeptiert auch nur den Namen als String. Ohne Namen: null.
 * @return ?array{name: string, tmdb: ?string, part?: ?int}
 */
function normalize_collection(mixed $collection, bool $withPart = true): ?array
{
    if (is_string($collection)) {
        $collection = ['name' => $collection];
    }
    if (!is_array($collection) || ($name = nullable_string($collection['name'] ?? null)) === null) {
        return null;
    }
    $result = ['name' => $name, 'tmdb' => nullable_string($collection['tmdb'] ?? null)];
    return $withPart ? $result + ['part' => nullable_int($collection['part'] ?? null)] : $result;
}

/** Leerer/fehlender Wert -> null, sonst getrimmter String. */
function nullable_string(mixed $value): ?string
{
    $s = trim((string) ($value ?? ''));
    return $s !== '' ? $s : null;
}

/** Leerer/fehlender Wert -> null, sonst int (Wertebereich prüft validate_movie_input()). */
function nullable_int(mixed $value): ?int
{
    return ($value === null || $value === '') ? null : (int) $value;
}

/**
 * Baut aus rohen Request-Daten die vom Store verwalteten Felder, mit Defaults/Typumwandlung. Je nach Art
 * (type) kommen die Film- bzw. die Staffelfelder dazu (TYPE_ONLY_FIELDS).
 * Nicht enthalten (und damit beim Update unverändert aus movie.json übernommen): slug, created,
 * uniqueids und die Bilddateien poster/thumb/fanart.
 */
function normalize_movie_fields(array $data): array
{
    $type = in_array($data['type'] ?? null, ENTRY_TYPES, true) ? $data['type'] : TYPE_MOVIE;
    $common = [
        'type' => $type,
        'title' => trim((string) ($data['title'] ?? '')),
        'originalTitle' => nullable_string($data['originalTitle'] ?? null),
        'year' => nullable_int($data['year'] ?? null),
        'genres' => array_values(array_unique(array_filter(array_map(fn($g) => trim((string) $g), $data['genres'] ?? []), 'strlen'))),
        'runtime' => nullable_int($data['runtime'] ?? null),
        'fsk' => nullable_string($data['fsk'] ?? null),
        'cast' => array_map(fn(array $c) => [
            'name' => trim((string) $c['name']),
            'role' => nullable_string($c['role'] ?? null),
            'profile' => nullable_string($c['profile'] ?? null),
            'thumb' => actor_photo_name($c['thumb'] ?? null),
        ], array_values($data['cast'] ?? [])),
        'trailer' => nullable_string($data['trailer'] ?? null),
        'plot' => nullable_string($data['plot'] ?? null),
        'notes' => nullable_string($data['notes'] ?? null),
        'media' => normalize_media($data['media'] ?? []),
    ];
    if ($type === TYPE_SEASON) {
        return $common + [
            'series' => normalize_collection($data['series'] ?? null, false),
            'season' => nullable_int($data['season'] ?? null),
            'creator' => nullable_string($data['creator'] ?? null),
            'episodes' => normalize_episodes($data['episodes'] ?? []),
        ];
    }
    return $common + [
        'collection' => normalize_collection($data['collection'] ?? null),
        'director' => nullable_string($data['director'] ?? null),
    ];
}

/**
 * Folgen einer Staffel, nach Nummer sortiert. Fehlt die Nummer, zählt die Position (1, 2, …). Die Handlung enthält
 * keine Leerzeilen (episode_plot()): Im Formular trennt eine Leerzeile die Folgen.
 * @return array<array{number: int, title: ?string, plot: ?string}>
 */
function normalize_episodes(array $episodes): array
{
    $result = [];
    foreach (array_values($episodes) as $i => $e) {
        $number = nullable_int($e['number'] ?? null) ?? $i + 1;
        $result[$number] = [
            'number' => $number,
            'title' => nullable_string($e['title'] ?? null),
            'plot' => episode_plot($e['plot'] ?? null),
        ];
    }
    ksort($result);
    return array_values($result);
}

/** Handlung einer Folge ohne Leerzeilen (auch für den Import aus TMDB), leer -> null. */
function episode_plot(mixed $plot): ?string
{
    $plot = nullable_string($plot);
    return $plot === null ? null : preg_replace("/\n(?:[ \t]*\n)+/", "\n", str_replace("\r", '', $plot));
}

/**
 * Folgennummer im Dateinamen ("serie-s01e03", "S01E03", "E03", "e3", "episode-03") oder null. Gegenstück in app.js:
 * episodeFiles().
 */
function file_episode_number(string $file): ?int
{
    return preg_match('/(?:^|[^a-z0-9])(?:s\d+)?e(?:pisode[\s._-]*)?0*(\d+)(?!\d)/i', basename($file), $m) ? (int) $m[1] : null;
}

/** Dateiname eines Darstellerfotos unter actors/ (ohne Pfad, Bildendung) oder null; alles andere wird verworfen. */
function actor_photo_name(mixed $name): ?string
{
    return is_string($name) && $name === basename($name) && !str_starts_with($name, '.')
        && preg_match('/\.(jpe?g|png|webp)$/i', $name) ? $name : null;
}

/** Löscht Darstellerfotos unter actors/, auf die kein Darsteller in $movie mehr verweist. */
function remove_unused_actor_photos(string $slug, array $movie): void
{
    $used = array_filter(array_column($movie['cast'] ?? [], 'thumb'));
    foreach (glob(movie_dir($slug) . '/actors/*') ?: [] as $file) {
        if (!in_array(basename($file), $used, true)) {
            @unlink($file);
        }
    }
}

/**
 * Dateiname eines Darstellerfotos wie bei Kodi (.actors/Mark_Strong.jpg): Leerzeichen -> "_",
 * in Dateinamen unzulässige Zeichen entfernt. Liegt unter data/movies/<slug>/actors/.
 */
function actor_file_name(string $name): string
{
    $s = preg_replace('#[\\\\/:*?"<>|\x00-\x1f]#u', '', trim($name));
    return str_replace(' ', '_', $s) . '.jpg';
}

/**
 * Titel einer Staffel als eigener Eintrag: "Die Tudors – Staffel 1" bzw. mit dem Namen der Staffel
 * ("Die Tudors – Mätresse des Königs"); Specials (Nummer 0) heißen "Specials". Hat die Serie nur eine reguläre
 * Staffel ($only, z. B. eine DVD, die TMDB als eigene Serie führt), heißt die Staffel wie die Serie.
 */
function season_entry_title(string $series, ?string $name, int $number, bool $only = false): string
{
    $name = trim((string) $name);
    if ($name === '' || preg_match('/^(Staffel|Season)\s+\d+$/iu', $name)) {
        $name = $number === 0 ? 'Specials' : "Staffel $number";
    }
    return $only && $number > 0 ? $series : "$series – $name";
}

/**
 * Slug aus Titel + Jahr, damit Remakes mit gleichem Titel sprechende, unterschiedliche Ordnernamen
 * bekommen ("ben-hur-1959", "ben-hur-2016") statt "ben-hur" und "ben-hur-2".
 */
function movie_base_slug(array $fields): string
{
    return slugify($fields['title'] . ($fields['year'] !== null ? ' ' . $fields['year'] : ''));
}

/**
 * Legt einen neuen Film bzw. eine neue Staffel an: validiert, vergibt Slug/created, erstellt den Ordner und schreibt movie.json.
 * $uniqueids (z. B. ["tmdb" => "9340", "imdb" => "tt0089218"]) setzt nur der Import.
 * @throws ValidationException
 */
function create_movie(array $data, array $uniqueids = []): array
{
    $errors = validate_movie_input($data);
    if ($errors) {
        throw new ValidationException($errors);
    }

    $fields = normalize_movie_fields($data);
    $label = type_label($fields['type']);
    // Staffeln teilen sich die TMDB-ID ihrer Serie, sie unterscheiden sich erst durch die Nummer.
    $existing = $fields['type'] === TYPE_SEASON
        ? find_season_slug($fields)
        : (isset($uniqueids['tmdb']) ? find_slug_by_tmdb_id((string) $uniqueids['tmdb'], $fields['type']) : null);
    if ($existing !== null) {
        throw new ValidationException(["$label bereits vorhanden ($existing)"]);
    }
    if (title_exists($fields['title'], $fields['year'], $fields['type'])) {
        throw new ValidationException(["$label mit diesem Titel und Jahr bereits vorhanden"]);
    }

    $baseSlug = movie_base_slug($fields);
    if ($baseSlug === '') {
        throw new ValidationException(['Titel ergibt keinen gültigen Ordnernamen']);
    }
    $slug = unique_slug($baseSlug);

    if (!mkdir(movie_dir($slug), 0775, true) && !is_dir(movie_dir($slug))) {
        throw new RuntimeException('Filmordner konnte nicht angelegt werden');
    }

    $movie = order_movie_fields(array_merge($fields, [
        'uniqueids' => $uniqueids,
        'slug' => $slug,
        'created' => date('c'),
        'poster' => null,
        'thumb' => null,
        'fanart' => null,
    ]));

    write_movie_json($slug, $movie);
    return $movie;
}

/**
 * Aktualisiert einen Film bzw. eine Staffel (auch einen Wechsel der Art). Ändert sich Titel oder Jahr so, dass der Slug wechselt, wird der
 * Ordner atomar umbenannt – bei Fehlschlag bleibt der alte Zustand vollständig erhalten.
 *
 * @throws NotFoundException|ValidationException|ConflictException
 */
function update_movie(string $slug, array $data): array
{
    $existing = read_movie_json($slug);
    if ($existing === null) {
        throw new NotFoundException("Film '$slug' nicht gefunden");
    }

    // Ohne "type" bleibt die bisherige Art (eine Staffel wird nicht stillschweigend zum Film).
    $data += ['type' => $existing['type']];
    $errors = validate_movie_input($data);
    if ($errors) {
        throw new ValidationException($errors);
    }

    // Das Formular schickt alles mit, was es zeigt (auch Handlung der Folgen, Foto- und Cover-Dateinamen). Was
    // fehlt, ist gewollt leer; nicht mehr benutzte Bilder werden unten aufgeräumt.
    $fields = normalize_movie_fields($data);
    $fields['media'] = keep_media_facts($fields['media'], $existing['media'] ?? []);
    if (title_exists($fields['title'], $fields['year'], $fields['type'], $slug)) {
        throw new ValidationException([type_label($fields['type']) . ' mit diesem Titel und Jahr bereits vorhanden']);
    }
    if (($other = find_season_slug($fields, $slug)) !== null) {
        throw new ValidationException(["Diese Staffel der Serie gibt es schon ($other)"]);
    }

    $newBaseSlug = movie_base_slug($fields);
    if ($newBaseSlug === '') {
        throw new ValidationException(['Titel ergibt keinen gültigen Ordnernamen']);
    }

    $newSlug = $newBaseSlug === $slug ? $slug : unique_slug($newBaseSlug, $slug);

    if ($newSlug !== $slug) {
        $oldDir = movie_dir($slug);
        $newDir = movie_dir($newSlug);
        if (is_dir($newDir)) {
            throw new ConflictException("Zielordner '$newSlug' existiert bereits");
        }
        if (!rename($oldDir, $newDir)) {
            throw new RuntimeException('Umbenennen des Filmordners fehlgeschlagen');
        }
    }

    $movie = array_merge($existing, $fields, ['slug' => $newSlug]);
    // Art gewechselt (Film <-> Staffel): Felder der anderen Art nicht mitschleppen.
    foreach (TYPE_ONLY_FIELDS as $type => $typeFields) {
        if ($type !== $fields['type']) {
            foreach ($typeFields as $field) {
                unset($movie[$field]);
            }
        }
    }
    $movie = order_movie_fields($movie);
    write_movie_json($newSlug, $movie);
    remove_unused_actor_photos($newSlug, $movie);
    move_locations($slug, $newSlug, $movie['media']);
    move_wishes($slug, $newSlug);
    move_ratings($slug, $newSlug);
    return $movie;
}

/** Entfernt den kompletten Filmordner (movie.json + Bilder verschwinden automatisch mit). @throws NotFoundException|RuntimeException */
function delete_movie(string $slug): void
{
    $dir = movie_dir($slug);
    if (!is_dir($dir)) {
        throw new NotFoundException("Film '$slug' nicht gefunden");
    }
    remove_directory_recursive($dir);
    move_locations($slug, null);
    move_wishes($slug, null);
    move_ratings($slug, null);
}

/**
 * Löscht einen Ordner samt Inhalt rekursiv (PHP hat dafür keine eingebaute Funktion).
 * Prüft die Rückgabewerte von unlink()/rmdir() bewusst: ohne diese Prüfung blieb der Ordner unter
 * Windows bei einem kurzzeitigen Datei-Lock (z. B. Windows Search/Virenscanner öffnet gerade
 * thumb.jpg) real bestehen, während die API trotzdem Erfolg meldete – Einträge "verschwanden" aus
 * der Liste beim nächsten Request nicht wirklich, sondern tauchten wieder auf.
 *
 * @throws RuntimeException wenn eine Datei oder der Ordner selbst nicht gelöscht werden konnte
 */
function remove_directory_recursive(string $dir): void
{
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            remove_directory_recursive($path);
        } elseif (!unlink($path)) {
            throw new RuntimeException("Datei konnte nicht gelöscht werden: $path");
        }
    }
    if (!rmdir($dir)) {
        throw new RuntimeException("Ordner konnte nicht gelöscht werden: $dir");
    }
}

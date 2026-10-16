<?php

require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/thumbnail.php';

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
const TYPE_SERIES = 'series';
const ENTRY_TYPES = [TYPE_MOVIE, TYPE_SERIES];

/**
 * Felder, die nur eine der beiden Arten hat. Filme: ein Medium und ggf. Dateien für den ganzen Film;
 * Serien: Medium und Dateien pro Staffel (seasons[]), dazu "Idee" (creator) und Endjahr.
 * Wechselt ein Eintrag die Art, fallen die Felder der anderen Art beim Speichern weg.
 */
const TYPE_ONLY_FIELDS = [
    TYPE_MOVIE => ['director', 'medium', 'files'],
    TYPE_SERIES => ['yearEnd', 'creator', 'seasons'],
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
 * Liest und dekodiert movie.json eines Films bzw. einer Serie. Null, wenn Ordner/Datei fehlt oder JSON ungültig ist.
 * Ältere Dateien kennen noch kein "type" (= Film) und speichern medium als einzelnen String ("USB"); beides wird
 * hier ergänzt bzw. zur Liste (["USB"]), damit alle Aufrufer nur das aktuelle Format sehen. Gespeichert wird es so
 * beim nächsten Schreiben.
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
    if (!in_array($data['type'] ?? null, ENTRY_TYPES, true)) {
        $data = ['type' => TYPE_MOVIE] + $data;
    }
    if (array_key_exists('medium', $data) && !is_array($data['medium'])) {
        $data['medium'] = normalize_movie_media($data['medium']);
    }
    return $data;
}

/**
 * Feldreihenfolge in movie.json: wie im Bearbeiten-Formular, danach die Verwaltungsfelder.
 * Bilder stehen dort, wo im Formular die Bildfelder sind.
 */
const MOVIE_FIELD_ORDER = [
    'type', 'title', 'originalTitle', 'year', 'yearEnd', 'runtime', 'fsk', 'genres', 'director', 'creator',
    'medium', 'files', 'seasons', 'poster', 'thumb', 'fanart', 'plot', 'trailer', 'cast', 'notes',
    'uniqueids', 'slug', 'created', 'trailerChecked',
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

/** @return array[] Alphabetisch nach Titel sortiert (das Frontend sortiert clientseitig ggf. um, siehe app.js). */
function list_movies(): array
{
    $movies = [];
    foreach (list_movie_slugs() as $slug) {
        $movie = get_movie($slug);
        if ($movie !== null) {
            $movies[] = $movie;
        }
    }
    usort($movies, fn(array $a, array $b) => strcasecmp((string) $a['title'], (string) $b['title']));
    return $movies;
}

/**
 * Prüft, ob es schon einen Eintrag derselben Art mit gleichem Titel (case-insensitiv) UND gleichem Jahr gibt.
 * Anders als beim Titel allein dürfen so Remakes ("Ben Hur" 1959 und 2016) nebeneinander existieren, ebenso ein
 * Film und eine gleichnamige Serie. $excludeSlug schließt den eigenen Eintrag beim Update aus.
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
 * Slug des Eintrags mit dieser TMDB-ID oder null. TMDB zählt Filme und Serien getrennt (Film 1396 ist nicht
 * Serie 1396), deshalb gehört die Art dazu. $excludeSlug schließt den eigenen Eintrag beim Update aus.
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

/** "Film" bzw. "Serie" für Meldungen. */
function type_label(string $type): string
{
    return $type === TYPE_SERIES ? 'Serie' : 'Film';
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
    if (isset($data['medium']) && !is_array($data['medium']) && !is_string($data['medium'])) {
        $errors[] = 'medium muss ein Array oder ein Text sein';
    }
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
    if (!empty($data['trailer']) && !preg_match('#^https?://#i', (string) $data['trailer'])) {
        $errors[] = 'trailer muss eine http(s)-URL sein';
    }
    // Pflicht bei Medium USB prüft das Formular (app.js: saveMovie()), nicht die API: Der TMDB-Import kennt
    // die Dateien nicht und muss einen Eintrag auch dann anlegen können, wenn IMPORT_DEFAULT_MEDIUM "USB" ist.
    array_push($errors, ...validate_files_input($data['files'] ?? null, 'files'));
    if (isset($data['seasons'])) {
        array_push($errors, ...validate_seasons_input($data['seasons']));
    }
    foreach (['plot', 'notes'] as $field) {
        if (isset($data[$field]) && !is_string($data[$field])) {
            $errors[] = "$field muss ein Text sein";
        }
    }
    foreach (['year' => [1880, 2100], 'yearEnd' => [1880, 2100], 'runtime' => [1, 1000]] as $field => [$min, $max]) {
        if (!valid_optional_int($data[$field] ?? null, $min, $max)) {
            $errors[] = "$field muss eine ganze Zahl zwischen $min und $max sein";
        }
    }
    if (!empty($data['year']) && !empty($data['yearEnd']) && (int) $data['yearEnd'] < (int) $data['year']) {
        $errors[] = 'yearEnd darf nicht vor year liegen';
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
 * Prüft eine Dateiliste (files eines Films oder einer Staffel): Array relativer Pfade ohne Laufwerk und "..".
 * @return string[]
 */
function validate_files_input(mixed $files, string $label): array
{
    if ($files === null) {
        return [];
    }
    if (!is_array($files)) {
        return ["$label muss ein Array sein"];
    }
    $errors = [];
    foreach (normalize_movie_files($files) as $f) {
        if (preg_match('#^([a-z]:|/)#i', $f) || in_array('..', explode('/', $f), true)) {
            $errors[] = "Datei '$f' muss relativ zum USB-Ordner angegeben werden (ohne Laufwerk und ohne '..')";
        }
    }
    return $errors;
}

/**
 * Prüft seasons[] einer Serie: jede Staffel ein Objekt mit eindeutiger Nummer (0 = Specials), optional
 * name, year, episodes[] ({number?, title?, plot?}), medium und files.
 * @return string[]
 */
function validate_seasons_input(mixed $seasons): array
{
    if (!is_array($seasons) || !array_is_list($seasons)) {
        return ['seasons muss ein Array sein'];
    }
    $errors = [];
    $numbers = [];
    foreach ($seasons as $i => $s) {
        if (!is_array($s)) {
            $errors[] = "seasons[$i] muss ein Objekt sein";
            continue;
        }
        $label = 'Staffel ' . (isset($s['number']) && is_scalar($s['number']) ? $s['number'] : '#' . ($i + 1));
        if (!isset($s['number']) || $s['number'] === '' || !valid_optional_int($s['number'], 0, 999)) {
            $errors[] = "$label: number muss eine ganze Zahl zwischen 0 und 999 sein";
        } elseif (in_array((int) $s['number'], $numbers, true)) {
            $errors[] = "$label ist doppelt vorhanden";
        } else {
            $numbers[] = (int) $s['number'];
        }
        if (!valid_optional_int($s['year'] ?? null, 1880, 2100)) {
            $errors[] = "$label: year muss eine ganze Zahl zwischen 1880 und 2100 sein";
        }
        if (isset($s['medium']) && !is_array($s['medium']) && !is_string($s['medium'])) {
            $errors[] = "$label: medium muss ein Array oder ein Text sein";
        }
        foreach (validate_files_input($s['files'] ?? null, 'files') as $e) {
            $errors[] = "$label: $e";
        }
        $episodes = $s['episodes'] ?? [];
        if (!is_array($episodes) || !array_is_list($episodes)) {
            $errors[] = "$label: episodes muss ein Array sein";
            continue;
        }
        foreach ($episodes as $e) {
            if (!is_array($e) || !valid_optional_int($e['number'] ?? null, 0, 9999)
                || (isset($e['title']) && !is_string($e['title'])) || (isset($e['plot']) && !is_string($e['plot']))) {
                $errors[] = "$label: jede Folge braucht die Form {number, title, plot}";
                break;
            }
        }
    }
    return $errors;
}

/**
 * Filmdateien (Medium USB) relativ zu USB_ROOT, z. B. ["zimmer-1408-2007/1408 (2007).avi"]; bei mehrteiligen
 * Filmen mehrere in Abspielreihenfolge. Gespeichert immer mit "/" (play.php wandelt für Windows um).
 * @return string[]
 */
function normalize_movie_files(array $files): array
{
    return array_values(array_filter(array_map(
        fn($f) => trim(str_replace('\\', '/', trim((string) $f)), '/'),
        $files
    ), 'strlen'));
}

/**
 * Medien des eigenen Exemplars, z. B. ["DVD", "USB"], wenn der Film doppelt vorhanden ist. Akzeptiert auch einen
 * einzelnen String, mehrere Werte darin durch Komma getrennt ("DVD, USB"; altes Format bzw. IMPORT_DEFAULT_MEDIUM).
 * @return string[]
 */
function normalize_movie_media(mixed $media): array
{
    if ($media === null) {
        return [];
    }
    $list = is_array($media) ? $media : explode(',', (string) $media);
    return array_values(array_unique(array_filter(array_map(fn($m) => trim((string) $m), $list), 'strlen')));
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
 * (type) kommen die Film- bzw. die Serienfelder dazu (TYPE_ONLY_FIELDS).
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
            'thumb' => nullable_string($c['thumb'] ?? null),
        ], array_values($data['cast'] ?? [])),
        'trailer' => nullable_string($data['trailer'] ?? null),
        'plot' => nullable_string($data['plot'] ?? null),
        'notes' => nullable_string($data['notes'] ?? null),
    ];
    if ($type === TYPE_SERIES) {
        return $common + [
            'yearEnd' => nullable_int($data['yearEnd'] ?? null),
            'creator' => nullable_string($data['creator'] ?? null),
            'seasons' => normalize_seasons($data['seasons'] ?? []),
        ];
    }
    return $common + [
        'director' => nullable_string($data['director'] ?? null),
        'medium' => normalize_movie_media($data['medium'] ?? null),
        'files' => normalize_movie_files($data['files'] ?? []),
    ];
}

/**
 * Staffeln einer Serie, nach Nummer sortiert: {number, name, year, episodes: [{number, title, plot}], medium, files}.
 * Eine Staffel mit leerem medium ist bekannt (z. B. von TMDB), aber nicht im eigenen Bestand.
 * files: Folgendateien relativ zu USB_ROOT, üblich <slug>/S01/E01.<ext> (Zuordnung zu den Folgen siehe
 * season_episode_files()).
 * @return array[]
 */
function normalize_seasons(array $seasons): array
{
    $result = array_map(fn(array $s) => [
        'number' => (int) $s['number'],
        'name' => nullable_string($s['name'] ?? null),
        'year' => nullable_int($s['year'] ?? null),
        'episodes' => normalize_episodes($s['episodes'] ?? []),
        'medium' => normalize_movie_media($s['medium'] ?? null),
        'files' => normalize_movie_files($s['files'] ?? []),
    ], array_values($seasons));
    usort($result, fn(array $a, array $b) => $a['number'] <=> $b['number']);
    return $result;
}

/**
 * Folgen einer Staffel, nach Nummer sortiert. Fehlt die Nummer, zählt die Position (1, 2, …).
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
            'plot' => nullable_string($e['plot'] ?? null),
        ];
    }
    ksort($result);
    return array_values($result);
}

/**
 * Das Formular kennt bei Folgen nur die Titel. Damit die von TMDB übernommene Handlung einer Folge beim Bearbeiten
 * nicht verloren geht, wird sie (Zuordnung über Staffel- und Folgennummer) aus dem alten Stand übernommen.
 */
function carry_over_episode_plots(array $newSeasons, array $oldSeasons): array
{
    $old = [];
    foreach ($oldSeasons as $s) {
        foreach ($s['episodes'] ?? [] as $e) {
            $old[$s['number'] . '/' . $e['number']] = $e['plot'] ?? null;
        }
    }
    foreach ($newSeasons as &$s) {
        foreach ($s['episodes'] as &$e) {
            $e['plot'] ??= $old[$s['number'] . '/' . $e['number']] ?? null;
        }
        unset($e);
    }
    unset($s);
    return $newSeasons;
}

/**
 * Dateien einer Folge innerhalb von $season['files'] (in Abspielreihenfolge, mehrteilig z. B. E03-cd1, E03-cd2).
 * Zuordnung über die Folgennummer im Dateinamen ("E03", "S01E03", "e3"); trägt keine Datei der Staffel eine
 * solche Nummer, zählt die Position (1. Datei = 1. Folge der Liste). Gegenstück in app.js: episodeFiles().
 * @return string[]
 */
function season_episode_files(array $season, int $episode): array
{
    $pattern = '/(?:^|[^a-z0-9])(?:s\d+)?e0*(\d+)(?!\d)/i';
    $numbered = [];
    foreach ($season['files'] ?? [] as $f) {
        if (preg_match($pattern, basename($f), $m)) {
            $numbered[] = [(int) $m[1], $f];
        }
    }
    if ($numbered) {
        return array_values(array_map(fn($x) => $x[1], array_filter($numbered, fn($x) => $x[0] === $episode)));
    }
    $numbers = array_column($season['episodes'] ?? [], 'number');
    $pos = array_search($episode, $numbers, true);
    return $pos !== false && isset($season['files'][$pos]) ? [$season['files'][$pos]] : [];
}

/**
 * Das Formular kennt nur Name und Rolle. Damit beim Bearbeiten TMDB-Porträtpfad (profile) und
 * heruntergeladenes Foto (thumb) nicht verloren gehen, werden sie anhand des Namens aus dem alten
 * Stand übernommen.
 */
function carry_over_cast_profiles(array $newCast, array $oldCast): array
{
    $old = [];
    foreach ($oldCast as $c) {
        $old[mb_strtolower($c['name'])] = $c;
    }
    return array_map(function (array $c) use ($old) {
        $prev = $old[mb_strtolower($c['name'])] ?? [];
        $c['profile'] ??= $prev['profile'] ?? null;
        $c['thumb'] ??= $prev['thumb'] ?? null;
        return $c;
    }, $newCast);
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
 * Slug aus Titel + Jahr, damit Remakes mit gleichem Titel sprechende, unterschiedliche Ordnernamen
 * bekommen ("ben-hur-1959", "ben-hur-2016") statt "ben-hur" und "ben-hur-2".
 */
function movie_base_slug(array $fields): string
{
    return slugify($fields['title'] . ($fields['year'] !== null ? ' ' . $fields['year'] : ''));
}

/**
 * Legt einen neuen Film bzw. eine neue Serie an: validiert, vergibt Slug/created, erstellt den Ordner und schreibt movie.json.
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
    if (isset($uniqueids['tmdb']) && ($existing = find_slug_by_tmdb_id((string) $uniqueids['tmdb'], $fields['type'])) !== null) {
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
 * Aktualisiert einen Film bzw. eine Serie (auch einen Wechsel der Art). Ändert sich Titel oder Jahr so, dass der Slug wechselt, wird der
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

    // Ohne "type" bleibt die bisherige Art (eine Serie wird nicht stillschweigend zum Film).
    $data += ['type' => $existing['type']];
    $errors = validate_movie_input($data);
    if ($errors) {
        throw new ValidationException($errors);
    }

    $fields = normalize_movie_fields($data);
    $fields['cast'] = carry_over_cast_profiles($fields['cast'], $existing['cast'] ?? []);
    if (isset($fields['seasons'])) {
        $fields['seasons'] = carry_over_episode_plots($fields['seasons'], $existing['seasons'] ?? []);
    }
    if (title_exists($fields['title'], $fields['year'], $fields['type'], $slug)) {
        throw new ValidationException([type_label($fields['type']) . ' mit diesem Titel und Jahr bereits vorhanden']);
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
    // Art gewechselt (Film <-> Serie): Felder der anderen Art nicht mitschleppen.
    foreach (TYPE_ONLY_FIELDS[$fields['type'] === TYPE_SERIES ? TYPE_MOVIE : TYPE_SERIES] as $field) {
        unset($movie[$field]);
    }
    $movie = order_movie_fields($movie);
    write_movie_json($newSlug, $movie);
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

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

/** Liest und dekodiert movie.json eines Films. Null, wenn Ordner/Datei fehlt oder JSON ungültig ist. */
function read_movie_json(string $slug): ?array
{
    $path = movie_dir($slug) . '/movie.json';
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/**
 * Feldreihenfolge in movie.json: wie im Bearbeiten-Formular, danach die Verwaltungsfelder.
 * Bilder stehen dort, wo im Formular die Bildfelder sind.
 */
const MOVIE_FIELD_ORDER = [
    'title', 'originalTitle', 'year', 'runtime', 'fsk', 'genres', 'director', 'medium', 'files',
    'poster', 'thumb', 'fanart', 'plot', 'trailer', 'cast', 'notes',
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
 * Prüft, ob es schon einen Film mit gleichem Titel (case-insensitiv) UND gleichem Erscheinungsjahr gibt.
 * Anders als beim Titel allein dürfen so Remakes ("Ben Hur" 1959 und 2016) nebeneinander existieren.
 * $excludeSlug schließt den eigenen Film beim Update aus.
 */
function title_exists(string $title, ?int $year, ?string $excludeSlug = null): bool
{
    $needle = mb_strtolower(trim($title));
    foreach (list_movie_slugs() as $slug) {
        if ($slug === $excludeSlug) {
            continue;
        }
        $m = read_movie_json($slug);
        if ($m !== null && mb_strtolower(trim((string) $m['title'])) === $needle && ($m['year'] ?? null) === $year) {
            return true;
        }
    }
    return false;
}

/** Slug des Films mit dieser TMDB-ID oder null. $excludeSlug schließt den eigenen Film beim Update aus. */
function find_slug_by_tmdb_id(string $tmdbId, ?string $excludeSlug = null): ?string
{
    foreach (list_movie_slugs() as $slug) {
        if ($slug === $excludeSlug) {
            continue;
        }
        $m = read_movie_json($slug);
        if ($m !== null && (string) ($m['uniqueids']['tmdb'] ?? '') === $tmdbId) {
            return $slug;
        }
    }
    return null;
}

/** @return string[] Fehlermeldungen, leer wenn valide. */
function validate_movie_input(array $data): array
{
    $errors = [];

    if (trim((string) ($data['title'] ?? '')) === '') {
        $errors[] = 'title ist erforderlich';
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
    if (isset($data['files']) && !is_array($data['files'])) {
        $errors[] = 'files muss ein Array sein';
    } else {
        // Pflicht bei Medium USB prüft das Formular (app.js: saveMovie()), nicht die API: Der TMDB-Import kennt
        // die Datei nicht und muss einen Film auch dann anlegen können, wenn IMPORT_DEFAULT_MEDIUM "USB" ist.
        foreach (normalize_movie_files($data['files'] ?? []) as $f) {
            if (preg_match('#^([a-z]:|/)#i', $f) || in_array('..', explode('/', $f), true)) {
                $errors[] = "Datei '$f' muss relativ zum USB-Ordner angegeben werden (ohne Laufwerk und ohne '..')";
            }
        }
    }
    foreach (['plot', 'notes'] as $field) {
        if (isset($data[$field]) && !is_string($data[$field])) {
            $errors[] = "$field muss ein Text sein";
        }
    }
    foreach (['year' => [1880, 2100], 'runtime' => [1, 1000]] as $field => [$min, $max]) {
        $value = $data[$field] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
            $errors[] = "$field muss eine ganze Zahl zwischen $min und $max sein";
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
 * Baut aus rohen Request-Daten die vom Store verwalteten Felder, mit Defaults/Typumwandlung.
 * Nicht enthalten (und damit beim Update unverändert aus movie.json übernommen): slug, created,
 * uniqueids und die Bilddateien poster/thumb/fanart.
 */
function normalize_movie_fields(array $data): array
{
    return [
        'title' => trim((string) ($data['title'] ?? '')),
        'originalTitle' => nullable_string($data['originalTitle'] ?? null),
        'year' => nullable_int($data['year'] ?? null),
        'genres' => array_values(array_unique(array_filter(array_map(fn($g) => trim((string) $g), $data['genres'] ?? []), 'strlen'))),
        'runtime' => nullable_int($data['runtime'] ?? null),
        'fsk' => nullable_string($data['fsk'] ?? null),
        'director' => nullable_string($data['director'] ?? null),
        'cast' => array_map(fn(array $c) => [
            'name' => trim((string) $c['name']),
            'role' => nullable_string($c['role'] ?? null),
            'profile' => nullable_string($c['profile'] ?? null),
            'thumb' => nullable_string($c['thumb'] ?? null),
        ], array_values($data['cast'] ?? [])),
        'medium' => nullable_string($data['medium'] ?? null),
        'files' => normalize_movie_files($data['files'] ?? []),
        'trailer' => nullable_string($data['trailer'] ?? null),
        'plot' => nullable_string($data['plot'] ?? null),
        'notes' => nullable_string($data['notes'] ?? null),
    ];
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
 * Legt einen neuen Film an: validiert, vergibt Slug/created, erstellt den Ordner und schreibt movie.json.
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
    if (isset($uniqueids['tmdb']) && ($existing = find_slug_by_tmdb_id((string) $uniqueids['tmdb'])) !== null) {
        throw new ValidationException(["Film bereits vorhanden ($existing)"]);
    }
    if (title_exists($fields['title'], $fields['year'])) {
        throw new ValidationException(['Film mit diesem Titel und Jahr bereits vorhanden']);
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
 * Aktualisiert einen Film. Ändert sich Titel oder Jahr so, dass der Slug wechselt, wird der
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

    $errors = validate_movie_input($data);
    if ($errors) {
        throw new ValidationException($errors);
    }

    $fields = normalize_movie_fields($data);
    $fields['cast'] = carry_over_cast_profiles($fields['cast'], $existing['cast'] ?? []);
    if (title_exists($fields['title'], $fields['year'], $slug)) {
        throw new ValidationException(['Film mit diesem Titel und Jahr bereits vorhanden']);
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

    $movie = order_movie_fields(array_merge($existing, $fields, ['slug' => $newSlug]));
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

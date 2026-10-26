<?php

/**
 * Bewertungen je Benutzer, data/ratings.json (wie wishes.json getrennt von movie.json und nur auf dem Server per
 * Klick geändert).
 *
 *   {"matrix-1999": {"frank": 2, "lilie": -1}}
 *
 * Je Slug der Benutzer (Login) mit einem Wert aus RATING_VALUES; ohne Eintrag keine Bewertung.
 */

require_once __DIR__ . '/jsonfile.php';

/**
 * Erlaubte Bewertungswerte: -1 schlecht/nicht sehenswert, 0 neutral/Zeitvertreib, 1 gut/sehenswert,
 * 2 sehr gut/muss man sehen (wie RATING_STEPS in app.js).
 *
 * @var int[]
 */
const RATING_VALUES = [-1, 0, 1, 2];

/**
 * Pfad der Bewertungsdatei.
 *
 * @return string Absoluter Pfad von data/ratings.json
 */
function ratings_file(): string
{
    return DATA_DIR . '/ratings.json';
}

/**
 * Alle Bewertungen.
 *
 * @return array<string, array<string, int>>
 */
function read_ratings(): array
{
    return read_json_file(ratings_file());
}

/**
 * Setzt die Bewertung von $user für $slug (aus RATING_VALUES; null = entfernen). Liefert die Bewertungen des Eintrags.
 *
 * @param string $slug Ordnername des Eintrags unter MOVIES_DIR
 * @param string $user Login des Benutzers
 * @param int|null $value Wert aus RATING_VALUES; null entfernt die Bewertung
 * @return array Die Bewertungen des Eintrags (Login => Wert)
 */
function set_rating(string $slug, string $user, ?int $value): array
{
    $data = update_json_file(ratings_file(), function (array $data) use ($slug, $user, $value) {
        if ($value === null) {
            unset($data[$slug][$user]);
        } else {
            $data[$slug][$user] = $value;
        }
        return $data;
    });
    return $data[$slug] ?? [];
}

/**
 * Nach dem Speichern bzw. Löschen eines Eintrags: Bewertungen auf den neuen Slug umziehen ($newSlug null = gelöscht).
 *
 * @param string $oldSlug Bisheriger Slug
 * @param string|null $newSlug Neuer Slug; null, wenn der Eintrag gelöscht wurde
 * @return void
 */
function move_ratings(string $oldSlug, ?string $newSlug): void
{
    if ($oldSlug === $newSlug || !is_file(ratings_file())) {
        return;
    }
    update_json_file(ratings_file(), function (array $data) use ($oldSlug, $newSlug) {
        $entry = $data[$oldSlug] ?? [];
        unset($data[$oldSlug]);
        if ($newSlug !== null && $entry) {
            $data[$newSlug] = array_merge($data[$newSlug] ?? [], $entry);
        }
        return $data;
    });
}

/**
 * Entfernt alle Bewertungen eines Benutzers (beim Entfernen seines Zugangs).
 *
 * @param string $login Login des entfernten Benutzers
 * @return void
 */
function remove_user_ratings(string $login): void
{
    if (is_file(ratings_file())) {
        update_json_file(ratings_file(), fn(array $data) => array_map(fn(array $entry) => array_diff_key($entry, [$login => 0]), $data));
    }
}

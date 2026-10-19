<?php

/**
 * Bewertungen: Daumen hoch bzw. runter je Benutzer, data/ratings.json (wie wishes.json getrennt von movie.json und
 * nur auf dem Server per Klick geändert).
 *
 *   {"matrix-1999": {"frank": 1, "lilie": -1}}
 *
 * Je Slug der Benutzer (Login) mit 1 (Daumen hoch) bzw. -1 (Daumen runter); ohne Eintrag keine Bewertung.
 */

require_once __DIR__ . '/jsonfile.php';

function ratings_file(): string
{
    return DATA_DIR . '/ratings.json';
}

/** @return array<string, array<string, int>> */
function read_ratings(): array
{
    return read_json_file(ratings_file());
}

/** Setzt die Bewertung von $user für $slug (1, -1; 0 = entfernen). Liefert die Bewertungen des Eintrags. */
function set_rating(string $slug, string $user, int $value): array
{
    $data = update_json_file(ratings_file(), function (array $data) use ($slug, $user, $value) {
        if ($value === 0) {
            unset($data[$slug][$user]);
        } else {
            $data[$slug][$user] = $value;
        }
        return $data;
    });
    return $data[$slug] ?? [];
}

/** Nach dem Speichern bzw. Löschen eines Eintrags: Bewertungen auf den neuen Slug umziehen ($newSlug null = gelöscht). */
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

/** Entfernt alle Bewertungen eines Benutzers (beim Entfernen seines Zugangs). */
function remove_user_ratings(string $login): void
{
    if (is_file(ratings_file())) {
        update_json_file(ratings_file(), fn(array $data) => array_map(fn(array $entry) => array_diff_key($entry, [$login => 0]), $data));
    }
}

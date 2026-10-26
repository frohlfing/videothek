<?php

/**
 * Wunschliste: wer welchen Eintrag sehen möchte, data/wishes.json (wie locations.json getrennt von movie.json und
 * nur auf dem Server per Klick geändert).
 *
 *   {"matrix-1999": {"lilie": "2026-10-19T20:15:00+02:00"}}
 *
 * Je Slug der Benutzer (Login) mit dem Zeitpunkt des Wunsches. Auch Einträge ohne Medium (nicht im Bestand) lassen
 * sich wünschen.
 */

require_once __DIR__ . '/jsonfile.php';

/**
 * Pfad der Wunschlistendatei.
 *
 * @return string Absoluter Pfad von data/wishes.json
 */
function wishes_file(): string
{
    return DATA_DIR . '/wishes.json';
}

/**
 * Alle Wünsche.
 *
 * @return array<string, array<string, string>>
 */
function read_wishes(): array
{
    return read_json_file(wishes_file());
}

/**
 * Setzt bzw. entfernt den Wunsch von $user für $slug. Liefert die Wünsche des Eintrags (Login => Zeitpunkt).
 *
 * @param string $slug Ordnername des Eintrags unter MOVIES_DIR
 * @param string $user Login des Benutzers
 * @param bool $wish true setzt den Wunsch, false entfernt ihn
 * @return array Wünsche des Eintrags (Login => Zeitpunkt)
 */
function set_wish(string $slug, string $user, bool $wish): array
{
    $data = update_json_file(wishes_file(), function (array $data) use ($slug, $user, $wish) {
        if (!$wish) {
            unset($data[$slug][$user]);
        } elseif (!isset($data[$slug][$user])) {
            $data[$slug][$user] = date('c');
        }
        return $data;
    });
    return $data[$slug] ?? [];
}

/**
 * Nach dem Speichern bzw. Löschen eines Eintrags: Wünsche auf den neuen Slug umziehen ($newSlug null = gelöscht).
 *
 * @param string $oldSlug Bisheriger Slug
 * @param string|null $newSlug Neuer Slug; null, wenn der Eintrag gelöscht wurde
 * @return void
 */
function move_wishes(string $oldSlug, ?string $newSlug): void
{
    if ($oldSlug === $newSlug || !is_file(wishes_file())) {
        return;
    }
    update_json_file(wishes_file(), function (array $data) use ($oldSlug, $newSlug) {
        $entry = $data[$oldSlug] ?? [];
        unset($data[$oldSlug]);
        if ($newSlug !== null && $entry) {
            $data[$newSlug] = array_merge($data[$newSlug] ?? [], $entry);
        }
        return $data;
    });
}

/**
 * Entfernt alle Wünsche eines Benutzers (beim Entfernen seines Zugangs).
 *
 * @param string $login Login des entfernten Benutzers
 * @return void
 */
function remove_user_wishes(string $login): void
{
    if (is_file(wishes_file())) {
        update_json_file(wishes_file(), fn(array $data) => array_map(fn(array $entry) => array_diff_key($entry, [$login => 0]), $data));
    }
}

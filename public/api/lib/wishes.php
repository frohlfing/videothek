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

function wishes_file(): string
{
    return DATA_DIR . '/wishes.json';
}

/** @return array<string, array<string, string>> */
function read_wishes(): array
{
    return read_json_file(wishes_file());
}

/** Setzt bzw. entfernt den Wunsch von $user für $slug. Liefert die Wünsche des Eintrags (Login => Zeitpunkt). */
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

/** Nach dem Speichern bzw. Löschen eines Eintrags: Wünsche auf den neuen Slug umziehen ($newSlug null = gelöscht). */
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

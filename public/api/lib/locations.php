<?php

/**
 * Wo sich eine DVD bzw. Blu-ray gerade befindet: data/locations.json, getrennt von movie.json. So überschreibt ein
 * Hochladen geänderter Filmordner keine Ausleihen, und ein Klick schreibt nur diese kleine Datei.
 *
 *   {"matrix-1999": {"lilie/DVD": {"at": "rohlfing", "since": "2026-10-18T20:15:00+02:00", "by": "frank"}}}
 *
 * Schlüssel ist der Slug und das Medium (Eigentümer/Art, siehe medium_key()). "at" ist ein Haushalt (Eigentümer wie
 * "feldmann" für Barbara und Arne, siehe owner_of()) oder ein beliebiger Name für jemanden ohne Zugang ("Oma Erna");
 * ein Login wird beim Speichern zu seinem Haushalt, ältere Einträge mit Login wertet app.js ebenso (holderOf()). Ohne Eintrag liegt das Medium beim
 * Eigentümer; "zurück beim Eigentümer" löscht den Eintrag. "since" und "by" setzt der Server. Dateien (Medium USB)
 * haben keinen Standort.
 */

require_once __DIR__ . '/jsonfile.php';

function locations_file(): string
{
    return DATA_DIR . '/locations.json';
}

/** "lilie/DVD": ein Medium innerhalb eines Eintrags (je Eigentümer jede Art höchstens einmal). */
function medium_key(string $owner, string $type): string
{
    return "$owner/$type";
}

/** @return array<string, array<string, array{at: string, since: string, by: ?string}>> */
function read_locations(): array
{
    return read_json_file(locations_file());
}

/**
 * Ändert locations.json unter Sperre (update_json_file()). Liefert die neuen Daten.
 * @param callable(array): array $change
 */
function update_locations(callable $change): array
{
    return update_json_file(locations_file(), $change);
}

/**
 * Setzt den Standort eines Mediums: $at ist der Haushalt, Benutzer (gespeichert wird sein Haushalt) bzw. Name, bei
 * dem es jetzt ist; null, der Eigentümer selbst oder ein Benutzer, der zu ihm gehört (Tinka bei Rohlfings DVD), =
 * zurück beim Eigentümer. Liefert die Standorte des Eintrags.
 */
function set_location(string $slug, string $owner, string $type, ?string $at, ?string $by): array
{
    $key = medium_key($owner, $type);
    if ($at !== null && isset(read_users()[$at])) {
        $at = owner_of($at); // verliehen wird an den Haushalt: "arne" => "feldmann"
    }
    $data = update_locations(function (array $data) use ($slug, $key, $owner, $at, $by) {
        if ($at === null || $at === $owner) {
            unset($data[$slug][$key]);
        } elseif (($data[$slug][$key]['at'] ?? null) !== $at) {
            $data[$slug][$key] = ['at' => $at, 'since' => date('c'), 'by' => $by];
        }
        return $data;
    });
    return $data[$slug] ?? [];
}

/**
 * Nach dem Speichern bzw. Löschen eines Eintrags: Standorte auf den neuen Slug umziehen ($newSlug null = gelöscht)
 * und die von Medien entfernen, die es nicht mehr gibt (Eigentümer oder Art geändert, Medium entfernt).
 * @param array<array{owner: string, type: string}> $media
 */
function move_locations(string $oldSlug, ?string $newSlug, array $media = []): void
{
    if (!is_file(locations_file())) {
        return;
    }
    $keys = array_map(fn(array $m) => medium_key($m['owner'], $m['type']), $media);
    update_locations(function (array $data) use ($oldSlug, $newSlug, $keys) {
        $entry = $data[$oldSlug] ?? [];
        unset($data[$oldSlug]);
        if ($newSlug !== null) {
            $data[$newSlug] = array_intersect_key($entry, array_flip($keys));
        }
        return $data;
    });
}

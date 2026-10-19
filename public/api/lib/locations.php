<?php

/**
 * Wo sich eine DVD bzw. Blu-ray gerade befindet: data/locations.json, getrennt von movie.json. So überschreibt ein
 * Hochladen geänderter Filmordner keine Ausleihen, und ein Klick schreibt nur diese kleine Datei.
 *
 *   {"matrix-1999": {"lilie/DVD": {"at": "frank", "since": "2026-10-18T20:15:00+02:00", "by": "frank"}}}
 *
 * Schlüssel ist der Slug und das Medium (Besitzer/Art, siehe medium_key()). "at" ist ein Benutzer (Login aus
 * users.json) oder ein beliebiger Name für jemanden ohne Zugang ("Oma Erna"). Ohne Eintrag liegt das Medium beim
 * Besitzer; "zurück beim Besitzer" löscht den Eintrag. "since" und "by" setzt der Server. Dateien (Medium USB) haben
 * keinen Standort.
 */

function locations_file(): string
{
    return DATA_DIR . '/locations.json';
}

/** "lilie/DVD": ein Medium innerhalb eines Eintrags (je Besitzer jede Art höchstens einmal). */
function medium_key(string $owner, string $type): string
{
    return "$owner/$type";
}

/** @return array<string, array<string, array{at: string, since: string, by: ?string}>> */
function read_locations(): array
{
    $file = locations_file();
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

/**
 * Liest locations.json unter Sperre, lässt $change die Daten ändern und schreibt sie zurück (nur, wenn sich etwas
 * geändert hat). Liefert die neuen Daten.
 * @param callable(array): array $change
 */
function update_locations(callable $change): array
{
    $fh = fopen(locations_file(), 'c+');
    if ($fh === false) {
        throw new RuntimeException('locations.json konnte nicht geöffnet werden');
    }
    try {
        flock($fh, LOCK_EX);
        $before = json_decode((string) stream_get_contents($fh), true);
        $before = is_array($before) ? $before : [];
        $data = array_filter($change($before));
        ksort($data, SORT_STRING);
        if ($data !== $before) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
            fflush($fh);
        }
        return $data;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Setzt den Standort eines Mediums: $at ist der Benutzer bzw. Name, bei dem es jetzt ist; null oder der Besitzer selbst =
 * zurück beim Besitzer. Liefert die Standorte des Eintrags.
 */
function set_location(string $slug, string $owner, string $type, ?string $at, ?string $by): array
{
    $key = medium_key($owner, $type);
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
 * und die von Medien entfernen, die es nicht mehr gibt (Besitzer oder Art geändert, Medium entfernt).
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

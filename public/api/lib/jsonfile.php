<?php

/**
 * Kleine JSON-Dateien unter data/, die viele Benutzer per Klick ändern (locations.json, wishes.json): Lesen und
 * unter Sperre ändern.
 */

/** Inhalt als Array; leer, wenn die Datei fehlt oder kaputt ist. */
function read_json_file(string $file): array
{
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

/**
 * Liest die Datei unter Sperre, lässt $change die Daten ändern und schreibt sie zurück (nur, wenn sich etwas
 * geändert hat). Leere Einträge der obersten Ebene fallen weg, die Schlüssel werden sortiert. Liefert die neuen
 * Daten.
 * @param callable(array): array $change
 */
function update_json_file(string $file, callable $change): array
{
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        throw new RuntimeException(basename($file) . ' konnte nicht geöffnet werden');
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

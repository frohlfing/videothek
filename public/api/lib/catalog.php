<?php

/**
 * Was es von einer Reihe bzw. Serie laut TMDB alles gibt: data/collections.json (Filme einer Reihe) und
 * data/series.json (Staffeln einer Serie), je TMDB-ID ein Eintrag, nur einmal gespeichert statt in jedem Teil.
 * Gepflegt beim Import (api/import.php) und einmalig über `bin/build_catalog.php`; was davon im Bestand ist, rechnet
 * das Frontend aus (Abgleich über uniqueids.tmdb bzw. series.tmdb + season), damit die Dateien nicht bei jeder
 * Änderung am Bestand mitgepflegt werden müssen.
 *
 *   collections.json: {"24761": {"name": "Millennium", "updated": "2026-10-18T12:00:00+02:00",
 *                                "parts": [{"tmdb": "15472", "title": "Verblendung", "year": 2009, "date": "2009-02-27"}, …]}}
 *   series.json:      {"2602": {"name": "Die Tudors", "updated": "…",
 *                               "seasons": [{"season": 1, "title": "Staffel 1", "year": 2007, "date": "2007-04-01", "episodes": 10}, …]}}
 *
 * `date` leer oder in der Zukunft = angekündigt (zählt im Frontend nicht mit).
 */

/**
 * Katalogart: Filmreihen (data/collections.json).
 *
 * @var string
 */
const CATALOG_COLLECTIONS = 'collections';
/**
 * Katalogart: Serien (data/series.json).
 *
 * @var string
 */
const CATALOG_SERIES = 'series';

/**
 * Pfad der Katalogdatei zur Katalogart.
 *
 * @param string $kind CATALOG_COLLECTIONS oder CATALOG_SERIES
 * @return string Absoluter Pfad der Datei
 * @throws InvalidArgumentException bei unbekannter Katalogart
 */
function catalog_file(string $kind): string
{
    if (!in_array($kind, [CATALOG_COLLECTIONS, CATALOG_SERIES], true)) {
        throw new InvalidArgumentException("Unbekannter Katalog '$kind'");
    }
    return DATA_DIR . "/$kind.json";
}

/**
 * Ganzer Katalog (TMDB-ID => Eintrag); leer, wenn die Datei fehlt oder kaputt ist.
 *
 * @param string $kind CATALOG_COLLECTIONS oder CATALOG_SERIES
 * @return array
 */
function read_catalog(string $kind): array
{
    $file = catalog_file($kind);
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

/**
 * Speichert bzw. ersetzt einen Eintrag (mit aktuellem Datum in "updated"). Lesen und Schreiben unter einer Sperre,
 * damit sich zwei gleichzeitige Importe nicht gegenseitig überschreiben.
 *
 * @param string $kind CATALOG_COLLECTIONS oder CATALOG_SERIES
 * @param string $tmdb TMDB-ID der Reihe bzw. Serie
 * @param array $entry {name, parts} bzw. {name, seasons}
 * @return void
 * @throws RuntimeException wenn die Katalogdatei nicht geöffnet werden kann
 */
function save_catalog_entry(string $kind, string $tmdb, array $entry): void
{
    $file = catalog_file($kind);
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        throw new RuntimeException("$kind.json konnte nicht geöffnet werden");
    }
    try {
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true);
        $data = is_array($data) ? $data : [];
        $data[$tmdb] = ['name' => $entry['name'], 'updated' => date('c')] + $entry;
        uksort($data, fn($a, $b) => strnatcmp((string) $a, (string) $b));
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

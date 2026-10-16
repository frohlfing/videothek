<?php

/**
 * Play-Button für Filme mit Medium "USB": liefert eine Wiedergabeliste (.m3u) mit den vollständigen
 * Pfaden der Filmdatei(en) – USB_ROOT (config.php) + files[] aus movie.json. Der Browser lädt sie
 * herunter und das Betriebssystem öffnet sie mit dem installierten Player (VLC, Windows Media Player, …),
 * mehrteilige Filme (cd1, cd2, …) laufen nacheinander.
 *
 * Warum kein direkter file:///-Link? Chrome, Edge und Firefox blockieren Links von einer http(s)-Seite
 * auf lokale Dateien kommentarlos ("Not allowed to load local resource"). Eine heruntergeladene
 * Wiedergabeliste ist der einfachste Weg, den lokalen Player zu starten. Tipp: In Chrome/Edge beim
 * ersten Download "Dateien dieses Typs immer öffnen" wählen, dann startet der Film mit einem Klick.
 *
 * GET /api/play.php?slug=xyz -> <slug>.m3u, 404 falls der Film keine Dateien hat.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$movie = get_movie((string) ($_GET['slug'] ?? ''));
if ($movie === null || empty($movie['files'])) {
    json_error(404, 'Für diesen Film ist keine Filmdatei eingetragen');
}

// Trennzeichen passend zu USB_ROOT: "D:\videothek" -> Windows-Pfade mit "\", sonst "/".
$sep = str_contains(USB_ROOT, '\\') || preg_match('/^[a-z]:/i', USB_ROOT) ? '\\' : '/';
$root = rtrim(USB_ROOT, '\\/');

$lines = ['#EXTM3U'];
foreach ($movie['files'] as $i => $file) {
    $part = count($movie['files']) > 1 ? ' (Teil ' . ($i + 1) . ')' : '';
    $lines[] = '#EXTINF:-1,' . $movie['title'] . $part;
    $lines[] = $root . $sep . str_replace('/', $sep, $file);
}

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $movie['slug'] . '.m3u"');
header('Cache-Control: no-store');
// UTF-8-BOM, damit Player unter Windows Umlaute im Pfad nicht als ANSI lesen (VLC wertet sie aus).
echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";

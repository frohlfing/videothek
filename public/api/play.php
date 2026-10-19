<?php

/**
 * Play-Button für Filme und Staffeln mit Medium "USB": liefert eine Wiedergabeliste (.m3u) mit den vollständigen
 * Pfaden der Datei(en) des angemeldeten Benutzers – <USB-Ordner>/<slug>/<Datei> (Dateien aus seinem Medium USB in
 * movie.json). Der Browser lädt sie herunter und das Betriebssystem öffnet sie mit dem installierten Player (VLC,
 * Windows Media Player, …); mehrteilige Filme (cd1, cd2, …) bzw. alle Folgen einer Staffel laufen nacheinander.
 *
 * Jeder hat seinen eigenen USB-Speicher: Der USB-Ordner gilt auf dem Gerät, das abspielt. Das Frontend merkt ihn sich
 * je Gerät (localStorage) und schickt ihn als root mit; ohne root gilt USB_ROOT aus config.php.
 *
 * Warum kein direkter file:///-Link? Chrome, Edge und Firefox blockieren Links von einer http(s)-Seite
 * auf lokale Dateien kommentarlos ("Not allowed to load local resource"). Eine heruntergeladene
 * Wiedergabeliste ist der einfachste Weg, den lokalen Player zu starten. Tipp: In Chrome/Edge beim
 * ersten Download "Dateien dieses Typs immer öffnen" wählen, dann startet der Film mit einem Klick.
 *
 * GET /api/play.php?slug=xyz[&root=D:\videothek]          -> Film: <slug>.m3u, Staffel: alle Folgen
 * GET /api/play.php?slug=xyz&episode=3[&root=…]           -> Staffel: nur Folge 3 (<slug>-e03.m3u)
 *   Optional owner=<kennung>: Dateien dieses Eigentümers statt der eigenen (owner_of() des Benutzers).
 *   404, falls keine passende Datei eingetragen ist.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/crud.php';

$movie = get_movie((string) ($_GET['slug'] ?? ''));
if ($movie === null) {
    json_error(404, 'Eintrag nicht gefunden');
}

$user = current_user() ?? first_user();
$owner = (string) ($_GET['owner'] ?? ($user !== null ? owner_of($user) : ''));
$medium = null;
foreach ($movie['media'] ?? [] as $m) {
    if ($m['owner'] === $owner && in_array($m['type'], DIGITAL_MEDIA, true) && !empty($m['files'])) {
        $medium = $m;
        break;
    }
}
if ($medium === null) {
    json_error(404, 'Dafür ist bei dir keine Datei eingetragen');
}
$files = $medium['files'];

$root = trim((string) ($_GET['root'] ?? ''));
// Steht als Zeile in der Wiedergabeliste: keine Zeilenumbrüche.
if ($root === '' || preg_match('/[\x00-\x1f]/', $root)) {
    $root = USB_ROOT;
}

/** @var array<array{0: string, 1: string}> $items [Titel in der Wiedergabeliste, Pfad relativ zum Ordner des Eintrags] */
$items = [];
if ($movie['type'] === TYPE_SEASON) {
    $episodeNo = isset($_GET['episode']) ? filter_var($_GET['episode'], FILTER_VALIDATE_INT) : null;
    if ($episodeNo === false) {
        json_error(400, 'episode muss eine ganze Zahl sein');
    }
    // Ohne Folgenliste zählt jede Datei als eine Folge.
    $season = $movie;
    $season['episodes'] = ($movie['episodes'] ?? []) ?: array_map(fn($i) => ['number' => $i + 1, 'title' => null], array_keys($files));
    foreach ($season['episodes'] as $e) {
        if ($episodeNo !== null && $e['number'] !== $episodeNo) {
            continue;
        }
        $episodeFiles = season_episode_files($files, $season, $e['number']);
        $code = ($movie['season'] ?? null) !== null ? sprintf('S%02dE%02d', $movie['season'], $e['number']) : sprintf('Folge %d', $e['number']);
        foreach ($episodeFiles as $i => $file) {
            $part = count($episodeFiles) > 1 ? ' (Teil ' . ($i + 1) . ')' : '';
            $items[] = [$movie['title'] . " – $code" . ($e['title'] ? ' ' . $e['title'] : '') . $part, $file];
        }
    }
    $name = $movie['slug'] . ($episodeNo !== null ? sprintf('-e%02d', $episodeNo) : '');
} else {
    foreach ($files as $i => $file) {
        $part = count($files) > 1 ? ' (Teil ' . ($i + 1) . ')' : '';
        $items[] = [$movie['title'] . $part, $file];
    }
    $name = $movie['slug'];
}

if (!$items) {
    json_error(404, 'Dafür ist keine Datei eingetragen');
}

// Trennzeichen passend zum USB-Ordner: "D:\videothek" bzw. "E:" -> Windows-Pfade mit "\", "E:/filme" oder
// "/media/usb" -> "/".
$sep = str_contains($root, '\\') || (preg_match('/^[a-z]:/i', $root) && !str_contains($root, '/')) ? '\\' : '/';
$root = rtrim($root, '\\/');

$lines = ['#EXTM3U'];
foreach ($items as [$title, $file]) {
    $lines[] = '#EXTINF:-1,' . $title;
    $lines[] = $root . $sep . $movie['slug'] . $sep . str_replace('/', $sep, $file);
}

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '.m3u"');
header('Cache-Control: no-store');
// UTF-8-BOM, damit Player unter Windows Umlaute im Pfad nicht als ANSI lesen (VLC wertet sie aus).
echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";

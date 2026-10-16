<?php

/**
 * Wartungsskript (optional, nur lokal): prüft die Trailer ALLER Filme sofort, statt wie die Webseite erst beim
 * Öffnen eines Films (api/trailer.php). Gleiche Logik, siehe api/lib/trailer_check.php: ensure_trailer().
 * Wirkt nur auf die movie.json-Dateien des Rechners, auf dem es läuft – für den Produktivserver nicht nötig,
 * dort repariert die Detailansicht tote Trailer selbst.
 *
 *   php bin/check_trailers.php [--dry-run] [--only=<slug>]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../public/api/lib/trailer_check.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$only = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, strlen('--only='));
    }
}

$labels = ['ok' => 'ok', 'replaced' => 'ersetzt', 'added' => 'ergänzt', 'removed' => 'entfernt', 'none' => 'weiter ohne', 'unknown' => 'unklar'];
$stats = array_fill_keys(array_values($labels), 0);
$slugs = array_values(array_filter(list_movie_slugs(), fn($s) => $only === null || $s === $only));
sort($slugs);

foreach ($slugs as $i => $slug) {
    $movie = read_movie_json($slug);
    if ($movie === null) {
        continue;
    }
    $result = ensure_trailer($slug, $movie, 0, !$dryRun);
    $label = $labels[$result['status']];
    $stats[$label]++;
    if (!in_array($result['status'], ['ok', 'none'], true)) {
        printf("[%3d/%d] %s (%s): %s%s\n", $i + 1, count($slugs), $movie['title'], $movie['year'] ?? '-', $label,
            $result['movie']['trailer'] && $result['status'] !== 'unknown' ? ' -> ' . $result['movie']['trailer'] : '');
    }
}

echo "\n== " . ($dryRun ? 'Probelauf' : 'Ergebnis') . " ==\n";
foreach ($stats as $k => $v) {
    echo mb_str_pad($k, 12) . ": $v\n";
}

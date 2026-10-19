<?php

/**
 * Abgleich von `data/` zwischen Server und lokaler Kopie (dev/sync.php auf dem Server, `bin/remote.php --download-data`
 * bzw. `--upload-data lokal`). Verglichen wird je Datei der Inhalt (MD5); beim Download gewinnt immer der Server, beim
 * Upload die neuere Änderungszeit. Nach dem Übertragen bekommt die Kopie die Änderungszeit des Originals, damit der
 * nächste Vergleich stimmt. `data/cache/` bleibt außen vor (dort liegt nur Zwischengespeichertes, auch die MD5-Werte
 * selbst).
 */

/** Dateiendungen, die unter data/ vorkommen und übertragen werden dürfen. */
const SYNC_EXTENSIONS = ['json', 'jpg', 'jpeg', 'png', 'webp'];

/**
 * Prüft einen Pfad relativ zu DATA_DIR ("movies/matrix-1999/movie.json") und liefert ihn mit "/" oder null:
 * keine leeren, versteckten oder ".."-Teile, nicht unter cache/, nur SYNC_EXTENSIONS.
 */
function sync_path(string $path): ?string
{
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    $invalid = fn(string $p) => $p === '' || str_starts_with($p, '.') || preg_match('/[\x00-\x1f:*?"<>|]/', $p);
    if (array_any($parts, $invalid)) {
        return null;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $parts[0] !== 'cache' && in_array($ext, SYNC_EXTENSIONS, true) ? $path : null;
}

/**
 * Alle übertragbaren Dateien unter DATA_DIR: Pfad => {size, mtime, md5}. Die MD5-Werte merken sich
 * `data/cache/sync_md5.json` je Größe und Änderungszeit, damit nicht jedes Mal alle Bilder gelesen werden.
 * @return array<string, array{size: int, mtime: int, md5: string}>
 */
function data_manifest(): array
{
    $cacheFile = DATA_DIR . '/cache/sync_md5.json';
    $cache = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];
    $result = [];
    if (!is_dir(DATA_DIR)) {
        return [];
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DATA_DIR, FilesystemIterator::SKIP_DOTS));
    $prefix = strlen(rtrim(str_replace('\\', '/', DATA_DIR), '/')) + 1;
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $path = sync_path(substr(str_replace('\\', '/', $file->getPathname()), $prefix));
        if ($path === null) {
            continue;
        }
        $size = $file->getSize();
        $mtime = $file->getMTime();
        $c = $cache[$path] ?? null;
        $md5 = $c && $c['size'] === $size && $c['mtime'] === $mtime ? $c['md5'] : md5_file($file->getPathname());
        $result[$path] = ['size' => $size, 'mtime' => $mtime, 'md5' => $md5];
    }
    ksort($result, SORT_STRING);
    if ($result !== $cache) {
        @mkdir(dirname($cacheFile), 0775, true);
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    return $result;
}

/** Schreibt eine Datei unter DATA_DIR (über eine Zwischendatei, Ordner werden angelegt) mit Änderungszeit $mtime. */
function write_data_file(string $path, string $content, int $mtime): void
{
    $target = DATA_DIR . '/' . $path;
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Ordner für $path konnte nicht angelegt werden");
    }
    $tmp = "$dir/.sync-" . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false || !touch($tmp, $mtime) || !rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException("$path konnte nicht geschrieben werden");
    }
}

<?php

/**
 * Gegenstelle für `bin/remote.php (--download-data, --upload-data, --upload-account)`: `data/` abgleichen und
 * Benutzer übertragen, ohne Terminal auf dem Server (siehe `api/lib/sync.php`). Token nötig, auch für GET.
 *
 * Liegt bewusst unter dev/: Nur zum Abgleichen auf den Server kopieren und danach wieder löschen (die Skripte
 * erinnern daran). Wer das Token kennt, könnte darüber sonst alle Daten lesen und überschreiben und Zugänge anlegen.
 *
 * GET  /dev/sync.php                          -> {"files": {<pfad>: {size, mtime, md5}}}, alles unter data/ außer cache/
 * GET  /dev/sync.php?file=<pfad>              -> Inhalt der Datei, Änderungszeit (Unix) im Header X-Mtime
 * PUT  /dev/sync.php?file=<pfad>&mtime=<unix> -> schreibt die Datei (Body = Inhalt) mit dieser Änderungszeit
 * POST /dev/sync.php?account                  -> Body {"login", "hash", "name", "owner"}: Eintrag in .htpasswd
 *                                                (Passwort-Hash, siehe htpasswd_file()) und users.json
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api/lib/http.php';
require_once __DIR__ . '/../api/lib/users.php';
require_once __DIR__ . '/../api/lib/sync.php';

require_token();
set_time_limit(300);

$method = $_SERVER['REQUEST_METHOD'];
$file = isset($_GET['file']) ? sync_path((string) $_GET['file']) : null;
if (isset($_GET['file']) && $file === null) {
    json_error(400, 'Ungültiger Pfad');
}

if ($method === 'GET' && $file === null) {
    json_response(200, ['files' => (object) data_manifest()]);
}

if ($method === 'GET') {
    $path = DATA_DIR . '/' . $file;
    if (!is_file($path)) {
        json_error(404, "$file nicht gefunden");
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('X-Mtime: ' . filemtime($path));
    readfile($path);
    exit;
}

if ($method === 'PUT' && $file !== null) {
    $mtime = filter_var($_GET['mtime'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($mtime === false) {
        json_error(400, 'mtime (Unix-Zeit) ist erforderlich');
    }
    try {
        write_data_file($file, (string) file_get_contents('php://input'), $mtime);
    } catch (RuntimeException $e) {
        json_error(500, $e->getMessage());
    }
    json_response(200, ['written' => $file]);
}

if ($method === 'POST' && isset($_GET['account'])) {
    $body = read_json_body();
    $login = (string) ($body['login'] ?? '');
    $hash = (string) ($body['hash'] ?? '');
    if (!preg_match(USER_LOGIN_PATTERN, $login)) {
        json_error(400, 'Ungültiger Benutzername');
    }
    // bcrypt ($2y$) bzw. apr1-MD5, wie sie htpasswd erzeugt; keine Zeilenumbrüche.
    if (!preg_match('/^\$(2[aby]|apr1)\$[.\/0-9A-Za-z$]{20,}$/', $hash)) {
        json_error(400, 'Ungültiger Passwort-Hash');
    }
    $name = trim((string) ($body['name'] ?? '')) ?: (string) (read_users()[$login]['name'] ?? $login);
    $owner = trim((string) ($body['owner'] ?? ''));
    try {
        save_htpasswd_entry($login, $hash);
        save_user($login, ['name' => $name, 'owner' => $owner !== '' ? $owner : null]); // ohne: selbst Eigentümer
    } catch (RuntimeException $e) {
        json_error(500, $e->getMessage());
    }
    json_response(200, ['account' => $login, 'htpasswd' => basename(htpasswd_file())]);
}

json_error(405, "Methode '$method' nicht erlaubt");

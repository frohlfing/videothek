<?php

/**
 * Benutzer der Videothek. Angemeldet wird per HTTP Basic Auth (`public/.htaccess`, `.htpasswd`), die App liest nur den
 * Benutzernamen (Besitzer beim Import, "eingetragen von" bei Ausleihen). `data/users.json` ordnet ihm den
 * Anzeigenamen zu:
 *
 *   {"frank": {"name": "Frank"}, "lilie": {"name": "Lilie"}}
 *
 * Änderungen brauchen weiterhin das API-Token (require_token() in http.php), die Anmeldung allein reicht nicht.
 * Angelegt werden Benutzer lokal mit `bin/add_account.php` (`.htpasswd` und `users.json`), auf den Server übertragen
 * mit `bin/remote.php --upload-account` (über `dev/sync.php`).
 */

/** Erlaubte Benutzernamen: klein, ohne Leerzeichen (stehen in movie.json als Besitzer und in locations.json). */
const USER_LOGIN_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,31}$/';

function users_file(): string
{
    return DATA_DIR . '/users.json';
}

/**
 * Alle Benutzer (Login => {name}), in der Reihenfolge der Datei; leer, wenn sie fehlt oder kaputt ist.
 * Je Request nur einmal gelesen.
 * @return array<string, array{name: string}>
 */
function read_users(bool $fresh = false): array
{
    static $users = null;
    if ($users === null || $fresh) {
        $data = is_file(users_file()) ? json_decode((string) file_get_contents(users_file()), true) : null;
        $users = is_array($data) ? $data : [];
    }
    return $users;
}

/**
 * Speichert bzw. ergänzt einen Benutzer (unter Sperre, wie save_catalog_entry()).
 * @param array{name: string} $entry
 */
function save_user(string $login, array $entry): void
{
    $fh = fopen(users_file(), 'c+');
    if ($fh === false) {
        throw new RuntimeException('users.json konnte nicht geöffnet werden');
    }
    try {
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true);
        $data = is_array($data) ? $data : [];
        $data[$login] = $entry + ($data[$login] ?? []);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    read_users(true);
}

/**
 * Angemeldeter Benutzer (Basic Auth) oder null. Unter CGI/FastCGI (Hetzner) fehlt PHP_AUTH_USER, dort setzt Apache
 * REMOTE_USER bzw. nach einer internen Weiterleitung REDIRECT_REMOTE_USER.
 */
function current_user(): ?string
{
    foreach (['PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $key) {
        $login = trim((string) ($_SERVER[$key] ?? ''));
        if ($login !== '') {
            return $login;
        }
    }
    return null;
}

/** Der erste Benutzer aus users.json oder null. */
function first_user(): ?string
{
    return array_key_first(read_users());
}

/**
 * Pfad der .htpasswd: HTPASSWD_FILE aus config.php, sonst AuthUserFile aus public/.htaccess, sonst die Datei im
 * Projekt-Root (neben config.php, wie im Setup beschrieben).
 */
function htpasswd_file(): string
{
    if (defined('HTPASSWD_FILE')) {
        return constant('HTPASSWD_FILE');
    }
    $htaccess = __DIR__ . '/../../.htaccess';
    if (is_file($htaccess) && preg_match('/^\s*AuthUserFile\s+"?([^"\r\n]+?)"?\s*$/mi', (string) file_get_contents($htaccess), $m)) {
        return $m[1];
    }
    return dirname(__DIR__, 3) . '/.htpasswd';
}

/** Passwort-Hash eines Benutzers aus der .htpasswd oder null. */
function htpasswd_hash(string $login): ?string
{
    $file = htpasswd_file();
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (str_starts_with($line, "$login:")) {
            return substr($line, strlen($login) + 1);
        }
    }
    return null;
}

/** Trägt den Benutzer mit dem Passwort-Hash in die .htpasswd ein bzw. ersetzt seine Zeile. */
function save_htpasswd_entry(string $login, string $hash): void
{
    $file = htpasswd_file();
    $lines = is_file($file) ? preg_split('/\r?\n/', trim((string) file_get_contents($file))) : [];
    $lines = array_values(array_filter($lines, fn($l) => $l !== '' && !str_starts_with($l, "$login:")));
    $lines[] = "$login:$hash";
    if (file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        throw new RuntimeException("$file konnte nicht geschrieben werden");
    }
}

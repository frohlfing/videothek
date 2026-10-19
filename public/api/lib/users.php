<?php

/**
 * Benutzer der Videothek. Angemeldet wird per HTTP Basic Auth (`public/.htaccess`, `.htpasswd`), die App liest nur den
 * Benutzernamen (Eigentümer beim Import, "eingetragen von" bei Ausleihen). `data/users.json` ordnet ihm den
 * Anzeigenamen zu und, wenn sich mehrere eine Sammlung teilen (ein Haushalt, ein Standort), den gemeinsamen
 * Eigentümer:
 *
 *   {"frank": {"name": "Frank", "owner": "Rohlfing"}, "tinka": {"name": "Tinka", "owner": "Rohlfing"},
 *    "lilie": {"name": "Lilie"}}
 *
 * In users.json steht beim Eigentümer der Name ("Rohlfing", angezeigt als "Rohlfings DVD"), an den Medien in
 * movie.json und in locations.json seine Kennung (owner_key(): "rohlfing"). Ohne "owner" ist der Benutzer selbst
 * Eigentümer. Wunschliste, Bewertungen und Ausleihen ("hat Tinka") bleiben bei der Person.
 *
 * Änderungen brauchen weiterhin das API-Token (require_token() in http.php), die Anmeldung allein reicht nicht.
 * Angelegt werden Benutzer lokal mit `bin/account.php` (`.htpasswd` und `users.json`), auf den Server übertragen
 * mit `bin/remote.php --upload-account` (über `dev/sync.php`).
 */

require_once __DIR__ . '/slug.php';

/** Erlaubte Benutzernamen: klein, ohne Leerzeichen (stehen in movie.json als Eigentümer und in locations.json). */
const USER_LOGIN_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,31}$/';

function users_file(): string
{
    return DATA_DIR . '/users.json';
}

/**
 * Alle Benutzer (Login => {name}), in der Reihenfolge der Datei; leer, wenn sie fehlt oder kaputt ist.
 * Je Request nur einmal gelesen.
 * @return array<string, array{name: string, owner?: string}>
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

/** Kennung eines gemeinsamen Eigentümers aus seinem Namen: "Rohlfing" -> "rohlfing" (Form wie ein Login). */
function owner_key(string $name): string
{
    return substr(slugify($name), 0, 32);
}

/** Eigentümer der Medien eines Benutzers: Kennung seines "owner" aus users.json, sonst sein Login. */
function owner_of(string $login): string
{
    $name = trim((string) (read_users()[$login]['owner'] ?? ''));
    return $name !== '' && owner_key($name) !== '' ? owner_key($name) : $login;
}

/**
 * Alle Eigentümer: Kennung => Anzeigename ("rohlfing" => "Rohlfing", "lilie" => "Lilie"), in der Reihenfolge von
 * users.json.
 * @return array<string, string>
 */
function owners(): array
{
    $owners = [];
    foreach (read_users() as $login => $user) {
        $owner = owner_of($login);
        $owners[$owner] ??= $owner === $login ? (string) ($user['name'] ?? $login) : trim((string) $user['owner']);
    }
    return $owners;
}

/**
 * Speichert bzw. ergänzt einen Benutzer (unter Sperre, wie save_catalog_entry()). Ein Feld mit null wird entfernt
 * (z. B. 'owner' => null: wieder selbst Eigentümer).
 * @param array{name?: string, owner?: ?string} $entry
 */
function save_user(string $login, array $entry): void
{
    update_users(function (array $data) use ($login, $entry) {
        // Neue Felder kommen ans Ende, null entfernt das Feld.
        $data[$login] = array_filter(array_replace($data[$login] ?? [], $entry), fn($v) => $v !== null);
        return $data;
    });
}

/** Entfernt einen Benutzer aus users.json. */
function remove_user(string $login): void
{
    update_users(function (array $data) use ($login) {
        unset($data[$login]);
        return $data;
    });
}

/**
 * Liest users.json unter Sperre, lässt $change die Daten ändern und schreibt sie zurück. Anders als
 * update_json_file() bleibt die Reihenfolge erhalten (der erste Benutzer ist die Vorgabe, siehe first_user()).
 * @param callable(array): array $change
 */
function update_users(callable $change): void
{
    $fh = fopen(users_file(), 'c+');
    if ($fh === false) {
        throw new RuntimeException('users.json konnte nicht geöffnet werden');
    }
    try {
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true);
        $data = $change(is_array($data) ? $data : []);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
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

/** Trägt den Benutzer mit dem Passwort-Hash in die .htpasswd ein bzw. ersetzt seine Zeile; null entfernt sie. */
function save_htpasswd_entry(string $login, ?string $hash): void
{
    $file = htpasswd_file();
    $lines = is_file($file) ? preg_split('/\r?\n/', trim((string) file_get_contents($file))) : [];
    $lines = array_values(array_filter($lines, fn($l) => $l !== '' && !str_starts_with($l, "$login:")));
    if ($hash !== null) {
        $lines[] = "$login:$hash";
    }
    if (file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        throw new RuntimeException("$file konnte nicht geschrieben werden");
    }
}

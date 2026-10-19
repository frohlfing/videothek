<?php

/**
 * Legt lokal einen Benutzer an bzw. setzt ein neues Passwort: Eintrag in der .htpasswd (Basic Auth) und in
 * data/users.json (Anzeigename). Ohne --password wird ein Passwort erzeugt und angezeigt. Auf den Server überträgt
 * ihn danach php bin/remote.php --upload-account <login>.
 *
 *   php bin/add_account.php <login> [<Anzeigename>] [--password=<passwort>]
 *
 *   login         klein, ohne Leerzeichen (a-z, 0-9, . _ -), z. B. "lilie"; steht als Besitzer an den Medien
 *   Anzeigename   z. B. "Lilie" (Vorgabe: bisheriger Name bzw. login mit großem Anfangsbuchstaben); "Lilies DVD"
 */

// Nur Kommandozeile. Nicht über PHP_SAPI geprüft: Je nach Installation läuft "php" als CGI (z. B. php-cgi).
if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit("Nur für die Kommandozeile.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../public/api/lib/users.php';

[$positional, $options] = cli_args();
$login = (string) ($positional[0] ?? '');
if (!preg_match(USER_LOGIN_PATTERN, $login)) {
    fwrite(STDERR, "Aufruf: php bin/add_account.php <login> [<Anzeigename>] [--password=<passwort>]\n"
        . "login: klein, ohne Leerzeichen (a-z, 0-9, . _ -), höchstens 32 Zeichen.\n");
    exit(1);
}
$name = trim((string) ($positional[1] ?? '')) ?: (string) (read_users()[$login]['name'] ?? ucfirst($login));

$password = is_string($options['password'] ?? null) ? $options['password'] : null;
$generated = $password === null;
if ($generated) {
    // Ohne leicht verwechselbare Zeichen (0/O, 1/l/I), zum Weitergeben per Nachricht.
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 14; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Das Passwort braucht mindestens 8 Zeichen.\n");
    exit(1);
}

$existed = isset(read_users()[$login]) || htpasswd_hash($login) !== null;
try {
    save_htpasswd_entry($login, password_hash($password, PASSWORD_BCRYPT));
    save_user($login, ['name' => $name]);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(1);
}

echo ($existed ? 'Aktualisiert' : 'Angelegt') . ": $login ($name)\n";
echo '  ' . htpasswd_file() . "\n  " . users_file() . "\n";
if ($generated) {
    echo "\nPasswort für $login: $password\n(nur jetzt sichtbar, gespeichert ist nur der Hash)\n";
}
echo "\nAuf den Server übertragen: php bin/remote.php --upload-account $login --password=…\n";

/**
 * Argumente ohne Skriptnamen: [Positionsargumente, Optionen]. "--name=wert" bzw. "--name" (true).
 * @return array{0: string[], 1: array<string, string|true>}
 */
function cli_args(): array
{
    $args = array_slice($_SERVER['argv'] ?? $GLOBALS['argv'] ?? [], 1);
    $positional = [];
    $options = [];
    foreach ($args as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
            $options[$m[1]] = $m[2] ?? true;
        } else {
            $positional[] = $a;
        }
    }
    return [$positional, $options];
}

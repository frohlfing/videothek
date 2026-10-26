<?php

/**
 * Benutzer lokal verwalten: Eintrag in der .htpasswd (Basic Auth) und in data/users.json (Anzeigename, ggf.
 * gemeinsamer Eigentümer, siehe api/lib/users.php). Auf den Server überträgt ihn danach
 * php bin/remote.php --upload-account <login>.
 *
 *   php bin/account.php --add <login> [--name=<Name>] [--password=<passwort>] [--owner=<Eigentümer>]
 *   php bin/account.php --change <login> [--name=<Name>] [--password[=<passwort>]] [--owner=[<Eigentümer>]]
 *   php bin/account.php --remove <login> [--yes]
 *
 *   login       klein, ohne Leerzeichen (a-z, 0-9, . _ -), z. B. "lilie"
 *   --name      Anzeigename, z. B. "Lilie" ("Lilies Wunschliste"); bei --add Vorgabe: login mit großem Anfangsbuchstaben
 *   --password  bei --add ohne Angabe bzw. bei --change ohne Wert: ein neues wird erzeugt und einmal angezeigt
 *   --owner     gemeinsamer Eigentümer, wenn sich mehrere eine Sammlung teilen, z. B. "Rohlfing" für Frank und Tinka
 *               ("Rohlfings DVD"); bei --change leer (--owner=): wieder selbst Eigentümer
 *
 * --change ändert nur, was angegeben ist. --remove nimmt auch Wünsche und Bewertungen des Benutzers mit. Die Medien
 * in movie.json bleiben, wie sie sind; gehören danach Medien keinem Benutzer mehr (Eigentümer gewechselt bzw.
 * Benutzer entfernt), sagt das Skript es.
 */

// Nur Kommandozeile. Nicht über PHP_SAPI geprüft: Je nach Installation läuft "php" als CGI (z. B. php-cgi).
use Random\RandomException as RandomExceptionAlias;

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit("Nur für die Kommandozeile.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../public/api/lib/crud.php';

/**
 * Hilfetext, der bei falschem Aufruf angezeigt wird.
 *
 * @var string
 */
const USAGE = "Aufruf:\n"
    . "php bin/account.php --add <login> [--name=<Name>] [--password=<passwort>] [--owner=<Eigentümer>]\n"
    . "php bin/account.php --change <login> [--name=<Name>] [--password[=<passwort>]] [--owner=[<Eigentümer>]]\n"
    . "php bin/account.php --remove <login> [--yes]\n"
    . "\n"
    . "login: klein, ohne Leerzeichen (a-z, 0-9, . _ -), höchstens 32 Zeichen.\n"
    . "--password ohne Wert (bzw. bei --add weggelassen): ein neues wird erzeugt. --owner= (leer): selbst Eigentümer.\n";

[$positional, $options] = cli_args();
$actions = array_values(array_intersect(['add', 'change', 'remove'], array_keys($options)));
$login = (string) ($positional[0] ?? '');
// Vertippes melden, statt nur den Aufruf zu zeigen ("name=Paul" ohne "--", "--nmae=Paul").
$unknown = array_merge(
    array_map(fn($a) => preg_match('/^-*([a-z]+)=/', $a, $m) ? "'$a' (gemeint: --$m[1]=…?)" : "'$a'", array_slice($positional, 1)),
    array_map(fn($o) => "'--$o'", array_diff(array_keys($options), ['add', 'change', 'remove', 'name', 'password', 'owner', 'yes'])));
if ($unknown) {
    fail('Unbekannt: ' . implode(', ', $unknown) . "\n\n" . USAGE);
}
if (count($actions) !== 1 || count($positional) !== 1 || !preg_match(USER_LOGIN_PATTERN, $login)) {
    fail(USAGE);
}
$action = $actions[0];
$exists = isset(read_users()[$login]) || htpasswd_hash($login) !== null;
if ($action === 'add' && $exists) {
    fail("$login gibt es schon. Ändern mit --change, entfernen mit --remove.\n");
}
if ($action !== 'add' && !$exists) {
    fail("$login gibt es nicht (weder in " . users_file() . " noch in " . htpasswd_file() . ").\n");
}

if ($action === 'remove') {
    $owner = owner_of($login);
    echo "Entfernen: $login (" . userName($login) . ") aus " . htpasswd_file() . " und " . users_file() . "\n";
    if (!cli_confirm('Ausführen?', isset($options['yes']))) {
        echo "Abgebrochen.\n";
        exit(0);
    }
    try {
        save_htpasswd_entry($login, null);
        remove_user($login);
        // Seine Wünsche und Bewertungen gehen mit (sie gehören der Person).
        remove_user_wishes($login);
        remove_user_ratings($login);
    } catch (RuntimeException $e) {
        fail('Fehlgeschlagen: ' . $e->getMessage() . "\n");
    }
    echo "Entfernt: $login\n";
    warn_orphaned_media($owner);
    echo "\nAuf dem Server muss $login aus folgenden Dateien entfernt werden:\n"
        . "  .htpasswd\n"
        . "  data/users.json\n"
        . "  data/wishes.json\n"
        . "  data/ratings.json\n";
    exit(0);
}

// --add bzw. --change: Nur, was angegeben ist (bei --add mit Vorgaben).
$entry = [];
if (is_string($options['name'] ?? null) && trim($options['name']) !== '') {
    $entry['name'] = trim($options['name']);
} elseif ($action === 'add') {
    $entry['name'] = ucfirst($login);
}
if (array_key_exists('owner', $options)) {
    $owner = is_string($options['owner']) ? trim($options['owner']) : '';
    // Die Kennung ("rohlfing") darf nicht der Login eines anderen Benutzers sein, sonst gehörten dessen Medien dazu.
    if ($owner !== '' && (owner_key($owner) === '' || (isset(read_users()[owner_key($owner)]) && owner_key($owner) !== $login))) {
        fail("Eigentümer '$owner' geht nicht: Die Kennung '" . owner_key($owner) . "' ist leer oder ein anderer Login.\n");
    }
    $entry['owner'] = $owner !== '' ? $owner : null;
}
$password = null;
$generated = false;
if (array_key_exists('password', $options) || $action === 'add') {
    $password = is_string($options['password'] ?? null) ? $options['password'] : null;
    if ($password === null) {
        $password = generate_password();
        $generated = true;
    }
    if (strlen($password) < 8) {
        fail("Das Passwort braucht mindestens 8 Zeichen.\n");
    }
}
if (!$entry && $password === null) {
    fail("Nichts zu ändern. Möglich: --name=…, --password[=…], --owner=[…]\n");
}

$ownerBefore = owner_of($login);
try {
    if ($password !== null) {
        save_htpasswd_entry($login, password_hash($password, PASSWORD_BCRYPT));
    }
    if ($entry || $action === 'add') {
        save_user($login, $entry);
    }
} catch (RuntimeException $e) {
    fail('Fehlgeschlagen: ' . $e->getMessage() . "\n");
}

$changed = array_merge(array_keys($entry), $password !== null ? ['password'] : []);
echo ($action === 'add' ? 'Angelegt' : 'Geändert (' . implode(', ', $changed) . ')') . ": $login ("
    . userName($login) . ', Eigentümer der Medien: ' . owners()[owner_of($login)] . ")\n";
if ($generated) {
    echo "\nPasswort für $login: $password\n(nur jetzt sichtbar, gespeichert ist nur der Hash)\n";
}
if ($ownerBefore !== owner_of($login)) {
    warn_orphaned_media($ownerBefore);
}
echo "\nAuf den Server übertragen: php bin/remote.php --upload-account $login --password=…\n";

/**
 * Anzeigename aus users.json, sonst der Login.
 *
 * @param string $login Login des Benutzers
 * @return string Der Anzeigename
 */
function userName(string $login): string
{
    return (string) (read_users()[$login]['name'] ?? $login);
}

/**
 * Meldet, wie viele Medien $owner gehören.
 *
 * @param string $owner Kennung des Eigentümers
 * @return void
 */
function warn_orphaned_media(string $owner): void
{
    if (isset(owners()[$owner])) {
        return;
    }
    $slugs = array_filter(list_movie_slugs(), fn($slug) => array_filter(
        read_movie_json($slug)['media'] ?? [], fn($m) => $m['owner'] === $owner));
    if ($slugs) {
        echo "\nAchtung: " . count($slugs) . " Einträge haben Medien von '$owner', der jetzt keinem Benutzer mehr"
            . " gehört (im Formular den Eigentümer ändern):\n" . cli_list(array_values($slugs));
    }
}

/**
 * 14 Zeichen ohne leicht verwechselbare (0/O, 1/l/I), zum Weitergeben per Nachricht.
 *
 * @return string Das Passwort
 * @throws RandomExceptionAlias
 */
function generate_password(): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 14; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

/**
 * Meldung auf STDERR, Exit-Code 1.
 *
 * @param string $message Die Meldung (mit Zeilenumbruch am Ende)
 * @return never
 */
function fail(string $message): never
{
    fwrite(STDERR, $message);
    exit(1);
}

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
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
            $options[$m[1]] = $m[2] ?? true;
        } else {
            $positional[] = $a;
        }
    }
    return [$positional, $options];
}

/**
 * Fragt "… [j/N]" auf der Kommandozeile; mit $yes (Option --yes) ohne Rückfrage.
 *
 * @param string $question Die Frage (ohne "[j/N]")
 * @param bool $yes true, wenn ohne Rückfrage bestätigt werden soll
 * @return bool true, wenn der Benutzer bestätigt hat
 */
function cli_confirm(string $question, bool $yes): bool
{
    if ($yes) {
        return true;
    }
    echo "$question [j/N] ";
    $answer = strtolower(trim((string) fgets(STDIN)));
    return in_array($answer, ['j', 'ja', 'y', 'yes'], true);
}

/**
 * Liste für die Ausgabe, höchstens $max Zeilen.
 *
 * @param string[] $lines
 * @param int $max Höchstzahl der angezeigten Zeilen
 * @return string Der eingerückte Text mit Zeilenumbrüchen
 */
function cli_list(array $lines, int $max = 20): string
{
    $shown = array_map(fn($l) => "    $l", array_slice($lines, 0, $max));
    if (count($lines) > $max) {
        $shown[] = '    … und ' . (count($lines) - $max) . ' weitere';
    }
    return implode("\n", $shown) . "\n";
}

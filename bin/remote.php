<?php

/**
 * Abgleich mit dem Server ohne Terminal dort: spricht dev/sync.php an, das dafür vorübergehend auf dem Server liegen
 * muss (Ordner public/dev/ per FTP hochladen, danach wieder löschen; das Skript erinnert daran).
 */

// Nur Kommandozeile. Nicht über PHP_SAPI geprüft: Je nach Installation läuft "php" als CGI (z. B. php-cgi).
if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit("Nur für die Kommandozeile.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../public/api/lib/sync.php';
require_once __DIR__ . '/../public/api/lib/users.php';

/** Adresse der Videothek auf dem Server (ohne "/" am Ende); mit --url=… abweichend. */
const REMOTE_URL = 'https://videothek.frank-rohlfing.de';
const REMOTE_DEFAULT_USER = 'frank';

[$positional, $options] = cli_args();
$actions = array_values(array_intersect(['download-data', 'upload-data', 'upload-account'], array_keys($options)));
$password = is_string($options['password'] ?? null) ? $options['password'] : '';
if (count($actions) !== 1 || $password === '') {
    fwrite(STDERR, "Aufruf:\n"
        . "php bin/remote.php --download-data [--yes] --password=<passwort>\n"
        . "    Gleicht das lokale Datenverzeichnis mit dem Server ab. Auf dem Server wird nichts verändert.\n"
        . "\n"
        . "php bin/remote.php --upload-data [--yes] --password=<passwort>\n"
        . "    Lädt Daten auf den Server, die dort fehlen oder älter sind. Auf dem Server wird nichts gelöscht.\n"
        . "\n"
        . "php bin/remote.php --upload-account <login> --password=<passwort>\n"
        . "    Überträgt den Benutzer der lokalen .htpasswd und der users.json zum Server.\n"
        . "\n"
        . "Argumente:\n"
        . "--url        Adresse der Videothek, Vorgabe \"" . REMOTE_URL . "\"\n"
        . "--user       Basic-Auth-Benutzer, Vorgabe \"" . REMOTE_DEFAULT_USER . "\"\n"
        . "--password   Basic-Auth-Passwort auf dem Server (Pflicht)\n"
        . "--yes        Ohne Rückfrage\n"
        . (count($actions) > 1 ? "\nBitte nur eine Aktion angeben.\n" : '')
        . ($password === '' && count($actions) === 1 ? "\nDas Passwort (--password=…) fehlt.\n" : ''));
    exit(1);
}

$remote = [
    'url' => rtrim(is_string($options['url'] ?? null) ? $options['url'] : REMOTE_URL, '/'),
    'user' => is_string($options['user'] ?? null) ? $options['user'] : REMOTE_DEFAULT_USER,
    'password' => $password,
];
$yes = isset($options['yes']);

// Am Ende (auch nach Fehler oder Abbruch) ans Löschen von dev/sync.php erinnern, sofern es erreichbar war.
register_shutdown_function(function () {
    if (!empty($GLOBALS['syncReached'])) {
        echo "\nNicht vergessen: dev/sync.php (bzw. den Ordner dev/) auf dem Server wieder löschen, wenn du fertig bist.\n";
    }
});

try {
    exit(match ($actions[0]) {
        'download-data' => run_download($yes),
        'upload-data' => run_upload($yes),
        'upload-account' => run_upload_account($positional),
    });
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

/** --download-data, siehe oben: lokal danach genau wie auf dem Server, auch lokal neuere Dateien. Liefert den Exit-Code. */
function run_download(bool $yes): int
{
    echo 'Download von ' . $GLOBALS['remote']['url'] . ' nach ' . DATA_DIR . "\n";
    $remote = remote_manifest();
    $local = data_manifest();

    $get = [];
    foreach ($remote as $path => $r) {
        $l = $local[$path] ?? null;
        if ($l === null) {
            $get[$path] = 'neu';
        } elseif ($l['md5'] !== $r['md5']) {
            $get[$path] = $l['mtime'] > $r['mtime'] ? 'geändert, lokal neuer' : 'geändert';
        }
    }
    $delete = array_keys(array_diff_key($local, $remote));

    echo count($remote) . ' Dateien auf dem Server, davon ' . (count($remote) - count($get)) . " schon aktuell.\n";
    if (!$get && !$delete) {
        echo "\nNichts zu tun.\n";
        return 0;
    }
    if ($get) {
        echo "\nHerunterladen (" . count($get) . "):\n" . cli_list(array_map(fn($p) => "$p ($get[$p])", array_keys($get)));
    }
    if ($delete) {
        echo "\nLokal löschen, nicht auf dem Server (" . count($delete) . "):\n" . cli_list($delete);
    }
    if (!cli_confirm("\nAusführen?", $yes)) {
        echo "Abgebrochen.\n";
        return 0;
    }

    $errors = 0;
    foreach (array_keys($get) as $n => $path) {
        try {
            $res = remote_request('GET', ['file' => $path]);
            if (md5($res['body']) !== $remote[$path]['md5']) {
                throw new RuntimeException('Inhalt unvollständig (MD5 stimmt nicht)');
            }
            write_data_file($path, $res['body'], (int) ($res['headers']['x-mtime'] ?? $remote[$path]['mtime']));
            if (($n + 1) % 50 === 0) {
                echo '  ' . ($n + 1) . ' von ' . count($get) . " geladen …\n";
            }
        } catch (Throwable $e) {
            $errors++;
            fwrite(STDERR, "  Fehler bei $path: {$e->getMessage()}\n");
        }
    }
    foreach ($delete as $path) {
        if (@unlink(DATA_DIR . "/$path")) {
            remove_empty_dirs(dirname(DATA_DIR . "/$path"));
        } else {
            $errors++;
            fwrite(STDERR, "  Konnte $path nicht löschen\n");
        }
    }

    echo "\nFertig: " . (count($get) - $errors) . ' geladen, ' . count($delete) . ' gelöscht' . ($errors ? ", $errors Fehler" : '') . ".\n";
    return $errors ? 1 : 0;
}

/** --upload-data: Lädt Daten auf den Server. Liefert den Exit-Code. */
function run_upload(bool $yes): int
{
    echo 'Upload von ' . DATA_DIR . ' nach ' . $GLOBALS['remote']['url'] . "\n";
    $remote = remote_manifest();
    $local = data_manifest();

    $put = [];
    $newerThere = [];
    foreach ($local as $path => $l) {
        $r = $remote[$path] ?? null;
        if ($r === null) {
            $put[$path] = 'neu';
        } elseif ($r['md5'] !== $l['md5']) {
            if ($l['mtime'] > $r['mtime']) {
                $put[$path] = 'geändert';
            } else {
                $newerThere[] = $path;
            }
        }
    }
    $onlyThere = count(array_diff_key($remote, $local));

    echo count($local) . ' Dateien lokal, davon ' . (count($local) - count($put) - count($newerThere)) . " schon aktuell.\n";
    if ($onlyThere) {
        echo "$onlyThere Dateien gibt es nur auf dem Server (bleiben; holen mit --download-data).\n";
    }
    if ($newerThere) {
        echo "\nAuf dem Server neuer, bleibt (holen mit --download-data):\n" . cli_list($newerThere);
    }
    if (!$put) {
        echo "\nNichts hochzuladen.\n";
        return 0;
    }
    echo "\nHochladen (" . count($put) . "):\n" . cli_list(array_map(fn($p) => "$p ($put[$p])", array_keys($put)));
    if (!cli_confirm("\nAusführen?", $yes)) {
        echo "Abgebrochen.\n";
        return 0;
    }

    $errors = 0;
    foreach (array_keys($put) as $n => $path) {
        try {
            $content = file_get_contents(DATA_DIR . "/$path");
            if ($content === false) {
                throw new RuntimeException('nicht lesbar');
            }
            remote_request('PUT', ['file' => $path, 'mtime' => $local[$path]['mtime']], $content);
            if (($n + 1) % 50 === 0) {
                echo '  ' . ($n + 1) . ' von ' . count($put) . " hochgeladen …\n";
            }
        } catch (Throwable $e) {
            $errors++;
            fwrite(STDERR, "  Fehler bei $path: {$e->getMessage()}\n");
        }
    }

    echo "\nFertig: " . (count($put) - $errors) . ' hochgeladen' . ($errors ? ", $errors Fehler" : '') . ".\n";
    return $errors ? 1 : 0;
}

/** --upload-account lilie, siehe oben. Liefert den Exit-Code. @param string[] $positional */
function run_upload_account(array $positional): int
{
    if (count($positional) !== 1) {
        fwrite(STDERR, "Genau einen Benutzer angeben: --upload-account <login>\n");
        return 1;
    }
    $login = $positional[0];
    $hash = htpasswd_hash($login);
    if ($hash === null) {
        fwrite(STDERR, "$login: nicht in " . htpasswd_file() . " (erst php bin/add_account.php $login)\n");
        return 1;
    }
    $name = read_users()[$login]['name'] ?? '';
    echo 'Benutzer übertragen nach ' . $GLOBALS['remote']['url'] . "\n";
    $res = remote_json('POST', ['account' => 1], ['login' => $login, 'hash' => $hash, 'name' => $name]);
    echo "  $login" . ($name !== '' ? " ($name)" : '') . ": übertragen ({$res['htpasswd']} und users.json)\n";
    return 0;
}

/**
 * Ruft dev/sync.php auf dem Server auf (Basic Auth mit Benutzer und Passwort, Token = API_TOKEN aus config.php).
 * Wirft bei Netzwerkfehlern und HTTP-Status ab 400 (mit der Fehlermeldung des Servers).
 * @param array<string, string|int> $query
 * @return array{body: string, headers: array<string, string>}
 */
function remote_request(string $method, array $query = [], ?string $body = null, string $contentType = 'application/octet-stream'): array
{
    $remote = $GLOBALS['remote'];
    $url = $remote['url'] . '/dev/sync.php' . ($query ? '?' . http_build_query($query) : '');
    $responseHeaders = [];
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => ['X-API-Token: ' . API_TOKEN],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $remote['user'] . ':' . $remote['password'],
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
        $options[CURLOPT_HTTPHEADER][] = "Content-Type: $contentType";
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($response === false) {
        throw new RuntimeException("Server nicht erreichbar ($url): " . curl_error($ch));
    }
    if ($status === 401 && !str_contains((string) $response, 'API-Token')) {
        // 401 vom Webserver (Basic Auth), nicht von sync.php
        throw new RuntimeException("Anmeldung abgelehnt: Benutzer '{$remote['user']}' bzw. Passwort stimmt nicht.");
    }
    $error = json_decode((string) $response, true)['error'] ?? null;
    if ($status === 404 && $error === null) {
        // 404 vom Webserver selbst, nicht von sync.php
        throw new RuntimeException("$url nicht gefunden. Erst den Ordner public/dev/ (mit sync.php) per FTP auf den Server kopieren.");
    }
    $GLOBALS['syncReached'] = true; // sync.php liegt auf dem Server: am Ende ans Löschen erinnern
    if ($status === 401) {
        throw new RuntimeException('Token abgelehnt: API_TOKEN in der lokalen config.php stimmt nicht mit dem des Servers überein.');
    }
    if ($status >= 400) {
        $message = $error ?? trim(strip_tags((string) $response));
        throw new RuntimeException("HTTP $status: " . mb_substr($message, 0, 300));
    }
    return ['body' => (string) $response, 'headers' => $responseHeaders];
}

/** Wie remote_request(), aber mit JSON-Body und JSON-Antwort. */
function remote_json(string $method, array $query = [], ?array $data = null): array
{
    $res = remote_request($method, $query, $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, 'application/json');
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException('Unerwartete Antwort vom Server: ' . mb_substr(trim(strip_tags($res['body'])), 0, 200));
    }
    return $json;
}

/** Dateiliste des Servers wie data_manifest(). @return array<string, array{size: int, mtime: int, md5: string}> */
function remote_manifest(): array
{
    return remote_json('GET')['files'] ?? [];
}

/** Leere Ordner unterhalb von DATA_DIR entfernen, beginnend bei $dir (nach dem Löschen von Dateien). */
function remove_empty_dirs(string $dir): void
{
    $root = rtrim(str_replace('\\', '/', DATA_DIR), '/');
    $dir = str_replace('\\', '/', $dir);
    while ($dir !== $root && str_starts_with($dir, "$root/") && is_dir($dir) && count(scandir($dir)) === 2) {
        rmdir($dir);
        $dir = dirname($dir);
    }
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
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
            $options[$m[1]] = $m[2] ?? true;
        } else {
            $positional[] = $a;
        }
    }
    return [$positional, $options];
}

/** Fragt "… [j/N]" auf der Kommandozeile; mit $yes (Option --yes) ohne Rückfrage. */
function cli_confirm(string $question, bool $yes): bool
{
    if ($yes) {
        return true;
    }
    echo "$question [j/N] ";
    $answer = strtolower(trim((string) fgets(STDIN)));
    return in_array($answer, ['j', 'ja', 'y', 'yes'], true);
}

/** Liste für die Ausgabe, höchstens $max Zeilen. @param string[] $lines */
function cli_list(array $lines, int $max = 20): string
{
    $shown = array_map(fn($l) => "    $l", array_slice($lines, 0, $max));
    if (count($lines) > $max) {
        $shown[] = '    … und ' . (count($lines) - $max) . ' weitere';
    }
    return implode("\n", $shown) . "\n";
}

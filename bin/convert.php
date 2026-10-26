<?php /** @noinspection GrazieStyle */

/**
 * Nimmt Videos von einer (fremden) USB-Platte in die Videothek auf.
 *
 * Die Filme bzw. Staffeln müssen bereits angelegt worden sein (Medium "USB", Eigentümer der Platte).
 *
 * Zuordnung Quelle -> Eintrag:
 *   1. Steht beim Medium unter "Dateien" ein Pfad, der relativ zu --src existiert, gilt dies.
 *   2. Sonst wird geraten: Ordner- und Dateiname müssen den Titel (oder Originaltitel, oder den Teil vor " - "/":")
 *      als ganze Wörter enthalten; eine Jahreszahl im Namen muss zum Jahr des Eintrags passen (±1). Staffeln: Name
 *      der Serie im Pfad, Staffelnummer im Pfad ("s02e05", "Staffel 2", "Season 2", "S02") und eine Folgennummer im
 *      Dateinamen (file_episode_number()).
 *   Mehrdeutiges bleibt liegen. Am Ende stehen die Einträge ohne Quelle und die Videos ohne Eintrag; für diese den
 *   Pfad im Formular eintragen und erneut starten. --dry-run zeigt nur die Zuordnung und den Plan.
 *
 * Umwandeln: Mehrteiler (cd1, cd2, part, teil, …, siehe PART_RE) werden zusammengefügt; je Datei copy (schon H.264 +
 * AAC/MP3 in MP4, nur neu verpackt), remux (Video übernommen, Ton nach AAC) oder encode (libx264 CRF 18 bzw. --qsv);
 * eine Tonspur (deutsch, sonst englisch mit deutschen bzw. englischen Text-Untertiteln als <name>.<sprache>.vtt
 * daneben). Titel, Jahr, Genres, Regie, Handlung, bei Folgen Serie/Staffel/Folge, und das Poster als Cover schreibt
 * ffmpeg im selben Durchgang mit. Weicht die Dauer ab (mehr als 2 %, mind. 10 s), wird trotzdem abgelegt und gewarnt.
 * Quelldateien bleiben unverändert.
 */

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit("Nur für die Kommandozeile.\n");
}

if (function_exists('sapi_windows_cp_set')) {
    sapi_windows_cp_set(65001);
}

/**
 * Projektordner.
 */
$root = dirname(__DIR__);
// config.php (API_TOKEN, USB_ROOT) nur, wenn vorhanden; im portablen Paket fehlt sie
if (is_file("$root/config.php")) {
    require_once "$root/config.php";
}
require_once "$root/public/api/lib/crud.php"; // nur die Regeln (usb_base_path(), slugify(), normalize_video(), …)

/**
 * Adresse der Videothek auf dem Server (ohne "/" am Ende), wie in `bin/remote.php`; mit --remote=… abweichend.
 *
 * @var string
 */
const REMOTE_URL = 'https://videothek.frank-rohlfing.de';

/**
 * Basic-Auth-Benutzer, wenn --user fehlt.
 *
 * @var string
 */
const REMOTE_DEFAULT_USER = 'frank';

/**
 * Dateiendungen, die als Videos gelten.
 *
 * @var string[]
 */
const VIDEO_EXT = ['avi', 'mkv', 'mp4', 'm4v', 'flv', 'mov', 'wmv', 'mpg', 'mpeg', 'ts', 'm2ts', 'vob', 'webm', 'ogm', 'divx', '3gp'];

/**
 * Erkennt Teile eines Mehrteilers im Dateinamen (cd1, part2, teil 3, …): Treffer 1 = Name ohne Teilnummer, Treffer 2 = Teilnummer.
 *
 * @var string Regulärer Ausdruck
 */
const PART_RE = '/^(.*?)[\s._-]*(?:cd|dvd|disc|disk|part|pt|teil)[\s._-]*(\d{1,2})$/i';

/**
 * Codecs von Text-Untertiteln (ffprobe), die sich nach WebVTT umwandeln lassen.
 *
 * @var string[]
 */
const TEXT_SUBS = ['subrip', 'srt', 'ass', 'ssa', 'mov_text', 'webvtt', 'text'];

/**
 * Sprachcodes für Deutsch.
 *
 * @var string[]
 */
const LANG_DE = ['ger', 'deu', 'de'];

/**
 * Sprachcodes für Englisch.
 *
 * @var string[]
 */
const LANG_EN = ['eng', 'en'];

/**
 * Dateiname des Logs im USB-Ordner (eine JSON-Zeile je Ereignis).
 *
 * @var string
 */
const LOG_NAME = 'convert-log.jsonl';

/**
 * Die Art des Mediums, das bearbeitet wird.
 *
 * @var string
 */
const USB_MEDIUM = 'USB';

/**
 * Hilfetext für --help und bei unbekannten Optionen.
 *
 * @noinspection
 * @var string
 */
const HELP = <<<'TXT'
Videos einer USB-Platte in die Videothek aufnehmen

  Schritt 1: Alle Filme bzw. Staffeln in der App anlegen (Medium "USB", Eigentümer der Platte, 
             Dateiname relativ zu `--src` - z. B. "Kick Ass (2010)/kickass.avi"). 
             Fehlt die Anghabe, sucht das Skript über den Namen.
  Schritt 2: Zuordnung prüfen:
             `php bin/convert.php --owner=rohlfing --src=D:\Filme --password=… --dry-run`
  Schritt 3: Film für Film die Videodatei in MP4 umwandeln, Metadaten und Cover einbetten,
             nach <to>\<titel>\<slug>.mp4 legen, Bild und Ton messen, auf dem Server eintragen:
             `php bin/convert.php --owner=rohlfing --src=D:\Filme --password=…`

  Optionen
    --owner=NAME    Pflicht: Eigentümer der USB-Platte (wie beim Medium, z. B. "rohlfing");
                    bearbeitet werden nur seine USB-Medien
    --src=DIR       Quelle (die neue Platte), wird nur gelesen
    --to=DIR        USB-Ordner (Vorgabe: USB_ROOT aus config.php)
    --password=…    Basic-Auth-Passwort des Servers (oder Umgebungsvariable VIDEOTHEK_PASSWORD)
    --api-token=…   API-Token des Servers (oder VIDEOTHEK_API_TOKEN; Vorgabe: API_TOKEN aus config.php)
    --user=NAME     Basic-Auth-Benutzer, Vorgabe "frank"
    --remote=URL    Adresse der Videothek, Vorgabe https://videothek.frank-rohlfing.de
    --only=a,b      nur Einträge, deren Slug so anfängt
    --crf=18        Bildqualität beim Neukodieren (libx264, 0–51): kleiner = besser und größer.
                    18 sieht praktisch aus wie die Vorlage, 23 ist der Standard von x264,
                    +6 halbiert etwa die Dateigröße. Gilt nur für Dateien, die neu kodiert
                    werden (encode); copy und remux übernehmen das Bild unverändert.
    --preset=slow   Gründlichkeit von libx264: ultrafast, superfast, veryfast, faster, fast,
                    medium, slow, slower, veryslow. Langsamer = kleinere Datei bei gleicher
                    Qualität (die bestimmt --crf), nicht besseres Bild. "slow" braucht etwa
                    doppelt so lange wie "medium" und spart rund 5–10 % Platz.
    --qsv           Intel-Hardware-Encoder (Quick Sync) statt libx264: deutlich schneller,
                    etwas größere Dateien bzw. etwas schlechteres Bild; --preset gilt dann
                    nicht, aus --crf wird global_quality = crf + 2
    --dry-run       nur Zuordnung und Plan zeigen
    --yes           ohne Rückfrage

    Abbruch mit Strg+C, erneuter Start setzt fort.

TXT;

// ---------------------------------------------------------------- Optionen

/**
 * Optionen der Kommandozeile (Vorgaben siehe HELP).
 *
 * @var array<string, string|bool> $opt
 */
$opt = ['src' => '', 'to' => defined('USB_ROOT') ? USB_ROOT : '', 'only' => '', 'owner' => '', 'crf' => '18',
    'preset' => 'slow', 'qsv' => false, 'dry-run' => false, 'yes' => false, 'remote' => REMOTE_URL,
    'user' => REMOTE_DEFAULT_USER, 'password' => (string) getenv('VIDEOTHEK_PASSWORD'),
    'api-token' => (string) getenv('VIDEOTHEK_API_TOKEN') ?: (defined('API_TOKEN') ? API_TOKEN : '')];
/**
 * Argumente der Kommandozeile ohne Skriptnamen.
 *
 * @var string[] $args
 */
$args = array_slice($_SERVER['argv'] ?? [], 1);
if (!$args || array_intersect($args, ['-h', '--help', '/?'])) {
    echo HELP;
    exit(0);
}
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = is_bool($opt[$m[1]]) ? true : ($m[2] ?? '');
        continue;
    }
    fwrite(STDERR, "Unbekannte Option: $arg\n\n" . HELP);
    exit(1);
}
/**
 * Quellordner (--src) ohne abschließenden Backslash.
 */
$SRC = rtrim($opt['src'], '\\/');
/**
 * USB-Ordner (--to) ohne abschließenden Backslash.
 */
$TO = rtrim($opt['to'], '\\/');
/**
 * Slug-Anfänge aus --only; leer = alle Einträge.
 *
 * @var string[] $ONLY
 */
$ONLY = array_values(array_filter(array_map(fn($s) => strtolower(trim($s)), explode(',', $opt['only'])), 'strlen'));
/**
 * Adresse der Videothek auf dem Server ohne abschließenden Slash.
 */
$REMOTE = rtrim($opt['remote'], '/');
/**
 * Pfad der Log-Datei im USB-Ordner.
 */
$LOG = $TO . '\\' . LOG_NAME;
foreach ([
    'Quelle fehlt: ' . ($SRC ?: '(--src angeben)') => $SRC === '' || !is_dir($SRC),
    'USB-Ordner fehlt: ' . ($TO ?: '(--to angeben)') => $TO === '' || !is_dir($TO),
    'Passwort für den Server fehlt (--password=… oder VIDEOTHEK_PASSWORD)' => $opt['password'] === '',
    'API-Token für den Server fehlt (--api-token=…, VIDEOTHEK_API_TOKEN oder API_TOKEN in config.php)' => $opt['api-token'] === '',
] as $message => $failed) {
    if ($failed) {
        fwrite(STDERR, "$message\n");
        exit(1);
    }
}
foreach (['ffmpeg', 'ffprobe'] as $tool) {
    exec("where $tool 2>NUL", $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "$tool nicht im PATH gefunden.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------- Ausgabe

/**
 * @param float $sec Dauer in Sekunden
 * @return string Die Dauer, z. B. "1h05m" oder "12m30s"
 */
function fmt_time(float $sec): string
{
    $sec = max(0, (int) round($sec));
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return $h > 0 ? sprintf('%dh%02dm', $h, $m) : sprintf('%dm%02ds', $m, $sec % 60);
}

/**
 * @param float $b Größe in Bytes
 * @return string Die Größe, z. B. "1.4 GB" oder "700 MB"
 */
function fmt_bytes(float $b): string
{
    return $b >= 1 << 30 ? sprintf('%.1f GB', $b / (1 << 30)) : sprintf('%.0f MB', $b / (1 << 20));
}

/**
 * Konsolenbreite minus 1 (damit die Zeile nicht umbricht).
 *
 * @return int Die Anzahl der Zeichen je Zeile
 */
function term_width(): int
{
    static $w = null;
    if ($w === null) {
        $w = (int) getenv('COLUMNS');
        if ($w <= 0 && preg_match('/(?:Spalten|Columns)\s*:\s*(\d+)/i', (string) shell_exec('mode con 2>NUL'), $m)) {
            $w = (int) $m[1];
        }
        $w = $w > 20 ? $w - 1 : 119;
    }
    return $w;
}

/**
 * Zeile ausgeben; steht gerade eine Fortschrittszeile da, wird sie vorher gelöscht.
 *
 * @param string $msg Die auszugebende Zeile (ohne Zeilenumbruch)
 * @return void
 */
function println(string $msg = ''): void
{
    if (!empty($GLOBALS['progressShown'])) {
        echo "\r" . str_repeat(' ', term_width()) . "\r";
        $GLOBALS['progressShown'] = false;
    }
    echo $msg . "\n";
}

/**
 * Fragt "… [j/N]" und liest die Antwort.
 *
 * @param string $question Die Frage (ohne "[j/N]")
 * @return bool true bei "j", "ja", "y" oder "yes"
 */
function ask_yes(string $question): bool
{
    echo $question . ' [j/N] ';
    return in_array(strtolower(trim((string) fgets(STDIN))), ['j', 'ja', 'y', 'yes'], true);
}

// ---------------------------------------------------------------- Server

/**
 * Anfrage an die API der Videothek auf dem Server (Basic Auth und X-API-Token wie `bin/remote.php`).
 * Ohne curl, nur mit PHP-Streams (das portable Paket hat nur openssl).
 *
 * @param string $method HTTP-Methode
 * @param string $endpoint Datei unter api/, z. B. "movies.php"
 * @param array $query Query-Parameter
 * @param array|null $json Daten, die als JSON gesendet werden; null = ohne Body
 * @return array{status: int, body: string, type: string}
 * @throws RuntimeException wenn der Server nicht erreichbar ist oder die Anmeldung scheitert
 */
function api(string $method, string $endpoint, array $query = [], ?array $json = null): array
{
    global $REMOTE, $opt;
    $url = "$REMOTE/api/$endpoint" . ($query ? '?' . http_build_query($query) : '');
    $headers = ['Authorization: Basic ' . base64_encode("{$opt['user']}:{$opt['password']}"), 'X-API-Token: ' . $opt['api-token']];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        'ignore_errors' => true,
        'timeout' => 60,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    if ($body === false || !$responseHeaders) {
        throw new RuntimeException("Server nicht erreichbar ($url)" . (error_get_last() ? ': ' . error_get_last()['message'] : ''));
    }
    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m) ? (int) $m[1] : 0;
    $type = '';
    foreach ($responseHeaders as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $type = strtolower(trim(substr($h, 13)));
        }
    }
    if ($status === 401) {
        throw new RuntimeException(str_contains($body, 'API-Token')
            ? 'Token abgelehnt: Das API-Token (--api-token bzw. API_TOKEN in config.php) stimmt nicht mit dem des Servers überein.'
            : "Anmeldung abgelehnt: Benutzer '{$opt['user']}' bzw. Passwort stimmt nicht.");
    }
    return ['status' => $status, 'body' => $body, 'type' => $type];
}

/**
 * JSON-Antwort der API; Fehler (4xx/5xx) als Exception mit der Meldung des Servers.
 *
 * @param string $method HTTP-Methode
 * @param string $endpoint Datei unter api/, z. B. "movies.php"
 * @param array $query Query-Parameter
 * @param array|null $json Daten, die als JSON gesendet werden; null = ohne Body
 * @return array Die dekodierte JSON-Antwort
 * @throws RuntimeException
 */
function api_json(string $method, string $endpoint, array $query = [], ?array $json = null): array
{
    $res = api($method, $endpoint, $query, $json);
    $data = json_decode($res['body'], true);
    if ($res['status'] >= 400 || !is_array($data)) {
        $message = is_array($data) ? ($data['error'] ?? '') . (isset($data['details']) ? ': ' . implode('; ', $data['details']) : '')
            : trim(strip_tags($res['body']));
        throw new RuntimeException("$endpoint: HTTP {$res['status']} " . mb_substr($message, 0, 300));
    }
    return $data;
}

/**
 * Poster des Eintrags vom Server als Datei im Temp-Ordner (für das Cover) oder null.
 *
 * @param array $movie Der Eintrag vom Server
 * @return string|null Pfad der Bilddatei; null, wenn es kein Poster gibt oder der Abruf scheitert
 */
function poster_file(array $movie): ?string
{
    if (empty($movie['poster'])) {
        return null;
    }
    try {
        $res = api('GET', 'image.php', ['slug' => $movie['slug'], 'type' => 'poster']);
    } catch (RuntimeException) {
        return null;
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][explode(';', $res['type'])[0]] ?? null;
    if ($res['status'] !== 200 || $ext === null) {
        return null;
    }
    $file = sys_get_temp_dir() . "/convert-{$movie['slug']}.$ext";
    file_put_contents($file, $res['body']);
    register_shutdown_function(fn() => @unlink($file));
    return $file;
}

// ---------------------------------------------------------------- Log

/**
 * @return array[] alle Zeilen des Logs
 */
function read_log(): array
{
    global $LOG;
    $entries = [];
    foreach (is_file($LOG) ? file($LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $e = json_decode($line, true);
        if (is_array($e)) {
            $entries[] = $e;
        }
    }
    return $entries;
}

/**
 * Hängt eine Zeile (mit Zeitstempel "time") an das Log an.
 *
 * @param array $e Die Daten des Ereignisses
 * @return void
 */
function log_entry(array $e): void
{
    global $LOG;
    file_put_contents($LOG, json_encode($e + ['time' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX);
}

/**
 * Pfad (relativ zum USB-Ordner, mit "/") unter einem Ordner.
 *
 * @param string $path Pfad relativ zum Ordner, mit "/"
 * @param string $root Ordner, unter dem die Datei liegt
 * @return string Der Windows-Pfad (mit "\")
 */
function on_disk(string $path, string $root): string
{
    return $root . '\\' . str_replace('/', '\\', $path);
}

// ---------------------------------------------------------------- Einträge vom Server

try {
    /**
     * Alle Einträge vom Server, nach Slug.
     *
     * @var array<string, array> $MOVIES
     */
    $MOVIES = array_column(api_json('GET', 'movies.php')['movies'] ?? [], null, 'slug');
    // Token und Endpunkt gleich prüfen, nicht erst nach dem ersten umgewandelten Film: ein leerer PUT ergibt mit
    // gültigem Token 400 (Angaben fehlen), mit falschem 401 (api() meldet es); fehlt media.php, 404 ohne JSON
    $check = api('PUT', 'media.php', [], []);
    if ($check['status'] === 404 || !is_array(json_decode($check['body'], true))) {
        throw new RuntimeException("$REMOTE/api/media.php fehlt auf dem Server: public/api/media.php hochladen.");
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
/**
 * Eigentümer, die bei mindestens einem Medium vorkommen (Kennung => true).
 *
 * @var array<string, true> $owners
 */
// Eigentümer: Pflicht und muss als Eigentümer eines Mediums vorkommen (Tippfehler sonst = "nichts zu tun")
$owners = [];
foreach ($MOVIES as $movie) {
    foreach ($movie['media'] ?? [] as $md) {
        $owners[$md['owner']] = true;
    }
}
if ($opt['owner'] === '' || !isset($owners[$opt['owner']])) {
    fwrite(STDERR, ($opt['owner'] === '' ? '--owner fehlt' : "Unbekannter Eigentümer \"{$opt['owner']}\"")
        . ' (bekannt: ' . implode(', ', array_keys($owners)) . ")\n");
    exit(1);
}

// ---------------------------------------------------------------- ffprobe-Auswertung

/**
 * Liest die Streams und das Format einer Datei mit ffprobe.
 *
 * @param string $file Pfad der Datei
 * @return array|null Die ffprobe-Ausgabe; null, wenn die Datei nicht lesbar ist
 */
function probe(string $file): ?array
{
    $proc = proc_open(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $file],
        [1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    $json = stream_get_contents($pipes[1]);
    proc_close($proc);
    $data = json_decode((string) $json, true);
    return is_array($data) && isset($data['streams']) ? $data : null;
}

/**
 * Dauer einer Datei laut ffprobe.
 *
 * @param array $p Ausgabe von `probe()`
 * @return float Die Dauer in Sekunden; 0, wenn unbekannt
 */
function duration(array $p): float
{
    $d = (float) ($p['format']['duration'] ?? 0);
    if ($d <= 0) {
        foreach ($p['streams'] as $s) {
            $d = max($d, (float) ($s['duration'] ?? 0));
        }
    }
    return $d;
}

/**
 * Erste echte Videospur (keine eingebetteten Cover).
 *
 * @param array $p Ausgabe von `probe()`
 * @return array|null Die Videospur; null, wenn es keine gibt
 */
function video_stream(array $p): ?array
{
    foreach ($p['streams'] as $s) {
        if ($s['codec_type'] === 'video' && empty($s['disposition']['attached_pic'])) {
            return $s;
        }
    }
    return null;
}

/**
 * Alle Tonspuren einer Datei.
 *
 * @param array $p Ausgabe von `probe()`
 * @return array Die Tonspuren
 */
function audio_streams(array $p): array
{
    return array_values(array_filter($p['streams'], fn($s) => $s['codec_type'] === 'audio'));
}

/**
 * Sprache der Spur; fehlt die Angabe, notfalls aus dem Titel (z. B. "AC3_192_DE", "German DTS").
 *
 * @param array $s Eine Spur aus `probe()`
 * @return string Der Sprachcode (z. B. "ger", "eng"); "und", wenn unbekannt
 */
function lang(array $s): string
{
    $l = strtolower($s['tags']['language'] ?? 'und');
    if ($l !== 'und') {
        return $l;
    }
    $title = $s['tags']['title'] ?? '';
    if (preg_match('/(?<![a-z])(de|ger|deu|german|deutsch)(?![a-z])/i', $title)) {
        return 'ger';
    }
    if (preg_match('/(?<![a-z])(en|eng|english|englisch)(?![a-z])/i', $title)) {
        return 'eng';
    }
    return 'und';
}

/**
 * Erste Spur der Liste in einer der Sprachen, Kommentarspuren nur notfalls.
 *
 * @param array $streams Spuren aus probe()
 * @param array $langs Gesuchte Sprachcodes
 * @return int|null Der Index der Spur in $streams; null, wenn keine passt
 */
function first_in_lang(array $streams, array $langs): ?int
{
    $found = null;
    foreach ($streams as $i => $s) {
        if (in_array(lang($s), $langs, true)) {
            if (!preg_match('/comment|kommentar/i', $s['tags']['title'] ?? '')) {
                return $i;
            }
            $found ??= $i;
        }
    }
    return $found;
}

/**
 * Die eine Tonspur, die ins Ziel kommt (Index innerhalb 0:a), und ob Untertitel dazu gehören: die deutsche; ohne
 * Sprachangaben die Standard- bzw. erste Spur; gibt es nur andere Sprachen, die englische (sonst die erste) – dann
 * mit Untertiteln. Null, wenn es keine Tonspur gibt.
 *
 * @param array $p Ausgabe von `probe()`
 * @return array{0: int, 1: bool}|null
 */
function pick_audio(array $p): ?array
{
    $audios = audio_streams($p);
    if (!$audios) {
        return null;
    }
    if (($i = first_in_lang($audios, LANG_DE)) !== null) {
        return [$i, false];
    }
    if (!array_filter($audios, fn($a) => lang($a) !== 'und')) {
        foreach ($audios as $i => $a) {
            if (!empty($a['disposition']['default'])) {
                return [$i, false];
            }
        }
        return [0, false];
    }
    return [first_in_lang($audios, LANG_EN) ?? 0, true];
}

/**
 * Kann das Bild ohne Neukodieren übernommen werden (H.264, 4:2:0)?
 *
 * @param array|null $v Die Videospur; null = keine
 * @return bool true, wenn es übernommen werden kann
 */
function video_ok(?array $v): bool
{
    return $v !== null && $v['codec_name'] === 'h264' && in_array($v['pix_fmt'] ?? '', ['yuv420p', 'yuvj420p'], true);
}

/**
 * Kann der Ton ohne Umwandlung übernommen werden (AAC oder MP3)?
 *
 * @param array $a Die Tonspur
 * @return bool true, wenn er übernommen werden kann
 */
function audio_ok(array $a): bool
{
    return in_array($a['codec_name'], ['aac', 'mp3'], true);
}

/**
 * Bitrate für Ton, der nach AAC umgewandelt wird.
 *
 * @param int $channels Anzahl der Kanäle
 * @return string Die Bitrate für ffmpeg, z. B. "192k"
 */
function audio_bitrate(int $channels): string
{
    return max(192, 64 * min($channels, 6)) . 'k';
}

/**
 * Hat die Videospur Zeilensprung?
 *
 * @param array|null $v Die Videospur; null = keine
 * @return bool true bei Zeilensprung
 */
function interlaced(?array $v): bool
{
    return $v !== null && in_array($v['field_order'] ?? '', ['tt', 'bb', 'tb', 'bt'], true);
}

/**
 * Bild einer Datei (normalize_video()), ohne Cover: Video-Bitrate aus dem Stream, sonst aus der Gesamtbitrate geschätzt.
 *
 * @param array $probe Ausgabe von `probe()`
 * @return array|null Die Angaben wie normalize_video(); null, wenn es kein Bild gibt
 */
function video_facts(array $probe): ?array
{
    $video = video_stream($probe);
    if ($video === null) {
        return null;
    }
    $audioBits = array_sum(array_map(fn($a) => (int) ($a['bit_rate'] ?? 192000), audio_streams($probe)));
    $bits = (int) ($video['bit_rate'] ?? 0);
    if ($bits <= 0 && (int) ($probe['format']['bit_rate'] ?? 0) > 0) {
        $bits = (int) $probe['format']['bit_rate'] - $audioBits;
    }
    [$fn, $fd] = array_map('intval', explode('/', ($video['avg_frame_rate'] ?? '') ?: ($video['r_frame_rate'] ?? '0/1'))) + [0, 1];
    return normalize_video([
        'codec' => $video['codec_name'] ?? '',
        'width' => $video['width'] ?? 0,
        'height' => $video['height'] ?? 0,
        'sar' => $video['sample_aspect_ratio'] ?? '1:1',
        'fps' => $fd > 0 ? $fn / $fd : null,
        'kbps' => $bits > 0 ? (int) round($bits / 1000) : null,
    ]);
}

/**
 * Ton einer Datei (normalize_audio()): die erste Tonspur.
 *
 * @param array $probe Ausgabe von `probe()?
 * @return array|null Die Angaben wie normalize_audio(); null, wenn es keinen Ton gibt
 */
function audio_facts(array $probe): ?array
{
    $a = audio_streams($probe)[0] ?? null;
    if ($a === null) {
        return null;
    }
    $bits = (int) ($a['bit_rate'] ?? 0);
    return normalize_audio([
        'codec' => $a['codec_name'] ?? '',
        'channels' => $a['channels'] ?? null,
        'kbps' => $bits > 0 ? (int) round($bits / 1000) : null,
        'lang' => $a['tags']['language'] ?? 'und',
    ]);
}

// ---------------------------------------------------------------- Zuordnung

/**
 * Videos unter --src, gruppiert (Teile eines Mehrteilers zusammen), natürlich sortiert.
 *
 * @param string $src Quellordner (--src)
 * @return array<string, array{key: string, files: string[], rel: string[], hay: string, episode: ?int, season: ?int}>
 *   key: relativer Pfad ohne Teilnummer und Endung (mit "/"); hay: key als Slug (für die Namenssuche)
 */
function source_groups(string $src): array
{
    $groups = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || !in_array(strtolower($f->getExtension()), VIDEO_EXT, true)) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
        $dir = dirname($rel) === '.' ? '' : dirname($rel) . '/';
        $base = $f->getBasename('.' . $f->getExtension());
        $key = preg_match(PART_RE, $base, $m) ? $dir . rtrim($m[1], ' ._-') : $dir . $base;
        $groups[$key][] = $rel;
    }
    $result = [];
    foreach ($groups as $key => $rels) {
        natcasesort($rels);
        $rels = array_values($rels);
        $result[$key] = source_group($key, $rels, $src);
    }
    uksort($result, 'strnatcasecmp');
    return $result;
}

/**
 * Baut die Gruppe einer Quelle.
 *
 * @param string $key Pfad ohne Teilnummer und Endung (mit "/")
 * @param array $rels Dateien der Gruppe, relativ zu $src (mit "/")
 * @param string $src Quellordner (--src)
 * @return array Die Gruppe (key, rel, files, hay, episode, season)
 */
function source_group(string $key, array $rels, string $src): array
{
    return [
        'key' => $key,
        'rel' => $rels,
        'files' => array_map(fn($r) => on_disk($r, $src), $rels),
        'hay' => slugify($key),
        'episode' => file_episode_number(basename($key)),
        'season' => path_season($key),
    ];
}

/**
 * Staffelnummer aus einem Pfad: "s02e05", "Staffel 2", "2. Staffel", "Season 2", "S02" (als Ordner).
 *
 * @param string $path Pfad der Quelle
 * @return int|null Die Staffelnummer; null, wenn keine zu erkennen ist
 */
function path_season(string $path): ?int
{
    if (preg_match('/(?:^|[^a-z0-9])s0*(\d{1,2})[\s._-]*e\d/i', basename($path), $m)) {
        return (int) $m[1];
    }
    $slug = '-' . slugify($path) . '-';
    if (preg_match('/-(?:staffel|season|s)-?0*(\d{1,2})-/', $slug, $m) || preg_match('/-0*(\d{1,2})-staffel-/', $slug, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * Namen, unter denen ein Eintrag im Dateinamen stehen kann: Titel, Originaltitel, jeweils auch ohne Untertitel.
 *
 * @param array $movie Der Eintrag
 * @return array Die Namen als Slug
 */
function name_variants(array $movie): array
{
    $names = $movie['type'] === TYPE_SEASON ? [$movie['series']['name'] ?? '', $movie['originalTitle'] ?? '']
        : [$movie['title'], $movie['originalTitle'] ?? ''];
    foreach ($names as $n) {
        $parts = preg_split('/\s+[-–]\s+|:\s+/u', (string) $n);
        if (count($parts) > 1) {
            $names[] = $parts[0];
        }
    }
    return array_values(array_unique(array_filter(array_map('slugify', $names), 'strlen')));
}

/**
 * Wie gut passt eine Quelle zu einem Eintrag: Länge des längsten Namens, der als ganze Wörter im Pfad steht, plus 5
 * bei passendem Jahr. 0, wenn kein Name passt, eine andere Jahreszahl im Pfad steht oder ein kurzer Name (unter 4
 * Zeichen, etwa "21") ohne Jahr passt.
 *
 * @param array $movie Der Eintrag
 * @param array $group Eine Quelle aus source_groups()
 * @return int Die Güte der Übereinstimmung; 0 = passt nicht
 */
function match_score(array $movie, array $group): int
{
    $hay = '-' . $group['hay'] . '-';
    $best = 0;
    $rest = $hay;
    foreach (name_variants($movie) as $name) {
        if (strlen($name) > $best && str_contains($hay, "-$name-")) {
            $best = strlen($name);
            $rest = str_replace("-$name-", '-', $hay);
        }
    }
    if ($best === 0) {
        return 0;
    }
    $year = $movie['year'] ?? null;
    preg_match_all('/-(19\d\d|20\d\d)-/', $rest, $m);
    $years = array_map('intval', $m[1]);
    if ($year !== null && $years) {
        if (!array_filter($years, fn($y) => abs($y - $year) <= 1)) {
            return 0;
        }
        return $best + 5;
    }
    return $best >= 4 ? $best : 0;
}

/**
 * Ordnet den offenen Einträgen Quellen zu.
 *
 * @param array[] $entries [slug => [movie, medium-Index]]
 * @param array[] $groups source_groups()
 * @return array{0: array, 1: array, 2: array} [slug => [Gruppen, Art ("Pfad" | "erkannt")]], [slug => Grund],
 *   übrige Gruppen
 */
function assign_sources(array $entries, array $groups): array
{
    global $SRC;
    $assigned = [];
    $open = [];
    $free = $groups;

    // 1. Pfade aus dem Formular, die unter --src existieren
    foreach ($entries as $slug => [$movie, $i]) {
        $paths = $movie['media'][$i]['paths'] ?? [];
        $found = array_values(array_filter($paths, fn($p) => is_file(on_disk($p, $SRC))));
        if (!$found || count($found) !== count($paths)) {
            continue;
        }
        $byKey = [];
        foreach ($found as $p) {
            $dir = dirname($p) === '.' ? '' : dirname($p) . '/';
            $base = pathinfo($p, PATHINFO_FILENAME);
            $byKey[preg_match(PART_RE, $base, $m) ? $dir . rtrim($m[1], ' ._-') : $dir . $base][] = $p;
        }
        $own = [];
        foreach ($byKey as $key => $rels) {
            natcasesort($rels);
            $own[] = source_group($key, array_values($rels), $SRC);
            unset($free[$key]);
        }
        $assigned[$slug] = [$own, 'Pfad'];
    }

    // 2. Staffeln: Folgen der Serie mit passender Staffelnummer
    $seasonsOfSeries = [];
    foreach ($entries as $slug => [$movie]) {
        if ($movie['type'] === TYPE_SEASON) {
            $seasonsOfSeries[slugify($movie['series']['name'] ?? $movie['title'])][] = $slug;
        }
    }
    foreach ($entries as $slug => [$movie]) {
        if ($movie['type'] !== TYPE_SEASON || isset($assigned[$slug])) {
            continue;
        }
        $only = count($seasonsOfSeries[slugify($movie['series']['name'] ?? $movie['title'])]) === 1;
        $mine = array_filter($free, fn($g) => $g['episode'] !== null && match_score(['year' => null] + $movie, $g) > 0
            && ($g['season'] === ($movie['season'] ?? null) || ($g['season'] === null && $only)));
        if (!$mine) {
            $open[$slug] = 'keine Folgen gefunden';
            continue;
        }
        $numbers = array_count_values(array_column($mine, 'episode'));
        if (max($numbers) > 1) {
            $twice = array_keys(array_filter($numbers, fn($c) => $c > 1));
            $open[$slug] = 'Folge ' . implode(', ', $twice) . ' mehrfach: ' . implode(', ',
                    array_column(array_filter($mine, fn($g) => in_array($g['episode'], $twice, true)), 'key'));
            continue;
        }
        usort($mine, fn($a, $b) => $a['episode'] <=> $b['episode']);
        foreach ($mine as $g) {
            unset($free[$g['key']]);
        }
        $assigned[$slug] = [$mine, 'erkannt'];
    }

    // 3. Filme: beste Quelle ohne Folgennummer; gleich gute Kandidaten = mehrdeutig
    $candidates = [];
    foreach ($entries as $slug => [$movie]) {
        if ($movie['type'] === TYPE_SEASON || isset($assigned[$slug])) {
            continue;
        }
        $scores = [];
        foreach ($free as $key => $g) {
            if ($g['episode'] === null && ($s = match_score($movie, $g)) > 0) {
                $scores[$key] = $s;
            }
        }
        arsort($scores);
        $candidates[$slug] = $scores;
    }
    uasort($candidates, fn($a, $b) => (reset($b) ?: 0) <=> (reset($a) ?: 0));
    foreach ($candidates as $slug => $scores) {
        $scores = array_filter($scores, fn($s, $key) => isset($free[$key]), ARRAY_FILTER_USE_BOTH);
        if (!$scores) {
            $open[$slug] = 'keine passende Datei gefunden';
            continue;
        }
        $keys = array_keys($scores);
        if (count($keys) > 1 && $scores[$keys[0]] === $scores[$keys[1]]) {
            $open[$slug] = 'mehrdeutig: ' . implode(', ', array_slice(array_keys(array_filter($scores, fn($s) => $s === $scores[$keys[0]])), 0, 4));
            continue;
        }
        $assigned[$slug] = [[$free[$keys[0]]], 'erkannt'];
        unset($free[$keys[0]]);
    }
    return [$assigned, $open, $free];
}

// ---------------------------------------------------------------- Metadaten

/**
 * Metadaten einer MP4-Datei des Eintrags (ffmpeg-Schlüssel für MP4/iTunes). $episode: Folgennummer bei Staffeln.
 *
 * @param array $movie Der Eintrag
 * @param int|null $episode Folgennummer bei Staffeln; null bei Filmen
 * @return array<string, string>
 */
function file_metadata(array $movie, ?int $episode): array
{
    $plot = (string) ($movie['plot'] ?? '');
    $meta = [
        'title' => $movie['title'],
        'date' => (string) ($movie['year'] ?? ''),
        'genre' => implode(', ', $movie['genres'] ?? []),
    ];
    if ($movie['type'] === TYPE_SEASON) {
        $e = array_values(array_filter($movie['episodes'] ?? [], fn($x) => $x['number'] === $episode))[0] ?? [];
        $show = $movie['series']['name'] ?? $movie['title'];
        $code = (($movie['season'] ?? null) !== null ? sprintf('S%02d', $movie['season']) : '') . sprintf('E%02d', $episode ?? 0);
        $plot = (string) ($e['plot'] ?? '') ?: $plot;
        $meta = array_merge($meta, [
            'title' => "$show – $code" . (!empty($e['title']) ? ' ' . $e['title'] : ''),
            'artist' => (string) ($movie['creator'] ?? ''),
            'show' => $show,
            'season_number' => (string) ($movie['season'] ?? ''),
            'episode_sort' => (string) ($episode ?? ''),
            'episode_id' => $code,
            'media_type' => '10',
        ]);
    } else {
        $meta += ['artist' => (string) ($movie['director'] ?? ''), 'media_type' => '9'];
    }
    $meta['description'] = mb_strimwidth($plot, 0, 255, '…');
    $meta['synopsis'] = $plot;
    return array_filter($meta, 'strlen');
}

// ---------------------------------------------------------------- ffmpeg-Argumente

/**
 * Video-Encoder für die erste Videospur (Spur 1 ist das Cover).
 *
 * @return array Die ffmpeg-Argumente
 */
function encoder_args(): array
{
    global $opt;
    if ($opt['qsv']) {
        return ['-c:v:0', 'h264_qsv', '-preset:v:0', 'veryslow', '-global_quality:v:0', (string) max(1, (int) $opt['crf'] + 2),
            '-look_ahead:v:0', '1', '-pix_fmt:v:0', 'nv12'];
    }
    return ['-c:v:0', 'libx264', '-preset:v:0', $opt['preset'], '-crf:v:0', $opt['crf'], '-profile:v:0', 'high',
        '-pix_fmt:v:0', 'yuv420p'];
}

/**
 * Erste Videospur kopieren bzw. neu kodieren (mit yadif bei Zeilensprung).
 *
 * @param array $job Der Auftrag
 * @param array|null $v Die Videospur der Quelle; null = keine
 * @return array Die ffmpeg-Argumente
 */
function video_codec_args(array $job, ?array $v): array
{
    if ($job['mode'] !== 'encode') {
        return ['-c:v:0', 'copy', '-tag:v:0', 'avc1'];
    }
    return array_merge(encoder_args(), interlaced($v) ? ['-filter:v:0', 'yadif=mode=send_frame:deint=interlaced'] : []);
}

/**
 * Ton: übernehmen, wenn AAC/MP3, sonst AAC; Sprache und Standard-Spur setzen.
 *
 * @param array $audios Die Tonspuren, die ins Ziel kommen
 * @return array Die ffmpeg-Argumente
 */
function audio_args(array $audios): array
{
    $args = [];
    foreach ($audios as $oi => $a) {
        if (audio_ok($a)) {
            array_push($args, "-c:a:$oi", 'copy');
        } else {
            $ch = (int) ($a['channels'] ?? 2);
            array_push($args, "-c:a:$oi", 'aac', "-b:a:$oi", audio_bitrate($ch));
            if ($ch >= 6) {
                // 5.1(side) aus DTS/AC3 bzw. 7.1 auf Standard-5.1, sonst landet "unknown" als Kanalbelegung im MP4
                array_push($args, "-filter:a:$oi", 'aformat=channel_layouts=5.1');
            }
        }
        array_push($args, "-disposition:a:$oi", $oi === 0 ? 'default' : '0', "-metadata:s:a:$oi", 'language=' . lang($a));
    }
    return $args;
}

/**
 * Cover als zweite Videospur (Eingabe Nummer $input), Metadaten des Eintrags statt derer der Quelle, MP4-Optionen.
 *
 * @param array $job Der Auftrag
 * @param int $input Nummer der Eingabe, die das Cover liefert
 * @param string $out Zieldatei
 * @return array Die ffmpeg-Argumente
 */
function finish_args(array $job, int $input, string $out): array
{
    $args = [];
    if ($job['poster'] !== null) {
        array_push($args, '-map', "$input:0", '-c:v:1', 'mjpeg', '-pix_fmt:v:1', 'yuvj420p', '-disposition:v:1', 'attached_pic');
    }
    array_push($args, '-map_metadata', '-1');
    foreach ($job['meta'] as $k => $v) {
        array_push($args, '-metadata', "$k=$v");
    }
    return array_merge($args, ['-max_muxing_queue_size', '4096', '-avoid_negative_ts', 'make_zero', '-movflags', '+faststart',
        '-f', 'mp4', $out]);
}

/**
 * Eingabe für das Cover.
 *
 * @param array $job Der Auftrag
 * @return array Die ffmpeg-Argumente; leer ohne Poster
 */
function poster_input(array $job): array
{
    return $job['poster'] !== null ? ['-i', $job['poster']] : [];
}

/**
 * Nur wenn der Film keine deutsche Tonspur hat: die deutschen Text-Untertitel (ersatzweise die englischen) als
 * zusätzliche WebVTT-Ausgaben; liefert [Argumente, [tmp => final]].
 *
 * @param array $probe Ausgabe von probe() der Quelle
 * @param string $outBase Zielpfad ohne Endung
 * @return array [ffmpeg-Argumente, [temporäre Datei => endgültige Datei]]
 */
function subtitle_outputs(array $probe, string $outBase): array
{
    $args = [];
    $files = [];
    $n = [];
    if (!(pick_audio($probe)[1] ?? false)) {
        return [$args, $files];
    }
    $subs = array_filter($probe['streams'], fn($s) => $s['codec_type'] === 'subtitle' && in_array($s['codec_name'], TEXT_SUBS, true));
    $de = array_filter($subs, fn($s) => in_array(lang($s), LANG_DE, true));
    $subs = $de ?: array_filter($subs, fn($s) => in_array(lang($s), LANG_EN, true)) ?: $subs;
    foreach ($subs as $s) {
        $l = lang($s);
        $n[$l] = ($n[$l] ?? 0) + 1;
        $name = $outBase . '.' . $l . ($n[$l] > 1 ? '.' . $n[$l] : '') . (!empty($s['disposition']['forced']) ? '.forced' : '') . '.vtt';
        array_push($args, '-map', '0:' . $s['index'], '-c:s', 'webvtt', '-f', 'webvtt', $name . '.part');
        $files[$name . '.part'] = $name;
    }
    return [$args, $files];
}

/**
 * Bei AVI neue Zeitstempel erzeugen lassen.
 *
 * @param string $file Quelldatei
 * @return array Die ffmpeg-Argumente
 */
function genpts(string $file): array
{
    return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'avi' ? ['-fflags', '+genpts'] : [];
}

/**
 * Eine Datei: Video + gewählte Tonspur, Cover, Metadaten; Untertitel als eigene Ausgaben.
 *
 * @param array $job Der Auftrag
 * @param string $out Zieldatei (zunächst als .part)
 * @param string $outBase Zielpfad ohne Endung
 * @param bool $withSubs false lässt die Untertitel weg
 * @return array [ffmpeg-Argumente, [temporäre Datei => endgültige Datei der Untertitel]]
 */
function build_single(array $job, string $out, string $outBase, bool $withSubs = true): array
{
    $p = $job['probes'][0];
    $v = video_stream($p);
    $args = array_merge(genpts($job['files'][0]), ['-i', $job['files'][0]], poster_input($job), ['-map', '0:' . $v['index']]);
    $ordered = [];
    if ($pick = pick_audio($p)) {
        array_push($args, '-map', "0:a:$pick[0]");
        $ordered[] = audio_streams($p)[$pick[0]];
    }
    $args = array_merge($args, video_codec_args($job, $v), audio_args($ordered), ['-map_chapters', '0', '-sn', '-dn'],
        finish_args($job, 1, $out));
    [$subArgs, $subFiles] = $withSubs ? subtitle_outputs($p, $outBase) : [[], []];
    return [array_merge($args, $subArgs), $subFiles];
}

/**
 * Prüft, ob die Teile für den concat-Demuxer gleich genug aufgebaut sind.
 *
 * @param array $probes Ausgaben von probe() der Teile
 * @return bool true, wenn alle Teile gleich aufgebaut sind
 */
function parts_match(array $probes): bool
{
    $shape = function (array $p) {
        $v = video_stream($p);
        $s = [$v['codec_name'] ?? '', $v['width'] ?? 0, $v['height'] ?? 0, $v['pix_fmt'] ?? '', $v['profile'] ?? ''];
        foreach (audio_streams($p) as $a) {
            $s[] = [$a['codec_name'], $a['sample_rate'] ?? '', $a['channels'] ?? ''];
        }
        return $s;
    };
    $first = $shape($probes[0]);
    return array_all($probes, fn($p) => $shape($p) === $first);
}

/**
 * Mehrteiler ohne Neukodieren zusammenfügen (concat-Demuxer).
 *
 * @param array $job Der Auftrag
 * @param string $out Zieldatei (zunächst als .part)
 * @param string $listFile Pfad der Listendatei für ffmpeg (wird geschrieben)
 * @return array Die ffmpeg-Argumente
 */
function build_concat_demuxer(array $job, string $out, string $listFile): array
{
    $p = $job['probes'][0];
    $v = video_stream($p);
    file_put_contents($listFile, implode("\n", array_map(
            fn($f) => "file '" . str_replace(["\\", "'"], ['/', "'\\''"], $f) . "'", $job['files'])) . "\n");
    $args = array_merge(genpts($job['files'][0]), ['-f', 'concat', '-safe', '0', '-i', $listFile], poster_input($job),
        ['-map', '0:' . $v['index']]);
    $ordered = [];
    if ($pick = pick_audio($p)) {
        array_push($args, '-map', "0:a:$pick[0]");
        $ordered[] = audio_streams($p)[$pick[0]];
    }
    return array_merge($args, video_codec_args($job, $v), audio_args($ordered), ['-map_chapters', '-1', '-sn', '-dn'],
        finish_args($job, 1, $out));
}

/**
 * Fallback für unterschiedliche Teile: concat-Filter, alles neu kodiert, gewählte Tonspur je Teil.
 *
 * @param array $job Der Auftrag
 * @param string $out Zieldatei (zunächst als .part)
 * @return array Die ffmpeg-Argumente
 */
function build_concat_filter(array $job, string $out): array
{
    $v0 = video_stream($job['probes'][0]);
    $sar = 1.0;
    if (!empty($v0['sample_aspect_ratio']) && preg_match('/^(\d+):(\d+)$/', $v0['sample_aspect_ratio'], $m) && $m[2] > 0 && $m[1] > 0) {
        $sar = $m[1] / $m[2];
    }
    $w = (int) (round($v0['width'] * $sar / 2) * 2);
    $h = (int) (round($v0['height'] / 2) * 2);
    $a0 = audio_streams($job['probes'][0])[0] ?? null;
    $layout = $a0 && ($a0['channels'] ?? 2) > 2 ? '5.1' : 'stereo';
    $args = [];
    $filter = '';
    $chain = '';
    foreach ($job['files'] as $i => $f) {
        $args = array_merge($args, genpts($f), ['-i', $f]);
        $p = $job['probes'][$i];
        $vi = video_stream($p)['index'];
        $yadif = interlaced(video_stream($p)) ? 'yadif=mode=send_frame:deint=interlaced,' : '';
        $filter .= "[$i:$vi]{$yadif}scale=$w:$h:force_original_aspect_ratio=decrease,pad=$w:$h:(ow-iw)/2:(oh-ih)/2,setsar=1,format=yuv420p[v$i];";
        if (audio_streams($p)) {
            $ai = pick_audio($p)[0];
            $filter .= "[$i:a:$ai]aresample=48000,aformat=sample_fmts=fltp:channel_layouts={$layout}[a$i];";
        } else {
            $filter .= sprintf('anullsrc=r=48000:cl=%s,atrim=0:%.3f[a%d];', $layout, duration($p), $i);
        }
        $chain .= "[v$i][a$i]";
    }
    $filter .= $chain . 'concat=n=' . count($job['files']) . ':v=1:a=1[v][a]';
    return array_merge($args, poster_input($job), ['-filter_complex', $filter, '-map', '[v]', '-map', '[a]'], encoder_args(),
        ['-c:a', 'aac', '-b:a', audio_bitrate($layout === '5.1' ? 6 : 2), '-metadata:s:a:0', 'language=' . ($a0 ? lang($a0) : 'und'),
            '-map_chapters', '-1'],
        finish_args($job, count($job['files']), $out));
}

/**
 * Ergebniscode von verify(): in Ordnung.
 *
 * @var string
 */
const VERIFY_OK = 'ok';
/**
 * Ergebniscode von verify(): die Datei ist nicht lesbar.
 *
 * @var string
 */
const VERIFY_UNREADABLE = 'unreadable';
/**
 * Ergebniscode von verify(): die Dauer weicht von der Quelle ab.
 *
 * @var string
 */
const VERIFY_DURATION = 'duration';

/**
 * Ergebnis prüfen: lesbar, und passt die Dauer zur Quelle?
 *
 * @param string $file Pfad der Ergebnisdatei
 * @param float $dur Dauer der Quelle in Sekunden
 * @return array{string, string} [Code (VERIFY_*), Meldung]
 */
function verify(string $file, float $dur): array
{
    $p = probe($file);
    if ($p === null || video_stream($p) === null) {
        return [VERIFY_UNREADABLE, 'Ergebnis nicht lesbar'];
    }
    $d = duration($p);
    if ($dur > 0 && abs($d - $dur) > max(10, $dur * 0.02)) {
        return [VERIFY_DURATION, sprintf('Dauer passt nicht: %s statt %s', fmt_time($d), fmt_time($dur))];
    }
    return [VERIFY_OK, ''];
}

// ---------------------------------------------------------------- Fortschritt und Hochrechnung

/**
 * Erfahrungswerte, bis eigene Messwerte vorliegen: Neu verpacken 80 MB/s, Ton umwandeln 60 MB/s, Neukodieren ≈ 1080p
 * in 1,5-facher Echtzeit.
 *
 * @var array<string, float|int> Modus (copy, remux, encode) => Aufwand je Sekunde
 */
const DEFAULT_RATE = ['copy' => 80 * 1048576, 'remux' => 60 * 1048576, 'encode' => 1920 * 1080 * 1.5];

/**
 * Geschätzte Verarbeitungsgeschwindigkeit eines Modus (Aufwand je Sekunde), aus den bisherigen Messwerten.
 *
 * @param string $mode copy, remux oder encode
 * @param array $live [Aufwand, Sekunden] der laufenden Datei
 * @return float Der Aufwand je Sekunde
 */
function rate(string $mode, array $live = [0, 0]): float
{
    global $stats;
    [$c, $w] = $stats[$mode];
    $c += $live[0];
    $w += $live[1];
    return $w > 30 ? $c / $w : DEFAULT_RATE[$mode];
}

/**
 * Fortschrittszeile über alle Dateien (nach geschätzter Zeit gewichtet) mit Rest und Uhrzeit, wann es fertig ist.
 *
 * @param int $idx Index des laufenden Auftrags in $jobs
 * @param float $frac Anteil der laufenden Datei (0 bis 1)
 * @param string $extra Zusatz am Zeilenende, z. B. die ffmpeg-Geschwindigkeit
 * @return void
 */
function show_progress(int $idx, float $frac, string $extra = ''): void
{
    global $jobs, $current;
    static $last = 0;
    $now = microtime(true);
    if ($now - $last < 0.5 && $frac < 1) {
        return;
    }
    $last = $now;
    $job = $jobs[$idx];
    $elapsed = $now - $current['start'];
    $live = $frac > 0.02 && $elapsed > 5 ? [$job['cost'] * $frac, $elapsed] : [0, 0];
    $remaining = (1 - $frac) * $job['cost'] / rate($job['mode'], $live);
    $totalCost = $doneCost = 0.0;
    foreach ($jobs as $i => $p) {
        $t = $p['cost'] / rate($p['mode']);
        $totalCost += $t;
        if ($i < $idx) {
            $doneCost += $t;
        } elseif ($i === $idx) {
            $doneCost += $t * $frac;
        } else {
            $remaining += $p['cost'] / rate($p['mode'], $p['mode'] === $job['mode'] ? $live : [0, 0]);
        }
    }
    $total = $totalCost > 0 ? $doneCost / $totalCost : 1;
    $fill = (int) round($total * 30);
    $eta = strtr(date('D H:i', (int) ($now + $remaining)),
        ['Mon' => 'Mo', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do', 'Fri' => 'Fr', 'Sat' => 'Sa', 'Sun' => 'So']);
    $name = $job['target'];
    if (mb_strlen($name) > 40) {
        $name = '…' . mb_substr($name, -39);
    }
    $line = sprintf("[%s] %5.1f%%  %d/%d  Rest %s (fertig %s)  %s %s %3.0f%% %s", str_repeat('#', $fill) . str_repeat('.', 30 - $fill),
        $total * 100, $idx + 1, count($jobs), fmt_time($remaining), $eta, $job['mode'], $name, $frac * 100, $extra);
    $line = mb_substr($line, 0, term_width());
    echo "\r" . $line . str_repeat(' ', term_width() - mb_strlen($line));
    $GLOBALS['progressShown'] = true;
}

/**
 * Startet ffmpeg, wertet -progress aus und ruft show_progress() auf. Fehlerausgabe landet in einer Temp-Datei
 * (stream_select() funktioniert unter Windows nicht mit Pipes, darum nicht beide Pipes gleichzeitig lesen).
 *
 * @param array $args ffmpeg-Argumente
 * @param int $idx Index des Auftrags in $jobs
 * @param float $dur Dauer der Quelle in Sekunden
 * @return array [true bei Erfolg, Fehlerausgabe von ffmpeg]
 */
function run_ffmpeg(array $args, int $idx, float $dur): array
{
    $errFile = tempnam(sys_get_temp_dir(), 'ffm');
    $cmd = array_merge(['ffmpeg', '-hide_banner', '-nostdin', '-y', '-loglevel', 'error', '-nostats',
        '-progress', 'pipe:1', '-stats_period', '1'], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
    if (!is_resource($proc)) {
        return [false, 'ffmpeg ließ sich nicht starten'];
    }
    $speed = '';
    while (($line = fgets($pipes[1])) !== false) {
        if (preg_match('/^out_time_us=(\d+)/', $line, $m) && $dur > 0) {
            show_progress($idx, min(0.999, $m[1] / 1e6 / $dur), $speed);
        } elseif (preg_match('/^speed=\s*([\d.]+x)/', $line, $m)) {
            $speed = $m[1];
        }
    }
    fclose($pipes[1]);
    $rc = proc_close($proc);
    $err = trim((string) file_get_contents($errFile));
    @unlink($errFile);
    return [$rc === 0, $err];
}

/**
 * Eine Datei umwandeln (Ergebnis zuerst als .part).
 *
 * @param array $job Der Auftrag
 * @param int $idx Index des Auftrags in $jobs
 * @param string $out Zieldatei
 * @return array{0: ?string, 1: ?string, 2: string} [Fehler, Warnung, Weg]
 */
function convert_job(array $job, int $idx, string $out): array
{
    $outBase = substr($out, 0, -4);
    $tmp = $out . '.part';
    @unlink($tmp);
    $subFiles = [];
    $how = $job['mode'];
    if (count($job['files']) === 1) {
        [$args, $subFiles] = build_single($job, $tmp, $outBase);
        [$success, $err] = run_ffmpeg($args, $idx, $job['dur']);
        if (!$success && $subFiles) {
            // Untertitel können ffmpeg scheitern lassen – dann ohne sie erneut
            array_map('unlink', array_filter(array_keys($subFiles), 'is_file'));
            $subFiles = [];
            [$args] = build_single($job, $tmp, $outBase, false);
            [$success, $err] = run_ffmpeg($args, $idx, $job['dur']);
        }
    } else {
        $success = false;
        $err = '';
        if (parts_match($job['probes'])) {
            $list = tempnam(sys_get_temp_dir(), 'cat');
            [$success, $err] = run_ffmpeg(build_concat_demuxer($job, $tmp, $list), $idx, $job['dur']);
            @unlink($list);
            $how .= '+concat';
            // Abweichende Dauer: Teile passen doch nicht zusammen, dann lieber über den concat-Filter neu kodieren
            if ($success && ($check = verify($tmp, $job['dur']))[0] !== VERIFY_OK) {
                [$success, $err] = [false, $check[1]];
            }
        }
        if (!$success) {
            if ($err !== '') {
                println("  ~ {$job['target']}: Zusammenfügen ohne Neukodierung klappt nicht ($err), kodiere neu …");
            }
            $job['mode'] = 'encode';
            $how = 'encode+concatfilter';
            [$success, $err] = run_ffmpeg(build_concat_filter($job, $tmp), $idx, $job['dur']);
        }
    }
    $error = $success ? null : ($err ?: 'ffmpeg fehlgeschlagen');
    $warning = null;
    if ($error === null) {
        [$code, $msg] = verify($tmp, $job['dur']);
        if ($code === VERIFY_UNREADABLE) {
            $error = $msg;
        } elseif ($code === VERIFY_DURATION) {
            $warning = $msg;
        }
    }
    if ($error === null && (!@unlink($out) && is_file($out) || !rename($tmp, $out))) {
        $error = 'Umbenennen fehlgeschlagen';
    }
    if ($error !== null) {
        @unlink($tmp);
    }
    foreach ($subFiles as $from => $to) {
        if (is_file($from)) {
            @unlink($to);
            $error === null && filesize($from) > 0 ? rename($from, $to) : unlink($from);
        }
    }
    return [$error, $warning, $how];
}

// ---------------------------------------------------------------- Einträge und Quellen

/**
 * Alle Zeilen des Logs.
 */
$logEntries = read_log();
/**
 * Zielpfad => Log-Zeile der fertigen Datei.
 *
 * @var array<string, array> $doneFiles
 */
$doneFiles = [];   // Zielpfad => Log-Zeile der fertigen Datei
/**
 * Je Modus (copy, remux, encode): [Aufwand, Sekunden] bisheriger Läufe.
 *
 * @var array<string, array{0: float|int, 1: float|int}> $stats
 */
$stats = ['copy' => [0, 0], 'remux' => [0, 0], 'encode' => [0, 0]];   // [Aufwand, Sekunden]
foreach ($logEntries as $e) {
    if (($e['status'] ?? '') === 'done') {
        $doneFiles[$e['target']] = $e;
        if (isset($stats[$e['mode'] ?? '']) && ($e['wall'] ?? 0) > 1) {
            $stats[$e['mode']][0] += $e['cost'];
            $stats[$e['mode']][1] += $e['wall'];
        }
    }
}

/**
 * Offene Einträge: Slug => [Eintrag, Index des USB-Mediums].
 *
 * @var array<string, array> $entries
 */
// Offen: Medium USB des Eigentümers ohne Angaben zum Bild
$entries = [];
foreach ($MOVIES as $slug => $movie) {
    if ($ONLY && !array_filter($ONLY, fn($p) => str_starts_with($slug, $p))) {
        continue;
    }
    if ($movie['type'] === TYPE_SERIES) {
        continue;
    }
    foreach ($movie['media'] ?? [] as $i => $md) {
        if ($md['type'] === USB_MEDIUM && $md['owner'] === $opt['owner'] && empty($md['video'])) {
            $entries[$slug] = [$movie, $i];
            break;
        }
    }
}
ksort($entries, SORT_NATURAL);
echo "Einträge auf $REMOTE: " . count($MOVIES) . ", davon offen mit Medium USB von {$opt['owner']}: " . count($entries) . ". Durchsuche $SRC …\n";
/**
 * Alle Videos unter --src, gruppiert (siehe source_groups()).
 *
 * @var array<string, array> $groups
 */
$groups = source_groups($SRC);
[$assigned, $open, $free] = assign_sources($entries, $groups);
// Quellen, die frühere Läufe schon verarbeitet haben, sind keine "Videos ohne Eintrag"
$doneSources = [];
foreach ($doneFiles as $e) {
    foreach ($e['src'] ?? [] as $r) {
        $doneSources[$r] = true;
    }
}
// ebenso Dateien, die schon als Pfad eines USB-Mediums eingetragen sind (Quelle = USB-Ordner)
foreach ($MOVIES as $movie) {
    foreach ($movie['media'] ?? [] as $md) {
        foreach ($md['paths'] ?? [] as $p) {
            $doneSources[$p] = true;
        }
    }
}
$free = array_filter($free, fn($g) => !array_filter($g['rel'], fn($r) => isset($doneSources[$r])));

// ---------------------------------------------------------------- Planen

/**
 * Signatur der Quelle (Pfad relativ zu --src, Größe, Änderungszeit): erkennt eine schon umgewandelte Datei wieder.
 *
 * @param array $group Eine Quelle aus source_groups()
 * @return string Die Signatur
 */
function sig(array $group): string
{
    return implode('|', array_map(fn($r, $f) => "$r:" . filesize($f) . ':' . filemtime($f), $group['rel'], $group['files']));
}

echo "Analysiere " . array_sum(array_map(fn($a) => count($a[0]), $assigned)) . " Videos …\n";
/**
 * Slug => [Eintrag, Medium-Index, [Index der Aufträge in $jobs], Art der Zuordnung].
 *
 * @var array<string, array> $films
 */
$films = [];   // slug => [movie, medium-Index, [Job-Indizes], Art]
/**
 * Alle Umwandlungsaufträge (eine Datei bzw. ein Mehrteiler je Auftrag).
 *
 * @var array[] $jobs
 */
$jobs = [];
/**
 * Meldungen zu Quellen, die sich nicht lesen ließen.
 *
 * @var string[] $problems
 */
$problems = [];
foreach ($assigned as $slug => [$own, $how]) {
    [$movie, $i] = $entries[$slug];
    $season = $movie['type'] === TYPE_SEASON;
    if (!$season && count($own) > 1) {
        $open[$slug] = 'mehrere Dateien, die kein Mehrteiler sind: ' . implode(', ', array_column($own, 'key'));
        continue;
    }
    $numbers = [];
    foreach ($own as $k => $g) {
        $numbers[] = $season ? ($g['episode'] ?? (int) ($movie['episodes'][$k]['number'] ?? $k + 1)) : null;
    }
    if ($season && count(array_unique($numbers)) !== count($numbers)) {
        $open[$slug] = 'Folgennummern doppelt: ' . implode(', ', array_column($own, 'key'));
        continue;
    }
    $mine = [];
    foreach ($own as $k => $g) {
        $target = usb_base_path($movie, $numbers[$k]) . '.mp4';
        $probes = array_map('probe', $g['files']);
        if (in_array(null, $probes, true) || video_stream($probes[0]) === null) {
            $problems[] = "$slug: nicht lesbar: " . implode(', ', $g['rel']);
            continue 2;
        }
        $v = video_stream($probes[0]);
        $audios = audio_streams($probes[0]);
        $audioOk = count($audios) <= 1 && array_reduce($audios, fn($c, $a) => $c && audio_ok($a), true);
        $allVideoOk = array_reduce($probes, fn($c, $p) => $c && video_ok(video_stream($p)) && !interlaced(video_stream($p)), true);
        $mode = !$allVideoOk ? 'encode' : (count($g['files']) === 1 && $audioOk ? 'copy' : 'remux');
        $dur = array_sum(array_map('duration', $probes));
        $bytes = array_sum(array_map('filesize', $g['files']));
        $pixels = (int) $v['width'] * (int) $v['height'];
        $mine[] = count($jobs);
        $jobs[] = [
            'slug' => $slug, 'target' => $target, 'episode' => $numbers[$k], 'files' => $g['files'], 'rel' => $g['rel'],
            'probes' => $probes, 'sig' => sig($g), 'mode' => $mode, 'dur' => $dur, 'bytes' => $bytes, 'pixels' => $pixels,
            'cost' => $mode === 'encode' ? $pixels * $dur : $bytes, 'poster' => null, 'meta' => file_metadata($movie, $numbers[$k]),
        ];
    }
    $films[$slug] = [$movie, $i, $mine, $how];
}
ksort($films, SORT_NATURAL);

/**
 * Ist die Datei laut Log schon aus genau dieser Quelle entstanden (und noch da)?
 *
 * @param array $job Der Auftrag
 * @return bool true, wenn die Zieldatei schon fertig ist
 */
function is_done(array $job): bool
{
    global $doneFiles, $TO;
    $e = $doneFiles[$job['target']] ?? null;
    return $e !== null && $e['sig'] === $job['sig'] && is_file(on_disk($job['target'], $TO))
        && filesize(on_disk($job['target'], $TO)) === $e['size'];
}

// Übersicht
foreach ($films as $slug => [$movie, , $mine, $how]) {
    println("$slug  ($how)");
    foreach ($mine as $j) {
        $job = $jobs[$j];
        println(sprintf('    %-7s %s  <=  %s%s', $job['mode'], $job['target'], implode(' + ', $job['rel']), is_done($job) ? '  (fertig)' : ''));
    }
}
if ($open) {
    println("\nOhne Quelle (" . count($open) . "), Pfad im Formular unter \"Dateien\" eintragen (relativ zu $SRC):");
    foreach ($open as $slug => $why) {
        println("  $slug: $why");
    }
}
if ($free) {
    println("\nVideos ohne Eintrag (" . count($free) . "):");
    foreach ($free as $g) {
        println('  ' . implode(' + ', $g['rel']));
    }
}
foreach ($problems as $p) {
    println("  ! $p");
}
$todo = array_filter($jobs, fn($j) => !is_done($j));
$estimate = array_sum(array_map(fn($j) => $j['cost'] / rate($j['mode']), $todo));
println(sprintf("\n%d Einträge mit %d Dateien zu bearbeiten (geschätzt %s), %d ohne Quelle, %d Videos ohne Eintrag.",
    count($films), count($todo), fmt_time($estimate), count($open), count($free)));
if (!$films || $opt['dry-run']) {
    exit(0);
}
if (!$opt['yes'] && !ask_yes("Jetzt umwandeln und nach $TO legen?")) {
    echo "Abgebrochen.\n";
    exit(0);
}

// ---------------------------------------------------------------- Ausführen, Film für Film

$startAll = microtime(true);
$ok = $failed = 0;
$warnings = [];
foreach ($films as $slug => [$movie, , $mine]) {
    $filmOk = true;
    $poster = null;
    foreach ($mine as $j) {
        $job = $jobs[$j];
        if (is_done($job)) {
            continue;
        }
        // Cover: Poster einmal je Eintrag vom Server laden, erst wenn wirklich etwas umzuwandeln ist
        $poster ??= poster_file($movie) ?? false;
        $job['poster'] = $poster ?: null;
        $out = on_disk($job['target'], $TO);
        if (is_file($out)) {
            println("  ✗ {$job['target']}: am Ziel liegt schon eine andere Datei, nicht überschrieben");
            $filmOk = false;
            break;
        }
        if (!is_dir(dirname($out))) {
            mkdir(dirname($out), 0777, true);
        }
        /**
         * Laufender Auftrag; "start" = Startzeit (microtime).
         *
         * @var array{start: float } $current
         */
        $current = ['start' => microtime(true)];
        show_progress($j, 0);
        [$error, $warning, $how] = convert_job($job, $j, $out);
        $wall = microtime(true) - $current['start'];
        if ($error !== null) {
            $short = mb_substr(preg_replace('/\s+/', ' ', $error), 0, 500);
            log_entry(['status' => 'error', 'slug' => $slug, 'target' => $job['target'], 'src' => $job['rel'], 'error' => $short]);
            println("  ✗ {$job['target']}: $short");
            $filmOk = false;
            break;
        }
        $stats[$job['mode']][0] += $job['cost'];
        $stats[$job['mode']][1] += $wall;
        $size = filesize($out);
        $doneFiles[$job['target']] = ['target' => $job['target'], 'sig' => $job['sig'], 'size' => $size];
        log_entry(['status' => 'done', 'slug' => $slug, 'target' => $job['target'], 'src' => $job['rel'], 'sig' => $job['sig'],
                'mode' => $job['mode'], 'how' => $how, 'bytes' => $job['bytes'], 'size' => $size, 'cost' => $job['cost'],
                'wall' => round($wall, 1)] + ($warning ? ['warning' => $warning] : []));
        println(sprintf("  ✓ %-60s %-20s %s → %s in %s", $job['target'], $how, fmt_bytes($job['bytes']), fmt_bytes($size), fmt_time($wall)));
        if ($warning) {
            $warnings[] = "{$job['target']}: $warning";
            println("  ⚠ {$job['target']}: $warning – trotzdem abgelegt, bitte prüfen");
        }
    }
    if (!$filmOk) {
        $failed++;
        continue;
    }

    // Eintrag fertig: Bild und Ton der ersten Datei messen und auf dem Server eintragen (nur dieses Medium)
    $paths = array_map(fn($j) => $jobs[$j]['target'], $mine);
    $probe = probe(on_disk($paths[0], $TO));
    $video = $probe ? video_facts($probe) : null;
    $audio = $probe ? audio_facts($probe) : null;
    try {
        api_json('PUT', 'media.php', [], ['slug' => $slug, 'owner' => $opt['owner'], 'type' => USB_MEDIUM, 'paths' => $paths,
            'video' => $video, 'audio' => $audio]);
    } catch (RuntimeException $e) {
        println("  ✗ $slug: Dateien fertig, aber nicht eingetragen ({$e->getMessage()}); erneut starten trägt nur noch ein");
        $failed++;
        continue;
    }
    log_entry(['status' => 'film', 'slug' => $slug, 'owner' => $opt['owner'], 'paths' => $paths] + array_filter(['video' => $video, 'audio' => $audio]));
    println("  ⇒ $slug eingetragen" . ($video ? sprintf(' (%d×%d, %s kbit/s)', $video['width'], $video['height'], $video['kbps'] ?? '?') : ''));
    $ok++;
}

println(sprintf("Fertig in %s: %d Einträge aufgenommen, %d fehlgeschlagen. Log: %s", fmt_time(microtime(true) - $startAll), $ok, $failed, $LOG));
if ($warnings) {
    println(count($warnings) . " mit abweichender Dauer (abgelegt, bitte prüfen):\n  " . implode("\n  ", $warnings));
}
if ($ok) {
    println("Eingetragen auf $REMOTE.");
}
exit($failed > 0 ? 2 : 0);

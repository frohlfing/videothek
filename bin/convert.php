<?php

/**
 * Wandelt die Videos der Videothek in ein Format um, das Browser direkt abspielen (MP4 mit H.264 und AAC/MP3),
 * und legt sie mit gleicher Ordnerstruktur im Ziel ab.
 *
 * C:\xampp\php\php.exe -d xdebug.mode=off bin/convert.php [--src=D:\videothek] [--dst=D:\videothek2]
 *                                                         [--crf=18] [--preset=slow] [--qsv] [--only=0-m] [--dry-run]
 *
 *   --crf=18, --preset=slow: Qualität und Gründlichkeit von libx264
 *   --qsv: Intel-Hardware-Encoder statt libx264: deutlich schneller, etwas schlechter
 *   --only: beschränkt auf Ordner mit diesen Anfangsbuchstaben
 *   --dry-run: Plan ausgeben, nichts ändern
 *
 * Jedes Video wird zu einer .mp4 (gleicher Name, nur die Endung ändert sich). Pro Datei bzw. Mehrteiler (movie-cd1,
 * movie-cd2, … / -part / -teil / -disc / -dvd) wird entschieden:
 *   copy    – schon browsertauglich (MP4/M4V mit H.264 8 Bit und nur AAC/MP3), wird unverändert kopiert (M4V als .mp4)
 *   remux   – Video ist schon H.264 (z. B. MKV/FLV): Video wird übernommen, nur der Ton (DTS, AC3, …) nach AAC
 *   encode  – Video wird neu kodiert (libx264, CRF 18, Preset slow; bei Zeilensprung mit yadif), Ton wie bei remux
 *
 * Mehrteiler: Dateien im selben Ordner, deren Namen sich nur durch eine Teilnummer am Ende unterscheiden (cd, dvd,
 * disc, disk, part, pt oder teil mit ein- oder zweistelliger Zahl, siehe PART_RE), werden in natürlicher Reihenfolge
 * zu einer Datei ohne Teilnummer zusammengefügt: movie-cd1.avi + movie-cd2.avi -> movie.mp4. Das gilt auch für eine
 * Folge einer Staffel, die in Teilen vorliegt: E03-cd1.avi + E03-cd2.avi -> E03.mp4. Folgen selbst werden nie
 * zusammengefügt, denn E01, S01E02, episode-03 o. Ä. sind keine Teilnummern. Ein einzelner Teil ohne Gegenstück
 * (nur movie-cd1.avi) behält seinen Namen. Passen die Teile technisch zusammen, läuft es über den concat-Demuxer
 * (Video ggf. ohne Neukodierung), sonst über den concat-Filter mit Neukodierung auf die Bildgröße des ersten Teils.
 *
 * Übernommen wird nur eine Tonspur: die deutsche, ohne Untertitel. Gibt es keine deutsche (nur andere Sprachen
 * angegeben), die englische, und dazu die deutschen (ersatzweise englischen) Text-Untertitel als WebVTT neben dem
 * Video (movie.ger.vtt). Bild-Untertitel (PGS, VobSub) lassen sich ohne OCR nicht umwandeln und entfallen. Andere
 * Dateien (Bilder, NFO, …) werden kopiert.
 *
 * Fortschritt: Jede erledigte Aufgabe wird in <dst>\convert-log.jsonl festgehalten (mit Größe und Änderungsdatum
 * der Quelle). Nach einem Abbruch (Strg+C, Neustart) einfach erneut starten: Erledigtes wird übersprungen, die
 * unterbrochene Datei (*.part) neu begonnen. Fehlgeschlagene Aufgaben werden beim nächsten Lauf erneut versucht.
 */

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit("Nur für die Kommandozeile.\n");
}

if (function_exists('sapi_windows_cp_set')) {
    sapi_windows_cp_set(65001);
}

const VIDEO_EXT = ['avi', 'mkv', 'mp4', 'm4v', 'flv', 'mov', 'wmv', 'mpg', 'mpeg', 'ts', 'm2ts', 'vob', 'webm', 'ogm', 'divx', '3gp'];
const PART_RE = '/^(.*?)[\s._-]*(?:cd|dvd|disc|disk|part|pt|teil)[\s._-]*(\d{1,2})$/i';
const TEXT_SUBS = ['subrip', 'srt', 'ass', 'ssa', 'mov_text', 'webvtt', 'text'];
const LOG_NAME = 'convert-log.jsonl';

// ---------------------------------------------------------------- Optionen

$opt = ['src' => 'D:\\videothek', 'dst' => 'D:\\videothek2', 'crf' => '18', 'preset' => 'slow', 'qsv' => false, 'only' => '', 'dry-run' => false];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = is_bool($opt[$m[1]]) ? true : ($m[2] ?? '');
        continue;
    }
    fwrite(STDERR, "Aufruf: php bin/convert.php [--src=DIR] [--dst=DIR] [--crf=18] [--preset=slow] [--qsv] [--only=0-m] [--dry-run]\n");
    exit(1);
}
$SRC = rtrim($opt['src'], '\\/');
$DST = rtrim($opt['dst'], '\\/');
$ONLY = [];   // Liste [von, bis] der erlaubten Anfangszeichen
foreach (array_filter(explode(',', strtolower($opt['only']))) as $range) {
    if (!preg_match('/^\s*([a-z0-9])(?:\s*-\s*([a-z0-9]))?\s*$/', $range, $m)) {
        fwrite(STDERR, "--only: \"$range\" ist kein Bereich wie 0-m oder n-z\n");
        exit(1);
    }
    $ONLY[] = [$m[1], $m[2] ?? $m[1]];
}
if (!is_dir($SRC)) {
    fwrite(STDERR, "Quellordner fehlt: $SRC\n");
    exit(1);
}
foreach (['ffmpeg', 'ffprobe'] as $tool) {
    exec("where $tool 2>NUL", $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "$tool nicht im PATH gefunden.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------- Hilfsfunktionen

function rel(string $path): string
{
    global $SRC;
    return ltrim(substr($path, strlen($SRC)), '\\/');
}

/** Liegt der relative Pfad (sein oberster Ordner) in den mit --only gewählten Anfangszeichen? */
function in_range(string $rel): bool
{
    global $ONLY;
    if (!$ONLY) {
        return true;
    }
    $c = strtolower($rel[0] ?? '');
    foreach ($ONLY as [$from, $to]) {
        if ($c >= $from && $c <= $to) {
            return true;
        }
    }
    return false;
}

function probe(string $file): ?array
{
    $cmd = 'ffprobe -v error -print_format json -show_format -show_streams ' . escapeshellarg($file) . ' 2>NUL';
    $json = json_decode((string) shell_exec($cmd), true);
    return is_array($json) && isset($json['streams']) ? $json : null;
}

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

/** Erste echte Videospur (keine eingebetteten Cover). */
function video_stream(array $p): ?array
{
    foreach ($p['streams'] as $s) {
        if ($s['codec_type'] === 'video' && empty($s['disposition']['attached_pic'])) {
            return $s;
        }
    }
    return null;
}

function audio_streams(array $p): array
{
    return array_values(array_filter($p['streams'], fn($s) => $s['codec_type'] === 'audio'));
}

/** Sprache der Spur; fehlt die Angabe, notfalls aus dem Titel (z. B. "AC3_192_DE", "German DTS"). */
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

const LANG_DE = ['ger', 'deu', 'de'];
const LANG_EN = ['eng', 'en'];

/** Erste Spur der Liste in einer der Sprachen, Kommentarspuren nur notfalls. */
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
 * Die eine Tonspur, die ins Ziel kommt (Index innerhalb 0:a), und ob Untertitel dazu gehören: die deutsche;
 * ohne Sprachangaben (typisch bei AVI) die Standard- bzw. erste Spur; gibt es nur andere Sprachen, die englische
 * (sonst die erste) – dann mit Untertiteln. Null, wenn es keine Tonspur gibt.
 *
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

function video_ok(?array $v): bool
{
    return $v !== null && $v['codec_name'] === 'h264' && in_array($v['pix_fmt'] ?? '', ['yuv420p', 'yuvj420p'], true);
}

function audio_ok(array $a): bool
{
    return in_array($a['codec_name'], ['aac', 'mp3'], true);
}

function audio_bitrate(int $channels): string
{
    return max(192, 64 * min($channels, 6)) . 'k';
}

function interlaced(?array $v): bool
{
    return $v !== null && in_array($v['field_order'] ?? '', ['tt', 'bb', 'tb', 'bt'], true);
}

function fmt_time(float $sec): string
{
    $sec = max(0, (int) round($sec));
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return $h > 0 ? sprintf('%dh%02dm', $h, $m) : sprintf('%dm%02ds', $m, $sec % 60);
}

function fmt_bytes(float $b): string
{
    return $b >= 1 << 30 ? sprintf('%.1f GB', $b / (1 << 30)) : sprintf('%.0f MB', $b / (1 << 20));
}

function encoder_args(string $vi): array
{
    global $opt;
    if ($opt['qsv']) {
        return ["-c:v:$vi", 'h264_qsv', '-preset', 'veryslow', '-global_quality', (string) max(1, (int) $opt['crf'] + 2),
            '-look_ahead', '1', '-pix_fmt', 'nv12'];
    }
    return ["-c:v:$vi", 'libx264', '-preset', $opt['preset'], '-crf', $opt['crf'], '-profile:v', 'high',
        '-pix_fmt', 'yuv420p'];
}

// ---------------------------------------------------------------- Aufgaben sammeln

$groups = [];   // Zielpfad => Liste der Quelldateien
$others = [];   // Nicht-Videodateien
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($SRC, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) {
        continue;
    }
    $path = $f->getPathname();
    if (!in_range(rel($path))) {
        continue;
    }
    $ext = strtolower($f->getExtension());
    $dir = dirname(rel($path));
    $dir = $dir === '.' ? '' : $dir . '\\';
    if (!in_array($ext, VIDEO_EXT, true)) {
        $others[] = $path;
        continue;
    }
    $base = $f->getBasename('.' . $f->getExtension());
    $key = preg_match(PART_RE, $base, $m) ? $dir . rtrim($m[1], ' ._-') : $dir . $base;
    $groups[$key][] = $path;
}
ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

// Teile natürlich sortieren; "Gruppen" aus nur einer Datei behalten ihren Namen (movie-cd1 allein bleibt movie-cd1)
$jobs = [];
foreach ($groups as $key => $files) {
    natcasesort($files);
    $files = array_values($files);
    if (count($files) === 1) {
        $key = substr(rel($files[0]), 0, -strlen(pathinfo($files[0], PATHINFO_EXTENSION)) - 1);
    }
    $jobs[] = ['key' => $key, 'files' => $files];
}

// ---------------------------------------------------------------- Log lesen

if (!$opt['dry-run'] && !is_dir($DST) && !mkdir($DST, 0777, true)) {
    fwrite(STDERR, "Zielordner lässt sich nicht anlegen: $DST\n");
    exit(1);
}
$LOG = $DST . '\\' . LOG_NAME;
$done = [];
$stats = ['copy' => [0, 0], 'remux' => [0, 0], 'encode' => [0, 0]];   // [Aufwand, Sekunden]
if (is_file($LOG)) {
    foreach (file($LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $e = json_decode($line, true);
        if (!is_array($e) || ($e['status'] ?? '') !== 'done') {
            continue;
        }
        $done[$e['dst']] = $e;
        if (isset($stats[$e['mode'] ?? '']) && ($e['wall'] ?? 0) > 1) {
            $stats[$e['mode']][0] += $e['cost'];
            $stats[$e['mode']][1] += $e['wall'];
        }
    }
}

function sig(array $files): string
{
    return implode('|', array_map(fn($f) => rel($f) . ':' . filesize($f) . ':' . filemtime($f), $files));
}

function is_done(string $dst, string $sig): bool
{
    global $done, $DST;
    return isset($done[$dst]) && $done[$dst]['sig'] === $sig && is_file($DST . '\\' . $dst)
        && filesize($DST . '\\' . $dst) === $done[$dst]['size'];
}

function log_entry(array $e): void
{
    global $LOG;
    file_put_contents($LOG, json_encode($e + ['time' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------- Planen

function done_line(string $dstRel, string $how, float $bytes, float $size, float $wall): string
{
    return sprintf("  ✓ %-60s %-20s %s → %s in %s", $dstRel, $how, fmt_bytes($bytes), fmt_bytes($size), fmt_time($wall));
}

/** Erledigte Aufgabe wie im vorigen Lauf auflisten und für den Fortschrittsbalken merken. */
function skip_done(string $dstRel, array $files): void
{
    global $done, $finished, $skipped;
    $e = $done[$dstRel];
    $skipped++;
    $finished[] = $e;
    echo done_line($dstRel, $e['how'] ?? $e['mode'], $e['bytes'] ?? array_sum(array_map('filesize', $files)),
        $e['size'], $e['wall'] ?? 0) . "
";
}

echo "Analysiere " . count($jobs) . " Videos in $SRC …\n";
$plan = [];
$finished = [];   // schon erledigte Aufgaben (aus dem Log), zählen im Fortschrittsbalken mit
$skipped = 0;
foreach ($jobs as $i => $job) {
    $files = $job['files'];
    $sig = sig($files);
    $single = count($files) === 1;
    $ext = strtolower(pathinfo($files[0], PATHINFO_EXTENSION));
    // Ergebnis ist immer eine .mp4 (auch aus einer kopierten M4V)
    $dstRel = $job['key'] . '.mp4';
    if (is_done($dstRel, $sig)) {
        skip_done($dstRel, $files);
        continue;
    }
    $probes = [];
    foreach ($files as $f) {
        $p = probe($f);
        if ($p === null) {
            echo "  ! Nicht lesbar, übersprungen: " . rel($f) . "\n";
            continue 2;
        }
        $probes[] = $p;
    }
    $v = video_stream($probes[0]);
    // Unverändert kopiert wird nur, was schon genau eine passende Tonspur und keine Untertitel hat
    $audios = audio_streams($probes[0]);
    $audioOk = count($audios) <= 1 && array_reduce($audios, fn($c, $a) => $c && audio_ok($a), true);
    $subsOk = !array_filter($probes[0]['streams'], fn($s) => $s['codec_type'] === 'subtitle');
    if ($single && in_array($ext, ['mp4', 'm4v'], true) && video_ok($v) && $audioOk && $subsOk) {
        $mode = 'copy';
    } else {
        // WebM (VP8/VP9/AV1) ist nie H.264 und wird daher neu kodiert
        $allVideoOk = array_reduce($probes, fn($c, $p) => $c && video_ok(video_stream($p)) && !interlaced(video_stream($p)), true);
        $mode = $allVideoOk ? 'remux' : 'encode';
    }
    $dur = array_sum(array_map('duration', $probes));
    $bytes = array_sum(array_map('filesize', $files));
    $pixels = $v ? (int) $v['width'] * (int) $v['height'] : 640 * 360;
    // Aufwand: copy/remux in Bytes, encode in Pixel × Sekunden
    $cost = $mode === 'encode' ? $pixels * $dur : $bytes;
    $plan[] = compact('files', 'probes', 'sig', 'dstRel', 'mode', 'dur', 'bytes', 'cost', 'pixels');
}

$count = ['copy' => 0, 'remux' => 0, 'encode' => 0];
foreach ($plan as $p) {
    $count[$p['mode']]++;
}
printf("Erledigt: %d, offen: %d (kopieren %d, Ton umwandeln %d, neu kodieren %d), davon Mehrteiler: %d\n",
    $skipped, count($plan), $count['copy'], $count['remux'], $count['encode'],
    count(array_filter($plan, fn($p) => count($p['files']) > 1)));

// ---------------------------------------------------------------- Fortschritt und Hochrechnung

// Erfahrungswerte, bis eigene Messwerte vorliegen: Kopieren 80 MB/s, Ton umwandeln 60 MB/s,
// Neukodieren ≈ 1080p in 1,5-facher Echtzeit
const DEFAULT_RATE = ['copy' => 80 * 1048576, 'remux' => 60 * 1048576, 'encode' => 1920 * 1080 * 1.5];

function rate(string $mode, array $live = [0, 0]): float
{
    global $stats;
    [$c, $w] = $stats[$mode];
    $c += $live[0];
    $w += $live[1];
    return $w > 30 ? $c / $w : DEFAULT_RATE[$mode];
}

if ($opt['dry-run']) {
    // Mit geschätzter Dauer (gemessene Geschwindigkeiten aus dem Log), z. B. um --only-Bereiche abzuwägen
    $sum = 0.0;
    foreach ($plan as $p) {
        $t = $p['cost'] / rate($p['mode']);
        $sum += $t;
        printf("  %-7s %8s  %s%s\n", $p['mode'], fmt_time($t), $p['dstRel'],
            count($p['files']) > 1 ? '  <= ' . implode(' + ', array_map('basename', $p['files'])) : '');
    }
    printf("Geschätzte Dauer: %s\n", fmt_time($sum));
    exit(0);
}

$startAll = microtime(true);
$current = null;   // ['index' => i, 'frac' => 0..1, 'start' => t, 'speed' => '…']

function show_progress(int $idx, float $frac, string $extra = ''): void
{
    global $plan, $finished, $current;
    static $last = 0;
    $now = microtime(true);
    if ($now - $last < 0.5 && $frac < 1) {
        return;
    }
    $last = $now;

    $job = $plan[$idx];
    $elapsed = $now - $current['start'];
    $live = $frac > 0.02 && $elapsed > 5 ? [$job['cost'] * $frac, $elapsed] : [0, 0];
    $remaining = (1 - $frac) * $job['cost'] / rate($job['mode'], $live);
    $totalCost = $doneCost = 0.0;
    foreach ($finished as $e) {
        $t = $e['cost'] / rate($e['mode']);
        $totalCost += $t;
        $doneCost += $t;
    }
    foreach ($plan as $i => $p) {
        // Gesamtfortschritt nach geschätzter Zeit gewichtet (Kopieren zählt wenig, Kodieren viel)
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
    $width = 30;
    $fill = (int) round($total * $width);
    $bar = str_repeat('#', $fill) . str_repeat('.', $width - $fill);
    $eta = date('D H:i', (int) ($now + $remaining));
    $eta = strtr($eta, ['Mon' => 'Mo', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do', 'Fri' => 'Fr', 'Sat' => 'Sa', 'Sun' => 'So']);
    $name = $job['dstRel'];
    if (mb_strlen($name) > 40) {
        $name = '…' . mb_substr($name, -39);
    }
    $line = sprintf("[%s] %5.1f%%  %d/%d  Rest %s (fertig %s)  %s %s %3.0f%% %s",
        $bar, $total * 100, count($finished) + $idx + 1, count($finished) + count($plan), fmt_time($remaining), $eta, $job['mode'], $name, $frac * 100, $extra);
    $line = mb_substr($line, 0, term_width());
    echo "\r" . $line . str_repeat(' ', term_width() - mb_strlen($line));
}

/** Konsolenbreite minus 1 (damit die Zeile nicht umbricht). */
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

function println(string $msg): void
{
    echo "\r" . str_repeat(' ', term_width()) . "\r" . $msg . "\n";
}

// ---------------------------------------------------------------- Ausführen

/**
 * Startet ffmpeg, wertet -progress aus und ruft show_progress() auf. Fehlerausgabe landet in einer Temp-Datei
 * (stream_select() funktioniert unter Windows nicht mit Pipes, darum nicht beide Pipes gleichzeitig lesen).
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

/** Gemeinsame Ausgabeoptionen für Ton, Metadaten und MP4. $aIn: Liste [Eingabe-Label, Audio-Stream] in Zielreihenfolge. */
function audio_args(array $audios, bool $copyOk): array
{
    $args = [];
    foreach ($audios as $oi => $a) {
        if ($copyOk && audio_ok($a)) {
            array_push($args, "-c:a:$oi", 'copy');
        } else {
            $ch = (int) ($a['channels'] ?? 2);
            array_push($args, "-c:a:$oi", 'aac', "-b:a:$oi", audio_bitrate($ch));
            if ($ch >= 6) {
                // 5.1(side) aus DTS/AC3 bzw. 7.1 auf Standard-5.1, sonst landet "unknown" als Kanalbelegung im MP4
                array_push($args, "-filter:a:$oi", 'aformat=channel_layouts=5.1');
            }
        }
        array_push($args, "-disposition:a:$oi", $oi === 0 ? 'default' : '0');
        array_push($args, "-metadata:s:a:$oi", 'language=' . lang($a));
    }
    return $args;
}

function mp4_args(string $out): array
{
    return ['-max_muxing_queue_size', '4096', '-avoid_negative_ts', 'make_zero', '-movflags', '+faststart',
        '-f', 'mp4', $out];
}

/**
 * Nur wenn der Film keine deutsche Tonspur hat: die deutschen Text-Untertitel (ersatzweise die englischen) als
 * zusätzliche WebVTT-Ausgaben; liefert [Argumente, [tmp => final]].
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
        $name = $outBase . '.' . $l . ($n[$l] > 1 ? '.' . $n[$l] : '')
            . (!empty($s['disposition']['forced']) ? '.forced' : '') . '.vtt';
        array_push($args, '-map', '0:' . $s['index'], '-c:s', 'webvtt', '-f', 'webvtt', $name . '.part');
        $files[$name . '.part'] = $name;
    }
    return [$args, $files];
}

function copy_file(string $src, string $dst, int $idx): bool
{
    $in = fopen($src, 'rb');
    $out = fopen($dst, 'wb');
    if (!$in || !$out) {
        return false;
    }
    $size = max(1, filesize($src));
    $copied = 0;
    while (!feof($in)) {
        $chunk = fread($in, 8 << 20);
        if ($chunk === false || fwrite($out, $chunk) !== strlen($chunk)) {
            return false;
        }
        $copied += strlen($chunk);
        show_progress($idx, min(0.999, $copied / $size));
    }
    fclose($in);
    fclose($out);
    touch($dst, filemtime($src));
    return true;
}

function build_single(array $job, string $out, string $outBase): array
{
    $p = $job['probes'][0];
    $v = video_stream($p);
    $args = [];
    if (strtolower(pathinfo($job['files'][0], PATHINFO_EXTENSION)) === 'avi') {
        array_push($args, '-fflags', '+genpts');
    }
    array_push($args, '-i', $job['files'][0]);
    if ($v) {
        array_push($args, '-map', '0:' . $v['index']);
    }
    $ordered = [];
    if ($pick = pick_audio($p)) {
        array_push($args, '-map', "0:a:{$pick[0]}");
        $ordered[] = audio_streams($p)[$pick[0]];
    }
    if ($v) {
        if ($job['mode'] === 'remux') {
            array_push($args, '-c:v', 'copy', '-tag:v', 'avc1');
        } else {
            $args = array_merge($args, encoder_args('0'));
            if (interlaced($v)) {
                array_push($args, '-vf', 'yadif=mode=send_frame:deint=interlaced');
            }
        }
    }
    $args = array_merge($args, audio_args($ordered, true),
        ['-map_metadata', '0', '-map_chapters', '0', '-sn', '-dn'], mp4_args($out));
    [$subArgs, $subFiles] = subtitle_outputs($p, $outBase);
    return [array_merge($args, $subArgs), $subFiles];
}

/** Prüft, ob die Teile für den concat-Demuxer gleich genug aufgebaut sind. */
function parts_match(array $probes): bool
{
    $shape = function (array $p) {
        $v = video_stream($p);
        $s = [$v['codec_name'] ?? '', $v['width'] ?? 0, $v['height'] ?? 0, $v['pix_fmt'] ?? '', $v['profile'] ?? ''];
        foreach (audio_streams($p) as $a) {
            $s[] = [$a['codec_name'], $a['sample_rate'] ?? '', $a['channels'] ?? ''];
        }
        // Untertitel und Datenspuren zählen nicht mit (sie werden beim Zusammenfügen ohnehin nicht übernommen)
        return $s;
    };
    $first = $shape($probes[0]);
    foreach ($probes as $p) {
        if ($shape($p) !== $first) {
            return false;
        }
    }
    return true;
}

function build_concat_demuxer(array $job, string $out, string $listFile): array
{
    $p = $job['probes'][0];
    $v = video_stream($p);
    file_put_contents($listFile, implode("\n", array_map(
        fn($f) => "file '" . str_replace(["\\", "'"], ['/', "'\\''"], $f) . "'", $job['files'])) . "\n");
    $args = [];
    if (strtolower(pathinfo($job['files'][0], PATHINFO_EXTENSION)) === 'avi') {
        array_push($args, '-fflags', '+genpts');
    }
    array_push($args, '-f', 'concat', '-safe', '0', '-i', $listFile, '-map', '0:' . $v['index']);
    $ordered = [];
    if ($pick = pick_audio($p)) {
        array_push($args, '-map', "0:a:{$pick[0]}");
        $ordered[] = audio_streams($p)[$pick[0]];
    }
    if ($job['mode'] === 'remux') {
        array_push($args, '-c:v', 'copy', '-tag:v', 'avc1');
    } else {
        $args = array_merge($args, encoder_args('0'));
        if (interlaced($v)) {
            array_push($args, '-vf', 'yadif=mode=send_frame:deint=interlaced');
        }
    }
    return array_merge($args, audio_args($ordered, true), ['-map_chapters', '-1', '-sn', '-dn'], mp4_args($out));
}

/** Fallback für unterschiedliche Teile: concat-Filter, alles neu kodiert, erste Tonspur je Teil. */
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
        if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'avi') {
            array_push($args, '-fflags', '+genpts');
        }
        array_push($args, '-i', $f);
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
    $args = array_merge($args, ['-filter_complex', $filter, '-map', '[v]', '-map', '[a]'], encoder_args('0'),
        ['-c:a', 'aac', '-b:a', audio_bitrate($layout === '5.1' ? 6 : 2), '-metadata:s:a:0', 'language=' . ($a0 ? lang($a0) : 'und'),
            '-map_chapters', '-1'],
        mp4_args($out));
    return $args;
}

/** Ergebnis prüfen: Dauer muss zur Quelle passen. */
function verify(string $file, float $dur): ?string
{
    $p = probe($file);
    if ($p === null || video_stream($p) === null) {
        return 'Ergebnis nicht lesbar';
    }
    $d = duration($p);
    if ($dur > 0 && abs($d - $dur) > max(10, $dur * 0.02)) {
        return sprintf('Dauer passt nicht: %s statt %s', fmt_time($d), fmt_time($dur));
    }
    return null;
}

$ok = $failed = 0;
foreach ($plan as $idx => $job) {
    $current = ['start' => microtime(true)];
    $out = $DST . '\\' . $job['dstRel'];
    $outBase = substr($out, 0, -strlen(pathinfo($out, PATHINFO_EXTENSION)) - 1);
    if (!is_dir(dirname($out))) {
        mkdir(dirname($out), 0777, true);
    }
    $tmp = $out . '.part';
    @unlink($tmp);
    show_progress($idx, 0);

    $error = null;
    $subFiles = [];
    $how = $job['mode'];
    if ($job['mode'] === 'copy') {
        if (!copy_file($job['files'][0], $tmp, $idx)) {
            $error = 'Kopieren fehlgeschlagen';
        }
    } elseif (count($job['files']) === 1) {
        [$args, $subFiles] = build_single($job, $tmp, $outBase);
        [$success, $err] = run_ffmpeg($args, $idx, $job['dur']);
        if (!$success && $subFiles) {
            // Untertitel können ffmpeg scheitern lassen – dann ohne sie erneut
            foreach (array_keys($subFiles) as $sf) {
                @unlink($sf);
            }
            $subFiles = [];
            [$args] = build_single(array_merge($job, ['probes' => [array_merge($job['probes'][0], [
                'streams' => array_values(array_filter($job['probes'][0]['streams'], fn($s) => $s['codec_type'] !== 'subtitle'))])]]), $tmp, $outBase);
            [$success, $err] = run_ffmpeg($args, $idx, $job['dur']);
        }
        $error = $success ? null : ($err ?: 'ffmpeg fehlgeschlagen');
    } else {
        $success = false;
        $err = '';
        if (parts_match($job['probes'])) {
            $list = tempnam(sys_get_temp_dir(), 'cat');
            [$success, $err] = run_ffmpeg(build_concat_demuxer($job, $tmp, $list), $idx, $job['dur']);
            @unlink($list);
            $how .= '+concat';
            if ($success && ($e = verify($tmp, $job['dur']))) {
                [$success, $err] = [false, $e];
            }
        }
        if (!$success) {
            if ($err !== '') {
                println("  ~ {$job['dstRel']}: Zusammenfügen ohne Neukodierung klappt nicht ($err), kodiere neu …");
            }
            $job['mode'] = 'encode';
            $job['cost'] = $job['pixels'] * $job['dur'];
            $how = 'encode+concatfilter';
            [$success, $err] = run_ffmpeg(build_concat_filter($job, $tmp), $idx, $job['dur']);
        }
        $error = $success ? null : ($err ?: 'ffmpeg fehlgeschlagen');
    }
    if ($error === null && $job['mode'] !== 'copy') {
        $error = verify($tmp, $job['dur']);
    }

    $wall = microtime(true) - $current['start'];
    if ($error === null) {
        @unlink($out);
        if (!rename($tmp, $out)) {
            $error = 'Umbenennen fehlgeschlagen';
        }
        foreach ($subFiles as $from => $to) {
            if (is_file($from)) {
                @unlink($to);
                if (filesize($from) > 0) {
                    rename($from, $to);
                } else {
                    unlink($from);
                }
            }
        }
    }
    if ($error === null) {
        $ok++;
        $stats[$job['mode']][0] += $job['cost'];
        $stats[$job['mode']][1] += $wall;
        log_entry(['status' => 'done', 'dst' => $job['dstRel'], 'src' => array_map('rel', $job['files']), 'sig' => $job['sig'],
            'mode' => $job['mode'], 'how' => $how, 'bytes' => $job['bytes'], 'size' => filesize($out), 'cost' => $job['cost'], 'wall' => round($wall, 1)]);
        println(done_line($job['dstRel'], $how, $job['bytes'], filesize($out), $wall));
    } else {
        $failed++;
        @unlink($tmp);
        foreach (array_keys($subFiles) as $sf) {
            @unlink($sf);
        }
        $short = mb_substr(preg_replace('/\s+/', ' ', $error), 0, 500);
        log_entry(['status' => 'error', 'dst' => $job['dstRel'], 'src' => array_map('rel', $job['files']), 'error' => $short]);
        println("  ✗ {$job['dstRel']}: $short");
    }
}

// Andere Dateien (Bilder, NFO, Untertitel, …) kopieren, sofern noch nicht vorhanden oder geändert
$copiedOthers = 0;
foreach ($others as $f) {
    $to = $DST . '\\' . rel($f);
    if (is_file($to) && filesize($to) === filesize($f) && filemtime($to) === filemtime($f)) {
        continue;
    }
    if (!is_dir(dirname($to))) {
        mkdir(dirname($to), 0777, true);
    }
    if (copy($f, $to)) {
        touch($to, filemtime($f));
        $copiedOthers++;
    }
}

println(sprintf("Fertig in %s: %d erfolgreich, %d fehlgeschlagen, %d schon erledigt, %d weitere Dateien kopiert. Log: %s",
    fmt_time(microtime(true) - $startAll), $ok, $failed, $skipped, $copiedOthers, $LOG));
exit($failed > 0 ? 2 : 0);

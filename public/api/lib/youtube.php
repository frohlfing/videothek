<?php

/**
 * Prüft YouTube-Videos auf Verfügbarkeit über den oEmbed-Endpunkt (ohne API-Key):
 *   200 -> vorhanden und einbettbar
 *   401 -> Einbetten vom Rechteinhaber verboten
 *   403 -> privat/gesperrt
 *   404 -> gelöscht bzw. nicht (mehr) auffindbar
 *   400 -> ungültige Video-ID
 *
 * Regionale Sperren (z. B. "in deinem Land nicht verfügbar") erkennt oEmbed NICHT – solche Trailer
 * gelten hier als verfügbar.
 */

const YOUTUBE_OEMBED_URL = 'https://www.youtube.com/oembed?format=json&url=';

/**
 * `true` = abspielbar/einbettbar, false = sicher nicht verfügbar, null = unklar (Netzwerkfehler,
 * Drosselung o. Ä.) – im Zweifel nichts ändern.
 */
function youtube_video_available(string $url): ?bool
{
    $status = http_status(YOUTUBE_OEMBED_URL . urlencode($url));
    if ($status === 200) {
        return true;
    }
    if (in_array($status, [400, 401, 403, 404], true)) {
        return false;
    }
    return null;
}

/**
 * Erster verfügbarer Trailer aus $urls (in der gegebenen Reihenfolge = Vorzug), sonst null.
 * Ist ein Kandidat "unklar", wird er genommen statt übersprungen – lieber ein evtl. toter Link als
 * ein guter, der nur wegen eines Netzwerkaussetzers verworfen wurde.
 */
function youtube_first_available(array $urls): ?string
{
    /** @noinspection PhpLoopCanBeConvertedToArrayFindInspection */
    foreach ($urls as $url) {
        if (youtube_video_available($url) !== false) {
            return $url;
        }
    }
    return null;
}

/**
 * HTTP-Statuscode einer GET-Anfrage (Body wird verworfen), 0 bei Netzwerkfehlern.
 * Eigene kleine Funktion statt http_get(), weil dort 4xx als Exception endet und hier gerade
 * der Code interessiert.
 */
function http_status(string $url, int $timeoutSeconds = 10): int
{
    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => HTTP_FETCH_USER_AGENT,
        ]);
        return curl_exec($ch) === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }

    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => 'User-Agent: ' . HTTP_FETCH_USER_AGENT . "\r\n",
        'timeout' => $timeoutSeconds,
        'ignore_errors' => true,
    ]]);
    if (@file_get_contents($url, false, $context) === false) {
        return 0;
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
            $status = (int) $m[1]; // bei Redirects zählt der letzte
        }
    }
    return $status;
}

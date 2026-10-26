<?php

require_once __DIR__ . '/fetch.php';

/*
 * Barcode (EAN) einer DVD/Blu-ray -> Suchbegriff für TMDB. TMDB kennt keine Barcodes und freie Produktdatenbanken
 * kennen die meisten DVDs nicht. Eine Websuche nach der reinen Nummer findet dagegen fast immer mehrere Shop- und
 * eBay-Angebote ("The Transporter - TV Movie Edition", "Transporter, The - TV Movie Edition DVD gebraucht kaufen",
 * ...). Die Wortfolge, die in den meisten dieser Ergebnistitel vorkommt, ist der Filmtitel; Shopnamen und Zusätze
 * wie "DVD" oder "kaufen" stehen jeweils nur in einzelnen Titeln.
 *
 * Die Websuche läuft standardmäßig über die HTML-Version von DuckDuckGo (ohne Schlüssel, die Trefferliste kommt
 * ohne JavaScript), wahlweise über eine Such-API (EAN_SEARCH_PROVIDER/EAN_SEARCH_API_KEY in config.php).
 * google.com selbst liefert Programmen ohne JavaScript keine Treffer, nur eine "Bitte JavaScript aktivieren"-Seite.
 */

/**
 * Wörter, die in Angebotstiteln vorkommen, aber nie zum Filmtitel gehören. Eine Wortfolge mit einem davon
 * zählt nicht. Bewusst knapp: Wörter, die auch in echten Titeln stehen ("und" in "Krieg und Frieden", "New" in
 * "New York", "Movie" in "Scary Movie", "Edition"), fehlen; solche Reste kürzt ean_tmdb_search() notfalls weg.
 *
 * @var string[]
 */
const EAN_STOPWORDS = [
    'dvd', 'dvds', 'blu', 'ray', 'bluray', 'uhd', '4k', 'disc', 'discs', 'fsk', 'region', 'rgn', 'pal', 'bbfc',
    'import', 'kaufen', 'gebraucht', 'ovp', 'portofrei', 'versandkostenfrei', 'ebay', 'amazon', 'isbn', 'ean',
    'gtin', 'barcode',
];

/**
 * Gefundene Ergebnistitel je EAN, damit ein erneuter Scan derselben DVD die Suchmaschine nicht erneut abfragt.
 *
 * @var string
 */
const EAN_CACHE_DIR = DATA_DIR . '/cache/ean';

/**
 * EAN-8, UPC-A (12 Stellen) oder EAN-13 mit gültiger Prüfziffer?
 *
 * @param string $code Der zu prüfende Barcode (nur Ziffern)
 * @return bool true, wenn Länge und Prüfziffer stimmen
 */
function is_valid_ean(string $code): bool
{
    if (!preg_match('/^(\d{8}|\d{12}|\d{13})$/', $code)) {
        return false;
    }
    // Prüfziffer: von rechts (ohne die Prüfziffer selbst) abwechselnd Gewicht 3 und 1.
    $digits = array_reverse(str_split(substr($code, 0, -1)));
    $sum = 0;
    foreach ($digits as $i => $d) {
        $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
    }
    return (10 - $sum % 10) % 10 === (int) substr($code, -1);
}

/**
 * 'duckduckgo' (Voreinstellung, auch wenn config.php die Konstante nicht kennt), 'serpapi' oder 'brave'.
 *
 * @return string "duckduckgo", "serpapi" oder "brave"
 */
function ean_search_provider(): string
{
    return defined('EAN_SEARCH_PROVIDER') && EAN_SEARCH_PROVIDER !== '' ? EAN_SEARCH_PROVIDER : 'duckduckgo';
}

/**
 * Ist die Websuche für Barcodes eingerichtet? Sonst meldet search.php das statt eines Fehlers der Such-API.
 *
 * @return bool true, wenn die Websuche nutzbar ist (DuckDuckGo oder Such-API mit Schlüssel)
 */
function ean_search_configured(): bool
{
    $provider = ean_search_provider();
    return $provider === 'duckduckgo'
        || (in_array($provider, ['serpapi', 'brave'], true) && defined('EAN_SEARCH_API_KEY') && EAN_SEARCH_API_KEY !== '');
}

/**
 * Titel der Websuche-Ergebnisse zur EAN (aus dem Cache oder von der Such-API).
 *
 * @param string $ean Der Barcode
 * @return string[]
 * @throws RuntimeException wenn die Such-API nicht erreichbar ist oder den Schlüssel ablehnt
 */
function ean_web_titles(string $ean): array
{
    $cacheFile = EAN_CACHE_DIR . "/$ean.json";
    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $titles = match (ean_search_provider()) {
        'serpapi' => ean_titles_serpapi($ean),
        'brave' => ean_titles_brave($ean),
        default => ean_titles_duckduckgo($ean),
    };
    // Leere Ergebnisse nicht zwischenspeichern: Vielleicht kennt die Suchmaschine die Nummer später.
    if ($titles !== []) {
        if (!is_dir(EAN_CACHE_DIR)) {
            mkdir(EAN_CACHE_DIR, 0775, true);
        }
        file_put_contents($cacheFile, json_encode($titles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    return $titles;
}

/**
 * Die Trefferliste der HTML-Version von DuckDuckGo (https://html.duckduckgo.com/html/), die ohne JavaScript auskommt.
 * Die Titel stehen dort in <a class="result__a">, Suchbegriffe darin ggf. in <b>.
 * Bei zu vielen Abfragen antwortet DuckDuckGo mit einer Prüfseite ("anomaly") statt Treffern.
 *
 * @param string $ean Der Barcode
 * @return string[]
 * @throws RuntimeException wenn die Suche fehlschlägt oder DuckDuckGo die Abfrage blockiert
 */
function ean_titles_duckduckgo(string $ean): array
{
    try {
        $html = http_get('https://html.duckduckgo.com/html/?' . http_build_query(['q' => $ean]), 15);
    } catch (RuntimeException $e) {
        throw new RuntimeException('Websuche über DuckDuckGo fehlgeschlagen: ' . $e->getMessage());
    }
    if (!preg_match_all('#<a[^>]*class="result__a"[^>]*>(.*?)</a>#s', $html, $m)) {
        if (stripos($html, 'anomaly') !== false || stripos($html, 'captcha') !== false) {
            throw new RuntimeException('DuckDuckGo hat die Abfrage vorübergehend blockiert, bitte später erneut versuchen '
                . 'oder den Titel eintippen');
        }
        return [];
    }
    return array_values(array_filter(array_map(
        fn($t) => trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        $m[1]
    )));
}

/**
 * Google-Ergebnisse über SerpApi (https://serpapi.com).
 *
 * @param string $ean Der Barcode
 * @return string[]
 * @throws RuntimeException wenn die Such-API nicht erreichbar ist oder den Schlüssel ablehnt
 */
function ean_titles_serpapi(string $ean): array
{
    $url = 'https://serpapi.com/search.json?' . http_build_query([
        'engine' => 'google', 'q' => $ean, 'hl' => 'de', 'gl' => 'de', 'num' => 10, 'api_key' => EAN_SEARCH_API_KEY,
    ]);
    $data = ean_api_get($url, 'SerpApi');
    return array_values(array_filter(array_map(fn($r) => (string) ($r['title'] ?? ''), $data['organic_results'] ?? [])));
}

/**
 * Ergebnisse der Brave Search API (https://brave.com/search/api/).
 *
 * @param string $ean Der Barcode
 * @return string[]
 * @throws RuntimeException wenn die Such-API nicht erreichbar ist oder den Schlüssel ablehnt
 */
function ean_titles_brave(string $ean): array
{
    $url = 'https://api.search.brave.com/res/v1/web/search?' . http_build_query([
        'q' => $ean, 'country' => 'de', 'search_lang' => 'de', 'count' => 20,
    ]);
    $data = ean_api_get($url, 'Brave Search', ['Accept: application/json', 'X-Subscription-Token: ' . EAN_SEARCH_API_KEY]);
    // Brave hebt Suchbegriffe mit <strong> hervor.
    return array_values(array_filter(array_map(
        fn($r) => html_entity_decode(strip_tags((string) ($r['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        $data['web']['results'] ?? []
    )));
}

/**
 * JSON-GET-Aufruf einer Such-API mit verständlichen Fehlermeldungen.
 *
 * @param string $url Abzufragende URL
 * @param string $name Anzeigename der API (für Fehlermeldungen)
 * @param array $headers Zusätzliche Request-Header ("Name: Wert")
 * @return array Die dekodierte JSON-Antwort
 * @throws RuntimeException mit einer Meldung, die search.php direkt anzeigen kann
 */
function ean_api_get(string $url, string $name, array $headers = []): array
{
    try {
        $body = http_get($url, 15, 2 * 1024 * 1024, $headers);
    } catch (RuntimeException $e) {
        if (preg_match('/HTTP (401|403)/', $e->getMessage())) {
            throw new RuntimeException("$name hat den API-Schlüssel abgelehnt (EAN_SEARCH_API_KEY in config.php prüfen)");
        }
        if (str_contains($e->getMessage(), 'HTTP 429')) {
            throw new RuntimeException("Das Kontingent bei $name ist aufgebraucht");
        }
        throw new RuntimeException("Websuche über $name fehlgeschlagen: " . $e->getMessage());
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("$name lieferte keine gültige Antwort");
    }
    return $data;
}

/**
 * Der mutmaßliche Filmtitel: die längste Wortfolge (höchstens 8 Wörter, ohne EAN_STOPWORDS und ohne lange
 * Ziffernfolgen wie die EAN selbst), die in mindestens 40 % der Ergebnistitel (mindestens zwei) vorkommt. Bei
 * gleicher Länge gewinnt die häufigere. Null, wenn sich nichts wiederholt.
 * Die Schreibweise ist die häufigste unter den Fundstellen ("Triangle of Sadness" statt "TRIANGLE OF SADNESS"
 * eines einzelnen eBay-Angebots), bei Gleichstand die zuerst gefundene.
 *
 * @param string[] $titles
 * @return string|null Der Titel; null, wenn sich nichts wiederholt
 */
function ean_title_from_results(array $titles): ?string
{
    $count = [];
    $spellings = [];
    foreach ($titles as $title) {
        $words = preg_split("/[^\\p{L}\\p{N}']+/u", $title, -1, PREG_SPLIT_NO_EMPTY);
        $lower = array_map(fn($w) => mb_strtolower(trim($w, "'")), $words);
        $seen = [];
        for ($i = 0, $n = count($words); $i < $n; $i++) {
            for ($len = 1; $len <= 8 && $i + $len <= $n; $len++) {
                $w = $lower[$i + $len - 1];
                if ($w === '' || in_array($w, EAN_STOPWORDS, true) || preg_match('/^\d{5,}$/', $w)) {
                    break;
                }
                $key = implode(' ', array_slice($lower, $i, $len));
                $seen[$key] ??= implode(' ', array_slice($words, $i, $len));
            }
        }
        foreach ($seen as $key => $spelling) {
            $count[$key] = ($count[$key] ?? 0) + 1;
            $spellings[$key][$spelling] = ($spellings[$key][$spelling] ?? 0) + 1;
        }
    }

    $min = max(2, (int) ceil(count($titles) * 0.4));
    $best = null;
    $bestRank = null;
    foreach ($count as $key => $c) {
        // Einzelne Ziffern ("1", "2") oder Ein-Buchstaben-Wörter allein sind kein Titel.
        if ($c < $min || mb_strlen((string) $key) < 2 || ctype_digit((string) $key)) {
            continue;
        }
        $rank = [substr_count((string) $key, ' '), $c];
        if ($bestRank === null || $rank > $bestRank) {
            [$best, $bestRank] = [(string) $key, $rank];
        }
    }
    if ($best === null) {
        return null;
    }
    // arsort() ist stabil (PHP 8): Bei Gleichstand bleibt die zuerst gefundene Schreibweise vorn.
    arsort($spellings[$best]);
    return (string) array_key_first($spellings[$best]);
}

/**
 * Sucht den aus den Ergebnistiteln gewonnenen Titel bei TMDB. Hängt noch ein Zusatz daran ("The Transporter TV
 * Movie" -> nichts gefunden), wird er Wort für Wort von hinten gekürzt, bis TMDB etwas findet – aber nicht bis
 * auf ein einzelnes kurzes Wort ("The"), das fände irgendetwas. War der Titel von vornherein ein Wort ("Dark"),
 * wird er natürlich gesucht.
 *
 * @param array $titles Titel der Websuche-Ergebnisse
 * @param callable(string): array $search z. B. fn($q) => tmdb_search($q)
 * @return array{query: ?string, results: array} query = Suchbegriff, mit dem TMDB fündig wurde (bzw. der
 *         ungekürzte, wenn nie), null, wenn sich aus den Titeln nichts ableiten ließ
 */
function ean_tmdb_search(array $titles, callable $search): array
{
    $title = ean_title_from_results($titles);
    if ($title === null) {
        return ['query' => null, 'results' => []];
    }
    $words = explode(' ', $title);
    $single = count($words) === 1;
    while (count($words) > 1 || ($words !== [] && ($single || mb_strlen($words[0]) >= 4))) {
        $query = implode(' ', $words);
        $results = $search($query);
        if ($results !== []) {
            return ['query' => $query, 'results' => $results];
        }
        array_pop($words);
    }
    return ['query' => $title, 'results' => []];
}

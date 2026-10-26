<?php

// Vorlage für config.php

/**
 * Daten-Ordner. Der Zugriff läuft ausschließlich über die API.
 *
 * @var string Absoluter Pfad, ohne abschließenden Slash
 */
const DATA_DIR = __DIR__ . '/data';

/**
 * Unterordner für Filmdateien (inkl. Serien).
 *
 * @var string Absoluter Pfad, ohne abschließenden Slash
 */
const MOVIES_DIR = DATA_DIR . '/movies';

/**
 * Muss im Header X-API-Token mitgeschickt werden, wenn Daten geändert werden.
 *
 * @var string Geheimes Token
 */
const API_TOKEN = 'CHANGE_ME';

/**
 * Zielbreite automatisch generierter Thumbnails (Center-Crop im Hochformat 2:3 wie ein Filmplakat,
 * siehe api/lib/thumbnail.php).
 *
 * @var int Pixel
 */
const THUMB_WIDTH = 400;

/**
 * Zielhöhe automatisch generierter Thumbnails (Center-Crop im Hochformat 2:3 wie ein Filmplakat,
 * siehe api/lib/thumbnail.php).
 *
 * @var int Pixel
 */
const THUMB_HEIGHT = 600;

/**
 * Erlaubte Datei-Endung => erwarteter MIME-Type, geprüft beim Bild-Upload.
 *
 * @var array<string, string>
 */
const ALLOWED_IMAGE_TYPES = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

/**
 * Maximal erlaubte Upload-Größe eines Coverbilds.
 *
 * @var int Bytes
 */
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

/**
 * API-KEY von TMDB (The Movie Database, https://www.themoviedb.org/) für den Film-Import.
 * Vorläufig der API-Key, den Team Kodi in seinem Scraper "The Movie Database Python" mitliefert.
 * Ist durch einen eigenen Key zu ersetzen!
 *
 * @var string
 */
const TMDB_API_KEY = 'f090bb54758cabf231fb605d3e3e0468';

/**
 * Sprache der importierten Daten (Titel, Handlung, Genres, Trailer, Poster).
 * Das Land für die Altersfreigabe (hier: DE -> FSK) wird daraus abgeleitet.
 *
 * @var string Sprachcode mit Region, z. B. "de-DE"
 */
const TMDB_LANGUAGE = 'de-DE';

/**
 * Vorgabe für den lokalen Ordner mit den Filmdateien für Medium "USB" (USB-Platte/-Stick): steht in der
 * Detailansicht vor dem Pfad der Datei; jedes Gerät kann ihn ändern.
 *
 * @var string
 */
const USB_ROOT = 'D:\Videos';

/**
 * Websuche für den Barcode-Scan beim Import (api/lib/ean.php):
 *   'duckduckgo' – HTML-Version von DuckDuckGo, ohne Schlüssel (Voreinstellung)
 *   'serpapi'    – Google-Ergebnisse über https://serpapi.com (Schlüssel nötig, kostenloses Monatskontingent)
 *   'brave'      – https://brave.com/search/api/ (Schlüssel nötig, monatliches Freiguthaben, Kreditkarte nötig)
 * Ergebnisse werden je EAN unter DATA_DIR/cache/ean/ zwischengespeichert.
 *
 * @var string "duckduckgo", "serpapi" oder "brave"
 */
const EAN_SEARCH_PROVIDER = 'duckduckgo';

/**
 * API-Schlüssel für die Such-API aus EAN_SEARCH_PROVIDER (bei "duckduckgo" nicht nötig).
 *
 * @var string Leer, wenn kein Schlüssel gesetzt ist
 */
const EAN_SEARCH_API_KEY = '';

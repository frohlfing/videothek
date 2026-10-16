<?php

// Vorlage für config.php

// Ordner, in dem alle Filmdaten liegen. Bewusst außerhalb von public/ (= außerhalb des
// Document Root), damit der Browser nicht direkt darauf zugreifen kann. Der Zugriff läuft
// ausschließlich über die API (api/movies.php, api/image.php).
const DATA_DIR = __DIR__ . '/data';

// Ein Unterordner pro Film: MOVIES_DIR/<slug>/movie.json, image.<ext> (Cover), thumb.<ext>
const MOVIES_DIR = DATA_DIR . '/movies';

// Muss im Header X-API-Token mitgeschickt werden, damit POST/PUT/DELETE auf movies.php
// und der Upload auf upload.php akzeptiert werden. GET bleibt ohne Token offen.
const API_TOKEN = 'CHANGE_ME';

// Zielmaße für automatisch generierte Thumbnails (Center-Crop im Hochformat 2:3 wie ein Filmplakat,
// siehe api/lib/thumbnail.php)
const THUMB_WIDTH = 400;
const THUMB_HEIGHT = 600;

// Erlaubte Datei-Endung => erwarteter MIME-Type, geprüft beim Bild-Upload
const ALLOWED_IMAGE_TYPES = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

// Maximal erlaubte Upload-Größe eines Coverbilds in Bytes
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

// API-KEY von TMDB (The Movie Database, https://www.themoviedb.org/) für den Film-Import
// Vorläufig der API-Key, den Team Kodi in seinem Scraper "The Movie Database Python" mitliefert.
// Ist durch einen eigenen Key zu ersetzen!
const TMDB_API_KEY = 'f090bb54758cabf231fb605d3e3e0468';

// Sprache der importierten Daten (Titel, Handlung, Genres, Trailer, Poster).
// Das Land für die Altersfreigabe (hier: DE -> FSK) wird daraus abgeleitet.
const TMDB_LANGUAGE = 'de-DE';

// Ordner mit den Filmdateien für Medium "USB" (USB-Platte/-Stick). Der Pfad gilt auf dem Rechner, der den
// Film abspielt (nicht auf dem Webserver): Der Play-Button liefert eine Wiedergabeliste (.m3u) mit
// USB_ROOT + der im Film eingetragenen relativen Datei, die der dort installierte Player öffnet.
const USB_ROOT = 'D:\videothek';

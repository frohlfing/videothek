# Videothek

Eine private Familien-Videothek: die Sammlung an Filmen und Serien (DVD, Blu-ray, USB, ...) als kleine
PHP/JSON-API mit einer statischen One-Page-Frontend-App. Mehrere Freunde können ihre Sammlungen gemeinsam pflegen:
Jedes Exemplar hat einen Eigentümer und bei DVDs und Blu-rays steht, wer sie gerade ausgeliehen hat. Jeder User kann
Filme auf seine Wunschliste setzen und in vier Stufen von "nicht sehenswert" bis "muss man sehen" bewerten.

## Voraussetzungen

- PHP 8.5+ mit aktivierter **GD**-Extension (für Thumbnails)
- Ein Webserver mit `public/` als Document Root
- Kein Datenbankserver nötig – alle Daten liegen als Dateien unter `data/`, bewusst außerhalb des Document Root 
  (Zugriff nur über `api/movies.php` bzw. `api/image.php`, nicht direkt per URL)

## Setup

1. `config.example.php` nach `config.php` kopieren (liegt im Projekt-Root, **nicht** unter `public/`, damit es 
   außerhalb des Document Root und damit nicht direkt aufrufbar ist).

2. In `config.php` ein echtes `API_TOKEN` eintragen, z. B.:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   ```
   Für den Film-Import außerdem `TMDB_API_KEY` und `TMDB_LANGUAGE` prüfen (siehe Abschnitt "Import-Funktion").
   Vorbelegt ist vorläufig der API-Key aus dem Kodi-Scraper; ein eigener ist kostenlos (themoviedb.org →
   Einstellungen → API).
   Die Suche per Barcode läuft ohne Eintrag über DuckDuckGo; optional `EAN_SEARCH_PROVIDER`/`EAN_SEARCH_API_KEY`
   (siehe "Barcode scannen").

3. Webserver mit Document Root `public/` auf ein Verzeichnis zeigen lassen (z. B. XAMPP-vHost).

4. GD-Extension für PHP aktivieren. Zur Prüfung gibt es `public/dev/info.php` (ruft `phpinfo()` auf, im Browser 
   aufrufen und nach "gd" suchen) – siehe auch Abschnitt "Admin-/Dev-Skripte" unter Projektstruktur.

   Unter XAMPP:
   - `C:\xampp\php\php.ini` öffnen und die Zeile `;extension=gd` zu `extension=gd` ändern.
   - Apache neu starten.

   Unter Hetzner-Webspace:
   - GD ist standardmäßig aktiviert. Falls nicht, kann dies per KonsoleH eingerichtet werden.

5. **HTTP Basic Auth einrichten** (schützt zusätzlich zum API-Token auch die statische `index.html` selbst – ohne 
   diese Auth wäre die leere Seiten-Hülle trotz Token-Schutz der API weiterhin für jeden abrufbar):
   ```
   C:\xampp\apache\bin\htpasswd -bcB .htpasswd <benutzername> <passwort>
   ```
   Die `.htpasswd`-Datei liegt im Projekt-Root (außerhalb des Document Root, neben `config.php`). Danach 
   `public/.htaccess.example` nach `public/.htaccess` kopieren und den `AuthUserFile`-Pfad auf den tatsächlichen 
   absoluten Pfad zur `.htpasswd` auf diesem Server anpassen. Beide Dateien (`.htpasswd`, `public/.htaccess`) sind 
   gitignored und müssen auf jedem Rechner/Server separat eingerichtet werden.

   Jeder Benutzer der `.htpasswd` ist zugleich Benutzer der App (Eigentümer von Medien, "eingetragen von" bei
   Ausleihen). Weitere Benutzer legt `php bin/account.php --add` an, siehe "Benutzer verwalten".
   Für Änderungen braucht jeder User zusätzlich das API-Token.

## Lokalen Testserver unter XAMPP (Windows) einrichten

1. URL in die Hosts-Datei eintragen

    `C:\Windows\System32\drivers\etc\hosts` als Administrator öffnen:
    
    ```
    127.0.0.1      videothek.test
    ```

2. SSL-Zertifikat installieren
    ```shell
    cd C:\xampp\apache\bin
    
    .\openssl.exe req -x509 -nodes -newkey rsa:2048 -sha256 `
     -keyout "C:\xampp\apache\conf\ssl.key\videothek.test.key" `
    -out "C:\xampp\apache\conf\ssl.crt\videothek.test.crt" `
     -days 3650 `
    -config "C:\xampp\apache\conf\openssl.cnf" `
     -subj "/CN=videothek.test" `
    -addext "basicConstraints=CA:FALSE" `
    -addext "subjectAltName=DNS:videothek.test"
    ```

    Apache neu starten!

3. VirtualHost hinzufügen

    Datei `C:\xampp\apache\conf\extra\httpd-vhosts.conf` bearbeiten:
    
    ```
    <VirtualHost *:80>
        ServerName videothek.test
    
        DocumentRoot "C:/Users/frank/Source/PhpStorm/videothek/public"
    
        <Directory "C:/Users/frank/Source/PhpStorm/videothek/public">
            AllowOverride All
            Require all granted
        </Directory>
    </VirtualHost>
    
    <VirtualHost *:443>
        ServerName videothek.test
    
        DocumentRoot "C:/Users/frank/Source/PhpStorm/videothek/public"
    
        SSLEngine on
        SSLCertificateFile "C:/xampp/apache/conf/ssl.crt/videothek.test.crt"
        SSLCertificateKeyFile "C:/xampp/apache/conf/ssl.key/videothek.test.key"
    
        <Directory "C:/Users/frank/Source/PhpStorm/videothek/public">
            AllowOverride All
            Require all granted
        </Directory>
    </VirtualHost>
    ```
    
    Apache neu starten!

4. SSL-Zertifikat in den Browser-Zertifikatsspeicher importieren:

    Damit der Browser dem selbstsignierten Zertifikat vertraut, muss es in den Browser-Zertifikatsspeicher importiert 
    werden:
    - Doppelklick auf `C:\xampp\apache\conf\ssl.crt\videothek.test.crt`
        - Zertifikat installieren...,
            - Lokaler Computer
            - Zertifikatsspeicher: Vertrauenswürdige Stammzertifizierungsstellen
    
    Browser neu starten!
    
    Die Homepage ist jetzt erreichbar unter: https://videothek.test/

## Benutzer verwalten

| Aufruf                                   | Zweck                                                                                                 |
|------------------------------------------|-------------------------------------------------------------------------------------------------------|
| `php bin/account.php --add <login> …`    | Legt **lokal** einen Benutzer an (`.htpasswd`, `users.json`): `--name=…`, `--password=…`, `--owner=…` |
| `php bin/account.php --change <login> …` | Ändert nur, was angegeben ist: `--name=…`, `--password[=…]`, `--owner=[…]` (leer: selbst Eigentümer)  |
| `php bin/account.php --remove <login>`   | Entfernt ihn lokal, samt Wünschen und Bewertungen                                                     |

## Abgleich mit dem Server

Auf dem Server (Hetzner-Webspace) gibt es kein Terminal. Die Skripte in `bin/` laufen deshalb lokal; `bin/remote.php`
spricht den Server über `dev/sync.php` an (Token nötig, auch zum Lesen). Wer das Token kennt, könnte darüber alle Daten
lesen und überschreiben und Zugänge anlegen. Deshalb liegt `sync.php` unter `public/dev/` und kommt
**nur zum Abgleichen** auf den Server: Vorher den Ordner `dev/` per FTP hochladen, danach dort wieder löschen.
`remote.php` erinnert am Ende daran; fehlt `dev/sync.php` auf dem Server, sagt es das.

| Aufruf                                                   | Zweck                                                                                                            |
|----------------------------------------------------------|------------------------------------------------------------------------------------------------------------------|
| `php bin/remote.php --download-data --password=…`        | Gleicht das lokale `data/` mit dem Server ab (lokal danach genau wie dort); auf dem Server wird nichts verändert |
| `php bin/remote.php --upload-data --password=…`          | Lädt nach `data/` auf dem Server, was dort fehlt oder lokal neuer ist; auf dem Server wird nichts gelöscht       |
| `php bin/remote.php --upload-account lilie --password=…` | Überträgt einen Benutzer auf den Server (`.htpasswd` und `users.json` dort)                                      |

**Zugang zum Server:** `--password` ist das Basic-Auth-Passwort auf dem Server, `--user` der Benutzer (Vorgabe `frank`).
Als Token dient `API_TOKEN` aus der lokalen `config.php`.

**Download und Upload** vergleichen jede Datei über ihren Inhalt (MD5); die Kopie bekommt danach die Änderungszeit des
Originals, damit der nächste Vergleich stimmt. Der **Download** übernimmt den Stand des Servers vollständig: Er
überschreibt auch lokal neuere Dateien (in der Liste als "lokal neuer" markiert) und löscht lokal, was es auf dem
Server nicht gibt.

Der **Upload** überschreibt nur, was auf dem Server älter ist; ist die Datei dort neuer (z. B. `locations.json` nach
einer Ausleihe auf dem Server), bleibt sie und wird nur gemeldet. Beide zeigen erst, was passieren würde, und fragen
dann nach (`--yes`: ohne Rückfrage).

`data/cache/` bleibt außen vor. Übertragen wird nur `data/`, den Code lädt man weiter per FTP hoch.

Üblicher Ablauf: erst `--download-data`, dann lokal arbeiten (Skripte, Importe), dann `--upload-data`.

## Projektstruktur

```
videothek/                            # Projektroot
 ├── data/                            # Außerhalb des Document Root, kein direkter Browser-Zugriff möglich
 │    ├── cache/ean/                  # Treffertitel der Websuche je Barcode
 │    ├── cache/sync_md5.json         # MD5 je Datei für den Abgleich
 │    ├── collections.json            # Alle Filme je Reihe laut TMDB
 │    ├── locations.json              # Wo DVDs und Blu-rays gerade sind
 │    ├── ratings.json                # Bewertungen: -1 bis 2 je Benutzer
 │    ├── series.json                 # Alle Staffeln je Serie laut TMDB
 │    ├── users.json                  # Anzeigename und ggf. gemeinsamer Eigentümer je Benutzer der .htpasswd
 │    ├── wishes.json                 # Wunschliste: wer welchen Eintrag sehen möchte
 │    └── movies/                     # Ein Unterordner pro Film bzw. Staffel
 │         └── <slug>/                # Slug aus Titel + Jahr, z. B. "die-goonies-1985", "breaking-bad-staffel-1-2008"
 │              ├── actors/           # Darstellerfotos wie bei Kodi benannt (Sean_Astin.jpg), aber nicht versteckt
 │              ├── movie.json        # Daten (Titel, Jahr, Genres, Darsteller, Folgen, ...)
 │              ├── poster.<ext>      # Poster (Upload oder TMDB)
 │              ├── fanart.<ext>      # Hintergrundbild (nur per TMDB-Import)
 │              └── thumb.<ext>       # automatisch generierte Miniatur des Posters (Hochformat 2:3)
 ├── bin/                             # Skripte für die Kommandozeile (lokal, php bin/…)
 │    ├── account.php                 # Benutzer lokal anlegen, ändern, entfernen (.htpasswd, users.json)
 │    ├── build_convert_portable.cmd  # convert.php samt PHP, ffmpeg und api/lib als Paket (<Zielordner>)
 │    ├── convert.php                 # Platte aufnehmen: je Film umwandeln, taggen, ablegen, auf dem Server eintragen
 │    └── remote.php                  # Abgleich mit dem Server: --download-data, --upload-data, --upload-account
 ├── public/                          # Document Root
 │    ├── api/                        # API-Endpunkte
 │    │    └── lib/                   # Gemeinsam genutzte Hilfsfunktionen der API-Endpunkte
 │    │    │    ├── actors.php        # Darstellerfotos von TMDB laden
 │    │    │    ├── catalog.php       # collections.json/series.json lesen und schreiben
 │    │    │    ├── crud.php          # Read/Write/Rename/Delete-Logik + Validierung
 │    │    │    ├── ean.php           # Barcode (EAN) → Websuche → Suchbegriff für TMDB
 │    │    │    ├── fetch.php         # http_get(): curl, sonst file_get_contents()-Fallback
 │    │    │    ├── http.php          # JSON-Response-Helper
 │    │    │    ├── image_store.php   # Poster/Hintergrundbild/Darstellerfoto speichern + Thumbnail
 │    │    │    ├── jsonfile.php      # Kleine JSON-Dateien lesen und unter Sperre ändern
 │    │    │    ├── locations.php     # locations.json lesen und schreiben (Standorte der Scheiben)
 │    │    │    ├── ratings.php       # ratings.json lesen und schreiben (Bewertungen)
 │    │    │    ├── slug.php          # slugify() + Eindeutigkeits-Check
 │    │    │    ├── sync.php          # Abgleich von data/: Dateiliste mit MD5, Datei schreiben
 │    │    │    ├── thumbnail.php     # Thumbnail-Erzeugung per GD (Center-Crop) + Lazy-Fallback
 │    │    │    ├── tmdb.php          # TMDB-API: Suche, Film-/Seriendetails, Zuordnung zu movie.json
 │    │    │    ├── users.php         # Angemeldeter Benutzer, users.json, .htpasswd
 │    │    │    ├── wishes.php        # wishes.json lesen und schreiben (Wunschliste)
 │    │    │    └── youtube.php       # Verfügbarkeit von YouTube-Videos prüfen (oEmbed)
 │    │    ├── actors.php             # Darstellerfotos eines Eintrags herunterladen (mehrere Aufrufe)
 │    │    ├── catalog.php            # Welche Teile/Staffeln es laut TMDB gibt (für "2 von 7")
 │    │    ├── image.php              # Liefert Poster, Thumbnail, Hintergrundbild, Darstellerfoto
 │    │    ├── import.php             # Film oder Serie (je Staffel ein Eintrag) aus TMDB importieren
 │    │    ├── locations.php          # Standorte der Scheiben lesen und setzen (Ausleihe)
 │    │    ├── media.php              # Pfade, Bild und Ton eines USB-Mediums setzen (für bin/convert.php)
 │    │    ├── movies.php             # Filme und Staffeln lesen, anlegen, bearbeiten, löschen
 │    │    ├── ratings.php            # Bewertungen lesen und setzen
 │    │    ├── search.php             # Suche nach Filmen und Serien in TMDB (Titel, IMDb-ID, Barcode)
 │    │    ├── upload.php             # Poster-/Hintergrundbild-Upload, zum Poster inkl. Thumbnail
 │    │    ├── users.php              # Angemeldeter Benutzer und Anzeigenamen
 │    │    └── wishes.php             # Wunschliste lesen und setzen
 │    ├── dev/                        # Admin-/Wartungsskripte – NICHT dauerhaft auf dem Produktivserver lassen
 │    │    ├── info.php               # phpinfo()
 │    │    ├── scan-test.html         # PoC: Barcode-Erkenner auf denselben Kamerabildern vergleichen
 │    │    ├── scan-test.js           # JavaScript dazu
 │    │    └── sync.php               # Für bin/: data/ abgleichen, Benutzer übertragen
 │    ├── .htaccess                   # Apache Zugriffsschutz
 │    ├── .htaccess.example           # Vorlage für Apache Zugriffsschutz
 │    ├── app.css                     # CSS-Styles für das Frontend
 │    ├── app.js                      # JavaScript für das Frontend
 │    ├── vendor/qrcode.js            # QR-Code-Generator (Kazuhiko Arase, MIT) für den Trailer im Ausdruck
 │    ├── vendor/zxing.min.js         # Barcode-Erkennung (@zxing/library 0.21.3, Apache-2.0), nur ohne BarcodeDetector
 │    ├── favicon.svg                 # Browser-Tab-Icon (Filmklappe)
 │    └── index.html                  # Frontend (lädt/schreibt ausschließlich über /api/*)
 ├── .gitignore                       # Vom Git-Repository auszuschließende Dateien
 ├── .htpasswd                        # Apache Passwortdatei (nicht im Git-Repository)
 ├── types/filesystem.d.ts            # Nur für die IDE: Typen für den Ordnerzugriff des Browser-Players (nicht deployen)
 ├── types/scanner.d.ts               # Nur für die IDE: Typen für BarcodeDetector und ZXing (nicht deployen)
 ├── config.example.php               # Vorlage für config.php
 ├── config.php                       # Zentrale Konfigurationsdatei (nicht im Git-Repository)
 ├── LICENSE                          # Lizenzhinweis
 └── README.md                        # Landingpage für das Git-Repository
```

### Admin-/Dev-Skripte (`public/dev/`)

Einmalige Wartungs- und Diagnose-Skripte, die (anders als die eigentliche App) direkt im Browser aufgerufen werden.
Auf Hetzner-Shared-Webspace gibt es keinen Terminalzugriff, ein CLI-Pendant entfällt daher bewusst. 

**Wichtig:** `public/dev/` gehört **nicht dauerhaft** auf den Produktivserver. Nach Gebrauch dort löschen – ein 
Server-Interna preisgebender `phpinfo()`-Endpunkt und ein Token im URL-Query (landet in Server-Logs/Browser-Verlauf) 
sollen keine Dauereinrichtung sein.

## API

Alle schreibenden Endpunkte (`POST`/`PUT`/`DELETE`) erwarten den Header `X-API-Token: <token>`, sonst `401`. 
Lesende Endpunkte (`GET`) sind ohne eigenes Token offen — Schutz vor öffentlichem Zugriff übernimmt stattdessen 
die HTTP Basic Auth vor der gesamten `public/`-Domain (siehe Setup, Schritt 5), die auch die statische `index.html` 
selbst mit abdeckt. Ausnahme: `dev/sync.php` verlangt das Token auch zum Lesen.

| Methode      | Endpunkt                                             | Beschreibung                                                                                                                                                                       |
|--------------|------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| GET          | `/api/movies.php`                                    | Alle Filme und Staffeln, alphabetisch nach Titel (das Frontend sortiert ggf. um); dazu je `thumbHash` (Prüfsumme des Thumbnails)                                                   |
| GET          | `/api/movies.php?slug=xyz`                           | Einzelner Eintrag                                                                                                                                                                  |
| POST         | `/api/movies.php`                                    | Neuen Film bzw. neue Staffel anlegen (JSON-Body, `type` = `movie`\|`season`)                                                                                                       |
| PUT          | `/api/movies.php?slug=xyz`                           | Eintrag bearbeiten (JSON-Body, ohne `type` bleibt die Art). Ändert sich der Slug (Titel/Jahr), wird der Ordner atomar umbenannt.                                                   |
| DELETE       | `/api/movies.php?slug=xyz`                           | Eintrag samt Ordner (Bilder inklusive) löschen                                                                                                                                     |
| POST         | `/api/upload.php`                                    | Bild hochladen (`multipart/form-data`: `slug`, `image`, `kind` = `poster`\|`fanart`\|`actor` + `actor`); mit `remove=1` Poster bzw. Hintergrundbild entfernen                      |
| GET          | `/api/image.php?slug=xyz&type=poster\|thumb\|fanart` | Poster, Thumbnail bzw. Hintergrundbild ausliefern                                                                                                                                  |
| GET          | `/api/image.php?slug=xyz&actor=Sean%20Astin`         | Darstellerfoto ausliefern                                                                                                                                                          |
| GET          | `/api/search.php?query=…&year=…`                     | Suche nach Filmen und Serien in TMDB (Titel, IMDb-ID oder EAN, Jahr optional), markiert bereits vorhandene                                                                         |
| POST         | `/api/import.php`                                    | Film bzw. Serie (je Staffel ein Eintrag) aus TMDB importieren inkl. Bilder (Body: `{"tmdb": 9340, "type": "movie"}`, Serie: `{"tmdb": 1396, "type": "series", "seasons": [1, 2]}`) |
| GET          | `/api/users.php`                                     | Angemeldeter Benutzer (`me`), alle Benutzer mit Anzeigenamen, Vorgabe für den USB-Ordner                                                                                           |
| GET          | `/api/locations.php`                                 | Standorte aller Scheiben, die nicht beim Eigentümer sind (`data/locations.json`)                                                                                                   |
| PUT          | `/api/locations.php`                                 | Standort einer Scheibe setzen (Body: `{"slug", "owner", "type", "at"}`; `at` = Login oder freier Name, leer oder der Eigentümer = zurück)                                          |
| PUT          | `/api/media.php`                                     | Nur Pfade, Bild und Ton eines USB-Mediums setzen, der Rest des Eintrags bleibt (Body: `{"slug", "owner", "type": "USB", "paths", "video", "audio"}`; für `bin/convert.php`)        |
| GET          | `/api/wishes.php`                                    | Wunschlisten aller Benutzer (`data/wishes.json`): je Slug Login und Zeitpunkt                                                                                                      |
| PUT          | `/api/wishes.php`                                    | Eigenen Wunsch setzen bzw. entfernen (Body: `{"slug", "wish": true\|false}`; Anmeldung nötig)                                                                                      |
| GET          | `/api/ratings.php`                                   | Bewertungen aller Benutzer (`data/ratings.json`): je Slug Login und -1, 0, 1 oder 2                                                                                                |
| PUT          | `/api/ratings.php`                                   | Eigene Bewertung setzen (Body: `{"slug", "rating": -1\|0\|1\|2\|null}`, null = entfernen; Anmeldung nötig)                                                                         |
| GET/PUT/POST | `/dev/sync.php`                                      | Nur für `bin/`, Token nötig: Dateiliste von `data/` mit MD5, Datei lesen/schreiben, Benutzer übertragen (`?account`)                                                               |
| POST         | `/api/actors.php`                                    | Fehlende Darstellerfotos laden, ~10 s pro Aufruf, bis `remaining` 0 ist (Body: `{"slug": "..."}`)                                                                                  |
| GET          | `/api/catalog.php`                                   | Alle Teile je Reihe und Staffeln je Serie laut TMDB (`collections`, `series`, siehe `api/lib/catalog.php`)                                                                         |

`movie.json`-Schema eines Films:

```json
{
  "type": "movie",
  "title": "Die Goonies",
  "originalTitle": "The Goonies",
  "year": 1985,
  "runtime": 114,
  "fsk": "12",
  "genres": ["Abenteuer", "Komödie", "Familie"],
  "collection": null,
  "director": "Richard Donner",
  "media": [
    { "owner": "frank", "type": "USB", "paths": ["die-goonies/die-goonies-1985.mp4"],
      "video": { "codec": "h264", "width": 720, "height": 576, "sar": "64:45", "fps": 25, "kbps": 2400 },
      "audio": { "codec": "aac", "channels": 6, "kbps": 384, "lang": "ger" } },
    { "owner": "lilie", "type": "DVD" }
  ],
  "poster": "poster.jpg",
  "thumb": "thumb.jpg",
  "fanart": "fanart.jpg",
  "plot": "...",
  "trailer": "https://www.youtube.com/watch?v=nnjVfIqkhzE",
  "cast": [
    { "name": "Sean Astin", "role": "Mikey", "profile": "/As3ctGUtBYmG4zj4Ifyrcqd71HP.jpg", "thumb": "Sean_Astin.jpg" },
    { "name": "Josh Brolin", "role": "Brand", "profile": "/sX2etBbIkxRaCsATyw5ZpOVMPTD.jpg", "thumb": "Josh_Brolin.jpg" }
  ],
  "notes": null,
  "uniqueids": { "tmdb": "9340", "imdb": "tt0089218" },
  "slug": "die-goonies-1985",
  "created": "2026-10-16T02:59:11+02:00"
}
```

Eine **Staffel** einer Serie (`"type": "season"`) ist ein Eintrag wie ein Film, mit eigenem Cover, Medien,
Handlung und Darstellern. Statt `collection` und `director` hat sie `series` (die Serie, aufgebaut wie die Reihe
eines Films, siehe "Filmreihen und Serien"), `season` (Nummer, `0` = Specials), `creator` ("Idee") und `episodes`
(gekürzt):

```json
{
  "type": "season",
  "title": "Breaking Bad – Staffel 1",
  "originalTitle": null,
  "year": 2008,
  "runtime": 48,
  "fsk": "16",
  "genres": ["Drama", "Krimi"],
  "series": { "name": "Breaking Bad", "tmdb": "1396" },
  "season": 1,
  "creator": "Vince Gilligan",
  "media": [
    { "owner": "frank", "type": "DVD" },
    { "owner": "frank", "type": "USB", "paths": ["breaking-bad/breaking-bad-staffel-1-2008/breaking-bad-s01e01.mp4", "breaking-bad/breaking-bad-staffel-1-2008/breaking-bad-s01e02.mp4"] }
  ],
  "episodes": [
    { "number": 1, "title": "Der Einstieg", "plot": "Ein Chemielehrer mit Lungenkrebs im Endstadium ..." },
    { "number": 2, "title": "Die Katze ist im Sack ...", "plot": "..." }
  ],
  "poster": "poster.jpg",
  "...": "weiter wie beim Film (thumb, fanart, plot, trailer, cast, notes, uniqueids, slug, created)"
}
```

Regeln zu den einzelnen Feldern (Pflichtfelder, Wertebereiche, Dateien, Erkennung doppelter Einträge, ältere
Formate) stehen bei den Funktionen in `public/api/lib/crud.php`.

## Frontend / Arbeitsweise

### Request-Ablauf beim Seitenaufruf

Beim Aufruf von `index.html` laufen die Requests in dieser Reihenfolge:

1. **Statische Assets** (durch den Browser beim HTML-Parsing): `favicon.svg`, `app.css`, danach `app.js` (steht am
   Ende von `<body>`, lädt/läuft also erst nach dem übrigen HTML).
2. Sobald `app.js` ausgeführt wird, startet sofort `init()` (kein Warten auf `DOMContentLoaded` nötig, da das Script
   ohnehin erst am Body-Ende geladen wird):
    - **`GET api/movies.php`** lädt die komplette Filmliste als JSON. Das ist der einzige zwingende Request beim Start;
      schlägt er fehl, wird eine Fehlermeldung angezeigt und die Initialisierung bricht ab.
    - Gleichzeitig laden `api/catalog.php` (Teile je Reihe), `api/users.php` (wer angemeldet ist, Anzeigenamen),
      `api/locations.php` (Standorte der Scheiben), `api/wishes.php` (Wunschlisten) und `api/ratings.php` (Bewertungen).
      Fehlt einer davon, fehlen nur die Hinweise bzw. Namen.
    - Beim Rendern des Kartenrasters bekommt jeder Film mit Poster ein
      `<img src="api/image.php?slug=…&type=thumb" loading="lazy">`. Dadurch fordert der Browser die Thumbnails an,
      aber wegen `loading="lazy"` nur die Thumbnails, die tatsächlich (nahe) im sichtbaren Viewport liegen; der Rest
      folgt erst beim Scrollen. Poster (`type=poster`) und Hintergrundbild (`type=fanart`) werden erst beim Öffnen
      eines einzelnen Films nachgeladen.

Kein Token-Request/Prompt beim Laden: `requireToken()` wird ausschließlich bei schreibenden Aktionen (Speichern/
Löschen/Importieren/Ausleihen/Wunschliste/Bewerten) aufgerufen, siehe "Token-Authentifizierung".

### Token-Authentifizierung

Für Änderungen (Film anlegen/bearbeiten/löschen, Bild-Upload, Ausleihe) fragt das Frontend beim ersten
Schreibversuch per `prompt()` nach dem API-Token und legt es danach in `localStorage` unter dem Schlüssel
`videothek_api_token` ab. Jeder weitere Schreibversuch liest das gespeicherte Token wieder aus und fragt nicht erneut 
nach.

Es wird nur dann wieder nachgefragt, wenn
a) der Server das gespeicherte Token ablehnt (401) – dann wird es automatisch aus `localStorage` gelöscht, oder
b) du es manuell löschst, z. B. in den Browser-DevTools mit `localStorage.removeItem('videothek_api_token')` oder
über "Website-Daten löschen".

Lesezugriffe (Filmliste, Bilder) brauchen kein App-Token — Schutz vor öffentlichem Zugriff übernimmt die
HTTP Basic Auth vor der gesamten `public/`-Domain (siehe Setup, Schritt 5). Die Anmeldung bestimmt nur, wer man
ist (Eigentümer beim Import, "eingetragen von" bei Ausleihen); unter CGI/FastCGI (Hetzner) kommt der Benutzername aus
`REMOTE_USER` statt `PHP_AUTH_USER` (`current_user()` in `lib/users.php`).

### Gemeinsame Sammlung

Teilen sich mehrere eine Sammlung (ein Haushalt), steht in `data/users.json` bei jedem derselbe Eigentümer:

```json
{ "frank": { "name": "Frank", "owner": "Rohlfing" },
  "tinka": { "name": "Tinka", "owner": "Rohlfing" },
  "lilie": { "name": "Lilie" } }
```

Verliehen wird an den Haushalt ("Feldmann hat von Rohlfing"); Wunschliste und Bewertungen bleiben bei der Person.

### Wunschliste und Bewertungen

Der Link "**möchte ich sehen**" in der Detailansicht setzt den Eintrag auf die eigene Wunschliste (grünes Häkchen auf 
der Karte). Bewertet wird in vier Stufen; ein zweiter Klick auf dieselbe Stufe nimmt die Bewertung zurück. Die erste
Bewertung nimmt den Eintrag von der Wunschliste (gesehen).

| Wert | Bedeutung                   | Symbol                    |
|------|-----------------------------|---------------------------|
| -1   | schlecht / nicht sehenswert | Daumen runter             |
| 0    | neutral / Zeitvertreib      | Daumen hoch und runter    |
| 1    | gut / sehenswert            | Daumen hoch               |
| 2    | sehr gut / muss man sehen   | Herz (zählt als Favorit)  |

Die Karten zeigen den Durchschnitt ("+1,3"). Wunschliste und Bewertungen gibt es nur mit Anmeldung.

### Trailer

Ist ein Trailer bei YouTube verschwunden, findet der Link "Trailer auf YouTube suchen" im Bearbeitungsformular einen 
neuen; dessen URL trägt man selbst ein.

### Abspielen (Medium USB)

Die Filmdateien liegen unter dem **USB-Ordner** (Vorgabe `USB_ROOT` in `config.php`, z. B. `D:\Videos`). Im Medium
USB stehen ihre Pfade relativ zu diesem Ordner, die Struktur ist frei. Beim Erfassen schlägt die App vor (der Slug ist
der Name des Eintrags in der Datenbank, z. B. `hangover-2009`):

| Eintrag          | Pfad                                            | Beispiel                                                                                   |
|------------------|-------------------------------------------------|--------------------------------------------------------------------------------------------|
| Film             | `<titel>/<slug>.mp4`                            | `bruchreif/bruchreif-2009.mp4`                                                             |
| Film einer Reihe | `<reihe>/<slug>.mp4`                            | `hangover/hangover-2009.mp4`                                                               |
| Folge            | `<serie>/<slug>/<serie>-s<staffel>e<folge>.mp4` | `scrubs-die-anfaenger/scrubs-die-anfaenger-staffel-2-2002/scrubs-die-anfaenger-s02e01.mp4` |

Mehrere Dateien eines Films bzw. einer Folge bekommen `-cd1`, `-cd2`, … angehängt. Die erste Ordnerebene (Titel,
Reihe, Serie) sortiert im Dateiexplorer Filme und Serien gemeinsam alphabetisch. Der Dateiname allein sagt, um welchen
Film es geht. Das ist wichtig, weil VLC und Mediaserver (z. B. FRITZ!Box) oft nur ihn anzeigen. Ändert sich später
Titel, Jahr oder Reihe, bleiben die Pfade unverändert.

In der Detailansicht steht bei einer Datei Auflösung und Urteil, z. B. "SD 640×272 (so lala)" (gut, so lala, schlecht
wie im Medium-Filter). Der Tooltip nennt die Gründe, Codec, Bitrate und den Ton (Sprache, Kanäle, Codec, Bitrate). Die Angaben (`video` und `audio` am Medium) misst
`bin/convert.php` an der MP4-Datei selbst; die Umwandlung mit CRF 18 gibt ihr so viele Bits, wie die Vorlage braucht. Zwei Kriterien, das schlechtere zählt (`videoQuality()` in `app.js`):

| Kriterium                                                                                       | gut                             | mittel                         | schlecht                 |
|-------------------------------------------------------------------------------------------------|---------------------------------|--------------------------------|--------------------------|
| Auflösung: Bildpunkte (Breite in quadratischen Pixeln × Höhe)                                   | ab 640.000 (HD, z. B. 1280×536) | ab 155.000 (SD, z. B. 624×256) | darunter (z. B. 592×240) |
| Kompression: Bits je Bildpunkt und Bild, umgerechnet auf H.264 (z. B. XviD ÷ 1,5, MPEG-2 ÷ 2,5) | ab 0,08                         | ab 0,05                        | darunter                 |

SD (Standard Definition) ist Fernsehauflösung bis DVD (720×576), HD 1280×720, Full HD 1920×1080, UHD 3840×2160; als
Full HD gilt ab 1,3 Mio. Bildpunkten (1920×800), als UHD ab 5 Mio. Gezählt werden die Bildpunkte, nicht die Breite: Bei
gleicher Breite wirkt ein flacheres Bild schlechter (Hangover 592×240 schlecht, Gamer 592×320 mittel). Ohne
Bitrate zählt nur die Auflösung. Fast quadratische Pixel (weniger als 1 % Abweichung, z. B. 1215:1216) gelten als
quadratisch.

Im Medium-Filter stehen unter "USB" die Stufen "USB (Qualität gut)", "USB (Qualität so lala)" (= mittel) und
"USB (Qualität schlecht)", jeweils nur, wenn es Einträge dazu gibt.

Passt die automatische Beurteilung nicht (z. B. wirkt ein Trickfilm in SD oft gut), lässt sie sich im
Bearbeitungsformular beim Medium unter "Bildqualität" überschreiben: "automatisch (…)" zeigt, was die Regel ergibt,
"gut", "so lala" oder "schlecht" speichert eine eigene Einschätzung als `"quality": "gut" | "mittel" | "schlecht"` am
Medium. Sie gilt in Detailansicht und Medium-Filter; der Tooltip nennt dann zusätzlich das automatische Urteil.

Mit Chrome oder Edge am Rechner laufen `.mp4`- und `.m4v`-Dateien in einem Player-Fenster im Browser. Beim ersten Mal
erscheint der Dialog "Videothek-Ordner": Aus Sicherheitsgründen muss man den Ordner (z. B. `D:\Videos`) einmal 
selbst auswählen. Der Browser merkt ihn sich (IndexedDB `videothek`) und fragt nach einem Neustart nur kurz nach der 
Erlaubnis. Ist der Ordner nicht erreichbar (USB-Speicher nicht angeschlossen, umbenannt) oder fehlt die Datei darin, 
kommt der Dialog wieder. 

In allen anderen Fällen (`.avi`, `.mkv`, Firefox, Safari, Handy) gibt es keinen Play-Button. Dafür steht bei jeder
Datei (USB) der Pfad auf diesem Gerät, z. B. `D:\Videos\bruchreif\bruchreif-2009.mp4`, bei Staffeln der Ordner, mit
einem Knopf zum Kopieren. In die Adresszeile des Explorers eingefügt, öffnet Windows die Datei im installierten Player
bzw. den Ordner. Der Stift daneben ändert den USB-Ordner für dieses Gerät (gemerkt im Browser). Vorgabe ist `USB_ROOT`,
unter Android "Interner Speicher/Videos" (so angezeigt; kopiert wird der echte Pfad `/storage/emulated/0/Videos/…`).
Scheitert ein MP4 im Browser-Player (z. B. unbekannter Codec), geht es ebenso über den Pfad.

### Import-Funktion

Der Import holt die Daten von Filmen und Serien aus **TMDB (The Movie Database)**, wie der Kodi-Scraper "The Movie
Database Python" (xbmc-Repo: `addons/metadata.themoviedb.org.python`). 

Zuordnung (`tmdb_movie()` in `api/lib/tmdb.php`):

| TMDB                                           | `movie.json`                                                                  |
|------------------------------------------------|-------------------------------------------------------------------------------|
| `title`, `original_title`                      | `title`, `originalTitle` (nur wenn abweichend)                                |
| `release_date`                                 | `year`                                                                        |
| `genres`                                       | `genres` (von TMDB bereits übersetzt)                                         |
| `runtime`                                      | `runtime`                                                                     |
| `release_dates` des Landes aus `TMDB_LANGUAGE` | `fsk` (erste nicht-leere Einstufung; keine Einstufung → leer, kein US-Rating) |
| `credits.crew` mit `job = Director`            | `director` (kommagetrennt)                                                    |
| `credits.cast` (alle, nach `order`)            | `cast` mit `name`, `role` (`character`), `profile`                            |
| `overview`                                     | `plot`; fehlt die Übersetzung, wie bei Kodi die englische                     |
| `videos` (YouTube, `Trailer` vor `Teaser`)     | `trailer`: der beste **abspielbare** (eigene Sprache vor englisch)            |
| `images.posters`                               | `poster` (eigene Sprache → englisch → ohne Text), Größe `w780`                |
| `images.backdrops`                             | `fanart` (bevorzugt ohne Text), Größe `w1280`                                 |
| `id`, `imdb_id`                                | `uniqueids`                                                                   |

Zuordnung bei Serien (`tmdb_series()`, Endpunkt `tv/<id>`, je Staffel ein Eintrag), falls abweichend:

| TMDB                                             | `movie.json` jeder Staffel                                                          |
|--------------------------------------------------|-------------------------------------------------------------------------------------|
| `name`, `id`                                     | `series` (`name`, `tmdb`)                                                           |
| `name` der Serie und der Staffel                 | `title` (siehe oben), `originalTitle` = `original_name`, wenn abweichend            |
| `seasons[].air_date`, sonst `first_air_date`     | `year`                                                                              |
| `seasons[].season_number`                        | `season`                                                                            |
| Median der Folgen der Staffel, sonst der Serie   | `runtime` (Länge einer Folge)                                                       |
| `content_ratings` des Landes aus `TMDB_LANGUAGE` | `fsk`                                                                               |
| `created_by`                                     | `creator` (kommagetrennt)                                                           |
| `aggregate_credits.cast` (nach `order`)          | `cast`, höchstens 50 (`TMDB_SERIES_CAST_LIMIT`); Rolle = die mit den meisten Folgen |
| `season/<n>` (je 20 pro Abruf)                   | `episodes` (`name` → `title`, `overview` → `plot`)                                  |
| `overview` der Staffel, sonst der Serie          | `plot`                                                                              |
| `poster_path` der Staffel, sonst der Serie       | `poster`; `fanart` ist das Hintergrundbild der Serie                                |
| `id`, `external_ids.imdb_id`, `tvdb_id`          | `uniqueids` (IDs der Serie)                                                         |

Konfiguration in `config.php`:

- `TMDB_API_KEY`: Vorläufig der Key, den Team Kodi im Scraper mitliefert (`tmdbapi.py`). Er ist für Kodi ausgestellt
  und sollte durch einen eigenen ersetzt werden (kostenlos, themoviedb.org → Einstellungen → API).
- `TMDB_LANGUAGE` (z. B. `de-DE`): Sprache für Titel, Handlung, Genres, Trailer und Poster. Das Land für die
  Altersfreigabe (`DE` → FSK) wird daraus abgeleitet.

Bewusst nicht übernommen aus den Kodi-Einstellungen: TMDB-Schlüsselwörter als Tags (englisch, zahlreich, unsortiert).

Ein Quellenhinweis auf TMDB steht im Import-Dialog (die Videothek ist rein privat).

`http_get()` (`fetch.php`) nutzt die curl-Extension, falls vorhanden, sonst `file_get_contents()` mit Stream-Context
als Fallback.

#### Barcode scannen

Im Import-Dialog öffnet "Barcode scannen" die Rückkamera des Smartphones; eine EAN lässt sich auch eintippen. TMDB
kennt keine Barcodes, deshalb sucht der Server die Nummer im Web und leitet aus den Angebotstiteln den Filmtitel ab
("Barcode 4012909509665 → The Transporter"). Findet er keinen Eintrag, tippt man den Titel. Die Kamera gibt der Browser 
nur über HTTPS frei, gescannt wird deshalb auf dem Produktivserver.

Die Websuche, eingestellt in `config.php`:

- `EAN_SEARCH_PROVIDER`:
  - `duckduckgo` (Voreinstellung): ohne Schlüssel, braucht aber **curl** (ohne curl, z. B. unter XAMPP, zeigt 
    DuckDuckGo nur eine Bot-Prüfung). Bei mehreren Scans kurz hintereinander blockiert DuckDuckGo vorübergehend; im 
    Abstand von etwa 20 s klappt es.
  - `serpapi`: Google-Ergebnisse über [SerpApi](https://serpapi.com), kostenloses Monatskontingent, Schlüssel nötig.
  - `brave`: [Brave Search API](https://brave.com/search/api/), monatliches Freiguthaben, Kreditkarte nötig.
- `EAN_SEARCH_API_KEY`: Schlüssel für `serpapi` bzw. `brave`.

### Drucken

"Drucken" in der Detailansicht druckt den offenen Eintrag auf einer hellen, tintensparenden Seite: Poster, Eckdaten,
Handlung, die ersten 6 Darsteller, Notizen, bei Staffeln die Folgen und den Trailer als QR-Code zum Abscannen mit dem
Handy. "Als PDF speichern" im Druckdialog ergibt eine PDF-Datei.

## Videos aufnehmen

Neue Filme einer Platte kommen mit `bin/convert.php` Film für Film in die Videothek. Das Skript arbeitet direkt
mit dem Server (Vorgabe `https://videothek.frank-rohlfing.de`, Zugang wie `bin/remote.php`: `--password` bzw.
Umgebungsvariable `VIDEOTHEK_PASSWORD`, dazu das API-Token per `--api-token` bzw. `VIDEOTHEK_API_TOKEN`, sonst
`API_TOKEN` aus `config.php`); `data/movies` wird nicht gebraucht. Ein falsches Token oder ein fehlendes
`api/media.php` auf dem Server meldet das Skript gleich zu Beginn.

1. Alle Filme bzw. Staffeln über die Import-Funktion der App importieren, Medium "USB" des Eigentümers der Platte.
   Beim USB-Medium unter "Dateien" den Dateinamen der Quelle relativ zum Quellordner eintragen (z. B.
   `Kick Ass (2010)/kickass.avi`; bei Staffeln eine Zeile je Folge, bei Mehrteilern alle Teile). Fehlt er,
   sucht das Skript über den Namen: Titel oder Originaltitel als ganze Wörter im Pfad, passendes Jahr; bei Staffeln
   Serie, Staffelnummer und Folgennummer wie `S01E05`.
2. `php bin/convert.php --owner=rohlfing --src=D:\Filme --password=… --dry-run` zeigt, welche Datei zu welchem
   Eintrag gehört. Was nicht eindeutig ist, steht am Ende unter "Ohne Quelle" bzw. "Videos ohne Eintrag"; dafür den
   Dateinamen in der App nachtragen.
3. Ohne `--dry-run` wandelt das Skript je Eintrag um (Mehrteiler zusammengefügt, H.264 + AAC/MP3, eine Tonspur),
   schreibt Titel, Handlung und das Poster als Cover mit in die MP4, legt sie unter dem Pfad nach der Regel oben in
   den USB-Ordner (`--to`, Vorgabe `USB_ROOT`), misst Bild und Ton und trägt Pfade, Bild und Ton über
   `PUT /api/media.php` auf dem Server ein. Die Quelle bleibt unverändert. Abbruch mit Strg+C; ein erneuter Start
   setzt fort (`convert-log.jsonl` im USB-Ordner).

`--owner` ist Pflicht: Nur USB-Medien dieses Eigentümers werden bearbeitet. 

Eine portable Version kann mit `bin\build_convert_portable.cmd` erstellt werden.

## Stand / Roadmap

- ✅ Projekt aus dem Familien-Kochbuch abgeleitet (gleiche Architektur: PHP/JSON-API ohne Datenbank, One-Page-Frontend)
- ✅ Film-Datenmodell (Titel, Jahr, Laufzeit, FSK, Genres, Regie, Medium, Handlung, Trailer, Darsteller, Notizen)
- ✅ JSON-API (CRUD + Poster-Upload mit automatischem Thumbnail im Plakatformat)
- ✅ Frontend inkl. Anlegen/Bearbeiten/Löschen, Suche, Genre- und Medium-Filter, Sortierung nach Jahr
- ✅ Druckansicht (`@media print`, Trailer als QR-Code, erste 6 Darsteller)
- ✅ Film-Import aus TMDB wie bei Kodi (deutsche Titel/Handlung, FSK, Poster, Hintergrundbild, Darstellerfotos, Trailer)
- ✅ Trailer als eingebetteter Player
- ✅ Mehrere Medien pro Film (z. B. DVD und USB)
- ✅ Mehrere Benutzer: Eigentümer je Medium, Ausleihe mit einem Klick (`locations.json`), eigener USB-Ordner je Gerät
- ✅ Wunschliste (`wishes.json`) mit Häkchen auf der Karte; Filter nach Eigentümer, Ausleihe und Wunschliste
- ✅ Bewertungen in vier Stufen von -1 bis 2 (`ratings.json`), Sortierung nach Bewertung
- ✅ Abgleich von `data/` und Benutzer ohne Terminal auf dem Server (`bin/remote.php`, `bin/account.php`)
- ✅ Serien: jede Staffel ein eigener Eintrag (Cover, Medium, Folgen), gruppiert wie Reihen;
- ✅ Import per Barcode-Scan mit dem Smartphone (Websuche über DuckDuckGo, wahlweise SerpApi oder Brave)
- ✅ Filmreihen als gemeinsame Kachel mit Cover-Collage, Teile aufklappbar
- ✅ Einmaliger Import der Kodi-Sammlung (289 Filme, Filmdateien nach USB_ROOT)

## Lizenz

Dieses Projekt steht unter der [GPL-3.0-Lizenz](LICENSE).

Das bedeutet: Du darfst diesen Code nutzen, ändern und verbreiten. Wenn du die Software (oder eine modifizierte
Version davon) jedoch weitergibst, musst du deinen Quellcode ebenfalls unter derselben Lizenz offenlegen.

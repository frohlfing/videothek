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
   htpasswd -bcB .htpasswd <benutzername> <passwort>
   ```
   Die `.htpasswd`-Datei liegt im Projekt-Root (außerhalb des Document Root, neben `config.php`). Danach 
   `public/.htaccess.example` nach `public/.htaccess` kopieren und den `AuthUserFile`-Pfad auf den tatsächlichen 
   absoluten Pfad zur `.htpasswd` auf diesem Server anpassen. Beide Dateien (`.htpasswd`, `public/.htaccess`) sind 
   gitignored und müssen auf jedem Rechner/Server separat eingerichtet werden.

   Jeder Benutzer der `.htpasswd` ist zugleich Benutzer der App (Eigentümer von Medien, "eingetragen von" bei
   Ausleihen). Weitere Benutzer legt `php bin/account.php --add` an, siehe "Benutzer und Abgleich mit dem Server".
   Für Änderungen braucht jeder User zusätzlich das API-Token.

6. Seite aufrufen – die Filmliste lädt sich selbst über `GET /api/movies.php`.

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

## Videos für den Browser umwandeln

`bin/convert.php` wandelt die Filme auf dem USB-Speicher in MP4 (H.264 + AAC/MP3) um, damit sie im Player-Fenster
der App laufen (siehe "Abspielen (Medium USB)"), und legt sie mit gleicher Ordnerstruktur in einem zweiten Ordner ab.
Details siehe Dokumentation in `bin/convert.php`.

`bin\build_convert_portable.cmd D:\convert` stellt eine portable Version in `D:\convert` zusammen (ca. 260 MB).
Nach Änderungen an `convert.php` muss das Paket neu erstellt werden.

Zum Aufteilen auf meherer Rechner könnte z.B. Rechner 1 mit `--only=0-m` und Rechner 2 mit `--only=n-z` auf einer
lokalen Kopie arbeiten. Danach die Ergebnisse ins Ziel von Rechner 1 kopieren und die Zeilen seines `convert-log.jsonl`
an das dortige anhängen (Befehle in `LIESMICH.txt`).

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
 │    ├── build_convert_portable.cmd  # convert.php samt PHP und ffmpeg als Paket für einen zweiten Rechner (<Zielordner>)
 │    ├── convert.php                 # Filme des USB-Speichers in browsertaugliches MP4 umwandeln (ffmpeg)
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
 │    │    ├── movies.php             # Filme und Staffeln lesen, anlegen, bearbeiten, löschen
 │    │    ├── play.php               # Play-Button: Wiedergabeliste (.m3u) mit den lokalen Dateipfaden
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
| GET          | `/api/play.php?slug=xyz[&episode=3][&root=D:\…]`     | Wiedergabeliste (`.m3u`) mit den eigenen Dateien (`<root>/<slug>/<Datei>`, ohne `root`: USB_ROOT) für den lokalen Player: Film, alle Folgen einer Staffel bzw. eine Folge          |
| GET          | `/api/users.php`                                     | Angemeldeter Benutzer (`me`), alle Benutzer mit Anzeigenamen, Vorgabe für den USB-Ordner                                                                                           |
| GET          | `/api/locations.php`                                 | Standorte aller Scheiben, die nicht beim Eigentümer sind (`data/locations.json`)                                                                                                   |
| PUT          | `/api/locations.php`                                 | Standort einer Scheibe setzen (Body: `{"slug", "owner", "type", "at"}`; `at` = Login oder freier Name, leer oder der Eigentümer = zurück)                                          |
| GET          | `/api/wishes.php`                                    | Wunschlisten aller Benutzer (`data/wishes.json`): je Slug Login und Zeitpunkt                                                                                                      |
| PUT          | `/api/wishes.php`                                    | Eigenen Wunsch setzen bzw. entfernen (Body: `{"slug", "wish": true\|false}`; Anmeldung nötig)                                                                                      |
| GET          | `/api/ratings.php`                                   | Bewertungen aller Benutzer (`data/ratings.json`): je Slug Login und -1, 0, 1 oder 2                                                                                                |
| PUT          | `/api/ratings.php`                                   | Eigene Bewertung setzen (Body: `{"slug", "rating": -1\|0\|1\|2\|null}`, null = entfernen; Anmeldung nötig)                                                                         |
| GET/PUT/POST | `/dev/sync.php`                                      | Nur für `bin/`, Token nötig: Dateiliste von `data/` mit MD5, Datei lesen/schreiben, Benutzer übertragen (`?account`)                                                               |
| POST         | `/api/actors.php`                                    | Fehlende Darstellerfotos laden, ~10 s pro Aufruf, bis `remaining` 0 ist (Body: `{"slug": "..."}`)                                                                                  |
| GET          | `/api/catalog.php`                                   | Alle Teile je Reihe und Staffeln je Serie laut TMDB (`collections`, `series`, siehe "Filmreihen und Serien")                                                                       |

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
    { "owner": "frank", "type": "USB", "files": ["movie.avi"] },
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
    { "owner": "frank", "type": "USB", "files": ["E01.mkv", "E02.mkv"] }
  ],
  "episodes": [
    { "number": 1, "title": "Der Einstieg", "plot": "Ein Chemielehrer mit Lungenkrebs im Endstadium ..." },
    { "number": 2, "title": "Die Katze ist im Sack ...", "plot": "..." }
  ],
  "poster": "poster.jpg",
  "...": "weiter wie beim Film (thumb, fanart, plot, trailer, cast, notes, uniqueids, slug, created)"
}
```

**TODO: Diese Informationen bis ende des Kapitels an geeigneter Stelle im Code hinterlegen und hier löschen (sofern es im Code 
für den Einsteiger nicht leicht ersichtlich ist, ansonsten verwerfen).**

Die Feldreihenfolge entspricht dem Bearbeiten-Formular, danach folgen die Verwaltungsfelder. `write_movie_json()`
schreibt sie immer in dieser Reihenfolge (`MOVIE_FIELD_ORDER` in `crud.php`); Felder, die nicht zum Schema gehören,
fallen dabei weg. Ältere `movie.json` ohne `type` gelten als Film. Wechselt ein Eintrag beim Bearbeiten die Art,
fallen die Felder der anderen Art weg (`TYPE_ONLY_FIELDS`).

Pflicht ist nur `title`. `year` (1880–2100) und `runtime` (Minuten) sind ganze Zahlen oder `null`, `genres` ist eine
Liste von Strings, `cast` eine Liste von Darstellern. Dort sind `profile` (TMDB-Porträtpfad, Quelle für den Download) 
und `thumb` (Dateiname unter `actors/`) nur bei importierten Filmen gesetzt. Die übrigen Felder sind Strings oder 
`null`.

`media` sind die Exemplare: je eines mit Eigentümer (`owner`, Login aus `users.json`) und Art (`type`: DVD, Blu-ray,
USB plus alle schon verwendeten Werte); je Eigentümer jede Art höchstens einmal, leer = nicht im Bestand. `files` gibt
es nur bei Dateien (`DIGITAL_MEDIA` in `crud.php`, also USB): die Filmdatei(en) relativ zum Ordner des Eintrags auf dem 
USB-Speicher des Eigentümers (`<USB-Ordner>/<slug>/`), bei mehrteiligen Filmen (`movie-cd1.avi`, `movie-cd2.avi`) 
mehrere in Abspielreihenfolge, immer mit `/` gespeichert. Absolute Pfade und `..` lehnt die API ab. Wo eine DVD bzw. 
Blu-ray gerade ist, steht nicht hier, sondern in `data/locations.json` (siehe "Mehrere Eigentümer, Medien und 
Ausleihe"). Ältere `movie.json` mit `medium` und `files` (relativ zu `USB_ROOT`) liest `read_movie_json()` als Medien 
des Eigentümers des ersten Benutzers aus `users.json` (`legacy_media()`); umgeschrieben werden sie beim nächsten 
Speichern. `fsk` ist die Altersfreigabe als freier Text, das Formular schlägt die üblichen Werte vor. `uniqueids`, 
`poster`, `thumb` und `fanart` setzen nur Import und Upload (`upload.php`, dort auch Entfernen); das Formular schickt 
sie nicht mit, sie bleiben beim Bearbeiten erhalten. Anders bei den Darstellern: Das Formular schickt `profile` 
(TMDB-Pfad) und `thumb` (Foto unter `actors/`) jeder Zeile mit, ein entferntes Foto fehlt also danach, und 
`update_movie()` löscht Fotos, auf die kein Darsteller mehr verweist (`remove_unused_actor_photos()`). Ein 
hochgeladenes Foto heißt `<Name>-<Zufall>.<ext>` (300 × 450), damit der Browser kein altes aus dem Cache zeigt. 
**Ein `PUT` gilt immer als vollständiger Stand** wie im Formular: Was fehlt (z. B. die Handlung einer Folge), ist 
danach leer.

Bei Staffeln ist `year` das Jahr der Staffel und `runtime` die Länge einer Folge; `uniqueids` sind die IDs der Serie. 
`episodes` hat `number`, `title` und `plot`, die Nummern sind eindeutig (doppelte lehnt die API mit Meldung ab). 
`files` eines Mediums USB sind die Folgendateien im Ordner der Staffel, üblich `E01.<ext>`; zugeordnet werden sie über 
die Folgennummer im Dateinamen (`E03`, `S01E03`, mehrteilig `E03-cd1`, `E03-cd2`), trägt keine Datei eine solche 
Nummer, über die Position (`season_episode_files()` in `crud.php`, gleiche Regel in `app.js`: `episodeFiles()`).

Alte Serien-Einträge (`"type": "series"`, alle Staffeln in `seasons[]`) blendet die Übersicht aus und nennt nur ihre
Zahl im Kopf; sie werden neu importiert (alten Ordner vorher löschen, sonst bekommt der neue Slug ein `-2`).

Doppelte Einträge werden über **Art + TMDB-ID** (TMDB nummeriert Filme und Serien getrennt), bei Staffeln über
**Serie + Staffelnummer** (Serie = TMDB-ID, wenn beide eine haben, sonst der Name), und über **Art + Titel + Jahr** 
erkannt (nicht über den Titel allein), damit Remakes wie "Ben Hur" (1959) und "Ben Hur" (2016) nebeneinander existieren 
können. Aus demselben Grund steckt das Jahr auch im Slug.

## Frontend / Arbeitsweise

**TODO: Dieses Kapitel komplett überarbeiten: Hier nur das wirklich erwähnesnwerte für dei Bedieung und Ablauf eschreiben,
was nicht inutuitiv oder sich bei der Bedienung der App von selbst eklärt. Insgesamt sollte es nur ein Überblick bleiben .
Technische Deteils sollten besser im Code an geeigneter Stelle hinterlegt hintelegt werden, denn da kuckt der Entwickler eher drauf.**

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

### Suchen, Filtern, Sortieren

Die Suche durchsucht Titel, Originaltitel, Jahr, Genres, Regie bzw. Idee, Darsteller (Name und Rolle), Handlung, 
Notizen, Medium, Eigentümer, Reihe bzw. Serie und bei Staffeln die Folgentitel. Gefiltert wird nach Art (Filme und 
Serien, nur Filme, nur Serien; dazu "Meine Favoriten" (alles, was man mit dem Herz
bewertet hat), "Meine Wunschliste" und nach einer Linie die Wunschlisten der anderen), nach Genre (alle Einträge, die das gewählte Genre enthalten), nach Medium (DVD, Blu-ray, ...; ein 
Eintrag mit mehreren Medien erscheint unter jedem, dazu "Blu-ray und DVD" für alles auf Scheibe) und nach Person. Die Personen-Auswahl 
bündelt zwei Dinge, damit es bei einer Auswahlliste bleibt:

- **Eigentümer**, direkt unter "Gesamtbestand": zuerst "Mein Bestand" (der Eigentümer des angemeldeten Benutzers),
  dann die anderen ("Lillis Bestand"): nur Einträge mit Medien dieses Eigentümers, auf der Karte nur seine Medien. Sind alle seine
  Medien eines Eintrags verliehen, ist die Karte blass wie "nicht im Bestand", das Schlagwort heißt dann "DVD bei
  Lilli". So ist auf einen Blick zu sehen, was gerade in seinem Regal steht.
- **Ausgeliehen** ("Lilli hat von Frank"): Was Lilli gerade von Frank hat. Hat sie Medien von mehreren Eigentümern,
  gibt es zusätzlich "Lilli hat" für alles.
- **(nicht im Bestand)**, nach einer Linie: Einträge ohne Medium (siehe unten).

Angeboten wird nur, was es gerade gibt; ohne Ausleihen und Einträge ohne Medium erscheint die Auswahl erst bei
mehreren Eigentümern. Medium und 
Person gelten zusammen: "Blu-ray" + "Lilies Bestand" zeigt Lilies Blu-rays. Die Filter lassen sich kombinieren und 
bieten nur Werte an, die im Bestand tatsächlich vorkommen. Sortiert wird alphabetisch, nach Erscheinungsjahr (Einträge 
ohne Jahr am Ende), nach Hinzufügedatum oder nach Bewertung (Durchschnitt, bei Gleichstand mehr Stimmen zuerst, ohne 
Bewertung am Ende). Bewertete Karten zeigen immer das Symbol der gerundeten Stufe und den Durchschnitt, z. B. "+1,3". 
Der Pfeil nach oben heißt: Neuestes bzw. Bestes zuerst. Alphabetisch zählt der Titel ohne führenden Artikel ("The Big 
Lebowski" steht bei B, wie in Kodi); welche Artikel, legt `SORT_IGNORE_ARTICLES` oben in `app.js` fest (der, die, das, 
the, ein, eine, a, an; leere Liste = aus). Eine Reihe bzw. Serie mit mehreren Teilen steht alphabetisch unter ihrem 
Namen, auch wenn die Teile anders heißen. Suchbegriff, Filter und Sortierung werden in `localStorage` unter 
`videothek_view_state` gemerkt und überstehen so einen Reload.

Staffeln zeigen auf der Karte Jahr, Folgenzahl und Folgenlänge ("2008 · 7 Folgen · 48 Min./Folge · FSK 16").

### Filmreihen und Serien

Filme derselben Reihe (`collection`, z. B. die Matrix-Filme) und Staffeln derselben Serie (`series`) erscheinen in
der Übersicht gemeinsam auf einer Kachel mit dem Etikett "Reihe" bzw. "Serie": die Cover der Teile (höchstens vier)
als aufgefächerter Stapel, darunter Name, Zahl der Filme bzw. Staffeln und Zeitraum. Gleiche Cover (z. B. Staffeln
mit dem Poster der Serie) erscheinen nur einmal, erkannt am `thumbHash` aus `movies.php`; bleibt nur eines, zeigt die
Kachel es wie ein normales Cover. Die Collage setzt der Browser aus den vorhandenen Thumbnails zusammen (CSS), es
gibt dafür keine eigene Bilddatei. Die Kachel steht an der Stelle ihres ersten Teils in der gewählten Sortierung. Ein
Klick fächert die Teile unter der Zeile der Kachel auf (Filme nach "Teil", ohne Teil nach Jahr; Staffeln nach Nummer,
Specials zuletzt, dort ohne den Seriennamen im Titel); ein weiterer Klick, "×" oder Escape klappt sie zu.

Auch bei Suche und Filtern (Art, Genre, Medium) steht ein Treffer als Kachel seiner Reihe bzw. Serie da, an der
Stelle des ersten Treffers. Die Kachel zeigt immer die ganze Gruppe; beim Auffächern sind die Treffer hervorgehoben
und die übrigen Teile abgeschwächt ("1 Treffer"). Eine Gruppe mit nur einem Eintrag bleibt ein einzelner Eintrag,
außer eine einzelne Staffel einer Serie, von der es laut Katalog weitere gibt (Breaking Bad mit nur Staffel 2): Sie
erscheint als Kachel "1 von 5 Staffeln", aufgeklappt mit den fehlenden Staffeln als Platzhalter.

**Nicht im Bestand** ist ein Eintrag ohne Medium (von Hand angelegt; der Import legt nur an, was man
hat). Solche Einträge erscheinen überall blass mit gestricheltem Rahmen und dem Schlagwort "nicht im Bestand", bleiben 
aber anklickbar. Die Personen-Auswahl hat dafür den Eintrag "(nicht im Bestand)". Die Reihe zählt mit ("2 von 3 im 
Bestand"), beim Aufklappen und in der Detailansicht, wo alle Teile als Zeile kleiner Plakate stehen (ein Klick öffnet 
den Teil; im Ausdruck fehlt der Abschnitt). TMDB wird dafür nicht gefragt, nur beim Import.

**Welche Teile bzw. Staffeln es gibt**, steht einmal je Reihe bzw. Serie in `data/collections.json` und
`data/series.json` (`api/lib/catalog.php`), nicht in den einzelnen Einträgen: je TMDB-ID Name, Datum der Abfrage
(`updated`) und die Teile (`tmdb`, `title`, `year`, `date`) bzw. Staffeln (`season`, `title`, `year`, `date`,
`episodes`, ohne Specials). Der Import aktualisiert den Eintrag nebenbei (Serie: aus dem ohnehin geladenen Abruf;
Film einer Reihe: ein zusätzlicher Abruf `collection/{id}`). Was davon im Bestand ist, rechnet das Frontend aus 
(`groupCatalog()`: Filme über `uniqueids.tmdb`, Staffeln über `series.tmdb` + `season`), damit die Dateien bei 
Änderungen am Bestand nicht mitgepflegt werden müssen. Angezeigt wird das bewusst neutral: Kachel "2 von 7 Filmen", 
in der Detailansicht über den Teilen "Filmteile (2 von 7)" bzw. "Staffeln (2 von 11)", vollständig "Staffeln (11)", mit 
Stand der Abfrage als Tooltip und die übrigen Teile als dezente leere Platzhalter an ihrer Stelle (nach Jahr bzw. 
Staffelnummer); ebenso beim Aufklappen der Kachel in der Übersicht ("fehlt", nicht anklickbar). Die Collage der Kachel
zeigt nur die vorhandenen Cover. Teile ohne oder mit künftigem Datum heißen "angekündigt" und zählen nicht mit. Gruppen ohne TMDB-ID 
(von Hand gebildet) bekommen keinen Hinweis.

`collection` ist `{"name": "Matrix", "tmdb": "2344", "part": 1}` oder `null`, `series` ebenso, aber ohne `part` (die 
Nummer einer Staffel steht in `season`). Den Zusatz "Filmreihe", den TMDB fast immer anhängt ("Matrix Filmreihe"), 
lässt der Import weg (`tmdb_collection()`). `part` ist die Nummer des Films in der Reihe, im Formular das Feld "Teil" 
neben "Reihe" (1–999, leer erlaubt). TMDB kennt keine Teilnummer, sie wird von Hand eingetragen; nötig ist sie nur, 
wenn das Jahr nicht reicht, z. B. bei Teilen aus demselben Jahr (Millennium: drei Filme 2009). Filme mit Teil stehen 
vorn, nach Nummer, die übrigen danach nach Jahr; die Detailansicht zeigt "Reihe: Millennium, Teil 2". Der Import 
übernimmt die Reihe aus TMDB (`belongs_to_collection`) bzw. die Serie; hat schon ein Teil derselben TMDB-Reihe bzw. 
-Serie einen Eintrag, gilt dessen Name. Im Formular sind "Reihe" und "Serie" Textfelder mit den vorhandenen Namen als 
Vorschlag; ein vorhandener Name übernimmt dessen TMDB-ID; beim Umbenennen behält der Eintrag seine eigene, ein ganz 
neuer Eintrag mit neuem Namen bekommt keine (`formGroup()`). Zusammengefasst wird über den Namen (ohne 
Groß-/Kleinschreibung), eine Gruppe lässt sich also auch von Hand bilden, z. B. drei DVDs, die TMDB als eigene Serien
führt, unter "Shaun das Schaf". Wird der Name bei einem Teil geändert, fragt das Formular nach dem Speichern, ob die
übrigen Teile der bisherigen Gruppe mit umbenannt werden sollen (`renameGroupSiblings()`; jeder Film behält TMDB-ID und
Teilnummer). So bleibt es bei einem Namen je TMDB-ID, und der Import einer Fortsetzung übernimmt diesen Namen. Die
Ordner bleiben flach unter `data/movies/`.

### Mehrere Eigentümer, Medien und Ausleihe

Jedes Exemplar ist ein Eintrag in `media` mit Eigentümer und Art. Haben zwei Freunde denselben Film, bleibt es ein
Eintrag mit zwei Medien (ein Ordner, ein Poster). In der Detailansicht stehen die Medien unter den Eckdaten:

```
Medien   Lilies DVD     hat Thomas seit 48 Tagen  ↶
         Rohlfings DVD  verleihen
         Rohlfings Datei (USB)
```

**Ausleihen**: Liegt eine DVD bzw. Blu-ray beim Eigentümer, steht daneben der Link "verleihen" (eigene Scheibe)
bzw. "ausleihen" (die eines anderen). "ausleihen" fragt nur nach Bestätigung ("Rohlfings DVD ausleihen?",
`askBorrowSelf()`) und trägt den Haushalt des angemeldeten Benutzers ein. "verleihen" fragt "An wen verliehen?"
(`askBorrower()`): je Haushalt ein Knopf ("Feldmann" für Barbara und Arne, ohne den, dem die Scheibe gehört), ebenso
für jeden Namen, bei dem gerade etwas ausgeliehen ist, dazu ein Feld für eine andere Person ohne Zugang (ihr Name
steht dann als `at` in `locations.json`, bis 60 Zeichen; ein eingetippter Name eines Benutzers oder Haushalts wird zur
Kennung des Haushalts). Verliehen wird also an einen Haushalt, nicht an eine Person: `at` ist die Kennung des
Eigentümers (`feldmann`); ältere Einträge mit einem Login (`arne`) zählen für seinen Haushalt. Gespeichert wird sofort (`PUT
api/locations.php`, mit API-Token wie jede Änderung). Danach steht dort "hat Thomas seit 48 Tagen" (bzw. "seit
heute", "seit gestern"; Datum und wer es eingetragen hat als Tooltip) und ein Undo-Symbol (↶, Tooltip
"Zurückgegeben"), das das Medium wieder beim Eigentümer einträgt. Zeitpunkt und welcher User es eingetragen hat
(angemeldeter Benutzer), setzt der Server. Dateien (USB) werden nicht verliehen. Im Ausdruck fehlen Links und Symbole.

Die Standorte stehen in **`data/locations.json`**, nicht in `movie.json`: So überschreibt ein Hochladen geänderter
Filmordner keine Ausleihen, und ein Klick schreibt nur diese kleine Datei (unter Sperre wie die Kataloge):

```json
{ "matrix-1999": { "lilie/DVD": { "at": "rohlfing", "since": "2026-10-18T20:15:00+02:00", "by": "frank" } } }
```

Ohne Eintrag liegt das Medium beim Eigentümer. Wird ein Eintrag umbenannt, zieht sein Standort mit; wird ein Medium
entfernt oder bekommt es einen anderen Eigentümer bzw. eine andere Art, fällt sein Standort weg; mit dem Eintrag
verschwindet auch sein Standort (`move_locations()` in `lib/locations.php`).

Im **Bearbeiten-Formular** ist jedes Medium eine Zeile mit Eigentümer, Art und "×", dazu "+ Medium" (neu: eigene DVD).
**Abgeben** heißt dort den Eigentümer ändern. Bei USB erscheint darunter das Feld für die Dateien. Der Import trägt
die DVD des angemeldeten Benutzers ein (`import_media()` in `import.php`).

**Wem welche Datei gehört**: Jeder Usre hat seinen eigenen USB-Speicher, darauf je Eintrag ein Ordner mit dem Slug als
Namen (siehe "Abspielen"). Gespeichert sind deshalb nur die Dateinamen im Ordner, nicht der ganze Pfad. Ändert sich
der Slug (Titel oder Jahr), erinnert das Formular daran, den Ordner auf dem USB-Speicher umzubenennen.

### Gemeinsame Sammlung

Teilen sich mehrere eine Sammlung (ein Haushalt, ein Standort, ein USB-Speicher), steht in `data/users.json` bei
jedem derselbe Eigentümer:

```json
{ "frank": { "name": "Frank", "owner": "Rohlfing" },
  "tinka": { "name": "Tinka", "owner": "Rohlfing" },
  "lilie": { "name": "Lilie" } }
```

An den Medien steht dann die Kennung `rohlfing` (aus dem Namen, `owner_key()` in `lib/users.php`), angezeigt als
"Rohlfings DVD", "Rohlfings Bestand" bzw. "Lilie hat von Rohlfing". Ohne `owner` ist der Benutzer selbst Eigentümer
(Kennung = Login). Für Frank und Tinka gleichermaßen gilt, was Rohlfing gehört, als eigen: Abspielen der Dateien,
"verleihen" statt "ausleihen", Import als "Rohlfings DVD"; im Ausleih-Dialog erscheinen sie bei Rohlfings Scheiben
nicht, und hat einer von beiden eine davon, liegt sie zu Hause. Verliehen wird an den Haushalt ("hat Feldmann",
"Feldmann hat von Rohlfing"); Wunschliste und Bewertungen bleiben bei der Person. Im Formular stehen die Eigentümer zur Wahl ("Rohlfing", "Lilie"), nicht die Personen.

Eingerichtet wird das lokal:

1. `php bin/account.php --change frank --owner=Rohlfing` bzw. `--add tinka --name=Tinka --owner=Rohlfing`.
2. Hatte jemand schon eigene Medien, gehören sie noch seinem Login (`frank`); das Skript meldet sie. Für den
   Bestand ist das einmalig geschehen (`frank` -> `rohlfing` in jeder `movie.json` und in `locations.json`), einzelne
   Medien stellt man im Formular um.
3. Mit dem Server: vorher `--download-data`, danach `--upload-data` und `--upload-account tinka`. Dazwischen sollte
   auf dem Server niemand ausleihen, sonst ist die `locations.json` dort neuer und wird nicht überschrieben.

### Wunschliste

In der Detailansicht steht unter den Eckdaten der Link "möchte ich sehen". Ein Klick setzt den Eintrag auf die eigene
Wunschliste: Dort steht dann ein grünes "✓ möchte ich sehen" mit Undo-Symbol (↶, "Doch nicht"), auf der Karte in
der Übersicht ein grünes Häkchen (bei Reihen und Serien, sobald ein Teil gewünscht ist). Die Detailansicht zeigt nur
die eigene Wunschliste. Gefiltert wird über die Art-Auswahl: "Meine Wunschliste", nach einer Linie die der
anderen ("Lillis Wunschliste"). Zusammen mit "Mein Bestand" in der Personen-Auswahl zeigt das, welche eigenen
DVDs man zum Besuch bei Lilli einpacken kann. Auch Einträge, die nicht im Bestand sind, lassen sich
wünschen; die Zeile gibt es nur mit Anmeldung.

Gespeichert wird sofort (`PUT api/wishes.php`, mit API-Token wie jede Änderung und nur mit Anmeldung, weil der Wunsch
dem angemeldeten Benutzer gehört) in **`data/wishes.json`**, getrennt von `movie.json` und `users.json` aus demselben
Grund wie bei `locations.json`: Wünsche entstehen auf dem Server, ein Hochladen lokaler Änderungen soll sie nicht
überschreiben.

```json
{ "matrix-1999": { "lilie": "2026-10-19T20:15:00+02:00" } }
```

Wird ein Eintrag umbenannt, ziehen seine Wünsche mit; mit dem Eintrag verschwinden sie (`move_wishes()` in
`lib/wishes.php`).

### Bewertungen

Bewertet wird in vier Stufen:

| Wert | Bedeutung                   | Symbol                         |
|------|-----------------------------|--------------------------------|
| -1   | schlecht / nicht sehenswert | Daumen runter (gelb)           |
| 0    | neutral / Zeitvertreib      | Daumen hoch und runter (gelb)  |
| 1    | gut / sehenswert            | Daumen hoch (gelb)             |
| 2    | sehr gut / muss man sehen   | Herz (rot)                     |

Die Symbole stammen aus Googles Material Symbols (Rounded, gefüllt; Apache-Lizenz 2.0) und stehen als SVG in
`RATING_STEPS` in `app.js`, die Farben in `app.css` (`--rate-hand`, `--rate-heart`). Unter "möchte ich sehen" stehen in 
der Detailansicht die vier Stufen mit der Zahl der Stimmen, im Tooltip die Bedeutung der Stufe. Ein Klick bewertet, die 
eigene Wahl ist hervorgehoben, ein zweiter Klick auf dieselbe Stufe nimmt sie zurück. Die erste Bewertung nimmt den 
Eintrag von der eigenen Wunschliste (gesehen). Man kann ihn danach wieder wünschen; eine geänderte Bewertung lässt den 
Wunsch dann stehen. Ohne Anmeldung erscheinen nur die Zahlen. Was man selbst mit dem Herz bewertet hat, zeigt die 
Art-Auswahl unter "Meine Favoriten".

Gespeichert wird sofort (`PUT api/ratings.php`, mit API-Token und Anmeldung wie bei der Wunschliste) in
**`data/ratings.json`**, aus denselben Gründen getrennt von `movie.json`:

```json
{ "matrix-1999": { "frank": 2, "lilie": -1 } }
```

Umbenennen und Löschen eines Eintrags ziehen die Bewertungen mit (`move_ratings()` in `lib/ratings.php`). Die
Sortierung "Bewertung ↑/↓" nutzt den Durchschnitt, den die Karten auch sonst immer zeigen.

### Detailansicht

Poster, Titel und Eckdaten (mit den Medien, siehe oben), darüber abgedunkelt das Hintergrundbild (`fanart`).
Darunter Handlung, Trailer, die **vollständige** Darstellerliste mit Fotos und Rollen (scrollt in einem eigenen
Bereich, Fotos per `loading="lazy"`) und die Notizen.

Der Trailer läuft als eingebetteter Player (`youtube-nocookie.com`); YouTube wird dadurch schon beim Öffnen der
Detailansicht geladen. Im Ausdruck erscheint stattdessen ein QR-Code mit der Trailer-URL (siehe "Druckfunktion").

Bei Staffeln folgen auf die Handlung die Folgen. Zunächst steht nur der Titel da; ein Klick auf den Titel (Pfeil
▸/▾) klappt die Handlung der Folge auf und wieder zu. Gehört der Eintrag zu einer Reihe bzw. Serie,
stehen darunter alle Teile als Zeile kleiner Plakate.

Im Bearbeiten-Formular stehen Poster und Hintergrundbild nebeneinander, jeweils als Vorschau: Ein Klick aufs Bild
(bzw. auf den Platzhalter "Poster wählen") wählt ein neues, das "×" unten rechts entfernt es. Beides wird erst beim
Speichern ausgeführt; "Abbrechen" lässt alles, wie es war.
Oben wird die Art gewählt (Film/Staffel einer Serie); die Felder der anderen Art werden ausgeblendet. Eine Staffel
hat statt Regie und Reihe die Felder Serie, Staffel (Nummer) und Idee; direkt unter der Handlung stehen die Folgen
in **einem Textfeld** (leicht zu bearbeiten und zu kopieren):

```
1. Kaltblütig
Der englische Botschafter wurde in Urbino, Italien, ermordet. …

2. Einfach Henry
Das geschlossene Bündnis mit Frankreich …
```

Eine Folge beginnt mit "Nummer. Titel" (Titel optional), aber nur am Anfang oder nach einer Leerzeile; die Zeilen
darunter sind die Handlung. Unter dem Feld steht laufend, wie viele Folgen erkannt wurden; Text vor der ersten Folge
verhindert das Speichern. Gespeichert wird weiter strukturiert in `episodes` (`episodesText()` /
`parseEpisodesText()` in `app.js`). Damit das Format eindeutig bleibt, enthält die Handlung einer Folge nie
Leerzeilen: `normalize_episodes()` entfernt sie bei jedem Speichern, auch beim Import (`episode_plot()` in
`crud.php`). Darsteller sind Zeilen mit Foto und einem Feld
"Name: Rolle" (Name bis zum ersten Doppelpunkt, ohne Rolle nur der Name). Ein Klick aufs Foto wählt ein neues, das
kleine "×" unten rechts am Foto entfernt es; "×" am Zeilenende entfernt den Darsteller, dazu "+ Darsteller". Neue
Bilder werden erst nach dem Speichern der Textfelder hochgeladen; schlägt eines fehl, bleibt der Text gespeichert.

### Abspielen (Medium USB)

Jeder User hat seinen eigenen USB-Speicher. Darauf liegt unter dem **USB-Ordner** (z. B. `D:\videothek`) ein 
Unterordner pro Film mit dem Slug als Namen und darin nur die Filmdatei `movie.<ext>` 
(`am-sonntag-bist-du-tot-2014\movie.mkv`; mehrteilig `act-of-valor-2012\movie-cd1.flv`, `movie-cd2.flv`, …), keine 
Poster oder NFOs. Im Formular erscheint bei einem Medium USB das Pflichtfeld für die Dateien, vorbelegt mit `movie.` 
(Endung ergänzen). Hat der angemeldete Benutzer den Film als Datei, zeigt die Detailansicht den Button 
**"▶ Abspielen"**; Dateien anderer lassen sich nicht abspielen (sie liegen ja auf deren Speicher).

Staffeln liegen nach demselben Muster in einem Ordner pro Staffel, eine Datei pro Folge:
`breaking-bad-staffel-1-2008\E01.mkv`, `E02.mkv`, ... Das Formular schlägt eine Zeile pro Folge vor (`E01.` usw.);
wird die Endung in einer Zeile ergänzt, übernehmen die übrigen sie beim Verlassen des Feldes. In der Detailansicht
gibt es dann **"▶ Alle Folgen abspielen"** und neben jeder Folge, der eine Datei zugeordnet ist, ein ▶ für genau
diese Folge (`play.php?…&episode=3`).

**Im Browser** (`playMedia()` in `app.js`): Sind alle abzuspielenden Dateien MP4 (`.mp4`, `.m4v`) und kann der
Browser lokale Ordner lesen (Chrome, Edge am Rechner: File System Access API), läuft der Film in einem Player-Fenster
in der Seite; mehrteilige Filme bzw. alle Folgen einer Staffel nacheinander. Beim ersten Mal erscheint der Dialog
"Videothek-Ordner" mit dem Hinweis, dass der Browser den Ordner aus Sicherheitsgründen einmal selbst ausgewählt haben
möchte, danach seine Ordnerauswahl (`D:\videothek`). Das Gerät merkt sich die Freigabe (IndexedDB `videothek`), nach
einem Neustart des Browsers fragt er einmal kurz nach. Ist der Ordner nicht erreichbar (USB-Speicher nicht
angeschlossen, Ordner umbenannt) oder fehlt die Datei darin, kommt der Dialog wieder. Kann der Browser eine Datei nicht abspielen, bietet das Fenster den installierten Player an. Alles andere (`.avi`, `.mkv`,
Firefox, Safari, Handy) geht wie bisher über die Wiedergabeliste (MP4-Dateien für den ganzen USB-Speicher erzeugt
`bin/convert.php`, siehe "Videos für den Browser umwandeln"):

Der USB-Ordner gilt auf dem Gerät, das abspielt. Beim Klick auf "▶ Abspielen" (bzw. ▶ an einer Folge) fragt die
App jedes Mal nach dem Ordner, vorbelegt mit dem zuletzt bestätigten Ordner (gespeichert im Browser unter
`videothek_usb_root`) bzw. mit `USB_ROOT` aus `config.php`; "Abbrechen" spielt nichts ab. Der Play-Link schickt
den Ordner als `root` mit. Die Abfrage ist ein eigener Dialog (`showDialog()` in `app.js`, ebenso "An wen
verliehen?"), nicht `prompt()`: Darüber schreibt der Browser sonst "<Adresse> meldet".

Der Button öffnet keinen `file:///`-Link, denn den blockieren Chrome, Edge und Firefox auf einer http(s)-Seite
kommentarlos. Stattdessen liefert `api/play.php` eine Wiedergabeliste (`<slug>.m3u`) mit dem vollständigen Pfad
(`<USB-Ordner>/<slug>/<Datei>`), die das Betriebssystem mit dem installierten Player öffnet (VLC, Windows Media
Player, …); mehrteilige Filme laufen nacheinander. Dafür wird ein installierter Player vorausgesetzt. Tipp: In Chrome/Edge beim ersten Mal im Download-Menü "Dateien dieses Typs immer öffnen" wählen,
dann startet der Film mit einem Klick.

### Token-Authentifizierung

Für Änderungen (Film anlegen/bearbeiten/löschen, Bild-Upload, Ausleihe) fragt das Frontend beim ersten
Schreibversuch per `prompt()` nach dem API-Token und legt es danach in `localStorage` unter dem Schlüssel
`videothek_api_token` ab. Jeder weitere Schreibversuch liest das gespeicherte Token wieder aus und fragt nicht
erneut nach.

Es wird nur dann wieder nachgefragt, wenn 
a) der Server das gespeicherte Token ablehnt (401) – dann wird es automatisch aus `localStorage` gelöscht, oder 
b) du es manuell löschst, z. B. in den Browser-DevTools mit `localStorage.removeItem('videothek_api_token')` oder 
   über "Website-Daten löschen".

Lesezugriffe (Filmliste, Bilder) brauchen kein App-Token — Schutz vor öffentlichem Zugriff übernimmt die 
HTTP Basic Auth vor der gesamten `public/`-Domain (siehe Setup, Schritt 5). Die Anmeldung bestimmt nur, wer man
ist (Eigentümer beim Import, "eingetragen von" bei Ausleihen); unter CGI/FastCGI (Hetzner) kommt der Benutzername aus
`REMOTE_USER` statt `PHP_AUTH_USER` (`current_user()` in `lib/users.php`).

### Import-Funktion

Der Import holt die Daten von Filmen und Serien aus **TMDB (The Movie Database)**, wie der Kodi-Scraper "The Movie
Database Python" (xbmc-Repo: `addons/metadata.themoviedb.org.python`). Er ersetzt den früheren HTML-Import über
schema.org-Daten, der nur englische Titel und keine FSK lieferte.

Ablauf im Import-Dialog ("+ Neu" in der Übersicht; "Manuell" dort legt einen Eintrag ohne TMDB von Hand an):

1. **Suchen** (`GET /api/search.php`): Titel, optional mit Jahr, oder direkt eine IMDb-ID (`tt0089218`, wird wie bei
   Kodi über `/find` aufgelöst). Gesucht wird in den Filmen und Serien von TMDB zugleich; jeder Treffer ist als
   "Film" bzw. "Serie" gekennzeichnet. Exakte Titeltreffer kommen nach oben, darin nach Popularität sortiert.
   Bereits vorhandene Filme sind markiert und öffnen sich beim Anklicken; bei Serien steht da, wie viele Staffeln
   schon vorhanden sind. Statt des Titels geht auch der **Barcode** der DVD (siehe unten).
2. **Treffer anklicken** (`POST /api/import.php`, Token nötig): lädt die Details in `TMDB_LANGUAGE` und legt
   den Eintrag über dieselbe Validierungs-/Anlege-Funktion wie `POST /api/movies.php` an (`create_movie()`). Da TMDB
   das Format des eigenen Exemplars nicht kennt, wählt man es im Dialog: **DVD**, **Blu-ray** oder **USB** wird als
   Medium des Eigentümers des angemeldeten Benutzers eingetragen; **Wunschliste** (Vorgabe, nur mit Anmeldung) legt
   den Eintrag ohne Medium an und setzt ihn auf die eigene Wunschliste. Die letzte Wahl merkt sich der Browser
   (`localStorage`). Ohne Angabe nimmt die API `IMPORT_DEFAULT_MEDIUM` (DVD). Danach werden Poster und Hintergrundbild 
   heruntergeladen (`store_movie_image()`, dieselbe Funktion wie beim Upload). Schlägt nur ein Bild fehl, bleibt der 
   Eintrag gespeichert, die Antwort enthält dann `"import_warning"`.
   Bei einer **Serie** fragt der Treffer zuerst ab, welche Staffel(n) importiert werden (z. B. `2` oder `1-3, 5`, `0` 
   = Specials). Nur diese legt der Import an, je Staffel ein Eintrag mit dem gewählten Medium; von TMDB werden auch nur
   ihre Folgen abgerufen. Staffeln, die es schon gibt (gleiche TMDB-ID der Serie und Nummer), bleiben unverändert;
   später gekaufte Staffeln ergänzt man genauso. Titel: "Serie – Staffel 1" bzw. mit dem Namen der
   Staffel; hat die Serie nur eine reguläre Staffel (etwa eine DVD, die TMDB als eigene Serie führt), heißt die
   Staffel wie die Serie (`season_entry_title()`).
3. **Darstellerfotos** (`POST /api/actors.php`): Das Frontend ruft den Endpunkt direkt danach so oft auf, bis keine
   Fotos mehr fehlen, und zeigt den Fortschritt an. Jeder Aufruf arbeitet höchstens ~10 s, damit auch Filme mit 50+
   Darstellern nicht ins PHP-Zeitlimit laufen (vor allem unter XAMPP, wo Apache ohne curl lädt, siehe `fetch.php`).
   Die Staffeln einer Serie teilen sich die Darsteller; ein Foto, das eine andere Staffel schon hat, wird kopiert.
   Abgelegt wird wie bei Kodi als `Vorname_Nachname.jpg` (TMDB-Größe `w185`, ~10 KB), aber in
   `data/movies/<slug>/actors/` statt in einem versteckten `.actors`. Darsteller ohne TMDB-Foto bekommen in der
   Detailansicht ihre Initialen.

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

Fehlt die deutsche Übersetzung einer Folge, liefert TMDB den Platzhalter "Folge 3" und keine Handlung; dann werden
englischer Titel und englische Handlung übernommen (ein zusätzlicher Abruf nur für die betroffenen Staffeln).

Konfiguration in `config.php`:

- `TMDB_API_KEY`: Vorläufig der Key, den Team Kodi im Scraper mitliefert (`tmdbapi.py`). Er ist für Kodi ausgestellt
  und sollte durch einen eigenen ersetzt werden (kostenlos, themoviedb.org → Einstellungen → API).
- `TMDB_LANGUAGE` (z. B. `de-DE`): Sprache für Titel, Handlung, Genres, Trailer und Poster. Das Land für die
  Altersfreigabe (`DE` → FSK) wird daraus abgeleitet.

Bewusst nicht übernommen aus den Kodi-Einstellungen: TMDB-Schlüsselwörter als Tags (englisch, zahlreich, unsortiert).

Ein Quellenhinweis auf TMDB steht im Import-Dialog (die Videothek ist rein privat).

`http_get()` (`fetch.php`) nutzt die curl-Extension, falls vorhanden, sonst `file_get_contents()` mit Stream-Context
als Fallback.

### Barcode scannen

Im Import-Dialog öffnet "Barcode scannen" die Rückkamera des Smartphones. Der erkannte Barcode (EAN-13, EAN-8,
UPC-A) landet im Suchfeld und läuft durch die normale Suche. `search.php` erkennt eine EAN an Länge und Prüfziffer
und ersetzt sie durch einen Suchbegriff (Antwort zusätzlich mit `ean` und `query`). Das Frontend schreibt diesen
Begriff ins Suchfeld ("Barcode 4012909509665 → The Transporter") und zeigt die Trefferliste wie bei einer
getippten Suche. Eine EAN lässt sich auch von Hand eintippen.

So entsteht der Suchbegriff (`api/lib/ean.php`): TMDB kennt keine Barcodes, freie Produktdatenbanken (opengtindb,
UPCitemdb, Open Products Facts) kannten im Test kaum eine DVD. Eine **Websuche nach der reinen Nummer** findet
dagegen fast immer mehrere Shop- und eBay-Angebote. Gewählt wird die längste Wortfolge, die in mindestens 40 % der
Ergebnistitel vorkommt (ohne Wörter wie "DVD", "kaufen", "FSK", ohne die EAN selbst), in der häufigsten
Schreibweise. Findet TMDB damit nichts ("The Transporter TV Movie Edition"), wird der Begriff Wort für Wort von
hinten gekürzt ("The Transporter"). Ließ sich kein Begriff ableiten, bittet der Dialog darum, den Titel zu tippen.
Die Ergebnistitel werden je EAN in `data/cache/ean/<ean>.json` gespeichert; ein erneuter Scan fragt die
Suchmaschine dann nicht mehr.

Die Websuche, eingestellt in `config.php`:

- `EAN_SEARCH_PROVIDER`:
  - `duckduckgo` (Voreinstellung, auch wenn die Konstante fehlt): liest die Trefferliste der HTML-Version
    `html.duckduckgo.com/html/` aus, ohne Schlüssel. Braucht **curl**; ohne curl (`file_get_contents()`, z. B.
    unter dem XAMPP-Apache) zeigte DuckDuckGo im Test immer eine Bot-Prüfung. Auch mit curl kommt die Prüfung,
    wenn mehrere Abfragen wenige Sekunden aufeinander folgen; im Abstand von 20 s klappten alle Test-EANs. Die
    Prüfung wird nicht umgangen: `search.php` meldet "vorübergehend blockiert", der Titel lässt sich dann tippen.
  - `serpapi`: Google-Ergebnisse über [SerpApi](https://serpapi.com), kostenloses Monatskontingent, Schlüssel nötig.
  - `brave`: [Brave Search API](https://brave.com/search/api/), monatliches Freiguthaben, Kreditkarte nötig.

  google.com selbst liefert Programmen ohne JavaScript keine Treffer, nur eine "JavaScript aktivieren"-Seite;
  Googles Custom Search API nimmt keine neuen Kunden mehr an und endet am 1.1.2027. Aus dem Browser heraus
  (ohne Server) lässt sich keine der Suchmaschinen auslesen, das verhindert die Same-Origin-Policy.
- `EAN_SEARCH_API_KEY`: Schlüssel für `serpapi`/`brave`. Fehlt er dort, antwortet `search.php` auf eine EAN mit 501
  und einem Hinweis; die Titelsuche funktioniert weiter.

Kamera und Erkennung im Browser: Chrome auf Android hat einen eingebauten Barcode-Leser (`BarcodeDetector`). Die
Kamera wird mit 1920 × 1080 und Dauer-Autofokus angefordert; mit Chromes Standard (640 × 480) las der Leser oft
nichts. Im Vergleich auf `dev/scan-test.html` mit echten DVD-Hüllen las auf Android nur der eingebaute Leser, die
Bibliotheken (ZXing-JS, ZXing-C++/WASM, Quagga2) erkannten bei 134 Versuchen keinen Code.
Safari auf dem iPhone und Desktop-Browser unter Windows haben keinen; dort lädt das Frontend beim ersten Scan
`vendor/zxing.min.js` nach (~330 KB). Die Kamera gibt der Browser nur über HTTPS (oder `localhost`) frei; ohne sie
bleibt der Button ausgeblendet. Lokal unter `https://videothek.test` müsste das Smartphone dem selbst ausgestellten
Zertifikat vertrauen, zum Scannen ist deshalb der Produktivserver gedacht.

### Trailer

Beim Import nimmt die Videothek von den Trailer-Kandidaten bei TMDB den besten abspielbaren; ob ein Video abspielbar
ist, verrät YouTubes oEmbed-Endpunkt (`api/lib/youtube.php`, ohne API-Key). Danach wird nichts mehr automatisch
geprüft oder ersetzt. Ist ein Trailer verschwunden, sucht man im Formular über den Link "Trailer auf YouTube suchen"
(YouTube-Suche nach Titel und Jahr, neuer Tab) einen neuen und trägt dessen URL selbst ein.

### Druckfunktion

Rein clientseitig über `@media print`-CSS, kein PHP-PDF-Generator. Der "Drucken"-Button in der Film-Detailansicht
ruft `window.print()` auf; die Print-Styles blenden Header, Suchleiste, Kartenraster, Formular-/Aktions-Buttons,
Hintergrundbild und Trailer-Player aus, schalten das dunkle Kino-Farbschema auf helle, tintensparende Farben um und
lassen nur den offenen Film übrig: Poster oben links, Titel und Eckdaten rechts daneben, darunter Handlung, der
Trailer als **QR-Code** (so groß wie ein Darstellerfoto) mit dem Trailer-Link daneben, die **ersten 6 Darsteller**
und die Notizen; bei Staffeln zusätzlich die Folgen (Nummer und Titel, ohne Handlung). Den QR-Code erzeugt
`public/vendor/qrcode.js` (qrcode-generator 1.4.4 von Kazuhiko Arase, MIT-Lizenz) als SVG im Browser, ganz ohne
externen Dienst. Der Browser-Dialog "Drucken → Als PDF speichern" reicht für einen PDF-Export.

## Stand / Roadmap

- ✅ Projekt aus dem Familien-Kochbuch abgeleitet (gleiche Architektur: PHP/JSON-API ohne Datenbank, One-Page-Frontend)
- ✅ Film-Datenmodell (Titel, Originaltitel, Jahr, Laufzeit, FSK, Genres, Regie, Medium, Handlung, Trailer,
     Darsteller mit Rollen, Notizen)
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
- ✅ Serien: jede Staffel ein eigener Eintrag (Cover, Medium, Folgen), gruppiert wie Reihen; Import ergänzt fehlende 
     Staffeln
- ✅ Import per Barcode-Scan mit dem Smartphone (Websuche über DuckDuckGo, wahlweise SerpApi oder Brave)
- ✅ Filmreihen als gemeinsame Kachel mit Cover-Collage, Teile aufklappbar
- ⬜ Eigenen TMDB-API-Key eintragen
- ✅ Einmaliger Import der Kodi-Sammlung (289 Filme, Filmdateien nach USB_ROOT)
- ⬜ Bestand erfassen

## Lizenz

Dieses Projekt steht unter der [GPL-3.0-Lizenz](LICENSE).

Das bedeutet: Du darfst diesen Code nutzen, ändern und verbreiten. Wenn du die Software (oder eine modifizierte
Version davon) jedoch weitergibst, musst du deinen Quellcode ebenfalls unter derselben Lizenz offenlegen.

# Videothek

Eine private Familien-Videothek: die eigene Sammlung an Filmen und Serien (DVD, Blu-ray, USB, ...) als kleine
PHP/JSON-API mit einer statischen One-Page-Frontend-App.

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

## Projektstruktur

```
videothek/                                            # Projektroot
 ├── data/                                            # Außerhalb des Document Root, kein direkter Browser-Zugriff möglich
 │    ├── cache/ean/                                  # Treffertitel der Websuche je Barcode (siehe "Barcode scannen")
 │    ├── collections.json                            # Alle Filme je Reihe laut TMDB (siehe "Filmreihen und Serien")
 │    ├── series.json                                 # Alle Staffeln je Serie laut TMDB
 │    └── movies/                                     # Ein Unterordner pro Film bzw. Staffel
 │         └── <slug>/                                # Slug aus Titel + Jahr, z. B. "die-goonies-1985", "breaking-bad-staffel-1-2008"
 │              ├── actors/                           # Darstellerfotos wie bei Kodi benannt (Sean_Astin.jpg), aber nicht versteckt
 │              ├── movie.json                        # Daten (Titel, Jahr, Genres, Darsteller, Folgen, ...) – Schema siehe "API"
 │              ├── poster.<ext>                      # Poster (Upload oder TMDB)
 │              ├── fanart.<ext>                      # Hintergrundbild (nur per TMDB-Import)
 │              └── thumb.<ext>                       # automatisch generierte Miniatur des Posters (Hochformat 2:3)
 ├── bin/                                             # Einmalige Skripte für die Kommandozeile (lokal, php bin/…)
 │    ├── build_catalog.php                           # collections.json/series.json für den Bestand füllen
 │    └── collections_from_list.php                   # Reihen aus docs/filmreihen.md in die Filme eintragen
 ├── docs/                                            # Projektdokumentation (Markdown)
 ├── public/                                          # Document Root
 │    ├── api/                                        # API-Endpunkte
 │    │    └── lib/                                   # Gemeinsam genutzte Hilfsfunktionen der API-Endpunkte
 │    │    │    ├── actors.php                        # Darstellerfotos von TMDB laden (zeitbegrenzt, siehe api/actors.php)
 │    │    │    ├── catalog.php                       # collections.json/series.json lesen und schreiben
 │    │    │    ├── crud.php                          # Read/Write/Rename/Delete-Logik + Validierung
 │    │    │    ├── ean.php                           # Barcode (EAN) → Websuche → Suchbegriff für TMDB
 │    │    │    ├── fetch.php                         # http_get(): curl, sonst file_get_contents()-Fallback
 │    │    │    ├── http.php                          # JSON-Response- und Token-Check-Helper
 │    │    │    ├── image_store.php                   # Poster/Hintergrundbild/Darstellerfoto speichern + Thumbnail
 │    │    │    ├── slug.php                          # slugify() + Eindeutigkeits-Check
 │    │    │    ├── thumbnail.php                     # Thumbnail-Erzeugung per GD (Center-Crop) + Lazy-Fallback
 │    │    │    ├── tmdb.php                          # TMDB-API: Suche, Film-/Seriendetails, Zuordnung zu movie.json
 │    │    │    └── youtube.php                       # Verfügbarkeit von YouTube-Videos prüfen (oEmbed)
 │    │    ├── actors.php                             # Darstellerfotos eines Eintrags herunterladen (mehrere Aufrufe)
 │    │    ├── catalog.php                            # Welche Teile/Staffeln es laut TMDB gibt (für "2 von 7")
 │    │    ├── image.php                              # Liefert Poster, Thumbnail, Hintergrundbild, Darstellerfoto
 │    │    ├── import.php                             # Film oder Serie (je Staffel ein Eintrag) aus TMDB importieren
 │    │    ├── movies.php                             # Filme und Staffeln lesen, anlegen, bearbeiten, löschen
 │    │    ├── play.php                               # Play-Button: Wiedergabeliste (.m3u) mit den lokalen Dateipfaden
 │    │    ├── search.php                             # Suche nach Filmen und Serien in TMDB (Titel, IMDb-ID, Barcode)
 │    │    └── upload.php                             # Poster-/Hintergrundbild-Upload, zum Poster inkl. Thumbnail
 │    ├── dev/                                        # Admin-/Wartungsskripte – NICHT dauerhaft auf dem Produktivserver lassen (siehe unten)
 │    │    ├── info.php                               # phpinfo()
 │    │    ├── scan-test.html                         # PoC: Barcode-Erkenner auf denselben Kamerabildern vergleichen
 │    │    └── scan-test.js                           # JavaScript dazu
 │    ├── .htaccess                                   # Apache Zugriffsschutz
 │    ├── .htaccess.example                           # Vorlage für Apache Zugriffsschutz
 │    ├── app.css                                     # CSS-Styles für das Frontend
 │    ├── app.js                                      # JavaScript für das Frontend
 │    ├── vendor/qrcode.js                            # QR-Code-Generator (Kazuhiko Arase, MIT) für den Trailer im Ausdruck
 │    ├── vendor/zxing.min.js                         # Barcode-Erkennung (@zxing/library 0.21.3, Apache-2.0), nur ohne BarcodeDetector
 │    ├── favicon.svg                                 # Browser-Tab-Icon (Filmklappe)
 │    └── index.html                                  # Frontend (lädt/schreibt ausschließlich über /api/*)
 ├── .gitignore                                       # Vom Git-Repository auszuschließende Dateien
 ├── .htpasswd                                        # Apache Passwortdatei (nicht im Git-Repository)
 ├── types/scanner.d.ts                               # Nur für die IDE: Typen für BarcodeDetector und ZXing (nicht deployen)
 ├── config.example.php                               # Vorlage für config.php
 ├── config.php                                       # Zentrale Konfigurationsdatei (nicht im Git-Repository)
 ├── LICENSE                                          # Lizenzhinweis
 └── README.md                                        # Landingpage für das Git-Repository
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
selbst mit abdeckt.

| Methode | Endpunkt                                             | Beschreibung                                                                                                                                                                       |
|---------|------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| GET     | `/api/movies.php`                                    | Alle Filme und Staffeln, alphabetisch nach Titel (das Frontend sortiert ggf. um); dazu je `thumbHash` (Prüfsumme des Thumbnails)                                                   |
| GET     | `/api/movies.php?slug=xyz`                           | Einzelner Eintrag                                                                                                                                                                  |
| POST    | `/api/movies.php`                                    | Neuen Film bzw. neue Staffel anlegen (JSON-Body, `type` = `movie`\|`season`)                                                                                                       |
| PUT     | `/api/movies.php?slug=xyz`                           | Eintrag bearbeiten (JSON-Body, ohne `type` bleibt die Art). Ändert sich der Slug (Titel/Jahr), wird der Ordner atomar umbenannt.                                                   |
| DELETE  | `/api/movies.php?slug=xyz`                           | Eintrag samt Ordner (Bilder inklusive) löschen                                                                                                                                     |
| POST    | `/api/upload.php`                                    | Bild hochladen (`multipart/form-data`: `slug`, `image`, `kind` = `poster`\|`fanart`\|`actor` + `actor`); mit `remove=1` Poster bzw. Hintergrundbild entfernen                      |
| GET     | `/api/image.php?slug=xyz&type=poster\|thumb\|fanart` | Poster, Thumbnail bzw. Hintergrundbild ausliefern                                                                                                                                  |
| GET     | `/api/image.php?slug=xyz&actor=Sean%20Astin`         | Darstellerfoto ausliefern                                                                                                                                                          |
| GET     | `/api/search.php?query=…&year=…`                     | Suche nach Filmen und Serien in TMDB (Titel, IMDb-ID oder EAN, Jahr optional), markiert bereits vorhandene                                                                         |
| POST    | `/api/import.php`                                    | Film bzw. Serie (je Staffel ein Eintrag) aus TMDB importieren inkl. Bilder (Body: `{"tmdb": 9340, "type": "movie"}`, Serie: `{"tmdb": 1396, "type": "series", "seasons": [1, 2]}`) |
| GET     | `/api/play.php?slug=xyz[&episode=3]`                 | Wiedergabeliste (`.m3u`) mit USB_ROOT + `files` für den lokalen Player: Film, alle Folgen einer Staffel bzw. eine Folge                                                            |
| POST    | `/api/actors.php`                                    | Fehlende Darstellerfotos laden, ~10 s pro Aufruf, bis `remaining` 0 ist (Body: `{"slug": "..."}`)                                                                                  |
| GET     | `/api/catalog.php`                                   | Alle Teile je Reihe und Staffeln je Serie laut TMDB (`collections`, `series`, siehe "Filmreihen und Serien")                                                                       |

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
  "medium": ["USB"],
  "files": ["die-goonies-1985/movie.avi"],
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

Eine **Staffel** einer Serie (`"type": "season"`) ist ein Eintrag wie ein Film, mit eigenem Cover, Medium, Dateien,
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
  "medium": ["DVD", "USB"],
  "files": ["breaking-bad-staffel-1-2008/E01.mkv", "breaking-bad-staffel-1-2008/E02.mkv"],
  "episodes": [
    { "number": 1, "title": "Der Einstieg", "plot": "Ein Chemielehrer mit Lungenkrebs im Endstadium ..." },
    { "number": 2, "title": "Die Katze ist im Sack ...", "plot": "..." }
  ],
  "poster": "poster.jpg",
  "...": "weiter wie beim Film (thumb, fanart, plot, trailer, cast, notes, uniqueids, slug, created)"
}
```

Die Feldreihenfolge entspricht dem Bearbeiten-Formular, danach folgen die Verwaltungsfelder. `write_movie_json()`
schreibt sie immer in dieser Reihenfolge (`MOVIE_FIELD_ORDER` in `crud.php`); Felder, die nicht zum Schema gehören,
fallen dabei weg. Ältere `movie.json` ohne `type` gelten als Film. Wechselt ein Eintrag beim Bearbeiten die Art,
fallen die Felder der anderen Art weg (`TYPE_ONLY_FIELDS`).

Pflicht ist nur `title`. `year` (1880–2100) und `runtime` (Minuten) sind ganze Zahlen oder `null`, `genres` ist eine
Liste von Strings, `cast` eine Liste von Darstellern. Dort sind `profile` (TMDB-Porträtpfad, Quelle für den
Download) und `thumb` (Dateiname unter `actors/`) nur bei importierten Filmen gesetzt. Die übrigen Felder sind
Strings oder `null`.

`medium` ist die Liste der Formate, in denen der Film vorhanden ist (`["DVD"]`, bei doppeltem Bestand z. B.
`["DVD", "USB"]`); im Formular per Checkbox gewählt (DVD, Blu-ray, USB plus alle schon verwendeten Werte). Die API
nimmt auch einen String an (`"DVD, USB"`, kommagetrennt), ältere `movie.json` mit `"medium": "USB"` werden beim
Lesen zur Liste und beim nächsten Speichern so geschrieben (`normalize_movie_media()` in `crud.php`). `fsk` ist
die Altersfreigabe als freier Text, das Formular schlägt die üblichen Werte vor. `files` gibt es nur bei Medium
USB: die Filmdatei(en) relativ zu `USB_ROOT`, bei mehrteiligen Filmen (`cd1`, `cd2`, …) mehrere in
Abspielreihenfolge, immer mit `/` gespeichert. Absolute Pfade und `..` lehnt die API ab. `uniqueids`, `poster`,
`thumb` und `fanart` setzen nur Import und Upload (`upload.php`, dort auch Entfernen); das Formular schickt sie nicht
mit, sie bleiben beim Bearbeiten erhalten. Anders bei den Darstellern: Das Formular schickt `profile` (TMDB-Pfad)
und `thumb` (Foto unter `actors/`) jeder Zeile mit, ein entferntes Foto fehlt also danach, und `update_movie()`
löscht Fotos, auf die kein Darsteller mehr verweist (`remove_unused_actor_photos()`). Ein hochgeladenes Foto heißt
`<Name>-<Zufall>.<ext>` (300 × 450), damit der Browser kein altes aus dem Cache zeigt. **Ein `PUT` gilt immer als
vollständiger Stand** wie im Formular: Was fehlt (z. B. die Handlung einer Folge), ist danach leer.

Bei Staffeln ist `year` das Jahr der Staffel und `runtime` die Länge einer Folge; `uniqueids` sind die IDs der
Serie. `episodes` hat `number`, `title` und `plot`, die Nummern sind eindeutig (doppelte lehnt die API mit Meldung
ab). `files` sind die Folgendateien relativ zu `USB_ROOT`, üblich `<slug>/E01.<ext>`; zugeordnet werden sie über die
Folgennummer im Dateinamen (`E03`, `S01E03`, mehrteilig `E03-cd1`, `E03-cd2`), trägt keine Datei eine solche Nummer,
über die Position (`season_episode_files()` in `crud.php`, gleiche Regel in `app.js`: `episodeFiles()`).

Alte Serien-Einträge (`"type": "series"`, alle Staffeln in `seasons[]`) blendet die Übersicht aus und nennt nur ihre
Zahl im Kopf; sie werden neu importiert (alten Ordner vorher löschen, sonst bekommt der neue Slug ein `-2`).

Doppelte Einträge werden über **Art + TMDB-ID** (TMDB nummeriert Filme und Serien getrennt), bei Staffeln über
**Serie + Staffelnummer** (Serie = TMDB-ID, wenn beide eine haben, sonst der Name), und über **Art + Titel + Jahr** erkannt (nicht über den Titel allein), damit Remakes wie "Ben Hur" (1959) und "Ben Hur" (2016) nebeneinander
existieren können. Aus demselben Grund steckt das Jahr auch im Slug.


## Frontend / Arbeitsweise

### Request-Ablauf beim Seitenaufruf

Beim Aufruf von `index.html` laufen die Requests in dieser Reihenfolge:

1. **Statische Assets** (durch den Browser beim HTML-Parsing): `favicon.svg`, `app.css`, danach `app.js` (steht am 
   Ende von `<body>`, lädt/läuft also erst nach dem übrigen HTML).
2. Sobald `app.js` ausgeführt wird, startet sofort `init()` (kein Warten auf `DOMContentLoaded` nötig, da das Script 
   ohnehin erst am Body-Ende geladen wird):
   - **`GET api/movies.php`** lädt die komplette Filmliste als JSON. Das ist der einzige zwingende Request beim 
     Start; schlägt er fehl, wird eine Fehlermeldung angezeigt und die Initialisierung bricht ab.
   - Beim Rendern des Kartenrasters bekommt jeder Film mit Poster ein
     `<img src="api/image.php?slug=…&type=thumb" loading="lazy">`. Dadurch fordert der Browser die Thumbnails an, 
     aber wegen `loading="lazy"` nur die Thumbnails, die tatsächlich (nahe) im sichtbaren Viewport liegen; der Rest 
     folgt erst beim Scrollen. Poster (`type=poster`) und Hintergrundbild (`type=fanart`) werden erst beim Öffnen
     eines einzelnen Films nachgeladen.

Kein Token-Request/Prompt beim Laden: `requireToken()` wird ausschließlich bei schreibenden Aktionen (Speichern/
Löschen/Importieren) aufgerufen, siehe nächsten Abschnitt.

### Suchen, Filtern, Sortieren

Die Suche durchsucht Titel, Originaltitel, Jahr, Genres, Regie bzw. Idee, Darsteller (Name und Rolle), Handlung,
Medium, Reihe bzw. Serie und bei Staffeln die Folgentitel. Gefiltert wird nach Art (Filme und Serien, nur Filme, nur
Serien), nach Genre (alle Einträge, die das gewählte Genre enthalten) und nach Medium (DVD, Blu-ray, ...; ein Eintrag
mit mehreren Medien erscheint unter jedem, dazu "(nicht im Bestand)"); die Filter lassen sich kombinieren und
bieten nur Werte an, die im Bestand tatsächlich vorkommen. Sortiert wird alphabetisch, nach Erscheinungsjahr
(Einträge ohne Jahr am Ende) oder nach Hinzufügedatum. Alphabetisch zählt der Titel ohne führenden Artikel ("The Big
Lebowski" steht bei B, wie in Kodi); welche Artikel, legt `SORT_IGNORE_ARTICLES` oben in `app.js` fest (der, die,
das, the, ein, eine, a, an; leere Liste = aus). Suchbegriff, Filter und Sortierung werden in `localStorage`
unter `videothek_view_state` gemerkt und überstehen so einen Reload.

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
und die übrigen Teile abgeschwächt ("1 Treffer"). Eine Gruppe mit nur einem Eintrag bleibt ein einzelner Eintrag.

**Nicht im Bestand** ist ein Eintrag ohne angehaktes Medium (von Hand angelegt; der Import legt nur an, was man
hat). Solche Einträge erscheinen überall blass mit gestricheltem Rahmen und dem Schlagwort "nicht im Bestand", bleiben 
aber anklickbar. Der Filter "Medium" hat dafür den Eintrag "(nicht im Bestand)". Die Reihe zählt mit ("2 von 3 im 
Bestand"), beim Aufklappen und in der Detailansicht, wo alle Teile als Zeile kleiner Plakate stehen (ein Klick öffnet 
den Teil; im Ausdruck fehlt der Abschnitt). TMDB wird dafür nicht gefragt, nur beim Import.

**Welche Teile bzw. Staffeln es gibt**, steht einmal je Reihe bzw. Serie in `data/collections.json` und
`data/series.json` (`api/lib/catalog.php`), nicht in den einzelnen Einträgen: je TMDB-ID Name, Datum der Abfrage
(`updated`) und die Teile (`tmdb`, `title`, `year`, `date`) bzw. Staffeln (`season`, `title`, `year`, `date`,
`episodes`, ohne Specials). Der Import aktualisiert den Eintrag nebenbei (Serie: aus dem ohnehin geladenen Abruf;
Film einer Reihe: ein zusätzlicher Abruf `collection/{id}`), `php bin/build_catalog.php` füllt ihn einmalig für den
vorhandenen Bestand (`--refresh`: alle neu holen, etwa wegen neuer Fortsetzungen). Was davon im Bestand ist, rechnet
das Frontend aus (`groupCatalog()`: Filme über `uniqueids.tmdb`, Staffeln über `series.tmdb` + `season`), damit die
Dateien bei Änderungen am Bestand nicht mitgepflegt werden müssen. Angezeigt wird das bewusst neutral: Kachel
"2 von 7 Filmen", in der Detailansicht über den Teilen "Filmteile (2 von 7)" bzw. "Staffeln (2 von 11)", vollständig 
"Staffeln (11)", mit Stand der Abfrage als Tooltip und die übrigen Teile als dezente leere Platzhalter an ihrer Stelle 
(nach Jahr bzw. Staffelnummer). Teile ohne oder mit künftigem Datum heißen "angekündigt" und zählen nicht mit. Gruppen 
ohne TMDB-ID (von Hand gebildet) bekommen keinen Hinweis.

`collection` ist `{"name": "Matrix", "tmdb": "2344", "part": 1}` oder `null`, `series` ebenso, aber ohne
`part` (die Nummer einer Staffel steht in `season`). Den Zusatz "Filmreihe", den TMDB fast immer anhängt ("Matrix
Filmreihe"), lässt der Import weg (`tmdb_collection()`). `part` ist die Nummer des Films in der Reihe, im Formular das
Feld "Teil" neben "Reihe" (1–999, leer erlaubt). TMDB kennt keine Teilnummer, sie wird von Hand eingetragen; nötig ist
sie nur, wenn das Jahr nicht reicht, z. B. bei Teilen aus demselben Jahr (Millennium: drei Filme 2009). Filme mit
Teil stehen vorn, nach Nummer, die übrigen danach nach Jahr; die Detailansicht zeigt "Reihe: Millennium, Teil 2".
Der Import übernimmt die
Reihe aus TMDB (`belongs_to_collection`) bzw. die Serie; hat schon ein Teil derselben TMDB-Reihe bzw. -Serie einen
Eintrag, gilt dessen Name. Im Formular sind "Reihe" und "Serie" Textfelder mit den vorhandenen Namen als Vorschlag;
ein vorhandener Name übernimmt dessen TMDB-ID; beim Umbenennen behält der Eintrag seine eigene, ein ganz neuer
Eintrag mit neuem Namen bekommt keine (`formGroup()`). Zusammengefasst wird über den Namen (ohne
Groß-/Kleinschreibung), eine Gruppe lässt sich also auch von Hand bilden, z. B. drei DVDs, die TMDB als eigene Serien
führt, unter "Shaun das Schaf". Wird der Name bei einem Teil geändert, fragt das Formular nach dem Speichern, ob die
übrigen Teile der bisherigen Gruppe mit umbenannt werden sollen (`renameGroupSiblings()`; jeder behält TMDB-ID und
Teilnummer). So bleibt es bei einem Namen je TMDB-ID, und der Import einer Fortsetzung übernimmt diesen Namen. Die
Ordner bleiben flach unter `data/movies/`.

Filme, die vor dieser Funktion importiert wurden, haben noch keine Reihe. Welche dazugehören, steht in
`docs/filmreihen.md` (einmalig über TMDB ermittelt, Stand 17.10.2026); eingetragen wird von Hand im Formular.

### Detailansicht

Poster, Titel und Eckdaten, darüber abgedunkelt das Hintergrundbild (`fanart`). Darunter Handlung, Trailer, die
**vollständige** Darstellerliste mit Fotos und Rollen (scrollt in einem eigenen Bereich, Fotos per `loading="lazy"`)
und die Notizen.

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

Filme mit Medium **USB** liegen als Dateien auf einer USB-Platte bzw. lokal unter `USB_ROOT` (config.php,
z. B. `D:\videothek`), ein Unterordner pro Film mit dem Slug als Namen und darin nur die Filmdatei `movie.<ext>`
(`am-sonntag-bist-du-tot-2014\movie.mkv`; mehrteilig `verblendung-2011\movie-cd1.flv`, `movie-cd2.flv`, …), keine
Poster oder NFOs. Im Formular erscheint bei angehaktem Medium USB (auch zusammen mit DVD o. Ä.) das Pflichtfeld
"Dateien (relativ zum USB-Ordner)", vorbelegt mit `<slug>/movie.` (Endung ergänzen); in der Detailansicht der
Button **"▶ Abspielen"**.

Staffeln liegen nach demselben Muster in einem Ordner pro Staffel, eine Datei pro Folge:
`breaking-bad-staffel-1-2008\E01.mkv`, `E02.mkv`, ... Das Formular schlägt eine Zeile pro Folge vor (`<slug>/E01.`
usw.); wird die Endung in einer Zeile ergänzt, übernehmen die übrigen sie beim Verlassen des Feldes. In der
Detailansicht gibt es dann **"▶ Alle Folgen abspielen"** und neben jeder Folge, der eine Datei zugeordnet ist, ein ▶
für genau diese Folge (`play.php?…&episode=3`). Früher eingetragene Pfade (z. B. `breaking-bad-2008/S01/E01.mkv`)
gelten weiter, die Zuordnung läuft über die Folgennummer im Dateinamen.

Der Button öffnet keinen `file:///`-Link, denn den blockieren Chrome, Edge und Firefox auf einer http(s)-Seite
kommentarlos. Stattdessen liefert `api/play.php` eine Wiedergabeliste (`<slug>.m3u`) mit dem vollständigen Pfad
(`USB_ROOT` + `files`), die das Betriebssystem mit dem installierten Player öffnet (VLC, Windows Media Player, …);
mehrteilige Filme laufen nacheinander. Abgespielt wird also nicht im Browser, ein Player wird vorausgesetzt.
Tipp: In Chrome/Edge beim ersten Mal im Download-Menü "Dateien dieses Typs immer öffnen" wählen, dann startet der
Film mit einem Klick. `USB_ROOT` gilt auf dem Rechner, der abspielt, nicht auf dem Webserver.

### Token-Authentifizierung

Für Änderungen (Film anlegen/bearbeiten/löschen, Bild-Upload) fragt das Frontend beim ersten Schreibversuch per 
`prompt()` nach dem API-Token und legt es danach in `localStorage` unter dem Schlüssel `videothek_api_token` ab. 
Jeder weitere Schreibversuch liest das gespeicherte Token wieder aus und fragt nicht erneut nach.

Es wird nur dann wieder nachgefragt, wenn 
a) der Server das gespeicherte Token ablehnt (401) – dann wird es automatisch aus `localStorage` gelöscht, oder 
b) du es manuell löschst, z. B. in den Browser-DevTools mit `localStorage.removeItem('videothek_api_token')` oder 
   über "Website-Daten löschen".

Lesezugriffe (Filmliste, Bilder) brauchen kein App-Token — Schutz vor öffentlichem Zugriff übernimmt die 
HTTP Basic Auth vor der gesamten `public/`-Domain (siehe Setup, Schritt 5).

### Import-Funktion

Der Import holt die Daten von Filmen und Serien aus **TMDB (The Movie Database)**, wie der Kodi-Scraper "The Movie
Database Python" (xbmc-Repo: `addons/metadata.themoviedb.org.python`). Er ersetzt den früheren HTML-Import über
schema.org-Daten, der nur englische Titel und keine FSK lieferte.

Ablauf im Import-Dialog ("Importieren" neben "+ Neu"):

1. **Suchen** (`GET /api/search.php`): Titel, optional mit Jahr, oder direkt eine IMDb-ID (`tt0089218`, wird wie bei
   Kodi über `/find` aufgelöst). Gesucht wird in den Filmen und Serien von TMDB zugleich; jeder Treffer ist als
   "Film" bzw. "Serie" gekennzeichnet. Exakte Titeltreffer kommen nach oben, darin nach Popularität sortiert.
   Bereits vorhandene Filme sind markiert und öffnen sich beim Anklicken; bei Serien steht da, wie viele Staffeln
   schon vorhanden sind. Statt des Titels geht auch der **Barcode** der DVD (siehe unten).
2. **Treffer anklicken** (`POST /api/import.php`, Token nötig): lädt die Details in `TMDB_LANGUAGE` und legt den
   Eintrag über dieselbe Validierungs-/Anlege-Funktion wie `POST /api/movies.php` an (`create_movie()`). Als Medium
   wird `DVD` eingetragen (`IMPORT_DEFAULT_MEDIUM` in `import.php`), da TMDB das Format des eigenen Exemplars nicht
   kennt. Danach werden Poster und Hintergrundbild heruntergeladen (`store_movie_image()`, dieselbe Funktion wie
   beim Upload). Schlägt nur ein Bild fehl, bleibt der Eintrag gespeichert, die Antwort enthält dann
   `"import_warning"`.

   Bei einer **Serie** fragt der Treffer zuerst ab, welche Staffel(n) importiert werden (z. B. `2` oder `1-3, 5`,
   `0` = Specials). Nur diese legt der Import an, je Staffel ein Eintrag mit Medium DVD; von TMDB werden auch nur
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
- ✅ Serien: jede Staffel ein eigener Eintrag (Cover, Medium, Folgen), gruppiert wie Reihen; Import ergänzt fehlende Staffeln
- ✅ Import per Barcode-Scan mit dem Smartphone (Websuche über DuckDuckGo, wahlweise SerpApi oder Brave)
- ✅ Filmreihen als gemeinsame Kachel mit Cover-Collage, Teile aufklappbar
- ⬜ Reihen der vorhandenen Filme eintragen (Liste: `docs/filmreihen.md`)
- ⬜ Eigenen TMDB-API-Key eintragen
- ✅ Einmaliger Import der Kodi-Sammlung (289 Filme, Filmdateien nach USB_ROOT)
- ⬜ Bestand erfassen

## Lizenz

Dieses Projekt steht unter der [GPL-3.0-Lizenz](LICENSE).

Das bedeutet: Du darfst diesen Code nutzen, ändern und verbreiten. Wenn du die Software (oder eine modifizierte
Version davon) jedoch weitergibst, musst du deinen Quellcode ebenfalls unter derselben Lizenz offenlegen.

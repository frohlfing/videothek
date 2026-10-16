# Videothek

Eine private Familien-Videothek: die eigene Filmsammlung (DVD, Blu-ray, USB, ...) als kleine PHP/JSON-API mit
einer statischen One-Page-Frontend-App.

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
 ├── bin/                                             # Kommandozeilen-Skripte (laufen lokal, nicht auf dem Webserver)
 │    └── check_trailers.php                          # Trailer bei YouTube prüfen, tote durch aktuelle von TMDB ersetzen
 ├── data/                                            # Außerhalb des Document Root, kein direkter Browser-Zugriff möglich
 │    └── movies/                                     # Ein Unterordner pro Film
 │         └── <slug>/                                # Ein Ordner pro Film (Slug aus Titel + Jahr, z. B. "die-goonies-1985")
 │              ├── actors/                           # Darstellerfotos wie bei Kodi benannt (Sean_Astin.jpg), aber nicht versteckt
 │              ├── movie.json                        # Filmdaten (Titel, Jahr, Genres, Darsteller, ...) – Schema siehe Abschnitt "API"
 │              ├── poster.<ext>                      # Poster (Upload oder TMDB)
 │              ├── fanart.<ext>                      # Hintergrundbild (nur per TMDB-Import)
 │              └── thumb.<ext>                       # automatisch generierte Miniatur des Posters (Hochformat 2:3)
 ├── docs/                                            # Projektdokumentation (Markdown)
 ├── public/                                          # Document Root
 │    ├── api/                                        # API-Endpunkte
 │    │    └── lib/                                   # Gemeinsam genutzte Hilfsfunktionen der API-Endpunkte
 │    │    │    ├── actors.php                        # Darstellerfotos von TMDB laden (zeitbegrenzt, siehe api/actors.php)
 │    │    │    ├── crud.php                          # Read/Write/Rename/Delete-Logik + Validierung
 │    │    │    ├── fetch.php                         # http_get(): curl, sonst file_get_contents()-Fallback
 │    │    │    ├── http.php                          # JSON-Response- und Token-Check-Helper
 │    │    │    ├── image_store.php                   # Poster/Hintergrundbild validieren/speichern + Thumbnail
 │    │    │    ├── slug.php                          # slugify() + Eindeutigkeits-Check
 │    │    │    ├── thumbnail.php                     # Thumbnail-Erzeugung per GD (Center-Crop) + Lazy-Fallback
 │    │    │    ├── tmdb.php                          # TMDB-API: Suche, Details, Zuordnung zu movie.json
 │    │    │    └── youtube.php                       # Verfügbarkeit von YouTube-Videos prüfen (oEmbed)
 │    │    ├── actors.php                             # Darstellerfotos eines Films herunterladen (mehrere Aufrufe)
 │    │    ├── image.php                              # Liefert Poster, Thumbnail, Hintergrundbild, Darstellerfoto
 │    │    ├── import.php                             # Film aus TMDB importieren
 │    │    ├── movies.php                             # Filme lesen, anlegen, bearbeiten, löschen
 │    │    ├── play.php                               # Play-Button: Wiedergabeliste (.m3u) mit dem lokalen Dateipfad
 │    │    ├── search.php                             # Filmsuche in TMDB (für den Import-Dialog)
 │    │    └── upload.php                             # Poster-/Hintergrundbild-Upload, zum Poster inkl. Thumbnail
 │    ├── dev/                                        # Admin-/Wartungsskripte – NICHT dauerhaft auf dem Produktivserver lassen (siehe unten)
 │    │    └── info.php                               # phpinfo()
 │    ├── .htaccess                                   # Apache Zugriffsschutz
 │    ├── .htaccess.example                           # Vorlage für Apache Zugriffsschutz
 │    ├── app.css                                     # CSS-Styles für das Frontend
 │    ├── app.js                                      # JavaScript für das Frontend
 │    ├── vendor/qrcode.js                            # QR-Code-Generator (Kazuhiko Arase, MIT) für den Trailer im Ausdruck
 │    ├── favicon.svg                                 # Browser-Tab-Icon (Filmklappe)
 │    └── index.html                                  # Frontend (lädt/schreibt ausschließlich über /api/*)
 ├── .gitignore                                       # Vom Git-Repository auszuschließende Dateien
 ├── .htpasswd                                        # Apache Passwortdatei (nicht im Git-Repository)
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

| Methode | Endpunkt                                         | Beschreibung                                                                                                            |
|---------|--------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------|
| GET     | `/api/movies.php`                                | Alle Filme, alphabetisch nach Titel sortiert (das Frontend sortiert clientseitig ggf. um)                               |
| GET     | `/api/movies.php?slug=xyz`                       | Einzelner Film                                                                                                          |
| POST    | `/api/movies.php`                                | Neuen Film anlegen (JSON-Body)                                                                                          |
| PUT     | `/api/movies.php?slug=xyz`                       | Film bearbeiten (JSON-Body). Ändern sich Titel/Jahr so, dass sich der Slug ändert, wird der Filmordner atomar umbenannt. |
| DELETE  | `/api/movies.php?slug=xyz`                       | Film samt Ordner (Bilder inklusive) löschen                                                                             |
| POST    | `/api/upload.php`                                | Poster/Hintergrundbild hochladen (`multipart/form-data`: `slug`, `image`, `kind` = `poster`\|`fanart`)                  |
| GET     | `/api/image.php?slug=xyz&type=poster\|thumb\|fanart` | Poster, Thumbnail bzw. Hintergrundbild ausliefern                                                               |
| GET     | `/api/image.php?slug=xyz&actor=Sean%20Astin`     | Darstellerfoto ausliefern                                                                                               |
| GET     | `/api/search.php?query=…&year=…`                 | Filmsuche in TMDB (Titel oder IMDb-ID, Jahr optional), markiert bereits vorhandene Filme                                |
| POST    | `/api/import.php`                                | Film aus TMDB importieren inkl. Poster und Hintergrundbild (Body: `{"tmdb": 9340}`)                                     |
| GET     | `/api/play.php?slug=xyz`                         | Wiedergabeliste (`.m3u`) mit USB_ROOT + `files` für den lokalen Player                                                  |
| POST    | `/api/actors.php`                                | Fehlende Darstellerfotos laden, ~10 s pro Aufruf, bis `remaining` 0 ist (Body: `{"slug": "..."}`)                       |

`movie.json`-Schema pro Film:

```json
{
  "title": "Die Goonies",
  "originalTitle": "The Goonies",
  "year": 1985,
  "runtime": 114,
  "fsk": "12",
  "genres": ["Abenteuer", "Komödie", "Familie"],
  "director": "Richard Donner",
  "medium": "USB",
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

Die Feldreihenfolge entspricht dem Bearbeiten-Formular, danach folgen die Verwaltungsfelder. `write_movie_json()`
schreibt sie immer in dieser Reihenfolge (`MOVIE_FIELD_ORDER` in `crud.php`); Felder, die nicht zum Schema gehören,
fallen dabei weg.

Pflicht ist nur `title`. `year` (1880–2100) und `runtime` (Minuten) sind ganze Zahlen oder `null`, `genres` ist eine
Liste von Strings, `cast` eine Liste von Darstellern. Dort sind `profile` (TMDB-Porträtpfad, Quelle für den
Download) und `thumb` (Dateiname unter `actors/`) nur bei importierten Filmen gesetzt. Die übrigen Felder sind
Strings oder `null`.

`medium` ist das Format des eigenen Exemplars (DVD, Blu-ray, USB), `fsk` die Altersfreigabe. `files` gibt es nur bei
Medium USB: die Filmdatei(en) relativ zu `USB_ROOT`, bei mehrteiligen Filmen (`cd1`, `cd2`, …) mehrere in
Abspielreihenfolge, immer mit `/` gespeichert. Absolute Pfade und `..` lehnt die API ab.
Beides sind bewusst freie Texte; das Formular schlägt die üblichen Werte vor. `uniqueids`, `poster`, `thumb` und
`fanart` setzen nur Import und Upload; beim Bearbeiten über das Formular bleiben sie erhalten, ebenso
`profile`/`thumb` der Darsteller (Zuordnung über den Namen).

Doppelte Einträge werden über die **TMDB-ID** und über **Titel + Jahr** erkannt (nicht über den Titel allein), damit
Remakes wie "Ben Hur" (1959) und "Ben Hur" (2016) nebeneinander existieren können. Aus demselben Grund steckt das
Jahr auch im Slug.


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

Die Suche durchsucht Titel, Originaltitel, Jahr, Genres, Regie, Darsteller (Name und Rolle), Handlung und Medium.
Gefiltert wird nach Genre (alle Filme, die das gewählte Genre enthalten) und nach Medium (DVD, Blu-ray, ...);
beide Filter lassen sich kombinieren und bieten nur Werte an, die im Bestand tatsächlich vorkommen. Sortiert wird
alphabetisch, nach Erscheinungsjahr (Filme ohne Jahr am Ende) oder nach Hinzufügedatum. Suchbegriff, Filter und
Sortierung werden in `localStorage` unter `videothek_view_state` gemerkt und überstehen so einen Reload.

### Detailansicht

Poster, Titel und Eckdaten, darüber abgedunkelt das Hintergrundbild (`fanart`). Darunter Handlung, Trailer, die
**vollständige** Darstellerliste mit Fotos und Rollen (scrollt in einem eigenen Bereich, Fotos per `loading="lazy"`)
und die Notizen.

Der Trailer läuft als eingebetteter Player (`youtube-nocookie.com`); YouTube wird dadurch schon beim Öffnen der
Detailansicht geladen. Im Ausdruck erscheint stattdessen ein QR-Code mit der Trailer-URL (siehe "Druckfunktion").

Im Bearbeiten-Formular stehen Poster und Hintergrundbild nebeneinander, jeweils mit Vorschau und eigenem Upload.

### Abspielen (Medium USB)

Filme mit Medium **USB** liegen als Dateien auf einer USB-Platte bzw. lokal unter `USB_ROOT` (config.php,
z. B. `D:\videothek`), ein Unterordner pro Film mit dem Slug als Namen und darin nur die Filmdatei `movie.<ext>`
(`am-sonntag-bist-du-tot-2014\movie.mkv`; mehrteilig `verblendung-2011\movie-cd1.flv`, `movie-cd2.flv`, …), keine
Poster oder NFOs. Im Formular erscheint bei Medium USB das Pflichtfeld "Filmdatei (relativ zum USB-Ordner)"; in
der Detailansicht der Button **"▶ Abspielen"**.

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

Der Import holt die Filmdaten aus **TMDB (The Movie Database)**, wie der Kodi-Scraper "The Movie Database Python"
(xbmc-Repo: `addons/metadata.themoviedb.org.python`). Er ersetzt den früheren HTML-Import über schema.org-Daten, der
nur englische Titel und keine FSK lieferte.

Ablauf im Import-Dialog ("Importieren" neben "+ Neuer Film"):

1. **Suchen** (`GET /api/search.php`): Titel, optional mit Jahr, oder direkt eine IMDb-ID (`tt0089218`, wird wie bei
   Kodi über `/find` aufgelöst). Exakte Titeltreffer kommen nach oben, darin nach Popularität sortiert. Bereits
   vorhandene Filme sind markiert und öffnen sich beim Anklicken.
2. **Treffer anklicken** (`POST /api/import.php`, Token nötig): lädt die Details in `TMDB_LANGUAGE` und legt den Film
   über dieselbe Validierungs-/Anlege-Funktion wie `POST /api/movies.php` an (`create_movie()`). Als Medium wird
   `DVD` eingetragen (`IMPORT_DEFAULT_MEDIUM` in `import.php`), da TMDB das Format des eigenen Exemplars nicht kennt. Danach werden Poster
   und Hintergrundbild heruntergeladen (`store_movie_image()`, dieselbe Funktion wie beim Upload). Schlägt nur ein
   Bild fehl, bleibt der Film gespeichert, die Antwort enthält dann `"import_warning"`.
3. **Darstellerfotos** (`POST /api/actors.php`): Das Frontend ruft den Endpunkt direkt danach so oft auf, bis keine
   Fotos mehr fehlen, und zeigt den Fortschritt an. Jeder Aufruf arbeitet höchstens ~10 s, damit auch Filme mit 50+
   Darstellern nicht ins PHP-Zeitlimit laufen (vor allem unter XAMPP, wo Apache ohne curl lädt, siehe `fetch.php`).
   Abgelegt wird wie bei Kodi als `Vorname_Nachname.jpg` (TMDB-Größe `w185`, ~10 KB), aber in
   `data/movies/<slug>/actors/` statt in einem versteckten `.actors`. Darsteller ohne TMDB-Foto bekommen in der
   Detailansicht ihre Initialen.

Zuordnung (`tmdb_movie()` in `api/lib/tmdb.php`):

| TMDB                                          | `movie.json`                                                                 |
|-----------------------------------------------|------------------------------------------------------------------------------|
| `title`, `original_title`                     | `title`, `originalTitle` (nur wenn abweichend)                               |
| `release_date`                                | `year`                                                                       |
| `genres`                                      | `genres` (von TMDB bereits übersetzt)                                        |
| `runtime`                                     | `runtime`                                                                    |
| `release_dates` des Landes aus `TMDB_LANGUAGE`| `fsk` (erste nicht-leere Einstufung; keine Einstufung → leer, kein US-Rating) |
| `credits.crew` mit `job = Director`           | `director` (kommagetrennt)                                                   |
| `credits.cast` (alle, nach `order`)           | `cast` mit `name`, `role` (`character`), `profile`                           |
| `overview`                                    | `plot`; fehlt die Übersetzung, wie bei Kodi die englische                    |
| `videos` (YouTube, `Trailer` vor `Teaser`)    | `trailer`: der beste **abspielbare** (eigene Sprache vor englisch)           |
| `images.posters`                              | `poster` (eigene Sprache → englisch → ohne Text), Größe `w780`               |
| `images.backdrops`                            | `fanart` (bevorzugt ohne Text), Größe `w1280`                                |
| `id`, `imdb_id`                               | `uniqueids`                                                                  |

Konfiguration in `config.php`:

- `TMDB_API_KEY`: Vorläufig der Key, den Team Kodi im Scraper mitliefert (`tmdbapi.py`). Er ist für Kodi ausgestellt
  und sollte durch einen eigenen ersetzt werden (kostenlos, themoviedb.org → Einstellungen → API).
- `TMDB_LANGUAGE` (z. B. `de-DE`): Sprache für Titel, Handlung, Genres, Trailer und Poster. Das Land für die
  Altersfreigabe (`DE` → FSK) wird daraus abgeleitet.

Bewusst nicht übernommen aus den Kodi-Einstellungen: TMDB-Schlüsselwörter als Tags (englisch, zahlreich, unsortiert).

Ein Quellenhinweis auf TMDB steht im Import-Dialog (die Videothek ist rein privat).

`http_get()` (`fetch.php`) nutzt die curl-Extension, falls vorhanden, sonst `file_get_contents()` mit Stream-Context
als Fallback.

### Trailer prüfen

YouTube-Videos verschwinden immer wieder (gelöscht, privat, Einbetten verboten), und TMDB listet oft noch längst
gelöschte. Deshalb prüft schon der Import jeden Trailer-Kandidaten über YouTubes oEmbed-Endpunkt
(`api/lib/youtube.php`, ohne API-Key) und nimmt den besten abspielbaren. Für den Bestand gibt es ein
Wartungsskript, das man gelegentlich laufen lässt:

```
php bin/check_trailers.php --dry-run   # nur berichten
php bin/check_trailers.php             # reparieren
```

Abspielbare Trailer bleiben. Tote oder fehlende werden durch den aktuell bei TMDB hinterlegten ersetzt bzw. ergänzt
(über `uniqueids.tmdb`); gibt es keinen abspielbaren, wird ein toter Trailer entfernt. Ist YouTube oder TMDB nicht
erreichbar, bleibt alles unverändert ("unklar"). Grenze: regionale Sperren ("in deinem Land nicht verfügbar")
erkennt oEmbed nicht.

### Druckfunktion

Rein clientseitig über `@media print`-CSS, kein PHP-PDF-Generator. Der "Drucken"-Button in der Film-Detailansicht
ruft `window.print()` auf; die Print-Styles blenden Header, Suchleiste, Kartenraster, Formular-/Aktions-Buttons,
Hintergrundbild und Trailer-Player aus, schalten das dunkle Kino-Farbschema auf helle, tintensparende Farben um und
lassen nur den offenen Film übrig: Poster oben links, Titel und Eckdaten rechts daneben, darunter Handlung, der
Trailer als **QR-Code** (so groß wie ein Darstellerfoto) mit dem Trailer-Link daneben, die **ersten 6 Darsteller**
und die Notizen. Den QR-Code erzeugt
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
- ⬜ Eigenen TMDB-API-Key eintragen
- ✅ Einmaliger Import der Kodi-Sammlung (289 Filme, Filmdateien nach USB_ROOT)
- ⬜ Bestand erfassen

## Lizenz

Dieses Projekt steht unter der [GPL-3.0-Lizenz](LICENSE).

Das bedeutet: Du darfst diesen Code nutzen, ändern und verbreiten. Wenn du die Software (oder eine modifizierte
Version davon) jedoch weitergibst, musst du deinen Quellcode ebenfalls unter derselben Lizenz offenlegen.

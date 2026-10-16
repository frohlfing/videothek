# Arbeitsübergabe

Stand: 16.10.2026

Diese Übergabe fasst zusammen, wie die Videothek entstanden ist, welche Entscheidungen dabei getroffen wurden (und
warum), wo die Daten liegen und was noch offen ist. Die technische Referenz (Setup, API, Datenmodell, Abläufe) steht
in der [README](../README.md); hier geht es um den Kontext, der dort nicht steht.

## 1. Kurzüberblick

Die Videothek ist ein privater Familien-Filmkatalog: PHP/JSON-API ohne Datenbank plus One-Page-Frontend, abgeleitet
aus dem Familien-Kochbuch (`C:\Users\frank\Source\PhpStorm\kochbuch`, gleiche Architektur). Sie verwaltet DVDs,
Blu-rays und Filme auf einer USB-Platte (Medium "USB").

| Bereich       | Stand                                                                                              |
|---------------|----------------------------------------------------------------------------------------------------|
| Bestand       | 289 Filme, alle Medium "USB" (aus der früheren Kodi-Sammlung), 386 MB in `data/movies`             |
| Filmdateien   | `D:\videothek\<slug>\movie.<ext>` (mehrteilig `movie-cd1.<ext>`, ...), 303 Dateien, 472 GB         |
| Trailer       | 271 Filme mit Trailer, Prüfung/Reparatur automatisch beim Öffnen eines Films                       |
| Repository    | `https://github.com/frohlfing/videothek.git`, ein Commit ("first commit"), danach weitere Änderungen |
| Produktiv     | Hetzner-Webspace (JSON + Bilder), lokal XAMPP mit `https://videothek.test`                         |

## 2. Wo liegt was?

| Was                                  | Wo                                                    | Bemerkung                                        |
|--------------------------------------|-------------------------------------------------------|--------------------------------------------------|
| Code                                 | `C:\Users\frank\Source\PhpStorm\videothek`            | Git-Repo, siehe Abschnitt 6 zum Commit-Stand     |
| Filmdaten (JSON, Poster, Fanart, Darstellerfotos) | Hetzner (maßgeblich) und lokal `data/movies` | `data/` ist gitignored, siehe Abschnitt 6     |
| Filmdateien (Medium USB)             | `D:\videothek\<slug>\movie.<ext>`                     | nur Filmdateien, keine Bilder/NFOs               |
| Alte Kodi-Metadaten                  | `D:\Filme\<Name (Jahr)>\` (NFO, Bilder, `.actors`)     | ohne Filmdateien, kann gelöscht werden           |
| DVDs/Blu-rays                        | im Schrank                                            | in der Videothek als Medium "DVD"/"Blu-ray"      |
| Konfiguration                        | `config.php` (gitignored), Vorlage `config.example.php` | API-Token, TMDB-Key, `USB_ROOT`                |

## 3. Wichtige Entscheidungen und ihre Gründe

### Datenmodell

- **Film statt Rezept:** eigenes Film-Schema (Titel, Originaltitel, Jahr, Laufzeit, FSK, Genres, Regie, Medium,
  Dateien, Bilder, Handlung, Trailer, Darsteller, Notizen). Felder in `movie.json` in derselben Reihenfolge wie im
  Formular (`MOVIE_FIELD_ORDER` in `crud.php`; Felder außerhalb des Schemas fallen beim Speichern weg).
- **Slug aus Titel + Jahr** (`die-goonies-1985`), Duplikaterkennung über TMDB-ID und Titel + Jahr, damit Remakes
  nebeneinander existieren können.
- **Standort-Feld wieder entfernt** (vom Nutzer nicht gewünscht).
- **Darstellerfotos** pro Film unter `data/movies/<slug>/actors/Vorname_Nachname.jpg`, benannt wie bei Kodi, aber
  nicht im versteckten `.actors`. Liste unbegrenzt (scrollt), alle Fotos werden direkt beim Import geladen
  (`api/actors.php`, in mehreren zeitbegrenzten Aufrufen wegen des PHP-Zeitlimits).

### Import

- **TMDB-API statt HTML-Scraping**, angelehnt an den Kodi-Scraper "The Movie Database Python". Der frühere Import
  über schema.org-Daten (Letterboxd, Rotten Tomatoes) lieferte nur englische Daten und keine FSK; IMDb blockt
  Server-Abrufe (HTTP 202 mit leerem Body), TMDB-Webseiten ebenfalls (403).
- **API-Key:** vorläufig der Key aus Kodis Scraper (`tmdbapi.py`), für Kodi ausgestellt. Vom Nutzer bewusst so
  gewählt, soll durch einen eigenen ersetzt werden (offen).
- **Nur `TMDB_LANGUAGE`** in der Konfiguration; FSK-Land, Bild- und Trailersprache werden daraus abgeleitet.
- **Default-Medium beim Import: DVD** (`IMPORT_DEFAULT_MEDIUM` in `import.php`).
- **Nicht übernommen:** TMDB-Schlüsselwörter als Tags (englisch, unsortiert), US-Altersfreigaben.

### Trailer

- **Eingebetteter YouTube-Player** (`youtube-nocookie.com`), der Link-Button wurde verworfen. Im Ausdruck ein
  QR-Code (so groß wie ein Darstellerfoto) mit dem Link daneben.
- **Kein Download der Trailer:** rechtlich heikel (YouTube-Nutzungsbedingungen, § 95a UrhG), 10–25 GB Speicher.
- **Reparatur "on the fly":** Die Detailansicht ruft `api/trailer.php` auf; der Server prüft per YouTube-oEmbed und
  ersetzt tote Trailer durch den aktuellen von TMDB (höchstens alle 7 Tage pro Film, Datum in `trailerChecked`).
  Damit braucht Hetzner kein Wartungsskript. `bin/check_trailers.php` bleibt nur als optionale lokale
  Komplettprüfung. Regionale Sperren erkennt oEmbed nicht.

### Abspielen (Medium USB)

- **`USB_ROOT = 'D:\videothek'`** in `config.php`; im Formular die Datei relativ dazu (Pflicht bei Medium USB, nur im
  Formular geprüft, damit Importe USB-Filme ohne Datei anlegen können).
- **Play-Button liefert eine `.m3u`-Wiedergabeliste**, die der lokale Player (VLC) öffnet. `file:///`-Links werden von
  allen Browsern auf http(s)-Seiten blockiert. Ein eigenes Link-Protokoll (`videothek:`) wurde besprochen und
  vom Nutzer abgelehnt ("so lassen").
- **Kein Abspielen im Browser:** Automatisches Durchsuchen von USB-Laufwerken ist Webseiten nicht möglich; die
  meisten Dateien (AVI/Xvid, FLV, MKV mit AC3/DTS) spielt kein Browser ab. Ein Test mit `2012` (AVI → MP4 per
  ffmpeg, 9,5 min für 2,5 h Film) ergab eine **asynchrone Tonspur**; der Ansatz (lokaler Apache-Alias +
  HTML5-Player) wurde daraufhin **komplett verworfen und zurückgebaut**. Für Abspielen im Browser/auf dem Fernseher
  wäre Jellyfin die naheliegende Lösung (nur besprochen, nicht umgesetzt).

### Darstellung

- Dunkles Kino-Farbschema, Druck hell und tintensparend; im Druck nur die ersten 6 Darsteller.
- Filter nach Genre und Medium, Sortierung alphabetisch, nach Jahr, nach Hinzufügedatum.

## 4. Was mit der Kodi-Sammlung passiert ist

1. Import der 289 Filme aus den Kodi-NFOs in `D:\Filme` (ohne TMDB-Abruf), Medium zunächst "Digital", später
   "USB". Vier NFOs waren gemischt kodiert (UTF-8 + DOS-Codepage 850) und wurden zeichenweise repariert.
   "The Rocky Horror Picture Show (mit Untertitel)" ist als eigener Eintrag drin (gleiche TMDB-ID wie die normale
   Fassung). Genre "Romance" bei "Vicky Cristina Barcelona" auf "Liebesfilm" korrigiert.
2. Trailer-Prüfung: 26 tote ersetzt, 6 ergänzt, 2 entfernt ("Der Allergrösste bin ich", "Jud Süß").
3. Filmdateien nach `D:\videothek\<slug>\movie.<ext>` verschoben (über mehrere Zwischenstände, zuletzt per
   Umbenennen; alle 303 Pfade gegen die Platte geprüft).
4. Kodi wird nicht mehr verwendet. Import- und Verschiebeskripte (`bin/import_kodi.php`, `bin/move_to_usb.php`,
   `bin/lib/kodi_nfo.php`), das Protokoll und das Migrationsskript wurden danach entfernt.

**Vorfall:** Beim Testen des Default-Mediums wurde "Zurück in die Zukunft" versehentlich gelöscht (Test-Import mit
einer bereits vorhandenen TMDB-ID, danach ungeprüftes Aufräumen) und sofort per Re-Import wiederhergestellt.
Eventuelle manuelle Änderungen an diesem Film gingen dabei verloren. Für den heutigen Bestand spielt das keine Rolle
mehr: Der damalige Testbestand wurde vom Nutzer nach `data/_movies` verschoben und beim Aufräumen gelöscht; der
Film ist aktuell nicht in der Videothek.

## 5. Offene Punkte

- [ ] **Eigenen TMDB-API-Key** in `config.php` eintragen (themoviedb.org → Einstellungen → API).
- [ ] **Abgleich lokal ↔ Hetzner klären:** Alle Änderungen dieser Sitzung an den Filmdaten (Kodi-Import,
      Medium "USB", `files`, reparierte Trailer, Genre-Korrektur) liegen bisher nur lokal in `data/movies`. Ob und
      wie sie auf Hetzner übertragen werden (Upload von `data/movies`?), ist noch nicht festgelegt. Achtung:
      `data/` ist gitignored, kommt also nicht über Git auf den Server.
- [ ] **Code committen und deployen:** Nach "first commit" sind noch Änderungen offen (u. a. Trailer-Reparatur beim
      Öffnen: `api/trailer.php`, `api/lib/trailer_check.php`).
- [ ] **`D:\Filme` löschen** (nur noch Kodi-Metadaten, 382 MB; die Videothek hat eigene Kopien).
- [ ] **"Der Fall Barschel" (2015):** TMDB kennt die gespeicherte TMDB-ID nicht mehr (HTTP 404) – ggf. per Import neu
      zuordnen; aktuell ohne Trailer.
- [ ] **curl unter XAMPP-Apache:** Im `php_error_log` stand zuletzt "Unable to load dynamic library
      'php_curl.dll'"; der Nutzer meint, curl sei vorhanden. Per `public/dev/info.php` prüfen. Ohne curl greift der
      Fallback `file_get_contents()` (funktioniert, nur langsamer).
- [ ] **`public/dev/info.php`** nach dem Deploy auf Hetzner wieder löschen (siehe README).
- [ ] Optional: Darstellerfotos werden beim Löschen eines Films mitgelöscht, beim Umbenennen eines Darstellers im
      Formular aber nicht aufgeräumt (verwaiste Datei unter `actors/`).

## 6. Hinweise für die Weiterarbeit

- **Tests:** Es gibt keine automatisierten Tests. Geprüft wurde jeweils mit PHPs eingebautem Server
  (`php -S 127.0.0.1:8098` im Ordner `public/`), `curl` gegen die API und jsdom für das Frontend. Im echten
  Browser bestätigt hat der Nutzer bisher den Play-Button (`.m3u` öffnet den Player); Druckansicht, QR-Code-Scan
  und die Trailer-Reparatur beim Öffnen sind dort noch nicht ausdrücklich geprüft.
- **Daten schreiben nur über die Funktionen in `crud.php`** (`write_movie_json()` hält Feldreihenfolge und Schema
  ein).
- **Vor jedem Löschen prüfen**, dass der Datensatz wirklich vom eigenen Test stammt (siehe Vorfall oben).
- **Markdown-Dateien** bei ca. 110–120 Zeichen umbrechen (Wunsch des Nutzers).

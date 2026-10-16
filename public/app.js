/**
 * @typedef {Object} CastMember
 * @property {string} name
 * @property {?string} role
 * @property {?string} profile  TMDB-Porträtpfad (Quelle für den Download durch api/actors.php)
 * @property {?string} thumb    heruntergeladenes Foto, z. B. "Sean_Astin.jpg" (data/movies/<slug>/actors/)
 */

/**
 * @typedef {Object} Episode
 * @property {number} number
 * @property {?string} title
 * @property {?string} plot
 */

/**
 * @typedef {Object} Season
 * @property {number} number     0 = Specials
 * @property {?string} name      z. B. "Staffel 1" (TMDB); leer -> seasonLabel()
 * @property {?number} year
 * @property {Episode[]} episodes
 * @property {string[]} medium   leer = Staffel ist nicht im eigenen Bestand
 * @property {string[]} files    nur bei Medium USB: Folgendateien relativ zu USB_ROOT, üblich <slug>/S01/E01.<ext>
 */

/**
 * Ein Film oder eine Serie (type). Filme haben director/medium/files, Serien creator/yearEnd/seasons.
 * @typedef {Object} Movie
 * @property {'movie'|'series'} type
 * @property {string} slug
 * @property {string} title
 * @property {?string} originalTitle
 * @property {?number} year      bei Serien das Jahr der Erstausstrahlung
 * @property {?number} [yearEnd] nur Serien: letztes Jahr, falls abgeschlossen
 * @property {string[]} genres
 * @property {?number} runtime   Laufzeit in Minuten (bei Serien pro Folge)
 * @property {?string} fsk       "0", "6", "12", "16" oder "18"
 * @property {?string} [director] nur Filme
 * @property {?string} [creator]  nur Serien ("Idee")
 * @property {CastMember[]} cast
 * @property {string[]} [medium]  nur Filme, z. B. ["DVD"] oder ["DVD", "USB"], wenn der Film mehrfach vorhanden ist
 * @property {string[]} [files]   nur Filme mit Medium USB: Filmdatei(en) relativ zu USB_ROOT (config.php)
 * @property {Season[]} [seasons] nur Serien
 * @property {?string} trailer  YouTube-URL
 * @property {?string} plot
 * @property {?string} notes
 * @property {{tmdb?: string, imdb?: string}} uniqueids
 * @property {?string} poster
 * @property {?string} thumb
 * @property {?string} fanart   Hintergrundbild (Kodi-Begriff)
 * @property {string} created
 * @property {string} [import_warning]
 */

/**
 * Ein Treffer von api/search.php.
 * @typedef {Object} SearchResult
 * @property {'movie'|'series'} type
 * @property {number} tmdb
 * @property {string} title
 * @property {?string} originalTitle
 * @property {?number} year
 * @property {?string} overview
 * @property {?string} poster    Plakat-URL bei TMDB
 * @property {?string} existing  Slug, falls schon in der Videothek
 */

/**
 * Antwort von api/actors.php.
 * @typedef {Object} ActorsResult
 * @property {Movie} movie
 * @property {number} loaded
 * @property {number} failed
 * @property {number} remaining
 */

/** @type {Movie[]} Filme und Serien */
let MOVIES = [];
// `true`, während openEditForm()/openImportForm() offen ist (ungespeicherte Eingaben sind möglich).
// Verhindert, dass ein Klick neben das Formular oder Escape die Eingaben versehentlich verwirft
// (siehe overlay-Klick-Handler und keydown-Handler weiter unten) — nur die expliziten Buttons
// "Abbrechen"/"×"/"Speichern" schließen das Formular dann noch.
let formIsOpen = false;

const grid = document.getElementById('grid');
const overlay = document.getElementById('overlay');
const sheet = document.getElementById('sheet');
const searchInput = document.getElementById('search');
const sortSel = document.getElementById('sortSel');
const typeSel = document.getElementById('typeSel');
const genreSel = document.getElementById('genreSel');
const mediumSel = document.getElementById('mediumSel');
const resultCount = document.getElementById('resultCount');
const subCount = document.getElementById('subCount');
const newMovieBtn = document.getElementById('newMovieBtn');
const importMovieBtn = document.getElementById('importMovieBtn');

const TOKEN_KEY = 'videothek_api_token';

// Vorschlagslisten im Formular; freie Eingaben bleiben trotzdem möglich.

const MEDIUM_OPTIONS = ['DVD', 'Blu-ray', 'USB'];
const FSK_OPTIONS = ['0', '6', '12', '16', '18'];

function escapeHtml(str){
  return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/**
 * Wie slugify() in api/lib/slug.php: Umlaute/Akzente umschreiben, kleinschreiben, alles außer a-z0-9 zu "-".
 * Nur für Vorschläge im Formular; den echten Slug vergibt der Server (ggf. mit -2, -3, … bei Kollision).
 */
function slugify(title){
  const map = {'ä':'ae','ö':'oe','ü':'ue','Ä':'Ae','Ö':'Oe','Ü':'Ue','ß':'ss','é':'e','è':'e','ê':'e','à':'a','â':'a','ô':'o','î':'i','ç':'c','ñ':'n'};
  return title.trim().replace(/[äöüÄÖÜßéèêàâôîçñ]/g, c => map[c]).toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

/** @param {Movie} m */
function isSeries(m){
  return m.type === 'series';
}

/** "Film" / "Serie" (für Überschriften und Meldungen). @param {'movie'|'series'} type */
function typeLabel(type){
  return type === 'series' ? 'Serie' : 'Film';
}

/** "1 Film" / "3 Filme", "1 Serie" / "2 Serien" usw. */
function countLabel(n, singular, plural){
  return `${n} ${n === 1 ? singular : plural}`;
}

/**
 * Medien eines Eintrags: beim Film sein Medium, bei einer Serie alle Medien ihrer Staffeln (für Karte, Filter, Suche).
 * @param {Movie} m
 * @returns {string[]}
 */
function mediaOf(m){
  return isSeries(m) ? [...new Set(m.seasons.flatMap(s => s.medium))] : m.medium;
}

/** Reguläre Staffeln (ohne Specials, Staffel 0). @param {Movie} m */
function regularSeasons(m){
  return m.seasons.filter(s => s.number > 0);
}

/** @param {Season} s */
function seasonLabel(s){
  return s.name || (s.number === 0 ? 'Specials' : `Staffel ${s.number}`);
}

/** "2008", bei abgeschlossenen Serien "2008–2013". @param {Movie} m */
function yearsText(m){
  return isSeries(m) && m.year && m.yearEnd && m.yearEnd !== m.year ? `${m.year}–${m.yearEnd}` : m.year;
}

/** Zweistellig mit führender Null (Staffel-/Folgennummern in Dateinamen: S01E03). */
function pad2(n){
  return String(n).padStart(2, '0');
}

/**
 * Dateien einer Folge innerhalb von season.files – wie season_episode_files() in api/lib/crud.php: Zuordnung über
 * die Folgennummer im Dateinamen ("E03", "S01E03", "e3", mehrteilig "E03-cd1", "E03-cd2"); trägt keine Datei der
 * Staffel eine solche Nummer, zählt die Position (1. Datei = 1. Folge der Liste).
 * @param {Season} season
 * @param {number} episode
 * @returns {string[]}
 */
function episodeFiles(season, episode){
  const pattern = /(?:^|[^a-z0-9])(?:s\d+)?e0*(\d+)(?!\d)/i;
  const numbered = season.files
    .map(f => [f.split('/').pop().match(pattern), f])
    .filter(([match]) => match);
  if(numbered.length){
    return numbered.filter(([match]) => parseInt(match[1], 10) === episode).map(([, f]) => f);
  }
  const pos = season.episodes.findIndex(e => e.number === episode);
  return pos >= 0 && season.files[pos] ? [season.files[pos]] : [];
}

/** @param {Season} season */
function seasonPlayable(season){
  return season.medium.includes('USB') && season.files.length > 0;
}

/**
 * Ergänzt Felder, die ältere movie.json-Dateien (vor Serien bzw. Dateien) noch nicht haben, damit der übrige Code
 * sich auf die Listen verlassen kann. Für jede Antwort des Servers, die in MOVIES landet.
 * @param {Movie} m
 * @returns {Movie}
 */
function withDefaults(m){
  m.type = m.type === 'series' ? 'series' : 'movie';
  m.genres = m.genres || [];
  m.cast = m.cast || [];
  if(isSeries(m)){
    m.seasons = (m.seasons || []).map(/** @param {Season} s @returns {Season} */ s => ({
      ...s, episodes: s.episodes || [], medium: s.medium || [], files: s.files || [],
    }));
  } else {
    m.medium = m.medium || [];
    m.files = m.files || [];
  }
  return m;
}

// PHP liefert die Liste der Filme und Serien über die JSON-API (api/movies.php).
/** @returns {Promise<Movie[]>} */
async function loadMovies(){
  /** @type {{movies: Movie[]}} */
  const data = await fetch('api/movies.php').then(res => res.json());
  return data.movies.map(withDefaults);
}

function getStoredToken(){
  return localStorage.getItem(TOKEN_KEY) || '';
}

function forgetToken(){
  localStorage.removeItem(TOKEN_KEY);
}

/** Holt das gespeicherte API-Token oder fragt es einmalig per prompt() ab und merkt es sich in localStorage. */
function requireToken(){
  let token = getStoredToken();
  if(!token){
    token = (window.prompt('API-Token für Änderungen eingeben:') || '').trim();
    if(token) localStorage.setItem(TOKEN_KEY, token);
  }
  return token;
}

/**
 * fetch()-Wrapper für schreibende API-Aufrufe: hängt das Token an, wirft bei
 * 401 (und vergisst dann das falsche Token) bzw. anderen Fehlerstatus mit der
 * Server-Fehlermeldung als Error.
 */
async function apiWrite(url, options){
  const token = requireToken();
  if(!token){
    throw new Error('Ohne Token sind keine Änderungen möglich.');
  }
  options = options || {};
  options.headers = Object.assign({}, options.headers, {'X-API-Token': token});

  const res = await fetch(url, options);
  if(res.status === 401){
    forgetToken();
    throw new Error('Token wurde vom Server abgelehnt. Bitte erneut versuchen.');
  }
  const body = await res.json().catch(() => ({}));
  if(!res.ok){
    const msg = body.details ? body.details.join('; ') : (body.error || `HTTP ${res.status}`);
    throw new Error(msg);
  }
  return body;
}

// data/ liegt außerhalb des Document Root, Bilder laufen deshalb über api/image.php statt über eine statische URL.
/**
 * @param {Movie} m
 * @param {'poster'|'thumb'|'fanart'} type
 */
function movieImageSrc(m, type){
  return m[type] ? `api/image.php?slug=${encodeURIComponent(m.slug)}&type=${type}` : null;
}

/** @param {Movie} m */
function thumbSrc(m){
  return movieImageSrc(m, m.thumb ? 'thumb' : 'poster');
}

/**
 * @param {Movie} m
 * @param {CastMember} c
 */
function actorSrc(m, c){
  return c.thumb ? `api/image.php?slug=${encodeURIComponent(m.slug)}&actor=${encodeURIComponent(c.name)}` : null;
}

/** YouTube-Video-ID aus watch?v=…, youtu.be/… oder /embed/… — null, wenn es keine YouTube-URL ist. */
function youtubeId(url){
  const match = (url || '').match(/(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/)|youtu\.be\/)([\w-]{11})/);
  return match ? match[1] : null;
}

/**
 * Film: "1985 · 114 Min. · FSK 12", Serie: "2008–2013 · 5 Staffeln · 48 Min./Folge · FSK 16" (mit $long zusätzlich
 * die Zahl der Folgen) — leere Angaben fallen weg.
 * @param {Movie} m
 * @param {boolean} [long]
 */
function metaLine(m, long = false){
  if(isSeries(m)){
    const seasons = regularSeasons(m);
    const episodes = seasons.reduce((sum, s) => sum + s.episodes.length, 0);
    return [
      yearsText(m),
      seasons.length ? countLabel(seasons.length, 'Staffel', 'Staffeln') : null,
      long && episodes ? countLabel(episodes, 'Folge', 'Folgen') : null,
      m.runtime ? `${m.runtime} Min./Folge` : null,
      m.fsk ? `FSK ${m.fsk}` : null,
    ].filter(Boolean).join(' · ');
  }
  return [
    m.year,
    m.runtime ? `${m.runtime} Min.` : null,
    m.fsk ? `FSK ${m.fsk}` : null,
  ].filter(Boolean).join(' · ');
}

/**
 * @param {Movie} m
 * @param {number} idx
 */
function cardHtml(m, idx){
  const src = thumbSrc(m);
  const img = src ? `<img src="${escapeHtml(src)}" alt="${escapeHtml(m.title)}" loading="lazy">` : '<span>kein Cover</span>';
  return `<div class="card" data-idx="${idx}">
    <div class="thumb">${img}${isSeries(m) ? '<div class="type-badge">Serie</div>' : ''}</div>
    <div class="body">
      <h3>${escapeHtml(m.title)}</h3>
      <div class="meta">${escapeHtml(metaLine(m))}</div>
      ${m.genres.slice(0, 2).map(g => `<div class="cat-tag">${escapeHtml(g)}</div>`).join('')}
      ${mediaOf(m).map(md => `<div class="cat-tag medium-tag">${escapeHtml(md)}</div>`).join('')}
    </div>
  </div>`;
}

/**
 * Alle bereits verwendeten Werte (aus dem aktuellen MOVIES-Stand), alphabetisch, ohne Duplikate.
 * @param {function(Movie): (string[]|string|null)} valuesOf  z. B. m => m.genres oder mediaOf
 */
function knownValues(valuesOf){
  return [...new Set(MOVIES.flatMap(valuesOf).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'de'));
}

/**
 * Baut die Optionen der Filter (Genre, Medium) aus dem aktuellen MOVIES-Stand neu auf (nötig nach jedem
 * Anlegen/Bearbeiten/Löschen/Import, da dabei Werte hinzukommen oder wegfallen können).
 * Behält die aktuelle Auswahl bei, falls sie noch existiert, sonst wird sie auf "Alle ..."
 * zurückgesetzt — eine Auswahl auf einen verschwundenen Wert würde sonst zu einem leeren,
 * unerklärten Ergebnis führen.
 *
 * Bekannter Fehler bei Microsoft Edge für Android (Version 140.0.3485.98):
 * Die Vorschlagsliste (datalist) erscheint und funktioniert, aber alle Optionen werden als leer angezeigt.
 * Stand 13. Okt. 2025: keine offizielle Lösung
 * https://learn.microsoft.com/en-us/answers/questions/5583325/datalist-tag-not-properly-rendered-on-microsoft-ed
 */
function refreshFilters(){
  const fill = (select, valuesOf, allLabel) => {
    const values = knownValues(valuesOf);
    const current = select.value;
    select.innerHTML = `<option value="">${allLabel}</option>` + values.map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');
    select.value = values.includes(current) ? current : '';
  };
  fill(genreSel, m => m.genres, 'Alle Genres');
  fill(mediumSel, mediaOf, 'Alle Medien');
}

/** Aktualisiert die Gesamtanzahl im Header (nötig nach jedem Anlegen/Löschen/Import). */
function updateHeaderCount(){
  const series = MOVIES.filter(isSeries).length;
  const movies = MOVIES.length - series;
  subCount.textContent = series
    ? `${countLabel(movies, 'Film', 'Filme')} · ${countLabel(series, 'Serie', 'Serien')}`
    : countLabel(movies, 'Film', 'Filme');
}

/**
 * Zeitstempel von m.created als Zahl für Sortierung. Fehlt/ungültig -> 0 (sortiert als ältestes).
 * @param {Movie} m
 */
function createdTime(m){
  const t = m.created ? new Date(m.created).getTime() : NaN;
  return isNaN(t) ? 0 : t;
}

/** Sortiert Einträge ohne Wert für `key` ans Ende, sonst nach `compare`, bei Gleichstand alphabetisch nach Titel. */
function sortWithMissingLast(items, key, compare){
  items.sort((a, b) => {
    const va = a.m[key], vb = b.m[key];
    const byTitle = a.m.title.localeCompare(b.m.title, 'de');
    if(va == null && vb == null) return byTitle;
    if(va == null) return 1;
    if(vb == null) return -1;
    return compare(va, vb) || byTitle;
  });
}

function renderGrid(){
  const q = searchInput.value.trim().toLowerCase();
  const type = typeSel.value;
  const genre = genreSel.value;
  const medium = mediumSel.value;
  let items = MOVIES.map((m, i) => ({m, i}));

  if(type){
    items = items.filter(({m}) => m.type === type);
  }
  if(genre){
    items = items.filter(({m}) => m.genres.includes(genre));
  }
  if(medium){
    items = items.filter(({m}) => mediaOf(m).includes(medium));
  }

  if(q){
    // Bei Serien zählen auch die Folgentitel ("Felina" findet Breaking Bad).
    const episodeTitles = m => isSeries(m) ? m.seasons.flatMap(s => s.episodes.map(e => e.title)) : [];
    items = items.filter(({m}) =>
      [m.title, m.originalTitle, m.director, m.creator, m.plot, m.year && String(m.year), ...m.genres, ...mediaOf(m), ...episodeTitles(m)]
        .some(v => (v || '').toLowerCase().includes(q))
      || m.cast.some(c => c.name.toLowerCase().includes(q) || (c.role || '').toLowerCase().includes(q))
    );
  }

  switch(sortSel.value){
    case 'title':
      items.sort((a,b) => a.m.title.localeCompare(b.m.title, 'de'));
      break;
    case 'year-desc':
      sortWithMissingLast(items, 'year', (x, y) => y - x);
      break;
    case 'year-asc':
      sortWithMissingLast(items, 'year', (x, y) => x - y);
      break;
    case 'created-asc':
      items.sort((a,b) => createdTime(a.m) - createdTime(b.m));
      break;
    case 'created-desc':
    default:
      items.sort((a,b) => createdTime(b.m) - createdTime(a.m));
      break;
  }

  resultCount.textContent = `${items.length} von ${MOVIES.length} ${MOVIES.length === 1 ? 'Titel' : 'Titeln'}`;
  grid.innerHTML = items.length
    ? items.map(({m,i}) => cardHtml(m, i)).join('')
    : '<p class="empty">Nichts gefunden.</p>';
}

/** Textblock mit Überschrift; jede Zeile wird ein eigener Absatz. Leer -> ''. */
function paragraphsHtml(heading, text, cssClass){
  const lines = text ? text.split('\n').map(s => s.trim()).filter(Boolean) : [];
  return lines.length
    ? `<h4>${heading}</h4><div class="${cssClass}">${lines.map(l => `<p>${escapeHtml(l)}</p>`).join('')}</div>`
    : '';
}

/**
 * Darstellerliste mit Fotos, vollständig (nicht gekürzt): Sie scrollt in einem eigenen Bereich, die
 * Fotos lädt der Browser per loading="lazy" erst, wenn sie in den sichtbaren Bereich kommen.
 * @param {Movie} m
 */
function castHtml(m){
  if(!m.cast.length) return '';
  const items = m.cast.map(c => {
    const src = actorSrc(m, c);
    const initials = c.name.split(/\s+/).map(w => w[0]).join('').slice(0, 2);
    const photo = src
      ? `<img src="${escapeHtml(src)}" alt="" loading="lazy">`
      : `<span>${escapeHtml(initials)}</span>`;
    return `<li><div class="actor-photo">${photo}</div><div class="actor-name">${escapeHtml(c.name)}</div>${c.role ? `<div class="actor-role">${escapeHtml(c.role)}</div>` : ''}</li>`;
  }).join('');
  return `<h4>Darsteller (${m.cast.length})</h4><ul class="cast-list">${items}</ul>`;
}

/**
 * Trailer: am Bildschirm als eingebetteter Player (youtube-nocookie.com), im Ausdruck stattdessen als
 * QR-Code zum Abscannen mit dem Handy (`public/vendor/qrcode.js`). Keine YouTube-URL -> nur der QR-Code.
 * @param {Movie} m
 */
function trailerHtml(m){
  if(!m.trailer) return '';
  const id = youtubeId(m.trailer);
  const player = id
    ? `<div class="trailer-player screen-only"><iframe src="https://www.youtube-nocookie.com/embed/${escapeHtml(id)}" title="Trailer" allow="encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe></div>`
    : '';
  return `<div class="trailer"><h4>Trailer</h4>${player}${trailerQrHtml(m.trailer)}</div>`;
}

/** QR-Code (SVG) mit der Trailer-URL daneben, sichtbar nur im Ausdruck. Leer, falls die Bibliothek fehlt. */
function trailerQrHtml(url){
  if(typeof qrcode !== 'function') return '';
  const qr = qrcode(0, 'M'); // 0 = Größe automatisch, M = mittlere Fehlerkorrektur
  qr.addData(url);
  qr.make();
  return `<div class="trailer-qr print-only">${qr.createSvgTag({cellSize: 3, margin: 0, scalable: true})}<div class="trailer-url">${escapeHtml(url)}</div></div>`;
}

/**
 * Baut den Inhalt, der sowohl in der Lese-Ansicht als auch beim Drucken sichtbar ist
 * (Poster/Titel, Eckdaten, Handlung, Darsteller, Notizen — ohne Toolbar/Schließen-Button).
 * @param {Movie} m
 */
function printableSheetHtml(m){
  const src = movieImageSrc(m, 'poster');
  const imgHtml = src
    ? `<div class="hero-img"><div class="ratio-box"><img src="${escapeHtml(src)}" alt="${escapeHtml(m.title)}"></div></div>`
    : '';

  const facts = [
    ['Genre', m.genres.join(', ')],
    isSeries(m) ? ['Idee', m.creator] : ['Regie', m.director],
    ['Medium', mediaOf(m).join(', ')],
  ].filter(([, v]) => v);
  const factsHtml = facts.length
    ? `<dl class="facts">${facts.map(([k, v]) => `<dt>${k}</dt><dd>${escapeHtml(v)}</dd>`).join('')}</dl>`
    : '';

  const createdDate = m.created ? new Date(m.created) : null;
  const createdHtml = createdDate && !isNaN(createdDate.getTime())
    ? `<div class="created-date">Hinzugefügt am ${escapeHtml(createdDate.toLocaleDateString('de-DE'))}</div>`
    : '';

  return `
    <div class="hero">
      ${imgHtml}
      <div class="titles">
        <h2>${escapeHtml(m.title)}</h2>
        ${m.originalTitle ? `<div class="original-title">${escapeHtml(m.originalTitle)}</div>` : ''}
        <div class="meta-line">${escapeHtml(metaLine(m, true))}</div>
        ${factsHtml}
        ${createdHtml}
      </div>
    </div>
    ${paragraphsHtml('Handlung', m.plot, 'plot')}
    ${isSeries(m) ? seasonsHtml(m) : ''}
    <div id="trailerSlot">${trailerHtml(m)}</div>
    ${castHtml(m)}
    ${paragraphsHtml('Notizen', m.notes, 'notes')}
  `;
}

/**
 * Staffeln einer Serie, jede aufklappbar mit ihren Folgen (Titel, Handlung). Im Bestand: Medium der Staffel und –
 * bei USB mit Dateien – Buttons zum Abspielen der ganzen Staffel bzw. einzelner Folgen. Nicht im Bestand: abgeblendet.
 * Im Ausdruck erscheint nur die zugeklappte Übersicht (eine Zeile pro Staffel).
 * @param {Movie} m
 */
function seasonsHtml(m){
  if(!m.seasons.length) return '';
  const owned = m.seasons.filter(s => s.medium.length).length;
  const playUrl = (s, e) => `api/play.php?slug=${encodeURIComponent(m.slug)}&season=${s.number}${e != null ? `&episode=${e}` : ''}`;
  const items = m.seasons.map(s => {
    const playable = seasonPlayable(s);
    const episodes = s.episodes.map(e => {
      const play = playable && episodeFiles(s, e.number).length
        ? `<a class="ep-play screen-only" href="${playUrl(s, e.number)}" title="Folge abspielen" aria-label="Folge ${e.number} abspielen">&#9654;</a>`
        : '';
      return `<li>
        <span class="ep-no">${e.number}</span>
        <div class="ep-body">
          <div class="ep-title">${escapeHtml(e.title || `Folge ${e.number}`)}${play}</div>
          ${e.plot ? `<div class="ep-plot">${escapeHtml(e.plot)}</div>` : ''}
        </div>
      </li>`;
    }).join('');
    const meta = [s.year, s.episodes.length ? countLabel(s.episodes.length, 'Folge', 'Folgen') : null].filter(Boolean).join(' · ');
    return `<details class="season${s.medium.length ? '' : ' missing'}">
      <summary>
        <span class="season-name">${escapeHtml(seasonLabel(s))}</span>
        <span class="season-meta">${escapeHtml(meta)}</span>
        <span class="season-media">${s.medium.length ? escapeHtml(s.medium.join(', ')) : 'nicht vorhanden'}</span>
      </summary>
      <div class="season-body">
        ${playable ? `<a class="btn btn-primary play-btn screen-only" href="${playUrl(s)}" title="Öffnet alle Folgen der Staffel im installierten Player">&#9654; ${escapeHtml(seasonLabel(s))} abspielen</a>` : ''}
        ${episodes ? `<ol class="episodes">${episodes}</ol>` : '<p class="ep-none">Keine Folgen eingetragen.</p>'}
      </div>
    </details>`;
  }).join('');
  return `<h4>Staffeln (${owned} von ${m.seasons.length} im Bestand)</h4><div class="seasons">${items}</div>`;
}

function openMovie(idx){
  const m = MOVIES[idx];
  formIsOpen = false;

  // Hintergrundbild (fanart) als abgedunkeltes Band hinter dem Kopfbereich, nicht im Druck.
  const fanart = movieImageSrc(m, 'fanart');
  sheet.innerHTML = `
    ${fanart ? `<div class="fanart" style="background-image:url('${escapeHtml(fanart)}')"></div>` : ''}
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <div class="btn-row toolbar">
      ${!isSeries(m) && m.medium.includes('USB') && m.files.length ? `<a class="btn btn-primary play-btn" href="api/play.php?slug=${encodeURIComponent(m.slug)}" title="Öffnet die Filmdatei im installierten Player">&#9654; Abspielen</a>` : ''}
      <button class="btn" id="printBtn" type="button">Drucken</button>
      <button class="btn" id="editBtn" type="button">Bearbeiten</button>
      <button class="btn btn-danger" id="deleteBtn" type="button">Löschen</button>
    </div>
    ${printableSheetHtml(m)}
  `;
  overlay.classList.add('open');
  document.getElementById('closeBtn').addEventListener('click', closeMovie);
  document.getElementById('printBtn').addEventListener('click', () => window.print());
  document.getElementById('editBtn').addEventListener('click', () => openEditForm(m));
  document.getElementById('deleteBtn').addEventListener('click', () => deleteMovieUI(m));
  void refreshTrailer(m);
}

/**
 * Lässt den Server den Trailer prüfen (api/trailer.php: höchstens alle paar Tage wirklich bei YouTube/TMDB)
 * und tauscht den Player aus, falls ein toter Trailer ersetzt, ergänzt oder entfernt wurde. Ein eingebetteter
 * YouTube-Player verrät dem Browser nicht, ob das Video fehlt – deshalb die Prüfung auf dem Server.
 * Fehler bleiben still: Dann bleibt einfach der bisherige Trailer stehen.
 * @param {Movie} m
 */
async function refreshTrailer(m){
  if(!m.trailer && !(m.uniqueids && m.uniqueids.tmdb)) return; // nichts zu prüfen, nichts nachzuholen
  let data;
  try {
    const res = await fetch(`api/trailer.php?slug=${encodeURIComponent(m.slug)}`);
    if(!res.ok) return;
    data = await res.json();
  } catch(err){
    return;
  }
  if(data.trailer === m.trailer) return;
  m.trailer = data.trailer;
  const slot = document.getElementById('trailerSlot');
  // Nur austauschen, wenn noch dieselbe Detailansicht offen ist (nicht inzwischen Formular oder anderer Film).
  if(slot && !formIsOpen && document.querySelector('.sheet h2')?.textContent === m.title){
    slot.innerHTML = trailerHtml(m);
  }
}

function closeMovie(){
  formIsOpen = false;
  overlay.classList.remove('open');
  sheet.innerHTML = '';
}

/**
 * Zeigt das Anlege-/Bearbeitungsformular für Filme und Serien. m === null bedeutet: neuer Eintrag (zunächst Film,
 * die Art lässt sich oben umschalten). Felder, die nur eine Art haben, stehen in .movie-only bzw. .series-only und
 * werden je nach gewählter Art ein- oder ausgeblendet; ihre Eingaben bleiben beim Umschalten erhalten, gespeichert
 * werden nur die der gewählten Art.
 * @param {?Movie} m
 */
function openEditForm(m){
  formIsOpen = true;
  const isEdit = m !== null;
  const type = isEdit ? m.type : 'movie';
  const val = field => isEdit ? escapeHtml(m[field] ?? '') : '';
  // Feste Medien plus alle schon verwendeten (frühere Freitexte bleiben so auswählbar).
  const mediumChoices = [...new Set([...MEDIUM_OPTIONS, ...knownValues(mediaOf)])];
  const castText = isEdit ? m.cast.map(c => c.role ? `${c.name} | ${c.role}` : c.name).join('\n') : '';
  /**
   * Bildfeld mit Vorschau (vorhandenes Bild bzw. nach Dateiauswahl die neue Datei).
   * @param {'poster'|'fanart'} kind
   * @param {string} label  Feldbeschriftung (HTML)
   * @param {string} hint   Hinweis unter dem Feld (HTML)
   */
  const imageFieldHtml = (kind, label, hint) => {
    const src = isEdit ? movieImageSrc(m, kind) : null;
    return `<div class="form-field image-field ${kind}-field">
      <label>${label}</label>
      <div class="form-preview" id="${kind}PreviewWrap"${src ? '' : ' style="display:none"'}><div class="ratio-box"><img id="${kind}Preview" src="${src ? escapeHtml(src) : ''}" alt=""></div></div>
      <input type="file" id="${kind}Input" accept="image/jpeg,image/png,image/webp">
      <div class="hint">${hint}</div>
    </div>`;
  };
  const seasons = isEdit && isSeries(m) ? m.seasons : [];

  sheet.innerHTML = `
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <div id="editForm">
    <h2 id="formTitle"></h2>
    <div id="formError"></div>
    <div class="form-field">
      <span class="label">Art</span>
      <div class="checkbox-row" id="typeInputs">
        <label><input type="radio" name="type" value="movie"${type === 'movie' ? ' checked' : ''}> Film</label>
        <label><input type="radio" name="type" value="series"${type === 'series' ? ' checked' : ''}> Serie</label>
      </div>
    </div>
    <div class="form-field">
      <label for="titleInput">Titel</label>
      <input type="text" id="titleInput" value="${val('title')}">
    </div>
    <div class="form-field">
      <label for="originalTitleInput">Originaltitel</label>
      <input type="text" id="originalTitleInput" value="${val('originalTitle')}">
    </div>
    <div class="form-row">
      <div class="form-field">
        <label for="yearInput" id="yearLabel">Jahr</label>
        <input type="text" id="yearInput" inputmode="numeric" value="${val('year')}" placeholder="z. B. 1985">
      </div>
      <div class="form-field series-only">
        <label for="yearEndInput">Bis (Jahr)</label>
        <input type="text" id="yearEndInput" inputmode="numeric" value="${val('yearEnd')}" placeholder="leer, falls sie noch läuft">
      </div>
      <div class="form-field">
        <label for="runtimeInput" id="runtimeLabel">Laufzeit (Min.)</label>
        <input type="text" id="runtimeInput" inputmode="numeric" value="${val('runtime')}">
      </div>
      <div class="form-field">
        <label for="fskInput">FSK</label>
        <input type="text" id="fskInput" list="fskList" autocomplete="off" value="${val('fsk')}">
        <datalist id="fskList">${FSK_OPTIONS.map(f => `<option value="${f}">`).join('')}</datalist>
      </div>
    </div>
    <div class="form-row">
      <div class="form-field">
        <label for="genresInput">Genres</label>
        <input type="text" id="genresInput" value="${isEdit ? escapeHtml(m.genres.join(', ')) : ''}" placeholder="z. B. Abenteuer, Komödie">
      </div>
      <div class="form-field movie-only">
        <label for="directorInput">Regie</label>
        <input type="text" id="directorInput" value="${val('director')}">
      </div>
      <div class="form-field series-only">
        <label for="creatorInput">Idee</label>
        <input type="text" id="creatorInput" value="${val('creator')}">
      </div>
    </div>
    <div class="media-block movie-only" id="movieMedia">
      <div class="form-field">
        <span class="label">Medium</span>
        <div class="checkbox-row media-choices">${mediaCheckboxesHtml(mediumChoices, isEdit && !isSeries(m) ? m.medium : [])}</div>
        <div class="hint">Mehrfachauswahl, wenn der Film z. B. als DVD und auf USB vorhanden ist.</div>
      </div>
      <div class="form-field files-field">
        <label for="filesInput">Filmdatei (relativ zum USB-Ordner)</label>
        <textarea id="filesInput" class="files-input" rows="2" placeholder="z. B. zimmer-1408-2007/movie.avi">${escapeHtml(isEdit && !isSeries(m) ? m.files.join('\n') : '')}</textarea>
        <div class="hint">Pfad relativ zu USB_ROOT aus config.php, üblich ist &lt;slug&gt;/movie.&lt;Endung&gt; (beim Anhaken von USB vorgeschlagen, Endung ergänzen). Mehrteilige Filme: eine Datei pro Zeile (movie-cd1.avi, movie-cd2.avi, …), in Abspielreihenfolge.</div>
      </div>
    </div>
    <div class="form-field series-only">
      <span class="label">Staffeln</span>
      <div id="seasonEditors">${seasons.map(s => seasonEditorHtml(s, mediumChoices)).join('')}</div>
      <button class="btn" id="addSeasonBtn" type="button">+ Staffel</button>
    </div>
    <div class="form-row">
      ${imageFieldHtml('poster', 'Poster', 'Hochformat. Wird erst nach dem Speichern hochgeladen und erzeugt automatisch ein Thumbnail.')}
      ${imageFieldHtml('fanart', 'Hintergrundbild', 'Querformat, erscheint abgedunkelt hinter dem Kopf der Detailansicht.')}
    </div>
    <div class="form-field">
      <label for="plotInput">Handlung</label>
      <textarea id="plotInput">${escapeHtml(isEdit ? (m.plot || '') : '')}</textarea>
    </div>
    <div class="form-field">
      <label for="trailerInput">Trailer (YouTube-URL)</label>
      <input type="text" id="trailerInput" value="${val('trailer')}" placeholder="https://www.youtube.com/watch?v=...">
    </div>
    <div class="form-field">
      <label for="castInput">Darsteller</label>
      <textarea id="castInput">${escapeHtml(castText)}</textarea>
      <div class="hint">Ein Darsteller pro Zeile, optional mit Rolle: "Sean Astin | Mikey".</div>
    </div>
    <div class="form-field">
      <label for="notesInput">Notizen</label>
      <textarea id="notesInput">${escapeHtml(isEdit ? (m.notes || '') : '')}</textarea>
      <div class="hint">Freier Text. In der Leseansicht wird jede Zeile als eigener Absatz dargestellt.</div>
    </div>
    <div class="btn-row">
      <button class="btn btn-primary" id="saveBtn" type="button">Speichern</button>
      <button class="btn" id="cancelBtn" type="button">Abbrechen</button>
    </div>
    </div>
  `;
  overlay.classList.add('open');

  const editForm = document.getElementById('editForm');
  const seasonEditors = document.getElementById('seasonEditors');
  const addSeason = () => {
    const numbers = [...seasonEditors.querySelectorAll('.se-number-input')].map(i => parseInt(i.value, 10)).filter(n => !isNaN(n));
    seasonEditors.insertAdjacentHTML('beforeend', seasonEditorHtml(
      {number: numbers.length ? Math.max(...numbers) + 1 : 1, name: null, year: null, episodes: [], medium: [], files: []},
      mediumChoices
    ));
    toggleFilesField(seasonEditors.lastElementChild);
  };
  // Art umschalten: Felder der anderen Art aus-, eigene einblenden; eine neue Serie beginnt mit Staffel 1.
  const applyType = () => {
    const t = formType();
    editForm.querySelectorAll('.movie-only').forEach(el => { el.hidden = t !== 'movie'; });
    editForm.querySelectorAll('.series-only').forEach(el => { el.hidden = t !== 'series'; });
    document.getElementById('formTitle').textContent = isEdit
      ? `${typeLabel(t)} bearbeiten`
      : (t === 'series' ? 'Neue Serie' : 'Neuer Film');
    document.getElementById('yearLabel').textContent = t === 'series' ? 'Von (Jahr)' : 'Jahr';
    document.getElementById('runtimeLabel').textContent = t === 'series' ? 'Folgenlänge (Min.)' : 'Laufzeit (Min.)';
    if(t === 'series' && !seasonEditors.children.length) addSeason();
  };

  document.getElementById('closeBtn').addEventListener('click', closeMovie);
  document.getElementById('cancelBtn').addEventListener('click', () => {
    isEdit ? openMovie(MOVIES.indexOf(m)) : closeMovie();
  });
  document.getElementById('typeInputs').addEventListener('change', applyType);
  document.getElementById('addSeasonBtn').addEventListener('click', addSeason);
  seasonEditors.addEventListener('click', e => {
    if(e.target.closest('.se-remove')) e.target.closest('.season-editor').remove();
  });
  // Medium angehakt/abgewählt (Film oder eine Staffel): Datei-Feld ein-/ausblenden, bei USB Dateien vorschlagen.
  // Am #editForm statt am dauerhaften #sheet, sonst käme bei jedem Öffnen ein weiterer Listener dazu.
  editForm.addEventListener('change', e => {
    const block = e.target.closest('.media-choices') && e.target.closest('.media-block');
    if(block){
      if(e.target.value === 'USB' && e.target.checked) suggestFiles(block);
      toggleFilesField(block);
    }
    if(e.target.classList.contains('files-input')) completeExtensions(e.target);
  });
  editForm.querySelectorAll('.media-block').forEach(toggleFilesField);
  applyType();

  for(const kind of ['poster', 'fanart']){
    document.getElementById(`${kind}Input`).addEventListener('change', e => {
      const file = e.target.files[0];
      if(!file) return;
      const reader = new FileReader();
      reader.onload = () => {
        document.getElementById(`${kind}PreviewWrap`).style.display = '';
        document.getElementById(`${kind}Preview`).src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  }
  document.getElementById('saveBtn').addEventListener('click', () => saveMovie(isEdit ? m.slug : null));
}

/** Im Formular gewählte Art. @returns {'movie'|'series'} */
function formType(){
  return document.querySelector('#typeInputs input:checked').value === 'series' ? 'series' : 'movie';
}

/** Checkboxen für die Medien, die angehakten aus $selected. */
function mediaCheckboxesHtml(choices, selected){
  return choices.map(md => `<label><input type="checkbox" value="${escapeHtml(md)}"${selected.includes(md) ? ' checked' : ''}> ${escapeHtml(md)}</label>`).join('');
}

let seasonEditorSeq = 0; // für eindeutige ids der Eingabefelder (label for=…)

/**
 * Eingabeblock einer Staffel im Formular. Die Folgen werden als Titel pro Zeile bearbeitet; ihre Nummern
 * (data-episode-numbers) bleiben beim Speichern über die Position erhalten (siehe parseEpisodes()).
 * @param {Season} s
 * @param {string[]} mediumChoices
 */
function seasonEditorHtml(s, mediumChoices){
  const k = ++seasonEditorSeq;
  const titles = s.episodes.map(e => e.title || '-').join('\n');
  return `<div class="season-editor media-block" data-episode-numbers="${escapeHtml(JSON.stringify(s.episodes.map(e => e.number)))}">
    <div class="form-row">
      <div class="form-field se-number">
        <label for="se${k}Number">Staffel</label>
        <input type="text" id="se${k}Number" class="se-number-input" inputmode="numeric" value="${s.number}">
      </div>
      <div class="form-field se-name">
        <label for="se${k}Name">Bezeichnung</label>
        <input type="text" id="se${k}Name" class="se-name-input" value="${escapeHtml(s.name || '')}" placeholder="${escapeHtml(seasonLabel({...s, name: null}))}">
      </div>
      <div class="form-field se-year">
        <label for="se${k}Year">Jahr</label>
        <input type="text" id="se${k}Year" class="se-year-input" inputmode="numeric" value="${s.year ?? ''}">
      </div>
      <button class="btn se-remove" type="button" title="Staffel aus der Liste entfernen">Entfernen</button>
    </div>
    <div class="form-field">
      <span class="label">Medium</span>
      <div class="checkbox-row media-choices">${mediaCheckboxesHtml(mediumChoices, s.medium)}</div>
      <div class="hint">Nichts angehakt = Staffel ist nicht im Bestand.</div>
    </div>
    <div class="form-field files-field">
      <label for="se${k}Files">Dateien (relativ zum USB-Ordner)</label>
      <textarea id="se${k}Files" class="files-input" rows="3">${escapeHtml(s.files.join('\n'))}</textarea>
      <div class="hint">Eine Datei pro Folge, üblich &lt;slug&gt;/S01/E01.&lt;Endung&gt; (beim Anhaken von USB vorgeschlagen; die Endung der ersten Zeile wird auf alle ohne Endung übertragen). Zugeordnet wird über die Folgennummer im Dateinamen (E01, S01E01), mehrteilige Folgen als E03-cd1, E03-cd2.</div>
    </div>
    <div class="form-field">
      <label for="se${k}Episodes">Folgen</label>
      <textarea id="se${k}Episodes" class="se-episodes-input" rows="4">${escapeHtml(titles)}</textarea>
      <div class="hint">Ein Titel pro Zeile, in der Reihenfolge der Folgen; „-“ für eine Folge ohne Titel.</div>
    </div>
  </div>`;
}

/** Datei-Feld eines Film- bzw. Staffelblocks nur bei Medium USB zeigen (eingetragene Dateien bleiben erhalten). */
function toggleFilesField(block){
  block.querySelector('.files-field').hidden = !checkedMedia(block).includes('USB');
}

/**
 * Beim Anhaken von USB ein leeres Datei-Feld mit dem üblichen Pfad vorbelegen: Film <slug>/movie., Staffel
 * <slug>/S01/E01., <slug>/S01/E02., … (eine Zeile pro Folge). Der Slug kommt aus dem aktuellen Titel + Jahr, so
 * wie der Server ihn vergibt; die Endung (avi, mkv, …) ist nicht bekannt und wird ergänzt (completeExtensions()).
 */
function suggestFiles(block){
  const filesInput = block.querySelector('.files-input');
  const title = document.getElementById('titleInput').value.trim();
  const year = document.getElementById('yearInput').value.trim();
  if(filesInput.value.trim() || !title) return;
  const slug = slugify(`${title} ${year}`);
  if(!block.classList.contains('season-editor')){
    filesInput.value = `${slug}/movie.`;
    return;
  }
  const season = parseInt(block.querySelector('.se-number-input').value, 10) || 1;
  const episodes = parseEpisodes(block).map(e => e.number);
  filesInput.value = (episodes.length ? episodes : [1]).map(e => `${slug}/S${pad2(season)}/E${pad2(e)}.`).join('\n');
}

/** Überträgt die Dateiendung der ersten Zeile mit Endung auf alle Zeilen, die noch auf "." enden (Vorschlag). */
function completeExtensions(textarea){
  const lines = textarea.value.split('\n');
  const ext = lines.map(l => l.trim().match(/\.([a-z0-9]+)$/i)).find(Boolean);
  if(!ext) return;
  textarea.value = lines.map(l => l.trim().endsWith('.') ? l.trim() + ext[1] : l).join('\n');
}

/** Im Film- bzw. Staffelblock angehakte Medien, in der Reihenfolge der Checkboxen. */
function checkedMedia(block){
  return [...block.querySelectorAll('.media-choices input:checked')].map(cb => cb.value);
}

/** Nicht leere Zeilen eines Textfelds, getrimmt. */
function textLines(value){
  return value.split('\n').map(s => s.trim()).filter(Boolean);
}

/**
 * Folgen eines Staffelblocks aus der Titelliste (eine Zeile pro Folge, "-" = ohne Titel). Die n-te Zeile behält die
 * Nummer der n-ten bisherigen Folge (data-episode-numbers), weitere Zeilen zählen ab der letzten Nummer weiter.
 * @returns {{number: number, title: ?string}[]}
 */
function parseEpisodes(block){
  const known = JSON.parse(block.dataset.episodeNumbers || '[]');
  const lines = block.querySelector('.se-episodes-input').value.split('\n').map(s => s.trim());
  while(lines.length && !lines[lines.length - 1]) lines.pop();
  let next = 1;
  return lines.map((line, i) => {
    const number = known[i] ?? next;
    next = number + 1;
    return {number, title: line && line !== '-' ? line : null};
  });
}

/** Liest die Formularfelder aus und baut daraus den API-Payload (je nach gewählter Art). */
function collectFormData(){
  const text = id => document.getElementById(id).value.trim();
  const number = v => v === '' ? null : (/^\d+$/.test(v) ? parseInt(v, 10) : v); // Ungültiges lehnt der Server mit Meldung ab
  const fsk = text('fskInput').replace(/^FSK\s*/i, '');
  const type = formType();

  const common = {
    type,
    title: text('titleInput'),
    originalTitle: text('originalTitleInput') || null,
    year: number(text('yearInput')),
    runtime: number(text('runtimeInput')),
    fsk: fsk || null,
    genres: text('genresInput').split(',').map(g => g.trim()).filter(Boolean),
    cast: text('castInput').split('\n').map(line => {
      const [name, ...role] = line.split('|');
      return {name: name.trim(), role: role.join('|').trim() || null};
    }).filter(c => c.name),
    trailer: text('trailerInput') || null,
    plot: text('plotInput') || null,
    notes: text('notesInput') || null,
  };

  if(type === 'series'){
    return {
      ...common,
      yearEnd: number(text('yearEndInput')),
      creator: text('creatorInput') || null,
      seasons: [...document.querySelectorAll('.season-editor')].map(block => {
        const medium = checkedMedia(block);
        return {
          number: number(block.querySelector('.se-number-input').value.trim()),
          name: block.querySelector('.se-name-input').value.trim() || null,
          year: number(block.querySelector('.se-year-input').value.trim()),
          episodes: parseEpisodes(block),
          medium,
          files: medium.includes('USB') ? textLines(block.querySelector('.files-input').value) : [],
        };
      }),
    };
  }

  const movieMedia = document.getElementById('movieMedia');
  const medium = checkedMedia(movieMedia);
  return {
    ...common,
    director: text('directorInput') || null,
    medium,
    files: medium.includes('USB') ? textLines(text('filesInput')) : [],
  };
}

async function saveMovie(slug){
  const payload = collectFormData();
  const errorBox = document.getElementById('formError');
  errorBox.innerHTML = '';
  const showError = msg => {
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(msg)}</div>`;
    errorBox.scrollIntoView({block: 'nearest'});
  };

  if(!payload.title){
    showError('Titel ist erforderlich.');
    return;
  }
  if(payload.type === 'series'){
    if(payload.seasons.some(s => s.number === null)){
      showError('Jede Staffel braucht eine Nummer (0 für Specials).');
      return;
    }
    const withoutFiles = payload.seasons.filter(s => s.medium.includes('USB') && !s.files.length);
    if(withoutFiles.length){
      showError(`Bei Medium USB bitte die Dateien (relativ zum USB-Ordner) angeben: ${withoutFiles.map(seasonLabel).join(', ')}.`);
      return;
    }
  } else if(payload.medium.includes('USB') && !payload.files.length){
    showError('Bei Medium USB bitte die Filmdatei (relativ zum USB-Ordner) angeben.');
    return;
  }
  // Fängt den nicht vervollständigten Vorschlag "<slug>/movie." bzw. "<slug>/S01/E01." ab.
  const files = payload.type === 'series' ? payload.seasons.flatMap(s => s.files) : payload.files;
  if(files.some(f => !/\.[a-z0-9]+$/i.test(f))){
    showError('Bei mindestens einer Datei fehlt die Dateiendung (z. B. .avi, .mkv, .mp4).');
    return;
  }

  const saveBtn = document.getElementById('saveBtn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Speichert …';

  /** @type {Movie} */
  let saved;
  try {
    const url = slug ? `api/movies.php?slug=${encodeURIComponent(slug)}` : 'api/movies.php';
    saved = await apiWrite(url, {
      method: slug ? 'PUT' : 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload),
    });
  } catch(err){
    showError(err.message);
    saveBtn.disabled = false;
    saveBtn.textContent = 'Speichern';
    return;
  }

  // Text-Teil ist ab hier gespeichert. Ein evtl. folgender Bild-Upload-Fehler
  // darf diesen Erfolg nicht mehr verschlucken (sonst wirkt es, als sei nichts gespeichert).
  const imageErrors = [];
  for(const [kind, label] of [['poster', 'Poster'], ['fanart', 'Hintergrundbild']]){
    const file = document.getElementById(`${kind}Input`).files[0];
    if(!file) continue;
    try {
      const fd = new FormData();
      fd.append('slug', saved.slug);
      fd.append('kind', kind);
      fd.append('image', file);
      saved = await apiWrite('api/upload.php', {method: 'POST', body: fd});
    } catch(err){
      imageErrors.push(`${label}: ${err.message}`);
    }
  }

  saved = withDefaults(saved);
  MOVIES = MOVIES.filter(x => x.slug !== slug && x.slug !== saved.slug);
  MOVIES.push(saved);
  refreshFilters();
  updateHeaderCount();
  renderGrid();
  openMovie(MOVIES.indexOf(saved));
  if(imageErrors.length){
    alert(`${typeLabel(saved.type)} gespeichert, aber Bild-Upload fehlgeschlagen:\n` + imageErrors.join('\n'));
  }
}

/** @param {Movie} m */
async function deleteMovieUI(m){
  if(!window.confirm(`"${m.title}" wirklich unwiderruflich löschen?`)) return;
  try {
    await apiWrite(`api/movies.php?slug=${encodeURIComponent(m.slug)}`, {method: 'DELETE'});
    MOVIES = MOVIES.filter(x => x.slug !== m.slug);
    refreshFilters();
    updateHeaderCount();
    closeMovie();
    renderGrid();
  } catch(err){
    alert('Löschen fehlgeschlagen: ' + err.message);
  }
}

grid.addEventListener('click', e => {
  const card = e.target.closest('.card');
  if(card) openMovie(parseInt(card.dataset.idx, 10));
});
overlay.addEventListener('click', e => {
  if(e.target === overlay && !formIsOpen) closeMovie();
});
document.addEventListener('keydown', e => {
  if(e.key === 'Escape' && !formIsOpen) closeMovie();
});
const VIEW_STATE_KEY = 'videothek_view_state';

/** Speichert Suchbegriff, Filter und Sortierung, damit sie einen Reload überstehen. */
function saveViewState(){
  localStorage.setItem(VIEW_STATE_KEY, JSON.stringify({
    search: searchInput.value,
    type: typeSel.value,
    genre: genreSel.value,
    medium: mediumSel.value,
    sort: sortSel.value,
  }));
}

function loadViewState(){
  try {
    return JSON.parse(localStorage.getItem(VIEW_STATE_KEY) || '{}');
  } catch(err) {
    return {};
  }
}

function onFilterChange(){
  saveViewState();
  renderGrid();
}

searchInput.addEventListener('input', onFilterChange);
sortSel.addEventListener('change', onFilterChange);
typeSel.addEventListener('change', onFilterChange);
genreSel.addEventListener('change', onFilterChange);
mediumSel.addEventListener('change', onFilterChange);
newMovieBtn.addEventListener('click', () => openEditForm(null));
importMovieBtn.addEventListener('click', () => openImportForm());

/**
 * Zeigt den Import-Dialog: Suche in TMDB nach Filmen und Serien per Titel (+ Jahr) oder IMDb-ID, ein Klick auf
 * einen Treffer importiert ihn.
 */
function openImportForm(){
  formIsOpen = true;
  sheet.innerHTML = `
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <h2>Film oder Serie importieren</h2>
    <div id="formError"></div>
    <div class="form-row">
      <div class="form-field search-query">
        <label for="importQueryInput">Titel oder IMDb-ID</label>
        <input type="text" id="importQueryInput" placeholder="z. B. Die Goonies, Breaking Bad oder tt0089218">
      </div>
      <div class="form-field search-year">
        <label for="importYearInput">Jahr (optional)</label>
        <input type="text" id="importYearInput" inputmode="numeric">
      </div>
    </div>
    <div class="btn-row">
      <button class="btn btn-primary" id="searchBtn" type="button">Suchen</button>
      <button class="btn" id="cancelBtn" type="button">Abbrechen</button>
    </div>
    <div id="searchResults"></div>
    <p class="tmdb-note">
      Daten und Bilder stammen von <a href="https://www.themoviedb.org" target="_blank" rel="noopener">The Movie Database (TMDB)</a>.
      Als Medium wird „DVD“ eingetragen, bei Serien für alle Staffeln (ohne Specials); Blu-ray o. Ä. und fehlende
      Staffeln danach im Formular ändern.
    </p>
  `;
  overlay.classList.add('open');
  document.getElementById('closeBtn').addEventListener('click', closeMovie);
  document.getElementById('cancelBtn').addEventListener('click', closeMovie);
  document.getElementById('searchBtn').addEventListener('click', runSearch);
  for(const id of ['importQueryInput', 'importYearInput']){
    document.getElementById(id).addEventListener('keydown', e => {
      if(e.key === 'Enter') document.getElementById('searchBtn').click();
    });
  }
  document.getElementById('importQueryInput').focus();
}

async function runSearch(){
  const query = document.getElementById('importQueryInput').value.trim();
  const year = document.getElementById('importYearInput').value.trim();
  const errorBox = document.getElementById('formError');
  const resultsBox = document.getElementById('searchResults');
  errorBox.innerHTML = '';

  if(!query){
    errorBox.innerHTML = '<div class="error-box">Titel oder IMDb-ID ist erforderlich.</div>';
    return;
  }

  resultsBox.innerHTML = '<p class="empty">Suche läuft …</p>';
  /** @type {{results: SearchResult[], error?: string}} */
  let data;
  let errorMsg = null;
  try {
    const params = new URLSearchParams({query});
    if(year) params.set('year', year);
    const res = await fetch(`api/search.php?${params}`);
    data = await res.json();
    if(!res.ok) errorMsg = data.error || `HTTP ${res.status}`;
  } catch(err){
    errorMsg = err.message;
  }
  if(errorMsg !== null){
    resultsBox.innerHTML = '';
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(errorMsg)}</div>`;
    return;
  }

  if(!data.results.length){
    resultsBox.innerHTML = '<p class="empty">Keine Treffer.</p>';
    return;
  }
  resultsBox.innerHTML = `<ul class="search-results">${data.results.map(r => `
    <li class="${r.existing ? 'exists' : ''}" data-tmdb="${r.tmdb}" data-type="${r.type}" data-slug="${escapeHtml(r.existing || '')}">
      <div class="result-poster">${r.poster ? `<img src="${escapeHtml(r.poster)}" alt="" loading="lazy">` : ''}</div>
      <div class="result-body">
        <div class="result-title"><span class="result-type${r.type === 'series' ? ' series' : ''}">${typeLabel(r.type)}</span>${escapeHtml(r.title)}${r.year ? ` <span>(${r.year})</span>` : ''}</div>
        ${r.originalTitle ? `<div class="result-original">${escapeHtml(r.originalTitle)}</div>` : ''}
        ${r.existing ? '<div class="result-status">bereits in der Videothek – anklicken zum Öffnen</div>' : '<div class="result-status"></div>'}
        ${r.overview ? `<div class="result-overview">${escapeHtml(r.overview)}</div>` : ''}
      </div>
    </li>`).join('')}</ul>`;

  resultsBox.querySelectorAll('li').forEach(li => li.addEventListener('click', () => {
    if(li.dataset.slug){
      const idx = MOVIES.findIndex(m => m.slug === li.dataset.slug);
      if(idx >= 0) openMovie(idx);
    } else {
      runImport(parseInt(li.dataset.tmdb, 10), li.dataset.type === 'series' ? 'series' : 'movie', li);
    }
  }));
}

/**
 * Importiert den Film bzw. die Serie und lädt danach alle Darstellerfotos über api/actors.php. Jeder Aufruf dort
 * arbeitet nur ~10 s (PHP-Zeitlimit), daher wird wiederholt, bis nichts mehr fehlt.
 * @param {number} tmdb
 * @param {'movie'|'series'} type
 * @param {HTMLElement} li  angeklickter Treffer (zeigt den Fortschritt)
 */
async function runImport(tmdb, type, li){
  const errorBox = document.getElementById('formError');
  if(document.querySelector('.search-results li.importing')) return; // kein Doppel-Import
  const status = li.querySelector('.result-status');
  errorBox.innerHTML = '';
  li.classList.add('importing');
  status.textContent = type === 'series'
    ? 'Importiere Serie mit allen Staffeln und Folgen, Poster und Hintergrundbild …'
    : 'Importiere Film, Poster und Hintergrundbild …';

  /** @type {Movie} */
  let saved;
  try {
    saved = await apiWrite('api/import.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({tmdb, type}),
    });
  } catch(err){
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(err.message)}</div>`;
    li.classList.remove('importing');
    status.textContent = '';
    return;
  }

  const warnings = saved.import_warning ? [saved.import_warning] : [];
  const total = saved.cast.filter(c => c.profile && !c.thumb).length;
  try {
    let remaining = total;
    while(remaining > 0){
      status.textContent = `Lade Darstellerfotos … ${total - remaining} von ${total}`;
      /** @type {ActorsResult} */
      const res = await apiWrite('api/actors.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({slug: saved.slug}),
      });
      saved = res.movie;
      if(res.failed) warnings.push(`${res.failed} Darstellerfoto(s) konnten nicht geladen werden.`);
      if(res.remaining >= remaining && !res.loaded && !res.failed) break; // kein Fortschritt -> nicht endlos wiederholen
      remaining = res.remaining;
    }
  } catch(err){
    warnings.push('Darstellerfotos unvollständig: ' + err.message);
  }

  saved = withDefaults(saved);
  MOVIES = MOVIES.filter(x => x.slug !== saved.slug);
  MOVIES.push(saved);
  refreshFilters();
  updateHeaderCount();
  renderGrid();
  openMovie(MOVIES.indexOf(saved));
  if(warnings.length){
    alert(warnings.join('\n'));
  }
}

async function init(){
  try {
    MOVIES = await loadMovies();
  } catch(err) {
    console.error(err);
    subCount.textContent = 'Die Videothek konnte nicht geladen werden.';
    grid.innerHTML = '<p class="empty">Fehler beim Laden der Filme und Serien.</p>';
    return;
  }

  refreshFilters();
  updateHeaderCount();

  // Suchbegriff/Filter/Sortierung vom letzten Besuch wiederherstellen. Art/Genre/Medium nur übernehmen,
  // wenn es diese Option nach dem Neuaufbau der Listen (refreshFilters) noch gibt — sonst
  // bleibt es bei "Alle ...", statt einen verschwundenen Wert stumm auszuwählen.
  const saved = loadViewState();
  if(saved.search) searchInput.value = saved.search;
  if(saved.sort && [...sortSel.options].some(o => o.value === saved.sort)) sortSel.value = saved.sort;
  if(saved.type && [...typeSel.options].some(o => o.value === saved.type)) typeSel.value = saved.type;
  if(saved.genre && [...genreSel.options].some(o => o.value === saved.genre)) genreSel.value = saved.genre;
  if(saved.medium && [...mediumSel.options].some(o => o.value === saved.medium)) mediumSel.value = saved.medium;

  renderGrid();
}

void init();

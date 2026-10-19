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
 * Reihe, zu der ein Film gehört (z. B. die Matrix-Filme), bzw. Serie einer Staffel. tmdb ist die TMDB-ID der Reihe
 * bzw. Serie (nur bei Import aus TMDB).
 * @typedef {Object} Collection
 * @property {string} name
 * @property {?string} tmdb
 * @property {?number} [part]  nur Reihen: Nummer des Films in der Reihe (von Hand); leer = nach Erscheinungsjahr
 */

/**
 * Ein Exemplar: Wem es gehört und in welcher Form. Dateien (DIGITAL_MEDIA, z. B. USB) liegen auf dem USB-Speicher
 * des Besitzers unter <USB-Ordner>/<slug>/; Scheiben (DVD, Blu-ray) haben einen Standort (LOCATIONS).
 * @typedef {Object} Medium
 * @property {string} owner  Benutzer (data/users.json)
 * @property {string} type   "DVD", "Blu-ray", "USB", …
 * @property {string[]} [files]  nur DIGITAL_MEDIA: Datei(en) relativ zum Ordner des Eintrags, bei Staffeln die Folgen
 */

/**
 * Wo eine Scheibe gerade ist, wenn nicht beim Besitzer (data/locations.json).
 * @typedef {Object} Location
 * @property {string} at     Benutzer, bei dem sie ist
 * @property {string} since  ISO-Zeitpunkt des Eintrags
 * @property {?string} by    wer es eingetragen hat
 */

/**
 * Ein Film oder eine Staffel einer Serie (type). Eine Staffel ist ein Eintrag wie ein Film, dazu Serie, Nummer,
 * Idee und Folgen; die Staffeln einer Serie erscheinen in der Übersicht gemeinsam auf einer Kachel wie eine Reihe.
 * @typedef {Object} Movie
 * @property {'movie'|'season'} type
 * @property {string} slug
 * @property {string} title
 * @property {?string} originalTitle
 * @property {?number} year
 * @property {string[]} genres
 * @property {?Collection} [collection]  nur Filme: Reihe
 * @property {?Collection} [series]      nur Staffeln: Serie
 * @property {?number} [season]          nur Staffeln: Nummer, 0 = Specials
 * @property {?number} runtime   Laufzeit in Minuten (bei Staffeln pro Folge)
 * @property {?string} fsk       "0", "6", "12", "16" oder "18"
 * @property {?string} [director] nur Filme
 * @property {?string} [creator]  nur Staffeln ("Idee")
 * @property {Episode[]} [episodes] nur Staffeln
 * @property {CastMember[]} cast
 * @property {Medium[]} media    alle Exemplare (auch mehrere Besitzer); leer = nicht im Bestand
 * @property {?string} trailer  YouTube-URL
 * @property {?string} plot
 * @property {?string} notes
 * @property {{tmdb?: string, imdb?: string}} uniqueids
 * @property {?string} poster
 * @property {?string} thumb
 * @property {?string} fanart   Hintergrundbild (Kodi-Begriff)
 * @property {string} created
 * @property {?string} [thumbHash]  Prüfsumme des Thumbnails (nur aus der Liste, für gleiche Cover einer Serie)
 * @property {string} [import_warning]
 */

/**
 * Ein Treffer von api/search.php.
 * @typedef {Object} SearchResult
 * @property {'movie'|'series'} type  bei TMDB gibt es Serien, in der Videothek werden daraus Staffeln
 * @property {number} tmdb
 * @property {string} title
 * @property {?string} originalTitle
 * @property {?number} year
 * @property {?string} overview
 * @property {?string} poster    Plakat-URL bei TMDB
 * @property {?string} existing  Slug, falls der Film schon in der Videothek ist
 * @property {number} [existingSeasons]  Serien: Zahl der schon vorhandenen Staffeln
 */

/**
 * Antwort von api/actors.php.
 * @typedef {Object} ActorsResult
 * @property {Movie} movie
 * @property {number} loaded
 * @property {number} failed
 * @property {number} remaining
 */

/** @type {Movie[]} Filme und Staffeln */
let MOVIES = [];
/** Alte Serien-Einträge (alle Staffeln in einem); nicht angezeigt, sie werden neu importiert. */
let legacySeriesCount = 0;
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
const ownerSel = document.getElementById('ownerSel');
/** Wert der Medium-Auswahl für Einträge ohne Medium; kann kein echtes Medium sein, der Server entfernt Leerzeichen am Rand. */
const NOT_OWNED = ' none';
const resultCount = document.getElementById('resultCount');
const subCount = document.getElementById('subCount');
const newMovieBtn = document.getElementById('newMovieBtn');
const importMovieBtn = document.getElementById('importMovieBtn');

const TOKEN_KEY = 'videothek_api_token';

// Vorschlagslisten im Formular; freie Eingaben bleiben trotzdem möglich.

const MEDIUM_OPTIONS = ['DVD', 'Blu-ray', 'USB'];
const FSK_OPTIONS = ['0', '6', '12', '16', '18'];
// Medien, die Dateien sind (wie DIGITAL_MEDIA in api/lib/crud.php): Mit Dateien statt Standort, werden nicht verliehen.
const DIGITAL_MEDIA = ['USB'];
// localStorage: USB-Ordner dieses Geräts (jeder hat seinen eigenen USB-Speicher), Vorgabe USB_ROOT aus config.php.
const USB_ROOT_KEY = 'videothek_usb_root';

// Artikel am Titelanfang, die beim Sortieren nach Titel übergangen werden ("The Big Lebowski" steht bei B, wie in
// Kodi). Angezeigt wird der Titel unverändert. Leere Liste = aus.
const SORT_IGNORE_ARTICLES = ['der', 'die', 'das', 'the', 'ein', 'eine', 'a', 'an'];
const SORT_ARTICLE_RE = SORT_IGNORE_ARTICLES.length ? new RegExp(`^(?:${SORT_IGNORE_ARTICLES.join('|')})\\s+`, 'i') : null;
const titleCollator = new Intl.Collator('de');

/** Titel ohne führenden Artikel (SORT_IGNORE_ARTICLES), zum Sortieren. */
function sortTitle(title){
  const t = (title || '').trim();
  const rest = SORT_ARTICLE_RE ? t.replace(SORT_ARTICLE_RE, '') : t;
  return rest || t; // Der Titel, der nur aus dem Artikel besteht ("Die"), bleibt, wie er ist
}

/** Vergleicht zwei Titel alphabetisch, führende Artikel übergangen. */
function compareTitles(a, b){
  return titleCollator.compare(sortTitle(a), sortTitle(b)) || titleCollator.compare(a || '', b || '');
}

function escapeHtml(str){
  return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/** @param {Movie} m */
function isSeason(m){
  return m.type === 'season';
}

/** "Film" / "Staffel" bzw. bei TMDB-Treffern "Serie" (für Überschriften und Meldungen). @param {string} type */
function typeLabel(type){
  return {season: 'Staffel', series: 'Serie'}[type] || 'Film';
}

/** "1 Film" / "3 Filme", "1 Serie" / "2 Serien" usw. */
function countLabel(n, singular, plural){
  return `${n} ${n === 1 ? singular : plural}`;
}

/** Arten der Medien eines Eintrags, ohne Doppelte (für Karte, Filter, Suche). @param {Movie} m @returns {string[]} */
function mediaOf(m){
  return [...new Set(m.media.map(md => md.type))];
}

/** Besitzer der Medien eines Eintrags. @param {Movie} m @returns {string[]} */
function ownersOf(m){
  return [...new Set(m.media.map(md => md.owner))];
}

/** @param {string} type */
function isDigital(type){
  return DIGITAL_MEDIA.includes(type);
}

/** "lilie/DVD" wie medium_key() in api/lib/locations.php. @param {Medium} md */
function mediumKey(md){
  return `${md.owner}/${md.type}`;
}

/** Anzeigename eines Benutzers (users.json), sonst der Login bzw. frei eingegebener Name, wie er ist. @param {string} login */
function userName(login){
  return USERS[login]?.name || login;
}

/** Genitiv vor dem Medium: "Lilies", aber "Klaus’", "Max’". @param {string} name */
function possessive(name){
  return /[sßxz]$/i.test(name) ? `${name}’` : `${name}s`;
}

/** "Lilies DVD", bei Dateien "Franks Datei (USB)". @param {Medium} md */
function mediumLabel(md){
  const who = possessive(userName(md.owner));
  return isDigital(md.type) ? `${who} Datei (${md.type})` : `${who} ${md.type}`;
}

/** Dateien des angemeldeten Benutzers zu einem Eintrag (zum Abspielen), sonst leer. @param {Movie} m @returns {string[]} */
function myFiles(m){
  const md = m.media.find(x => x.owner === ME && isDigital(x.type) && x.files.length);
  return md ? md.files : [];
}

/** USB-Ordner dieses Geräts oder '' (dann gilt USB_ROOT aus config.php). */
function usbRoot(){
  try {
    return localStorage.getItem(USB_ROOT_KEY) || '';
  } catch {
    return '';
  }
}

/**
 * Link auf die Wiedergabeliste der eigenen Datei(en).
 * @param {Movie} m
 * @param {?number} [episode]  nur diese Folge einer Staffel
 * @param {string} [root]  USB-Ordner; Vorgabe der dieses Geräts, leer = USB_ROOT aus config.php
 */
function playUrl(m, episode = null, root = usbRoot()){
  const params = new URLSearchParams({slug: m.slug});
  if(episode !== null) params.set('episode', String(episode));
  if(root) params.set('root', root);
  return `api/play.php?${params}`;
}

/**
 * Abspielen (Klick auf ▶): erst den USB-Ordner dieses Geräts bestätigen bzw. ändern (leer = Vorgabe aus config.php,
 * gemerkt im Browser), dann die Wiedergabeliste mit diesem Ordner laden. Abbrechen spielt nichts ab.
 * @param {HTMLElement} link  .play-btn bzw. .ep-play mit data-slug (und data-episode)
 */
async function playWithFolder(link){
  const m = MOVIES.find(x => x.slug === link.dataset.slug);
  if(!m) return;
  const value = await askText({
    title: 'Videothek-Ordner',
    text: `Ordner mit den Filmdateien. Darin liegt der Ordner "${m.slug}".`,
    value: usbRoot() || DEFAULT_USB_ROOT,
    ok: 'Abspielen',
  });
  if(value === null) return;
  try {
    if(value && value !== DEFAULT_USB_ROOT) localStorage.setItem(USB_ROOT_KEY, value);
    else localStorage.removeItem(USB_ROOT_KEY);
  } catch {
    // Speichern nicht erlaubt (privates Fenster o. Ä.): dann nur für dieses Abspielen
  }
  window.location.href = playUrl(m, link.dataset.episode ? parseInt(link.dataset.episode, 10) : null, value);
}

/**
 * Eigener Dialog statt prompt() (darüber schreibt der Browser "<Adresse> meldet"): $body (HTML) mit Bestätigen und
 * Abbrechen darunter. Enter im Eingabefeld bestätigt (returnValue "ok"), Escape bzw. Abbrechen schließt ohne. Ruft
 * onClose(dialog) beim Schließen auf und entfernt ihn danach.
 * @param {string} body
 * @param {string} ok  Beschriftung des Bestätigen-Knopfs
 * @param {function(HTMLDialogElement): void} onClose
 * @returns {HTMLDialogElement}
 */
function showDialog(body, ok, onClose){
  const dialog = document.createElement('dialog');
  dialog.className = 'ask-dialog';
  dialog.innerHTML = `<form method="dialog">
    ${body}
    <div class="btn-row">
      <button class="btn btn-primary" value="ok">${escapeHtml(ok)}</button>
      <button class="btn dialog-cancel" type="button">Abbrechen</button>
    </div>
  </form>`;
  // Escape schließt nur den Dialog, nicht auch die Detailansicht darunter (keydown-Handler am document).
  dialog.addEventListener('keydown', e => { if(e.key === 'Escape') e.stopPropagation(); });
  dialog.querySelector('.dialog-cancel').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => {
    onClose(dialog);
    dialog.remove();
  });
  document.body.appendChild(dialog);
  dialog.showModal();
  return dialog;
}

/**
 * Abfrage eines Texts (showDialog()): Überschrift, Text, Eingabefeld. Liefert den Text (getrimmt) bzw. null bei
 * Abbruch.
 * @param {{title: string, text?: string, value?: string, placeholder?: string, ok?: string}} options
 * @returns {Promise<?string>}
 */
function askText({title, text = '', value = '', placeholder = '', ok = 'OK'}){
  return new Promise(resolve => {
    const dialog = showDialog(`<h3>${escapeHtml(title)}</h3>
      ${text ? `<p>${escapeHtml(text)}</p>` : ''}
      <input type="text" value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" aria-label="${escapeHtml(title)}">`,
      ok,
      d => resolve(d.returnValue === 'ok' ? d.querySelector('input').value.trim() : null));
    dialog.querySelector('input').select();
  });
}

/**
 * "An wen verliehen?" (showDialog()): je Benutzer und je Name, bei dem gerade etwas ausgeliehen ist, ein Knopf (ein
 * Klick wählt), dazu ein Feld "Andere Person" (ohne Knöpfe, etwa so lange es nur einen Benutzer gibt, nur "Name").
 * Ein eingetippter Anzeigename eines Benutzers ("Thomas") wird
 * zu seinem Login. Liefert Login bzw. Name oder null bei Abbruch.
 * @param {Medium} md
 * @returns {Promise<?string>}
 */
function askBorrower(md){
  return new Promise(resolve => {
    const people = [...new Set([...Object.keys(USERS), ...borrowerNames()])].filter(u => u !== md.owner);
    let chosen = null;
    const dialog = showDialog(`<h3>An wen verliehen?</h3>
      ${people.length ? `<div class="borrower-choices">${people.map(u => `<button class="btn" type="button" data-at="${escapeHtml(u)}">${escapeHtml(userName(u))}</button>`).join('')}</div>` : ''}
      <label class="dialog-label" for="borrowerInput">${people.length ? 'Andere Person' : 'Person'}</label>
      <input type="text" id="borrowerInput" placeholder="z. B. Oma Erna">`,
      'Verleihen',
      d => {
        if(d.returnValue !== 'ok') return resolve(null);
        if(chosen !== null) return resolve(chosen);
        const name = d.querySelector('input').value.trim().replace(/\s+/g, ' ');
        const login = Object.keys(USERS).find(u => u.toLowerCase() === name.toLowerCase() || userName(u).toLowerCase() === name.toLowerCase());
        resolve(login || name || null);
      });
    dialog.querySelectorAll('[data-at]').forEach(btn => btn.addEventListener('click', () => {
      chosen = btn.dataset.at;
      dialog.close('ok');
    }));
    dialog.querySelector('input').focus();
  });
}

/** Zweistellig mit führender Null (Staffel-/Folgennummern in Dateinamen: S01E03). */
function pad2(n){
  return String(n).padStart(2, '0');
}

/**
 * Dateien einer Folge unter den Dateien files eines Mediums der Staffel – wie season_episode_files() in
 * api/lib/crud.php: Zuordnung über die Folgennummer im Dateinamen ("E03", "S01E03", "e3", mehrteilig "E03-cd1",
 * "E03-cd2"); trägt keine Datei eine solche Nummer, zählt die Position (1. Datei = 1. Folge der Liste).
 * @param {string[]} files
 * @param {Movie} season
 * @param {number} episode
 * @returns {string[]}
 */
function episodeFiles(files, season, episode){
  const pattern = /(?:^|[^a-z0-9])(?:s\d+)?e0*(\d+)(?!\d)/i;
  const numbered = files
    .map(f => [f.split('/').pop().match(pattern), f])
    .filter(([match]) => match);
  if(numbered.length){
    return numbered.filter(([match]) => parseInt(match[1], 10) === episode).map(([, f]) => f);
  }
  const pos = season.episodes.findIndex(e => e.number === episode);
  return pos >= 0 && files[pos] ? [files[pos]] : [];
}

/** Hat der angemeldete Benutzer den Film bzw. die Staffel als Datei (mit eingetragenen Dateien)? @param {Movie} m */
function isPlayable(m){
  return myFiles(m).length > 0;
}

/**
 * Ergänzt Felder, die ältere movie.json-Dateien (vor Serien bzw. Dateien) noch nicht haben, damit der übrige Code
 * sich auf die Listen verlassen kann. Für jede Antwort des Servers, die in MOVIES landet.
 * @param {Movie} m
 * @returns {Movie}
 */
function withDefaults(m){
  m.type = m.type === 'season' ? 'season' : 'movie';
  m.genres = m.genres || [];
  m.cast = m.cast || [];
  m.media = m.media || [];
  m.media.forEach(md => { if(isDigital(md.type)) md.files = md.files || []; });
  if(isSeason(m)){
    m.series = m.series && m.series.name ? m.series : null;
    m.season = m.season ?? null;
    m.episodes = m.episodes || [];
  } else {
    m.collection = m.collection && m.collection.name ? m.collection : null;
  }
  return m;
}

// PHP liefert die Liste der Filme und Staffeln über die JSON-API (api/movies.php). Alte Serien-Einträge (type
// "series") zeigt die Übersicht nicht, sie werden nur gezählt (Hinweis im Kopf: neu importieren).
/** @returns {Promise<Movie[]>} */
async function loadMovies(){
  /** @type {{movies: Movie[]}} */
  const data = await fetch('api/movies.php').then(res => res.json());
  legacySeriesCount = data.movies.filter(m => m.type === 'series').length;
  return data.movies.filter(m => m.type !== 'series').map(withDefaults);
}

/**
 * @typedef {Object} CatalogItem  ein Teil einer Reihe bzw. eine Staffel laut TMDB (data/collections.json, series.json)
 * @property {string} [tmdb]  Filme: TMDB-ID des Films
 * @property {number} [season]  Staffeln: Nummer
 * @property {?string} title
 * @property {?number} year
 * @property {?string} date  Erscheinungs- bzw. Ausstrahlungsdatum; leer oder in der Zukunft = angekündigt
 */
/**
 * @typedef {Object} CatalogEntry
 * @property {string} name
 * @property {string} updated  ISO-Datum der letzten Abfrage bei TMDB
 * @property {CatalogItem[]} [parts]
 * @property {CatalogItem[]} [seasons]
 */

/** Was es laut TMDB von Reihen und Serien gibt, je TMDB-ID (api/catalog.php). */
let CATALOG = /** @type {{collections: Object<string, CatalogEntry>, series: Object<string, CatalogEntry>}} */ ({collections: {}, series: {}});

/** Lädt CATALOG. Fehlt er oder schlägt das fehl, zeigt die Übersicht einfach keine "2 von 7"-Hinweise. */
async function loadCatalog(){
  try {
    const res = await fetch('api/catalog.php');
    if(!res.ok) return;
    const data = await res.json();
    CATALOG = {collections: data.collections || {}, series: data.series || {}};
  } catch(err) {
    console.warn('Katalog nicht geladen', err);
  }
}

/**
 * Gleicht eine Reihe bzw. Serie mit dem Katalog ab: owned = Einträge im Bestand, total = vorhandene Einträge plus
 * erschienene Teile ohne Eintrag (missing); angekündigte Teile zählen nicht mit. Abgleich über uniqueids.tmdb bzw.
 * Serien-TMDB-ID + Staffelnummer; eine Gruppe mit mehreren TMDB-IDs (z. B. Shaun das Schaf) nimmt alle zusammen.
 * @param {Movie[]} members  alle Einträge der Gruppe
 * @returns {?{owned: number, total: number, missing: CatalogItem[], announced: CatalogItem[], updated: string}}  null ohne Katalog
 */
function groupCatalog(members){
  const season = isSeason(members[0]);
  const ids = [...new Set(members.map(m => groupOf(m)?.tmdb).filter(Boolean))];
  const entries = ids.map(id => season ? CATALOG.series[id] : CATALOG.collections[id]).filter(Boolean);
  if(!entries.length) return null;
  const itemKey = (id, item) => season ? `${id}:${item.season}` : String(item.tmdb);
  const entryKey = m => season ? (m.series?.tmdb ? `${m.series.tmdb}:${m.season}` : null) : (m.uniqueids?.tmdb || null);
  const present = new Set(MOVIES.filter(m => isSeason(m) === season).map(entryKey).filter(Boolean));
  const today = new Date().toISOString().slice(0, 10);
  const seen = new Set();
  const missing = [], announced = [];
  ids.forEach(id => {
    const entry = season ? CATALOG.series[id] : CATALOG.collections[id];
    for(const item of (entry && (season ? entry.seasons : entry.parts)) || []){
      const key = itemKey(id, item);
      if(seen.has(key) || present.has(key)) continue;
      seen.add(key);
      (item.date && item.date <= today ? missing : announced).push(item);
    }
  });
  return {
    owned: members.filter(isOwned).length,
    total: members.length + missing.length,
    missing,
    announced,
    updated: entries.map(e => e.updated).sort()[0],
  };
}

/** Benutzer (Login => {name}) aus data/users.json, siehe api/users.php. */
let USERS = /** @type {Object<string, {name: string}>} */ ({});
/** @type {?string} angemeldeter Benutzer (Basic Auth), null ohne Anmeldung */
let ME = null;
/** USB_ROOT aus config.php: Vorgabe für den USB-Ordner, solange dieses Gerät keinen eigenen hat. */
let DEFAULT_USB_ROOT = '';
/** Standorte der Scheiben je Slug und Medium ("lilie/DVD"), siehe api/locations.php; ohne Eintrag beim Besitzer. */
let LOCATIONS = /** @type {Object<string, Object<string, Location>>} */ ({});

/** Lädt USERS und ME. Schlägt das fehl, gibt es eben keine Namen (es erscheinen die Logins). */
async function loadUsers(){
  try {
    const res = await fetch('api/users.php');
    if(!res.ok) return;
    /** @type {{users?: Object<string, {name: string}>, me?: ?string, usbRoot?: string}} */
    const data = await res.json();
    USERS = data.users || {};
    ME = data.me || null;
    DEFAULT_USB_ROOT = data.usbRoot || '';
  } catch(err) {
    console.warn('Benutzer nicht geladen', err);
  }
}

/** Lädt LOCATIONS (nach dem Laden und nach dem Speichern eines Eintrags: Umbenennen zieht seine Standorte mit). */
async function loadLocations(){
  try {
    const res = await fetch('api/locations.php');
    if(!res.ok) return;
    /** @type {{locations?: Object<string, Object<string, Location>>}} */
    const data = await res.json();
    LOCATIONS = data.locations || {};
  } catch(err) {
    console.warn('Standorte nicht geladen', err);
  }
}

/** "2 von 7" ohne Wertung; für die Kachel mit Einheit ("2 von 7 Filmen"). */
function catalogCountText(info, unitPlural = ''){
  return `${info.owned} von ${info.total}${unitPlural ? ` ${unitPlural}` : ''}`;
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
  return c.thumb ? `api/image.php?slug=${encodeURIComponent(m.slug)}&actor=${encodeURIComponent(c.name)}&v=${encodeURIComponent(c.thumb)}` : null;
}

/** YouTube-Video-ID aus watch?v=…, youtu.be/… oder /embed/… — null, wenn es keine YouTube-URL ist. */
function youtubeId(url){
  const match = (url || '').match(/(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/)|youtu\.be\/)([\w-]{11})/);
  return match ? match[1] : null;
}

/**
 * Film: "1985 · 114 Min. · FSK 12", Staffel: "2008 · 7 Folgen · 48 Min./Folge · FSK 16" — leere Angaben fallen weg.
 * @param {Movie} m
 */
function metaLine(m){
  if(isSeason(m)){
    return [
      m.year,
      m.episodes.length ? countLabel(m.episodes.length, 'Folge', 'Folgen') : null,
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
 * Im eigenen Bestand: Mindestens ein Medium angehakt. Einträge ohne Medium, etwa fehlende Teile einer Reihe oder
 * Staffeln einer Serie, erscheinen überall blass.
 * @param {Movie} m
 */
function isOwned(m){
  return mediaOf(m).length > 0;
}

/**
 * @param {Movie} m
 * @param {number} idx
 * @param {string} [extraClass]  z. B. "hit"/"other" im aufgefächerten Panel einer Reihe
 * @param {?number} [k]  Position beim Auffächern (Verzögerung der Animation); im Panel kurzer Titel ohne Etikett
 */
function cardHtml(m, idx, extraClass = '', k = null){
  const src = thumbSrc(m);
  const inPanel = k !== null;
  const img = src ? `<img src="${escapeHtml(src)}" alt="${escapeHtml(m.title)}" loading="lazy">` : '<span>kein Cover</span>';
  const cls = ['card', isOwned(m) ? '' : 'not-owned', extraClass].filter(Boolean).join(' ');
  return `<div class="${cls}" data-idx="${idx}"${inPanel ? ` style="--k:${k}"` : ''}>
    <div class="thumb">${img}${isSeason(m) && !inPanel ? '<div class="type-badge">Serie</div>' : ''}</div>
    <div class="body">
      <h3>${escapeHtml(inPanel ? shortTitle(m) : m.title)}</h3>
      <div class="meta">${escapeHtml(metaLine(m))}</div>
      ${m.genres.slice(0, 2).map(g => `<div class="cat-tag">${escapeHtml(g)}</div>`).join('')}
      ${notOwnedTagOr(mediaOf(m))}
    </div>
  </div>`;
}

/** Medien als Schlagwörter, ohne Medium der Hinweis "nicht im Bestand". @param {string[]} media */
function notOwnedTagOr(media){
  return media.length
    ? media.map(md => `<div class="cat-tag medium-tag">${escapeHtml(md)}</div>`).join('')
    : '<div class="cat-tag missing-tag">nicht im Bestand</div>';
}

/** Gruppe eines Eintrags: die Reihe eines Films bzw. die Serie einer Staffel, sonst null. @param {Movie} m */
function groupOf(m){
  return (isSeason(m) ? m.series : m.collection) || null;
}

/**
 * Schlüssel der Gruppe (Art und Name ohne Groß-/Kleinschreibung), null ohne Gruppe. Eine Reihe und eine Serie
 * gleichen Namens bleiben getrennt.
 * @param {Movie} m
 */
function groupKey(m){
  const g = groupOf(m);
  return g ? `${isSeason(m) ? 's' : 'c'}:${g.name.trim().toLowerCase()}` : null;
}

/**
 * Teile einer Gruppe: Staffeln nach Nummer (Specials ans Ende); Filme einer Reihe nach "Teil", Filme ohne Teil
 * danach nach Jahr (ohne Jahr ans Ende); bei Gleichstand nach Titel.
 */
function groupOrder(a, b){
  const rank = ({m}) => isSeason(m)
    ? [0, m.season === 0 || m.season === null ? 9999 : m.season]
    : (m.collection?.part != null ? [0, m.collection.part] : [1, m.year ?? 9999]);
  const [ra, rb] = [rank(a), rank(b)];
  return ra[0] - rb[0] || ra[1] - rb[1] || compareTitles(a.m.title, b.m.title);
}

/** "Teil 2" bei Filmen mit Teilnummer, sonst null. @param {Movie} m */
function partLabel(m){
  return !isSeason(m) && m.collection?.part != null ? `Teil ${m.collection.part}` : null;
}

/**
 * Titel innerhalb der eigenen Serie: ohne den Seriennamen vorn ("Die Tudors – Staffel 2" -> "Staffel 2").
 * @param {Movie} m
 */
function shortTitle(m){
  const name = isSeason(m) && m.series ? m.series.name : null;
  if(!name || !m.title.toLowerCase().startsWith(name.toLowerCase())) return m.title;
  return m.title.slice(name.length).replace(/^\s*[–:-]\s*/, '') || m.title;
}

/**
 * Cover als aufgefächerter Stapel (höchstens vier), von links oben (erstes, vorn) nach rechts unten (hinten).
 * @param {{src: ?string, label: string}[]} covers  ohne src erscheint label
 */
function collageHtml(covers){
  const shown = covers.slice(0, 4);
  const n = shown.length;
  return shown.map(({src, label}, k) => {
    const t = n === 1 ? 0.5 : k / (n - 1);
    const style = `left:${(4 + t * 32).toFixed(1)}%;top:${(4 + t * 28).toFixed(1)}%;transform:rotate(${(-7 + t * 14).toFixed(1)}deg);z-index:${n - k}`;
    return `<div class="collage-cover" style="${style}">${src ? `<img src="${escapeHtml(src)}" alt="" loading="lazy">` : `<span>${escapeHtml(label)}</span>`}</div>`;
  }).join('');
}

/**
 * Kachel einer Reihe bzw. Serie: die Cover der Teile als aufgefächerter Stapel (höchstens vier, der erste Teil liegt
 * oben). Gleiche Cover (z. B. Staffeln mit dem Poster der Serie) erscheinen nur einmal; bleibt nur eines, ist es das
 * Cover der Kachel.
 * @param {CollectionGroup} group
 */
function groupCardHtml(group){
  const seen = new Set();
  const covers = group.items
    .map(({m}) => ({src: thumbSrc(m), label: shortTitle(m), hash: m.thumbHash || thumbSrc(m) || m.slug}))
    .filter(c => !seen.has(c.hash) && seen.add(c.hash));
  const first = group.items[0].m;
  const series = isSeason(first);
  const years = [...new Set(group.items.map(({m}) => m.year).filter(Boolean))].sort((a, b) => a - b);
  const span = years.length ? (years.length === 1 ? years[0] : `${years[0]}–${years[years.length - 1]}`) : null;
  const media = [...new Set(group.items.flatMap(({m}) => mediaOf(m)))];
  const cls = ['card', 'collection-card', media.length ? '' : 'not-owned', expandedGroup === group.key ? 'expanded' : ''].filter(Boolean).join(' ');
  const img = covers.length === 1
    ? (covers[0].src ? `<img src="${escapeHtml(covers[0].src)}" alt="" loading="lazy">` : '<span>kein Cover</span>')
    : collageHtml(covers);
  const info = groupCatalog(group.items.map(({m}) => m));
  const count = info && info.total > group.items.length
    ? catalogCountText(info, series ? 'Staffeln' : 'Filmen')
    : series ? countLabel(group.items.length, 'Staffel', 'Staffeln') : countLabel(group.items.length, 'Film', 'Filme');
  return `<div class="${cls}" data-group="${escapeHtml(group.key)}" title="${series ? 'Staffeln anzeigen' : 'Teile der Reihe anzeigen'}">
    <div class="thumb${covers.length === 1 ? '' : ' collage'}">${img}<div class="type-badge">${series ? 'Serie' : 'Reihe'}</div></div>
    <div class="body">
      <h3>${escapeHtml(groupOf(first).name)}</h3>
      <div class="meta">${escapeHtml([count, span].filter(Boolean).join(' · '))}</div>
      ${notOwnedTagOr(media)}
    </div>
  </div>`;
}

/**
 * Aufgefächerte Teile einer Reihe bzw. Staffeln einer Serie unter der Zeile ihrer Kachel (über die volle Breite des
 * Rasters). Grenzen Suche oder Filter die Übersicht ein, sind die Treffer hervorgehoben und die übrigen abgeschwächt.
 * @param {CollectionGroup} group
 */
function groupPanelHtml(group){
  const filtered = group.hits.size < group.items.length;
  const owned = group.items.filter(({m}) => isOwned(m)).length;
  const info = groupCatalog(group.items.map(({m}) => m));
  const note = [
    info && info.total > owned ? catalogCountText(info)
      : owned < group.items.length ? `${owned} von ${group.items.length} im Bestand` : null,
    filtered ? countLabel(group.hits.size, 'Treffer', 'Treffer') : null,
  ].filter(Boolean).join(' · ');
  const cards = group.items.map(({m, i}, k) => cardHtml(m, i, filtered ? (group.hits.has(i) ? 'hit' : 'other') : '', k));
  const label = isSeason(group.items[0].m) ? 'Serie' : 'Reihe';
  return `<div class="collection-panel" id="collectionPanel">
    <div class="collection-head">
      <h3>${escapeHtml(groupOf(group.items[0].m).name)}${note ? ` <span class="collection-count">${escapeHtml(note)}</span>` : ''}</h3>
      <button class="close" id="collectionCloseBtn" type="button" aria-label="${label} zuklappen">&times;</button>
    </div>
    <div class="collection-items">${cards.join('')}</div>
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
 * Baut die Optionen der Filter (Genre, Medium, Besitzer) aus dem aktuellen MOVIES-Stand neu auf (nötig nach jedem
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
  const fill = (select, valuesOf, allLabel, extra = [], labelOf = v => v) => {
    const values = knownValues(valuesOf);
    const current = select.value;
    const options = [...values.map(v => [v, labelOf(v)]), ...extra];
    select.innerHTML = `<option value="">${allLabel}</option>` + options.map(([v, label]) => `<option value="${escapeHtml(v)}">${escapeHtml(label)}</option>`).join('');
    select.value = options.some(([v]) => v === current) ? current : '';
    return options.length;
  };
  fill(genreSel, m => m.genres, 'Alle Genres');
  fill(mediumSel, mediaOf, 'Alle Medien', MOVIES.some(m => !isOwned(m)) ? [[NOT_OWNED, '(nicht im Bestand)']] : []);
  // Besitzer erst, wenn es mehrere gibt.
  ownerSel.hidden = fill(ownerSel, ownersOf, 'Alle Besitzer', [], userName) < 2;
  if(ownerSel.hidden) ownerSel.value = '';
}

/**
 * Aktualisiert die Gesamtanzahl im Header (nötig nach jedem Anlegen/Löschen/Import): Filme und Serien (Staffeln ohne
 * Serie zählen einzeln), dazu ein Hinweis auf alte Serien-Einträge, die neu zu importieren sind.
 */
function updateHeaderCount(){
  const seasons = MOVIES.filter(isSeason);
  const series = new Set(seasons.map(m => groupKey(m) || m.slug)).size;
  const movies = MOVIES.length - seasons.length;
  subCount.textContent = [
    countLabel(movies, 'Film', 'Filme'),
    series ? countLabel(series, 'Serie', 'Serien') : null,
    legacySeriesCount ? `${countLabel(legacySeriesCount, 'Serie', 'Serien')} im alten Format ausgeblendet (neu importieren)` : null,
  ].filter(Boolean).join(' · ');
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
    const byTitle = compareTitles(a.m.title, b.m.title);
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
  const owner = ownerSel.value;
  let items = MOVIES.map((m, i) => ({m, i}));

  if(type){
    items = items.filter(({m}) => m.type === type);
  }
  if(genre){
    items = items.filter(({m}) => m.genres.includes(genre));
  }
  // Medium und Besitzer zusammen: "Blu-ray" + "Lilie" = Lilies Blu-rays, nicht irgendeine Blu-ray bei Lilies Filmen.
  if(medium === NOT_OWNED){
    items = items.filter(({m}) => !isOwned(m));
  } else if(medium || owner){
    items = items.filter(({m}) => m.media.some(md => (!medium || md.type === medium) && (!owner || md.owner === owner)));
  }

  if(q){
    // Bei Staffeln zählen auch die Folgentitel ("Felina" findet Breaking Bad).
    const episodeTitles = m => isSeason(m) ? m.episodes.map(e => e.title) : [];
    items = items.filter(({m}) =>
      [m.title, m.originalTitle, groupOf(m)?.name, m.director, m.creator, m.plot, m.year && String(m.year), ...m.genres, ...mediaOf(m), ...ownersOf(m).map(userName), ...episodeTitles(m)]
        .some(v => (v || '').toLowerCase().includes(q))
      || m.cast.some(c => c.name.toLowerCase().includes(q) || (c.role || '').toLowerCase().includes(q))
    );
  }

  switch(sortSel.value){
    case 'title':
      items.sort((a,b) => compareTitles(a.m.title, b.m.title));
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

  // Filme einer Reihe bzw. Staffeln einer Serie erscheinen gemeinsam auf einer Kachel, an der Stelle ihres ersten
  // Treffers in der gewählten Sortierung. Das gilt auch bei Suche und Filtern: Die Kachel steht dann für die ganze
  // Gruppe, beim Auffächern sind die Treffer hervorgehoben. Eine Gruppe mit nur einem Eintrag bleibt einzeln.
  const hitIdx = new Set(items.map(({i}) => i));
  const hitKeys = new Set(items.map(({m}) => groupKey(m)).filter(Boolean));
  /** @type {Map<string, {m: Movie, i: number}[]>} */
  const members = new Map();
  MOVIES.forEach((m, i) => {
    const key = groupKey(m);
    if(key && hitKeys.has(key)) members.set(key, [...(members.get(key) || []), {m, i}]);
  });
  currentGroups = new Map([...members]
    .filter(([, list]) => list.length > 1)
    .map(([key, list]) => [key, {
      key,
      items: [...list].sort(groupOrder),
      hits: new Set(list.filter(({i}) => hitIdx.has(i)).map(({i}) => i)),
    }]));
  if(expandedGroup && !currentGroups.has(expandedGroup)) expandedGroup = null;

  const done = new Set();
  const html = [];
  for(const item of items){
    const key = groupKey(item.m);
    if(key && currentGroups.has(key)){
      if(!done.has(key)) html.push(groupCardHtml(currentGroups.get(key)));
      done.add(key);
    } else {
      html.push(cardHtml(item.m, item.i));
    }
  }
  grid.innerHTML = html.length ? html.join('') : '<p class="empty">Nichts gefunden.</p>';
  placeGroupPanel();
}

/**
 * Teile einer Reihe bzw. Staffeln einer Serie in der Übersicht (groupOrder()).
 * @typedef {Object} CollectionGroup
 * @property {string} key  groupKey()
 * @property {{m: Movie, i: number}[]} items  alle Teile der Gruppe, unabhängig von Suche und Filtern
 * @property {Set<number>} hits  Indizes (in MOVIES) der Teile, die Suche und Filter erfüllen
 */

/** @type {?string} Schlüssel der aufgeklappten Gruppe (groupKey()) */
let expandedGroup = null;
/** @type {Map<string, CollectionGroup>} Gruppen der zuletzt gezeigten Übersicht */
let currentGroups = new Map();

/**
 * Setzt die aufgefächerten Teile der offenen Gruppe hinter die letzte Kachel derselben Rasterzeile (welche das ist,
 * hängt von der Fensterbreite ab, daher auch nach einer Größenänderung neu).
 */
function placeGroupPanel(){
  document.getElementById('collectionPanel')?.remove();
  if(!expandedGroup) return;
  const tile = [...grid.querySelectorAll('.collection-card')].find(c => c.dataset.group === expandedGroup);
  if(!tile) return;
  let last = tile;
  while(last.nextElementSibling && last.nextElementSibling.offsetTop === tile.offsetTop){
    last = last.nextElementSibling;
  }
  last.insertAdjacentHTML('afterend', groupPanelHtml(currentGroups.get(expandedGroup)));
}

/** Klappt eine Gruppe auf, bzw. zu bei einem Klick auf die schon offene oder mit null. @param {?string} key */
function toggleGroup(key){
  expandedGroup = key && key !== expandedGroup ? key : null;
  grid.querySelectorAll('.collection-card').forEach(c => c.classList.toggle('expanded', c.dataset.group === expandedGroup));
  placeGroupPanel();
  const panel = document.getElementById('collectionPanel');
  if(panel && panel.getBoundingClientRect().bottom > window.innerHeight){
    panel.scrollIntoView({behavior: 'smooth', block: 'nearest'});
  }
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
    isSeason(m) ? ['Serie', m.series?.name] : ['Reihe', m.collection && [m.collection.name, partLabel(m)].filter(Boolean).join(', ')],
    isSeason(m) ? ['Staffel', m.season === 0 ? 'Specials' : m.season] : null,
    isSeason(m) ? ['Idee', m.creator] : ['Regie', m.director],
    isOwned(m) ? ['Medien', mediaListHtml(m), true] : ['Medien', 'nicht im Bestand'],
  ].filter(f => f && f[1] !== null && f[1] !== undefined && f[1] !== '');
  // Dritter Wert true: schon fertiges HTML (die Medienliste)
  const factsHtml = facts.length
    ? `<dl class="facts">${facts.map(([k, v, html]) => `<dt>${k}</dt><dd>${html ? v : escapeHtml(v)}</dd>`).join('')}</dl>`
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
        <div class="meta-line">${escapeHtml(metaLine(m))}</div>
        ${factsHtml}
        ${createdHtml}
      </div>
    </div>
    ${paragraphsHtml('Handlung', m.plot, 'plot')}
    ${isSeason(m) ? episodesHtml(m) : ''}
    <div class="screen-only">${groupPartsHtml(m)}</div>
    ${trailerHtml(m)}
    ${castHtml(m)}
    ${paragraphsHtml('Notizen', m.notes, 'notes')}
  `;
}

/**
 * Medien in der Detailansicht, je Zeile eins (mediumItemHtml()).
 * @param {Movie} m
 */
function mediaListHtml(m){
  return `<ul class="media-list" data-slug="${escapeHtml(m.slug)}">${m.media.map(md => mediumItemHtml(m, md)).join('')}</ul>`;
}

/**
 * Namen von Leuten ohne Zugang, bei denen gerade etwas ausgeliehen ist (frei eingegeben, siehe askBorrower()),
 * alphabetisch; als Vorschläge beim Verleihen.
 * @returns {string[]}
 */
function borrowerNames(){
  const names = Object.values(LOCATIONS).flatMap(entry => Object.values(entry).map(loc => loc.at));
  return [...new Set(names)].filter(n => !USERS[n]).sort((a, b) => a.localeCompare(b, 'de'));
}

/** "seit heute", "seit gestern", "seit 48 Tagen" (nach Kalendertagen). @param {string} iso */
function sinceText(iso){
  const day = d => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((day(new Date()) - day(new Date(iso))) / 86400000);
  return days <= 0 ? 'seit heute' : days === 1 ? 'seit gestern' : `seit ${days} Tagen`;
}

/**
 * Ein Medium in der Detailansicht. Scheiben beim Besitzer: "Lilies DVD" mit dem Link "ausleihen" (fragt, an wen,
 * siehe lendMedium()); verliehen: "Lilies DVD hat Thomas seit 48 Tagen" mit dem Link "zurückgeben", Datum und wer
 * es eingetragen hat als Tooltip. Im Ausdruck fehlen die Links. Dateien ohne beides.
 * @param {Movie} m
 * @param {Medium} md
 */
function mediumItemHtml(m, md){
  const label = escapeHtml(mediumLabel(md));
  if(isDigital(md.type)){
    return `<li>${label}</li>`;
  }
  const data = `data-owner="${escapeHtml(md.owner)}" data-type="${escapeHtml(md.type)}"`;
  const loc = (LOCATIONS[m.slug] || {})[mediumKey(md)] || null;
  if(!loc){
    return `<li ${data}>${label} <button class="link-btn lend-btn screen-only" type="button">ausleihen</button></li>`;
  }
  const title = [`seit ${new Date(loc.since).toLocaleDateString('de-DE')}`, loc.by ? `eingetragen von ${userName(loc.by)}` : null]
    .filter(Boolean).join(' · ');
  return `<li ${data}>${label} <span class="lent" title="${escapeHtml(title)}">hat ${escapeHtml(userName(loc.at))} ${sinceText(loc.since)}</span>
    <button class="link-btn return-btn screen-only" type="button">zurückgeben</button></li>`;
}

/**
 * Klick auf "ausleihen" bzw. "zurückgeben" in der Medienliste: fragt beim Ausleihen, an wen (askBorrower()),
 * speichert den Standort (api/locations.php; zurückgeben = wieder beim Besitzer) und zeigt die Zeile neu.
 * @param {HTMLElement} btn
 */
async function lendMedium(btn){
  const li = /** @type {HTMLElement} */ (btn.closest('li'));
  const list = /** @type {HTMLElement} */ (btn.closest('.media-list'));
  const slug = list.dataset.slug;
  const m = MOVIES.find(x => x.slug === slug);
  const md = m && m.media.find(x => x.owner === li.dataset.owner && x.type === li.dataset.type);
  if(!md) return;
  const at = btn.classList.contains('return-btn') ? md.owner : await askBorrower(md);
  if(!at) return;
  btn.disabled = true;
  try {
    /** @type {{locations: Object<string, Location>}} */
    const res = await apiWrite('api/locations.php', {
      method: 'PUT',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({slug, owner: md.owner, type: md.type, at}),
    });
    if(Object.keys(res.locations).length) LOCATIONS[slug] = res.locations;
    else delete LOCATIONS[slug];
  } catch(err){
    alert('Nicht gespeichert: ' + err.message);
  }
  li.outerHTML = mediumItemHtml(m, md);
}

/**
 * Folgen einer Staffel; hat der angemeldete Benutzer sie als Datei, je Folge ein Button zum Abspielen. Die Handlung
 * ist zugeklappt, ein Klick auf den Titel (mit Pfeil ▸/▾) zeigt sie (Handler am #sheet).
 * @param {Movie} m
 */
function episodesHtml(m){
  if(!m.episodes.length) return '';
  const files = myFiles(m);
  const items = m.episodes.map(e => {
    const play = episodeFiles(files, m, e.number).length
      ? `<a class="ep-play screen-only" href="${escapeHtml(playUrl(m, e.number))}" data-slug="${escapeHtml(m.slug)}" data-episode="${e.number}" title="Folge abspielen" aria-label="Folge ${e.number} abspielen">&#9654;</a>`
      : '';
    return `<li>
      <span class="ep-no">${e.number}</span>
      <div class="ep-body">
        <div class="ep-title">${e.plot
          ? `<button class="ep-toggle" type="button" aria-expanded="false" title="Handlung anzeigen">${escapeHtml(e.title || `Folge ${e.number}`)}</button>`
          : escapeHtml(e.title || `Folge ${e.number}`)}${play}</div>
        ${e.plot ? `<div class="ep-plot" hidden>${escapeHtml(e.plot)}</div>` : ''}
      </div>
    </li>`;
  }).join('');
  return `<h4>Folgen (${m.episodes.length})</h4><ol class="episodes">${items}</ol>`;
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
      ${isPlayable(m) ? `<a class="btn btn-primary play-btn" href="${escapeHtml(playUrl(m))}" data-slug="${escapeHtml(m.slug)}" title="${isSeason(m) ? 'Öffnet alle Folgen der Staffel' : 'Öffnet die Filmdatei'} im installierten Player">&#9654; ${isSeason(m) ? 'Alle Folgen abspielen' : 'Abspielen'}</a>` : ''}
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
}

/**
 * Detailansicht: alle Teile der Reihe bzw. Staffeln der Serie als kleine Plakate (groupOrder()); ein Klick öffnet den
 * Teil. Teile, die nicht im Bestand sind, erscheinen blass. Weitere Teile laut Katalog (groupCatalog()) stehen als
 * leere Platzhalter an ihrer Stelle in der Reihenfolge (nach Staffelnummer bzw. Jahr). Leer ohne Gruppe oder wenn
 * es außer diesem Eintrag nichts zu zeigen gibt.
 * @param {Movie} m
 */
function groupPartsHtml(m){
  const key = groupKey(m);
  if(!key) return '';
  const parts = MOVIES.map((x, i) => ({m: x, i})).filter(({m: x}) => groupKey(x) === key).sort(groupOrder);
  const info = groupCatalog(parts.map(({m: x}) => x));
  const gaps = info ? [...info.missing, ...info.announced] : [];
  if(parts.length < 2 && !gaps.length) return '';
  const owned = parts.filter(({m: x}) => isOwned(x)).length;
  const total = info ? info.total : parts.length;
  const season = isSeason(m);
  // Der Name steht schon oben in der Detailansicht ("Reihe: …" bzw. Serie), hier nur Art und Anzahl.
  const heading = `${season ? 'Staffeln' : 'Filmteile'} (${owned < total ? `${owned} von ${total}` : total})`;

  const entryHtml = ({m: x, i}) => {
    const cls = [x === m ? 'current' : '', isOwned(x) ? '' : 'not-owned'].filter(Boolean).join(' ');
    const year = [partLabel(x), x.year, isOwned(x) ? null : 'nicht im Bestand'].filter(Boolean).join(' · ');
    return `<li${cls ? ` class="${cls}"` : ''} data-idx="${i}">
      <div class="part-poster">${thumbSrc(x) ? `<img src="${escapeHtml(thumbSrc(x))}" alt="" loading="lazy">` : ''}</div>
      <div class="part-title">${escapeHtml(shortTitle(x))}</div><div class="part-year">${escapeHtml(year)}</div></li>`;
  };
  const gapHtml = item => {
    const title = season ? (item.title || `Staffel ${item.season}`) : (item.title || '');
    const meta = [item.year, info.announced.includes(item) ? 'angekündigt' : null].filter(Boolean).join(' · ');
    return `<li class="gap"><div class="part-poster"></div>
      <div class="part-title">${escapeHtml(title)}</div><div class="part-year">${escapeHtml(meta)}</div></li>`;
  };
  // Platzhalter vor dem ersten Eintrag, der danach kommt (Staffelnummer bzw. Jahr), sonst ans Ende.
  const after = (item, x) => season
    ? (x.season === 0 || x.season === null ? 9999 : x.season) > item.season
    : (x.year ?? 9999) > (item.year ?? 9999);
  const slots = parts.map(() => []);
  const tail = [];
  for(const item of gaps){
    const k = parts.findIndex(({m: x}) => after(item, x));
    (k < 0 ? tail : slots[k]).push(item);
  }
  const items = parts.map((p, k) => slots[k].map(gapHtml).join('') + entryHtml(p)).join('') + tail.map(gapHtml).join('');
  const stand = info ? ` title="Laut TMDB, Stand ${escapeHtml(new Date(info.updated).toLocaleDateString('de-DE'))}"` : '';
  return `<h4${stand}>${escapeHtml(heading)}</h4><ul class="collection-parts">${items}</ul>`;
}

function closeMovie(){
  stopScan();
  formIsOpen = false;
  overlay.classList.remove('open');
  sheet.innerHTML = '';
}

/**
 * Zeigt das Anlege-/Bearbeitungsformular für Filme und Staffeln. m === null bedeutet: neuer Eintrag (zunächst Film,
 * die Art lässt sich oben umschalten). Felder, die nur eine Art haben, stehen in .movie-only bzw. .season-only und
 * werden je nach gewählter Art ein- oder ausgeblendet; ihre Eingaben bleiben beim Umschalten erhalten, gespeichert
 * werden nur die der gewählten Art.
 * @param {?Movie} m
 */
function openEditForm(m){
  formIsOpen = true;
  const isEdit = m !== null;
  const type = isEdit ? m.type : 'movie';
  const val = field => isEdit ? escapeHtml(m[field] ?? '') : '';
  /**
   * Bildfeld wie beim Darstellerfoto: Klick auf die Vorschau wählt ein neues Bild, "×" unten rechts entfernt es.
   * Hochgeladen bzw. gelöscht wird erst beim Speichern (data-remove="1" merkt das Entfernen, siehe saveMovie()).
   * @param {'poster'|'fanart'} kind
   * @param {string} label  Feldbeschriftung
   * @param {string} hint   Hinweis unter dem Feld (HTML)
   */
  const imageFieldHtml = (kind, label, hint) => {
    const src = isEdit ? movieImageSrc(m, kind) : null;
    return `<div class="form-field image-field ${kind}-field" id="${kind}Field" data-remove="">
      <span class="label">${label}</span>
      <div class="form-preview">
        <label class="ratio-box" for="${kind}Input" title="${label} auswählen">
          <img id="${kind}Preview" src="${src ? escapeHtml(src) : ''}" alt=""${src ? '' : ' hidden'}>
          <span class="preview-empty" id="${kind}Empty"${src ? ' hidden' : ''}>${label} wählen</span>
        </label>
        <button class="image-remove" id="${kind}RemoveBtn" type="button" title="${label} entfernen" aria-label="${label} entfernen"${src ? '' : ' hidden'}>&times;</button>
      </div>
      <input type="file" id="${kind}Input" accept="image/jpeg,image/png,image/webp" hidden>
      <div class="hint">${hint}</div>
    </div>`;
  };

  sheet.innerHTML = `
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <div id="editForm">
    <h2 id="formTitle"></h2>
    <div id="formError"></div>
    <div class="form-field">
      <div class="checkbox-row" id="typeInputs">
        <label><input type="radio" name="type" value="movie"${type === 'movie' ? ' checked' : ''}> Film</label>
        <label><input type="radio" name="type" value="season"${type === 'season' ? ' checked' : ''}> Serie</label>
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
        <label for="yearInput">Jahr</label>
        <input type="text" id="yearInput" inputmode="numeric" value="${val('year')}" placeholder="z. B. 1985">
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
      <div class="form-field season-only">
        <label for="creatorInput">Idee</label>
        <input type="text" id="creatorInput" value="${val('creator')}">
      </div>
    </div>
    <div class="form-row movie-only">
      <div class="form-field">
        <label for="collectionInput">Reihe</label>
        <input type="text" id="collectionInput" list="collectionList" autocomplete="off" value="${isEdit && m.collection ? escapeHtml(m.collection.name) : ''}" placeholder="z. B. Matrix Filmreihe">
        <datalist id="collectionList">${knownValues(x => x.collection?.name).map(c => `<option value="${escapeHtml(c)}">`).join('')}</datalist>
        <div class="hint">Filme derselben Reihe erscheinen in der Übersicht gemeinsam auf einer Kachel. Leer = keine Reihe.</div>
      </div>
      <div class="form-field se-number">
        <label for="partInput">Teil</label>
        <input type="text" id="partInput" inputmode="numeric" value="${isEdit && m.collection?.part != null ? m.collection.part : ''}" placeholder="z. B. 2">
        <div class="hint">Leer = nach Jahr.</div>
      </div>
    </div>
    <div class="form-row season-only">
      <div class="form-field">
        <label for="seriesInput">Serie</label>
        <input type="text" id="seriesInput" list="seriesList" autocomplete="off" value="${isEdit && m.series ? escapeHtml(m.series.name) : ''}" placeholder="z. B. Die Tudors">
        <datalist id="seriesList">${knownValues(x => x.series?.name).map(c => `<option value="${escapeHtml(c)}">`).join('')}</datalist>
        <div class="hint">Staffeln derselben Serie erscheinen in der Übersicht gemeinsam auf einer Kachel.</div>
      </div>
      <div class="form-field se-number">
        <label for="seasonInput">Staffel</label>
        <input type="text" id="seasonInput" inputmode="numeric" value="${val('season')}" placeholder="0 = Specials">
      </div>
    </div>
    <div class="form-field" id="mediaBlock">
      <span class="label">Medien</span>
      <div class="media-rows" id="mediaRows">${(isEdit ? m.media : []).map(md => mediumRowHtml(md, m.slug)).join('')}</div>
      <button class="btn" id="addMediumBtn" type="button">+ Medium</button>
      <div class="hint">Je Exemplar eine Zeile: Wem es gehört und in welcher Form. Keine Zeile = nicht im Bestand. Abgeben: Besitzer ändern; × entfernt das Medium.</div>
    </div>
    <div class="form-row">
      ${imageFieldHtml('poster', 'Poster', 'Hochformat, das Thumbnail entsteht automatisch. Klick aufs Bild wählt ein neues, × entfernt es; beides erst beim Speichern.')}
      ${imageFieldHtml('fanart', 'Hintergrundbild', 'Querformat, erscheint abgedunkelt hinter dem Kopf der Detailansicht. Klick aufs Bild wählt ein neues, × entfernt es.')}
    </div>
    <div class="form-field">
      <label for="plotInput">Handlung</label>
      <textarea id="plotInput">${escapeHtml(isEdit ? (m.plot || '') : '')}</textarea>
    </div>
    <!-- Folgen direkt nach der Handlung, wie in der Detailansicht -->
    <div class="form-field season-only">
      <label for="episodesInput">Folgen</label>
      <textarea id="episodesInput" class="episodes-input" rows="16" spellcheck="true" placeholder="1. Titel der ersten Folge&#10;Handlung …&#10;&#10;2. Titel der zweiten Folge&#10;Handlung …">${escapeHtml(isEdit && isSeason(m) ? episodesText(m.episodes) : '')}</textarea>
      <div class="hint"><strong id="episodeCount"></strong> Je Folge eine Zeile „Nummer. Titel“, darunter die Handlung; zwischen den Folgen eine Leerzeile.</div>
    </div>
    <div class="form-field">
      <label for="trailerInput">Trailer (YouTube-URL)</label>
      <input type="text" id="trailerInput" value="${val('trailer')}" placeholder="https://www.youtube.com/watch?v=...">
      <a class="field-link" id="trailerSearch" href="#" target="_blank" rel="noopener">Trailer auf YouTube suchen</a>
    </div>
    <div class="form-field">
      <span class="label">Darsteller</span>
      <div class="cast-rows" id="castRows">${(isEdit ? m.cast : []).map(c => castRowHtml(c, isEdit ? actorSrc(m, c) : null)).join('')}</div>
      <button class="btn" id="addCastBtn" type="button">+ Darsteller</button>
      <div class="hint">Reihenfolge wie in der Detailansicht. Ein neues Foto wird erst nach dem Speichern hochgeladen.</div>
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
  // Art umschalten: Felder der anderen Art aus-, eigene einblenden.
  const applyType = () => {
    const t = formType();
    editForm.querySelectorAll('.movie-only').forEach(el => { el.hidden = t !== 'movie'; });
    editForm.querySelectorAll('.season-only').forEach(el => { el.hidden = t !== 'season'; });
    document.getElementById('formTitle').textContent = isEdit
      ? `${typeLabel(t)} bearbeiten`
      : (t === 'season' ? 'Neue Staffel' : 'Neuer Film');
    document.getElementById('runtimeLabel').textContent = t === 'season' ? 'Folgenlänge (Min.)' : 'Laufzeit (Min.)';
  };

  document.getElementById('closeBtn').addEventListener('click', closeMovie);
  document.getElementById('cancelBtn').addEventListener('click', () => {
    isEdit ? openMovie(MOVIES.indexOf(m)) : closeMovie();
  });
  document.getElementById('typeInputs').addEventListener('change', applyType);
  // Folgen: Zähler bzw. Hinweis auf ein Formatproblem, laufend beim Tippen.
  const episodesInput = document.getElementById('episodesInput');
  const showEpisodeCount = () => {
    const {episodes, error} = parseEpisodesText(episodesInput.value);
    document.getElementById('episodeCount').textContent = error || `${countLabel(episodes.length, 'Folge', 'Folgen')} erkannt.`;
  };
  episodesInput.addEventListener('input', showEpisodeCount);
  showEpisodeCount();
  // Trailer suchen: YouTube-Suche nach Titel und Jahr aus dem Formular; die gefundene URL trägt man selbst ein.
  const trailerSearch = document.getElementById('trailerSearch');
  const updateTrailerSearch = () => {
    const words = [document.getElementById('titleInput').value.trim(), document.getElementById('yearInput').value.trim(), 'Trailer Deutsch'];
    trailerSearch.href = 'https://www.youtube.com/results?search_query=' + encodeURIComponent(words.filter(Boolean).join(' '));
  };
  updateTrailerSearch();
  ['titleInput', 'yearInput'].forEach(id => document.getElementById(id).addEventListener('input', updateTrailerSearch));
  const castRows = document.getElementById('castRows');
  document.getElementById('addCastBtn').addEventListener('click', () => {
    castRows.insertAdjacentHTML('beforeend', castRowHtml({name: '', role: null, profile: null, thumb: null}, null));
    castRows.lastElementChild.querySelector('.cast-entry').focus();
  });
  castRows.addEventListener('click', e => {
    const row = e.target.closest('.cast-row');
    if(e.target.closest('.cast-remove')) row.remove();
    if(e.target.closest('.cast-photo-remove')){
      // Foto weg: Dateiname nicht mehr mitschicken (der Server löscht die Datei), auch kein TMDB-Porträt nachladen.
      row.dataset.thumb = row.dataset.profile = '';
      row.querySelector('.cast-photo-input').value = '';
      showPreview(row.querySelector('.cast-photo'), null, parseCastEntry(row.querySelector('.cast-entry').value).name);
      e.target.closest('.cast-photo-remove').hidden = true;
    }
  });
  // Medien: Zeile hinzufügen (eigenes Exemplar, DVD) bzw. entfernen.
  const mediaRows = document.getElementById('mediaRows');
  document.getElementById('addMediumBtn').addEventListener('click', () => {
    mediaRows.insertAdjacentHTML('beforeend', mediumRowHtml({owner: ME || Object.keys(USERS)[0] || '', type: 'DVD'}, isEdit ? m.slug : null));
    mediaRows.lastElementChild.querySelector('.medium-type').focus();
  });
  mediaRows.addEventListener('click', e => {
    if(e.target.closest('.medium-remove')) e.target.closest('.medium-row').remove();
  });
  // Art eines Mediums gewechselt: Datei-Feld ein-/ausblenden, bei USB Dateien vorschlagen.
  // Am #editForm statt am dauerhaften #sheet, sonst käme bei jedem Öffnen ein weiterer Listener dazu.
  editForm.addEventListener('change', e => {
    if(e.target.classList.contains('medium-type')){
      const row = e.target.closest('.medium-row');
      if(isDigital(e.target.value)) suggestFiles(row);
      toggleFilesField(row);
    }
    if(e.target.classList.contains('files-input')) completeExtensions(e.target);
    // Neues Foto gewählt (Klick aufs Foto): in der Vorschau zeigen, hochgeladen wird erst nach dem Speichern.
    if(e.target.matches('.cast-photo-input') && e.target.files[0]){
      const row = e.target.closest('.cast-row');
      const reader = new FileReader();
      reader.onload = () => showPreview(row.querySelector('.cast-photo'), reader.result, '');
      reader.readAsDataURL(e.target.files[0]);
      row.querySelector('.cast-photo-remove').hidden = false;
    }
  });
  applyType();

  for(const kind of ['poster', 'fanart']){
    const field = document.getElementById(`${kind}Field`);
    const input = document.getElementById(`${kind}Input`);
    const preview = document.getElementById(`${kind}Preview`);
    const removeBtn = document.getElementById(`${kind}RemoveBtn`);
    const show = src => {
      preview.hidden = !src;
      if(src) preview.src = src;
      document.getElementById(`${kind}Empty`).hidden = !!src;
      removeBtn.hidden = !src;
    };
    input.addEventListener('change', () => {
      const file = input.files[0];
      if(!file) return;
      const reader = new FileReader();
      reader.onload = () => show(reader.result);
      reader.readAsDataURL(file);
      field.dataset.remove = ''; // ein neues Bild ersetzt das alte ohnehin
    });
    // Entfernen: eine gewählte Datei verwerfen; ein vorhandenes Bild löscht erst das Speichern.
    removeBtn.addEventListener('click', () => {
      input.value = '';
      show(null);
      field.dataset.remove = isEdit && m[kind] ? '1' : '';
    });
  }
  document.getElementById('saveBtn').addEventListener('click', () => saveMovie(isEdit ? m.slug : null));
}

/** Im Formular gewählte Art. @returns {'movie'|'season'} */
function formType(){
  return document.querySelector('#typeInputs input:checked').value === 'season' ? 'season' : 'movie';
}

/**
 * Eine Zeile im Formular je Medium: Besitzer, Art (feste plus alle schon verwendeten), "×"; bei Dateien (USB)
 * darunter die Dateien im Ordner des Eintrags, dazu der Speicherort und aufklappbar die Konvention für Dateinamen.
 * @param {Medium} md
 * @param {?string} slug  Ordner des Eintrags; null bei einem neuen (entsteht beim Speichern aus Titel und Jahr)
 */
function mediumRowHtml(md, slug){
  const owners = [...new Set([md.owner, ...Object.keys(USERS), ...(ME ? [ME] : [])])].filter(Boolean);
  const types = [...new Set([...MEDIUM_OPTIONS, ...knownValues(mediaOf), md.type])];
  const option = (value, label, selected) => `<option value="${escapeHtml(value)}"${selected ? ' selected' : ''}>${escapeHtml(label)}</option>`;
  const files = isDigital(md.type) ? md.files || [] : [];
  return `<div class="medium-row">
    <select class="medium-owner" aria-label="Besitzer">${owners.map(u => option(u, userName(u), u === md.owner)).join('')}</select>
    <select class="medium-type" aria-label="Art">${types.map(t => option(t, t, t === md.type)).join('')}</select>
    <button class="btn btn-small medium-remove" type="button" title="Medium entfernen" aria-label="Medium entfernen">&times;</button>
    <div class="form-field files-field"${isDigital(md.type) ? '' : ' hidden'}>
      <span class="label">Dateien im Ordner des Eintrags auf dem USB-Speicher</span>
      <textarea class="files-input" rows="2" aria-label="Dateien" placeholder="z. B. movie.avi">${escapeHtml(files.join('\n'))}</textarea>
      <div class="hint">Der Speicherort ist "&lt;Videothek-Ordner&gt;/${escapeHtml(slug || '<titel-jahr>')}/".</div>
      <details class="hint files-help">
        <summary>Konvention für Dateinamen</summary>
        Film: "movie.&lt;ext&gt;", mehrteilig eine Datei pro Zeile in Abspielreihenfolge (z.B. "movie-cd1.avi", "movie-cd2.avi").<br/> 
        Staffel: Eine Datei pro Folge (z.B. "S01E01.mkv", "S01E02.mkv", …).
      </details>
    </div>
  </div>`;
}

/** Bild bzw. Platzhalter in einer Vorschau-Box (Darstellerfoto). */
function showPreview(box, src, name){
  const initials = (name || '').split(/\s+/).filter(Boolean).map(w => w[0]).join('').slice(0, 2);
  box.innerHTML = src ? `<img src="${escapeHtml(src)}" alt="">` : `<span>${escapeHtml(initials)}</span>`;
}

let castRowSeq = 0; // eindeutige ids für label for=… (Klick aufs Foto öffnet die Dateiauswahl)

/**
 * Eine Darsteller-Zeile im Formular: Foto (Klick wählt ein neues, "×" in der Ecke entfernt es), ein Feld
 * "Name: Rolle" und "×" für den Darsteller. Dateiname des Fotos und TMDB-Porträtpfad stehen in
 * data-thumb/data-profile und gehen beim Speichern mit.
 * @param {CastMember} c
 * @param {?string} src  vorhandenes Foto
 */
function castRowHtml(c, src){
  const id = `castPhoto${++castRowSeq}`;
  const initials = c.name.split(/\s+/).filter(Boolean).map(w => w[0]).join('').slice(0, 2);
  return `<div class="cast-row" data-thumb="${escapeHtml(c.thumb || '')}" data-profile="${escapeHtml(c.profile || '')}">
    <div class="cast-photo-wrap">
      <label class="cast-photo" for="${id}" title="Foto auswählen">${src ? `<img src="${escapeHtml(src)}" alt="" loading="lazy">` : `<span>${escapeHtml(initials)}</span>`}</label>
      <input type="file" id="${id}" class="cast-photo-input" accept="image/jpeg,image/png,image/webp" hidden>
      <button class="cast-photo-remove" type="button" title="Foto entfernen" aria-label="Foto entfernen"${c.thumb ? '' : ' hidden'}>&times;</button>
    </div>
    <input type="text" class="cast-entry" value="${escapeHtml(castEntryText(c))}" placeholder="Name: Rolle" aria-label="Name: Rolle">
    <button class="btn btn-small cast-remove" type="button" title="Darsteller entfernen" aria-label="Darsteller entfernen">&times;</button>
  </div>`;
}

/** "Name: Rolle" bzw. nur "Name" ohne Rolle. @param {CastMember} c */
function castEntryText(c){
  return c.role ? `${c.name}: ${c.role}` : c.name;
}

/**
 * Gegenstück zu castEntryText(): Name bis zum ersten Doppelpunkt, der Rest ist die Rolle.
 * @param {string} text
 * @returns {{name: string, role: ?string}}
 */
function parseCastEntry(text){
  const pos = text.indexOf(':');
  return pos < 0
    ? {name: text.trim(), role: null}
    : {name: text.slice(0, pos).trim(), role: text.slice(pos + 1).trim() || null};
}

/**
 * Folgen als Text fürs Formular: je Folge eine Zeile "Nummer. Titel", darunter die Handlung, zwischen den Folgen eine
 * Leerzeile. Gegenstück: parseEpisodesText().
 * @param {Episode[]} episodes
 */
function episodesText(episodes){
  return episodes.map(e => [`${e.number}.${e.title ? ' ' + e.title : ''}`, ...(e.plot ? [e.plot] : [])].join('\n')).join('\n\n');
}

/**
 * Liest den Text aus episodesText() zurück. Eine Folge beginnt mit "Nummer." (dahinter optional der Titel), aber nur
 * am Anfang oder nach einer Leerzeile; so darf eine Handlung auch mit "3. Oktober …" weitergehen. Die Zeilen bis zur
 * nächsten Folge sind die Handlung (Leerzeilen darin entfallen, wie auf dem Server). Text vor der ersten Folge ist
 * ein Fehler. Doppelte Nummern prüft der Server.
 * @param {string} text
 * @returns {{episodes: Episode[], error: ?string}}
 */
function parseEpisodesText(text){
  /** @type {Episode[]} */
  const episodes = [];
  let afterBlank = true;
  let error = null;
  for(const raw of text.replace(/\r/g, '').split('\n')){
    const line = raw.trim();
    if(!line){
      afterBlank = true;
      continue;
    }
    const head = afterBlank ? line.match(/^(\d+)\.(?:\s+(.*))?$/) : null;
    afterBlank = false;
    if(head){
      episodes.push({number: parseInt(head[1], 10), title: head[2] ? head[2].trim() : null, plot: null});
    } else if(episodes.length){
      const e = episodes[episodes.length - 1];
      e.plot = e.plot ? `${e.plot}\n${line}` : line;
    } else {
      error ??= `Vor der ersten Folge steht Text ohne Nummer („${line.slice(0, 40)}“). Eine Folge beginnt mit „1. Titel“.`;
    }
  }
  return {episodes, error};
}

/** Datei-Feld einer Medienzeile nur bei Dateien (USB) zeigen (eingetragene Dateien bleiben erhalten). */
function toggleFilesField(row){
  row.querySelector('.files-field').hidden = !isDigital(row.querySelector('.medium-type').value);
}

/**
 * Bei Wechsel auf USB ein leeres Datei-Feld mit dem üblichen Namen vorbelegen: Film `movie.`, Staffel E01., E02., …
 * (eine Zeile pro Folge). Die Endung (avi, mkv, …) ist nicht bekannt und wird ergänzt (completeExtensions()).
 */
function suggestFiles(row){
  const filesInput = row.querySelector('.files-input');
  if(filesInput.value.trim()) return;
  if(formType() !== 'season'){
    filesInput.value = 'movie.';
    return;
  }
  const episodes = parseEpisodesText(document.getElementById('episodesInput').value).episodes.map(e => e.number);
  filesInput.value = (episodes.length ? episodes : [1]).map(e => `E${pad2(e)}.`).join('\n');
}

/** Überträgt die Dateiendung der ersten Zeile mit Endung auf alle Zeilen, die noch auf "." enden (Vorschlag). */
function completeExtensions(textarea){
  const lines = textarea.value.split('\n');
  const ext = lines.map(l => l.trim().match(/\.([a-z0-9]+)$/i)).find(Boolean);
  if(!ext) return;
  textarea.value = lines.map(l => l.trim().endsWith('.') ? l.trim() + ext[1] : l).join('\n');
}

/** Medien aus den Zeilen des Formulars. @returns {Medium[]} */
function formMedia(){
  return [...document.querySelectorAll('#mediaRows .medium-row')].map(row => {
    const md = {owner: row.querySelector('.medium-owner').value, type: row.querySelector('.medium-type').value};
    return isDigital(md.type) ? {...md, files: textLines(row.querySelector('.files-input').value)} : md;
  });
}

/** Nicht leere Zeilen eines Textfelds, getrimmt. @param {string} value @returns {string[]} */
function textLines(value){
  return value.split('\n').map(s => s.trim()).filter(Boolean);
}

/**
 * Liest die Formularfelder aus und baut daraus den API-Payload (je nach gewählter Art).
 * @param {?Movie} [current]  bearbeiteter Eintrag (null beim Anlegen)
 */
function collectFormData(current = null){
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
    cast: [...document.querySelectorAll('#castRows .cast-row')].map(row => ({
      ...parseCastEntry(row.querySelector('.cast-entry').value),
      profile: row.dataset.profile || null,
      thumb: row.dataset.thumb || null,
    })).filter(c => c.name),
    trailer: text('trailerInput') || null,
    plot: text('plotInput') || null,
    notes: text('notesInput') || null,
    media: formMedia(),
  };

  if(type === 'season'){
    return {
      ...common,
      series: formGroup(text('seriesInput'), x => x.series, current),
      season: number(text('seasonInput')),
      creator: text('creatorInput') || null,
      episodes: parseEpisodesText(document.getElementById('episodesInput').value).episodes,
    };
  }
  return {
    ...common,
    collection: withPart(formGroup(text('collectionInput'), x => x.collection, current), number(text('partInput'))),
    director: text('directorInput') || null,
  };
}

/**
 * Reihe bzw. Serie zum eingegebenen Namen. Hatte der Eintrag schon diesen Namen, behält er seine TMDB-ID (die
 * Shaun-das-Schaf-DVDs sind bei TMDB verschiedene Serien unter einem Namen). Sonst wird die TMDB-ID einer
 * vorhandenen Gruppe dieses Namens übernommen, bei einem neuen Namen ist sie leer.
 * @param {string} name
 * @param {function(Movie): ?Collection} of  m => m.collection bzw. m => m.series
 * @param {?Movie} current  bearbeiteter Eintrag oder null
 * @returns {?Collection}
 */
function formGroup(name, of, current){
  if(!name) return null;
  const same = g => g && g.name.trim().toLowerCase() === name.toLowerCase();
  const own = current ? of(current) : null;
  const existing = same(own) ? own : MOVIES.map(of).find(same);
  // Umbenannt (kein anderer Eintrag trägt den neuen Namen): Die eigene TMDB-ID bleibt, damit Import und
  // "2 von 7" die Reihe weiter erkennen.
  return {name, tmdb: existing ? existing.tmdb : (own ? own.tmdb : null)};
}

/**
 * Nach dem Speichern: Hat sich der Name der Reihe bzw. Serie geändert, wird gefragt, ob die übrigen Teile der
 * bisherigen Gruppe mit umbenannt werden sollen (sonst zerfällt sie in zwei Gruppen). Jeder Teil behält seine TMDB-ID
 * und Teilnummer. Liefert Fehlermeldungen; MOVIES wird für die umbenannten Teile aktualisiert.
 * @param {Movie} before  Eintrag vor dem Speichern
 * @param {Movie} saved  gespeicherter Eintrag
 * @returns {Promise<string[]>}
 */
async function renameGroupSiblings(before, saved){
  const oldGroup = groupOf(before), newGroup = groupOf(saved);
  if(!oldGroup || !newGroup || isSeason(before) !== isSeason(saved) || oldGroup.name === newGroup.name) return [];
  const key = groupKey(before);
  const siblings = MOVIES.filter(x => x.slug !== before.slug && groupKey(x) === key);
  if(!siblings.length) return [];
  const label = isSeason(saved) ? 'Serie' : 'Reihe';
  const others = siblings.length === 1 ? 'dem anderen Teil' : `den anderen ${siblings.length} Teilen`;
  if(!window.confirm(`${label} auch bei ${others} von „${oldGroup.name}“ in „${newGroup.name}“ umbenennen?`)) return [];

  const field = isSeason(saved) ? 'series' : 'collection';
  const errors = [];
  for(const sibling of siblings){
    try {
      const url = `api/movies.php?slug=${encodeURIComponent(sibling.slug)}`;
      /** @type {Movie} */
      const fresh = await fetch(url).then(res => { if(!res.ok) throw new Error(`HTTP ${res.status}`); return res.json(); });
      fresh[field] = {...fresh[field], name: newGroup.name};
      const updated = withDefaults(await apiWrite(url, {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(fresh),
      }));
      MOVIES = MOVIES.map(x => x.slug === sibling.slug ? {...updated, thumbHash: x.thumbHash} : x);
    } catch(err){
      errors.push(`${sibling.title}: ${err.message}`);
    }
  }
  return errors;
}

/**
 * Reihe mit der Teilnummer aus dem Formular (ohne Reihe keine Nummer).
 * @param {?Collection} collection
 * @param {number|string|null} part  ungültige Eingaben lehnt der Server mit Meldung ab
 * @returns {?{name: string, tmdb: ?string, part: (number|string|null)}}  Payload, part ggf. noch ungeprüft
 */
function withPart(collection, part){
  return collection ? {name: collection.name, tmdb: collection.tmdb, part} : null;
}

async function saveMovie(slug){
  const before = slug ? MOVIES.find(x => x.slug === slug) || null : null;
  const payload = collectFormData(before);
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
  const episodesError = payload.type === 'season' ? parseEpisodesText(document.getElementById('episodesInput').value).error : null;
  if(episodesError){
    showError(episodesError);
    return;
  }
  const keys = payload.media.map(mediumKey);
  const twice = payload.media.find((md, k) => keys.indexOf(mediumKey(md)) !== k);
  if(twice){
    showError(`${mediumLabel(twice)} ist doppelt eingetragen.`);
    return;
  }
  for(const md of payload.media.filter(x => isDigital(x.type))){
    if(!md.files.length){
      showError(`Bei ${mediumLabel(md)} bitte ${payload.type === 'season' ? 'die Dateien der Folgen' : 'die Filmdatei'} angeben.`);
      return;
    }
    // Fängt den nicht vervollständigten Vorschlag "movie." bzw. "E01." ab.
    if(md.files.some(f => !/\.[a-z0-9]+$/i.test(f))){
      showError(`Bei ${mediumLabel(md)} fehlt mindestens einer Datei die Dateiendung (z. B. .avi, .mkv, .mp4).`);
      return;
    }
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
  /** @type {{label: string, fields: Object<string, string|Blob>}[]} */
  const uploads = [];
  for(const [kind, label] of [['poster', 'Poster'], ['fanart', 'Hintergrundbild']]){
    const file = document.getElementById(`${kind}Input`).files[0];
    if(file) uploads.push({label, fields: {kind, image: file}});
    else if(document.getElementById(`${kind}Field`).dataset.remove === '1') uploads.push({label, fields: {kind, remove: '1'}});
  }
  document.querySelectorAll('#castRows .cast-row').forEach(row => {
    const file = row.querySelector('.cast-photo-input').files[0];
    const name = parseCastEntry(row.querySelector('.cast-entry').value).name;
    if(file && name) uploads.push({label: `Foto ${name}`, fields: {kind: 'actor', actor: name, image: file}});
  });
  for(const {label, fields} of uploads){
    try {
      const fd = new FormData();
      fd.append('slug', saved.slug);
      for(const [key, value] of Object.entries(fields)) fd.append(key, value);
      saved = await apiWrite('api/upload.php', {method: 'POST', body: fd});
    } catch(err){
      imageErrors.push(`${label}: ${err.message}`);
    }
  }

  saved = withDefaults(saved);
  MOVIES = MOVIES.filter(x => x.slug !== slug && x.slug !== saved.slug);
  MOVIES.push(saved);
  const renameErrors = before ? await renameGroupSiblings(before, saved) : [];
  await loadLocations(); // der Server zieht Standorte beim Umbenennen mit und entfernt die Medien
  refreshFilters();
  updateHeaderCount();
  renderGrid();
  openMovie(MOVIES.indexOf(saved));
  if(imageErrors.length){
    alert(`${typeLabel(saved.type)} gespeichert, aber Bild-Upload fehlgeschlagen:\n` + imageErrors.join('\n'));
  }
  if(renameErrors.length){
    alert('Gespeichert, aber nicht alle Teile umbenannt:\n' + renameErrors.join('\n'));
  }
  // Der Ordner auf dem USB-Speicher heißt wie der Eintrag: Nach einer Titel- oder Jahresänderung muss er mit.
  const usbOwners = saved.media.filter(md => isDigital(md.type)).map(md => userName(md.owner));
  if(before && before.slug !== saved.slug && usbOwners.length){
    alert(`Der Ordner des Eintrags heißt jetzt „${saved.slug}“. Bitte auf dem USB-Speicher den Ordner „${before.slug}“ `
      + `genauso umbenennen (${usbOwners.join(', ')}), sonst findet der Player die Datei nicht.`);
  }
}

/** @param {Movie} m */
async function deleteMovieUI(m){
  if(!window.confirm(`"${m.title}" wirklich unwiderruflich löschen?`)) return;
  try {
    await apiWrite(`api/movies.php?slug=${encodeURIComponent(m.slug)}`, {method: 'DELETE'});
    MOVIES = MOVIES.filter(x => x.slug !== m.slug);
    delete LOCATIONS[m.slug];
    refreshFilters();
    updateHeaderCount();
    closeMovie();
    renderGrid();
  } catch(err){
    alert('Löschen fehlgeschlagen: ' + err.message);
  }
}

grid.addEventListener('click', e => {
  if(e.target.closest('#collectionCloseBtn')){
    toggleGroup(null);
    return;
  }
  const tile = e.target.closest('.collection-card');
  if(tile){
    toggleGroup(tile.dataset.group);
    return;
  }
  const card = e.target.closest('.card');
  if(card) openMovie(parseInt(card.dataset.idx, 10));
});
// Detailansicht: anderen Teil der Reihe bzw. andere Staffel der Serie öffnen (einmal am dauerhaften #sheet, nicht pro Öffnen).
sheet.addEventListener('click', e => {
  // Handlung einer Folge auf-/zuklappen
  const toggle = e.target.closest('.ep-toggle');
  if(toggle){
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(open));
    toggle.title = open ? 'Handlung ausblenden' : 'Handlung anzeigen';
    toggle.closest('li').querySelector('.ep-plot').hidden = !open;
    return;
  }
  // Abspielen: erst den USB-Ordner dieses Geräts bestätigen bzw. ändern (leer = Vorgabe aus config.php, gemerkt im
  // Browser), dann die Wiedergabeliste mit diesem Ordner laden. Abbrechen spielt nichts ab.
  const play = e.target.closest('.play-btn, .ep-play');
  if(play){
    e.preventDefault();
    void playWithFolder(play);
    return;
  }
  // Medien: "ausleihen" bzw. "zurückgeben"
  const lend = e.target.closest('.lend-btn, .return-btn');
  if(lend){
    void lendMedium(lend);
    return;
  }
  const part = e.target.closest('.collection-parts li[data-idx]');
  if(part && !part.classList.contains('current')) openMovie(parseInt(part.dataset.idx, 10));
});
let resizeTimer = 0;
window.addEventListener('resize', () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(placeGroupPanel, 150);
});
overlay.addEventListener('click', e => {
  if(e.target === overlay && !formIsOpen) closeMovie();
});
document.addEventListener('keydown', e => {
  if(e.key !== 'Escape' || formIsOpen) return;
  if(overlay.classList.contains('open')) closeMovie();
  else if(expandedGroup) toggleGroup(null);
});
const VIEW_STATE_KEY = 'videothek_view_state';

/** Speichert Suchbegriff, Filter und Sortierung, damit sie einen Reload überstehen. */
function saveViewState(){
  localStorage.setItem(VIEW_STATE_KEY, JSON.stringify({
    search: searchInput.value,
    type: typeSel.value,
    genre: genreSel.value,
    medium: mediumSel.value,
    owner: ownerSel.value,
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
ownerSel.addEventListener('change', onFilterChange);
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
        <label for="importQueryInput">Titel, IMDb-ID oder Barcode</label>
        <input type="text" id="importQueryInput" placeholder="z. B. Die Goonies, tt0089218 oder 4012909509665">
      </div>
      <div class="form-field search-year">
        <label for="importYearInput">Jahr (optional)</label>
        <input type="text" id="importYearInput" inputmode="numeric">
      </div>
    </div>
    <div class="btn-row">
      <button class="btn btn-primary" id="searchBtn" type="button">Suchen</button>
      <button class="btn" id="scanBtn" type="button"${canScan() ? '' : ' hidden'}>Barcode scannen</button>
      <button class="btn" id="cancelBtn" type="button">Abbrechen</button>
    </div>
    <div class="scan-box" id="scanBox" hidden>
      <video id="scanVideo" playsinline muted></video>
      <p class="hint">Barcode der DVD-Hülle ins Bild halten …</p>
      <button class="btn" id="scanStopBtn" type="button">Scan abbrechen</button>
    </div>
    <div id="searchResults"></div>
    <p class="tmdb-note">
      Daten und Bilder stammen von <a href="https://www.themoviedb.org" target="_blank" rel="noopener">The Movie Database (TMDB)</a>.
      Als Medium wird ${escapeHtml(ME ? `${possessive(userName(ME))} DVD` : 'eine DVD')} eingetragen (im Formular änderbar).
      Eine Serie wird zu einem Eintrag pro Staffel; vorhandene Staffeln bleiben, wie sie sind, fehlende kommen dazu.
    </p>
  `;
  overlay.classList.add('open');
  document.getElementById('closeBtn').addEventListener('click', closeMovie);
  document.getElementById('cancelBtn').addEventListener('click', closeMovie);
  document.getElementById('searchBtn').addEventListener('click', runSearch);
  document.getElementById('scanBtn').addEventListener('click', openScan);
  document.getElementById('scanStopBtn').addEventListener('click', stopScan);
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

  resultsBox.innerHTML = /^\d{8,13}$/.test(query)
    ? '<p class="empty">Suche den Titel zum Barcode …</p>'
    : '<p class="empty">Suche läuft …</p>';
  /** @type {{results: SearchResult[], error?: string, ean?: string, query?: ?string}} */
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

  // Barcode: Der aus der Websuche gewonnene Titel ersetzt die Nummer im Suchfeld (lässt sich dort korrigieren).
  let eanNote = '';
  if(data.ean){
    if(data.query) document.getElementById('importQueryInput').value = data.query;
    eanNote = data.query
      ? `<p class="ean-note">Barcode ${escapeHtml(data.ean)} → „${escapeHtml(data.query)}“</p>`
      : `<p class="empty">Zum Barcode ${escapeHtml(data.ean)} wurde kein Titel gefunden. Bitte den Titel eintippen.</p>`;
    if(!data.query){
      resultsBox.innerHTML = eanNote;
      return;
    }
  }
  if(!data.results.length){
    resultsBox.innerHTML = eanNote + '<p class="empty">Keine Treffer.</p>';
    return;
  }
  const statusText = r => r.existing ? 'bereits in der Videothek – anklicken zum Öffnen'
    : r.existingSeasons ? `${countLabel(r.existingSeasons, 'Staffel', 'Staffeln')} schon in der Videothek – anklicken ergänzt weitere` : '';
  resultsBox.innerHTML = eanNote + `<ul class="search-results">${data.results.map(r => `
    <li class="${r.existing ? 'exists' : ''}" data-tmdb="${r.tmdb}" data-type="${r.type}" data-slug="${escapeHtml(r.existing || '')}">
      <div class="result-poster">${r.poster ? `<img src="${escapeHtml(r.poster)}" alt="" loading="lazy">` : ''}</div>
      <div class="result-body">
        <div class="result-title"><span class="result-type${r.type === 'series' ? ' series' : ''}">${typeLabel(r.type)}</span>${escapeHtml(r.title)}${r.year ? ` <span>(${r.year})</span>` : ''}</div>
        ${r.originalTitle ? `<div class="result-original">${escapeHtml(r.originalTitle)}</div>` : ''}
        <div class="result-status">${escapeHtml(statusText(r))}</div>
        ${r.overview ? `<div class="result-overview">${escapeHtml(r.overview)}</div>` : ''}
      </div>
    </li>`).join('')}</ul>`;

  resultsBox.querySelectorAll('li').forEach(li => li.addEventListener('click', e => {
    if(li.dataset.slug){
      const idx = MOVIES.findIndex(m => m.slug === li.dataset.slug);
      if(idx >= 0) openMovie(idx);
    } else if(li.dataset.type === 'series'){
      if(!e.target.closest('.import-seasons')) askSeasons(li);
    } else {
      void runImport(parseInt(li.dataset.tmdb, 10), 'movie', li);
    }
  }));
}

/**
 * Fragt bei einem Serien-Treffer, welche Staffeln importiert werden sollen (nur diese werden angelegt, mit dem
 * Medium DVD), und startet dann den Import.
 * @param {HTMLElement} li
 */
function askSeasons(li){
  if(li.querySelector('.import-seasons')) return;
  li.querySelector('.result-body').insertAdjacentHTML('beforeend', `<div class="import-seasons">
    <label>Staffel <input type="text" class="seasons-input" placeholder="z. B. 2 oder 1-3, 5"></label>
    <button class="btn btn-primary btn-small" type="button">Importieren</button>
  </div>`);
  const input = li.querySelector('.seasons-input');
  const start = () => {
    const seasons = parseSeasonList(input.value);
    if(!seasons){
      document.getElementById('formError').innerHTML = '<div class="error-box">Staffel bitte als Nummer angeben, z. B. 2 oder 1-3, 5.</div>';
      return;
    }
    void runImport(parseInt(li.dataset.tmdb, 10), 'series', li, seasons);
  };
  li.querySelector('.import-seasons button').addEventListener('click', start);
  input.addEventListener('keydown', e => { if(e.key === 'Enter') start(); });
  input.focus();
}

/**
 * "1-3, 5" -> [1, 2, 3, 5]; leer -> null, Ungültiges -> false.
 * @param {string} text
 * @returns {number[]|null|false}
 */
function parseSeasonList(text){
  if(!text.trim()) return null;
  const numbers = [];
  for(const part of text.split(/[,;\s]+/).filter(Boolean)){
    const m = part.match(/^(\d+)(?:-(\d+))?$/);
    if(!m) return false;
    const from = parseInt(m[1], 10), to = m[2] ? parseInt(m[2], 10) : from;
    if(to < from || to - from > 100) return false;
    for(let n = from; n <= to; n++) numbers.push(n);
  }
  return numbers;
}

// --- Barcode-Scan im Import-Dialog ------------------------------------------------------------------------------
// Chrome auf Android erkennt Barcodes selbst (BarcodeDetector). Safari auf dem iPhone kann das nicht, dort übernimmt
// vendor/zxing.min.js (ZXing, ~330 KB, wird erst beim ersten Scan geladen). Die Kamera gibt der Browser nur über
// HTTPS (oder localhost) frei, sonst fehlt navigator.mediaDevices und der Button bleibt ausgeblendet.
// Der erkannte Code landet im Suchfeld und läuft durch die normale Suche; search.php erkennt die EAN.

const SCAN_FORMATS = ['ean_13', 'ean_8', 'upc_a'];
// Ohne Größenangabe liefert Chrome nur 640 × 480; damit las der BarcodeDetector die feinen Striche oft nicht. Mit
// 1920 × 1080 erkannte er im Vergleich zuverlässig (Android-Smartphone, Oktober 2026).
const SCAN_CONSTRAINTS = {
  video: {facingMode: {ideal: 'environment'}, width: {ideal: 1920}, height: {ideal: 1080}},
  audio: false,
};
/** @type {?{stop: function(): void}} */
let activeScan = null;
let scanSeq = 0;      // erkennt, dass ein Scan abgebrochen wurde, während die Kamera noch startete
/** @type {?Promise<typeof ZXing>} Promise des einmaligen Nachladens */
let zxingLoad = null;

function canScan(){
  return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}

/** BarcodeDetector des Browsers für EAN/UPC oder null, wenn es keinen gibt (Safari, Firefox). */
async function nativeBarcodeDetector(){
  const Detector = window.BarcodeDetector;
  if(!Detector) return null;
  try {
    /** @type {string[]} */
    const supported = await Detector.getSupportedFormats();
    const formats = SCAN_FORMATS.filter(f => supported.includes(f));
    return formats.length ? new Detector({formats}) : null;
  } catch {
    return null;
  }
}

/**
 * Lädt vendor/zxing.min.js einmalig nach (Typen für die IDE: types/scanner.d.ts).
 * @returns {Promise<typeof ZXing>}
 */
function loadZxing(){
  zxingLoad ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'vendor/zxing.min.js';
    script.onload = () => resolve(window.ZXing);
    script.onerror = () => {
      zxingLoad = null;
      reject(new Error('Die Barcode-Erkennung konnte nicht geladen werden.'));
    };
    document.head.appendChild(script);
  });
  return zxingLoad;
}

/**
 * Schaltet den Dauer-Autofokus ein, falls die Kamera ihn anbietet (sonst bleibt der Fokus oft auf dem ersten Bild
 * stehen). Fehler bleiben still, dann scannt es eben mit dem Standardfokus.
 * @param {MediaStream} stream
 */
async function useContinuousFocus(stream){
  const track = stream.getVideoTracks()[0];
  try {
    // focusMode fehlt in den Standard-Typen (Chrome kennt es trotzdem), daher die Casts.
    const caps = /** @type {{focusMode?: string[]}} */ (track && track.getCapabilities ? track.getCapabilities() : {});
    if((caps.focusMode || []).includes('continuous')){
      await track.applyConstraints(/** @type {MediaTrackConstraints} */ ({advanced: [{focusMode: 'continuous'}]}));
    }
  } catch {
    // nicht unterstützt
  }
}

/**
 * Startet die Rückkamera im video-Element und ruft onCode einmal mit dem ersten erkannten Barcode auf; die Kamera
 * ist dann schon wieder aus.
 * @param {HTMLVideoElement} video
 * @param {function(string): void} onCode
 * @returns {Promise<{stop: function(): void}>}
 */
async function startScan(video, onCode){
  let stopped = false;
  const detector = await nativeBarcodeDetector();
  if(detector){
    const stream = await navigator.mediaDevices.getUserMedia(SCAN_CONSTRAINTS);
    await useContinuousFocus(stream);
    const stop = () => {
      stopped = true;
      stream.getTracks().forEach(t => t.stop());
      video.srcObject = null;
    };
    video.srcObject = stream;
    await video.play();
    const tick = async () => {
      if(stopped) return;
      try {
        const codes = await detector.detect(video);
        if(codes.length && !stopped){
          stop();
          onCode(codes[0].rawValue);
          return;
        }
      } catch {
        // einzelnes Bild nicht auswertbar, nächstes versuchen
      }
      setTimeout(tick, 150);
    };
    void tick();
    return {stop};
  }

  const ZXing = await loadZxing();
  const hints = new Map([[ZXing.DecodeHintType.POSSIBLE_FORMATS,
    [ZXing.BarcodeFormat.EAN_13, ZXing.BarcodeFormat.EAN_8, ZXing.BarcodeFormat.UPC_A]]]);
  const reader = new ZXing.BrowserMultiFormatReader(hints);
  const stop = () => {
    stopped = true;
    reader.reset();
  };
  await reader.decodeFromConstraints(SCAN_CONSTRAINTS, video, result => {
    if(result && !stopped){
      stop();
      onCode(result.getText());
    }
  });
  return {stop};
}

async function openScan(){
  const box = document.getElementById('scanBox');
  const errorBox = document.getElementById('formError');
  errorBox.innerHTML = '';
  box.hidden = false;
  const seq = ++scanSeq;
  try {
    const scan = await startScan(document.getElementById('scanVideo'), code => {
      activeScan = null;
      box.hidden = true;
      document.getElementById('importQueryInput').value = code;
      document.getElementById('searchBtn').click();
    });
    if(seq === scanSeq && !box.hidden) activeScan = scan;
    else scan.stop(); // während des Kamerastarts abgebrochen oder Dialog geschlossen
  } catch(err){
    if(seq !== scanSeq) return;
    box.hidden = true;
    const msg = err && err.name === 'NotAllowedError'
      ? 'Der Zugriff auf die Kamera wurde verweigert. Bitte in den Browser-Einstellungen erlauben.'
      : err && err.name === 'NotFoundError' ? 'Keine Kamera gefunden.' : `Kamera nicht verfügbar: ${err.message}`;
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(msg)}</div>`;
  }
}

/** Beendet einen laufenden Scan (Button, Schließen des Dialogs). */
function stopScan(){
  scanSeq++;
  if(activeScan){
    activeScan.stop();
    activeScan = null;
  }
  const box = document.getElementById('scanBox');
  if(box) box.hidden = true;
}

/**
 * Importiert den Film bzw. die Serie (je Staffel ein Eintrag) und lädt danach alle Darstellerfotos.
 * @param {number} tmdb
 * @param {'movie'|'series'} type
 * @param {HTMLElement} li  angeklickter Treffer (zeigt den Fortschritt)
 * @param {number[]} [seasons]  Serien: die zu importierenden Staffeln
 */
async function runImport(tmdb, type, li, seasons = []){
  const errorBox = document.getElementById('formError');
  if(document.querySelector('.search-results li.importing')) return; // kein Doppel-Import
  const status = li.querySelector('.result-status');
  errorBox.innerHTML = '';
  li.classList.add('importing');
  li.querySelector('.import-seasons')?.remove();
  status.textContent = type === 'series'
    ? `Importiere ${seasons.length > 1 ? 'die Staffeln' : 'die Staffel'} mit Folgen, Cover und Hintergrundbild …`
    : 'Importiere Film, Poster und Hintergrundbild …';

  /** @type {Movie[]} */
  let entries;
  const warnings = [];
  try {
    /** @type {Movie & {entries?: Movie[]}} */
    const res = await apiWrite('api/import.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(type === 'series' ? {tmdb, type, seasons} : {tmdb, type}),
    });
    entries = res.entries || [res];
    if(res.import_warning) warnings.push(res.import_warning);
  } catch(err){
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(err.message)}</div>`;
    li.classList.remove('importing');
    status.textContent = '';
    return;
  }

  for(const [n, entry] of entries.entries()){
    const label = entries.length > 1 ? `${shortTitle(withDefaults(entry))} (${n + 1} von ${entries.length}): ` : '';
    entries[n] = withDefaults(await loadActorPhotos(entry, text => { status.textContent = label + text; }, warnings));
  }

  const slugs = new Set(entries.map(x => x.slug));
  MOVIES = MOVIES.filter(x => !slugs.has(x.slug));
  MOVIES.push(...entries);
  await loadCatalog(); // Der Import hat die Teile bzw. Staffeln der Reihe/Serie festgehalten.
  refreshFilters();
  updateHeaderCount();
  renderGrid();
  openMovie(MOVIES.indexOf(entries.find(isOwned) || entries[0]));
  if(warnings.length){
    alert([...new Set(warnings)].join('\n'));
  }
}

/**
 * Lädt die Darstellerfotos eines neuen Eintrags über api/actors.php. Jeder Aufruf dort arbeitet nur ~10 s
 * (PHP-Zeitlimit), daher wird wiederholt, bis nichts mehr fehlt. Liefert den aktualisierten Eintrag.
 * @param {Movie} entry
 * @param {function(string): void} progress
 * @param {string[]} warnings  hier landen Probleme
 * @returns {Promise<Movie>}
 */
async function loadActorPhotos(entry, progress, warnings){
  const total = entry.cast.filter(c => c.profile && !c.thumb).length;
  try {
    let remaining = total;
    while(remaining > 0){
      progress(`Lade Darstellerfotos … ${total - remaining} von ${total}`);
      /** @type {ActorsResult} */
      const res = await apiWrite('api/actors.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({slug: entry.slug}),
      });
      entry = res.movie;
      if(res.failed) warnings.push(`${res.failed} Darstellerfoto(s) konnten nicht geladen werden.`);
      if(res.remaining >= remaining && !res.loaded && !res.failed) break; // kein Fortschritt -> nicht endlos wiederholen
      remaining = res.remaining;
    }
  } catch(err){
    warnings.push('Darstellerfotos unvollständig: ' + err.message);
  }
  return entry;
}

async function init(){
  try {
    const movies = loadMovies();
    await Promise.all(/** @type {Promise<unknown>[]} */ ([movies, loadCatalog(), loadUsers(), loadLocations()]));
    MOVIES = await movies;
  } catch(err) {
    console.error(err);
    subCount.textContent = 'Die Videothek konnte nicht geladen werden.';
    grid.innerHTML = '<p class="empty">Fehler beim Laden der Filme und Staffeln.</p>';
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
  if(saved.owner && !ownerSel.hidden && [...ownerSel.options].some(o => o.value === saved.owner)) ownerSel.value = saved.owner;

  renderGrid();
}

void init();

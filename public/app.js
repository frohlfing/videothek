/**
 * @typedef {Object} CastMember
 * @property {string} name
 * @property {?string} role
 * @property {?string} profile  TMDB-Porträtpfad (Quelle für den Download durch api/actors.php)
 * @property {?string} thumb    heruntergeladenes Foto, z. B. "Sean_Astin.jpg" (data/movies/<slug>/actors/)
 */

/**
 * @typedef {Object} Movie
 * @property {string} slug
 * @property {string} title
 * @property {?string} originalTitle
 * @property {?number} year
 * @property {string[]} genres
 * @property {?number} runtime   Laufzeit in Minuten
 * @property {?string} fsk       "0", "6", "12", "16" oder "18"
 * @property {?string} director
 * @property {CastMember[]} cast
 * @property {?string} medium    z. B. "DVD", "Blu-ray", "USB"
 * @property {string[]} [files]  nur bei Medium USB: Filmdatei(en) relativ zu USB_ROOT (config.php)
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

/** @type {Movie[]} */
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

// PHP liefert die Filmliste über die JSON-API (api/movies.php).
/** @returns {Promise<Movie[]>} */
async function loadMovies(){
  /** @type {{movies: Movie[]}} */
  const data = await fetch('api/movies.php').then(res => res.json());
  return data.movies;
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

/** "1985 · 114 Min. · FSK 12" — leere Angaben fallen weg. @param {Movie} m */
function metaLine(m){
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
    <div class="thumb">${img}</div>
    <div class="body">
      <h3>${escapeHtml(m.title)}</h3>
      <div class="meta">${escapeHtml(metaLine(m))}</div>
      ${m.genres.slice(0, 2).map(g => `<div class="cat-tag">${escapeHtml(g)}</div>`).join('')}
      ${m.medium ? `<div class="cat-tag medium-tag">${escapeHtml(m.medium)}</div>` : ''}
    </div>
  </div>`;
}

/** Alle bereits verwendeten Werte eines Feldes (aus dem aktuellen MOVIES-Stand; Listenfelder wie genres werden aufgefaltet), alphabetisch, ohne Duplikate. */
function knownValues(field){
  return [...new Set(MOVIES.flatMap(m => m[field]).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'de'));
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
  const fill = (select, field, allLabel) => {
    const values = knownValues(field);
    const current = select.value;
    select.innerHTML = `<option value="">${allLabel}</option>` + values.map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');
    select.value = values.includes(current) ? current : '';
  };
  fill(genreSel, 'genres', 'Alle Genres');
  fill(mediumSel, 'medium', 'Alle Medien');
}

/** Aktualisiert die Gesamtanzahl im Header (nötig nach jedem Anlegen/Löschen/Import). */
function updateHeaderCount(){
  subCount.textContent = `${MOVIES.length} ${MOVIES.length === 1 ? 'Film' : 'Filme'}`;
}

/**
 * Zeitstempel von m.created als Zahl für Sortierung. Fehlt/ungültig -> 0 (sortiert als ältestes).
 * @param {Movie} m
 */
function createdTime(m){
  const t = m.created ? new Date(m.created).getTime() : NaN;
  return isNaN(t) ? 0 : t;
}

/** Sortiert Filme ohne Wert für `key` ans Ende, sonst nach `compare`, bei Gleichstand alphabetisch nach Titel. */
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
  const genre = genreSel.value;
  const medium = mediumSel.value;
  let items = MOVIES.map((m, i) => ({m, i}));

  if(genre){
    items = items.filter(({m}) => m.genres.includes(genre));
  }
  if(medium){
    items = items.filter(({m}) => m.medium === medium);
  }

  if(q){
    items = items.filter(({m}) =>
      [m.title, m.originalTitle, m.director, m.plot, m.medium, m.year && String(m.year), ...m.genres]
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

  resultCount.textContent = `${items.length} von ${MOVIES.length} Filmen`;
  grid.innerHTML = items.length
    ? items.map(({m,i}) => cardHtml(m, i)).join('')
    : '<p class="empty">Keine Filme gefunden.</p>';
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
 * QR-Code zum Abscannen mit dem Handy (public/vendor/qrcode.js). Keine YouTube-URL -> nur der QR-Code.
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
    ['Regie', m.director],
    ['Medium', m.medium],
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
        <div class="meta-line">${escapeHtml(metaLine(m))}</div>
        ${factsHtml}
        ${createdHtml}
      </div>
    </div>
    ${paragraphsHtml('Handlung', m.plot, 'plot')}
    <div id="trailerSlot">${trailerHtml(m)}</div>
    ${castHtml(m)}
    ${paragraphsHtml('Notizen', m.notes, 'notes')}
  `;
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
      ${m.medium === 'USB' && (m.files || []).length ? `<a class="btn btn-primary play-btn" href="api/play.php?slug=${encodeURIComponent(m.slug)}" title="Öffnet die Filmdatei im installierten Player">&#9654; Abspielen</a>` : ''}
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

/** <datalist> aus festen Vorschlägen plus allen bereits verwendeten Werten. */
function datalistHtml(id, fixed, field){
  const values = [...new Set([...fixed, ...knownValues(field)])];
  return `<datalist id="${id}">${values.map(v => `<option value="${escapeHtml(v)}">`).join('')}</datalist>`;
}

/**
 * Zeigt das Anlege-/Bearbeitungsformular. m === null bedeutet: neuer Film.
 * @param {?Movie} m
 */
function openEditForm(m){
  formIsOpen = true;
  const isEdit = m !== null;
  const val = field => isEdit ? escapeHtml(m[field] ?? '') : '';
  const castText = isEdit ? m.cast.map(c => c.role ? `${c.name} | ${c.role}` : c.name).join('\n') : '';
  /**
   * Bildfeld mit Vorschau (vorhandenes Bild bzw. nach Dateiauswahl die neue Datei).
   * @param {'poster'|'fanart'} kind
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

  sheet.innerHTML = `
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <h2>${isEdit ? 'Film bearbeiten' : 'Neuer Film'}</h2>
    <div id="formError"></div>
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
        <label for="runtimeInput">Laufzeit (Min.)</label>
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
      <div class="form-field">
        <label for="directorInput">Regie</label>
        <input type="text" id="directorInput" value="${val('director')}">
      </div>
    </div>
    <div class="form-field">
      <label for="mediumInput">Medium</label>
      <input type="text" id="mediumInput" list="mediumList" autocomplete="off" value="${val('medium')}">
      ${datalistHtml('mediumList', MEDIUM_OPTIONS, 'medium')}
    </div>
    <div class="form-field" id="filesField">
      <label for="filesInput">Filmdatei (relativ zum USB-Ordner)</label>
      <textarea id="filesInput" rows="2" placeholder="z. B. zimmer-1408-2007/movie.avi">${escapeHtml(isEdit ? (m.files || []).join('\n') : '')}</textarea>
      <div class="hint">Pfad relativ zu USB_ROOT aus config.php. Mehrteilige Filme: eine Datei pro Zeile, in Abspielreihenfolge.</div>
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
  `;
  overlay.classList.add('open');

  document.getElementById('closeBtn').addEventListener('click', closeMovie);
  document.getElementById('cancelBtn').addEventListener('click', () => {
    isEdit ? openMovie(MOVIES.indexOf(m)) : closeMovie();
  });
  // Datei-Feld nur bei Medium USB zeigen (eingetragene Dateien bleiben beim Umschalten erhalten).
  const mediumInput = document.getElementById('mediumInput');
  const toggleFilesField = () => {
    document.getElementById('filesField').style.display = mediumInput.value.trim() === 'USB' ? '' : 'none';
  };
  mediumInput.addEventListener('input', toggleFilesField);
  toggleFilesField();

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

/** Liest die Formularfelder aus und baut daraus den API-Payload. */
function collectFormData(){
  const text = id => document.getElementById(id).value.trim();
  const number = id => {
    const v = text(id);
    return v === '' ? null : (/^\d+$/.test(v) ? parseInt(v, 10) : v); // Ungültiges lehnt der Server mit Meldung ab
  };
  const fsk = text('fskInput').replace(/^FSK\s*/i, '');

  return {
    title: text('titleInput'),
    originalTitle: text('originalTitleInput') || null,
    year: number('yearInput'),
    runtime: number('runtimeInput'),
    fsk: fsk || null,
    genres: text('genresInput').split(',').map(g => g.trim()).filter(Boolean),
    director: text('directorInput') || null,
    medium: text('mediumInput') || null,
    files: text('mediumInput') === 'USB' ? text('filesInput').split('\n').map(f => f.trim()).filter(Boolean) : [],
    cast: text('castInput').split('\n').map(line => {
      const [name, ...role] = line.split('|');
      return {name: name.trim(), role: role.join('|').trim() || null};
    }).filter(c => c.name),
    trailer: text('trailerInput') || null,
    plot: text('plotInput') || null,
    notes: text('notesInput') || null,
  };
}

async function saveMovie(slug){
  const payload = collectFormData();
  const errorBox = document.getElementById('formError');
  errorBox.innerHTML = '';

  if(!payload.title){
    errorBox.innerHTML = '<div class="error-box">Titel ist erforderlich.</div>';
    return;
  }
  if(payload.medium === 'USB' && !payload.files.length){
    errorBox.innerHTML = '<div class="error-box">Bei Medium USB bitte die Filmdatei (relativ zum USB-Ordner) angeben.</div>';
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
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(err.message)}</div>`;
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

  MOVIES = MOVIES.filter(x => x.slug !== slug && x.slug !== saved.slug);
  MOVIES.push(saved);
  refreshFilters();
  updateHeaderCount();
  renderGrid();
  openMovie(MOVIES.indexOf(saved));
  if(imageErrors.length){
    alert('Film gespeichert, aber Bild-Upload fehlgeschlagen:\n' + imageErrors.join('\n'));
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

/** Speichert Suchbegriff, Genre-Filter und Sortierung, damit sie einen Reload überstehen. */
function saveViewState(){
  localStorage.setItem(VIEW_STATE_KEY, JSON.stringify({
    search: searchInput.value,
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
genreSel.addEventListener('change', onFilterChange);
mediumSel.addEventListener('change', onFilterChange);
newMovieBtn.addEventListener('click', () => openEditForm(null));
importMovieBtn.addEventListener('click', () => openImportForm());

/** Zeigt den Import-Dialog: Suche in TMDB nach Titel (+ Jahr) oder IMDb-ID, ein Klick auf einen Treffer importiert ihn. */
function openImportForm(){
  formIsOpen = true;
  sheet.innerHTML = `
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <h2>Film importieren</h2>
    <div id="formError"></div>
    <div class="form-row">
      <div class="form-field search-query">
        <label for="importQueryInput">Titel oder IMDb-ID</label>
        <input type="text" id="importQueryInput" placeholder="z. B. Die Goonies oder tt0089218">
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
      Filmdaten und Bilder stammen von <a href="https://www.themoviedb.org" target="_blank" rel="noopener">The Movie Database (TMDB)</a>.
      Als Medium wird „DVD“ eingetragen; bei Blu-ray o. Ä. danach im Formular ändern.
    </p>
  `;
  overlay.classList.add('open');
  document.getElementById('closeBtn').addEventListener('click', closeMovie);
  document.getElementById('cancelBtn').addEventListener('click', closeMovie);
  document.getElementById('searchBtn').addEventListener('click', runSearch);
  for(const id of ['importQueryInput', 'importYearInput']){
    document.getElementById(id).addEventListener('keydown', e => { if(e.key === 'Enter') runSearch(); });
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
  let data;
  try {
    const params = new URLSearchParams({query});
    if(year) params.set('year', year);
    const res = await fetch(`api/search.php?${params}`);
    data = await res.json();
    if(!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  } catch(err){
    resultsBox.innerHTML = '';
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(err.message)}</div>`;
    return;
  }

  if(!data.results.length){
    resultsBox.innerHTML = '<p class="empty">Keine Treffer.</p>';
    return;
  }
  resultsBox.innerHTML = `<ul class="search-results">${data.results.map(r => {
    const existingSlug = data.existing[r.tmdb];
    return `<li class="${existingSlug ? 'exists' : ''}" data-tmdb="${r.tmdb}" data-slug="${escapeHtml(existingSlug || '')}">
      <div class="result-poster">${r.poster ? `<img src="${escapeHtml(r.poster)}" alt="" loading="lazy">` : ''}</div>
      <div class="result-body">
        <div class="result-title">${escapeHtml(r.title)}${r.year ? ` <span>(${r.year})</span>` : ''}</div>
        ${r.originalTitle ? `<div class="result-original">${escapeHtml(r.originalTitle)}</div>` : ''}
        ${existingSlug ? '<div class="result-status">bereits in der Videothek – anklicken zum Öffnen</div>' : '<div class="result-status"></div>'}
        ${r.overview ? `<div class="result-overview">${escapeHtml(r.overview)}</div>` : ''}
      </div>
    </li>`;
  }).join('')}</ul>`;

  resultsBox.querySelectorAll('li').forEach(li => li.addEventListener('click', () => {
    if(li.dataset.slug){
      const idx = MOVIES.findIndex(m => m.slug === li.dataset.slug);
      if(idx >= 0) openMovie(idx);
    } else {
      runImport(parseInt(li.dataset.tmdb, 10), li);
    }
  }));
}

/**
 * Importiert den Film und lädt danach alle Darstellerfotos über api/actors.php. Jeder Aufruf dort
 * arbeitet nur ~10 s (PHP-Zeitlimit), daher wird wiederholt, bis nichts mehr fehlt.
 * @param {number} tmdb
 * @param {HTMLElement} li  angeklickter Treffer (zeigt den Fortschritt)
 */
async function runImport(tmdb, li){
  const errorBox = document.getElementById('formError');
  if(document.querySelector('.search-results li.importing')) return; // kein Doppel-Import
  const status = li.querySelector('.result-status');
  errorBox.innerHTML = '';
  li.classList.add('importing');
  status.textContent = 'Importiere Film, Poster und Hintergrundbild …';

  /** @type {Movie} */
  let saved;
  try {
    saved = await apiWrite('api/import.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({tmdb}),
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
    subCount.textContent = 'Filme konnten nicht geladen werden.';
    grid.innerHTML = '<p class="empty">Fehler beim Laden der Filme.</p>';
    return;
  }

  refreshFilters();
  updateHeaderCount();

  // Suchbegriff/Filter/Sortierung vom letzten Besuch wiederherstellen. Genre/Medium nur übernehmen,
  // wenn es diese Option nach dem Neuaufbau der Listen (refreshFilters) noch gibt — sonst
  // bleibt es bei "Alle ...", statt einen verschwundenen Wert stumm auszuwählen.
  const saved = loadViewState();
  if(saved.search) searchInput.value = saved.search;
  if(saved.sort && [...sortSel.options].some(o => o.value === saved.sort)) sortSel.value = saved.sort;
  if(saved.genre && [...genreSel.options].some(o => o.value === saved.genre)) genreSel.value = saved.genre;
  if(saved.medium && [...mediumSel.options].some(o => o.value === saved.medium)) mediumSel.value = saved.medium;

  renderGrid();
}

void init();

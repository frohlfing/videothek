<?php

/**
 * Welche Teile einer Reihe bzw. Staffeln einer Serie es laut TMDB gibt (siehe lib/catalog.php). Das Frontend zeigt
 * damit "2 von 7" und die übrigen Teile als Platzhalter.
 *
 * GET /api/catalog.php -> {"collections": {<TMDB-ID>: {name, updated, parts}}, "series": {<TMDB-ID>: {name, updated, seasons}}}
 *   Kein Token nötig.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/catalog.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Nur GET erlaubt');
}

// (object): Ein leerer Katalog soll als {} ankommen, nicht als [].
json_response(200, [
    'collections' => (object) read_catalog(CATALOG_COLLECTIONS),
    'series' => (object) read_catalog(CATALOG_SERIES),
]);

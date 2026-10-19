<?php

/**
 * Wer angemeldet ist und welche Benutzer es gibt (für Eigentümer und Standorte der Medien).
 *
 * GET /api/users.php -> {"me": "frank", "users": {"frank": {"name": "Frank"}, …}, "usbRoot": "D:\\videothek"}
 *   me: angemeldeter Benutzer oder null (ohne Basic Auth). usbRoot: USB_ROOT aus config.php (Vorgabe für den
 *   USB-Ordner, jedes Gerät kann ihn ändern). Kein Token nötig.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/users.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Nur GET erlaubt');
}

$users = array_map(fn(array $u) => ['name' => (string) ($u['name'] ?? '')], read_users());
json_response(200, [
    'me' => current_user(),
    // (object): Ohne Benutzer soll {} ankommen, nicht [].
    'users' => (object) $users,
    'usbRoot' => USB_ROOT,
]);

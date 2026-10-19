<?php

/**
 * Wer angemeldet ist und welche Benutzer es gibt (für Eigentümer und Standorte der Medien).
 *
 * GET /api/users.php -> {"me": "frank", "users": {"frank": {"name": "Frank", "owner": "rohlfing"}, …},
 *                        "owners": {"rohlfing": "Rohlfing", "lilie": "Lilie"}, "usbRoot": "D:\\videothek"}
 *   me: angemeldeter Benutzer oder null (ohne Basic Auth). users[…].owner: Kennung des Eigentümers seiner Medien
 *   (owner_of()), owners: Kennung => Anzeigename aller Eigentümer (siehe lib/users.php). usbRoot: USB_ROOT aus
 *   config.php (Vorgabe für den USB-Ordner, jedes Gerät kann ihn ändern). Kein Token nötig.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/users.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Nur GET erlaubt');
}

$users = [];
foreach (read_users() as $login => $u) {
    $users[$login] = ['name' => (string) ($u['name'] ?? ''), 'owner' => owner_of($login)];
}
json_response(200, [
    'me' => current_user(),
    // (object): Ohne Benutzer soll {} ankommen, nicht [].
    'users' => (object) $users,
    'owners' => (object) owners(),
    'usbRoot' => USB_ROOT,
]);

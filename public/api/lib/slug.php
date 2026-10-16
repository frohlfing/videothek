<?php

/** Transliteriert Umlaute/Akzente, kleinschreibt und ersetzt alles Nicht-a-z0-9 durch Bindestriche. */
function slugify(string $title): string
{
    $map = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'î' => 'i', 'ç' => 'c', 'ñ' => 'n',
    ];
    $s = strtr(trim($title), $map);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/**
 * Liefert einen im MOVIES_DIR eindeutigen Slug. Hängt bei Kollision -2, -3, ... an.
 * $excludeSlug wird ignoriert (nötig beim Rename, wenn der alte Ordner noch existiert).
 */
function unique_slug(string $baseSlug, ?string $excludeSlug = null): string
{
    $slug = $baseSlug;
    $n = 2;
    while (is_dir(MOVIES_DIR . '/' . $slug) && $slug !== $excludeSlug) {
        $slug = $baseSlug . '-' . $n;
        $n++;
    }
    return $slug;
}

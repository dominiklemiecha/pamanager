<?php
/**
 * Helper Avatar - mostra foto profilo o iniziali
 * PAManager - Comune
 */

/**
 * Genera HTML avatar dipendente.
 * Se $emp['photo_path'] è valorizzato → <img>, altrimenti iniziali.
 *
 * @param array $emp  Riga employee con almeno first_name, last_name, photo_path (opzionale)
 * @param string $cssClass Classe CSS del contenitore (es. "lp-avatar", "request-avatar")
 * @param string $extraStyle Stile inline aggiuntivo (opzionale)
 */
function employeeAvatarHtml(array $emp, string $cssClass, string $extraStyle = ''): string
{
    $first = $emp['first_name'] ?? '';
    $last = $emp['last_name'] ?? '';
    $initials = strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
    $altName = htmlspecialchars(trim("$first $last"), ENT_QUOTES);
    $style = $extraStyle ? ' style="' . htmlspecialchars($extraStyle, ENT_QUOTES) . '"' : '';

    if (!empty($emp['photo_path'])) {
        $url = PUBLIC_URL . '/' . ltrim($emp['photo_path'], '/');
        $url = htmlspecialchars($url, ENT_QUOTES);
        return '<div class="' . htmlspecialchars($cssClass, ENT_QUOTES) . ' has-photo"' . $style . '>'
             . '<img src="' . $url . '" alt="' . $altName . '" loading="lazy" decoding="async">'
             . '</div>';
    }

    return '<div class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '"' . $style . '>'
         . htmlspecialchars($initials, ENT_QUOTES)
         . '</div>';
}

/**
 * Avatar generico (per chat: prende solo nome + path foto opzionale).
 * Iniziali = prime 2 lettere del nome.
 */
function chatAvatarHtml(string $name, ?string $photoPath, string $cssClass): string
{
    $initials = strtoupper(mb_substr(trim($name), 0, 2));
    if (!empty($photoPath)) {
        $url = htmlspecialchars(PUBLIC_URL . '/' . ltrim($photoPath, '/'), ENT_QUOTES);
        $alt = htmlspecialchars($name, ENT_QUOTES);
        return '<div class="' . htmlspecialchars($cssClass, ENT_QUOTES) . ' has-photo">'
             . '<img src="' . $url . '" alt="' . $alt . '" loading="lazy" decoding="async"></div>';
    }
    return '<div class="' . htmlspecialchars($cssClass, ENT_QUOTES) . '">'
         . htmlspecialchars($initials, ENT_QUOTES) . '</div>';
}

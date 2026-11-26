<?php
// unlock/session.php — session fan côté serveur
//
// Le CDN média exige une signature CloudFront : sans session fan, aucun média
// verrouillé n'est lisible. Deux sources, dans cet ordre :
//  1. SECOURS — cookie collé à la main (fichier session.manual.txt), qui expire.
//  2. PRINCIPAL — cookies rafraîchis par refresh.php, appelé par cron.
// Le premier fichier non expiré gagne. Rien n'est jamais affiché en clair.

const UNLOCK_STATE_DIR   = __DIR__ . '/state';
const UNLOCK_AUTO_FILE   = UNLOCK_STATE_DIR . '/session.auto.json';
const UNLOCK_MANUAL_FILE = UNLOCK_STATE_DIR . '/session.manual.txt';
const UNLOCK_EVENT_FILE  = UNLOCK_STATE_DIR . '/session.events.log';

function unlock_state_dir(): string
{
    if (!is_dir(UNLOCK_STATE_DIR)) {
        @mkdir(UNLOCK_STATE_DIR, 0700, true);
        @chmod(UNLOCK_STATE_DIR, 0700);
    }
    return UNLOCK_STATE_DIR;
}

/** Cookie jugé expiré au-delà de cette marge (secondes). */
function unlock_session_margin(): int
{
    return 120;
}

/** Écrit un événement de session, borné à 200 lignes. */
function unlock_session_log(string $message): void
{
    unlock_state_dir();
    $line = gmdate('c') . ' ' . preg_replace('~\s+~', ' ', $message) . "\n";
    @file_put_contents(UNLOCK_EVENT_FILE, $line, FILE_APPEND | LOCK_EX);
    $lines = @file(UNLOCK_EVENT_FILE, FILE_IGNORE_NEW_LINES);
    if (is_array($lines) && count($lines) > 200) {
        @file_put_contents(UNLOCK_EVENT_FILE, implode("\n", array_slice($lines, -200)) . "\n", LOCK_EX);
    }
    @chmod(UNLOCK_EVENT_FILE, 0600);
}

/** Cookies sous forme de chaîne d'en-tête, ou null. */
function unlock_cookie_header(?array $state): ?string
{
    if (!is_array($state) || empty($state['cookies']) || !is_array($state['cookies'])) return null;
    $pairs = [];
    foreach ($state['cookies'] as $name => $value) {
        if (is_string($name) && is_string($value) && $name !== '') $pairs[] = $name . '=' . $value;
    }
    return $pairs ? implode('; ', $pairs) : null;
}

/** Indique si un état porte une session encore utilisable. */
function unlock_state_is_fresh(?array $state): bool
{
    if (!is_array($state) || unlock_cookie_header($state) === null) return false;
    $expires = (int)($state['expires_at'] ?? 0);
    if ($expires > 0 && $expires - unlock_session_margin() <= time()) return false;
    return true;
}

/** Charge un fichier d'état, ou null. */
function unlock_read_state(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * État de session utilisable, ou null.
 * Priorité au rafraîchissement automatique, puis au cookie manuel de secours.
 */
function unlock_session_state(): ?array
{
    $auto = unlock_read_state(UNLOCK_AUTO_FILE);
    if (unlock_state_is_fresh($auto)) {
        $auto['source'] = 'auto';
        return $auto;
    }

    $manual = unlock_read_state(UNLOCK_MANUAL_FILE);
    if (unlock_state_is_fresh($manual)) {
        $manual['source'] = 'manual';
        return $manual;
    }
    return null;
}

/** Diagnostic public, sans secret : sert au bandeau d'état. */
function unlock_session_report(): array
{
    $auto = unlock_read_state(UNLOCK_AUTO_FILE);
    $manual = unlock_read_state(UNLOCK_MANUAL_FILE);
    $active = unlock_session_state();

    $state = 'expired';
    $source = null;
    $refreshedAt = null;
    $expiresAt = null;
    $account = null;

    if ($active !== null) {
        $state = 'connected';
        $source = $active['source'];
        $refreshedAt = $active['fetched_at'] ?? null;
        $expiresAt = $active['expires_at'] ?? null;
        $account = $active['account'] ?? null;
    } elseif (is_array($auto) || is_array($manual)) {
        $state = 'expired';
        $source = is_array($auto) ? 'auto' : 'manual';
        $expiresAt = ($auto['expires_at'] ?? null) ?: ($manual['expires_at'] ?? null);
    } else {
        $state = 'missing';
    }

    $lastEvent = null;
    if (is_file(UNLOCK_EVENT_FILE)) {
        $lines = @file(UNLOCK_EVENT_FILE, FILE_IGNORE_NEW_LINES);
        if (is_array($lines) && $lines) $lastEvent = end($lines);
    }

    return [
        'state' => $state,
        'source' => $source,
        'account' => $account,
        'expires_at' => $expiresAt,
        'refreshed_at' => $refreshedAt,
        'has_manual' => is_array($manual),
        'has_auto' => is_array($auto),
        'last_event' => $lastEvent,
        'margin' => unlock_session_margin(),
    ];
}

function unlock_session_expired(?array $state): bool
{
    if (!is_array($state) || unlock_cookie_header($state) === null) return false;
    return !unlock_state_is_fresh($state);
}

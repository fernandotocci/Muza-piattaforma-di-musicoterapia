<?php
/**
 * Configurazione centralizzata Spotify per Muza
 * 
 * File di configurazione unico per tutte le API Spotify
 * per evitare inconsistenze nei parametri OAuth 2.0 e nelle chiamate API.
 */

// === CREDENZIALI SPOTIFY ===
define('SPOTIFY_CLIENT_ID', '49240058547c408e84cbf72206a02101');
define('SPOTIFY_CLIENT_SECRET', '8f8c5c6c77b34e0295b08bcf5d844470');

// === URL E REDIRECT ===
define('SPOTIFY_REDIRECT_URI', 'https://horrent-sharda-heeled.ngrok-free.dev/muza/callback.php?type=spotify');
define('SPOTIFY_CALLBACK_BASE', 'https://horrent-sharda-heeled.ngrok-free.dev/muza/callback.php');

// === SCOPE E PERMESSI ===
define('SPOTIFY_SCOPE', 'user-read-private user-read-email user-read-playback-state user-modify-playback-state streaming');

// === ENDPOINT API ===
define('SPOTIFY_AUTH_URL', 'https://accounts.spotify.com/authorize');
define('SPOTIFY_TOKEN_URL', 'https://accounts.spotify.com/api/token');
define('SPOTIFY_API_BASE', 'https://api.spotify.com/v1');

// === PARAMETRI CONFIGURAZIONE ===
define('SPOTIFY_SHOW_DIALOG', 'false'); // Non forzare dialogo autorizzazione
define('SPOTIFY_MARKET', 'IT'); // Mercato italiano

/**
 * Genera URL di autorizzazione Spotify standardizzato e definizione dei parametri Auth OAuth 2.0 per tutte le API che richiedono autenticazione.
 */
function generateSpotifyAuthUrl($state = null, $userType = 'patient') {
    if (!$state) {
        $state = bin2hex(random_bytes(16));
    }
    
    $params = [
        'response_type' => 'code',
        'client_id' => SPOTIFY_CLIENT_ID,
        'scope' => SPOTIFY_SCOPE,
        'redirect_uri' => SPOTIFY_REDIRECT_URI,
        'state' => $state,
        'show_dialog' => SPOTIFY_SHOW_DIALOG
    ];
    
    return SPOTIFY_AUTH_URL . '?' . http_build_query($params);
}

/**
 * Ottiene headers di autorizzazione Spotify
 */
function getSpotifyAuthHeaders() {
    return [
        'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
        'Content-Type: application/x-www-form-urlencoded'
    ];
}

/**
 * Ottiene headers per chiamate API Spotify
 */
function getSpotifyApiHeaders($access_token) {
    return [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
}
?>
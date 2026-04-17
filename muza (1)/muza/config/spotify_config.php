<?php
/**
 * config/spotify_config.php - Configurazione centralizzata Spotify
 * 
 *  Questo file contiene credenziali sensibili, da non condividere pubblicamente.
 * - Non committarlo in repository pubblici
 * - Mantieni le credenziali sicure
 * - Usa variabili d'ambiente in produzione
 */

// Configurazione Spotify
define('SPOTIFY_CLIENT_ID', '49240058547c408e84cbf72206a02101');
define('SPOTIFY_CLIENT_SECRET', 'a746e47136244d0c83a1f7b4b64654f1');

// URL di callback
define('SPOTIFY_REDIRECT_URI', 'https://horrent-sharda-heeled.ngrok-free.dev/muza/callback.php?type=spotify');

// Scopes richiesti
define('SPOTIFY_SCOPES', 'user-read-private user-read-email streaming user-read-playback-state user-modify-playback-state');

// Funzione helper per ottenere la configurazione
function getSpotifyConfig() {
    return [
        'client_id' => SPOTIFY_CLIENT_ID,
        'client_secret' => SPOTIFY_CLIENT_SECRET,
        'redirect_uri' => SPOTIFY_REDIRECT_URI,
        'scopes' => SPOTIFY_SCOPES
    ];
}

// Funzione per generare URL di autorizzazione
function generateSpotifyAuthUrl($state) {
    return 'https://accounts.spotify.com/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => SPOTIFY_CLIENT_ID,
        'scope' => SPOTIFY_SCOPES,
        'redirect_uri' => SPOTIFY_REDIRECT_URI,
        'state' => $state,
        'show_dialog' => 'true'
    ]);
}

// Funzione per token exchange
function exchangeSpotifyCodeForToken($code) {
    $data = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => SPOTIFY_REDIRECT_URI,
    ];
    
    $headers = [
        'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
        'Content-Type: application/x-www-form-urlencoded'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Log dettagliato
    error_log("=== SPOTIFY TOKEN EXCHANGE (CENTRALIZED) ===");
    error_log("HTTP Code: " . $http_code);
    error_log("cURL Error: " . $curl_error);
    error_log("Response: " . $response);
    
    if ($response === false || !empty($curl_error)) {
        return ['error' => 'network_error', 'error_description' => $curl_error];
    }
    
    $response_data = json_decode($response, true);
    
    if ($http_code === 200) {
        return $response_data;
    } else {
        return $response_data ?: ['error' => 'unknown_error', 'error_description' => 'HTTP ' . $http_code];
    }
}

// Validazione configurazione
function validateSpotifyConfig() {
    $errors = [];
    
    if (empty(SPOTIFY_CLIENT_ID)) {
        $errors[] = 'Client ID mancante';
    }
    
    if (empty(SPOTIFY_CLIENT_SECRET)) {
        $errors[] = 'Client Secret mancante';
    }
    
    if (empty(SPOTIFY_REDIRECT_URI)) {
        $errors[] = 'Redirect URI mancante';
    }
    
    if (!filter_var(SPOTIFY_REDIRECT_URI, FILTER_VALIDATE_URL)) {
        $errors[] = 'Redirect URI non valido';
    }
    
    return $errors;
}

// Test configurazione
if (isset($_GET['test']) && $_GET['test'] === 'config') {
    header('Content-Type: application/json');
    
    $errors = validateSpotifyConfig();
    
    if (empty($errors)) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Configurazione Spotify valida',
            'config' => [
                'client_id' => SPOTIFY_CLIENT_ID,
                'client_secret' => substr(SPOTIFY_CLIENT_SECRET, 0, 8) . '...',
                'redirect_uri' => SPOTIFY_REDIRECT_URI,
                'scopes' => SPOTIFY_SCOPES
            ]
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Errori nella configurazione',
            'errors' => $errors
        ]);
    }
    exit;
}
?>

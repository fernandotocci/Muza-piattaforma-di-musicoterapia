<?php
/**
 * API: search_spotify.php - Ricerca brani su Spotify con gestione robusta dei token
 * 
    * Endpoint: GET /api/search_spotify.php?q={query}
 */

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once 'refresh_spotify_token.php';

header('Content-Type: application/json');

try {
    // === CONTROLLI DI SICUREZZA ===
    
    if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'therapist') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Non autorizzato - solo terapeuti']);
        exit;
    }

    $user_id = $_SESSION['user']['id'];
    
    // === VALIDAZIONE QUERY ===
    
    $query = trim($_GET['q'] ?? '');
    if (empty($query)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Query di ricerca mancante']);
        exit;
    }
    
    if (strlen($query) < 2) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Query troppo corta (minimo 2 caratteri)']);
        exit;
    }
    
    if (strlen($query) > 100) {
        $query = substr($query, 0, 100); // Tronca se troppo lunga
    }
    
    error_log("SEARCH_SPOTIFY: Ricerca per user_id=$user_id, query='$query'");

    // === OTTENIMENTO TOKEN VALIDO ===
    
    $access_token = getValidSpotifyToken($user_id);
    // Log diagnostico token (mascherato) e controllo rapido /me
    $masked = is_string($access_token) ? (substr($access_token,0,6) . '...' . substr($access_token, -6)) : 'NULL';
    error_log("SEARCH_SPOTIFY: user_id=$user_id access_token(masked)=$masked");
    $me_check = @file_get_contents('https://api.spotify.com/v1/me', false, stream_context_create(['http'=>['method'=>'GET','header'=>"Authorization: Bearer $access_token\r\n",'timeout'=>10]]));
    if ($me_check === false) {
        error_log("SEARCH_SPOTIFY: /me check failed for user_id=$user_id");
    } else {
        error_log("SEARCH_SPOTIFY: /me response preview=" . substr($me_check,0,500));
    }
    
    if (!$access_token) {
        error_log("SEARCH_SPOTIFY: Token non valido per user_id=$user_id");
        
        echo json_encode([
            'success' => false,
            'error' => 'Token Spotify non valido o scaduto - riconnetti il tuo account',
            'needs_reconnect' => true,
            'error_code' => 'TOKEN_INVALID'
        ]);
        exit;
    }
    
    // === CHIAMATA API SPOTIFY ===
    
    $search_params = [
        'q' => $query,
        'type' => 'track',
        'limit' => 20,
        'market' => 'IT', 
        'offset' => 0
    ];
    
    $search_url = 'https://api.spotify.com/v1/search?' . http_build_query($search_params);
    
    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $search_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_USERAGENT => 'Muza-MusicTherapy/1.0'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);
    
    // === GESTIONE ERRORI CURL ===
    
    if ($response === false || $curl_errno !== 0) {
        error_log("SEARCH_SPOTIFY: Errore cURL - errno=$curl_errno, error='$curl_error'");
        
        echo json_encode([
            'success' => false,
            'error' => 'Errore di connessione a Spotify',
            'error_code' => 'CURL_ERROR',
            'details' => "cURL error $curl_errno: $curl_error"
        ]);
        exit;
    }
    
    // === GESTIONE RISPOSTE HTTP ===
    
    error_log("SEARCH_SPOTIFY: HTTP $http_code per user_id=$user_id");
    
    if ($http_code === 401) {
        // Token scaduto, proviamo a rinnovarlo una volta
        error_log("SEARCH_SPOTIFY: Token scaduto, tentativo di refresh per user_id=$user_id");
        
        $refresh_result = refreshSpotifyToken($user_id);
        if ($refresh_result['success']) {
            // Riprova la ricerca con il nuovo token
            $access_token = $refresh_result['access_token'];
            $headers[0] = 'Authorization: Bearer ' . $access_token;
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $search_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            error_log("SEARCH_SPOTIFY: Dopo refresh - HTTP $http_code per user_id=$user_id");
        }
    }
    
    if ($http_code !== 200) {
        $error_details = json_decode($response, true);
        $error_message = $error_details['error']['message'] ?? 'Errore sconosciuto da Spotify';
        
        error_log("SEARCH_SPOTIFY: Errore HTTP $http_code - $error_message");
        
        $user_error = 'Errore nella ricerca Spotify';
        if ($http_code === 401) {
            $user_error = 'Token Spotify scaduto - riconnetti il tuo account';
        } elseif ($http_code === 403) {
            $user_error = 'Accesso negato da Spotify - verifica i permessi';
        } elseif ($http_code === 429) {
            $user_error = 'Troppe richieste - riprova tra qualche minuto';
        } elseif ($http_code >= 500) {
            $user_error = 'Spotify temporaneamente non disponibile';
        }
        
        echo json_encode([
            'success' => false,
            'error' => $user_error,
            'error_code' => "HTTP_$http_code",
            'spotify_error' => $error_message,
            'needs_reconnect' => $http_code === 401
        ]);
        exit;
    }
    
    // === PARSING RISPOSTA SPOTIFY ===
    
    $spotify_data = json_decode($response, true);
    
    if (!$spotify_data || !isset($spotify_data['tracks']['items'])) {
        error_log("SEARCH_SPOTIFY: Risposta Spotify non valida per user_id=$user_id");
        
        echo json_encode([
            'success' => false,
            'error' => 'Risposta non valida da Spotify',
            'error_code' => 'INVALID_RESPONSE'
        ]);
        exit;
    }
    
    // === FORMATTAZIONE RISULTATI ===
    
    $tracks = [];
    $items = $spotify_data['tracks']['items'] ?? [];
    
    foreach ($items as $item) {
        // Validazione base dell'item
        if (!isset($item['id']) || !isset($item['name'])) {
            continue; // Salta item non validi
        }
        
        // Estrai dati sicuri
        $track_data = [ 
            'id' => $item['id'],
            'name' => $item['name'],
            'artist' => isset($item['artists'][0]['name']) ? $item['artists'][0]['name'] : 'Artista sconosciuto',
            'album' => $item['album']['name'] ?? '',
            'duration_ms' => intval($item['duration_ms'] ?? 0),
            'duration_formatted' => formatDuration($item['duration_ms'] ?? 0),
            'preview_url' => $item['preview_url'] ?? null,
            'external_url' => $item['external_urls']['spotify'] ?? '',
            'image' => null,
            'popularity' => intval($item['popularity'] ?? 0),
            'explicit' => !empty($item['explicit'])
        ];
        
        // Gestione immagine copertina
        if (isset($item['album']['images']) && is_array($item['album']['images']) && count($item['album']['images']) > 0) {
            // Prendi l'immagine di dimensione media (di solito la seconda)
            $images = $item['album']['images'];
            if (count($images) >= 2) {
                $track_data['image'] = $images[1]['url'] ?? $images[0]['url'];
            } else {
                $track_data['image'] = $images[0]['url'];
            }
        }
        
        // Gestione artisti multipli
        if (isset($item['artists']) && is_array($item['artists']) && count($item['artists']) > 1) {
            $artists = array_slice($item['artists'], 0, 3); // Massimo 3 artisti
            $artist_names = array_map(function($artist) { return $artist['name'] ?? ''; }, $artists);
            $track_data['artist'] = implode(', ', array_filter($artist_names));
        }
        
        $tracks[] = $track_data;
    }
    
    // === RISPOSTA FINALE ===
    
    $total_results = count($tracks);
    error_log("SEARCH_SPOTIFY: Trovati $total_results risultati per query='$query'");
    
    echo json_encode([
        'success' => true,
        'tracks' => $tracks,
        'total' => $total_results,
        'query' => $query,
        'search_info' => [
            'market' => 'IT',
            'limit' => 20,
            'type' => 'track'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("SEARCH_SPOTIFY: Eccezione - " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Errore interno nella ricerca',
        'error_code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}

/**
 * Formatta la durata da millisecondi a formato MM:SS
 */
function formatDuration($milliseconds) {
    if ($milliseconds <= 0) {
        return '0:00';
    }
    
    $seconds = intval($milliseconds / 1000);
    $minutes = intval($seconds / 60);
    $remaining_seconds = $seconds % 60;
    
    return sprintf('%d:%02d', $minutes, $remaining_seconds);
}
?>
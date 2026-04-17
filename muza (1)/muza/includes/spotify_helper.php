<?php
/**
 * File: spotify_helper.php
 * Contiene funzioni di utilità per interagire con l'API di Spotify.
 */

/**
 * Recupera un token Spotify valido per un utente, gestendo il refresh se necessario.
 *
 * @param int $user_id L'ID dell'utente.
 * @param mysqli $conn L'oggetto della connessione al database.
 * @return array|null I dati del token (incluso access_token) o null se non valido.
 */
function get_spotify_token_for_user($user_id, $conn) {
    $sql = "SELECT id, access_token, refresh_token, expires_at FROM user_music_tokens WHERE user_id = ? AND service = 'spotify'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Errore nella preparazione dello statement: " . $conn->error);
        return null;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result === false || $result->num_rows === 0) {
        return null; // Nessun token trovato
    }
    
    $token_data = $result->fetch_assoc();
    
    // Controlla se il token è scaduto (con un margine di 60 secondi)
    if (strtotime($token_data['expires_at']) < (time() + 60)) {
        // Token scaduto o in scadenza, prova a fare il refresh
        return refresh_spotify_token($token_data['refresh_token'], $user_id, $conn);
    }
    
    return $token_data; // Token valido
}

/**
 * Esegue il refresh di un token di accesso Spotify.
 *
 * @param string $refresh_token Il refresh token.
 * @param int $user_id L'ID dell'utente a cui associare il nuovo token.
 * @param mysqli $conn L'oggetto della connessione al database.
 * @return array|null I nuovi dati del token o null in caso di fallimento.
 */
function refresh_spotify_token($refresh_token, $user_id, $conn) {
    // Le tue credenziali Spotify (le stesse che usi in callback.php)
    $client_id = '49240058547c408e84cbf72206a02101'; // SOSTITUISCI SE DIVERSO
    $client_secret = 'a746e47136244d0c83a1f7b4b64654f1'; // SOSTITUISCI SE DIVERSO

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($client_id . ':' . $client_secret)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        // Impossibile fare il refresh, potresti voler eliminare il token vecchio.
        error_log("Fallito refresh token Spotify per utente $user_id: " . $response);
        return null;
    }
    
    $new_token_data = json_decode($response, true);
    
    // Salva il nuovo token nel database
    $new_access_token = $conn->real_escape_string($new_token_data['access_token']);
    $new_expires_at = date('Y-m-d H:i:s', time() + $new_token_data['expires_in']);
    // Spotify non sempre restituisce un nuovo refresh token. Se non lo fa, riutilizziamo il vecchio.
    $new_refresh_token = isset($new_token_data['refresh_token']) ? $conn->real_escape_string($new_token_data['refresh_token']) : $conn->real_escape_string($refresh_token);

    $update_sql = "
        UPDATE user_music_tokens SET
        access_token = ?,
        refresh_token = ?,
        expires_at = ?,
        updated_at = NOW()
        WHERE user_id = ? AND service = 'spotify'
    ";
    
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param('sssi', $new_access_token, $new_refresh_token, $new_expires_at, $user_id);
    $update_stmt->execute();
    
    return [
        'access_token' => $new_access_token,
        'refresh_token' => $new_refresh_token,
        'expires_at' => $new_expires_at
    ];
}


/**
 * Funzione generica per effettuare richieste all'API di Spotify.
 *
 * @param string $method Metodo HTTP (GET, POST, PUT, DELETE).
 * @param string $url L'URL completo dell'endpoint API.
 * @param string $access_token Il token di accesso.
 * @param array|null $payload Il corpo della richiesta (per POST e PUT).
 * @return array Risultato contenente 'http_code' e 'response' decodificata.
 */
function spotify_api_request($method, $url, $access_token, $payload = null) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ];
    
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } else {
        // Se non c'è payload, il Content-Type non è sempre necessario
        // Ma per PUT/POST vuoti, Spotify potrebbe richiederlo.
        // Aggiungiamo Content-Length a 0 per le richieste PUT/POST senza corpo
        if ($method === 'PUT' || $method === 'POST') {
             $headers[] = 'Content-Length: 0';
        }
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'http_code' => $http_code,
        'response' => json_decode($response_body, true)
    ];
}

/**
 * Valida se un ID Spotify è valido.
 *
 * @param string|null $spotify_id L'ID Spotify da validare.
 * @return bool True se l'ID è valido, false altrimenti.
 */
function is_valid_spotify_id($spotify_id) {
    // Controlli di base
    if (empty($spotify_id) || !is_string($spotify_id)) {
        return false;
    }
    
    // Rimuovi spazi bianchi
    $spotify_id = trim($spotify_id);
    
    // Controlli per valori non validi comuni
    $invalid_values = ['undefined', 'null', 'NULL', '', '0'];
    if (in_array($spotify_id, $invalid_values, true)) {
        return false;
    }
    
    // Gli ID Spotify sono stringhe alfanumeriche di 22 caratteri
    if (strlen($spotify_id) !== 22) {
        return false;
    }
    
    // Verifica che contenga solo caratteri alfanumerici
    if (!preg_match('/^[a-zA-Z0-9]+$/', $spotify_id)) {
        return false;
    }
    
    return true;
}

/**
 * Estrae un ID Spotify da un URL Spotify.
 *
 * @param string $spotify_url L'URL Spotify (es. https://open.spotify.com/track/3KkXRkHbMCARz0aVfEt68P).
 * @return string|null L'ID Spotify estratto o null se non valido.
 */
function extract_spotify_id_from_url($spotify_url) {
    if (empty($spotify_url) || !is_string($spotify_url)) {
        return null;
    }
    
    // Verifica che sia un URL Spotify valido
    if (strpos($spotify_url, 'open.spotify.com/track/') === false) {
        return null;
    }
    
    // Estrai l'ID dall'URL
    $url_parts = parse_url($spotify_url);
    if (!$url_parts || !isset($url_parts['path'])) {
        return null;
    }
    
    $path_parts = explode('/', trim($url_parts['path'], '/'));
    
    if (count($path_parts) < 2 || $path_parts[0] !== 'track') {
        return null;
    }
    
    $spotify_id = $path_parts[1];
    
    // Rimuovi parametri extra (come ?si=...)
    $spotify_id = explode('?', $spotify_id)[0];
    
    // Valida l'ID estratto
    if (is_valid_spotify_id($spotify_id)) {
        return $spotify_id;
    }
    
    return null;
}

/**
 * Pulisce e corregge un ID Spotify, tentando di estrarlo da un URL se necessario.
 *
 * @param string|null $spotify_data Può essere un ID diretto o un URL Spotify.
 * @return string|null L'ID Spotify pulito e validato o null se non valido.
 */
function clean_spotify_id($spotify_data) {
    if (empty($spotify_data)) {
        return null;
    }
    
    // Se è già un ID valido, restituiscilo
    if (is_valid_spotify_id($spotify_data)) {
        return $spotify_data;
    }
    
    // Se sembra un URL, prova a estrarre l'ID
    if (strpos($spotify_data, 'spotify.com') !== false) {
        return extract_spotify_id_from_url($spotify_data);
    }
    
    return null;
}

/**
 * Ottiene artisti simili a un dato artista tramite l'API Spotify.
 *
 * @param string $artist_id L'ID Spotify dell'artista.
 * @param string $access_token Il token di accesso Spotify.
 * @return array|null Array di artisti simili o null in caso di errore.
 */
function get_related_artists($artist_id, $access_token) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.spotify.com/v1/artists/{$artist_id}/related-artists",
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$access_token}",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        return $data['artists'] ?? null;
    }

    error_log("SPOTIFY_API: Errore nel recuperare artisti simili per {$artist_id}: HTTP {$http_code}");
    return null;
}

/**
 * Cerca tracce su Spotify usando vari criteri.
 *
 * @param array $criteria Criteri di ricerca (artist, genre, limit, etc.)
 * @param string $access_token Il token di accesso Spotify.
 * @return array|null Array di tracce trovate o null in caso di errore.
 */
function search_spotify_tracks($criteria, $access_token) {
    $query_parts = [];
    
    if (!empty($criteria['artist'])) {
        $query_parts[] = 'artist:"' . addslashes($criteria['artist']) . '"';
    }
    if (!empty($criteria['genre'])) {
        $query_parts[] = 'genre:"' . addslashes($criteria['genre']) . '"';
    }
    if (!empty($criteria['year'])) {
        $query_parts[] = 'year:' . intval($criteria['year']);
    }

    $query = implode(' ', $query_parts);
    $limit = min(50, intval($criteria['limit'] ?? 20));
    
    $url = "https://api.spotify.com/v1/search?" . http_build_query([
        'q' => $query,
        'type' => 'track',
        'limit' => $limit,
        'market' => 'IT'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$access_token}",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        return $data['tracks']['items'] ?? null;
    }

    error_log("SPOTIFY_API: Errore nella ricerca tracce: HTTP {$http_code}");
    return null;
}

/**
 * Ottiene raccomandazioni da Spotify basate su seed di artisti, tracce o generi.
 *
 * @param array $seed Parametri seed (seed_artists, seed_tracks, seed_genres)
 * @param string $access_token Il token di accesso Spotify.
 * @param int $limit Numero di raccomandazioni (max 100).
 * @return array|null Array di raccomandazioni o null in caso di errore.
 */
function get_spotify_recommendations($seed, $access_token, $limit = 20) {
    $params = [
        'limit' => min(100, intval($limit)),
        'market' => 'IT'
    ];
    
    if (!empty($seed['seed_artists'])) {
        $params['seed_artists'] = implode(',', array_slice($seed['seed_artists'], 0, 5));
    }
    if (!empty($seed['seed_tracks'])) {
        $params['seed_tracks'] = implode(',', array_slice($seed['seed_tracks'], 0, 5));
    }
    if (!empty($seed['seed_genres'])) {
        $params['seed_genres'] = implode(',', array_slice($seed['seed_genres'], 0, 5));
    }

    $url = "https://api.spotify.com/v1/recommendations?" . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$access_token}",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        return $data['tracks'] ?? null;
    }

    error_log("SPOTIFY_API: Errore nel recuperare raccomandazioni: HTTP {$http_code}");
    return null;
}
?>
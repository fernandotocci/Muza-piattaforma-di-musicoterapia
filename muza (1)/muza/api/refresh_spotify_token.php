<?php
// api/refresh_spotify_token.php - Gestisce il refresh dei token Spotify
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/spotify_config.php';

function getValidSpotifyToken($user_id) {
    global $conn;
    
    // Recupera il token corrente
    $token_query = "
        SELECT access_token, refresh_token, expires_at 
        FROM user_music_tokens 
        WHERE user_id = ? AND service = 'spotify' 
        ORDER BY created_at DESC 
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($token_query);
    if (!$stmt) {
        error_log("REFRESH_TOKEN: Errore preparazione query: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        error_log("REFRESH_TOKEN: Nessun token trovato per user_id=" . $user_id);
        return false;
    }
    
    $token_data = $result->fetch_assoc();
    $stmt->close();
    
    // Se il token è ancora valido, restituiscilo
    if (strtotime($token_data['expires_at']) > time() + 300) { // 5 minuti di buffer
        return $token_data['access_token'];
    }
    
    // Se non c'è un refresh token, non possiamo aggiornare
    if (empty($token_data['refresh_token'])) {
        error_log("REFRESH_TOKEN: Nessun refresh token per user_id=" . $user_id);
        return false;
    }
    
    // Prova a fare il refresh del token - usa configurazione centralizzata
    $refresh_data = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $token_data['refresh_token']
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET)
            ],
            'content' => http_build_query($refresh_data),
            'timeout' => 30
        ]
    ]);
    
    $response = file_get_contents('https://accounts.spotify.com/api/token', false, $context);
    
    if ($response === false) {
        error_log("REFRESH_TOKEN: Errore nel refresh per user_id=" . $user_id);
        return false;
    }
    
    $new_token_data = json_decode($response, true);
    
    if (!$new_token_data || isset($new_token_data['error'])) {
        error_log("REFRESH_TOKEN: Risposta errore: " . print_r($new_token_data, true));
        return false;
    }
    
    // Salva il nuovo token
    $new_access_token = $new_token_data['access_token'];
    $new_refresh_token = $new_token_data['refresh_token'] ?? $token_data['refresh_token'];
    $expires_in = $new_token_data['expires_in'] ?? 3600;
    $new_expires_at = date('Y-m-d H:i:s', time() + $expires_in);
    
    $update_query = "
        UPDATE user_music_tokens 
        SET access_token = ?, refresh_token = ?, expires_at = ?, updated_at = NOW()
        WHERE user_id = ? AND service = 'spotify'
    ";
    
    $stmt = $conn->prepare($update_query);
    if ($stmt) {
        $stmt->bind_param("sssi", $new_access_token, $new_refresh_token, $new_expires_at, $user_id);
        $stmt->execute();
        $stmt->close();
        
        error_log("REFRESH_TOKEN: Token aggiornato con successo per user_id=" . $user_id);
        return $new_access_token;
    } else {
        error_log("REFRESH_TOKEN: Errore nell'aggiornare il token: " . $conn->error);
        return false;
    }
}
?>
<?php
/**
 * API: spotify_playback.php
 * Gestisce le azioni di riproduzione su Spotify (play, pause, etc.).
 *
 * Endpoint: POST /api/spotify_playback.php
 * Parametri:
 * - action (string): 'play' o 'pause'.
 * - track_id (int, opzionale): L'ID del brano nel database locale.
 * - device_id (string, opzionale): L'ID del dispositivo Spotify su cui riprodurre.
 */

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../includes/db.php';

// Verifica autenticazione
if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

$user = $_SESSION['user'];

// Verifica metodo
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

// Leggi i dati JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON non valido']);
    exit;
}

$action = $data['action'] ?? '';
$track_id = $data['track_id'] ?? null;
$device_id = $data['device_id'] ?? '';

error_log("SPOTIFY_PLAYBACK: action=$action, track_id=$track_id, device_id=$device_id");

// Recupera token Spotify dell'utente
$token_query = "
    SELECT access_token, refresh_token, expires_at 
    FROM user_music_tokens 
    WHERE user_id = ? AND service = 'spotify' AND expires_at > NOW()
";
$stmt = $conn->prepare($token_query);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$token_result = $stmt->get_result();

if ($token_result->num_rows === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token Spotify non valido o scaduto']);
    exit;
}

$token_data = $token_result->fetch_assoc();
$access_token = $token_data['access_token'];
$stmt->close();

// Funzione helper per le chiamate cURL
function makeSpotifyRequest($url, $method = 'GET', $headers = [], $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['response' => $response, 'http_code' => $http_code];
}

if ($action === 'play_track' && $track_id) {
    // Recupera spotify_track_id dal database
    $track_query = "SELECT spotify_track_id FROM tracks WHERE id = ?";
    $stmt = $conn->prepare($track_query);
    $stmt->bind_param("i", $track_id);
    $stmt->execute();
    $track_result = $stmt->get_result();
    
    if ($track_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Brano non trovato']);
        exit;
    }
    
    $track_data = $track_result->fetch_assoc();
    $spotify_track_id = $track_data['spotify_track_id'];
    $stmt->close();
    
    if (empty($spotify_track_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Brano non disponibile su Spotify']);
        exit;
    }
    
    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ];

    // Se è stato passato un device_id, prova a trasferire la riproduzione su quel device.
    if (!empty($device_id)) {
        error_log("STEP 1: Trasferimento riproduzione al device $device_id");
        $transfer_url = "https://api.spotify.com/v1/me/player";
        $transfer_payload = json_encode([
            'device_ids' => [$device_id],
            'play' => false
        ]);

        $transfer_result = makeSpotifyRequest($transfer_url, 'PUT', $headers, $transfer_payload);
        error_log("TRANSFER_RESULT: HTTP {$transfer_result['http_code']} - {$transfer_result['response']}");

        // Attendi un momento per il trasferimento
        sleep(1);
    } else {
        error_log("STEP 1: Nessun device_id fornito, verrà usato il device attivo dell'utente (se presente)");
    }

    // STEP 2: Avvia la riproduzione del brano specifico (senza device_id se non fornito)
    error_log("STEP 2: Avvio riproduzione brano $spotify_track_id");

    $play_url = "https://api.spotify.com/v1/me/player/play";
    if (!empty($device_id)) {
        $play_url .= '?device_id=' . urlencode($device_id);
    }
    $play_payload = json_encode([
        'uris' => ["spotify:track:" . $spotify_track_id],
        'position_ms' => 0
    ]);

    $play_result = makeSpotifyRequest($play_url, 'PUT', $headers, $play_payload);
    error_log("PLAY_RESULT: HTTP {$play_result['http_code']} - {$play_result['response']}");
    
    // Gestisci le risposte
    if ($play_result['http_code'] === 204) {
        echo json_encode(['success' => true, 'message' => 'Riproduzione avviata con successo']);
    } elseif ($play_result['http_code'] === 403) {
        $error_data = json_decode($play_result['response'], true);
        if (isset($error_data['error']['reason']) && $error_data['error']['reason'] === 'PREMIUM_REQUIRED') {
            echo json_encode(['success' => false, 'error' => 'Account Spotify Premium richiesto per controllare la riproduzione']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Permessi insufficienti']);
        }
    } elseif ($play_result['http_code'] === 404) {
        echo json_encode(['success' => false, 'error' => 'Device non trovato - assicurati che Spotify sia aperto e attivo']);
    } elseif ($play_result['http_code'] === 502 || $play_result['http_code'] === 503) {
        // Retry dopo errore server
        sleep(2);
        $retry_result = makeSpotifyRequest($play_url, 'PUT', $headers, $play_payload);
        if ($retry_result['http_code'] === 204) {
            echo json_encode(['success' => true, 'message' => 'Riproduzione avviata dopo retry']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Server Spotify temporaneamente non disponibile']);
        }
    } else {
        $error_data = json_decode($play_result['response'], true);
        $error_msg = $error_data['error']['message'] ?? 'Errore sconosciuto';
        echo json_encode(['success' => false, 'error' => "Errore Spotify: $error_msg (HTTP {$play_result['http_code']})"]);
    }
    
} elseif ($action === 'transfer_playback' && $device_id) {
    // Solo trasferimento senza riproduzione
    $transfer_url = "https://api.spotify.com/v1/me/player";
    $transfer_payload = json_encode([
        'device_ids' => [$device_id],
        'play' => false
    ]);
    
    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ];
    
    $result = makeSpotifyRequest($transfer_url, 'PUT', $headers, $transfer_payload);
    
    if ($result['http_code'] === 204) {
        echo json_encode(['success' => true, 'message' => 'Riproduzione trasferita']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Errore nel trasferimento']);
    }
    
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parametri non validi']);
}
?>
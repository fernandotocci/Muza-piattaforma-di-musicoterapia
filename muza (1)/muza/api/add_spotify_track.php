<?php
/**
 
 */

// Inizia output buffering per catturare eventuali output indesiderati
ob_start();

session_start();
require_once '../includes/db.php';
require_once 'refresh_spotify_token.php';

// Pulisci il buffer se contiene errori o warning
if (ob_get_length()) {
    ob_clean();
}

// Controlla se l'utente è autenticato 
$user = $_SESSION['user'] ?? null;
$raw_input = file_get_contents('php://input');
$json_input = $raw_input ? json_decode($raw_input, true) : null;
$spotify_track_id = $_GET['spotify_track_id'] ?? $_POST['spotify_track_id'] ?? $json_input['spotify_id'] ?? $json_input['spotify_track_id'] ?? null;
$return_to_session = $_GET['return_to_session'] ?? $_POST['return_to_session'] ?? false;

// Se viene richiesto il ritorno alla sessione, non impostare header JSON
if (!$return_to_session) {
    header('Content-Type: application/json');
}

// Accetta sia pazienti che terapeuti (terapeuta può aggiungere brani alla propria libreria)
if (!$user || !in_array($user['user_type'], ['patient', 'therapist'])) {
    if ($return_to_session) {
        $_SESSION['error'] = 'Non autorizzato';
        header('Location: ../patient_dashboard.php');
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
        exit;
    }
}

// Per richieste POST JSON da terapeuta, i metadati del brano possono arrivare nel body
// Verifica che l'ID Spotify sia presente almeno
if (!$spotify_track_id) {
    if ($return_to_session) {
        $_SESSION['error'] = 'ID Spotify mancante';
        header('Location: ../patient_dashboard.php');
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'ID Spotify mancante']);
        exit;
    }
}

try {
    // Se il terapeuta invia metadati JSON (title), permetti l'inserimento senza chiamare Spotify
    $provided_metadata = ($user['user_type'] === 'therapist' && !empty($json_input['title']));

    // Controlla se il brano esiste già: priorità spotify_track_id se presente, altrimenti title+artist per metadati forniti
    if (!empty($spotify_track_id)) {
        $check_query = "SELECT id FROM tracks WHERE spotify_track_id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("s", $spotify_track_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif ($provided_metadata) {
        $meta_title = substr($json_input['title'], 0, 255);
        $meta_artist = substr($json_input['artist'] ?? '', 0, 255);
        $check_query = "SELECT id FROM tracks WHERE title = ? AND artist = ? LIMIT 1";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("ss", $meta_title, $meta_artist);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $existing = null;
    }

    if ($existing) {
        if ($return_to_session) {
            header("Location: ../listening_session.php?track=" . $existing['id'] . "&is_recommendation=true");
            exit;
        } else {
            echo json_encode(['success' => true, 'track_id' => $existing['id'], 'message' => 'Brano già esistente']);
            exit;
        }
    }
    // Se sono stati forniti metadati dal terapeuta, usa quelli senza chiamare Spotify
    if ($provided_metadata) {
        $title = substr($json_input['title'], 0, 255);
        $artist = substr($json_input['artist'] ?? '', 0, 255);
        $album = substr($json_input['album'] ?? '', 0, 255);
        $duration = isset($json_input['duration_ms']) ? intval($json_input['duration_ms'] / 1000) : 0;
        $image_url = $json_input['image'] ?? null;
        $preview_url = $json_input['preview_url'] ?? null;
        $explicit = !empty($json_input['explicit']) ? 1 : 0;
        // spotify_track_id may be absent
        $spotify_track_id = $json_input['spotify_id'] ?? $spotify_track_id;
        $therapist_id = ($user['user_type'] === 'therapist') ? intval($user['id']) : null;
    } else {
        // Ottieni token Spotify valido
        $spotify_token = getValidSpotifyToken($user['id']);
        if (!$spotify_token) {
            throw new Exception('Token Spotify non disponibile - collega il tuo account Spotify');
        }

        // Log diagnostico: token (mascherato) e controllo /me per validità
        $masked_token = is_string($spotify_token) ? (substr($spotify_token,0,6) . '...' . substr($spotify_token, -6)) : 'NULL';
        error_log("ADD_SPOTIFY_TRACK: user_id={$user['id']} - access_token(masked)={$masked_token}");
        // Controllo rapido /me per capire se il token è valido e a quale user appartiene
        $me_url = "https://api.spotify.com/v1/me";
        $me_ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer " . $spotify_token . "\r\n",
                'timeout' => 10
            ]
        ]);
        $me_resp = @file_get_contents($me_url, false, $me_ctx);
        $me_status = 0;
        if (isset($http_response_header) && is_array($http_response_header) && preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $http_response_header[0], $m)) {
            $me_status = intval($m[1]);
        }
        $me_body_preview = $me_resp ? substr($me_resp, 0, 1000) : '';
        error_log("ADD_SPOTIFY_TRACK: /me http_code={$me_status} body_preview=" . $me_body_preview);

        // Log per debug
        error_log("ADD_SPOTIFY_TRACK: Tentativo di recuperare dati per ID: " . $spotify_track_id);
        
        // Recupera dati del brano da Spotify
        $track_url = "https://api.spotify.com/v1/tracks/" . urlencode($spotify_track_id);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer " . $spotify_token . "\r\n",
                'timeout' => 30
            ]
        ]);
        
        $response = file_get_contents($track_url, false, $context);
        if ($response === false) {
            $error_details = error_get_last();
            error_log("ADD_SPOTIFY_TRACK: Errore file_get_contents: " . print_r($error_details, true));
            throw new Exception('Errore nel contattare Spotify API - verifica la connessione internet');
        }
        
        $track_data = json_decode($response, true);
        if (!$track_data) {
            error_log("ADD_SPOTIFY_TRACK: Risposta non JSON valida: " . $response);
            throw new Exception('Risposta non valida da Spotify');
        }
        
        if (isset($track_data['error'])) {
            error_log("ADD_SPOTIFY_TRACK: Errore Spotify API: " . print_r($track_data['error'], true));
            if ($track_data['error']['status'] === 401) {
                throw new Exception('Token Spotify scaduto - ricollega il tuo account');
            } else if ($track_data['error']['status'] === 404) {
                throw new Exception('Brano non trovato su Spotify');
            } else {
                throw new Exception('Errore Spotify: ' . $track_data['error']['message']);
            }
        }

        // Prepara i dati per l'inserimento (per il caso paziente quando abbiamo chiamato Spotify)
        $title = $track_data['name'];
        $artist = implode(', ', array_column($track_data['artists'], 'name'));
        $album = $track_data['album']['name'];
        $duration = intval($track_data['duration_ms'] / 1000); // Converti in secondi
        $image_url = $track_data['album']['images'][0]['url'] ?? null;
        $preview_url = $track_data['preview_url'] ?? null;
        $explicit = !empty($track_data['explicit']) ? 1 : 0;
        $therapist_id = ($user['user_type'] === 'therapist') ? intval($user['id']) : null;
    }

    // Inserisci il brano nel database (terapista viene salvato come owner se presente)
    $insert_query = "
        INSERT INTO tracks (
            title, artist, album, duration, spotify_track_id, 
            image_url, preview_url, explicit, therapist_id, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmt = $conn->prepare($insert_query);
    if ($stmt === false) {
        throw new Exception('Errore nella preparazione della query: ' . $conn->error);
    }
    $stmt->bind_param("sssisssii",
        $title, $artist, $album, $duration, $spotify_track_id,
        $image_url, $preview_url, $explicit, $therapist_id
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Errore nel salvare il brano: ' . $stmt->error);
    }
    
    $new_track_id = $conn->insert_id;
    $stmt->close();
    
    // Se deve tornare alla sessione, reindirizza
    if ($return_to_session) {
        header("Location: ../listening_session.php?track=" . $new_track_id . "&is_recommendation=true");
        exit;
    } else {
        echo json_encode([
            'success' => true, 
            'track_id' => $new_track_id,
            'message' => 'Brano aggiunto con successo'
        ]);
    }
    
} catch (Exception $e) {
    error_log("ADD_SPOTIFY_TRACK: " . $e->getMessage());
    
    if ($return_to_session) {
        $_SESSION['error'] = 'Errore nel caricare il brano: ' . $e->getMessage();
        header("Location: ../patient_dashboard.php");
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} finally {
    // Assicurati che l'output buffer sia gestito correttamente
    if (ob_get_level()) {
        ob_end_flush();
    }
}
?>
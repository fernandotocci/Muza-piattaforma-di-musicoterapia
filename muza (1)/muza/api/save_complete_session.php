<?php
/**
 * API: save_complete_session.php
 * Salva una sessione di ascolto completa con tutti i dati raccolti
 * 
 * Endpoint: POST /api/save_complete_session.php
 * Autenticazione: Richiesta (paziente)
 * 
 * Body JSON:
 * {
 *   "trackId": int,
 *   "moodBefore": int (1-10),
 *   "moodAfter": int (1-10), 
 *   "energyBefore": int (1-10),
 *   "energyAfter": int (1-10),
 *   "listenDuration": int (secondi),
 *   "rating": int (1-5),
 *   "helpful": boolean,
 *   "emotionalImpact": string,
 *   "notes": string,
 *   "listeningNotes": string,
 *   "completed": boolean
 * }
 */

// Evita output HTML indesiderato
ob_start();

session_start();
require_once '../includes/db.php';

// Pulizia output buffer per evitare HTML prima del JSON
ob_clean();

// Headers per API JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Gestione preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verifica che sia una richiesta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Metodo non consentito',
        'code' => 'METHOD_NOT_ALLOWED'
    ]);
    exit;
}

// Verifica autenticazione
if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'error' => 'Non autorizzato',
        'code' => 'UNAUTHORIZED'
    ]);
    exit;
}

// Verifica connessione database
if (!$conn || $conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore di connessione al database',
        'code' => 'DATABASE_CONNECTION_ERROR'
    ]);
    exit;
}

$user_id = $_SESSION['user']['id'];

// Lettura e decodifica input JSON
$input_raw = file_get_contents('php://input');
$input = json_decode($input_raw, true);

// Debug: log sicuro senza dati sensibili
error_log("save_complete_session.php - Request from user_id: $user_id");

// Validazione JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'JSON non valido: ' . json_last_error_msg(),
        'code' => 'INVALID_JSON'
    ]);
    exit;
}

// Validazione dati obbligatori
$required_fields = ['trackId', 'moodBefore', 'moodAfter', 'energyBefore', 'energyAfter'];
$missing_fields = [];

foreach ($required_fields as $field) {
    if (!isset($input[$field]) || $input[$field] === null || $input[$field] === '') {
        $missing_fields[] = $field;
    }
}

if (!empty($missing_fields)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Campi obbligatori mancanti: ' . implode(', ', $missing_fields),
        'code' => 'MISSING_REQUIRED_FIELDS',
        'missing_fields' => $missing_fields
    ]);
    exit;
}

// Validazione range valori
$track_id = intval($input['trackId']);
$mood_before = intval($input['moodBefore']);
$mood_after = intval($input['moodAfter']);
$energy_before = intval($input['energyBefore']);
$energy_after = intval($input['energyAfter']);

if ($track_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'ID traccia non valido',
        'code' => 'INVALID_TRACK_ID'
    ]);
    exit;
}

if ($mood_before < 1 || $mood_before > 10 || $mood_after < 1 || $mood_after > 10) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Valori umore devono essere tra 1 e 10',
        'code' => 'INVALID_MOOD_RANGE'
    ]);
    exit;
}

if ($energy_before < 1 || $energy_before > 10 || $energy_after < 1 || $energy_after > 10) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Valori energia devono essere tra 1 e 10',
        'code' => 'INVALID_ENERGY_RANGE'
    ]);
    exit;
}

try {
    // Verifica che la traccia esista
    $track_check = $conn->prepare("SELECT id FROM tracks WHERE id = ?");
    if (!$track_check) {
        throw new Exception('Errore nella preparazione query traccia: ' . $conn->error);
    }
    
    $track_check->bind_param("i", $track_id);
    $track_check->execute();
    $track_result = $track_check->get_result();
    
    if (!$track_result->fetch_assoc()) {
        $track_check->close();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Traccia audio non trovata',
            'code' => 'TRACK_NOT_FOUND'
        ]);
        exit;
    }
    $track_check->close();
    
    // Inizio transazione
    $conn->begin_transaction();
    
    // Preparazione dati
    $playlist_id = isset($input['playlist_id']) && $input['playlist_id'] > 0 ? intval($input['playlist_id']) : null;
    $listen_duration = isset($input['listenDuration']) ? max(0, intval($input['listenDuration'])) : 0;
    $completed = isset($input['completed']) ? (bool)$input['completed'] : true;
    $listening_notes = isset($input['listeningNotes']) ? trim($input['listeningNotes']) : '';
    $session_notes = isset($input['notes']) ? trim($input['notes']) : '';
    $all_notes = trim($listening_notes . ' ' . $session_notes);
    
    // 1. Inserimento sessione
    $session_sql = "
        INSERT INTO listening_sessions (
            user_id, track_id, playlist_id, session_type, mood_before, mood_after, 
            energy_before, energy_after, listen_duration, completed, notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";
    
    $session_stmt = $conn->prepare($session_sql);
    if (!$session_stmt) {
        throw new Exception('Errore preparazione query sessione: ' . $conn->error);
    }
    
    $session_type = $playlist_id ? 'playlist' : 'single_track';
    
    $session_stmt->bind_param(
        'iiisiiiiiss',
        $user_id,
        $track_id,
        $playlist_id,
        $session_type,
        $mood_before,
        $mood_after,
        $energy_before,
        $energy_after,
        $listen_duration,
        $completed,
        $all_notes
    );
    
    if (!$session_stmt->execute()) {
        throw new Exception('Errore inserimento sessione: ' . $session_stmt->error);
    }
    
    $session_id = $conn->insert_id;
    $session_stmt->close();
    
    // 2. Gestione valutazioni tracce
    $tracks_rated = 0;
    if (isset($input['track_ratings']) && is_array($input['track_ratings'])) {
        foreach ($input['track_ratings'] as $rated_track_id => $rating_data) {
            if (!is_numeric($rated_track_id) || !isset($rating_data['rating'])) {
                continue;
            }
            
            $rated_track_id = intval($rated_track_id);
            $rating = intval($rating_data['rating']);
            $feedback = isset($rating_data['feedback']) ? trim($rating_data['feedback']) : '';
            
            if ($rating < 1 || $rating > 5) {
                continue; // Salta valutazioni non valide
            }
            
            $rating_sql = "
                INSERT INTO track_ratings (
                    user_id, track_id, rating, feedback, playlist_id, session_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    rating = VALUES(rating),
                    feedback = VALUES(feedback),
                    session_id = VALUES(session_id),
                    updated_at = NOW()
            ";
            
            $rating_stmt = $conn->prepare($rating_sql);
            if (!$rating_stmt) {
                error_log('Errore preparazione rating per track ' . $rated_track_id . ': ' . $conn->error);
                continue;
            }
            
            $rating_stmt->bind_param('iiisii', 
                $user_id,
                $rated_track_id,
                $rating,
                $feedback,
                $playlist_id,
                $session_id
            );
            
            if ($rating_stmt->execute()) {
                $tracks_rated++;
            } else {
                error_log('Errore inserimento rating per track ' . $rated_track_id . ': ' . $rating_stmt->error);
            }
            
            $rating_stmt->close();
        }
    }
    
    // Commit transazione
    $conn->commit();
    
    // Calcolo miglioramenti
    $mood_improvement = $mood_after - $mood_before;
    $energy_improvement = $energy_after - $energy_before;
    
    // Risposta di successo
    echo json_encode([
        'success' => true,
        'message' => 'Sessione di ascolto completata con successo!',
        'data' => [
            'session_id' => $session_id,
            'mood_improvement' => $mood_improvement,
            'energy_improvement' => $energy_improvement,
            'tracks_rated' => $tracks_rated,
            'duration_minutes' => round($listen_duration / 60, 1),
            'completion_time' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback in caso di errore
    if ($conn->inTransaction ?? false) {
        $conn->rollback();
    }
    
    // Log errore
    error_log('save_complete_session.php - Errore: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore interno del server',
        'code' => 'INTERNAL_SERVER_ERROR',
        'details' => $e->getMessage()
    ]);
}

// Pulizia finale
ob_end_flush();
?>
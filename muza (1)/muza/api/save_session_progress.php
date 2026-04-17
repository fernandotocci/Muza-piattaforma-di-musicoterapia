<?php
/**
 * API: save_session_progress.php
 * Salva il progresso di una sessione in corso (auto-save ogni 30 secondi)
 * 
 * Endpoint: POST /api/save_session_progress.php
 * Autenticazione: Richiesta (paziente)
 * 
 * Body JSON:
 * {
 *   "trackId": int,
 *   "moodBefore": int (1-10),
 *   "energyBefore": int (1-10),
 *   "listenDuration": int (secondi),
 *   "listeningNotes": string,
 *   "completed": boolean
 * }
 */

session_start();
require_once '../includes/db.php';

// Headers per API JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Verifica autenticazione
if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    echo json_encode([
        'success' => false, 
        'error' => 'Non autorizzato',
        'code' => 'UNAUTHORIZED'
    ]);
    exit;
}

$user_id = $_SESSION['user']['id'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['trackId'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Dati mancanti - trackId richiesto',
        'code' => 'MISSING_DATA'
    ]);
    exit;
}

try {
    // Salva progresso temporaneo
    $progress_sql = "
        INSERT INTO listening_sessions (
            user_id, track_id, mood_before, energy_before, 
            listen_duration, notes, completed, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE
            listen_duration = GREATEST(listen_duration, VALUES(listen_duration)),
            notes = COALESCE(NULLIF(VALUES(notes), ''), notes),
            mood_before = COALESCE(VALUES(mood_before), mood_before),
            energy_before = COALESCE(VALUES(energy_before), energy_before)
    ";
    
    $stmt = $conn->prepare($progress_sql);
    $stmt->bind_param('iiiiis', 
        $user_id,
        $input['trackId'],
        $input['moodBefore'] ?? null,
        $input['energyBefore'] ?? null,
        $input['listenDuration'] ?? 0,
        $input['sessionNotes'] ?? ''
    );
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Progresso salvato'
        ]);
    } else {
        throw new Exception('Errore nel salvare il progresso');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'DATABASE_ERROR'
    ]);
}
?>
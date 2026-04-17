<?php
/**
 * API: save_track_rating.php
 * Salva la valutazione di una singola traccia durante la sessione
 */

session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Non autorizzato',
        'code' => 'UNAUTHORIZED'
    ]);
    exit;
}

$user_id = $_SESSION['user']['id'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['track_id']) || !isset($input['rating'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Dati mancanti - track_id e rating richiesti',
        'code' => 'MISSING_DATA'
    ]);
    exit;
}

$track_id = intval($input['track_id']);
$rating = intval($input['rating']);
$feedback = $input['feedback'] ?? '';
$playlist_id = isset($input['playlist_id']) ? intval($input['playlist_id']) : null;

// Validazione rating
if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Rating deve essere tra 1 e 5',
        'code' => 'INVALID_RATING'
    ]);
    exit;
}

try {
    // Verifica che la traccia esista
    $track_check = $conn->prepare("SELECT id, title FROM tracks WHERE id = ?");
    $track_check->bind_param("i", $track_id);
    $track_check->execute();
    $track_result = $track_check->get_result();
    
    if (!$track_result->fetch_assoc()) {
        throw new Exception('Traccia non trovata');
    }
    
    // Salva o aggiorna la valutazione
    $rating_sql = "
        INSERT INTO track_ratings (
            user_id, track_id, rating, helpful, emotional_impact, feedback, playlist_id, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            rating = VALUES(rating),
            helpful = VALUES(helpful),
            emotional_impact = VALUES(emotional_impact),
            feedback = VALUES(feedback),
            playlist_id = VALUES(playlist_id),
            updated_at = NOW()
    ";
    
    // Determina se è stato utile (4-5 stelle)
    $helpful = ($rating >= 4) ? 1 : 0;
    
    // Determina impatto emotivo basato sul rating
    $emotional_impact = 'neutral';
    if ($rating >= 4) {
        $emotional_impact = 'positive';
    } elseif ($rating <= 2) {
        $emotional_impact = 'negative';
    }
    
    $stmt = $conn->prepare($rating_sql);
    $stmt->bind_param('iisissi', 
        $user_id,
        $track_id,
        $rating,
        $helpful,
        $emotional_impact,
        $feedback,
        $playlist_id
    );
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Valutazione salvata con successo',
            'rating_data' => [
                'track_id' => $track_id,
                'rating' => $rating,
                'helpful' => $helpful,
                'emotional_impact' => $emotional_impact
            ]
        ]);
    } else {
        throw new Exception('Errore nel salvare la valutazione: ' . $stmt->error);
    }
    
} catch (Exception $e) {
    error_log("TRACK_RATING_ERROR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'DATABASE_ERROR'
    ]);
}
?>

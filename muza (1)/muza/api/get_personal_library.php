<?php
/**
 * API per ottenere la libreria personale del terapeuta
 */

session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

// Abilita la visualizzazione degli errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verifica autenticazione
if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'therapist') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

$user_id = $_SESSION['user']['id'];

try {
    // Prima verifichiamo la struttura della tabella
    $check_query = "DESCRIBE tracks";
    $check_result = $conn->query($check_query);
    
    if (!$check_result) {
        throw new Exception("Errore nella verifica della tabella tracks: " . $conn->error);
    }
    
    $columns = [];
    while ($row = $check_result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    // Costruiamo la query dinamicamente basandoci sulle colonne disponibili
    $select_fields = ['t.id', 't.title', 't.artist'];
    
    // Aggiungi campi opzionali se esistono
    $optional_fields = [
        'spotify_id', 'album', 'duration_ms', 'image_url', 
        'preview_url', 'spotify_url', 'popularity', 'explicit', 'created_at'
    ];
    
    foreach ($optional_fields as $field) {
        if (in_array($field, $columns)) {
            $select_fields[] = "t.$field";
        }
    }
    
    // Gestione speciale per il campo duration (potrebbe essere duration o duration_ms)
    if (in_array('duration', $columns)) {
        $select_fields[] = "t.duration";
    } elseif (in_array('duration_ms', $columns)) {
        $select_fields[] = "t.duration_ms as duration";
    }
    
    $query = "
        SELECT " . implode(', ', $select_fields) . "
        FROM tracks t 
        WHERE t.therapist_id = ? 
        ORDER BY " . (in_array('created_at', $columns) ? 't.created_at DESC' : 't.id DESC') . "
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Errore nella preparazione della query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Errore nell'esecuzione della query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    $tracks = [];
    while ($row = $result->fetch_assoc()) {
        $track = [
            'id' => (int)$row['id'],
            'title' => $row['title'] ?? 'Titolo sconosciuto',
            'artist' => $row['artist'] ?? 'Artista sconosciuto',
            'album' => $row['album'] ?? '',
            'duration' => isset($row['duration']) ? (int)$row['duration'] : 0,
            'image_url' => $row['image_url'] ?? '',
            'preview_url' => $row['preview_url'] ?? '',
            'spotify_url' => $row['spotify_url'] ?? '',
            'popularity' => isset($row['popularity']) ? (int)$row['popularity'] : 0,
            'explicit' => isset($row['explicit']) ? (bool)$row['explicit'] : false,
            'created_at' => $row['created_at'] ?? null
        ];
        
        // Aggiungi spotify_id se presente
        if (isset($row['spotify_id'])) {
            $track['spotify_id'] = $row['spotify_id'];
        }
        
        $tracks[] = $track;
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'tracks' => $tracks,
        'count' => count($tracks),
        'debug' => [
            'columns_found' => $columns,
            'user_id' => $user_id
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Errore get_personal_library.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore interno del server',
        'debug_message' => $e->getMessage(),
        'debug_trace' => $e->getTraceAsString()
    ]);
}
?>

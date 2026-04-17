<?php
/**
 * API per rimuovere un brano dalla libreria personale
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

// Verifica metodo
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

$user_id = $_SESSION['user']['id'];

try {
    // Leggi dati JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['track_id']) || !is_numeric($input['track_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID brano non valido']);
        exit;
    }
    
    $track_id = (int)$input['track_id'];
    
    // Verifica che il brano appartenga al terapeuta
    $check_query = "SELECT id, title FROM tracks WHERE id = ? AND therapist_id = ?";
    $check_stmt = $conn->prepare($check_query);
    
    if (!$check_stmt) {
        throw new Exception("Errore nella preparazione della query di controllo: " . $conn->error);
    }
    
    $check_stmt->bind_param("ii", $track_id, $user_id);
    
    if (!$check_stmt->execute()) {
        throw new Exception("Errore nell'esecuzione della query di controllo: " . $check_stmt->error);
    }
    
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $check_stmt->close();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Brano non trovato nella tua libreria']);
        exit;
    }
    
    $track_data = $check_result->fetch_assoc();
    $check_stmt->close();
    
    // Elimina il brano dalla libreria
    $delete_query = "DELETE FROM tracks WHERE id = ? AND therapist_id = ?";
    $delete_stmt = $conn->prepare($delete_query);
    
    if (!$delete_stmt) {
        throw new Exception("Errore nella preparazione della query di eliminazione: " . $conn->error);
    }
    
    $delete_stmt->bind_param("ii", $track_id, $user_id);
    
    if (!$delete_stmt->execute()) {
        throw new Exception("Errore nell'esecuzione della query di eliminazione: " . $delete_stmt->error);
    }
    
    if ($delete_stmt->affected_rows === 0) {
        $delete_stmt->close();
        throw new Exception("Nessun brano è stato eliminato");
    }
    
    $delete_stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Brano rimosso dalla libreria',
        'track_title' => $track_data['title']
    ]);
    
} catch (Exception $e) {
    error_log("Errore remove_from_library.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore interno del server',
        'debug_message' => $e->getMessage()
    ]);
}
?>

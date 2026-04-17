<?php
// api/save_spotify_state.php - Salva state per sicurezza CSRF
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['state'])) {
        $_SESSION['spotify_state'] = $input['state'];
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'State mancante']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
}
?>
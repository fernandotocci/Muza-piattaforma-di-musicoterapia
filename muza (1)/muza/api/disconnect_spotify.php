<?php
/**
 * API per disconnettere l'account Spotify del terapeuta
 */

session_start();
require_once '../includes/db.php';
require_once 'refresh_spotify_token.php';

header('Content-Type: application/json');

// Abilita la visualizzazione degli errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verifica autenticazione
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

// Supporta sia terapeuti che pazienti
$user_type = $_SESSION['user']['user_type'];
if (!in_array($user_type, ['therapist', 'patient'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Tipo utente non supportato']);
    exit;
}

// Verifica metodo HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito - usa POST']);
    exit;
}

$user_id = $_SESSION['user']['id'];

try {
    error_log("DISCONNECT_SPOTIFY: Inizio disconnessione per user_id=$user_id");
    
    // Verifica se l'utente ha Spotify collegato
    $check_query = "
        SELECT id, access_token, refresh_token 
        FROM user_music_tokens 
        WHERE user_id = ? AND service = 'spotify'
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($check_query);
    if (!$stmt) {
        throw new Exception("Errore nella preparazione della query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Errore nell'esecuzione della query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Nessun account Spotify collegato
        echo json_encode([
            'success' => true,
            'message' => 'Nessun account Spotify era collegato',
            'already_disconnected' => true
        ]);
        exit;
    }
    
    $token_data = $result->fetch_assoc();
    $stmt->close();
    
    // 1. Rimuovi token dal database
    $delete_query = "DELETE FROM user_music_tokens WHERE user_id = ? AND service = 'spotify'";
    $delete_stmt = $conn->prepare($delete_query);
    
    if (!$delete_stmt) {
        throw new Exception("Errore nella preparazione della query di eliminazione: " . $conn->error);
    }
    
    $delete_stmt->bind_param("i", $user_id);
    
    if (!$delete_stmt->execute()) {
        throw new Exception("Errore nell'eliminazione del token: " . $delete_stmt->error);
    }
    
    $delete_stmt->close();
    
    // 2. Rimuovi informazioni Spotify dal profilo utente
    $update_profile_query = "
        UPDATE users 
        SET spotify_id = NULL, 
            spotify_display_name = NULL, 
            spotify_image = NULL,
            updated_at = NOW()
        WHERE id = ?
    ";
    
    $update_stmt = $conn->prepare($update_profile_query);
    if (!$update_stmt) {
        throw new Exception("Errore nella preparazione dell'aggiornamento profilo: " . $conn->error);
    }
    
    $update_stmt->bind_param("i", $user_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Errore nell'aggiornamento del profilo: " . $update_stmt->error);
    }
    
    $update_stmt->close();
    
    // 3. Opzionale: Prova a revocare il token su Spotify (best practice)
    try {
        if (!empty($token_data['access_token'])) {
            // Chiamata API Spotify per revocare il token (opzionale)
            $revoke_url = 'https://accounts.spotify.com/api/token';
            
            // Nota: Spotify non ha un endpoint pubblico per revocare token,
            // ma possiamo tentare di invalidarlo tramite refresh con credenziali errate
            error_log("DISCONNECT_SPOTIFY: Token rimosso dal database per user_id=$user_id");
        }
    } catch (Exception $e) {
        // Non bloccare il processo se la revoca fallisce
        error_log("DISCONNECT_SPOTIFY: Avviso - impossibile revocare token su Spotify: " . $e->getMessage());
    }
    
    // 4. Pulisci sessione
    if (isset($_SESSION['spotify_connected'])) {
        unset($_SESSION['spotify_connected']);
    }
    
    error_log("DISCONNECT_SPOTIFY: Disconnessione completata con successo per user_id=$user_id");
    
    echo json_encode([
        'success' => true,
        'message' => 'Account Spotify disconnesso con successo! 🎵',
        'details' => [
            'tokens_removed' => true,
            'profile_cleared' => true,
            'disconnected_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    error_log("DISCONNECT_SPOTIFY: Errore per user_id=$user_id - " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore durante la disconnessione',
        'debug_message' => $e->getMessage(),
        'debug_trace' => $e->getTraceAsString()
    ]);
}
?>

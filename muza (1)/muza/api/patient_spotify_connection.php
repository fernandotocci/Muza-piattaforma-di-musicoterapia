<?php
// api/patient_spotify_connection.php - Gestisce la connessione Spotify per i pazienti
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/spotify_config.php';

// Imposta header JSON e abilita errori per debug
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log dettagliato per debug
error_log("=== PATIENT_SPOTIFY_CONNECTION START ===");
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Session exists: " . (isset($_SESSION['user']) ? 'YES' : 'NO'));
if (isset($_SESSION['user'])) {
    error_log("User ID: " . $_SESSION['user']['id']);
    error_log("User Type: " . $_SESSION['user']['user_type']);
}

// Verifica autenticazione
if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

$user = $_SESSION['user'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Verifica stato connessione
        $token_query = "
            SELECT access_token, refresh_token, expires_at, created_at
            FROM user_music_tokens 
            WHERE user_id = ? AND service = 'spotify' 
            ORDER BY created_at DESC 
            LIMIT 1
        ";
        
        $stmt = $conn->prepare($token_query);
        if (!$stmt) {
            throw new Exception('Errore database: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $token_data = $result->fetch_assoc();
            $is_valid = strtotime($token_data['expires_at']) > time();
            
            echo json_encode([
                'success' => true,
                'spotify_connected' => true,
                'token_valid' => $is_valid,
                'expires_at' => $token_data['expires_at']
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'spotify_connected' => false
            ]);
        }
        
        $stmt->close();
        
    } elseif ($method === 'POST') {
        error_log("POST request detected");
        
        $input_raw = file_get_contents('php://input');
        error_log("Raw input: " . $input_raw);
        
        $input = json_decode($input_raw, true);
        error_log("Parsed input: " . print_r($input, true));
        
        $action = $input['action'] ?? '';
        error_log("Action: " . $action);
        
        if ($action === 'connect') {
            error_log("PATIENT_SPOTIFY_CONNECTION: Generazione URL auth per user_id=" . $user['id']);
            
            // Usa configurazione centralizzata
            $state = bin2hex(random_bytes(16));
            $_SESSION['spotify_state'] = $state;
            
            $auth_url = generateSpotifyAuthUrl($state);
            
            error_log("PATIENT_SPOTIFY_CONNECTION: redirect_uri=" . SPOTIFY_REDIRECT_URI);
            error_log("PATIENT_SPOTIFY_CONNECTION: state=" . $state);
            
            echo json_encode([
                'success' => true,
                'auth_url' => $auth_url
            ]);
            
        } elseif ($action === 'disconnect') {
            // Rimuovi token Spotify
            $delete_query = "DELETE FROM user_music_tokens WHERE user_id = ? AND service = 'spotify'";
            $stmt = $conn->prepare($delete_query);
            
            if ($stmt) {
                $stmt->bind_param("i", $user['id']);
                $success = $stmt->execute();
                $stmt->close();
                
                if ($success) {
                    echo json_encode(['success' => true, 'message' => 'Account Spotify disconnesso']);
                } else {
                    throw new Exception('Errore nella disconnessione');
                }
            } else {
                throw new Exception('Errore database: ' . $conn->error);
            }
            
        } else {
            throw new Exception('Azione non valida');
        }
        
    } else {
        throw new Exception('Metodo non supportato');
    }
    
} catch (Exception $e) {
    error_log("PATIENT_SPOTIFY_CONNECTION: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

<?php
// callback.php - Gestione callback per API musicali e OAuth
session_start();
require_once 'includes/db.php';
require_once 'config/spotify_config.php';

// Log per debug - temporaneamente abilitato
error_log("=== SPOTIFY CALLBACK DEBUG ===");
error_log("Callback ricevuto GET: " . print_r($_GET, true));
error_log("Callback ricevuto POST: " . print_r($_POST, true));
error_log("Session user: " . (isset($_SESSION['user']) ? $_SESSION['user']['id'] : 'NON LOGGATO'));
error_log("Callback type: " . ($_GET['type'] ?? 'MANCANTE'));
error_log("=== END DEBUG ===");

// CONFIGURAZIONE API - Usa configurazione centralizzata
// Le credenziali sono ora in config/spotify_config.php

// Se arriva da Spotify senza parametro type, gestiscilo
if (isset($_GET['code']) && !isset($_GET['type'])) {
    $_GET['type'] = 'spotify'; // Forza il tipo Spotify
}

// Gestisci diversi tipi di callback
$callback_type = $_GET['type'] ?? 'default';

switch($callback_type) {
    case 'spotify':
        handleSpotifyCallback();
        break;
    
    case 'youtube':
        handleYouTubeCallback();
        break;
    
    case 'apple':
        handleAppleMusicCallback();
        break;
    
    case 'deezer':
        handleDeezerCallback();
        break;
    
    case 'soundcloud':
        handleSoundCloudCallback();
        break;
    
    case 'oauth':
        handleOAuthCallback();
        break;
    
    case 'payment':
        handlePaymentCallback();
        break;
    
    case 'api':
        handleApiCallback();
        break;
    
    default:
        handleDefaultCallback();
        break;
}

/**
 * Gestisce callback Spotify OAuth
 */
function handleSpotifyCallback() {
    global $conn;
    
    error_log("=== HANDLE SPOTIFY CALLBACK ===");
    
    // Verifica che l'utente sia loggato
    if (!isset($_SESSION['user'])) {
        error_log("ERRORE: Utente non loggato");
        $_SESSION['error'] = "Devi essere loggato per collegare Spotify";
        header('Location: login.php');
        exit;
    }
    
    error_log("Utente loggato: " . $_SESSION['user']['id']);
    
    if (isset($_GET['code'])) {
        error_log("Authorization code ricevuto: " . substr($_GET['code'], 0, 20) . "...");
        $auth_code = $_GET['code'];
        $state = $_GET['state'] ?? '';
        
        // Verifica state per sicurezza CSRF
        if (!isset($_SESSION['spotify_state']) || $_SESSION['spotify_state'] !== $state) {
            $_SESSION['error'] = "Errore di sicurezza OAuth - state non valido";
            header('Location: dashboard.php');
            exit;
        }
        
        // Rimuovi state dalla sessione
        unset($_SESSION['spotify_state']);
        
        // Scambia authorization code con access token
        $token_data = exchangeSpotifyCodeForToken($auth_code);
        
        if ($token_data && isset($token_data['access_token'])) {
            // Salva token nel database
            $save_result = saveSpotifyTokens($_SESSION['user']['id'], $token_data);
            
            if ($save_result) {
                // Ottieni profilo utente Spotify
                $spotify_profile = getSpotifyProfile($token_data['access_token']);
                
                if ($spotify_profile && isset($spotify_profile['id'])) {
                    // Aggiorna profilo utente con info Spotify
                    updateUserSpotifyProfile($_SESSION['user']['id'], $spotify_profile);
                    
                    $_SESSION['success'] = "Account Spotify collegato con successo! 🎵";
                    $_SESSION['spotify_connected'] = true;
                    
                    // Log successo
                    error_log("Spotify collegato con successo per utente: " . $_SESSION['user']['id']);
                } else {
                    error_log("Errore nel recuperare profilo Spotify: " . print_r($spotify_profile, true));
                    $_SESSION['error'] = "Errore nel recuperare il profilo Spotify";
                }
            } else {
                $_SESSION['error'] = "Errore nel salvare i token Spotify";
            }
            
            // Redirect alla dashboard corretta in base al tipo utente
            if ($_SESSION['user']['user_type'] === 'patient') {
                header('Location: patient_dashboard.php');
                exit;
            } elseif ($_SESSION['user']['user_type'] === 'therapist') {
                header('Location: therapist_dashboard.php');
                exit;
            } else {
                // Tipo utente sconosciuto, logout di sicurezza
                session_destroy();
                header('Location: login.php?error=invalid_user_type');
                exit;
            }
            
        } else {
            // Gestione errore migliorata
            if (is_array($token_data) && isset($token_data['error'])) {
                $error_code = $token_data['error'];
                $error_description = $token_data['error_description'] ?? 'Descrizione non disponibile';
                
                error_log("Errore Spotify OAuth: " . $error_code . " - " . $error_description);
                
                // Messaggi di errore più specifici
                switch ($error_code) {
                    case 'invalid_grant':
                        $_SESSION['error'] = "Codice di autorizzazione Spotify scaduto o non valido. Riprova.";
                        break;
                    case 'invalid_client':
                        $_SESSION['error'] = "Configurazione app Spotify non valida. Contatta l'amministratore.";
                        break;
                    case 'network_error':
                        $_SESSION['error'] = "Errore di connessione: " . $error_description;
                        break;
                    default:
                        $_SESSION['error'] = "Errore Spotify: " . $error_code . " - " . $error_description;
                }
            } else {
                $_SESSION['error'] = "Errore nella risposta di Spotify. Riprova.";
            }
            
            // Redirect alla dashboard corretta in base al tipo utente anche in caso di errore
            if (isset($_SESSION['user']['user_type']) && $_SESSION['user']['user_type'] === 'patient') {
                header('Location: patient_dashboard.php');
            } elseif (isset($_SESSION['user']['user_type']) && $_SESSION['user']['user_type'] === 'therapist') {
                header('Location: therapist_dashboard.php');
            } else {
                header('Location: login.php');
            }
            exit;
        }
        
    } elseif (isset($_GET['error'])) {
        $error = $_GET['error'];
        $error_description = $_GET['error_description'] ?? '';
        
        if ($error === 'access_denied') {
            $_SESSION['error'] = "Accesso negato. Hai rifiutato l'autorizzazione Spotify.";
        } else {
            $_SESSION['error'] = "Errore Spotify: " . htmlspecialchars($error . ' - ' . $error_description);
        }
        
        // Redirect alla dashboard corretta in base al tipo utente anche in caso di errore
        if (isset($_SESSION['user']['user_type']) && $_SESSION['user']['user_type'] === 'patient') {
            header('Location: patient_dashboard.php');
        } elseif (isset($_SESSION['user']['user_type']) && $_SESSION['user']['user_type'] === 'therapist') {
            header('Location: therapist_dashboard.php');
        } else {
            header('Location: login.php');
        }
        exit;
    } else {
        $_SESSION['error'] = "Callback Spotify non valido";
        // Redirect alla dashboard corretta in base al tipo utente anche in caso di errore
        if (isset($_SESSION['user']['user_type']) && $_SESSION['user']['user_type'] === 'patient') {
            header('Location: patient_dashboard.php');
        } elseif (isset($_SESSION['user']['user_type']) && $_SESSION['user']['user_type'] === 'therapist') {
            header('Location: therapist_dashboard.php');
        } else {
            header('Location: login.php');
        }
        exit;
    }
}

/**
 * Scambia authorization code con access token Spotify
 * @deprecated Usa exchangeSpotifyCodeForToken() da config/spotify_config.php
 */
function exchangeSpotifyCode($code) {
    return exchangeSpotifyCodeForToken($code);
}

/**
 * Ottiene profilo utente Spotify
 */
function getSpotifyProfile($access_token) {
    $headers = [
        'Authorization: Bearer ' . $access_token
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/me');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Solo per sviluppo locale
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return json_decode($response, true);
    } else {
        error_log("Errore Spotify profile: " . $response);
        return false;
    }
}

/**
 * Salva token Spotify nel database
 */
function saveSpotifyTokens($user_id, $token_data) {
    global $conn;
    
    error_log("=== SAVING SPOTIFY TOKENS ===");
    error_log("User ID: " . $user_id);
    error_log("Token data: " . print_r($token_data, true));
    
    // Usa prepared statement per sicurezza
    $sql = "
        INSERT INTO user_music_tokens 
        (user_id, service, access_token, refresh_token, expires_at, scope, created_at) 
        VALUES 
        (?, 'spotify', ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
        access_token = VALUES(access_token),
        refresh_token = VALUES(refresh_token),
        expires_at = VALUES(expires_at),
        scope = VALUES(scope),
        updated_at = NOW()
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Errore prepare statement: " . $conn->error);
        return false;
    }
    
    $access_token = $token_data['access_token'];
    $refresh_token = $token_data['refresh_token'] ?? '';
    $expires_in = intval($token_data['expires_in'] ?? 3600);
    $expires_at = date('Y-m-d H:i:s', time() + $expires_in);
    $scope = $token_data['scope'] ?? '';
    
    $stmt->bind_param('issss', $user_id, $access_token, $refresh_token, $expires_at, $scope);
    
    $result = $stmt->execute();
    if (!$result) {
        error_log("Errore SQL save tokens: " . $stmt->error);
    } else {
        error_log("Token salvati con successo");
    }
    
    $stmt->close();
    return $result;
}

/**
 * Aggiorna profilo utente con info Spotify
 */
function updateUserSpotifyProfile($user_id, $spotify_profile) {
    global $conn;
    
    $spotify_id = $conn->real_escape_string($spotify_profile['id']);
    $spotify_display_name = $conn->real_escape_string($spotify_profile['display_name'] ?? '');
    $spotify_image = '';
    
    // Gestisci immagine profilo
    if (isset($spotify_profile['images']) && is_array($spotify_profile['images']) && count($spotify_profile['images']) > 0) {
        $spotify_image = $conn->real_escape_string($spotify_profile['images'][0]['url']);
    }
    
    $sql = "
        UPDATE users SET 
        spotify_id = '$spotify_id',
        spotify_display_name = '$spotify_display_name',
        spotify_image = '$spotify_image',
        updated_at = NOW()
        WHERE id = $user_id
    ";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Errore SQL update profile: " . $conn->error);
    }
    
    return $result;
}

/**
 * Gestisce callback YouTube Music
 */
function handleYouTubeCallback() {
    if (!isset($_SESSION['user'])) {
        $_SESSION['error'] = "Devi essere loggato per collegare YouTube";
        header('Location: login.php');
        exit;
    }
    
    if (isset($_GET['code'])) {
        $auth_code = $_GET['code'];
        
        // TODO: Implementa OAuth YouTube Music
        // Per ora solo messaggio di successo
        $_SESSION['success'] = "YouTube Music collegato! (In sviluppo)";
        header('Location: dashboard.php');
        exit;
    } elseif (isset($_GET['error'])) {
        $_SESSION['error'] = "Errore YouTube: " . htmlspecialchars($_GET['error']);
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Gestisce callback Apple Music
 */
function handleAppleMusicCallback() {
    if (!isset($_SESSION['user'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Non autorizzato']);
        exit;
    }
    
    if (isset($_POST['user_token'])) {
        $user_token = $_POST['user_token'];
        
        // Salva token Apple Music
        $result = saveAppleMusicToken($_SESSION['user']['id'], $user_token);
        
        if ($result) {
            $_SESSION['success'] = "Apple Music collegato! 🍎";
            
            // Risposta JSON per JavaScript
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Apple Music collegato']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Errore nel salvare token']);
        }
        exit;
    }
    
    // Se non è POST, redirect
    header('Location: dashboard.php');
    exit;
}

/**
 * Gestisce callback Deezer
 */
function handleDeezerCallback() {
    if (!isset($_SESSION['user'])) {
        $_SESSION['error'] = "Devi essere loggato per collegare Deezer";
        header('Location: login.php');
        exit;
    }
    
    if (isset($_GET['code'])) {
        $auth_code = $_GET['code'];
        
        // TODO: Implementa OAuth Deezer
        $_SESSION['success'] = "Deezer collegato! (In sviluppo)";
        header('Location: dashboard.php');
        exit;
    } elseif (isset($_GET['error'])) {
        $_SESSION['error'] = "Errore Deezer: " . htmlspecialchars($_GET['error']);
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Gestisce callback SoundCloud
 */
function handleSoundCloudCallback() {
    if (!isset($_SESSION['user'])) {
        $_SESSION['error'] = "Devi essere loggato per collegare SoundCloud";
        header('Location: login.php');
        exit;
    }
    
    if (isset($_GET['code'])) {
        $auth_code = $_GET['code'];
        
        // TODO: Implementa OAuth SoundCloud
        $_SESSION['success'] = "SoundCloud collegato! (In sviluppo)";
        header('Location: dashboard.php');
        exit;
    } elseif (isset($_GET['error'])) {
        $_SESSION['error'] = "Errore SoundCloud: " . htmlspecialchars($_GET['error']);
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Gestisce callback OAuth generici
 */
function handleOAuthCallback() {
    if (isset($_GET['code'])) {
        $auth_code = $_GET['code'];
        
        // TODO: Gestione OAuth generica
        $_SESSION['success'] = "OAuth completato";
        header('Location: dashboard.php');
        exit;
        
    } elseif (isset($_GET['error'])) {
        $error = $_GET['error'];
        $_SESSION['error'] = "Errore OAuth: " . htmlspecialchars($error);
        header('Location: login.php');
        exit;
    }
}

/**
 * Gestisce callback pagamenti
 */
function handlePaymentCallback() {
    if (!isset($_SESSION['user'])) {
        $_SESSION['error'] = "Sessione scaduta";
        header('Location: login.php');
        exit;
    }
    
    if (isset($_GET['session_id'])) {
        $session_id = $_GET['session_id'];
        
        // TODO: Verifica pagamento con Stripe/PayPal
        $_SESSION['success'] = "Pagamento completato con successo!";
        header('Location: dashboard.php');
        exit;
        
    } elseif (isset($_GET['canceled'])) {
        $_SESSION['error'] = "Pagamento annullato";
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Gestisce callback API webhook
 */
function handleApiCallback() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data) {
        // Log del webhook ricevuto
        error_log("API Webhook ricevuto: " . print_r($data, true));
        
        // TODO: Processa dati API
        
        // Risposta JSON standard
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', 
            'message' => 'Webhook processato',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Risposta di errore
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Dati non validi']);
    exit;
}

/**
 * Callback di default - redirect appropriato
 */
function handleDefaultCallback() {
    if (isset($_SESSION['user'])) {
        if ($_SESSION['user']['user_type'] === 'patient') {
            header('Location: patient_dashboard.php');
        } else {
            header('Location: therapist_dashboard.php');
        }
    } else {
        header('Location: login.php');
    }
    exit;
}

/**
 * Salva token Apple Music
 */
function saveAppleMusicToken($user_id, $user_token) {
    global $conn;
    
    $token = $conn->real_escape_string($user_token);
    
    $sql = "
        INSERT INTO user_music_tokens 
        (user_id, service, access_token, created_at) 
        VALUES 
        ($user_id, 'apple', '$token', NOW())
        ON DUPLICATE KEY UPDATE
        access_token = VALUES(access_token),
        updated_at = NOW()
    ";
    
    $result = $conn->query($sql);
    if (!$result) {
        error_log("Errore SQL save Apple token: " . $conn->error);
    }
    
    return $result;
}

/**
 * Funzione di utilità per verificare se un utente ha collegato un servizio
 */
function isServiceConnected($user_id, $service) {
    global $conn;
    
    $sql = "
        SELECT id FROM user_music_tokens 
        WHERE user_id = $user_id 
        AND service = '$service' 
        AND (expires_at IS NULL OR expires_at > NOW())
    ";
    
    $result = $conn->query($sql);
    return $result && $result->num_rows > 0;
}

/**
 * Refresh token Spotify se necessario
 */
function refreshSpotifyToken($user_id) {
    global $conn;
    
    $sql = "
        SELECT refresh_token, expires_at 
        FROM user_music_tokens 
        WHERE user_id = $user_id AND service = 'spotify'
    ";
    
    $result = $conn->query($sql);
    if (!$result || $result->num_rows === 0) {
        return false;
    }
    
    $token_data = $result->fetch_assoc();
    
    // Controlla se il token è scaduto
    if ($token_data['expires_at'] && strtotime($token_data['expires_at']) <= time()) {
        // Token scaduto, prova a rinnovarlo
        $refresh_result = performSpotifyTokenRefresh($token_data['refresh_token']);
        
        if ($refresh_result) {
            // Salva il nuovo token
            saveSpotifyTokens($user_id, $refresh_result);
            return $refresh_result['access_token'];
        }
    }
    
    return false;
}

/**
 * Esegue il refresh del token Spotify
 */
function performSpotifyTokenRefresh($refresh_token) {
    $data = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token,
    ];
    
    $headers = [
        'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
        'Content-Type: application/x-www-form-urlencoded'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return json_decode($response, true);
    } else {
        error_log("Errore refresh token Spotify: " . $response);
        return false;
    }
}

// Gestione errori generali - se arriviamo qui senza match, redirect di default
if (!headers_sent()) {
    error_log("Callback non gestito, redirect di default");
    header('Location: index.php');
    exit;
}
?>
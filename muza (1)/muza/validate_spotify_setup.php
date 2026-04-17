<?php
/**
 * validate_spotify_setup.php - Sistema di validazione configurazione Spotify
 * Controlla che tutto sia configurato correttamente per evitare problemi futuri
 */

require_once 'config/spotify_config.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

function validateSpotifySetup() {
    $results = [
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'checks' => [],
        'warnings' => [],
        'errors' => []
    ];
    
    // 1. Verifica configurazione base
    $config_errors = validateSpotifyConfig();
    if (!empty($config_errors)) {
        $results['errors'] = array_merge($results['errors'], $config_errors);
        $results['status'] = 'error';
    }
    
    $results['checks']['config'] = [
        'status' => empty($config_errors) ? 'ok' : 'error',
        'message' => empty($config_errors) ? 'Configurazione valida' : 'Errori nella configurazione',
        'details' => $config_errors
    ];
    
    // 2. Test connessione API Spotify
    $api_test = testSpotifyApiConnection();
    $results['checks']['api_connection'] = $api_test;
    
    if ($api_test['status'] !== 'ok') {
        $results['errors'][] = $api_test['message'];
        $results['status'] = 'error';
    }
    
    // 3. Verifica database
    $db_test = testDatabaseSetup();
    $results['checks']['database'] = $db_test;
    
    if ($db_test['status'] !== 'ok') {
        $results['errors'][] = $db_test['message'];
        $results['status'] = 'error';
    }
    
    // 4. Test token exchange con code fittizio
    $token_test = testTokenExchange();
    $results['checks']['token_exchange'] = $token_test;
    
    if ($token_test['status'] === 'error') {
        $results['errors'][] = $token_test['message'];
        $results['status'] = 'error';
    } elseif ($token_test['status'] === 'warning') {
        $results['warnings'][] = $token_test['message'];
    }
    
    // 5. Verifica file di configurazione
    $files_test = testConfigurationFiles();
    $results['checks']['files'] = $files_test;
    
    if ($files_test['status'] !== 'ok') {
        $results['warnings'][] = $files_test['message'];
    }
    
    return $results;
}

function testSpotifyApiConnection() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/me');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer test_token']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if (!empty($curl_error)) {
        return [
            'status' => 'error',
            'message' => 'Errore di connessione API Spotify: ' . $curl_error
        ];
    }
    
    if ($http_code == 401) {
        return [
            'status' => 'ok',
            'message' => 'API Spotify raggiungibile (401 expected con token test)'
        ];
    }
    
    return [
        'status' => 'warning',
        'message' => 'Risposta API inaspettata: ' . $http_code
    ];
}

function testDatabaseSetup() {
    global $conn;
    
    try {
        // Verifica tabella user_music_tokens
        $result = $conn->query("SELECT COUNT(*) as count FROM user_music_tokens");
        if (!$result) {
            return [
                'status' => 'error',
                'message' => 'Tabella user_music_tokens non trovata: ' . $conn->error
            ];
        }
        
        $count = $result->fetch_assoc()['count'];
        
        return [
            'status' => 'ok',
            'message' => 'Database configurato correttamente',
            'details' => ['tokens_count' => $count]
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Errore database: ' . $e->getMessage()
        ];
    }
}

function testTokenExchange() {
    $fake_code = 'test_code_123';
    $result = exchangeSpotifyCodeForToken($fake_code);
    
    if (isset($result['error'])) {
        if ($result['error'] === 'invalid_grant') {
            return [
                'status' => 'ok',
                'message' => 'Token exchange configurato correttamente (invalid_grant expected con code fittizio)'
            ];
        } elseif ($result['error'] === 'invalid_client') {
            return [
                'status' => 'error',
                'message' => 'Credenziali Spotify non valide'
            ];
        } else {
            return [
                'status' => 'warning',
                'message' => 'Errore token exchange: ' . $result['error']
            ];
        }
    }
    
    return [
        'status' => 'warning',
        'message' => 'Risposta token exchange inaspettata'
    ];
}

function testConfigurationFiles() {
    $files = [
        'config/spotify_config.php',
        'api/patient_spotify_connection.php',
        'api/refresh_spotify_token.php',
        'callback.php'
    ];
    
    $missing_files = [];
    
    foreach ($files as $file) {
        if (!file_exists(__DIR__ . '/' . $file)) {
            $missing_files[] = $file;
        }
    }
    
    if (!empty($missing_files)) {
        return [
            'status' => 'error',
            'message' => 'File mancanti: ' . implode(', ', $missing_files)
        ];
    }
    
    return [
        'status' => 'ok',
        'message' => 'Tutti i file di configurazione presenti'
    ];
}

// Esegui validazione
$validation_results = validateSpotifySetup();
echo json_encode($validation_results, JSON_PRETTY_PRINT);
?>

<?php
/**
 * Debug Spotify Connection - Traccia dettagliata per troubleshooting
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/spotify_config.php';

// Abilita tutti gli errori per debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>🔍 Debug Spotify Connection</h1>";

// Test 1: Verifica configurazione
echo "<h2>1. Configurazione Spotify</h2>";
echo "<ul>";
echo "<li><strong>Client ID:</strong> " . SPOTIFY_CLIENT_ID . "</li>";
echo "<li><strong>Client Secret:</strong> " . (SPOTIFY_CLIENT_SECRET ? "✅ Presente" : "❌ Mancante") . "</li>";
echo "<li><strong>Redirect URI:</strong> " . SPOTIFY_REDIRECT_URI . "</li>";
echo "<li><strong>Token URL:</strong> " . SPOTIFY_TOKEN_URL . "</li>";
echo "</ul>";

// Test 2: Verifica autenticazione utente
echo "<h2>2. Stato Autenticazione</h2>";
if (isset($_SESSION['user'])) {
    echo "✅ Utente loggato: " . $_SESSION['user']['email'] . " (ID: " . $_SESSION['user']['id'] . ")";
    $user_id = $_SESSION['user']['id'];
} else {
    echo "❌ Utente NON loggato";
    exit;
}

// Test 3: Verifica connessione database
echo "<h2>3. Database Connection</h2>";
if ($conn && !$conn->connect_error) {
    echo "✅ Database connesso";
} else {
    echo "❌ Errore database: " . ($conn->connect_error ?? 'Connessione non disponibile');
    exit;
}

// Test 4: Verifica token esistenti
echo "<h2>4. Token Spotify Esistenti</h2>";
$token_query = "SELECT * FROM user_music_tokens WHERE user_id = ? AND service = 'spotify' ORDER BY created_at DESC LIMIT 1";
$stmt = $conn->prepare($token_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $token_data = $result->fetch_assoc();
    echo "<ul>";
    echo "<li><strong>Token trovato:</strong> ✅</li>";
    echo "<li><strong>Expires at:</strong> " . $token_data['expires_at'] . "</li>";
    echo "<li><strong>È scaduto:</strong> " . (strtotime($token_data['expires_at']) <= time() ? "❌ SI" : "✅ NO") . "</li>";
    echo "<li><strong>Refresh token:</strong> " . (!empty($token_data['refresh_token']) ? "✅ Presente" : "❌ Mancante") . "</li>";
    echo "</ul>";
} else {
    echo "❌ Nessun token Spotify trovato";
}

// Test 5: Test chiamata token exchange
echo "<h2>5. Test Token Exchange</h2>";

if (isset($_GET['test_token'])) {
    echo "<p>⚠️ <strong>ATTENZIONE:</strong> Questo è un test con un authorization code fittizio per verificare il meccanismo.</p>";
    
    $test_code = 'test_code_12345';
    
    $data = [
        'grant_type' => 'authorization_code',
        'code' => $test_code,
        'redirect_uri' => SPOTIFY_REDIRECT_URI,
    ];
    
    $headers = [
        'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
        'Content-Type: application/x-www-form-urlencoded'
    ];
    
    echo "<h3>Parametri inviati:</h3>";
    echo "<pre>" . print_r($data, true) . "</pre>";
    
    echo "<h3>Headers inviati:</h3>";
    echo "<pre>" . print_r($headers, true) . "</pre>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, SPOTIFY_TOKEN_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    echo "<h3>Risposta Spotify:</h3>";
    echo "<ul>";
    echo "<li><strong>HTTP Code:</strong> " . $http_code . "</li>";
    echo "<li><strong>cURL Error:</strong> " . ($curl_error ?: "Nessuno") . "</li>";
    echo "</ul>";
    
    echo "<h3>Risposta completa:</h3>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    
    $response_data = json_decode($response, true);
    if ($response_data) {
        echo "<h3>JSON Decodificato:</h3>";
        echo "<pre>" . print_r($response_data, true) . "</pre>";
    }
} else {
    echo "<p><a href='?test_token=1' class='btn'>🧪 Esegui Test Token Exchange</a></p>";
}

// Test 6: Genera URL di autorizzazione
echo "<h2>6. URL di Autorizzazione</h2>";
$test_state = 'debug_state_' . bin2hex(random_bytes(8));
$auth_url = generateSpotifyAuthUrl($test_state);
echo "<p><strong>URL generato:</strong></p>";
echo "<p><a href='$auth_url' target='_blank' style='word-break: break-all;'>$auth_url</a></p>";

// Test 7: Log recenti
echo "<h2>7. Log degli Errori Recenti</h2>";
$log_file = ini_get('error_log');
if ($log_file && file_exists($log_file)) {
    $log_content = file_get_contents($log_file);
    $log_lines = explode("\n", $log_content);
    $spotify_logs = array_filter($log_lines, function($line) {
        return strpos(strtolower($line), 'spotify') !== false;
    });
    
    if (!empty($spotify_logs)) {
        echo "<pre style='max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px;'>";
        echo htmlspecialchars(implode("\n", array_slice($spotify_logs, -20)));
        echo "</pre>";
    } else {
        echo "<p>Nessun log relativo a Spotify trovato.</p>";
    }
} else {
    echo "<p>File di log non accessibile.</p>";
}

echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h1, h2, h3 { color: #1db954; }
.btn { background: #1db954; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
ul li { margin: 5px 0; }
</style>";
?>
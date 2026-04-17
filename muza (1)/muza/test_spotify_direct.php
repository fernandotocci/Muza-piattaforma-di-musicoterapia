<?php
/**
 * Test diretto configurazione Spotify
 * Verifica che le credenziali e la configurazione siano corrette
 */

require_once 'includes/spotify_config.php';

// Abilita errori
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🎵 Test Diretto Spotify Configuration</h1>";

// Test 1: Verifica costanti
echo "<h2>1. Verifica Costanti Spotify</h2>";
$constants = [
    'SPOTIFY_CLIENT_ID' => SPOTIFY_CLIENT_ID,
    'SPOTIFY_CLIENT_SECRET' => SPOTIFY_CLIENT_SECRET,
    'SPOTIFY_REDIRECT_URI' => SPOTIFY_REDIRECT_URI,
    'SPOTIFY_TOKEN_URL' => SPOTIFY_TOKEN_URL,
    'SPOTIFY_SCOPE' => SPOTIFY_SCOPE
];

foreach ($constants as $name => $value) {
    $status = !empty($value) ? "✅" : "❌";
    echo "<p><strong>$name:</strong> $status " . ($name === 'SPOTIFY_CLIENT_SECRET' ? '[NASCOSTO]' : $value) . "</p>";
}

// Test 2: Verifica headers di autorizzazione
echo "<h2>2. Headers di Autorizzazione</h2>";
try {
    $headers = getSpotifyAuthHeaders();
    echo "<p>✅ Headers generati correttamente:</p>";
    echo "<ul>";
    foreach ($headers as $header) {
        // Nascondi le credenziali per sicurezza
        if (strpos($header, 'Authorization:') === 0) {
            echo "<li>Authorization: Basic [CREDENTIALS_HIDDEN]</li>";
        } else {
            echo "<li>$header</li>";
        }
    }
    echo "</ul>";
} catch (Exception $e) {
    echo "<p>❌ Errore nella generazione headers: " . $e->getMessage() . "</p>";
}

// Test 3: Test chiamata token con credenziali fittizie
echo "<h2>3. Test Endpoint Token Spotify</h2>";
echo "<p>⚠️ <strong>Nota:</strong> Questo test usa un authorization code fittizio per verificare che l'endpoint risponda.</p>";

$test_data = [
    'grant_type' => 'authorization_code',
    'code' => 'test_code_fake_12345',
    'redirect_uri' => SPOTIFY_REDIRECT_URI,
];

$headers = [
    'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
    'Content-Type: application/x-www-form-urlencoded',
    'User-Agent: Muza-Test/1.0'
];

echo "<h3>Parametri inviati:</h3>";
echo "<pre>" . print_r($test_data, true) . "</pre>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => SPOTIFY_TOKEN_URL,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($test_data),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_VERBOSE => false
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
$curl_info = curl_getinfo($ch);
curl_close($ch);

echo "<h3>Risultato Test:</h3>";
echo "<ul>";
echo "<li><strong>HTTP Code:</strong> $http_code " . getHttpCodeMeaning($http_code) . "</li>";
echo "<li><strong>cURL Error:</strong> " . ($curl_error ?: "Nessuno") . "</li>";
echo "<li><strong>Tempo connessione:</strong> " . round($curl_info['connect_time'], 2) . "s</li>";
echo "<li><strong>Tempo totale:</strong> " . round($curl_info['total_time'], 2) . "s</li>";
echo "</ul>";

echo "<h3>Risposta Spotify:</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px; max-height: 300px; overflow-y: auto;'>";
echo htmlspecialchars($response);
echo "</pre>";

// Analizza la risposta
$response_data = json_decode($response, true);
if ($response_data) {
    echo "<h3>Risposta JSON Decodificata:</h3>";
    echo "<pre>" . print_r($response_data, true) . "</pre>";
    
    if (isset($response_data['error'])) {
        echo "<div style='background: #ffeaa7; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>🔍 Analisi Errore Spotify:</h4>";
        
        switch ($response_data['error']) {
            case 'invalid_grant':
                echo "<p>❌ <strong>invalid_grant:</strong> Authorization code non valido o scaduto. Questo è normale per il test.</p>";
                echo "<p>✅ <strong>Endpoint raggiungibile:</strong> Spotify risponde correttamente.</p>";
                break;
            case 'invalid_client':
                echo "<p>❌ <strong>invalid_client:</strong> Client ID o Client Secret errati!</p>";
                echo "<p>🔧 <strong>Azione richiesta:</strong> Verifica le credenziali Spotify nell'app Developer Console.</p>";
                break;
            case 'invalid_request':
                echo "<p>❌ <strong>invalid_request:</strong> Parametri della richiesta non validi.</p>";
                break;
            default:
                echo "<p>❓ <strong>Errore sconosciuto:</strong> " . $response_data['error'] . "</p>";
        }
        echo "</div>";
    }
} else {
    echo "<p>⚠️ Risposta non è JSON valido.</p>";
}

// Test 4: Verifica redirect URI
echo "<h2>4. Verifica Redirect URI</h2>";
echo "<p><strong>Redirect URI configurato:</strong> " . SPOTIFY_REDIRECT_URI . "</p>";
echo "<p>🔧 <strong>Assicurati che questo URL sia autorizzato nella tua app Spotify:</strong></p>";
echo "<ol>";
echo "<li>Vai su <a href='https://developer.spotify.com/dashboard' target='_blank'>Spotify Developer Dashboard</a></li>";
echo "<li>Seleziona la tua app</li>";
echo "<li>Clicca 'Edit Settings'</li>";
echo "<li>Aggiungi <code>" . SPOTIFY_REDIRECT_URI . "</code> alla lista Redirect URIs</li>";
echo "<li>Salva le modifiche</li>";
echo "</ol>";

// Test 5: Genera URL di test
echo "<h2>5. URL di Autorizzazione per Test Manuale</h2>";
$test_state = 'manual_test_' . bin2hex(random_bytes(8));
$auth_url = generateSpotifyAuthUrl($test_state);
echo "<p><a href='$auth_url' target='_blank' style='background: #1db954; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>🚀 Testa OAuth Manualmente</a></p>";
echo "<p><small>Questo ti porterà su Spotify per autorizzare l'app. Usa questo per testare il flusso completo.</small></p>";

function getHttpCodeMeaning($code) {
    $meanings = [
        200 => "(OK)",
        400 => "(Bad Request)",
        401 => "(Unauthorized)",
        403 => "(Forbidden)",
        404 => "(Not Found)",
        429 => "(Too Many Requests)",
        500 => "(Internal Server Error)",
        502 => "(Bad Gateway)",
        503 => "(Service Unavailable)"
    ];
    
    return $meanings[$code] ?? "(Codice sconosciuto)";
}

echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f8f9fa; }
h1, h2, h3 { color: #1db954; }
pre { font-size: 12px; }
a { color: #1db954; }
</style>";
?>
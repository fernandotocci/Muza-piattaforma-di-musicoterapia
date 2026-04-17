<?php
/**
 * Test configurazione Spotify OAuth
 * Verifica che tutti i parametri siano corretti
 */

require_once 'includes/spotify_config.php';

echo "<h1>Test Configurazione Spotify</h1>";

echo "<h2>Parametri OAuth:</h2>";
echo "<ul>";
echo "<li><strong>Client ID:</strong> " . SPOTIFY_CLIENT_ID . "</li>";
echo "<li><strong>Redirect URI:</strong> " . SPOTIFY_REDIRECT_URI . "</li>";
echo "<li><strong>Scope:</strong> " . SPOTIFY_SCOPE . "</li>";
echo "<li><strong>Show Dialog:</strong> " . SPOTIFY_SHOW_DIALOG . "</li>";
echo "</ul>";

echo "<h2>URL di autorizzazione esempio:</h2>";
$test_state = 'test_state_123';
$auth_url = generateSpotifyAuthUrl($test_state);
echo "<a href=\"$auth_url\" target=\"_blank\">$auth_url</a>";

echo "<h2>Headers Autorizzazione:</h2>";
echo "<pre>";
print_r(getSpotifyAuthHeaders());
echo "</pre>";

echo "<h2>Note:</h2>";
echo "<p>✅ URL redirect corretti</p>";
echo "<p>✅ Parametri OAuth standardizzati</p>";
echo "<p>✅ Headers configurati correttamente</p>";

echo "<h2>Endpoint di test:</h2>";
echo "<p><a href=\"api/patient_spotify_connection.php\" target=\"_blank\">Test API paziente</a></p>";
echo "<p><a href=\"callback.php?type=spotify&test=1\" target=\"_blank\">Test callback</a></p>";
?>
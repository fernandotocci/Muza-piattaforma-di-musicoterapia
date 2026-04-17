<?php
// api/check_music_services.php - Controlla stato servizi musicali (pazienti e terapeuti)
session_start();
require_once '../includes/db.php';
// Imposta il tipo di contenuto JSON
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$user_type = $_SESSION['user']['user_type'];

// Controlla stato token Spotify
$spotify_query = "
    SELECT expires_at, refresh_token 
    FROM user_music_tokens 
    WHERE user_id = $user_id AND service = 'spotify'
";

$result = $conn->query($spotify_query);
$response = [
    'spotify_connected' => false,
    'spotify_expired' => false,
    'youtube_connected' => false,
    'apple_connected' => false,
    'user_type' => $user_type
];

if ($result && $result->num_rows > 0) {
    $spotify_data = $result->fetch_assoc();
    $response['spotify_connected'] = true;
    
    // Controlla se è scaduto
    if ($spotify_data['expires_at'] && strtotime($spotify_data['expires_at']) <= time()) {
        $response['spotify_expired'] = true;
    }
}

// Controlla altri servizi
$other_services_query = "
    SELECT service 
    FROM user_music_tokens 
    WHERE user_id = $user_id 
    AND service IN ('youtube', 'apple')
    AND (expires_at IS NULL OR expires_at > NOW())
";

$other_result = $conn->query($other_services_query);
if ($other_result) {
    while ($service = $other_result->fetch_assoc()) {
        $response[$service['service'] . '_connected'] = true;
    }
}

echo json_encode($response);
?>
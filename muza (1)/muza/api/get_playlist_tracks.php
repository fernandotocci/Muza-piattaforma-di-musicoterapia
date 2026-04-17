<?php
// api/get_playlist_tracks.php - API per recuperare tracce di una playlist (paziente)
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    echo json_encode(['error' => 'Non autorizzato - solo pazienti']);
    exit;
}

$patient_id = $_SESSION['user']['id'];
$playlist_id = intval($_GET['playlist_id'] ?? 0);

if (!$playlist_id) {
    echo json_encode(['error' => 'ID playlist mancante']);
    exit;
}

// Verifica che la playlist sia assegnata al paziente
$check_query = "
    SELECT p.id, p.name, p.description, CONCAT(u.first_name, ' ', u.last_name) as therapist_name
    FROM therapist_playlists p 
    JOIN users u ON p.therapist_id = u.id
    WHERE p.id = $playlist_id AND p.patient_id = $patient_id
";
$check_result = $conn->query($check_query);

if (!$check_result || $check_result->num_rows === 0) {
    echo json_encode(['error' => 'Playlist non trovata o non assegnata']);
    exit;
}

$playlist_info = $check_result->fetch_assoc();

// Recupera le tracce della playlist
$tracks_query = "
    SELECT t.*, pt.position 
    FROM tracks t 
    JOIN playlist_tracks pt ON t.id = pt.track_id 
    WHERE pt.playlist_id = $playlist_id 
    ORDER BY pt.position ASC
";
$tracks_result = $conn->query($tracks_query);

$tracks = [];
while ($track = $tracks_result->fetch_assoc()) {
    $tracks[] = [
        'id' => $track['id'],
        'title' => $track['title'],
        'artist' => $track['artist'],
        'album' => $track['album'],
        'duration' => $track['duration'],
        'duration_formatted' => gmdate("i:s", $track['duration']),
        'preview_url' => $track['preview_url'],
        'image_url' => $track['image_url'],
        'spotify_url' => $track['spotify_url'],
        'explicit' => $track['explicit'],
        'position' => $track['position']
    ];
}

echo json_encode([
    'success' => true,
    'playlist' => $playlist_info,
    'tracks' => $tracks,
    'total_tracks' => count($tracks)
]);
?>

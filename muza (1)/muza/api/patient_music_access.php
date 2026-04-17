<?php
/**
 * API per ottenere informazioni sui brani per la riproduzione Spotify del paziente
 */

session_start();
require_once '../includes/db.php';
require_once 'refresh_spotify_token.php';

header('Content-Type: application/json');

// Abilita la visualizzazione degli errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verifica autenticazione paziente
if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autorizzato - solo pazienti']);
    exit;
}

$patient_id = $_SESSION['user']['id'];
$track_id = isset($_GET['track_id']) ? intval($_GET['track_id']) : null;

try {
    // Se viene richiesto un brano specifico
    if ($track_id) {
        // Verifica che il paziente possa accedere al brano
        $track_query = "
            SELECT t.*, 
                   CASE 
                       WHEN pt.track_id IS NOT NULL THEN 'playlist'
                       ELSE 'general'
                   END as access_type
            FROM tracks t
            LEFT JOIN playlist_tracks pt ON t.id = pt.track_id
            LEFT JOIN therapist_playlists tp ON pt.playlist_id = tp.id
            WHERE t.id = ? 
            AND (tp.patient_id = ? OR tp.patient_id IS NULL OR pt.track_id IS NULL)
            LIMIT 1
        ";
        
        $stmt = $conn->prepare($track_query);
        if (!$stmt) {
            throw new Exception("Errore nella preparazione della query: " . $conn->error);
        }
        
        $stmt->bind_param("ii", $track_id, $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Brano non trovato o non accessibile']);
            exit;
        }
        
        $track = $result->fetch_assoc();
        $stmt->close();
        
        // Verifica se il paziente ha Spotify collegato (ottieni access token valido)
        $access_token = getValidSpotifyToken($patient_id);

        if (!$access_token) {
            echo json_encode([
                'success' => false,
                'error' => 'Spotify non collegato o token non valido',
                'needs_spotify_connection' => true,
                'track_info' => [
                    'id' => $track['id'],
                    'title' => $track['title'],
                    'artist' => $track['artist'],
                    'album' => $track['album'] ?? '',
                    'preview_url' => $track['preview_url'] ?? '',
                    'image_url' => $track['image_url'] ?? ''
                ]
            ]);
            exit;
        }
        
        // Prepara informazioni per la riproduzione
        $response_data = [
            'success' => true,
            'track' => [
                'id' => (int)$track['id'],
                'title' => $track['title'],
                'artist' => $track['artist'],
                'album' => $track['album'] ?? '',
                'duration' => (int)($track['duration'] ?? 0),
                'spotify_id' => $track['spotify_track_id'] ?? null,
                'spotify_url' => $track['spotify_url'] ?? '',
                'preview_url' => $track['preview_url'] ?? '',
                'image_url' => $track['image_url'] ?? '',
                'explicit' => (bool)($track['explicit'] ?? false),
                'access_type' => $track['access_type']
            ],
            'spotify_connected' => true,
            'can_play_full_track' => !empty($track['spotify_track_id']),
            'play_instructions' => [
                'spotify_web_player' => "https://open.spotify.com/track/" . ($track['spotify_track_id'] ?? ''),
                'preview_available' => !empty($track['preview_url'])
            ]
        ];
        
        echo json_encode($response_data);
        
    } else {
        // Restituisce lista brani accessibili al paziente
        $tracks_query = "
            SELECT DISTINCT t.id, t.title, t.artist, t.album, t.duration, 
                   t.spotify_track_id, t.spotify_url, t.preview_url, 
                   t.image_url, t.explicit, t.created_at,
                   CASE 
                       WHEN pt.track_id IS NOT NULL THEN 'playlist'
                       ELSE 'general'
                   END as access_type,
                   tp.name as playlist_name
            FROM tracks t
            LEFT JOIN playlist_tracks pt ON t.id = pt.track_id
            LEFT JOIN therapist_playlists tp ON pt.playlist_id = tp.id
            WHERE tp.patient_id = ? OR tp.patient_id IS NULL OR pt.track_id IS NULL
            ORDER BY t.created_at DESC
        ";
        
        $stmt = $conn->prepare($tracks_query);
        if (!$stmt) {
            throw new Exception("Errore nella preparazione della query: " . $conn->error);
        }
        
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tracks = [];
        while ($row = $result->fetch_assoc()) {
            $tracks[] = [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'artist' => $row['artist'],
                'album' => $row['album'] ?? '',
                'duration' => (int)($row['duration'] ?? 0),
                'spotify_id' => $row['spotify_track_id'] ?? null,
                'spotify_url' => $row['spotify_url'] ?? '',
                'preview_url' => $row['preview_url'] ?? '',
                'image_url' => $row['image_url'] ?? '',
                'explicit' => (bool)($row['explicit'] ?? false),
                'access_type' => $row['access_type'],
                'playlist_name' => $row['playlist_name'],
                'created_at' => $row['created_at']
            ];
        }
        
        $stmt->close();
        
        // Verifica stato connessione Spotify (ottieni access token valido)
        $access_token = getValidSpotifyToken($patient_id);
        $spotify_connected = (bool)$access_token;

        echo json_encode([
            'success' => true,
            'tracks' => $tracks,
            'count' => count($tracks),
            'spotify_connected' => $spotify_connected,
            'message' => $spotify_connected 
                ? 'Brani disponibili per la riproduzione'
                : 'Collega Spotify per la riproduzione completa'
        ]);
    }
    
} catch (Exception $e) {
    error_log("PATIENT_MUSIC_ACCESS: Errore per patient_id=$patient_id - " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore interno del server',
        'debug_message' => $e->getMessage()
    ]);
}
?>

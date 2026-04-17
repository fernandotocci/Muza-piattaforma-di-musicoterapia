<?php
// api/manage_playlists.php - Gestione playlist terapeutiche
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'therapist') {
    echo json_encode(['error' => 'Non autorizzato - solo terapeuti']);
    exit;
}

$therapist_id = $_SESSION['user']['id'];
$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        handleGetRequest();
        break;
    case 'POST':
        handlePostRequest();
        break;
    case 'PUT':
        handlePutRequest();
        break;
    case 'DELETE':
        handleDeleteRequest();
        break;
    default:
        echo json_encode(['error' => 'Metodo non supportato']);
        break;
}

function handleGetRequest() {
    global $conn, $therapist_id;
    
    $action = $_GET['action'] ?? 'list';
    
    switch($action) {
        case 'list':
            // Lista tutte le playlist del terapeuta
            $query = "
                SELECT p.*, 
                       COUNT(pt.track_id) as track_count,
                       CONCAT(u.first_name, ' ', u.last_name) as patient_name
                FROM therapist_playlists p 
                LEFT JOIN playlist_tracks pt ON p.id = pt.playlist_id
                LEFT JOIN users u ON p.patient_id = u.id
                WHERE p.therapist_id = $therapist_id 
                GROUP BY p.id
                ORDER BY p.created_at DESC
            ";
            $result = $conn->query($query);
            
            $playlists = [];
            while ($row = $result->fetch_assoc()) {
                $playlists[] = $row;
            }
            
            echo json_encode(['success' => true, 'playlists' => $playlists]);
            break;
            
        case 'tracks':
            // Ottieni tracce di una playlist specifica
            $playlist_id = intval($_GET['playlist_id'] ?? 0);
            if (!$playlist_id) {
                echo json_encode(['error' => 'ID playlist mancante']);
                return;
            }
            
            // Verifica proprietà
            $check_query = "SELECT id FROM therapist_playlists WHERE id = $playlist_id AND therapist_id = $therapist_id";
            if (!$conn->query($check_query)->num_rows) {
                echo json_encode(['error' => 'Playlist non trovata']);
                return;
            }
            
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
                $tracks[] = $track;
            }
            
            echo json_encode(['success' => true, 'tracks' => $tracks]);
            break;
            
        case 'patients':
            // Lista pazienti per assegnazione playlist
            $patients_query = "
                SELECT id, CONCAT(first_name, ' ', last_name) as name 
                FROM users 
                WHERE user_type = 'patient' 
                ORDER BY first_name, last_name
            ";
            $patients_result = $conn->query($patients_query);
            
            $patients = [];
            while ($patient = $patients_result->fetch_assoc()) {
                $patients[] = $patient;
            }
            
            echo json_encode(['success' => true, 'patients' => $patients]);
            break;
    }
}

function handlePostRequest() {
    global $conn, $therapist_id;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch($action) {        
        case 'create':
            // Crea nuova playlist
            $name = $conn->real_escape_string($input['name']);
            $description = $conn->real_escape_string($input['description'] ?? '');
            $patient_id = intval($input['patient_id'] ?? 0);
            
            if (empty($name)) {
                echo json_encode(['error' => 'Nome playlist richiesto']);
                return;
            }
            
            $create_query = "
                INSERT INTO therapist_playlists (name, description, patient_id, therapist_id, created_at) 
                VALUES ('$name', '$description', " . ($patient_id ?: 'NULL') . ", $therapist_id, NOW())
            ";
            
            if ($conn->query($create_query)) {
                $playlist_id = $conn->insert_id;
                echo json_encode([
                    'success' => true, 
                    'playlist_id' => $playlist_id,
                    'message' => 'Playlist creata con successo'
                ]);
            } else {
                echo json_encode(['error' => 'Errore nella creazione: ' . $conn->error]);
            }
            break;        

        case 'add_spotify_track_to_playlist':
            // Aggiungi brano da Spotify direttamente a playlist (con auto-creazione se necessario)
            $playlist_id = intval($input['playlist_id']);
            $spotify_data = $input['spotify_data'];
            
            // Validazione dati Spotify richiesti
            $required_fields = ['spotify_id', 'title', 'artist'];
            foreach ($required_fields as $field) {
                if (!isset($spotify_data[$field]) || empty($spotify_data[$field])) {
                    echo json_encode(['error' => "Campo Spotify mancante: $field"]);
                    return;
                }
            }
            
            // Verifica proprietà playlist
            $check_query = "SELECT id FROM therapist_playlists WHERE id = $playlist_id AND therapist_id = $therapist_id";
            if (!$conn->query($check_query)->num_rows) {
                echo json_encode(['error' => 'Playlist non trovata o non autorizzata']);
                return;
            }
            
            // Verifica se il brano esiste già nella libreria
            $track_check_query = "SELECT id FROM tracks WHERE spotify_track_id = ?";
            $track_check_stmt = $conn->prepare($track_check_query);
            $track_check_stmt->bind_param('s', $spotify_data['spotify_id']);
            $track_check_stmt->execute();
            $existing_track = $track_check_stmt->get_result()->fetch_assoc();
            
            if ($existing_track) {
                $track_id = $existing_track['id'];
            } else {
                // Aggiungi automaticamente il brano alla libreria
                $insert_track_query = "
                    INSERT INTO tracks (
                        title, artist, album, duration, spotify_track_id, spotify_url, 
                        preview_url, image_url, popularity, explicit, therapist_id, 
                        source, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'spotify', NOW())
                ";
                
                $stmt = $conn->prepare($insert_track_query);
                
                // CORREZIONE: Preparazione corretta dei parametri
                $duration = intval(($spotify_data['duration_ms'] ?? 0) / 1000);
                $album = $spotify_data['album'] ?? '';
                $spotify_url = $spotify_data['external_url'] ?? '';
                $preview_url = $spotify_data['preview_url'] ?? '';
                $image_url = $spotify_data['image'] ?? '';
                $popularity = intval($spotify_data['popularity'] ?? 0);
                $explicit_int = $spotify_data['explicit'] ? 1 : 0;
                
                // CORREZIONE: Bind corretto (11 parametri)
                $stmt->bind_param(
                    'sssissssiii',  // 11 caratteri per 11 parametri
                    $spotify_data['title'],      // s
                    $spotify_data['artist'],     // s
                    $album,                      // s
                    $duration,                   // i
                    $spotify_data['spotify_id'], // s
                    $spotify_url,                // s
                    $preview_url,                // s
                    $image_url,                  // s
                    $popularity,                 // i
                    $explicit_int,               // i
                    $therapist_id                // i
                );
                  
                if (!$stmt->execute()) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Errore nell\'aggiunta del brano alla libreria: ' . $conn->error,
                        'debug' => [
                            'stmt_error' => $stmt->error,
                            'mysql_error' => mysqli_error($conn),
                            'spotify_data' => $spotify_data
                        ]
                    ]);
                    return;
                }
                
                $track_id = $conn->insert_id;
            }
            
            // Verifica se track già presente nella playlist (solo playlist specifica)
            $playlist_check = "SELECT id FROM playlist_tracks WHERE playlist_id = ? AND track_id = ?";
            $stmt_check = $conn->prepare($playlist_check);
            $stmt_check->bind_param('ii', $playlist_id, $track_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check->num_rows > 0) {
                echo json_encode([
                    'error' => 'Brano già presente nella playlist',
                    'debug' => [
                        'playlist_id' => $playlist_id,
                        'track_id' => $track_id,
                        'track_in_playlist' => true
                    ]
                ]);
                return;
            }
            
            // Ottieni prossima posizione
            $position_query = "SELECT COALESCE(MAX(position), 0) + 1 as next_position FROM playlist_tracks WHERE playlist_id = $playlist_id";
            $position = $conn->query($position_query)->fetch_assoc()['next_position'];
              
            // Aggiungi alla playlist
            $add_to_playlist_query = "INSERT INTO playlist_tracks (playlist_id, track_id, position, created_at) VALUES ($playlist_id, $track_id, $position, NOW())";
            
            if ($conn->query($add_to_playlist_query)) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Brano aggiunto alla playlist',
                    'track_id' => $track_id
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Errore nell\'aggiunta alla playlist: ' . $conn->error,
                    'debug' => [
                        'playlist_id' => $playlist_id,
                        'track_id' => $track_id,
                        'position' => $position,
                        'query' => $add_to_playlist_query,
                        'mysql_error' => mysqli_error($conn),
                        'mysql_errno' => mysqli_errno($conn)
                    ]
                ]);
            }
            break;
            
        case 'add_track':
            // Aggiungi track a playlist
            $playlist_id = intval($input['playlist_id']);
            $track_id = intval($input['track_id']);
            
            // Verifica proprietà playlist
            $check_query = "SELECT id FROM therapist_playlists WHERE id = $playlist_id AND therapist_id = $therapist_id";
            if (!$conn->query($check_query)->num_rows) {
                echo json_encode(['error' => 'Playlist non trovata']);
                return;
            }
            
            // Verifica se track già presente
            $exists_query = "SELECT id FROM playlist_tracks WHERE playlist_id = $playlist_id AND track_id = $track_id";
            if ($conn->query($exists_query)->num_rows > 0) {
                echo json_encode(['error' => 'Brano già presente nella playlist']);
                return;
            }
            
            // Ottieni prossima posizione
            $position_query = "SELECT COALESCE(MAX(position), 0) + 1 as next_position FROM playlist_tracks WHERE playlist_id = $playlist_id";
            $position = $conn->query($position_query)->fetch_assoc()['next_position'];
            
            $add_query = "INSERT INTO playlist_tracks (playlist_id, track_id, position, created_at) VALUES ($playlist_id, $track_id, $position, NOW())";
            
            if ($conn->query($add_query)) {
                echo json_encode(['success' => true, 'message' => 'Brano aggiunto alla playlist']);
            } else {
                echo json_encode(['error' => 'Errore nell\'aggiunta: ' . $conn->error]);
            }
            break;
    }
}

function handlePutRequest() {
    global $conn, $therapist_id;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch($action) {
        case 'update':
            // Aggiorna playlist
            $playlist_id = intval($input['playlist_id']);
            $name = $conn->real_escape_string($input['name']);
            $description = $conn->real_escape_string($input['description'] ?? '');
            $patient_id = intval($input['patient_id'] ?? 0);
            
            // Verifica proprietà
            $check_query = "SELECT id FROM therapist_playlists WHERE id = $playlist_id AND therapist_id = $therapist_id";
            if (!$conn->query($check_query)->num_rows) {
                echo json_encode(['error' => 'Playlist non trovata']);
                return;
            }
            
            $update_query = "
                UPDATE therapist_playlists 
                SET name = '$name', description = '$description', patient_id = " . ($patient_id ?: 'NULL') . "
                WHERE id = $playlist_id AND therapist_id = $therapist_id
            ";
            
            if ($conn->query($update_query)) {
                echo json_encode(['success' => true, 'message' => 'Playlist aggiornata']);
            } else {
                echo json_encode(['error' => 'Errore nell\'aggiornamento: ' . $conn->error]);
            }
            break;
        case 'update_track':
            // Aggiorna metadati del brano o posizione all'interno di una playlist
            $playlist_id = intval($input['playlist_id']);
            $track_id = intval($input['track_id']);
            $title = isset($input['title']) ? $conn->real_escape_string($input['title']) : null;
            $artist = isset($input['artist']) ? $conn->real_escape_string($input['artist']) : null;
            $new_position = isset($input['position']) ? intval($input['position']) : null;

            // Verifica proprietà playlist
            $check_query = "SELECT id FROM therapist_playlists WHERE id = $playlist_id AND therapist_id = $therapist_id";
            if (!$conn->query($check_query)->num_rows) {
                echo json_encode(['error' => 'Playlist non trovata']);
                return;
            }

            // Aggiorna metadati brano nella tabella tracks solo se il terapeuta è proprietario
            if ($title !== null || $artist !== null) {
                $update_fields = [];
                if ($title !== null) $update_fields[] = "title = '$title'";
                if ($artist !== null) $update_fields[] = "artist = '$artist'";

                // Aggiorna solo se therapist_id corrisponde o senza controllo (preferiamo controllo)
                $update_query = "UPDATE tracks SET " . implode(', ', $update_fields) . " WHERE id = $track_id AND therapist_id = $therapist_id";
                $conn->query($update_query);
            }

            // Aggiorna posizione nella playlist_tracks
            if ($new_position !== null) {
                // Verifica che il track esista nella playlist
                $exists_q = "SELECT id FROM playlist_tracks WHERE playlist_id = $playlist_id AND track_id = $track_id";
                if (!$conn->query($exists_q)->num_rows) {
                    echo json_encode(['error' => 'Brano non presente nella playlist']);
                    return;
                }

                // Imposta la nuova posizione provvisoria
                $conn->query("UPDATE playlist_tracks SET position = $new_position WHERE playlist_id = $playlist_id AND track_id = $track_id");

                // Ricalcola tutte le posizioni in modo sequenziale
                $res = $conn->query("SELECT id FROM playlist_tracks WHERE playlist_id = $playlist_id ORDER BY position ASC, created_at ASC");
                $pos = 1;
                while ($r = $res->fetch_assoc()) {
                    $pid = intval($r['id']);
                    $conn->query("UPDATE playlist_tracks SET position = $pos WHERE id = $pid");
                    $pos++;
                }
            }

            echo json_encode(['success' => true, 'message' => 'Brano aggiornato']);
            break;
    }
}

function handleDeleteRequest() {
    global $conn, $therapist_id;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch($action) {
        case 'playlist':
            // Elimina playlist
            $playlist_id = intval($input['playlist_id']);
            
            // Verifica proprietà
            $check_query = "SELECT id FROM therapist_playlists WHERE id = $playlist_id AND therapist_id = $therapist_id";
            if (!$conn->query($check_query)->num_rows) {
                echo json_encode(['error' => 'Playlist non trovata']);
                return;
            }
            
            // Elimina prima le associazioni tracce
            $conn->query("DELETE FROM playlist_tracks WHERE playlist_id = $playlist_id");
            
            // Poi elimina la playlist
            $delete_query = "DELETE FROM therapist_playlists WHERE id = $playlist_id AND therapist_id = $therapist_id";
            
            if ($conn->query($delete_query)) {
                echo json_encode(['success' => true, 'message' => 'Playlist eliminata']);
            } else {
                echo json_encode(['error' => 'Errore nell\'eliminazione: ' . $conn->error]);
            }
            break;
            
        case 'track':
            // Rimuovi track da playlist
            $playlist_id = intval($input['playlist_id']);
            $track_id = intval($input['track_id']);
            
            // Verifica proprietà playlist
            $check_query = "SELECT id FROM therapist_playlists WHERE id = $playlist_id AND therapist_id = $therapist_id";
            if (!$conn->query($check_query)->num_rows) {
                echo json_encode(['error' => 'Playlist non trovata']);
                return;
            }
            
            $remove_query = "DELETE FROM playlist_tracks WHERE playlist_id = $playlist_id AND track_id = $track_id";
            
            if ($conn->query($remove_query)) {
                echo json_encode(['success' => true, 'message' => 'Brano rimosso dalla playlist']);
            } else {
                echo json_encode(['error' => 'Errore nella rimozione: ' . $conn->error]);
            }
            break;
    }
}
?>
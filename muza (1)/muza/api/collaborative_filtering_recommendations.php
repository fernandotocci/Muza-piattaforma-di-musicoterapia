<?php
/**
 * Sistema di Raccomandazione Collaborativa (User-User) per Mūza
 *
 * Questo sistema identifica i pazienti con gusti musicali simili in base
 * alla loro cronologia di ascolto e alle valutazioni (in particolare, i brani valutati positivamente).
 * Successivamente, raccomanda i brani apprezzati da questi "utenti simili"
 * che il paziente attuale non ha ancora ascoltato o valutato.
 *
 * Endpoint: GET /api/collaborative_filtering_recommendations.php
 * Autenticazione: Richiesta (paziente)
 *
 * Funzionamento dettagliato:
 * 1. Recupera i brani valutati positivamente (4-5 stelle) dal paziente attuale.
 * 2. Recupera i brani valutati positivamente da tutti gli altri pazienti.
 * 3. Calcola un "punteggio di similarità" (numero di brani apprezzati in comune)
 *    tra il paziente attuale e tutti gli altri pazienti.
 * 4. Identifica i primi N pazienti più simili.
 * 5. Raccoglie i brani valutati positivamente da questi utenti simili che il
 *    paziente attuale non ha ancora ascoltato o valutato.
 * 6. Ordina queste raccomandazioni e le restituisce come risposta JSON.
 */

session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Imposta l'header per la risposta JSON
header('Content-Type: application/json');

/**
 * Restituisce un errore in formato JSON e termina l'esecuzione.
 * @param string $message Messaggio di errore
 * @param mixed $debug_info Informazioni di debug opzionali
 */
function returnError($message, $debug_info = null) {
    echo json_encode([
        'success' => false,
        'error' => $message,
        'debug_info' => $debug_info
    ]);
    exit;
}

// Verifica autenticazione del paziente
try {
    $patient = checkAuth('patient');
    $current_patient_id = $patient['id'];
} catch (Exception $e) {
    returnError('Autenticazione fallita: ' . $e->getMessage());
}

$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

try {

    // 1. Recupera i brani valutati positivamente dal paziente attuale
    $current_patient_liked_tracks = getPatientLikedTracks($current_patient_id, $conn);
    if (empty($current_patient_liked_tracks)) {
        returnError('Nessun brano valutato positivamente trovato per questo paziente. Valuta almeno 3 brani con 4-5 stelle per ricevere raccomandazioni.', $debug_mode ? [
            'current_patient_liked_tracks' => $current_patient_liked_tracks
        ] : null);
    }
    $current_patient_liked_track_ids = array_column($current_patient_liked_tracks, 'track_id');

    // 2. Recupera i brani valutati positivamente da tutti gli altri pazienti
    $all_other_patients_ratings = getAllOtherPatientsLikedTracks($current_patient_id, $conn);
    if (empty($all_other_patients_ratings)) {
        returnError('Non ci sono abbastanza dati da altri utenti per il filtraggio collaborativo.', $debug_mode ? [
            'all_other_patients_ratings' => $all_other_patients_ratings
        ] : null);
    }

    // 3. Calcola la similarità e trova gli utenti simili
    $similar_users = findSimilarUsers($current_patient_liked_track_ids, $all_other_patients_ratings);
    if (empty($similar_users)) {
        returnError('Non sono stati trovati utenti simili con gusti sovrapposti.', $debug_mode ? [
            'current_patient_liked_tracks' => $current_patient_liked_tracks,
            'all_other_patients_ratings' => $all_other_patients_ratings,
            'similar_users' => $similar_users
        ] : null);
    }
    // Ordina gli utenti simili per punteggio di similarità (decrescente)
    usort($similar_users, function($a, $b) {
        return $b['similarity_score'] <=> $a['similarity_score'];
    });
    // Prendi i primi 5 utenti simili (o meno se non ce ne sono abbastanza)
    $top_similar_users = array_slice($similar_users, 0, 5);

    // 4. Genera raccomandazioni dai top utenti simili
    $raw_recommendations = generateRecommendationsFromSimilarUsers($current_patient_id, $top_similar_users, $all_other_patients_ratings, $conn);

    // 5. Filtra i brani già ascoltati/valutati dal paziente attuale
    $filtered_recommendations = filterExistingTracks($raw_recommendations, $current_patient_id, $conn);

    if (empty($filtered_recommendations)) {
        returnError('Nessuna nuova raccomandazione trovata in base agli utenti simili. Prova a valutare più brani!', $debug_mode ? [
            'current_patient_liked_tracks' => $current_patient_liked_tracks,
            'all_other_patients_ratings' => $all_other_patients_ratings,
            'similar_users' => $similar_users,
            'top_similar_users' => $top_similar_users,
            'raw_recommendations' => $raw_recommendations,
            'filtered_recommendations' => $filtered_recommendations
        ] : null);
    }

    // Ordina le raccomandazioni per punteggio (es. media delle valutazioni degli utenti simili)
    usort($filtered_recommendations, function($a, $b) {
        return $b['recommendation_score'] <=> $a['recommendation_score'];
    });
    // Limita alle prime 10 raccomandazioni
    $final_recommendations = array_slice($filtered_recommendations, 0, 10);

    // === RISPOSTA ===
    $response = [
        'success' => true,
        'recommendations' => $final_recommendations,
        'total_found' => count($final_recommendations),
        'debug_info' => $debug_mode ? [
            'current_patient_liked_tracks' => $current_patient_liked_tracks,
            'all_other_patients_ratings' => $all_other_patients_ratings,
            'similar_users' => $similar_users,
            'top_similar_users' => $top_similar_users,
            'raw_recommendations' => $raw_recommendations,
            'filtered_recommendations' => $filtered_recommendations
        ] : null,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Errore nel sistema di raccomandazione collaborativa User-User: " . $e->getMessage());
    returnError('Errore nel sistema di raccomandazione: ' . $e->getMessage(), $debug_mode ? [
        'error_message' => $e->getMessage(),
        'error_line' => $e->getLine()
    ] : null);
}

// =====================================================
// FUNZIONI DI SUPPORTO
// =====================================================

/**
 * Recupera i brani valutati positivamente (rating >= 4) da un paziente.
 * @param int $user_id ID del paziente
 * @param mysqli $conn Connessione al database
 * @return array Elenco dei brani valutati positivamente
 */
function getPatientLikedTracks($user_id, $conn) {
    $liked_songs = [];
    $query = "
        SELECT tr.track_id, tr.rating, t.spotify_track_id
        FROM track_ratings tr
        JOIN tracks t ON tr.track_id = t.id
        WHERE tr.user_id = ? AND tr.rating >= 4
        ORDER BY tr.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Errore nella preparazione della query per i brani valutati positivamente: " . $conn->error);
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $liked_songs[] = $row;
    }
    $stmt->close();
    return $liked_songs;
}

/**
 * Recupera i brani valutati positivamente (rating >= 4) da tutti gli altri pazienti.
 * @param int $current_patient_id ID del paziente attuale
 * @param mysqli $conn Connessione al database
 * @return array Elenco dei brani valutati positivamente dagli altri pazienti, raggruppati per utente
 */
function getAllOtherPatientsLikedTracks($current_patient_id, $conn) {
    $other_patients_ratings = [];
    $query = "
        SELECT u.id as user_id, tr.track_id, tr.rating, t.title, t.artist, t.album, t.image_url, t.preview_url, t.spotify_track_id
        FROM users u
        JOIN track_ratings tr ON u.id = tr.user_id
        JOIN tracks t ON tr.track_id = t.id
        WHERE u.user_type = 'patient' AND u.id != ? AND tr.rating >= 4
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Errore nella preparazione della query per i brani degli altri pazienti: " . $conn->error);
    }
    $stmt->bind_param('i', $current_patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $other_patients_ratings[$row['user_id']][] = $row;
    }
    $stmt->close();
    return $other_patients_ratings;
}

/**
 * Trova gli utenti simili in base ai brani valutati positivamente in comune.
 * Il punteggio di similarità è dato dal numero di brani apprezzati in comune.
 * @param array $current_patient_liked_track_ids ID dei brani valutati positivamente dal paziente attuale
 * @param array $all_other_patients_ratings Brani valutati positivamente dagli altri pazienti
 * @return array Elenco degli utenti simili con relativo punteggio di similarità
 */
function findSimilarUsers($current_patient_liked_track_ids, $all_other_patients_ratings) {
    $similar_users = [];
    foreach ($all_other_patients_ratings as $other_user_id => $other_user_liked_tracks) {
        $other_user_liked_track_ids = array_column($other_user_liked_tracks, 'track_id');
        
        // Trova i brani valutati positivamente in comune
        $common_tracks = array_intersect($current_patient_liked_track_ids, $other_user_liked_track_ids);
        $similarity_score = count($common_tracks);

        // Considera solo utenti con almeno 1 brano in comune
        if ($similarity_score > 0) {
            $similar_users[] = [
                'user_id' => $other_user_id,
                'similarity_score' => $similarity_score,
                'common_tracks_count' => $similarity_score
            ];
        }
    }
    return $similar_users;
}

/**
 * Genera raccomandazioni a partire dagli utenti più simili.
 * @param int $current_patient_id ID del paziente attuale
 * @param array $top_similar_users Elenco dei top utenti simili
 * @param array $all_other_patients_ratings Brani valutati positivamente dagli altri pazienti
 * @param mysqli $conn Connessione al database
 * @return array Raccomandazioni aggregate dai top utenti simili
 */
function generateRecommendationsFromSimilarUsers($current_patient_id, $top_similar_users, $all_other_patients_ratings, $conn) {
    $recommendations = [];
    $current_patient_rated_track_ids = [];

    // Recupera tutti i brani già ascoltati/valutati dal paziente attuale per escluderli
    $query_existing = "SELECT track_id FROM track_ratings WHERE user_id = ?";
    $stmt_existing = $conn->prepare($query_existing);
    if ($stmt_existing) {
        $stmt_existing->bind_param('i', $current_patient_id);
        $stmt_existing->execute();
        $result_existing = $stmt_existing->get_result();
        while($row = $result_existing->fetch_assoc()) {
            $current_patient_rated_track_ids[] = $row['track_id'];
        }
        $stmt_existing->close();
    }
    
    foreach ($top_similar_users as $similar_user) {
        $user_id = $similar_user['user_id'];
        if (isset($all_other_patients_ratings[$user_id])) {
            foreach ($all_other_patients_ratings[$user_id] as $track) {
                // Assicurati che il paziente attuale non abbia già valutato questo brano e che sia valutato positivamente dall'utente simile
                if (!in_array($track['track_id'], $current_patient_rated_track_ids) && $track['rating'] >= 4) {
                    // Aggrega le raccomandazioni e calcola la media delle valutazioni degli utenti simili
                    if (!isset($recommendations[$track['track_id']])) {
                        $recommendations[$track['track_id']] = [
                            'id' => $track['track_id'],
                            'title' => $track['title'],
                            'artist' => $track['artist'],
                            'album' => $track['album'],
                            'image_url' => $track['image_url'],
                            'preview_url' => $track['preview_url'],
                            'spotify_track_id' => $track['spotify_track_id'],
                            'source' => 'db_user_user', // Indica la fonte della raccomandazione
                            'reason' => 'brano consigliato da altri pazienti con gusti simili ai tuoi',
                            'similar_users_count' => 0,
                            'total_rating_sum' => 0,
                            'recommendation_score' => 0 // Verrà calcolato successivamente
                        ];
                    }
                    $recommendations[$track['track_id']]['similar_users_count']++;
                    $recommendations[$track['track_id']]['total_rating_sum'] += $track['rating'];
                }
            }
        }
    }

    // Calcola il punteggio finale della raccomandazione (es. media delle valutazioni)
    foreach ($recommendations as $track_id => &$rec) {
        if ($rec['similar_users_count'] > 0) {
            $rec['recommendation_score'] = $rec['total_rating_sum'] / $rec['similar_users_count']; // Media semplice delle valutazioni
            // Eventuale ponderazione aggiuntiva può essere inserita qui
        }
    }
    unset($rec); // Rimuove il riferimento

    return array_values($recommendations); // Restituisce come array indicizzato
}

/**
 * Filtra i brani che il paziente attuale ha già ascoltato o valutato.
 * @param array $recommendations Raccomandazioni generate
 * @param int $patient_id ID del paziente attuale
 * @param mysqli $conn Connessione al database
 * @return array Raccomandazioni filtrate
 */
function filterExistingTracks($recommendations, $patient_id, $conn) {
    $filtered = [];
    $existing_track_ids = [];

    // Recupera tutti gli ID dei brani già valutati dal paziente
    $query = "SELECT track_id FROM track_ratings WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existing_track_ids[$row['track_id']] = true;
        }
        $stmt->close();
    }

    foreach ($recommendations as $rec) {
        // Se l'ID del brano non è tra quelli già valutati, aggiungilo alle raccomandazioni filtrate
        if (!isset($existing_track_ids[$rec['id']])) {
            $filtered[] = $rec;
        }
    }
    return $filtered;
}
<?php
/**
 * Sistema di Raccomandazione Collaborativa (Item-Item) per Mūza
 *
 * Questo sistema suggerisce brani che sono spesso apprezzati dagli utenti
 * che hanno valutato positivamente specifici brani che il paziente attuale ha gradito.
 * Si concentra sulla similarità tra i brani (item) piuttosto che tra gli utenti.
 *
 * Endpoint: GET /api/item_item_collaborative_filtering.php
 * Autenticazione: Richiesta (paziente)
 *
 * Funzionamento:
 * 1. Recupera i brani valutati positivamente (4-5 stelle) dal paziente attuale.
 * 2. Costruisce una matrice di co-occorrenza/similarità tra brani in base a
 *    quante volte sono stati valutati positivamente insieme da qualsiasi utente.
 * 3. Per ciascun brano gradito dal paziente, trova i brani più simili.
 * 4. Esclude i brani già ascoltati/valutati dal paziente.
 * 5. Aggrega e ordina le raccomandazioni, restituendole in formato JSON.
 */

session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Imposta l'header per la risposta JSON
header('Content-Type: application/json');

/**
 * Restituisce un errore in formato JSON e termina l'esecuzione.
 */
function returnError($message, $debug_info = null) {
    echo json_encode([
        'success' => false,
        'error' => $message,
        'debug_info' => $debug_info
    ]);
    exit;
}

// Verifica autenticazione
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
        returnError('Nessun brano valutato positivamente trovato per questo paziente. Valuta almeno 3 brani con 4-5 stelle per ricevere raccomandazioni.', null);
    }
    $current_patient_liked_track_ids = array_column($current_patient_liked_tracks, 'track_id');

    // 2. Recupera tutte le valutazioni dei brani per costruire la similarità item-item
    $all_ratings = getAllTrackRatings($conn);

    if (empty($all_ratings)) {
        returnError('Non ci sono abbastanza dati di valutazione per costruire la similarità tra brani.', null);
    }

    // Costruisce la matrice di similarità tra brani (co-occorrenza semplice per valutazioni positive)
    $item_similarity = calculateItemSimilarity($all_ratings);

    // 3. Genera raccomandazioni basate sui brani graditi dal paziente e la similarità tra brani
    $raw_recommendations = generateItemItemRecommendations(
        $current_patient_liked_track_ids,
        $item_similarity,
        $conn // Passa la connessione per recuperare i dettagli dei brani
    );

    // 4. Esclude i brani già ascoltati/valutati dal paziente attuale
    $filtered_recommendations = filterExistingTracks($raw_recommendations, $current_patient_id, $conn);

    if (empty($filtered_recommendations)) {
        returnError('Nessuna nuova raccomandazione trovata in base ai brani simili. Prova a valutare altri brani!', null);
    }

    // Ordina le raccomandazioni per punteggio (es. punteggio di similarità o aggregato)
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
            'current_patient_liked_tracks_count' => count($current_patient_liked_tracks),
            'raw_recommendations_count' => count($raw_recommendations),
            'filtered_recommendations_count' => count($filtered_recommendations)
        ] : null,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Errore nel sistema di raccomandazione Item-Item: " . $e->getMessage());
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
 */
function getPatientLikedTracks($user_id, $conn) {
    $liked_songs = [];
    $query = "
        SELECT tr.track_id, tr.rating
        FROM track_ratings tr
        WHERE tr.user_id = ? AND tr.rating >= 4
        ORDER BY tr.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Errore nella preparazione della query per i brani graditi: " . $conn->error);
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
 * Recupera tutte le valutazioni dei brani dal database.
 */
function getAllTrackRatings($conn) {
    $all_ratings = [];
    $query = "SELECT user_id, track_id, rating FROM track_ratings";
    $result = $conn->query($query);
    if (!$result) {
        throw new Exception("Errore nel recupero di tutte le valutazioni dei brani: " . $conn->error);
    }
    while ($row = $result->fetch_assoc()) {
        $all_ratings[] = $row;
    }
    return $all_ratings;
}

/**
 * Calcola la similarità tra brani in base alla co-occorrenza di valutazioni positive.
 * Conta quante volte due brani sono stati valutati positivamente dallo stesso utente.
 */
function calculateItemSimilarity($all_ratings) {
    $user_positive_tracks = []; // user_id => [track_id1, track_id2, ...]
    foreach ($all_ratings as $rating) {
        if ($rating['rating'] >= 4) { // Considera solo valutazioni positive
            $user_positive_tracks[$rating['user_id']][] = $rating['track_id'];
        }
    }

    $item_co_occurrence = []; // track_id1 => [track_id2 => conteggio, ...]

    foreach ($user_positive_tracks as $user_id => $liked_tracks) {
        // Ordina i brani per garantire coerenza nelle coppie
        sort($liked_tracks);
        $num_liked = count($liked_tracks);

        for ($i = 0; $i < $num_liked; $i++) {
            for ($j = $i + 1; $j < $num_liked; $j++) {
                $item1 = $liked_tracks[$i];
                $item2 = $liked_tracks[$j];

                // Conta entrambe le direzioni per facilitare la ricerca
                if (!isset($item_co_occurrence[$item1])) {
                    $item_co_occurrence[$item1] = [];
                }
                if (!isset($item_co_occurrence[$item2])) {
                    $item_co_occurrence[$item2] = [];
                }

                $item_co_occurrence[$item1][$item2] = ($item_co_occurrence[$item1][$item2] ?? 0) + 1;
                $item_co_occurrence[$item2][$item1] = ($item_co_occurrence[$item2][$item1] ?? 0) + 1;
            }
        }
    }
    return $item_co_occurrence;
}

/**
 * Genera raccomandazioni per il paziente attuale in base alla similarità tra brani.
 */
function generateItemItemRecommendations($current_patient_liked_track_ids, $item_similarity, $conn) {
    $recommendations = [];
    $recommended_track_ids = []; // Tiene traccia dei brani già raccomandati

    foreach ($current_patient_liked_track_ids as $liked_track_id) {
        if (isset($item_similarity[$liked_track_id])) {
            foreach ($item_similarity[$liked_track_id] as $similar_track_id => $co_occurrence_count) {
                // Non raccomanda brani già graditi o uguali a quelli già valutati
                if (!in_array($similar_track_id, $current_patient_liked_track_ids) && !isset($recommended_track_ids[$similar_track_id])) {
                    
                    // Recupera i dettagli del brano
                    $track_details = getTrackDetails($similar_track_id, $conn);
                    if ($track_details) {
                        $recommendations[] = [
                            'id' => $similar_track_id,
                            'title' => $track_details['title'],
                            'artist' => $track_details['artist'],
                            'album' => $track_details['album'],
                            'image_url' => $track_details['image_url'],
                            'preview_url' => $track_details['preview_url'],
                            'spotify_track_id' => $track_details['spotify_track_id'],
                            'source' => 'db_item_item', // Indica la fonte
                            'reason' => 'Potrebbe piacerti, dato che hai apprezzato brani simili',
                            'recommendation_score' => $co_occurrence_count // Usa la co-occorrenza come punteggio
                        ];
                        $recommended_track_ids[$similar_track_id] = true; // Segna come già aggiunto
                    }
                }
            }
        }
    }
    return $recommendations;
}

/**
 * Esclude i brani che il paziente ha già ascoltato o valutato.
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

/**
 * Recupera i dettagli completi di un brano dato il suo ID.
 */
function getTrackDetails($track_id, $conn) {
    $query = "
        SELECT id, title, artist, album, image_url, preview_url, spotify_track_id
        FROM tracks
        WHERE id = ?
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Errore nella preparazione della query per i dettagli del brano: " . $conn->error);
        return false;
    }
    $stmt->bind_param('i', $track_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $track = $result->fetch_assoc();
    $stmt->close();
    return $track;
}

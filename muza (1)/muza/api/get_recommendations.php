<?php
/**
 * SISTEMA DI RACCOMANDAZIONI SPOTIFY BASATO SUI GUSTI DEL PAZIENTE
 * 
 * Questo sistema:
 * 1. Analizza le canzoni che il paziente ha valutato positivamente
 * 2. Usa l'API Spotify per trovare canzoni simili
 * 3. Restituisce raccomandazioni personalizzate da Spotify
 */

session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Imposta header per JSON
header('Content-Type: application/json');

// Gestione errori
function returnError($message, $debug_info = null) {
    echo json_encode([
        'success' => false,
        'error' => $message,
        'debug_info' => $debug_info
    ]);
    exit;
}

// Controlla autenticazione
try {
    $patient = checkAuth('patient');
    $patient_id = $patient['id'];
} catch (Exception $e) {
    returnError('Autenticazione fallita: ' . $e->getMessage());
}

$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

// === SISTEMA DI RACCOMANDAZIONI SPOTIFY ===
try {
    // 1. Ottieni token Spotify valido
    $spotify_token = getValidSpotifyToken($conn);
    if (!$spotify_token) {
        returnError('Token Spotify non disponibile. Connetti un account Spotify.');
    }
    
    // 2. Analizza le preferenze del paziente
    $patient_preferences = analyzePatientPreferences($patient_id, $conn);
    if (empty($patient_preferences)) {
        returnError('Nessuna preferenza trovata. Valuta almeno 3 canzoni con 4-5 stelle.');
    }
    
    // 3. Ottieni raccomandazioni da Spotify
    $spotify_recommendations = getSpotifyRecommendationsFromPreferences($patient_preferences, $spotify_token, $debug_mode);
    
    // 4. Filtra e formatta le raccomandazioni
    $formatted_recommendations = formatSpotifyRecommendations($spotify_recommendations, $patient_preferences);
    
    // 5. Escludi brani già ascoltati/valutati
    $filtered_recommendations = filterExistingTracks($formatted_recommendations, $patient_id, $conn);
    
    // === RISPOSTA ===
    $response = [
        'success' => true,
        'recommendations' => $filtered_recommendations,
        'total_found' => count($filtered_recommendations),
        'patient_preferences' => $debug_mode ? $patient_preferences : null,
        'spotify_token_valid' => !empty($spotify_token),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Errore Spotify recommendations: " . $e->getMessage());
    returnError('Errore nel sistema di raccomandazioni Spotify', $debug_mode ? [
        'error_message' => $e->getMessage(),
        'error_line' => $e->getLine()
    ] : null);
}

// =====================================================
// FUNZIONI DEL SISTEMA SPOTIFY
// =====================================================

/**
 * Ottiene un token Spotify valido
 */
function getValidSpotifyToken($conn) {
    try {
        $token_query = "
            SELECT access_token, expires_at, user_id 
            FROM user_music_tokens 
            WHERE service = 'spotify' AND expires_at > NOW()
            ORDER BY expires_at DESC
            LIMIT 1
        ";
        
        $result = $conn->query($token_query);
        if ($result && $result->num_rows > 0) {
            $token_data = $result->fetch_assoc();
            return $token_data['access_token'];
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("Errore token Spotify: " . $e->getMessage());
        return null;
    }
}

/**
 * Analizza le preferenze musicali del paziente
 */
function analyzePatientPreferences($patient_id, $conn) {
    try {
        // Ottieni le canzoni valutate positivamente dal paziente
        $liked_songs_query = "
            SELECT t.*, r.rating, r.created_at as rated_at
            FROM tracks t
            JOIN track_ratings r ON t.id = r.track_id
            WHERE r.user_id = ? AND r.rating >= 4
            ORDER BY r.rating DESC, r.created_at DESC
            LIMIT 20
        ";
        
        $stmt = $conn->prepare($liked_songs_query);
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $liked_songs = [];
        while ($row = $result->fetch_assoc()) {
            $liked_songs[] = $row;
        }
        $stmt->close();
        
        if (empty($liked_songs)) {
            return [];
        }
        
        // Analizza i pattern nelle preferenze
        $preferences = [
            'liked_songs' => $liked_songs,
            'top_artists' => extractTopArtists($liked_songs),
            'top_genres' => extractTopGenres($liked_songs),
            'audio_features' => calculateAverageAudioFeatures($liked_songs),
            'total_liked' => count($liked_songs),
            'avg_rating' => array_sum(array_column($liked_songs, 'rating')) / count($liked_songs)
        ];
        
        return $preferences;
        
    } catch (Exception $e) {
        error_log("Errore analisi preferenze: " . $e->getMessage());
        return [];
    }
}

/**
 * Estrae i top artisti dalle canzoni piaciute
 */
function extractTopArtists($liked_songs) {
    $artist_count = [];
    $artist_ratings = [];
    
    foreach ($liked_songs as $song) {
        $artist = $song['artist'];
        if (!isset($artist_count[$artist])) {
            $artist_count[$artist] = 0;
            $artist_ratings[$artist] = [];
        }
        $artist_count[$artist]++;
        $artist_ratings[$artist][] = $song['rating'];
    }
    
    $top_artists = [];
    foreach ($artist_count as $artist => $count) {
        $avg_rating = array_sum($artist_ratings[$artist]) / count($artist_ratings[$artist]);
        $top_artists[] = [
            'name' => $artist,
            'count' => $count,
            'avg_rating' => $avg_rating,
            'score' => $count * $avg_rating // Punteggio combinato
        ];
    }
    
    // Ordina per punteggio
    usort($top_artists, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    
    return array_slice($top_artists, 0, 5); // Top 5 artisti
}

/**
 * Estrae i top generi dalle canzoni piaciute
 */
function extractTopGenres($liked_songs) {
    $genre_count = [];
    $genre_ratings = [];
    
    foreach ($liked_songs as $song) {
        if (!empty($song['category'])) {
            $genre = $song['category'];
            if (!isset($genre_count[$genre])) {
                $genre_count[$genre] = 0;
                $genre_ratings[$genre] = [];
            }
            $genre_count[$genre]++;
            $genre_ratings[$genre][] = $song['rating'];
        }
    }
    
    $top_genres = [];
    foreach ($genre_count as $genre => $count) {
        $avg_rating = array_sum($genre_ratings[$genre]) / count($genre_ratings[$genre]);
        $top_genres[] = [
            'name' => $genre,
            'count' => $count,
            'avg_rating' => $avg_rating,
            'score' => $count * $avg_rating
        ];
    }
    
    usort($top_genres, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    
    return array_slice($top_genres, 0, 3); // Top 3 generi
}

/**
 * Calcola le caratteristiche audio medie
 */
function calculateAverageAudioFeatures($liked_songs) {
    $valence_sum = 0;
    $energy_sum = 0;
    $popularity_sum = 0;
    $bpm_sum = 0;
    $count = 0;
    
    foreach ($liked_songs as $song) {
        if (!empty($song['valence'])) {
            $valence_sum += $song['valence'];
        }
        if (!empty($song['energy_level'])) {
            $energy_value = match($song['energy_level']) {
                'low' => 0.3,
                'medium' => 0.6,
                'high' => 0.9,
                default => 0.5
            };
            $energy_sum += $energy_value;
        }
        if (!empty($song['popularity'])) {
            $popularity_sum += $song['popularity'];
        }
        if (!empty($song['bpm'])) {
            $bpm_sum += $song['bpm'];
        }
        $count++;
    }
    
    return [
        'avg_valence' => $count > 0 ? $valence_sum / $count : 0.5,
        'avg_energy' => $count > 0 ? $energy_sum / $count : 0.5,
        'avg_popularity' => $count > 0 ? $popularity_sum / $count : 50,
        'avg_bpm' => $count > 0 ? $bpm_sum / $count : 120
    ];
}

/**
 * Ottiene raccomandazioni da Spotify basate sulle preferenze
 */
function getSpotifyRecommendationsFromPreferences($preferences, $access_token, $debug = false) {
    $all_recommendations = [];
    
    // 1. Raccomandazioni basate sui top artisti
    $artist_recs = getRecommendationsByArtists($preferences['top_artists'], $access_token, $debug);
    $all_recommendations = array_merge($all_recommendations, $artist_recs);
    
    // 2. Raccomandazioni basate sui generi
    $genre_recs = getRecommendationsByGenres($preferences['top_genres'], $access_token, $debug);
    $all_recommendations = array_merge($all_recommendations, $genre_recs);
    
    // 3. Raccomandazioni basate sulle caratteristiche audio
    $audio_recs = getRecommendationsByAudioFeatures($preferences['audio_features'], $access_token, $debug);
    $all_recommendations = array_merge($all_recommendations, $audio_recs);
    
    // 4. Raccomandazioni basate sui brani piaciuti (se hanno spotify_track_id)
    $track_recs = getRecommendationsByTracks($preferences['liked_songs'], $access_token, $debug);
    $all_recommendations = array_merge($all_recommendations, $track_recs);
    
    return $all_recommendations;
}

/**
 * Raccomandazioni basate sui top artisti
 */
function getRecommendationsByArtists($top_artists, $access_token, $debug = false) {
    $recommendations = [];
    
    foreach (array_slice($top_artists, 0, 3) as $artist_info) {
        $artist_name = $artist_info['name'];
        
        // Cerca l'artista su Spotify
        $spotify_artist_id = searchSpotifyArtist($artist_name, $access_token);
        
        if ($spotify_artist_id) {
            // Ottieni le top tracks dell'artista
            $top_tracks = getArtistTopTracks($spotify_artist_id, $access_token);
            foreach ($top_tracks as $track) {
                $track['recommendation_reason'] = "Ti piace " . $artist_name . " (⭐" . round($artist_info['avg_rating'], 1) . ")";
                $track['recommendation_type'] = 'artist_based';
                $recommendations[] = $track;
            }
            
            // Ottieni artisti simili
            $related_artists = getRelatedArtists($spotify_artist_id, $access_token);
            foreach (array_slice($related_artists, 0, 2) as $related_artist) {
                $related_top_tracks = getArtistTopTracks($related_artist['id'], $access_token);
                foreach (array_slice($related_top_tracks, 0, 2) as $track) {
                    $track['recommendation_reason'] = "Simile a " . $artist_name . " che ti piace";
                    $track['recommendation_type'] = 'related_artist';
                    $recommendations[] = $track;
                }
            }
        }
    }
    
    return $recommendations;
}

/**
 * Raccomandazioni basate sui generi
 */
function getRecommendationsByGenres($top_genres, $access_token, $debug = false) {
    $recommendations = [];
    
    foreach (array_slice($top_genres, 0, 2) as $genre_info) {
        $genre_name = strtolower($genre_info['name']);
        
        // Converti il genere in un genere Spotify valido
        $spotify_genre = mapToSpotifyGenre($genre_name);
        
        if ($spotify_genre) {
            // Usa l'API recommendations di Spotify
            $genre_recommendations = getSpotifyRecommendationsByGenre($spotify_genre, $access_token, 5);
            
            foreach ($genre_recommendations as $track) {
                $track['recommendation_reason'] = "Ti piace il genere " . $genre_info['name'] . " (⭐" . round($genre_info['avg_rating'], 1) . ")";
                $track['recommendation_type'] = 'genre_based';
                $recommendations[] = $track;
            }
        }
    }
    
    return $recommendations;
}

/**
 * Raccomandazioni basate sulle caratteristiche audio
 */
function getRecommendationsByAudioFeatures($audio_features, $access_token, $debug = false) {
    $recommendations = [];
    
    // Usa l'API recommendations di Spotify con target audio features
    $params = [
        'limit' => 10,
        'market' => 'IT',
        'target_valence' => round($audio_features['avg_valence'], 2),
        'target_energy' => round($audio_features['avg_energy'], 2),
        'target_popularity' => round($audio_features['avg_popularity']),
        'seed_genres' => 'pop,rock,indie' // Generi di default
    ];
    
    $audio_recommendations = callSpotifyRecommendationsAPI($params, $access_token);
    
    foreach ($audio_recommendations as $track) {
        $track['recommendation_reason'] = "Caratteristiche audio simili ai tuoi gusti";
        $track['recommendation_type'] = 'audio_features';
        $recommendations[] = $track;
    }
    
    return $recommendations;
}

/**
 * Raccomandazioni basate sui brani piaciuti
 */
function getRecommendationsByTracks($liked_songs, $access_token, $debug = false) {
    $recommendations = [];
    
    // Trova brani con spotify_track_id
    $spotify_track_ids = [];
    foreach ($liked_songs as $song) {
        if (!empty($song['spotify_track_id'])) {
            $spotify_track_ids[] = $song['spotify_track_id'];
        }
    }
    
    if (!empty($spotify_track_ids)) {
        // Usa i primi 5 brani come seed
        $seed_tracks = array_slice($spotify_track_ids, 0, 5);
        
        $params = [
            'limit' => 10,
            'market' => 'IT',
            'seed_tracks' => implode(',', $seed_tracks)
        ];
        
        $track_recommendations = callSpotifyRecommendationsAPI($params, $access_token);
        
        foreach ($track_recommendations as $track) {
            $track['recommendation_reason'] = "Simile ai brani che hai apprezzato";
            $track['recommendation_type'] = 'track_based';
            $recommendations[] = $track;
        }
    }
    
    return $recommendations;
}

// =====================================================
// FUNZIONI HELPER SPOTIFY API
// =====================================================

/**
 * Cerca artista su Spotify
 */
function searchSpotifyArtist($artist_name, $access_token) {
    $query = urlencode($artist_name);
    $url = "https://api.spotify.com/v1/search?q={$query}&type=artist&limit=1";
    
    $response = makeSpotifyAPICall($url, $access_token);
    
    if ($response && isset($response['artists']['items'][0])) {
        return $response['artists']['items'][0]['id'];
    }
    
    return null;
}

/**
 * Ottiene le top tracks di un artista
 */
function getArtistTopTracks($artist_id, $access_token) {
    $url = "https://api.spotify.com/v1/artists/{$artist_id}/top-tracks?market=IT";
    
    $response = makeSpotifyAPICall($url, $access_token);
    
    if ($response && isset($response['tracks'])) {
        return array_slice($response['tracks'], 0, 3); // Top 3 tracks
    }
    
    return [];
}

/**
 * Ottiene artisti simili
 */
function getRelatedArtists($artist_id, $access_token) {
    $url = "https://api.spotify.com/v1/artists/{$artist_id}/related-artists";
    
    $response = makeSpotifyAPICall($url, $access_token);
    
    if ($response && isset($response['artists'])) {
        return array_slice($response['artists'], 0, 3); // Top 3 artisti simili
    }
    
    return [];
}

/**
 * Ottiene raccomandazioni da Spotify per genere
 */
function getSpotifyRecommendationsByGenre($genre, $access_token, $limit = 5) {
    $params = [
        'limit' => $limit,
        'market' => 'IT',
        'seed_genres' => $genre
    ];
    
    return callSpotifyRecommendationsAPI($params, $access_token);
}

/**
 * Chiamata generica all'API recommendations di Spotify
 */
function callSpotifyRecommendationsAPI($params, $access_token) {
    $url = "https://api.spotify.com/v1/recommendations?" . http_build_query($params);
    
    $response = makeSpotifyAPICall($url, $access_token);
    
    if ($response && isset($response['tracks'])) {
        return $response['tracks'];
    }
    
    return [];
}

/**
 * Chiamata generica all'API Spotify
 */
function makeSpotifyAPICall($url, $access_token) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$access_token}",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($http_code === 200) {
        return json_decode($response, true);
    }
    
    error_log("Spotify API Error: HTTP {$http_code} for URL: {$url}");
    return null;
}

/**
 * Mappa i generi locali ai generi Spotify
 */
function mapToSpotifyGenre($local_genre) {
    $genre_map = [
        'rock' => 'rock',
        'pop' => 'pop',
        'jazz' => 'jazz',
        'blues' => 'blues',
        'country' => 'country',
        'electronic' => 'electronic',
        'hip-hop' => 'hip-hop',
        'rap' => 'hip-hop',
        'reggae' => 'reggae',
        'classical' => 'classical',
        'indie' => 'indie',
        'alternative' => 'alternative',
        'metal' => 'metal',
        'r&b' => 'r-n-b',
        'soul' => 'soul',
        'funk' => 'funk'
    ];
    
    return $genre_map[$local_genre] ?? 'pop';
}

/**
 * Formatta le raccomandazioni Spotify
 */
function formatSpotifyRecommendations($spotify_recommendations, $patient_preferences) {
    $formatted = [];
    
    foreach ($spotify_recommendations as $track) {
        $formatted[] = [
            'id' => null, // Nessun ID locale
            'title' => $track['name'],
            'artist' => $track['artists'][0]['name'],
            'album' => $track['album']['name'] ?? null,
            'spotify_track_id' => $track['id'],
            'spotify_url' => $track['external_urls']['spotify'] ?? null,
            'image_url' => $track['album']['images'][0]['url'] ?? null,
            'preview_url' => $track['preview_url'],
            'popularity' => $track['popularity'],
            'duration_ms' => $track['duration_ms'],
            'explicit' => $track['explicit'],
            'reason' => $track['recommendation_reason'] ?? 'Raccomandazione Spotify',
            'recommendation_type' => $track['recommendation_type'] ?? 'spotify',
            'recommendation_score' => calculateRecommendationScore($track, $patient_preferences),
            'source' => 'spotify'
        ];
    }
    
    return $formatted;
}

/**
 * Calcola il punteggio di raccomandazione
 */
function calculateRecommendationScore($track, $patient_preferences) {
    $base_score = 0.7;
    
    // Bonus per popolarità simile
    if (isset($patient_preferences['audio_features']['avg_popularity'])) {
        $popularity_diff = abs($track['popularity'] - $patient_preferences['audio_features']['avg_popularity']);
        $popularity_bonus = (100 - $popularity_diff) / 100 * 0.2;
        $base_score += $popularity_bonus;
    }
    
    // Bonus per tipo di raccomandazione
    $type_bonus = match($track['recommendation_type'] ?? 'spotify') {
        'artist_based' => 0.2, //artisti preferiti
        'track_based' => 0.15, //brani simili
        'genre_based' => 0.1, //generi simili
        'audio_features' => 0.1, //caratteristiche audio simili
        default => 0.05      //bonus predefinito per raccomandazioni Spotify generiche
    };
    
    $base_score += $type_bonus;
    
    return min(1.0, max(0.1, $base_score));
}

/**
 * Filtra i brani già esistenti nel database
 */
function filterExistingTracks($recommendations, $patient_id, $conn) {
    $filtered = [];
    foreach ($recommendations as $rec) {
        $spotify_id = $rec['spotify_track_id'];
        // Controlla se il brano è già stato valutato dall'utente
        $exists_query = "
            SELECT r.id 
            FROM tracks t 
            JOIN track_ratings r ON t.id = r.track_id 
            WHERE t.spotify_track_id = ? AND r.user_id = ?
        ";
        $stmt = $conn->prepare($exists_query);
        $stmt->bind_param('si', $spotify_id, $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $filtered[] = $rec;
        }
        $stmt->close();
    }
    return $filtered;
}
?>
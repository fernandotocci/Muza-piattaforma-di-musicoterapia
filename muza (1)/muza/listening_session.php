<?php
// listening_session.php - VERSIONE CORRETTA
session_start();
require_once 'includes/db.php';

// CORREZIONE: Verifica robusta della connessione database
if (!$conn) {
    error_log("LISTENING_SESSION: Connessione database non disponibile");
    die("Errore di connessione al database. Riprova più tardi.");
}

if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];

// CORREZIONE: Gestione ID traccia Spotify e ID interno
$track_param = $_GET['track'] ?? null;
$track_id = null;
$spotify_track_id_param = null;

if ($track_param) {
    // Se non è puramente numerico, è un ID Spotify
    if (!ctype_digit($track_param)) {
        $spotify_track_id_param = $track_param;
    } else {
        $track_id = intval($track_param);
    }
}

$playlist_id = isset($_GET['playlist']) ? intval($_GET['playlist']) : null;
$is_recommendation = isset($_GET['is_recommendation']) && $_GET['is_recommendation'] === 'true';

// Se è una raccomandazione, non deve mai essere considerata una playlist
if ($is_recommendation) {
    $playlist_id = null;
}

// Validazione parametri
if ($track_id !== null && $track_id <= 0) {
    $track_id = null;
}
if ($playlist_id !== null && $playlist_id <= 0) {
    $playlist_id = null;
}

// Debug
error_log("LISTENING_SESSION: track_id = " . ($track_id ?? 'NULL') . ", playlist_id = " . ($playlist_id ?? 'NULL'));

// Inizializza variabili
$playlist = null;
$playlist_tracks = [];
$current_track = null;
$current_track_index = 0;

try {
    // CORREZIONE: Gestione migliorata della modalità playlist
    if ($playlist_id && !$is_recommendation) {
        // === MODALITÀ PLAYLIST ===
        
        // Recupera info della playlist e verifica accesso
        $playlist_query = "
            SELECT tp.*, CONCAT(u.first_name, ' ', u.last_name) as therapist_name 
            FROM therapist_playlists tp 
            LEFT JOIN users u ON tp.therapist_id = u.id 
            WHERE tp.id = ? AND (tp.patient_id = ? OR tp.patient_id IS NULL)
        ";
        
        $stmt = $conn->prepare($playlist_query);
        if (!$stmt) {
            throw new Exception("Errore preparazione query playlist: " . $conn->error);
        }
        
        $stmt->bind_param("ii", $playlist_id, $user['id']);
        $stmt->execute();
        $playlist_result = $stmt->get_result();
        
        if (!$playlist_result || $playlist_result->num_rows === 0) {
            $stmt->close();
            error_log("LISTENING_SESSION: Playlist $playlist_id non trovata o non accessibile per user_id=" . $user['id']);
            header('Location: patient_dashboard.php');
            exit;
        }
        
        $playlist = $playlist_result->fetch_assoc();
        $stmt->close();
        
        // Recupera tutte le tracce della playlist
        $tracks_query = "
            SELECT t.*, pt.position, pt.therapist_notes
            FROM playlist_tracks pt
            JOIN tracks t ON pt.track_id = t.id
            WHERE pt.playlist_id = ?
            ORDER BY pt.position ASC
        ";
        
        $stmt = $conn->prepare($tracks_query);
        if (!$stmt) {
            throw new Exception("Errore preparazione query tracce: " . $conn->error);
        }
        
        $stmt->bind_param("i", $playlist_id);
        $stmt->execute();
        $tracks_result = $stmt->get_result();
        
        if (!$tracks_result) {
            $stmt->close();
            throw new Exception("Errore esecuzione query tracce: " . $conn->error);
        }
        
        $playlist_tracks = [];
        while ($track = $tracks_result->fetch_assoc()) {
            $playlist_tracks[] = $track;
        }
        $stmt->close();
        
        // CORREZIONE: Verifica che la playlist abbia tracce
        if (empty($playlist_tracks)) {
            error_log("LISTENING_SESSION: Playlist $playlist_id è vuota");
            $_SESSION['error'] = "La playlist selezionata è vuota.";
            header('Location: patient_dashboard.php');
            exit;
        }
        
        // Determina la traccia corrente
        if ($track_id) {
            // Trova la posizione della traccia specifica nella playlist
            foreach ($playlist_tracks as $index => $track) {
                if ($track['id'] == $track_id) {
                    $current_track_index = $index;
                    break;
                }
            }
        }
        
        $current_track = $playlist_tracks[$current_track_index] ?? $playlist_tracks[0];
        
    } elseif ($track_id || $spotify_track_id_param) {
        // === MODALITÀ TRACCIA SINGOLA (INTERNA O SPOTIFY) ===
        
        $track_query = "
            SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) as therapist_name 
            FROM tracks t 
            LEFT JOIN users u ON t.therapist_id = u.id 
            WHERE " . ($track_id ? "t.id = ?" : "t.spotify_track_id = ?");
        
        $stmt = $conn->prepare($track_query);
        if (!$stmt) {
            throw new Exception("Errore preparazione query traccia: " . $conn->error);
        }
        
        if ($track_id) {
            $stmt->bind_param("i", $track_id);
        } else {
            $stmt->bind_param("s", $spotify_track_id_param);
        }
        $stmt->execute();
        $track_result = $stmt->get_result();
        
        if ($track_result && $track_result->num_rows > 0) {
            $current_track = $track_result->fetch_assoc();
        } else {
            // Se la traccia non è nel DB e abbiamo un ID Spotify, la aggiungiamo
            if ($spotify_track_id_param) {
                $stmt->close();
                // Reindirizza allo script che aggiunge la traccia e poi torna qui
                header("Location: api/add_spotify_track.php?spotify_track_id=" . urlencode($spotify_track_id_param) . "&return_to_session=1");
                exit;
            }
            
            $stmt->close();
            error_log("LISTENING_SESSION: Traccia non trovata per " . ($track_id ? "ID=$track_id" : "SpotifyID=$spotify_track_id_param"));
            header('Location: patient_dashboard.php');
            exit;
        }
        
        $stmt->close();
        
    } else {
        // === MODALITÀ SELEZIONE PLAYLIST ===
        
        // Mostra selezione playlist disponibili
        $playlists_query = "
            SELECT tp.*, CONCAT(u.first_name, ' ', u.last_name) as therapist_name,
                   COUNT(pt.track_id) as track_count
            FROM therapist_playlists tp 
            LEFT JOIN users u ON tp.therapist_id = u.id 
            LEFT JOIN playlist_tracks pt ON tp.id = pt.playlist_id
            WHERE tp.patient_id = ? OR tp.patient_id IS NULL
            GROUP BY tp.id
            HAVING track_count > 0
            ORDER BY tp.created_at DESC
        ";
        
        $stmt = $conn->prepare($playlists_query);
        if (!$stmt) {
            error_log("LISTENING_SESSION: Errore nella preparazione query playlist: " . $conn->error);
            $playlists_result = false;
        } else {
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $playlists_result = $stmt->get_result();
            $stmt->close();
        }
    }

} catch (Exception $e) {
    error_log("LISTENING_SESSION: Errore critico - " . $e->getMessage());
    $_SESSION['error'] = "Errore nel caricamento della sessione. Riprova più tardi.";
    header('Location: patient_dashboard.php');
    exit;
}

// CORREZIONE: Gestione più robusta del token Spotify
$spotify_token = null;
$spotify_connected = false;

if (isset($current_track)) {
    try {
        $spotify_token_query = "
            SELECT access_token, refresh_token, expires_at 
            FROM user_music_tokens 
            WHERE user_id = ? 
            AND service = 'spotify' 
            AND expires_at > NOW()
            LIMIT 1
        ";
        
        $stmt = $conn->prepare($spotify_token_query);
        if ($stmt) {
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $spotify_result = $stmt->get_result();
            
            if ($spotify_result && $spotify_result->num_rows > 0) {
                $token_data = $spotify_result->fetch_assoc();
                $spotify_token = $token_data['access_token'];
                $spotify_connected = true;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("LISTENING_SESSION: Errore nel recuperare token Spotify per user_id=" . $user['id'] . ": " . $e->getMessage());
        // Continua senza Spotify
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessione di Ascolto - Mūza</title>
    <link rel="stylesheet" href="css/style.css">
    
    <style>
        /* Nascondi errori di rete di Spotify nella console */
        .console-error[data-url*="cpapi.spotify.com"] {
            display: none !important;
        }
        
        .integrated-player {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin: 0;
            color: #333333;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid #e1e5e9;
            width: 100%;
        }
        
        .track-extra-info {
            animation: fadeInUp 0.5s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .track-info {
            text-align: center;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .album-cover {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            object-fit: cover;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            flex-shrink: 0;
        }
        
        .track-details {
            flex: 1;
            text-align: left;
        }
        
        .track-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
            line-height: 1.2;
        }
        
        .track-artist {
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        
        .track-album {
            font-size: 14px;
            opacity: 0.7;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .track-info {
                flex-direction: column;
                text-align: center;
            }
            
            .track-details {
                text-align: center;
            }
            
            .album-cover {
                width: 100px;
                height: 100px;
            }
        }
        
        .connection-status {
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: center;
        }
        
        .status-connected {
            background: rgba(29, 185, 84, 0.1);
            border: 1px solid #1db954;
            color: #1db954;
        }
        
        .status-disconnected {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid #dc3545;
            color: #dc3545;
        }
        
        .device-info {
            font-size: 14px;
            opacity: 0.8;
            margin-top: 10px;
        }

        .playlist-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 32px;
            border-radius: 20px;
            margin-bottom: 24px;
            text-align: center;
        }
        
        .track-navigation {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 24px 0;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .nav-btn {
            background: #6b21a8;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .nav-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }
        
        .nav-btn:not(:disabled):hover {
            background: #7c3aed;
            transform: translateY(-1px);
        }
        
        .track-counter {
            font-weight: 600;
            color: #374151;
        }
        
        .rating-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin: 24px 0;
            border: 2px solid #e5e7eb;
            display: none;
        }
        
        .rating-section.active {
            display: block;
            animation: fadeInUp 0.5s ease;
        }
        
        .rating-stars {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 16px 0;
        }
        
        .rating-star {
            font-size: 2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            opacity: 0.3;
        }
        
        .rating-star:hover,
        .rating-star.active {
            opacity: 1;
            transform: scale(1.1);
        }
        
        .playlist-progress {
            background: #e5e7eb;
            height: 6px;
            border-radius: 9999px;
            margin: 16px 0;
            overflow: hidden;
        }
        
        .playlist-progress-fill {
            background: linear-gradient(135deg, #6b21a8, #7c3aed);
            height: 100%;
            transition: width 0.3s ease;
        }
        
        /* Nuovi stili per il flusso step-by-step */
        .session-flow {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .session-step {
            display: none;
            background: white;
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 2px solid #e5e7eb;
        }
        
        .session-step.active {
            display: block;
            border-color: #6b21a8;
            box-shadow: 0 8px 25px rgba(107, 33, 168, 0.15);
        }
        
        .step-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .step-header h3 {
            color: #6b21a8;
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .step-header p {
            color: #6b7280;
            font-size: 16px;
        }
        
        .mood-assessment-card,
        .rating-card,
        .listening-notes-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 24px;
            margin: 24px 0;
        }
        
        .mood-scale {
            margin: 24px 0;
        }
        
        .mood-scale label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 12px;
        }
        
        .mood-scale input[type="range"] {
            width: 100%;
            height: 8px;
            border-radius: 4px;
            background: #e5e7eb;
            outline: none;
            margin: 12px 0;
        }
        
        .scale-labels {
            color: #000000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
           
        }
        
        .current-value {
            
            padding: 4px 10px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .step-actions {
            text-align: center;
            margin-top: 32px;
        }
        
        .btn-lg {
            padding: 16px 32px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 14px;
        }
        
        .listening-notes-card textarea {
            width: 100%;
            padding: 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
            font-size: 16px;
            resize: vertical;
            margin-bottom: 16px;
        }
        
        .listening-notes-card textarea:focus {
            border-color: #6b21a8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(107, 33, 168, 0.1);
        }
        
        .rating-card textarea {
            width: 100%;
            min-height: 150px;
            padding: 20px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-family: inherit;
            font-size: 16px;
            resize: vertical;
            margin-top: 20px;
            line-height: 1.6;
        }
        
        .rating-card textarea:focus {
            border-color: #6b21a8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(107, 33, 168, 0.1);
        }
        
        .rating-card textarea::placeholder {
            color: #9ca3af;
            font-style: italic;
        }

        .feedback-message {
            display: none;
            background: #d1e7dd;
            color: #0f5132;
            padding: 12px;
            border-radius: 8px;
            margin: 16px 0;
            font-size: 16px;
            text-align: center;
        }

        .mood-comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin: 24px 0;
        }
        
        .mood-before,
        .mood-after {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            border: 2px solid #e5e7eb;
        }
        
        .mood-before h4,
        .mood-after h4 {
            text-align: center;
            color: #374151;
            margin-bottom: 16px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .mood-values {
            display: flex;
            flex-direction: column;
            gap: 12px;
            text-align: center;
        }
        
        .mood-values span {
            background: #e5e7eb;
            padding: 12px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 16px;
        }

        @media (max-width: 768px) {
            .mood-comparison {
                grid-template-columns: 1fr;
            }
            
            .session-step {
                padding: 24px;
            }
            
            .rating-star {
                font-size: 2.5rem;
            }
        }

        

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        .playlist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
            margin-top: 24px;
        }

        .playlist-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .playlist-card:hover {
            border-color: #6b21a8;
            box-shadow: 0 8px 25px rgba(107, 33, 168, 0.15);
            transform: translateY(-2px);
        }

        .playlist-card h3 {
            color: #374151;
            margin-bottom: 12px;
            font-size: 18px;
            font-weight: 600;
        }

        .playlist-description {
            color: #6b7280;
            margin-bottom: 16px;
            line-height: 1.5;
        }

        .playlist-meta {
            font-size: 14px;
            color: #9ca3af;
            margin-bottom: 16px;
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-wrapper">
        <div class="session-container">
        
        <div class="dashboard-header">
            <div>
                <h1>🎵 Sessione di Ascolto</h1>
                <p>Concentrati sulla musica e lasciati guidare dalle emozioni</p>
            </div>
            <a href="patient_dashboard.php" class="dashboard-link">← Torna alla Dashboard</a>
        </div>

        <?php if (!isset($current_track) && !$playlist_id): ?>
            <!-- Selezione Playlist -->
            <div class="widget">
                <h2>🎭 Playlist Disponibili</h2>
                <p style="color: var(--text-secondary); margin-bottom: var(--space-6);">
                    Scegli una playlist creata dal tuo terapeuta per iniziare la sessione di ascolto
                </p>
                
                <?php if (isset($playlists_result) && $playlists_result && mysqli_num_rows($playlists_result) > 0): ?>
                    <div class="playlist-grid">
                        <?php while ($playlist_item = mysqli_fetch_assoc($playlists_result)): ?>
                            <div class="playlist-card" onclick="startPlaylistSession(<?= $playlist_item['id'] ?>)">
                                <h3>🎵 <?= htmlspecialchars($playlist_item['name']) ?></h3>
                                <p class="playlist-description"><?= htmlspecialchars($playlist_item['description'] ?? 'Playlist terapeutica') ?></p>
                                <div class="playlist-meta">
                                    <span>👤 <?= htmlspecialchars($playlist_item['therapist_name']) ?></span>
                                    <span>🎶 <?= $playlist_item['track_count'] ?> brani</span>
                                </div>
                                <button class="btn btn-primary" style="margin-top: var(--space-4);">
                                    ▶️ Inizia Sessione
                                </button>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <h3>📭 Nessuna playlist disponibile</h3>
                        <p>Il tuo terapeuta non ha ancora creato playlist per te.</p>
                        <button onclick="location.href='patient_dashboard.php'" class="btn btn-secondary" style="margin-top: 16px;">
                            Torna alla Dashboard
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- Sessione di Ascolto Attiva -->
            <?php if ($playlist_id && $playlist): ?>
                <!-- Header Playlist -->
                <div class="playlist-header">
                    <h2>🎭 <?= htmlspecialchars($playlist['name']) ?></h2>
                    <?php if (!empty($playlist['description'])): ?>
                        <p><?= htmlspecialchars($playlist['description']) ?></p>
                    <?php endif; ?>
                    <p><strong>Terapeuta:</strong> <?= htmlspecialchars($playlist['therapist_name'] ?? 'Non specificato') ?></p>
                    
                    <!-- Progress Playlist -->
                    <?php if (!empty($playlist_tracks)): ?>
                        <div class="playlist-progress">
                            <div class="playlist-progress-fill" style="width: <?= (($current_track_index + 1) / count($playlist_tracks)) * 100 ?>%"></div>
                        </div>
                        <p><?= $current_track_index + 1 ?> di <?= count($playlist_tracks) ?> brani</p>
                    <?php endif; ?>
                </div>
                
                <!-- Navigazione Tracce -->
                <?php if (!empty($playlist_tracks)): ?>
                    <div class="track-navigation">
                        <button class="nav-btn" id="prevTrackBtn" <?= $current_track_index <= 0 ? 'disabled' : '' ?>>
                            ⏮ Precedente
                        </button>
                        
                        <div class="track-counter">
                            Brano <?= $current_track_index + 1 ?> di <?= count($playlist_tracks) ?>
                        </div>
                        
                        <button class="nav-btn" id="nextTrackBtn" <?= $current_track_index >= count($playlist_tracks) - 1 ? 'disabled' : '' ?>>
                            Successivo ⏭
                        </button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($current_track): ?>
                <div class="widget">
                    <div class="session-header">
                        <h2>🎼 <?= htmlspecialchars($current_track['title']) ?></h2>
                        <p class="track-artist">di <?= htmlspecialchars($current_track['artist']) ?></p>
                        <?php if (isset($current_track['therapist_notes']) && !empty($current_track['therapist_notes'])): ?>
                            <div class="therapist-notes" style="background: var(--light-purple); padding: var(--space-4); border-radius: var(--radius-md); margin: var(--space-4) 0;">
                                <strong>💬 Note del Terapeuta:</strong><br>
                                <?= htmlspecialchars($current_track['therapist_notes']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sessione di Ascolto - Flusso Step by Step -->
                    <div class="session-flow">
                        
                        <!-- STEP 1: Valutazione Iniziale -->
                        <div class="session-step active" id="step-initial-mood">
                            <div class="step-header">
                                <h3>Come ti senti ora?</h3>
                                <p>Prima di iniziare l'ascolto, valuta il tuo stato d'animo attuale</p>
                            </div>
                            
                            <div class="mood-assessment-card">
                                <div class="mood-scale">
                                    <label for="moodBefore">Umore (1=molto basso, 10=eccellente):</label>
                                    <input type="range" id="moodBefore" min="1" max="10" value="5">
                                    <div class="scale-labels">
                                        <span> Basso</span>
                                        <span id="moodBeforeDisplay" class="current-value">5</span>
                                        <span> Eccellente</span>
                                    </div>
                                </div>
                                
                                <div class="mood-scale">
                                    <label for="energyBefore">Energia (1=esausto, 10=energico):</label>
                                    <input type="range" id="energyBefore" min="1" max="10" value="5">
                                    <div class="scale-labels">
                                        <span> Esausto</span>
                                        <span id="energyBeforeDisplay" class="current-value">5</span>
                                        <span> Energico</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="step-actions">
                                <button class="btn btn-primary btn-lg" onclick="startListening()">
                                    Inizia l'Ascolto
                                </button>
                            </div>
                        </div>
                        
                        <!-- STEP 2: Player e Ascolto -->
                        <div class="session-step" id="step-listening">
                            <div class="step-header">
                                <h3> Ascolta e Rifletti</h3>
                                <p>Concentrati sulla musica e annota le tue sensazioni</p>
                            </div>
                            
                            <!-- Player Integrato -->
                            <?php if ($spotify_connected && !empty($current_track['spotify_track_id'])): ?>
                                <div class="integrated-player" id="integratedPlayer" style="display: block;">
                                    <!-- Iframe del Web Player Spotify -->
                                    <iframe 
                                        src="https://open.spotify.com/embed/track/<?= urlencode($current_track['spotify_track_id']) ?>?utm_source=generator&theme=0" 
                                        width="100%" 
                                        height="380" 
                                        frameBorder="0" 
                                        allowfullscreen="" 
                                        allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture" 
                                        loading="lazy"
                                        style="border-radius: 12px;">
                                    </iframe>
                                    
                                    <div style="margin-top:12px; display:flex; gap:12px; align-items:center;">
                                        <div id="webPlayerStatus" style="font-size:14px; color:#6b7280;">Usa il player Spotify qui sopra per riprodurre il brano completo.</div>
                                    </div>
                                    <?php if (isset($current_track['therapist_notes']) && !empty($current_track['therapist_notes'])): ?>
                                        <!-- Note del terapeuta -->
                                        <div class="track-extra-info" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 15px; border-left: 4px solid #6b21a8;">
                                            <div style="display: flex; align-items: flex-start; gap: 10px;">
                                                <span style="font-size: 18px;">💭</span>
                                                <div>
                                                    <div style="font-weight: 600; color: #6b21a8; margin-bottom: 5px;">Nota del terapeuta:</div>
                                                    <div style="color: #374151;"><?= htmlspecialchars($current_track['therapist_notes']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="connection-status status-disconnected">
                                    <div>❌ Spotify non collegato o brano non disponibile</div>
                                    <p>Per utilizzare il player integrato e ascoltare i brani completi, collega il tuo account Spotify.</p>
                                    <p><small><strong>Nota:</strong> Il player funziona direttamente nel browser con un account Spotify Premium.</small></p>
                                    <button onclick="connectSpotifyFromSession()" class="btn btn-primary">
                                        🔗 Connetti Spotify Ora
                                    </button>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Note durante l'ascolto -->
                            <div class="listening-notes-card">
                                        <h4>📝 Le tue riflessioni</h4>
                                        <label for="sessionNotes" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">Le tue riflessioni</label>
                                        <textarea id="sessionNotes" 
                                                  placeholder="Scrivi qui quello che provi durante l'ascolto: emozioni, ricordi, sensazioni fisiche..." 
                                                  rows="4"></textarea>
                                <button class="btn btn-secondary btn-sm" onclick="saveSessionProgress()">
                                    💾 Salva Note
                                </button>
                            </div>
                            
                            <div class="step-actions">
                                <button class="btn btn-success btn-lg" onclick="showTrackRating()" id="proceedToRating">
                                    ⭐ Valuta questo Brano
                                </button>
                            </div>
                        </div>
                        
                        <!-- STEP 3: Valutazione Brano -->
                        <div class="session-step" id="step-rating">
                            <div class="step-header">
                                <h3>⭐ Valuta il Brano</h3>
                                <p>Quanto è stato utile "<strong id="ratingTrackTitle"><?= htmlspecialchars($current_track['title']) ?></strong>" per il tuo benessere?</p>
                            </div>
                            
                            <div class="rating-card">
                                <div class="rating-stars" id="ratingStars">
                                    <span class="rating-star" data-rating="1">⭐</span>
                                    <span class="rating-star" data-rating="2">⭐</span>
                                    <span class="rating-star" data-rating="3">⭐</span>
                                    <span class="rating-star" data-rating="4">⭐</span>
                                    <span class="rating-star" data-rating="5">⭐</span>
                                </div>
                                <p class="rating-description">Clicca sulle stelle per valutare (1 = per niente utile, 5 = molto utile)</p>
                                
                                <div style="margin-top: 24px;">
                                    <label style="display: block; font-weight: 600; color: #374151; margin-bottom: 12px;">
                                        💭 Racconta la tua esperienza (opzionale)
                                    </label>
                                    <textarea id="trackFeedback" 
                                              placeholder="Prenditi tutto il tempo che ti serve per descrivere cosa hai provato ascoltando questo brano...

• Come ti ha fatto sentire emotivamente?
• Hai pensato a qualcosa di particolare?
• Che sensazioni fisiche hai avvertito?
• Ti ha ricordato qualche memoria o esperienza?
• Come descrivi l'effetto che ha avuto su di te?

Ogni dettaglio è prezioso per il tuo percorso terapeutico." 
                                              rows="8"></textarea>
                                </div>
                            </div>
                            
                            <div class="step-actions">
                                <button class="btn btn-primary btn-lg" onclick="saveTrackRating()">
                                    ✅ Conferma Valutazione
                                </button>
                                <?php if ($playlist_id && !empty($playlist_tracks) && $current_track_index < count($playlist_tracks) - 1): ?>
                                    <button class="btn btn-secondary" onclick="skipRating()">
                                        ⏭ Salta Valutazione
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- STEP 4: Valutazione Finale -->
                        <?php if (!$playlist_id || ($playlist_id && !empty($playlist_tracks) && $current_track_index >= count($playlist_tracks) - 1)): ?>
                        <div class="session-step" id="step-final">
                            <div class="step-header">
                                <h3>🌟 Come ti senti ora?</h3>
                                <p>Valuta come è cambiato il tuo stato d'animo dopo <?php if ($playlist_id): ?>l'intera sessione di ascolto<?php else: ?>l'ascolto<?php endif ?></p>
                            </div>
                            
                            <div class="mood-comparison">
                                <div class="mood-before">
                                    <h4>Prima dell'ascolto</h4>
                                    <div class="mood-values">
                                        <span>Umore: <span id="moodBeforeShow">-</span></span>
                                        <span>Energia: <span id="energyBeforeShow">-</span></span>
                                    </div>
                                </div>
                                
                                <div class="mood-after">
                                    <h4>Dopo l'ascolto</h4>
                                    <div class="mood-scale">
                                        <label for="moodAfter">Umore:</label>
                                        <input type="range" id="moodAfter" min="1" max="10" value="5">
                                        <div class="scale-labels">
                                            <span>😞</span>
                                            <span id="moodAfterDisplay" class="current-value">5</span>
                                            <span>😊</span>
                                        </div>
                                    </div>
                                    
                                    <div class="mood-scale">
                                        <label for="energyAfter">Energia:</label>
                                        <input type="range" id="energyAfter" min="1" max="10" value="5">
                                        <div class="scale-labels">
                                            <span>😴</span>
                                            <span id="energyAfterDisplay" class="current-value">5</span>
                                            <span>⚡</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="step-actions">
                                <button class="btn btn-success btn-lg" onclick="completeSession()">
                                    🎉 Completa Sessione
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            <?php else: ?>
                <div class="error-message">
                    <h3>Errore nel caricamento della traccia</h3>
                    <p>La traccia richiesta non è disponibile o si è verificato un errore.</p>
                    <button onclick="location.href='patient_dashboard.php'" class="btn btn-secondary">
                        Torna alla Dashboard
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        </div>
    </div>
    
    <script>
        // === HELPER FUNCTION PER PERCORSI API ===
        function getApiUrl(endpoint) {
            const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
            return baseUrl + 'api/' + endpoint;
        }
        
        // === VARIABILI GLOBALI ===
        let session_start_time = Date.now();

        // CORREZIONE: Validazione più robusta delle variabili globali
        const playlistId = <?= $playlist_id ? (int)$playlist_id : 'null' ?>;
        const playlistTracks = <?= isset($playlist_tracks) && !empty($playlist_tracks) ? json_encode($playlist_tracks) : '[]' ?>;
        let currentTrackIndex = <?= (int)$current_track_index ?>;
        let currentTrackRating = 0;
        let trackRatings = {};

        const trackId = <?= isset($current_track) && $current_track ? (int)$current_track['id'] : 'null' ?>;

        const spotifyConnected = <?= $spotify_connected ? 'true' : 'false' ?>;
        const hasSpotifyTrackId = <?= (isset($current_track['spotify_track_id']) && $current_track['spotify_track_id']) ? 'true' : 'false' ?>;

        console.log('🔧 Variabili inizializzate:', {
            trackId: trackId,
            playlistId: playlistId,
            currentTrackIndex: currentTrackIndex,
            playlistTracksCount: playlistTracks.length,
            hasSpotifyTrackId: '<?= isset($current_track['spotify_track_id']) && $current_track['spotify_track_id'] ? 'SÌ' : 'NO' ?>'
        });

        // === FUNZIONI PLAYLIST ===
        function startPlaylistSession(playlistId) {
            if (!playlistId || playlistId <= 0) {
                console.error('ID playlist non valido:', playlistId);
                return;
            }
            window.location.href = `listening_session.php?playlist=${playlistId}`;
        }

        function navigateToTrack(newIndex) {
            if (!playlistTracks || newIndex < 0 || newIndex >= playlistTracks.length) {
                console.error('Indice traccia non valido:', newIndex);
                return;
            }
            
            const newTrack = playlistTracks[newIndex];
            if (!newTrack || !newTrack.id) {
                console.error('Traccia non valida all\'indice:', newIndex);
                return;
            }
            
            window.location.href = `listening_session.php?playlist=${playlistId}&track=${newTrack.id}`;
        }

        // === FUNZIONI RATING ===
        function setupRatingStars() {
            const stars = document.querySelectorAll('.rating-star');
            stars.forEach(function(star) {
                star.addEventListener('click', function() {
                    const rating = parseInt(this.dataset.rating);
                    if (rating >= 1 && rating <= 5) {
                        setRating(rating);
                    }
                });
                
                star.addEventListener('mouseenter', function() {
                    const rating = parseInt(this.dataset.rating);
                    if (rating >= 1 && rating <= 5) {
                        highlightStars(rating);
                    }
                });
            });
            
            const ratingContainer = document.getElementById('ratingStars');
            if (ratingContainer) {
                ratingContainer.addEventListener('mouseleave', function() {
                    highlightStars(currentTrackRating);
                });
            }
        }

        function highlightStars(rating) {
            const stars = document.querySelectorAll('.rating-star');
            stars.forEach(function(star, index) {
                if (index < rating) {
                    star.classList.add('active');
                } else {
                    star.classList.remove('active');
                }
            });
        }

        function setRating(rating) {
            if (rating >= 1 && rating <= 5) {
                currentTrackRating = rating;
                highlightStars(rating);
            }
        }

        function showTrackRating() {
            showStep('step-rating');
            
            // Aggiorna il titolo del brano da valutare
            const ratingTitle = document.getElementById('ratingTrackTitle');
            if (ratingTitle && playlistTracks && playlistTracks[currentTrackIndex]) {
                ratingTitle.textContent = playlistTracks[currentTrackIndex].title;
            } else if (ratingTitle && trackId) {
                // Per tracce singole, mantieni il titolo corrente
                const currentTitle = ratingTitle.textContent;
                if (!currentTitle || currentTitle.trim() === '') {
                    ratingTitle.textContent = 'questo brano';
                }
            }
        }
        
        // CORREZIONE: Gestione più robusta del completamento sessione
        function completeSession() {
            const moodBefore = document.getElementById('moodBefore')?.value;
            const moodAfter = document.getElementById('moodAfter')?.value;
            const energyBefore = document.getElementById('energyBefore')?.value;
            const energyAfter = document.getElementById('energyAfter')?.value;
            const sessionNotes = document.getElementById('sessionNotes')?.value || '';
            const listenDuration = Math.floor((Date.now() - session_start_time) / 1000);
            
            // Validazione dati obbligatori
            if (!moodBefore || !moodAfter || !energyBefore || !energyAfter) {
                alert('❌ Errore: dati dell\'umore mancanti');
                return;
            }
            
            // Per le playlist, usa l'ID della prima traccia o un valore di default
            let sessionTrackId = trackId;
            if (playlistId && (!trackId || trackId === null)) {
                if (playlistTracks && playlistTracks.length > 0) {
                    sessionTrackId = playlistTracks[0].id;
                } else {
                    alert('❌ Errore: impossibile completare la sessione senza tracce valide');
                    return;
                }
            }
            
            // Verifica che abbiamo un ID traccia valido
            if (!sessionTrackId || sessionTrackId === null || sessionTrackId <= 0) {
                alert('❌ Errore: nessuna traccia selezionata per completare la sessione');
                return;
            }
            
            // CORREZIONE: Preparazione dati più robusta
            const sessionData = {
                trackId: parseInt(sessionTrackId),
                playlist_id: playlistId,
                moodBefore: parseInt(moodBefore),
                moodAfter: parseInt(moodAfter),
                energyBefore: parseInt(energyBefore),
                energyAfter: parseInt(energyAfter),
                listeningNotes: sessionNotes,
                listenDuration: listenDuration,
                completed: true,
                track_ratings: trackRatings
            };
            
            console.log('Dati sessione da inviare:', sessionData);
            
            // Disabilita il pulsante per evitare doppi click
            const completeBtn = document.querySelector('[onclick="completeSession()"]');
            if (completeBtn) {
                completeBtn.disabled = true;
                completeBtn.textContent = '⏳ Salvando...';
            }
            
            // Invia i dati al server
            fetch(getApiUrl('save_complete_session.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(sessionData)
            })
            .then(response => {
                console.log('Risposta HTTP status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Risposta API:', data);
                if (data.success) {
                    alert('✅ Sessione completata con successo!');
                    window.location.href = 'patient_dashboard.php';
                } else {
                    throw new Error(data.error || 'Errore sconosciuto');
                }
            })
            .catch(error => {
                console.error('Errore:', error);
                alert('❌ Errore di connessione durante il salvataggio: ' + error.message);
                
                // Riabilita il pulsante
                if (completeBtn) {
                    completeBtn.disabled = false;
                    completeBtn.textContent = '🎉 Completa Sessione';
                }
            });
        }

        function skipRating() {
            if (playlistId && playlistTracks && currentTrackIndex < playlistTracks.length - 1) {
                navigateToTrack(currentTrackIndex + 1);
            } else {
                showStep('step-final');
            }
        }

        // CORREZIONE: Gestione più robusta del salvataggio progresso
        function saveSessionProgress() {
            const sessionNotes = document.getElementById('sessionNotes')?.value || '';
            
            if (!trackId) {
                console.warn('Nessun trackId disponibile per salvare il progresso');
                return;
            }
            
            const progressData = {
                trackId: trackId,
                playlist_id: playlistId,
                sessionNotes: sessionNotes,
                timestamp: Date.now()
            };
            
            fetch(getApiUrl('save_session_progress.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(progressData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostra feedback temporaneo
                    showTemporaryFeedback('💾 Note salvate');
                } else {
                    console.warn('Errore nel salvare le note:', data.error);
                }
            })
            .catch(error => {
                console.error('Errore nel salvare le note:', error);
            });
        }

        // CORREZIONE: Funzione helper per feedback temporaneo
        function showTemporaryFeedback(message, duration = 2000) {
            const feedback = document.createElement('div');
            feedback.textContent = message;
            feedback.style.cssText = `
                position: fixed; 
                top: 20px; 
                right: 20px; 
                background: #d1e7dd; 
                color: #0f5132; 
                padding: 12px 20px; 
                border-radius: 8px; 
                z-index: 1000; 
                font-weight: 500;
                animation: slideInRight 0.3s ease-out;
            `;
            
            document.body.appendChild(feedback);
            
            setTimeout(() => {
                if (feedback.parentNode) {
                    feedback.style.animation = 'slideOutRight 0.3s ease-in forwards';
                    setTimeout(() => feedback.remove(), 300);
                }
            }, duration);
        }

        function connectSpotifyFromSession() {
            saveSessionProgress();
            window.location.href = 'patient_dashboard.php#spotify-connect';
        }

        function updateMoodDisplay(elementId, value) {
            const displayElement = document.getElementById(elementId + 'Display');
            if (displayElement) {
                displayElement.textContent = value;
            }
        }

        // === INIZIALIZZAZIONE ===
        document.addEventListener('DOMContentLoaded', function() {
            console.log('📱 DOM caricato - inizializzo componenti');
            
            try {
                // Setup mood sliders
                ['moodBefore', 'energyBefore', 'moodAfter', 'energyAfter'].forEach(function(id) {
                    const element = document.getElementById(id);
                    if (element) {
                        element.addEventListener('input', function() {
                            updateMoodDisplay(id, this.value);
                        });
                    }
                });

                // Setup navigazione playlist
                const prevBtn = document.getElementById('prevTrackBtn');
                const nextBtn = document.getElementById('nextTrackBtn');
                
                if (prevBtn) {
                    prevBtn.addEventListener('click', function() {
                        if (currentTrackIndex > 0) {
                            navigateToTrack(currentTrackIndex - 1);
                        }
                    });
                }
                
                if (nextBtn) {
                    nextBtn.addEventListener('click', function() {
                        if (playlistTracks && currentTrackIndex < playlistTracks.length - 1) {
                            navigateToTrack(currentTrackIndex + 1);
                        }
                    });
                }
                
                // Setup rating stars
                setupRatingStars();
                
                console.log('✅ Inizializzazione completata');
                
            } catch (error) {
                console.error('❌ Errore durante l\'inizializzazione:', error);
            }
        });
        
        // === FUNZIONI FLUSSO SESSIONE ===
        function startListening() {
            try {
                // Salva i valori iniziali di mood
                const moodBefore = document.getElementById('moodBefore')?.value;
                const energyBefore = document.getElementById('energyBefore')?.value;
                
                if (!moodBefore || !energyBefore) {
                    alert('❌ Errore: valori dell\'umore non trovati');
                    return;
                }
                
                // Mostra i valori nella comparazione finale
                const moodBeforeShow = document.getElementById('moodBeforeShow');
                const energyBeforeShow = document.getElementById('energyBeforeShow');
                if (moodBeforeShow) moodBeforeShow.textContent = moodBefore;
                if (energyBeforeShow) energyBeforeShow.textContent = energyBefore;
                
                // Passa al step di ascolto
                showStep('step-listening');

                // Se abbiamo Spotify collegato e la traccia è disponibile su Spotify,
                // proviamo ad avviare la riproduzione completa sul device attivo.
                // Nota: alcuni browser bloccano l'autoplay audio, ma la chiamata
                // al backend prova a controllare il player dell'utente (Spotify Connect).
                (async () => {
                    try {
                        // Determina l'ID della traccia da usare per la riproduzione della sessione
                        let sessionTrackId = trackId;
                        if (playlistId && (!trackId || trackId === null)) {
                            if (playlistTracks && playlistTracks.length > 0) {
                                sessionTrackId = playlistTracks[0].id;
                            }
                        }

                        if (spotifyConnected && hasSpotifyTrackId && sessionTrackId) {
                            console.log('Attempting server-side Spotify playback for track', sessionTrackId);
                            const resp = await fetch(getApiUrl('spotify_playback.php'), {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'play_track', track_id: parseInt(sessionTrackId) })
                            });
                            const data = await resp.json().catch(() => ({}));
                            if (data && data.success) {
                                showNotification('🎶 Riproduzione completa avviata su Spotify', 'success');
                            } else {
                                const err = (data && data.error) ? data.error : 'Impossibile avviare la riproduzione completa';
                                console.warn('Spotify playback error:', data);
                                // Mostra messaggio specifico per Premium richiesto
                                if (err && err.toString().includes('PREMIUM_REQUIRED')) {
                                    showNotification('⚠️ Riproduzione completa non disponibile: è richiesto Spotify Premium', 'warning');
                                } else {
                                    showNotification(err, 'error');
                                }
                            }
                        } else {
                            console.log('Spotify non collegato o traccia non disponibile su Spotify — riproduzione anteprima');
                        }
                    } catch (e) {
                        console.error('Errore nel tentativo di avviare la riproduzione Spotify:', e);
                    }
                })();

                console.log('✅ Sessione di ascolto iniziata');
                
            } catch (error) {
                console.error('Errore in startListening:', error);
                alert('❌ Errore nell\'avvio della sessione');
            }
        }
        
        function showStep(stepId) {
            try {
                // Nascondi tutti gli step
                const allSteps = document.querySelectorAll('.session-step');
                allSteps.forEach(step => step.classList.remove('active'));
                
                // Mostra lo step richiesto
                const targetStep = document.getElementById(stepId);
                if (targetStep) {
                    targetStep.classList.add('active');
                    targetStep.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    console.warn('Step non trovato:', stepId);
                }
            } catch (error) {
                console.error('Errore in showStep:', error);
            }
        }

        async function saveTrackRating() {
            if (currentTrackRating === 0) {
                alert('Per favore, seleziona una valutazione per questo brano');
                return;
            }
            
            if (!trackId) {
                alert('❌ Errore: ID traccia non valido');
                return;
            }
            
            const feedbackElement = document.getElementById('trackFeedback');
            const feedback = feedbackElement ? feedbackElement.value : '';
            
            try {
                const response = await fetch(getApiUrl('save_track_rating.php'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        track_id: trackId,
                        rating: currentTrackRating,
                        feedback: feedback,
                        playlist_id: playlistId
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    // Salva la valutazione localmente
                    trackRatings[trackId] = {
                        rating: currentTrackRating,
                        feedback: feedback
                    };
                    
                    // Reset del form
                    currentTrackRating = 0;
                    highlightStars(0);
                    if (feedbackElement) {
                        feedbackElement.value = '';
                    }
                    
                    // Gestisci il flusso dei brani rimanenti 
                    if (playlistId && playlistTracks && currentTrackIndex < playlistTracks.length - 1) {
                        // Vai al prossimo brano
                        setTimeout(function() {
                            navigateToTrack(currentTrackIndex + 1);
                        }, 1000);
                    } else if (!playlistId) {
                        // Se NON è una playlist (quindi brano raccomandato singolo), carica un nuovo brano raccomandato
                        showTemporaryFeedback(' Valutazione salvata! Cerco un nuovo brano per te...', 2000);
                        setTimeout(() => {
                            fetch(getApiUrl('get_recommendations.php'))
                                .then(r => r.json())
                                .then(recData => {
                                    if (recData.success && recData.recommendations && recData.recommendations.length > 0) {
                                        // Prendi il primo brano raccomandato non ancora ascoltato
                                        // Assicurati che non sia lo stesso brano appena ascoltato
                                        let nextTrack = recData.recommendations[0];
                                        if (nextTrack.id === trackId && recData.recommendations.length > 1) {
                                            nextTrack = recData.recommendations[1];
                                        }
                                        
                                        const nextTrackId = nextTrack.id || nextTrack.track_id || nextTrack.spotify_track_id;
                                        if (nextTrackId) {
                                            // Se è un id numerico (DB), passa come ?track=ID, altrimenti come ?spotify_track=ID
                                            if (nextTrack.id || nextTrack.track_id) {
                                                window.location.href = `listening_session.php?track=${nextTrackId}`;
                                            } else if (nextTrack.spotify_track_id) {
                                                window.location.href = `listening_session.php?spotify_track=${nextTrack.spotify_track_id}`;
                                            } else {
                                                throw new Error('ID del prossimo brano non trovato.');
                                            }
                                        } else {
                                            throw new Error('ID del prossimo brano non trovato.');
                                        }
                                    } else {
                                        alert('Non ci sono altri brani raccomandati disponibili al momento. Tornerai alla dashboard.');
                                        window.location.href = 'patient_dashboard.php';
                                    }
                                })
                                .catch((err) => {
                                    console.error("Errore nel caricare nuova raccomandazione:", err);
                                    let errorMsg = 'Errore nel caricare un nuovo brano. Tornerai alla dashboard.';
                                    // Se l'errore è una Response, prova a leggerne il JSON
                                    if (err && err instanceof Response) {
                                        err.json().then(data => {
                                            if (data && data.error) {
                                                alert('Errore: ' + data.error);
                                            } else {
                                                alert(errorMsg);
                                            }
                                            window.location.href = 'patient_dashboard.php';
                                        }).catch(() => {
                                            alert(errorMsg);
                                            window.location.href = 'patient_dashboard.php';
                                        });
                                    } else if (err && err.message) {
                                        alert('Errore: ' + err.message);
                                        window.location.href = 'patient_dashboard.php';
                                    } else {
                                        alert(errorMsg);
                                        window.location.href = 'patient_dashboard.php';
                                    }
                                });
                        }, 2000);
                    } else {
                        // Vai alla valutazione finale della playlist
                        showStep('step-final');
                    }
                } else {
                    throw new Error(data.error || 'Errore sconosciuto');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('❌ Errore di connessione durante il salvataggio: ' + error.message);
            }
        }

        // Il player integrato è gestito dall'iframe Spotify embed: la riproduzione completa avviene quando l'utente usa il controllo play dentro l'iframe.

        // Aggiungi stili CSS per le animazioni
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
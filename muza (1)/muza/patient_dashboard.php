<?php
// patient_dashboard.php - Dashboard paziente con integrazione API musicali
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

$user = checkAuth('patient');

// Recupera statistiche del paziente
$stats_query = "
    SELECT 
        COUNT(*) as total_sessions,
        AVG(mood_after - mood_before) as avg_mood_improvement,
        AVG(listen_duration) as avg_duration,
        COUNT(CASE WHEN completed = 1 THEN 1 END) as completed_sessions
    FROM listening_sessions 
    WHERE user_id = {$user['id']} AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
";
$stats = $conn->query($stats_query)->fetch_assoc();

// Recupera obiettivi attivi
$goals_query = "SELECT * FROM patient_goals WHERE user_id = {$user['id']} AND status = 'active' ORDER BY created_at DESC";
$goals_result = $conn->query($goals_query);

// Recupera playlist assegnate dal terapeuta
$playlists_query = "
    SELECT tp.*, CONCAT(u.first_name, ' ', u.last_name) as therapist_name 
    FROM therapist_playlists tp 
    JOIN users u ON tp.therapist_id = u.id 
    WHERE tp.patient_id = {$user['id']} 
    ORDER BY tp.created_at DESC
";
$playlists_result = $conn->query($playlists_query);

// Recupera messaggi non letti
$messages_query = "
    SELECT COUNT(*) as unread_count 
    FROM secure_messages 
    WHERE recipient_id = {$user['id']} AND is_read = 0
";
$unread_messages = $conn->query($messages_query)->fetch_assoc()['unread_count'];

// Recupera solo i brani assegnati al paziente tramite playlist
$assigned_tracks_query = "
    SELECT DISTINCT t.*, 
           tp.name as playlist_name,
           pt.therapist_notes,
           COUNT(pt.track_id) as times_assigned
    FROM tracks t
    JOIN playlist_tracks pt ON t.id = pt.track_id
    JOIN therapist_playlists tp ON pt.playlist_id = tp.id
    WHERE tp.patient_id = {$user['id']}
    GROUP BY t.id
    ORDER BY tp.created_at DESC, pt.position ASC
";
$assigned_tracks_result = $conn->query($assigned_tracks_query);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Paziente - Mūza</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="dashboard-body">
    <div class="dashboard-wrapper">
        <div class="patient-dashboard-container">
        
        <div class="dashboard-header">
            <div>
                <h1>Ciao, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</h1>
                <p>Benvenuto nella tua dashboard di musicoterapia</p>
            </div>
            <div class="flex items-center gap-md">
                <?php if($unread_messages > 0): ?>
                    <span class="notification-badge">
                        📬 <?php echo $unread_messages; ?> messaggi
                    </span>
                <?php endif; ?>
                <a href="logout.php" class="dashboard-link">Logout</a>
            </div>
        </div>

        <div class="quick-actions">
            <button class="quick-btn" onclick="startListeningSession()">
                Inizia Sessione
            </button>
            <button class="quick-btn" onclick="showMoodTracker()">
                 Mood
            </button>
            <button class="quick-btn" onclick="viewProgress()">
                 I Miei Progressi
            </button>
            <button class="quick-btn" onclick="openMessages()">
                Messaggi
            </button>
        </div>
        
        <div class="widget">
            <h3> Brani Consigliati per Te</h3>
            <p class="text-secondary" style="margin-bottom: var(--space-4);">
                Basato sui tuoi ascolti, preferenze e su ciò che è piaciuto ad altri pazienti con gusti simili.
            </p>
            <div id="recommendations-container" class="recommendations-container">
                <p class="text-secondary">Caricamento consigli in corso...</p>
            </div>
            <div class="scroll-indicator">
                ← Scorri per vedere più consigli →
            </div>
        </div>
        <div class="dashboard-grid">
            <div class="widget">
                <h3>📊 Le Tue Statistiche (30 giorni)</h3>
                <div class="grid grid-cols-2 gap-lg text-center">
                    <div>
                        <div class="stats-number"><?php echo $stats['total_sessions'] ?: 0; ?></div>
                        <div class="text-secondary">Sessioni</div>
                    </div>
                    <div>
                        <div class="stats-number"><?php echo $stats['completed_sessions'] ?: 0; ?></div>
                        <div class="text-secondary">Completate</div>
                    </div>
                    <div>
                        <div class="stats-number"><?php echo round($stats['avg_mood_improvement'] ?: 0, 1); ?></div>
                        <div class="text-secondary">Miglioramento Umore</div>
                    </div>
                    <div>
                        <div class="stats-number"><?php echo round(($stats['avg_duration'] ?: 0) / 60, 1); ?></div>
                        <div class="text-secondary">Min Medi</div>
                    </div>
                </div>
            </div>

            <div class="widget">
                <h3>🎵 Le Tue Playlist</h3>
                <div class="playlists-container">
                    <?php if ($playlists_result && $playlists_result->num_rows > 0): ?>
                        <?php while ($playlist = $playlists_result->fetch_assoc()): ?>
                            <div class="playlist-card">
                                <div class="playlist-info">
                                    <h4><?php echo htmlspecialchars($playlist['name']); ?></h4>
                                    <p class="text-secondary">
                                        Creata da: <?php echo htmlspecialchars($playlist['therapist_name']); ?>
                                    </p>
                                    <?php if ($playlist['description']): ?>
                                        <p class="text-sm"><?php echo htmlspecialchars($playlist['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="playlist-actions">
                                    <button onclick="viewPlaylistTracks(<?php echo $playlist['id']; ?>, '<?php echo htmlspecialchars($playlist['name']); ?>')" class="btn-secondary">
                                        👁️ Visualizza
                                    </button>
                                    <button onclick="startPlaylistSession(<?php echo $playlist['id']; ?>)" class="btn-primary">
                                        ▶️ Ascolta
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>📭 Nessuna playlist assegnata</p>
                            <p class="text-secondary">Il tuo terapeuta non ha ancora creato playlist per te.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="widget">
                <h3>🎯 I Tuoi Obiettivi</h3>
                <?php if($goals_result && $goals_result->num_rows > 0): ?>
                    <?php while($goal = $goals_result->fetch_assoc()): ?>
                        <div class="mb-lg">
                            <div class="font-semibold text-primary">
                                <?php echo ucfirst(str_replace('_', ' ', $goal['goal_type'])); ?>
                            </div>
                            <div class="text-sm text-secondary mt-xs mb-md">
                                <?php echo htmlspecialchars($goal['goal_description']); ?>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo min(100, ($goal['current_progress'] / $goal['target_value']) * 100); ?>%"></div>
                            </div>
                            <div class="text-sm text-muted mt-xs">
                                <?php echo $goal['current_progress']; ?>/<?php echo $goal['target_value']; ?> completato
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="text-secondary">
                        Nessun obiettivo attivo. 
                        <a href="#" onclick="showDevPopup(); return false;" class="text-purple">Crea il tuo primo obiettivo!</a>
                    </p>
                <?php endif; ?>
            </div>

            <div class="widget">
                <h3>🎼 I Tuoi Brani Assegnati</h3>
                <p class="text-secondary" style="margin-bottom: var(--space-4); font-style: italic;">
                    Brani selezionati dal tuo terapeuta specificamente per te
                </p>
                
                <div id="assignedTracksList" style="max-height: 400px; overflow-y: auto;">
                    <?php if($assigned_tracks_result && $assigned_tracks_result->num_rows > 0): ?>
                        <?php while($track = $assigned_tracks_result->fetch_assoc()): ?>
                            <div class="assigned-track-item bg-light rounded-lg p-lg mb-md hover-lift">
                                <div class="track-header">
                                    <div class="font-semibold text-primary">
                                        🎵 <?php echo htmlspecialchars($track['title']); ?>
                                        <?php if($track['explicit']): ?>
                                            <span class="explicit-badge">E</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-sm text-secondary mt-xs">
                                        👤 <?php echo htmlspecialchars($track['artist']); ?>
                                        <?php if($track['album']): ?>
                                            • 💿 <?php echo htmlspecialchars($track['album']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if($track['therapist_notes']): ?>
                                    <div class="therapist-note" style="background: rgba(139, 92, 246, 0.1); padding: var(--space-3); border-radius: var(--radius-md); margin: var(--space-3) 0; border-left: 3px solid var(--primary-purple);">
                                        <strong>💬 Nota del terapeuta:</strong><br>
                                        <em><?php echo htmlspecialchars($track['therapist_notes']); ?></em>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="track-meta" style="display: flex; justify-content: space-between; align-items: center; margin-top: var(--space-3);">
                                    <div class="track-info">
                                        <span class="text-xs text-muted">
                                            📁 Playlist: <?php echo htmlspecialchars($track['playlist_name']); ?>
                                            <?php if($track['category']): ?>
                                                • 🏷️ <?php echo htmlspecialchars($track['category']); ?>
                                            <?php endif; ?>
                                            <?php if($track['mood_target']): ?>
                                                • 🎭 <?php echo htmlspecialchars($track['mood_target']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="track-actions">
                                        <button onclick="startSingleTrackSession(<?php echo $track['id']; ?>)" 
                                                class="btn-primary text-sm" style="padding: var(--space-2) var(--space-4);">
                                            🎧 Ascolta
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>📭 Nessun brano assegnato specificamente</p>
                            <p class="text-secondary">Il tuo terapeuta non ha ancora assegnato brani individuali per te. Controlla le tue playlist per i brani disponibili.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="widget">
                <h3>🎵 Connessione Spotify</h3>
                <div id="spotify-status-container">
                    <p id="connection-status-text">Verificando connessione...</p>
                    <div id="spotify-not-connected" style="display: none;">
                        <p class="text-secondary mb-lg">
                            Collega il tuo account Spotify per ascoltare i brani completi direttamente nell'applicazione con il nostro player integrato.
                        </p>
                        <p class="text-sm text-muted mb-lg">
                            ✨ <strong>Vantaggi del player integrato:</strong><br>
                            • Ascolto senza uscire dall'app<br>
                            • Controlli integrati nella sessione<br>
                            • Tracciamento preciso del progresso<br>
                            • Qualità audio completa Spotify
                        </p>
                        <button onclick="connectPatientSpotify()" class="btn-primary">
                            🔗 Connetti Spotify
                        </button>
                    </div>
                    <div id="spotify-connected" style="display: none;">
                        <p class="text-success mb-lg">
                            ✅ Account Spotify collegato! 
                        </p>
                        <div class="spotify-features mb-lg">
                            <p class="text-sm text-secondary mb-sm">
                                🎵 <strong>Player integrato attivo:</strong>
                            </p>
                            <ul class="text-sm text-secondary" style="margin-left: 1rem;">
                                <li>• Riproduzione diretta nell'app</li>
                                <li>• Controllo volume e progresso</li>
                                <li>• Qualità audio completa</li>
                                <li>• Tracciamento sessioni avanzato</li>
                            </ul>
                        </div>
                        <div class="spotify-actions">
                            <button onclick="disconnectPatientSpotify()" class="btn-warning text-sm">
                                🔌 Disconnetti Spotify
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
    /* Modal centrato e responsive */
    #playlist-modal.modal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0,0,0,0.25);
        justify-content: center;
        align-items: center;
        overflow-y: auto;
    }
    #playlist-modal .modal-content {
        background: #fff;
        border-radius: 24px;
        max-width: 420px;
        width: 95vw;
        margin: 40px auto;
        box-shadow: 0 8px 32px rgba(60,30,90,0.18);
        padding: 2.5rem 1.5rem 1.5rem 1.5rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        position: relative;
        animation: modalIn 0.18s cubic-bezier(.4,1.4,.6,1) 1;
    }
    @keyframes modalIn {
        from { transform: translateY(40px) scale(0.98); opacity: 0; }
        to { transform: none; opacity: 1; }
    }
    #playlist-modal .modal-header {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.2rem;
    }
    #playlist-modal .modal-title {
        font-size: 1.3rem;
        font-weight: 700;
        margin: 0;
    }
    #playlist-modal .close-modal {
        background: #7c5ae0;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 1.5rem;
        width: 36px;
        height: 36px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s;
    }
    #playlist-modal .close-modal:hover {
        background: #5a3bb5;
    }
    #playlist-modal #playlist-tracks-container {
        width: 100%;
        margin-bottom: 1.5rem;
    }
    #playlist-modal .playlist-info-detail {
        text-align: center;
        margin-bottom: 1.2rem;
    }
    #playlist-modal .playlist-info-detail img {
        display: block;
        margin: 0.5rem auto 1rem auto;
        border-radius: 16px;
        max-width: 220px;
        width: 100%;
        box-shadow: 0 2px 12px rgba(60,30,90,0.10);
    }
    #playlist-modal .tracks-list {
        margin-top: 1.2rem;
    }
    #playlist-modal .track-item-simple {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.1rem;
        background: #f7f5ff;
        border-radius: 12px;
        padding: 0.7rem 1rem;
    }
    #playlist-modal .track-thumb, #playlist-modal .track-thumb-placeholder {
        width: 54px;
        height: 54px;
        border-radius: 10px;
        object-fit: cover;
        background: #e5e1fa;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
    }
    #playlist-modal .track-info-simple {
        flex: 1;
        min-width: 0;
    }
    #playlist-modal .track-title-simple {
        font-weight: 600;
        font-size: 1rem;
        color: #3a2a5d;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #playlist-modal .track-artist-simple {
        font-size: 0.95rem;
        color: #7c5ae0;
        margin-top: 0.1rem;
    }
    #playlist-modal .track-duration-simple {
        font-size: 0.85rem;
        color: #b0a6d6;
        margin-top: 0.1rem;
    }
    #playlist-modal .modal-actions {
        width: 100%;
        display: flex;
        justify-content: flex-end;
        gap: 0.7rem;
        margin-top: 1.2rem;
    }
    @media (max-width: 600px) {
        #playlist-modal .modal-content {
            max-width: 99vw;
            padding: 1.2rem 0.2rem 1.2rem 0.2rem;
        }
        #playlist-modal .playlist-info-detail img {
            max-width: 98vw;
        }
    }
    </style>
    <div id="playlist-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="playlist-modal-title">Playlist</h3>
                <button class="close-modal" onclick="closeModal('playlist-modal')">&times;</button>
            </div>
            <div id="playlist-tracks-container"></div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('playlist-modal')">Chiudi</button>
                <button type="button" class="btn-primary" id="start-playlist-btn" onclick="startCurrentPlaylist()">▶️ Inizia Sessione Playlist</button>
            </div>
        </div>
    </div>

    <style>
        /* Center mood modal vertically and horizontally */
        #moodModal.modal {
            display: none;
            position: fixed;
            z-index: 1100;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            -webkit-box-align: center; -ms-flex-align: center; align-items: center;
            -webkit-box-pack: center; -ms-flex-pack: center; justify-content: center;
            padding: 1rem;
        }

        #moodModal .modal-content {
            margin: 0;
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
        }
        /* Header: title left, close button right on single line */
        #moodModal .modal-header {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        #moodModal .modal-header h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
        }
        #moodModal .close-modal {
            background: #7c5ae0;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1.2rem;
            width: 36px;
            height: 36px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }
        #moodModal .close-modal:hover {
            background: #5a3bb5;
        }
    </style>

    <div id="moodModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3> Come ti senti oggi?</h3>
                <button class="close-modal" onclick="closeModal('moodModal')">&times;</button>
            </div>
            
            <form onsubmit="saveMoodCheck(event)">
                <div class="form-group">
                    <label class="form-label">Umore (1-10)</label>
                    <input type="range" id="currentMood" min="1" max="10" value="5" oninput="updateMoodDisplay()">
                    <div class="mood-display">
                        <span id="moodValue">5</span>/10
                        <span id="moodEmoji">😐</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Energia (1-10)</label>
                    <input type="range" id="currentEnergy" min="1" max="10" value="5" oninput="updateEnergyDisplay()">
                    <div class="energy-display">
                        <span id="energyValue">5</span>/10
                        <span id="energyEmoji">⚡</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Note (opzionali)</label>
                    <textarea id="moodNotes" placeholder="Come ti senti? Cosa ti preoccupa o ti rende felice?"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('moodModal')">Annulla</button>
                    <button type="submit" class="btn-primary">Salva Mood</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Variabili globali
        let currentPlaylistId = null;
        let currentPlaylistTracks = [];

        // Inizializzazione
        document.addEventListener('DOMContentLoaded', async function() {
            // Aggiorna i display iniziali
            updateMoodDisplay();
            updateEnergyDisplay();
            
            // Verifica stato connessione Spotify
            const isConnected = await checkPatientSpotifyConnection();

            // Carica raccomandazioni unificate (Spotify + collaborative) SOLO se connesso
            if (isConnected) {
                loadUnifiedRecommendations();
            } else {
                const container = document.getElementById('recommendations-container');
                if (container) container.innerHTML = '<div class="recommendation-card" style="flex: 1; min-width: 300px;"><p class="text-secondary">Collega Spotify per ricevere consigli personalizzati.</p></div>';
                const scrollIndicator = document.querySelector('.scroll-indicator');
                if (scrollIndicator) scrollIndicator.style.display = 'none';
            }
            // Il popup di sviluppo viene mostrato solo cliccando sul link "Crea il tuo primo obiettivo!"
    
        // FUNZIONI RACCOMANDAZIONI UNIFICATE (Spotify + collaborative) e carica i brani assegnati tramite playlist
        // ================================================
        async function loadUnifiedRecommendations() {
            const container = document.getElementById('recommendations-container');
            container.innerHTML = '<p class="text-secondary">Caricamento consigli in corso...</p>';
            try {
                // Chiamate parallele
                const [spotifyRes, collabRes] = await Promise.all([
                    fetch('api/get_recommendations.php'),
                    fetch('api/collaborative_filtering_recommendations.php')
                ]);
                const spotifyData = await spotifyRes.json();
                const collabData = await collabRes.json();

                let allRecs = [];
                if (spotifyData.success && Array.isArray(spotifyData.recommendations)) {
                    allRecs = allRecs.concat(spotifyData.recommendations);
                }
                if (collabData.success && Array.isArray(collabData.recommendations)) {
                    // Evita duplicati (stesso spotify_track_id o id)
                    const existingIds = new Set(allRecs.map(t => t.spotify_track_id || t.id));
                    collabData.recommendations.forEach(t => {
                        const tid = t.spotify_track_id || t.id;
                        if (!existingIds.has(tid)) {
                            allRecs.push(t);
                        }
                    });
                }
//
                if (allRecs.length > 0) {
                    displayRecommendations(allRecs);
                } else {
                    container.innerHTML = '<div class="recommendation-card" style="flex: 1; min-width: 300px;"><p class="text-secondary">Non abbiamo ancora abbastanza dati per fornirti dei consigli. Inizia ad ascoltare e valutare alcuni brani!</p></div>';
                }
            } catch (error) {
                console.error('Errore nel caricamento delle raccomandazioni:', error);
                container.innerHTML = '<div class="recommendation-card" style="flex: 1; min-width: 300px;"><p class="text-secondary">Errore nel caricare i consigli. Riprova più tardi.</p></div>';
            }
        }
            
            // Controlla se c'è un messaggio di successo dalla sessione PHP
            <?php if (isset($_SESSION['success'])): ?>
                showNotification('<?php echo addslashes($_SESSION['success']); ?>', 'success');
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                showNotification('<?php echo addslashes($_SESSION['error']); ?>', 'error');
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
        });

        // ================================================
        // FUNZIONI RACCOMANDAZIONI - INIZIO NUOVA SEZIONE
        // ================================================
        async function loadRecommendations() {
            const container = document.getElementById('recommendations-container');
            try {
                const response = await fetch('api/get_recommendations.php');
                const data = await response.json();

                if (data.success && data.recommendations.length > 0) {
                    displayRecommendations(data.recommendations);
                } else {
                    container.innerHTML = '<div class="recommendation-card" style="flex: 1; min-width: 300px;"><p class="text-secondary">Non abbiamo ancora abbastanza dati per fornirti dei consigli. Inizia ad ascoltare e valutare alcuni brani!</p></div>';
                    // Nascondi indicatore scorrimento
                    const scrollIndicator = document.querySelector('.scroll-indicator');
                    if (scrollIndicator) scrollIndicator.style.display = 'none';
                }
            } catch (error) {
                console.error('Errore nel caricamento delle raccomandazioni:', error);
                container.innerHTML = '<div class="recommendation-card" style="flex: 1; min-width: 300px;"><p class="text-secondary">Errore nel caricare i consigli. Riprova più tardi.</p></div>';
                // Nascondi indicatore scorrimento
                const scrollIndicator = document.querySelector('.scroll-indicator');
                if (scrollIndicator) scrollIndicator.style.display = 'none';
            }
        }

        function displayRecommendations(recommendations) {
            const container = document.getElementById('recommendations-container');
            container.innerHTML = ''; // Pulisci il container

            recommendations.forEach(track => {
                // Usa sempre l'ID numerico reale della traccia per il link
                const trackId = track.id || track.track_id || track.spotify_track_id;
                const trackCard = document.createElement('div');
                trackCard.className = 'recommendation-card';
                // Sostituisci la frase per i consigli collaborativi (match più robusto)
                let reasonHtml = '';
                if (track.reason) {
                    let customReason = track.reason;
                    const reasonNorm = customReason.toLowerCase();
                    if (
                        reasonNorm.includes('altri pazienti') &&
                        reasonNorm.includes('gusti') &&
                        (reasonNorm.includes('piaciuto') || reasonNorm.includes('apprezzato'))
                    ) {
                        customReason = 'Altri pazienti con gusti simili hanno apprezzato questo brano';
                    }
                    reasonHtml = `<div class="recommendation-reason" style="color:#7c5ae0; font-style:italic; margin-top:6px;">${customReason}</div>`;
                }
                trackCard.innerHTML = `
                    <img src="${track.image_url || track.image || 'img/placeholder.png'}" alt="Copertina" />
                    <h3>${track.title || track.name}</h3>
                    <p>${track.artist}</p>
                    ${reasonHtml}
                    <div class="recommendation-actions">
                        <button class="btn-primary" onclick="window.location.href='listening_session.php?track=${trackId}&is_recommendation=true'">Ascolta</button>
                    </div>
                `;
                container.appendChild(trackCard);
            });
            
            // Mostra indicatore scorrimento solo se ci sono più di 3 consigli
            const scrollIndicator = document.querySelector('.scroll-indicator');
            if (scrollIndicator) {
                scrollIndicator.style.display = recommendations.length > 3 ? 'block' : 'none';
            }
        }
        // ================================================
        // FUNZIONI RACCOMANDAZIONI - FINE NUOVA SEZIONE
        // ================================================

        // === GESTIONE PLAYLIST ===
        async function viewPlaylistTracks(playlistId, playlistName) {
            currentPlaylistId = playlistId;
            document.getElementById('playlist-modal-title').textContent = playlistName;
            
            try {
                const response = await fetch(`api/get_playlist_tracks.php?playlist_id=${playlistId}`);
                const data = await response.json();
                
                if (data.success) {
                    currentPlaylistTracks = data.tracks;
                    displayPlaylistTracks(data.tracks, data.playlist);
                    document.getElementById('playlist-modal').style.display = 'block';
                } else {
                    alert(`❌ Errore: ${data.error}`);
                }
            } catch (error) {
                console.error('Errore caricamento tracce playlist:', error);
                alert('❌ Errore durante il caricamento della playlist');
            }
        }

        function displayPlaylistTracks(tracks, playlistInfo) {
            const container = document.getElementById('playlist-tracks-container');
            
            if (tracks.length === 0) {
                container.innerHTML = '<p class="no-results">📭 Nessuna traccia in questa playlist</p>';
                return;
            }
            
            let html = `
                <div class="playlist-info-detail">
                    <p><strong>Creata da:</strong> ${playlistInfo.therapist_name}</p>
                    ${playlistInfo.description ? `<p><strong>Descrizione:</strong> ${playlistInfo.description}</p>` : ''}
                    <p><strong>Brani:</strong> ${tracks.length}</p>
                </div>
                <div class="tracks-list">
            `;
            
            tracks.forEach((track, index) => {
                html += `
                    <div class="track-item-simple">
                        ${track.image_url ? 
                            `<img src="${track.image_url}" alt="${track.title}" class="track-thumb">` : 
                            '<div class="track-thumb-placeholder">🎵</div>'
                        }
                        <div class="track-info-simple">
                            <div class="track-title-simple">
                                ${index + 1}. ${track.title}
                                ${track.explicit ? '<span class="explicit-badge">E</span>' : ''}
                            </div>
                            <div class="track-artist-simple">${track.artist}</div>
                            <div class="track-duration-simple">${track.duration_formatted}</div>
                        </div>
                        <div class="track-actions-simple">
                            ${track.preview_url ? 
                                `<button onclick="playPreview('${track.preview_url}')" class="btn-preview-small">▶️</button>` : 
                                ''
                            }
                            <button onclick="startSingleTrackSession(${track.id})" class="btn-primary-small">Ascolta</button>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }

        function startCurrentPlaylist() {
            if (currentPlaylistId) {
                startPlaylistSession(currentPlaylistId);
                closeModal('playlist-modal');
            }
        }

        function startPlaylistSession(playlistId) {
            // Reindirizza alla sessione di ascolto con playlist
            window.location.href = `listening_session.php?playlist=${playlistId}`;
        }

        /**
         * Avvia sessione per un singolo brano assegnato
         */
        function startSingleTrackSession(trackId) {
            // Reindirizza alla sessione di ascolto con traccia singola
            window.location.href = `listening_session.php?track=${trackId}`;
        }

        // === MOOD TRACKER ===
        function showMoodTracker() {
            const m = document.getElementById('moodModal');
            if (m) m.style.display = 'flex';
        }

        function updateMoodDisplay() {
            const value = document.getElementById('currentMood').value;
            document.getElementById('moodValue').textContent = value;
            
            const emojis = ['😢', '😢', '😔', '😔', '😐', '😐', '🙂', '🙂', '😊', '😁'];
            document.getElementById('moodEmoji').textContent = emojis[value - 1];
        }

        function updateEnergyDisplay() {
            const value = document.getElementById('currentEnergy').value;
            document.getElementById('energyValue').textContent = value;
            
            const emojis = ['😴', '😴', '😴', '⚡', '⚡', '⚡', '⚡⚡', '⚡⚡', '⚡⚡⚡', '🔥'];
            document.getElementById('energyEmoji').textContent = emojis[value - 1];
        }

        async function saveMoodCheck(event) {
            event.preventDefault();
            
            const mood = document.getElementById('currentMood').value;
            const energy = document.getElementById('currentEnergy').value;
            const notes = document.getElementById('moodNotes').value;
            
            try {
                const response = await fetch('api/save_mood_check.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        mood: parseInt(mood),
                        energy: parseInt(energy),
                        notes: notes
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('✅ Mood salvato con successo!');
                    closeModal('moodModal');
                    // Ricarica la pagina per aggiornare le statistiche
                    location.reload();
                } else {
                    alert(`❌ Errore: ${data.error || 'Errore sconosciuto'}`);
                }
            } catch (error) {
                console.error('Errore salvataggio mood:', error);
                alert('❌ Errore durante il salvataggio');
            }
        }

        // === UTILITY FUNCTIONS ===
        function startListeningSession() {
            window.location.href = 'listening_session.php';
        }

        function viewProgress() {
            // TODO: Implementare pagina progressi
            alert('Funzionalità in sviluppo');
        }

        function showDevPopup() {
            alert('Ambiente in fase di sviluppo');
        }

        function openMessages() {
            // TODO: Implementare sistema messaggi
            alert('Funzionalità in sviluppo');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Player anteprima
        let currentAudio = null;
        function playPreview(url) {
            if (currentAudio) {
                currentAudio.pause();
            }
            
            currentAudio = new Audio(url);
            currentAudio.play().catch(error => {
                console.error('Errore riproduzione:', error);
                alert('❌ Impossibile riprodurre l\'anteprima');
            });
            
            setTimeout(() => {
                if (currentAudio) {
                    currentAudio.pause();
                }
            }, 30000);
        }

        // Chiudi modal cliccando fuori
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }        

        /* ========================================
           GESTIONE SPOTIFY PER PAZIENTE
           ======================================== */
        
        // Configurazione Spotify
        const SPOTIFY_CLIENT_ID = '49240058547c408e84cbf72206a02101';
        const CALLBACK_URL = <?php echo json_encode(defined('SPOTIFY_CALLBACK_BASE') ? SPOTIFY_CALLBACK_BASE : 'https://horrent-sharda-heeled.ngrok-free.dev/muza/callback.php'); ?>;        
        /**
         * Verifica stato connessione Spotify del paziente - versione migliorata
         */
        async function checkPatientSpotifyConnection() {
            try {
                const response = await fetch('api/patient_spotify_connection.php');
                const data = await response.json();

                if (data.success && data.spotify_connected) {
                    showSpotifyConnected();
                    return true;
                } else {
                    showSpotifyNotConnected();
                    return false;
                }

            } catch (error) {
                console.error('Errore verifica connessione Spotify:', error);
                const statusEl = document.getElementById('connection-status-text');
                if (statusEl) statusEl.textContent = '❌ Errore verifica connessione';
                showSpotifyNotConnected();
                return false;
            }
        }

        /**
         * Mostra interfaccia quando Spotify è connesso
         */
        function showSpotifyConnected() {
            document.getElementById('connection-status-text').textContent = '✅ Spotify Connesso';
            document.getElementById('spotify-not-connected').style.display = 'none';
            document.getElementById('spotify-connected').style.display = 'block';
        }

        /**
         * Mostra interfaccia quando Spotify NON è connesso
         */
        function showSpotifyNotConnected() {
            document.getElementById('connection-status-text').textContent = '❌ Spotify Non Connesso';
            document.getElementById('spotify-connected').style.display = 'none';
            document.getElementById('spotify-not-connected').style.display = 'block';
        }        
        /**
         * Connetti account Spotify paziente - versione migliorata
         */
        async function connectPatientSpotify() {
            try {
                // Mostra loading
                const connectBtn = document.querySelector('#spotify-not-connected button');
                const originalText = connectBtn.textContent;
                connectBtn.textContent = '🔄 Connessione in corso...';
                connectBtn.disabled = true;
                
                // Ottieni URL di autorizzazione dalla nuova API
                const response = await fetch('api/patient_spotify_connection.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'connect' })
                });
                
                const data = await response.json();
                
                if (data.success && data.auth_url) {
                    // Reindirizza a Spotify per l'autorizzazione
                    window.location.href = data.auth_url;
                } else {
                    throw new Error(data.error || 'Errore nella generazione URL di autorizzazione');
                }
                
            } catch (error) {
                console.error('Errore connessione Spotify:', error);
                alert('❌ Errore nella connessione con Spotify: ' + error.message);
                
                // Ripristina il pulsante
                const connectBtn = document.querySelector('#spotify-not-connected button');
                connectBtn.textContent = '🔗 Connetti Spotify';
                connectBtn.disabled = false;
            }
        }
        
        /**
         * Disconnetti account Spotify paziente
         */
        async function disconnectPatientSpotify() {
            if (!confirm('🔌 Sei sicuro di voler disconnettere il tuo account Spotify?')) {
                return;
            }
            
            try {
                // Mostra loading
                const disconnectBtn = document.querySelector('#spotify-connected .btn-warning');
                const originalText = disconnectBtn.textContent;
                disconnectBtn.textContent = '🔄 Disconnessione...';
                disconnectBtn.disabled = true;
                
                const response = await fetch('api/patient_spotify_connection.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'disconnect' })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Aggiorna interfaccia
                    showSpotifyNotConnected();
                    
                    // Mostra messaggio di successo
                    showNotification('✅ Account Spotify disconnesso con successo', 'success');
                } else {
                    throw new Error(data.error || 'Errore nella disconnessione');
                }
                
            } catch (error) {
                console.error('Errore disconnessione Spotify:', error);
                alert('❌ Errore nella disconnessione: ' + error.message);
                
                // Ripristina il pulsante
                const disconnectBtn = document.querySelector('#spotify-connected .btn-warning');
                disconnectBtn.textContent = '🔌 Disconnetti Spotify';
                disconnectBtn.disabled = false;
            }        }

        /**
         * Mostra notifica temporanea
         */
        function showNotification(message, type = 'info') {
            // Crea elemento notifica se non esiste
            let notification = document.getElementById('notification');
            if (!notification) {
                notification = document.createElement('div');
                notification.id = 'notification';
                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 1rem 1.5rem;
                    border-radius: 8px;
                    color: white;
                    font-weight: 500;
                    z-index: 9999;
                    transform: translateX(400px);
                    transition: transform 0.3s ease;
                `;
                document.body.appendChild(notification);
            }
            
            // Imposta stile in base al tipo - mantieni errore identico a .error in CSS
            if (type === 'error') {
                notification.style.backgroundColor = '#5b21b6'; /* come .error */
                notification.style.border = '1px solid #4c1d95';
                notification.style.color = 'white';
                notification.style.minHeight = '48px';
                notification.style.padding = '0.75rem 1.25rem';
                notification.style.fontWeight = '600';
                notification.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
            } else {
                const colors = {
                    success: '#10b981',
                    warning: '#f59e0b',
                    info: '#3b82f6'
                };
                notification.style.backgroundColor = colors[type] || colors.info;
                notification.style.color = 'white';
                notification.style.border = 'none';
                notification.style.boxShadow = '0 2px 6px rgba(0,0,0,0.08)';
            }

            notification.textContent = message;

            // Mostra notifica
            notification.style.transform = 'translateX(0)';
            // Nascondi dopo 4 secondi
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
            }, 4000);
        }
    </script>
</body>
</html>
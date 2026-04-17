<?php
/**
 * MUSIC LIBRARY & PLAYLIST MANAGEMENT
 * 
 * Gestione completa della libreria musicale e delle playlist per terapeuti.
 * Funzionalità principali:
 * - Ricerca e aggiunta brani da Spotify alla libreria personale
 * - Creazione e gestione playlist terapeutiche
 * - Visualizzazione brani organizzati per playlist
 * - Integrazione OAuth Spotify con refresh automatico token
 * 
 * @author Sistema Mūza
 * @version 2.0
 * @since 2024
 */

session_start();
require_once 'includes/db.php';

// === CONTROLLO AUTENTICAZIONE ===
if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'therapist') {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];

// === VERIFICA CONNESSIONE SPOTIFY ===
// Controlla se esiste un token Spotify valido e non scaduto
$spotify_query = "
    SELECT access_token, expires_at 
    FROM user_music_tokens 
    WHERE user_id = {$user['id']} AND service = 'spotify'
    AND expires_at > NOW()
";
$spotify_result = $conn->query($spotify_query);
$spotify_connected = $spotify_result && $spotify_result->num_rows > 0;

// === CARICAMENTO DATI LIBRERIA ===
// Recupera tutti i brani aggiunti dal terapeuta o condivisi
$tracks_query = "
    SELECT * FROM tracks 
    WHERE therapist_id = {$user['id']} OR therapist_id IS NULL
    ORDER BY created_at DESC
";
$tracks_result = $conn->query($tracks_query);

// === CARICAMENTO PLAYLIST TERAPEUTA ===
// Recupera playlist con conteggio brani e informazioni paziente assegnato
$playlists_query = "
    SELECT p.*, 
           COUNT(pt.track_id) as track_count,
           CONCAT(u.first_name, ' ', u.last_name) as patient_name
    FROM therapist_playlists p 
    LEFT JOIN playlist_tracks pt ON p.id = pt.playlist_id
    LEFT JOIN users u ON p.patient_id = u.id
    WHERE p.therapist_id = {$user['id']} 
    GROUP BY p.id
    ORDER BY p.created_at DESC
";
$playlists_result = $conn->query($playlists_query);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libreria Musicale e Playlist - Mūza</title>
    <link rel="stylesheet" href="css/style.css">
    
    <!-- ================================
         STILI SPECIFICI MUSIC LIBRARY
         ================================ -->
    <style>
        /* ========================================
           MUSIC LIBRARY - LAYOUT PRINCIPALE
           ======================================== */
        .music-library {
            max-width: 1400px;
            margin: 0 auto;
            padding: var(--space-6);
        }

        /* === NAVIGAZIONE TAB === */
        .library-tabs {
            display: flex;
            gap: var(--space-2);
            margin-bottom: var(--space-6);
            border-bottom: 2px solid var(--border-light);
        }
        
        /* === STILI TAB NAVIGATION === */
        .tab-button {
            padding: var(--space-3) var(--space-6);
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all var(--transition-fast);
        }

        .tab-button.active {
            color: var(--primary-purple);
            border-bottom-color: var(--primary-purple);
        }

        .tab-button:hover {
            color: var(--text-primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }        /* ========================================
           SEZIONE CONNESSIONE SPOTIFY
           ======================================== */
        .connection-status {
            background: var(--bg-light);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            margin-bottom: var(--space-6);
            text-align: center;
        }

        .connection-status.connected {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .connection-status.disconnected {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* Layout per stato connesso */
        .spotify-status-connected {
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-align: left;
        }

        .status-info h2 {
            margin-bottom: var(--space-2);
        }

        .status-info p {
            margin: 0;
            color: var(--text-secondary);
        }

        .status-actions {
            flex-shrink: 0;
        }

        /* Pulsante disconnessione */
        .disconnect-btn {
            background: #ef4444;
            color: white;
            border: none;
            padding: var(--space-3) var(--space-5);
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .disconnect-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        .disconnect-btn:active {
            transform: translateY(0);
        }

        .disconnect-btn.loading {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Responsive per dispositivi mobili */
        @media (max-width: 768px) {
            .spotify-status-connected {
                flex-direction: column;
                gap: var(--space-4);
                text-align: center;
            }
            
            .status-actions {
                width: 100%;
            }
            
            .disconnect-btn {
                width: 100%;
            }
        }

        /* ========================================
           SEZIONE RICERCA BRANI
           ======================================== */
        .search-section {
            background: var(--bg-light);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            margin-bottom: var(--space-6);
        }

        .search-header {
            margin-bottom: var(--space-4);
        }
        
        /* === FORM RICERCA === */
        .search-form {
            display: flex;
            gap: var(--space-3);
            margin-bottom: var(--space-4);
        }

        .search-input {
            flex: 1;
            padding: var(--space-3);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 1rem;
        }

        .search-btn {
            padding: var(--space-3) var(--space-6);
            background: var(--primary-purple);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .search-btn:hover {
            background: var(--purple);
        }

        .search-btn:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
        }

        /* ========================================
           RISULTATI RICERCA E BRANI
           ======================================== */
        .results-grid {
            display: grid;
            gap: var(--space-4);
        }
        
        /* === TRACK ITEMS === */
        .track-item {
            display: flex;
            align-items: center;
            padding: var(--space-4);
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            transition: all var(--transition-fast);
        }

        .track-item:hover {
            background: var(--light-purple);
            border-color: var(--primary-purple);
        }

        .track-image {
            width: 80px;
            height: 80px;
            border-radius: var(--radius-md);
            object-fit: cover;
            margin-right: var(--space-4);
        }

        .track-placeholder {
            width: 80px;
            height: 80px;
            border-radius: var(--radius-md);
            background: var(--bg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-right: var(--space-4);
        }

        .track-info {
            flex: 1;
        }

        .track-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: var(--space-1);
        }

        .track-artist {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: var(--space-2);
        }

        .track-meta {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .track-actions {
            display: flex;
            gap: var(--space-2);
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        /* ========================================
           BOTTONI E AZIONI
           ======================================== */
        .action-btn {
            padding: var(--space-2) var(--space-4);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        
        /* === BOTTONI SPECIFICI === */
        .btn-preview {
            background: var(--bg-light);
            color: var(--text-primary);
            border: 1px solid var(--border-light);
        }

        .btn-preview:hover {
            background: var(--light-purple);
        }

        .btn-add {
            background: var(--primary-purple);
            color: white;
        }

        .btn-add:hover {
            background: var(--purple);
        }

        .btn-added {
            background: #10b981;
            color: white;
            cursor: not-allowed;
            opacity: 0.8;
        }

        .btn-playlist {
            background: #f59e0b;
            color: white;
        }

        .btn-playlist:hover {
            background: #d97706;
        }

        .btn-add-playlist {
            background: var(--primary-purple);
            color: white;
            border: 1px solid var(--primary-purple);
        }

        .btn-add-playlist:hover {
            background: rgba(139, 92, 246, 0.8);
        }

        /* ========================================
           SEZIONE PLAYLIST
           ======================================== */
        .playlist-section {
            background: var(--bg-light);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
        }

        .playlist-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-6);
        }

        .playlist-actions {
            display: flex;
            gap: var(--space-3);
            flex-wrap: wrap;
            margin-top: var(--space-3);
        }

        .playlist-actions button {
            transition: all var(--transition-fast);
        }

        .playlist-actions button:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* === BOTTONE CREA PLAYLIST === */
        .create-playlist-btn {
            padding: var(--space-3) var(--space-6);
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-purple) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-lg);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all var(--transition-fast);
            box-shadow: 0 4px 6px -1px rgba(139, 92, 246, 0.3);
            position: relative;
            overflow: hidden;
        }

        .create-playlist-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .create-playlist-btn:hover {
            background: linear-gradient(135deg, var(--accent-purple) 0%, var(--primary-purple) 100%);
            box-shadow: 0 8px 15px -3px rgba(139, 92, 246, 0.4);
        }

        .create-playlist-btn:hover::before {
            left: 100%;
        }

        .create-playlist-btn:active {
            box-shadow: 0 4px 6px -1px rgba(139, 92, 246, 0.3);
        }

        /* === GRID E CARD PLAYLIST === */
        .playlist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: var(--space-4);
        }

        .playlist-card {
            background: var(--bg-secondary);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            transition: all var(--transition-fast);
        }

        .playlist-card:hover {
            border-color: var(--primary-purple);
        }

        .playlist-name {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }

        .playlist-description {
            color: var(--text-secondary);
            margin-bottom: var(--space-3);
        }

        .playlist-meta {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: var(--space-4);
        }

        /* ========================================
           SEZIONE LIBRERIA BRANI
           ======================================== */        .library-description {
            color: var(--text-secondary);
            font-size: var(--text-base);
            margin-bottom: var(--space-6);
            text-align: center;
            font-style: italic;
        }

        /* GRIGLIA LIBRERIA PERSONALE */
        .library-tracks-grid {
            display: grid;
            gap: var(--space-4);
        }

        .library-track-item {
            display: flex;
            align-items: center;
            padding: var(--space-4);
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            transition: all var(--transition-fast);
        }

        .library-track-item:hover {
            background: var(--light-purple);
            border-color: var(--primary-purple);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.15);
        }

        /* ANIMAZIONI */
        @keyframes fadeOut {
            from { opacity: 1; transform: scale(1); }
            to { opacity: 0; transform: scale(0.95); }
        }

        .loading, .error, .no-results {
            text-align: center;
            padding: var(--space-8);
            color: var(--text-secondary);
        }

        .error {
            color: #ef4444;
        }

        .explicit-badge {
            background: #ef4444;
            color: white;
            font-size: 0.625rem;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            margin-left: var(--space-2);
        }

        /* ========================================
           MODAL STYLING
           ======================================== */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
        }

        .modal-content {
            background-color: var(--bg-secondary);
            margin: 5% auto;
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 800px;
            position: relative;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 2rem;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .close-modal:hover {
            color: var(--text-primary);
        }

        .form-group {
            margin-bottom: var(--space-4);
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: var(--space-3);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 1rem;
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .modal-actions {
            display: flex;
            gap: var(--space-3);
            justify-content: flex-end;
        }

        .track-preview {
            background: var(--bg-light);
            padding: var(--space-3);
            border-radius: var(--radius-sm);
            margin-bottom: var(--space-3);
            border-left: 4px solid var(--primary-purple);
        }

        .link-button {
            background: none;
            border: none;
            color: var(--primary-purple);
            text-decoration: underline;
            cursor: pointer;
            font-size: inherit;
            font-weight: 500;
            padding: 0;
            margin: 0;
            transition: color var(--transition-fast);
            display: inline;
            font-family: inherit;
        }

        .link-button:hover {
            color: var(--accent-purple);
            text-decoration: none;
        }

        .form-hint {
            margin-top: var(--space-2);
            color: var(--text-secondary);
            font-size: var(--text-sm);
            line-height: var(--leading-normal);
            font-style: italic;
        }

        .form-hint small {
            font-size: var(--text-xs);
        }

        /* Inline track list inside playlist card */
        .inline-track-list {
            margin-top: 12px;
            padding: 12px;
            background: #fff;
            border: 1px solid #eef2ff;
            border-radius: 8px;
        }

        .inline-track-list-body { display: flex; flex-direction: column; gap: 8px; }

        .inline-track-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            border-radius: 6px;
            background: #fbfbff;
            border: 1px solid #f1f5f9;
        }

        .inline-track-meta { display: flex; flex-direction: column; }
        .inline-track-title { font-weight: 600; color: #111827; }
        .inline-track-artist { font-size: 0.9rem; color: #6b7280; }

        .inline-track-actions { display: flex; gap: 8px; }

        .inline-track-loading, .inline-no-tracks, .inline-error { color: #6b7280; text-align: center; padding: 6px 0; }

        /* Inline modal-like header */
        .inline-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 14px;
            border-bottom: 1px solid #eef2ff;
            margin: -12px -12px 12px -12px;
            background: linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0.02));
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }

        .inline-modal-title { margin: 0; font-size: 1.125rem; color: #111827; }

        .inline-close {
            background: none;
            border: none;
            font-size: 1.1rem;
            color: #6b7280;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
        }

        .inline-close:hover { background: #f3f4f6; color: #111827; }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-wrapper">
        <div class="music-library">
            
            <!-- Header -->
            <div class="dashboard-header">
                <div>
                    <h1>🎵 Libreria Musicale e Playlist</h1>
                    <p>Gestisci i tuoi contenuti musicali e crea playlist per i pazienti</p>
                </div>
                <a href="therapist_dashboard.php" class="dashboard-link">← Torna alla Dashboard</a>
            </div>

            <!-- ================================
                 NAVIGAZIONE TAB PRINCIPALE
                 ================================ -->
            <div class="library-tabs">
                <button class="tab-button active" onclick="switchTab('search')">🔍 Cerca e Aggiungi</button>
                <button class="tab-button" onclick="switchTab('library')">📚 Libreria Brani</button>
                <button class="tab-button" onclick="switchTab('playlists')">🎭 Playlist</button>
            </div>

            <!-- ================================
                 TAB 1: RICERCA E AGGIUNTA BRANI
                 ================================ -->
            <div id="search-tab" class="tab-content active">
                  <!-- Indicatore stato connessione Spotify -->
                <div class="connection-status <?php echo $spotify_connected ? 'connected' : 'disconnected'; ?>">
                    <?php if ($spotify_connected): ?>
                        <div class="spotify-status-connected">
                            <div class="status-info">
                                <h2>✅ Spotify Connesso</h2>
                                <p>Puoi cercare e aggiungere brani dalla libreria Spotify</p>
                            </div>
                            <div class="status-actions">
                                <button onclick="disconnectSpotify()" class="disconnect-btn" id="disconnect-spotify-btn">
                                    🔌 Disconnetti Spotify
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <h2>❌ Spotify Non Connesso</h2>
                        <p>Devi connettere il tuo account Spotify per cercare brani</p>
                        <button onclick="connectSpotify()" class="search-btn">
                            🔗 Connetti Spotify
                        </button>
                    <?php endif; ?>
                </div>
                
                <?php if ($spotify_connected): ?>
                <!-- Area ricerca brani Spotify -->
                <div class="search-section">
                    <div class="search-header">
                        <h2>🔍 Cerca e Aggiungi Brani alla Libreria</h2>
                        <p style="color: var(--text-secondary); font-size: 0.95rem; margin-top: 8px;">
                            🎯 Cerca brani su Spotify e aggiungili alla tua libreria personale
                        </p>
                    </div>
                    
                    <div class="search-form">
                        <input 
                            type="text" 
                            id="search-query" 
                            class="search-input"
                            placeholder="Cerca artista, brano, album..."
                            onkeypress="if(event.key==='Enter') searchTracks()"
                        >
                        <button onclick="searchTracks()" class="search-btn" id="search-btn">
                            🔍 Cerca
                        </button>
                    </div>
                    
                    <div id="search-results"></div>
                </div>
                <?php endif; ?>
            </div>            <!-- Tab: Libreria Brani -->
            <div id="library-tab" class="tab-content">
                <div class="library-section">
                    <h2>📚 La Tua Libreria Musicale</h2>
                    <p class="library-description">Visualizza tutti i brani che hai aggiunto alla tua libreria personale e gestiscili</p>
                    
                    <div id="personal-library">
                        <!-- I brani della libreria verranno caricati qui -->
                    </div>
                </div>
            </div>

            <!-- Tab: Playlist -->
            <div id="playlists-tab" class="tab-content">
                <div class="playlist-section">
                    <div class="playlist-header">
                        <div>
                            <h2>🎭 Le Tue Playlist</h2>
                            <p style="color: var(--text-secondary); margin-top: 8px; font-size: 0.95rem;">
                                Crea playlist personalizzate per i tuoi pazienti e gestisci i brani terapeutici
                            </p>
                        </div>
                        <button onclick="showCreatePlaylistModal()" class="create-playlist-btn">
                            ➕ Crea Nuova Playlist
                        </button>
                    </div>
                    
                    <div id="playlists-grid" class="playlist-grid">
                        <!-- Le playlist verranno caricate dinamicamente -->
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal Crea/Modifica Playlist -->
    <div id="playlist-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-title">Crea Nuova Playlist</h3>
                <button class="close-modal" onclick="closeModal('playlist-modal')">&times;</button>
            </div>
            
            <form id="playlist-form">
                <div class="form-group">
                    <label class="form-label">Nome Playlist *</label>
                    <input type="text" id="playlist-name" class="form-input" required placeholder="Es: Rilassamento serale, Energie positive...">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrizione</label>
                    <textarea id="playlist-description" class="form-textarea" placeholder="Descrivi lo scopo terapeutico di questa playlist... Es: Brani per ridurre l'ansia prima del sonno"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Assegna a Paziente (opzionale)</label>
                    <select id="playlist-patient" class="form-select">
                        <option value="">Nessun paziente specifico</option>
                        <!-- Opzioni caricate dinamicamente -->
                    </select>
                    <div class="form-hint">
                        💡 Puoi sempre assegnare la playlist a un paziente in seguito modificandola
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('playlist-modal')">Annulla</button>
                    <button type="submit" class="search-btn">Salva Playlist</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Aggiungi a Playlist -->
    <div id="add-to-playlist-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Aggiungi Brano a Playlist</h3>
                <button class="close-modal" onclick="closeAddToPlaylistModal()">&times;</button>
            </div>
            
            <div id="selected-track-info" class="track-preview"></div>
            
            <div class="form-group">
                <label class="form-label">Seleziona Playlist</label>
                <select id="playlist-select" class="form-select" required>
                    <option value="">Caricamento playlist...</option>
                </select>
                <div class="form-hint">
                    <small>💡 Non trovi la playlist giusta? <button type="button" class="link-button" onclick="createNewPlaylistFromModal()">Crea una nuova playlist</button></small>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeAddToPlaylistModal()">Annulla</button>
                <button type="button" class="search-btn" onclick="addToSelectedPlaylist()">Aggiungi alla Playlist</button>
            </div>
        </div>
    </div>

    <script>
        /* ========================================
           CONFIGURAZIONE E VARIABILI GLOBALI
           ======================================== */
        
        // Configurazione Spotify OAuth
        const SPOTIFY_CLIENT_ID = '49240058547c408e84cbf72206a02101';
        const CALLBACK_URL = <?php echo json_encode(defined('SPOTIFY_CALLBACK_BASE') ? SPOTIFY_CALLBACK_BASE : 'https://horrent-sharda-heeled.ngrok-free.dev/muza/callback.php'); ?>;
        
        // Variabili di stato per la gestione playlist e brani
        let currentEditingPlaylist = null;      // Playlist attualmente in modifica
        let selectedTrackId = null;             // ID del brano selezionato
        let selectedTrackForPlaylist = null;    // Dati completi del brano per aggiunta playlist
        
        /* ========================================
           FUNZIONI DI UTILITÀ
           ======================================== */
        
        /**
         * Mostra feedback all'utente con auto-dismiss
         */
        function showUserFeedback(message, type = 'info', duration = 5000) {
            // Rimuovi messaggi esistenti
            const existingMessages = document.querySelectorAll('.user-feedback');
            existingMessages.forEach(msg => msg.remove());
            
            const feedbackDiv = document.createElement('div');
            feedbackDiv.className = `user-feedback ${type}`;
            feedbackDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                max-width: 400px;
                font-weight: 500;
                animation: slideInRight 0.3s ease-out;
            `;
            
            // Stili per tipo
            const styles = {
                success: 'background: #10b981; color: white;',
                error: 'background: #ef4444; color: white;',
                warning: 'background: #f59e0b; color: white;',
                info: 'background: #3b82f6; color: white;'
            };
            
            feedbackDiv.style.cssText += styles[type] || styles.info;
            feedbackDiv.textContent = message;
            
            document.body.appendChild(feedbackDiv);
            
            // Auto-dismiss
            setTimeout(() => {
                if (feedbackDiv.parentNode) {
                    feedbackDiv.style.animation = 'slideOutRight 0.3s ease-in forwards';
                    setTimeout(() => feedbackDiv.remove(), 300);
                }
            }, duration);
        }
        
        /**
         * Aggiorna l'UI durante le operazioni di caricamento
         */
        function updateLoadingState(element, isLoading, originalText = null) {
            if (!element) return;
            
            if (isLoading) {
                element.dataset.originalText = element.textContent;
                element.textContent = 'Caricamento...';
                element.disabled = true;
                element.classList.add('loading');
            } else {
                element.textContent = originalText || element.dataset.originalText || element.textContent;
                element.disabled = false;
                element.classList.remove('loading');
                delete element.dataset.originalText;
            }
        }
        
        /**
         * Esegue una richiesta HTTP con retry automatico
         */
        async function robustFetch(url, options = {}, maxRetries = 2) {
            for (let attempt = 0; attempt <= maxRetries; attempt++) {
                try {
                    console.log(`Tentativo ${attempt + 1}/${maxRetries + 1} per ${url}`);
                    
                    const response = await fetch(url, {
                        ...options,
                        headers: {
                            'Content-Type': 'application/json',
                            ...options.headers
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const data = await response.json();
                    console.log(`Successo per ${url}:`, data);
                    return data;
                    
                } catch (error) {
                    console.error(` Tentativo ${attempt + 1} fallito per ${url}:`, error);
                    
                    if (attempt === maxRetries) {
                        throw error; // Ultimo tentativo fallito
                    }

                    // Attendi prima di riprovare 
                    await new Promise(resolve => setTimeout(resolve, Math.pow(2, attempt) * 1000));
                }
            }
        }
        
        /* ========================================
           GESTIONE NAVIGAZIONE TAB
           ======================================== */
        
        /**
         * Cambia il tab attivo e carica i dati necessari
         */
        function switchTab(tabName) {
            // Nasconde tutti i contenuti dei tab
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Rimuove la classe active da tutti i bottoni tab
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Attiva il tab e il contenuto selezionato
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
              // Carica i dati specifici per ogni tab
            switch(tabName) {
                case 'playlists':
                    loadPlaylists();
                    break;
                case 'library':
                    loadPersonalLibrary();
                    break;
                // Il tab search non necessita di ricaricamento dati
            }
        }
        
        /* ========================================
           GESTIONE CONNESSIONE SPOTIFY
           ======================================== */
          /**
         * Avvia il processo di connessione Spotify
         */
        async function connectSpotify() {
            try {
                showUserFeedback('Preparazione connessione Spotify...', 'info');
                
                const state = Math.random().toString(36).substring(2, 15);
                
                // Salva stato per sicurezza CSRF
                await robustFetch('api/save_spotify_state.php', {
                    method: 'POST',
                    body: JSON.stringify({ state: state })
                });
                
                const scopes = [
                    'user-read-private',
                    'user-read-email', 
                    'playlist-read-private',
                    'user-library-read',
                    'user-top-read'
                ].join(' ');
                
                const spotifyAuthUrl = `https://accounts.spotify.com/authorize?` +
                    `client_id=${SPOTIFY_CLIENT_ID}&` +
                    `response_type=code&` +
                    `redirect_uri=${encodeURIComponent(CALLBACK_URL + '?type=spotify')}&` +
                    `scope=${encodeURIComponent(scopes)}&` +
                    `state=${state}`;
                
                showUserFeedback('Reindirizzamento a Spotify...', 'info');
                window.location.href = spotifyAuthUrl;
                
            } catch (error) {
                console.error('❌ Errore connessione Spotify:', error);
                showUserFeedback('Errore nella connessione a Spotify. Riprova.', 'error');
            }
        }

        /**
         * Disconnette l'account Spotify del terapeuta
         */
        async function disconnectSpotify() {
            // Conferma dall'utente
            const confirmDisconnect = confirm(
                '🔌 Sei sicuro di voler disconnettere Spotify?\n\n' +
                '• Perderai l\'accesso alla ricerca brani\n' +
                '• La tua libreria personale rimarrà intatta\n' +
                '• Potrai riconnettere Spotify in qualsiasi momento'
            );

            if (!confirmDisconnect) {
                return;
            }

            const disconnectBtn = document.getElementById('disconnect-spotify-btn');
            
            try {
                // UI feedback
                disconnectBtn.classList.add('loading');
                disconnectBtn.textContent = 'Disconnettendo...';
                disconnectBtn.disabled = true;

                showUserFeedback('Disconnessione da Spotify in corso...', 'info');

                // Chiamata API per disconnettere
                const response = await robustFetch('api/disconnect_spotify.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (response.success) {
                    showUserFeedback(response.message || 'Spotify disconnesso con successo! 🎵', 'success');
                    
                    // Ricarica la pagina per aggiornare l'interfaccia
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    throw new Error(response.error || 'Errore durante la disconnessione');
                }

            } catch (error) {
                console.error('❌ Errore disconnessione Spotify:', error);
                
                // Ripristina pulsante
                disconnectBtn.classList.remove('loading');
                disconnectBtn.textContent = '🔌 Disconnetti Spotify';
                disconnectBtn.disabled = false;

                showUserFeedback(
                    `Errore durante la disconnessione: ${error.message}`, 
                    'error'
                );
            }
        }
        
        /* ========================================
           RICERCA BRANI SPOTIFY
           ======================================== */
        
        /**
         * Cerca brani su Spotify con gestione errori robusta
         */
        async function searchTracks() {
            const query = document.getElementById('search-query').value.trim();
            
            // Validazione input
            if (!query) {
                showUserFeedback('Inserisci un termine di ricerca', 'warning');
                document.getElementById('search-query').focus();
                return;
            }
            
            if (query.length < 2) {
                showUserFeedback('Ricerca troppo corta (minimo 2 caratteri)', 'warning');
                return;
            }
            
            const resultsDiv = document.getElementById('search-results');
            const searchBtn = document.getElementById('search-btn');
            
            // Aggiorna stato di caricamento
            updateLoadingState(searchBtn, true);
            resultsDiv.innerHTML = '<div class="loading">🔍 Ricerca in corso...</div>';
            
            try {
                console.log(`🔍 Ricerca Spotify per: "${query}"`);
                
                const data = await robustFetch(`api/search_spotify.php?q=${encodeURIComponent(query)}`);
                
                if (data.success && data.tracks?.length > 0) {
                    displaySearchResults(data.tracks, resultsDiv);
                    showUserFeedback(`Trovati ${data.tracks.length} brani per "${query}"`, 'success');
                } else if (data.tracks?.length === 0) {
                    resultsDiv.innerHTML = `
                        <div class="no-results">
                            ❌ Nessun risultato per "${query}"
                            <br><small>Prova con termini diversi o meno specifici</small>
                        </div>
                    `;
                    showUserFeedback('Nessun risultato trovato', 'warning');
                } else {
                    handleSearchError(data, resultsDiv);
                }
                
            } catch (error) {
                console.error('❌ Errore ricerca completo:', error);
                resultsDiv.innerHTML = `
                    <div class="error">
                        ❌ Errore di connessione: ${error.message}
                        <br><small>Verifica la connessione e riprova</small>
                    </div>
                `;
                showUserFeedback('Errore durante la ricerca', 'error');
            } finally {
                updateLoadingState(searchBtn, false);
            }
        }
        
        /**
         * Gestisce gli errori di ricerca con feedback dettagliato
         */
        function handleSearchError(data, resultsDiv) {
            console.error('❌ Errore API ricerca:', data);
            
            let errorMsg = data.error || 'Errore sconosciuto';
            let actionMsg = '';
            
            if (data.needs_reconnect) {
                errorMsg = 'Token Spotify scaduto';
                actionMsg = `
                    <br><br>
                    <button onclick="connectSpotify()" class="search-btn" style="margin-top: 10px;">
                        🔗 Riconnetti Spotify
                    </button>
                `;
            } else if (data.error_code === 'HTTP_401') {
                errorMsg = 'Accesso Spotify non autorizzato';
                actionMsg = '<br><small>Riconnetti il tuo account Spotify</small>';
            } else if (data.error_code === 'HTTP_429') {
                errorMsg = 'Troppe richieste a Spotify';
                actionMsg = '<br><small>Attendi qualche minuto e riprova</small>';
            }
            
            resultsDiv.innerHTML = `<div class="error">${errorMsg}${actionMsg}</div>`;
            showUserFeedback(errorMsg, 'error');
        }
        
        /**
         * Mostra i risultati della ricerca
         */
        function displaySearchResults(tracks, resultsDiv) {
            let html = '<div class="results-grid">';
            
            tracks.forEach(track => {
                html += createTrackItemHTML(track);
            });
            
            html += '</div>';
            resultsDiv.innerHTML = html;
        }
        
        /**
         * Crea HTML per un singolo brano nei risultati
         */
        function createTrackItemHTML(track) {
            return `
                <div class="track-item">
                    ${track.image ? 
                        `<img src="${track.image}" alt="${escapeHtml(track.name)}" class="track-image" loading="lazy">` : 
                        '<div class="track-placeholder">🎵</div>'
                    }
                    <div class="track-info">
                        <div class="track-title">
                            ${escapeHtml(track.name)}
                            ${track.explicit ? '<span class="explicit-badge">E</span>' : ''}
                        </div>
                        <div class="track-artist">${escapeHtml(track.artist)}</div>
                        <div class="track-meta">
                            Album: ${escapeHtml(track.album)} • ${track.duration_formatted}
                            ${track.popularity ? ` • ♫ ${track.popularity}%` : ''}
                        </div>
                    </div>
                    <div class="track-actions">
                        ${track.preview_url ? 
                            `<button onclick="playPreview('${track.preview_url}')" class="action-btn btn-preview">▶️ Anteprima</button>` : 
                            ''
                        }
                        <button onclick="addTrackToLibrary('${track.id}', '${escapeHtml(track.name)}', '${escapeHtml(track.artist)}', '${escapeHtml(track.album)}', ${track.duration_ms}, '${track.external_url || ''}', '${track.preview_url || ''}', '${track.image || ''}', ${track.popularity || 0}, ${track.explicit}, this)" 
                                class="action-btn btn-add">📚 Aggiungi alla Libreria</button>
                        <button onclick="showAddToPlaylistModalFromSearch('${track.id}', '${escapeHtml(track.name)}', '${escapeHtml(track.artist)}', '${escapeHtml(track.album)}', ${track.duration_ms}, '${track.external_url || ''}', '${track.preview_url || ''}', '${track.image || ''}', ${track.popularity || 0}, ${track.explicit})" 
                                class="action-btn btn-add-playlist">🎭 Aggiungi a Playlist</button>
                    </div>
                </div>
            `;
        }
        
        /* ========================================
           GESTIONE BRANI
           ======================================== */
        
        /**
         * Aggiunge un brano alla libreria con feedback robusto
         */
        async function addTrackToLibrary(spotifyId, title, artist, album, durationMs, externalUrl, previewUrl, image, popularity, explicit, buttonElement = null) {
            // Validazione input
            if (!spotifyId || !title || !artist) {
                showUserFeedback('Dati del brano non validi', 'error');
                return;
            }
            
            // Aggiorna UI
            if (buttonElement) {
                updateLoadingState(buttonElement, true);
            }
            
            try {
                console.log(`📚 Aggiunta brano: "${title}" di ${artist}`);
                
                const requestData = {
                    spotify_id: spotifyId,
                    title: title,
                    artist: artist,
                    album: album || '',
                    duration_ms: parseInt(durationMs) || 0,
                    external_url: externalUrl || '',
                    preview_url: previewUrl || '',
                    image: image || '',
                    popularity: parseInt(popularity) || 0,
                    explicit: Boolean(explicit)
                };
                
                const data = await robustFetch('api/add_spotify_track.php', {
                    method: 'POST',
                    body: JSON.stringify(requestData)
                });
                
                if (data.success) {
                    showUserFeedback(`✅ "${title}" aggiunto alla libreria!`, 'success');
                    
                    if (buttonElement) {
                        buttonElement.textContent = '✅ Aggiunto';
                        buttonElement.className = 'action-btn btn-added';
                        buttonElement.disabled = true;
                    }
                } else {
                    throw new Error(data.error || 'Errore sconosciuto');
                }
                
            } catch (error) {
                console.error('❌ Errore aggiunta brano:', error);
                showUserFeedback(`Errore: ${error.message}`, 'error');
                
                if (buttonElement) {
                    updateLoadingState(buttonElement, false);
                }
            }
        }
        
        /* ========================================
           GESTIONE PLAYLIST
           ======================================== */
        
        /**
         * Carica tutte le playlist del terapeuta
         */
        async function loadPlaylists() {
            try {
                console.log('📋 Caricamento playlist...');
                
                const data = await robustFetch('api/manage_playlists.php?action=list');
                
                if (data.success) {
                    displayPlaylists(data.playlists);
                    updatePlaylistSelects(data.playlists);
                    console.log(`✅ Caricate ${data.playlists.length} playlist`);
                } else {
                    throw new Error(data.error || 'Errore nel caricamento playlist');
                }
                
            } catch (error) {
                console.error('❌ Errore caricamento playlist:', error);
                showUserFeedback('Errore nel caricamento delle playlist', 'error');
            }
        }
        
        /**
         * Mostra le playlist nell'interfaccia
         */
        function displayPlaylists(playlists) {
            const grid = document.getElementById('playlists-grid');
            if (!grid) return;
            
            if (playlists.length === 0) {
                grid.innerHTML = `
                    <div class="no-results">
                        📭 Nessuna playlist creata ancora
                        <br><small>Clicca "Crea Nuova Playlist" per iniziare</small>
                    </div>
                `;
                return;
            }
            
            let html = '';
            playlists.forEach(playlist => {
                html += `
                    <div class="playlist-card">
                        <div class="playlist-name">${escapeHtml(playlist.name)}</div>
                        <div class="playlist-description">${escapeHtml(playlist.description || 'Nessuna descrizione')}</div>
                        <div class="playlist-meta">
                            🎵 ${playlist.track_count} brani
                            ${playlist.patient_name ? ` • 👤 ${escapeHtml(playlist.patient_name)}` : ' • 📝 Non assegnata'}
                        </div>
                        <div class="playlist-actions">
                            <button onclick="editPlaylist(${playlist.id})" class="action-btn btn-preview">✏️ Modifica</button>
                            <button onclick="showPlaylistTracksModal(${playlist.id})" class="action-btn btn-playlist">📂 Mostra brani</button>
                            <button onclick="deletePlaylist(${playlist.id}, '${escapeHtml(playlist.name)}')" class="action-btn btn-danger">🗑️ Elimina</button>
                        </div>
                    </div>
                `;
            });
            
            grid.innerHTML = html;
        }

        /* ===== Modal display for playlist tracks ===== */
        function showPlaylistTracksModal(playlistId) {
            // Create modal if not present
            let modal = document.getElementById('playlist-tracks-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'playlist-tracks-modal';
                modal.className = 'modal';
                modal.innerHTML = `
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title">📂 Brani Playlist</h3>
                            <button class="close-modal" onclick="closePlaylistTracksModal()">&times;</button>
                        </div>
                        <div id="playlist-tracks-body">
                            <div class="inline-track-loading">Caricamento brani...</div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
            }

            // Show modal and load tracks
            modal.style.display = 'block';
            const body = document.getElementById('playlist-tracks-body');
            if (body) {
                body.innerHTML = '<div class="inline-track-loading">Caricamento brani...</div>';
            }
            loadPlaylistTracksModal(playlistId);
        }

        function closePlaylistTracksModal() {
            const modal = document.getElementById('playlist-tracks-modal');
            if (modal) modal.style.display = 'none';
        }

        function loadPlaylistTracksModal(playlistId) {
            const body = document.getElementById('playlist-tracks-body');
            if (!body) return;

            fetch('api/manage_playlists.php?action=tracks&playlist_id=' + playlistId)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.tracks || !data.tracks.length) {
                        body.innerHTML = '<div class="inline-no-tracks">Nessun brano</div>';
                        return;
                    }

                    // Create organized table of tracks
                    const table = document.createElement('table');
                    table.style.width = '100%';
                    table.style.fontSize = '14px';
                    table.innerHTML = `
                        <thead>
                            <tr style="background:#f3f4f6;">
                                <th style="text-align:left; padding:10px;">#</th>
                                <th style="text-align:left; padding:10px;">Titolo</th>
                                <th style="text-align:left; padding:10px;">Artista</th>
                                <th style="text-align:left; padding:10px;">Durata</th>
                                <th style="text-align:center; padding:10px; width:180px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    `;

                    const tbody = table.querySelector('tbody');
                    data.tracks.forEach((track, idx) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="padding:10px; vertical-align: middle;">${idx + 1}</td>
                            <td style="padding:10px; vertical-align: middle;">${escapeHtml(track.title)}</td>
                            <td style="padding:10px; vertical-align: middle;">${escapeHtml(track.artist)}</td>
                            <td style="padding:10px; vertical-align: middle;">${formatDuration(Math.floor((track.duration_ms || 0) / 1000))}</td>
                            <td style="padding:10px; text-align:center; vertical-align: middle;">
                                <button class="action-btn btn-danger" onclick="removePlaylistTrack(${playlistId}, ${track.id}, this)">Elimina</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });

                    body.innerHTML = '';
                    body.appendChild(table);
                })
                .catch(err => {
                    console.error('Errore caricamento brani modal:', err);
                    body.innerHTML = '<div class="inline-error">Errore nel caricamento</div>';
                });
        }
        
        /**
         * Crea una nuova playlist
         */
        async function createPlaylistFromModal() {
            const nameField = document.getElementById('playlist-name');
            const descriptionField = document.getElementById('playlist-description');
            const patientField = document.getElementById('playlist-patient');
            
            const name = nameField.value.trim();
            const description = descriptionField.value.trim();
            const patientId = patientField.value;
            
            // Validazione
            if (!name) {
                showUserFeedback('Il nome della playlist è obbligatorio', 'warning');
                nameField.focus();
                return;
            }
            
            if (name.length < 3) {
                showUserFeedback('Il nome deve essere di almeno 3 caratteri', 'warning');
                nameField.focus();
                return;
            }
            
            try {
                console.log(`📝 Creazione playlist: "${name}"`);
                
                const data = await robustFetch('api/manage_playlists.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'create',
                        name: name,
                        description: description,
                        patient_id: patientId || null
                    })
                });
                
                if (data.success) {
                    console.log('MANAGE_PLAYLISTS CREATE response:', data);
                    showUserFeedback(`✅ Playlist "${name}" creata!`, 'success');
                    
                    // Se c'è un brano in attesa, aggiungilo
                    if (selectedTrackForPlaylist) {
                        console.log('Auto-adding track after create:', {playlist_id: data.playlist_id, track: selectedTrackForPlaylist});
                        await addTrackToNewPlaylist(data.playlist_id, name);
                    }
                    
                    cleanupAfterPlaylistCreation();
                } else {
                    throw new Error(data.error || 'Errore nella creazione');
                }
                
            } catch (error) {
                console.error('❌ Errore creazione playlist:', error);
                showUserFeedback(`Errore: ${error.message}`, 'error');
            }
        }
        
        /**
         * Aggiunge un brano alla playlist appena creata
         */
        async function addTrackToNewPlaylist(playlistId, playlistName) {
            try {
                console.log('addTrackToNewPlaylist called with:', {playlistId, playlistName, track: selectedTrackForPlaylist});
                const data = await robustFetch('api/manage_playlists.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'add_spotify_track_to_playlist',
                        playlist_id: playlistId,
                        spotify_data: selectedTrackForPlaylist
                    })
                });
                
                if (data.success) {
                    showUserFeedback(`✅ Playlist "${playlistName}" creata e brano aggiunto!`, 'success');
                } else {
                    throw new Error(data.error || 'Errore aggiunta brano');
                }
                
                selectedTrackForPlaylist = null;
                
            } catch (error) {
                console.error('❌ Errore aggiunta brano a nuova playlist:', error);
                showUserFeedback(`Playlist creata, ma errore nell'aggiunta del brano`, 'warning');
            }
        }
        
        /**
         * Pulisce l'UI dopo la creazione di una playlist
         */
        function cleanupAfterPlaylistCreation() {
            // Rimuovi hint se presente
            const hint = document.querySelector('.creation-hint');
            if (hint) {
                hint.remove();
            }
            
            // Chiudi modal e ricarica playlist
            closeModal('playlist-modal');
            loadPlaylists();
        }
        
        /* ========================================
           MODAL E PLAYLIST MANAGEMENT
           ======================================== */
        
        /**
         * Mostra il modal per creare una nuova playlist
         */
        function showCreatePlaylistModal() {
            document.getElementById('playlist-form').reset();
            document.getElementById('modal-title').textContent = 'Crea Nuova Playlist';
            document.getElementById('playlist-modal').style.display = 'block';
        }
        
        /**
         * Mostra modal per aggiungere brano a playlist
         */
        async function showAddToPlaylistModalFromSearch(spotifyId, title, artist, album, durationMs, externalUrl, previewUrl, image, popularity, explicit) {
            selectedTrackForPlaylist = {
                spotify_id: spotifyId,
                title: title,
                artist: artist,
                album: album,
                duration_ms: durationMs,
                external_url: externalUrl,
                preview_url: previewUrl,
                image: image,
                popularity: popularity,
                explicit: explicit
            };
            
            await loadPlaylistsForModal();
            
            document.getElementById('selected-track-info').innerHTML = `
                <strong>🎵 ${title}</strong><br>
                <small>👤 ${artist}</small><br>
                <small class="text-muted">Aggiungi questo brano alla playlist selezionata</small>
            `;
            
            document.getElementById('add-to-playlist-modal').style.display = 'block';
        }
        
        /**
         * Carica playlist nel modal di selezione
         */
        async function loadPlaylistsForModal() {
            try {
                const data = await robustFetch('api/manage_playlists.php?action=list');
                
                if (data.success) {
                    const playlistSelect = document.getElementById('playlist-select');
                    playlistSelect.innerHTML = '<option value="">Seleziona playlist...</option>';
                    
                    if (data.playlists.length === 0) {
                        playlistSelect.innerHTML = '<option value="">Nessuna playlist disponibile</option>';
                    } else {
                        data.playlists.forEach(playlist => {
                            const patientInfo = playlist.patient_name ? ` (${playlist.patient_name})` : '';
                            playlistSelect.innerHTML += `<option value="${playlist.id}">${playlist.name}${patientInfo} - ${playlist.track_count} brani</option>`;
                        });
                    }
                } else {
                    showUserFeedback('Errore nel caricamento delle playlist', 'error');
                }
            } catch (error) {
                console.error('Errore:', error);
                showUserFeedback('Errore nel caricamento delle playlist', 'error');
            }
        }
        
        /**
         * Aggiunge brano alla playlist selezionata
         */
        async function addToSelectedPlaylist() {
            const playlistId = document.getElementById('playlist-select').value;
            
            if (!playlistId) {
                showUserFeedback('Seleziona una playlist', 'warning');
                return;
            }
            
            if (!selectedTrackForPlaylist) {
                showUserFeedback('Nessun brano selezionato', 'error');
                return;
            }
            
            try {
                const data = await robustFetch('api/manage_playlists.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'add_spotify_track_to_playlist',
                        playlist_id: parseInt(playlistId),
                        spotify_data: selectedTrackForPlaylist
                    })
                });
                
                if (data.success) {
                    showUserFeedback(`✅ "${selectedTrackForPlaylist.title}" aggiunto alla playlist!`, 'success');
                    closeAddToPlaylistModal();
                    
                    // Ricarica playlist se siamo nel tab playlist
                    if (document.querySelector('#playlists-tab.active')) {
                        loadPlaylists();
                    }
                } else {
                    throw new Error(data.error || 'Errore nell\'aggiunta alla playlist');
                }
                
            } catch (error) {
                console.error('Errore:', error);
                showUserFeedback(`Errore: ${error.message}`, 'error');
            }
        }
        
        /**
         * Chiude il modal di aggiunta a playlist
         */
        function closeAddToPlaylistModal() {
            document.getElementById('add-to-playlist-modal').style.display = 'none';
            selectedTrackForPlaylist = null;
        }
        
        /**
         * Crea nuova playlist dal modal di aggiunta brano
         */
        function createNewPlaylistFromModal() {
            closeAddToPlaylistModal();
            showCreatePlaylistModal();
            
            if (selectedTrackForPlaylist) {
                setupAutoTrackAddition();
            }
        }
        
        /**
         * Configura l'aggiunta automatica del brano
         */
        function setupAutoTrackAddition() {
            document.getElementById('modal-title').textContent = 
                `Crea Playlist per "${selectedTrackForPlaylist.title}"`;
            
            const existingHint = document.querySelector('.creation-hint');
            if (existingHint) {
                existingHint.remove();
            }
            
            const hint = document.createElement('div');
            hint.className = 'creation-hint';
            hint.style.cssText = `
                background: rgba(139, 92, 246, 0.1); 
                padding: 12px; 
                border-radius: 8px; 
                margin-bottom: 16px; 
                color: var(--text-secondary); 
                font-size: 0.9rem;
            `;
            hint.innerHTML = `
                <strong>💡 Info:</strong> 
                Dopo aver creato la playlist, il brano 
                "<em>${selectedTrackForPlaylist.title}</em>" 
                verrà automaticamente aggiunto.
            `;
            
            const form = document.getElementById('playlist-form');
            form.insertBefore(hint, form.firstChild);
        }
          /* ========================================
           CARICAMENTO LIBRERIA PERSONALE
           ======================================== */
          /**
         * Carica i brani della libreria personale del terapeuta
         */
        async function loadPersonalLibrary() {
            try {
                console.log('📚 Caricamento libreria personale...');
                
                const data = await robustFetch('api/get_personal_library.php');
                
                if (data.success) {
                    displayPersonalLibrary(data.tracks);
                    console.log(`✅ Caricati ${data.tracks.length} brani dalla libreria`);
                    
                    // Log debug info se presente
                    if (data.debug) {
                        console.log('🔧 Info debug:', data.debug);
                    }
                } else {
                    // Mostra informazioni di debug se disponibili
                    let errorMsg = data.error || 'Errore nel caricamento libreria';
                    if (data.debug_message) {
                        console.error('🐛 Debug dettagliato:', data.debug_message);
                        if (data.debug_trace) {
                            console.error('📍 Stack trace:', data.debug_trace);
                        }
                        errorMsg = `${errorMsg}: ${data.debug_message}`;
                    }
                    throw new Error(errorMsg);
                }
                
            } catch (error) {
                console.error('❌ Errore caricamento libreria:', error);
                
                // Fallback: mostra messaggio di errore nell'interfaccia
                const container = document.getElementById('personal-library');
                if (container) {
                    container.innerHTML = `
                        <div class="error">
                            ❌ Errore nel caricamento della libreria
                            <br><small>${error.message}</small>
                            <br><br>
                            <button onclick="loadPersonalLibrary()" class="action-btn btn-preview">
                                🔄 Riprova
                            </button>
                        </div>
                    `;
                }
                showUserFeedback('Errore nel caricamento della libreria', 'error');
            }
        }
        
        /**
         * Mostra i brani della libreria personale
         */
        function displayPersonalLibrary(tracks) {
            const container = document.getElementById('personal-library');
            if (!container) return;
            
            if (tracks.length === 0) {
                container.innerHTML = `
                    <div class="no-results">
                        📭 La tua libreria è vuota
                        <br><small>Cerca brani nella sezione "Cerca e Aggiungi" per iniziare a costruire la tua libreria</small>
                    </div>
                `;
                return;
            }
            
            let html = '<div class="library-tracks-grid">';
            
            tracks.forEach((track, index) => {
                html += `
                    <div class="library-track-item" data-track-id="${track.id}">
                        ${track.image_url ? 
                            `<img src="${track.image_url}" alt="${escapeHtml(track.title)}" class="track-image">` : 
                            '<div class="track-placeholder">🎵</div>'
                        }
                        <div class="track-info">
                            <div class="track-title">
                                ${escapeHtml(track.title)}
                                ${track.explicit ? '<span class="explicit-badge">E</span>' : ''}
                            </div>
                            <div class="track-artist">${escapeHtml(track.artist)}</div>
                            <div class="track-meta">
                                Album: ${escapeHtml(track.album || 'N/A')} • ${formatDuration(track.duration)}
                                ${track.popularity ? ` • ♫ ${track.popularity}%` : ''}
                            </div>
                        </div>
                        <div class="track-actions">
                            ${track.preview_url ? 
                                `<button onclick="playPreview('${track.preview_url}')" class="action-btn btn-preview">▶️ Anteprima</button>` : 
                                ''
                            }
                            
                            <button onclick="showAddToPlaylistModalFromLibrary('${track.spotify_id}', '${escapeHtml(track.title)}', '${escapeHtml(track.artist)}', '${escapeHtml(track.album || '')}', ${track.duration * 1000}, '${track.spotify_url || ''}', '${track.preview_url || ''}', '${track.image_url || ''}', ${track.popularity || 0}, ${track.explicit})" 
                                    class="action-btn btn-add-playlist"> Aggiungi a Playlist</button>
                            <button onclick="removeFromLibrary(${track.id}, '${escapeHtml(track.title)}')" 
                                    class="action-btn btn-danger">🗑️ Rimuovi</button>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        /**
         * Mostra modal per aggiungere brano dalla libreria a playlist
         */
        async function showAddToPlaylistModalFromLibrary(spotifyId, title, artist, album, durationMs, externalUrl, previewUrl, image, popularity, explicit) {
            // Riutilizza la stessa logica del modal di ricerca
            await showAddToPlaylistModalFromSearch(spotifyId, title, artist, album, durationMs, externalUrl, previewUrl, image, popularity, explicit);
        }
        
        /**
         * Rimuove un brano dalla libreria personale
         */
        async function removeFromLibrary(trackId, title) {
            if (!confirm(`Sei sicuro di voler rimuovere "${title}" dalla tua libreria?\n\nIl brano rimarrà nelle playlist dove è già presente.`)) {
                return;
            }
            
            try {
                console.log(`🗑️ Rimozione brano dalla libreria: "${title}"`);
                
                const data = await robustFetch('api/remove_from_library.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        track_id: trackId
                    })
                });
                
                if (data.success) {
                    showUserFeedback(`✅ "${title}" rimosso dalla libreria`, 'success');
                    
                    // Rimuovi elemento dall'interfaccia
                    const trackElement = document.querySelector(`[data-track-id="${trackId}"]`);
                    if (trackElement) {
                        trackElement.style.animation = 'fadeOut 0.3s ease-out forwards';
                        setTimeout(() => {
                            trackElement.remove();
                            
                            // Controlla se la libreria è vuota
                            const container = document.getElementById('personal-library');
                            const remainingTracks = container.querySelectorAll('.library-track-item');
                            if (remainingTracks.length === 0) {
                                displayPersonalLibrary([]);
                            }
                        }, 300);
                    }
                } else {
                    throw new Error(data.error || 'Errore nella rimozione');
                }
                
            } catch (error) {
                console.error('❌ Errore rimozione brano:', error);
                showUserFeedback(`Errore: ${error.message}`, 'error');
            }
        }
        
        /* ========================================
           GESTIONE PAZIENTI
           ======================================== */
        
        /**
         * Carica lista pazienti per assegnazione playlist
         */
        async function loadPatients() {
            try {
                const data = await robustFetch('api/manage_playlists.php?action=patients');
                
                if (data.success) {
                    const select = document.getElementById('playlist-patient');
                    if (select) {
                        select.innerHTML = '<option value="">Nessun paziente specifico</option>';
                        
                        data.patients.forEach(patient => {
                            select.innerHTML += `<option value="${patient.id}">${patient.name}</option>`;
                        });
                    }
                }
            } catch (error) {
                console.error('Errore caricamento pazienti:', error);
            }
        }
        
        /**
         * Aggiorna i select delle playlist
         */
        function updatePlaylistSelects(playlists) {
            const targetSelect = document.getElementById('playlist-select');
            if (targetSelect) {
                targetSelect.innerHTML = '<option value="">Seleziona una playlist</option>';
                
                playlists.forEach(playlist => {
                    const patientInfo = playlist.patient_name ? ` (${playlist.patient_name})` : '';
                    targetSelect.innerHTML += `<option value="${playlist.id}">${playlist.name}${patientInfo} - ${playlist.track_count} brani</option>`;
                });
            }
        }
        
        /* ========================================
           GESTIONE MODAL E CHIUSURA
           ======================================== */
        
        /**
         * Chiude un modal specifico
         */
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            
            switch(modalId) {
                case 'playlist-modal':
                    resetPlaylistCreationModal();
                    break;
            }
        }
        
        /**
         * Resetta il modal di creazione playlist
         */
        function resetPlaylistCreationModal() {
            document.getElementById('playlist-form').reset();
            document.getElementById('modal-title').textContent = 'Crea Nuova Playlist';
            
            const hint = document.querySelector('.creation-hint');
            if (hint) {
                hint.remove();
            }
        }
        
        /**
         * Chiusura modal cliccando sull'overlay
         */
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        /* ========================================
           PLAYLIST EDITING (PLACEHOLDER)
           ======================================== */
        
        /**
         * Modifica playlist (placeholder)
         */
        function editPlaylist(playlistId) {
            // Recupera dati playlist esistente
            robustFetch(`api/manage_playlists.php?action=list`)
                .then(data => {
                    if (data.success) {
                        const playlist = data.playlists.find(p => p.id == playlistId);
                        if (playlist) {
                            showEditPlaylistModal(playlist);
                        } else {
                            showUserFeedback('Playlist non trovata', 'error');
                        }
                    } else {
                        showUserFeedback('Errore nel recupero dati playlist', 'error');
                    }
                })
                .catch(error => {
                    console.error('Errore edit playlist:', error);
                    showUserFeedback('Errore nel recupero dati playlist', 'error');
                });
        }

        function showEditPlaylistModal(playlist) {
            // Rimuovi modal esistente se presente
            const existingModal = document.getElementById('edit-playlist-modal');
            if (existingModal) {
                existingModal.remove();
            }

            // Crea modal per editing
            const modal = document.createElement('div');
            modal.id = 'edit-playlist-modal';
            modal.className = 'modal';
            modal.innerHTML = `
                <div class="modal-content">
                    <span class="close" onclick="closeEditPlaylistModal()">&times;</span>
                    <h2>✏️ Modifica Playlist</h2>
                    
                    <div class="form-group">
                        <label for="edit-playlist-name">Nome Playlist:</label>
                        <input type="text" id="edit-playlist-name" value="${playlist.name}" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-playlist-description">Descrizione:</label>
                        <textarea id="edit-playlist-description" rows="3">${playlist.description || ''}</textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-playlist-patient">Paziente Assegnato:</label>
                        <select id="edit-playlist-patient">
                            <option value="">-- Nessun paziente --</option>
                        </select>
                    </div>

                    
                    <div class="form-actions">
                        <button onclick="savePlaylistChanges(${playlist.id})" class="btn btn-primary">💾 Salva Modifiche</button>
                        <button onclick="closeEditPlaylistModal()" class="btn btn-secondary">❌ Annulla</button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
            modal.style.display = 'block';

            // Popola lista pazienti
            loadPatientsForPlaylist(playlist.patient_id);
            // (Brani nella playlist rimossi dal modal; usare 'Mostra brani' nella card)
        }

        // Nota: la visualizzazione dei brani nel modal è stata rimossa.
        // La lista dei brani è disponibile solo tramite "Mostra brani" nella card della playlist.

        function removePlaylistTrack(playlistId, trackId, btn) {
            if (!confirm('Vuoi davvero rimuovere questo brano dalla playlist?')) return;
            btn.disabled = true;
            fetch('api/manage_playlists.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'track', playlist_id: playlistId, track_id: trackId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Se il pulsante è nella vista inline (vecchio comportamento), ricarica le playlist
                    const inlineContainer = btn.closest('.inline-track-list');
                    if (inlineContainer) {
                        loadPlaylists();
                        return;
                    }

                    // Se il pulsante è nella modal dei brani, ricarica il contenuto del modal
                    const modal = document.getElementById('playlist-tracks-modal');
                    if (modal && modal.style.display === 'block' && modal.contains(btn)) {
                        loadPlaylistTracksModal(playlistId);
                        return;
                    }

                    // Altrimenti ricarica le playlist per aggiornare i conteggi
                    loadPlaylists();
                } else {
                    alert(data.error || 'Errore nella rimozione');
                    btn.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                btn.disabled = false;
                alert('Errore di rete');
            });
        }

        

        function loadPatientsForPlaylist(selectedPatientId) {
            robustFetch('api/manage_playlists.php?action=patients')
                .then(data => {
                    if (data.success) {
                        const select = document.getElementById('edit-playlist-patient');
                        select.innerHTML = '<option value="">-- Nessun paziente --</option>';
                        
                        data.patients.forEach(patient => {
                            const option = document.createElement('option');
                            option.value = patient.id;
                            option.textContent = patient.name;
                            option.selected = patient.id == selectedPatientId;
                            select.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Errore caricamento pazienti:', error);
                });
        }

        function savePlaylistChanges(playlistId) {
            const name = document.getElementById('edit-playlist-name').value.trim();
            const description = document.getElementById('edit-playlist-description').value.trim();
            const patientId = document.getElementById('edit-playlist-patient').value;

            if (!name) {
                showUserFeedback('Nome playlist richiesto', 'error');
                return;
            }

            const updateData = {
                action: 'update',
                playlist_id: playlistId,
                name: name,
                description: description,
                patient_id: patientId || null
            };

            robustFetch('api/manage_playlists.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(updateData)
            })
            .then(data => {
                if (data.success) {
                    showUserFeedback('Playlist aggiornata con successo', 'success');
                    closeEditPlaylistModal();
                    loadPlaylists(); // Ricarica la lista
                } else {
                    showUserFeedback('Errore nell\'aggiornamento: ' + (data.error || 'Errore sconosciuto'), 'error');
                }
            })
            .catch(error => {
                console.error('Errore salvataggio:', error);
                showUserFeedback('Errore nell\'aggiornamento della playlist', 'error');
            });
        }

        function closeEditPlaylistModal() {
            const modal = document.getElementById('edit-playlist-modal');
            if (modal) {
                modal.remove();
            }
        }
        
        /**
         * Elimina playlist con conferma
         */
        function deletePlaylist(playlistId, name) {
            if (!confirm(`Sei sicuro di voler eliminare la playlist "${name}"?\n\nQuesta azione eliminerà definitivamente la playlist e tutti i suoi contenuti.`)) {
                return;
            }
            
            robustFetch('api/manage_playlists.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'playlist',
                    playlist_id: playlistId
                })
            })
            .then(data => {
                if (data.success) {
                    showUserFeedback(`✅ Playlist "${name}" eliminata con successo`, 'success');
                    loadPlaylists();
                } else {
                    throw new Error(data.error || 'Errore nell\'eliminazione');
                }
            })
            .catch(error => {
                console.error('Errore eliminazione playlist:', error);
                showUserFeedback(`Errore: ${error.message}`, 'error');
            });
        }
        
        /* ========================================
           UTILITY FUNCTIONS
           ======================================== */
        
        /**
         * Escape HTML per sicurezza
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        /**
         * Formatta durata da secondi a MM:SS
         */
        function formatDuration(seconds) {
            if (!seconds || seconds <= 0) return '0:00';
            
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
        }
        
        /* ========================================
           AUDIO PLAYER
           ======================================== */
        
        /**
         * Player anteprima audio
         */
        let currentAudio = null;
        function playPreview(url) {
            if (currentAudio) {
                currentAudio.pause();
            }
            
            if (!url) {
                showUserFeedback('Anteprima non disponibile per questo brano', 'warning');
                return;
            }
            
            currentAudio = new Audio(url);
            currentAudio.play().catch(error => {
                console.error('Errore riproduzione:', error);
                showUserFeedback('Impossibile riprodurre l\'anteprima', 'error');
            });
            
            // Ferma dopo 30 secondi (limite Spotify)
            setTimeout(() => {
                if (currentAudio) {
                    currentAudio.pause();
                }
            }, 30000);
        }
        
        /* ========================================
           INIZIALIZZAZIONE
           ======================================== */
        
        /**
         * Inizializza l'applicazione
         */        document.addEventListener('DOMContentLoaded', function() {
            console.log('🎵 Music Library inizializzata');
            
            // Carica dati iniziali
            loadPlaylists();
            loadPatients();
            loadPersonalLibrary(); // Carica anche la libreria personale
            
            // Gestione form creazione playlist
            const playlistForm = document.getElementById('playlist-form');
            if (playlistForm) {
                playlistForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    createPlaylistFromModal();
                });
            }
            
            // Gestione ricerca con Enter
            const searchInput = document.getElementById('search-query');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        searchTracks();
                    }
                });
            }
            
            // Animazioni iniziali
            const cards = document.querySelectorAll('.track-item, .playlist-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.05}s`;
                card.classList.add('animate-fadeInUp');
            });
            
            showUserFeedback('Music Library caricata con successo', 'success', 3000);
        });
        
        // Aggiungere gli stili CSS per le animazioni
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
            .track-thumb {
                width: 50px;
                height: 50px;
                border-radius: var(--radius-sm);
                object-fit: cover;
                margin-right: var(--space-3);
            }
            .track-thumb-placeholder {
                width: 50px;
                height: 50px;
                border-radius: var(--radius-sm);
                background: var(--bg-secondary);
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: var(--space-3);
                font-size: 1.5rem;
            }
            .track-info-edit {
                flex: 1;
            }
            .track-title-edit {
                font-weight: 600;
                color: var(--text-primary);
                margin-bottom: var(--space-1);
            }
            .track-artist-edit {
                color: var(--text-secondary);
                font-size: 0.9rem;
            }
            .track-duration-edit {
                color: var(--text-muted);
                font-size: 0.8rem;
            }
            .track-actions-edit {
                display: flex;
                gap: var(--space-2);
                flex-shrink: 0;
            }
            .btn-preview-track {
                background: var(--primary-purple);
                color: white;
                border: none;
                padding: var(--space-2);
                border-radius: var(--radius-sm);
                cursor: pointer;
                font-size: 0.8rem;
                font-weight: 500;
                transition: all var(--transition-fast);
                min-width: 70px;
            }
            .btn-preview-track:hover {
                background: var(--purple);
                transform: scale(1.05);
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
<?php
// therapist_dashboard.php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

$therapist = checkAuth('therapist');

// === STATISTICHE AVANZATE DEI PAZIENTI ===

// Statistiche generali della dashboard del terapeuta
$general_stats_query = "
    SELECT 
        COUNT(DISTINCT ls.user_id) as total_active_patients,
        COUNT(ls.id) as total_sessions_month,
        AVG(ls.mood_after - ls.mood_before) as avg_mood_improvement,
        AVG(ls.energy_after - ls.energy_before) as avg_energy_improvement,
        AVG(ls.listen_duration) as avg_session_duration,
        COUNT(CASE WHEN ls.completed = 1 THEN 1 END) as completed_sessions,
        COUNT(CASE WHEN tr.rating >= 4 THEN 1 END) as positive_ratings,
        COUNT(tr.id) as total_ratings
    FROM listening_sessions ls
    LEFT JOIN track_ratings tr ON ls.id = tr.session_id
    WHERE ls.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
";
$general_stats = $conn->query($general_stats_query)->fetch_assoc();

// Statistiche dettagliate per paziente
$patient_stats_query = "
    SELECT u.id, u.first_name, u.last_name, u.email,
           COUNT(ls.id) as total_sessions,
           COUNT(CASE WHEN ls.completed = 1 THEN 1 END) as completed_sessions,
           AVG(ls.mood_before) as avg_mood_before,
           AVG(ls.mood_after) as avg_mood_after,
           AVG(ls.mood_after - ls.mood_before) as mood_improvement,
           AVG(ls.energy_before) as avg_energy_before,
           AVG(ls.energy_after) as avg_energy_after,
           AVG(ls.energy_after - ls.energy_before) as energy_improvement,
           AVG(ls.listen_duration) as avg_duration,
           MAX(ls.created_at) as last_session,
           MIN(ls.created_at) as first_session,
           COUNT(tr.id) as total_track_ratings,
           AVG(tr.rating) as avg_track_rating,
           COUNT(CASE WHEN tr.rating >= 4 THEN 1 END) as positive_ratings,
           COUNT(CASE WHEN tr.helpful = 1 THEN 1 END) as helpful_tracks
    FROM users u 
    LEFT JOIN listening_sessions ls ON u.id = ls.user_id 
    LEFT JOIN track_ratings tr ON u.id = tr.user_id
    WHERE u.user_type = 'patient' AND ls.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY u.id 
    ORDER BY total_sessions DESC, last_session DESC
";
$patient_stats_result = $conn->query($patient_stats_query);

// Trend settimanali dell'umore per grafico
$mood_trends_query = "
    SELECT 
        WEEK(ls.created_at) as week_number,
        YEAR(ls.created_at) as year,
        DATE_FORMAT(ls.created_at, '%Y-%m-%d') as date,
        AVG(ls.mood_before) as avg_mood_before,
        AVG(ls.mood_after) as avg_mood_after,
        COUNT(ls.id) as session_count
    FROM listening_sessions ls
    WHERE ls.created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
    GROUP BY YEAR(ls.created_at), WEEK(ls.created_at)
    ORDER BY year DESC, week_number DESC
    LIMIT 8
";
$mood_trends_result = $conn->query($mood_trends_query);

// Top brani più efficaci
$top_tracks_query = "
    SELECT t.title, t.artist,
           COUNT(tr.id) as rating_count,
           AVG(tr.rating) as avg_rating,
           COUNT(CASE WHEN tr.helpful = 1 THEN 1 END) as helpful_count,
           (COUNT(CASE WHEN tr.helpful = 1 THEN 1 END) / COUNT(tr.id)) * 100 as helpful_percentage
    FROM tracks t
    JOIN track_ratings tr ON t.id = tr.track_id
    WHERE tr.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY t.id
    HAVING rating_count >= 3
    ORDER BY avg_rating DESC, helpful_percentage DESC
    LIMIT 10
";
$top_tracks_result = $conn->query($top_tracks_query);

// Recupera tutti i pazienti registrati (query esistente modificata)
$patients_query = "
    SELECT u.*, 
           COUNT(ls.id) as total_sessions,
           MAX(ls.created_at) as last_session
    FROM users u 
    LEFT JOIN listening_sessions ls ON u.id = ls.user_id 
    WHERE u.user_type = 'patient'
    GROUP BY u.id 
    ORDER BY last_session DESC
";
$patients_result = $conn->query($patients_query);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Terapeuta - Mūza</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* === STILI ANALYTICS === */
        .analytics-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow-sm);
        }
        
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }
        
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: var(--space-5);
            border-radius: var(--radius-md);
            text-align: center;
        }
        
        .stat-box.mood { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-box.energy { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-box.sessions { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .stat-box.ratings { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            display: block;
            margin-bottom: var(--space-2);
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .patient-analytics-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: var(--space-6);
            margin-bottom: var(--space-6);
        }
        
        .patient-list-analytics {
            background: white;
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
        }
        
        .patient-card-analytics {
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-md);
            padding: var(--space-4);
            margin-bottom: var(--space-4);
            transition: all 0.2s ease;
        }
        
        .patient-card-analytics:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow-sm);
        }
        
        .patient-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-3);
        }
        
        .patient-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: var(--space-1);
        }
        
        .patient-metrics {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--space-3);
            margin-top: var(--space-3);
        }
        
        .metric {
            text-align: center;
            padding: var(--space-2);
            background: #f8fafc;
            border-radius: var(--radius-sm);
        }
        
        .metric-value {
            font-weight: bold;
            font-size: 1.1rem;
            color: var(--primary-color);
        }
        
        .metric-label {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: var(--space-1);
        }
        
        .progress-indicator {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: var(--space-2) 0;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #ef4444, #f59e0b, #10b981);
            transition: width 0.3s ease;
        }
        
        .chart-container {
            background: white;
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            height: 400px;
        }
        
        .top-tracks-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow-sm);
        }
        
        .track-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-3);
            border-bottom: 1px solid #f1f5f9;
        }
        
        .track-info h4 {
            margin: 0 0 var(--space-1) 0;
            color: var(--primary-color);
        }
        
        .track-stats {
            display: flex;
            gap: var(--space-4);
            align-items: center;
        }
        
        .rating-badge {
            background: var(--primary-color);
            color: white;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .patient-analytics-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-overview {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .patient-metrics {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-wrapper">
        <div class="therapist-dashboard">
        
        <!-- Header -->
        <div class="dashboard-header">
            <div>
                <h1>Dashboard Terapeuta</h1>
                <p>Benvenuto, Dr. <?php echo htmlspecialchars($therapist['first_name'] . ' ' . $therapist['last_name']); ?></p>
            </div>
            <div class="flex items-center gap-lg">
                <a href="logout.php" class="dashboard-link">Logout</a>
            </div>
        </div>

        <!-- Gestione Contenuti Musicali -->
        <div class="widget">
            <h3>🎵 Gestione Contenuti Musicali</h3>
            
            <div class="mb-lg">
                <a href="music_library.php" class="btn-primary" style="display: inline-block; text-decoration: none; padding: var(--space-4) var(--space-6); border-radius: var(--radius-md); text-align: center; width: 100%; font-weight: 600;">
                    🎭 Gestisci Libreria e Playlist
                </a>
                <p class="text-sm text-muted mt-sm">Cerca brani su Spotify, crea playlist e assegnale ai pazienti</p>
            </div>
        </div>

        <!-- === NUOVA SEZIONE: ANALYTICS E STATISTICHE === -->
        <div class="analytics-section">
            <h2>📊 Analytics e Statistiche Pazienti (Ultimi 30 giorni)</h2>
            
            <!-- Statistiche Overview -->
            <div class="stats-overview">
                <div class="stat-box sessions">
                    <span class="stat-number"><?php echo $general_stats['total_active_patients'] ?: 0; ?></span>
                    <div class="stat-label">Pazienti Attivi</div>
                </div>
                <div class="stat-box mood">
                    <span class="stat-number">+<?php echo number_format($general_stats['avg_mood_improvement'] ?: 0, 1); ?></span>
                    <div class="stat-label">Miglioramento Umore Medio</div>
                </div>
                <div class="stat-box energy">
                    <span class="stat-number">+<?php echo number_format($general_stats['avg_energy_improvement'] ?: 0, 1); ?></span>
                    <div class="stat-label">Miglioramento Energia Medio</div>
                </div>
                <div class="stat-box ratings">
                    <span class="stat-number"><?php echo $general_stats['positive_ratings'] ?: 0; ?></span>
                    <div class="stat-label">Valutazioni Positive (4-5★)</div>
                </div>
            </div>
        </div>

        <!-- Grid Analytics e Lista Pazienti -->
        <div class="patient-analytics-grid">
            <!-- Lista Pazienti con Metriche -->
            <div class="patient-list-analytics">
                <h3>👥 Pazienti - Dettagli Performance</h3>
                
                <?php if($patient_stats_result && $patient_stats_result->num_rows > 0): ?>
                    <?php while($patient = $patient_stats_result->fetch_assoc()): ?>
                        <div class="patient-card-analytics">
                            <div class="patient-header">
                                <div>
                                    <div class="patient-name">
                                        <a href="patient_details.php?patient_id=<?php echo $patient['id']; ?>" style="text-decoration: none; color: inherit;">
                                            <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                                        </a>
                                    </div>
                                    <div class="text-sm text-secondary">📧 <?php echo htmlspecialchars($patient['email']); ?></div>
                                </div>
                                <div class="text-sm">
                                    <strong>Ultima sessione:</strong><br>
                                    <?php echo $patient['last_session'] ? date('d/m H:i', strtotime($patient['last_session'])) : 'Mai'; ?>
                                    <br><br>
                                    <a href="patient_details.php?patient_id=<?php echo $patient['id']; ?>" class="btn" style="font-size: 12px; padding: 4px 8px;">📊 Dettagli</a>
                                </div>
                            </div>
                            
                            <!-- Metriche del Paziente -->
                            <div class="patient-metrics">
                                <div class="metric">
                                    <div class="metric-value"><?php echo $patient['total_sessions'] ?: 0; ?></div>
                                    <div class="metric-label">Sessioni Totali</div>
                                </div>
                                <div class="metric">
                                    <div class="metric-value"><?php echo number_format($patient['mood_improvement'] ?: 0, 1); ?></div>
                                    <div class="metric-label">Miglioramento Umore</div>
                                </div>
                                <div class="metric">
                                    <div class="metric-value"><?php echo number_format($patient['avg_track_rating'] ?: 0, 1); ?>★</div>
                                    <div class="metric-label">Valutazione Media</div>
                                </div>
                            </div>
                            
                            <!-- Barra Progresso Mood -->
                            <?php 
                            $mood_progress = 0;
                            if ($patient['mood_improvement']) {
                                $mood_progress = min(100, max(0, (($patient['mood_improvement'] + 5) / 10) * 100));
                            }
                            ?>
                            <div class="progress-indicator">
                                <div class="progress-bar" style="width: <?php echo $mood_progress; ?>%"></div>
                            </div>
                            
                            <!-- Statistiche Aggiuntive -->
                            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 12px; font-size: 12px;">
                                <div><strong>Completate:</strong> <?php echo $patient['completed_sessions'] ?: 0; ?></div>
                                <div><strong>Durata Media:</strong> <?php echo gmdate("i:s", $patient['avg_duration'] ?: 0); ?></div>
                                <div><strong>Valutazioni:</strong> <?php echo $patient['total_track_ratings'] ?: 0; ?></div>
                                <div><strong>Brani Utili:</strong> <?php echo $patient['helpful_tracks'] ?: 0; ?></div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center" style="padding: 40px;">
                        <h3>Nessun paziente con sessioni attive</h3>
                        <p class="text-secondary">I pazienti che inizieranno le sessioni compariranno qui.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Grafico Trend Umore -->
            <div class="chart-container">
                <h3>📈 Trend Umore Settimanale</h3>
                <canvas id="moodTrendChart"></canvas>
            </div>
        </div>

        <!-- Top Brani Più Efficaci -->
        <div class="top-tracks-section">
            <h3>🎵 Top 10 Brani Più Efficaci (Ultimi 30 giorni)</h3>
            
            <?php if($top_tracks_result && $top_tracks_result->num_rows > 0): ?>
                <?php $rank = 1; while($track = $top_tracks_result->fetch_assoc()): ?>
                    <div class="track-item">
                        <div class="track-info">
                            <h4><?php echo "#$rank. " . htmlspecialchars($track['title']); ?></h4>
                            <div class="text-secondary"><?php echo htmlspecialchars($track['artist']); ?></div>
                        </div>
                        <div class="track-stats">
                            <div class="rating-badge"><?php echo number_format($track['avg_rating'], 1); ?>★</div>
                            <div class="text-sm">
                                <strong><?php echo $track['rating_count']; ?></strong> valutazioni<br>
                                <strong><?php echo number_format($track['helpful_percentage'], 0); ?>%</strong> trova utile
                            </div>
                        </div>
                    </div>
                <?php $rank++; endwhile; ?>
            <?php else: ?>
                <p class="text-center text-secondary">Nessun dato disponibile per questo periodo.</p>
            <?php endif; ?>
        </div>

        <!-- Statistiche Base (Mantenute per compatibilità) -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-number"><?php echo $general_stats['total_active_patients'] ?: 0; ?></span>
                <div>Pazienti Attivi</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php echo $general_stats['total_sessions_month'] ?: 0; ?></span>
                <div>Sessioni Mese</div>
            </div>
        </div>

        <!-- Lista Pazienti -->
        <div class="mb-xl">
            <h2 class="text-2xl font-bold text-primary mb-lg">👥 I Tuoi Pazienti</h2>
            <div class="patients-grid">
                <?php if($patients_result && $patients_result->num_rows > 0): ?>
                    <?php while($patient = $patients_result->fetch_assoc()): ?>
                        <div class="patient-card">
                            <h3><a href="patient_details.php?patient_id=<?php echo $patient['id']; ?>" class="patient-link"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></a></h3>
                            <p class="text-sm text-secondary">
                                📧 <?php echo htmlspecialchars($patient['email']); ?>
                            </p>
                            
                            <div class="mt-lg space-y-md">
                                <div class="flex justify-between">
                                    <span class="text-secondary">Sessioni totali:</span>
                                    <strong class="text-primary"><?php echo $patient['total_sessions'] ?: 0; ?></strong>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-secondary">Ultima sessione:</span>
                                    <strong class="text-primary">
                                        <?php 
                                        echo $patient['last_session'] 
                                            ? date('d/m H:i', strtotime($patient['last_session']))
                                            : '<span class="text-muted">N/A</span>';
                                        ?>
                                    </strong>
                                </div>
                            </div>

                            <!-- Modifica Playlist - Popup -->
                            <div id="editPlaylistModal" class="modal">
                                <div class="modal-content">
                                    <span class="close" onclick="closeEditPlaylistModal()">&times;</span>
                                    <h2>Modifica Playlist</h2>
                                    
                                    <!-- Nuova sezione: ultime sessioni con voti pre e post -->
                                    <div class="mt-lg">
                                        <h4 class="text-sm text-primary">Ultime sessioni</h4>
                                        <table style="width:100%; font-size:13px; margin-top:8px;">
                                            <tr style="background:#f3f4f6;">
                                                <th>Data</th>
                                                <th>Brano/Playlist</th>
                                                <th>Umore Prima</th>
                                                <th>Energia Prima</th>
                                                <th>Umore Dopo</th>
                                                <th>Energia Dopo</th>
                                            </tr>
                                            <?php
                                            $sid = $patient['id'];
                                            $sessq = $conn->query("SELECT ls.*, t.title as track_title, tp.name as playlist_name FROM listening_sessions ls LEFT JOIN tracks t ON ls.track_id = t.id LEFT JOIN therapist_playlists tp ON ls.playlist_id = tp.id WHERE ls.user_id = $sid ORDER BY ls.created_at DESC LIMIT 5");
                                            if($sessq && $sessq->num_rows > 0) {
                                                while($s = $sessq->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                                                    <td><?php echo $s['playlist_name'] ? '🎭 '.htmlspecialchars($s['playlist_name']) : '🎵 '.htmlspecialchars($s['track_title']); ?></td>
                                                    <td style="text-align:center;"> <?php echo $s['mood_before']; ?> </td>
                                                    <td style="text-align:center;"> <?php echo $s['energy_before']; ?> </td>
                                                    <td style="text-align:center;"> <?php echo $s['mood_after']; ?> </td>
                                                    <td style="text-align:center;"> <?php echo $s['energy_after']; ?> </td>
                                                </tr>
                                            <?php endwhile; }
                                            else {
                                                echo '<tr><td colspan="6" style="text-align:center; color:#aaa;">Nessuna sessione</td></tr>';
                                            }
                                            ?>
                                        </table>
                                    </div>

                                    <!-- Esempio: elenco brani di una playlist -->
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Titolo</th>
                                                <th>Artista</th>
                                                <th>Azioni</th>
                                            </tr>
                                        </thead>
                                        <tbody id="playlist-tracks-body">
                                            <!-- I brani verranno caricati via JS -->
                                        </tbody>
                                    </table>

                                    <script>
                                    // Funzione per caricare i brani della playlist
                                    function loadPlaylistTracks(playlistId) {
                                        fetch('/muza/api/manage_playlists.php?action=tracks&playlist_id=' + playlistId)
                                            .then(res => res.json())
                                            .then(data => {
                                                if (data.success) {
                                                    const tbody = document.getElementById('playlist-tracks-body');
                                                    tbody.innerHTML = '';
                                                    data.tracks.forEach(track => {
                                                        const tr = document.createElement('tr');
                                                        tr.innerHTML = `
                                                            <td>${track.title}</td>
                                                            <td>${track.artist}</td>
                                                            <td>
                                                                <button onclick="editTrack(${playlistId}, ${track.id}, this)">Modifica</button>
                                                            </td>
                                                        `;
                                                        tbody.appendChild(tr);
                                                    });
                                                }
                                            });
                                    }

                                    // Funzione per eliminare un brano dalla playlist
                                    function removeTrack(playlistId, trackId, btn) {
                                        if (!confirm('Vuoi davvero rimuovere questo brano dalla playlist?')) return;
                                        btn.disabled = true;
                                        fetch('/muza/api/manage_playlists.php', {
                                            method: 'DELETE',
                                            headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify({
                                                action: 'track',
                                                playlist_id: playlistId,
                                                track_id: trackId
                                            })
                                        })
                                        .then(res => res.json())
                                        .then(data => {
                                            if (data.success) {
                                                btn.closest('tr').remove();
                                            } else {
                                                alert(data.error || 'Errore nella rimozione');
                                                btn.disabled = false;
                                            }
                                        });
                                    }

                                    // Funzione per modificare un brano nella playlist (title, artist, position)
                                    function editTrack(playlistId, trackId, btn) {
                                        const tr = btn.closest('tr');
                                        const currentTitle = tr.children[0].textContent;
                                        const currentArtist = tr.children[1].textContent;
                                        const newTitle = prompt('Modifica titolo del brano:', currentTitle);
                                        if (newTitle === null) return; // annullato
                                        const newArtist = prompt('Modifica artista del brano:', currentArtist);
                                        if (newArtist === null) return;
                                        const newPosInput = prompt('Nuova posizione (numero intero). Lascia vuoto per mantenere:', '');
                                        let position = null;
                                        if (newPosInput !== null && newPosInput.trim() !== '') {
                                            position = parseInt(newPosInput);
                                            if (isNaN(position) || position < 1) {
                                                alert('Posizione non valida');
                                                return;
                                            }
                                        }

                                        btn.disabled = true;
                                        fetch('/muza/api/manage_playlists.php', {
                                            method: 'PUT',
                                            headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify({
                                                action: 'update_track',
                                                playlist_id: playlistId,
                                                track_id: trackId,
                                                title: newTitle,
                                                artist: newArtist,
                                                position: position
                                            })
                                        })
                                        .then(res => res.json())
                                        .then(data => {
                                            btn.disabled = false;
                                            if (data.success) {
                                                // Aggiorna riga UI: se posizione cambiata ricarica i brani
                                                tr.children[0].textContent = newTitle;
                                                tr.children[1].textContent = newArtist;
                                                if (position !== null) {
                                                    loadPlaylistTracks(playlistId);
                                                }
                                            } else {
                                                alert(data.error || 'Errore nell\'aggiornamento');
                                            }
                                        })
                                        .catch(err => {
                                            console.error(err);
                                            btn.disabled = false;
                                            alert('Errore di rete');
                                        });
                                    }

                                    // Esempio: carica i brani della playlist con ID 123 all'apertura
                                    // Sostituisci 123 con l'ID reale della playlist selezionata
                                    // loadPlaylistTracks(123);
                                    </script>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="patient-card text-center">
                        <h3>Nessun paziente registrato</h3>
                        <p class="text-secondary">I pazienti registrati compariranno qui automaticamente.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        </div>
    </div>

    <script>
        // === GRAFICO TREND UMORE ===
        document.addEventListener('DOMContentLoaded', function() {
            const chartElement = document.getElementById('moodTrendChart');
            if (!chartElement) return;
            
            const ctx = chartElement.getContext('2d');
            
            // Dati da PHP 
            const moodData = [
                <?php 
                $mood_trends_data = [];
                if ($mood_trends_result && $mood_trends_result->num_rows > 0) {
                    // Reset del risultato se necessario
                    $mood_trends_result->data_seek(0);
                    while ($trend = $mood_trends_result->fetch_assoc()) {
                        $mood_trends_data[] = $trend;
                    }
                }
                $mood_trends_data = array_reverse($mood_trends_data); // Ordine cronologico
                
                foreach ($mood_trends_data as $index => $trend) {
                    echo json_encode([
                        'week' => "Sett. " . $trend['week_number'],
                        'mood_before' => round($trend['avg_mood_before'] ?: 0, 1),
                        'mood_after' => round($trend['avg_mood_after'] ?: 0, 1),
                        'sessions' => $trend['session_count'] ?: 0
                    ]);
                    if ($index < count($mood_trends_data) - 1) echo ",\n                ";
                }
                ?>
            ]; 
            
            if (moodData.length === 0) {
                chartElement.parentElement.innerHTML = '<div style="text-align: center; padding: 50px;"><h4>Nessun dato disponibile</h4><p>I dati del grafico appariranno quando i pazienti inizieranno le sessioni.</p></div>';
                return;
            }
            
            const chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: moodData.map(d => d.week),
                    datasets: [
                        {
                            label: 'Umore Prima',
                            data: moodData.map(d => d.mood_before),
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            tension: 0.4,
                            fill: false,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        },
                        {
                            label: 'Umore Dopo',
                            data: moodData.map(d => d.mood_after),
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: false,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Trend Umore Settimanale - Tutti i Pazienti',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 10,
                            title: {
                                display: true,
                                text: 'Livello Umore (1-10)'
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Periodo'
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    elements: {
                        line: {
                            borderWidth: 3
                        }
                    }
                }
            });
        });

        // Auto-refresh della dashboard ogni 5 minuti
        setTimeout(() => {
            if (confirm('🔄 Aggiornare i dati della dashboard?')) {
                location.reload();
            }
        }, 5 * 60 * 1000);
        
        // Funzione per aprire dettagli paziente
        function viewPatientDetails(patientId) {
            // TODO: Implementare modal con dettagli paziente
            console.log('Visualizza dettagli paziente:', patientId);
        }
    </script>
</body>
</html>
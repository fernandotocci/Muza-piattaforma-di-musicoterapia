<?php
/**
 * Dettagli Analytics di un Singolo Paziente
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

$therapist = checkAuth('therapist');
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if (!$patient_id) {
    header('Location: therapist_dashboard.php');
    exit;
}

// Verifica che il paziente esista
$patient_query = "SELECT * FROM users WHERE id = ? AND user_type = 'patient'";
$stmt = $conn->prepare($patient_query);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$patient_result = $stmt->get_result();

if (!$patient_result || $patient_result->num_rows === 0) {
    header('Location: therapist_dashboard.php');
    exit;
}

$patient = $patient_result->fetch_assoc();

// Statistiche dettagliate del paziente
$stats_query = "
    SELECT 
        COUNT(ls.id) as total_sessions,
        COUNT(CASE WHEN ls.completed = 1 THEN 1 END) as completed_sessions,
        AVG(ls.mood_before) as avg_mood_before,
        AVG(ls.mood_after) as avg_mood_after,
        AVG(ls.mood_after - ls.mood_before) as mood_improvement,
        AVG(ls.energy_before) as avg_energy_before,
        AVG(ls.energy_after) as avg_energy_after,
        AVG(ls.energy_after - ls.energy_before) as energy_improvement,
        AVG(ls.listen_duration) as avg_duration,
        SUM(ls.listen_duration) as total_duration,
        MIN(ls.created_at) as first_session,
        MAX(ls.created_at) as last_session,
        COUNT(DISTINCT DATE(ls.created_at)) as active_days
    FROM listening_sessions ls
    WHERE ls.user_id = ?
      AND ls.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$patient_stats = $stmt->get_result()->fetch_assoc();

// Sessioni recenti
$recent_sessions_query = "
        SELECT ls.*, t.title as track_title, t.artist as track_artist,
                     tp.name as playlist_name
        FROM listening_sessions ls
        LEFT JOIN tracks t ON ls.track_id = t.id
        LEFT JOIN therapist_playlists tp ON ls.playlist_id = tp.id
        WHERE ls.user_id = ?
            AND ls.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY ls.created_at DESC
        LIMIT 15
";
$stmt = $conn->prepare($recent_sessions_query);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$recent_sessions_result = $stmt->get_result();

// Trend dell'umore nel tempo
$mood_history_query = "
    SELECT DATE(created_at) as session_date,
           AVG(mood_before) as avg_mood_before,
           AVG(mood_after) as avg_mood_after,
           COUNT(*) as session_count
    FROM listening_sessions
    WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY session_date ASC
";
$stmt = $conn->prepare($mood_history_query);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$mood_history_result = $stmt->get_result();

// Valutazioni tracce
$track_ratings_query = "
    SELECT t.title, t.artist, tr.rating, tr.helpful, tr.feedback, tr.created_at
    FROM track_ratings tr
    JOIN tracks t ON tr.track_id = t.id
    WHERE tr.user_id = ?
    ORDER BY tr.created_at DESC
    LIMIT 10
";
$stmt = $conn->prepare($track_ratings_query);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$track_ratings_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dettagli Paziente - <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?> - Mūza</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .patient-detail-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            margin-bottom: var(--space-6);
        }
        
        .patient-info {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: var(--space-4);
            align-items: center;
        }
        
        .patient-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }
        
        .stat-card-detail {
            background: white;
            padding: var(--space-5);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: var(--space-2);
        }
        
        .sessions-table {
            background: white;
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow-sm);
        }
        
        .session-row {
            display: grid;
            grid-template-columns: 120px 1fr 80px 80px 80px 80px;
            gap: var(--space-3);
            padding: var(--space-3);
            border-bottom: 1px solid #f1f5f9;
            align-items: center;
        }
        
        .session-header {
            font-weight: bold;
            background: #f8fafc;
        }
        
        .mood-indicator {
            width: 40px;
            height: 20px;
            border-radius: 10px;
            display: inline-block;
        }
        
        .chart-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow-sm);
        }
        
        .ratings-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
        }
        
        .rating-item {
            padding: var(--space-3);
            border-bottom: 1px solid #f1f5f9;
        }
        
        .rating-stars {
            color: #fbbf24;
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-wrapper">
        
        <!-- Header Paziente -->
        <div class="patient-detail-header">
            <div class="patient-info">
                <div>
                    <h1><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h1>
                    <p>📧 <?php echo htmlspecialchars($patient['email']); ?></p>
                    <p>📅 Paziente dal: <?php echo date('d/m/Y', strtotime($patient['created_at'])); ?></p>
                </div>
                <div>
                    <a href="therapist_dashboard.php" class="btn" style="background: rgba(255,255,255,0.2); color: white;">← Torna alla Dashboard</a>
                </div>
            </div>
        </div>

        <!-- Statistiche Overview -->
        <div class="patient-stats-grid">
            <div class="stat-card-detail">
                <div class="stat-value"><?php echo $patient_stats['total_sessions'] ?: 0; ?></div>
                <div>Sessioni Totali</div>
            </div>
            <div class="stat-card-detail">
                <div class="stat-value"><?php echo number_format($patient_stats['mood_improvement'] ?: 0, 1); ?></div>
                <div>Miglioramento Umore Medio</div>
            </div>
            <div class="stat-card-detail">
                <div class="stat-value"><?php echo number_format($patient_stats['energy_improvement'] ?: 0, 1); ?></div>
                <div>Miglioramento Energia Medio</div>
            </div>
            <div class="stat-card-detail">
                <div class="stat-value"><?php echo gmdate("H:i", $patient_stats['total_duration'] ?: 0); ?></div>
                <div>Tempo Totale Ascolto</div>
            </div>
            <div class="stat-card-detail">
                <div class="stat-value"><?php echo $patient_stats['active_days'] ?: 0; ?></div>
                <div>Giorni Attivi</div>
            </div>
            <div class="stat-card-detail">
                <div class="stat-value"><?php echo round(($patient_stats['completed_sessions'] ?: 0) / max(1, $patient_stats['total_sessions'] ?: 1) * 100); ?>%</div>
                <div>Tasso Completamento</div>
            </div>
        </div>

        <!-- Grafico Trend Umore -->
        <div class="chart-section">
            <h3>📈 Trend Umore Giornaliero (Ultimi 30 giorni)</h3>
            <div style="position:relative; width:100%; max-width:600px; margin:auto;">
                <canvas id="patientMoodChart" style="max-height:300px; width:100%;"></canvas>
            </div>
        </div>

        <!-- Sessioni Recenti -->
        <div class="sessions-table">
            <h3>📋 Sessioni Recenti</h3>
            
            <div class="session-row session-header">
                <div>Data</div>
                <div>Brano/Playlist</div>
                <div>Umore Prima</div>
                <div>Umore Dopo</div>
                <div>Energia Prima</div>
                <div>Energia Dopo</div>
            </div>
            
            <?php if ($recent_sessions_result && $recent_sessions_result->num_rows > 0): ?>
                <?php while ($session = $recent_sessions_result->fetch_assoc()): ?>
                    <div class="session-row">
                        <div><?php echo date('d/m H:i', strtotime($session['created_at'])); ?></div>
                        <div>
                            <?php if ($session['playlist_name']): ?>
                                🎭 <?php echo htmlspecialchars($session['playlist_name']); ?>
                            <?php elseif ($session['track_title']): ?>
                                🎵 <?php echo htmlspecialchars($session['track_title']); ?>
                            <?php else: ?>
                                <em>Sessione libera</em>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span class="mood-indicator" style="background: hsl(<?php echo ($session['mood_before'] - 1) * 12; ?>, 70%, 50%);"></span>
                            <?php echo $session['mood_before'] ?: '-'; ?>
                        </div>
                        <div>
                            <span class="mood-indicator" style="background: hsl(<?php echo ($session['mood_after'] - 1) * 12; ?>, 70%, 50%);"></span>
                            <?php echo $session['mood_after'] ?: '-'; ?>
                        </div>
                        <div><?php echo $session['energy_before'] ?: '-'; ?></div>
                        <div><?php echo $session['energy_after'] ?: '-'; ?></div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    Nessuna sessione registrata per questo paziente
                </div>
            <?php endif; ?>
        </div>

        <!-- Valutazioni Brani -->
        <div class="ratings-section">
            <h3>⭐ Valutazioni Brani Recenti</h3>
            
            <?php if ($track_ratings_result && $track_ratings_result->num_rows > 0): ?>
                <?php while ($rating = $track_ratings_result->fetch_assoc()): ?>
                    <div class="rating-item">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div>
                                <strong><?php echo htmlspecialchars($rating['title']); ?></strong><br>
                                <small><?php echo htmlspecialchars($rating['artist']); ?></small>
                            </div>
                            <div style="text-align: right;">
                                <div class="rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php echo $i <= $rating['rating'] ? '★' : '☆'; ?>
                                    <?php endfor; ?>
                                </div>
                                <small><?php echo date('d/m', strtotime($rating['created_at'])); ?></small>
                            </div>
                        </div>
                        <?php if ($rating['feedback']): ?>
                            <div style="margin-top: 8px; padding: 8px; background: #f8fafc; border-radius: 4px; font-style: italic;">
                                "<?php echo htmlspecialchars($rating['feedback']); ?>"
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    Nessuna valutazione disponibile
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        // Grafico trend umore paziente
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('patientMoodChart').getContext('2d');
            const moodData = <?php
                $mood_data = [];
                if ($mood_history_result && $mood_history_result->num_rows > 0) {
                    foreach ($mood_history_result->fetch_all(MYSQLI_ASSOC) as $mood) {
                        $mood_data[] = [
                            'date' => date('d/m', strtotime($mood['session_date'])),
                            'mood_before' => round($mood['avg_mood_before'], 1),
                            'mood_after' => round($mood['avg_mood_after'], 1)
                        ];
                    }
                }
                echo json_encode($mood_data, JSON_UNESCAPED_UNICODE);
            ?>;
            if (!Array.isArray(moodData) || moodData.length === 0) {
                ctx.canvas.parentElement.innerHTML = '<div style="text-align: center; padding: 50px;"><h4>Nessun dato disponibile</h4><p>I dati appariranno quando il paziente completerà le sessioni.</p></div>';
                return;
            }
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: moodData.map(d => d.date),
                    datasets: [
                        {
                            label: 'Umore Prima',
                            data: moodData.map(d => d.mood_before),
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            tension: 0.4,
                            fill: false
                        },
                        {
                            label: 'Umore Dopo',
                            data: moodData.map(d => d.mood_after),
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
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
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
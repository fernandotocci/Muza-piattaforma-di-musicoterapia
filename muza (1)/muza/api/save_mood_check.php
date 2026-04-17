<?php
/**
 * API: save_mood_check.php
 * Salva un check dell'umore senza sessione di ascolto
 * 
 * Endpoint: POST /api/save_mood_check.php
 * Autenticazione: Richiesta (paziente)
 * 
 * Body JSON:
 * {
 *   "mood": int (1-10),
 *   "energy": int (1-10),
 *   "notes": string
 * }
 */

session_start();
require_once '../includes/db.php';

// Headers per API JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Verifica autenticazione - FIX: user_type invece di type
// Da:
if (!isset($_SESSION['user']) || $_SESSION['user']['type'] !== 'patient') 

// A:
if (!isset($_SESSION['user']) || $_SESSION['user']['user_type'] !== 'patient') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Non autorizzato',
        'code' => 'UNAUTHORIZED'
    ]);
    exit;
}

$user_id = $_SESSION['user']['id'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Dati JSON non validi',
        'code' => 'INVALID_JSON'
    ]);
    exit;
}

// Validazione campi richiesti
if (!isset($input['mood']) || !isset($input['energy'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Mood ed energia sono richiesti',
        'code' => 'MISSING_FIELDS'
    ]);
    exit;
}

// Validazione range valori
if ($input['mood'] < 1 || $input['mood'] > 10 || $input['energy'] < 1 || $input['energy'] > 10) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Mood ed energia devono essere tra 1 e 10',
        'code' => 'INVALID_RANGE'
    ]);
    exit;
}

try {
    // Salva mood check come sessione senza traccia specifica
    $sql = "
        INSERT INTO listening_sessions (
            user_id, track_id, mood_before, energy_before, notes, created_at
        ) VALUES (?, NULL, ?, ?, ?, NOW())
    ";
    
    $stmt = $conn->prepare($sql);
    $notes = $input['notes'] ?? '';
    
    $stmt->bind_param(
        'iiis',
        $user_id,
        $input['mood'],
        $input['energy'],
        $notes
    );
    
    if ($stmt->execute()) {
        // Recupera statistiche mood recenti per trend
        $trend_sql = "
            SELECT 
                AVG(mood_before) as avg_mood_7d,
                COUNT(*) as checks_7d
            FROM listening_sessions 
            WHERE user_id = ? 
            AND track_id IS NULL 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ";
        
        $trend_stmt = $conn->prepare($trend_sql);
        $trend_stmt->bind_param('i', $user_id);
        $trend_stmt->execute();
        $trend = $trend_stmt->get_result()->fetch_assoc();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Mood check salvato con successo! 😊',
            'data' => [
                'current_mood' => $input['mood'],
                'current_energy' => $input['energy'],
                'trend_7d' => [
                    'avg_mood' => round($trend['avg_mood_7d'], 1),
                    'total_checks' => (int)$trend['checks_7d']
                ]
            ],
            'recommendations' => generateMoodRecommendations($input['mood'], $input['energy'])
        ]);
    } else {
        throw new Exception('Errore nell\'esecuzione della query');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore nel salvare il mood check',
        'code' => 'SAVE_ERROR',
        'debug' => $e->getMessage()
    ]);
}

/**
 * Genera raccomandazioni basate sull'umore attuale
 */
function generateMoodRecommendations($mood, $energy) {
    $recommendations = [];
    
    if ($mood <= 3) {
        $recommendations[] = "Il tuo umore sembra basso oggi. Prova una sessione di musica rilassante o motivazionale.";
        if ($energy <= 4) {
            $recommendations[] = "Energia bassa + umore basso: consigliamo musica uplifting o ambient.";
        }
    } elseif ($mood >= 8) {
        $recommendations[] = "Ottimo umore! È un buon momento per consolidare questa sensazione con musica positiva.";
    }
    
    if ($energy <= 3) {
        $recommendations[] = "Energia bassa: prova musica energizzante o una breve pausa rigenerante.";
    } elseif ($energy >= 8) {
        $recommendations[] = "Hai molta energia! Perfetto per sessioni più intensive o musica dinamica.";
    }
    
    // Raccomandazioni contestuali basate su ora del giorno
    $hour = (int)date('H');
    if ($hour < 12 && $energy < 5) {
        $recommendations[] = "Mattina con poca energia: prova musica energizzante per iniziare bene la giornata.";
    } elseif ($hour > 20 && $energy > 7) {
        $recommendations[] = "Sera con molta energia: considera musica rilassante per prepararti al sonno.";
    }
    
    return $recommendations;
}
?>
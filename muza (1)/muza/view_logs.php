<?php
/**
 * Visualizzatore Log PHP in tempo reale per debug Spotify
 */

// Header per evitare cache
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

echo "<h1>📋 Log PHP in Tempo Reale - Debug Spotify</h1>";

// Trova il file di log di PHP
$possible_log_files = [
    ini_get('error_log'),
    'C:/xampp/php/logs/php_error_log',
    'C:/xampp/apache/logs/error.log',
    getcwd() . '/error.log',
    sys_get_temp_dir() . '/php_errors.log'
];

$log_file = null;
foreach ($possible_log_files as $file) {
    if ($file && file_exists($file) && is_readable($file)) {
        $log_file = $file;
        break;
    }
}

if (!$log_file) {
    echo "<p>❌ Nessun file di log trovato. Percorsi cercati:</p>";
    echo "<ul>";
    foreach ($possible_log_files as $file) {
        echo "<li>" . ($file ?: 'N/A') . "</li>";
    }
    echo "</ul>";
    echo "<p><strong>Configurazione PHP attuale:</strong></p>";
    echo "<ul>";
    echo "<li>error_log: " . ini_get('error_log') . "</li>";
    echo "<li>log_errors: " . (ini_get('log_errors') ? 'ON' : 'OFF') . "</li>";
    echo "<li>display_errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</li>";
    echo "</ul>";
    exit;
}

echo "<p>✅ <strong>File di log trovato:</strong> $log_file</p>";

// Parametri per la visualizzazione
$lines_to_show = isset($_GET['lines']) ? (int)$_GET['lines'] : 50;
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'spotify';
$auto_refresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 0;

echo "<div style='margin: 20px 0; padding: 15px; background: #f0f0f0; border-radius: 5px;'>";
echo "<strong>Controlli:</strong> ";
echo "<a href='?lines=20&filter=spotify'>20 righe Spotify</a> | ";
echo "<a href='?lines=50&filter=spotify'>50 righe Spotify</a> | ";
echo "<a href='?lines=100'>100 righe tutte</a> | ";
echo "<a href='?lines=50&filter=spotify&refresh=5'>Auto-refresh 5s</a> | ";
echo "<a href='?lines=20&filter=callback'>Solo callback</a>";
echo "</div>";

// Auto-refresh se richiesto
if ($auto_refresh > 0) {
    echo "<meta http-equiv='refresh' content='$auto_refresh'>";
    echo "<p>🔄 <strong>Auto-refresh attivo ogni $auto_refresh secondi</strong></p>";
}

// Leggi il file di log
try {
    $file_content = file_get_contents($log_file);
    if ($file_content === false) {
        throw new Exception("Impossibile leggere il file di log");
    }
    
    $lines = explode("\n", $file_content);
    $lines = array_reverse($lines); // Mostra i più recenti per primi
    
    // Filtra le righe se richiesto
    if ($filter) {
        $lines = array_filter($lines, function($line) use ($filter) {
            return stripos($line, $filter) !== false;
        });
    }
    
    // Limita il numero di righe
    $lines = array_slice($lines, 0, $lines_to_show);
    
    if (empty($lines)) {
        echo "<p>⚠️ Nessuna riga trovata con filtro '$filter'</p>";
    } else {
        echo "<h2>🔍 Ultimi $lines_to_show log" . ($filter ? " contenenti '$filter'" : "") . ":</h2>";
        echo "<div style='background: #000; color: #0f0; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 12px; max-height: 600px; overflow-y: auto;'>";
        
        foreach ($lines as $line) {
            if (trim($line)) {
                // Colora diversamente i livelli di log
                $colored_line = $line;
                if (stripos($line, 'error') !== false) {
                    $colored_line = "<span style='color: #ff6b6b;'>$line</span>";
                } elseif (stripos($line, 'warning') !== false) {
                    $colored_line = "<span style='color: #ffd93d;'>$line</span>";
                } elseif (stripos($line, 'spotify') !== false) {
                    $colored_line = "<span style='color: #74c0fc;'>$line</span>";
                } elseif (stripos($line, 'callback') !== false) {
                    $colored_line = "<span style='color: #51cf66;'>$line</span>";
                }
                
                echo $colored_line . "<br>";
            }
        }
        echo "</div>";
    }
    
    // Informazioni sul file
    echo "<div style='margin-top: 20px; padding: 10px; background: #e3f2fd; border-radius: 5px; font-size: 12px;'>";
    echo "<strong>Info file:</strong> ";
    echo "Dimensione: " . number_format(filesize($log_file)) . " bytes | ";
    echo "Ultima modifica: " . date('Y-m-d H:i:s', filemtime($log_file)) . " | ";
    echo "Righe totali nel file: " . count(explode("\n", $file_content));
    echo "</div>";
    
} catch (Exception $e) {
    echo "<p>❌ <strong>Errore nella lettura del log:</strong> " . $e->getMessage() . "</p>";
}

// Aggiungi test per scrivere nel log
echo "<div style='margin-top: 30px; padding: 15px; background: #fff3cd; border-radius: 5px;'>";
echo "<h3>🧪 Test Scrittura Log</h3>";
if (isset($_GET['test_log'])) {
    $test_message = "TEST LOG SPOTIFY - " . date('Y-m-d H:i:s') . " - ID: " . uniqid();
    error_log($test_message);
    echo "<p>✅ Log di test scritto: <code>$test_message</code></p>";
    echo "<p><a href='?filter=spotify&lines=20'>🔄 Ricarica per vedere il log</a></p>";
} else {
    echo "<p><a href='?test_log=1&filter=spotify&lines=20'>✍️ Scrivi un log di test</a></p>";
}
echo "</div>";

echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
h1, h2, h3 { color: #1db954; }
a { color: #1db954; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>";
?>
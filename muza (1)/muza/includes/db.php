<?php
// Configurazione connessione database
$host = 'localhost';
$user = 'root';           // utente di default XAMPP
$pass = '';               // password vuota di default XAMPP
$dbname = 'musa_db';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    // Se siamo in una chiamata API (rilevato dal Content-Type), restituisci JSON
    if (isset($_SERVER['HTTP_CONTENT_TYPE']) && $_SERVER['HTTP_CONTENT_TYPE'] === 'application/json' || 
        strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Errore connessione database: ' . $conn->connect_error]);
        exit;
    } else {
        die("Connessione al DB fallita: " . $conn->connect_error);
    }
}
?>
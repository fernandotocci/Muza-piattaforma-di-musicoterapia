<?php
/**
 * logout.php - Logout completo con pulizia sessione
 */

session_start();

// Se l'utente è loggato, prova a rimuovere i token Spotify associati
require_once 'includes/db.php';
if (isset($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    $user_id = (int)$_SESSION['user']['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM user_music_tokens WHERE user_id = ? AND service = 'spotify'");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
            error_log("LOGOUT: Spotify tokens removed for user_id=$user_id");
        } else {
            error_log('LOGOUT: prepare failed for deleting spotify tokens: ' . $conn->error);
        }
    } catch (Exception $e) {
        error_log('LOGOUT: Errore rimozione spotify tokens: ' . $e->getMessage());
    }
}

// Cancella tutte le variabili di sessione
$_SESSION = [];

// Cancella il cookie di sessione se esiste
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Distruggi la sessione
session_destroy();

// Previeni caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Redirect al login
header('Location: index.php');
exit;
?>
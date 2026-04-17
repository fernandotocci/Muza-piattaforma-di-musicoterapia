<?php
/**
 * dashboard.php - Reindirizzamento automatico alla dashboard appropriata
 * Questo file redirige automaticamente l'utente alla dashboard corretta
 * in base al tipo di utente (patient o therapist)
 */

session_start();

// Verifica se l'utente è autenticato
if (!isset($_SESSION['user'])) {
    // Se non è autenticato, reindirizza al login
    header('Location: login.php');
    exit;
}

$user = $_SESSION['user'];

// Reindirizza in base al tipo di utente
switch ($user['user_type']) {
    case 'patient':
        header('Location: patient_dashboard.php');
        break;
    
    case 'therapist':
        header('Location: therapist_dashboard.php');
        break;
    
    default:
        // Tipo utente non riconosciuto, logout e redirect al login
        session_destroy();
        header('Location: login.php?error=invalid_user_type');
        break;
}

exit;
?>

<?php
// auth.php - Funzioni di autenticazione e autorizzazione

function checkAuth($requiredUserType = null) {
    if (!isset($_SESSION['user'])) {
        $_SESSION['error'] = "Devi effettuare l'accesso per visualizzare questa pagina.";
        header('Location: login.php');
        exit;
    }

    if ($requiredUserType !== null && $_SESSION['user']['user_type'] !== $requiredUserType) {
        $_SESSION['error'] = "Non hai i permessi per accedere a questa pagina.";
        if ($_SESSION['user']['user_type'] === 'patient') {
            header('Location: patient_dashboard.php');
        } else {
            header('Location: therapist_dashboard.php');
        }
        exit;
    }

    return $_SESSION['user'];
}

function isTherapist() {
    return isset($_SESSION['user']) && $_SESSION['user']['user_type'] === 'therapist';
}

function isPatient() {
    return isset($_SESSION['user']) && $_SESSION['user']['user_type'] === 'patient';
}
?>

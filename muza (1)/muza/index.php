<?php
// index.php
session_start();

// Se l'utente è già loggato, reindirizza alla dashboard corretta
if (isset($_SESSION['user'])) {
    if ($_SESSION['user']['user_type'] === 'patient') {
        header('Location: patient_dashboard.php');
    } else {
        header('Location: therapist_dashboard.php');
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Muza - Benvenuto</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Muza</h1>
    <p>
      Piattaforma di musicoterapia digitale
    </p>
    <div class="nav-buttons">
      <button class="nav-button" onclick="window.location.href='login.php'">Accedi</button>
      <button class="nav-button" onclick="window.location.href='register.php'">Registrati</button>
    </div>
  </div>
</body>
</html>
<?php
// register.php
session_start();
require_once 'includes/db.php';

// Se già loggato, reindirizza alla dashboard corretta
if (isset($_SESSION['user'])) {
    if ($_SESSION['user']['user_type'] === 'patient') {
        header('Location: patient_dashboard.php');
    } else {
        header('Location: therapist_dashboard.php');
    }
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $userType = $_POST['userType'] ?? '';

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($userType)) {
        $error = "Tutti i campi sono obbligatori.";
    } else {
        $first_name = $conn->real_escape_string($first_name);
        $last_name = $conn->real_escape_string($last_name);
        $email = $conn->real_escape_string($email);
        $password = password_hash($password, PASSWORD_DEFAULT);
        $userType = $conn->real_escape_string($userType);

        $check_email = $conn->query("SELECT id FROM users WHERE email='$email'");

        if ($check_email->num_rows > 0) {
            $error = "Email già registrata.";
        } else {
            // Generiamo un username basato sulla email
            $username = explode('@', $email)[0];
            
            $sql = "INSERT INTO users (username, first_name, last_name, email, password, user_type, created_at) VALUES ('$username', '$first_name', '$last_name', '$email', '$password', '$userType', NOW())";
            if ($conn->query($sql) === TRUE) {
                $success = "Registrazione completata con successo! Ora puoi effettuare l'accesso.";
                // NON creiamo la sessione, reindiriziamo al login
                echo "<script>
                    setTimeout(function() {
                        window.location.href = 'login.php';
                    }, 2000);
                </script>";
            } else {
                $error = "Errore durante la registrazione: " . $conn->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Registrazione - Muza</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body class="auth-body">
  <div class="login-container">
    <h2>Registrati su Muza</h2>
    
    <?php if ($error): ?>
      <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php elseif ($success): ?>
      <p class="success"><?php echo htmlspecialchars($success); ?></p>
    <?php endif; ?>
    
    <form method="POST" action="">
      <input type="text" name="first_name" placeholder="Nome" required autocomplete="given-name" />
      <input type="text" name="last_name" placeholder="Cognome" required autocomplete="family-name" />
      <input type="email" name="email" placeholder="Email" required autocomplete="email" />
      <input type="password" name="password" placeholder="Password (min 6 caratteri)" required autocomplete="new-password" minlength="6" />
      <select name="userType" required>
        <option value="" disabled selected>Seleziona tipo utente</option>
        <option value="patient" selected>Paziente</option>
        <option value="therapist">Terapeuta</option>
      </select>
      <button type="submit">Registrati</button>
    </form>
      <div class="auth-links">
      <p>Hai già un account? <a href="login.php">Accedi qui</a></p>
      <p><a href="index.php">← Torna alla home</a></p>    </div>
  </div>
</body>
</html>
<?php
// login.php - SOSTITUISCI IL TUO FILE ESISTENTE CON QUESTO
// Sistema di login con debug per troubleshooting
session_start();
require_once 'includes/db.php';

// Flash error: prendi l'errore dalla sessione e rimuovilo subito
$flash_error = '';
if (isset($_SESSION['error'])) {
  $flash_error = $_SESSION['error'];
  unset($_SESSION['error']);
}
// Se c'è un parametro ?error= nella query string, usalo come flash (mappa valori noti)
if (isset($_GET['error']) && !empty($_GET['error'])) {
  $errcode = $_GET['error'];
  switch ($errcode) {
    case 'invalid_user_type':
      $flash_error = 'Tipo utente non valido. Effettua il login con un account corretto.';
      break;
    default:
      // Fallback: mostra il valore grezzo (ma sanitizzato)
      $flash_error = htmlspecialchars($errcode);
      break;
  }
}

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];
    $userType = $conn->real_escape_string($_POST['userType']);

    // DEBUG temporaneo - rimuovi dopo aver verificato che funziona
    error_log("LOGIN ATTEMPT: Email=$email, UserType=$userType");

    $result = $conn->query("SELECT * FROM users WHERE email='$email' AND user_type='$userType'");
    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // DEBUG temporaneo
        error_log("USER FOUND: " . $user['first_name'] . " " . $user['last_name']);
        error_log("PASSWORD VERIFY: " . ($password === 'password' ? 'Testing with: password' : 'Testing with: ' . $password));
        
        if (password_verify($password, $user['password'])) {
            // DEBUG temporaneo
            error_log("PASSWORD VERIFIED SUCCESSFULLY");
            
            $_SESSION['user'] = [
                'id' => $user['id'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email'],
                'user_type' => $user['user_type']
            ];
            
            // Aggiorna ultimo login
            $conn->query("UPDATE users SET last_login = NOW() WHERE id = {$user['id']}");
            
            // DEBUG temporaneo
            error_log("SESSION CREATED, REDIRECTING TO: " . ($user['user_type'] === 'therapist' ? 'therapist_dashboard.php' : 'patient_dashboard.php'));
            
            // Reindirizza alla dashboard corretta
            $dashboard = ($user['user_type'] === 'therapist') ? 'therapist_dashboard.php' : 'patient_dashboard.php';
            header("Location: $dashboard");
            exit;
        } else {
            // DEBUG temporaneo
            error_log("PASSWORD VERIFICATION FAILED");
            $error = "Password errata";
        }
    } else {
        // DEBUG temporaneo
        error_log("USER NOT FOUND");
        $error = "Utente non trovato o tipo utente errato";
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login - Mūza</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body class="auth-body">  <div class="login-container">
    <h2>Accedi a Muza</h2>
    
    <?php if (!empty($flash_error)): ?>
      <p class="error"><?php echo htmlspecialchars($flash_error); ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
      <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    
    <form method="POST" action="">
      <input type="email" name="email" placeholder="Email" required autocomplete="username" />
      <input type="password" name="password" placeholder="Password" required autocomplete="current-password" />
      <select name="userType" required>
        <option value="" disabled selected>Seleziona tipo utente</option>
        <option value="patient">Paziente</option>
        <option value="therapist" <?php echo (isset($_GET['type']) && $_GET['type'] === 'therapist') ? 'selected' : ''; ?>>Terapeuta</option>
      </select>
      <button type="submit">Accedi</button>
    </form>
      <div class="auth-links">
      <p>Non hai un account? <a href="register.php">Registrati qui</a></p>
      <p><a href="index.php">← Torna alla home</a></p>    </div>
  </div>
  <script>
    // Nasconde tutti i messaggi di errore dopo 3 secondi
    document.addEventListener('DOMContentLoaded', function() {
      var errs = document.querySelectorAll('.error');
      errs.forEach(function(err) {
        setTimeout(function() {
          err.style.display = 'none';
        }, 3000);
      });
      // Rimuovi la query string (es. ?error=...) per evitare che il messaggio riappaia al refresh
      if (window.history && window.history.replaceState) {
        var clean = window.location.pathname + window.location.hash;
        window.history.replaceState(null, '', clean);
      }
      // Mostra il messaggio usando l'elemento .error già presente nel container (posizione originale)
      (function showExistingError() {
        var flash = <?php echo json_encode($flash_error ?: ''); ?>;
        var localErr = <?php echo json_encode($error ?: ''); ?>;
        var message = flash || localErr || '';
        if (!message) return;

        // Prendi il primo elemento .error nel DOM; se non esiste, nulla da fare
        var errEl = document.querySelector('.login-container .error') || document.querySelector('.error');
        if (!errEl) return;

        // Ripristina la posizione normale (in-flow) e applica lo stile leggibile viola
        errEl.style.position = '';
        errEl.style.top = '';
        errEl.style.right = '';
        errEl.style.background = '#5b21b6';
        errEl.style.border = '1px solid #4c1d95';
        errEl.style.color = 'white';
        errEl.style.padding = '0.75rem 1.25rem';
        errEl.style.borderRadius = '8px';
        errEl.style.minHeight = '48px';
        errEl.style.display = 'block';
        errEl.style.transform = 'translateY(-8px)';
        errEl.style.transition = 'transform 0.25s ease, opacity 0.25s ease';
        errEl.textContent = message;

        // slide down leggero
        requestAnimationFrame(function() { errEl.style.transform = 'translateY(0)'; errEl.style.opacity = '1'; });
        // lascialo al precedente timeout che nasconde .error dopo 3s
      })();
    });
  </script>
</body>
</html>
<?php 
session_start();
ob_start();

// Logout: the Logout buttons link here with ?logout=true. Clear the whole session so an
// earlier admin login can never leak into the next person who signs in on this browser.
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit();
}




require_once __DIR__ . '/includes/db_config.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Show and clear the session error message
$error_message = "";
if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    unset($_SESSION['login_error']); // Clear it right after displaying
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = strtolower(trim($_POST['username']));
    $password = trim($_POST['password']);

    // Query the database to check if the user exists and retrieve their role
    $stmt = $conn->prepare("SELECT username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Stored passwords are currently plain text (users.php's "show
        // password" admin feature depends on being able to read them back,
        // so they aren't hashed here). This also accepts a bcrypt hash via
        // password_verify() so nothing breaks if that changes later —
        // but today's accounts are compared as plain text below.
        $stored = $user['password'];
        $looksHashed = (strpos($stored, '$2y$') === 0 || strpos($stored, '$2a$') === 0);
        $passwordOk = $looksHashed ? password_verify($password, $stored) : ($password === $stored);

        if ($passwordOk) {
            unset($_SESSION['admin'], $_SESSION['cashier']);   // never keep the previous user's role
            $_SESSION['username'] = $user['username'];
            if (strtolower($user['role']) === 'cashier') {
                $_SESSION['cashier'] = true;
                header("Location: staff.php");
            } else {
                $_SESSION['admin'] = true;
                header("Location: admin.php");
            }
            exit();
        } else {
            $_SESSION['login_error'] = "Incorrect password.";
        }
    } else {
        $_SESSION['login_error'] = "User not found.";
    }

    $stmt->close();
    $conn->close();
    header("Location: " . $_SERVER['PHP_SELF']); // Redirect back to login to show error once
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in · Stella</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="assets/style.css">
  <style>
    :root {
      --bg: #dcefe0;
      --surface: #ffffff;
      --primary: #2e7d32;
      --primary-dark: #1b5e20;
      --accent: #43a047;
      --text: #1f2b20;
      --text-muted: #5c6b5e;
      --border: #dcebe0;
      --danger: #e0364a;
      --danger-bg: #fdeced;
      --radius-lg: 20px;
      --shadow-lg: 0 16px 40px rgba(20, 25, 50, 0.14);
      --font: "Segoe UI", -apple-system, BlinkMacSystemFont, "Inter", Roboto, Helvetica, Arial, sans-serif;
    }

    * { box-sizing: border-box; }

    html, body { height: 100%; }

    body {
      font-family: var(--font) !important;
      margin: 0;
      padding: 24px;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      color: var(--text);
      background:
        radial-gradient(560px circle at 12% 8%, rgba(67, 160, 71, 0.16), transparent 60%),
        radial-gradient(620px circle at 92% 96%, rgba(27, 94, 32, 0.14), transparent 55%),
        var(--bg) !important;
      overflow: hidden;
    }

    body::before,
    body::after {
      content: "";
      position: fixed;
      border-radius: 50%;
      filter: blur(60px);
      z-index: 0;
      pointer-events: none;
    }
    body::before {
      width: 320px;
      height: 320px;
      top: -80px;
      left: -100px;
      background: rgba(67, 160, 71, 0.28);
    }
    body::after {
      width: 380px;
      height: 380px;
      bottom: -120px;
      right: -110px;
      background: rgba(27, 94, 32, 0.22);
    }

    .login-wrap {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
      max-width: 380px;
      animation: rise 0.5s ease both;
    }

    @keyframes rise {
      from { opacity: 0; transform: translateY(14px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .logo-badge {
      width: 108px;
      height: 108px;
      border-radius: 26px;
      overflow: hidden;
      box-shadow: 0 10px 24px rgba(20, 25, 50, 0.18), 0 0 0 6px var(--surface);
      margin-bottom: -34px;
      z-index: 2;
      background: var(--surface);
    }
    .logo-badge img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .login-container {
      background: var(--surface);
      padding: 52px 34px 34px;
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lg);
      text-align: center;
      width: 100%;
    }

    .login-heading {
      font-size: 21px;
      font-weight: 700;
      color: var(--text);
      margin: 0 0 4px;
    }

    .login-subtitle {
      font-size: 13.5px;
      color: var(--text-muted);
      margin: 0 0 26px;
    }

    .field {
      text-align: left;
      margin-bottom: 16px;
    }

    label {
      display: block;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin: 0 0 6px 2px;
    }

    .input-shell {
      position: relative;
      display: flex;
      align-items: center;
    }

    .input-shell .field-icon {
      position: absolute;
      left: 14px;
      color: var(--text-muted);
      font-size: 14px;
      pointer-events: none;
      z-index: 1;
    }

    /* the shared stylesheet applies !important padding/border/radius to every
       input[type="text"/"password"] on the site, so these overrides need
       matching !important + the .login-container scope to win reliably */
    .login-container input[type="text"],
    .login-container input[type="password"] {
      width: 100% !important;
      padding: 12px 14px 12px 40px !important;
      border: 1.5px solid var(--border) !important;
      border-radius: 12px !important;
      font-size: 15px !important;
      font-family: var(--font);
      color: var(--text);
      background: #fbfdfb;
      transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }

    .login-container input[type="text"]:focus,
    .login-container input[type="password"]:focus {
      outline: none !important;
      border-color: var(--accent) !important;
      background: #ffffff;
      box-shadow: 0 0 0 4px rgba(67, 160, 71, 0.15) !important;
    }

    .password-container {
      position: relative;
      width: 100%;
    }

    /* a <span> (not <button>) on purpose — the shared stylesheet applies
       !important background/padding to every <button>, which turned this
       into a big green pill. A span with role="button" keeps it a small
       icon-only control while staying keyboard accessible. */
    .pw-toggle {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      font-size: 14px;
      color: var(--text-muted);
      background: transparent;
      width: 28px;
      height: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      transition: color 0.15s ease, background 0.15s ease;
    }
    .pw-toggle:hover { color: var(--primary-dark); background: rgba(67, 160, 71, 0.12); }
    .pw-toggle:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    input[type="submit"] {
      width: 100%;
      background: linear-gradient(180deg, var(--accent) 0%, var(--primary-dark) 100%);
      color: #ffffff;
      border: none;
      cursor: pointer;
      font-size: 15.5px;
      font-weight: 700;
      letter-spacing: 0.02em;
      padding: 13px;
      border-radius: 12px;
      margin-top: 6px;
      box-shadow: 0 8px 18px rgba(27, 94, 32, 0.28);
      transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
      font-family: var(--font);
    }

    input[type="submit"]:hover {
      filter: brightness(1.06);
      transform: translateY(-1px);
      box-shadow: 0 10px 22px rgba(27, 94, 32, 0.34);
    }

    input[type="submit"]:active {
      transform: translateY(0);
      box-shadow: 0 6px 14px rgba(27, 94, 32, 0.28);
    }

    input[type="submit"]:focus-visible {
      outline: 2px solid var(--primary-dark);
      outline-offset: 2px;
    }

    /* named .login-alert (not .error-message) — the shared stylesheet
       already uses .error-message for a fixed-position toast, and reusing
       that name here would put this banner in the top-center of the
       viewport instead of inline under the form */
    .login-alert {
      display: flex;
      align-items: center;
      gap: 8px;
      color: var(--danger) !important;
      background: var(--danger-bg) !important;
      border: 1px solid rgba(224, 54, 74, 0.25);
      border-radius: 10px !important;
      margin: 18px 0 0;
      padding: 10px 12px !important;
      font-size: 13.5px !important;
      font-weight: 500 !important;
      text-align: left;
      position: static !important;
      transform: none !important;
      transition: opacity 0.3s ease;
    }

    .login-footer {
      margin-top: 22px;
      font-size: 12px;
      color: var(--text-muted);
      opacity: 0.85;
    }

    @media (max-width: 420px) {
      .login-container { padding: 48px 22px 28px; }
    }

    @media (prefers-reduced-motion: reduce) {
      .login-wrap { animation: none; }
      input, .pw-toggle, input[type="submit"] { transition: none; }
    }
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="logo-badge">
      <img src="assets/images/stellaph.jpg" alt="Stella logo">
    </div>
    <div class="login-container">
      <h1 class="login-heading">Welcome back</h1>
      <p class="login-subtitle">Sign in to your Stella account</p>

      <form id="loginForm" action="" method="POST" novalidate>
        <div class="field">
          <label for="username">Username</label>
          <div class="input-shell">
            <i class="fas fa-user field-icon"></i>
            <input type="text" name="username" id="username" autocomplete="username" required>
          </div>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="input-shell password-container">
            <i class="fas fa-lock field-icon"></i>
            <input type="password" name="password" id="password" autocomplete="current-password" required>
            <span id="togglePassword" class="pw-toggle" role="button" tabindex="0" aria-label="Show password" aria-pressed="false">
              <i class="fas fa-eye"></i>
            </span>
          </div>
        </div>

        <input type="submit" id="loginBtn" value="Login">
      </form>

      <?php if (!empty($error_message)): ?>
      <p class="login-alert" id="error-message"><i class="fas fa-circle-exclamation"></i><?= htmlspecialchars($error_message) ?></p>
      <?php endif; ?>
    </div>
    <p class="login-footer">&copy; <?= date('Y') ?> Stella</p>
  </div>

  <script>
document.addEventListener("DOMContentLoaded", function () {
  const togglePassword = document.getElementById("togglePassword");
  const passwordInput = document.getElementById("password");
  const errorMessage = document.getElementById("error-message");

  function togglePasswordVisibility() {
    const showing = passwordInput.getAttribute("type") === "text";
    passwordInput.setAttribute("type", showing ? "password" : "text");
    togglePassword.querySelector("i").className = showing ? "fas fa-eye" : "fas fa-eye-slash";
    togglePassword.setAttribute("aria-label", showing ? "Show password" : "Hide password");
    togglePassword.setAttribute("aria-pressed", String(!showing));
  }

  togglePassword.addEventListener("click", togglePasswordVisibility);
  togglePassword.addEventListener("keydown", function (e) {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      togglePasswordVisibility();
    }
  });

  if (errorMessage) {
    setTimeout(() => {
      errorMessage.style.opacity = "0";
      setTimeout(() => errorMessage.remove(), 300);
    }, 5000);
  }
});
</script>

</body>
</html>
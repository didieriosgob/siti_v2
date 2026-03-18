<?php
session_start();
$pdo = require __DIR__ . '/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$user, $pass] = [$_POST['user'] ?? '', $_POST['pass'] ?? ''];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=?");
    $stmt->execute([$user]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && password_verify($pass, $row['passhash'])) {
        $_SESSION['uid']  = $row['id'];
        $_SESSION['username'] = $row['username']; 
        $_SESSION['role'] = $row['role'];
        header('Location: index.php');
        exit;
    }
    $error = 'Credenciales incorrectas';
}
?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8"><title>Login tickets</title>
  <link rel="shortcut icon" href="siti_logo.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="styles.css" rel="stylesheet">
</head>

<body class="bg-secoed d-flex justify-content-center align-items-center min-vh-100 py-4">
  <div class="login-shell">
    <div class="login-card">
      <div class="login-brand">
        <div class="login-brand__row">
        </div>
        <div class="login-brand__eyebrow">Secretaría de Contraloría y Transparencia Gubernamental del Estado</div>
        <h1 class="login-brand__title">SITI</h1>
        <p class="login-brand__subtitle">Sistema Integral de Tickets Informático</p>
        <p class="login-brand__copy">Acceso institucional para administración, seguimiento y consulta de tickets.</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <div class="mb-3 text-start">
          <label class="form-label">Usuario</label>
          <input name="user" class="form-control" autocomplete="username" required>
        </div>
        <div class="mb-4 text-start">
          <label class="form-label">Contraseña</label>
          <input type="password" name="pass" class="form-control" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary w-100">Entrar</button>
      </form>
    </div>
  </div>
</body>


</html>

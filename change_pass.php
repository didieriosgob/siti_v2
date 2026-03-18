<?php
/*************** change_pass.php – Cambiar contraseña ***************/
session_start();
if (!isset($_SESSION['uid'])) {
    header('Location: login.php');
    exit;
}

$pdo   = require __DIR__ . '/db.php';
$msg   = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old = trim($_POST['old'] ?? '');
    $new = trim($_POST['new'] ?? '');

    /* Obtener hash actual */
    $stmt = $pdo->prepare("SELECT passhash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['uid']]);
    $hash = $stmt->fetchColumn();

    /* Validaciones */
    if (!password_verify($old, $hash)) {
        $error = 'Contraseña actual incorrecta.';
    } elseif (strlen($new) < 6) {
        $error = 'La nueva contraseña debe tener al menos 6 caracteres.';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET passhash = ? WHERE id = ?");
        $stmt->execute([
            password_hash($new, PASSWORD_DEFAULT),
            $_SESSION['uid']
        ]);
        $msg = '✅ Contraseña actualizada.';
    }
}
?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8">
  <title>Cambiar contraseña</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="styles.css" rel="stylesheet">  <!-- contiene .auth-card / gradientes -->
</head>
<body class="bg-secoed d-flex align-items-center justify-content-center min-vh-100 py-4">
  <div class="login-shell">
    <div class="auth-card">
      <div class="login-brand">
        <div class="login-brand__row">
        </div>
        <div class="login-brand__eyebrow">SECOTED · Seguridad de acceso</div>
        <h1 class="login-brand__title">SITI</h1>
        <p class="login-brand__subtitle">Actualizar contraseña</p>
        <p class="login-brand__copy">Protege tu acceso al sistema con una contraseña nueva y segura.</p>
      </div>

      <?php if ($msg):   ?><div class="alert alert-success"><?= $msg   ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

      <form method="post" novalidate>
        <div class="mb-3 text-start">
          <label class="form-label">Contraseña actual</label>
          <input type="password" name="old" class="form-control" required>
        </div>

        <div class="mb-4 text-start">
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="new" class="form-control" required>
        </div>

        <button class="btn btn-primary w-100">Actualizar</button>
      </form>

      <a href="index.php" class="btn btn-link mt-3">← Volver al panel</a>
    </div>
  </div>
</body></html>

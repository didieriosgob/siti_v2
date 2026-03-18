<?php
/*****************************************************************
 * edit_user.php – Editar o eliminar usuarios (solo admin)
 *****************************************************************/
session_start();
if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pdo = require __DIR__ . '/db.php';
require __DIR__ . '/user_profile.php';
ensureUserProfileColumns($pdo);

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, username, COALESCE(full_name,'') AS full_name, role, COALESCE(is_social_service,0) AS is_social_service FROM users WHERE id=?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "Usuario inexistente";
    exit;
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if ($user['id'] == $_SESSION['uid']) {
        $error = 'No puedes eliminar tu propia cuenta.';
    } else {
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($user['role'] === 'admin' && $admins <= 1) {
            $error = 'Debe existir al menos un administrador.';
        }
    }

    if (!$error) {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        header('Location: register.php?msg=deleted');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $newUser = trim($_POST['username'] ?? '');
    $newFullName = trim($_POST['full_name'] ?? '');
    $validRoles = ['admin', 'user', 'guest'];
    $newRole = in_array($_POST['role'] ?? 'user', $validRoles, true) ? $_POST['role'] : 'user';
    $newSocialService = isset($_POST['is_social_service']) ? 1 : 0;

    if ($newUser === '' || $newFullName === '') {
        $error = 'Nombre completo y usuario no pueden quedar vacíos.';
    } else {
        $dup = $pdo->prepare("SELECT id FROM users WHERE username=? AND id<>?");
        $dup->execute([$newUser, $id]);
        if ($dup->fetch()) {
            $error = 'Ese nombre de usuario ya está en uso.';
        }
    }

    if (!$error) {
        $pdo->prepare("UPDATE users SET username=?, full_name=?, role=?, is_social_service=? WHERE id=?")
            ->execute([$newUser, $newFullName, $newRole, $newSocialService, $id]);

        if ($id == $_SESSION['uid']) {
            $_SESSION['username'] = $newUser;
        }

        $user['username'] = $newUser;
        $user['full_name'] = $newFullName;
        $user['role'] = $newRole;
        $user['is_social_service'] = $newSocialService;
        $msg = 'Cambios guardados correctamente.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Editar usuario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="styles.css" rel="stylesheet">
  <link rel="shortcut icon" href="siti_logo.ico">
</head>
<body class="bg-secoed">

<div class="container py-4 py-lg-5">
  <section class="sigei-hero hero-admin mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
      <div>
        <a href="register.php" class="btn btn-ghost mb-3"><i class="bi bi-arrow-left"></i> Volver a usuarios</a>
        <div class="brand-lockup justify-content-start mb-3">
          <span class="brand-lockup__siti"><img src="siti_logo_blanco.png" alt="SITI"></span>
        </div>
        <div class="eyebrow">Edición segura</div>
        <h1 class="hero-title-sm mb-2">Editar perfil de usuario</h1>
        <p class="hero-copy mb-0">Actualiza el nombre visible, rol y clasificación de servicio social sin tocar contraseñas ni borrar historial.</p>
      </div>
      <div class="hero-stat-card narrow">
        <div class="hero-stat-label">Usuario actual</div>
        <div class="hero-stat-mini"><?= htmlspecialchars($user['username']) ?></div>
        <div class="hero-stat-foot">ID <?= (int)$user['id'] ?></div>
      </div>
    </div>
  </section>

  <div class="row g-4 align-items-start">
    <div class="col-lg-7">
      <div class="panel-card">
        <div class="panel-card__header">
          <div>
            <div class="panel-card__eyebrow">Datos del usuario</div>
            <h2 class="panel-card__title">Información general</h2>
          </div>
          <span class="pill pill-neutral"><i class="bi bi-sliders"></i> Edición</span>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post" class="vstack gap-3">
          <div>
            <label class="form-label">Nombre completo</label>
            <input name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
          </div>

          <div>
            <label class="form-label">Usuario</label>
            <div class="input-group input-group-soft">
              <span class="input-group-text"><i class="bi bi-at"></i></span>
              <input name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
            </div>
          </div>

          <div>
            <label class="form-label">Rol</label>
            <select name="role" class="form-select">
              <option value="guest" <?= $user['role'] === 'guest' ? 'selected' : '' ?>>Consulta</option>
              <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Operativo</option>
              <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
            </select>
          </div>

          <label class="soft-check">
            <input type="checkbox" name="is_social_service" value="1" <?= (int)$user['is_social_service'] === 1 ? 'checked' : '' ?>>
            <span>
              <strong>Servicio social</strong>
              <small>Se muestra una insignia especial en el directorio de usuarios.</small>
            </span>
          </label>

          <div class="d-flex flex-column flex-md-row gap-3 pt-2">
            <button name="save" class="btn btn-primary flex-fill"><i class="bi bi-floppy"></i> Guardar cambios</button>
            <a href="register.php" class="btn btn-soft flex-fill"><i class="bi bi-grid"></i> Ver directorio</a>
          </div>
        </form>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="panel-card">
        <div class="panel-card__header">
          <div>
            <div class="panel-card__eyebrow">Vista previa</div>
            <h2 class="panel-card__title">Cómo se verá</h2>
          </div>
        </div>

        <?php $roleClass = $user['role'] === 'admin' ? 'pill-admin' : ($user['role'] === 'user' ? 'pill-user' : 'pill-guest'); ?>
        <article class="user-card-professional <?= (int)$user['is_social_service'] === 1 ? 'social-service' : '' ?> mb-4">
          <div class="user-card-professional__top">
            <div>
              <div class="user-card-professional__name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
              <div class="user-card-professional__username">@<?= htmlspecialchars($user['username']) ?></div>
            </div>
            <span class="btn btn-soft-icon disabled" aria-hidden="true"><i class="bi bi-person"></i></span>
          </div>
          <div class="user-card-professional__meta">
            <span class="pill <?= $roleClass ?>"><i class="bi bi-person-workspace"></i> <?= htmlspecialchars(userRoleLabel($user['role'])) ?></span>
            <?php if ((int)$user['is_social_service'] === 1): ?>
              <span class="pill pill-social"><i class="bi bi-award"></i> Servicio social</span>
            <?php else: ?>
              <span class="pill pill-neutral"><i class="bi bi-briefcase"></i> Personal interno</span>
            <?php endif; ?>
          </div>
        </article>

        <form method="post" onsubmit="return confirm('¿Eliminar definitivamente al usuario <?= htmlspecialchars($user['username']) ?>?');">
          <input type="hidden" name="delete" value="1">
          <button class="btn btn-danger w-100"><i class="bi bi-trash3"></i> Eliminar usuario</button>
        </form>
        <p class="text-muted small mt-3 mb-0">La eliminación sigue protegida: no puedes borrarte a ti mismo ni dejar al sistema sin administradores.</p>
      </div>
    </div>
  </div>
</div>

</body>
</html>

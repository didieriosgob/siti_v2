<?php
/********  register.php – Administración de usuarios (sólo admin) *******/
session_start();
if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pdo = require __DIR__ . '/db.php';
require __DIR__ . '/user_profile.php';
ensureUserProfileColumns($pdo);

$msg = '';
$error = '';
$user = '';
$fullName = '';
$role = 'user';
$isSocialService = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $pass = trim($_POST['pass'] ?? '');
    $validRoles = ['admin', 'user', 'guest'];
    $role = $_POST['role'] ?? 'user';
    if (!in_array($role, $validRoles, true)) {
        $role = 'user';
    }
    $isSocialService = isset($_POST['is_social_service']) ? 1 : 0;

    if ($fullName === '' || $user === '' || $pass === '') {
        $error = 'Nombre completo, usuario y contraseña son obligatorios.';
    } else {
        try {
            $pdo->prepare("INSERT INTO users (username, full_name, passhash, role, is_social_service) VALUES (?, ?, ?, ?, ?)")
                ->execute([
                    $user,
                    $fullName,
                    password_hash($pass, PASSWORD_DEFAULT),
                    $role,
                    $isSocialService,
                ]);

            $msg = "Usuario creado correctamente.";
            $user = '';
            $fullName = '';
            $role = 'user';
            $isSocialService = 0;
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000'
                ? 'Ese nombre de usuario ya existe.'
                : 'No fue posible guardar el usuario: ' . $e->getMessage();
        }
    }
}

$users = $pdo->query(
    "SELECT id, username, COALESCE(full_name, '') AS full_name, role, COALESCE(is_social_service, 0) AS is_social_service
       FROM users
   ORDER BY role='admin' DESC, full_name ASC, username ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Administrador de usuarios</title>
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
        <a href="index.php" class="btn btn-ghost mb-3"><i class="bi bi-arrow-left"></i> Volver al panel</a>
        <div class="brand-lockup justify-content-start mb-3">
          <span class="brand-lockup__siti"><img src="siti_logo_blanco.png" alt="SITI"></span>
        </div>
        <div class="eyebrow">Administración interna</div>
        <h1 class="hero-title-sm mb-2">Administrador de usuarios</h1>
        <p class="hero-copy mb-0">Alta, edición y control del personal con una vista más limpia: nombre completo, rol y señalización de servicio social.</p>
      </div>
      <div class="hero-stat-card">
        <div class="hero-stat-label">Usuarios registrados</div>
        <div class="hero-stat-value"><?= count($users) ?></div>
        <div class="hero-stat-foot">Panel profesional, sin las tarjetitas flacas del pasado jurásico.</div>
      </div>
    </div>
  </section>

  <div class="row g-4 align-items-start">
    <div class="col-xl-4 col-lg-5">
      <div class="panel-card sticky-card">
        <div class="panel-card__header">
          <div>
            <div class="panel-card__eyebrow">Nuevo registro</div>
            <h2 class="panel-card__title">Crear usuario</h2>
          </div>
          <span class="pill pill-neutral"><i class="bi bi-person-plus"></i> Alta</span>
        </div>

        <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post" class="vstack gap-3" novalidate>
          <div>
            <label class="form-label">Nombre completo</label>
            <input name="full_name" class="form-control" value="<?= htmlspecialchars($fullName) ?>" required>
          </div>

          <div>
            <label class="form-label">Usuario</label>
            <div class="input-group input-group-soft">
              <span class="input-group-text"><i class="bi bi-at"></i></span>
              <input name="user" class="form-control" value="<?= htmlspecialchars($user) ?>" required>
            </div>
          </div>

          <div>
            <label class="form-label">Contraseña</label>
            <div class="input-group input-group-soft">
              <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
              <input type="password" name="pass" class="form-control" required>
            </div>
          </div>

          <div>
            <label class="form-label">Rol</label>
            <select name="role" class="form-select">
              <option value="guest" <?= $role === 'guest' ? 'selected' : '' ?>>Consulta</option>
              <option value="user" <?= $role === 'user' ? 'selected' : '' ?>>Operativo</option>
              <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administrador</option>
            </select>
          </div>

          <label class="soft-check">
            <input type="checkbox" name="is_social_service" value="1" <?= $isSocialService ? 'checked' : '' ?>>
            <span>
              <strong>Servicio social</strong>
              <small>Activa esta opción para diferenciarlo visualmente dentro del panel.</small>
            </span>
          </label>

          <button class="btn btn-primary btn-lg w-100"><i class="bi bi-save2"></i> Guardar usuario</button>
        </form>
      </div>
    </div>

    <div class="col-xl-8 col-lg-7">
      <div class="panel-card">
        <div class="panel-card__header panel-card__header--spaced">
          <div>
            <div class="panel-card__eyebrow">Directorio interno</div>
            <h2 class="panel-card__title">Equipo registrado</h2>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <span class="pill pill-neutral"><i class="bi bi-person-badge"></i> Nombre completo visible</span>
            <span class="pill pill-neutral"><i class="bi bi-funnel"></i> Roles diferenciados</span>
          </div>
        </div>

        <div class="users-grid">
          <?php foreach ($users as $u): ?>
            <?php
              $roleClass = $u['role'] === 'admin' ? 'pill-admin' : ($u['role'] === 'user' ? 'pill-user' : 'pill-guest');
              $full = trim($u['full_name']) !== '' ? $u['full_name'] : $u['username'];
            ?>
            <article class="user-card-professional <?= (int)$u['is_social_service'] === 1 ? 'social-service' : '' ?>">
              <div class="user-card-professional__top">
                <div>
                  <div class="user-card-professional__name"><?= htmlspecialchars($full) ?></div>
                  <div class="user-card-professional__username">@<?= htmlspecialchars($u['username']) ?></div>
                </div>
                <a href="edit_user.php?id=<?= (int)$u['id'] ?>" class="btn btn-soft-icon" title="Editar usuario" aria-label="Editar usuario">
                  <i class="bi bi-pencil-square"></i>
                </a>
              </div>

              <div class="user-card-professional__meta">
                <span class="pill <?= $roleClass ?>"><i class="bi bi-person-workspace"></i> <?= htmlspecialchars(userRoleLabel($u['role'])) ?></span>
                <?php if ((int)$u['is_social_service'] === 1): ?>
                  <span class="pill pill-social"><i class="bi bi-award"></i> Servicio social</span>
                <?php else: ?>
                  <span class="pill pill-neutral"><i class="bi bi-briefcase"></i> Personal interno</span>
                <?php endif; ?>
              </div>

              <div class="user-card-professional__footer">
                <span>ID <?= (int)$u['id'] ?></span>
                <span><?= htmlspecialchars($u['role']) ?></span>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

</body>
</html>

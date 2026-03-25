<?php
session_start();
$role = $_SESSION['role'] ?? 'guest';
if (!in_array($role, ['admin', 'user'])) {
    header('Location: login.php');
    exit;
}

$pdo = require __DIR__ . '/db.php';
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT id, user_name, department, direction, asset_number,
           equipment_type, brand, description, solucion,
           created_at, status, attended_by, attended_at
      FROM tickets
     WHERE id = ?
     LIMIT 1
");
$stmt->execute([$id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    http_response_code(404);
}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$users = $pdo->query("
    SELECT id, username
    FROM users
    WHERE role IN ('admin','user')
    ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

$teamStmt = $pdo->prepare("
    SELECT ta.user_id, ta.username_snapshot, ta.is_primary
    FROM ticket_attendees ta
    WHERE ta.ticket_id = ?
    ORDER BY ta.is_primary DESC, ta.username_snapshot ASC
");
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_team'])) {
    $selected = array_map('intval', $_POST['attendees'] ?? []);
    $selected = array_values(array_unique(array_filter($selected)));

    if (count($selected) < 1) {
        $error = 'Debes seleccionar al menos una persona.';
    } elseif (count($selected) > 3) {
        $error = 'Solo se permiten máximo 3 personas por ticket.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM ticket_attendees WHERE ticket_id = ?")->execute([$id]);

            $primaryUsername = null;

            foreach ($selected as $index => $userId) {
                $uStmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $uStmt->execute([$userId]);
                $uName = $uStmt->fetchColumn();

                if (!$uName) {
                    throw new RuntimeException('Uno de los usuarios seleccionados no existe.');
                }

                $pdo->prepare("
                    INSERT INTO ticket_attendees (ticket_id, user_id, username_snapshot, is_primary)
                    VALUES (?, ?, ?, ?)
                ")->execute([$id, $userId, $uName, $index === 0 ? 1 : 0]);

                if ($index === 0) {
                    $primaryUsername = $uName;
                }
            }

            $pdo->prepare("
                UPDATE tickets
                   SET attended_by = ?
                 WHERE id = ?
            ")->execute([$primaryUsername, $id]);

            $pdo->commit();
            header("Location: ticket_view.php?id=".$id);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
$teamStmt->execute([$id]);
$team = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
$currentTeamIds = array_map(fn($r) => (int)$r['user_id'], $team);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Detalle de ticket</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="shortcut icon" href="siti_logo.ico">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="styles.css" rel="stylesheet">
</head>
<body class="bg-secoed container py-4">
<?php
  $brand_back_href = 'index.php';
  $brand_back_text = 'Panel';
  $brand_eyebrow   = 'SECOED · Consulta de ticket';
  $brand_title     = 'Detalle del ticket';
  $brand_subtitle  = 'Visualiza la información completa del ticket, su estatus actual y la atención registrada.';
  $brand_badge     = 'Consulta individual';
  require __DIR__ . '/brand_header.php';
?>

  <div class="ticket-card mx-auto" style="max-width:960px;">
    <?php if (!$ticket): ?>
      <div class="alert alert-danger mb-0">No se encontró el ticket solicitado.</div>
    <?php else: ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
          <h1 class="h3 mb-1">Ticket #<?= (int)$ticket['id'] ?></h1>
          <div class="text-muted">Creado el <?= h($ticket['created_at']) ?></div>
        </div>
        <div>
          <?php if ($ticket['status'] === 'Atendido'): ?>
            <span class="badge bg-success">Atendido</span>
          <?php elseif ($ticket['status'] === 'En camino'): ?>
            <span class="badge bg-warning text-dark">En camino</span>
          <?php else: ?>
            <span class="badge bg-secondary">Pendiente</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-md-6"><strong>Solicitante:</strong><br><?= h($ticket['user_name']) ?></div>
        <div class="col-md-6"><strong>Departamento:</strong><br><?= h($ticket['department']) ?></div>
        <div class="col-md-6"><strong>Dirección:</strong><br><?= h($ticket['direction']) ?></div>
        <div class="col-md-6"><strong>Patrimonio:</strong><br><?= h($ticket['asset_number']) ?></div>
        <div class="col-md-6"><strong>Tipo de equipo:</strong><br><?= h($ticket['equipment_type']) ?></div>
        <div class="col-md-6"><strong>Marca:</strong><br><?= h($ticket['brand']) ?></div>
        <div class="col-md-12"><strong>Descripción del problema:</strong><div class="mt-2 p-3 rounded-4 bg-light"><?= nl2br(h($ticket['description'])) ?></div></div>
        <div class="col-md-12"><strong>Solución:</strong><div class="mt-2 p-3 rounded-4 bg-light"><?= $ticket['solucion'] ? nl2br(h($ticket['solucion'])) : '<span class="text-muted">Sin captura todavía.</span>' ?></div></div>
      </div>

      <hr class="my-4">
<div class="mt-4">
  <h2 class="h5">Equipo de atención</h2>

  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-12">
      <label class="form-label">Selecciona hasta 3 usuarios</label>
      <select name="attendees[]" class="form-select" multiple size="8" required>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $currentTeamIds, true) ? 'selected' : '' ?>>
            <?= h($u['username']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">El primero de la lista quedará como responsable principal.</div>
    </div>
    <div class="col-12">
      <button type="submit" name="save_team" value="1" class="btn btn-primary">
        Guardar equipo
      </button>
    </div>
  </form>

  <?php if ($team): ?>
    <div class="mt-3">
      <?php foreach ($team as $member): ?>
        <span class="badge bg-secondary me-1">
          <?= h($member['username_snapshot']) ?><?= $member['is_primary'] ? ' · principal' : '' ?>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
      <div class="row g-3">
        <div class="col-md-4"><strong>Estatus actual:</strong><br><?= h($ticket['status']) ?></div>
        <div class="col-md-12">
          <strong>Atendido por:</strong><br>
          <?php if ($team): ?>
            <?= h(implode(', ', array_column($team, 'username_snapshot'))) ?>
          <?php else: ?>
            <span class="text-muted">Sin asignar</span>
          <?php endif; ?>
        </div>
        <div class="col-md-4"><strong>Fecha de atención:</strong><br><?= h($ticket['attended_at'] ?: 'Pendiente') ?></div>
      </div>

      <div class="mt-4 d-flex gap-2 flex-wrap">
        <a href="index.php#tickets" class="btn btn-primary">Volver al listado</a>
        <a href="export_tickets_excel.php" class="btn btn-outline-primary">Exportar tickets</a>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>

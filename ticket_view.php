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

      <div class="row g-3">
        <div class="col-md-4"><strong>Estatus actual:</strong><br><?= h($ticket['status']) ?></div>
        <div class="col-md-4"><strong>Atendido por:</strong><br><?= h($ticket['attended_by'] ?: 'Sin asignar') ?></div>
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

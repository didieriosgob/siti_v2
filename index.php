<?php
/********************************************************************
 * index.php — Sistema de Tickets · Informática
 * ------------------------------------------------------------------
 * • Formulario público + validador de duplicados
 * • Consulta de ticket por folio (sin login)
 * • Selector Dirección → Departamento (BD + AJAX)
 * • Panel privado (admin & user): Ver 👁 | Atender ✔
 * • Sólo admin puede Eliminar 🗑
 * • Registro “Atendido por” + PRG
 * • Mejoras:
 *   - Pop-up centrado (Modal) al guardar ticket, con #Ticket y aviso de requisición
 *   - Fix: opciones Dirección/Departamento (sin escapes rotos)
 ********************************************************************/



/*── 0. Sesión + conexión ──────────────────────────────────────────*/
session_start();
if (empty($_SESSION['role']) || empty($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

$role     = $_SESSION['role'];
$username = $_SESSION['username'];
$pdo      = require __DIR__ . '/db.php';
$errors = []; 
$GUEST_CREATION_ONLY = ($role === 'guest');
/*── Config: Límite semanal por patrimonio ─────────────────────*/
const TICKET_WEEK_LIMIT = 3;          // máximo por semana
const TICKET_WEEK_MODE  = 'iso';      // 'iso' | 'rolling'
const TICKET_SKIP_ASSET = 'SIN-PATRIMONIO'; // no aplica a “rápidos”
const TICKET_LIMIT_ASSETS  = ['309-007198']; // ← SOLO aplica a este patrimonio
$errors = [];
$limitBlock = null;  // datos para mostrar el modal grande de bloqueo

/*── 0-bis · Flash-message ───────────────────────────────────────*/
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

// Flash específico del ticket guardado (para modal)
$flashTicket = $_SESSION['flash_ticket'] ?? null;
unset($_SESSION['flash_ticket']);



/*── 1. Acciones rápidas (ack/done/del) ─────────────────────────*/
if (in_array($role, ['admin','user']) && isset($_GET['done'])) {
    $pdo->prepare("
        UPDATE tickets
           SET status='Atendido',
               attended_by=?,
               attended_at=CURRENT_TIMESTAMP
         WHERE id=?")->execute([$username, (int)$_GET['done']]);
    header('Location: index.php'); exit;
}

/* 1-C · Marcar Visto / En camino + registrar quién lo vio */
if (in_array($role, ['admin','user']) && isset($_GET['ack'])) {
    $pdo->prepare("
        UPDATE tickets
           SET status       = 'En camino',
               attended_by  = ?,        -- guarda el usuario
               attended_at  = NULL      -- aún no concluye
         WHERE id = ?  AND status='Pendiente'
    ")->execute([$username, (int)$_GET['ack']]);
    header('Location: index.php'); exit;
}

if ($role === 'admin' && isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM tickets WHERE id=?")
        ->execute([(int)$_GET['del']]);
    header('Location: index.php'); exit;
}

if (in_array($role, ['admin','user']) && isset($_POST['done_ticket_id'])) {
    $ticketId  = (int)($_POST['done_ticket_id'] ?? 0);
    $attendees = array_map('intval', $_POST['attendees'] ?? []);
    $attendees = array_values(array_unique(array_filter($attendees)));

    if ($ticketId <= 0) {
        $_SESSION['flash_success'] = 'Error: ticket inválido.';
        header('Location: index.php#tickets');
        exit;
    }

    if (count($attendees) < 1) {
        $_SESSION['flash_success'] = 'Error: selecciona al menos una persona.';
        header('Location: index.php#tickets');
        exit;
    }

    if (count($attendees) > 3) {
        $_SESSION['flash_success'] = 'Error: solo puedes seleccionar máximo 3 personas.';
        header('Location: index.php#tickets');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM ticket_attendees WHERE ticket_id = ?")
            ->execute([$ticketId]);

        $currentUserStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $currentUserStmt->execute([$username]);
        $currentUserId = (int)$currentUserStmt->fetchColumn();

        if ($currentUserId > 0) {
            $attendees = array_values(array_unique(array_merge([$currentUserId], $attendees)));
            $attendees = array_slice($attendees, 0, 3);
        }

        $primaryUsername = '';

        foreach ($attendees as $i => $userId) {
            $stmtUser = $pdo->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
            $stmtUser->execute([$userId]);
            $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$userRow) {
                throw new RuntimeException('Uno de los usuarios seleccionados no existe.');
            }

            $isPrimary = ($i === 0) ? 1 : 0;

            $pdo->prepare("
                INSERT INTO ticket_attendees (ticket_id, user_id, username_snapshot, is_primary)
                VALUES (?, ?, ?, ?)
            ")->execute([
                $ticketId,
                (int)$userRow['id'],
                $userRow['username'],
                $isPrimary
            ]);

            if ($isPrimary) {
                $primaryUsername = $userRow['username'];
            }
        }

        $pdo->prepare("
            UPDATE tickets
               SET status = 'Atendido',
                   attended_by = ?,
                   attended_at = CURRENT_TIMESTAMP
             WHERE id = ?
        ")->execute([$primaryUsername, $ticketId]);

        $pdo->commit();
        $_SESSION['flash_success'] = '✅ Ticket marcado como atendido.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_success'] = 'Error: ' . $e->getMessage();
    }

    header('Location: index.php#tickets');
    exit;
}

/*── 2. Alta de ticket (POST principal) ─────────────────────────*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST['immediate'])
    && !isset($_POST['folio_lookup'])
    && !isset($_POST['name_lookup'])
    && !isset($_POST['done_ticket_id'])
) {

  /* Campos obligatorios -------------------------------------------------- */
  $labels = [
    'user_name'      => 'Nombre del usuario',
    'department'     => 'Departamento',
    'direction'      => 'Dirección',
    'asset_number'   => 'Número de patrimonio',
    'equipment_type' => 'Tipo de equipo',
    'brand'          => 'Marca',
    'description'    => 'Descripción'
  ];

  foreach ($labels as $field => $etiqueta) {
      if (empty(trim($_POST[$field] ?? ''))) {
          $errors[] = "El campo «{$etiqueta}» es obligatorio.";
      }
  }

  /* Validación del patrimonio (solo si lo enviaron) ---------------------- */
  $asset = trim($_POST['asset_number'] ?? '');
  if ($asset !== '' && !preg_match('/^[0-9]{3}-[0-9]{6}$/', $asset)) {
      $errors[] = 'Patrimonio debe tener el formato 309-001234';
  }

  /* Ticket duplicado (si hay patrimonio y aún no hay errores) ------------ */
  if ($asset !== '' && !$errors) {
      $dupStmt = $pdo->prepare("
          SELECT id
            FROM tickets
           WHERE asset_number = ?
             AND status <> 'Atendido'
           LIMIT 1
      ");
      $dupStmt->execute([$asset]);
      if ($dupStmt->fetch()) {
          $errors[] = '⚠ Ya existe un ticket en proceso para este patrimonio.';
      }
  }

  // Aplica el límite solo si el patrimonio está en la lista
$aplicaLimite = (
    $asset !== '' &&
    $asset !== TICKET_SKIP_ASSET &&
    !$errors &&
    in_array($asset, TICKET_LIMIT_ASSETS, true) 
);
  /* Límite semanal por patrimonio (si enviaron patrimonio válido) -------- */
  if ($aplicaLimite) {

      if (TICKET_WEEK_MODE === 'iso') {
          // Semana ISO: mismo YEARWEEK(...,3)
          $limStmt = $pdo->prepare("
              SELECT COUNT(*)
                FROM tickets
               WHERE asset_number = ?
                 AND YEARWEEK(created_at, 3) = YEARWEEK(CURDATE(), 3)
          ");
          $limStmt->execute([$asset]);
          $count = (int)$limStmt->fetchColumn();

          if ($count >= TICKET_WEEK_LIMIT) {
              // Próximo lunes 00:00
              $reset = new DateTimeImmutable('monday next week 08:00');

              // Mensaje (también para fallback de alert)
              $errors[] = sprintf(
                  'Se alcanzó el límite semanal de %d tickets para el patrimonio %s. '
                . 'Podrá registrar otro a partir del %s.',
                  TICKET_WEEK_LIMIT,
                  htmlspecialchars($asset, ENT_QUOTES, 'UTF-8'),
                  $reset->format('d-m-Y H:i')
              );

              // Datos para MODAL grande centrado
              $limitBlock = [
                  'asset' => $asset,
                  'reset' => $reset->format('d-m-Y H:i'),
                  'limit' => TICKET_WEEK_LIMIT,
                  'mode'  => 'ISO (Lun–Dom)'
              ];
          }
      } else {
          // Rolling: últimos 7 días exactos
          $limStmt = $pdo->prepare("
              SELECT created_at
                FROM tickets
               WHERE asset_number = ?
                 AND created_at >= (NOW() - INTERVAL 7 DAY)
            ORDER BY created_at ASC
          ");
          $limStmt->execute([$asset]);
          $hits = $limStmt->fetchAll(PDO::FETCH_COLUMN);

          if (count($hits) >= TICKET_WEEK_LIMIT) {
              $earliest = new DateTimeImmutable($hits[0]);
              $reset = $earliest->modify('+7 days');

              $errors[] = sprintf(
                  'Se alcanzó el límite de %d tickets en los últimos 7 días para el patrimonio %s. '
                . 'Podrá registrar otro a partir del %s.',
                  TICKET_WEEK_LIMIT,
                  htmlspecialchars($asset, ENT_QUOTES, 'UTF-8'),
                  $reset->format('d-m-Y H:i')
              );

              $limitBlock = [
                  'asset' => $asset,
                  'reset' => $reset->format('d-m-Y H:i'),
                  'limit' => TICKET_WEEK_LIMIT,
                  'mode'  => 'Ventana móvil 7 días'
              ];
          }
      }
  }





  /* Insertar el ticket ---------------------------------------------------- */
  if (!$errors) {
      /* Normaliza el nombre de la dirección */
      $dirStmt = $pdo->prepare("SELECT nombre FROM direcciones WHERE id=?");
      $dirStmt->execute([(int)$_POST['direction']]);
      $directionName = $dirStmt->fetchColumn() ?: 'N/D';

      $pdo->prepare("
          INSERT INTO tickets
            (user_name, department, direction, asset_number,
             equipment_type, brand, description, status)
          VALUES (?,?,?,?,?,?,?, 'Pendiente')
      ")->execute([
          $_POST['user_name'],
          $_POST['department'],
          $directionName,
          $asset,
          $_POST['equipment_type'],
          $_POST['brand'],
          $_POST['description']
      ]);

      // Flash para modal con folio real
      $ticketId = (int)$pdo->lastInsertId();
      $_SESSION['flash_ticket'] = [ 'id' => $ticketId ];
      // (Opcional) mantener mensaje genérico:
      // $_SESSION['flash_success'] = '✅ Ticket #'.$ticketId.' creado con éxito.';

      header('Location: index.php'); exit;
  }
}
if (!empty($limitBlock)): ?>
<div class="modal fade" id="modalLimit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>
          Límite semanal alcanzado
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="text-center mb-3">
          <div class="display-6 fw-bold text-danger">No es posible registrar más tickets</div>
          <div class="text-muted">para el patrimonio:</div>
          <div class="display-6 fw-bold mt-1">
            <?= htmlspecialchars($limitBlock['asset'] ?? '', ENT_QUOTES, 'UTF-8') ?>
          </div>
        </div>

        <div class="alert alert-warning">
          Se alcanzó el límite de <strong><?= (int)$limitBlock['limit'] ?></strong> tickets por semana.
          <br>
          <small class="text-muted">Modo de conteo: <?= htmlspecialchars($limitBlock['mode']) ?></small>
        </div>

        <div class="row text-center">
          <div class="col-md-6">
            <div class="fw-semibold text-muted">Próxima fecha para registrar</div>
            <div class="h4 m-0"><?= htmlspecialchars($limitBlock['reset']) ?></div>
          </div>
          <div class="col-md-6 mt-3 mt-md-0">
            <div class="fw-semibold text-muted">Sugerencia</div>
            <div>Si el caso es urgente, contacte a soporte para seguimiento.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          Entendido
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; 



/*── 3. Consulta por folio o nombre ───────────────────────────*/
$lookupError   = '';
$ticketLookup  = null;   // resultado único
$listaTickets  = [];     // varios resultados por nombre

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['folio_lookup']) || isset($_POST['name_lookup']))) {


    $folio = trim($_POST['folio_lookup'] ?? '');
    $name  = trim($_POST['name_lookup']  ?? '');

    if ($folio === '' && $name === '') {
        $lookupError = 'Ingresa un folio o un nombre.';
    }
    /* ——— búsqueda por folio (tiene prioridad si viene) ——— */
    elseif ($folio !== '') {
        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.status,
                t.attended_by,
                t.attended_at,
                t.created_at,
                (
                  SELECT GROUP_CONCAT(ta.username_snapshot ORDER BY ta.is_primary DESC, ta.username_snapshot SEPARATOR ', ')
                  FROM ticket_attendees ta
                  WHERE ta.ticket_id = t.id
                ) AS attended_team
              FROM tickets t
             WHERE t.id = ?
        ");
        $stmt->execute([(int)$folio]);
        $ticketLookup = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticketLookup) $lookupError = 'No se encontró el ticket.';
    }
    /* ——— búsqueda por nombre (LIKE) ——— */
    else {
      $stmt = $pdo->prepare("
      SELECT
        t.id,
        t.status,
        t.attended_by,
        t.created_at,
        t.description,
        (
          SELECT GROUP_CONCAT(ta.username_snapshot ORDER BY ta.is_primary DESC, ta.username_snapshot SEPARATOR ', ')
          FROM ticket_attendees ta
          WHERE ta.ticket_id = t.id
        ) AS attended_team
        FROM tickets t
       WHERE t.user_name LIKE ?
    ORDER BY t.created_at DESC
       LIMIT 50
  ");
        $stmt->execute(['%'.$name.'%']);
        $listaTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$listaTickets) $lookupError = 'Sin coincidencias.';
    }
}

/*── 4. Alta rápida: atención inmediata ───────────────────────*/
if ($role !== 'guest' && isset($_POST['immediate'])) {

    $user  = trim($_POST['quick_user']  ?? '');
    $dirID = (int)($_POST['quick_direction'] ?? 0);
    $dept  = trim($_POST['quick_department'] ?? '');
    $descr = trim($_POST['quick_descr'] ?? '');

    /* Validación */
    if ($user==='' || $descr==='' || $dirID===0 || $dept==='') {
        $errors[] = 'Todos los campos de la atención inmediata son obligatorios.';
    } else {
        /* nombre de la dirección */
        $dirStmt = $pdo->prepare("SELECT nombre FROM direcciones WHERE id=?");
        $dirStmt->execute([$dirID]);
        $dirName = $dirStmt->fetchColumn() ?: 'N/D';

        /* Inserción */
        $pdo->prepare("
            INSERT INTO tickets
              (user_name, department, direction, asset_number,
               equipment_type, brand, description, status)
            VALUES (?,?,?,?,?,?,?, 'Pendiente')
        ")->execute([
            $user,
            $dept,
            $dirName,
            'SIN-PATRIMONIO',   // placeholder
            'Inmediato',
            'N/A',
            $descr
        ]);

        $_SESSION['flash_success'] = '✅ Actividad inmediata registrada.';
        $ticketId = (int)$pdo->lastInsertId();
        $_SESSION['flash_ticket'] = [ 'id' => $ticketId ];

        header('Location: index.php'); exit;
    }
}

/*── 5. Catálogos (Direcciones + estáticos) ─────────────────────*/
$directions = $pdo->query("
    SELECT id,nombre FROM direcciones ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

$equipmentTypes = [
  'Escritorio','Laptop','Regulador/No Break','Impresora','Cisco','Otro'
];
$brands = ['DELL','HP','LANIX','LENOVO', 'Smartbit', 'Cyberpower','Otra'];

$ticketUsers = $pdo->query("
    SELECT id, username
      FROM users
     WHERE role IN ('admin','user')
     ORDER BY username ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*── 6. Paginación muy simple ─────────────────────────────────*/
$perPage = 10;                           // ← tickets por página
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $perPage;

/* total para contar páginas */
$totalRows = (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

/* tickets paginados */
$stmt = $pdo->prepare("
    SELECT
        t.id,
        t.user_name,
        t.direction,
        t.asset_number,
        t.status,
        t.attended_by,
        t.created_at,
        (
          SELECT GROUP_CONCAT(ta.username_snapshot ORDER BY ta.is_primary DESC, ta.username_snapshot SEPARATOR ', ')
          FROM ticket_attendees ta
          WHERE ta.ticket_id = t.id
        ) AS attended_team
      FROM tickets t
  ORDER BY
  CASE t.status
  WHEN 'En camino' THEN 0      -- 1ª prioridad
  WHEN 'Pendiente'  THEN 1      -- 2ª prioridad
  ELSE 2                        -- Atendido
END,
t.id DESC                         -- dentro de cada grupo, folio más alto primeros
LIMIT :lim OFFSET :off
");
$stmt->bindValue(':lim',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off',  $offset,  PDO::PARAM_INT);
$stmt->execute();
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8">
  <title>SITI · Informática - SECOTED</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
  <link rel="shortcut icon" href="siti_logo.ico">
  <!-- favicon estándar -->
  <link rel="icon" type="image/x-icon" href="siti_logo.ico">
  <!-- ícono “alta resolución” para accesos directos -->
  <link rel="apple-touch-icon" href="siti_logo.png">
</head>
<body class="bg-secoed container py-4">
<section class="sigei-hero my-4 text-center">
  <div class="brand-lockup">
    <span class="brand-lockup__siti"><img src="siti_logo_blanco.png" alt="SITI"></span>
  </div>
  <h1 class="sigei-title mb-1">
    <span class="sigei-sigla">SITI</span>
  </h1>
  <p class="sigei-subtitle lead mb-2">
    Sistema Integral de Tickets Informático
  </p>
  <p class="hero-copy mx-auto mb-0">Plataforma interna de SECOTED para tickets, gestión documental, indicadores y apoyo operativo del área informática.</p>
</section>

<!--────────  Barra superior ───────────────────────────────────-->
<div class="workspace-shell mb-4">
  <div class="workspace-shell__top">
    <div class="workspace-user-chip">
      <span class="workspace-user-chip__icon"><i class="bi bi-person-circle"></i></span>
      <div>
        <div class="workspace-user-chip__label">Sesión activa</div>
        <div class="workspace-user-chip__name"><?= htmlspecialchars($username ?: 'Visitante') ?></div>
      </div>
    </div>

    <div class="workspace-shell__actions">
      <?php if ($role === 'admin'): ?>
        <div class="dropdown">
          <button class="btn btn-soft dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-shield-check"></i> Administración
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm dropdown-sigei">
            <li><a class="dropdown-item" href="register.php"><i class="bi bi-people"></i> Administrador de usuarios</a></li>
            <li><a class="dropdown-item" href="change_pass.php"><i class="bi bi-key"></i> Cambiar contraseña</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a></li>
          </ul>
        </div>
      <?php elseif ($role === 'user' || $role === 'guest'): ?>
        <div class="dropdown">
          <button class="btn btn-soft dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-gear"></i> Cuenta
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm dropdown-sigei">
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a></li>
          </ul>
        </div>
      <?php else: ?>
        <a href="login.php" class="btn btn-primary"><i class="bi bi-door-open"></i> Acceso</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($role !== 'guest'): ?>
    <div class="modules-card mt-3">
      <div class="modules-card__head">
        <div>
          <div class="modules-card__eyebrow">Navegación operativa</div>
          <h2 class="modules-card__title">Módulos del sistema</h2>
        </div>
        <div class="dropdown">
          <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-grid-3x3-gap"></i> Abrir menú
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm dropdown-sigei dropdown-sigei-lg">
            <li><a class="dropdown-item" href="analytics.php"><i class="bi bi-graph-up-arrow"></i> Analíticas Tickets</a></li>
            <li><a class="dropdown-item" href="control_oficios.php"><i class="bi bi-files"></i> Control de oficios</a></li>
            <li><a class="dropdown-item" href="control_actividades.php"><i class="bi bi-list-check"></i> Control de actividades</a></li>
            <li><a class="dropdown-item" href="poa.php"><i class="bi bi-journal-text"></i> POA</a></li>
            <li><a class="dropdown-item" href="control_cotizaciones.php"><i class="bi bi-receipt"></i> Cotizaciones y facturas</a></li>
          </ul>
        </div>
      </div>

      <div class="modules-grid">
        <a href="analytics.php" class="module-link-card">
          <span class="module-link-card__icon"><i class="bi bi-graph-up-arrow"></i></span>
          <span class="module-link-card__title">Analíticas</span>
          <span class="module-link-card__desc">KPIs, tendencias y cargas por atención.</span>
        </a>
        <a href="control_oficios.php" class="module-link-card">
          <span class="module-link-card__icon"><i class="bi bi-files"></i></span>
          <span class="module-link-card__title">Oficios</span>
          <span class="module-link-card__desc">Seguimiento formal, folios y proveedores.</span>
        </a>
        <a href="control_actividades.php" class="module-link-card">
          <span class="module-link-card__icon"><i class="bi bi-list-check"></i></span>
          <span class="module-link-card__title">Actividades</span>
          <span class="module-link-card__desc">Registro técnico, periodos y exportación.</span>
        </a>
        <a href="poa.php" class="module-link-card">
          <span class="module-link-card__icon"><i class="bi bi-journal-text"></i></span>
          <span class="module-link-card__title">POA</span>
          <span class="module-link-card__desc">Indicadores consolidados por periodo.</span>
        </a>
        <a href="control_cotizaciones.php" class="module-link-card">
          <span class="module-link-card__icon"><i class="bi bi-receipt"></i></span>
          <span class="module-link-card__title">Cotizaciones</span>
          <span class="module-link-card__desc">Archivos, facturas y control patrimonial.</span>
        </a>
      </div>
    </div>
  <?php endif; ?>
</div>

<!--────────  MODAL centrado con #Ticket + recomendación ──────-->
<?php if (!empty($flashTicket)): ?>
<div class="modal fade" id="modalTicket" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow">
      <div class="modal-header">
        <h5 class="modal-title">Ticket generado correctamente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning mb-3" role="alert">
          La requisición sigue siendo importante; en caso de aplicar, podremos llevarnos el equipo.
          De lo contrario, simplemente quedará con el registro del ticket.
        </div>

        <div class="text-center">
          <div class="text-muted mb-1">Su número de ticket</div>
          <div class="display-5 fw-bold mb-2">#<?= (int)$flashTicket['id'] ?></div>
          <button id="btnCopyTicket" type="button" class="btn btn-outline-secondary btn-sm">
            Copiar #Ticket
          </button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-success" onclick="window.print()">Imprimir</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($role!=='guest'): ?>
<div class="modal fade" id="modalDoneTeam" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow">
      <form method="post" action="index.php#tickets">
        <div class="modal-header">
          <h5 class="modal-title">Registrar equipo de atención</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="done_ticket_id" id="done_ticket_id">

          <div class="mb-3">
            <div class="text-muted small">Ticket</div>
            <div class="fw-semibold" id="done_ticket_label">#</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Usuarios que atendieron</label>
            <select name="attendees[]" id="done_attendees" class="form-select" multiple size="8" required>
              <?php foreach ($ticketUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Selecciona hasta 3 personas. Tu usuario aparecerá preseleccionado.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Guardar y marcar atendido</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<br><br>
<!--────────  Tutoriales – menú acordeón ─────────────────────-->
<section id="tutoriales-menu" class="tutorials-module modules-card">
  <div class="tutorials-module__head">
    <div>
      <div class="tutorials-module__eyebrow">Recursos de apoyo</div>
      <h2 class="tutorials-module__title">Módulo de tutoriales</h2>
      <p class="tutorials-module__copy">Guías rápidas y plantillas descargables para solicitudes y procesos frecuentes.</p>
    </div>
    <span class="tutorials-module__badge"><i class="bi bi-collection-play"></i> 4 recursos disponibles</span>
  </div>
  <div class="tutorials-module__body">

  <!-- Tutorial 1 -->
  <div class="tut-item">
    <button data-target="tut1">
      Cómo realizar una solicitud de correo <i class="bi bi-chevron-down"></i>
    </button>
    <div id="tut1" class="tut-content">
      <p><strong>Buen día.</strong><br>
      Como recordatorio, siempre me comunico por este medio para dar seguimiento a las solicitudes de los correos faltantes de cada área.</p>

      <p>Pido su colaboración para que, al solicitar correos institucionales, lo hagan mediante <strong>oficio dirigido al I.T.I.C. JOSÉ&nbsp;DIDIER&nbsp;RÍOS&nbsp;JIMÉNEZ – Jefe del Departamento de Recursos Informáticos</strong>, incluyendo la siguiente información:</p>

      <ul>
        <li><strong>Nombre completo</strong> de la(s) persona(s) a quien se generará el correo</li>
        <li><strong>Puesto</strong></li>
        <li><strong>Departamento</strong></li>
        <li><strong>Dirección</strong></li>
        <li><strong>Extensión</strong></li>
      </ul>

      <p class="mb-2">Ejemplo:</p>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle text-center">
          <thead class="table-light">
            <tr>
              <th>Personal</th>
              <th>Puesto</th>
              <th>Departamento</th>
              <th>Dirección</th>
              <th>Extensión</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>(Nombre completo)</td>
              <td>AUDITOR</td>
              <td>INVESTIGACIONES PATRIMONIALES</td>
              <td>Dirección de Situación Patrimonial y de Intereses</td>
              <td>99999</td>
            </tr>
            <tr>
              <td>(Nombre completo)</td>
              <td>AUDITOR CONTABLE</td>
              <td>REGISTRO PATRIMONIAL Y DE INTERESES</td>
              <td>Dirección de Situación Patrimonial y de Intereses</td>
              <td>88888</td>
            </tr>
            <tr>
              <td>(Nombre completo)</td>
              <td>AUDITOR</td>
              <td>FISCALIZACIÓN DE OBRA PÚBLICA</td>
              <td>Obra Pública</td>
              <td>55555</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p>Si son varias personas, todas deben incluirse en el <em>mismo oficio</em> y firmarse por el Director del área.</p>

      <p class="fw-semibold">Tiempo de respuesta máximo: 7 días hábiles a partir de la recepción del oficio firmado.</p>

      <p class="mb-3 text-center">Descarga la plantilla dirigida al Dpto. Informática:</p>
      <p class="text-center">
        <a href="plantilla_solicitud_correo.docx" class="btn btn-primary" download>
          <i class="bi bi-file-earmark-arrow-down"></i> Descargar plantilla
        </a>
      </p>
    </div>
  </div>

  <!-- Tutorial 2 -->
  <div class="tut-item">
    <button data-target="tut2">
      Método para Diagnóstico y/o Reparación de Equipo de Cómputo<i class="bi bi-chevron-down"></i>
    </button>
    <div id="tut2" class="tut-content text-center">
      <p class="mb-3">Descarga el procedimiento completo en formato PDF:</p>
      <a href="diagnostico_reparacion_2025.pdf" class="btn btn-primary" download>
        <i class="bi bi-file-earmark-arrow-down"></i> Descargar PDF
      </a>
    </div>
  </div>

  <!-- Tutorial 3 -->
  <div class="tut-item">
    <button data-target="tut3">
      Creación de solicitud para resguardo en teléfonos Cisco/Cambio de Display <i class="bi bi-chevron-down"></i>
    </button>
    <div id="tut3" class="tut-content">
      <p>Actualizar el display para que aparezca su nombre en el teléfono Cisco receptor al realizar una llamada.</p>

      <p>Para solicitarlo, envíe un <strong>oficio</strong> dirigido al <strong>I.T.I.C. JOSE&nbsp;DIDIER&nbsp;RÍOS&nbsp;JIMÉNEZ – Jefe del Departamento de Recursos Informáticos</strong>, firmado por el Director/a o Coordinador/a, que incluya la siguiente información:</p>

      <ul class="mb-3">
        <li>Nombre completo de la persona a quien se asigna el equipo</li>
        <li>Puesto</li>
        <li>Departamento</li>
        <li>Dirección</li>
        <li>Extensión</li>
      </ul>

      <p>Descargue la plantilla <em>OFICIAL DEL DTIYT en EXCEL</em>, aqui se explica campo por campo la información requerida. Si el equipo está asignado a alguien distinto del resguardante, escriba el nombre de dicha persona en el apartado <em>Display (Datos del Equipo)</em>.</p>

      <p>Para asesoría adicional no dude en consultarme; estoy a su disposición. Favor de difundir esta información entre quien pueda requerir dicho cambio.</p>

      <p class="fw-semibold">Tiempo de respuesta máximo: 7 días hábiles a partir de la recepción del oficio firmado.</p>

      
    <p class="mb-3 text-center">
      Descarga la plantilla oficial del DTIYT en formato Excel:
    </p>

    <p class="text-center">
      <a href="plantilla_cambios_display_cisco.xlsx"
         class="btn btn-primary" download>
        <i class="bi bi-file-earmark-arrow-down"></i>
        Descargar plantilla
      </a>
    </p>
    <p class="mb-3 text-center">
      Descarga la plantilla dirigida al Dpto. Informática:
    </p>
    <p class="text-center">
      <a href="plantilla_solicitud_cisco.docx"
         class="btn btn-primary" download>
        <i class="bi bi-file-earmark-arrow-down"></i>
        Descargar plantilla
      </a>
    </p>
    </div>
  </div>
      <!-- Tutorial 4 -->
      <div class="tut-item">
    <button data-target="tut4">
      Cómo crear un listado de contactos frecuentes en Cisco <i class="bi bi-chevron-down"></i>
    </button>
    <div id="tut4" class="tut-content">
      <p>Con esta plantilla podrás armar rápidamente un listado de contactos frecuentes en tu teléfono Cisco. No olvides mandarme el oficio.</p>
      <p class="mb-3 text-center">Descarga la plantilla en formato en Word:</p>
      <p class="text-center">
        <a href="plantilla_contactos_frecuentes_cisco.docx" class="btn btn-primary" download>
          <i class="bi bi-file-earmark-arrow-down"></i> Descargar plantilla
        </a>
      </p>
    </div>
  </div>
</section>

<h1 class="hero-title mb-5">Crear ticket&nbsp;IT</h1>
<?php if ($role!=='guest'): ?>
  <!--
  <div class="ticket-card">
<h2 class="h5 mb-3">Actividad de atención inmediata</h2>

  <form class="row g-3" method="post" novalidate>
    <input type="hidden" name="immediate" value="1">

    <div class="col-md-6">
      <label class="form-label">Nombre del solicitante</label>
      <input class="form-control" name="quick_user" required>
    </div>

    <div class="col-md-6">
      <label class="form-label">Dirección</label>
      <select id="quickSelDir" class="form-select" name="quick_direction" required>
        <option value="" disabled selected>– Selecciona –</option>
        <?php foreach ($directions as $d): ?>
          <option value="<?= $d['id'] ?>"><?= $d['nombre'] ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-6">
      <label class="form-label">Departamento</label>
      <select id="quickSelDep" class="form-select" name="quick_department" required disabled>
        <option value="">– Selecciona dirección primero –</option>
      </select>
    </div>

    <div class="col-md-12">
      <label class="form-label">Descripción breve</label>
      <textarea class="form-control" name="quick_descr" rows="3" required></textarea>
    </div>

    <div class="col-12">
      <button class="btn btn-outline-primary" type="submit">
        Guardar actividad
      </button>
    </div>
  </form>
</div>
--> 
<?php endif; ?>
      

<?php if ($errors):   ?><div class="alert alert-danger"><?= implode('<br>', $errors) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

<!--────────  Formulario público ────────────────────────────────-->
<div class="ticket-card">
  <h2 class="h5 mb-3">Actividad de Atención Personalizada</h2>
  <form class="row g-3" method="post" novalidate>
    <div class="col-md-6">
      <label class="form-label">Nombre del solicitante</label>
      <input class="form-control" name="user_name"
             value="<?= htmlspecialchars($_POST['user_name'] ?? '') ?>" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Dirección</label>
      <select id="selDir" class="form-select" name="direction" required>
        <option value="" disabled selected>– Selecciona –</option>
        <?php foreach ($directions as $d): ?>
          <option value="<?= $d['id'] ?>"><?= $d['nombre'] ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Departamento (se habilita tras elegir Dirección)</label>
      <select id="selDep" class="form-select" name="department" required disabled>
        <option value="">– Selecciona dirección primero –</option>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Número de patrimonio (No olvide el guión)</label>
      <input class="form-control" name="asset_number" placeholder="309-001234 (No olvide el guión)"
             value="<?= htmlspecialchars($_POST['asset_number'] ?? '') ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Tipo de equipo</label>
      <select class="form-select" name="equipment_type" required>
        <option disabled selected value="">– Selecciona –</option>
        <?php foreach ($equipmentTypes as $t):
              $sel = ($_POST['equipment_type'] ?? '') === $t ? 'selected' : '';
              echo "<option $sel>$t</option>";
        endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Marca</label>
      <select class="form-select" name="brand" required>
        <option disabled selected value="">– Selecciona –</option>
        <?php foreach ($brands as $b):
              $sel = ($_POST['brand'] ?? '') === $b ? 'selected' : '';
              echo "<option $sel>$b</option>";
        endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <label class="form-label">Descripción del problema</label>
      <textarea class="form-control" name="description" rows="4" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>
    <?php if ($role === 'guest'): ?>
   <!-- <div class="col-md-4">
      <label class="form-label">Captcha</label>
      <input class="form-control" value="<?= (int)($_SESSION['captcha_a'] ?? 0) ?> + <?= (int)($_SESSION['captcha_b'] ?? 0) ?>" readonly>
    </div
    <div class="col-md-4">
      <label class="form-label">Resultado</label>
      <input class="form-control" name="captcha_answer" inputmode="numeric" required
             value="<?= htmlspecialchars($_POST['captcha_answer'] ?? '') ?>">
    </div-->
    <?php endif; ?>
    <div class="col-12">
      <button class="btn btn-primary" type="submit">Enviar ticket</button>
    </div>
  </form>
</div>

<!--────────  Consulta por folio (nuevo bloque) ─────────────────-->

<div class="ticket-card" id="consulta-ticket">
  <h2 class="h5 mb-3">Consulta tus tickets</h2>
  <form class="row g-3" method="post" action="#consulta-ticket">
    <div class="col-md-4">
      <label class="form-label mb-0">Folio</label>
      <input type="number" name="folio_lookup" class="form-control"
             placeholder="Ej. 123">
    </div>

    <div class="col-md-4">
      <label class="form-label mb-0">Nombre del solicitante</label>
      <input type="text" name="name_lookup" class="form-control"
             placeholder="Ej. JUAN PÉREZ">
    </div>

    <div class="col-md-4 d-flex align-items-end">
      <button class="btn btn-outline-primary w-100">Buscar</button>
    </div>
  </form>

  <?php if ($lookupError): ?>
  <div class="alert alert-danger mt-3"><?= $lookupError ?></div>

<?php elseif ($ticketLookup): ?>
  <!--  resultado único -->
  <div class="alert alert-info mt-3">
    Folio <strong>#<?= $ticketLookup['id'] ?></strong><br>
    Estado: <strong><?= $ticketLookup['status'] ?></strong>
    <?php if ($ticketLookup['attended_by']): ?>
      <br>Atendido por: <strong><?= htmlspecialchars($ticketLookup['attended_team'] ?: ($ticketLookup['attended_by'] ?? '')) ?></strong>
    <?php endif; ?>
    <?php if ($ticketLookup['attended_at']): ?>
      <br>Fecha y hora de atención: <strong><?= $ticketLookup['attended_at'] ?></strong>
    <?php endif; ?>
  </div>




<?php elseif ($listaTickets): ?>
  <!--  varios resultados  -->
  <div class="table-responsive mt-3">
  <table class="table table-sm table-striped">
    <thead class="table-light">
      <tr>
        <th>Folio</th><th>Estado</th><th>Atiende</th>
        <th>Fecha</th><th>Descripción</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($listaTickets as $t): ?>
      <tr>
        <td><?= $t['id'] ?></td>
        <td><?= $t['status'] ?></td>
        <td><?= htmlspecialchars($t['attended_team'] ?: ($t['attended_by'] ?? '')) ?></td>
        <td><?= $t['created_at'] ?></td>
        <td><?= htmlspecialchars($t['description']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

</div>


<!--────────  Panel privado (admin & user) ──────────────────────-->
<?php if ($role!=='guest'): ?>
  <div class="btn-excel">
  <a href="export_tickets_excel.php" class="btn btn-primary">
      <i class="bi bi-file-earmark-excel"></i> Exportar tickets a Excel
  </a>
</div>
  <h2 class="section-title" id="tickets">Tickets recientes</h2>
  <table class="table table-striped table-tickets">
    <thead>
      <tr>
        <th>#</th><th>Solicitante</th><th>Dirección</th><th>Patrimonio</th>
        <th>Estado</th><th>Atiende</th><th>Fecha</th><th>Ver</th><th>Acción</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tickets as $t): ?>
      <tr>
        <td><?= $t['id'] ?></td>
        <td><?= htmlspecialchars($t['user_name']) ?></td>
        <td><?= htmlspecialchars($t['direction']) ?></td>
        <td><?= htmlspecialchars($t['asset_number']) ?></td>
<td>
  <?php if ($t['status']==='Pendiente' && $role!=='guest'): ?>
      <a href="?ack=<?= $t['id'] ?>"  class="btn btn-sm btn-outline-warning me-1">🚶‍♂️</a>
      <button type="button"
              class="btn btn-sm btn-outline-success btn-done-team"
              data-bs-toggle="modal"
              data-bs-target="#modalDoneTeam"
              data-ticket-id="<?= (int)$t['id'] ?>"
              data-ticket-folio="<?= (int)$t['id'] ?>"
              data-ticket-user="<?= htmlspecialchars($t['user_name'], ENT_QUOTES) ?>">✔</button>

  <?php elseif ($t['status']==='En camino' && $role!=='guest'): ?>
      <span class="badge bg-warning text-dark me-1">En&nbsp;camino</span>
      <button type="button"
              class="btn btn-sm btn-outline-success btn-done-team"
              data-bs-toggle="modal"
              data-bs-target="#modalDoneTeam"
              data-ticket-id="<?= (int)$t['id'] ?>"
              data-ticket-folio="<?= (int)$t['id'] ?>"
              data-ticket-user="<?= htmlspecialchars($t['user_name'], ENT_QUOTES) ?>">✔</button>

  <?php else: ?>
      <span class="badge bg-success">Atendido</span>
  <?php endif; ?>
</td>

        <td><?= htmlspecialchars($t['attended_team'] ?: ($t['attended_by'] ?? '')) ?></td>
        <td><?= $t['created_at'] ?></td>
        <td><a href="ticket_view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-info">👁</a></td>
        <td>
          <?php if ($role==='admin'): ?>
            <a href="?del=<?= $t['id'] ?>" class="btn btn-sm btn-danger"
               onclick="return confirm('¿Eliminar ticket #<?= $t['id'] ?>?');">🗑</a>
          <?php endif; ?>
        </td>
        
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php if ($role!=='guest' && $totalPages > 1): ?>
<nav aria-label="Paginar tickets">
	<?php
		// Config: tamaño de ventana alrededor de la página actual
		$window = 2; // muestra 2 a la izquierda y 2 a la derecha (ajústalo a gusto)

		// Armamos la query base preservando todos los GET excepto 'p'
		$params = $_GET;
		unset($params['p']);
		$baseQS = $params ? ('?'.http_build_query($params).'&') : '?';
		$anchor = '#tickets';

		// Helper para item de paginación
		function page_item($label, $pageNumber, $disabled=false, $active=false, $baseQS='?', $anchor='') {
			$classes = 'page-item';
			if ($disabled) $classes .= ' disabled';
			if ($active)   $classes .= ' active';
			$href = $disabled ? 'javascript:void(0)' : $baseQS.'p='.$pageNumber.$anchor;
			echo '<li class="'.$classes.'"><a class="page-link" href="'.$href.'">'.$label.'</a></li>';
		}

		// Calculamos los bloques a imprimir
		$pagesToShow = [];

		// Siempre incluir primera y última
		$pagesToShow[] = 1;
		for ($i = max(2, $page - $window); $i <= min($totalPages - 1, $page + $window); $i++) {
			$pagesToShow[] = $i;
		}
		if ($totalPages > 1) $pagesToShow[] = $totalPages;

		// Quitamos duplicados y ordenamos
		$pagesToShow = array_values(array_unique($pagesToShow));
		sort($pagesToShow);

		// Función que imprime con elipsis
		function render_compact_pagination($pages, $current, $total, $baseQS, $anchor) {
			// Botón anterior
			page_item('«', max(1, $current - 1), $current == 1, false, $baseQS, $anchor);

			$prev = 0;
			foreach ($pages as $p) {
				// Si hay hueco entre prev y p, ponemos elipsis
				if ($prev && $p > $prev + 1) {
					echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
				}
				page_item((string)$p, $p, false, $p==$current, $baseQS, $anchor);
				$prev = $p;
			}

			// Botón siguiente
			page_item('»', min($total, $current + 1), $current == $total, false, $baseQS, $anchor);
		}
	?>
	<ul class="pagination justify-content-center mt-3">
		<?php render_compact_pagination($pagesToShow, $page, $totalPages, $baseQS, $anchor); ?>
	</ul>

	<!-- Ir a página (opcional, chico y discreto) -->
	<form class="d-flex justify-content-center gap-2 mt-2" method="get" action="">
		<?php
			// Reinyectar todos los parámetros GET excepto 'p'
			foreach ($params as $k=>$v) {
				if (is_array($v)) {
					foreach ($v as $vv) echo '<input type="hidden" name="'.htmlspecialchars($k).'[]" value="'.htmlspecialchars($vv).'">';
				} else {
					echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">';
				}
			}
		?>
		<div class="input-group" style="max-width: 220px;">
			<span class="input-group-text">Ir a</span>
			<input type="number" class="form-control" name="p" min="1" max="<?= (int)$totalPages ?>" value="<?= (int)$page ?>">
			<button class="btn btn-outline-primary">Ir</button>
		</div>
	</form>

	<!-- Texto "Mostrando X–Y de Z" (opcional) -->
	<?php
		$from = $offset + 1;
		$to   = min($offset + $perPage, $totalRows);
	?>
	<p class="text-center text-muted mt-2 mb-0">
		Mostrando <strong><?= $from ?></strong>–<strong><?= $to ?></strong> de <strong><?= $totalRows ?></strong> registros
	</p>
</nav>
<?php endif; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!--────────  JS: Select dependiente ───────────────────────────-->
<script>
const selDir = document.getElementById('selDir');
const selDep = document.getElementById('selDep');
if (selDir) {
  selDir.addEventListener('change', async e=>{
    const id  = e.target.value;
    selDep.innerHTML  = '<option> Cargando… </option>';
    selDep.disabled   = true;
    try{
      const res  = await fetch('get_departments.php?dir_id='+encodeURIComponent(id));
      const data = await res.json();
      selDep.innerHTML =
        '<option disabled selected value="">– Selecciona –</option>' +
        data.map(d => `<option value="${d.nombre}">${d.nombre}</option>`).join('');
      selDep.disabled = false;
    }catch(err){
      console.error(err);
      selDep.innerHTML = '<option>Error al cargar</option>';
      selDep.disabled = false;
    }
  });
}
</script>

<!--────────  JS: Accordion de tutoriales ─────────────────────-->
<script>
document.querySelectorAll('#tutoriales-menu button').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const tgt=document.getElementById(btn.dataset.target);
    const open=tgt.style.display==='block';
    document.querySelectorAll('#tutoriales-menu .tut-content')
            .forEach(el=>el.style.display='none');
    document.querySelectorAll('#tutoriales-menu button i.bi')
            .forEach(i=>i.classList.replace('bi-chevron-up','bi-chevron-down'));
    if(!open){
      tgt.style.display='block';
      btn.querySelector('i.bi').classList.replace('bi-chevron-down','bi-chevron-up');
    }
  });
});
</script>
<script>
/* Dependiente para la tarjeta rápida */
const quickSelDir = document.getElementById('quickSelDir');
const quickSelDep = document.getElementById('quickSelDep');
if (quickSelDir) {
  quickSelDir.addEventListener('change', async e=>{
    const id  = e.target.value;
    quickSelDep.innerHTML='<option>Cargando…</option>'; quickSelDep.disabled=true;
    try{
      const res  = await fetch('get_departments.php?dir_id='+encodeURIComponent(id));
      const data = await res.json();
      quickSelDep.innerHTML='<option disabled selected value="">– Selecciona –</option>' +
        data.map(d=>`<option value="${d.nombre}">${d.nombre}</option>`).join('');
    }catch(err){ quickSelDep.innerHTML='<option>Error</option>'; }
    quickSelDep.disabled=false;
  });
}
</script>

<script>
const currentUsername = <?= json_encode($username, JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('.btn-done-team').forEach(btn => {
  btn.addEventListener('click', () => {
    const ticketId    = btn.dataset.ticketId || '';
    const ticketFolio = btn.dataset.ticketFolio || '';
    const ticketUser  = btn.dataset.ticketUser || '';

    document.getElementById('done_ticket_id').value = ticketId;
    document.getElementById('done_ticket_label').textContent = `#${ticketFolio} · ${ticketUser}`;

    const select = document.getElementById('done_attendees');
    if (!select) return;

    [...select.options].forEach(opt => {
      opt.selected = (opt.textContent.trim() === currentUsername);
    });
  });
});

document.getElementById('done_attendees')?.addEventListener('change', function () {
  const selected = [...this.selectedOptions];
  if (selected.length > 3) {
    selected[selected.length - 1].selected = false;
    alert('Solo puedes seleccionar máximo 3 personas.');
  }
});
</script>

<?php if (!empty($flashTicket)): ?>
<script>
  // Mostrar MODAL centrado y copiar #Ticket
  (function(){
    var modalEl = document.getElementById('modalTicket');
    if (!modalEl) return;
    var modal = new bootstrap.Modal(modalEl, {backdrop:'static', keyboard:false});
    modal.show();
    var btn = document.getElementById('btnCopyTicket');
    if (btn && navigator.clipboard) {
      btn.addEventListener('click', function(){
        navigator.clipboard.writeText('#<?= (int)$flashTicket['id'] ?>').then(function(){
          btn.innerText = '¡Copiado!';
          setTimeout(function(){ btn.innerText = 'Copiar #Ticket'; }, 1800);
        }).catch(function(){
          btn.innerText = 'No se pudo copiar';
        });
      });
    }
  })();
</script>
<?php endif; ?>
<?php if (!empty($limitBlock)): ?>
<script>
(function(){
  var el = document.getElementById('modalLimit');
  if (!el) return;
  new bootstrap.Modal(el, {backdrop:'static', keyboard:false}).show();
})();
</script>
<?php endif; ?>


<script>
  /* Recarga automática cada 20 min (20 × 60 × 1000 ms) */
  setTimeout(()=>location.reload(), 1_200_000);
</script>
<script src="validation.js"></script>
</body>
</html>
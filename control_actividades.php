<?php
/*****************************************************************
 * control_actividades.php – Registro de actividades
 * + Paginado, filtro por año (default: año actual) y búsqueda
 *****************************************************************/
session_start();
$role     = $_SESSION['role']     ?? 'guest';
$username = $_SESSION['username'] ?? '';
if (!in_array($role, ['admin','user'])) { header('Location: login.php'); exit; }

$pdo = require __DIR__ . '/db.php';

/* Catálogo único de tipos de atención */
$tiposAtencion = [
  'BLOQUEO DE RED POR DTIYT','CISCO','CORREO','DIAGNÓSTICO',
  'INNOVACION - CREACIÓN','MANTENIMIENTO','OTRO','PERMISOS INTERNET',
  'REUNIÓN','SITIOS WEB','SOPORTE','VALIDACIÓN COTIZACIÓN','VIDEO VIGILANCIA'
];

/* --------- Eliminar (solo admin) ---------- */
if ($role==='admin' && isset($_GET['del'])) {
  $id = (int)$_GET['del'];
  $pdo->prepare("DELETE FROM actividades WHERE id=?")->execute([$id]);
  $qs = http_build_query([
    'deleted'=>1,
    'p'  => (int)($_GET['p'] ?? 1),
    'q'  => ($_GET['q'] ?? ''),
    'y'  => ($_GET['y'] ?? date('Y')),
    'pp' => (int)($_GET['pp'] ?? 20),
  ]);
  header('Location: control_actividades.php?'.$qs); exit;
}

/* --------- Alta / actualización ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Normalizar tipo
  $tipo = trim($_POST['tipo_atencion'] ?? 'OTRO');
  if (!in_array($tipo, $tiposAtencion, true)) $tipo = 'OTRO';

  if (isset($_POST['update_id'])) {
    $upd = $pdo->prepare("
      UPDATE actividades
         SET status=?, actividad=?, fecha_inicial=?, fecha_final=?, tipo_atencion=?
       WHERE id=?
    ");
    $upd->execute([
      $_POST['status'] ?: 'EN PROCESO',
      trim($_POST['actividad']),
      $_POST['fecha_inicial'] ?: date('Y-m-d'),
      $_POST['fecha_final'] ?: null,
      $tipo,
      (int)$_POST['update_id']
    ]);
    $qs = http_build_query([
      'updated'=>1,
      'p'  => (int)($_GET['p'] ?? 1),
      'q'  => ($_GET['q'] ?? ''),
      'y'  => ($_GET['y'] ?? date('Y')),
      'pp' => (int)($_GET['pp'] ?? 20),
    ]);
    header('Location: control_actividades.php?'.$qs); exit;
  }

  $ins = $pdo->prepare("
    INSERT INTO actividades (status, actividad, fecha_inicial, fecha_final, tipo_atencion, created_by)
    VALUES (?,?,?,?,?,?)
  ");
  $ins->execute([
    $_POST['status'] ?: 'EN PROCESO',
    trim($_POST['actividad']),
    $_POST['fecha_inicial'] ?: date('Y-m-d'),
    $_POST['fecha_final'] ?: null,
    $tipo,
    $username
  ]);
  header('Location: control_actividades.php?saved=1'); exit;
}

/* --------- Parámetros de filtro/paginado ---------- */
$q       = trim($_GET['q'] ?? '');
$pp      = max(1, (int)($_GET['pp'] ?? 20));               // per-page
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $pp;
$mi = $_GET['mi'] ?? 'all'; // mes fecha_inicial
$mf = $_GET['mf'] ?? 'all'; // mes fecha_final

$mi = ($mi === 'all') ? 'all' : max(1, min(12, (int)$mi));
$mf = ($mf === 'all') ? 'all' : max(1, min(12, (int)$mf));

/* Año: default año actual para aligerar; “all” muestra todo */
$yearParam = $_GET['y'] ?? date('Y');
$useAllYears = ($yearParam === 'all');
$startDate = $useAllYears ? null : ($yearParam.'-01-01');
$endDate   = $useAllYears ? null : ($yearParam.'-12-31');

/* Años disponibles (para el combo) */
$years = $pdo->query("
  SELECT DISTINCT YEAR(fecha_inicial) AS y
    FROM actividades
   WHERE fecha_inicial IS NOT NULL
ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);

/* --------- Conteo total con filtros ---------- */
$where = [];
$paramsCnt = [];

if (!$useAllYears) { $where[] = "(fecha_inicial BETWEEN ? AND ?)"; $paramsCnt[]=$startDate; $paramsCnt[]=$endDate; }
if ($q !== '') {
  $where[] = "(actividad LIKE ? OR tipo_atencion LIKE ? OR created_by LIKE ?)";
  $like = "%$q%"; $paramsCnt[]=$like; $paramsCnt[]=$like; $paramsCnt[]=$like;
}

$sqlCnt = "SELECT COUNT(*) FROM actividades";
if ($where) $sqlCnt .= " WHERE ".implode(" AND ", $where);
$cntStmt = $pdo->prepare($sqlCnt);
$cntStmt->execute($paramsCnt);
$totalRows  = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $pp));

if ($mi !== 'all') {
  $where[] = "MONTH(fecha_inicial) = ?";
  $paramsCnt[] = $mi;
}

if ($mf !== 'all') {
  $where[] = "fecha_final IS NOT NULL AND MONTH(fecha_final) = ?";
  $paramsCnt[] = $mf;
}


/* --------- Select paginado ---------- */
$selectCols = "id,status,actividad,fecha_inicial,fecha_final,tipo_atencion,created_by,created_at";
$sql = "SELECT $selectCols FROM actividades";
$params = [];
$whereSel = []; // <-- importante

if (!$useAllYears) {
  $whereSel[] = "(fecha_inicial BETWEEN ? AND ?)";
  $params[] = $startDate;
  $params[] = $endDate;
}

if ($mi !== 'all') {
  $whereSel[] = "MONTH(fecha_inicial) = ?";
  $params[] = $mi;
}

if ($mf !== 'all') {
  $whereSel[] = "fecha_final IS NOT NULL AND MONTH(fecha_final) = ?";
  $params[] = $mf;
}

if ($q !== '') {
  $whereSel[] = "(actividad LIKE ? OR tipo_atencion LIKE ? OR created_by LIKE ?)";
  $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($whereSel) $sql .= " WHERE " . implode(" AND ", $whereSel);

$sql .= "
  ORDER BY CASE status
             WHEN 'EN PROCESO' THEN 0
             ELSE 1
           END,
           fecha_inicial DESC, id DESC
  LIMIT ? OFFSET ?";
$params[] = $pp; $params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<title>Control de actividades</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="styles.css" rel="stylesheet">
<link rel="shortcut icon" href="siti_logo.ico">
</head>
<body class="bg-secoed container-fluid py-4">
<?php
  $brand_back_href = 'index.php';
  $brand_back_text = 'Panel';
  $brand_eyebrow   = 'SECOED · Operación técnica';
  $brand_title     = 'Control de actividades';
  $brand_subtitle  = 'Captura y seguimiento de actividades del área con filtros por año, mes inicial y mes final.';
  $brand_badge     = 'Periodo activo';
  require __DIR__ . '/brand_header.php';
?>

<?php if (isset($_GET['saved'])):   ?><div class="alert alert-success">✅ Actividad registrada.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">✅ Actividad actualizada.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">✅ Actividad eliminada.</div><?php endif; ?>

<!-- Filtro: búsqueda + año + per-page -->
<form class="row g-2 align-items-end mb-3" method="get">
  <div class="col-sm-4 col-md-5 col-lg-6">
    <label class="form-label text-muted">Buscar</label>
    <input name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Actividad, tipo o usuario…">
  </div>

  <div class="col-sm-3 col-md-3 col-lg-3">
    <label class="form-label text-muted">Año</label>
    <select name="y" class="form-select">
      <option value="all" <?= $useAllYears?'selected':'' ?>>Todos (más lento)</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= (!$useAllYears && $yearParam==$y)?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
<div class="col-sm-3 col-md-2 col-lg-2">
  <label class="form-label text-muted">Mes (Inicial)</label>
  <select name="mi" class="form-select">
    <option value="all" <?= ($mi==='all')?'selected':''; ?>>Todos</option>
    <?php for ($m=1;$m<=12;$m++): ?>
      <option value="<?= $m ?>" <?= ($mi!=='all' && (int)$mi===$m)?'selected':''; ?>>
        <?= str_pad($m,2,'0',STR_PAD_LEFT) ?>
      </option>
    <?php endfor; ?>
  </select>
</div>

<div class="col-sm-3 col-md-2 col-lg-2">
  <label class="form-label text-muted">Mes (Final)</label>
  <select name="mf" class="form-select">
    <option value="all" <?= ($mf==='all')?'selected':''; ?>>Todos</option>
    <?php for ($m=1;$m<=12;$m++): ?>
      <option value="<?= $m ?>" <?= ($mf!=='all' && (int)$mf===$m)?'selected':''; ?>>
        <?= str_pad($m,2,'0',STR_PAD_LEFT) ?>
      </option>
    <?php endfor; ?>
  </select>
</div>
  <div class="col-sm-2 col-md-2 col-lg-2">
    <label class="form-label text-muted">Por página</label>
    <select name="pp" class="form-select">
      <?php foreach ([20,50,100] as $n): ?>
        <option value="<?= $n ?>" <?= $pp==$n?'selected':'' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-sm-3 col-md-2 col-lg-1 d-grid">
    <button class="btn btn-primary">Aplicar</button>
  </div>
</form>

<!-- Alta rápida -->
<div class="card mb-4 shadow-sm">
  <div class="card-body">
    <h5 class="card-title">Agregar actividad</h5>
    <form class="row g-3" method="post">
      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option>EN PROCESO</option>
          <option>RESUELTO</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Tipo de atención</label>
        <select name="tipo_atencion" class="form-select" required>
          <?php foreach ($tiposAtencion as $t) echo "<option>$t</option>"; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Fecha inicial</label>
        <input type="date" name="fecha_inicial" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Fecha final</label>
        <input type="date" name="fecha_final" class="form-control">
      </div>
      <div class="col-12">
        <label class="form-label">Actividad</label>
        <textarea name="actividad" class="form-control" rows="2" required placeholder="Describe la actividad…"></textarea>
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Guardar</button>
      </div>
    </form>
    <small class="text-muted">Captura realizada por: <strong><?= htmlspecialchars($username) ?></strong></small>
  </div>
</div>
<div class="col-sm-3 col-md-2 col-lg-2 d-grid">
  <label class="form-label text-muted">&nbsp;</label>
  <a class="btn btn-success"
     href="export_actividades.php?q=<?= urlencode($q) ?>&y=<?= urlencode($yearParam) ?>">
    <i class="bi bi-file-earmark-excel"></i> Exportar Excel
  </a>
</div>
      </p>

<!-- Listado -->
<div class="table-responsive">
  <table class="table table-striped table-sm table-tickets">
    <thead class="table-light text-center">
      <tr>
        <th style="width:40px">Editar</th>
        <?php if ($role==='admin'): ?><th style="width:40px">Eliminar</th><?php endif; ?>
        <th>ID</th><th>Status</th><th>Actividad</th><th>Fecha inicial</th><th>Fecha final</th><th>Tipo de atención</th><th>Registró</th><th>Creado</th>
      </tr>
    </thead>
    <tbody class="text-center">
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <button class="btn btn-sm btn-outline-secondary edit-btn"
                    data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>'>
              <i class="bi bi-pencil-square"></i>
            </button>
          </td>
          <?php if ($role==='admin'): ?>
          <td>
            <a href="?<?= http_build_query(['del'=>$r['id'],'p'=>$page,'q'=>$q,'y'=>$useAllYears?'all':$yearParam,'pp'=>$pp]) ?>"
               class="btn btn-sm btn-danger"
               onclick="return confirm('¿Eliminar actividad #<?= $r['id'] ?>?');">
              <i class="bi bi-trash"></i>
            </a>
          </td>
          <?php endif; ?>
          <td><?= $r['id'] ?></td>
          <td><?= htmlspecialchars($r['status']) ?></td>
          <td class="text-start"><?= nl2br(htmlspecialchars($r['actividad'])) ?></td>
          <td><?= $r['fecha_inicial'] ? date('d-m-Y', strtotime($r['fecha_inicial'])) : '—' ?></td>
          <td><?= $r['fecha_final']   ? date('d-m-Y', strtotime($r['fecha_final']))   : '—' ?></td>
          <td><?= htmlspecialchars($r['tipo_atencion']) ?></td>
          <td><?= htmlspecialchars($r['created_by']) ?></td>
          <td><?= date('d-m-Y H:i', strtotime($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Paginación -->
<?php if ($totalPages>1): ?>
<nav class="mt-3" aria-label="Paginación">
  <ul class="pagination justify-content-center mt-3">
<?php
  // Ventana alrededor de la página actual (ajústalo si quieres más/menos números)
  $window = 2;

  // Preservamos todos los parámetros GET excepto 'p' (página) para construir los links
  $params = $_GET;
  unset($params['p']);
  $baseQS = $params ? ('?' . http_build_query($params) . '&') : '?';

  // Helpers con nombres únicos para evitar colisiones
  if (!function_exists('page_item_act')) {
    function page_item_act($label, $pageNumber, $disabled=false, $active=false, $baseQS='?', $anchor='') {
      $classes = 'page-item';
      if ($disabled) $classes .= ' disabled';
      if ($active)   $classes .= ' active';
      $href = $disabled ? 'javascript:void(0)' : $baseQS . 'p=' . $pageNumber . $anchor;
      echo '<li class="' . $classes . '"><a class="page-link" href="' . $href . '">' . $label . '</a></li>';
    }
  }

  if (!function_exists('render_compact_pagination_act')) {
    function render_compact_pagination_act($pages, $current, $total, $baseQS, $anchor='') {
      // Botón anterior
      page_item_act('«', max(1, $current - 1), $current == 1, false, $baseQS, $anchor);

      // Números + elipsis
      $prev = 0;
      foreach ($pages as $p) {
        if ($prev && $p > $prev + 1) {
          echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        page_item_act((string)$p, $p, false, $p == $current, $baseQS, $anchor);
        $prev = $p;
      }

      // Botón siguiente
      page_item_act('»', min($total, $current + 1), $current == $total, false, $baseQS, $anchor);
    }
  }

  // Calculamos las páginas a mostrar (primera, ventana alrededor de la actual y última)
  $pagesToShow = [];
  $pagesToShow[] = 1;
  for ($i = max(2, $page - $window); $i <= min($totalPages - 1, $page + $window); $i++) {
    $pagesToShow[] = $i;
  }
  if ($totalPages > 1) $pagesToShow[] = $totalPages;

  // Normalizamos
  $pagesToShow = array_values(array_unique($pagesToShow));
  sort($pagesToShow);

  // Render
  render_compact_pagination_act($pagesToShow, $page, $totalPages, $baseQS, '');
?>
</ul>
</nav>
<?php endif; ?>

<!-- Modal editar -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" class="modal-body row g-3">
        <input type="hidden" name="update_id" id="upd_id">
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="status" id="upd_status" class="form-select">
            <option>EN PROCESO</option>
            <option>RESUELTO</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Tipo de atención</label>
          <select name="tipo_atencion" id="upd_tipo" class="form-select">
            <?php foreach ($tiposAtencion as $t) echo "<option>$t</option>"; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Fecha final</label>
          <input type="date" name="fecha_final" id="upd_ffin" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">Fecha inicial</label>
          <input type="date" name="fecha_inicial" id="upd_fini" class="form-control" required>
        </div>
        <div class="col-12">
          <label class="form-label">Actividad</label>
          <textarea name="actividad" id="upd_act" rows="3" class="form-control" required></textarea>
        </div>
        <div class="col-12 text-end">
          <button class="btn btn-primary">Guardar cambios</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modal = new bootstrap.Modal(document.getElementById('modalEdit'));
document.querySelectorAll('.edit-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const d = JSON.parse(btn.dataset.json);
    upd_id.value     = d.id;
    upd_status.value = d.status;
    upd_tipo.value   = d.tipo_atencion;
    upd_fini.value   = d.fecha_inicial ?? '';
    upd_ffin.value   = d.fecha_final   ?? '';
    upd_act.value    = d.actividad ?? '';
    modal.show();
  });
});
</script>
</body></html>

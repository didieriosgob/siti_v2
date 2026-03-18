<?php
/*****************************************************************
 * control_oficios.php – CRUD + Fotos + Filtro/Paginación + Analíticas
 * Mejoras:
 * - Analíticas para oficios (KPIs, tendencia, distribuciones, Top-N)
 * - Filtros por Año/Mes (fecha_recibido) + búsqueda por texto (q)
 * - Consultas parametrizadas y preservación de filtros en redirecciones
 *****************************************************************/
session_start();
$role = $_SESSION['role'] ?? 'guest';
if (!in_array($role, ['admin', 'user'])) {
    header('Location: login.php');
    exit;
}

$pdo = require __DIR__ . '/db.php';

/*──────────── Directorio de fotos ─────────────────────────────*/
$uploadDirFs  = __DIR__ . '/uploads/oficios';   // físico
$uploadDirWeb = 'uploads/oficios';              // web
if (!is_dir($uploadDirFs)) mkdir($uploadDirFs, 0775, true);

/*──────────── Catálogo refacciones ────────────────────────────*/
$refacciones = [
  'DISCO DURO/SSD','BATERIA','RAM','CARGADOR','FUSOR','LIMPIEZA/MTTO',
  'NO BREAK/UPS','LECTOR CD','FUENTE DE PODER','TARJETA MADRE','CARCASA DD PORTÁTIL','CÁMARA'
];

/*──────────── Utils filtros ───────────────────────────────────*/
function as_int_or_null($v){ return (isset($v) && is_numeric($v)) ? (int)$v : null; }
function month_name_es(int $m): string {
  $n=[1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  return $n[$m] ?? (string)$m;
}

/*──────────── Filtros (GET) ───────────────────────────────────*/
$q        = trim($_GET['q'] ?? '');
$selYear  = as_int_or_null($_GET['year']  ?? null);
$selMonth = as_int_or_null($_GET['month'] ?? null);
$perPage  = 10;
$page     = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page - 1) * $perPage;


/* ─────────── Cisco desde control_actividades (mismos filtros de año/mes) ─────────── */
$condsAct = []; $paramsAct = [];
if ($selYear)  { $condsAct[] = "YEAR(fecha_inicial)=:ayr";  $paramsAct[':ayr'] = $selYear; }
if ($selMonth) { $condsAct[] = "MONTH(fecha_inicial)=:amn"; $paramsAct[':amn'] = $selMonth; }
$wAct = $condsAct ? ("WHERE ".implode(" AND ", $condsAct)) : "";

/* Total CISCO actividades (para sumar a distribución por tipo) */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM actividades $wAct".($wAct?' AND':' WHERE')." tipo_atencion='CISCO'");
$stmt->execute($paramsAct);
$actCiscoTotal = (int)$stmt->fetchColumn();

/* CISCO por mes (para sumar a la tendencia) */
if ($selYear) {
    // para año seleccionado → 12 meses
    $stmt = $pdo->prepare("
        SELECT MONTH(fecha_inicial) m, COUNT(*) c
          FROM actividades
         WHERE tipo_atencion='CISCO' AND YEAR(fecha_inicial)=:yr
      GROUP BY m
    ");
    $stmt->execute([':yr'=>$selYear]);
    $actCiscoByMonth = array_fill(1,12,0);
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
        $actCiscoByMonth[(int)$r['m']] = (int)$r['c'];
    }
} else {
    // últimos 12 meses (ventana móvil)
    $rawActCisco = $pdo->query("
        SELECT DATE_FORMAT(fecha_inicial,'%Y-%m') ym, COUNT(*) cnt
          FROM actividades
         WHERE tipo_atencion='CISCO'
           AND fecha_inicial >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
      GROUP BY ym ORDER BY ym
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
}


/* Lista de años disponibles (por fecha_recibido) */
$years = $pdo->query("SELECT DISTINCT YEAR(fecha_recibido) AS yr FROM oficios WHERE fecha_recibido IS NOT NULL ORDER BY yr DESC")
             ->fetchAll(PDO::FETCH_COLUMN);

/*──────────── Construcción WHERE común ───────────────────────*/
$conds = []; $params = [];
if ($q !== '') {
  $like = "%$q%";
  $conds[] = "(solicitante        LIKE :q1
           OR  tipo_solicitud     LIKE :q2
           OR  folio_osc          LIKE :q3
           OR  folio_oficio_admin LIKE :q4
           OR  folio_req_info     LIKE :q5
           OR  folio_req_admin    LIKE :q6
           OR  folio_dictamen     LIKE :q7
           OR  notas              LIKE :q8)";
  $params += [
    ':q1'=>$like, ':q2'=>$like, ':q3'=>$like, ':q4'=>$like,
    ':q5'=>$like, ':q6'=>$like, ':q7'=>$like, ':q8'=>$like
  ];
}
if ($selYear)  { $conds[] = "YEAR(fecha_recibido) = :yr";  $params[':yr'] = (int)$selYear; }
if ($selMonth) { $conds[] = "MONTH(fecha_recibido) = :mn"; $params[':mn'] = (int)$selMonth; }
$where = $conds ? "WHERE ".implode(" AND ", $conds) : "";


/*──────────── Subir foto ─────────────────────────────────────*/
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['upload_id'])) {
    if (!empty($_FILES['foto_file']['tmp_name']) && $_FILES['foto_file']['error']===0) {
        $id  = (int)$_POST['upload_id'];
        $ext = strtolower(pathinfo($_FILES['foto_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $fileName = "oficio_{$id}_".time().".$ext";
            move_uploaded_file($_FILES['foto_file']['tmp_name'], "$uploadDirFs/$fileName");
            $pdo->prepare("UPDATE oficios SET foto=? WHERE id=?")->execute(["$uploadDirWeb/$fileName", $id]);
        }
    }
    // preserva filtros
    $qs = http_build_query(['p'=>$_GET['p']??1,'q'=>$q,'year'=>$selYear,'month'=>$selMonth]);
    header("Location: control_oficios.php?$qs"); exit;
}

/*──────────── POST: alta rápida o actualización modal ─────────*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['upload_id'])) {
    /* 1) actualizar por modal */
    if (isset($_POST['update_id'])) {
        $refTxt = isset($_POST['refaccion']) && is_array($_POST['refaccion']) ? implode(', ', $_POST['refaccion']) : '';
        $prov = ($_POST['proveedor_externo'] === 'OTRO')
              ? trim($_POST['proveedor_externo_otro'] ?? '')
              : $_POST['proveedor_externo'];

        $pdo->prepare("
            UPDATE oficios SET
              status=?, tipo_solicitud=?, solicitante=?, notas=?,
              folio_osc=?, folio_oficio_admin=?, folio_req_info=?,
              folio_req_admin=?, folio_dictamen=?, refaccion=?,
              proveedor_externo=?, fecha_recibido=?
            WHERE id=?")->execute([
              $_POST['status'], $_POST['tipo_solicitud'], $_POST['solicitante'], $_POST['notas'],
              $_POST['folio_osc'], $_POST['folio_oficio_admin'], $_POST['folio_req_info'],
              $_POST['folio_req_admin'], $_POST['folio_dictamen'], $refTxt,
              $prov, $_POST['fecha_recibido'] ?: date('Y-m-d'),
              (int)$_POST['update_id']
        ]);
        $qs = http_build_query(['updated'=>1,'p'=>$_GET['p']??1,'q'=>$q,'year'=>$selYear,'month'=>$selMonth]);
        header("Location: control_oficios.php?$qs"); exit;
    }

    /* 2) alta rápida */
    $refTxt = isset($_POST['refaccion']) && is_array($_POST['refaccion']) ? implode(', ', $_POST['refaccion']) : '';
    $pdo->prepare("
        INSERT INTO oficios
          (status,tipo_solicitud,solicitante,notas,folio_osc,
           folio_oficio_admin,folio_req_info,folio_req_admin,
           folio_dictamen,refaccion,proveedor_externo,fecha_recibido)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
          $_POST['status'], $_POST['tipo_solicitud'], $_POST['solicitante'], $_POST['notas'],
          $_POST['folio_osc'], $_POST['folio_oficio_admin'], $_POST['folio_req_info'],
          $_POST['folio_req_admin'], $_POST['folio_dictamen'], $refTxt,
          $_POST['proveedor_externo'], $_POST['fecha_recibido'] ?: date('Y-m-d')
    ]);
    $qs = http_build_query(['saved'=>1,'year'=>$selYear,'month'=>$selMonth,'q'=>$q]);
    header("Location: control_oficios.php?$qs"); exit;
}

/*── Eliminar oficio (solo admin) ─────────────────────────────*/
if ($role === 'admin' && isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $pdo->prepare("DELETE FROM oficios WHERE id=?")->execute([$id]);
    $qs = http_build_query(['deleted'=>1,'p'=>$_GET['p']??1,'q'=>$q,'year'=>$selYear,'month'=>$selMonth]);
    header("Location: control_oficios.php?$qs"); exit;
}

/*──────────── TotalRows (conteo con mismas condiciones) ──────*/
$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM oficios $where");
$stmtCnt->execute($params);
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/*──────────── Consulta principal (lista) ─────────────────────*/
$sql = "SELECT * FROM oficios $where
        ORDER BY CASE status
                   WHEN 'En proceso' THEN 0
                   WHEN 'Pendiente'  THEN 1
                   ELSE 2
                 END,
                 id DESC
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach($params as $k=>$v){ $stmt->bindValue($k,$v); }
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*──────────── Analíticas (todas usan el mismo WHERE) ─────────*/
/* KPIs */
$stmt = $pdo->prepare("
  SELECT COUNT(*) total,
         SUM(status='Pendiente')  AS pend,
         SUM(status='En proceso') AS proc,
         SUM(status='Concluido')  AS conc
  FROM oficios $where");
$stmt->execute($params);
$kpi = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'pend'=>0,'proc'=>0,'conc'=>0];

/* Tendencia (12 puntos por mes de fecha_recibido) */
if ($selYear) {
  $stmt = $pdo->prepare("
    SELECT DATE_FORMAT(fecha_recibido,'%Y-%m') ym, COUNT(*) cnt
    FROM oficios WHERE YEAR(fecha_recibido)=:yr
    GROUP BY ym ORDER BY ym");
  $stmt->execute([':yr'=>$selYear]);
  $rawTrend = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$trendLabels=[]; $trendValues=[];
for($m=1;$m<=12;$m++){
  $trendLabels[] = month_name_es($m);
  $oficiosM = (int)($rawTrend[sprintf('%04d-%02d',$selYear,$m)] ?? 0);
  $ciscoM   = (int)($actCiscoByMonth[$m] ?? 0);
  $trendValues[] = $oficiosM + $ciscoM;  // ← sumado
}
} else {
  $rawTrend = $pdo->query("
    SELECT DATE_FORMAT(fecha_recibido,'%Y-%m') ym, COUNT(*) cnt
    FROM oficios
    WHERE fecha_recibido >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym ORDER BY ym")->fetchAll(PDO::FETCH_KEY_PAIR);
$trendLabels=[]; $trendValues=[];
$start = new DateTime('first day of -11 months');
for($i=0;$i<12;$i++){
  $ym = $start->format('Y-m');
  $trendLabels[] = month_name_es((int)$start->format('n')).' '.substr($start->format('Y'),2);
  $oficiosYM = (int)($rawTrend[$ym] ?? 0);
  $ciscoYM   = (int)($rawActCisco[$ym] ?? 0);
  $trendValues[] = $oficiosYM + $ciscoYM;  // ← sumado
  $start->modify('+1 month');
}
}

/* Distribución por tipo_solicitud */
$stmt = $pdo->prepare("SELECT tipo_solicitud tipo, COUNT(*) cnt
                       FROM oficios $where
                       GROUP BY tipo");
$stmt->execute($params);
$tmp = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [tipo => cnt]

// Suma CISCO de actividades
$tmp['CISCO'] = ($tmp['CISCO'] ?? 0) + $actCiscoTotal;

// Re-ordena desc y vuelve a matriz de pares (para Chart.js)
arsort($tmp);
$byTipo = [];
foreach($tmp as $k=>$v){ $byTipo[] = ['tipo'=>$k, 'cnt'=>(int)$v]; }


/* Top 10 solicitantes */
$stmt = $pdo->prepare("SELECT solicitante sol, COUNT(*) cnt
                       FROM oficios $where
                       GROUP BY sol ORDER BY cnt DESC LIMIT 10");
$stmt->execute($params);
$topSolic = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Top 10 proveedores externos (excluye vacíos) */
$condProv = $where ? "$where AND proveedor_externo IS NOT NULL AND proveedor_externo<>''"
                   : "WHERE proveedor_externo IS NOT NULL AND proveedor_externo<>''";
$stmt = $pdo->prepare("SELECT proveedor_externo prov, COUNT(*) cnt
                       FROM oficios $condProv
                       GROUP BY prov ORDER BY cnt DESC LIMIT 10");
$stmt->execute($params);
$topProv = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Conteo por refacción (desde texto CSV) */
$stmt = $pdo->prepare("SELECT refaccion FROM oficios $where");
$stmt->execute($params);
$refCounts = array_fill_keys($refacciones, 0);
while($r = $stmt->fetch(PDO::FETCH_ASSOC)){
  if (!$r['refaccion']) continue;
  $parts = array_map('trim', explode(',', $r['refaccion']));
  foreach($parts as $p){
    if ($p==='' ) continue;
    if (isset($refCounts[$p])) $refCounts[$p]++; // solo catálogo
  }
}
$refLabels = array_keys($refCounts);
$refValues = array_values($refCounts);

/*──────────── Helper de color de fila ───────────────────────*/
function oficioRowClass(string $st): string {
  return in_array($st, ['Pendiente', 'En proceso']) ? 'table-danger' : 'table-success';
}
$badgeFiltro = ($selYear||$selMonth) ? (($selMonth? month_name_es($selMonth).' ':'').($selYear?:'')) : 'Todos';
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<title>Control de oficios</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="styles.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<link rel="shortcut icon" href="siti_logo.ico">
<style>
  .card-kpi h3 { font-size:1.6rem; line-height:1; }
  .filter-sticky { position: sticky; top: .5rem; z-index: 100; }
</style>
</head>
<body class="bg-secoed container-fluid py-4">
<?php
  $brand_back_href = 'index.php';
  $brand_back_text = 'Panel';
  $brand_eyebrow   = 'SECOED · Gestión documental';
  $brand_title     = 'Control de oficios';
  $brand_subtitle  = 'Registro, consulta y seguimiento de oficios con filtros por periodo, búsqueda y estatus.';
  $brand_badge     = 'Filtro: ' . $badgeFiltro . ($q ? ' · búsqueda' : '');
  require __DIR__ . '/brand_header.php';
?>

<!-- Mensajes -->
<?php
if (isset($_GET['saved']))   echo '<div class="alert alert-success">✅ Oficio guardado.</div>';
if (isset($_GET['updated'])) echo '<div class="alert alert-success">✅ Oficio actualizado.</div>';
if (isset($_GET['deleted'])) echo '<div class="alert alert-success">✅ Oficio eliminado.</div>';
?>


<!-- ALTA RÁPIDA -->
<div class="card mb-4 shadow-sm"><div class="card-body">
  <h5 class="card-title">Agregar nuevo oficio</h5>
  <form class="row g-3" method="post">
    <div class="col-md-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option>Pendiente</option><option>En proceso</option><option>Concluido</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Tipo de solicitud</label>
      <select name="tipo_solicitud" class="form-select" required>
        <?php
          $tipos=['SOPORTE','SITIO WEB','CISCO','CORREO',
                  'PERMISOS DE INTERNET','EQUIPO NUEVO','BLOQUEO DE INTERNET', 'SERVICIO SOCIAL'];
          foreach($tipos as $t) echo "<option>$t</option>";
        ?>
      </select>
    </div>
    <div class="col-md-3"><label class="form-label">Solicitante</label>
      <input name="solicitante" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label">Folio OSC</label>
      <input name="folio_osc" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Folio Oficio Adm.</label>
      <input name="folio_oficio_admin" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Folio Req. Info</label>
      <input name="folio_req_info" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Folio Req. Adm.</label>
      <input name="folio_req_admin" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Folio Dictamen</label>
      <input name="folio_dictamen" class="form-control"></div>

    <div class="col-md-4">
      <label class="form-label">Refacción</label>
      <select name="refaccion[]" class="form-select" multiple size="4">
        <?php foreach($refacciones as $r) echo "<option>$r</option>"; ?>
      </select>
      <small class="text-muted">Ctrl / ⌘ + clic para elegir varias</small>
    </div>
    <div class="col-md-4">
      <label class="form-label">Proveedor externo</label>
      <select id="provSel" name="proveedor_externo" class="form-select">
        <option disabled selected value="">– Selecciona –</option>
        <option>Centro de Cómputo</option>
        <option>Pulsar</option>
        <option>PACS</option>
        <option>Abacco</option>
        <option value="OTRO">Otro (escribir)</option>
      </select>
      <input id="provOtro" type="text" class="form-control mt-2 d-none" placeholder="Especificar proveedor…">
    </div>

    <div class="col-md-4"><label class="form-label">Fecha recibido</label>
      <input type="date" name="fecha_recibido" class="form-control"></div>
    <div class="col-12"><label class="form-label">Notas</label>
      <textarea name="notas" rows="2" class="form-control"></textarea></div>
    <div class="col-12"><button class="btn btn-primary">Guardar</button></div>
  </form>
</div></div>
<!-- FILTROS (q + año/mes) -->
<div class="card mb-4 shadow-sm filter-sticky">
  <form class="card-body row gy-2 gx-3 align-items-end" method="get">
    <div class="col-md-5">
      <label class="form-label m-0">Buscar</label>
      <input name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Solicitante, tipo, folios, notas…">
    </div>
    <div class="col-md-2">
      <label class="form-label m-0">Año</label>
      <select name="year" class="form-select">
        <option value="">Todos</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y ?>" <?= $selYear===(int)$y?'selected':'' ?>><?= (int)$y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label m-0">Mes</label>
      <select name="month" class="form-select">
        <option value="">Todos</option>
        <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= $selMonth===$m?'selected':'' ?>><?= month_name_es($m) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-md-3 d-flex gap-2">
      <button class="btn btn-primary flex-fill">Aplicar</button>
      <a class="btn btn-outline-secondary flex-fill" href="control_oficios.php">Limpiar</a>
    </div>
  </form>
</div>

<!-- LISTADO -->
<div class="table-responsive">
<table class="table table-striped table-sm table-tickets w-100">
<thead class="table-light text-center">
  <tr>
    <th style="width:70px">Foto</th>
    <th style="width:40px">Editar</th>
    <th>ID</th><th>Status</th><th>Tipo</th><th>Solicitante</th><th>Notas</th>
    <th>Folio OSC</th><th>F. Oficio Adm.</th><th>F. Req. Info</th>
    <th>F. Req. Adm.</th><th>F. Dictamen</th>
    <th>Refacción</th><th>Proveedor ext.</th><th>Fecha recibido</th><th style="width:40px">Eliminar</th>
  </tr>
</thead>
<tbody class="text-center">
<?php foreach($rows as $r): ?>
  <tr class="<?= oficioRowClass($r['status']) ?>">
    <!-- FOTO -->
    <td>
      <?php if($r['foto']): ?>
        <a href="<?= htmlspecialchars($r['foto']) ?>" target="_blank">
          <img src="<?= htmlspecialchars($r['foto']) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
        </a>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="d-inline">
        <input type="hidden" name="upload_id" value="<?= $r['id'] ?>">
        <input type="file" name="foto_file" accept="image/*" class="d-none" onchange="this.form.submit();">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="this.previousElementSibling.click();" title="Subir/actualizar foto">
          <i class="bi bi-camera"></i>
        </button>
      </form>
    </td>

    <!-- editar -->
    <td>
      <button class="btn btn-sm btn-outline-secondary edit-btn"
              data-json='<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>' title="Editar">
        <i class="bi bi-pencil-square"></i>
      </button>
    </td>

    <td><?= $r['id'] ?></td>
    <td><?= htmlspecialchars($r['status']) ?></td>
    <td><?= htmlspecialchars($r['tipo_solicitud']) ?></td>
    <td><?= htmlspecialchars($r['solicitante']) ?></td>
    <td><?= htmlspecialchars($r['notas']) ?></td>
    <td><?= htmlspecialchars($r['folio_osc']) ?></td>
    <td><?= htmlspecialchars($r['folio_oficio_admin']) ?></td>
    <td><?= htmlspecialchars($r['folio_req_info']) ?></td>
    <td><?= htmlspecialchars($r['folio_req_admin']) ?></td>
    <td><?= htmlspecialchars($r['folio_dictamen']) ?></td>
    <td><?= htmlspecialchars($r['refaccion']) ?></td>
    <td><?= htmlspecialchars($r['proveedor_externo']) ?></td>
    <td><?= $r['fecha_recibido'] ? date('d-m-Y', strtotime($r['fecha_recibido'])) : '' ?></td>

    <?php if ($role==='admin'): ?>
      <td>
        <a href="?del=<?= $r['id'] ?>&p=<?= $page ?>&q=<?= urlencode($q) ?>&year=<?= $selYear ?>&month=<?= $selMonth ?>"
           class="btn btn-sm btn-danger"
           onclick="return confirm('¿Eliminar oficio #<?= $r['id'] ?>?');" title="Eliminar">
          <i class="bi bi-trash"></i>
        </a>
      </td>
    <?php else: ?>
      <td></td>
    <?php endif; ?>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<!-- paginación -->
<?php if($totalPages>1): ?>
<nav class="mt-3" aria-label="Paginación">
  <ul class="pagination justify-content-center">
    <li class="page-item <?= $page==1?'disabled':'' ?>">
      <a class="page-link" href="?p=<?= $page-1 ?>&q=<?= urlencode($q) ?>&year=<?= $selYear ?>&month=<?= $selMonth ?>">«</a>
    </li>
    <?php for($i=1;$i<=$totalPages;$i++): ?>
      <li class="page-item <?= $i==$page?'active':'' ?>">
        <a class="page-link" href="?p=<?= $i ?>&q=<?= urlencode($q) ?>&year=<?= $selYear ?>&month=<?= $selMonth ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
    <li class="page-item <?= $page==$totalPages?'disabled':'' ?>">
      <a class="page-link" href="?p=<?= $page+1 ?>&q=<?= urlencode($q) ?>&year=<?= $selYear ?>&month=<?= $selMonth ?>">»</a>
    </li>
  </ul>
</nav>
<?php endif; ?>
<!-- ANALÍTICAS -->
<div class="mb-4">
  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <?php
      $cards = [
        ['Total',       (int)$kpi['total'], 'secondary'],
        ['Pendientes',  (int)$kpi['pend'],  'warning'],
        ['En proceso',  (int)$kpi['proc'],  'info'],
        ['Concluidos',  (int)$kpi['conc'],  'success'],
      ];
      foreach($cards as [$lbl,$num,$clr]): ?>
      <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm card-kpi">
          <div class="card-body">
            <h6 class="card-title mb-1"><?= $lbl ?></h6>
            <h3 class="fw-bold text-<?= $clr ?>"><?= $num ?></h3>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Gráficos -->
  <div class="row g-4">
    <div class="col-lg-12">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title">Tendencia mensual (oficios)</h6>
          <canvas id="cTrend" height="120"></canvas>
          <small class="text-muted">12 puntos → visión clara, sin ruido.</small>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title">Distribución por tipo de solicitud</h6>
          <canvas id="cTipo" height="200"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title">Refacciones más frecuentes</h6>
          <canvas id="cRef" height="200"></canvas>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title">Top 10 solicitantes</h6>
          <canvas id="cSolic" height="200"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title">Top 10 proveedores externos</h6>
          <canvas id="cProv" height="200"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>




<!-- modal editar -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="post" class="modal-body row g-3">
      <input type="hidden" name="update_id" id="upd_id">

      <div class="col-md-4"><label class="form-label">Status</label>
        <select name="status" id="upd_status" class="form-select">
          <option>Pendiente</option><option>En proceso</option><option>Concluido</option>
        </select></div>
      <div class="col-md-4"><label class="form-label">Tipo</label>
        <select name="tipo_solicitud" id="upd_tipo" class="form-select">
          <?php foreach(['SOPORTE','SITIO WEB','CISCO','CORREO','PERMISOS DE INTERNET','EQUIPO NUEVO','BLOQUEO DE INTERNET', 'SERVICIO SOCIAL'] as $t) echo "<option>$t</option>"; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label">Fecha recibido</label>
        <input type="date" name="fecha_recibido" id="upd_fecha" class="form-control"></div>

      <div class="col-md-6"><label class="form-label">Solicitante</label>
        <input name="solicitante" id="upd_solic" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label">Folio OSC</label>
        <input name="folio_osc" id="upd_fosc" class="form-control"></div>

      <?php
        $map=['folio_oficio_admin'=>'F. Oficio Adm.','folio_req_info'=>'F. Req. Info',
              'folio_req_admin'=>'F. Req. Adm.','folio_dictamen'=>'F. Dictamen'];
        foreach($map as $k=>$lbl){ ?>
          <div class="col-md-6">
            <label class="form-label"><?= $lbl ?></label>
            <input name="<?= $k ?>" id="upd_<?= $k ?>" class="form-control">
          </div>
      <?php } ?>

      <div class="col-md-6"><label class="form-label">Refacción</label>
        <select name="refaccion[]" id="upd_ref" class="form-select" multiple size="4">
          <?php foreach($refacciones as $r) echo "<option>$r</option>"; ?>
        </select></div>

      <!-- Proveedor externo -->
      <div class="col-md-6">
        <label class="form-label">Proveedor externo</label>
        <select id="upd_provSel" class="form-select">
          <option disabled selected value="">– Selecciona –</option>
          <option>Centro de Cómputo</option>
          <option>Pulsar</option>
          <option>PACS</option>
          <option>Abacco</option>
          <option value="OTRO">Otro (escribir)</option>
        </select>
        <input id="upd_provOtro" type="text" class="form-control mt-2 d-none" placeholder="Especificar proveedor…">
        <input type="hidden" name="proveedor_externo" id="upd_prov">
      </div>

      <div class="col-12"><label class="form-label">Notas</label>
        <textarea name="notas" id="upd_notas" rows="2" class="form-control"></textarea></div>

      <div class="col-12 text-end">
        <button class="btn btn-primary">Guardar cambios</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </form>
  </div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Modal edición
const modal=new bootstrap.Modal(document.getElementById('modalEdit'));
document.querySelectorAll('.edit-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const d=JSON.parse(btn.dataset.json);
    upd_id.value=d.id;
    upd_status.value=d.status;
    upd_tipo.value=d.tipo_solicitud;
    upd_fecha.value=d.fecha_recibido??'';
    upd_solic.value=d.solicitante;
    upd_fosc.value=d.folio_osc;
    upd_folio_oficio_admin.value=d.folio_oficio_admin;
    upd_folio_req_info.value=d.folio_req_info;
    upd_folio_req_admin.value=d.folio_req_admin;
    upd_folio_dictamen.value=d.folio_dictamen;
    upd_notas.value=d.notas;

    // refacciones seleccionadas
    const sel=(d.refaccion||'').split(', ').filter(Boolean);
    [...upd_ref.options].forEach(o=>o.selected=sel.includes(o.text));

    // Proveedor externo (preset u otro)
    const provPreset = ['Centro de Cómputo','Pulsar','PACS','Abacco'];
    if (provPreset.includes(d.proveedor_externo)) {
      upd_provSel.value = d.proveedor_externo;
      upd_provOtro.classList.add('d-none');
      upd_provOtro.value = '';
      upd_prov.value = d.proveedor_externo;
    } else {
      upd_provSel.value = 'OTRO';
      upd_provOtro.classList.remove('d-none');
      upd_provOtro.value = d.proveedor_externo || '';
      upd_prov.value = upd_provOtro.value;
    }
    upd_provSel.onchange = e=>{
      if (e.target.value==='OTRO'){ upd_provOtro.classList.remove('d-none'); upd_provOtro.value=''; upd_prov.focus(); }
      else { upd_provOtro.classList.add('d-none'); upd_provOtro.value=''; upd_prov.value=e.target.value; }
    };
    upd_provOtro.oninput = ()=> upd_prov.value = upd_provOtro.value;

    modal.show();
  });
});

// Alta: proveedor "otro"
document.getElementById('provSel').addEventListener('change', e=>{
  const otro = document.getElementById('provOtro');
  if (e.target.value === 'OTRO') {
    otro.classList.remove('d-none');
    otro.name = 'proveedor_externo_otro';
  } else {
    otro.classList.add('d-none');
    otro.name = '';
    otro.value = '';
  }
});
</script>

<script>
// ======= CHARTS (Chart.js) =======
const trendLabels = <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>;
const trendValues = <?= json_encode($trendValues) ?>;
new Chart(document.getElementById('cTrend'),{
  type:'line',
  data:{ labels:trendLabels, datasets:[{ data:trendValues, tension:.3, pointRadius:3 }] },
  options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
});

const tipoLabels = <?= json_encode(array_column($byTipo,'tipo'), JSON_UNESCAPED_UNICODE) ?>;
const tipoValues = <?= json_encode(array_column($byTipo,'cnt')) ?>;
new Chart(document.getElementById('cTipo'),{
  type:'pie', data:{ labels:tipoLabels, datasets:[{ data:tipoValues }] },
  options:{ plugins:{ legend:{ position:'bottom' } } }
});

const refLabels = <?= json_encode($refLabels, JSON_UNESCAPED_UNICODE) ?>;
const refValues = <?= json_encode($refValues) ?>;
new Chart(document.getElementById('cRef'),{
  type:'bar', data:{ labels:refLabels, datasets:[{ data:refValues }] },
  options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
});

const solicLabels = <?= json_encode(array_column($topSolic,'sol'), JSON_UNESCAPED_UNICODE) ?>;
const solicValues = <?= json_encode(array_column($topSolic,'cnt')) ?>;
new Chart(document.getElementById('cSolic'),{
  type:'bar', data:{ labels:solicLabels, datasets:[{ data:solicValues }] },
  options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
});

const provLabels = <?= json_encode(array_column($topProv,'prov'), JSON_UNESCAPED_UNICODE) ?>;
const provValues = <?= json_encode(array_column($topProv,'cnt')) ?>;
new Chart(document.getElementById('cProv'),{
  type:'bar', data:{ labels:provLabels, datasets:[{ data:provValues }] },
  options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
});
</script>

</body></html>

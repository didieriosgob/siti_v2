<?php
session_start();
$role = $_SESSION['role'] ?? 'guest';
if (!in_array($role, ['admin','user'])) { header('Location: login.php'); exit; }
$pdo = require __DIR__ . '/db.php';

/* === Filtros === */
$year  = $_GET['y'] ?? date('Y');   // solo por performance: default año actual
$month = $_GET['m'] ?? 'all';       // 1..12 | 'all'
$meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

if ($year === 'all') {
  $start = '2000-01-01'; $end = date('Y-m-d');
  $periodo = 'Todos los años';
} else {
  $y = (int)$year;
  if ($month === 'all') { $start = "$y-01-01"; $end = "$y-12-31"; $periodo = "Año $y"; }
  else { $m=(int)$month; $start = sprintf('%04d-%02d-01',$y,$m); $end = date('Y-m-t',strtotime($start)); $periodo = $meses[$m]." $y"; }
}

/* === Años disponibles (tickets.created_at + oficios.fecha_recibido + actividades.fecha_inicial) === */
$years = $pdo->query("
  SELECT y FROM (
    SELECT DISTINCT YEAR(created_at) y FROM tickets WHERE created_at IS NOT NULL
    UNION
    SELECT DISTINCT YEAR(fecha_recibido) y FROM oficios WHERE fecha_recibido IS NOT NULL
    UNION
    SELECT DISTINCT YEAR(fecha_inicial) y FROM actividades WHERE fecha_inicial IS NOT NULL
  ) t ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);

/* Helper */
function scalar($pdo,$sql,$params){ $st=$pdo->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }

/* === Cálculos POA === */

// A) Mantenimiento / Diagnóstico / Correctivo externo (desglosado)
$a1_mant = scalar($pdo,"
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='MANTENIMIENTO'
     AND fecha_inicial BETWEEN ? AND ?
", [$start,$end]);

$a1_diag = scalar($pdo,"
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion IN ('DIAGNÓSTICO','DIAGNOSTICO')
     AND fecha_inicial BETWEEN ? AND ?
", [$start,$end]);

// Total de actividades (Mantenimiento + Diagnóstico)
$a1 = $a1_mant + $a1_diag;

$a2 = scalar($pdo,"
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='SOPORTE'
     AND LOWER(actividad) LIKE '%manten%'
     AND LOWER(actividad) LIKE '%diagn%'
     AND fecha_inicial BETWEEN ? AND ?
", [$start,$end]);

$a3 = scalar($pdo,"
  SELECT COUNT(*) FROM oficios
   WHERE (TRIM(COALESCE(proveedor_externo,'')) <> '' OR TRIM(COALESCE(refaccion,'')) <> '')
     AND fecha_recibido BETWEEN ? AND ?
", [$start,$end]);

$poaA_total = $a1 + $a2 + $a3;

/* B) Soporte a usuarios / Apps (Tickets + Actividades SITIOS WEB) */
$b1 = scalar($pdo,"
  SELECT COUNT(*) FROM tickets
   WHERE created_at BETWEEN ? AND ?
", [$start,$end]);

$b2 = scalar($pdo,"
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='SITIOS WEB'
     AND fecha_inicial BETWEEN ? AND ?
", [$start,$end]);

$b3 = scalar($pdo,"
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='SOPORTE'
     AND NOT (LOWER(actividad) LIKE '%manten%' AND LOWER(actividad) LIKE '%diagn%')
     AND fecha_inicial BETWEEN ? AND ?
", [$start,$end]);

$poaB_total = $b1 + $b2 +$b3;

/* C) Video vigilancia (solo actividades) */
$poaC_total = scalar($pdo,"
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='VIDEO VIGILANCIA'
     AND fecha_inicial BETWEEN ? AND ?
", [$start,$end]);

/* D) Oficios telefonía/internet/correo + CISCO de actividades */
$dOficiosTelco = scalar($pdo,"
  SELECT COUNT(*) FROM oficios
   WHERE tipo_solicitud IN ('CISCO','CORREO','PERMISOS DE INTERNET')
     AND fecha_recibido BETWEEN ? AND ?
", [$start,$end]);

$dActCisco = scalar($pdo,"
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='CISCO'
     AND fecha_inicial BETWEEN ? AND ?
", [$start,$end]);

$poaD_total = $dOficiosTelco + $dActCisco;

/* Totales */
$poa_total = $poaA_total + $poaB_total + $poaC_total + $poaD_total;
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<title>POA – MENSUAL </title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="styles.css" rel="stylesheet">
<style>
  .kpi-grid{ display:grid; gap:16px; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); }
  .kpi-card .display-5{ font-size:2rem; line-height:1; }
  .kpi-card small{ color:#6c757d; }
  .section-title{ text-align:center; font-weight:600; margin:.5rem 0 1rem; }
  .btn-home { background:#0d6efd0f; border:1px solid #0d6efd33; }
  .list-breakdown{ font-size:.92rem; margin:0; padding-left:1rem; color:#495057; }
</style>
</head>
<body class="bg-secoed container py-4">
<?php
  $brand_back_href = 'index.php';
  $brand_back_text = 'Panel';
  $brand_eyebrow   = 'SECOED · Planeación operativa';
  $brand_title     = 'POA';
  $brand_subtitle  = 'Indicadores del Programa Operativo Anual con desglose por mantenimiento, soporte, video vigilancia y comunicaciones.';
  $brand_badge     = 'Periodo: ' . $periodo;
  require __DIR__ . '/brand_header.php';
?>

<!-- Filtros -->
<form class="row g-2 align-items-end mb-4" method="get">
  <div class="col-6 col-md-3">
    <label class="form-label">Año</label>
    <select name="y" class="form-select">
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= ($year==$y)?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
      <option disabled>────────</option>
      <option value="all" <?= ($year==='all')?'selected':'' ?>>Todos (puede ser pesado)</option>
    </select>
  </div>
  <div class="col-6 col-md-3">
    <label class="form-label">Mes</label>
    <select name="m" class="form-select">
      <option value="all" <?= ($month==='all')?'selected':'' ?>>Todos</option>
      <?php foreach ($meses as $i=>$n): ?>
        <option value="<?= $i ?>" <?= ((string)$month===(string)$i)?'selected':'' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-3 d-grid">
    <label class="form-label">&nbsp;</label>
    <button class="btn btn-primary">Aplicar</button>
  </div>
  <div class="col-12 col-md-3 d-grid">
    <label class="form-label">&nbsp;</label>
    <a class="btn btn-success" href="export_poa.php?y=<?= urlencode($year) ?>&m=<?= urlencode($month) ?>">
      <i class="bi bi-filetype-csv"></i> Exportar CSV (filtro)
    </a>
  </div>
</form>

<h3 class="section-title">Indicadores POA (totales del periodo)</h3>
<div class="kpi-grid">

  <div class="card kpi-card shadow-sm">
    <div class="card-body">
      <div class="fw-semibold mb-1">Mantenimiento/Diagnóstico/Correctivo externo</div>
      <div class="display-5"><?= $poaA_total ?></div>
      <ul class="list-breakdown mt-2">
      <li>Mantenimiento (actividades): <strong><?= $a1_mant ?></strong></li>
<li>Diagnóstico (actividades): <strong><?= $a1_diag ?></strong></li>
<li><em>Total Mant + Diag:</em> <strong><?= $a1 ?></strong></li>
      </ul>
    </div>
  </div>

  <div class="card kpi-card shadow-sm">
    <div class="card-body">
      <div class="fw-semibold mb-1">Soporte a usuarios / Aplicaciones</div>
      <div class="display-5"><?= $poaB_total ?></div>
      <ul class="list-breakdown mt-2">
        <li>Tickets (periodo): <strong><?= $b1 ?></strong></li>
        <li>Actividades “SITIOS WEB”: <strong><?= $b2 ?></strong></li>
        <li>SOPORTE (Actividades): <strong><?= $b3 ?></strong></li>
      </ul>
    </div>
  </div>

  <div class="card kpi-card shadow-sm">
    <div class="card-body">
      <div class="fw-semibold mb-1">Video vigilancia</div>
      <div class="display-5"><?= $poaC_total ?></div>
      <small>Conteo de actividades con tipo “VIDEO VIGILANCIA”.</small>
    </div>
  </div>

  <div class="card kpi-card shadow-sm">
    <div class="card-body">
      <div class="fw-semibold mb-1">Telefonía / Internet / Correo</div>
      <div class="display-5"><?= $poaD_total ?></div>
      <ul class="list-breakdown mt-2">
        <li>Oficios (CISCO, CORREO, PERMISOS INTERNET): <strong><?= $dOficiosTelco ?></strong></li>
        <li>Actividades “CISCO”: <strong><?= $dActCisco ?></strong></li>
      </ul>
    </div>
  </div>

</div>

<!-- Total global -->
<div class="text-center mt-4">
  <div class="card d-inline-block shadow-sm">
    <div class="card-body py-2 px-4">
      <span class="fw-semibold">TOTAL POA del período: </span>
      <span class="display-6" style="font-size:1.8rem;"><?= $poa_total ?></span>
    </div>
  </div>
</div>

<!-- Volver -->
<div class="text-center my-4">
  <a class="btn btn-outline-primary" href="index.php"><i class="bi bi-house-door"></i> Volver al inicio</a>
</div>

</body></html>

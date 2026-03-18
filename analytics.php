<?php
/*************************************************************
 * analytics.php – Panel de analíticas (admin/user)
 * Mejoras: filtros por Año/Mes, consultas parametrizadas,
 * tendencia mensual compacta y charts acotados (Top-N).
 *************************************************************/
session_start();
if (!isset($_SESSION['uid']) || $_SESSION['role'] === 'guest') {
    header('Location: login.php'); exit;
}
$pdo = require __DIR__.'/db.php';

/* ── Utilidades ──────────────────────────────────────────── */
function as_int_or_null($v) {
    return (isset($v) && is_numeric($v)) ? (int)$v : null;
}
function month_name_es(int $m): string {
    // Evitamos depender del locale del servidor
    $n = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return $n[$m] ?? (string)$m;
}
function build_where_and_params(?int $yr, ?int $mn, string $alias='tickets'): array {
    $w = []; $p = [];
    if ($yr) { $w[] = "YEAR($alias.created_at) = :yr"; $p[':yr'] = $yr; }
    if ($mn) { $w[] = "MONTH($alias.created_at) = :mn"; $p[':mn'] = $mn; }
    $where = $w ? ('WHERE '.implode(' AND ', $w)) : '';
    return [$where, $p];
}

/* ── Filtros de UI ───────────────────────────────────────── */
$selYear  = as_int_or_null($_GET['year']  ?? null);
$selMonth = as_int_or_null($_GET['month'] ?? null);

/* Años disponibles (en base a tickets.created_at) */
$years = $pdo->query("SELECT DISTINCT YEAR(created_at) AS yr FROM tickets ORDER BY yr DESC")
             ->fetchAll(PDO::FETCH_COLUMN);

/* ── Métricas resumen (filtradas) ────────────────────────── */
list($W, $P) = build_where_and_params($selYear, $selMonth, 'tickets');
$sqlTot = "
  SELECT COUNT(*) total,
         SUM(status IN ('Pendiente','En camino')) AS pend,
         SUM(status='Atendido')                   AS att,
         ROUND(AVG(IF(status='Atendido', TIMESTAMPDIFF(HOUR,created_at,attended_at), NULL)),1) AS avg_hrs
  FROM tickets $W";
$stmt = $pdo->prepare($sqlTot); $stmt->execute($P);
$tot = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'pend'=>0,'att'=>0,'avg_hrs'=>null];

/* ── TOP 10 usuarios que atendieron más (filtrado por fecha del ticket) ── */
list($W2, $P2) = build_where_and_params($selYear, $selMonth, 't');
$sqlByUser = "
    SELECT u.username AS usr, COUNT(t.id) AS cnt
      FROM users u
 LEFT JOIN tickets t ON t.attended_by = u.username ".($W2?str_replace('WHERE','AND',$W2):'')."
  GROUP BY u.username
  ORDER BY cnt DESC
  LIMIT 10";
$stmt = $pdo->prepare($sqlByUser); $stmt->execute($P2);
$byUser = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Datos por Tipo (pie, filtrado) ───────────────────────── */
$sqlByType = "
  SELECT COALESCE(NULLIF(equipment_type,''),'Sin registro') AS type,
         COUNT(*) cnt
  FROM tickets $W
  GROUP BY type
  ORDER BY cnt DESC";
$stmt = $pdo->prepare($sqlByType); $stmt->execute($P);
$byType = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── TOP 10 Direcciones (bar, filtrado) ───────────────────── */
$sqlByDir = "
  SELECT direction dir, COUNT(*) cnt
  FROM tickets
  $W".($W?' AND ':' WHERE ')."direction IS NOT NULL AND direction<>'' AND direction<>'N/A'
  GROUP BY direction
  ORDER BY cnt DESC
  LIMIT 10";
$stmt = $pdo->prepare($sqlByDir); $stmt->execute($P);
$byDir = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Tendencia mensual compacta ─────────────────────────────
   Si hay año seleccionado: 12 meses del año.
   Si no: últimos 12 meses desde hoy. */
if ($selYear) {
    $sqlTrend = "
      SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
        FROM tickets
       WHERE YEAR(created_at)=:yr
    GROUP BY ym
    ORDER BY ym";
    $stmt = $pdo->prepare($sqlTrend); $stmt->execute([':yr'=>$selYear]);
    $rawTrend = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ym => cnt

    // Aseguramos 12 meses con ceros
    $trendLabels = []; $trendValues = [];
    for ($m=1; $m<=12; $m++) {
        $k = sprintf('%04d-%02d', $selYear, $m);
        $trendLabels[] = month_name_es($m);
        $trendValues[] = (int)($rawTrend[$k] ?? 0);
    }
} else {
    // últimos 12 meses móviles
    $sqlTrend = "
      SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
        FROM tickets
       WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym
    ORDER BY ym";
    $rawTrend = $pdo->query($sqlTrend)->fetchAll(PDO::FETCH_KEY_PAIR);

    // Normalizamos a 12 puntos
    $trendLabels = []; $trendValues = [];
    $start = new DateTime('first day of -11 months'); // incluye el actual
    for ($i=0; $i<12; $i++) {
        $ym = $start->format('Y-m');
        $trendLabels[] = month_name_es((int)$start->format('n')).' '.substr($start->format('Y'),2);
        $trendValues[] = (int)($rawTrend[$ym] ?? 0);
        $start->modify('+1 month');
    }
}

/* ── Conteo por tipo de soporte (Oficios) ───────────────────
   Nota: si tu tabla `oficios` tiene `created_at`, puedes
   aplicar el mismo filtro de fecha descomentando el WHERE. */
$sqlSupport = "
    SELECT tipo_solicitud AS tipo, COUNT(*) AS cnt
      FROM oficios
  /* WHERE YEAR(created_at)=:yr AND MONTH(created_at)=:mn */ 
  GROUP BY tipo
  ORDER BY cnt DESC";
$stmt = $pdo->prepare($sqlSupport);
/* Si activas el WHERE arriba:
   $stmt->execute([':yr'=>$selYear, ':mn'=>$selMonth]); */
$stmt->execute();
$supportCnt = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Helpers de presentación ─────────────────────────────── */
function acronym(string $str): string {
    $words = preg_split('/\\s+/u', trim($str));
    if (count($words) <= 3) { return $str; }
    preg_match_all('/\\b\\p{L}/u', $str, $m);
    return mb_strtoupper(implode($m[0]));
}
$badgeFiltro = ($selYear || $selMonth)
  ? (($selMonth? month_name_es($selMonth).' ':'').($selYear ?: ''))
  : 'Sin filtro (global/reciente)';
?>

<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Analíticas · Tickets</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<link href="styles.css" rel="stylesheet">
<link rel="icon" type="image/x-icon" href="siti_logo.ico">
<style>
  .card-kpi h3 { font-size: 1.8rem; line-height: 1; }
  .filter-sticky { position: sticky; top: .5rem; z-index: 100; }
</style>
</head>
<body class="bg-secoed container py-4">
<?php
  $brand_back_href = 'index.php';
  $brand_back_text = 'Panel';
  $brand_eyebrow   = 'SECOED · Indicadores institucionales';
  $brand_title     = 'Analíticas de tickets';
  $brand_subtitle  = 'Consulta rápida de volumen, atención, tendencia mensual y comportamiento operativo del servicio.';
  $brand_badge     = 'Filtro: ' . $badgeFiltro;
  require __DIR__ . '/brand_header.php';
?>

  <!-- Filtros compactos -->
  <div class="card mb-4 shadow-sm filter-sticky">
    <form class="card-body row gy-2 gx-3 align-items-end" method="get">
      <div class="col-12 col-md-4">
        <label for="year" class="form-label m-0">Año</label>
        <select id="year" name="year" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y ?>" <?= $selYear===(int)$y?'selected':'' ?>><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label for="month" class="form-label m-0">Mes</label>
        <select id="month" name="month" class="form-select">
          <option value="">Todos</option>
          <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?= $m ?>" <?= $selMonth===$m?'selected':'' ?>><?= month_name_es($m) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-12 col-md-4 d-flex gap-2">
        <button class="btn btn-primary flex-fill">Aplicar</button>
        <a class="btn btn-outline-secondary flex-fill" href="analytics.php">Limpiar</a>
        <a href="export_analytics.php<?= ($selYear||$selMonth)?('?year='.$selYear.'&month='.$selMonth):'' ?>"
           class="btn btn-success flex-fill">CSV</a>
      </div>
    </form>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <?php
      $cards = [
        ['Total',        (int)$tot['total'],        'secondary'],
        ['Pendientes',   (int)$tot['pend'],         'warning'],
        ['Atendidos',    (int)$tot['att'],          'success'],
        ['⌀ Horas resp.',($tot['avg_hrs']!==null?$tot['avg_hrs']:'—'), 'info']
      ];
      foreach($cards as [$lbl,$num,$clr]): ?>
      <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm card-kpi">
          <div class="card-body">
            <h6 class="card-title  mb-1"><?= $lbl ?></h6>
            <h3 class="fw-bold text-<?= $clr ?>"><?= htmlspecialchars((string)$num) ?></h3>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Tendencia mensual (12 puntos) -->
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h6 class="card-title mb-2">Tendencia mensual (tickets)</h6>
      <canvas id="cTrend" height="120"></canvas>
    </div>
  </div>

  <!-- Charts centrados en lo importante -->
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title">Top 10 Direcciones</h6>
          <canvas id="cDir" height="200"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title">Distribución por tipo de equipo (%)</h6>
          <canvas id="cType" height="200"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-12">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title">Top 10 usuarios con más tickets atendidos</h6>
          <canvas id="cUser" height="200"></canvas>
        </div>
      </div>
    </div>
  </div>

  <script>
  // Datos desde PHP
  const trendLabels = <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>;
  const trendValues = <?= json_encode($trendValues) ?>;

  const dirLabels = <?= json_encode(array_map(fn($r)=>acronym($r['dir']),$byDir), JSON_UNESCAPED_UNICODE) ?>;
  const dirValues = <?= json_encode(array_column($byDir,'cnt')) ?>;

  const typeLabels = <?= json_encode(array_column($byType,'type'), JSON_UNESCAPED_UNICODE) ?>;
  const typeValues = <?= json_encode(array_column($byType,'cnt')) ?>;

  const userLabels = <?= json_encode(array_column($byUser,'usr'), JSON_UNESCAPED_UNICODE) ?>;
  const userValues = <?= json_encode(array_column($byUser,'cnt')) ?>;

  // Trend (línea) – compacta
  new Chart(document.getElementById('cTrend'), {
    type: 'line',
    data: {
      labels: trendLabels,
      datasets: [{ data: trendValues, tension: .3, fill: false, pointRadius: 3 }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { precision:0 } }
      }
    }
  });

  // Barras: Direcciones
  new Chart(document.getElementById('cDir'), {
    type:'bar',
    data:{ labels: dirLabels, datasets:[{ data: dirValues }] },
    options:{
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }
    }
  });

  // Pie por tipo
  new Chart(document.getElementById('cType'),{
    type:'pie',
    data:{ labels:typeLabels, datasets:[{ data:typeValues }] },
    options:{ plugins:{ legend:{ position:'bottom' } } }
  });

  // Barras: Usuarios
  new Chart(document.getElementById('cUser'),{
    type:'bar',
    data:{ labels:userLabels, datasets:[{ data:userValues }] },
    options:{
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }
    }
  });
  </script>
</body>
</html>

<?php
session_start();
$role = $_SESSION['role'] ?? 'guest';
if (!in_array($role, ['admin','user'])) {
    header('Location: login.php');
    exit;
}

$pdo = require __DIR__ . '/db.php';

/* === Filtros === */
$year  = $_GET['y'] ?? date('Y');
$month = $_GET['m'] ?? 'all';
$meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

if ($year === 'all') {
    $start = '2000-01-01';
    $end = date('Y-m-d');
    $periodo = 'Todos los años';
} else {
    $y = (int)$year;
    if ($month === 'all') {
        $start = "$y-01-01";
        $end   = "$y-12-31";
        $periodo = "Año $y";
    } else {
        $m = (int)$month;
        if ($m < 1 || $m > 12) {
            $m = (int)date('n');
        }
        $start = sprintf('%04d-%02d-01', $y, $m);
        $end   = date('Y-m-t', strtotime($start));
        $periodo = ($meses[$m] ?? 'Mes') . " $y";
    }
}

function scalar(PDO $pdo, string $sql, array $params): int {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/* === Cálculos POA === */

/* A) Mantenimiento/Diagnóstico/Correctivo externo */
$a1 = scalar($pdo, "
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion IN ('MANTENIMIENTO','DIAGNÓSTICO')
     AND fecha_inicial BETWEEN ? AND ?
", [$start, $end]);

$a2 = scalar($pdo, "
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='SOPORTE'
     AND LOWER(actividad) LIKE '%manten%'
     AND LOWER(actividad) LIKE '%diagn%'
     AND fecha_inicial BETWEEN ? AND ?
", [$start, $end]);

$a3 = scalar($pdo, "
  SELECT COUNT(*) FROM oficios
   WHERE (TRIM(COALESCE(proveedor_externo,'')) <> '' OR TRIM(COALESCE(refaccion,'')) <> '')
     AND fecha_recibido BETWEEN ? AND ?
", [$start, $end]);

$poaA_total = $a1 + $a2 + $a3;

/* B) Soporte a usuarios / Apps (Tickets + Actividades SITIOS WEB) */
$b1 = scalar($pdo, "
  SELECT COUNT(*) FROM tickets
   WHERE created_at BETWEEN ? AND ?
", [$start, $end]);

$b2 = scalar($pdo, "
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='SITIOS WEB'
     AND fecha_inicial BETWEEN ? AND ?
", [$start, $end]);

$poaB_total = $b1 + $b2;

/* C) Video vigilancia */
$poaC_total = scalar($pdo, "
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='VIDEO VIGILANCIA'
     AND fecha_inicial BETWEEN ? AND ?
", [$start, $end]);

/* D) Oficios telefonía/internet/correo + CISCO de actividades */
$dOficiosTelco = scalar($pdo, "
  SELECT COUNT(*) FROM oficios
   WHERE tipo_solicitud IN ('CISCO','CORREO','PERMISOS DE INTERNET')
     AND fecha_recibido BETWEEN ? AND ?
", [$start, $end]);

$dActCisco = scalar($pdo, "
  SELECT COUNT(*) FROM actividades
   WHERE tipo_atencion='CISCO'
     AND fecha_inicial BETWEEN ? AND ?
", [$start, $end]);

$poaD_total = $dOficiosTelco + $dActCisco;

$poa_total = $poaA_total + $poaB_total + $poaC_total + $poaD_total;

/* === Salida CSV === */
$filename = 'poa_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(str_replace(' ', '_', $periodo))) . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel

$out = fopen('php://output', 'w');

fputcsv($out, ['POA - REPORTE']);
fputcsv($out, ['Periodo', $periodo]);
fputcsv($out, ['Fecha inicio', $start]);
fputcsv($out, ['Fecha fin', $end]);
fputcsv($out, []);

fputcsv($out, ['Apartado', 'Concepto', 'Cantidad']);

fputcsv($out, ['Mantenimiento/Diagnóstico/Correctivo externo', 'Mantenimiento + Diagnóstico (actividades)', $a1]);
fputcsv($out, ['Mantenimiento/Diagnóstico/Correctivo externo', 'Soporte con texto manten y diagn', $a2]);
fputcsv($out, ['Mantenimiento/Diagnóstico/Correctivo externo', 'Canalización (oficios con refacción/proveedor)', $a3]);
fputcsv($out, ['Mantenimiento/Diagnóstico/Correctivo externo', 'TOTAL APARTADO', $poaA_total]);

fputcsv($out, ['Soporte a usuarios / Aplicaciones', 'Tickets (periodo)', $b1]);
fputcsv($out, ['Soporte a usuarios / Aplicaciones', 'Actividades SITIOS WEB', $b2]);
fputcsv($out, ['Soporte a usuarios / Aplicaciones', 'TOTAL APARTADO', $poaB_total]);

fputcsv($out, ['Video vigilancia', 'Actividades VIDEO VIGILANCIA', $poaC_total]);
fputcsv($out, ['Video vigilancia', 'TOTAL APARTADO', $poaC_total]);

fputcsv($out, ['Telefonía / Internet / Correo', 'Oficios (CISCO, CORREO, PERMISOS DE INTERNET)', $dOficiosTelco]);
fputcsv($out, ['Telefonía / Internet / Correo', 'Actividades CISCO', $dActCisco]);
fputcsv($out, ['Telefonía / Internet / Correo', 'TOTAL APARTADO', $poaD_total]);

fputcsv($out, []);
fputcsv($out, ['TOTAL POA DEL PERÍODO', '', $poa_total]);

fclose($out);
exit;

<?php
// export_analytics.php — CSV de tickets (plantilla base)
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="analytics_tickets.csv"');

$pdo = require __DIR__ . '/db.php';

$out = fopen('php://output', 'w');
fputcsv($out, ['Folio','Usuario','Dirección','Departamento','Patrimonio','Tipo equipo','Marca','Status','Atendido por','Fecha']);

$stmt = $pdo->query("
  SELECT id, user_name, direction, department, asset_number,
         equipment_type, brand, status, attended_by, created_at
    FROM tickets
ORDER BY created_at DESC
LIMIT 5000
");

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  fputcsv($out, $r);
}
fclose($out);
exit;

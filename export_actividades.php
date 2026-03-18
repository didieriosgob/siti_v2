<?php
/**
 * export_actividades_excel.php
 *
 * Exporta actividades de la tabla `actividades` a un archivo Excel (.xls)
 * usando una tabla HTML (Excel la interpreta).
 */

session_start();

$role = $_SESSION['role'] ?? 'guest';
if ($role === 'guest') {
    header('Location: login.php');
    exit;
}

$pdo = require __DIR__ . '/db.php';

// (Opcional) usar los mismos filtros que control_actividades.php: q y y
$q = trim($_GET['q'] ?? '');
$yearParam   = $_GET['y'] ?? date('Y');
$useAllYears = ($yearParam === 'all');

$where  = [];
$params = [];

// Filtro por año (fecha_inicial)
if (!$useAllYears) {
    $where[] = "(fecha_inicial BETWEEN ? AND ?)";
    $params[] = $yearParam . '-01-01';
    $params[] = $yearParam . '-12-31';
}

// Búsqueda
if ($q !== '') {
    $like = "%$q%";
    $where[] = "(actividad LIKE ? OR tipo_atencion LIKE ? OR created_by LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = "
    SELECT id,
           status,
           actividad,
           fecha_inicial,
           fecha_final,
           tipo_atencion,
           created_by,
           created_at
      FROM actividades
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
  ORDER BY CASE status
             WHEN 'EN PROCESO' THEN 0
             ELSE 1
           END,
           fecha_inicial DESC, id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// Nombre del archivo
$labelYear = $useAllYears ? 'ALL' : $yearParam;
$filename = 'actividades_siti_' . $labelYear . '_' . date('Ymd_His') . '.xls';

// Encabezados Excel
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM UTF-8 para acentos
echo "\xEF\xBB\xBF";

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

?>
<table border="1">
    <thead>
        <tr>
            <th>ID</th>
            <th>Status</th>
            <th>Actividad</th>
            <th>Fecha inicial</th>
            <th>Fecha final</th>
            <th>Tipo de atención</th>
            <th>Capturado por</th>
            <th>Creado en</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
            <tr>
                <td><?= h($row['id']) ?></td>
                <td><?= h($row['status']) ?></td>
                <td><?= h($row['actividad']) ?></td>
                <td><?= h($row['fecha_inicial']) ?></td>
                <td><?= h($row['fecha_final']) ?></td>
                <td><?= h($row['tipo_atencion']) ?></td>
                <td><?= h($row['created_by']) ?></td>
                <td><?= h($row['created_at']) ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
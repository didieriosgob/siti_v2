<?php
/**
 * export_tickets_excel.php
 *
 * Exporta todos los tickets de la tabla `tickets` a un archivo Excel (.xls)
 * usando una tabla HTML (Excel la interpreta sin problema).
 */

session_start();

// Opcional: solo usuarios autenticados
$role = $_SESSION['role'] ?? 'guest';
if ($role === 'guest') {
    header('Location: login.php');
    exit;
}

// Conexión PDO (mismo db.php que usas en el sistema)
$pdo = require __DIR__ . '/db.php';

// Nombre del archivo
$filename = 'tickets_siti_' . date('Ymd_His') . '.xls';

// Encabezados para forzar descarga como Excel
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Asegurar salida en UTF-8
echo "\xEF\xBB\xBF"; // BOM para que Excel respete UTF-8

// Consulta de tickets (ajusta si quieres filtros por fecha/estatus)
$sql = "
    SELECT id,
           user_name,
           department,
           direction,
           asset_number,
           equipment_type,
           brand,
           description,
           solucion,
           created_at,
           status,
           attended_by,
           attended_at
      FROM tickets
  ORDER BY created_at DESC
";

$stmt = $pdo->query($sql);

// Función de escape básica para HTML
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Comenzamos la tabla HTML que Excel interpretará
?>
<table border="1">
    <thead>
        <tr>
            <th>ID</th>
            <th>Fecha creación</th>
            <th>Nombre usuario</th>
            <th>Departamento</th>
            <th>Dirección</th>
            <th>Núm. de activo</th>
            <th>Tipo de equipo</th>
            <th>Marca</th>
            <th>Descripción</th>
            <th>Solución</th>
            <th>Estatus</th>
            <th>Atendido por</th>
            <th>Fecha atención</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
            <tr>
                <td><?= h($row['id']) ?></td>
                <td><?= h($row['created_at']) ?></td>
                <td><?= h($row['user_name']) ?></td>
                <td><?= h($row['department']) ?></td>
                <td><?= h($row['direction']) ?></td>
                <td><?= h($row['asset_number']) ?></td>
                <td><?= h($row['equipment_type']) ?></td>
                <td><?= h($row['brand']) ?></td>
                <td><?= h($row['description']) ?></td>
                <td><?= h($row['solucion']) ?></td>
                <td><?= h($row['status']) ?></td>
                <td><?= h($row['attended_by']) ?></td>
                <td><?= h($row['attended_at']) ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>

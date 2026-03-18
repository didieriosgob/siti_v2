<?php
/*****************************************************************
 * control_cotizaciones.php – Control de Cotizaciones y Facturas
 * ----------------------------------------------------------------
 * - Alta y edición de registros con:
 *     · Req. Informática / Dictamen / Req. Administración
 *     · Proveedor, Artículo, Descripción
 *     · PDF Cotización, PDF Factura, XML Factura
 *     · Check: Enviado a Joaquín para alta de patrimonio
 * - Listado con paginación y buscador
 * - Eliminación de registro + archivos (solo admin)
 * - Archivos en carpetas por Req. Administración:
 *     uploads/cotizaciones/<REQ_ADMIN_NORMALIZADA>/
 *****************************************************************/

session_start();
$role     = $_SESSION['role']     ?? 'guest';
$username = $_SESSION['username'] ?? '';

if (!in_array($role, ['admin', 'user'])) {
    header('Location: login.php');
    exit;
}

$pdo = require __DIR__ . '/db.php';

$errors    = [];
$messages  = [];
$editId    = null;
$editRow   = null;

/*──────────── Configuración de uploads ─────────────────────────*/
$uploadBaseFs  = __DIR__ . '/uploads/cotizaciones'; // ruta física base
$uploadBaseWeb = 'uploads/cotizaciones';            // ruta web base

if (!is_dir($uploadBaseFs)) {
    mkdir($uploadBaseFs, 0775, true);
}

/*──────────── Helpers básicos ─────────────────────────────────*/
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Normaliza la carpeta a partir de la requisición de administración.
 */
function buildSubdir(?string $reqAdm): string
{
    $reqAdm = trim((string)$reqAdm);
    if ($reqAdm === '') {
        return 'SIN_REQ';
    }
    $slug = strtoupper($reqAdm);
    // Solo letras, números, guion y guion bajo
    $slug = preg_replace('/[^A-Z0-9_\-]/', '_', $slug);
    if ($slug === '' || $slug === null) {
        $slug = 'SIN_REQ';
    }
    return $slug;
}

/**
 * Sube un archivo de $_FILES[$campo] si cumple extensión permitida.
 * Crea la subcarpeta si no existe.
 * Devuelve la ruta relativa "subdir/archivo.ext" o null.
 */
function subirArchivo(
    string $campo,
    array $extPermitidas,
    string $baseDirFs,
    string $subdir,
    array &$errors,
    string $etiqueta
): ?string {
    if (empty($_FILES[$campo]['name'] ?? '')) {
        return null;
    }

    $file = $_FILES[$campo];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Error al subir «{$etiqueta}».";
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extPermitidas, true)) {
        $errors[] = "El archivo de «{$etiqueta}» debe ser de tipo: " . implode(', ', $extPermitidas) . ".";
        return null;
    }

    $dirFs = $baseDirFs . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($dirFs)) {
        mkdir($dirFs, 0775, true);
    }

    $nuevoNombre = uniqid($campo . '_', true) . '.' . $ext;
    $destinoFs   = $dirFs . DIRECTORY_SEPARATOR . $nuevoNombre;

    if (!move_uploaded_file($file['tmp_name'], $destinoFs)) {
        $errors[] = "No se pudo guardar el archivo de «{$etiqueta}».";
        return null;
    }

    // Guardamos en BD la ruta relativa incluyendo carpeta
    return $subdir . '/' . $nuevoNombre;
}

/**
 * Borra un archivo físico a partir de la ruta relativa guardada en BD.
 */
function borrarArchivoFisico(string $baseDirFs, ?string $relPath): void
{
    if (!$relPath) {
        return;
    }
    // Seguridad básica: evitar .. y backslashes
    $relPath = str_replace(['..', '\\'], '', $relPath);
    $relPath = ltrim($relPath, '/');
    $f       = $baseDirFs . DIRECTORY_SEPARATOR . $relPath;
    if (is_file($f)) {
        @unlink($f);
    }
}

/*──────────────── Manejo de POST (crear / actualizar) ─────────*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $accion = $_POST['accion'] ?? '';

    /*──────── Alta ────────*/
    if ($accion === 'crear') {

        $reqInfo   = trim($_POST['requisicion_informatica']    ?? '');
        $dictamen  = trim($_POST['dictamen']                   ?? '');
        $reqAdm    = trim($_POST['requisicion_administracion'] ?? '');
        $prov      = trim($_POST['proveedor']                  ?? '');
        $art       = trim($_POST['articulo']                   ?? '');
        $desc      = trim($_POST['descripcion']                ?? '');
        $enviado   = isset($_POST['enviado_patrimonio']) ? 1 : 0;

        if ($prov === '') {
            $errors[] = 'El campo «Proveedor» es obligatorio.';
        }
        if ($art === '') {
            $errors[] = 'El campo «Artículo» es obligatorio.';
        }

        $subdir        = buildSubdir($reqAdm);
        $cotizacionPdf = null;
        $facturaPdf    = null;
        $facturaXml    = null;

        if (!$errors) {
            $cotizacionPdf = subirArchivo('cotizacion_pdf', ['pdf'], $uploadBaseFs, $subdir, $errors, 'Cotización (PDF)');
            $facturaPdf    = subirArchivo('factura_pdf',    ['pdf'], $uploadBaseFs, $subdir, $errors, 'Factura (PDF)');
            $facturaXml    = subirArchivo('factura_xml',    ['xml'], $uploadBaseFs, $subdir, $errors, 'Factura (XML)');
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO cotizaciones_facturas
                        (requisicion_informatica, dictamen, requisicion_administracion,
                         proveedor, articulo, descripcion,
                         cotizacion_pdf, factura_pdf, factura_xml,
                         enviado_patrimonio, usuario_registro)
                    VALUES
                        (:ri, :di, :ra,
                         :pr, :ar, :de,
                         :cp, :fp, :fx,
                         :ep, :usr)
                ");

                $stmt->execute([
                    ':ri'  => $reqInfo !== ''  ? $reqInfo  : null,
                    ':di'  => $dictamen !== '' ? $dictamen : null,
                    ':ra'  => $reqAdm  !== ''  ? $reqAdm   : null,
                    ':pr'  => $prov,
                    ':ar'  => $art,
                    ':de'  => $desc !== '' ? $desc : null,
                    ':cp'  => $cotizacionPdf,
                    ':fp'  => $facturaPdf,
                    ':fx'  => $facturaXml,
                    ':ep'  => $enviado,
                    ':usr' => $username !== '' ? $username : 'sistema'
                ]);

                header('Location: control_cotizaciones.php?ok=1');
                exit;

            } catch (PDOException $e) {
                $errors[] = 'Error al guardar el registro en la base de datos.';
            }
        }

    }

    /*──────── Actualizar (edición) ────────*/
    if ($accion === 'actualizar') {

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if (!$id || $id <= 0) {
            $errors[] = 'ID de registro no válido.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM cotizaciones_facturas WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$editRow) {
                $errors[] = 'Registro no encontrado para edición.';
            } else {
                $editId = $id;
            }
        }

        $reqInfo   = trim($_POST['requisicion_informatica']    ?? '');
        $dictamen  = trim($_POST['dictamen']                   ?? '');
        $reqAdm    = trim($_POST['requisicion_administracion'] ?? '');
        $prov      = trim($_POST['proveedor']                  ?? '');
        $art       = trim($_POST['articulo']                   ?? '');
        $desc      = trim($_POST['descripcion']                ?? '');
        $enviado   = isset($_POST['enviado_patrimonio']) ? 1 : 0;

        if ($prov === '') {
            $errors[] = 'El campo «Proveedor» es obligatorio.';
        }
        if ($art === '') {
            $errors[] = 'El campo «Artículo» es obligatorio.';
        }

        // Subcarpeta con la requisición de administración nueva (o la previa si viene vacía)
        if (!$reqAdm && $editRow) {
            $subdir = buildSubdir($editRow['requisicion_administracion'] ?? '');
        } else {
            $subdir = buildSubdir($reqAdm);
        }

        $newCotizacionPdf = null;
        $newFacturaPdf    = null;
        $newFacturaXml    = null;

        if (!$errors && $editRow) {
            $newCotizacionPdf = subirArchivo('cotizacion_pdf', ['pdf'], $uploadBaseFs, $subdir, $errors, 'Cotización (PDF)');
            $newFacturaPdf    = subirArchivo('factura_pdf',    ['pdf'], $uploadBaseFs, $subdir, $errors, 'Factura (PDF)');
            $newFacturaXml    = subirArchivo('factura_xml',    ['xml'], $uploadBaseFs, $subdir, $errors, 'Factura (XML)');
        }

        if (!$errors && $editRow) {
            // Si se subió archivo nuevo, borrar el anterior
            if ($newCotizacionPdf !== null && !empty($editRow['cotizacion_pdf'])) {
                borrarArchivoFisico($uploadBaseFs, $editRow['cotizacion_pdf']);
                $editRow['cotizacion_pdf'] = $newCotizacionPdf;
            }
            if ($newFacturaPdf !== null && !empty($editRow['factura_pdf'])) {
                borrarArchivoFisico($uploadBaseFs, $editRow['factura_pdf']);
                $editRow['factura_pdf'] = $newFacturaPdf;
            }
            if ($newFacturaXml !== null && !empty($editRow['factura_xml'])) {
                borrarArchivoFisico($uploadBaseFs, $editRow['factura_xml']);
                $editRow['factura_xml'] = $newFacturaXml;
            }

            $sql = "
                UPDATE cotizaciones_facturas
                   SET requisicion_informatica    = :ri,
                       dictamen                  = :di,
                       requisicion_administracion = :ra,
                       proveedor                  = :pr,
                       articulo                   = :ar,
                       descripcion                = :de,
                       enviado_patrimonio         = :ep
            ";

            $params = [
                ':ri'  => $reqInfo !== ''  ? $reqInfo  : null,
                ':di'  => $dictamen !== '' ? $dictamen : null,
                ':ra'  => $reqAdm  !== ''  ? $reqAdm   : null,
                ':pr'  => $prov,
                ':ar'  => $art,
                ':de'  => $desc !== '' ? $desc : null,
                ':ep'  => $enviado,
                ':id'  => $id
            ];

            // Actualizar campos de archivos solo si se subió algo nuevo
            if ($newCotizacionPdf !== null) {
                $sql               .= ", cotizacion_pdf = :cp";
                $params[':cp']      = $newCotizacionPdf;
            }
            if ($newFacturaPdf !== null) {
                $sql               .= ", factura_pdf = :fp";
                $params[':fp']      = $newFacturaPdf;
            }
            if ($newFacturaXml !== null) {
                $sql               .= ", factura_xml = :fx";
                $params[':fx']      = $newFacturaXml;
            }

            $sql .= " WHERE id = :id";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                header('Location: control_cotizaciones.php?upd_ok=1');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Error al actualizar el registro en la base de datos.';
            }
        }
    }
}

/*──────────────── Eliminar registro (solo admin) ──────────────*/
if ($role === 'admin' && isset($_GET['del'])) {
    $id = filter_input(INPUT_GET, 'del', FILTER_VALIDATE_INT);

    if ($id && $id > 0) {
        $stmt = $pdo->prepare("
            SELECT cotizacion_pdf, factura_pdf, factura_xml
              FROM cotizaciones_facturas
             WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            borrarArchivoFisico($uploadBaseFs, $row['cotizacion_pdf'] ?? null);
            borrarArchivoFisico($uploadBaseFs, $row['factura_pdf']    ?? null);
            borrarArchivoFisico($uploadBaseFs, $row['factura_xml']    ?? null);

            $del = $pdo->prepare("DELETE FROM cotizaciones_facturas WHERE id = :id");
            $del->execute([':id' => $id]);
            header('Location: control_cotizaciones.php?del_ok=1');
            exit;
        }
    }
}

/*──────────────── Modo edición por GET ────────────────────────*/
if (isset($_GET['edit'])) {
    $tmpId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($tmpId && $tmpId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM cotizaciones_facturas WHERE id = :id");
        $stmt->execute([':id' => $tmpId]);
        $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($editRow) {
            $editId = $tmpId;
        }
    }
}

/*──────────────── Filtros + paginación ────────────────────────*/
$q       = trim($_GET['q'] ?? '');
$perPage = 15;
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $perPage;

$conds  = [];
$params = [];

if ($q !== '') {
    $conds[]        = "(proveedor LIKE :q
                    OR articulo LIKE :q
                    OR descripcion LIKE :q
                    OR requisicion_informatica LIKE :q
                    OR dictamen LIKE :q
                    OR requisicion_administracion LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$whereSql = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$countSql  = "SELECT COUNT(*) FROM cotizaciones_facturas {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows   = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($page, $totalPages);

$listSql = "
    SELECT *
      FROM cotizaciones_facturas
      {$whereSql}
  ORDER BY fecha_registro DESC
     LIMIT :limit OFFSET :offset
";
$listStmt = $pdo->prepare($listSql);

foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$listStmt->execute();

$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/*──────────────── Mensajes por GET ────────────────────────────*/
if (isset($_GET['ok'])) {
    $messages[] = 'Registro guardado correctamente.';
}
if (isset($_GET['upd_ok'])) {
    $messages[] = 'Registro actualizado correctamente.';
}
if (isset($_GET['del_ok'])) {
    $messages[] = 'Registro eliminado correctamente.';
}

/*──────────────── Datos para el formulario (alta/edición) ─────*/
$isEdit = $editId !== null && $editRow !== null;
$formRi = $isEdit ? ($editRow['requisicion_informatica']    ?? '') : '';
$formDi = $isEdit ? ($editRow['dictamen']                   ?? '') : '';
$formRa = $isEdit ? ($editRow['requisicion_administracion'] ?? '') : '';
$formPr = $isEdit ? ($editRow['proveedor']                  ?? '') : '';
$formAr = $isEdit ? ($editRow['articulo']                   ?? '') : '';
$formDe = $isEdit ? ($editRow['descripcion']                ?? '') : '';
$formEp = $isEdit ? (int)($editRow['enviado_patrimonio']    ?? 0)  : 0;

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Cotizaciones y facturas</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="styles.css" rel="stylesheet">
<link rel="icon" type="image/x-icon" href="siti_logo.ico">
<style>
  .filter-sticky { position: sticky; top: .5rem; z-index: 100; }
  .table-sm td, .table-sm th { vertical-align: middle; }

  /* Divisiones sutiles entre columnas del listado */
  .tabla-cotizaciones th,
  .tabla-cotizaciones td {
    border-right: 1px solid rgba(0, 0, 0, 0.08); /* línea muy ligera */
  }

  /* Quitar la línea en la última columna para que no se vea recargado */
  .tabla-cotizaciones th:last-child,
  .tabla-cotizaciones td:last-child {
    border-right: none;
  }
</style>

</head>
<body class="bg-secoed container py-4">

<?php
  $brand_back_href = 'index.php';
  $brand_back_text = 'Panel';
  $brand_eyebrow   = 'SECOED · Expedientes y compras';
  $brand_title     = 'Control de cotizaciones y facturas';
  $brand_subtitle  = 'Concentra requisiciones, proveedores y archivos comprobatorios en un solo módulo operativo.';
  $brand_badge     = 'Total: ' . (int)$totalRows . ' registro' . ($totalRows === 1 ? '' : 's');
  require __DIR__ . '/brand_header.php';
?>

  <!-- Mensajes -->
  <?php if ($messages): ?>
    <div class="alert alert-success">
      <ul class="mb-0">
        <?php foreach ($messages as $m): ?>
          <li><?= h($m) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- Filtros -->
  <div class="card mb-4 shadow-sm filter-sticky">
    <form class="card-body row gy-2 gx-3 align-items-end" method="get">
      <div class="col-md-6">
        <label class="form-label">Buscar (proveedor, artículo, descripción, requisición, dictamen)</label>
        <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Ej. PROVEEDOR X, laptop, INF-123, dictamen...">
      </div>
      <div class="col-md-3">
        <label class="form-label d-block">&nbsp;</label>
        <button class="btn btn-primary w-100" type="submit">
          <i class="bi bi-search"></i> Buscar
        </button>
      </div>
      <div class="col-md-3 text-md-end">
        <label class="form-label d-block">&nbsp;</label>
        <a href="control_cotizaciones.php" class="btn btn-outline-secondary w-100">
          Limpiar filtros
        </a>
      </div>
    </form>
  </div>

  <!-- Formulario de alta / edición -->
  <div class="card mb-4 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>
        <?= $isEdit ? 'Editar registro #' . (int)$editRow['id'] : 'Nuevo registro de cotización / factura' ?>
      </strong>
      <?php if ($isEdit): ?>
        <a href="control_cotizaciones.php" class="btn btn-sm btn-outline-secondary">
          Cancelar edición
        </a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row gy-3">
        <input type="hidden" name="accion" value="<?= $isEdit ? 'actualizar' : 'crear' ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Req. Informática</label>
          <input type="text" name="requisicion_informatica" class="form-control" maxlength="50"
                 value="<?= h($formRi) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Dictamen</label>
          <input type="text" name="dictamen" class="form-control" maxlength="150"
                 value="<?= h($formDi) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Req. Administración</label>
          <input type="text" name="requisicion_administracion" class="form-control" maxlength="50"
                 value="<?= h($formRa) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Proveedor *</label>
          <input type="text" name="proveedor" class="form-control" required maxlength="150"
                 value="<?= h($formPr) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Artículo *</label>
          <input type="text" name="articulo" class="form-control" required maxlength="150"
                 value="<?= h($formAr) ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label">Descripción</label>
          <textarea name="descripcion" class="form-control" rows="2"><?= h($formDe) ?></textarea>
        </div>

        <div class="col-md-4">
          <label class="form-label">
            Cotización (PDF)
            <?php if ($isEdit && !empty($editRow['cotizacion_pdf'])): ?>
              <small class="text-muted d-block">
                Actual: <a href="<?= h($uploadBaseWeb . '/' . $editRow['cotizacion_pdf']) ?>" target="_blank">ver archivo</a>
              </small>
            <?php endif; ?>
          </label>
          <input type="file" name="cotizacion_pdf" accept="application/pdf" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">
            Factura (PDF)
            <?php if ($isEdit && !empty($editRow['factura_pdf'])): ?>
              <small class="text-muted d-block">
                Actual: <a href="<?= h($uploadBaseWeb . '/' . $editRow['factura_pdf']) ?>" target="_blank">ver archivo</a>
              </small>
            <?php endif; ?>
          </label>
          <input type="file" name="factura_pdf" accept="application/pdf" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">
            Factura (XML)
            <?php if ($isEdit && !empty($editRow['factura_xml'])): ?>
              <small class="text-muted d-block">
                Actual: <a href="<?= h($uploadBaseWeb . '/' . $editRow['factura_xml']) ?>" target="_blank">ver archivo</a>
              </small>
            <?php endif; ?>
          </label>
          <input type="file" name="factura_xml" accept=".xml" class="form-control">
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="enviado_patrimonio" id="chkPat"
                   <?= $formEp ? 'checked' : '' ?>>
            <label class="form-check-label" for="chkPat">
              ¿Enviado por correo a Joaquín para alta de patrimonio?
            </label>
          </div>
        </div>

        <div class="col-12 text-end">
          <button type="submit" class="btn btn_SUCCESS">
            <i class="bi bi-save"></i> <?= $isEdit ? 'Actualizar' : 'Guardar' ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Listado -->
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Listado de cotizaciones y facturas</strong>
      <small class="text-muted">
        Página <?= (int)$currentPage ?> de <?= (int)$totalPages ?>
      </small>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0 tabla-cotizaciones">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Fecha</th>
            <th>Req. Inf.</th>
            <th>Dictamen</th>
            <th>Req. Adm.</th>
            <th>Proveedor</th>
            <th>Artículo</th>
            <th>Enviado patrimonio</th>
            <th>Archivos</th>
            <th>Registró</th>
            <th class="text-center">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="11" class="text-center text-muted py-4">
                No hay registros con el filtro actual.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td>
                  <?php
                    $ts = strtotime($r['fecha_registro'] ?? '');
                    echo $ts ? date('d/m/Y H:i', $ts) : h($r['fecha_registro']);
                  ?>
                </td>
                <td><?= h($r['requisicion_informatica']) ?></td>
                <td><?= h($r['dictamen']) ?></td>
                <td><?= h($r['requisicion_administracion']) ?></td>
                <td><?= h($r['proveedor']) ?></td>
                <td><?= h($r['articulo']) ?></td>
                <td>
                  <?php if (!empty($r['enviado_patrimonio'])): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Sí</span>
                  <?php else: ?>
                    <span class="badge bg-secondary"><i class="bi bi-dash-circle"></i> No</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($r['cotizacion_pdf'])): ?>
                    <a href="<?= h($uploadBaseWeb . '/' . $r['cotizacion_pdf']) ?>" target="_blank" class="d-block">
                      <i class="bi bi-file-earmark-pdf"></i> Cotización
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($r['factura_pdf'])): ?>
                    <a href="<?= h($uploadBaseWeb . '/' . $r['factura_pdf']) ?>" target="_blank" class="d-block">
                      <i class="bi bi-file-earmark-pdf"></i> Factura PDF
                    </a>
                  <?php endif; ?>
                  <?php if (!empty($r['factura_xml'])): ?>
                    <a href="<?= h($uploadBaseWeb . '/' . $r['factura_xml']) ?>" target="_blank" class="d-block">
                      <i class="bi bi-file-earmark-code"></i> Factura XML
                    </a>
                  <?php endif; ?>
                </td>
                <td><?= h($r['usuario_registro'] ?? '') ?></td>
                <td class="text-center">
                  <a class="btn btn-sm btn-primary mb-1"
                     href="control_cotizaciones.php?edit=<?= (int)$r['id'] ?>">
                    <i class="bi bi-pencil-square"></i>
                  </a>
                  <?php if ($role === 'admin'): ?>
                    <a class="btn btn-sm btn-danger"
                       href="control_cotizaciones.php?del=<?= (int)$r['id'] ?>"
                       onclick="return confirm('¿Eliminar este registro y sus archivos asociados?');">
                      <i class="bi bi-trash"></i>
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPages > 1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted small">
          Mostrando <?= count($rows) ?> de <?= (int)$totalRows ?> registro<?= $totalRows === 1 ? '' : 's' ?>
        </span>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <?php
              $baseParams = [];
              if ($q !== '') $baseParams['q'] = $q;
              $buildUrl = function(int $p) use ($baseParams) {
                  $baseParams['p'] = $p;
                  return 'control_cotizaciones.php?' . http_build_query($baseParams);
              };
            ?>
            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $currentPage > 1 ? h($buildUrl($currentPage - 1)) : '#' ?>">&laquo;</a>
            </li>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="<?= h($buildUrl($p)) ?>"><?= $p ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $currentPage < $totalPages ? h($buildUrl($currentPage + 1)) : '#' ?>">&raquo;</a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

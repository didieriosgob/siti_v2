<?php
require 'vendor/autoload.php';            // ← si usas PhpSpreadsheet
$pdo = require __DIR__.'/db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
$xl = IOFactory::load('RELACION DIRECCION - DEPARTAMENTO SECOED.xlsx')
      ->getActiveSheet()
      ->toArray(null,true,true,true);     // [A] Dirección [B] Departamento

$insDir = $pdo->prepare("INSERT IGNORE INTO direcciones(nombre) VALUES (?)");
$selDir = $pdo->prepare("SELECT id FROM direcciones WHERE nombre=?");
$insDep = $pdo->prepare("INSERT INTO departamentos(direccion_id,nombre) VALUES (?,?)");

foreach ($xl as $row) {
    $dir = trim($row['A']); $dep = trim($row['B']);
    if (!$dir || !$dep) continue;

    $insDir->execute([$dir]);
    $selDir->execute([$dir]);
    $dirId = $selDir->fetchColumn();
    $insDep->execute([$dirId, $dep]);
}
echo "Catálogo cargado.";
?>

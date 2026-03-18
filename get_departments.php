<?php
session_start();                         // no restricción: solo devuelve datos
$pdo = require __DIR__.'/db.php';
$dirId = (int)($_GET['dir_id'] ?? 0);

$rows = $pdo->prepare("SELECT id,nombre
                         FROM departamentos
                        WHERE direccion_id = ?
                     ORDER BY nombre");
$rows->execute([$dirId]);
echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));

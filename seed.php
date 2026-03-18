<?php
$pdo = require 'db.php';
$pdo->prepare("INSERT INTO users (username, passhash, role)
               VALUES (?, ?, 'admin')")
    ->execute([
        'didier',                 // usuario
        password_hash('SeCr3t!', PASSWORD_DEFAULT)  // contraseña
    ]);
echo "admin creado\n";

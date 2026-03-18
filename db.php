<?php
$config = require __DIR__ . '/config/database.php';

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['database'],
    $config['charset']
);

return new PDO(
    $dsn,
    $config['username'],
    $config['password'],
    $config['options']
);

<?php

$DB_CONFIG = [
    "host"     => "127.0.0.1",
    "port"     => 5432,
    "user"     => "postgres",
    "password" => "example",
    "dbname"   => "temins"
];

$dsn = sprintf(
    "pgsql:host=%s;port=%d;dbname=%s",
    $DB_CONFIG['host'],
    $DB_CONFIG['port'],
    $DB_CONFIG['dbname']
);

try {
    $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "status" => false,
        "message" => "DB Connection failed",
        "error" => $e->getMessage()
    ]);
    exit;
}

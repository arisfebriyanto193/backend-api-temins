<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$host = "localhost";
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = "ALTER TABLE `instansi` 
            ADD COLUMN `username` VARCHAR(50) UNIQUE AFTER `name`,
            ADD COLUMN `password` VARCHAR(255) AFTER `username`";
    
    $pdo->exec($sql);
    echo "Database updated successfully.\n";

} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Columns already exist.\n";
    } else {
        die("DB Error: " . $e->getMessage() . "\n");
    }
}

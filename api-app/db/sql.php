<?php
$DB_HOST = "localhost";
$DB_USER = "aris";
$DB_PASS = "Aris@022805";
$DB_NAME = "temins";

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Koneksi database gagal: " . $conn->connect_error
    ]);
    exit;
}

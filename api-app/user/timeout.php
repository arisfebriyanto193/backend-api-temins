<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Daftar device ID yang menggunakan timeout panjang (contoh: 3 menit)
// Kedepannya bisa diubah menjadi query database jika diperlukan
$device_id_long_timeout = ["0035"];

echo json_encode([
    "status" => true,
    "data" => $device_id_long_timeout
]);
?>

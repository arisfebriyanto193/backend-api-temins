<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// require __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '../../vendor/autoload.php';
require __DIR__ . '/../auth/jwt.php';
include '../../db/sql.php'; 

// Cek Auth
$token = null;
$headers = null;
if (isset($_SERVER['Authorization'])) {
    $headers = trim($_SERVER["Authorization"]);
} else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
} elseif (function_exists('apache_request_headers')) {
    $requestHeaders = apache_request_headers();
    $requestHeaders = array_change_key_case($requestHeaders, CASE_LOWER);
    if (isset($requestHeaders['authorization'])) {
        $headers = trim($requestHeaders['authorization']);
    }
}
if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    http_response_code(401);
    echo json_encode(["status"=>false, "message"=>"Unauthorized"]);
    exit();
}

$user = verify_jwt($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(["status"=>false, "message"=>"Invalid Token"]);
    exit();
}

$user_id = isset($user['uid']) ? $user['uid'] : $user['id'];

// Ambil daftar devices milik user ini dari tabel user_devices
// Struktur JSON return disamakan dengan instansi/devices.php: id, name (unique_id), location, full_name
$sql = "SELECT device_unique_id, device_name, location FROM user_devices WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$devices = [];
while ($row = $result->fetch_assoc()) {
    $devices[] = [
        'id' => $row['device_unique_id'], // Gunakan unique_id sebagai ID selektor
        'name' => $row['device_name'], 
        'location' => $row['location'],
        'full_name' => $row['device_name'] // Mapping agar sama dengan frontend yang mengharapkan full_name
    ];
}

echo json_encode([
    "status" => true,
    "data" => $devices
]);
?>

<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS"); // Tambahkan OPTIONS
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle Preflight Request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include '../../db/sql.php'; 
include '../../auth/jwt.php'; 

// FUNGSI GET TOKEN YANG LEBIH KUAT
function getBearerToken() {
    $headers = null;
    
    // 1. Cek $_SERVER (Standar)
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } 
    else if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    }
    // 2. Cek Apache Headers (Backup jika $_SERVER kosong)
    else if (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        // Server kadang mengubah case header (misal: authorization atau Authorization)
        $requestHeaders = array_change_key_case($requestHeaders, CASE_LOWER); // Ubah ke lowercase agar pasti ketemu
        if (isset($requestHeaders['authorization'])) {
            $headers = trim($requestHeaders['authorization']);
        }
    }

    // Ekstrak token dari string "Bearer <token>"
    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

// 1. Validasi Token
$token = getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Token tidak ditemukan di Header"]);
    exit();
}

$user = verify_jwt($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Token tidak valid atau expired"]);
    exit();
}

// PERBAIKAN PENTING: Sesuaikan key dengan Payload Login
// Di login.php: "uid" => (int)$user['id']
// Maka disini harus pakai ['uid']
$user_id = isset($user['uid']) ? $user['uid'] : $user['id']; 

$q_device = mysqli_query($conn, "SELECT * FROM user_devices WHERE user_id = '$user_id' LIMIT 1");
$device = mysqli_fetch_assoc($q_device);

if (!$device) {
    echo json_encode(["status" => false, "message" => "No device found for User ID: " . $user_id]);
    exit;
}

$device_unique_id = $device['device_unique_id'];


// --- ROUTING LOGIC ---
$q_data = mysqli_query($conn, "SELECT parameter_name, mqtt_topic FROM device_settings WHERE device_unique_id='$device_unique_id' AND parameter_name IN ('Suhu Udara', 'Kelembapan Udara', 'Radiasi Matahari', 'Curah Hujan Berjalan', 'Kecepatan Angin') ");
$rows = mysqli_fetch_all($q_data, MYSQLI_ASSOC);

// Remap: { parameter_name => suffix_topic } misal { "Suhu Udara": "su" }
$data = [];
foreach ($rows as $row) {
    $suffix = basename($row['mqtt_topic']); // ambil bagian setelah '/' terakhir
    $data[] = [$row['parameter_name'] => $suffix];
}

echo json_encode(array("data"=>$data,
"device_unique_id"=>$device_unique_id));    



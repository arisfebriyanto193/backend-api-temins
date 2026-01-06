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
    echo json_encode(["status" => false, "message" => "Device not connected"]);
    exit();
}

$device_unique_id = $device['device_unique_id'];

// 2. HELPER: WARNA & ICON (Sama seperti logika PHP Anda)
function getSensorStyle($label) {
    $l = strtolower($label);
    if (strpos($l, 'arah') !== false) return ['icon'=>'fa-compass', 'color'=>'#a855f7']; 
    if (strpos($l, 'kecepatan') !== false || strpos($l, 'speed') !== false) return ['icon'=>'fa-wind', 'color'=>'#06b6d4']; 
    if (strpos($l, 'hujan') !== false || strpos($l, 'rain') !== false) return ['icon'=>'fa-cloud-rain', 'color'=>'#3b82f6']; 
    if (strpos($l, 'suhu') !== false || strpos($l, 'temp') !== false) return ['icon'=>'fa-temperature-half', 'color'=>'#f97316']; 
    if (strpos($l, 'lembab') !== false || strpos($l, 'hum') !== false) return ['icon'=>'fa-droplet', 'color'=>'#10b981']; 
    if (strpos($l, 'tekanan') !== false) return ['icon'=>'fa-gauge', 'color'=>'#64748b']; 
    if (strpos($l, 'solar') !== false || strpos($l, 'radiasi') !== false) return ['icon'=>'fa-sun', 'color'=>'#eab308']; 
    if (strpos($l, 'volt') !== false || strpos($l, 'batt') !== false) return ['icon'=>'fa-car-battery', 'color'=>'#22c55e']; 
    return ['icon'=>'fa-microchip', 'color'=>'#6366f1']; 
}

// 3. GET SENSOR LIST
$sensorOptions = [];
$q = mysqli_query($conn, "
    SELECT usc.data, ds.parameter_name, ds.unit
    FROM user_sensor_charts usc
    JOIN device_settings ds ON ds.id = usc.device_setting_id
    WHERE usc.user_id = '$user_id' AND usc.device_unique_id = '$device_unique_id' AND usc.is_active = 1
    ORDER BY usc.chart_order ASC
");

while ($row = mysqli_fetch_assoc($q)) {
    $style = getSensorStyle($row['parameter_name']);
    $sensorOptions[] = [
        'code'  => $row['data'],
        'label' => $row['parameter_name'],
        'unit'  => $row['unit'],
        'icon'  => $style['icon'],
        'color' => $style['color']
    ];
}

// 4. GET YEAR RANGE
$q_range = mysqli_query($conn, "SELECT MIN(recorded_at) as f, MAX(recorded_at) as l FROM sensor_logs WHERE device_unique_id = '$device_unique_id'");
$range = mysqli_fetch_assoc($q_range);

echo json_encode([
    "status" => true,
    "device_name" => $device['device_name'],
    "device_id" => $device_unique_id,
        "zonawaktu" => $device['timezone'],
    "sensors" => $sensorOptions,

    "years" => [
        "start" => $range['f'] ? date('Y', strtotime($range['f'])) : date('Y'),
        "end" => $range['l'] ? date('Y', strtotime($range['l'])) : date('Y')
    ]
]);
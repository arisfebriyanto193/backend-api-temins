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

// 2. GET DEVICE INFO
$q_device = mysqli_query($conn, "SELECT * FROM user_devices WHERE user_id = '$user_id' LIMIT 1");
$device = mysqli_fetch_assoc($q_device);

if (!$device) {
    echo json_encode(["status" => false, "message" => "No device found for User ID: " . $user_id]);
    exit;
}

$device_unique_id = $device['device_unique_id'];

// 3. GET SENSORS CONFIG
$sensors = [];
$topics = [];

$sql = "SELECT parameter_name, mqtt_topic, unit, category 
        FROM device_settings 
        WHERE device_unique_id = '$device_unique_id' 
        AND is_visible = 1 AND category = 'sensor' AND parameter_name != 'Arus Charging' 
        ORDER BY display_order ASC";

$q_config = mysqli_query($conn, $sql);

while ($conf = mysqli_fetch_assoc($q_config)) {
    $topic = $conf['mqtt_topic'];
    // Optimasi query log (limit 1 cukup)
    $q_l = mysqli_query($conn, "SELECT value FROM sensor_logs WHERE topic='$topic' ORDER BY id DESC LIMIT 1");
    $lastVal = ($r = mysqli_fetch_assoc($q_l)) ? (float)$r['value'] : 0;

    $sensors[] = [
        'label' => $conf['parameter_name'],
        'topic' => $conf['mqtt_topic'],
        'unit'  => $conf['unit'],
        'value' => $lastVal,
        'type'  => detectType($conf['parameter_name'])
    ];
    $topics[] = $conf['mqtt_topic'];
}

// 4. GET CHART LIST
$charts = [];
$q_charts = mysqli_query($conn, "
    SELECT usc.data, ds.parameter_name, ds.mqtt_topic
    FROM user_sensor_charts usc
    JOIN device_settings ds ON ds.id = usc.device_setting_id
    WHERE usc.user_id = '$user_id' AND usc.device_unique_id = '$device_unique_id' AND usc.is_active = 1
    ORDER BY usc.chart_order ASC
");

while ($row = mysqli_fetch_assoc($q_charts)) {
    $valCode = $row['data'];
    if (empty($valCode) && !empty($row['mqtt_topic'])) {
        $parts = explode('/', $row['mqtt_topic']);
        $valCode = end($parts);
    }
    $charts[] = ['val' => $valCode, 'label' => $row['parameter_name']];
}

echo json_encode([
    "status" => true,
    "device" => [
        "name" => $device['device_name'],
        "id"   => $device_unique_id,
        "lokasi" => $device['location'],
        "zona_waktu" => $device['timezone'],
        "owner" => $device['owner_name']
    ],
    "sensors" => $sensors,
    "charts"  => $charts,
    "mqtt_topics" => $topics
]);

// Helper Function
function detectType($label) {
    $l = strtolower($label);
    if (strpos($l, 'arah') !== false) return 'wind_dir';
    if (strpos($l, 'kecepatan') !== false || strpos($l, 'speed') !== false) return 'wind_spd';
    if (strpos($l, 'gust') !== false) return 'wind_gust';
    if (strpos($l, 'hujan') !== false || strpos($l, 'rain') !== false) return 'rain';
    if (strpos($l, 'suhu') !== false || strpos($l, 'temp') !== false) return 'temp';
    if (strpos($l, 'lembab') !== false || strpos($l, 'hum') !== false) return 'hum';
    if (strpos($l, 'tekanan') !== false || strpos($l, 'press') !== false) return 'press';
    if (strpos($l, 'radiasi') !== false || strpos($l, 'solar') !== false) return 'solar';
    if (strpos($l, 'baterai') !== false || strpos($l, 'batt') !== false || strpos($l, 'volt') !== false) return 'battery';
    return 'general';
}
?>
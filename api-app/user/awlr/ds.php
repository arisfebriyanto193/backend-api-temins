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



// 1. GET DEVICE INFO
$q_device = mysqli_query($conn, "SELECT * FROM user_devices WHERE user_id = '$user_id' LIMIT 1");
$device = mysqli_fetch_assoc($q_device);

if (!$device) {
    echo json_encode(["status" => false, "message" => "Device not found"]);
    exit();
}

$device_unique_id = $device['device_unique_id'];
$device_name = $device['device_name'];

// 2. GET DYNAMIC SETTINGS (Topics & Max Height)
$topics_to_subscribe = [];
$topic_dist = "";
$topic_batt = "";
$sensor_max_height = 4100; // default

$q_conf = mysqli_query($conn, "SELECT parameter_name, mqtt_topic, tinggi_sensor FROM device_settings 
                               WHERE device_unique_id = '$device_unique_id' AND is_visible = 1");

while($row = mysqli_fetch_assoc($q_conf)) {
    $p_name = strtolower($row['parameter_name']);
    $t_mqtt = $row['mqtt_topic'];
    
    if (strpos($p_name, 'tinggi air cm') !== false) {
        $topic_dist = $t_mqtt;
        if($row['tinggi_sensor'] > 0) $sensor_max_height = floatval($row['tinggi_sensor']);
    } 
    else if (strpos($p_name, 'batre') !== false || strpos($p_name, 'battery') !== false) {
        $topic_batt = $t_mqtt;
    }
    $topics_to_subscribe[] = $t_mqtt;
}



// 3. GET INITIAL VALUES
$initial_distance = 0;
$initial_battery = 0;

if ($topic_dist) {
    $q = mysqli_query($conn, "SELECT value FROM sensor_logs WHERE topic='$topic_dist' ORDER BY id DESC LIMIT 1");
    if($r = mysqli_fetch_assoc($q)) $initial_distance = floatval($r['value']);
}
if ($topic_batt) {
    $q = mysqli_query($conn, "SELECT value FROM sensor_logs WHERE topic='$topic_batt' ORDER BY id DESC LIMIT 1");
    if($r = mysqli_fetch_assoc($q)) $initial_battery = floatval($r['value']);
}
$q_config = mysqli_query(
    $conn,
    "SELECT parameter_name, unit, tinggi_sensor 
     FROM device_settings 
     WHERE device_unique_id = '$device_unique_id' 
     AND category = 'config'"
);


$q_config2 = mysqli_query(
    $conn,
    "SELECT category
     FROM device_settings 
     WHERE device_unique_id = '$device_unique_id' 
     AND mqtt_topic = 'jenis'"
);

$data_config = mysqli_fetch_assoc($q_config);
$data_config2 = mysqli_fetch_assoc($q_config2);


$grafik_data = null;

$q_grafik = mysqli_query(
    $conn,
    "SELECT data 
     FROM user_sensor_charts 
     WHERE device_unique_id = '$device_unique_id' 
     AND is_active = 1 AND 	chart_order =  111
     LIMIT 1"
);

if ($q_grafik && mysqli_num_rows($q_grafik) > 0) {
    $row_grafik = mysqli_fetch_assoc($q_grafik);

    $decoded = json_decode($row_grafik['data'], true);
    $grafik_data = (json_last_error() === JSON_ERROR_NONE)
        ? $decoded
        : $row_grafik['data'];
}



echo json_encode([
    "status" => true,
    "device" => [
        
        "name" => $device_name,
        "id" => $device_unique_id,
        "max_height" => $data_config['tinggi_sensor'],
        "data" => $data_config['parameter_name'],
        "statusData" => $data_config['unit'],
       "grafik" => $grafik_data,
       "lokasi" => $device['location'],
       "owner" => $device['owner_name'],
       "jenis" => $data_config2['category'],
       "zonawaktu" => $device['timezone']
    ],
    "mqtt" => [
        "topics" => $topics_to_subscribe,
        "topic_dist" => $topic_dist,
        "topic_batt" => $topic_batt
    ],
    "initial" => [
        "distance" => $initial_distance,
        "battery" => $initial_battery
    ]
]);
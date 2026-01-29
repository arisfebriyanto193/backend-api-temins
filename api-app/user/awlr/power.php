<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 1. Handle Preflight Request (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include '../../db/sql.php'; 
include '../../auth/jwt.php'; 

/**
 * Fungsi untuk mengambil Bearer Token dari Header
 */
function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } else if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_change_key_case($requestHeaders, CASE_LOWER);
        if (isset($requestHeaders['authorization'])) {
            $headers = trim($requestHeaders['authorization']);
        }
    }

    if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
        return $matches[1];
    }
    return null;
}

// 2. Validasi Token
$token = getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Token tidak ditemukan"]);
    exit();
}

$user = verify_jwt($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Token tidak valid atau expired"]);
    exit();
}

// 3. Identifikasi User & Device
// Menggunakan 'uid' sesuai payload login yang Anda sebutkan
$user_id = isset($user['uid']) ? $user['uid'] : $user['id']; 

$q_device = mysqli_query($conn, "SELECT device_name, device_unique_id FROM user_devices WHERE user_id = '$user_id' LIMIT 1");
$device = mysqli_fetch_assoc($q_device);

if (!$device) {
    echo json_encode(["status" => false, "message" => "Device not found"]);
    exit();
}

$device_unique_id = $device['device_unique_id'];

// 4. Ambil Data Sensor Kategori 'Power'
$sensors = [];
$topics = [];
$initialValues = []; // Opsional: tetap disediakan jika frontend lama membutuhkannya

$sql = "SELECT parameter_name, mqtt_topic, unit 
        FROM device_settings 
        WHERE device_unique_id = '$device_unique_id' 
        AND category = 'power' 
        ORDER BY display_order ASC";

$q_config = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($q_config)) {
    $topic = $row['mqtt_topic'];
    $topics[] = $topic;

    // Ambil nilai terakhir dari sensor_logs
    $q_l = mysqli_query($conn, "SELECT value FROM sensor_logs WHERE topic='$topic' ORDER BY id DESC LIMIT 1");
    $lastVal = ($r = mysqli_fetch_assoc($q_l)) ? (float)$r['value'] : 0;
    
    $initialValues[$topic] = $lastVal;

    // Extract 'code' (misal: bagian terakhir dari topic)
    $parts = explode('/', $topic);
    $code = end($parts);

    // Penentuan type_id untuk UI (Ikon/Warna)
    $t_lower = strtolower($topic);
    $l_lower = strtolower($row['parameter_name']);
    $type_id = 'general';
    
    if (strpos($t_lower, 'amp') !== false || strpos($l_lower, 'arus') !== false) $type_id = 'amp';
    elseif (strpos($t_lower, 'volt') !== false || strpos($l_lower, 'tegangan') !== false) $type_id = 'volt';
    elseif (strpos($t_lower, 'watt') !== false || strpos($l_lower, 'daya') !== false) $type_id = 'watt';

    // Gabungkan data ke dalam array sensors
    $sensors[] = [
        'code'    => $code,
        'label'   => $row['parameter_name'],
        'unit'    => $row['unit'],
        'topic'   => $topic,
        'value'   => $lastVal,
        'type_id' => $type_id
    ];
}

// 5. Output JSON Terpadu
echo json_encode([
    "status"        => true,
    "device_name"   => $device['device_name'],
    "device_id"     => $device_unique_id,
    "mqtt_topics"   => $topics,
    "sensors"       => $sensors,
    "initialValues" => $initialValues // Masih disertakan untuk kompatibilitas
]);
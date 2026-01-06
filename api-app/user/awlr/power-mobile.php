<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// ===== HANDLE PREFLIGHT =====
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include '../../db/sql.php';
include '../../auth/jwt.php';

/**
 * ===== HELPER: GET BEARER TOKEN =====
 */
function getBearerToken() {
    $headers = null;

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } elseif (function_exists('apache_request_headers')) {
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

// ===== VALIDASI TOKEN =====
$token = getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Token missing"]);
    exit();
}

$user = verify_jwt($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Invalid or expired token"]);
    exit();
}

$user_id = isset($user['uid']) ? $user['uid'] : $user['id'];

// ===== GET DEVICE =====
$q_device = mysqli_query($conn, "
    SELECT device_name, device_unique_id, timezone 
    FROM user_devices 
    WHERE user_id = '$user_id' 
    LIMIT 1
");

$device = mysqli_fetch_assoc($q_device);
if (!$device) {
    echo json_encode(["status" => false, "message" => "No device found"]);
    exit();
}

$device_unique_id = $device['device_unique_id'];

// ===== GET POWER SENSORS =====
$sensors = [];
$topics  = [];

$sql = "
    SELECT parameter_name, mqtt_topic, unit 
    FROM device_settings 
    WHERE device_unique_id = '$device_unique_id'
    AND category = 'power'
    ORDER BY display_order ASC
";

$q_config = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($q_config)) {

    $topic = $row['mqtt_topic'];
    $topics[] = $topic;

    // Ambil nilai terakhir
    $q_l = mysqli_query($conn, "
        SELECT value 
        FROM sensor_logs 
        WHERE topic = '$topic' 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $lastVal = ($r = mysqli_fetch_assoc($q_l)) ? (float)$r['value'] : 0;

    // Extract code
    $parts = explode('/', $topic);
    $code  = end($parts);

    // Tentukan type_id
    $t = strtolower($topic);
    $l = strtolower($row['parameter_name']);
    $type_id = 'general';

    if (strpos($t, 'amp') !== false || strpos($l, 'arus') !== false) {
        $type_id = 'amp';
    } elseif (strpos($t, 'volt') !== false || strpos($l, 'tegangan') !== false) {
        $type_id = 'volt';
    } elseif (strpos($t, 'watt') !== false || strpos($l, 'daya') !== false) {
        $type_id = 'watt';
    }

    $sensors[] = [
        "label"   => $row['parameter_name'],
        "topic"   => $topic,
        "unit"    => $row['unit'],
        "value"   => $lastVal,
        "type_id"=> $type_id,
        "code"    => $code
    ];
}

// ===== OUTPUT JSON =====
echo json_encode([
    "status" => true,
    "device" => [
        "name"       => $device['device_name'],
        "id"         => $device_unique_id,
        "zonawaktu"  => $device['timezone']
    ],
    "sensors"     => $sensors,
    "mqtt_topics" => $topics
]);

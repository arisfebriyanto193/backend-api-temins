<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

include '../../db/sql.php';
include '../../auth/jwt.php';

// --- HELPER AUTH ---
function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) { $headers = trim($_SERVER["HTTP_AUTHORIZATION"]); }
    else if (isset($_SERVER['Authorization'])) { $headers = trim($_SERVER["Authorization"]); }
    elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_change_key_case($requestHeaders, CASE_LOWER);
        if (isset($requestHeaders['authorization'])) { $headers = trim($requestHeaders['authorization']); }
    }
    if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) { return $matches[1]; }
    return null;
}



// --- VALIDASI TOKEN ---
$token = getBearerToken();
if (!$token) { echo json_encode(["status" => false, "message" => "Token missing"]); exit(); }
$user = verify_jwt($token);
if (!$user) { echo json_encode(["status" => false, "message" => "Invalid token"]); exit(); }


// Hardcode UID sementara untuk testing API jika session kosong (Hapus saat production)
$user_id = isset($user['uid']) ? $user['uid'] : $user['id'];

// 2. GET DEVICE INFO
$q_device = mysqli_query($conn, "SELECT * FROM user_devices WHERE user_id = '$user_id'");
$device = mysqli_fetch_assoc($q_device);

if (!$device) {
    echo json_encode(['status' => false, 'message' => 'No device linked']);
    exit();
}

$device_unique_id = $device['device_unique_id'];

// 3. GET SENSORS CONFIG & LAST VALUES
$sensors = [];
$mqtt_topics = [];

$sql = "SELECT * FROM device_settings WHERE device_unique_id='$device_unique_id' AND category='power' ORDER BY display_order ASC";
$q_config = mysqli_query($conn, $sql);

while($row = mysqli_fetch_assoc($q_config)) {
    $topic = $row['mqtt_topic'];
    $mqtt_topics[] = $topic;

    // Logic penentuan tipe sensor untuk animasi visual
    $t = strtolower($topic);
    $l = strtolower($row['parameter_name']);
    $type_id = 'general';

    if(strpos($t, 'amp')!==false || strpos($t, 'arus')!==false || strpos($l, 'arus')!==false) $type_id = 'amp';
    if(strpos($t, 'volt')!==false || strpos($t, 'tegangan')!==false || strpos($l, 'tegangan')!==false) $type_id = 'volt';
    if(strpos($t, 'watt')!==false || strpos($t, 'daya')!==false || strpos($l, 'daya')!==false) $type_id = 'watt';

    // Ambil nilai terakhir dari log
    $q_last = mysqli_query($conn, "SELECT value FROM sensor_logs WHERE topic='$topic' ORDER BY id DESC LIMIT 1");
    $last_val = ($r = mysqli_fetch_assoc($q_last)) ? (float)$r['value'] : 0;

    // Extract code untuk API Chart
    $parts = explode('/', $topic);
    $code = end($parts);

    $sensors[] = [
        'label' => $row['parameter_name'],
        'topic' => $topic,
        'unit'  => $row['unit'],
        'value' => $last_val,
        'type_id' => $type_id, // Penting untuk frontend tau mana sensor Ampere/Volt
        'code'  => $code
    ];
}

echo json_encode([
    'status' => true,
    'device' => [
        'name' => $device['device_name'],
        'id'   => $device_unique_id
    ],
    'sensors' => $sensors,
    'mqtt_topics' => $mqtt_topics
]);
?>
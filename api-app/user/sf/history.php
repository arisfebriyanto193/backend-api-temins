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
$device_name = $device['device_name'];

// 3. GET SENSOR LIST
$sensors = [];
$q_sensor = mysqli_query($conn, "
    SELECT 
        usc.id as chart_id,
        usc.data as code,                    
        ds.parameter_name as label,
        ds.mqtt_topic as topic,
        ds.unit
    FROM user_sensor_charts usc
    JOIN device_settings ds ON ds.id = usc.device_setting_id
    WHERE usc.user_id = '$user_id'
      AND usc.device_unique_id = '$device_unique_id'
      AND usc.is_active = 1
    ORDER BY usc.chart_order ASC
");

while ($row = mysqli_fetch_assoc($q_sensor)) {
    $sensors[] = $row;
}

// 4. GET YEAR RANGE
// $q_range = mysqli_query($conn, "SELECT MIN(recorded_at) as first_date, MAX(recorded_at) as last_date FROM sensor_logs WHERE device_unique_id = '$device_unique_id'");
// $range = mysqli_fetch_assoc($q_range);

// $minYear = $range['first_date'] ? date('Y', strtotime($range['first_date'])) : date('Y');
// $maxYear = $range['last_date'] ? date('Y', strtotime($range['last_date'])) : date('Y');

$minYear = 2024;
$maxYear = date('Y');

$years = range($maxYear, $minYear);

echo json_encode([
    'status' => true,
    'device' => [
        'name' => $device_name,
        'id' => $device_unique_id,
        'zonawaktu' => $device["timezone"]
    ],
    'sensors' => $sensors,
    'years' => [
        'min' => (int)$minYear,
        'max' => (int)$maxYear
    ]
]);
?>
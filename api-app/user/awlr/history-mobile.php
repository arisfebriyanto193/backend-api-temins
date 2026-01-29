<?php
// File: api-app/user/aws/init_analytics.php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include '../../db/sql.php';
include '../../auth/jwt.php';

/* ===============================
   1. HELPER GET BEARER TOKEN
================================ */
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

/* ===============================
   2. AUTHENTICATION
================================ */
$token = getBearerToken();
if (!$token) {
    echo json_encode(["status" => false, "message" => "Token missing"]);
    exit();
}

$user = verify_jwt($token);
if (!$user) {
    echo json_encode(["status" => false, "message" => "Invalid token"]);
    exit();
}

$user_id = isset($user['uid']) ? $user['uid'] : $user['id'];

/* ===============================
   3. GET DEVICE
================================ */
$q_device = mysqli_query(
    $conn,
    "SELECT * FROM user_devices WHERE user_id = '$user_id' LIMIT 1"
);

$device = mysqli_fetch_assoc($q_device);

if (!$device) {
    echo json_encode([
        "status" => false,
        "message" => "No device linked"
    ]);
    exit();
}

$device_unique_id = $device['device_unique_id'];

/* ===============================
   4. HELPER ICON & COLOR
================================ */
function getSensorConfig($label) {
    $l = strtolower($label);

    if (strpos($l, 'arah') !== false)
        return ['icon'=>'fa-compass', 'color'=>'#a855f7'];

    if (strpos($l, 'kecepatan') !== false || strpos($l, 'speed') !== false)
        return ['icon'=>'fa-wind', 'color'=>'#06b6d4'];

    if (strpos($l, 'hujan') !== false || strpos($l, 'rain') !== false)
        return ['icon'=>'fa-cloud-rain', 'color'=>'#3b82f6'];

    if (strpos($l, 'suhu') !== false || strpos($l, 'temp') !== false)
        return ['icon'=>'fa-temperature-half', 'color'=>'#f97316'];

    if (strpos($l, 'lembab') !== false || strpos($l, 'hum') !== false)
        return ['icon'=>'fa-droplet', 'color'=>'#10b981'];

    if (strpos($l, 'tekanan') !== false)
        return ['icon'=>'fa-gauge', 'color'=>'#64748b'];

    if (strpos($l, 'solar') !== false || strpos($l, 'radiasi') !== false)
        return ['icon'=>'fa-sun', 'color'=>'#eab308'];

    if (strpos($l, 'volt') !== false || strpos($l, 'batt') !== false)
        return ['icon'=>'fa-car-battery', 'color'=>'#22c55e'];

    return ['icon'=>'fa-microchip', 'color'=>'#6366f1'];
}

/* ===============================
   5. GET SENSOR LIST
================================ */
$sensors = [];

$q_sensor = mysqli_query($conn, "
    SELECT usc.data, ds.parameter_name, ds.unit
    FROM user_sensor_charts usc
    JOIN device_settings ds ON ds.id = usc.device_setting_id
    WHERE usc.user_id = '$user_id'
      AND usc.device_unique_id = '$device_unique_id'
      AND usc.is_active = 1
    ORDER BY usc.chart_order ASC
");

while ($row = mysqli_fetch_assoc($q_sensor)) {
    $style = getSensorConfig($row['parameter_name']);

    $sensors[] = [
        "code"  => $row['data'],
        "label" => $row['parameter_name'],
        "unit"  => $row['unit'],
        "icon"  => $style['icon'],
        "color" => $style['color']
    ];
}

/* ===============================
   6. YEAR RANGE (ARRAY)
================================ */
// $q_range = mysqli_query($conn, "
//     SELECT MIN(recorded_at) AS first_date,
//           MAX(recorded_at) AS last_date
//     FROM sensor_logs
//     WHERE device_unique_id = '$device_unique_id'
// ");

// $range = mysqli_fetch_assoc($q_range);

// $minYear = $range['first_date']
//     ? (int)date('Y', strtotime($range['first_date']))
//     : (int)date('Y');

// $maxYear = $range['last_date']
//     ? (int)date('Y', strtotime($range['last_date']))
//     : (int)date('Y');

// $years = range($maxYear, $minYear);


$minYear = 2024;
$maxYear = date('Y');

$years = range($maxYear, $minYear);
/* ===============================
   7. RESPONSE JSON
================================ */
echo json_encode([
    "status"      => true,
    "device_name" => $device['device_name'],
    "device_id"   => $device_unique_id,
    "zonawaktu"   => $device['timezone'],
    "sensors"     => $sensors,
    "years"       => $years
]);

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
$device   = mysqli_fetch_assoc($q_device);

if (!$device) {
    echo json_encode(["status" => false, "message" => "Akun belum terhubung alat Smart Farm."]);
    exit();
}

$device_unique_id = $device['device_unique_id'];
$device_name      = $device['device_name'];

// 3. HELPER: DETEKSI TIPE (Sama seperti logika lama)
function detectType($label) {
    $l = strtolower($label);
    if (strpos($l, 'suhu') !== false || strpos($l, 'temp') !== false) return 'soil_temp';
    if (strpos($l, 'kelembapan') !== false || strpos($l, 'moist') !== false || strpos($l, 'hum') !== false) return 'soil_moist';
    if (preg_match("/\bn\b/", $l) || strpos($l, 'nitrogen') !== false) return 'val_n';
    if (preg_match("/\bp\b/", $l) || strpos($l, 'fosfor') !== false || strpos($l, 'phosphor') !== false) return 'val_p';
    if (preg_match("/\bk\b/", $l) || strpos($l, 'kalium') !== false || strpos($l, 'potassium') !== false) return 'val_k';
    if (strpos($l, 'ph') !== false) return 'val_ph';
    if (strpos($l, 'tds') !== false) return 'val_tds';
    if (strpos($l, 'ec') !== false || strpos($l, 'konduktifitas') !== false) return 'val_ec';
    if (strpos($l, 'garam') !== false || strpos($l, 'salt') !== false) return 'val_salt';
    if (strpos($l, 'baterai') !== false || strpos($l, 'batt') !== false || strpos($l, 'volt') !== false) return 'battery';
    return 'general';
}

// 4. PREPARE DATA
$topics_to_subscribe = [];
$soilMoist = null;
$soilTemp  = null;
$soilPh    = null;
$battery   = null;
$npkList   = [];
$chemList  = [];
$chartList = [];

// A. CHART LIST
$q_charts = mysqli_query($conn, "
    SELECT ds.mqtt_topic, ds.parameter_name
    FROM user_sensor_charts usc
    JOIN device_settings ds ON ds.id = usc.device_setting_id
    WHERE usc.device_unique_id = '$device_unique_id' AND usc.is_active = 1
    ORDER BY usc.chart_order ASC
");

while($row = mysqli_fetch_assoc($q_charts)) {
    $parts = explode('/', $row['mqtt_topic']);
    $chartList[] = [
        'val'   => end($parts), 
        'label' => $row['parameter_name']
    ];
}

// B. SENSORS
$sql = "SELECT * FROM device_settings 
        WHERE device_unique_id = '$device_unique_id' 
        AND is_visible = 1 AND category = 'sensor' 
        ORDER BY display_order ASC";
$q_config = mysqli_query($conn, $sql);

while($conf = mysqli_fetch_assoc($q_config)) {
    $topic = $conf['mqtt_topic'];
    $topics_to_subscribe[] = $topic;
    
    // Nilai Terakhir
    $q_l = mysqli_query($conn, "SELECT value FROM sensor_logs WHERE topic='$topic' ORDER BY id DESC LIMIT 1");
    $val = ($r = mysqli_fetch_assoc($q_l)) ? $r['value'] : 0;
    
    $sensorData = [
        'id' => $conf['id'],
        'name' => $conf['parameter_name'],
        'topic' => $topic,
        'value' => (float)$val,
        'unit' => $conf['unit'],
        'type' => detectType($conf['parameter_name'])
    ];

    switch ($sensorData['type']) {
        case 'soil_moist':
            if(!$soilMoist) $soilMoist = $sensorData; 
            else $chemList[] = $sensorData;
            break;
        case 'soil_temp':
            if(!$soilTemp) $soilTemp = $sensorData; 
            else $chemList[] = $sensorData;
            break;
        case 'val_ph':
            if(!$soilPh) $soilPh = $sensorData;
            else $chemList[] = $sensorData;
            break;
        case 'val_n':
        case 'val_p':
        case 'val_k':
            
            $npkList[] = $sensorData;
            break;
        case 'battery':
            $battery = $sensorData;
            break;
        default:
            $chemList[] = $sensorData;
            break;
    }
}

// OUTPUT JSON
echo json_encode([
    'status' => true,
    'device' => [
        'name' => $device_name,
        'id' => $device_unique_id,
        'lokasi' => "smg",
        'zonawaktu' => "WITA"
    ],
    'mqtt' => [
        'broker' => "wss://karsacerdasinovatif.web.id:8081",
        'topics' => $topics_to_subscribe
    ],
    'sensors' => [
        'soil_moist' => $soilMoist,
        'soil_temp' => $soilTemp,
        'soil_ph' => $soilPh,
        'battery' => $battery,
        'npk' => $npkList,
        'chem' => $chemList
    ],
    'charts' => $chartList
]);
?>
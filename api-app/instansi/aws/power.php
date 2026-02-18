<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

include '../../db/sql.php';
include '../../auth/jwt.php';

function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) { $headers = trim($_SERVER["HTTP_AUTHORIZATION"]); }
    else if (isset($_SERVER['Authorization'])) { $headers = trim($_SERVER["Authorization"]); }
    if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) { return $matches[1]; }
    return null;
}

$token = getBearerToken();
$user = verify_jwt($token);

if (!$user || $user['role'] !== 'instansi') {
    echo json_encode(["status" => false, "message" => "Unauthorized"]);
    exit();
}

$instansi_id = $user['uid'];
$selected_device_id = isset($_GET['device_id']) ? $_GET['device_id'] : null;

// 1. AMBIL SEMUA DEVICE MILIK ANGGOTA INSTANSI
$sql_list = "SELECT ud.device_unique_id, ud.device_name, ud.location, u.username as owner 
             FROM user_devices ud 
             JOIN users u ON ud.user_id = u.id 
             WHERE u.instansi_id = ?";
$stmt_list = $conn->prepare($sql_list);
$stmt_list->bind_param("i", $instansi_id);
$stmt_list->execute();
$res_list = $stmt_list->get_result();
$device_list = [];
while ($row = $res_list->fetch_assoc()) { $device_list[] = $row; }

// 2. JIKA DEVICE DIPILIH, AMBIL DETAIL SENSOR POWER
$details = null;
if ($selected_device_id) {
    $sql_dev = "SELECT * FROM user_devices WHERE device_unique_id = ? LIMIT 1";
    $stmt_dev = $conn->prepare($sql_dev);
    $stmt_dev->bind_param("s", $selected_device_id);
    $stmt_dev->execute();
    $device = $stmt_dev->get_result()->fetch_assoc();

    if ($device) {
        $sensors = [];
        $topics = [];
        $sql_s = "SELECT parameter_name, mqtt_topic, unit FROM device_settings 
                  WHERE device_unique_id = ? AND category = 'power' AND is_visible = 1 
                  ORDER BY display_order ASC";
        $stmt_s = $conn->prepare($sql_s);
        $stmt_s->bind_param("s", $selected_device_id);
        $stmt_s->execute();
        $res_s = $stmt_s->get_result();

        while ($conf = $res_s->fetch_assoc()) {
            $topic = $conf['mqtt_topic'];
            $topics[] = $topic;
            
            $q_l = mysqli_query($conn, "SELECT value FROM sensor_logs WHERE topic='$topic' ORDER BY id DESC LIMIT 1");
            $lastVal = ($r = mysqli_fetch_assoc($q_l)) ? (float)$r['value'] : 0;

            $type_id = 'general';
            $l = strtolower($conf['parameter_name']);
            if(strpos($l, 'arus')!==false || strpos($l, 'amp')!==false) $type_id = 'amp';
            if(strpos($l, 'tegangan')!==false || strpos($l, 'volt')!==false) $type_id = 'volt';
            if(strpos($l, 'daya')!==false || strpos($l, 'watt')!==false) $type_id = 'watt';

            $parts = explode('/', $topic);
            $sensors[] = [
                'label' => $conf['parameter_name'],
                'topic' => $topic,
                'unit'  => $conf['unit'],
                'value' => $lastVal,
                'type_id' => $type_id,
                'code'  => end($parts)
            ];
        }

        $details = [
            "device" => [
                "name" => $device['device_name'],
                "id"   => $device['device_unique_id'],
                "zonawaktu" => $device['timezone'] ?? 'WIB',
                "lokasi" => $device['location']
            ],
            "sensors" => $sensors,
            "mqtt_topics" => $topics
        ];
    }
}

echo json_encode([
    "status" => true,
    "instansi_name" => $user['username'],
    "device_list" => $device_list,
    "selected_device_details" => $details
]);
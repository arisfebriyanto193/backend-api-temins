<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include '../../db/sql.php'; 
include '../../auth/jwt.php'; 

function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) { $headers = trim($_SERVER["HTTP_AUTHORIZATION"]); }
    else if (isset($_SERVER['Authorization'])) { $headers = trim($_SERVER["Authorization"]); }
    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) { return $matches[1]; }
    }
    return null;
}

$token = getBearerToken();
$user = verify_jwt($token);

if (!$user || $user['role'] !== 'instansi') {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Unauthorized"]);
    exit();
}

$instansi_id = $user['uid'];

/**
 * LOGIKA:
 * 1. Cari semua User yang punya instansi_id tersebut
 * 2. Ambil semua Device (user_devices) milik user-user tersebut
 */



$sql = "SELECT ud.device_unique_id, ud.device_name, ud.location, ud.owner_name, u.username as member_name
        FROM user_devices ud
        JOIN users u ON ud.user_id = u.id
        WHERE u.instansi_id = ? 
        ORDER BY ud.device_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instansi_id);
$stmt->execute();
$result = $stmt->get_result();




$sql_user = "SELECT * FROM users WHERE id = ?";
$stmt_user = $conn->prepare($sql_user);
$stmt_user->bind_param("i", $instansi_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
$user = $result_user->fetch_assoc();

$devices = [];
while ($row = $result->fetch_assoc()) {
    $devices[] = [
        "id" => $row['device_unique_id'],
        "name" => $row['device_name'],
        "location" => $row['location'],
        "owner" => $row['owner_name'],
        "added_by" => $row['member_name']
    ];
}

// Jika ada permintaan detail untuk device spesifik (saat user memilih device)
$selected_id = isset($_GET['device_id']) ? $_GET['device_id'] : null;
$details = null;

if ($selected_id) {
    // Ambil Config Sensor (Sama seperti logika dashboard user biasa)
    $sql_s = "SELECT parameter_name, mqtt_topic, unit, category, display_order 
              FROM device_settings WHERE device_unique_id = ? AND is_visible = 1 AND category = 'sensor'";
    $stmt_s = $conn->prepare($sql_s);
    $stmt_s->bind_param("s", $selected_id);
    $stmt_s->execute();
    $res_s = $stmt_s->get_result();

    $sensors = [];
    $topics = [];
    while($s = $res_s->fetch_assoc()){
        $sensors[] = [
            'label' => $s['parameter_name'],
            'topic' => $s['mqtt_topic'],
            'unit'  => $s['unit'],
            'value' => 0,
            'type'  => '' // Akan dideteksi di frontend
        ];
        $topics[] = $s['mqtt_topic'];
    }

    // Ambil Info Device
    $sql_d = "SELECT * FROM user_devices WHERE device_unique_id = ? LIMIT 1";
    $stmt_d = $conn->prepare($sql_d);
    $stmt_d->bind_param("s", $selected_id);
    $stmt_d->execute();
    $dev_info = $stmt_d->get_result()->fetch_assoc();

    // Ambil Chart Config
    $charts = [];
    $sql_c = "SELECT usc.data, ds.parameter_name 
              FROM user_sensor_charts usc 
              JOIN device_settings ds ON usc.device_setting_id = ds.id 
              WHERE usc.device_unique_id = ? AND usc.is_active = 1";
    $stmt_c = $conn->prepare($sql_c);
    $stmt_c->bind_param("s", $selected_id);
    $stmt_c->execute();
    $res_c = $stmt_c->get_result();
    while($c = $res_c->fetch_assoc()){
        $charts[] = ['val' => $c['data'], 'label' => $c['parameter_name']];
    }

    $details = [
        "device" => [
            "name" => $dev_info['device_name'],
            "id" => $dev_info['device_unique_id'],
            "lokasi" => $dev_info['location'],
            "zona_waktu" => $dev_info['timezone'] ?? 'WIB',
            "owner" => $dev_info['owner_name'],
            "username" => $user['username']
        ],
        "sensors" => $sensors,
        "charts" => $charts,
        "mqtt_topics" => $topics
    ];
}

echo json_encode([
    "status" => true,
    "instansi_name" => $user['username'],
    "device_list" => $devices,
    "selected_device_details" => $details
]);
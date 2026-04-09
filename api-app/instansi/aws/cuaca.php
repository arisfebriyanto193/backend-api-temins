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

/**
 * cuaca.php — Instansi AWS
 * 
 * Mengembalikan topic MQTT untuk sensor cuaca
 * (Suhu, Kelembapan, Radiasi, Curah Hujan) dari device milik instansi.
 * 
 * Parameter: ?device_id=XXXX (opsional, jika tidak dikirim pakai device pertama)
 */

function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } else if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (function_exists('apache_request_headers')) {
        $reqHeaders = array_change_key_case(apache_request_headers(), CASE_LOWER);
        if (isset($reqHeaders['authorization'])) {
            $headers = trim($reqHeaders['authorization']);
        }
    }
    if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
        return $matches[1];
    }
    return null;
}

// 1. Validasi Token Instansi
$token = getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Token tidak ditemukan"]);
    exit();
}

$user = verify_jwt($token);
if (!$user || $user['role'] !== 'instansi') {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Unauthorized: instansi role required"]);
    exit();
}

$instansi_id = $user['uid'];

// 2. Tentukan device_id yang akan dipakai
$requested_device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : null;

if ($requested_device_id) {
    // Verifikasi device tersebut memang milik instansi ini
    $sql_check = "SELECT ud.device_unique_id 
                  FROM user_devices ud
                  JOIN users u ON ud.user_id = u.id
                  WHERE u.instansi_id = ? AND ud.device_unique_id = ?
                  LIMIT 1";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("is", $instansi_id, $requested_device_id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();

    if ($res_check->num_rows === 0) {
        echo json_encode(["status" => false, "message" => "Device tidak ditemukan atau bukan milik instansi ini"]);
        exit();
    }
    $device_unique_id = $requested_device_id;
} else {
    // Ambil device pertama milik instansi
    $sql_first = "SELECT ud.device_unique_id 
                  FROM user_devices ud
                  JOIN users u ON ud.user_id = u.id
                  WHERE u.instansi_id = ?
                  ORDER BY ud.device_name ASC
                  LIMIT 1";
    $stmt_first = $conn->prepare($sql_first);
    $stmt_first->bind_param("i", $instansi_id);
    $stmt_first->execute();
    $res_first = $stmt_first->get_result()->fetch_assoc();

    if (!$res_first) {
        echo json_encode(["status" => false, "message" => "Tidak ada device terdaftar untuk instansi ini"]);
        exit();
    }
    $device_unique_id = $res_first['device_unique_id'];
}

// 3. Ambil MQTT topic untuk sensor cuaca (Suhu, Kelembapan, Radiasi, Curah Hujan)
$sql_sensor = "SELECT parameter_name, mqtt_topic 
               FROM device_settings 
               WHERE device_unique_id = ? 
               AND parameter_name IN (
                   'Suhu Udara', 
                   'Kelembapan Udara', 
                   'Radiasi Matahari', 
                   'Curah Hujan Berjalan',
                   'Kecepatan Angin'
               )";
$stmt_sensor = $conn->prepare($sql_sensor);
$stmt_sensor->bind_param("s", $device_unique_id);
$stmt_sensor->execute();
$rows = $stmt_sensor->get_result()->fetch_all(MYSQLI_ASSOC);

$data = [];
$mqtt_topics = [];

foreach ($rows as $row) {
    $code = '';
    switch ($row['parameter_name']) {
        case 'Suhu Udara':              $code = 'su'; break;
        case 'Kelembapan Udara':        $code = 'ku'; break;
        case 'Radiasi Matahari':        $code = 'rm'; break;
        case 'Curah Hujan Berjalan':    $code = 'cp'; break;
        case 'Kecepatan Angin':         $code = 'ka'; break;
    }
    if ($code !== '') {
        $data[$code] = $row['mqtt_topic'];
        $mqtt_topics[] = $row['mqtt_topic'];
    }
}

echo json_encode([
    "status"           => true,
    "device_unique_id" => $device_unique_id,
    "data"             => $data,
    "mqtt_topics"      => $mqtt_topics,
]);

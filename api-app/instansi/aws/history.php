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

// --- 1. AMBIL DAFTAR SEMUA DEVICE MILIK ANGGOTA INSTANSI ---
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

// --- 2. JIKA DEVICE DIPILIH, AMBIL CONFIG NYA ---
$details = null;
if ($selected_device_id) {
    $sql_dev = "SELECT * FROM user_devices WHERE device_unique_id = ? LIMIT 1";
    $stmt_dev = $conn->prepare($sql_dev);
    $stmt_dev->bind_param("s", $selected_device_id);
    $stmt_dev->execute();
    $device = $stmt_dev->get_result()->fetch_assoc();

    if ($device) {
        // Ambil Sensor dari device_settings
        $sensors = [];
        $sql_s = "SELECT parameter_name, mqtt_topic, unit FROM device_settings 
                  WHERE device_unique_id = ? AND category = 'sensor' AND is_visible = 1";
        $stmt_s = $conn->prepare($sql_s);
        $stmt_s->bind_param("s", $selected_device_id);
        $stmt_s->execute();
        $res_s = $stmt_s->get_result();

        function getStyle($label) {
            $l = strtolower($label);
            if (strpos($l, 'arah') !== false) return ['icon'=>'fa-compass', 'color'=>'#a855f7']; 
            if (strpos($l, 'kecepatan') !== false) return ['icon'=>'fa-wind', 'color'=>'#06b6d4']; 
            if (strpos($l, 'hujan') !== false) return ['icon'=>'fa-cloud-rain', 'color'=>'#3b82f6']; 
            if (strpos($l, 'suhu') !== false) return ['icon'=>'fa-temperature-half', 'color'=>'#f97316']; 
            return ['icon'=>'fa-microchip', 'color'=>'#6366f1']; 
        }

        while ($s = $res_s->fetch_assoc()) {
            $style = getStyle($s['parameter_name']);
            // Ambil suffix topic sebagai code (misal: 'su' dari 'topic/data/su')
            $parts = explode('/', $s['mqtt_topic']);
            $code = end($parts);
            
            $sensors[] = [
                'code'  => $code,
                'label' => $s['parameter_name'],
                'unit'  => $s['unit'],
                'icon'  => $style['icon'],
                'color' => $style['color']
            ];
        }

        $details = [
            "device_name" => $device['device_name'],
            "device_id" => $device['device_unique_id'],
            "lokasi" => $device['location'],
            "kota" => $device['city'], 
            "zonawaktu" => $device['timezone'] ?? 'WIB',
            "sensors" => $sensors,
            "years" => range(date('Y'), 2024)
        ];
    }
}

echo json_encode([
    "status" => true,
    "device_list" => $device_list,
    "details" => $details
]);
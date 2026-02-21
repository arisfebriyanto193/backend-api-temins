<?php
// api-app/notifications/save_push_token.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "../db/sql.php";
require_once "../auth/jwt.php"; // Asumsikan file jwt.php ada di folder auth/

// Mendapatkan Header Authorization
$headers = apache_request_headers();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : '');

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    echo json_encode(["status" => false, "message" => "Token tidak ditemukan"]);
    exit;
}

$token = $matches[1];
$payload = decode_jwt($token); // Pastikan fungsi decode_jwt dari jwt.php me-return false jika invalid

if (!$payload || !isset($payload['uid'])) {
    echo json_encode(["status" => false, "message" => "Token tidak valid atau sudah kadaluarsa"]);
    exit;
}

$user_id = (int) $payload['uid'];

// Mendapatkan Body JSON
$data = json_decode(file_get_contents("php://input"), true);

$expo_push_token = isset($data['expo_push_token']) ? trim($data['expo_push_token']) : '';
$device_id = isset($data['device_id']) ? trim($data['device_id']) : '';
$device_name = isset($data['device_name']) ? trim($data['device_name']) : 'Unknown Device';

// Validasi input
if (empty($expo_push_token) || empty($device_id)) {
    echo json_encode(["status" => false, "message" => "expo_push_token dan device_id wajib diisi"]);
    exit;
}

// Mencegah XSS basic
$expo_push_token = htmlspecialchars($expo_push_token, ENT_QUOTES, 'UTF-8');
$device_id = htmlspecialchars($device_id, ENT_QUOTES, 'UTF-8');
$device_name = htmlspecialchars($device_name, ENT_QUOTES, 'UTF-8');

// Insert atau Update menggunakan Prepared Statement (Upsert: ON DUPLICATE KEY UPDATE)
$stmt = $conn->prepare("
    INSERT INTO user_push_tokens (user_id, expo_push_token, device_name, device_id, created_at, updated_at) 
    VALUES (?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE 
        expo_push_token = VALUES(expo_push_token),
        user_id = VALUES(user_id),
        device_name = VALUES(device_name),
        updated_at = NOW()
");

$stmt->bind_param("isss", $user_id, $expo_push_token, $device_name, $device_id);

if ($stmt->execute()) {
    echo json_encode(["status" => true, "message" => "Push token berhasil disimpan/diperbarui"]);
} else {
    echo json_encode(["status" => false, "message" => "Gagal menyimpan push token", "error" => $stmt->error]);
}

$stmt->close();
$conn->close();
?>

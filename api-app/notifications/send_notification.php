<?php
// api-app/notifications/send_notification.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "../db/sql.php";
require_once "../auth/jwt.php"; 

$headers = apache_request_headers();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : '');

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    echo json_encode(["status" => false, "message" => "Token tidak ditemukan"]);
    exit;
}

$token = $matches[1];
$payload = decode_jwt($token);

if (!$payload) {
    echo json_encode(["status" => false, "message" => "Token tidak valid atau sudah kadaluarsa"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$target_user_id = isset($data['user_id']) ? (int) $data['user_id'] : 0;
$title = isset($data['title']) ? trim($data['title']) : '';
$body = isset($data['message']) ? trim($data['message']) : '';
$data_payload = isset($data['data']) ? $data['data'] : null;

// Validasi
if ($target_user_id <= 0 || empty($title) || empty($body)) {
    echo json_encode(["status" => false, "message" => "user_id, title, dan message wajib diisi"]);
    exit;
}

// Ambil token push berdasarkan target_user_id (Hanya ambil token expo)
$stmt = $conn->prepare("SELECT id, expo_push_token, device_id FROM user_push_tokens WHERE user_id = ?");
$stmt->bind_param("i", $target_user_id);
$stmt->execute();
$result = $stmt->get_result();

$tokens = [];
$token_map = []; // Untuk pembersihan DeviceNotRegistered
while ($row = $result->fetch_assoc()) {
    $tokens[] = $row['expo_push_token'];
    $token_map[$row['expo_push_token']] = $row['device_id']; 
}
$stmt->close();

if (empty($tokens)) {
    echo json_encode(["status" => false, "message" => "User tidak memiliki device terdaftar"]);
    $conn->close();
    exit;
}

// Persiapkan payload untuk Expo
$messages = [];
foreach ($tokens as $expo_token) {
    $messages[] = [
        "to" => $expo_token,
        "sound" => "default",
        "title" => $title,
        "body" => $body,
        "data" => $data_payload,
        "priority" => "high"
    ];
}

// Kirim request via cURL ke server Expo
$ch = curl_init('https://exp.host/--/api/v2/push/send');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Accept-encoding: gzip, deflate',
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messages));

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(["status" => false, "message" => "Gagal menghubungi API Expo", "error" => $error]);
    exit;
}

$response_data = json_decode($response, true);
$invalid_device_ids = [];

if (isset($response_data['data'])) {
    foreach ($response_data['data'] as $index => $res) {
        if ($res['status'] === 'error') {
            $error_code = isset($res['details']['error']) ? $res['details']['error'] : 'unknown';
            if ($error_code === 'DeviceNotRegistered') {
                $failed_token = $messages[$index]['to'];
                if (isset($token_map[$failed_token])) {
                    $invalid_device_ids[] = $token_map[$failed_token];
                }
            }
        }
    }
}

// Jika ada token DeviceNotRegistered yang kedaluwarsa, hapus dari database
if (!empty($invalid_device_ids)) {
    $placeholders = implode(',', array_fill(0, count($invalid_device_ids), '?'));
    $types = str_repeat('s', count($invalid_device_ids));
    
    $clean_stmt = $conn->prepare("DELETE FROM user_push_tokens WHERE device_id IN ($placeholders)");
    $clean_stmt->bind_param($types, ...$invalid_device_ids);
    $clean_stmt->execute();
    $clean_stmt->close();
}

$conn->close();

echo json_encode([
    "status" => true, 
    "message" => "Notifikasi diproses", 
    "expo_response" => $response_data,
    "cleaned_devices" => count($invalid_device_ids)
]);
?>

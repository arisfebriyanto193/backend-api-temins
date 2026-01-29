<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include '../db/sql.php';
include '../auth/jwt.php';

/* =========================
   HELPER AMBIL TOKEN
========================= */
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

/* =========================
   VALIDASI JWT
========================= */
$token = getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Token missing"
    ]);
    exit();
}

$user = verify_jwt($token);
if (!$user) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Invalid token"
    ]);
    exit();
}

$user_id = $user['uid'] ?? $user['id'] ?? null;
if (!$user_id) {
    echo json_encode([
        "status" => false,
        "message" => "User ID not found in token"
    ]);
    exit();
}

/* =========================
   GET DATA DEVICE
========================= */

// Ambil semua device user
$sql = "SELECT 
            id,
            user_id,
            device_name,
            owner_name,
            device_type,
            device_unique_id,
            city,
            location,
            internet_no,
            pic_contact,
            pic,
            timezone,
            status
        FROM user_devices
        WHERE user_id = '$user_id'";

$query = mysqli_query($conn, $sql);

if (!$query) {
    echo json_encode([
        "status" => false,
        "message" => "Query error"
    ]);
    exit();
}

$data = [];
while ($row = mysqli_fetch_assoc($query)) {
    $data[] = $row;
}

if (count($data) === 0) {
    echo json_encode([
        "status" => false,
        "message" => "No device found"
    ]);
    exit();
}

/* =========================
   RESPONSE JSON
========================= */
echo json_encode([
    "status" => true,
    "user_id" => $user_id,
    "total" => count($data),
    "data" => $data
]);

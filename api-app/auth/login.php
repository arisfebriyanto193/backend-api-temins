<?php
// Header agar bisa diakses dari frontend
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "../db/sql.php"; 
require_once "jwt.php"; 

// Ambil parameter login dari URL (user atau instansi)
$login_type_request = isset($_GET['login']) ? $_GET['login'] : 'user';

// Ambil data JSON dari input body
$data = json_decode(file_get_contents("php://input"), true);
$username = isset($data['username']) ? trim($data['username']) : '';
$password = isset($data['password']) ? $data['password'] : '';

if (empty($username) || empty($password)) {
    echo json_encode(["status" => false, "message" => "Username dan Password wajib diisi"]);
    exit;
}

$user = null;
$role = '';
$device_type = null;
$redirect_path = "users/";

// ===============================================
// LOGIKA PERCABANGAN LOGIN
// ===============================================

if ($login_type_request === 'instansi') {
    /**
     * LOGIN SEBAGAI INSTANSI
     * Mencari di tabel 'instansi'
     */
    $stmt = $conn->prepare("SELECT id, username, password FROM instansi WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        echo json_encode(["status" => false, "message" => "Akun Instansi tidak ditemukan!"]);
        exit;
    }

    $user = $result->fetch_assoc();
    
    // Verifikasi Password
    if (!password_verify($password, $user['password'])) {
        echo json_encode(["status" => false, "message" => "Password Instansi salah!"]);
        exit;
    }

    // Set data khusus Instansi
    $role = 'instansi';
    $device_type = 'instansi'; // Penanda untuk frontend
    $redirect_path = "user/instansi/";

} else {
    /**
     * LOGIN SEBAGAI USER BIASA / ADMIN
     * Logika asli Anda
     */
    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        echo json_encode(["status" => false, "message" => "Username tidak ditemukan!"]);
        exit;
    }

    $user = $result->fetch_assoc();

    if (!password_verify($password, $user['password'])) {
        echo json_encode(["status" => false, "message" => "Password salah!"]);
        exit;
    }

    $role = $user['role'];

    if ($role === "admin") {
        $redirect_path = "sett/";
        $device_type = 'admin';
    } else {
        $uid = (int)$user['id'];
        $stmtDev = $conn->prepare("SELECT device_type FROM user_devices WHERE user_id = ? LIMIT 1");
        $stmtDev->bind_param("i", $uid);
        $stmtDev->execute();
        $devResult = $stmtDev->get_result();
        
        if ($devResult->num_rows > 0) {
            $device = $devResult->fetch_assoc();
            $device_type = $device['device_type'];
            
            if (strtolower($device_type) === "awlr") {
                $redirect_path = "user/awlr/";
            } else if (strtolower($device_type) === "aws") {
                $redirect_path = "user/aws/";
            } else if (strtolower($device_type) === "smart_farm") {
                $redirect_path = "user/sf/";
            }
        }
    }
}

// ===============================================
// GENERATE JWT TOKEN
// ===============================================
$payload = [
    "uid" => (int)$user['id'],
    "username" => $user['username'],
    "role" => $role,
    "device_type" => $device_type,
    "exp" => time() + (60 * 60 * 24 * 7) // 7 hari
];

$token = generate_jwt($payload);

// ===============================================
// OUTPUT JSON
// ===============================================
echo json_encode([
    "status" => true,
    "message" => "Login berhasil sebagai " . ($role === 'instansi' ? 'Instansi' : 'User'),
    "token" => $token,
    "data" => [
        "user_id" => (int)$user['id'],
        "username" => $user['username'],
        "role" => $role,
        "device_type" => $device_type,
        "redirect_target" => $redirect_path 
    ]
]);
?>
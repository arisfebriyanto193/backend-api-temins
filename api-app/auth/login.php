<?php
// Header agar bisa diakses dari React Native / Next.js / Frontend lain
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Tangani preflight request (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Sesuaikan path ini dengan struktur folder Anda
// Asumsi: api_login.php ada di folder 'api', jadi naik satu level untuk ke config
require_once "../db/sql.php"; 
require_once "jwt.php"; // Pastikan file jwt.php ada di satu folder dengan file ini

// Ambil data JSON dari input body
$data = json_decode(file_get_contents("php://input"), true);

// Ambil username & password
$username = isset($data['username']) ? trim($data['username']) : '';
$password = isset($data['password']) ? $data['password'] : '';

// Validasi input
if (empty($username) || empty($password)) {
    echo json_encode(["status" => false, "message" => "Username dan Password wajib diisi"]);
    exit;
}

// ===============================================
// 1. QUERY USER UTAMA (Sama seperti logika asli)
// ===============================================
$stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    echo json_encode(["status" => false, "message" => "Username tidak ditemukan!"]);
    exit;
}

$user = $result->fetch_assoc();

// ===============================================
// 2. VERIFIKASI PASSWORD
// ===============================================
if (!password_verify($password, $user['password'])) {
    echo json_encode(["status" => false, "message" => "Password salah!"]);
    exit;
}

// ===============================================
// 3. LOGIKA CEK DEVICE & ROLE (Diadaptasi ke API)
// ===============================================
$device_type = null;
$redirect_path = "users/"; // Default path

// Jika admin, tidak perlu cek device
if ($user['role'] === "admin") {
    $redirect_path = "admin/";
} else {
    // Jika bukan admin, cek tabel user_devices
    $uid = (int)$user['id'];
    $stmtDev = $conn->prepare("SELECT device_type FROM user_devices WHERE user_id = ? LIMIT 1");
    $stmtDev->bind_param("i", $uid);
    $stmtDev->execute();
    $devResult = $stmtDev->get_result();
    
    if ($devResult->num_rows > 0) {
        $device = $devResult->fetch_assoc();
        $device_type = $device['device_type']; // Contoh: "awlr"
        
        // Tentukan navigasi berdasarkan tipe device
        if (strtolower($device_type) === "awlr") {
            $redirect_path = "users/awlr/";
        }
    }
}

// ===============================================
// 4. GENERATE JWT TOKEN
// ===============================================
$payload = [
    "uid" => (int)$user['id'],
    "username" => $user['username'],
    "role" => $user['role'],
    "device_type" => $device_type, // Disimpan di token agar Front-end tahu tipe alatnya
   // "exp" => time() + 60 // 1 menit  #time() + (60 * 60 * 24) // Token berlaku 24 jam
   "exp" => time() + (60 * 60 * 24 * 7) // 7 hari

];

// Gunakan fungsi generate_jwt dari file jwt.php
$token = generate_jwt($payload);


if ($user['role'] == "admin") $device_type = 'admin';
// ===============================================
// 5. OUTPUT JSON
// ===============================================
echo json_encode([
    "status" => true,
    "message" => "Login berhasil",
    "token" => $token,
    "data" => [
        "user_id" => (int)$user['id'],
        "username" => $user['username'],
        "role" => $user['role'],
        "device_type" => $device_type,
        "redirect_target" => $redirect_path // Membantu frontend menentukan routing
    ]
]);
?>

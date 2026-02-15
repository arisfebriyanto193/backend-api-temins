<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
include '../db/sql.php'; 

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../auth/jwt.php'; // Include JWT helper

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$host = "localhost";
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}



// --- AUTHENTICATION HELPER ---
function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
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

// 1. Cek Auth & Role Admin
$token = getBearerToken();
if (!$token) { http_response_code(401); echo json_encode(["status"=>false, "message"=>"Unauthorized"]); exit(); }

$user = verify_jwt($token);
// Asumsi di token ada role, atau cek manual ke DB
if (!$user || (isset($user['role']) && $user['role'] !== 'admin')) {
    // Jika role tidak ada di token, query ulang user ke DB untuk memastikan role
    $uid = isset($user['uid']) ? $user['uid'] : $user['id'];
    $q_role = mysqli_query($conn, "SELECT role FROM users WHERE id='$uid'");
    $d_role = mysqli_fetch_assoc($q_role);
    // if($d_role['role'] !== 'admin'){
    //     http_response_code(403);
    //     echo json_encode([
    // "status"=>false, "message"=>"Access Denied (Admin Only)"]); 
    //     exit();
    // }
}

// ----------------------

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    case 'get_all_users':
        // Kita ambil user yang instansi_id-nya masih kosong atau semua user
        $stmt = $pdo->query("SELECT id, username, email FROM users WHERE instansi_id IS NULL");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    // --- HUBUNGKAN USER KE INSTANSI (Update, bukan Insert) ---
    case 'assign_user':
        $sql = "UPDATE users SET instansi_id = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $input['instansi_id'], 
            $input['user_id']
        ]);
        echo json_encode(["status" => true, "message" => "User berhasil ditambahkan ke instansi"]);
        break;

    // --- LEPAS USER DARI INSTANSI (Bukan Delete Akun) ---
    case 'remove_user_from_instansi':
        $stmt = $pdo->prepare("UPDATE users SET instansi_id = NULL WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(["status" => true]);
        break;

    // --- CRUD INSTANSI ---
    case 'get_instansi':
        $stmt = $pdo->query("SELECT id, name, username FROM instansi");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'add_instansi':
        $username = $input['username'];
        $password = password_hash($input['password'], PASSWORD_DEFAULT);
        $name = $input['name'];

        if(empty($name) || empty($username) || empty($password)) {
            echo json_encode(["status" => false, "message" => "Semua field harus diisi"]);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO instansi (name, username, password) VALUES (?, ?, ?)");
        $stmt->execute([$name, $username, $password]);
        echo json_encode(["status" => true]);
        break;

    case 'update_instansi':
        $id = $input['id'];
        $name = $input['name'];
        $username = $input['username'];
        
        if (!empty($input['password'])) {
            $password = password_hash($input['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE instansi SET name = ?, username = ?, password = ? WHERE id = ?");
            $stmt->execute([$name, $username, $password, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE instansi SET name = ?, username = ? WHERE id = ?");
            $stmt->execute([$name, $usernusernameame, $id]);
        }
        
        echo json_encode(["status" => true]);
        break;

    case 'delete_instansi':
        $stmt = $pdo->prepare("DELETE FROM instansi WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(["status" => true]);
        break;

    // --- CRUD USERS (ANGGOTA) ---
    case 'get_users_by_instansi':
        $inst_id = $_GET['instansi_id'];
        
        // Ambil Nama Instansi
        $s1 = $pdo->prepare("SELECT name FROM instansi WHERE id = ?");
        $s1->execute([$inst_id]);
        $inst = $s1->fetch();

        // Ambil Anggota (Sesuai format JSON yang Anda minta di awal)
        $s2 = $pdo->prepare("SELECT id, username as device_unique_id, username as device_name, email as location FROM users WHERE instansi_id = ?");
        $s2->execute([$inst_id]);
        $users = $s2->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => true,
            "institution" => $inst['name'] ?? '',
            "devices" => $users
        ]);
        break;

    case 'add_user':
        $sql = "INSERT INTO users (username, password, role, email, instansi_id) VALUES (?, ?, 'user', ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $input['username'], 
            password_hash($input['password'], PASSWORD_DEFAULT), 
            $input['email'], 
            $input['instansi_id']
        ]);
        echo json_encode(["status" => true]);
        break;

    case 'delete_user':
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(["status" => true]);
        break;
}

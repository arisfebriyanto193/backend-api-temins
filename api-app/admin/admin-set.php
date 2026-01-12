<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// ================== PREFLIGHT ==================
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ================== CONFIG ==================
$FILE_JSON = __DIR__ . "app.json";

include '../db/sql.php';     // $conn
include '../auth/jwt.php';   // verify_jwt()

// ================== AUTH HELPER ==================
function getBearerToken() {
    $headers = null;

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
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

// ================== AUTH & ROLE CHECK ==================
$token = getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Unauthorized"]);
    exit();
}

$user = verify_jwt($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Invalid token"]);
    exit();
}

// Pastikan ADMIN
$uid = $user['uid'] ?? $user['id'] ?? null;
$q = mysqli_query($conn, "SELECT role FROM users WHERE id='$uid'");
$d = mysqli_fetch_assoc($q);

if (!$d || $d['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(["status" => false, "message" => "Access Denied (Admin Only)"]);
    exit();
}

// ================== ROUTER ==================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

/*
|--------------------------------------------------------------------------
| ACTION: admin-users
|--------------------------------------------------------------------------
*/
if ($action === 'admin-users') {

// ---------- PUT CHANGE PASSWORD ----------
if ($method === 'PUT') {
    $input = json_decode(file_get_contents("php://input"), true);

    if (
        empty($input['id']) ||
        empty($input['password']) ||
        ($input['type'] ?? '') !== 'change_password'
    ) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Data tidak lengkap"
        ]);
        exit();
    }

    $id = (int) $input['id'];
    $password = password_hash($input['password'], PASSWORD_BCRYPT);

    // Pastikan admin ada
    $cek = mysqli_query($conn, "SELECT id FROM users WHERE id=$id AND role='admin'");
    if (mysqli_num_rows($cek) === 0) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Admin tidak ditemukan"
        ]);
        exit();
    }

    // Update password
    $sql = "UPDATE users SET password='$password' WHERE id=$id";

    if (mysqli_query($conn, $sql)) {
        echo json_encode([
            "status" => true,
            "message" => "Password admin berhasil diubah"
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Gagal mengubah password"
        ]);
    }
    exit();
}

    // ---------- GET ADMIN USERS ----------
    if ($method === 'GET') {
        $res = mysqli_query($conn, "SELECT id, username, role, diBuat FROM users WHERE role='admin'");
        $data = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $data[] = $row;
        }

        echo json_encode([
            "status" => true,
            "data" => $data
        ]);
        exit();
    }

    // ---------- POST ADD ADMIN ----------
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);

        if (empty($input['username']) || empty($input['password'])) {
            http_response_code(400);
            echo json_encode(["status"=>false,"message"=>"Username dan password wajib diisi"]);
            exit();
        }

        $username = mysqli_real_escape_string($conn, $input['username']);
        $password = password_hash($input['password'], PASSWORD_BCRYPT);

        $cek = mysqli_query($conn, "SELECT id FROM users WHERE username='$username'");
        if (mysqli_num_rows($cek) > 0) {
            http_response_code(409);
            echo json_encode(["status"=>false,"message"=>"Username sudah terdaftar"]);
            exit();
        }

        $sql = "INSERT INTO users (username,password,role,diBuat)
                VALUES ('$username','$password','admin',NOW())";

        if (mysqli_query($conn, $sql)) {
            echo json_encode(["status"=>true,"message"=>"Admin berhasil ditambahkan"]);
        } else {
            http_response_code(500);
            echo json_encode(["status"=>false,"message"=>"Gagal menambah admin"]);
        }
        exit();
    }
    
    // ---------- DELETE ADMIN ----------
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents("php://input"), true);

    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "ID admin wajib diisi"
        ]);
        exit();
    }

    $id = (int) $input['id'];

    // Pastikan user adalah admin
    $cek = mysqli_query($conn, "SELECT id FROM users WHERE id=$id AND role='admin'");
    if (mysqli_num_rows($cek) === 0) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Admin tidak ditemukan"
        ]);
        exit();
    }

    // Hitung jumlah admin
    $countAdmin = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role='admin'");
    $row = mysqli_fetch_assoc($countAdmin);

    if ((int)$row['total'] <= 1) {
        http_response_code(403);
        echo json_encode([
            "status" => false,
            "message" => "Admin tidak bisa dihapus karena hanya tersisa satu admin"
        ]);
        exit();
    }

    // Hapus admin
    $sql = "DELETE FROM users WHERE id=$id AND role='admin'";

    if (mysqli_query($conn, $sql)) {
        echo json_encode([
            "status" => true,
            "message" => "Admin berhasil dihapus"
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Gagal menghapus admin"
        ]);
    }
    exit();
}

}


/*
|--------------------------------------------------------------------------
| ACTION: app-config
|--------------------------------------------------------------------------
*/
if ($action === 'app-config') {

    if (!file_exists($FILE_JSON)) {
        http_response_code(404);
        echo json_encode(["status"=>false,"message"=>"File app.json tidak ditemukan"]);
        exit();
    }

    // ---------- GET CONFIG ----------
    if ($method === 'GET') {
        $json = json_decode(file_get_contents($FILE_JSON), true);
        echo json_encode([
            "status" => true,
            "data" => $json
        ]);
        exit();
    }

    // ---------- POST UPDATE CONFIG ----------
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(["status"=>false,"message"=>"JSON body tidak valid"]);
            exit();
        }

        $old = json_decode(file_get_contents($FILE_JSON), true);
        $new = array_merge($old, $input);

        $save = file_put_contents(
            $FILE_JSON,
            json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if ($save === false) {
            http_response_code(500);
            echo json_encode(["status"=>false,"message"=>"Gagal menyimpan config"]);
            exit();
        }

        echo json_encode([
            "status" => true,
            "message" => "Config berhasil diperbarui",
            "data" => $new
        ]);
        exit();
    }
}

// ================== FALLBACK ==================
http_response_code(404);
echo json_encode([
    "status" => false,
    "message" => "Endpoint tidak ditemukan"
]);

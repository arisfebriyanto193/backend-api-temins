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
$FILE_JSON = __DIR__ . "/app.json";

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

// ---------- PUT: CHANGE PASSWORD  |  EDIT PROFILE ----------
if ($method === 'PUT') {
    $input = json_decode(file_get_contents("php://input"), true);
    $type  = $input['type'] ?? '';

    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(["status" => false, "message" => "ID wajib diisi"]);
        exit();
    }

    $id = (int) $input['id'];

    // Pastikan admin ada
    $cek = mysqli_query($conn, "SELECT id FROM users WHERE id=$id AND role='admin'");
    if (mysqli_num_rows($cek) === 0) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "Admin tidak ditemukan"]);
        exit();
    }

    // ---- Ganti Password ----
    if ($type === 'change_password') {
        if (empty($input['password'])) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Password baru wajib diisi"]);
            exit();
        }
        $password = password_hash($input['password'], PASSWORD_BCRYPT);
        if (mysqli_query($conn, "UPDATE users SET password='$password' WHERE id=$id")) {
            echo json_encode(["status" => true, "message" => "Password admin berhasil diubah"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => false, "message" => "Gagal mengubah password"]);
        }
        exit();
    }

    // ---- Edit Username & Email ----
    if ($type === 'edit_profile') {
        if (empty($input['username'])) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Username wajib diisi"]);
            exit();
        }

        $username = mysqli_real_escape_string($conn, $input['username']);
        $email    = isset($input['email']) ? mysqli_real_escape_string($conn, $input['email']) : '';

        // Cek username sudah dipakai admin lain
        $cekUser = mysqli_query($conn, "SELECT id FROM users WHERE username='$username' AND id != $id");
        if (mysqli_num_rows($cekUser) > 0) {
            http_response_code(409);
            echo json_encode(["status" => false, "message" => "Username sudah digunakan"]);
            exit();
        }

        $sql = "UPDATE users SET username='$username', email='$email' WHERE id=$id";
        if (mysqli_query($conn, $sql)) {
            echo json_encode(["status" => true, "message" => "Profil admin berhasil diperbarui"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => false, "message" => "Gagal memperbarui profil"]);
        }
        exit();
    }

    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Tipe aksi tidak dikenal"]);
    exit();
}

    // ---------- GET ADMIN USERS ----------
    if ($method === 'GET') {
        $res = mysqli_query($conn, "SELECT id, username, email, role, diBuat FROM users WHERE role='admin'");
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
        $email    = isset($input['email']) ? mysqli_real_escape_string($conn, $input['email']) : '';

        $cek = mysqli_query($conn, "SELECT id FROM users WHERE username='$username'");
        if (mysqli_num_rows($cek) > 0) {
            http_response_code(409);
            echo json_encode(["status"=>false,"message"=>"Username sudah terdaftar"]);
            exit();
        }

        $sql = "INSERT INTO users (username, password, role, email, diBuat)
                VALUES ('$username', '$password', 'admin', '$email', NOW())";

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
| ACTION: app-config  (DB-based — tabel app_configs)
|--------------------------------------------------------------------------
*/
if ($action === 'app-config') {

    // ---------- GET CONFIG ----------
    if ($method === 'GET') {
        $res = mysqli_query($conn, "SELECT status, versi, url FROM app_configs ORDER BY id ASC LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) {
            http_response_code(404);
            echo json_encode(["status" => false, "message" => "Konfigurasi belum tersedia di database"]);
            exit();
        }
        $row = mysqli_fetch_assoc($res);
        echo json_encode(["status" => true, "data" => $row]);
        exit();
    }

    // ---------- POST UPDATE CONFIG ----------
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if ($input === null) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "JSON body tidak valid"]);
            exit();
        }

        $status = isset($input['status']) ? (int)$input['status'] : null;
        $versi  = isset($input['versi'])  ? mysqli_real_escape_string($conn, trim($input['versi']))  : null;
        $url    = isset($input['url'])    ? mysqli_real_escape_string($conn, trim($input['url']))    : null;

        if ($status === null || $versi === null || $url === null) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Field status, versi, dan url wajib diisi"]);
            exit();
        }

        // Cek apakah baris config sudah ada
        $cek = mysqli_query($conn, "SELECT id FROM app_configs LIMIT 1");
        if (mysqli_num_rows($cek) === 0) {
            // Insert pertama kali
            $sql = "INSERT INTO app_configs (status, versi, url) VALUES ($status, '$versi', '$url')";
        } else {
            $row = mysqli_fetch_assoc($cek);
            $id  = (int) $row['id'];
            $sql = "UPDATE app_configs SET status=$status, versi='$versi', url='$url' WHERE id=$id";
        }

        if (mysqli_query($conn, $sql)) {
            echo json_encode([
                "status"  => true,
                "message" => "Konfigurasi berhasil diperbarui",
                "data"    => ["status" => $status, "versi" => $versi, "url" => $url]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => false, "message" => "Gagal menyimpan konfigurasi"]);
        }
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| ACTION: push-users  (Daftar user yang punya push token aktif)
|--------------------------------------------------------------------------
*/
if ($action === 'push-users') {
    if ($method === 'GET') {
        $sql = "
            SELECT u.id AS user_id, u.username, COUNT(upt.id) AS device_count
            FROM users u
            INNER JOIN user_push_tokens upt ON upt.user_id = u.id
            GROUP BY u.id, u.username
            ORDER BY u.username ASC
        ";
        $res  = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $data[] = [
                "user_id"      => (int)$row['user_id'],
                "username"     => $row['username'],
                "device_count" => (int)$row['device_count'],
            ];
        }
        echo json_encode(["status" => true, "data" => $data]);
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| ACTION: send-notif  (Kirim push notifikasi manual ke user tertentu)
|--------------------------------------------------------------------------
*/
if ($action === 'send-notif') {
    if ($method === 'POST') {
        $input   = json_decode(file_get_contents("php://input"), true);
        $title   = isset($input['title'])   ? trim($input['title'])   : '';
        $message = isset($input['message']) ? trim($input['message']) : '';
        $userIds = $input['user_ids'] ?? [];   // array int[] atau string 'all'

        if (empty($title) || empty($message)) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "title dan message wajib diisi"]);
            exit();
        }

        // Ambil user_ids yang valid
        if ($userIds === 'all' || (is_array($userIds) && count($userIds) === 0)) {
            // Semua user yang punya token
            $res     = mysqli_query($conn, "SELECT DISTINCT user_id FROM user_push_tokens");
            $targets = [];
            while ($r = mysqli_fetch_assoc($res)) {
                $targets[] = (int)$r['user_id'];
            }
        } else {
            $targets = array_map('intval', (array)$userIds);
        }

        if (empty($targets)) {
            echo json_encode(["status" => false, "message" => "Tidak ada user dengan push token terdaftar"]);
            exit();
        }

        $results = [];

        foreach ($targets as $uid) {
            $payload = json_encode([
                "user_id" => $uid,
                "title"   => $title,
                "message" => $message,
                "data"    => [
                    "screen" => "detail",
                    "id"     => $uid,
                    "status" => "manual"
                ]
            ]);

            $ch = curl_init("https://be-data.dash.temins.id/send/notif");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

            $resp     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            $results[] = [
                "user_id"    => $uid,
                "http_code"  => $httpCode,
                "ok"         => ($httpCode >= 200 && $httpCode < 300 && !$curlErr),
                "response"   => $resp ? json_decode($resp, true) : null,
                "curl_error" => $curlErr ?: null,
            ];
        }

        $successCount = count(array_filter($results, fn($r) => $r['ok']));
        echo json_encode([
            "status"  => true,
            "message" => "Notifikasi dikirim ke $successCount/" . count($targets) . " user",
            "results" => $results
        ]);
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| ACTION: email-config  (Baca/simpan/test email SMTP dari tabel email_configs)
|--------------------------------------------------------------------------
*/
if ($action === 'email-config') {

    // ---------- GET: Baca config email ----------
    if ($method === 'GET') {
        $res = mysqli_query($conn, "SELECT id, email, app_pass FROM email_configs ORDER BY id ASC LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) {
            echo json_encode(["status" => true, "data" => ["email" => "", "app_pass" => ""]]);
            exit();
        }
        $row = mysqli_fetch_assoc($res);
        // Sembunyikan sebagian app_pass untuk keamanan (tampilkan 4 char pertama + ***)
        $masked = strlen($row['app_pass']) > 4
            ? substr($row['app_pass'], 0, 4) . str_repeat('*', strlen($row['app_pass']) - 4)
            : str_repeat('*', strlen($row['app_pass']));
        echo json_encode([
            "status" => true,
            "data"   => [
                "id"           => (int)$row['id'],
                "email"        => $row['email'],
                "app_pass"     => $row['app_pass'],  // full value untuk form
                "app_pass_masked" => $masked
            ]
        ]);
        exit();
    }

    // ---------- POST: Update atau Test ----------
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        $type  = $input['type'] ?? 'update';

        // ---- Update config ----
        if ($type === 'update') {
            $email    = isset($input['email'])    ? mysqli_real_escape_string($conn, trim($input['email']))    : '';
            $app_pass = isset($input['app_pass']) ? mysqli_real_escape_string($conn, trim($input['app_pass'])) : '';

            if (empty($email) || empty($app_pass)) {
                http_response_code(400);
                echo json_encode(["status" => false, "message" => "Email dan app password wajib diisi"]);
                exit();
            }

            $cek = mysqli_query($conn, "SELECT id FROM email_configs LIMIT 1");
            if (mysqli_num_rows($cek) === 0) {
                $sql = "INSERT INTO email_configs (email, app_pass) VALUES ('$email', '$app_pass')";
            } else {
                $row = mysqli_fetch_assoc($cek);
                $id  = (int)$row['id'];
                $sql = "UPDATE email_configs SET email='$email', app_pass='$app_pass' WHERE id=$id";
            }

            if (mysqli_query($conn, $sql)) {
                echo json_encode(["status" => true, "message" => "Konfigurasi email berhasil disimpan"]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => false, "message" => "Gagal menyimpan konfigurasi email"]);
            }
            exit();
        }

        // ---- Test kirim email ----
        if ($type === 'test') {
            $to = isset($input['to']) ? trim($input['to']) : '';
            if (empty($to)) {
                http_response_code(400);
                echo json_encode(["status" => false, "message" => "Email tujuan wajib diisi"]);
                exit();
            }

            // Ambil kredensial dari DB
            $res = mysqli_query($conn, "SELECT email, app_pass FROM email_configs LIMIT 1");
            if (!$res || mysqli_num_rows($res) === 0) {
                http_response_code(400);
                echo json_encode(["status" => false, "message" => "Konfigurasi email belum diatur"]);
                exit();
            }
            $cfg      = mysqli_fetch_assoc($res);
            $smtpUser = $cfg['email'];
            $smtpPass = $cfg['app_pass'];
            $smtpHost = 'smtp.gmail.com';
            $smtpPort = 587;

            // Kirim menggunakan raw SMTP (PHP native, tanpa library)
            $subject  = "[TEST] Email dari Temins IoT Admin";
            $bodyHtml = "
<html><body style='font-family:sans-serif;padding:20px'>
<h2 style='color:#4f46e5'>✅ Test Email Berhasil</h2>
<p>Email ini dikirim dari sistem admin Temins IoT sebagai uji coba konfigurasi SMTP.</p>
<p><strong>Waktu:</strong> " . date('d M Y H:i:s') . "</p>
<p><strong>From:</strong> $smtpUser</p>
<hr><p style='color:#888;font-size:12px'>Jangan balas email ini.</p>
</body></html>";

            // Kirim via cURL ke SMTP (atau gunakan PHP mail dengan stream)
            // Gunakan socket SMTP manual agar tidak perlu library
            $ok      = false;
            $errMsg  = '';

            try {
                $socket = fsockopen('tls://' . $smtpHost, $smtpPort, $errno, $errstr, 30);
                if (!$socket) {
                    throw new Exception("Tidak bisa membuka koneksi SMTP: $errstr ($errno)");
                }

                $read = fgets($socket, 515); // 220 greeting

                // EHLO
                fputs($socket, "EHLO temins.local\r\n");
                while ($line = fgets($socket, 515)) {
                    if (substr($line, 3, 1) === ' ') break;
                }

                // AUTH LOGIN
                fputs($socket, "AUTH LOGIN\r\n");
                fgets($socket, 515); // 334

                fputs($socket, base64_encode($smtpUser) . "\r\n");
                fgets($socket, 515); // 334

                fputs($socket, base64_encode($smtpPass) . "\r\n");
                $authResp = fgets($socket, 515);
                if (strpos($authResp, '235') === false) {
                    throw new Exception("Autentikasi gagal: $authResp");
                }

                // MAIL FROM
                fputs($socket, "MAIL FROM: <$smtpUser>\r\n");
                fgets($socket, 515);

                // RCPT TO
                fputs($socket, "RCPT TO: <$to>\r\n");
                fgets($socket, 515);

                // DATA
                fputs($socket, "DATA\r\n");
                fgets($socket, 515);

                $headers  = "From: Temins IoT <$smtpUser>\r\n";
                $headers .= "To: $to\r\n";
                $headers .= "Subject: $subject\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "\r\n";

                fputs($socket, $headers . $bodyHtml . "\r\n.\r\n");
                $dataResp = fgets($socket, 515);

                fputs($socket, "QUIT\r\n");
                fclose($socket);

                if (strpos($dataResp, '250') !== false) {
                    $ok = true;
                } else {
                    $errMsg = "Server menolak pesan: $dataResp";
                }
            } catch (Exception $e) {
                $errMsg = $e->getMessage();
            }

            if ($ok) {
                echo json_encode(["status" => true, "message" => "Test email berhasil dikirim ke $to"]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => false, "message" => "Gagal kirim email: $errMsg"]);
            }
            exit();
        }

        http_response_code(400);
        echo json_encode(["status" => false, "message" => "Tipe aksi tidak dikenal"]);
        exit();
    }
}

// ================== FALLBACK ==================
http_response_code(404);
echo json_encode([
    "status"  => false,
    "message" => "Endpoint tidak ditemukan"
]);


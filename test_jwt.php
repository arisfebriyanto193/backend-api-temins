<?php

$baseUrl = "http://localhost:8001/api-app/admin/instansi.php";
$loginUrl = "http://localhost:8001/api-app/auth/login.php";

function makeRequest($url, $method, $data = [], $token = null) {
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => $method,
            'ignore_errors' => true // to fetch content even on failure
        ]
    ];

    if ($token) {
        $options['http']['header'] .= "Authorization: Bearer $token\r\n";
    }

    if ($method === 'POST' || $method === 'PUT') {
        $options['http']['content'] = json_encode($data);
    }

    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    // Parse headers to get status code
    $status_line = $http_response_header[0];
    preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
    $status = $match[1];
    
    return ['code' => $status, 'body' => $result];
}

echo "1. Getting Token (Login)...\n";
// Assuming there is a user 'admin' or similar. We need a valid user.
// Based on previous context, there might be an admin user.
// Let's try to find a user first or use a known one if possible.
// Wait, I don't know any user credentials for sure.
// I should probably check the DB or create a user if needed.
// But `login.php` checks `users` table.

// Let's try a default admin if it exists, or fail gracefully.
// Actually, I can check the `users` table via `sql.php` if I were inside the app, but here I am outside.
// I will try to use the `admin` user if it exists.
// Code snippet from `login.php` suggests checking `users` table.

// Let's create a temporary user for testing directly in DB to be sure.
require __DIR__ . '/api-app/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$host = "localhost";
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    // Create temp admin user
    $tempUser = "jwt_tester_" . time();
    $tempPass = "password123";
    $hashedPass = password_hash($tempPass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
    $stmt->execute([$tempUser, $hashedPass]);
    echo "Created temp user: $tempUser\n";
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}

// Now Login
$response = makeRequest($loginUrl, "POST", ["username" => $tempUser, "password" => $tempPass]);
$loginData = json_decode($response['body'], true);

if (!$loginData['status']) {
    die("Login failed: " . $response['body'] . "\n");
}

$token = $loginData['token'];
echo "Got Token: " . substr($token, 0, 20) . "...\n";

// 2. Test Access WITHOUT Token
echo "2. Accessing Instansi WITHOUT Token...\n";
$response = makeRequest($baseUrl . "?action=get_instansi", "GET");
echo "Status: " . $response['code'] . " (Expected 401)\n";
if ($response['code'] == 401) echo "PASS\n"; else echo "FAIL\n";

// 3. Test Access WITH Token
echo "3. Accessing Instansi WITH Token...\n";
$response = makeRequest($baseUrl . "?action=get_instansi", "GET", [], $token);
echo "Status: " . $response['code'] . " (Expected 200)\n";
if ($response['code'] == 200) echo "PASS\n"; else echo "FAIL\n";

// 4. Test Access WITH INVALID Token
echo "4. Accessing Instansi WITH INVALID Token...\n";
$response = makeRequest($baseUrl . "?action=get_instansi", "GET", [], "invalid_token_string");
echo "Status: " . $response['code'] . " (Expected 401)\n";
if ($response['code'] == 401) echo "PASS\n"; else echo "FAIL\n";


// Cleanup
$pdo->exec("DELETE FROM users WHERE username = '$tempUser'");
echo "Cleaned up temp user.\n";

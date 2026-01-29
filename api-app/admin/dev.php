<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include '../db/sql.php'; 
include '../auth/jwt.php'; 

// --- AUTH HELPER ---
function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) $headers = trim($_SERVER["Authorization"]);
    else if (isset($_SERVER['HTTP_AUTHORIZATION'])) $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_change_key_case($requestHeaders, CASE_LOWER);
        if (isset($requestHeaders['authorization'])) $headers = trim($requestHeaders['authorization']);
    }
    if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) return $matches[1];
    return null;
}

// 1. Cek Auth & Role Admin
$token = getBearerToken();
if (!$token) { http_response_code(401); echo json_encode(["status"=>false, "message"=>"Unauthorized"]); exit(); }

$user = verify_jwt($token);
// Cek Role Admin (Opsional: Query ulang ke DB jika token tidak memuat role)
if (!$user || (isset($user['role']) && $user['role'] !== 'admin')) {
    // Fallback cek DB
    $uid = $user['id'] ?? $user['uid'];
    $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role FROM users WHERE id='$uid'"));
    if($chk['role'] !== 'admin'){
        http_response_code(403); 
        echo json_encode(["status"=>false, "message"=>"Access Denied"]); 
        exit();
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($input['action'] ?? '');

// --- LOGIC ---

// A. GET ALL TEMPLATES
if ($method === 'GET') {
    $templates = [];
    $q_all = mysqli_query($conn, "SELECT t.id, t.template_code, t.template_name, p.param_name, p.mqtt_suffix, p.unit, p.data 
                                  FROM device_templates t 
                                  LEFT JOIN template_params p ON t.id = p.template_id 
                                  ORDER BY t.id DESC, p.id ASC");

    while($row = mysqli_fetch_assoc($q_all)){
        $tid = $row['id'];
        if(!isset($templates[$tid])){
            $templates[$tid] = [
                'id' => $row['id'],
                'code' => $row['template_code'],
                'name' => $row['template_name'],
                'params' => []
            ];
        }
        if($row['param_name']){
            $templates[$tid]['params'][] = [
                'label' => $row['param_name'],
                'key'   => $row['mqtt_suffix'],
                'unit'  => $row['unit'],
                'data'  => $row['data']
            ];
        }
    }
    
    // Reset keys array agar jadi JSON Array murni [{}, {}] bukan Object {"1": {}, "5": {}}
    echo json_encode(["status" => true, "data" => array_values($templates)]);
    exit();
}

// B. CREATE & UPDATE & DELETE
if ($method === 'POST') {
    
    // 1. CREATE TEMPLATE
    if ($action === 'create') {
        $code = mysqli_real_escape_string($conn, $input['code']);
        $name = mysqli_real_escape_string($conn, $input['name']);
        $params = $input['params'] ?? [];

        // Cek Duplikat
        $cek = mysqli_query($conn, "SELECT id FROM device_templates WHERE template_code = '$code'");
        if(mysqli_num_rows($cek) > 0){
            echo json_encode(["status"=>false, "message"=>"Kode Template sudah ada!"]); exit();
        }

        if(mysqli_query($conn, "INSERT INTO device_templates (template_code, template_name) VALUES ('$code', '$name')")){
            $t_id = mysqli_insert_id($conn);
            insertParams($conn, $t_id, $params);
            echo json_encode(["status"=>true, "message"=>"Template berhasil dibuat"]);
        } else {
            echo json_encode(["status"=>false, "message"=>"Error DB: ".mysqli_error($conn)]);
        }
        exit();
    }

    // 2. UPDATE TEMPLATE
    if ($action === 'update') {
        $t_id = mysqli_real_escape_string($conn, $input['id']);
        $code = mysqli_real_escape_string($conn, $input['code']);
        $name = mysqli_real_escape_string($conn, $input['name']);
        $params = $input['params'] ?? [];

        $sql = "UPDATE device_templates SET template_code='$code', template_name='$name' WHERE id='$t_id'";
        if(mysqli_query($conn, $sql)){
            // Reset Param Lama
            mysqli_query($conn, "DELETE FROM template_params WHERE template_id='$t_id'");
            // Insert Param Baru
            insertParams($conn, $t_id, $params);
            echo json_encode(["status"=>true, "message"=>"Template diperbarui"]);
        } else {
            echo json_encode(["status"=>false, "message"=>"Error Update"]);
        }
        exit();
    }

    // 3. DELETE TEMPLATE
    if ($action === 'delete') {
        $id = mysqli_real_escape_string($conn, $input['id']);
        mysqli_query($conn, "DELETE FROM template_params WHERE template_id='$id'"); // Hapus params dulu (jika tidak cascade)
        mysqli_query($conn, "DELETE FROM device_templates WHERE id='$id'");
        echo json_encode(["status"=>true, "message"=>"Template dihapus"]);
        exit();
    }
}

// Helper Insert
function insertParams($conn, $t_id, $params){
    foreach($params as $p){
        $l = mysqli_real_escape_string($conn, $p['label']);
        $k = mysqli_real_escape_string($conn, $p['key']);
        $u = mysqli_real_escape_string($conn, $p['unit']);
        $d = mysqli_real_escape_string($conn, $p['data']);
        
        if(!empty($l) && !empty($k)){
            mysqli_query($conn, "INSERT INTO template_params (template_id, param_name, mqtt_suffix, unit, data) VALUES ('$t_id', '$l', '$k', '$u', '$d')");
        }
    }
}
?>
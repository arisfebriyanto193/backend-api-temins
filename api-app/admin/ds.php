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

// Ambil JSON Input
$input = json_decode(file_get_contents('php://input'), true);
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : (isset($input['action']) ? $input['action'] : '');

// --- ROUTING LOGIC ---

// A. GET DATA (List Users / Detail Device / Templates)
if ($method === 'GET') {
    
    // 1. Get Templates
    if ($action === 'get_templates') {
        $templates_data = [];
        $q_temp = mysqli_query($conn, "SELECT t.template_code, t.template_name, p.param_name, p.mqtt_suffix, p.unit, p.data 
                                       FROM device_templates t 
                                       JOIN template_params p ON t.id = p.template_id 
                                       ORDER BY t.id, p.id");
        while($row = mysqli_fetch_assoc($q_temp)){
            $code = $row['template_code'];
            $templates_data[$code]['name'] = $row['template_name'];
            $templates_data[$code]['params'][] = [
                'n' => $row['param_name'],
                'k' => $row['mqtt_suffix'],
                'u' => $row['unit'],
                'd' => $row['data']
                
            ];
        }
        echo json_encode(["status"=>true, "data"=>$templates_data]);
        exit();
    }

    // 2. Get Detail Config Device (untuk Modal Edit)
  if ($action === 'get_device_config' && isset($_GET['device_unique_id'])) {

    $did = mysqli_real_escape_string($conn, $_GET['device_unique_id']);
    $settings = [];

    // ================================
    // Ambil data sensor
    // ================================
    $q_s = mysqli_query(
        $conn,
        "SELECT * 
         FROM device_settings 
         WHERE device_unique_id='$did' 
        --  AND category='sensor' 
         ORDER BY display_order ASC"
    );

    while ($s = mysqli_fetch_assoc($q_s)) {

        // cek chart
        $q_chart = mysqli_query(
            $conn,
            "SELECT chart_order, data 
             FROM user_sensor_charts 
             WHERE device_setting_id = '".$s['id']."'"
        );

        $chart_data = mysqli_fetch_assoc($q_chart);

        $s['is_chart']    = $chart_data ? true : false;
        $s['chart_order'] = $chart_data ? $chart_data['chart_order'] : 10;
        $s['chart_data']  = $chart_data ? $chart_data['data'] : '';

        $settings[] = $s;
    }

    // ================================
    // Ambil tinggi sensor (AWLR)
    // ================================
    $awlr_height = 0;
    $q_h = mysqli_query(
        $conn,
        "SELECT tinggi_sensor 
         FROM device_settings 
         WHERE device_unique_id = '$did'
         LIMIT 1"
    );

    if ($r_h = mysqli_fetch_assoc($q_h)) {
        $awlr_height = $r_h['tinggi_sensor'];
    }

    // ================================
    // Ambil timezone, status, dan type device
    // ================================
    $timezone = 'UTC';
    $statusAlat = '';
    $device_type = '';
    $lokasi = '';

    $q_device = mysqli_query(
        $conn,
        "SELECT d.timezone, d.status, d.device_type, d.location, d.city, d.owner_name, d.internet_no, d.pic, d.pic_contact, d.masa_aktif, d.masa_paket, d.waktu_add, u.email, u.username
         FROM user_devices d
         JOIN users u ON d.user_id = u.id
         WHERE d.device_unique_id = '$did'
         LIMIT 1"
    );

    if ($r_dev = mysqli_fetch_assoc($q_device)) {
        $timezone   = $r_dev['timezone'];
        $statusAlat = $r_dev['status'];
        $device_type = $r_dev['device_type'];
        $lokasi = $r_dev['location'];
        $kota = $r_dev['city'];
        
        // Extended fields
        $extended_data = [
            'owner' => $r_dev['owner_name'],
            'internet_no' => $r_dev['internet_no'],
            'pic_name' => $r_dev['pic'],
            'pic_contact' => $r_dev['pic_contact'],
            'email' => $r_dev['email'],
            'masa_aktif' => $r_dev['masa_aktif'],
            'masa_paket' => $r_dev['masa_paket'],
            'waktu_add' => $r_dev['waktu_add'],
            'username' => $r_dev['username']
        ];
    }

    // ================================
    // Ambil data automasi
    // ================================
    $automations = [];
    $q_auto = mysqli_query($conn, "SELECT id, parameter_name, operator, threshold, send_email, send_notification FROM device_automations WHERE device_unique_id = '$did' ORDER BY id ASC");
    while($auto = mysqli_fetch_assoc($q_auto)) {
        $automations[] = [
            'id' => $auto['id'],
            'parameter_name' => $auto['parameter_name'],
            'operator' => $auto['operator'],
            'threshold' => (float)$auto['threshold'],
            'send_email' => (bool)$auto['send_email'],
            'send_notification' => (bool)$auto['send_notification']
        ];
    }

    // ================================
    // Jika device AWLR → ambil config tambahan
    // ================================
    if ($device_type === 'awlr' ||$device_type === 'AWLR') {

        $q_config = mysqli_query(
            $conn,
            "SELECT parameter_name, unit 
             FROM device_settings 
             WHERE device_unique_id = '$did' 
             AND category = 'config'
             LIMIT 1"
        );
        
           $q_config2 = mysqli_query(
            $conn,
            "SELECT category
             FROM device_settings 
             WHERE device_unique_id = '$did' 
             AND mqtt_topic = 'jenis'
             LIMIT 1"
        );


        $data_config = mysqli_fetch_assoc($q_config);
        $data_config2 = mysqli_fetch_assoc($q_config2);
        echo json_encode([
            "status"          => true,
            "timezone"        => $timezone,
            "lokasi"          => $lokasi,
            "kota"            => $kota,
            "statusAlat"      => $statusAlat,
            "settings"        => $settings,
            "automations"     => $automations,
            "awlr_height"     => $awlr_height,
            "awlrData"        => $data_config['parameter_name'] ?? null,
            "awlrStatusData"  => $data_config['unit'] ?? null,
            "awlrJenis" => $data_config2['category'] ?? 'tidak di ketahui',
            ...($extended_data ?? [])
        ]);
        exit;
    }

    // ================================
    // Jika BUKAN AWLR
    // ================================
    echo json_encode([
        "status"       => true,
        "timezone"     => $timezone,
        "lokasi"          => $lokasi,
        "kota"           => $kota,
        "statusAlat"   => $statusAlat,
        "settings"     => $settings,
        "automations"  => $automations,
        "awlr_height"  => $awlr_height,
        ...($extended_data ?? [])
    ]);
    exit;
}


  // 3. Get All Users (Default)
$users = [];
$q = mysqli_query($conn, "
    SELECT 
        u.id, 
        u.username, 
        d.device_type, 
        d.device_unique_id, 
        d.owner_name, 
        d.city,
        d.status
    FROM users u 
    JOIN user_devices d ON u.id = d.user_id 
    WHERE u.role = 'user'
    ORDER BY u.id DESC
");

while ($row = mysqli_fetch_assoc($q)) {
    $users[] = $row;
}

/* =========================
   GET STATUS DEVICE SETTINGS
   ========================= */
$status = [
    "aktif"     => 0,
    "nonaktif"  => 0
];

$qs = mysqli_query($conn, "
    SELECT 
        SUM(status = 1) AS aktif,
        SUM(status = 0) AS nonaktif
    FROM user_devices
");

if ($row = mysqli_fetch_assoc($qs)) {
    $status['aktif']    = (int)$row['aktif'];
    $status['nonaktif'] = (int)$row['nonaktif'];
}

/* =========================
   RESPONSE JSON
   ========================= */
echo json_encode([
    "device_status" => $status,
    "status" => true,
    "data"   => $users
    
]);
exit();

}

// B. POST ACTIONS (Add, Update, Delete)
if ($method === 'POST') {
    /////////////////////////////////////////////////////
    // 1. ADD NEW USER & DEVICE
    if ($action === 'create_user') {
        $username = mysqli_real_escape_string($conn, $input['username']);
        $password = password_hash($input['password'], PASSWORD_DEFAULT);
        
        // Cek Username
        $cek = mysqli_query($conn, "SELECT id FROM users WHERE username='$username'");
        if(mysqli_num_rows($cek) > 0){
            echo json_encode(["status"=>false, "message"=>"Username sudah ada"]); exit();
        }

        $pic_name = mysqli_real_escape_string($conn, $input['pic_name']);
        $timezone = mysqli_real_escape_string($conn, $input['timezone']);

        $dev_name = mysqli_real_escape_string($conn, $input['dev_name']);
        $owner = mysqli_real_escape_string($conn, $input['owner']);
        $city = mysqli_real_escape_string($conn, $input['city']);
        $loc = mysqli_real_escape_string($conn, $input['lokasi']);
        $inet = mysqli_real_escape_string($conn, $input['internet_no']);
        $pic = mysqli_real_escape_string($conn, $input['pic_name']);
        $dev_type = mysqli_real_escape_string($conn, $input['dev_type']);
        $dev_id = mysqli_real_escape_string($conn, $input['dev_id']);
        $email = mysqli_real_escape_string($conn, $input['email']);
        $masa_aktif = nullIfEmpty($input['masa_aktif']);
        $masa_paket = nullIfEmpty($input['masa_paket']);
        $waktu_add  = nullIfEmpty($input['waktu_add']);
    

        // $masa_aktif = mysqli_real_escape_string($conn, $input['masa_aktif']);
        // $masa_paket = mysqli_real_escape_string($conn, $input['masa_paket']);
        // $waktu_add  = mysqli_real_escape_string($conn, $input['waktu_add']);

        // Insert User
        $conn->query("INSERT INTO users (username, password, role, email) VALUES ('$username', '$password', 'user', '$email')");
        $new_uid = $conn->insert_id;

        $sql_dev = "INSERT INTO user_devices (user_id, device_name, owner_name, city, location, internet_no, pic_contact, device_type, device_unique_id, pic, timezone, masa_aktif, masa_paket, waktu_add) 
                    VALUES ('$new_uid', '$dev_name', '$owner', '$city', '$loc', '$inet', '$pic', '$dev_type', '$dev_id', '$pic_name', '$timezone', '$masa_aktif', '$masa_paket', NOW())";
        
        if(!$conn->query($sql_dev)){
            echo json_encode(["status"=>false, "message"=>"Gagal insert device: ".$conn->error]); exit();
        }

        $is_demo = !empty($input['is_demo']) ? true : false;

        if (!$is_demo) {
            // Insert Params
        if(isset($input['params']) && is_array($input['params'])){
            foreach($input['params'] as $idx => $p){
                $label = mysqli_real_escape_string($conn, $p['label']);
                $topic = mysqli_real_escape_string($conn, $p['topic']);
                $unit  = mysqli_real_escape_string($conn, $p['unit']);
                $d_key = mysqli_real_escape_string($conn, $p['data_key']);
                $order = $idx + 1;
                
                $conn->query("INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) 
                              VALUES ('$dev_id', '$label', '$topic', '$unit', '$order', 1, 'sensor')");
                $sid = $conn->insert_id;

                if(!empty($d_key)){
                    $conn->query("INSERT INTO user_sensor_charts (user_id, device_unique_id, device_setting_id, chart_order, is_active, data) 
                                  VALUES ('$new_uid', '$dev_id', '$sid', '$order', 1, '$d_key')");
                }
            }
        }

        // Insert Automations
        if(isset($input['automations']) && is_array($input['automations'])){
            foreach($input['automations'] as $auto){
                $param_name = mysqli_real_escape_string($conn, $auto['parameter_name']);
                $operator = mysqli_real_escape_string($conn, $auto['operator']);
                $threshold = (float)$auto['threshold'];
                $send_email = !empty($auto['send_email']) ? 1 : 0;
                $send_notification = !empty($auto['send_notification']) ? 1 : 0;
                
                $conn->query("INSERT INTO device_automations (device_unique_id, parameter_name, operator, threshold, send_email, send_notification) 
                              VALUES ('$dev_id', '$param_name', '$operator', $threshold, $send_email, $send_notification)");
            }
        }

        // Default Power Settings
        $conn->query("INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) VALUES ('$dev_id', 'Tegangan', 'temins_iot/$dev_id/data/tsp', 'V', 100, 1, 'power')");
        $conn->query("INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) VALUES ('$dev_id', 'Arus Charging', 'temins_iot/$dev_id/data/ac', 'mA', 101, 1, 'power')");
         $conn->query("INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) VALUES ('$dev_id', 'daya', 'temins_iot/$dev_id/data/da', 'watt', 101, 1, 'power')");

        // AWLR Config
        if(strtoupper($dev_type) == 'AWLR') {
             $conn->query("INSERT INTO device_settings (device_unique_id, parameter_name,  tinggi_sensor, unit, is_visible, category, mqtt_topic) VALUES ('$dev_id', 'tuc', '400', '1', 0, 'config', 'config')");
             $conn->query("INSERT INTO device_settings (device_unique_id, parameter_name,  tinggi_sensor, unit, is_visible, category, mqtt_topic) VALUES ('$dev_id', 'tuc', '400', '1', 0, 'sungai', 'jenis')");
        } 
        } // end if (!$is_demo)

        echo json_encode(["status"=>true, "message"=>"User berhasil dibuat"]);
        exit();
    }

   if ($action === 'update_config') {           //updateeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee

    $dev_id = mysqli_real_escape_string($conn, $input['device_unique_id']);
    $uid    = mysqli_real_escape_string($conn, $input['user_id']);
    $params = $input['params']; // Array of objects

    /* =========================
       UPDATE TIMEZONE DEVICE
       ========================= */
if (
    isset($input['timezone']) && !empty($input['timezone']) &&
    isset($input['statusAlat']) && !empty($input['statusAlat']) &&
    isset($input['lokasi']) &&
    isset($input['username']) && !empty($input['username'])
) {

    $timezone   = $input['timezone'];
    $statusAlat = $input['statusAlat'];
    $lokasi     = $input['lokasi'];
    $kota       = $input['city'];
    $username   = $input['username'];
    
    // New fields
    $owner = $input['owner'];
    $internet_no = $input['internet_no'];
    $pic_name = $input['pic_name'];       // Mapped to 'pic' column in DB
    $pic_contact = $input['pic_contact']; // Mapped to 'pic_contact' column or similar
    
    $masa_aktif = nullIfEmpty($input['masa_aktif']);
    $masa_paket = nullIfEmpty($input['masa_paket']);
    $waktu_add = nullIfEmpty($input['waktu_add']);
    $email = $input['email'];

    // Update user_devices
    // Columns: timezone, status, location, city, owner_name, internet_no, pic, pic_contact, masa_aktif, masa_paket, waktu_add
    $stmt = $conn->prepare("
        UPDATE user_devices 
        SET timezone = ?, status = ?, location = ?, city = ?, 
            owner_name = ?, internet_no = ?, pic = ?, pic_contact = ?, 
            masa_aktif = ?, masa_paket = ?, waktu_add = ?
        WHERE device_unique_id = ?
    ");
    $stmt->bind_param("ssssssssssss", $timezone, $statusAlat, $lokasi, $kota, 
                      $owner, $internet_no, $pic_name, $pic_contact, 
                      $masa_aktif, $masa_paket, $waktu_add, $dev_id);
    $stmt->execute();

    // Ambil user_id
    $stmt2 = $conn->prepare("SELECT user_id FROM user_devices WHERE device_unique_id = ?");
    $stmt2->bind_param("s", $dev_id);
    $stmt2->execute();
    $result = $stmt2->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_id = $row['user_id'];

        // Update username & email
        $stmt3 = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
        $stmt3->bind_param("ssi", $username, $email, $user_id);
        $stmt3->execute();
    }
}



/////////////update baris data awlr dan status


if (
    isset($input['awlrData'], $input['awlrStatusData'], $input['awlrJenis'])
) {
    $parameter_name = $input['awlrData'];
    $unit           = $input['awlrStatusData'];
    $awlrJenis      = $input['awlrJenis'];

    $conn->begin_transaction();

    try {
        // 1. Update config
        $stmt1 = $conn->prepare("
            UPDATE device_settings
            SET parameter_name = ?, unit = ?
            WHERE device_unique_id = ?
              AND category = 'config'
            LIMIT 1
        ");
        $stmt1->bind_param("sss", $parameter_name, $unit, $dev_id);
        $stmt1->execute();

        // 2. Cek apakah mqtt_topic = 'jenis' sudah ada
        $check = $conn->prepare("
            SELECT id FROM device_settings
            WHERE device_unique_id = ?
              AND mqtt_topic = 'jenis'
            LIMIT 1
        ");
        $check->bind_param("s", $dev_id);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            // UPDATE
            $update = $conn->prepare("
                UPDATE device_settings
                SET category = ?
                WHERE device_unique_id = ?
                  AND mqtt_topic = 'jenis'
                LIMIT 1
            ");
            $update->bind_param("ss", $awlrJenis, $dev_id);
            $update->execute();
        } else {
            // INSERT
            $insert = $conn->prepare("
                INSERT INTO device_settings
                (device_unique_id, parameter_name, tinggi_sensor, unit, is_visible, category, mqtt_topic)
                VALUES (?, 'tuc', '400', '1', 0, ?, 'jenis')
            ");
            $insert->bind_param("ss", $dev_id, $awlrJenis);
            $insert->execute();
        }

        // 3. Commit sekali saja
        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log($e->getMessage());
    }
}



    /* =========================
       UPDATE AWLR HEIGHT
       ========================= */
    if (isset($input['awlr_height'])) {
        $h = mysqli_real_escape_string($conn, $input['awlr_height']);

        $conn->query("
            UPDATE device_settings 
            SET tinggi_sensor = '$h'
            WHERE device_unique_id = '$dev_id'
        ");
    }

    /* =========================
       RESET CHART CONFIG
       ========================= */
    $conn->query("
        DELETE FROM user_sensor_charts 
        WHERE device_unique_id = '$dev_id'
    ");

    /* =========================
       UPDATE / INSERT PARAMS
       ========================= */
    $processed_ids = [];

    foreach ($params as $idx => $p) {

        $label = mysqli_real_escape_string($conn, $p['label']);
        $topic = mysqli_real_escape_string($conn, $p['topic']);
        $unit  = mysqli_real_escape_string($conn, $p['unit']);
        $vis   = !empty($p['is_visible']) ? 1 : 0;
        $order = $idx + 1;

        $setting_id = 0;

        if (!empty($p['id'])) {
            // Update Existing Parameter
            $sid = mysqli_real_escape_string($conn, $p['id']);

            $conn->query("
                UPDATE device_settings 
                SET 
                    parameter_name = '$label',
                    mqtt_topic     = '$topic',
                    unit           = '$unit',
                    is_visible     = '$vis',
                    display_order  = '$order'
                WHERE id = '$sid'
            ");

            $setting_id = $sid;
            $processed_ids[] = $sid;

        } else {
            // Insert New Parameter
            $conn->query("
                INSERT INTO device_settings 
                (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category)
                VALUES 
                ('$dev_id', '$label', '$topic', '$unit', '$order', '$vis', 'sensor')
            ");

            $setting_id = $conn->insert_id;
        }

        /* =========================
           HANDLE CHART
           ========================= */
        if (!empty($p['is_chart'])) {

            $d_key = mysqli_real_escape_string($conn, $p['chart_data']);
            $c_ord = (int)$p['chart_order'];

            $conn->query("
                INSERT INTO user_sensor_charts 
                (user_id, device_unique_id, device_setting_id, chart_order, is_active, data)
                VALUES 
                ('$uid', '$dev_id', '$setting_id', '$c_ord', 1, '$d_key')
            ");
        }
    }

    /* =========================
       UPDATE AUTOMATIONS
       ========================= */
    $conn->query("
        DELETE FROM device_automations 
        WHERE device_unique_id = '$dev_id'
    ");

    if (isset($input['automations']) && is_array($input['automations'])) {
        foreach ($input['automations'] as $auto) {
            $param_name = mysqli_real_escape_string($conn, $auto['parameter_name']);
            $operator = mysqli_real_escape_string($conn, $auto['operator']);
            $threshold = (float)$auto['threshold'];
            $send_email = !empty($auto['send_email']) ? 1 : 0;
            $send_notification = !empty($auto['send_notification']) ? 1 : 0;
            
            $conn->query("INSERT INTO device_automations (device_unique_id, parameter_name, operator, threshold, send_email, send_notification) 
                          VALUES ('$dev_id', '$param_name', '$operator', $threshold, $send_email, $send_notification)");
        }
    }

    echo json_encode([
        "status"  => true,
        "message" => "Konfigurasi device berhasil diupdate",
        "username" => $username
    ]);
    exit();
}


    // 3. DELETE PARAMETER (Specific)
    if ($action === 'delete_param') {
        $id = mysqli_real_escape_string($conn, $input['id']);
        $conn->query("DELETE FROM user_sensor_charts WHERE device_setting_id='$id'");
        $conn->query("DELETE FROM device_settings WHERE id='$id'");
        echo json_encode(["status"=>true, "message"=>"Parameter dihapus"]);
        exit();
    }

    // 4. CHANGE PASSWORD
    if ($action === 'change_password') {
        $uid = $input['user_id'];
        $pass = password_hash($input['new_password'], PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password='$pass' WHERE id='$uid'");
        echo json_encode(["status"=>true, "message"=>"Password diubah"]);
        exit();
    }

    // 5. DELETE USER (Full)
    if ($action === 'delete_user') {
        $uid = mysqli_real_escape_string($conn, $input['user_id']);
        $did = mysqli_real_escape_string($conn, $input['device_unique_id']);
        
        $conn->query("DELETE FROM user_sensor_charts WHERE device_unique_id='$did'");
        $conn->query("DELETE FROM sensor_logs WHERE device_unique_id='$did'");
        $conn->query("DELETE FROM device_settings WHERE device_unique_id='$did'");
        $conn->query("DELETE FROM device_automations WHERE device_unique_id='$did'");
        $conn->query("DELETE FROM user_devices WHERE user_id='$uid'");
        $conn->query("DELETE FROM users WHERE id='$uid'");
        
        echo json_encode(["status"=>true, "message"=>"User dihapus total"]);
        exit();
    }
}




function nullIfEmpty($value) {
    if (!isset($value)) return null;
    
    // trim supaya '   ' juga dianggap kosong
    $value = trim($value);
    
    return $value === '' ? null : $value;
}

?>

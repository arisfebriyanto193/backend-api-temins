<?php
// api.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle Preflight Request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dataFile = '/rekam-data/py/1.json';

// --- SETUP ---
if (!is_dir(dirname($dataFile))) {
    mkdir(dirname($dataFile), 0777, true);
}

if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode(["device_type" => []], JSON_PRETTY_PRINT)); // Fix empty array structure
}

// Read Data
$data = json_decode(file_get_contents($dataFile), true);
if (!$data) $data = ["device_type" => []]; // Fallback

function saveData($data, $file) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// --- HANDLE REQUESTS ---
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(['status' => true, 'data' => $data]);
    exit();
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $message = '';
    $status = true;

    switch ($action) {
        case 'add_device_type':
            $type = trim($input['device_type']);
            if (!empty($type) && !isset($data['device_type'][$type])) {
                // Initialize as object not array to preserve JSON structure in PHP
                $data['device_type'][$type] = ['def_topic' => [], 'devices' => []];
                $message = "Tipe perangkat '$type' berhasil dibuat.";
            } else {
                $status = false;
                $message = "Gagal: Nama kosong atau sudah ada.";
            }
            break;

        case 'update_def_topic':
            $type = $input['device_type'];
            // Convert comma separated string to array if needed, or accept array
            $topics = is_array($input['def_topic']) ? $input['def_topic'] : array_values(array_filter(array_map('trim', explode(',', $input['def_topic']))));
            
            if(isset($data['device_type'][$type])) {
                $data['device_type'][$type]['def_topic'] = $topics;
                $message = "Default topik untuk '$type' diperbarui.";
            }
            break;

        case 'add_device':
            $type = $input['device_type'];
            $devId = trim($input['dev_id']);
            
            if(isset($data['device_type'][$type])) {
                $exists = false;
                foreach($data['device_type'][$type]['devices'] as $d) {
                    if($d['dev_id'] === $devId) { $exists = true; break; }
                }

                if (!empty($devId) && !$exists) {
                    $data['device_type'][$type]['devices'][] = [
                        'dev_id' => $devId,
                        'use_default' => true,
                        'topic' => []
                    ];
                    $message = "Device '$devId' ditambahkan.";
                } else {
                    $status = false;
                    $message = "Gagal: Device ID kosong atau sudah ada.";
                }
            }
            break;

        case 'update_device':
            $type = $input['device_type'];
            $index = (int)$input['index'];
            $newDevId = trim($input['new_dev_id']);
            $useDefault = $input['use_default']; // boolean

            if (isset($data['device_type'][$type]['devices'][$index])) {
                if (!empty($newDevId)) {
                    $data['device_type'][$type]['devices'][$index]['dev_id'] = $newDevId;
                }

                if ($useDefault) {
                    $data['device_type'][$type]['devices'][$index]['topic'] = [];
                    $data['device_type'][$type]['devices'][$index]['use_default'] = true;
                } else {
                    $rawTopic = $input['topic']; // Can be array or string
                    $topics = is_array($rawTopic) ? $rawTopic : array_values(array_filter(array_map('trim', explode(',', $rawTopic))));
                    
                    $data['device_type'][$type]['devices'][$index]['use_default'] = false;
                    $data['device_type'][$type]['devices'][$index]['topic'] = $topics;
                }
                $message = "Konfigurasi device diperbarui.";
            }
            break;

        case 'delete_device':
            $type = $input['device_type'];
            $index = (int)$input['index'];
            if (isset($data['device_type'][$type]['devices'][$index])) {
                array_splice($data['device_type'][$type]['devices'], $index, 1);
                $message = "Device berhasil dihapus.";
            }
            break;
            
        case 'delete_device_type':
            $type = $input['device_type'];
            if(isset($data['device_type'][$type])) {
                unset($data['device_type'][$type]);
                $message = "Tipe perangkat '$type' dihapus.";
            }
            break;
            
        default:
            $status = false;
            $message = "Action tidak valid.";
    }

    if ($status) {
        saveData($data, $dataFile);
    }

    echo json_encode(['status' => $status, 'message' => $message]);
    exit();
}
?>

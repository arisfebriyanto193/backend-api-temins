<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once "../config.php";

/* ==============================
   PARAMETER
============================== */
$device_id = $_GET['device_id'] ?? null;
$jenis     = $_GET['jenis'] ?? null;
$periode   = $_GET['periode'] ?? 'hari';
$mode      = $_GET['mode'] ?? 'raw';
$tahun     = $_GET['tahun'] ?? date('Y');
$bulan     = $_GET['bulan'] ?? date('m');
$tanggal   = $_GET['tanggal'] ?? null;     // YYYY-MM-DD
$valueMode = $_GET['value'] ?? null;       // high | low | avg

if (!$device_id) {
    echo json_encode([
        "status" => false,
        "message" => "device_id wajib diisi"
    ]);
    exit;
}

$sql = "";
$whereTime = "";



/* ==============================
   🔥 MODE NOW (DATA TERBARU SEMUA SENSOR)
============================== */
if ($periode === 'now') {

    $sql = "
        SELECT s1.id,
               s1.device_unique_id,
               s1.parameter_name,
               s1.value,
               s1.recorded_at
        FROM sensor_logs s1
        INNER JOIN (
            SELECT parameter_name, MAX(recorded_at) AS latest_time
            FROM sensor_logs
            WHERE device_unique_id = :device_id
            GROUP BY parameter_name
        ) s2
        ON s1.parameter_name = s2.parameter_name
        AND s1.recorded_at = s2.latest_time
        WHERE s1.device_unique_id = :device_id
        ORDER BY s1.parameter_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(":device_id", $device_id);
    $stmt->execute();

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "filter" => "now",
        "mode"   => "latest",
        "total"  => count($data),
        "data"   => $data
    ]);
    exit;
}

/* ==============================
   1️⃣ MODE VALUE (HIGH / LOW / AVG)
============================== */
if ($valueMode) {

    switch ($valueMode) {
        case 'high':
            $agg = "MAX(value)";
            break;
        case 'low':
            $agg = "MIN(value)";
            break;
        case 'avg':
            $agg = "AVG(value)";
            break;
        default:
            echo json_encode([
                "status" => false,
                "message" => "value hanya boleh high | low | avg"
            ]);
            exit;
    }

    // FILTER WAKTU
    if ($tanggal) {
        $whereTime = "
            recorded_at BETWEEN 
            CONCAT(:tanggal, ' 00:00:00')
            AND CONCAT(:tanggal, ' 23:59:59')
        ";
    } else {
        $whereTime = "recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    }

    $sql = "
        SELECT 
            device_unique_id,
            parameter_name,
            ROUND($agg, 2) AS value,
            '$valueMode' AS mode,
            MIN(recorded_at) AS from_time,
            MAX(recorded_at) AS to_time
        FROM sensor_logs
        WHERE device_unique_id = :device_id
          AND parameter_name = :jenis
          AND $whereTime
    ";
}

/* ==============================
   2️⃣ MODE TANGGAL / PERIODE
============================== */
else {

    if ($tanggal) {

        if ($mode === 'ringkas') {
            $sql = "
                SELECT 
                    MIN(id) AS id,
                    device_unique_id,
                    parameter_name,
                    ROUND(AVG(value), 2) AS value,
                    DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00') AS recorded_at
                FROM sensor_logs
                WHERE device_unique_id = :device_id
                  AND parameter_name = :jenis
                  AND recorded_at BETWEEN 
                      CONCAT(:tanggal, ' 00:00:00')
                  AND CONCAT(:tanggal, ' 23:59:59')
                GROUP BY DATE_FORMAT(recorded_at, '%Y-%m-%d %H')
                ORDER BY recorded_at ASC
            ";
        } else {
            $whereTime = "
                recorded_at BETWEEN 
                CONCAT(:tanggal, ' 00:00:00')
                AND CONCAT(:tanggal, ' 23:59:59')
            ";
        }

    } else {

        switch ($periode) {

            case 'hari':
                if ($mode === 'ringkas') {
                    $sql = "
                        SELECT 
                            MIN(id) AS id,
                            device_unique_id,
                            parameter_name,
                            ROUND(AVG(value), 2) AS value,
                            DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00') AS recorded_at
                        FROM sensor_logs
                        WHERE device_unique_id = :device_id
                          AND parameter_name = :jenis
                          AND recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        GROUP BY DATE_FORMAT(recorded_at, '%Y-%m-%d %H')
                        ORDER BY recorded_at ASC
                    ";
                } else {
                    $whereTime = "recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                }
                break;

            case 'minggu_ini':
                if ($mode === 'ringkas') {
                    $sql = "
                        SELECT 
                            MIN(id) AS id,
                            device_unique_id,
                            parameter_name,
                            ROUND(AVG(value), 2) AS value,
                            DATE(recorded_at) AS recorded_at
                        FROM sensor_logs
                        WHERE device_unique_id = :device_id
                          AND parameter_name = :jenis
                          AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                        GROUP BY DATE(recorded_at)
                        ORDER BY recorded_at ASC
                    ";
                } else {
                    $whereTime = "recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                }
                break;

            case 'bulan':
                if ($mode === 'ringkas') {
                    $sql = "
                        SELECT 
                            MIN(id) AS id,
                            device_unique_id,
                            parameter_name,
                            ROUND(AVG(value), 2) AS value,
                            DATE(recorded_at) AS recorded_at
                        FROM sensor_logs
                        WHERE device_unique_id = :device_id
                          AND parameter_name = :jenis
                          AND YEAR(recorded_at) = :tahun
                          AND MONTH(recorded_at) = :bulan
                        GROUP BY DATE(recorded_at)
                        ORDER BY recorded_at ASC
                    ";
                } else {
                    $whereTime = "YEAR(recorded_at) = :tahun AND MONTH(recorded_at) = :bulan";
                }
                break;

            default:
                echo json_encode([
                    "status" => false,
                    "message" => "Periode tidak valid"
                ]);
                exit;
        }
    }
}

/* ==============================
   RAW QUERY
============================== */
if (!$valueMode && $mode === 'raw') {

    if (!$whereTime) {
        echo json_encode([
            "status" => false,
            "message" => "Filter waktu tidak valid"
        ]);
        exit;
    }

    $sql = "
        SELECT 
            id,
            device_unique_id,
            parameter_name,
            value,
            recorded_at
        FROM sensor_logs
        WHERE device_unique_id = :device_id
          AND parameter_name = :jenis
          AND $whereTime
        ORDER BY recorded_at ASC
    ";
}

/* ==============================
   EXECUTE
============================== */
$stmt = $pdo->prepare($sql);
$stmt->bindParam(":device_id", $device_id);
$stmt->bindParam(":jenis", $jenis);

if ($tanggal) {
    $stmt->bindParam(":tanggal", $tanggal);
}

if ($periode === 'bulan' && !$tanggal && !$valueMode) {
    $stmt->bindParam(":tahun", $tahun);
    $stmt->bindParam(":bulan", $bulan);
}

$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ==============================
   RESPONSE
============================== */
echo json_encode([
    "status" => true,
    "filter" => $valueMode ?? ($tanggal ? "tanggal" : $periode),
    "mode"   => $valueMode ?? $mode,
    "total"  => count($data),
    "data"   => $data
]);

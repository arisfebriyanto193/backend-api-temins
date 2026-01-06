<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS"); // Tambahkan OPTIONS
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once "../db/configpg.php";

/* ==============================
   PARAMETER
============================== */
$device_id = $_GET['device_id'] ?? null;
$jenis     = $_GET['jenis'] ?? null;
$periode   = $_GET['periode'] ?? 'hari';
$mode      = $_GET['mode'] ?? 'raw';
$tahun     = $_GET['tahun'] ?? date('Y');
$bulan     = $_GET['bulan'] ?? date('m');
$tanggal   = $_GET['tanggal'] ?? null;
$valueMode = $_GET['value'] ?? null;

if (!$device_id) {
    echo json_encode([
        "status" => false,
        "message" => "device_id wajib diisi"
    ]);
    exit;
}

$sql = "";
$whereTime = "";

// Sintaks Timezone untuk digunakan berulang
// Asumsi data di DB disimpan sebagai UTC tanpa timezone
$tzQuery = "recorded_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Jakarta'";

/* ==============================
   🔥 MODE NOW (DATA TERBARU)
============================== */
if ($periode === 'now') {

    $sql = "
        SELECT s1.id,
               s1.device_unique_id,
               s1.parameter_name,
               s1.value,
               ($tzQuery) AS recorded_at
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

    echo json_encode([
        "status" => true,
        "filter" => "now",
        "mode"   => "latest",
        "total"  => $stmt->rowCount(),
        "data"   => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;
}

/* ==============================
   1️⃣ MODE VALUE (HIGH / LOW / AVG)
============================== */
if ($valueMode) {

    switch ($valueMode) {
        case 'high': $agg = "MAX(value)"; break;
        case 'low':  $agg = "MIN(value)"; break;
        case 'avg':  $agg = "AVG(value)"; break;
        default:
            echo json_encode(["status"=>false,"message"=>"value hanya high | low | avg"]);
            exit;
    }

    if ($tanggal) {
        // Filter tetap menggunakan waktu server (biasanya UTC) agar index tetap jalan
        $whereTime = "
            recorded_at BETWEEN 
            (:tanggal || ' 00:00:00')::timestamp
            AND (:tanggal || ' 23:59:59')::timestamp
        ";
    } else {
        $whereTime = "recorded_at >= NOW() - INTERVAL '24 HOURS'";
    }

    $sql = "
        SELECT 
            device_unique_id,
            parameter_name,
            ROUND(($agg)::numeric, 2) AS value,
            '$valueMode' AS mode,
            MIN($tzQuery) AS from_time,
            MAX($tzQuery) AS to_time
        FROM sensor_logs
        WHERE device_unique_id = :device_id
          AND parameter_name = :jenis
          AND $whereTime
        GROUP BY device_unique_id, parameter_name
    ";
}

/* ==============================
   2️⃣ MODE PERIODE
============================== */
else {

    if ($tanggal) {

        if ($mode === 'ringkas') {
            // Group by Hour (WIB)
            $sql = "
                SELECT 
                    MIN(id) AS id,
                    device_unique_id,
                    parameter_name,
                    ROUND(AVG(value)::numeric, 2) AS value,
                    DATE_TRUNC('hour', $tzQuery) AS recorded_at
                FROM sensor_logs
                WHERE device_unique_id = :device_id
                  AND parameter_name = :jenis
                  AND recorded_at BETWEEN 
                      (:tanggal || ' 00:00:00')::timestamp
                  AND (:tanggal || ' 23:59:59')::timestamp
                GROUP BY device_unique_id, parameter_name, DATE_TRUNC('hour', $tzQuery)
                ORDER BY recorded_at ASC
            ";
        } else {
            $whereTime = "
                recorded_at BETWEEN 
                (:tanggal || ' 00:00:00')::timestamp
                AND (:tanggal || ' 23:59:59')::timestamp
            ";
        }

    } else {

        switch ($periode) {

            case 'hari':
                if ($mode === 'ringkas') {
                    // Group by Hour (WIB)
                    $sql = "
                        SELECT 
                            MIN(id) AS id,
                            device_unique_id,
                            parameter_name,
                            ROUND(AVG(value)::numeric, 2) AS value,
                            DATE_TRUNC('hour', $tzQuery) AS recorded_at
                        FROM sensor_logs
                        WHERE device_unique_id = :device_id
                          AND parameter_name = :jenis
                          AND recorded_at >= NOW() - INTERVAL '24 HOURS'
                        GROUP BY device_unique_id, parameter_name, DATE_TRUNC('hour', $tzQuery)
                        ORDER BY recorded_at ASC
                    ";
                } else {
                    $whereTime = "recorded_at >= NOW() - INTERVAL '24 HOURS'";
                }
                break;

            case 'minggu_ini':
                if ($mode === 'ringkas') {
                    // Group by Date (WIB)
                    $sql = "
                        SELECT 
                            MIN(id) AS id,
                            device_unique_id,
                            parameter_name,
                            ROUND(AVG(value)::numeric, 2) AS value,
                            DATE($tzQuery) AS recorded_at
                        FROM sensor_logs
                        WHERE device_unique_id = :device_id
                          AND parameter_name = :jenis
                          AND recorded_at >= CURRENT_DATE - INTERVAL '6 DAYS'
                        GROUP BY device_unique_id, parameter_name, DATE($tzQuery)
                        ORDER BY recorded_at ASC
                    ";
                } else {
                    $whereTime = "recorded_at >= NOW() - INTERVAL '7 DAYS'";
                }
                break;

            case 'bulan':
                if ($mode === 'ringkas') {
                    // Group by Date (WIB)
                    $sql = "
                        SELECT 
                            MIN(id) AS id,
                            device_unique_id,
                            parameter_name,
                            ROUND(AVG(value)::numeric, 2) AS value,
                            DATE($tzQuery) AS recorded_at
                        FROM sensor_logs
                        WHERE device_unique_id = :device_id
                          AND parameter_name = :jenis
                          AND EXTRACT(YEAR FROM recorded_at) = :tahun
                          AND EXTRACT(MONTH FROM recorded_at) = :bulan
                        GROUP BY device_unique_id, parameter_name, DATE($tzQuery)
                        ORDER BY recorded_at ASC
                    ";
                } else {
                    $whereTime = "
                        EXTRACT(YEAR FROM recorded_at) = :tahun
                        AND EXTRACT(MONTH FROM recorded_at) = :bulan
                    ";
                }
                break;

            default:
                echo json_encode(["status"=>false,"message"=>"Periode tidak valid"]);
                exit;
        }
    }
}

/* ==============================
   RAW MODE (DEFAULT)
============================== */
if (!$valueMode && $mode === 'raw') {

    if (!$whereTime) {
        echo json_encode(["status"=>false,"message"=>"Filter waktu tidak valid"]);
        exit;
    }

    // Ambil data mentah tapi jamnya sudah dikonversi
    $sql = "
        SELECT id, device_unique_id, parameter_name, value, 
               ($tzQuery) AS recorded_at
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
    "timezone" => "Asia/Jakarta", // Info tambahan di JSON response
    "total"  => count($data),
    "data"   => $data
]);
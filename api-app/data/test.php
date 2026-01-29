<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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
$bulan     = $_GET['bulan'] ?? null; // Bisa format "12-21-2025" atau "12"
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
$tzQuery = "recorded_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Jakarta'";

/* ==============================
   🔥 MODE ALL PARAMETERS (TANPA JENIS)
   Query: ?device_id=0021
   atau: ?device_id=0021&bulan=12-21-2025
============================== */
if (!$jenis && !$valueMode && $periode === 'hari') {
    
    // Jika ada parameter bulan dengan format MM-DD-YYYY atau MM-YYYY
    if ($bulan) {
        // Parse format bulan
        $bulanParts = explode('-', $bulan);
        
        if (count($bulanParts) == 3) {
            // Format: MM-DD-YYYY (bulan tertentu di tahun tertentu)
            $bulanNum = $bulanParts[0];
            $tahunNum = $bulanParts[2];
            $whereTime = "
                EXTRACT(YEAR FROM recorded_at) = :tahun
                AND EXTRACT(MONTH FROM recorded_at) = :bulan
            ";
        } elseif (count($bulanParts) == 2) {
            // Format: MM-YYYY
            $bulanNum = $bulanParts[0];
            $tahunNum = $bulanParts[1];
            $whereTime = "
                EXTRACT(YEAR FROM recorded_at) = :tahun
                AND EXTRACT(MONTH FROM recorded_at) = :bulan
            ";
        } else {
            // Format: MM saja (bulan di tahun sekarang)
            $bulanNum = $bulan;
            $tahunNum = date('Y');
            $whereTime = "
                EXTRACT(YEAR FROM recorded_at) = :tahun
                AND EXTRACT(MONTH FROM recorded_at) = :bulan
            ";
        }
        
        $sql = "
            SELECT id, 
                   device_unique_id, 
                   parameter_name, 
                   value, 
                   ($tzQuery) AS recorded_at
            FROM sensor_logs
            WHERE device_unique_id = :device_id
              AND $whereTime
            ORDER BY recorded_at DESC, parameter_name ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":device_id", $device_id);
        $stmt->bindParam(":tahun", $tahunNum);
        $stmt->bindParam(":bulan", $bulanNum);
        $stmt->execute();
        
        echo json_encode([
            "status" => true,
            "filter" => "all_parameters_by_month",
            "mode"   => "raw",
            "timezone" => "Asia/Jakarta",
            "device_id" => $device_id,
            "month" => $bulanNum,
            "year" => $tahunNum,
            "total"  => $stmt->rowCount(),
            "data"   => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
        exit;
        
    } else {
        // Tampilkan semua data dari semua parameter (default 24 jam terakhir)
        $sql = "
            SELECT id, 
                   device_unique_id, 
                   parameter_name, 
                   value, 
                   ($tzQuery) AS recorded_at
            FROM sensor_logs
            WHERE device_unique_id = :device_id
              AND recorded_at >= NOW() - INTERVAL '24 HOURS'
            ORDER BY recorded_at DESC, parameter_name ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":device_id", $device_id);
        $stmt->execute();
        
        echo json_encode([
            "status" => true,
            "filter" => "all_parameters",
            "mode"   => "raw",
            "timezone" => "Asia/Jakarta",
            "device_id" => $device_id,
            "time_range" => "24_hours",
            "total"  => $stmt->rowCount(),
            "data"   => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
        exit;
    }
}

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
                // Parse bulan jika formatnya MM-DD-YYYY atau MM-YYYY
                if ($bulan && strpos($bulan, '-') !== false) {
                    $bulanParts = explode('-', $bulan);
                    if (count($bulanParts) >= 2) {
                        $bulan = $bulanParts[0];
                        $tahun = $bulanParts[count($bulanParts) - 1];
                    }
                } else {
                    $bulan = $bulan ?? date('m');
                }
                
                if ($mode === 'ringkas') {
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
    "timezone" => "Asia/Jakarta",
    "total"  => count($data),
    "data"   => $data
]);
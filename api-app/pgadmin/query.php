<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

/*
|--------------------------------------------------------------------------
| CONFIG DATABASE
|--------------------------------------------------------------------------
*/
$host     = "localhost";
$port     = "5432";
$dbname   = "temins";
$user     = "postgres";
$password = "example";

/*
|--------------------------------------------------------------------------
| CONNECT TO POSTGRESQL
|--------------------------------------------------------------------------
*/
$conn = pg_connect("
    host=$host
    port=$port
    dbname=$dbname
    user=$user
    password=$password
");

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        "error" => "Database connection failed"
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| READ REQUEST BODY
|--------------------------------------------------------------------------
*/
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['query']) || empty(trim($data['query']))) {
    http_response_code(400);
    echo json_encode([
        "error" => "Query is required"
    ]);
    exit;
}

$query = $data['query'];

/*
|--------------------------------------------------------------------------
| EXECUTE QUERY
|--------------------------------------------------------------------------
*/
$result = @pg_query($conn, $query);

if (!$result) {
    http_response_code(400);
    echo json_encode([
        "error" => pg_last_error($conn)
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| HANDLE RESULT
|--------------------------------------------------------------------------
*/
if (pg_num_rows($result) > 0) {
    $rows = [];
    while ($row = pg_fetch_assoc($result)) {
        $rows[] = $row;
    }

    echo json_encode([
        "success" => true,
        "type" => "select",
        "rows" => $rows
    ]);
} else {
    echo json_encode([
        "success" => true,
        "type" => "non-select",
        "affected_rows" => pg_affected_rows($result)
    ]);
}

pg_close($conn);
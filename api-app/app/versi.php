<?php
header('Content-Type: application/json');

require_once "../db/sql.php"; 

$query = "SELECT versi, url FROM app_configs WHERE id = 1";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $response = [
        "status" => true,
        "versi" => $row['versi'],
        "url" => $row['url']
    ];
} else {
    $response = [
        "status" => false,
        "versi" => "",
        "url" => ""
    ];
}

$conn->close();
echo json_encode($response);

?>

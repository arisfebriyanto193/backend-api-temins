<?php
header('Content-Type: application/json');

$response = [
    "status" => true,
    "versi" => "1.0.0",
    "url" => "https://expo.dev/artifacts/eas/k2AHh4XoV9NCYRymVHZ2Ki.apk"
];

echo json_encode($response);

?>

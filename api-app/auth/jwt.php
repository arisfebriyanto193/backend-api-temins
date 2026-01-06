<?php
// PENTING: Jangan ada spasi atau baris kosong sebelum <?php

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// 1. FUNGSI MEMBUAT TOKEN (Dipakai saat Login)
function generate_jwt($payload, $secret = "MY_SECRET_KEY") {
    $header = base64url_encode(json_encode(["alg" => "HS256", "typ" => "JWT"]));
    $payload = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac("sha256", "$header.$payload", $secret, true));
    return "$header.$payload.$signature";
}

// 2. FUNGSI CEK TOKEN (Dipakai saat akses API Relay/Sensor)
// Nama fungsi disamakan menjadi verify_jwt agar sinkron dengan file lain
function verify_jwt($jwt, $secret = "MY_SECRET_KEY") {
    $parts = explode(".", $jwt);
    if (count($parts) != 3) return false;

    list($header, $payload, $signature) = $parts;
    
    // Cek Signature (Keaslian Token)
    $valid = base64url_encode(hash_hmac("sha256", "$header.$payload", $secret, true));

    if ($signature != $valid) return false;

    // Decode Payload
    $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

    // Cek Expired (Kadaluarsa)
    if (isset($decoded['exp']) && $decoded['exp'] < time()) {
        return false; // Token sudah kadaluarsa
    }

    return $decoded; // Kembalikan data user (id, email, dll)
}
?>
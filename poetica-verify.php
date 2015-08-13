<?php
$json = file_get_contents('php://input');
$obj = json_decode($json);

if (!isset($obj->verification_token)) {
    exit(http_response_code(500));
}

if ($obj->verification_token === get_option('poetica_verification_token')) {
    http_response_code(200);
} else {
    http_response_code(400);
}
?>

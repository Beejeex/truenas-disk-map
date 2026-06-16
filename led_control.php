<?php
require_once __DIR__ . "/hardware_helpers.php";

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Use POST.\n");
}

$cmd = isset($_POST['cmd']) ? trim($_POST['cmd']) : '';
if ($cmd === '') {
    http_response_code(400);
    exit("Missing cmd.\n");
}


$parsed = tdm_parse_sg_ses_command($cmd);
if ($parsed === null) {
    http_response_code(400);
    exit("Command not allowed. Expected generated sg_ses identify command.\n");
}

$code = 0;
$body = trim(tdm_exec_sg_ses($parsed['ses_device'], $parsed['slot'], $parsed['action'], $code));

if ($code === 0) {
    echo "Command executed successfully.\n";
    if ($body !== '') echo $body . "\n";
    echo "CMD: $cmd\n"; 
} else {
    http_response_code(500);
    echo "Error (exit $code)\n";
    if ($body !== '') echo $body . "\n";
    echo "CMD: $cmd\n";
}
?>

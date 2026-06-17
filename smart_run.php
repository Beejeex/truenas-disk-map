<?php
// smart_run.php
require_once __DIR__ . "/hardware_helpers.php";

header('Content-Type: text/plain; charset=utf-8');

$dev = isset($_POST['device']) ? trim($_POST['device']) : '';
if ($dev === '' || !tdm_is_safe_dev_path($dev)) {
    http_response_code(400);
    echo "Invalid device";
    exit;
}

$code = 0;
$output = tdm_run_command(array("sudo", "/usr/local/sbin/tdm-smartctl-read", "-x", $dev), $code);
if ($code !== 0 && trim($output) === "")
{
    http_response_code(500);
    echo "smartctl exited with code " . $code . " and returned no output.";
    exit;
}

echo $output;

<?php

require_once __DIR__ . "/api_config_store.php";

header("Content-Type: application/json; charset=utf-8");

function tdm_settings_response(array $config)
{
    echo json_encode(array(
        'status' => 'ok',
        'configured' => tdm_api_configured($config),
        'api_url' => $config['api_url'],
        'api_key_masked' => tdm_mask_api_key($config['api_key']),
        'verify_tls' => $config['verify_tls'],
    ));
}

try
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET')
    {
        tdm_settings_response(tdm_load_api_config());
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    {
        http_response_code(405);
        echo json_encode(array('status' => 'error', 'message' => 'Use GET or POST.'));
        exit;
    }

    $existing = tdm_load_api_config();
    $action = isset($_POST['action']) ? trim($_POST['action']) : 'save';

    if ($action === 'disable')
    {
        $config = tdm_save_api_config('', '', false);
        tdm_settings_response($config);
        exit;
    }

    $api_url = isset($_POST['api_url']) ? trim($_POST['api_url']) : '';
    $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';
    $verify_tls = isset($_POST['verify_tls']) && ($_POST['verify_tls'] === '1' || $_POST['verify_tls'] === 'true');

    if ($api_key === '' && $existing['api_key'] !== '')
    {
        $api_key = $existing['api_key'];
    }

    $config = tdm_save_api_config($api_url, $api_key, $verify_tls);
    tdm_settings_response($config);
}
catch (Throwable $e)
{
    http_response_code(400);
    echo json_encode(array(
        'status' => 'error',
        'message' => $e->getMessage(),
    ));
}

?>

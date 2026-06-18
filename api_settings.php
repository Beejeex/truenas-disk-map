<?php

require_once __DIR__ . "/api_config_store.php";

header("Content-Type: application/json; charset=utf-8");

function tdm_settings_response(array $config)
{
    $response = array(
        'status' => 'ok',
        'configured' => tdm_api_configured($config),
        'api_url' => $config['api_url'],
        'api_key_masked' => tdm_mask_api_key($config['api_key']),
        'verify_tls' => $config['verify_tls'],
    );

    // Suggest URL when none is saved — detect host IP from default gateway
    if ($config['api_url'] === '') {
        $suggested = tdm_detect_host_url();
        if ($suggested !== '') {
            $response['suggested_url'] = $suggested;
        }
    }

    echo json_encode($response);
}

/**
 * Detect the TrueNAS host URL from inside the container.
 * Uses the default route gateway (the Docker host) and assumes port 443.
 */
function tdm_detect_host_url()
{
    // Try /proc/net/route first (works on most Linux containers, no extra tools needed)
    $route = @file_get_contents('/proc/net/route');
    if ($route !== false) {
        $lines = explode("\n", trim($route));
        array_shift($lines); // skip header
        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line));
            // Field 1 = Destination, Field 7 = Gateway
            // Default route has Destination = 00000000
            if (isset($fields[1], $fields[7]) && $fields[1] === '00000000' && $fields[7] !== '00000000') {
                // Gateway is hex-encoded little-endian IP
                $hex = $fields[7];
                if (strlen($hex) === 8) {
                    $ip = implode('.', array_map('hexdec', [
                        substr($hex, 6, 2),
                        substr($hex, 4, 2),
                        substr($hex, 2, 2),
                        substr($hex, 0, 2),
                    ]));
                    return 'https://' . $ip . '/api/v2.0';
                }
            }
        }
    }

    // Fallback: try host.docker.internal (Docker Desktop / macOS)
    $host = @gethostbyname('host.docker.internal');
    if ($host && $host !== 'host.docker.internal') {
        return 'https://' . $host . '/api/v2.0';
    }

    return '';
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

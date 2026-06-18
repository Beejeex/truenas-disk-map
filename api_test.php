<?php
// api_test.php
// POST: tests the TrueNAS API connection with the provided (or saved) credentials.
// Returns JSON: { ok: bool, message: string, status_code: int|null }

require_once __DIR__ . '/api_config_store.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use POST']);
    exit;
}

// ── Determine credentials to test ───────────────────────────────────
// Accept new values from the form (so user can test before saving),
// falling back to the saved config.
$input = json_decode(file_get_contents('php://input'), true);
$saved = tdm_load_api_config();

$api_url    = isset($input['api_url'])    ? tdm_normalize_api_url($input['api_url'])    : $saved['api_url'];
$api_key    = isset($input['api_key'])    ? trim((string)$input['api_key'])              : $saved['api_key'];
$verify_tls = isset($input['verify_tls']) ? (bool)$input['verify_tls']                    : $saved['verify_tls'];

// If key is empty but saved key exists and user didn't provide one,
// use the saved key (for the "test existing config" case).
if ($api_key === '' && !isset($input['api_key'])) {
    $api_key = $saved['api_key'];
}

if ($api_url === '' || $api_key === '') {
    echo json_encode(['ok' => false, 'message' => 'API URL and API Key are required.']);
    exit;
}

// ── Test the connection ─────────────────────────────────────────────
// Try GET /api/v2.0/system/info — a lightweight, read-only endpoint.
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $api_url . '/system/info',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $api_key,
        'Accept: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => $verify_tls,
    CURLOPT_SSL_VERIFYHOST => $verify_tls ? 2 : 0,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    $msg = $curl_error;
    // Give a hint for common TLS errors
    if (stripos($curl_error, 'certificate') !== false || stripos($curl_error, 'SSL') !== false) {
        $msg .= ' (Try enabling "Verify TLS certificate" or check the URL.)';
    }
    echo json_encode(['ok' => false, 'message' => $msg, 'status_code' => null]);
    exit;
}

if ($http_code >= 200 && $http_code < 300) {
    $data = @json_decode($response, true);
    $hostname = isset($data['hostname']) ? $data['hostname'] : '';
    $version  = isset($data['version'])  ? $data['version']  : '';
    $extra = $hostname ? " (host: {$hostname}" . ($version ? ", version: {$version}" : '') . ')' : '';
    echo json_encode([
        'ok'          => true,
        'message'     => 'Connected successfully' . $extra,
        'status_code' => $http_code,
    ]);
} elseif ($http_code === 401 || $http_code === 403) {
    echo json_encode([
        'ok'          => false,
        'message'     => 'Authentication failed (HTTP ' . $http_code . '). Check your API key.',
        'status_code' => $http_code,
    ]);
} else {
    $body = $response ? substr($response, 0, 200) : '(empty response)';
    echo json_encode([
        'ok'          => false,
        'message'     => 'Unexpected response (HTTP ' . $http_code . '): ' . $body,
        'status_code' => $http_code,
    ]);
}

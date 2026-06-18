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
// Try /api/v2.0/disk first — the actual endpoint the app uses and
// the one a Readonly Admin role has access to.
function tdm_api_call($url, $api_key, $verify_tls) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
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
    return [$http_code, $response, $curl_error];
}

// Test 1: /api/v2.0/disk (what the app actually needs, works with Readonly Admin)
list($code1, $body1, $err1) = tdm_api_call($api_url . '/disk', $api_key, $verify_tls);

if ($err1) {
    $msg = $err1;
    if (stripos($err1, 'certificate') !== false || stripos($err1, 'SSL') !== false) {
        $msg .= ' (Try enabling "Verify TLS certificate" or check the URL.)';
    }
    echo json_encode(['ok' => false, 'message' => $msg, 'status_code' => null]);
    exit;
}

if ($code1 >= 200 && $code1 < 300) {
    // Success! Also try system/info for hostname/version
    $hostname = '';
    $version  = '';
    list($code2, $body2) = tdm_api_call($api_url . '/system/info', $api_key, $verify_tls);
    if ($code2 >= 200 && $code2 < 300) {
        $data = @json_decode($body2, true);
        $hostname = isset($data['hostname']) ? $data['hostname'] : '';
        $version  = isset($data['version'])  ? $data['version']  : '';
    }
    $extra = $hostname ? " (host: {$hostname}" . ($version ? ", version: {$version}" : '') . ')' : '';
    echo json_encode([
        'ok'          => true,
        'message'     => 'Connected successfully' . $extra,
        'status_code' => $code1,
    ]);
    exit;
}

// /disk returned non-2xx. Try just the API root to diagnose.
if ($code1 === 401 || $code1 === 403) {
    // Could be the key, or could be the endpoint requires higher privileges.
    // Try a bare minimum endpoint.
    list($codeRoot, $bodyRoot) = tdm_api_call(rtrim($api_url, '/'), $api_key, $verify_tls);

    if ($codeRoot === 401 || $codeRoot === 403) {
        // Both endpoints rejected — it's likely the key itself.
        $body_sample = $bodyRoot ? ': ' . substr(strip_tags($bodyRoot), 0, 120) : '';
        echo json_encode([
            'ok'          => false,
            'message'     => 'Authentication failed (HTTP ' . $code1 . '). The API key was rejected.' . $body_sample,
            'status_code' => $code1,
        ]);
    } elseif ($codeRoot >= 200 && $codeRoot < 300) {
        // Root works but /disk doesn't — permissions issue on the endpoint.
        echo json_encode([
            'ok'          => false,
            'message'     => 'API key is valid but lacks permission to list disks (HTTP ' . $code1 . '). Check that the user has the Readonly Admin role.',
            'status_code' => $code1,
        ]);
    } else {
        echo json_encode([
            'ok'          => false,
            'message'     => 'API key works at root but /disk returned HTTP ' . $code1 . '.',
            'status_code' => $code1,
        ]);
    }
    exit;
}

// Non-auth error (404, 500, etc.)
$body = $body1 ? substr($body1, 0, 200) : '(empty response)';
echo json_encode([
    'ok'          => false,
    'message'     => 'Unexpected response from /api/v2.0/disk (HTTP ' . $code1 . '): ' . $body,
    'status_code' => $code1,
]);

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
// Tests both /disk and /pool and reports counts + topology summary.
function tdm_api_call($url, $api_key, $verify_tls) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
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

// ── Test /disk ──────────────────────────────────────────────────────
list($diskCode, $diskBody, $diskErr) = tdm_api_call($api_url . '/disk', $api_key, $verify_tls);
if ($diskErr) {
    echo json_encode(['ok' => false, 'message' => 'Connection error: ' . $diskErr]);
    exit;
}

$diskCount = null;
if ($diskCode >= 200 && $diskCode < 300) {
    $disks = @json_decode($diskBody, true);
    if (is_array($disks)) $diskCount = count($disks);
}

// ── Test /pool ──────────────────────────────────────────────────────
list($poolCode, $poolBody, $poolErr) = tdm_api_call($api_url . '/pool', $api_key, $verify_tls);
if ($poolErr) {
    echo json_encode(['ok' => false, 'message' => '/disk OK but /pool error: ' . $poolErr, 'disk_count' => $diskCount]);
    exit;
}

$poolCount = null;
$poolDiskCount = 0;
$poolNames = [];
if ($poolCode >= 200 && $poolCode < 300) {
    $pools = @json_decode($poolBody, true);
    if (is_array($pools)) {
        $poolCount = count($pools);
        foreach ($pools as $pool) {
            $pn = isset($pool['name']) ? $pool['name'] : '(unnamed)';
            $vdevs = [];
            if (isset($pool['topology']['data']) && is_array($pool['topology']['data'])) {
                foreach ($pool['topology']['data'] as $vdev) {
                    $vt = isset($vdev['type']) ? strtoupper($vdev['type']) : 'DATA';
                    $c = isset($vdev['children']) && is_array($vdev['children']) ? count($vdev['children']) : 0;
                    $poolDiskCount += $c;
                    $vdevs[] = $c ? "$vt×$c" : $vt;
                }
            }
            $poolNames[] = $pn . ($vdevs ? ' (' . implode(', ', $vdevs) . ')' : '');
        }
    }
}

// ── Test /system/info for hostname ──────────────────────────────────
$hostname = '';
list($infoCode, $infoBody) = tdm_api_call($api_url . '/system/info', $api_key, $verify_tls);
if ($infoCode >= 200 && $infoCode < 300) {
    $info = @json_decode($infoBody, true);
    $hostname = isset($info['hostname']) ? $info['hostname'] : '';
}

// ── Build response ──────────────────────────────────────────────────
$parts = [];
if ($hostname) $parts[] = "host: $hostname";
if ($diskCount !== null) $parts[] = "$diskCount disks";
if ($poolCount !== null) $parts[] = "$poolCount pools ($poolDiskCount data disks)";

$allOk = ($diskCode >= 200 && $diskCode < 300) && ($poolCode >= 200 && $poolCode < 300);

if ($allOk) {
    $msg = 'All OK — ' . implode(', ', $parts);
    if ($poolNames) $msg .= "\nPools: " . implode(' | ', $poolNames);
    if ($diskCount > 0 && $poolCount > 0 && $poolDiskCount === 0) {
        $msg .= "\nWARNING: pools found but no topology data extracted. API format may differ. Run Refresh and check output.";
    }
} else {
    $errs = [];
    if ($diskCode < 200 || $diskCode >= 300) $errs[] = "/disk HTTP $diskCode";
    if ($poolCode < 200 || $poolCode >= 300) $errs[] = "/pool HTTP $poolCode";
    $msg = 'FAILED: ' . implode(', ', $errs);
    if ($parts) $msg .= ' — ' . implode(', ', $parts);
}

echo json_encode([
    'ok'           => $allOk,
    'message'      => $msg,
    'disk_count'   => $diskCount,
    'pool_count'   => $poolCount,
    'pool_disks'   => $poolDiskCount,
    'disk_code'    => $diskCode,
    'pool_code'    => $poolCode,
]);

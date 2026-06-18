<?php
// cron_config.php
// GET  → returns current cron config as JSON
// POST → saves new config (enabled, interval_minutes)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$config_file = __DIR__ . '/disk_data/.cron_config.json';

$defaults = [
    'enabled'          => false,
    'interval_minutes' => 720,  // 12 hours
];

// ── Read current config ─────────────────────────────────────────────
function load_config($file, $defaults) {
    if (file_exists($file)) {
        $data = @json_decode(@file_get_contents($file), true);
        if (is_array($data)) {
            return array_merge($defaults, $data);
        }
    }
    return $defaults;
}

function save_config($file, $config) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return @file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ── GET: return current config ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $config = load_config($config_file, $defaults);
    echo json_encode($config);
    exit;
}

// ── POST: save config ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $current = load_config($config_file, $defaults);

    if (isset($input['enabled'])) {
        $current['enabled'] = (bool)$input['enabled'];
    }
    if (isset($input['interval_minutes'])) {
        $val = (int)$input['interval_minutes'];
        if ($val < 5) $val = 5; // minimum 5 minutes
        $current['interval_minutes'] = $val;
    }

    if (save_config($config_file, $current) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write config file']);
        exit;
    }

    echo json_encode($current);
    exit;
}

// ── Other methods ───────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

<?php
// refresh_status.php
// Lightweight endpoint: returns JSON with refresh state.
// Polled by the frontend every 30 seconds.
//
// Response:
//   { "running": bool, "last_run_at": "ISO8601|null", "last_status": "ok|error|null" }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$status_file = __DIR__ . '/disk_data/.refresh.status';
$lock_file   = __DIR__ . '/disk_data/.refresh.lock';

$status = ['running' => false, 'last_run_at' => null, 'last_status' => null];

// Check if a CLI refresh is currently running (lock file exists)
if (file_exists($lock_file)) {
    $status['running'] = true;
}

// Read last status
if (file_exists($status_file)) {
    $data = @json_decode(@file_get_contents($status_file), true);
    if (is_array($data)) {
        if (isset($data['running']) && $data['running']) {
            $status['running'] = true;
        }
        $status['last_run_at'] = isset($data['last_run_at']) ? $data['last_run_at'] : null;
        $status['last_status'] = isset($data['last_status']) ? $data['last_status'] : null;
    }
}

// If lock file exists but status says not running, it's stale — clean up
if ($status['running'] && file_exists($lock_file)) {
    // Lock exists but maybe the PID is dead? Check mtime.
    $lock_age = time() - @filemtime($lock_file);
    if ($lock_age > 3600) {
        // Stale lock (>1 hour), treat as not running
        $status['running'] = false;
        @unlink($lock_file);
    }
}

echo json_encode($status);

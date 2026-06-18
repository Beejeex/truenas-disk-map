<?php
// scheduler.php
//
// Called every 60 seconds by daemon.php (or optionally by host cron).
// Reads disk_data/.cron_config.json and triggers run_regen.php (CLI mode)
// if enough time has passed since the last successful refresh.
//
// Safe to call every minute — it's a no-op when it's not time yet or when
// a refresh is already running.

$config_file = __DIR__ . '/disk_data/.cron_config.json';
$status_file = __DIR__ . '/disk_data/.refresh.status';
$lock_file   = __DIR__ . '/disk_data/.refresh.lock';

// ── Load config ─────────────────────────────────────────────────────
$config = ['enabled' => false, 'interval_minutes' => 720]; // defaults (12h)
if (file_exists($config_file)) {
    $data = @json_decode(@file_get_contents($config_file), true);
    if (is_array($data)) {
        if (isset($data['enabled'])) $config['enabled'] = (bool)$data['enabled'];
        if (isset($data['interval_minutes'])) $config['interval_minutes'] = max(5, (int)$data['interval_minutes']);
    }
}

// Not enabled — nothing to do
if (!$config['enabled']) {
    exit(0);
}

// Already running — don't start another
if (file_exists($lock_file)) {
    exit(0);
}

// ── Check if it's time ──────────────────────────────────────────────
$last_ok = null;
if (file_exists($status_file)) {
    $status = @json_decode(@file_get_contents($status_file), true);
    if (is_array($status) && isset($status['last_run_at']) && isset($status['last_status']) && $status['last_status'] === 'ok') {
        $last_ok = strtotime($status['last_run_at']);
    }
}

$now = time();
$next_due = $last_ok ? ($last_ok + ($config['interval_minutes'] * 60)) : 0;

if ($now < $next_due) {
    // Not yet due
    exit(0);
}

// ── Trigger background refresh ──────────────────────────────────────
// Run in background so the cron minute tick returns immediately.
// On Linux: use exec with &>/dev/null & to detach.
$php_bin = PHP_BINARY ?: '/usr/local/bin/php';
$script  = __DIR__ . '/run_regen.php';
$cmd     = escapeshellcmd($php_bin) . ' ' . escapeshellarg($script) . ' cron';

if (stripos(PHP_OS, 'WIN') === 0) {
    // Windows: pclose(popen(...)) for background
    pclose(popen('start /B ' . $cmd . ' > NUL 2>&1', 'r'));
} else {
    // Linux: exec with output redirection
    exec($cmd . ' > /dev/null 2>&1 &');
}

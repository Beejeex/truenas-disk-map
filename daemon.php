<?php
// daemon.php
//
// Long-running PHP process that calls scheduler.php every 60 seconds.
// Started by the Docker entrypoint alongside Apache.
// No host cron needed — scheduling is fully self-contained.
//
// Logs to disk_data/.daemon.log (rotated: last 1000 lines kept).

$scheduler_script = __DIR__ . '/scheduler.php';
$log_file         = __DIR__ . '/disk_data/.daemon.log';
$php_bin          = PHP_BINARY ?: '/usr/local/bin/php';
$sleep_seconds    = 60;

// Ensure disk_data exists
$disk_data_dir = __DIR__ . '/disk_data';
if (!is_dir($disk_data_dir)) {
    @mkdir($disk_data_dir, 0755, true);
}

function daemon_log($msg) {
    global $log_file;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    // Keep only last 1000 lines
    $lines = @file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines) && count($lines) > 1000) {
        $lines = array_slice($lines, -1000);
        @file_put_contents($log_file, implode("\n", $lines) . "\n", LOCK_EX);
    }
}

// ── Trap signals for graceful shutdown ──────────────────────────────
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
}

daemon_log('Daemon started (pid=' . getmypid() . ', interval=' . $sleep_seconds . 's)');

while ($running) {
    // Dispatch signals
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Call scheduler.php — it's a fast no-op when not needed
    $cmd = escapeshellcmd($php_bin) . ' ' . escapeshellarg($scheduler_script) . ' 2>&1';
    $output = [];
    $exit_code = 0;
    @exec($cmd, $output, $exit_code);

    if ($exit_code !== 0) {
        daemon_log('scheduler exit=' . $exit_code . ' output=' . implode(' | ', $output));
    }

    // Sleep in 1-second increments so we can react to signals
    for ($i = 0; $i < $sleep_seconds && $running; $i++) {
        sleep(1);
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}

daemon_log('Daemon stopped');

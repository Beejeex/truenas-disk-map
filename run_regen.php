<?php
// run_regen.php
//
// Can be called in two ways:
//   1. Via web (POST): shows progress in browser, blocks until complete
//   2. Via CLI:        runs as a background/cron job with lock file
//
// Cron example (every 6 hours):
//   0 */6 * * * /usr/local/bin/php /var/www/html/run_regen.php cron

$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$mode = 'cu_smart';
if ($is_cli) {
    // CLI: first argument can override mode
    $mode = isset($argv[1]) ? trim($argv[1]) : 'cu_smart';
} else {
    $mode = isset($_POST['mode']) ? trim($_POST['mode']) : 'cu_smart';
}

// ── Lock file (prevents overlapping runs) ──────────────────────────
$lock_file = __DIR__ . '/disk_data/.refresh.lock';
$status_file = __DIR__ . '/disk_data/.refresh.status';

function write_status($file, $data) {
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

if ($is_cli) {
    // Try to acquire lock
    $lock_fp = @fopen($lock_file, 'c');
    if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
        // Another refresh is already running — exit silently
        if ($lock_fp) fclose($lock_fp);
        exit(0);
    }
    // Write running status
    write_status($status_file, [
        'running'    => true,
        'started_at' => date('c'),
        'pid'        => getmypid(),
    ]);

    // Ensure cleanup on fatal errors / early exit
    register_shutdown_function(function () use ($lock_file, $status_file, &$lock_fp) {
        $error = error_get_last();
        $is_fatal = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]);
        write_status($status_file, [
            'running'      => false,
            'last_run_at'  => date('c'),
            'last_status'  => $is_fatal ? 'error' : 'ok',
            'last_error'   => $is_fatal ? ($error['message'] . ' in ' . $error['file'] . ':' . $error['line']) : null,
        ]);
        if ($lock_fp) {
            @flock($lock_fp, LOCK_UN);
            @fclose($lock_fp);
        }
        @unlink($lock_file);
    });
}

function include_optional_step($file, $name)
{
    try
    {
        include $file;
    }
    catch (Throwable $e)
    {
        echo "[WARN] Optional step skipped (" . $name . "): " . $e->getMessage() . "\n";
    }
}

ob_start();

echo "Execution mode: {$mode}\n";
echo str_repeat("=", 60) . "\n\n";

echo "Step 1: Cleaning previous files\n";
include __DIR__ . "/clean_hdd_files.php";
echo "\n";

echo "Step 2: Detecting controllers\n";
include __DIR__ . "/detect_controllers.php";
echo "\n";

echo "Step 3: Generating HDD files\n";
include __DIR__ . "/generate_hdd_files.php";
echo "\n";

echo "Step 4: Associating serials with devices\n";
include __DIR__ . "/associate_devices.php";
echo "\n";

echo "Step 5: Generating SES files\n";
include __DIR__ . "/generate_ses_smart_files.php";
echo "\n";

echo "Step 6: Generating unused disk list\n";
include_optional_step(__DIR__ . "/gen_disk_unused_api.php", "unused disk list");
echo "\n";

echo "Step 7: Generating per-pool disk list\n";
include_optional_step(__DIR__ . "/gen_disk_per_pool_api.php", "per-pool disk list");
echo "\n";

$log = ob_get_clean();
echo $log;
echo "\n=== COMPLETE ===\n";

// ── Update status & release lock (CLI mode) ─────────────────────────
// Note: the shutdown function also handles cleanup for fatal errors,
// but on a clean exit we write the success status here.
if ($is_cli) {
    write_status($status_file, [
        'running'      => false,
        'last_run_at'  => date('c'),
        'last_status'  => 'ok',
    ]);
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    @unlink($lock_file);
}

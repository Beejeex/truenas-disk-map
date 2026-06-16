<?php
// run_regen.php

header('Content-Type: text/plain; charset=utf-8');

$mode = isset($_POST['mode']) ? trim($_POST['mode']) : 'cu_smart';

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

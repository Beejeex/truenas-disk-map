<?php
require_once __DIR__ . "/hardware_helpers.php";

$data = "";
$known_serials = array();

// 1. Luam lista corecta de device-uri din smartctl
$scan_code = 0;
$scan_output = tdm_run_command(array("sudo", "/usr/local/sbin/tdm-smartctl-read", "--scan"), $scan_code);
$lines = explode("\n", trim($scan_output));

foreach ($lines as $line)
{
    $line = trim($line);

    if ($line != "")
    {
        // Extrage /dev/sdX
        preg_match('/(\/dev\/[A-Za-z0-9._-]+)/', $line, $dev_match);

        if (isset($dev_match[1]))
        {
            $dev = $dev_match[1];
            if (!tdm_is_safe_dev_path($dev))
            {
                continue;
            }

            $serial = "";
            $device_type = "";

            // Extrage -d TYPE daca exista
            preg_match('/-d\s+([A-Za-z0-9,+_-]+)/', $line, $type_match);

            if (isset($type_match[1]))
            {
                $device_type = trim($type_match[1]);
                if (!preg_match('/^[A-Za-z0-9,+_-]+$/', $device_type))
                {
                    $device_type = "";
                }
            }

            $serial = tdm_get_smart_serial($dev, $device_type);

            if ($serial != "")
            {
                $data .= $serial . " " . $dev . "\n";
                $known_serials[$serial] = true;
            }
        }
    }
}

$raw_lsscsi = "";
$lsscsi_code = 0;
$detected = tdm_detect_lsscsi($raw_lsscsi, $lsscsi_code);

if ($lsscsi_code === 0)
{
    foreach ($detected['disks'] as $disk)
    {
        $dev = $disk['dev'];
        if (!tdm_is_safe_dev_path($dev))
        {
            continue;
        }

        $serial = tdm_get_smart_serial($dev);
        if ($serial != "" && !isset($known_serials[$serial]))
        {
            $data .= $serial . " " . $dev . "\n";
            $known_serials[$serial] = true;
        }
    }
}

file_put_contents("serial_cache.txt", $data);

echo "[OK] Serials were associated with devices.";

?>

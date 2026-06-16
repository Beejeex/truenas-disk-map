<?php
require_once __DIR__ . "/hardware_helpers.php";

$data = "";

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

            // Construim comanda inteligent
            if ($device_type != "")
            {
                $info_code = 0;
                $info = tdm_run_command(array("sudo", "/usr/local/sbin/tdm-smartctl-read", "-i", "-d", $device_type, $dev), $info_code);
            }
            else
            {
                $info_code = 0;
                $info = tdm_run_command(array("sudo", "/usr/local/sbin/tdm-smartctl-read", "-i", $dev), $info_code);
            }

            if ($info)
            {
                // Unele returneaza "Serial Number"
                if (preg_match('/Serial Number:\s*(.+)/i', $info, $match))
                {
                    $serial = trim($match[1]);
                }

                // SAS uneori returneaza "Serial number"
                if ($serial == "")
                {
                    if (preg_match('/Serial number:\s*(.+)/i', $info, $match))
                    {
                        $serial = trim($match[1]);
                    }
                }
            }

            if ($serial != "")
            {
                $data .= $serial . " " . $dev . "\n";
            }
        }
    }
}

file_put_contents("serial_cache.txt", $data);

echo "[OK] Serials were associated with devices.";

?>

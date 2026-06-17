<?php

require_once __DIR__ . "/hardware_helpers.php";

// sas3ircu can return a shortened serial while smartctl returns the full one.
// Match by prefix so controller serials still resolve to /dev/sdX entries.
function get_device_by_serial($serial, $cache_file = "serial_cache.txt")
{
    if (preg_match('/^DEV-([A-Za-z0-9._-]+)$/', $serial, $m))
    {
        return "/dev/" . $m[1];
    }

    if (!file_exists($cache_file))
    {
        return "N/A";
    }

    $lines = file($cache_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line)
    {
        $parts = preg_split('/\s+/', trim($line), 2);
        if (count($parts) < 2)
        {
            continue;
        }

        list($s, $dev) = $parts;

        if (strpos($s, $serial) === 0)
        {
            return $dev;
        }
    }

    return "N/A";
}

function get_smart_status($dev)
{
    if ($dev === "N/A")
    {
        return "X";
    }

    if (!tdm_is_safe_dev_path($dev))
    {
        return "X";
    }

    $code = 0;
    $smart = tdm_run_command(array("sudo", "/usr/local/sbin/tdm-smartctl-read", "-x", $dev), $code);
    if (trim($smart) === "")
    {
        return "X";
    }

    // Variabile
    $realloc = 0;
    $pending = 0;
    $uncorrect = 0;
    $load = 0;
    $hours = 0;
    $crc = 0;
    $ata_errors = 0; // Poate lipsi pe unele modele
    $selftest_fail = false;
    $read_fail = false;
    $overall_passed = true;

    // Parse atribute SMART clasice
    foreach (explode("\n", $smart) as $line)
    {
        $line = trim($line);

        if (preg_match('/^5\s+Reallocated_Sector_Ct\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(-?\d+)/', $line, $m))
        {
            $realloc = (int)$m[1];
        }
        elseif (preg_match('/^197\s+Current_Pending_Sector\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(-?\d+)/', $line, $m))
        {
            $pending = (int)$m[1];
        }
        elseif (preg_match('/^198\s+Offline_Uncorrectable\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(-?\d+)/', $line, $m))
        {
            $uncorrect = (int)$m[1];
        }
        elseif (preg_match('/^193\s+Load_Cycle_Count\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(-?\d+)/', $line, $m))
        {
            $load = (int)$m[1];
        }
        elseif (preg_match('/^9\s+Power_On_Hours\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(-?\d+)/', $line, $m))
        {
            $hours = (int)$m[1];
        }
        elseif (preg_match('/^199\s+UDMA_CRC_Error_Count\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(-?\d+)/', $line, $m))
        {
            $crc = (int)$m[1];
        }
    }

    // Overall health
    if (preg_match('/SMART overall-health .*?:\s*(\S+)/i', $smart, $m))
    {
        if (strtoupper($m[1]) !== 'PASSED')
        {
            $overall_passed = false;
        }
    }

    // Self-test failures (diverse mesaje posibile)
    if (preg_match('/Completed:\s*(read|electrical|servo|unknown)\s+failure/i', $smart))
    {
        $selftest_fail = true;
    }

    // Read failure in self-test (string util cand long test pica pe citire)
    if (preg_match('/Completed:\s*read failure/i', $smart))
    {
        $read_fail = true;
    }

    // ATA Error Count (nu toate modelele il au)
    if (preg_match('/ATA\s+Error\s+Count:\s*(\d+)/i', $smart, $m))
    {
        $ata_errors = (int)$m[1];
    }
    else
    {
        // Daca log-ul zice "No Errors Logged", consideram 0
        if (preg_match('/SMART\s+Error\s+Log.*?\n\s*No\s+Errors\s+Logged/i', $smart))
        {
            $ata_errors = 0;
        }
    }

    // ORDONARE SEVERITATI:
    // 1) DEAD: SMART not passed, self-test failure, or severe combinations
    if (!$overall_passed || $selftest_fail || ($pending > 0 && $uncorrect > 0) || $realloc >= 100)
    {
        return "DEAD (Overall=" . ($overall_passed ? "PASSED" : "FAILED") .
               " / SelfTestFail=" . ($selftest_fail ? "YES" : "NO") .
               " / Realloc=$realloc / Pending=$pending / Uncorrect=$uncorrect / CRC=$crc / ATA_Errors=$ata_errors)";
    }

    // 2) DANGEROUS: clear risk signals
    if ($pending > 0 || $uncorrect > 0 || $realloc > 10 || $crc > 0 || $ata_errors > 0)
    {
        return "DANGEROUS (Realloc=$realloc / Pending=$pending / Uncorrect=$uncorrect / CRC=$crc / ATA_Errors=$ata_errors)";
    }

	// 3) TIRED: many load/unload cycles or light wear
	if ($load > 20000 || ($realloc > 0 && $realloc <= 10))
	{
		return "TIRED (Realloc=$realloc / Load=$load)";
	}


    // 4) SUSPECT: semnale mai slabe, dar de urmarit
    if ($read_fail || $realloc > 0 || $ata_errors > 0)
    {
        return "SUSPECT (Realloc=$realloc / ATA_Errors=$ata_errors / ReadFail=" . ($read_fail ? "YES" : "NO") . ")";
    }

    // 5) OK
    return "OK";
}






$target_dir = __DIR__ . "/hdd_controlere";
$warnings = array();
$raw_lsscsi = "";
$lsscsi_exit = 0;
$ses_devs = tdm_detect_ses_devices($raw_lsscsi, $lsscsi_exit);

if ($lsscsi_exit !== 0)
{
    $warnings[] = "lsscsi -g exited with code " . $lsscsi_exit . ". Output: " . trim($raw_lsscsi);
}

if (empty($ses_devs))
{
    $warnings[] = "No SES enclosure was detected by lsscsi -g. LED commands will be left empty.";
}

$controllers = array();
$controllers_file = __DIR__ . "/controllers.txt";
if (is_file($controllers_file))
{
    $controllers = file($controllers_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

$generated = 0;
$mapped = array();
$source_files = glob($target_dir . "/hdd_c_*");
if ($source_files === false)
{
    $source_files = array();
}

foreach ($source_files as $file)
{
    if (str_contains(basename($file), "_ses"))
    {
        continue;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines)
    {
        continue;
    }

    $enclosure_to_lines = array();

    foreach ($lines as $line)
    {
        $parts = explode("|", $line);
        if (count($parts) < 4)
        {
            continue;
        }

        list($serial, $enclosure, $slot, $ctrl) = $parts;
        $enclosure = trim($enclosure);
        $enclosure_to_lines[$enclosure][] = array(trim($serial), $enclosure, trim($slot), trim($ctrl));
    }

    if (empty($enclosure_to_lines))
    {
        continue;
    }

    $enc_keys = array_keys($enclosure_to_lines);
    usort($enc_keys, function ($a, $b) {
        if (is_numeric($a) && is_numeric($b))
        {
            return (int)$a <=> (int)$b;
        }
        return strnatcasecmp((string)$a, (string)$b);
    });

    $base = basename($file);
    $ctrl = "unknown";
    if (preg_match('/^hdd_c_(\d+)/', $base, $m))
    {
        $ctrl = $m[1];
    }

    foreach ($enc_keys as $enc_index => $enc)
    {
        $ses_device = null;
        if (isset($ses_devs[$enc_index]))
        {
            $ses_device = $ses_devs[$enc_index];
        }
        elseif (count($ses_devs) === 1)
        {
            $ses_device = $ses_devs[0];
        }

        $fallback_label = "Controller " . $ctrl . " Enclosure " . $enc;
        $label = tdm_enclosure_label($ses_device, $fallback_label);
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $fallback_label));
        $slug = trim($slug, '_');
        $output_file = $file . "_" . $slug . "_ses";
        $out = fopen($output_file, "w");

        if ($out === false)
        {
            $warnings[] = "Could not open output file: " . $output_file;
            continue;
        }

        foreach ($enclosure_to_lines[$enc] as $row)
        {
            list($serial, $enclosure, $slot, $row_ctrl) = $row;
            $device = get_device_by_serial($serial);
            $smart_status = get_smart_status($device);

            $cmd_on = "";
            $cmd_off = "";
            if ($ses_device !== null)
            {
                $cmd_on = tdm_build_sg_ses_command($ses_device['sg'], $slot, "set");
                $cmd_off = tdm_build_sg_ses_command($ses_device['sg'], $slot, "clear");
            }

            fwrite($out, "$serial|$device|$label|$slot|$smart_status|$cmd_on|$cmd_off\n");
        }

        fclose($out);
        $generated++;
        $mapped[] = array(
            'controller' => $ctrl,
            'enclosure' => $enc,
            'ses_device' => $ses_device,
            'output_file' => basename($output_file),
        );

        if ($ses_device === null)
        {
            $warnings[] = "No SES device was available for controller " . $ctrl . ", enclosure " . $enc . ".";
        }
    }
}

tdm_write_discovery_report($target_dir, array(
    'controllers' => $controllers,
    'ses_devices' => $ses_devs,
    'controller_enclosure_map' => $mapped,
    'raw_lsscsi' => $raw_lsscsi,
    'warnings' => $warnings,
));

echo "[OK] SES files generated: " . $generated . ". SES devices detected: " . count($ses_devs) . ".\n";
foreach ($warnings as $warning)
{
    echo "[WARN] " . $warning . "\n";
}
?>

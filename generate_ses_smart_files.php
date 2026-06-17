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

function tdm_clean_ses_field($value)
{
    $value = preg_replace('/[\r\n|]+/', ' ', (string)$value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function tdm_smart_attr_raw($line, $id, $name)
{
    $pattern = '/^' . preg_quote((string)$id, '/') . '\s+' . preg_quote($name, '/') . '\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(.+)$/';
    if (preg_match($pattern, trim($line), $m))
    {
        if (preg_match('/-?\d+/', $m[1], $raw))
        {
            return (int)$raw[0];
        }
    }

    if (preg_match_all('/-?\d+/', trim($line), $nums) && !empty($nums[0]))
    {
        $matches = $nums[0];
        return (int)$matches[count($matches) - 1];
    }

    return 0;
}

function tdm_format_smart_status($state, array $d)
{
    $bits = array(
        "Overall=" . ($d['overall_health'] !== '' ? $d['overall_health'] : 'UNKNOWN'),
        "Realloc=" . $d['reallocated'],
        "Pending=" . $d['pending'],
        "Uncorrect=" . $d['uncorrectable'],
        "CRC=" . $d['crc_errors'],
        "ATA_Errors=" . $d['ata_errors'],
    );

    if ($d['selftest_failed'])
    {
        $bits[] = "SelfTestFail=YES";
    }

    if ($d['read_failure'])
    {
        $bits[] = "ReadFail=YES";
    }

    return $state . " (" . implode(" / ", $bits) . ")";
}

function get_smart_report($dev)
{
    $details = array(
        'model' => '',
        'capacity' => '',
        'firmware' => '',
        'power_hours' => 0,
        'temperature_c' => '',
        'reallocated' => 0,
        'pending' => 0,
        'uncorrectable' => 0,
        'crc_errors' => 0,
        'ata_errors' => 0,
        'load_cycle_count' => 0,
        'overall_health' => '',
        'selftest_failed' => false,
        'read_failure' => false,
    );

    if ($dev === "N/A")
    {
        return array('status' => "UNKNOWN (device not mapped)", 'details' => $details);
    }

    if (!tdm_is_safe_dev_path($dev))
    {
        return array('status' => "UNKNOWN (invalid device path)", 'details' => $details);
    }

    $code = 0;
    $smart = tdm_run_command(array("sudo", "/usr/local/sbin/tdm-smartctl-read", "-x", $dev), $code);
    if (trim($smart) === "")
    {
        return array('status' => "UNKNOWN (smartctl returned no data)", 'details' => $details);
    }

    if (preg_match('/^(?:Device Model|Model Number):\s*(.+)$/mi', $smart, $m))
    {
        $details['model'] = tdm_clean_ses_field($m[1]);
    }
    elseif (preg_match('/^Product:\s*(.+)$/mi', $smart, $m))
    {
        $details['model'] = tdm_clean_ses_field($m[1]);
    }

    if (preg_match('/^Firmware Version:\s*(.+)$/mi', $smart, $m))
    {
        $details['firmware'] = tdm_clean_ses_field($m[1]);
    }

    if (preg_match('/^User Capacity:\s*.*?\[([^\]]+)\]/mi', $smart, $m))
    {
        $details['capacity'] = tdm_clean_ses_field($m[1]);
    }
    elseif (preg_match('/^User Capacity:\s*(.+)$/mi', $smart, $m))
    {
        $details['capacity'] = tdm_clean_ses_field($m[1]);
    }

    // Parse atribute SMART clasice
    foreach (explode("\n", $smart) as $line)
    {
        $line = trim($line);

        if (preg_match('/^5\s+Reallocated_Sector_Ct\b/i', $line))
        {
            $details['reallocated'] = tdm_smart_attr_raw($line, 5, 'Reallocated_Sector_Ct');
        }
        elseif (preg_match('/^197\s+Current_Pending_Sector\b/i', $line))
        {
            $details['pending'] = tdm_smart_attr_raw($line, 197, 'Current_Pending_Sector');
        }
        elseif (preg_match('/^198\s+Offline_Uncorrectable\b/i', $line))
        {
            $details['uncorrectable'] = tdm_smart_attr_raw($line, 198, 'Offline_Uncorrectable');
        }
        elseif (preg_match('/^193\s+Load_Cycle_Count\b/i', $line))
        {
            $details['load_cycle_count'] = tdm_smart_attr_raw($line, 193, 'Load_Cycle_Count');
        }
        elseif (preg_match('/^9\s+Power_On_Hours\b/i', $line))
        {
            $details['power_hours'] = tdm_smart_attr_raw($line, 9, 'Power_On_Hours');
        }
        elseif (preg_match('/^199\s+UDMA_CRC_Error_Count\b/i', $line))
        {
            $details['crc_errors'] = tdm_smart_attr_raw($line, 199, 'UDMA_CRC_Error_Count');
        }
        elseif (preg_match('/^(190|194)\s+\S*Temperature\S*\b.*?\s+(-|\w+)\s+(.+)$/i', $line, $m))
        {
            if ($details['temperature_c'] === '' && preg_match('/(-?\d+)/', $m[3], $tm))
            {
                $details['temperature_c'] = (string)(int)$tm[1];
            }
        }
    }

    if ($details['power_hours'] === 0 && preg_match('/^Power On Hours:\s*([\d,]+)/mi', $smart, $m))
    {
        $details['power_hours'] = (int)str_replace(',', '', $m[1]);
    }

    if ($details['temperature_c'] === '' && preg_match('/Current Drive Temperature:\s*(\d+)\s*C/i', $smart, $m))
    {
        $details['temperature_c'] = (string)(int)$m[1];
    }

    // Overall health
    if (preg_match('/SMART overall-health .*?:\s*(\S+)/i', $smart, $m))
    {
        $details['overall_health'] = strtoupper($m[1]);
    }
    elseif (preg_match('/SMART Health Status:\s*(\S+)/i', $smart, $m))
    {
        $details['overall_health'] = strtoupper($m[1]);
    }

    // Self-test failures (diverse mesaje posibile)
    if (preg_match('/Completed:\s*(read|electrical|servo|unknown)\s+failure/i', $smart))
    {
        $details['selftest_failed'] = true;
    }

    // Read failure in self-test (string util cand long test pica pe citire)
    if (preg_match('/Completed:\s*read failure/i', $smart))
    {
        $details['read_failure'] = true;
    }

    // ATA Error Count (nu toate modelele il au)
    if (preg_match('/ATA\s+Error\s+Count:\s*(\d+)/i', $smart, $m))
    {
        $details['ata_errors'] = (int)$m[1];
    }
    else
    {
        // Daca log-ul zice "No Errors Logged", consideram 0
        if (preg_match('/SMART\s+Error\s+Log.*?\n\s*No\s+Errors\s+Logged/i', $smart))
        {
            $details['ata_errors'] = 0;
        }
    }

    $overall_passed = ($details['overall_health'] === '' || $details['overall_health'] === 'PASSED' || $details['overall_health'] === 'OK');

    // ORDONARE SEVERITATI:
    // 1) DEAD: SMART not passed, self-test failure, or severe combinations
    if (!$overall_passed || $details['selftest_failed'] || ($details['pending'] > 0 && $details['uncorrectable'] > 0) || $details['reallocated'] >= 100)
    {
        return array('status' => tdm_format_smart_status("DEAD", $details), 'details' => $details);
    }

    // 2) DANGEROUS: clear risk signals
    if ($details['pending'] > 0 || $details['uncorrectable'] > 0 || $details['reallocated'] > 10)
    {
        return array('status' => tdm_format_smart_status("DANGEROUS", $details), 'details' => $details);
    }

    // 3) SUSPECT: non-critical counters that should be reviewed.
    // Load_Cycle_Count is shown as info only; it is too noisy to mark a disk bad by itself.
    if ($details['read_failure'] || $details['reallocated'] > 0 || $details['ata_errors'] > 0)
    {
        return array('status' => tdm_format_smart_status("SUSPECT", $details), 'details' => $details);
    }

    // 4) OK
    return array('status' => tdm_format_smart_status("OK", $details), 'details' => $details);
}

function get_smart_status($dev)
{
    $report = get_smart_report($dev);
    return $report['status'];
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
            $smart_report = get_smart_report($device);
            $smart_status = $smart_report['status'];
            $smart_details = $smart_report['details'];

            $cmd_on = "";
            $cmd_off = "";
            if ($ses_device !== null)
            {
                $cmd_on = tdm_build_sg_ses_command($ses_device['sg'], $slot, "set");
                $cmd_off = tdm_build_sg_ses_command($ses_device['sg'], $slot, "clear");
            }

            $fields = array(
                $serial,
                $device,
                $label,
                $slot,
                $smart_status,
                $cmd_on,
                $cmd_off,
                $smart_details['model'],
                $smart_details['capacity'],
                $smart_details['firmware'],
                $smart_details['power_hours'],
                $smart_details['temperature_c'],
                $smart_details['reallocated'],
                $smart_details['pending'],
                $smart_details['uncorrectable'],
                $smart_details['crc_errors'],
                $smart_details['ata_errors'],
                $smart_details['load_cycle_count'],
            );

            $fields = array_map('tdm_clean_ses_field', $fields);
            fwrite($out, implode("|", $fields) . "\n");
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

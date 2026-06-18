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
        "ReallocEvents=" . $d['reallocation_events'],
        "Pending=" . $d['pending'],
        "Uncorrect=" . $d['uncorrectable'],
        "ReportedUncorrect=" . $d['reported_uncorrectable'],
        "EndToEnd=" . $d['end_to_end_errors'],
        "CRC=" . $d['crc_errors'],
        "ATA_Errors=" . $d['ata_errors'],
    );

    if ($d['temperature_c'] !== '')
    {
        $bits[] = "Temp=" . $d['temperature_c'] . "C";
    }

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
        'reported_uncorrectable' => 0,
        'end_to_end_errors' => 0,
        'reallocation_events' => 0,
        'spin_retry_count' => 0,
        'calibration_retry_count' => 0,
        'command_timeout' => 0,
        'high_fly_writes' => 0,
        'crc_errors' => 0,
        'ata_errors' => 0,
        'load_cycle_count' => 0,
        'overall_health' => '',
        'selftest_failed' => false,
        'read_failure' => false,
        'selftest_incomplete' => false,
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
        elseif (preg_match('/^187\s+Reported_Uncorrect\b/i', $line))
        {
            $details['reported_uncorrectable'] = tdm_smart_attr_raw($line, 187, 'Reported_Uncorrect');
        }
        elseif (preg_match('/^184\s+End-to-End_Error\b/i', $line))
        {
            $details['end_to_end_errors'] = tdm_smart_attr_raw($line, 184, 'End-to-End_Error');
        }
        elseif (preg_match('/^196\s+Reallocated_Event_Count\b/i', $line))
        {
            $details['reallocation_events'] = tdm_smart_attr_raw($line, 196, 'Reallocated_Event_Count');
        }
        elseif (preg_match('/^10\s+Spin_Retry_Count\b/i', $line))
        {
            $details['spin_retry_count'] = tdm_smart_attr_raw($line, 10, 'Spin_Retry_Count');
        }
        elseif (preg_match('/^11\s+Calibration_Retry_Count\b/i', $line))
        {
            $details['calibration_retry_count'] = tdm_smart_attr_raw($line, 11, 'Calibration_Retry_Count');
        }
        elseif (preg_match('/^188\s+Command_Timeout\b/i', $line))
        {
            $details['command_timeout'] = tdm_smart_attr_raw($line, 188, 'Command_Timeout');
        }
        elseif (preg_match('/^189\s+High_Fly_Writes\b/i', $line))
        {
            $details['high_fly_writes'] = tdm_smart_attr_raw($line, 189, 'High_Fly_Writes');
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
        elseif (preg_match('/^(190|194)\s+\S*Temperature\S*\b/i', $line))
        {
            // Extract all numbers from the line and take the last one (RAW_VALUE).
            // The VALUE/WORST columns are normalized and misleading on WD drives.
            if ($details['temperature_c'] === '' && preg_match_all('/-?\d+/', $line, $nums) && !empty($nums[0])) {
                $raw = (int)$nums[0][count($nums[0]) - 1];
                if ($raw > 0 && $raw < 200) {
                    $details['temperature_c'] = (string)$raw;
                }
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

    if (preg_match('/(Aborted by host|Interrupted|manually stopped|Self-test routine in progress)/i', $smart))
    {
        $details['selftest_incomplete'] = true;
    }

    // ATA Error Count (nu toate modelele il au)
    if (preg_match('/ATA\s+Error\s+Count:\s*(\d+)/i', $smart, $m))
    {
        $details['ata_errors'] = (int)$m[1];
    }
    else
    {
        // If the log says "No Errors Logged", keep ATA errors at 0.
        if (preg_match('/SMART\s+Error\s+Log.*?\n\s*No\s+Errors\s+Logged/i', $smart))
        {
            $details['ata_errors'] = 0;
        }
    }

    $has_meaningful_smart_data = (
        $details['model'] !== '' ||
        $details['capacity'] !== '' ||
        $details['overall_health'] !== '' ||
        $details['power_hours'] > 0 ||
        $details['temperature_c'] !== '' ||
        $details['reallocated'] > 0 ||
        $details['pending'] > 0 ||
        $details['uncorrectable'] > 0 ||
        $details['reported_uncorrectable'] > 0 ||
        $details['end_to_end_errors'] > 0 ||
        $details['reallocation_events'] > 0 ||
        $details['crc_errors'] > 0 ||
        $details['ata_errors'] > 0
    );

    if (!$has_meaningful_smart_data &&
        ($code !== 0 ||
        preg_match('/(read device identity failed|unable to detect device type|please specify device type|unsupported|unavailable|permission denied|operation not permitted|no such device|input\/output error|inquiry failed|scsi error)/i', $smart)))
    {
        return array('status' => "UNKNOWN (smartctl read or parse failed)", 'details' => $details);
    }

    $overall_passed = ($details['overall_health'] === '' || $details['overall_health'] === 'PASSED' || $details['overall_health'] === 'OK');

    $active_media_indicators = 0;
    foreach (array('reallocated', 'reallocation_events', 'pending', 'uncorrectable', 'reported_uncorrectable', 'end_to_end_errors') as $indicator)
    {
        if ($details[$indicator] > 0)
        {
            $active_media_indicators++;
        }
    }

    if (!$overall_passed || (($details['pending'] > 0 || $details['uncorrectable'] > 0) && $details['selftest_failed']))
    {
        return array('status' => tdm_format_smart_status("DEAD", $details), 'details' => $details);
    }

    if ($details['pending'] > 0 ||
        $details['uncorrectable'] > 0 ||
        $details['reported_uncorrectable'] > 0 ||
        $details['end_to_end_errors'] > 0 ||
        $details['read_failure'] ||
        $details['reallocated'] >= 100 ||
        $details['reallocation_events'] >= 100 ||
        $active_media_indicators >= 2)
    {
        return array('status' => tdm_format_smart_status("CRITICAL", $details), 'details' => $details);
    }

    if (($details['reallocated'] >= 10 && $details['reallocated'] <= 99) ||
        ($details['reallocation_events'] >= 10 && $details['reallocation_events'] <= 99) ||
        $details['spin_retry_count'] >= 3 ||
        $details['calibration_retry_count'] >= 3)
    {
        return array('status' => tdm_format_smart_status("DANGEROUS", $details), 'details' => $details);
    }

    if (($details['reallocated'] >= 1 && $details['reallocated'] <= 9) ||
        ($details['reallocation_events'] >= 1 && $details['reallocation_events'] <= 9) ||
        $details['spin_retry_count'] > 0 ||
        $details['calibration_retry_count'] > 0 ||
        $details['command_timeout'] > 0 ||
        $details['high_fly_writes'] > 0)
    {
        return array('status' => tdm_format_smart_status("SUSPECT", $details), 'details' => $details);
    }

    if ($details['selftest_incomplete'] || $details['temperature_c'] === '')
    {
        return array('status' => tdm_format_smart_status("MAINTENANCE", $details), 'details' => $details);
    }

    return array('status' => tdm_format_smart_status("OK", $details), 'details' => $details);
}

function get_smart_status($dev)
{
    $report = get_smart_report($dev);
    return $report['status'];
}






$target_dir = __DIR__ . "/disk_data";
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
$enc_counter = 0; // global counter for SES device assignment
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
        // Assign SES devices sequentially across all enclosure groups
        if (isset($ses_devs[$enc_counter])) {
            $ses_device = $ses_devs[$enc_counter];
        } elseif (count($ses_devs) === 1) {
            $ses_device = $ses_devs[0];
        }
        $enc_counter++;

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

            // Handle empty slots
            if ($serial === 'EMPTY') {
                $fields = array(
                    'EMPTY', 'Empty', $label, $slot, 'EMPTY', '', '',
                    '', '', '', 0, '', 0, 0, 0, 0, 0, 0,
                    0, 0, 0, 0, 0, 0, 0,
                );
                $fields = array_map('tdm_clean_ses_field', $fields);
                fwrite($out, implode("|", $fields) . "\n");
                continue;
            }

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
                $smart_details['reported_uncorrectable'],
                $smart_details['end_to_end_errors'],
                $smart_details['reallocation_events'],
                $smart_details['spin_retry_count'],
                $smart_details['calibration_retry_count'],
                $smart_details['command_timeout'],
                $smart_details['high_fly_writes'],
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

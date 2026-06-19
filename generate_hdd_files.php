<?php
require_once __DIR__ . "/hardware_helpers.php";

$controllers_file = __DIR__ . "/controllers.txt";
$controllers = array();
if (is_file($controllers_file))
{
    $controllers = file($controllers_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

$target_dir = __DIR__ . "/disk_data";
if (!is_dir($target_dir))
{
    @mkdir($target_dir, 0775, true);
}

function write_lsscsi_hdd_files($target_dir)
{
    $raw_lsscsi = "";
    $lsscsi_code = 0;
    $detected = tdm_detect_lsscsi($raw_lsscsi, $lsscsi_code);
    $enclosures = $detected['enclosures'];

    if ($lsscsi_code !== 0)
    {
        echo "[WARN] lsscsi fallback failed (exit " . $lsscsi_code . ").\n";
        if (trim($raw_lsscsi) !== "") echo trim($raw_lsscsi) . "\n";
        return 0;
    }

    if (empty($enclosures))
    {
        echo "[WARN] lsscsi fallback found no SES enclosures.\n";
        return 0;
    }

    // Get SAS transport addresses for disk matching
    $transport = tdm_parse_lsscsi_transport();
    $sas_map = $transport['disks'];
    $enc_sas_map = $transport['enclosures'];
    echo "[INFO] SAS transport: " . count($sas_map) . " disk addresses, " . count($enc_sas_map) . " enclosure addresses resolved.\n";

    $written = 0;
    $file_index = 0;
    $last_enclosure_target_by_bus = array();
    $detected_disks = $detected['disks']; // for fallback

    foreach ($enclosures as $enc_index => $enc)
    {
        // Read SES element data for this enclosure
        $ses_elements = tdm_parse_ses_join($enc['sg']);
        if (empty($ses_elements))
        {
            echo "[WARN] Could not read SES elements for " . $enc['sg'] . ", falling back to SCSI target grouping.\n";
            // Fallback: group disks by SCSI target range (old behavior)
            $enc_disks = [];
            $bus_key = (string)$enc['host'] . ":" . (string)$enc['channel'];
            $lower_target = $last_enclosure_target_by_bus[$bus_key] ?? -1;
            foreach ($detected_disks as $disk) {
                if ($disk['host'] !== $enc['host'] || $disk['channel'] !== $enc['channel']) continue;
                if ($disk['target'] === null || $enc['target'] === null) continue;
                if ($disk['target'] <= $lower_target || $disk['target'] >= $enc['target']) continue;
                $enc_disks[] = $disk;
            }
            $total = count($enc_disks);
            if ($total === 0) {
                if ($enc['target'] !== null) $last_enclosure_target_by_bus[$bus_key] = $enc['target'];
                continue;
            }
            $out_path = $target_dir . "/hdd_c_" . $file_index;
            $file = fopen($out_path, "w");
            if (!$file) { echo "[WARN] Could not open: $out_path\n"; continue; }
            $rows = 0;
            foreach ($enc_disks as $disk) {
                $serial = tdm_get_smart_serial($disk['dev']);
                if ($serial === '') $serial = "DEV-" . basename($disk['dev']);
                fwrite($file, $serial . "|" . $enc_index . "|" . $rows . "|" . $file_index . "\n");
                $rows++; $written++;
            }
            fclose($file);
            echo "[OK] SCSI fallback: " . $rows . " disks for " . $enc['sg'] . " → " . $out_path . "\n";
            $file_index++;
            if ($enc['target'] !== null) $last_enclosure_target_by_bus[$bus_key] = $enc['target'];
            continue;
        }

        // Match disks to this enclosure by SAS address
        $slot_disks = []; // element_index => disk info
        foreach ($ses_elements as $ei => $elem) {
            $elem_sas = $elem['sas_address'];
            if ($elem_sas === '' || $elem_sas === '0x0') continue;

            foreach ($sas_map as $dev => $disk_sas) {
                if (strcasecmp($disk_sas, $elem_sas) === 0) {
                    $serial = tdm_get_smart_serial($dev);
                    if ($serial === '') $serial = "DEV-" . basename($dev);
                    $slot_disks[$ei] = ['dev' => $dev, 'serial' => $serial];
                    break;
                }
            }
        }

        $total_slots = count($ses_elements);
        $out_path = $target_dir . "/hdd_c_" . $file_index;
        $file = fopen($out_path, "w");
        if ($file === false)
        {
            echo "[WARN] Could not open output file: " . $out_path . "\n";
            continue;
        }

        $rows = 0;
        foreach ($ses_elements as $ei => $elem)
        {
            $disk = $slot_disks[$ei] ?? null;
            $serial = $disk ? $disk['serial'] : 'EMPTY';
            fwrite($file, $serial . "|" . $enc_index . "|" . $ei . "|" . $file_index . "\n");
            $rows++;
            $written++;
        }

        fclose($file);
        $populated = count($slot_disks);
        echo "[OK] SAS-matched " . $populated . " disks in " . $total_slots . " slots for enclosure " . $enc['sg'] . " → " . $out_path . "\n";
        $file_index++;
    }

    return $written;
}

$generated_rows = 0;

foreach ($controllers as $ctl) 
{
    $ctl = trim($ctl);
    if (!preg_match('/^\d+$/', $ctl))
    {
        echo "[WARN] Ignoring invalid controller id: " . $ctl . "\n";
        continue;
    }

    $code = 0;
    $output = tdm_run_command(array("sudo", "/usr/local/sbin/tdm-sas3ircu-read", $ctl, "display"), $code);
    if ($code !== 0 || trim($output) === "")
    {
        echo "[WARN] sas3ircu display failed for controller " . $ctl . " (exit " . $code . ").\n";
        continue;
    }

    $lines = explode("\n", $output);

    $enclosure = "";
    $slot = "";
    $serial = "";
    $is_hdd = false;
    $written_slots = []; // track populated slots per enclosure for empty-fill

    $file = fopen($target_dir . "/hdd_c_$ctl", "w");
    if ($file === false)
    {
        echo "[WARN] Could not open HDD output file for controller " . $ctl . ".\n";
        continue;
    }

    foreach ($lines as $line) 
    {
        $line = trim($line);

        if (preg_match('/Device is a Hard disk/i', $line)) 
        {
            $is_hdd = true;
        }
        elseif (preg_match('/Device is a /i', $line)) 
        {
            // Reset the flag when sas3ircu starts describing a non-disk device.
            $is_hdd = false;
        }

        if (preg_match('/Enclosure #\s*:\s*(\d+)/', $line, $m)) 
        {
            $enclosure = $m[1];
        } 
        elseif (preg_match('/Slot #\s*:\s*(\d+)/', $line, $m)) 
        {
            $slot = $m[1];
        } 
        elseif (preg_match('/Serial No\s*:\s*(\S+)/', $line, $m)) 
        {
            $serial = $m[1];

            // Write only disk rows.
            if ($is_hdd && $enclosure !== "" && $slot !== "" && $serial !== "")
            {
                fwrite($file, "$serial|$enclosure|$slot|$ctl\n");
                $generated_rows++;
                $written_slots[$enclosure][] = (int)$slot;

                $enclosure = "";
                $slot = "";
                $serial = "";
                $is_hdd = false;
            }
        }
    }

    // Fill empty bays from SES
    if (!empty($written_slots)) {
        $ses_devs = tdm_detect_ses_devices();
        $ses_idx = 0;
        foreach ($written_slots as $enc_idx => $slots) {
            $ses_device = $ses_devs[$ses_idx] ?? null;
            $ses_idx++;
            if (!$ses_device) continue;
            $ses_elems = tdm_parse_ses_join($ses_device['sg']);
            if (empty($ses_elems)) continue;
            foreach ($ses_elems as $ei => $elem) {
                if (in_array($ei, $slots)) continue;
                fwrite($file, "EMPTY|$enc_idx|$ei|$ctl\n");
                $generated_rows++;
            }
        }
    }

    fclose($file);
}

if ($generated_rows === 0)
{
    if (empty($controllers))
    {
        echo "[WARN] No controllers found by sas3ircu. Trying lsscsi/smartctl fallback.\n";
    }
    else
    {
        echo "[WARN] sas3ircu did not produce disk rows. Trying lsscsi/smartctl fallback.\n";
    }

    $generated_rows = write_lsscsi_hdd_files($target_dir);
}

if ($generated_rows === 0)
{
    echo "[WARN] HDD files were not generated. No disk-slot rows were found.\n";
}
else
{
    echo "[OK] HDD files generated. Rows: " . $generated_rows . ".\n";
}
?>

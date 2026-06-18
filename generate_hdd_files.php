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
    $disks = $detected['disks'];
    $enclosures = $detected['enclosures'];

    if ($lsscsi_code !== 0)
    {
        echo "[WARN] lsscsi fallback failed (exit " . $lsscsi_code . ").\n";
        if (trim($raw_lsscsi) !== "")
        {
            echo trim($raw_lsscsi) . "\n";
        }
        return 0;
    }

    if (empty($enclosures))
    {
        echo "[WARN] lsscsi fallback found no SES enclosures.\n";
        return 0;
    }

    $written = 0;
    $file_index = 0;
    $last_enclosure_target_by_bus = array();

    foreach ($enclosures as $enc_index => $enc)
    {
        $bus_key = (string)$enc['host'] . ":" . (string)$enc['channel'];
        $lower_target = $last_enclosure_target_by_bus[$bus_key] ?? -1;
        $out_path = $target_dir . "/hdd_c_" . $file_index;
        $file = fopen($out_path, "w");
        if ($file === false)
        {
            echo "[WARN] Could not open lsscsi fallback output file: " . $out_path . "\n";
            continue;
        }

        $rows = 0;
        $slot = 0; // physical bay counter for this enclosure
        foreach ($disks as $disk)
        {
            if ($disk['host'] !== $enc['host'] || $disk['channel'] !== $enc['channel'])
            {
                continue;
            }

            if ($disk['target'] === null || $enc['target'] === null || $disk['target'] <= $lower_target || $disk['target'] >= $enc['target'])
            {
                continue;
            }

            $serial = tdm_get_smart_serial($disk['dev']);
            if ($serial === '')
            {
                $serial = "DEV-" . basename($disk['dev']);
            }

            fwrite($file, $serial . "|" . $enc_index . "|" . $slot . "|" . $file_index . "\n");
            $rows++;
            $written++;
            $slot++;
        }

        fclose($file);

        if ($rows === 0)
        {
            @unlink($out_path);
        }
        else
        {
            echo "[OK] lsscsi fallback generated " . $rows . " disk rows for enclosure " . $enc['sg'] . " from " . $out_path . ".\n";
            $file_index++;
        }

        if ($enc['target'] !== null)
        {
            $last_enclosure_target_by_bus[$bus_key] = $enc['target'];
        }
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

                $enclosure = "";
                $slot = "";
                $serial = "";
                $is_hdd = false;
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

<?php
require_once __DIR__ . "/hardware_helpers.php";

$controllers_file = __DIR__ . "/controllers.txt";
$controllers = array();
if (is_file($controllers_file))
{
    $controllers = file($controllers_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

$target_dir = __DIR__ . "/hdd_controlere";
if (!is_dir($target_dir))
{
    @mkdir($target_dir, 0775, true);
}

if (empty($controllers))
{
    echo "[WARN] No controllers found in controllers.txt. HDD files were not generated.\n";
    return;
}

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
    $is_hdd = false; // Flag pentru validare hard disk

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
            // Dacă apare alt "Device is a ..." flag-ul
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

            // scriem doar daca este HDD
            if ($is_hdd && $enclosure !== "" && $slot !== "" && $serial !== "")
            {
                fwrite($file, "$serial|$enclosure|$slot|$ctl\n");

                // Resetam dupa scriere
                $enclosure = "";
                $slot = "";
                $serial = "";
                $is_hdd = false;
            }
        }
    }
    fclose($file);
}
echo "[OK] HDD files generated.\n";
?>

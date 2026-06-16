
<?php
require_once __DIR__ . "/hardware_helpers.php";

$code = 0;
$output = tdm_run_command(array("sudo", "/usr/local/sbin/tdm-sas3ircu-read", "list"), $code);
$controllers = [];

foreach (explode("\n", $output) as $line)
{
    if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches))
    {
        $description = $matches[2];
        if (preg_match('/\b(SAS|LSI|Avago|Broadcom)\b/i', $description))
        {
            $controllers[] = $matches[1];
        }
    }
}

$controllers = array_values(array_unique($controllers));
file_put_contents(__DIR__ . "/controllers.txt", implode("\n", $controllers));

if ($code !== 0)
{
    echo "[WARN] sas3ircu list exited with code " . $code . ".\n";
}

if (empty($controllers))
{
    echo "[WARN] No controllers found by sas3ircu. SES devices may still be visible via lsscsi/sg_ses, but slot-to-disk mapping cannot be generated without controller disk data.\n";
    if (trim($output) !== "")
    {
        echo "[INFO] sas3ircu output:\n" . trim($output) . "\n";
    }
}
else
{
    echo "[OK] Controllers found: " . implode(", ", $controllers);
}
?>
